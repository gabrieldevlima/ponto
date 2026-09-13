<?php
declare(strict_types=1);
/**
 * Livro fiscal append-only — cadeia de integridade, NSR por marcação e imutabilidade.
 * Fase 1 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * O teste que mais importa é o [4]: adulterar uma marcação já assinada e
 * confirmar que a verificação ACUSA. Sem ele, os demais só provariam que o
 * sistema escreve linhas — não que elas provam alguma coisa.
 *
 * Execução: php tests/test_nsr_ledger.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/nsr_ledger.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();

echo "[1] Serialização canônica e hash\n";

$ev = ['nsr' => 42, 'event_type' => 'mark', 'teacher_cpf' => '12345678901',
       'direction' => 'E', 'marked_at' => '2026-08-05 08:00:00', 'work_date' => '2026-08-05',
       'record_type' => 'work', 'origin' => 'device', 'record_mode' => 'online',
       'method' => 'pin', 'lat' => -7.0252, 'lng' => -42.1311];

$c1 = nsr_ledger_canonical($ev);
$c2 = nsr_ledger_canonical($ev);
check('canônico é determinístico', $c1 === $c2);
check('canônico começa com a versão', str_starts_with($c1, 'v1|'), $c1);
check('float com precisão fixa (independe de php.ini)', str_contains($c1, '-7.0252000'), $c1);

// Campo com pipe não pode quebrar a serialização e permitir forjar um payload.
$evPipe = $ev; $evPipe['method'] = 'pin|forjado';
check('pipe em campo é neutralizado',
      substr_count(nsr_ledger_canonical($evPipe), '|') === substr_count($c1, '|'),
      'contagem de delimitadores mudou');

$h1 = nsr_ledger_hash('abc', $c1, 'chave-teste');
$h2 = nsr_ledger_hash('abc', $c1, 'chave-teste');
$h3 = nsr_ledger_hash('abd', $c1, 'chave-teste');
$h4 = nsr_ledger_hash('abc', $c1, 'outra-chave');
check('hash é determinístico', $h1 === $h2);
check('prev_hash diferente muda o hash', $h1 !== $h3);
check('chave diferente muda o hash', $h1 !== $h4);
check('hash tem 64 hex', strlen($h1) === 64 && ctype_xdigit($h1));

echo "[2] Append: NSR sequencial e cadeia encadeada\n";

// O livro pode já conter dados (backfill, operação normal). As asserções são
// RELATIVAS ao estado inicial — uma versão anterior deste teste assumia livro
// vazio e passou a falhar assim que o backfill rodou, o que é justamente o tipo
// de teste que corrói a confiança na suíte.
$livroAntes = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn();
// attendance_id que não colide com dados reais.
$attFicticio = 2000000000;

$pdo->beginTransaction();
try {
    $schoolId = (int)$pdo->query("SELECT id FROM schools ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,1,NOW())")
        ->execute(['ZZ Teste Ledger', '00000000272']);
    $teacherId = (int)$pdo->lastInsertId();

    $base = ['teacher_id' => $teacherId, 'teacher_cpf' => '00000000272', 'school_id' => $schoolId,
             'work_date' => '2026-08-05', 'origin' => 'device', 'record_mode' => 'online',
             'method' => 'pin', 'record_type' => 'work'];

    $in  = nsr_ledger_append($pdo, $base + ['mark_role' => 'in',  'direction' => 'E',
                                            'marked_at' => '2026-08-05 08:00:00', 'attendance_id' => $attFicticio]);
    $out = nsr_ledger_append($pdo, $base + ['mark_role' => 'out', 'direction' => 'S',
                                            'marked_at' => '2026-08-05 12:00:00', 'attendance_id' => $attFicticio]);

    check('entrada e saída recebem NSR DISTINTOS (NC-09)', $in['nsr'] !== $out['nsr'],
          "in={$in['nsr']} out={$out['nsr']}");
    check('NSR é sequencial', $out['nsr'] === $in['nsr'] + 1, "in={$in['nsr']} out={$out['nsr']}");

    $rowOut = $pdo->query("SELECT prev_hash, record_hash FROM nsr_ledger WHERE nsr = {$out['nsr']}")->fetch(PDO::FETCH_ASSOC);
    check('prev_hash da saída = record_hash da entrada', $rowOut['prev_hash'] === $in['record_hash']);

    $genesis = $pdo->query("SELECT COUNT(*) FROM nsr_ledger WHERE event_type = 'chain_genesis'")->fetchColumn();
    check('gênese da cadeia foi criada automaticamente', (int)$genesis === 1);

    $marks = nsr_ledger_marks_for($pdo, $attFicticio);
    check('nsr_ledger_marks_for devolve as 2 marcações', count($marks) === 2);

    echo "[3] Anulação é APPEND, não UPDATE\n";
    $void = nsr_ledger_void($pdo, $in['nsr'], null, 'teste de anulacao');
    check('void gera NSR próprio', $void['nsr'] === $out['nsr'] + 1);

    $orig = $pdo->query("SELECT marked_at, event_type FROM nsr_ledger WHERE nsr = {$in['nsr']}")->fetch(PDO::FETCH_ASSOC);
    check('marcação original permanece intacta', $orig['marked_at'] === '2026-08-05 08:00:00'
          && $orig['event_type'] === 'mark');

    $v = $pdo->query("SELECT target_nsr, reason FROM nsr_ledger WHERE nsr = {$void['nsr']}")->fetch(PDO::FETCH_ASSOC);
    check('void aponta para o NSR alvo', (int)$v['target_nsr'] === $in['nsr']);
    check('void guarda o motivo', $v['reason'] === 'teste de anulacao');

    try { nsr_ledger_void($pdo, $in['nsr'], null, '   '); check('void sem motivo é recusado', false); }
    catch (InvalidArgumentException $e) { check('void sem motivo é recusado', true); }

    echo "[4] Verificação da cadeia\n";

    $v1 = nsr_ledger_verify($pdo);
    check('cadeia íntegra passa na verificação', $v1['ok'], json_encode($v1['errors']));
    check('sem buracos de NSR', empty($v1['gaps']), json_encode($v1['gaps']));
    check('verificou o livro inteiro (base + 3 eventos deste teste)',
          $v1['checked'] >= $livroAntes + 3, "checked={$v1['checked']}, base={$livroAntes}");

    echo "[5] Detecção de adulteração\n";
    // A adulteração é simulada EM MEMÓRIA, sobre a linha real lida do banco.
    // Adulterar de verdade exigiria derrubar o trigger de imutabilidade, e
    // DROP TRIGGER é DDL: provoca COMMIT implícito no MySQL e vazaria dados
    // adulterados para dentro de um livro que não permite apagá-los.
    $rowIn = $pdo->query("SELECT * FROM nsr_ledger WHERE nsr = {$in['nsr']}")->fetch(PDO::FETCH_ASSOC);
    check('linha íntegra é aceita', nsr_ledger_verify_row($rowIn) === null);

    // (a) Atacante muda o horário da marcação e esquece o payload assinado.
    $tampered = $rowIn;
    $tampered['marked_at'] = '2026-08-05 07:00:00';
    $p1 = nsr_ledger_verify_row($tampered);
    check('horário alterado é DETECTADO', $p1 !== null);
    check('classificado como payload divergente', ($p1['tipo'] ?? '') === 'payload_divergente',
          json_encode($p1));

    // (b) Atacante mais cuidadoso: recalcula payload_canon para bater com as
    //     colunas. A checagem (1) passa; sem a chave secreta, o HMAC não fecha.
    $tampered2 = $tampered;
    $tampered2['payload_canon'] = nsr_ledger_canonical($tampered);
    $p2 = nsr_ledger_verify_row($tampered2);
    check('adulteração COM payload recalculado ainda é detectada', $p2 !== null);
    check('classificado como hash inválido', ($p2['tipo'] ?? '') === 'hash_invalido', json_encode($p2));

    // (c) Atacante com a chave: reassina tudo. Só a checagem de ELO o pega —
    //     é por isso que a cadeia existe, e não apenas uma assinatura por linha.
    $tampered3 = $tampered2;
    $tampered3['record_hash'] = nsr_ledger_hash((string)$tampered3['prev_hash'],
                                                (string)$tampered3['payload_canon']);
    check('reassinado isoladamente passa na checagem de linha',
          nsr_ledger_verify_row($tampered3) === null);
    $p3 = nsr_ledger_verify_row($tampered3, 'prev_hash_diferente_do_esperado');
    check('mas o ELO com o predecessor acusa', ($p3['tipo'] ?? '') === 'cadeia_rompida', json_encode($p3));

    echo "[6] Reconciliação attendance x livro\n";
    $rec = nsr_ledger_reconcile($pdo, '2026-08-05', '2026-08-05');
    check('reconcile executa sem erro', is_array($rec) && isset($rec['ok']));

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo "[7] Imutabilidade continua ativa no banco\n";
$names = array_column($pdo->query("SHOW TRIGGERS LIKE 'nsr_ledger'")->fetchAll(PDO::FETCH_ASSOC), 'Trigger');
check('trg_nsr_ledger_no_update presente', in_array('trg_nsr_ledger_no_update', $names, true));
check('trg_nsr_ledger_no_delete presente', in_array('trg_nsr_ledger_no_delete', $names, true));
check('livro voltou ao estado anterior ao teste',
      (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn() === $livroAntes,
      'o rollback deveria ter revertido os eventos criados aqui');

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

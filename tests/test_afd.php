<?php
declare(strict_types=1);
/**
 * Gerador do Arquivo Fonte de Dados (AFD) — Fase 4 da auditoria (NC-05).
 *
 * O AFD é um arquivo POSICIONAL de largura fixa. O modo de falha que importa
 * não é o erro barulhento: é a coluna deslocada em um byte, que produz um
 * arquivo aparentemente correto e rejeitado na fiscalização. Por isso os testes
 * centrais aqui são [2] (largura exata de toda linha) e [3] (acentuação e
 * campos longos não deslocam colunas).
 *
 * Execução: php tests/test_afd.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/afd.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();

echo "[1] Consistência interna da spec\n";
$erros = afd_spec_selfcheck();
check('spec sem erro estrutural', empty($erros), implode(' | ', $erros));
check('todos os tipos começam por nsr + tipo_registro', empty(array_filter($erros, fn($e) => str_contains($e, 'deveria ser'))));
$spec = afd_spec();
foreach ([1, 2, 3, 4, 5, 7, 9] as $t) {
    check("tipo {$t} definido e com largura > 0", isset($spec[$t]) && afd_largura($t, $spec) > 0);
}

echo "[2] Padding e largura\n";
check('numérico com zeros à esquerda', afd_pad_num('42', 9) === '000000042');
check('numérico descarta não-dígitos', afd_pad_num('12.345.678/0001-90', 14) === '12345678000190');
check('numérico vazio vira zeros', afd_pad_num('', 5) === '00000');
check('numérico excedente mantém a parte menos significativa',
      afd_pad_num('123456789', 4) === '6789', afd_pad_num('123456789', 4));
check('alfa com espaços à direita', afd_pad_alpha('ABC', 6) === 'ABC   ');
check('alfa em maiúsculas', afd_pad_alpha('teste', 5) === 'TESTE');
check('alfa excedente é truncado', afd_pad_alpha('ABCDEFGH', 4) === 'ABCD');

echo "[3] Acentuação não desloca colunas (o modo de falha silencioso)\n";
$acentuado = 'SEMED — Secretaria de Educação de Oeiras/PIAUÍ ção ç ã é';
$campo = afd_pad_alpha($acentuado, 60);
check('campo acentuado tem exatamente a largura pedida', strlen($campo) === 60, 'strlen=' . strlen($campo));
check('resultado é ASCII puro', preg_match('/^[\x20-\x7E]*$/', $campo) === 1, $campo);
check('mb_strlen == strlen (sem multibyte residual)', mb_strlen($campo) === strlen($campo));

$nomeLongo = str_repeat('JOSÉ DA SILVA ÁÉÍÓÚÇÃO ', 20);
check('campo longo e acentuado ainda respeita a largura',
      strlen(afd_pad_alpha($nomeLongo, 52)) === 52);

echo "[4] afd_line monta e valida\n";
$linha7 = afd_line(7, [
    'nsr' => 12345, 'tipo_registro' => 7,
    'data_marcacao' => '06082026', 'hora_marcacao' => '0830', 'cpf' => '123.456.789-01',
]);
check('linha tipo 7 com a largura da spec', strlen($linha7) === afd_largura(7), 'strlen=' . strlen($linha7));
check('NSR posicionado no início', substr($linha7, 0, 9) === '000012345', substr($linha7, 0, 9));
check('tipo de registro na posição 10', substr($linha7, 9, 1) === '7');
check('CPF sem máscara no fim', str_ends_with($linha7, '12345678901'), $linha7);

echo "[5] afd_line FALHA ALTO quando algo não bate\n";
$falhou = false;
try { afd_line(7, ['nsr' => 1, 'tipo_registro' => 7, 'data_marcacao' => '06082026']); }
catch (RuntimeException $e) { $falhou = str_contains($e->getMessage(), 'nao informado'); }
check('campo ausente é recusado', $falhou);

$falhou = false;
try { afd_line(99, []); } catch (InvalidArgumentException $e) { $falhou = true; }
check('tipo de registro inexistente é recusado', $falhou);

echo "[6] Geração com fixture controlada\n";
$empBackup = $pdo->query("SELECT * FROM employer_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$teacherId = 0;
try {
    $pdo->prepare("UPDATE employer_config SET cnpj = ?, company_name = ?, rep_identifier = ?,
                       service_location = ?, employer_type = 1")
        ->execute(['12.345.678/0001-90', 'EMPREGADOR DE TESTE — ÁÇÃO', 'REP-TESTE-0001', 'Escola Municipal Teste']);

    $schoolId = (int)$pdo->query("SELECT id FROM schools ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO teachers (name, cpf, pis, active, created_at) VALUES (?,?,?,1,NOW())")
        ->execute(['ZZ Teste AFD ÁÉÍ', '00000000272', '12345678901']);
    $teacherId = (int)$pdo->lastInsertId();

    $modo = (string)(get_setting('ledger_mode', 'off') ?? 'off');
    set_setting('ledger_mode', 'shadow');
    $dia = '2026-02-17';

    // O livro é append-only: execuções anteriores deste teste deixaram eventos
    // nesta data e não há como removê-los (nem deveria haver). As asserções são
    // RELATIVAS à linha de base — um teste que exija banco virgem falha na
    // segunda execução e ensina a equipe a ignorar a suíte.
    $baseMarks = (int)$pdo->query(
        "SELECT COUNT(*) FROM nsr_ledger WHERE event_type = 'mark' AND work_date = " . $pdo->quote($dia)
    )->fetchColumn();

    $pdo->beginTransaction();
    foreach ([['08:00', 'E', 'in'], ['12:00', 'S', 'out']] as [$hora, $dir, $papel]) {
        nsr_ledger_record_mark($pdo, [
            'teacher_id' => $teacherId, 'teacher_cpf' => '00000000272', 'teacher_pis' => '12345678901',
            'school_id' => $schoolId, 'mark_role' => $papel, 'direction' => $dir,
            'record_type' => 'work', 'marked_at' => "{$dia} {$hora}:00", 'work_date' => $dia,
            'origin' => 'device', 'method' => 'pin',
        ]);
    }
    $pdo->commit();
    set_setting('ledger_mode', $modo);

    $pf = afd_preflight($pdo, $dia, $dia);
    check('pré-voo aprova com cadastro completo', $pf['ok'], implode(' | ', $pf['bloqueios']));

    $tmp = tmpfile();
    $r = afd_generate_to_stream($pdo, $dia, $dia, $tmp, ['aceitar_spec_nao_verificada' => true]);
    fseek($tmp, 0);
    $conteudo = stream_get_contents($tmp);
    fclose($tmp);

    $linhas = array_values(array_filter(explode("\r\n", $conteudo), fn($l) => $l !== ''));
    $esperado = $baseMarks + 2 + 2; // base + as 2 deste teste + cabeçalho + trailer
    check('linhas = base + 2 marcações + cabeçalho + trailer',
          count($linhas) === $esperado, 'obtido ' . count($linhas) . ', esperado ' . $esperado);
    check('contador de tipo 7 subiu 2', ($r['contadores'][7] ?? 0) === $baseMarks + 2,
          json_encode($r['contadores']) . " base={$baseMarks}");

    $ultima = $linhas[count($linhas) - 1];
    check('1ª linha é o cabeçalho (tipo 1)', substr($linhas[0], 9, 1) === '1');
    check('cabeçalho com a largura da spec', strlen($linhas[0]) === afd_largura(1), 'strlen=' . strlen($linhas[0]));
    check('CNPJ do cabeçalho sem máscara', str_contains(substr($linhas[0], 11, 14), '12345678000190'));

    check('última linha é o trailer (tipo 9)', substr($ultima, 9, 1) === '9');
    check('trailer começa com NSR 999999999', str_starts_with($ultima, '999999999'));
    check('trailer com a largura da spec', strlen($ultima) === afd_largura(9));

    // O invariante que mais importa: TODA linha com a largura exata do seu tipo.
    $larguraOk = true; $detalhe = '';
    foreach ($linhas as $i => $l) {
        $tipo = (int)substr($l, 9, 1);
        if (strlen($l) !== afd_largura($tipo)) {
            $larguraOk = false;
            $detalhe = "linha {$i} (tipo {$tipo}): " . strlen($l) . " != " . afd_largura($tipo);
            break;
        }
    }
    check('TODAS as linhas com largura exata do seu tipo', $larguraOk, $detalhe);

    $soAscii = preg_match('/^[\x20-\x7E\r\n]*$/', $conteudo) === 1;
    check('arquivo inteiro é ASCII (nome acentuado não deslocou nada)', $soAscii);

    $prev = -1; $ordenado = true;
    foreach ($linhas as $l) {
        if ((int)substr($l, 9, 1) !== 7) continue;
        $n = (int)substr($l, 0, 9);
        if ($n <= $prev) { $ordenado = false; break; }
        $prev = $n;
    }
    check('NSR das marcações estritamente crescente', $ordenado);

    echo "[7] Recusa de emissão com leiaute não conferido\n";
    if (!AFD_SPEC_VERIFICADA) {
        $recusou = false;
        $tmp2 = tmpfile();
        try { afd_generate_to_stream($pdo, $dia, $dia, $tmp2); }
        catch (RuntimeException $e) { $recusou = str_contains($e->getMessage(), 'nao foi conferido'); }
        fclose($tmp2);
        check('gera apenas com aceite explícito do rascunho', $recusou);
    } else {
        check('spec marcada como verificada — checagem de rascunho não se aplica', true);
    }

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($empBackup) {
        $pdo->prepare("UPDATE employer_config SET cnpj = ?, company_name = ?, rep_identifier = ?,
                           service_location = ?, employer_type = ?")
            ->execute([$empBackup['cnpj'], $empBackup['company_name'], $empBackup['rep_identifier'],
                       $empBackup['service_location'], $empBackup['employer_type']]);
    }
    if ($teacherId) {
        $pdo->prepare("DELETE FROM attendance WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$teacherId]);
    }
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

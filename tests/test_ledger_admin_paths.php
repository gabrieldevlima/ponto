<?php
declare(strict_types=1);
/**
 * Caminhos ADMINISTRATIVOS de escrita x livro fiscal.
 * Fase 1 (complemento) de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * Os endpoints de marcação já estavam cobertos. Estes são os fluxos em que a
 * gestão cria ou anula marcações — justamente os que mais importam para a
 * fiscalização, porque não nasceram de um registro espontâneo do trabalhador.
 * Toda marcação criada por aqui precisa entrar no livro com NSR próprio,
 * origem identificando o fluxo e motivo registrado.
 *
 * Execução: php tests/test_ledger_admin_paths.php
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
$modoOriginal = (string)(get_setting('ledger_mode', 'off') ?? 'off');

// admin_scope_where() depende da sessão; network_admin enxerga tudo.
$adminId = (int)$pdo->query("SELECT id FROM admins WHERE role = 'network_admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$adminId) $adminId = (int)$pdo->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION['admin_id'] = $adminId;

$porTipo = function (string $t) use ($pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger WHERE event_type = " . $pdo->quote($t))->fetchColumn();
};

try {
    set_setting('ledger_mode', 'shadow');

    $schoolId = (int)$pdo->query("SELECT id FROM schools ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,1,NOW())")
        ->execute(['ZZ Teste AdminPaths', '00000000272']);
    $teacherId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teacher_schools (teacher_id, school_id) VALUES (?,?)")
        ->execute([$teacherId, $schoolId]);
    $data = '2026-03-12';
    $criados = [];

    echo "[1] Edição de dia — período criado entra no livro com 2 NSRs\n";
    // admin_save_attendance_day EDITA um dia existente; semeia-se o registro
    // base (fora do livro, como um dado legado) e pede-se a inclusão de um
    // segundo período — é esse que deve nascer no livro.
    $pdo->beginTransaction();
    $nsrBase = nsr_legacy_reserve($pdo);
    $pdo->prepare("INSERT INTO attendance (teacher_id, school_id, date, check_in, check_out, method, approved, record_type, nsr)
                   VALUES (?,?,?,?,?,'manual',1,'work',?)")
        ->execute([$teacherId, $schoolId, $data, "$data 08:00:00", "$data 12:00:00", $nsrBase]);
    $idBase = (int)$pdo->lastInsertId();
    $criados[] = $idBase;
    $pdo->commit();

    $marks0 = $porTipo('mark');
    $out = admin_save_attendance_day($pdo, $adminId, [
        'teacher_id' => $teacherId,
        'orig_date'  => $data,
        'date'       => $data,
        'reason'     => 'inclusao de periodo em teste',
        'works'      => [
            ['id' => $idBase, 'start' => '08:00', 'end' => '12:00'],
            ['start' => '14:00', 'end' => '18:00'],
        ],
        'breaks'     => [],
    ]);
    $novoId = $out['works']['added'][0] ?? null;
    $criados[] = $novoId;
    check('período foi criado', is_int($novoId) && $novoId > 0, json_encode($out['works'] ?? null));
    // Conta MARCAÇÕES, não eventos: num livro vazio a primeira marcação emite
    // também o evento de gênese da cadeia (chain_genesis), e a contagem total
    // dava 3. Livro vazio é o estado real do primeiro deploy do ledger. Mesmo
    // critério já usado abaixo para os eventos void.
    check('livro recebeu 2 marcações (entrada + saída)', $porTipo('mark') === $marks0 + 2,
          "marcações: {$marks0} -> " . $porTipo('mark'));

    $row = $pdo->query("SELECT nsr, nsr_out FROM attendance WHERE id = " . (int)$novoId)->fetch(PDO::FETCH_ASSOC);
    check('attendance.nsr e nsr_out preenchidos e distintos',
          !empty($row['nsr']) && !empty($row['nsr_out']) && $row['nsr'] !== $row['nsr_out'],
          json_encode($row));

    $marks = nsr_ledger_marks_for($pdo, (int)$novoId);
    check('livro tem exatamente as 2 marcações do período', count($marks) === 2);
    check('origem identifica o fluxo administrativo',
          ($pdo->query("SELECT origin FROM nsr_ledger WHERE nsr = " . (int)$row['nsr'])->fetchColumn()) === 'admin_edit');
    check('motivo foi registrado no livro',
          str_contains((string)$pdo->query("SELECT reason FROM nsr_ledger WHERE nsr = " . (int)$row['nsr'])->fetchColumn(),
                       'inclusao de periodo'));

    echo "[2] Anulação administrativa — vira evento void, original intacto\n";
    $voids0 = $porTipo('void');
    $nsrIn  = (int)$row['nsr'];
    $nsrOut = (int)$row['nsr_out'];
    $marcadoAntes = (string)$pdo->query("SELECT marked_at FROM nsr_ledger WHERE nsr = {$nsrIn}")->fetchColumn();

    admin_remove_attendance($pdo, (int)$novoId, $adminId, 'anulacao em teste');

    check('foram gerados 2 eventos void (entrada e saída)', $porTipo('void') === $voids0 + 2,
          "voids: {$voids0} -> " . $porTipo('void'));
    check('marcação original permanece INTACTA no livro',
          (string)$pdo->query("SELECT marked_at FROM nsr_ledger WHERE nsr = {$nsrIn}")->fetchColumn() === $marcadoAntes);
    check('void aponta para o NSR da entrada',
          (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger WHERE event_type='void' AND target_nsr = {$nsrIn}")->fetchColumn() === 1);
    check('void aponta para o NSR da saída',
          (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger WHERE event_type='void' AND target_nsr = {$nsrOut}")->fetchColumn() === 1);
    check('void guarda o motivo da anulação',
          (string)$pdo->query("SELECT reason FROM nsr_ledger WHERE event_type='void' AND target_nsr = {$nsrIn}")->fetchColumn()
          === 'anulacao em teste');

    echo "[3] Cadeia permanece íntegra após os fluxos administrativos\n";
    $v = nsr_ledger_verify($pdo);
    check('verificação da cadeia passa', $v['ok'], json_encode($v['errors']));
    check('sem buracos de NSR', empty($v['gaps']), json_encode($v['gaps']));

    echo "[4] Emissor legado não abre buraco na numeração fiscal\n";
    $liv0 = (int)$pdo->query("SELECT ledger_current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $pdo->beginTransaction();
    nsr_legacy_reserve($pdo);
    $pdo->commit();
    check('contador fiscal não avançou',
          (int)$pdo->query("SELECT ledger_current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn() === $liv0);

    // Limpeza (attendance é mutável; eventos do livro são imutáveis por desenho)
    foreach (array_filter($criados) as $id) {
        $pdo->prepare("DELETE FROM attendance_edits WHERE attendance_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM attendance WHERE id = ?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM attendance WHERE teacher_id = ?")->execute([$teacherId]);
    $pdo->prepare("DELETE FROM teacher_schools WHERE teacher_id = ?")->execute([$teacherId]);
    $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$teacherId]);

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_setting('ledger_mode', $modoOriginal);
    unset($_SESSION['admin_id']);
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

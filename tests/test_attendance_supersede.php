<?php
declare(strict_types=1);
/**
 * Edição de ponto por SUPERSEDE — NC-12 (Fase 3 da auditoria).
 *
 * Antes, editar um horário fazia `UPDATE attendance SET check_in = ...` sobre a
 * linha original: o registro legal com aquele NSR era sobrescrito e o valor
 * anterior sobrevivia apenas em `attendance_edits.before_json`. Perdido o log,
 * o original desaparecia — e a Portaria exige que a marcação original seja
 * preservada.
 *
 * Os dois testes que mais importam:
 *   [2] o registro original continua no banco, com os horários ORIGINAIS;
 *   [3] o novo registro herda a PROVA do original (foto, GPS, dispositivo).
 *       Sem isso, o supersede corrigiria a rastreabilidade destruindo a
 *       evidência — trocaria um problema por outro.
 *
 * Execução: php tests/test_attendance_supersede.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();
$adminId = (int)$pdo->query("SELECT id FROM admins WHERE role='network_admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$adminId) $adminId = (int)$pdo->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION['admin_id'] = $adminId;

$criados = [];
$teacherId = 0;

try {
    $schoolId = (int)$pdo->query("SELECT id FROM schools ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,1,NOW())")
        ->execute(['ZZ Teste Supersede', '00000000272']);
    $teacherId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teacher_schools (teacher_id, school_id) VALUES (?,?)")->execute([$teacherId, $schoolId]);
    $data = '2026-03-14';

    // Registro base com PROVA completa, como viria de uma marcação real.
    $pdo->beginTransaction();
    $nsrBase = nsr_legacy_reserve($pdo);
    $pdo->prepare("INSERT INTO attendance
        (teacher_id, school_id, date, check_in, check_out, method, approved, record_type, nsr,
         photo, check_in_lat, check_in_lng, check_in_acc, ip, user_agent,
         device_identifier, device_fingerprint, record_mode, recorded_at)
        VALUES (?,?,?,?,?,'pin',1,'work',?, ?,?,?,?,?,?,?,?,'online',?)")
        ->execute([$teacherId, $schoolId, $data, "$data 08:00:00", "$data 12:00:00", $nsrBase,
                   'foto_prova_original.jpg', -7.0252, -42.1311, 12.5, '203.0.113.9', 'AgenteDeTeste/1.0',
                   'devid-original-abc', 'fp-original-xyz', "$data 08:00:05"]);
    $idOriginal = (int)$pdo->lastInsertId();
    $criados[] = $idOriginal;
    $pdo->commit();

    echo "[1] Edição de horário cria um registro NOVO\n";
    $out = admin_save_attendance_day($pdo, $adminId, [
        'teacher_id' => $teacherId, 'orig_date' => $data, 'date' => $data,
        'reason' => 'correcao de horario em teste',
        'works'  => [['id' => $idOriginal, 'start' => '08:30', 'end' => '12:30']],
        'breaks' => [],
    ]);
    $idNovo = $out['works']['updated'][0] ?? null;
    $criados[] = $idNovo;
    check('a edição devolve um id de registro vigente', is_int($idNovo) && $idNovo > 0, json_encode($out['works']));
    check('o id vigente é DIFERENTE do original', $idNovo !== $idOriginal,
          "original={$idOriginal} vigente=" . var_export($idNovo, true));

    $orig = $pdo->query("SELECT * FROM attendance WHERE id = {$idOriginal}")->fetch(PDO::FETCH_ASSOC);
    $novo = $pdo->query("SELECT * FROM attendance WHERE id = " . (int)$idNovo)->fetch(PDO::FETCH_ASSOC);

    echo "[2] O registro ORIGINAL sobrevive intacto (NC-12)\n";
    check('original ainda existe no banco', $orig !== false);
    check('horário de entrada ORIGINAL preservado', $orig['check_in'] === "$data 08:00:00", (string)$orig['check_in']);
    check('horário de saída ORIGINAL preservado', $orig['check_out'] === "$data 12:00:00", (string)$orig['check_out']);
    check('original marcado como anulado', !empty($orig['removed_at']));
    check('original aponta para o SUCESSOR', (int)$orig['superseded_by_id'] === (int)$idNovo,
          'superseded_by_id=' . var_export($orig['superseded_by_id'], true));
    check('motivo da substituição registrado', !empty($orig['removed_reason']));
    check('NSR original inalterado', (int)$orig['nsr'] === (int)$nsrBase);

    echo "[3] O registro NOVO herda a prova do original (\$carryFrom)\n";
    check('novo tem os horários corrigidos',
          $novo['check_in'] === "$data 08:30:00" && $novo['check_out'] === "$data 12:30:00",
          "{$novo['check_in']} .. {$novo['check_out']}");
    check('foto preservada', $novo['photo'] === 'foto_prova_original.jpg', (string)$novo['photo']);
    check('GPS preservado', (float)$novo['check_in_lat'] === -7.0252 && (float)$novo['check_in_lng'] === -42.1311);
    check('precisão do GPS preservada', (float)$novo['check_in_acc'] === 12.5);
    check('IP preservado', $novo['ip'] === '203.0.113.9');
    check('user agent preservado', $novo['user_agent'] === 'AgenteDeTeste/1.0');
    check('identificador do dispositivo preservado', $novo['device_identifier'] === 'devid-original-abc');
    check('fingerprint do dispositivo preservado', $novo['device_fingerprint'] === 'fp-original-xyz');
    check('carimbo do servidor preservado', $novo['recorded_at'] === "$data 08:00:05");
    check('novo NÃO está anulado', empty($novo['removed_at']));
    check('novo tem NSR próprio, diferente do original', (int)$novo['nsr'] !== (int)$orig['nsr']);

    echo "[4] Jornada não é contada em dobro\n";
    $efetivo = calculate_effective_worked_minutes($pdo, $teacherId, $data);
    check('apenas o período vigente conta (240 min)', $efetivo === 240,
          "obtido {$efetivo} — se der 480, o anulado está sendo somado junto");

    echo "[5] Restaurar o substituído é recusado (evita duplicar o período)\n";
    try {
        admin_restore_attendance($pdo, $idOriginal, $adminId, 'tentativa em teste');
        check('restauração do substituído é bloqueada', false, 'a restauração passou');
    } catch (RuntimeException $e) {
        check('restauração do substituído é bloqueada', true);
        check('mensagem indica o sucessor', str_contains($e->getMessage(), '#' . (int)$idNovo), $e->getMessage());
    }

    echo "[6] Remoção comum continua restaurável\n";
    admin_remove_attendance($pdo, (int)$idNovo, $adminId, 'remocao comum em teste');
    $rem = $pdo->query("SELECT removed_at, superseded_by_id FROM attendance WHERE id = " . (int)$idNovo)->fetch(PDO::FETCH_ASSOC);
    check('removido aponta para si mesmo', (int)$rem['superseded_by_id'] === (int)$idNovo);
    $r = admin_restore_attendance($pdo, (int)$idNovo, $adminId, 'restauracao em teste');
    check('restauração de remoção comum funciona', in_array((int)$idNovo, $r['restored'] ?? [], true));

    echo "[7] Auditoria distingue substituição de remoção\n";
    $tipos = $pdo->query("SELECT type FROM attendance_edits WHERE attendance_id = {$idOriginal}")->fetchAll(PDO::FETCH_COLUMN);
    check('edição do original registrada como "superseded"', in_array('superseded', $tipos, true),
          json_encode($tipos));

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($teacherId) {
        foreach (array_filter($criados) as $id) {
            $pdo->prepare("DELETE FROM attendance_edits WHERE attendance_id = ?")->execute([$id]);
        }
        $pdo->prepare("DELETE FROM hour_bank_entries WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM attendance WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM teacher_schools WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$teacherId]);
    }
    unset($_SESSION['admin_id']);
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

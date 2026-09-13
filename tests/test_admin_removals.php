<?php
declare(strict_types=1);
/**
 * Testa as remoções auditáveis do admin (lógica de backend, determinística):
 *   admin_remove_attendance / admin_restore_attendance / admin_set_collaborator_active.
 * Cobre: anular intervalo, anular ponto com cascata de intervalos filhos, exclusão
 * da folha (superseded), reversibilidade, motivo obrigatório, auditoria, e
 * inativar/reativar colaborador.
 *
 * Como rodar:  php tests\test_admin_removals.php
 */
require_once __DIR__ . '/../config.php';

$failed = 0; $passed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "  [OK]   $label\n"; }
    else { $failed++; echo "  [FAIL] $label" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$pdo = db();
$CPF = '00000000444';
$ADMIN = 1; // adminId fictício; em CLI admin_scope_where retorna 1=1 (sem sessão)

$cleanup = function () use ($pdo, $CPF) {
    foreach ($pdo->query("SELECT id FROM teachers WHERE cpf='$CPF'")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        $ids = $pdo->query("SELECT id FROM attendance WHERE teacher_id=$tid")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) { $in = implode(',', array_map('intval', $ids));
            try { $pdo->exec("DELETE FROM attendance_audit_log WHERE attendance_id IN ($in)"); } catch (Throwable $e) {}
            try { $pdo->exec("DELETE FROM attendance_edits WHERE attendance_id IN ($in)"); } catch (Throwable $e) {}
            try { $pdo->exec("DELETE FROM audit_logs WHERE entity='attendance' AND entity_id IN ($in)"); } catch (Throwable $e) {}
        }
        $pdo->prepare("DELETE FROM hour_bank_entries WHERE teacher_id=?")->execute([$tid]);
        $pdo->prepare("DELETE FROM attendance WHERE teacher_id=?")->execute([$tid]);
        try { $pdo->prepare("DELETE FROM audit_logs WHERE entity='teacher' AND entity_id=?")->execute([$tid]); } catch (Throwable $e) {}
    }
    $pdo->prepare("DELETE FROM teachers WHERE cpf=?")->execute([$CPF]);
};
$cleanup();

echo "=== Remoções auditáveis do admin ===\n\n";

$pdo->prepare("INSERT INTO teachers(name,cpf,active) VALUES('__rem_test',?,1)")->execute([$CPF]);
$tid = (int)$pdo->lastInsertId();
// A produção tem attendance.nsr NOT NULL: gera um NSR único por inserção (padrão atômico do nsr_sequence).
$nextNsr = function () use ($pdo): int {
    $cur = (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $n = $cur + 1;
    $pdo->prepare("UPDATE nsr_sequence SET current_nsr=? WHERE id=1")->execute([$n]);
    return $n;
};
$insWork = function ($ci, $co) use ($pdo, $tid, $nextNsr) {
    $pdo->prepare("INSERT INTO attendance(teacher_id,date,check_in,check_out,method,record_type,approved,nsr) VALUES(?,CURDATE(),?,?,'manual','work',1,?)")->execute([$tid, $ci, $co, $nextNsr()]);
    return (int)$pdo->lastInsertId();
};
$insBreak = function ($ci, $co, $parent) use ($pdo, $tid, $nextNsr) {
    $pdo->prepare("INSERT INTO attendance(teacher_id,date,check_in,check_out,method,record_type,parent_attendance_id,approved,nsr) VALUES(?,CURDATE(),?,?,'manual','break',?,1,?)")->execute([$tid, $ci, $co, $parent, $nextNsr()]);
    return (int)$pdo->lastInsertId();
};
$d = date('Y-m-d');
$w1 = $insWork("$d 08:00:00", "$d 12:00:00");
$b1 = $insBreak("$d 12:00:00", "$d 13:00:00", $w1);
$w2 = $insWork("$d 13:00:00", "$d 17:00:00");

$active = fn() => (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE teacher_id=$tid AND superseded_by_id IS NULL")->fetchColumn();
$col = fn($id, $c) => $pdo->query("SELECT $c FROM attendance WHERE id=$id")->fetchColumn();
$auditCount = fn($act, $eid) => (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='$act' AND entity_id='$eid'")->fetchColumn();

echo "[1] anular INTERVALO\n";
check('baseline: 3 registros ativos', $active() === 3);
$r = admin_remove_attendance($pdo, $b1, $ADMIN, 'intervalo lançado errado');
check('break removido (1)', ($r['removed'] ?? []) === [$b1], json_encode($r));
check('break.removed_at preenchido', !empty($col($b1, 'removed_at')));
check('break.approved = 0', (int)$col($b1, 'approved') === 0);
check('break excluído da folha (superseded)', $active() === 2);
check('auditoria admin_remove gravada', $auditCount('admin_remove', $b1) === 1);

echo "[2] restaurar INTERVALO\n";
$r = admin_restore_attendance($pdo, $b1, $ADMIN, 'reverter');
check('break restaurado', ($r['restored'] ?? []) === [$b1]);
check('break.removed_at NULL', $col($b1, 'removed_at') === null);
check('volta a 3 ativos', $active() === 3);
check('auditoria admin_restore gravada', $auditCount('admin_restore', $b1) === 1);

echo "[3] anular PONTO (work) com cascata do intervalo filho\n";
$r = admin_remove_attendance($pdo, $w1, $ADMIN, 'ponto duplicado');
$rem = $r['removed'] ?? [];
check('removeu w1 e o filho b1 (cascata)', in_array($w1, $rem, true) && in_array($b1, $rem, true), json_encode($rem));
check('w1.removed_at preenchido', !empty($col($w1, 'removed_at')));
check('b1.removed_at preenchido (filho)', !empty($col($b1, 'removed_at')));
check('sobra só w2 ativo', $active() === 1);

echo "[4] motivo obrigatório\n";
$threw = false;
try { admin_remove_attendance($pdo, $w2, $ADMIN, '   '); } catch (RuntimeException $e) { $threw = true; }
check('remoção sem motivo lança exceção', $threw);
check('w2 segue ativo', $active() === 1);

echo "[5] inativar / reativar COLABORADOR\n";
$r = admin_set_collaborator_active($pdo, $tid, $ADMIN, false, 'desligado');
check('inativado (active=0)', $r['active'] === 0 && $r['changed'] === true);
check('teacher.active=0 no banco', (int)$pdo->query("SELECT active FROM teachers WHERE id=$tid")->fetchColumn() === 0);
check('auditoria deactivate', $auditCount('deactivate', $tid) === 1);
$threw = false;
try { admin_set_collaborator_active($pdo, $tid, $ADMIN, false, ''); } catch (RuntimeException $e) { $threw = true; }
check('inativar sem motivo lança exceção', $threw);
$r = admin_set_collaborator_active($pdo, $tid, $ADMIN, true);
check('reativado (active=1)', $r['active'] === 1);

$cleanup();
echo "\n=== Resultado ===\nPassed: $passed\nFailed: $failed\n";
exit($failed > 0 ? 1 : 0);

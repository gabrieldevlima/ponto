<?php
/**
 * Regressão: aprovar/alterar um ponto DEPOIS do checkout deve ressincronizar o
 * lançamento automático do banco de horas (source='auto').
 *
 * Bug original (caso Amélia 02/06): saída batida com ponto PENDENTE → no
 * checkout worked=0 → banco grava −jornada inteira (−360). Admin aprova no dia
 * seguinte e nada recalculava — o débito ficava fossilizado, enquanto as telas
 * ao vivo mostravam o dia correto (+28 min de extrapolação).
 *
 * Como rodar: php tests\test_hour_bank_recalc_on_approval.php
 * Roda em transação com rollback — não polui dados.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pdo = db();
$failed = 0;
$passed = 0;

// A produção tem attendance.nsr NOT NULL: gera um NSR único por inserção (padrão atômico do nsr_sequence).
$nextNsr = function () use ($pdo): int {
    $cur = (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $n = $cur + 1;
    $pdo->prepare("UPDATE nsr_sequence SET current_nsr=? WHERE id=1")->execute([$n]);
    return $n;
};

function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "  [OK]   $label\n"; }
    else { $failed++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== Regressão: recálculo do banco de horas pós-aprovação ===\n\n";

$pdo->beginTransaction();

try {
    // Setup: tipo 'time', jornada terça (weekday=2) 07:00–13:00 (360 min, break 0)
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'time', 1)")
        ->execute(['__test_recalc_type', '__test_recalc_type']);
    $typeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_recalc_user', '00000000089', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, 2, '07:00:00', '13:00:00', 0, 0)")
        ->execute([$teacherId]);

    $date = '2026-06-02'; // terça-feira

    // Reproduz o estado do bug: ponto PENDENTE (como ficou no checkout)...
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', NULL, 'manual', ?)")
        ->execute([$teacherId, $date, "$date 07:04:38", "$date 13:33:17", $nextNsr()]);
    $attId = (int)$pdo->lastInsertId();

    // ...e o lançamento fossilizado que o checkout gravou (worked=0 na época).
    $pdo->prepare("INSERT INTO hour_bank_entries (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id) VALUES (?, NULL, ?, -360, 'Recalculo diário automático', 'auto', ?)")
        ->execute([$teacherId, $date, $attId]);

    $getBank = function () use ($pdo, $teacherId, $date) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(minutes), 0) FROM hour_bank_entries WHERE teacher_id=? AND date=? AND source='auto'");
        $st->execute([$teacherId, $date]);
        return (int)$st->fetchColumn();
    };

    check('setup: banco fossilizado em -360', $getBank() === -360, 'obtido: ' . $getBank());

    // --- Cenário 0: dia ainda PENDENTE → banco fica NEUTRO (0), não -360 ---
    echo "\nCenário 0: dia pendente é neutro no banco\n";
    recalculate_hour_bank_for_attendance($pdo, $attId);
    check('banco do dia pendente vira 0 (débito só após decisão do admin)', $getBank() === 0, 'obtido: ' . $getBank());

    // --- Cenário 1: admin APROVA o ponto → banco deve ressincronizar ---
    echo "\nCenário 1: aprovação pós-checkout\n";
    $pdo->prepare("UPDATE attendance SET approved = 1 WHERE id = ?")->execute([$attId]);
    recalculate_hour_bank_for_attendance($pdo, $attId);

    // worked 07:04→13:33 = 388, expected 360, delta +28 (> tolerância) → banco 0
    // (recompute_day_hour_bank apaga a linha quando o valor é 0).
    check('banco do dia vira 0 (delta +28 acima da carga não gera saldo)', $getBank() === 0, 'obtido: ' . $getBank());

    // --- Cenário 2: admin REJEITA o ponto → sistema NÃO registra saldo negativo ---
    // Jornada flexível: déficit deixou de ser gravado no banco de horas (é apenas
    // informativo, calculado por mês). Rejeitar o ponto mantém o banco em 0.
    echo "\nCenário 2: rejeição pós-checkout (sem saldo negativo)\n";
    $pdo->prepare("UPDATE attendance SET approved = 0 WHERE id = ?")->execute([$attId]);
    recalculate_hour_bank_for_attendance($pdo, $attId);
    check('banco do dia permanece 0 (sistema não grava mais déficit)', $getBank() === 0, 'obtido: ' . $getBank());

    // --- Cenário 3: re-aprovação → ressincroniza de novo ---
    echo "\nCenário 3: re-aprovação\n";
    $pdo->prepare("UPDATE attendance SET approved = 1 WHERE id = ?")->execute([$attId]);
    recalculate_hour_bank_for_attendance($pdo, $attId);
    check('banco do dia volta a 0', $getBank() === 0, 'obtido: ' . $getBank());

    // --- Cenário 4: attendance_id inexistente não explode ---
    echo "\nCenário 4: id inexistente é inofensivo\n";
    recalculate_hour_bank_for_attendance($pdo, 99999999);
    check('nenhuma exceção lançada', true);

    echo "\n=== Resultado ===\n";
    echo "Passed: $passed\nFailed: $failed\n";

    $pdo->rollBack();
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(2);
}

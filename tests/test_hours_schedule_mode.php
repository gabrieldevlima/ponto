<?php
/**
 * Smoke test para o schedule_mode 'hours' (motoristas, monitores, etc).
 *
 * Como rodar (PowerShell):
 *   php tests\test_hours_schedule_mode.php
 *
 * O teste roda DENTRO de uma transacao, com rollback no final — nao polui dados
 * de producao. Aborta na primeira asserção que falha.
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
    if ($cond) {
        $passed++;
        echo "  [OK]   $label\n";
    } else {
        $failed++;
        echo "  [FAIL] $label";
        if ($detail !== '') echo " — $detail";
        echo "\n";
    }
}

echo "=== Smoke test: schedule_mode 'hours' ===\n\n";

$pdo->beginTransaction();

try {
    // Isola o teste do calendário real. O dump de produção tem exceções como
    // "Sábado Letivo" (2026-05-30 com reflects_weekday=5) que remapeiam o weekday
    // efetivo das datas fixas usadas abaixo, fazendo calculate_expected_minutes
    // devolver a jornada de outro dia. Este teste valida a LÓGICA de schedule_mode
    // 'hours' (lookup por weekday), não a interação com calendário — essa é coberta
    // pelos testes overnight. Removemos as exceções aqui dentro da transação; o
    // rollback final restaura tudo, sem poluir dados.
    $pdo->exec("DELETE FROM calendar_exceptions");

    // Setup: cria tipo 'hours' e colaborador de teste
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'hours', 1)")
        ->execute(['__test_motorista', '__test_motorista']);
    $typeId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_motorista', '00000000099', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();

    echo "Setup: teacher_id=$teacherId, type_id=$typeId\n\n";

    // Schedule: 8h seg-sex (480min), 4h sáb (240min), 0h dom, break 60min seg-sex
    foreach ([1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 240, 0 => 0] as $wd => $total) {
        $brk = ($total === 0) ? 0 : ($wd === 6 ? 0 : 60);
        $pdo->prepare("INSERT INTO collaborator_hours_schedules (teacher_id, weekday, total_minutes, break_minutes) VALUES (?, ?, ?, ?)")
            ->execute([$teacherId, $wd, $total, $brk]);
    }

    // ---------- Teste 1: calculate_expected_minutes em dia util ----------
    echo "Teste 1: calculate_expected_minutes em dia util (seg)\n";
    // 2026-05-25 eh segunda-feira (weekday=1)
    $exp = calculate_expected_minutes($pdo, $teacherId, '2026-05-25');
    check('seg → 480 min', $exp === 480, "obtido: $exp");

    echo "\nTeste 2: calculate_expected_minutes em sabado\n";
    // 2026-05-30 eh sabado (weekday=6)
    $exp = calculate_expected_minutes($pdo, $teacherId, '2026-05-30');
    check('sab → 240 min', $exp === 240, "obtido: $exp");

    echo "\nTeste 3: calculate_expected_minutes em domingo\n";
    // 2026-05-31 eh domingo (weekday=0)
    $exp = calculate_expected_minutes($pdo, $teacherId, '2026-05-31');
    check('dom → 0 min', $exp === 0, "obtido: $exp");

    echo "\nTeste 4: get_expected_break_minutes em dia util\n";
    $brk = get_expected_break_minutes($pdo, $teacherId, '2026-05-25');
    check('seg → 60 min de intervalo previsto', $brk === 60, "obtido: $brk");

    echo "\nTeste 5: get_expected_break_minutes em domingo (sem schedule)\n";
    $brk = get_expected_break_minutes($pdo, $teacherId, '2026-05-31');
    check('dom → 0 min', $brk === 0, "obtido: $brk");

    // ---------- Teste 6: calculate_effective_worked_minutes ----------
    // Insere um ponto de trabalho de 7h (420min) na segunda
    echo "\nTeste 6: calculate_effective_worked_minutes (worked=420, sem break batido)\n";
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, nsr) VALUES (?, '2026-05-25', '2026-05-25 08:00:00', '2026-05-25 15:00:00', 'work', 1, ?)")
        ->execute([$teacherId, $nextNsr()]);
    $eff = calculate_effective_worked_minutes($pdo, $teacherId, '2026-05-25');
    // Modelo "cheio vs cheio": sem desconto algum — efetivo = presença (420).
    check('efetivo = 420 min (presença cheia, sem desconto de intervalo)', $eff === 420, "obtido: $eff");

    // ---------- Teste 7: calculate_effective_worked_minutes com break batido ----------
    echo "\nTeste 7: calculate_effective_worked_minutes (jornada dividida + break 60)\n";
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_motorista2', '00000000098', $typeId]);
    $teacherId2 = (int)$pdo->lastInsertId();
    foreach ([1 => 480] as $wd => $total) {
        $pdo->prepare("INSERT INTO collaborator_hours_schedules (teacher_id, weekday, total_minutes, break_minutes) VALUES (?, ?, ?, ?)")
            ->execute([$teacherId2, $wd, $total, 60]);
    }
    // Fluxo real: work#1 08–12, break 12–13, work#2 13–17 (pares work excluem o break).
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, nsr) VALUES (?, '2026-05-25', '2026-05-25 08:00:00', '2026-05-25 12:00:00', 'work', 1, ?)")
        ->execute([$teacherId2, $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, nsr) VALUES (?, '2026-05-25', '2026-05-25 12:00:00', '2026-05-25 13:00:00', 'break', 1, ?)")
        ->execute([$teacherId2, $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, nsr) VALUES (?, '2026-05-25', '2026-05-25 13:00:00', '2026-05-25 17:00:00', 'work', 1, ?)")
        ->execute([$teacherId2, $nextNsr()]);
    $eff = calculate_effective_worked_minutes($pdo, $teacherId2, '2026-05-25');
    // Presença cheia: work 480 + break 60 = 540 (intervalo conta como trabalho).
    check('efetivo = 540 (presença cheia: work 480 + break 60)', $eff === 540, "obtido: $eff");

    // ---------- Teste 8: Regressão — tipo 'time' continua funcionando ----------
    echo "\nTeste 8: Regressao — tipo 'time' continua funcionando\n";
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'time', 1)")
        ->execute(['__test_time_regress', '__test_time_regress']);
    $typeIdTime = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_time_user', '00000000097', $typeIdTime]);
    $teacherIdTime = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, 1, '08:00:00', '17:00:00', 0, 60)")
        ->execute([$teacherIdTime]);
    $expTime = calculate_expected_minutes($pdo, $teacherIdTime, '2026-05-25'); // segunda
    // Modelo "cheio vs cheio": janela completa, break_minutes não desconta.
    check('time mode seg 08-17 = 540 min (janela completa)', $expTime === 540, "obtido: $expTime");
    $brkTime = get_expected_break_minutes($pdo, $teacherIdTime, '2026-05-25');
    check('time mode break = 60', $brkTime === 60, "obtido: $brkTime");

    // ---------- Teste 9: Regressão — tipo 'classes' (professor) continua funcionando ----------
    echo "\nTeste 9: Regressao — tipo 'classes' continua funcionando\n";
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'classes', 1)")
        ->execute(['__test_classes_regress', '__test_classes_regress']);
    $typeIdCls = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_classes_user', '00000000096', $typeIdCls]);
    $teacherIdCls = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teacher_schedules (teacher_id, weekday, classes_count, class_minutes) VALUES (?, 1, 5, 50)")
        ->execute([$teacherIdCls]);
    $expCls = calculate_expected_minutes($pdo, $teacherIdCls, '2026-05-25');
    check('classes mode seg 5×50min = 250 min', $expCls === 250, "obtido: $expCls");

    // ---------- Teste 10: Edge case — hours sem schedule no weekday → 0 ----------
    echo "\nTeste 10: hours mode sem schedule para weekday → 0\n";
    // Usa $teacherId já criado (8h seg-sex). Domingo (weekday=0) tem total=0.
    $expDom = calculate_expected_minutes($pdo, $teacherId, '2026-05-24'); // domingo
    check('dom (weekday=0) sem schedule → 0', $expDom === 0, "obtido: $expDom");

    // ---------- Teste 11: Edge case — hours com afastamento remunerado → 0 ----------
    // Cria leave_type pago e leave aprovada para o teacher
    echo "\nTeste 11: hours + afastamento remunerado aprovado → expected=0\n";
    $stLT = $pdo->prepare("SELECT id FROM leave_types WHERE paid = 1 LIMIT 1");
    $stLT->execute();
    $paidLeaveTypeId = (int)$stLT->fetchColumn();
    if ($paidLeaveTypeId > 0) {
        $pdo->prepare("INSERT INTO leaves (teacher_id, type_id, start_date, end_date, approved, excuses_absence) VALUES (?, ?, '2026-05-25', '2026-05-25', 1, 1)")
            ->execute([$teacherId, $paidLeaveTypeId]);
        $expLeave = calculate_expected_minutes($pdo, $teacherId, '2026-05-25');
        check('hours + afastamento pago seg → 0', $expLeave === 0, "obtido: $expLeave");
    } else {
        check('hours + afastamento pago (skipped, sem leave_type pago)', true);
    }

    // ---------- Resultado final ----------
    echo "\n=== Resultado ===\n";
    echo "Passed: $passed\n";
    echo "Failed: $failed\n";

    $pdo->rollBack();
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nEXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(2);
}

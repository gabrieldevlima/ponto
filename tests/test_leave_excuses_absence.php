<?php
/**
 * Smoke test: leaves.excuses_absence controla a zeragem da jornada prevista,
 * desacoplado de leave_types.paid e acoplado a leaves.approved.
 *
 * Rodar: php tests\test_leave_excuses_absence.php
 * Roda dentro de transacao com rollback — nao polui dados.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pdo = db();
$failed = 0;
$passed = 0;

function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "  [OK]   $label\n"; }
    else { $failed++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== Smoke test: leaves.excuses_absence ===\n\n";

$pdo->beginTransaction();
try {
    // Isola do calendario real (mesma razao do teste de hours).
    $pdo->exec("DELETE FROM calendar_exceptions");

    // Colaborador 'hours' com 480min na segunda (weekday=1).
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'hours', 1)")
        ->execute(['__test_excuses', '__test_excuses']);
    $typeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_excuses', '00000000091', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_hours_schedules (teacher_id, weekday, total_minutes, break_minutes) VALUES (?, 1, 480, 0)")
        ->execute([$teacherId]);

    // Dois tipos de afastamento: um pago, um nao pago (controla decoupling).
    $pdo->prepare("INSERT INTO leave_types (name, code, paid, affects_bank, active) VALUES (?, ?, 1, 0, 1)")
        ->execute(['__test_lt_paid', '__test_lt_paid']);
    $ltPaid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO leave_types (name, code, paid, affects_bank, active) VALUES (?, ?, 0, 0, 1)")
        ->execute(['__test_lt_unpaid', '__test_lt_unpaid']);
    $ltUnpaid = (int)$pdo->lastInsertId();

    $MON = '2026-05-25'; // segunda

    $insLeave = function (int $type, int $approved, $excuses) use ($pdo, $teacherId, $MON): int {
        $pdo->prepare("INSERT INTO leaves (teacher_id, type_id, start_date, end_date, approved, excuses_absence) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$teacherId, $type, $MON, $MON, $approved, $excuses]);
        return (int)$pdo->lastInsertId();
    };
    $clearLeaves = function () use ($pdo, $teacherId): void {
        $pdo->prepare("DELETE FROM leaves WHERE teacher_id=?")->execute([$teacherId]);
    };

    echo "Teste 1: baseline sem afastamento → 480\n";
    check('seg → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);

    echo "\nTeste 2: aprovado + abona (tipo pago) → 0\n";
    $insLeave($ltPaid, 1, 1);
    check('abona=1 (pago) → 0', calculate_expected_minutes($pdo, $teacherId, $MON) === 0);
    $clearLeaves();

    echo "\nTeste 3: aprovado + NAO abona (tipo pago) → 480 (decoupling de paid)\n";
    $insLeave($ltPaid, 1, 0);
    check('abona=0 mesmo pago → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);
    $clearLeaves();

    echo "\nTeste 4: aprovado + abona (tipo NAO pago) → 0 (decoupling de paid)\n";
    $insLeave($ltUnpaid, 1, 1);
    check('abona=1 mesmo nao pago → 0', calculate_expected_minutes($pdo, $teacherId, $MON) === 0);
    $clearLeaves();

    echo "\nTeste 5: NAO aprovado + abona → 480 (acoplamento com approved)\n";
    $insLeave($ltPaid, 0, 1);
    check('abona=1 mas pendente/rejeitado → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);
    $clearLeaves();

    echo "\nTeste 6: helper leave_day_is_excused\n";
    check('vazio → false', leave_day_is_excused([]) === false);
    check('[excuses=0] → false', leave_day_is_excused([['excuses_absence' => 0]]) === false);
    check('[excuses=1] → true', leave_day_is_excused([['excuses_absence' => 0], ['excuses_absence' => 1]]) === true);
    check('chave ausente → false', leave_day_is_excused([['paid' => 1]]) === false);

    echo "\n=== Resultado ===\nPassed: $passed\nFailed: $failed\n";
    $pdo->rollBack();
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(2);
}

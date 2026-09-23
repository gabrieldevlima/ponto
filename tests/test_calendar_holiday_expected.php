<?php
/**
 * Smoke test: exceções de calendário NÃO-úteis (feriado, ponto facultativo,
 * recesso — qualquer linha com is_working_day=0) zeram a jornada prevista,
 * respeitando a escola do colaborador (school_id específico OU rede inteira).
 *
 * Cobre o bug em que feriado/ponto facultativo cadastrados para uma escola
 * específica eram ignorados nos relatórios e o dia caía como FALTA.
 *
 * Rodar: php tests\test_calendar_holiday_expected.php
 * Roda dentro de transação com rollback — não polui dados.
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

echo "=== Smoke test: feriado/ponto facultativo zeram jornada prevista ===\n\n";

$pdo->beginTransaction();
try {
    // Isola do calendário real.
    $pdo->exec("DELETE FROM calendar_exceptions");

    // Duas escolas: a do colaborador e uma "outra".
    $pdo->prepare("INSERT INTO schools (name, code, active) VALUES (?, ?, 1)")
        ->execute(['__test_school_A', '__test_A']);
    $schoolA = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO schools (name, code, active) VALUES (?, ?, 1)")
        ->execute(['__test_school_B', '__test_B']);
    $schoolB = (int)$pdo->lastInsertId();

    // Colaborador 'hours' com 480min na segunda (weekday=1), lotado na escola A.
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'hours', 1)")
        ->execute(['__test_holiday', '__test_holiday']);
    $typeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_holiday', '00000000092', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_hours_schedules (teacher_id, weekday, total_minutes, break_minutes) VALUES (?, 1, 480, 0)")
        ->execute([$teacherId]);
    $pdo->prepare("INSERT INTO teacher_schools (teacher_id, school_id) VALUES (?, ?)")
        ->execute([$teacherId, $schoolA]);

    $MON = '2026-05-25'; // segunda

    $insExc = function (?int $school, string $type, int $isWorkday) use ($pdo, $MON): void {
        $pdo->prepare("INSERT INTO calendar_exceptions (school_id, date, type, name, recurrence, is_working_day) VALUES (?, ?, ?, ?, 'none', ?)")
            ->execute([$school, $MON, $type, strtoupper($type), $isWorkday]);
    };
    $clearExc = function () use ($pdo): void {
        $pdo->exec("DELETE FROM calendar_exceptions");
    };

    echo "Teste 1: baseline sem exceção → 480\n";
    check('seg → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);

    echo "\nTeste 2: feriado da REDE (school_id NULL, is_working_day=0) → 0\n";
    $insExc(null, 'holiday', 0);
    check('feriado rede → 0', calculate_expected_minutes($pdo, $teacherId, $MON) === 0);
    $clearExc();

    echo "\nTeste 3: feriado da ESCOLA do colaborador (school A) → 0\n";
    $insExc($schoolA, 'holiday', 0);
    check('feriado escola A → 0', calculate_expected_minutes($pdo, $teacherId, $MON) === 0);
    $clearExc();

    echo "\nTeste 4: ponto facultativo (holiday, is_working_day=0) escola A → 0\n";
    $insExc($schoolA, 'holiday', 0);
    check('ponto facultativo escola A → 0', calculate_expected_minutes($pdo, $teacherId, $MON) === 0);
    $clearExc();

    echo "\nTeste 5: feriado de OUTRA escola (school B) → 480 (não aplica)\n";
    $insExc($schoolB, 'holiday', 0);
    check('feriado escola B → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);
    $clearExc();

    echo "\nTeste 6: exceção que É dia útil (is_working_day=1) NÃO zera → 480\n";
    $insExc($schoolA, 'academic_event', 1);
    check('evento útil → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);
    $clearExc();

    echo "\nTeste 7: helper calendar_day_is_off\n";
    $insExc($schoolA, 'holiday', 0);
    check('off p/ escola A → true', calendar_day_is_off($pdo, $MON, $schoolA) === true);
    check('off p/ rede (null) → false (exceção é da escola A)', calendar_day_is_off($pdo, $MON, null) === false);
    check('off p/ escola B → false', calendar_day_is_off($pdo, $MON, $schoolB) === false);
    $clearExc();
    $insExc(null, 'holiday', 0);
    check('feriado rede: off p/ qualquer escola → true', calendar_day_is_off($pdo, $MON, $schoolA) === true);
    $clearExc();

    echo "\n=== Resultado ===\nPassed: $passed\nFailed: $failed\n";
    $pdo->rollBack();
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(2);
}

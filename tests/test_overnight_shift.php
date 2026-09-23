<?php
/**
 * Smoke test standalone para suporte a turnos noturnos.
 *
 * Como rodar (PowerShell):
 *   php tests\test_overnight_shift.php
 *
 * Pré-requisitos:
 *   1. Banco `ponto_oeiras2` instalado com schema atualizado.
 *   2. Migration `sql/migrations/2026_05_13_add_end_next_day.sql` aplicada.
 *
 * O teste roda DENTRO de uma transação, com rollback no final — não polui dados
 * de produção. Aborta na primeira asserção que falha.
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

echo "=== Smoke test: turnos noturnos ===\n\n";

$pdo->beginTransaction();

try {
    // ---------- Setup: cria collaborator_type 'time' e teacher fictício ----------
    $stCt = $pdo->prepare("SELECT id FROM collaborator_types WHERE schedule_mode='time' LIMIT 1");
    $stCt->execute();
    $typeId = (int)$stCt->fetchColumn();
    if (!$typeId) {
        $pdo->prepare("INSERT INTO collaborator_types (name, schedule_mode, requires_schedule) VALUES (?, 'time', 1)")
            ->execute(['__test_vigilante']);
        $typeId = (int)$pdo->lastInsertId();
    }

    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_overnight', '00000000000', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();

    echo "Setup: teacher_id=$teacherId, type_id=$typeId\n\n";

    // ---------- Teste 1: schedule 18h → 06h com end_next_day=1 ----------
    echo "Teste 1: cadastro de turno noturno 18h → 06h (end_next_day=1)\n";
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, 1, '18:00:00', '06:00:00', 1, 0)")
        ->execute([$teacherId]);

    $win = compute_schedule_window([
        'start_time' => '18:00:00',
        'end_time' => '06:00:00',
        'end_next_day' => 1,
        'break_minutes' => 0,
    ], '2026-05-13');
    check('compute_schedule_window retorna janela válida', $win !== null);
    check('crossesMidnight = true', $win['crossesMidnight'] === true);
    $minutes = ($win['end']->getTimestamp() - $win['start']->getTimestamp()) / 60;
    check('duração = 720 minutos (12h)', $minutes == 720, "obtido: $minutes");
    check('end aponta para o dia seguinte', $win['end']->format('Y-m-d') === '2026-05-14', "obtido: " . $win['end']->format('Y-m-d'));

    // 2026-05-13 é uma quarta-feira (weekday=3), não segunda (weekday=1).
    // Vou usar uma data de segunda real: 2026-05-11 (segunda).
    $monday = '2026-05-11';
    $expected = calculate_expected_minutes($pdo, $teacherId, $monday);
    check('calculate_expected_minutes(segunda) = 720', $expected === 720, "obtido: $expected");

    // ---------- Teste 2: turno de 24h (08h → 08h, end_next_day=1) ----------
    echo "\nTeste 2: turno de 24h (08h → 08h)\n";
    $win24 = compute_schedule_window([
        'start_time' => '08:00:00',
        'end_time' => '08:00:00',
        'end_next_day' => 1,
        'break_minutes' => 0,
    ], '2026-05-13');
    check('janela retornada', $win24 !== null);
    $minutes24 = ($win24['end']->getTimestamp() - $win24['start']->getTimestamp()) / 60;
    check('duração = 1440 minutos (24h)', $minutes24 == 1440, "obtido: $minutes24");

    // ---------- Teste 3: turno diurno normal (08h → 17h, end_next_day=0) ----------
    echo "\nTeste 3: turno diurno normal (08h → 17h)\n";
    $winDay = compute_schedule_window([
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'end_next_day' => 0,
        'break_minutes' => 60,
    ], '2026-05-13');
    check('crossesMidnight = false', $winDay['crossesMidnight'] === false);
    $minutesDay = ($winDay['end']->getTimestamp() - $winDay['start']->getTimestamp()) / 60 - $winDay['breakMin'];
    check('duração líquida = 480 minutos (8h)', $minutesDay == 480, "obtido: $minutesDay");

    // ---------- Teste 4: legado sem flag (end_time <= start_time) ainda cruza ----------
    echo "\nTeste 4: compatibilidade legada (sem flag, end <= start)\n";
    $winLegacy = compute_schedule_window([
        'start_time' => '22:00:00',
        'end_time' => '04:00:00',
        // sem end_next_day
        'break_minutes' => 0,
    ], '2026-05-13');
    check('detecta heuristicamente como noturno', $winLegacy['crossesMidnight'] === true);
    $minutesLegacy = ($winLegacy['end']->getTimestamp() - $winLegacy['start']->getTimestamp()) / 60;
    check('duração = 360 minutos (6h)', $minutesLegacy == 360, "obtido: $minutesLegacy");

    // ---------- Teste 5: calculate_worked_minutes com check_in/check_out cruzando ----------
    echo "\nTeste 5: calculate_worked_minutes com check_out no dia seguinte\n";
    // Insere registro: entrada 2026-05-11 22:00, saída 2026-05-12 02:00
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, method, approved, nsr) VALUES (?, '2026-05-11', '2026-05-11 22:00:00', '2026-05-12 02:00:00', 'manual', 1, ?)")
        ->execute([$teacherId, $nextNsr()]);
    $worked = calculate_worked_minutes($pdo, $teacherId, '2026-05-11');
    check('worked = 240 minutos (4h)', $worked === 240, "obtido: $worked");

    // ---------- Resultado final ----------
    echo "\n=== Resultado: $passed passou(aram), $failed falhou(aram) ===\n";
} catch (Throwable $e) {
    echo "\nErro inesperado: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        echo "(transação revertida — nenhum dado persistido)\n";
    }
}

exit($failed > 0 ? 1 : 0);

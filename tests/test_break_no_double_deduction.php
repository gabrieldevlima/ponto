<?php
/**
 * Regressão: o intervalo NUNCA subtrai o tempo trabalhado (modelo "cheio vs cheio").
 *
 * Como rodar (PowerShell):
 *   php tests\test_break_no_double_deduction.php
 *
 * Regra de negócio: o intervalo é um direito do colaborador (almoço/descanso) e
 * conta como tempo trabalhado — é apenas informativo. Logo:
 *   - effective = presença CHEIA (pares work + pares break, mesmo pendentes)
 *   - expected  = janela COMPLETA da jornada (break_minutes é informativo)
 *   - registrar intervalo (pendente ou aprovado) não pode gerar saldo negativo
 *
 * Roda dentro de transação com rollback no final — não polui dados de produção.
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

echo "=== Regressão: intervalo não subtrai o tempo trabalhado ===\n\n";

$pdo->beginTransaction();

try {
    // Tipo 'time' + colaborador
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'time', 1)")
        ->execute(['__test_break_time', '__test_break_time']);
    $typeId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_break_user', '00000000091', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();

    // Jornada segunda (weekday=1): 08:00–17:00. break_minutes=60 é INFORMATIVO —
    // a prevista é a janela completa (540 min).
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, 1, '08:00:00', '17:00:00', 0, 60)")
        ->execute([$teacherId]);

    echo "Setup: teacher_id=$teacherId (time 08:00–17:00, break informativo 60)\n\n";

    $date = '2026-05-25'; // segunda-feira
    $expected = calculate_expected_minutes($pdo, $teacherId, $date);
    check('expected = 540 (janela completa, break não desconta)', $expected === 540, "obtido: $expected");

    // --- Cenário 1: jornada dividida com intervalo PENDENTE ---
    echo "\nCenário 1: jornada dividida com intervalo PENDENTE\n";
    // work#1 08:00–12:00 (approved), break 12:00–13:00 (PENDENTE), work#2 13:00–17:00 (approved)
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId, $date, "$date 08:00:00", "$date 12:00:00", $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'break', NULL, 'manual', ?)")
        ->execute([$teacherId, $date, "$date 12:00:00", "$date 13:00:00", $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId, $date, "$date 13:00:00", "$date 17:00:00", $nextNsr()]);

    $worked = calculate_worked_minutes($pdo, $teacherId, $date);
    check('worked = 480 (soma bruta dos pares work)', $worked === 480, "obtido: $worked");

    $eff = calculate_effective_worked_minutes($pdo, $teacherId, $date);
    check('effective = 540 (presença cheia: work 480 + break 60, mesmo pendente)', $eff === 540, "obtido: $eff");

    $delta = $eff - $expected;
    check('delta = 0 (banco NÃO fica negativo)', $delta === 0, "obtido: $delta");

    // --- Cenário 2: mesma jornada com intervalo APROVADO ---
    echo "\nCenário 2: jornada dividida com intervalo APROVADO\n";
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_break_user2', '00000000092', $typeId]);
    $teacherId2 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, 1, '08:00:00', '17:00:00', 0, 60)")
        ->execute([$teacherId2]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId2, $date, "$date 08:00:00", "$date 12:00:00", $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'break', 1, 'manual', ?)")
        ->execute([$teacherId2, $date, "$date 12:00:00", "$date 13:00:00", $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId2, $date, "$date 13:00:00", "$date 17:00:00", $nextNsr()]);

    $eff2 = calculate_effective_worked_minutes($pdo, $teacherId2, $date);
    check('effective = 540 (presença cheia)', $eff2 === 540, "obtido: $eff2");
    check('delta = 0', ($eff2 - $expected) === 0, "obtido: " . ($eff2 - $expected));

    // --- Cenário 3: jornada CONTÍNUA sem intervalo registrado ---
    echo "\nCenário 3: jornada contínua sem intervalo registrado\n";
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_break_user3', '00000000093', $typeId]);
    $teacherId3 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, 1, '08:00:00', '17:00:00', 0, 60)")
        ->execute([$teacherId3]);
    // Único par 08:00–17:00 (540), sem registro de break.
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId3, $date, "$date 08:00:00", "$date 17:00:00", $nextNsr()]);

    $eff3 = calculate_effective_worked_minutes($pdo, $teacherId3, $date);
    check('effective = 540 (presença cheia, sem desconto algum)', $eff3 === 540, "obtido: $eff3");
    check('delta = 0', ($eff3 - $expected) === 0, "obtido: " . ($eff3 - $expected));

    // --- Cenário 4: caso real do bug (Auzenir) — janela 07:00–15:30, break=0 ---
    echo "\nCenário 4: janela 07:00–15:30 break=0, intervalo de 2h07 registrado\n";
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_break_user4', '00000000094', $typeId]);
    $teacherId4 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, 1, '07:00:00', '15:30:00', 0, 0)")
        ->execute([$teacherId4]);
    // work 07:07–11:07 + break 11:07–13:14 (2h07) + work 13:14–15:48
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId4, $date, "$date 07:07:15", "$date 11:07:33", $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'break', 1, 'manual', ?)")
        ->execute([$teacherId4, $date, "$date 11:07:33", "$date 13:14:26", $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId4, $date, "$date 13:14:26", "$date 15:48:16", $nextNsr()]);

    $exp4 = calculate_expected_minutes($pdo, $teacherId4, $date);
    check('expected = 510 (07:00–15:30)', $exp4 === 510, "obtido: $exp4");

    $eff4 = calculate_effective_worked_minutes($pdo, $teacherId4, $date);
    // work: 240 + 153 = 393; break: 126 → cheio 519~521 (floor por par)
    check('effective ≈ 519–521 (presença cheia 07:07→15:48)', $eff4 >= 519 && $eff4 <= 521, "obtido: $eff4");

    $delta4 = $eff4 - $exp4;
    check('delta positivo (~+9 a +11) — saldo NÃO negativo', $delta4 > 0, "obtido: $delta4");
    $deltaForBank = $delta4 > 0 ? 0 : $delta4;
    check('banco recebe 0 (delta>0 vira candidato a hora extra)', $deltaForBank === 0, "obtido: $deltaForBank");

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

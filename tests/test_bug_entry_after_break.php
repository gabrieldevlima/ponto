<?php
/**
 * Reproduz bug reportado pelo usuario: ao consolidar um dia com
 *   08:00 entrada -> 11:00 inicia intervalo -> 14:00 retorna -> 16:00 saida,
 * o sistema mostra entrada como 14:00 (errado), em vez de 08:00.
 *
 * Sistema grava (Hypothesis B confirmada via api/checkin.php:1727-1758):
 *   work#1: check_in=08:00, check_out=11:00 (fechado ao iniciar intervalo)
 *   break:  check_in=11:00, check_out=14:00 (fechado ao retornar)
 *   work#2: check_in=14:00, check_out=16:00 (fechado ao bater saida)
 *
 * Esperado pela funcao consolidate_attendance_by_day():
 *   check_in='08:00:00', check_out='16:00:00', 1 break 11:00-14:00.
 *
 * Como rodar:
 *   php tests\test_bug_entry_after_break.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

$failed = 0;
$passed = 0;

function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  [OK]   $label\n";
    } else {
        $failed++;
        echo "  [FAIL] $label";
        if ($detail !== '') echo " - $detail";
        echo "\n";
    }
}

echo "=== Bug repro: entrada 08:00 -> intervalo 11:00-14:00 -> saida 16:00 ===\n\n";

// Cenario EXATO descrito pelo usuario
$rows = [
    [
        'id'                   => 1,
        'teacher_id'           => 10,
        'teacher_name'         => 'Joao',
        'date'                 => '2026-05-27',
        'check_in'             => '2026-05-27 08:00:00',
        'check_out'            => '2026-05-27 11:00:00',  // fechado ao iniciar intervalo
        'record_type'          => 'work',
        'parent_attendance_id' => null,
        'sequence_number'      => 1,
    ],
    [
        'id'                   => 2,
        'teacher_id'           => 10,
        'teacher_name'         => 'Joao',
        'date'                 => '2026-05-27',
        'check_in'             => '2026-05-27 11:00:00',
        'check_out'            => '2026-05-27 14:00:00',  // fechado ao retornar
        'record_type'          => 'break',
        'parent_attendance_id' => 1,
        'sequence_number'      => 2,
    ],
    [
        'id'                   => 3,
        'teacher_id'           => 10,
        'teacher_name'         => 'Joao',
        'date'                 => '2026-05-27',
        'check_in'             => '2026-05-27 14:00:00',
        'check_out'            => '2026-05-27 16:00:00',  // fechado ao bater saida final
        'record_type'          => 'work',
        'parent_attendance_id' => null,
        'sequence_number'      => 3,
    ],
];

$result = consolidate_attendance_by_day($rows);

echo "Resultado da consolidacao:\n";
echo "  check_in       = " . var_export($result[0]['check_in'] ?? null, true) . "\n";
echo "  check_out      = " . var_export($result[0]['check_out'] ?? null, true) . "\n";
echo "  status         = " . var_export($result[0]['status'] ?? null, true) . "\n";
echo "  worked min     = " . var_export($result[0]['total_worked_minutes'] ?? null, true) . "\n";
echo "  break min      = " . var_export($result[0]['total_break_minutes'] ?? null, true) . "\n";
echo "  num breaks     = " . count($result[0]['breaks'] ?? []) . "\n";
foreach (($result[0]['breaks'] ?? []) as $i => $b) {
    echo "  break[$i]: " . var_export($b['start'], true) . " -> " . var_export($b['end'], true)
       . " (raw_id=" . var_export($b['raw_id'], true) . ", inferred=" . (empty($b['raw_id']) ? 'true' : 'false') . ")\n";
}
echo "\n";

check("check_in deve ser 08:00:00", ($result[0]['check_in'] ?? null) === '08:00:00');
check("check_out deve ser 16:00:00", ($result[0]['check_out'] ?? null) === '16:00:00');
check("status deve ser 'closed'", ($result[0]['status'] ?? null) === 'closed');
check("deve ter EXATAMENTE 1 intervalo (nao duplicado)", count($result[0]['breaks'] ?? []) === 1);
check("intervalo deve ser 11:00 -> 14:00", ($result[0]['breaks'][0]['start'] ?? null) === '11:00:00' && ($result[0]['breaks'][0]['end'] ?? null) === '14:00:00');
check("intervalo NAO deve ser inferred (deve ter raw_id)", !empty($result[0]['breaks'][0]['raw_id']));
// 08:00 -> 16:00 = 480 min, menos 180 de break = 300 efetivo
check("total_worked_minutes deve ser 300 (5h)", ($result[0]['total_worked_minutes'] ?? null) === 300);
check("total_break_minutes deve ser 180 (3h)", ($result[0]['total_break_minutes'] ?? null) === 180);

echo "\n=== Resultado ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

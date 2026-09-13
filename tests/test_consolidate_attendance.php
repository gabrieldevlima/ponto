<?php
/**
 * Smoke test standalone para consolidate_attendance_by_day().
 *
 * Como rodar (PowerShell):
 *   php tests\test_consolidate_attendance.php
 *
 * Testa apenas a lógica pura da função helper — não toca banco de dados.
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
        if ($detail !== '') echo " — $detail";
        echo "\n";
    }
}

function row(array $overrides): array {
    return array_merge([
        'id' => 1,
        'teacher_id' => 10,
        'teacher_name' => 'Joao',
        'date' => '2026-05-26',
        'check_in' => '2026-05-26 08:00:00',
        'check_out' => '2026-05-26 17:00:00',
        'record_type' => 'work',
        'parent_attendance_id' => null,
        'sequence_number' => 1,
    ], $overrides);
}

echo "=== Smoke test: consolidate_attendance_by_day ===\n\n";

// ---------- Cenário 1: Dia sem intervalos (só 1 work) ----------
echo "Cenario 1: Dia sem intervalos (so 1 work)\n";
$rows = [
    row(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 17:00:00']),
];
$result = consolidate_attendance_by_day($rows);
check('1 dia consolidado', count($result) === 1);
check('check_in = 08:00:00', ($result[0]['check_in'] ?? null) === '08:00:00');
check('check_out = 17:00:00', ($result[0]['check_out'] ?? null) === '17:00:00');
check('0 breaks', count($result[0]['breaks'] ?? []) === 0);
check('total_break_minutes = 0', ($result[0]['total_break_minutes'] ?? null) === 0);
check('total_worked_minutes = 540', ($result[0]['total_worked_minutes'] ?? null) === 540);
check("status = 'closed'", ($result[0]['status'] ?? null) === 'closed');

// ---------- Cenário 2: Dia com 1 intervalo ----------
echo "\nCenario 2: Dia com 1 intervalo (1 work + 1 break)\n";
$rows = [
    row(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 17:00:00']),
    row(['id'=>2, 'check_in'=>'2026-05-26 12:00:00', 'check_out'=>'2026-05-26 13:00:00',
         'record_type'=>'break', 'parent_attendance_id'=>1, 'sequence_number'=>2]),
];
$result = consolidate_attendance_by_day($rows);
check('1 dia consolidado', count($result) === 1);
check('1 break', count($result[0]['breaks']) === 1);
check('break start = 12:00:00', $result[0]['breaks'][0]['start'] === '12:00:00');
check('break end = 13:00:00', $result[0]['breaks'][0]['end'] === '13:00:00');
check('break duration = 60', $result[0]['breaks'][0]['duration_minutes'] === 60);
check('break raw_id = 2', $result[0]['breaks'][0]['raw_id'] === 2);
check('total_break_minutes = 60', $result[0]['total_break_minutes'] === 60);
check('total_worked_minutes = 480', $result[0]['total_worked_minutes'] === 480);

// ---------- Cenário 3: Dia com 3 intervalos ----------
echo "\nCenario 3: Dia com 3 intervalos\n";
$rows = [
    row(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 18:00:00']),
    // Propositalmente fora de ordem para validar ordenação
    row(['id'=>3, 'check_in'=>'2026-05-26 17:00:00', 'check_out'=>'2026-05-26 17:30:00',
         'record_type'=>'break', 'parent_attendance_id'=>1, 'sequence_number'=>4]),
    row(['id'=>2, 'check_in'=>'2026-05-26 12:00:00', 'check_out'=>'2026-05-26 13:00:00',
         'record_type'=>'break', 'parent_attendance_id'=>1, 'sequence_number'=>2]),
    row(['id'=>4, 'check_in'=>'2026-05-26 15:00:00', 'check_out'=>'2026-05-26 15:15:00',
         'record_type'=>'break', 'parent_attendance_id'=>1, 'sequence_number'=>3]),
];
$result = consolidate_attendance_by_day($rows);
check('3 breaks', count($result[0]['breaks']) === 3);
check('breaks ordenados (1o = 12:00)', $result[0]['breaks'][0]['start'] === '12:00:00');
check('breaks ordenados (2o = 15:00)', $result[0]['breaks'][1]['start'] === '15:00:00');
check('breaks ordenados (3o = 17:00)', $result[0]['breaks'][2]['start'] === '17:00:00');
check('total_break_minutes = 105 (60+15+30)', $result[0]['total_break_minutes'] === 105);
check('total_worked_minutes = 495 (600-105)', $result[0]['total_worked_minutes'] === 495);

// ---------- Cenário 4: Work em aberto ----------
echo "\nCenario 4: Work em aberto (sem check_out)\n";
$rows = [
    row(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>null]),
];
$result = consolidate_attendance_by_day($rows);
check('check_in = 08:00:00', $result[0]['check_in'] === '08:00:00');
check('check_out = null', $result[0]['check_out'] === null);
check("status = 'in_progress'", $result[0]['status'] === 'in_progress');

// ---------- Cenário 5: Break em aberto ----------
echo "\nCenario 5: Break em aberto (sem check_out)\n";
$rows = [
    row(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>null]),
    row(['id'=>2, 'check_in'=>'2026-05-26 12:00:00', 'check_out'=>null,
         'record_type'=>'break', 'parent_attendance_id'=>1, 'sequence_number'=>2]),
];
$result = consolidate_attendance_by_day($rows);
check('1 break', count($result[0]['breaks']) === 1);
check('break end = null', $result[0]['breaks'][0]['end'] === null);
check('break duration = null', $result[0]['breaks'][0]['duration_minutes'] === null);
check('total_break_minutes = 0 (so soma fechados)', $result[0]['total_break_minutes'] === 0);
check("status = 'in_progress'", $result[0]['status'] === 'in_progress');

// ---------- Cenário 6: Break órfão (sem work) ----------
echo "\nCenario 6: Break orfao (sem work pai)\n";
$rows = [
    row(['id'=>1, 'check_in'=>'2026-05-26 12:00:00', 'check_out'=>'2026-05-26 13:00:00',
         'record_type'=>'break', 'parent_attendance_id'=>null, 'sequence_number'=>1]),
];
$result = consolidate_attendance_by_day($rows);
check('1 dia consolidado', count($result) === 1);
check("status = 'orphan'", $result[0]['status'] === 'orphan');
check('check_in = null', $result[0]['check_in'] === null);

// ---------- Cenário 7: 2 works no dia ----------
echo "\nCenario 7: 2 works no mesmo dia (lancamento manual)\n";
$rows = [
    row(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 12:00:00',
         'record_type'=>'work', 'sequence_number'=>1]),
    row(['id'=>2, 'check_in'=>'2026-05-26 13:00:00', 'check_out'=>'2026-05-26 17:00:00',
         'record_type'=>'work', 'sequence_number'=>2]),
];
$result = consolidate_attendance_by_day($rows);
check('check_in = 08:00:00 (1o work)', $result[0]['check_in'] === '08:00:00');
check('check_out = 17:00:00 (2o work)', $result[0]['check_out'] === '17:00:00');
check('1 break inferido', count($result[0]['breaks']) === 1);
check('break inferido start = 12:00:00', $result[0]['breaks'][0]['start'] === '12:00:00');
check('break inferido end = 13:00:00', $result[0]['breaks'][0]['end'] === '13:00:00');
check('break inferido raw_id = null', $result[0]['breaks'][0]['raw_id'] === null);
check('total_worked_minutes = 480 (540-60)', $result[0]['total_worked_minutes'] === 480);

// ---------- Cenário 7b: fluxo padrao (2 works + 1 break real cobrindo o gap) ----------
// Regressao do bug onde inferred break era criado em paralelo ao break real,
// inflando total_break_minutes. Cenario tipico: entrada 8h, intervalo 11-14h, saida 16h.
echo "\nCenario 7b: 2 works + break real entre eles (fluxo padrao do checkin.php)\n";
$rows = [
    row(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 11:00:00',
         'record_type'=>'work', 'sequence_number'=>1]),
    row(['id'=>2, 'check_in'=>'2026-05-26 11:00:00', 'check_out'=>'2026-05-26 14:00:00',
         'record_type'=>'break', 'parent_attendance_id'=>1, 'sequence_number'=>2]),
    row(['id'=>3, 'check_in'=>'2026-05-26 14:00:00', 'check_out'=>'2026-05-26 16:00:00',
         'record_type'=>'work', 'sequence_number'=>3]),
];
$result = consolidate_attendance_by_day($rows);
check('7b: check_in = 08:00:00 (1o work cronologico)', $result[0]['check_in'] === '08:00:00');
check('7b: check_out = 16:00:00 (ultimo work cronologico)', $result[0]['check_out'] === '16:00:00');
check('7b: EXATAMENTE 1 break (sem duplicacao com inferred)', count($result[0]['breaks']) === 1);
check('7b: break real preservado (raw_id=2)', $result[0]['breaks'][0]['raw_id'] === 2);
check('7b: total_break = 180 (3h, nao 360)', $result[0]['total_break_minutes'] === 180);
check('7b: total_worked = 300 (5h efetivo)', $result[0]['total_worked_minutes'] === 300);

// ---------- Cenário 8: Múltiplos dias e múltiplos teachers ----------
echo "\nCenario 8: Multiplos dias e multiplos teachers\n";
$rows = [
    row(['id'=>1, 'teacher_id'=>10, 'teacher_name'=>'Joao', 'date'=>'2026-05-26',
         'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 17:00:00']),
    row(['id'=>2, 'teacher_id'=>20, 'teacher_name'=>'Maria', 'date'=>'2026-05-26',
         'check_in'=>'2026-05-26 09:00:00', 'check_out'=>'2026-05-26 18:00:00']),
    row(['id'=>3, 'teacher_id'=>10, 'teacher_name'=>'Joao', 'date'=>'2026-05-27',
         'check_in'=>'2026-05-27 08:00:00', 'check_out'=>'2026-05-27 17:00:00']),
];
$result = consolidate_attendance_by_day($rows);
check('3 dias consolidados (Joao x2 + Maria x1)', count($result) === 3);
$keys = array_map(fn($r) => $r['date'] . '_' . $r['teacher_id'], $result);
check('contem Joao 2026-05-26', in_array('2026-05-26_10', $keys));
check('contem Joao 2026-05-27', in_array('2026-05-27_10', $keys));
check('contem Maria 2026-05-26', in_array('2026-05-26_20', $keys));

// ---------- Resultado final ----------
echo "\n=== Resultado ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

<?php
/**
 * Regressão da correção "Duração líquida" (worked = presença − intervalo).
 *
 * Garante a invariante que TODAS as telas/relatórios passaram a exibir como
 * tempo trabalhado: o LÍQUIDO = presença (entrada→saída) − intervalos. O saldo
 * permanece no modelo "cheio vs cheio" e NÃO é coberto aqui.
 *
 * Cobre os dois caminhos de cálculo usados na base:
 *   (A) consolidação por dia  → $day['total_worked_minutes']
 *       (my_timesheet.php, attendances.php, _tpl_attendances_pdf.php)
 *   (B) agregação cheia        → workedMin − breakMin
 *       (reports.php, teacher_monthly_report.php e seus PDFs), onde workedMin
 *       soma pares work + break (presença) e breakMin soma os breaks.
 *
 * Como rodar (PowerShell):
 *   php tests\test_net_worked_display.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

$failed = 0;
$passed = 0;

function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "  [OK]   $label\n"; }
    else { $failed++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function r(array $o): array {
    return array_merge([
        'id' => 1, 'teacher_id' => 10, 'teacher_name' => 'Joao',
        'date' => '2026-05-26',
        'check_in' => '2026-05-26 08:00:00', 'check_out' => '2026-05-26 17:00:00',
        'record_type' => 'work',
    ], $o);
}

/**
 * Reproduz a agregação "cheia" de reports.php/teacher_monthly_report.php:
 * workedMin recebe TANTO work quanto break (presença); breakMin só os breaks.
 * O líquido exibido é workedMin − breakMin.
 */
function aggregate_net(array $rows): array {
    $workedMin = 0; $breakMin = 0;
    foreach ($rows as $row) {
        $ci = $row['check_in'] ?? null; $co = $row['check_out'] ?? null;
        if (!$ci || !$co) continue;
        $min = (int)floor((strtotime($co) - strtotime($ci)) / 60);
        if ($min <= 0) continue;
        if (($row['record_type'] ?? 'work') === 'break') { $breakMin += $min; $workedMin += $min; }
        else { $workedMin += $min; }
    }
    return ['workedMin' => $workedMin, 'breakMin' => $breakMin, 'net' => max(0, $workedMin - $breakMin)];
}

echo "=== Test: tempo trabalhado LÍQUIDO (presença − intervalo) ===\n\n";

// ---------- Cenário 1: fluxo padrão (work, break, work) — exemplo do usuário ----------
// Entrada 08:00, intervalo 12:00–13:40 (1h40), saída 16:20 → presença 8h20, líquido 6h40.
echo "Cenario 1: fluxo padrao work/break/work (presenca 8h20, intervalo 1h40)\n";
$rows = [
    r(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 12:00:00', 'record_type'=>'work']),
    r(['id'=>2, 'check_in'=>'2026-05-26 12:00:00', 'check_out'=>'2026-05-26 13:40:00', 'record_type'=>'break']),
    r(['id'=>3, 'check_in'=>'2026-05-26 13:40:00', 'check_out'=>'2026-05-26 16:20:00', 'record_type'=>'work']),
];
$cons = consolidate_attendance_by_day($rows)[0];
$agg  = aggregate_net($rows);
check('consolidacao: presenca (span) − intervalo = 400min (6h40)', $cons['total_worked_minutes'] === 400, 'got ' . $cons['total_worked_minutes']);
check('consolidacao: intervalo = 100min (1h40)', $cons['total_break_minutes'] === 100, 'got ' . $cons['total_break_minutes']);
check('agregacao: workedMin (presenca) = 500min (8h20)', $agg['workedMin'] === 500, 'got ' . $agg['workedMin']);
check('agregacao: net (workedMin − breakMin) = 400min (6h40)', $agg['net'] === 400, 'got ' . $agg['net']);
check('AMBOS os caminhos concordam no liquido (400)', $cons['total_worked_minutes'] === $agg['net']);
check('liquido + intervalo = presenca (400+100=500)', $agg['net'] + $cons['total_break_minutes'] === $agg['workedMin']);

// ---------- Cenário 2: dia sem intervalo → líquido == presença ----------
echo "\nCenario 2: dia sem intervalo (liquido == presenca)\n";
$rows = [ r(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 17:00:00', 'record_type'=>'work']) ];
$cons = consolidate_attendance_by_day($rows)[0];
$agg  = aggregate_net($rows);
check('consolidacao: total_worked = 540 (9h)', $cons['total_worked_minutes'] === 540, 'got ' . $cons['total_worked_minutes']);
check('agregacao: net = 540 (9h)', $agg['net'] === 540, 'got ' . $agg['net']);
check('sem intervalo: net == presenca', $agg['net'] === $agg['workedMin']);

// ---------- Cenário 3: dois intervalos no dia ----------
echo "\nCenario 3: dois intervalos (almoco 1h + cafe 15min)\n";
$rows = [
    r(['id'=>1, 'check_in'=>'2026-05-26 08:00:00', 'check_out'=>'2026-05-26 12:00:00', 'record_type'=>'work']),
    r(['id'=>2, 'check_in'=>'2026-05-26 12:00:00', 'check_out'=>'2026-05-26 13:00:00', 'record_type'=>'break']),
    r(['id'=>3, 'check_in'=>'2026-05-26 13:00:00', 'check_out'=>'2026-05-26 15:00:00', 'record_type'=>'work']),
    r(['id'=>4, 'check_in'=>'2026-05-26 15:00:00', 'check_out'=>'2026-05-26 15:15:00', 'record_type'=>'break']),
    r(['id'=>5, 'check_in'=>'2026-05-26 15:15:00', 'check_out'=>'2026-05-26 18:00:00', 'record_type'=>'work']),
];
$cons = consolidate_attendance_by_day($rows)[0];
$agg  = aggregate_net($rows);
// presenca 08:00→18:00 = 600min; intervalos 60+15 = 75; liquido = 525.
check('consolidacao: intervalo = 75min', $cons['total_break_minutes'] === 75, 'got ' . $cons['total_break_minutes']);
check('consolidacao: liquido = 525min', $cons['total_worked_minutes'] === 525, 'got ' . $cons['total_worked_minutes']);
check('agregacao: net = 525min', $agg['net'] === 525, 'got ' . $agg['net']);
check('AMBOS concordam (525)', $cons['total_worked_minutes'] === $agg['net']);

// ---------- Cenário 4: caso real do print (16/06/2026) ----------
// Entrada 07:06, intervalo 11:21→13:01 (1h40), saída 15:26 → presença 8h20, líquido 6h40.
// Antes do fix, a "Duração" mostrava 4h15 (apenas 07:06→11:21, o 1º trecho de trabalho).
echo "\nCenario 4: caso real do print (07:06 / 11:21-13:01 / 15:26)\n";
$rows = [
    r(['id'=>1, 'check_in'=>'2026-06-16 07:06:00', 'check_out'=>'2026-06-16 11:21:00', 'record_type'=>'work', 'date'=>'2026-06-16']),
    r(['id'=>2, 'check_in'=>'2026-06-16 11:21:00', 'check_out'=>'2026-06-16 13:01:00', 'record_type'=>'break', 'date'=>'2026-06-16']),
    r(['id'=>3, 'check_in'=>'2026-06-16 13:01:00', 'check_out'=>'2026-06-16 15:26:00', 'record_type'=>'work', 'date'=>'2026-06-16']),
];
$cons = consolidate_attendance_by_day($rows)[0];
$agg  = aggregate_net($rows);
check('presenca (07:06→15:26) = 500min (8h20)', ($agg['workedMin']) === 500, 'got ' . $agg['workedMin']);
check('intervalo (11:21→13:01) = 100min (1h40)', $cons['total_break_minutes'] === 100, 'got ' . $cons['total_break_minutes']);
check('Duracao LIQUIDA = 400min (6h40), nao 4h15', $cons['total_worked_minutes'] === 400, 'got ' . $cons['total_worked_minutes']);
check('agregacao concorda (400)', $agg['net'] === 400, 'got ' . $agg['net']);

echo "\n=== Resultado ===\nPassed: $passed\nFailed: $failed\n";
exit($failed > 0 ? 1 : 0);

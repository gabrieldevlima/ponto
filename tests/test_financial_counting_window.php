<?php
/**
 * Testa apply_counting_window() e o efeito na contagem do desconto financeiro.
 *
 * Como rodar (PowerShell):
 *   php tests\test_financial_counting_window.php
 *
 * Regressão alvo: o relatório financeiro descontava o mês inteiro (e mostrava saldo
 * negativo) mesmo quando a data de início da contagem ainda não havia chegado.
 * apply_counting_window zera previsto/trabalhado dos dias fora da janela [início, hoje],
 * de modo que totais, saldo e desconto refletem só os dias contados.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

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

/** Monta um mapa diário de junho/2026: seg-sex 480 min previstos, fim de semana 0, trabalhado 0. */
function build_june_daily(): array {
    $daily = [];
    $start = new DateTime('2026-06-01');
    $end   = new DateTime('2026-06-30');
    for ($d = clone $start; $d <= $end; $d = $d->modify('+1 day')) {
        $w = (int)$d->format('w');
        $exp = ($w >= 1 && $w <= 5) ? 480 : 0;
        $daily[$d->format('Y-m-d')] = ['expected' => $exp, 'worked' => 0, 'effective' => 0];
    }
    return $daily;
}

/** Calcula o desconto do jeito do relatório: déficit (sobre dias contados) x valor/minuto. */
function discount_for(array $daily, string $start, string $today, float $baseSalary, int $monthlyExpected): float {
    $d = apply_counting_window($daily, $start, $today);
    $totalExpected = (int) array_sum(array_column($d, 'expected'));
    $totalWorked   = (int) array_sum(array_map(static fn($x) => (int)($x['effective'] ?? $x['worked'] ?? 0), $d));
    $deficit = max(0, $totalExpected - $totalWorked);
    $minuteValue = $monthlyExpected > 0 ? $baseSalary / $monthlyExpected : 0.0;
    return $deficit * $minuteValue;
}

echo "=== Teste: apply_counting_window (desconto financeiro) ===\n\n";

$daily = build_june_daily();
$monthlyExpected = (int) array_sum(array_column($daily, 'expected')); // 22 dias úteis * 480 = 10560
$baseSalary = 3242.0;

// --- Cenário do BUG (real): início = 2026-06-10, hoje = 2026-06-09 (janela vazia) ---
$w1 = apply_counting_window($daily, '2026-06-10', '2026-06-09');
check('janela vazia: previsto total = 0', (int)array_sum(array_column($w1, 'expected')) === 0);
$disc1 = discount_for($daily, '2026-06-10', '2026-06-09', $baseSalary, $monthlyExpected);
check('janela vazia: desconto = R$ 0,00 (salário cheio)', abs($disc1) < 0.001, 'obtido ' . number_format($disc1, 2));

// --- Cenário MÊS FECHADO: início no passado, hoje após o fim (janela completa) ---
$w2 = apply_counting_window($daily, '2025-01-01', '2026-06-30');
check('mês fechado: previsto total = mês cheio (no-op)', (int)array_sum(array_column($w2, 'expected')) === $monthlyExpected);
$disc2 = discount_for($daily, '2025-01-01', '2026-06-30', $baseSalary, $monthlyExpected);
check('mês fechado, sem trabalho: desconto = salário cheio', abs($disc2 - $baseSalary) < 0.01, 'obtido ' . number_format($disc2, 2));

// --- Cenário PARCIAL: início no passado, hoje = 2026-06-09 (conta só 01..09/06) ---
$w3 = apply_counting_window($daily, '2025-01-01', '2026-06-09');
// dias úteis 01..09/06/2026: 1,2,3,4,5,8,9 = 7 dias * 480 = 3360
check('parcial: conta só dias úteis até hoje', (int)array_sum(array_column($w3, 'expected')) === 7 * 480, 'obtido ' . array_sum(array_column($w3, 'expected')));

// --- Dias fora da janela ficam zerados; dentro permanecem ---
$w4 = apply_counting_window(build_june_daily(), '2026-06-10', '2026-06-30');
check('dia antes do início zerado', $w4['2026-06-09']['expected'] === 0);
check('dia dentro da janela preservado', $w4['2026-06-10']['expected'] === 480, 'obtido ' . $w4['2026-06-10']['expected']);

// --- Campos customizados (relatório mensal usa expectedMin/workedMin) ---
$d5 = [
    '2026-06-09' => ['expectedMin' => 480, 'workedMin' => 480, 'effectiveMin' => 480],
    '2026-06-10' => ['expectedMin' => 480, 'workedMin' => 0,   'effectiveMin' => 0],
];
$w5 = apply_counting_window($d5, '2026-06-10', '2026-06-30', ['expectedMin', 'workedMin', 'effectiveMin']);
check('campo custom: fora da janela zerado', $w5['2026-06-09']['expectedMin'] === 0 && $w5['2026-06-09']['workedMin'] === 0);
check('campo custom: dentro preservado', $w5['2026-06-10']['expectedMin'] === 480);

echo "\n=== $passed OK, $failed FAIL ===\n";
exit($failed > 0 ? 1 : 0);

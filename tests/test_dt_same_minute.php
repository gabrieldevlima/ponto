<?php
/**
 * Regressão: dt_same_minute() — base da correção do bug "edição de ponto salva
 * com dados diferentes". A edição do admin é por minuto; comparar com segundos do
 * banco marcava campos NÃO tocados como alterados e reescrevia os segundos.
 * Teste puro (sem banco). Rodar: php tests\test_dt_same_minute.php
 */
declare(strict_types=1);
require __DIR__ . '/../helpers.php';
$pass = 0; $fail = 0;
function ck(string $l, bool $c): void { global $pass,$fail; if($c){$pass++;echo "  [OK]   $l\n";}else{$fail++;echo "  [FAIL] $l\n";} }

echo "=== dt_same_minute ===\n";
// O caso do bug: campo não tocado (form mostra 07:14, banco tem 07:14:25) NÃO deve contar como alteração.
ck('07:14:25 vs 07:14:00 => mesmo minuto (true)',  dt_same_minute('2026-06-16 07:14:25', '2026-06-16 07:14:00') === true);
ck('07:14:59 vs 07:14:00 => mesmo minuto (true)',  dt_same_minute('2026-06-16 07:14:59', '2026-06-16 07:14:00') === true);
// Mudança real de minuto deve contar.
ck('07:14:25 vs 07:30:00 => minutos diferentes (false)', dt_same_minute('2026-06-16 07:14:25', '2026-06-16 07:30:00') === false);
// Vira o minuto / o dia.
ck('23:59:50 vs 00:00:00(+1d) => diferente (false)', dt_same_minute('2026-06-16 23:59:50', '2026-06-17 00:00:00') === false);
// Setar valor em campo antes nulo é alteração (caller usa !dt_same_minute → inclui o campo).
ck('null vs valor => false (é alteração)', dt_same_minute(null, '2026-06-16 07:30:00') === false);
ck('valor vs vazio => false', dt_same_minute('2026-06-16 07:14:25', '') === false);

echo "\nPassed: $pass  Failed: $fail\n";
exit($fail > 0 ? 1 : 0);

<?php
/**
 * Testa a regra de data de início da contagem (resolve_counting_start).
 *
 * Como rodar (PowerShell):
 *   php tests\test_counting_start_date.php
 *
 * Não toca no banco: resolve_counting_start() é função pura.
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

echo "=== Teste: resolve_counting_start ===\n\n";

// 1) Sem data global -> usa o created_at do colaborador
check(
    'sem global, com created_at',
    resolve_counting_start(null, '2025-10-08 11:50:04') === '2025-10-08',
    'esperado 2025-10-08, obtido ' . resolve_counting_start(null, '2025-10-08 11:50:04')
);

// 2) Data global POSTERIOR ao created_at -> vence a global
check(
    'global > created_at',
    resolve_counting_start('2026-01-01', '2025-10-08 11:50:04') === '2026-01-01',
    'obtido ' . resolve_counting_start('2026-01-01', '2025-10-08 11:50:04')
);

// 3) Data global ANTERIOR ao created_at -> vence o created_at
check(
    'global < created_at',
    resolve_counting_start('2025-01-01', '2025-10-08 11:50:04') === '2025-10-08',
    'obtido ' . resolve_counting_start('2025-01-01', '2025-10-08 11:50:04')
);

// 4) Sem global e sem created_at -> piso 1900-01-01
check(
    'sem global e sem created_at',
    resolve_counting_start(null, null) === '1900-01-01',
    'obtido ' . resolve_counting_start(null, null)
);

// 5) Global vazia ('') tratada como ausente
check(
    "global '' tratada como ausente",
    resolve_counting_start('', '2025-10-08 11:50:04') === '2025-10-08',
    'obtido ' . resolve_counting_start('', '2025-10-08 11:50:04')
);

echo "\n=== $passed OK, $failed FAIL ===\n";
exit($failed > 0 ? 1 : 0);

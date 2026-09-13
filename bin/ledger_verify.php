#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Verificador da cadeia de integridade do livro fiscal.
 * =====================================================
 * Fase 1.10 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * Uso:
 *   php bin/ledger_verify.php                  # cadeia inteira
 *   php bin/ledger_verify.php --from=1 --to=500
 *   php bin/ledger_verify.php --json           # saída para monitoramento
 *   php bin/ledger_verify.php --reconcile=2026-08-01:2026-08-31
 *
 * Cron sugerido (diário, de madrugada):
 *   0 3 * * * cd /caminho/do/projeto && php bin/ledger_verify.php >> logs/ledger_verify.log 2>&1
 *
 * Códigos de saída: 0 íntegro · 1 divergência na cadeia · 2 drift na reconciliação
 *
 * DUAS VERIFICAÇÕES DIFERENTES, e as duas importam:
 *
 *   --verify (padrão)  percorre nsr_ledger e confere payload, assinatura e elo.
 *                      Pega adulteração DENTRO do livro.
 *
 *   --reconcile        compara `attendance` com o livro. Pega o caso em que
 *                      alguém editou a tabela operacional direto no MySQL: o
 *                      livro continuaria perfeitamente íntegro (ninguém o
 *                      tocou), mas divergente da realidade. A verificação da
 *                      cadeia sozinha jamais notaria.
 */

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando (CLI)\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/nsr_ledger.php';

$args = $argv ?? [];
$asJson = in_array('--json', $args, true);

$opt = function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) return substr($a, strlen($name) + 3);
    }
    return null;
};

$from      = $opt('from') !== null ? (int)$opt('from') : null;
$to        = $opt('to') !== null ? (int)$opt('to') : null;
$reconcile = $opt('reconcile');

$pdo   = db();
$stamp = date('Y-m-d H:i:s');

try {
    ledger_hmac_key();
} catch (Throwable $e) {
    // Sem chave não há verificação possível — e isso é um alerta, não um detalhe.
    if ($asJson) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "[{$stamp}] ERRO: {$e->getMessage()}\n";
    }
    exit(1);
}

// ---------------------------------------------------------------------------
// Reconciliação attendance x livro
// ---------------------------------------------------------------------------
if ($reconcile !== null) {
    [$rf, $rt] = array_pad(explode(':', $reconcile, 2), 2, null);
    if (!$rf || !$rt) {
        echo "Formato: --reconcile=AAAA-MM-DD:AAAA-MM-DD\n";
        exit(1);
    }

    $r = nsr_ledger_reconcile($pdo, $rf, $rt);

    if ($asJson) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "[{$stamp}] reconciliação {$rf} .. {$rt}\n";
        echo "  linhas de attendance analisadas: {$r['rows']}\n";
        if ($r['ok']) {
            echo "  OK — nenhuma divergência entre a tabela operacional e o livro\n";
        } else {
            echo "  DRIFT — " . count($r['drift']) . " divergência(s):\n";
            foreach (array_slice($r['drift'], 0, 30) as $d) {
                echo "    attendance#{$d['attendance_id']} {$d['campo']}: "
                   . "tabela={$d['attendance']} livro={$d['ledger']}\n";
            }
            if (count($r['drift']) > 30) {
                echo "    ... e mais " . (count($r['drift']) - 30) . " (use --json para a lista completa)\n";
            }
            echo "  Divergência aqui sugere edição direta no banco, fora da aplicação.\n";
        }
    }
    exit($r['ok'] ? 0 : 2);
}

// ---------------------------------------------------------------------------
// Verificação da cadeia
// ---------------------------------------------------------------------------
$total = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn();
if ($total === 0) {
    if ($asJson) {
        echo json_encode(['ok' => true, 'checked' => 0, 'note' => 'livro vazio'], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "[{$stamp}] livro fiscal vazio — nada a verificar.\n";
        echo "           (ledger_mode = " . nsr_ledger_mode() . ")\n";
    }
    exit(0);
}

$started = microtime(true);
$r = nsr_ledger_verify($pdo, $from, $to);
$elapsed = round(microtime(true) - $started, 2);

// Publica o resultado para o painel admin poder alertar sem reprocessar tudo.
set_setting('ledger_last_verify', json_encode([
    'at' => $stamp, 'ok' => $r['ok'], 'checked' => $r['checked'],
    'first_bad_nsr' => $r['first_bad_nsr'], 'gaps' => count($r['gaps']),
], JSON_UNESCAPED_UNICODE));

if ($asJson) {
    echo json_encode($r + ['elapsed_s' => $elapsed], JSON_UNESCAPED_UNICODE) . "\n";
    exit($r['ok'] ? 0 : 1);
}

echo "[{$stamp}] verificação da cadeia\n";
echo "  modo do ledger : " . nsr_ledger_mode() . "\n";
echo "  registros      : {$r['checked']} de {$total}\n";
echo "  tempo          : {$elapsed}s\n";

if (!empty($r['gaps'])) {
    echo "  BURACOS na sequência de NSR (" . count($r['gaps']) . "):\n";
    foreach (array_slice($r['gaps'], 0, 20) as $g) {
        echo "    faltam {$g['missing']} entre NSR {$g['after']} e {$g['next']}\n";
    }
    echo "    Buraco significa registro emitido e ausente — investigar.\n";
}

if ($r['ok']) {
    echo "  ÍNTEGRA — payload, assinatura e encadeamento conferem em todos os registros.\n";
    exit(empty($r['gaps']) ? 0 : 1);
}

echo "  DIVERGÊNCIA a partir do NSR {$r['first_bad_nsr']}:\n";
foreach ($r['errors'] as $e) {
    echo "    NSR {$e['nsr']} — {$e['tipo']}: {$e['detalhe']}\n";
}
echo "\n  A verificação para na primeira divergência: a partir dali o\n";
echo "  encadeamento perde o sentido e os erros seguintes seriam consequência.\n";
exit(1);

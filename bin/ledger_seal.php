#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Selo diário do livro fiscal.
 * ============================
 * Fase 2.1 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * Uso:
 *   php bin/ledger_seal.php                     # sela ONTEM (uso normal em cron)
 *   php bin/ledger_seal.php --date=2026-08-05   # sela um dia específico
 *   php bin/ledger_seal.php --backfill          # sela todos os dias pendentes
 *   php bin/ledger_seal.php --audit             # confere todos os selos emitidos
 *   php bin/ledger_seal.php --pubkey            # mostra a chave pública
 *   php bin/ledger_seal.php --anchor=2026-08-05 --ref="Message-ID: <x@y>" [--channel=email]
 *
 * Cron sugerido (todo dia, de madrugada, depois da verificação da cadeia):
 *   30 3 * * * cd /caminho/do/projeto && php bin/ledger_seal.php >> logs/ledger_seal.log 2>&1
 *
 * Sela ONTEM por padrão, nunca hoje: um dia ainda em curso continua recebendo
 * eventos, e selar um head que vai mudar em seguida não prova coisa alguma.
 *
 * Códigos de saída: 0 ok · 1 erro · 2 auditoria encontrou selo divergente
 */

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando (CLI)\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ledger_seal.php';

$args = $argv ?? [];
$opt = function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) return substr($a, strlen($name) + 3);
    }
    return null;
};
$has = fn(string $n) => in_array("--{$n}", $args, true);

$pdo   = db();
$stamp = date('Y-m-d H:i:s');

// --------------------------------------------------------------------------
if ($has('pubkey')) {
    try {
        echo ledger_seal_public_key_b64() . "\n";
        exit(0);
    } catch (Throwable $e) {
        echo "ERRO: {$e->getMessage()}\n";
        exit(1);
    }
}

// --------------------------------------------------------------------------
if ($opt('anchor') !== null) {
    $d   = (string)$opt('anchor');
    $ref = (string)($opt('ref') ?? '');
    $ch  = (string)($opt('channel') ?? 'email');
    if ($ref === '') {
        echo "Informe --ref com a referência externa (Message-ID, protocolo, ata).\n";
        echo "A ancoragem só vale se for verificável fora do sistema.\n";
        exit(1);
    }
    try {
        ledger_seal_anchor($pdo, $d, $ch, $ref);
        echo "[{$stamp}] ancoragem registrada para {$d} via {$ch}: {$ref}\n";
        exit(0);
    } catch (Throwable $e) {
        echo "ERRO: {$e->getMessage()}\n";
        exit(1);
    }
}

// --------------------------------------------------------------------------
if ($has('audit')) {
    $seals = $pdo->query("SELECT * FROM nsr_ledger_seals ORDER BY seal_date ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$seals) {
        echo "[{$stamp}] nenhum selo emitido ainda.\n";
        exit(0);
    }
    echo "[{$stamp}] auditoria de " . count($seals) . " selos\n";
    $ruins = 0;
    foreach ($seals as $s) {
        $r = ledger_seal_audit($pdo, $s);
        if (!$r['ok']) {
            $ruins++;
            echo "  [FALHA] {$s['seal_date']} (NSR {$s['first_nsr']}..{$s['last_nsr']}) — {$r['detalhe']}\n";
        }
    }
    if ($ruins === 0) {
        echo "  todos os " . count($seals) . " selos conferem: assinatura válida e head inalterado.\n";
        exit(0);
    }
    echo "  {$ruins} selo(s) com problema — investigar antes de qualquer fiscalização.\n";
    exit(2);
}

// --------------------------------------------------------------------------
try {
    ledger_seal_secret_key();
} catch (Throwable $e) {
    echo "[{$stamp}] ERRO: {$e->getMessage()}\n";
    exit(1);
}

$dias = [];
if ($has('backfill')) {
    // Dias com evento no livro e ainda sem selo, exceto hoje.
    $dias = $pdo->query("
        SELECT DISTINCT DATE(l.created_at) AS d
          FROM nsr_ledger l
          LEFT JOIN nsr_ledger_seals s ON s.seal_date = DATE(l.created_at)
         WHERE s.id IS NULL AND DATE(l.created_at) < CURDATE()
         ORDER BY d ASC
    ")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $dias = [$opt('date') ?? date('Y-m-d', strtotime('-1 day'))];
}

if (!$dias) {
    echo "[{$stamp}] nenhum dia pendente de selo.\n";
    exit(0);
}

$selados = 0;
foreach ($dias as $d) {
    if ($d >= date('Y-m-d')) {
        echo "[{$stamp}] {$d}: ignorado — dia ainda em curso, o head mudaria depois do selo.\n";
        continue;
    }
    try {
        $r = ledger_seal_day($pdo, $d);
    } catch (Throwable $e) {
        echo "[{$stamp}] {$d}: ERRO — {$e->getMessage()}\n";
        exit(1);
    }
    switch ($r['status']) {
        case 'selado':
            $selados++;
            echo "[{$stamp}] {$d}: SELADO — {$r['count']} eventos (NSR {$r['first_nsr']}..{$r['last_nsr']})\n";
            echo "            head " . substr($r['head_hash'], 0, 32) . "...\n";
            break;
        case 'ja_selado':
            echo "[{$stamp}] {$d}: já selado.\n";
            break;
        case 'sem_eventos':
            echo "[{$stamp}] {$d}: sem eventos no livro — nada a selar.\n";
            break;
        default:
            echo "[{$stamp}] {$d}: {$r['status']} — " . ($r['message'] ?? '') . "\n";
    }
}

if ($selados > 0) {
    $ultimo = ledger_seal_latest($pdo);
    echo "\n";
    echo "PUBLIQUE ESTE HEAD FORA DO SISTEMA (e-mail ao empregador, ata, protocolo).\n";
    echo "É a publicação externa que dá valor ao selo: sem ela, quem controlar o\n";
    echo "servidor e a chave ainda poderia reescrever o passado sem deixar rastro.\n\n";
    echo "  Data ......: {$ultimo['seal_date']}\n";
    echo "  NSR .......: {$ultimo['first_nsr']} a {$ultimo['last_nsr']} ({$ultimo['event_count']} eventos)\n";
    echo "  Head ......: {$ultimo['head_hash']}\n";
    echo "  Assinatura : {$ultimo['signature']}\n";
    echo "  Chave ID ..: {$ultimo['pubkey_id']}\n\n";
    echo "Depois de publicar, registre a referência:\n";
    echo "  php bin/ledger_seal.php --anchor={$ultimo['seal_date']} --ref=\"<referencia>\"\n";
}

exit(0);

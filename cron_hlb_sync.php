#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * CRON — medição do desvio do relógio contra a Hora Legal Brasileira.
 * ==================================================================
 * Fase 0b.4 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * MODO OBSERVAÇÃO: apenas mede e registra. Nenhuma marcação de ponto é alterada
 * por este script. Aplicar a correção ao horário gravado é a Fase 6 — e a
 * decisão de virar essa chave depende justamente da série histórica que este
 * cron começa a acumular agora (a hospedagem permite NTP? qual o desvio real?).
 *
 * Agendamento sugerido — a cada 15 minutos:
 *
 *   0,15,30,45 * * * * cd /caminho/do/projeto && php cron_hlb_sync.php >> logs/hlb_sync.log 2>&1
 *
 * Uso manual:
 *   php cron_hlb_sync.php          # respeita o intervalo mínimo (HLB_SYNC_INTERVAL_HOURS)
 *   php cron_hlb_sync.php --force  # mede agora
 *   php cron_hlb_sync.php --status # só mostra o estado atual, sem medir
 */

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando (CLI)\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/hlb.php';

$argvList = $argv ?? [];
$force    = in_array('--force', $argvList, true);
$onlyShow = in_array('--status', $argvList, true);

$pdo   = db();
$stamp = date('Y-m-d H:i:s');

if ($onlyShow) {
    $st = hlb_status($pdo);
    echo "[{$stamp}] estado HLB\n";
    echo "  status      : {$st['status']}\n";
    echo "  fonte       : " . ($st['source'] !== '' ? $st['source'] : '(nunca sincronizado)') . "\n";
    echo "  offset_ms   : {$st['offset_ms']}\n";
    echo "  sincronizado: " . ($st['synced_at'] ?? '—') . "\n";
    echo "  idade (s)   : " . ($st['age_seconds'] ?? '—') . "\n";
    echo "  saudável    : " . ($st['healthy'] ? 'sim' : 'não') . "\n";
    exit(0);
}

// Usa o RETORNO de hlb_sync (valores frescos). hlb_status() leria do cache
// estático de get_setting, que set_setting não invalida — ver nota em lib/hlb.php.
$r = hlb_sync($pdo, $force);

if (!empty($r['skipped'])) {
    echo "[{$stamp}] pulado — medição recente ainda válida (fonte {$r['source']}, offset {$r['offset_ms']} ms)\n";
    exit(0);
}

if (!$r['ok']) {
    // Falha NÃO é erro fatal: em hospedagem compartilhada, UDP/123 bloqueado é o
    // caso esperado. O valor está justamente em registrar que não foi possível
    // comprovar a hora — silêncio aqui seria pior que a falha.
    echo "[{$stamp}] FALHA — {$r['error']}\n";
    echo "            (UDP/123 bloqueado é comum em hospedagem compartilhada;\n";
    echo "             verifique se o fallback HTTP também está bloqueado por firewall)\n";
    exit(1);
}

$offsetMs  = (int)$r['offset_ms'];
$maxOffset = (defined('HLB_MAX_OFFSET_SECONDS') ? (int)HLB_MAX_OFFSET_SECONDS : 120) * 1000;
$sinal     = $offsetMs > 0 ? 'adiantado' : ($offsetMs < 0 ? 'atrasado' : 'exato');

echo "[{$stamp}] OK — fonte {$r['source']}"
   . ($r['stratum'] !== null ? " (stratum {$r['stratum']})" : '')
   . ", offset " . sprintf('%+d', $offsetMs) . " ms ({$sinal})"
   . ($r['rtt_ms'] !== null ? ", rtt {$r['rtt_ms']} ms" : '')
   . "\n";

if (abs($offsetMs) > $maxOffset) {
    echo "            ATENÇÃO: desvio acima do limite de "
       . (int)($maxOffset / 1000) . " s — o relógio do servidor precisa ser corrigido.\n";
    exit(2);
}

exit(0);

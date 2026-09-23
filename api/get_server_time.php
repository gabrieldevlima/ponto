<?php
declare(strict_types=1);
/**
 * Hora do servidor + ÂNCORA DE TEMPO assinada.
 * Fase 6 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md — NC-13 / NC-14.
 *
 * Antes, este endpoint só devolvia `date()` cru e servia de fallback para o
 * PWA, que preferia consultar worldtimeapi.org — um serviço estrangeiro, o que
 * além de frágil contradizia a política de privacidade (NC-38).
 *
 * Agora devolve:
 *   - o instante do servidor corrigido pela Hora Legal Brasileira, com a
 *     proveniência da medição (NTP.br, fallback HTTP ou relógio local);
 *   - uma ÂNCORA assinada, que permite ao app provar depois — inclusive
 *     offline — a que instante uma marcação corresponde, sem que alterar o
 *     relógio do aparelho mude o resultado.
 *
 * Ver lib/time_anchor.php para o desenho e suas ressalvas.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/hlb.php';
require_once __DIR__ . '/../lib/time_anchor.php';

$pdo = db();

// O dispositivo é vinculado à âncora para que ela não sirva a outro aparelho.
// Aceita-se ausência: cliente antigo continua funcionando (âncora sem device).
$deviceId = (string)($_GET['device'] ?? $_GET['deviceId'] ?? '');

try {
    $anchor = time_anchor_issue($pdo, $deviceId);
} catch (Throwable $e) {
    error_log('[get_server_time] falha ao emitir ancora: ' . $e->getMessage());
    $anchor = null;
}

$agora = function_exists('hlb_now')
    ? hlb_now($pdo)
    : new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

$resp = [
    // Campos mantidos por compatibilidade com o PWA já instalado.
    'datetime'  => $agora->format('Y-m-d\TH:i:s.uP'),
    'timezone'  => 'America/Sao_Paulo',
    'source'    => 'server',
    'timestamp' => $agora->getTimestamp(),
    'date'      => $agora->format('Y-m-d'),
    'time'      => $agora->format('H:i:s'),
];

if ($anchor) {
    $resp['anchor'] = [
        'token'          => $anchor['token'],
        'server_time_ms' => $anchor['server_time_ms'],
        'expires_at'     => $anchor['expires_at'],
        'ttl'            => $anchor['ttl'],
    ];
    // Proveniência da hora: o app pode avisar o usuário quando o servidor não
    // conseguiu comprovar sincronia com a HLB.
    $resp['hlb'] = $anchor['hlb'];
}

echo json_encode($resp, JSON_UNESCAPED_UNICODE);

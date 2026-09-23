<?php
declare(strict_types=1);
/**
 * Hora Legal Brasileira (HLB) — medição do desvio do relógio do SERVIDOR.
 * ======================================================================
 * Fase 0b.4 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * O que este arquivo resolve (NC-13):
 * `config.php` declara `HLB_NTP_SERVER = 'a.st1.ntp.br'` desde a primeira
 * versão, mas a constante nunca foi lida por lugar nenhum. A sincronização que
 * de fato existia rodava no NAVEGADOR (fetch a worldtimeapi.org) e o offset
 * apurado voltava ao servidor DENTRO do payload do cliente — que então o usava
 * para decidir se confiava no horário informado pelo mesmo cliente. Aqui a
 * medição passa a ser feita pelo servidor, contra fonte brasileira.
 *
 * MODO OBSERVAÇÃO: nesta fase nada é aplicado às marcações. Mede-se, registra-se
 * em `hlb_sync_log` e publica-se o estado em `app_settings`. Aplicar o offset ao
 * horário gravado é a Fase 6 — depois de haver série histórica que mostre se a
 * hospedagem permite NTP e qual o desvio real.
 *
 * Por que a cadeia de fallback: hospedagem compartilhada (o caso aqui, Hostinger)
 * costuma bloquear UDP/123 de saída. Em vez de assumir que NTP funciona, o
 * código tenta em ordem e REGISTRA qual fonte respondeu — a proveniência da
 * medição é parte da prova, não um detalhe de implementação.
 *
 *   1. UDP/123 contra os servidores do NTP.br  → precisão de milissegundos
 *   2. Header Date: de https://ntp.br (HTTP)   → precisão de ~1 segundo
 *   3. Nada                                    → hlb_status 'failed'
 *
 * Convenção de sinal: offset_ms = relógio do servidor − referência.
 * Positivo = servidor ADIANTADO. Negativo = servidor ATRASADO.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}

/** Servidores NTP brasileiros, em ordem de preferência (NTP.br / Observatório Nacional). */
function hlb_ntp_hosts(): array {
    $primary = defined('HLB_NTP_SERVER') && HLB_NTP_SERVER !== '' ? (string)HLB_NTP_SERVER : 'a.st1.ntp.br';
    $hosts = [$primary, 'b.st1.ntp.br', 'c.st1.ntp.br', 'd.st1.ntp.br'];
    return array_values(array_unique($hosts));
}

/**
 * Consulta um servidor NTP e devolve o desvio do relógio local.
 *
 * Implementa o cálculo clássico do NTP com quatro carimbos:
 *   t1 = saída do cliente, t2 = chegada no servidor,
 *   t3 = saída do servidor, t4 = chegada no cliente
 *   offset = ((t2 - t1) + (t3 - t4)) / 2   → cancela a latência da rede
 *
 * Usa apenas stream_socket_client + unpack — sem extensão externa.
 *
 * @return array{offset_ms:int,rtt_ms:int,stratum:int}|null null se não responder
 */
function hlb_query_ntp(string $host, float $timeoutSec = 1.0): ?array {
    $errno = 0; $errstr = '';
    $t1 = microtime(true);
    $sock = @stream_socket_client("udp://{$host}:123", $errno, $errstr, $timeoutSec);
    if (!$sock) {
        return null;
    }
    stream_set_timeout($sock, (int)$timeoutSec, (int)(fmod($timeoutSec, 1.0) * 1e6));

    // Pacote NTPv4 de cliente: 48 bytes. Primeiro octeto 0x1B = LI 0, VN 3, Mode 3.
    if (@fwrite($sock, "\x1b" . str_repeat("\0", 47)) === false) {
        fclose($sock);
        return null;
    }
    $resp = @fread($sock, 48);
    $t4 = microtime(true);
    $meta = stream_get_meta_data($sock);
    fclose($sock);

    if (!empty($meta['timed_out']) || $resp === false || strlen($resp) < 48) {
        return null;
    }

    // Carimbo NTP: 32 bits de segundos desde 1900 + 32 bits de fração.
    // 2208988800 = segundos entre 1900-01-01 e a época Unix.
    $toUnix = static function (string $bytes): float {
        $sec  = unpack('N', substr($bytes, 0, 4))[1] - 2208988800;
        $frac = unpack('N', substr($bytes, 4, 4))[1] / 4294967296;
        return $sec + $frac;
    };

    $t2 = $toUnix(substr($resp, 32, 8)); // receive timestamp
    $t3 = $toUnix(substr($resp, 40, 8)); // transmit timestamp
    $stratum = ord($resp[1]);

    // stratum 0 = "kiss-o'-death"; resposta inválida como referência de tempo.
    if ($stratum === 0 || $t2 <= 0 || $t3 <= 0) {
        return null;
    }

    $offsetSec = (($t2 - $t1) + ($t3 - $t4)) / 2; // referência − local
    $rttSec    = ($t4 - $t1) - ($t3 - $t2);

    return [
        // Invertido para a convenção do projeto: positivo = servidor adiantado.
        'offset_ms' => (int)round(-$offsetSec * 1000),
        'rtt_ms'    => (int)round(max(0, $rttSec) * 1000),
        'stratum'   => $stratum,
    ];
}

/**
 * Fallback HTTP: lê o header `Date:` de uma fonte brasileira.
 * Precisão de ~1 segundo (o header não tem fração) — suficiente para detectar
 * um relógio grosseiramente errado, insuficiente para prova fina.
 *
 * @return array{offset_ms:int,rtt_ms:int,stratum:null}|null
 */
function hlb_query_http(string $url = 'https://ntp.br/', float $timeoutSec = 3.0): ?array {
    $ctx = stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => $timeoutSec, 'ignore_errors' => true,
                   'header' => "User-Agent: DEEDO-Ponto-HLB/1.0\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $t1 = microtime(true);
    $headers = @get_headers($url, true, $ctx);
    $t4 = microtime(true);

    if (!$headers || empty($headers['Date'])) {
        return null;
    }
    $dateHeader = is_array($headers['Date']) ? end($headers['Date']) : $headers['Date'];
    $ts = strtotime((string)$dateHeader);
    if ($ts === false) {
        return null;
    }

    // O header foi gerado em algum ponto do round-trip; assume o meio.
    $rtt       = $t4 - $t1;
    $reference = $ts + ($rtt / 2);

    return [
        'offset_ms' => (int)round(($t4 - $reference) * 1000),
        'rtt_ms'    => (int)round($rtt * 1000),
        'stratum'   => null,
    ];
}

/**
 * Executa uma medição e registra o resultado.
 *
 * @param bool $force ignora o intervalo mínimo entre medições
 * @return array{ok:bool,source:string,offset_ms:?int,rtt_ms:?int,stratum:?int,error:?string,skipped?:bool}
 */
function hlb_sync(PDO $pdo, bool $force = false): array {
    $intervalHours = defined('HLB_SYNC_INTERVAL_HOURS') ? (int)HLB_SYNC_INTERVAL_HOURS : 1;
    if ($intervalHours <= 0) $intervalHours = 1;

    if (!$force) {
        $last = get_setting('hlb_synced_at', '');
        if ($last) {
            $lastTs = strtotime((string)$last);
            if ($lastTs && (time() - $lastTs) < $intervalHours * 3600) {
                return ['ok' => true, 'skipped' => true, 'source' => (string)get_setting('hlb_offset_source', ''),
                        'offset_ms' => (int)get_setting('hlb_offset_ms', '0'), 'rtt_ms' => null,
                        'stratum' => null, 'error' => null];
            }
        }
    }

    $result = null; $source = 'server_clock'; $error = null;

    foreach (hlb_ntp_hosts() as $host) {
        $r = hlb_query_ntp($host);
        if ($r !== null) { $result = $r; $source = 'ntp:' . $host; break; }
    }

    if ($result === null) {
        // UDP/123 provavelmente bloqueado pela hospedagem — cai para HTTP.
        $r = hlb_query_http();
        if ($r !== null) {
            $result = $r;
            $source = 'http:ntp.br';
        } else {
            $error = 'nenhuma fonte de tempo respondeu (UDP/123 e HTTP falharam)';
        }
    }

    $ok = $result !== null;
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->prepare("INSERT INTO hlb_sync_log (checked_at, source, stratum, offset_ms, rtt_ms, ok, error)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$now, $source, $result['stratum'] ?? null, $result['offset_ms'] ?? null,
                       $result['rtt_ms'] ?? null, $ok ? 1 : 0, $error]);
    } catch (Throwable $e) {
        error_log('[hlb_sync] falha ao gravar hlb_sync_log: ' . $e->getMessage());
    }

    if ($ok) {
        // Livro fiscal — registro tipo 4 do AFD (ajuste do relógio).
        // Só quando o desvio muda de forma relevante (> 1 s): registrar cada
        // oscilação de milissegundos encheria o AFD de ruído sem informar nada.
        $anterior = (int)(get_setting('hlb_offset_ms', '0') ?? 0);
        $delta    = abs($result['offset_ms'] - $anterior);
        if ($delta > 1000 && function_exists('nsr_ledger_record_cadastro')) {
            // `reason` traz "antes|depois" — formato que lib/afd.php espera
            // para montar as datas/horas do registro tipo 4.
            $agoraTs = time();
            nsr_ledger_record_cadastro($pdo, 'clock_adjust', [
                'origin'        => 'system',
                'hlb_source'    => $source,
                'hlb_offset_ms' => $result['offset_ms'],
                'hlb_status'    => 'synced',
                'reason'        => date('Y-m-d H:i:s', (int)round($agoraTs - $anterior / 1000)) . '|'
                                 . date('Y-m-d H:i:s', (int)round($agoraTs - $result['offset_ms'] / 1000)),
            ]);
        }

        // Estado corrente em app_settings: o caminho quente lê daqui (get_setting
        // tem cache estático) e NUNCA abre socket durante um registro de ponto.
        //
        // Ressalva: helpers.set_setting() não invalida o cache estático de
        // get_setting(). Chamar hlb_status() DEPOIS de hlb_sync() na mesma
        // requisição devolve o valor anterior. Por isso hlb_sync() retorna os
        // valores frescos no próprio array — use o retorno, não hlb_status(),
        // quando precisar do resultado imediato (é o que cron_hlb_sync.php faz).
        set_setting('hlb_offset_ms', (string)$result['offset_ms']);
        set_setting('hlb_offset_source', $source);
        set_setting('hlb_synced_at', $now);
    } else {
        set_setting('hlb_last_error', $now . '|' . (string)$error);
    }

    return ['ok' => $ok, 'source' => $source, 'offset_ms' => $result['offset_ms'] ?? null,
            'rtt_ms' => $result['rtt_ms'] ?? null, 'stratum' => $result['stratum'] ?? null,
            'error' => $error];
}

/**
 * Estado corrente da sincronização, para o painel admin e para a Fase 6.
 *
 * @return array{offset_ms:int,source:string,synced_at:?string,age_seconds:?int,healthy:bool,status:string}
 */
function hlb_status(PDO $pdo = null): array {
    $offset   = (int)(get_setting('hlb_offset_ms', '0') ?? 0);
    $source   = (string)(get_setting('hlb_offset_source', '') ?? '');
    $syncedAt = get_setting('hlb_synced_at', '') ?: null;

    $age = null;
    if ($syncedAt) {
        $ts = strtotime((string)$syncedAt);
        if ($ts) $age = max(0, time() - $ts);
    }

    $intervalHours = defined('HLB_SYNC_INTERVAL_HOURS') ? (int)HLB_SYNC_INTERVAL_HOURS : 1;
    $maxOffsetMs   = (defined('HLB_MAX_OFFSET_SECONDS') ? (int)HLB_MAX_OFFSET_SECONDS : 120) * 1000;

    // "stale" = medição velha demais para ser usada como prova.
    $stale   = $age === null || $age > ($intervalHours * 3600 * 3);
    $inRange = abs($offset) <= $maxOffsetMs;
    $healthy = $source !== '' && !$stale && $inRange;

    $status = 'failed';
    if ($source !== '' && !$stale) $status = $inRange ? 'synced' : 'drift';
    elseif ($source !== '')        $status = 'stale';

    return ['offset_ms' => $offset, 'source' => $source, 'synced_at' => $syncedAt,
            'age_seconds' => $age, 'healthy' => $healthy, 'status' => $status];
}

/**
 * Instante corrente corrigido pela HLB.
 *
 * MODO OBSERVAÇÃO: hoje NADA chama esta função no caminho de gravação de ponto.
 * Ela existe para a Fase 6, quando `marked_at` passa a ser derivado daqui em vez
 * do relógio cru do servidor. Em hospedagem compartilhada não é possível ajustar
 * o relógio do sistema operacional, então a correção é aplicada em software e o
 * par (offset, fonte) é gravado junto da marcação — auditável e defensável.
 */
function hlb_now(PDO $pdo = null): DateTimeImmutable {
    $st  = hlb_status($pdo);
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    if (!$st['healthy'] || $st['offset_ms'] === 0) {
        return $now;
    }
    // offset positivo = servidor adiantado → subtrai para chegar na referência.
    return $now->modify(sprintf('%+d milliseconds', -$st['offset_ms']));
}

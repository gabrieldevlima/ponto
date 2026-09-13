<?php
declare(strict_types=1);
/**
 * Âncora de tempo assinada pelo servidor.
 * =======================================
 * Fase 6 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md — resolve NC-14.
 *
 * O RACIOCÍNIO CIRCULAR QUE ISTO SUBSTITUI
 * Até aqui, para decidir se aceitava o horário que o CLIENTE informava numa
 * marcação offline, o servidor consultava... o `hlbOffsetSeconds` que o mesmo
 * cliente enviava no payload. Quem quisesse retrodatar um ponto mandava
 * `hlbOffsetSeconds: 0` junto com o horário forjado e passava. As três
 * verificações existentes (offset dentro do limite, não-futuro, recente)
 * checavam a coerência interna do payload, não a sua veracidade.
 *
 * COMO A ÂNCORA RESOLVE
 *   1. Online, o app pede uma âncora: o servidor devolve o instante ATUAL dele
 *      e um token HMAC sobre esse instante, o dispositivo e a validade.
 *   2. O app guarda o token junto com o `performance.now()` daquele momento.
 *   3. Offline, ao registrar o ponto, o app calcula quanto tempo passou pelo
 *      relógio MONOTÔNICO (`performance.now()`), que não muda se o usuário
 *      alterar a hora do aparelho.
 *   4. Na sincronização, envia token + `elapsed_ms`. O servidor confere o HMAC
 *      e reconstrói `instante = server_time + elapsed_ms`.
 *
 * O horário deixa de ser uma afirmação do cliente e passa a ser derivado de um
 * instante que o SERVIDOR assinou. Mexer no relógio do aparelho não afeta nada;
 * forjar o token exige a chave, que não sai do servidor.
 *
 * RESSALVA HONESTA (a medir em campo antes de exigir a âncora)
 * Em iOS e Android, `performance.now()` pode CONGELAR enquanto o app está
 * suspenso em segundo plano. Se congelar, `elapsed_ms` fica MENOR que o tempo
 * real e o instante reconstruído cai ANTES do real. O viés é conservador do
 * ponto de vista do trabalhador (a marcação nunca "avança"), mas precisa ser
 * medido nos aparelhos usados antes de a âncora virar obrigatória.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}

/** Validade padrão da âncora: 24 h cobre uma jornada offline inteira. */
const TIME_ANCHOR_TTL_SECONDS = 86400;

/**
 * Tolerância para o relógio monotônico correr um pouco além do esperado.
 * `performance.now()` pode ter granularidade reduzida por mitigação de Spectre.
 */
const TIME_ANCHOR_SKEW_MS = 120000; // 2 min

/**
 * Chave de assinatura da âncora. Reusa a chave do quiosque, que já segue o
 * padrão constante → env → segredo gerado por instalação.
 *
 * Diferente da chave do livro fiscal, aqui um fallback persistido é aceitável:
 * a âncora protege contra o CLIENTE forjar horário, não contra quem já tem o
 * banco. Quem tem o banco pode alterar a marcação diretamente — e é a cadeia
 * do livro, com selo externo, que trata desse caso.
 */
function time_anchor_key(): string {
    if (defined('TIME_ANCHOR_KEY') && TIME_ANCHOR_KEY !== '') return (string)TIME_ANCHOR_KEY;
    $env = getenv('PONTO_TIME_ANCHOR_KEY');
    if ($env !== false && $env !== '') return (string)$env;
    return kiosk_signing_secret(); // segredo por instalação, nunca versionado
}

/**
 * Emite uma âncora para o dispositivo.
 *
 * @return array{token:string,server_time_ms:int,expires_at:int,ttl:int,hlb:array}
 */
function time_anchor_issue(PDO $pdo, string $deviceId = ''): array {
    // Instante corrigido pela HLB quando a medição está saudável; caso
    // contrário, o relógio do servidor com a proveniência declarada.
    $hlb = function_exists('hlb_status') ? hlb_status($pdo) : ['healthy' => false, 'offset_ms' => 0, 'source' => '', 'status' => 'failed'];
    $agora = function_exists('hlb_now')
        ? hlb_now($pdo)
        : new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

    $serverMs  = (int)round((float)$agora->format('U.u') * 1000);
    $expiresAt = time() + TIME_ANCHOR_TTL_SECONDS;
    $device    = substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', $deviceId), 0, 64);

    $payload = time_anchor_payload($serverMs, $device, $expiresAt);
    $sig     = hash_hmac('sha256', $payload, time_anchor_key());

    return [
        'token'          => base64_encode($payload) . '.' . $sig,
        'server_time_ms' => $serverMs,
        'expires_at'     => $expiresAt,
        'ttl'            => TIME_ANCHOR_TTL_SECONDS,
        'hlb'            => [
            'status'    => $hlb['status'] ?? 'failed',
            'source'    => $hlb['source'] ?? '',
            'offset_ms' => (int)($hlb['offset_ms'] ?? 0),
        ],
    ];
}

/** String canônica assinada. Delimitada por pipe, versionada — como no livro. */
function time_anchor_payload(int $serverMs, string $device, int $expiresAt): string {
    return implode('|', ['anchor-v1', (string)$serverMs, $device, (string)$expiresAt]);
}

/**
 * Valida uma âncora e reconstrói o instante da marcação.
 *
 * @param string $token     token devolvido por time_anchor_issue()
 * @param float  $elapsedMs quanto o relógio MONOTÔNICO do app avançou desde a emissão
 * @param string $deviceId  dispositivo que apresenta a âncora
 * @return array{ok:bool,motivo:string,marked_at:?string,idade_ms:?int}
 */
function time_anchor_resolve(string $token, float $elapsedMs, string $deviceId = ''): array {
    $falha = fn(string $m) => ['ok' => false, 'motivo' => $m, 'marked_at' => null, 'idade_ms' => null];

    if ($token === '') return $falha('sem ancora');
    $partes = explode('.', $token, 2);
    if (count($partes) !== 2) return $falha('ancora malformada');

    [$b64, $sig] = $partes;
    $payload = base64_decode($b64, true);
    if ($payload === false) return $falha('ancora malformada');

    $esperado = hash_hmac('sha256', $payload, time_anchor_key());
    if (!hash_equals($esperado, $sig)) return $falha('assinatura da ancora invalida');

    $campos = explode('|', $payload);
    if (count($campos) !== 4 || $campos[0] !== 'anchor-v1') return $falha('versao de ancora desconhecida');

    [, $serverMsStr, $device, $expiresStr] = $campos;
    $serverMs  = (int)$serverMsStr;
    $expiresAt = (int)$expiresStr;

    if ($expiresAt < time()) return $falha('ancora expirada');

    // Vincular ao dispositivo impede reaproveitar a âncora de outro aparelho.
    // Âncora emitida sem device (cliente antigo) segue aceita — o objetivo é
    // não quebrar quem ainda não atualizou o PWA.
    $devAtual = substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', $deviceId), 0, 64);
    if ($device !== '' && $devAtual !== '' && !hash_equals($device, $devAtual)) {
        return $falha('ancora emitida para outro dispositivo');
    }

    if ($elapsedMs < 0) return $falha('tempo monotonico negativo');
    $limite = ($expiresAt - (int)floor($serverMs / 1000)) * 1000 + TIME_ANCHOR_SKEW_MS;
    if ($elapsedMs > $limite) return $falha('tempo decorrido excede a validade da ancora');

    $marcadoMs = $serverMs + (int)round($elapsedMs);
    $agoraMs   = (int)round(microtime(true) * 1000);

    // Instante no futuro é impossível: ou o monotônico correu demais, ou é
    // tentativa de pós-datar. Tolera-se apenas o skew.
    if ($marcadoMs > $agoraMs + TIME_ANCHOR_SKEW_MS) {
        return $falha('instante reconstruido esta no futuro');
    }

    return [
        'ok'        => true,
        'motivo'    => '',
        'marked_at' => date('Y-m-d H:i:s', (int)floor($marcadoMs / 1000)),
        'idade_ms'  => $agoraMs - $marcadoMs,
    ];
}

/**
 * Decide o instante AUTORITATIVO de uma marcação.
 *
 * Esta é a função que substitui o bloco circular de api/checkin.php.
 *
 * Online  → sempre o relógio do servidor (corrigido pela HLB). O cliente não
 *           tem como influenciar.
 * Offline → tenta a âncora assinada. Se ela resolver, usa o instante
 *           reconstruído. Se não, grava a hora de RECEBIMENTO e devolve um
 *           motivo para `pending_reasons`.
 *
 * Nunca descarta a marcação: o registro de jornada é direito do trabalhador
 * (art. 74 da CLT) e não pode ser perdido porque o horário não pôde ser
 * comprovado. O que se faz é registrar a incerteza de forma explícita, para o
 * admin decidir.
 *
 * @return array{marked_at:string,fonte:string,comprovado:bool,motivo:?string,hlb_status:string,hlb_source:string,hlb_offset_ms:int}
 */
function time_anchor_authoritative(PDO $pdo, array $input, string $recordMode, string $deviceId = ''): array {
    $hlb = function_exists('hlb_status') ? hlb_status($pdo) : ['healthy' => false, 'offset_ms' => 0, 'source' => '', 'status' => 'failed'];
    $agora = function_exists('hlb_now')
        ? hlb_now($pdo)
        : new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $agoraStr = $agora->format('Y-m-d H:i:s');

    $base = [
        'hlb_status'    => (string)($hlb['status'] ?? 'failed'),
        'hlb_source'    => (string)($hlb['source'] ?? ''),
        'hlb_offset_ms' => (int)($hlb['offset_ms'] ?? 0),
    ];

    if ($recordMode !== 'offline') {
        return $base + ['marked_at' => $agoraStr, 'fonte' => 'servidor',
                        'comprovado' => true, 'motivo' => null];
    }

    $token   = (string)($input['timeAnchor'] ?? '');
    $elapsed = isset($input['anchorElapsedMs']) ? (float)$input['anchorElapsedMs'] : -1.0;

    if ($token === '' || $elapsed < 0) {
        return $base + [
            'marked_at' => $agoraStr, 'fonte' => 'recebimento', 'comprovado' => false,
            'motivo' => 'Horário não comprovado — registrado no momento da sincronização.',
            'hlb_status' => 'failed',
        ];
    }

    $r = time_anchor_resolve($token, $elapsed, $deviceId);
    if (!$r['ok']) {
        return $base + [
            'marked_at' => $agoraStr, 'fonte' => 'recebimento', 'comprovado' => false,
            'motivo' => 'Horário não comprovado (' . $r['motivo'] . ') — registrado na sincronização.',
            'hlb_status' => 'failed',
        ];
    }

    return $base + ['marked_at' => $r['marked_at'], 'fonte' => 'ancora', 'comprovado' => true,
                    'motivo' => null];
}

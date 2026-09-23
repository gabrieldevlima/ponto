<?php
declare(strict_types=1);

/**
 * Funções compartilhadas dos endpoints do Quiosque.
 *
 * Pré-requisito: config.php já carregado (db(), get_setting(), kiosk_signing_secret()).
 *
 * Segurança:
 *   - Device-auth por token (header X-Kiosk-Token), com hash sha256 em repouso.
 *   - Token de reconhecimento assinado (HMAC), vinculado ao device+teacher,
 *     com expiração curta e USO ÚNICO (consumido atomicamente via transição de
 *     status na linha de identify do kiosk_face_logs).
 *   - Toda imagem é validada (MIME real + tamanho) antes de salvar.
 */

// ---------------------------------------------------------------------------
// Requisição / resposta
// ---------------------------------------------------------------------------

function kiosk_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function kiosk_user_agent(): string
{
    return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function kiosk_json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Emite JSON e encerra. */
function kiosk_respond(array $data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    if (ob_get_level()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function kiosk_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        kiosk_respond(['status' => 'error', 'code' => 'method_not_allowed', 'message' => 'Método não permitido.'], 405);
    }
}

function kiosk_enabled(): bool
{
    return (string)(get_setting('kiosk_enabled', '0') ?? '0') === '1';
}

// ---------------------------------------------------------------------------
// Autenticação do dispositivo
// ---------------------------------------------------------------------------

function kiosk_token_from_request(): string
{
    $h = $_SERVER['HTTP_X_KIOSK_TOKEN'] ?? '';
    return is_string($h) ? trim($h) : '';
}

/**
 * Resolve o dispositivo a partir do header X-Kiosk-Token. Atualiza last_seen.
 * @return array|null linha de kiosk_devices, ou null se inválido/inativo.
 */
function kiosk_device_from_request(PDO $pdo): ?array
{
    $token = kiosk_token_from_request();
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    try {
        $st = $pdo->prepare("SELECT * FROM kiosk_devices WHERE device_token_hash = ? AND active = 1 LIMIT 1");
        $st->execute([$hash]);
        $dev = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
    if (!$dev) return null;
    try {
        $pdo->prepare("UPDATE kiosk_devices SET last_seen_at = NOW(), last_ip = ? WHERE id = ?")
            ->execute([kiosk_client_ip(), (int)$dev['id']]);
    } catch (Throwable $e) { /* não crítico */ }
    return $dev;
}

/** Exige device válido (ou 401) e quiosque habilitado (ou 403). Retorna o device. */
function kiosk_guard(PDO $pdo): array
{
    $dev = kiosk_device_from_request($pdo);
    if (!$dev) {
        kiosk_respond([
            'status' => 'error', 'code' => 'kiosk_unpaired',
            'message' => 'Dispositivo não autorizado. Refaça o pareamento com o administrador.',
        ], 401);
    }
    if (!kiosk_enabled()) {
        kiosk_respond([
            'status' => 'error', 'code' => 'kiosk_disabled',
            'message' => 'O modo quiosque está desativado. Procure o administrador.',
        ], 403);
    }
    return $dev;
}

// ---------------------------------------------------------------------------
// Rate limit (reusa o conceito kiosk_max_checkins_10min por dispositivo)
// ---------------------------------------------------------------------------

function kiosk_rate_limit_ok(PDO $pdo, int $deviceId): bool
{
    $max = (int)(get_setting('kiosk_max_checkins_10min', '30') ?? '30');
    if ($max <= 0) return true;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM kiosk_face_logs
                             WHERE device_id = ? AND event_type IN ('identify','checkin')
                               AND created_at >= (NOW() - INTERVAL 10 MINUTE)");
        $st->execute([$deviceId]);
        return (int)$st->fetchColumn() < $max;
    } catch (Throwable $e) {
        error_log('[kiosk] rate_limit fail-open (erro de contagem): ' . $e->getMessage());
        return true; // fail-open: não trava o quiosque por erro de contagem
    }
}

// ---------------------------------------------------------------------------
// Log de auditoria de reconhecimento facial
// ---------------------------------------------------------------------------

/**
 * Insere uma linha em kiosk_face_logs. Campos aceitos:
 *   device_id, teacher_id, event_type(req), status(req), confidence,
 *   action_attempted, attendance_id, photo, detail(array)
 * @return int id inserido (0 em falha).
 */
function kiosk_log(PDO $pdo, array $f): int
{
    try {
        $st = $pdo->prepare("INSERT INTO kiosk_face_logs
            (device_id, teacher_id, event_type, status, confidence, action_attempted, attendance_id, ip, user_agent, photo, detail)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $f['device_id'] ?? null,
            $f['teacher_id'] ?? null,
            (string)($f['event_type'] ?? 'identify'),
            (string)($f['status'] ?? 'unknown'),
            isset($f['confidence']) && $f['confidence'] !== null ? (float)$f['confidence'] : null,
            $f['action_attempted'] ?? null,
            $f['attendance_id'] ?? null,
            kiosk_client_ip(),
            substr(kiosk_user_agent(), 0, 255),
            $f['photo'] ?? null,
            isset($f['detail']) ? json_encode($f['detail'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('kiosk_log falhou: ' . $e->getMessage());
        return 0;
    }
}

// ---------------------------------------------------------------------------
// Imagem (decode seguro + persistência) — espelha hardening de checkin.php
// ---------------------------------------------------------------------------

/**
 * Decodifica uma dataURL base64 de imagem com validação de MIME e tamanho.
 * @return array ['ok'=>bool, 'bytes'=>?string, 'ext'=>?string, 'mime'=>?string, 'reason'=>?string]
 */
function kiosk_decode_image(string $dataUrl): array
{
    $maxBytes = 5 * 1024 * 1024;
    if (strpos($dataUrl, 'data:image') !== 0) {
        return ['ok' => false, 'reason' => 'format'];
    }
    $parts = explode(',', $dataUrl, 2);
    if (count($parts) !== 2) {
        return ['ok' => false, 'reason' => 'format'];
    }
    $decoded = base64_decode($parts[1], true);
    if ($decoded === false) {
        return ['ok' => false, 'reason' => 'decode'];
    }
    if (strlen($decoded) > $maxBytes) {
        return ['ok' => false, 'reason' => 'too_large'];
    }
    $info = @getimagesizefromstring($decoded);
    $allowed = ['image/jpeg', 'image/png'];
    if ($info === false || !in_array($info['mime'] ?? '', $allowed, true)) {
        return ['ok' => false, 'reason' => 'mime'];
    }
    $ext = ($info['mime'] === 'image/png') ? '.png' : '.jpg';
    return ['ok' => true, 'bytes' => $decoded, 'ext' => $ext, 'mime' => $info['mime']];
}

/** Salva bytes em public/photos e retorna o caminho relativo ('photos/xxx.jpg') ou null. */
function kiosk_save_photo(string $bytes, string $ext): ?string
{
    $dir = __DIR__ . '/../public/photos/';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            error_log('[kiosk] falha ao criar diretório de fotos: ' . $dir);
            return null;
        }
    }
    $fn = bin2hex(random_bytes(16)) . $ext;
    if (file_put_contents($dir . $fn, $bytes) === false) {
        error_log('[kiosk] file_put_contents falhou para foto');
        return null;
    }
    return 'photos/' . $fn;
}

/** Valida um photo_ref vindo do cliente (anti path traversal). */
function kiosk_valid_photo_ref(?string $ref): ?string
{
    if (!is_string($ref) || $ref === '') return null;
    return preg_match('#^photos/[a-f0-9]{32}\.(jpg|png)$#', $ref) ? $ref : null;
}

// ---------------------------------------------------------------------------
// Token de reconhecimento (identify -> checkin): HMAC, device-bound, uso único
// ---------------------------------------------------------------------------

function kiosk_b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function kiosk_b64url_decode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/'));
}

/** Assina um payload de reconhecimento. Formato: base64url(json).base64url(hmac). */
function kiosk_sign_recognition(array $payload): string
{
    $body = kiosk_b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig  = hash_hmac('sha256', $body, kiosk_signing_secret(), true);
    return $body . '.' . kiosk_b64url_encode($sig);
}

function kiosk_parse_recognition(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    [$body, $sig] = $parts;
    $expected = kiosk_b64url_encode(hash_hmac('sha256', $body, kiosk_signing_secret(), true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(kiosk_b64url_decode($body), true);
    return is_array($data) ? $data : null;
}

/**
 * Verifica (sem consumir) um token de reconhecimento: HMAC + expiração +
 * vínculo de dispositivo. NÃO toca o banco — use kiosk_consume_recognition()
 * para o consumo atômico de uso único logo antes de gravar o ponto.
 * Separar peek/consume evita "queimar" o token em erros de validação benignos
 * (ex.: o colaborador escolheu a ação errada) e mantém o guard de concorrência.
 * @return array|null ['teacher_id','confidence','log_id'] ou null se inválido.
 */
function kiosk_verify_recognition(string $token, int $deviceId): ?array
{
    $data = kiosk_parse_recognition($token);
    if (!$data) return null;
    if ((int)($data['device_id'] ?? 0) !== $deviceId) return null;
    if ((int)($data['exp'] ?? 0) < time()) return null;
    $logId     = (int)($data['log_id'] ?? 0);
    $teacherId = (int)($data['teacher_id'] ?? 0);
    if ($logId <= 0 || $teacherId <= 0) return null;
    return [
        'teacher_id' => $teacherId,
        'confidence' => (float)($data['confidence'] ?? 0),
        'log_id'     => $logId,
    ];
}

/**
 * Consome (uso único) o reconhecimento, de forma ATÔMICA: marca a linha de
 * identify do kiosk_face_logs como 'consumed' apenas se ainda estiver
 * 'recognized' (rowCount==1). Impede replay e troca de teacher_id, e garante
 * que dois requests concorrentes não gerem ponto duplicado.
 * @return bool true se consumiu agora (válido); false se já usado/inválido.
 */
function kiosk_consume_recognition(int $logId, int $teacherId, int $deviceId, PDO $pdo): bool
{
    if ($logId <= 0 || $teacherId <= 0) return false;
    try {
        $upd = $pdo->prepare("UPDATE kiosk_face_logs SET status = 'consumed'
                              WHERE id = ? AND event_type = 'identify' AND status = 'recognized'
                                AND teacher_id = ? AND device_id = ?");
        $upd->execute([$logId, $teacherId, $deviceId]);
        return $upd->rowCount() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Exibição
// ---------------------------------------------------------------------------

/** Nome de exibição amigável no quiosque (nome completo, sem outros PII). */
function kiosk_display_name(string $full): string
{
    return trim($full) !== '' ? trim($full) : 'Colaborador';
}

/**
 * Estado atual do ponto + próxima ação AUTO-DETECTADA, espelhando a máquina de
 * estados de kiosk_checkin.php / checkin.php. Permite ao quiosque pré-selecionar
 * a ação (o colaborador não precisa escolher entre 4 botões). Só o estado
 * TRABALHANDO é ambíguo (saída vs. iniciar intervalo) → can_break=true para
 * oferecer o intervalo como ação secundária. Fail-safe: em erro de leitura,
 * assume FORA (entrada) — o kiosk_checkin revalida o estado de qualquer forma.
 *
 * @return array{state:string,primary_action:string,primary_label:string,can_break:bool,since:?string}
 */
function kiosk_punch_state(PDO $pdo, int $teacherId): array
{
    $openWindow = (int)(get_setting('open_checkin_window_hours', '30') ?? '30');
    if ($openWindow <= 0) $openWindow = 30;
    $open = null;
    try {
        // NC-52: exclui anulados/substituídos (ver attendance_vigente_sql em helpers.php)
        $st = $pdo->prepare("SELECT id, check_in, record_type FROM attendance
                             WHERE teacher_id = ? AND check_in IS NOT NULL AND check_out IS NULL
                               AND " . attendance_vigente_sql() . "
                               AND check_in >= (NOW() - INTERVAL ? HOUR)
                             ORDER BY check_in DESC LIMIT 1");
        $st->execute([$teacherId, $openWindow]);
        $open = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $open = null;
    }
    $since = null;
    if ($open && !empty($open['check_in'])) {
        $ts = strtotime((string)$open['check_in']);
        if ($ts) $since = date('H:i', $ts);
    }
    if (!$open) {
        return ['state' => 'fora', 'primary_action' => 'entrada',
                'primary_label' => 'Registrar entrada', 'can_break' => false, 'since' => null];
    }
    $rt = (isset($open['record_type']) && $open['record_type'] !== null) ? (string)$open['record_type'] : 'work';
    if ($rt === 'work') {
        return ['state' => 'trabalhando', 'primary_action' => 'saida',
                'primary_label' => 'Registrar saída', 'can_break' => true, 'since' => $since];
    }
    return ['state' => 'em_intervalo', 'primary_action' => 'retornar_intervalo',
            'primary_label' => 'Retornar do intervalo', 'can_break' => false, 'since' => $since];
}

/**
 * Normaliza a ação do ponto vinda do quiosque para o vocabulário interno.
 * Espelha normalize_expected_action() de api/checkin.php — definido aqui com
 * outro nome para NÃO colidir com a definição daquele arquivo (que não é incluído).
 * @return 'entrada'|'saida'|'iniciar_intervalo'|'retornar_intervalo'|null
 */
function kiosk_normalize_action($value): ?string
{
    if ($value === null) return null;
    $v = strtolower(trim((string)$value));
    if ($v === '') return null;
    if (in_array($v, ['entrada', 'in', 'checkin', 'check_in', 'entry'], true)) return 'entrada';
    if (in_array($v, ['saida', 'saída', 'out', 'checkout', 'check_out', 'exit'], true)) return 'saida';
    if (in_array($v, ['break_start', 'iniciar_intervalo', 'sair_intervalo', 'pause'], true)) return 'iniciar_intervalo';
    if (in_array($v, ['break_end', 'retornar_intervalo', 'retorno', 'resume'], true)) return 'retornar_intervalo';
    return null;
}

/** Valida que um valor é um descriptor facial: array de 128 números finitos. */
function kiosk_is_valid_descriptor($d): bool
{
    if (!is_array($d) || count($d) !== 128) return false;
    foreach ($d as $v) {
        if (!is_numeric($v) || !is_finite((float)$v)) return false;
    }
    return true;
}

/**
 * Verificação facial 1:1 (motor local face-api.js): compara um descriptor de
 * 128 floats contra os descritores cadastrados de UM colaborador. Reusa os
 * limiares calibrados de get_face_thresholds('identify') + euclidean_distance().
 *
 * Aceita se: melhor distância < match_threshold E pelo menos ceil(total *
 * min_consensus_ratio) amostras dentro de consensus_threshold (votação por
 * consenso). Margem 2º-melhor não se aplica em 1:1 (há um único candidato).
 *
 * @return array ['ok'=>bool,'best_distance'=>?float,'hits'=>int,'total'=>int,'confidence'=>float]
 */
function kiosk_verify_face_1to1(array $descriptor, array $storedDescriptors): array
{
    $th   = get_face_thresholds('identify');
    $mThr = (float)$th['match_threshold'];
    $cThr = (float)$th['consensus_threshold'];
    $mR   = (float)$th['min_consensus_ratio'];
    // Override de limiar específico do quiosque (definido pela calibração).
    // Vazio = usa o limiar compartilhado (face_identify_threshold).
    $ovr = get_setting('kiosk_face_match_threshold', '');
    if ($ovr !== null && $ovr !== '' && is_numeric($ovr)) {
        $ov = (float)$ovr;
        if ($ov > 0 && $ov <= 1.5) $mThr = $ov;
    }

    $best = PHP_FLOAT_MAX;
    $hits = 0;
    $total = 0;
    foreach ($storedDescriptors as $ref) {
        if (!is_array($ref) || count($ref) !== 128) continue;
        $total++;
        $d = euclidean_distance($descriptor, $ref);
        if ($d < $best) $best = $d;
        if ($d <= $cThr) $hits++;
    }
    $minHits = max(1, (int)ceil($total * $mR));
    $ok = ($total > 0 && $best < $mThr && $hits >= $minHits);
    // Confiança como proxy amigável (0..1) a partir da menor distância.
    $confidence = $ok ? max(0.0, min(1.0, 1.0 - $best)) : 0.0;
    return [
        'ok' => $ok,
        'best_distance' => ($best === PHP_FLOAT_MAX ? null : round($best, 4)),
        'hits' => $hits,
        'total' => $total,
        'confidence' => round($confidence, 4),
    ];
}

/**
 * Anti brute-force por CPF: indica bloqueio temporário após N falhas
 * (not_matched) do mesmo colaborador dentro de uma janela de minutos.
 * Protege contra impersonação por tentativa-e-erro. Tentativas bem-sucedidas
 * saem naturalmente da janela. Fail-open em caso de erro de leitura.
 * @return array ['locked'=>bool,'fails'=>int,'window_min'=>int]
 */
function kiosk_cpf_locked_out(PDO $pdo, int $teacherId): array
{
    $max = (int)(get_setting('kiosk_cpf_max_fails', '5') ?? '5');
    $win = (int)(get_setting('kiosk_cpf_lockout_minutes', '10') ?? '10');
    if ($max <= 0 || $win <= 0 || $teacherId <= 0) {
        return ['locked' => false, 'fails' => 0, 'window_min' => $win];
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM kiosk_face_logs
                             WHERE teacher_id = ? AND event_type = 'identify' AND status = 'not_matched'
                               AND created_at >= (NOW() - INTERVAL ? MINUTE)");
        $st->execute([$teacherId, $win]);
        $fails = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[kiosk] lockout fail-open (erro de contagem): ' . $e->getMessage());
        return ['locked' => false, 'fails' => 0, 'window_min' => $win];
    }
    return ['locked' => $fails >= $max, 'fails' => $fails, 'window_min' => $win];
}

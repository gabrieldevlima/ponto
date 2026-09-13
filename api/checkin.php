<?php
// Desabilita TODOS os outputs que não sejam JSON
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL); // Loga tudo no ficheiro, mas não exibe

// Limites para processamento facial (produção pode ter defaults baixos)
set_time_limit(60);
ini_set('memory_limit', '256M');

// CORS preflight: header X-CSRF-Token é custom, browser pode enviar OPTIONS
// Respondemos antes de carregar config.php para evitar session_start() desnecessário
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 7200');
    http_response_code(204);
    exit;
}

// Limpa qualquer output buffer anterior
if (ob_get_level()) ob_end_clean();
ob_start();

// Handler global para erros fatais — garante resposta JSON mesmo em crash
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'error',
            'code' => 'server_error',
            'message' => 'Erro interno do servidor. Tente novamente.',
        ], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/nsr_ledger.php';
require_once __DIR__ . '/../lib/hlb.php';
require_once __DIR__ . '/../lib/time_anchor.php';
require_once __DIR__ . '/../lib/offline_auth.php';

// Define headers ANTES de qualquer output
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

$tzBR = new DateTimeZone('America/Sao_Paulo');

function api_error(int $httpCode, string $code, string $message, array $hints = [], ?string $field = null): void {
    // Limpa qualquer output anterior
    if (ob_get_level()) ob_clean();
    
    http_response_code($httpCode);
    echo json_encode([
        'status'  => 'error',
        'code'    => $code,
        'message' => $message,
        'hints'   => $hints,
        'field'   => $field
    ], JSON_UNESCAPED_UNICODE);
    
    // Força flush e termina
    if (ob_get_level()) ob_end_flush();
    exit;
}

function haversineDistance($lat1, $lon1, $lat2, $lon2): float {
    $R = 6371; // Raio da Terra em km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

function normalize_expected_action($value): ?string {
    if ($value === null) return null;
    $v = strtolower(trim((string)$value));
    if ($v === '') return null;
    if (in_array($v, ['entrada', 'in', 'checkin', 'check_in', 'entry'], true)) return 'entrada';
    if (in_array($v, ['saida', 'out', 'checkout', 'check_out', 'exit'], true)) return 'saida';
    if (in_array($v, ['break_start', 'iniciar_intervalo', 'sair_intervalo', 'pause'], true)) return 'iniciar_intervalo';
    if (in_array($v, ['break_end', 'retornar_intervalo', 'retorno', 'resume'], true)) return 'retornar_intervalo';
    return null;
}

function analyzePhotoQuality(string $filepath): array
{
    $reasons = [];
    $metrics = [
        'width' => null,
        'height' => null,
        'filesize' => null,
        'brightness_avg' => null,
        'brightness_std' => null,
        'laplacian_var' => null,
    ];

    if (!is_file($filepath)) {
        return ['ok' => false, 'reasons' => ['Arquivo de foto não encontrado'], 'metrics' => $metrics];
    }
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return ['ok' => false, 'reasons' => ['Extensão GD não disponível para avaliar a foto'], 'metrics' => $metrics];
    }

    $size = @getimagesize($filepath);
    if (!$size) {
        return ['ok' => false, 'reasons' => ['Arquivo de imagem inválido'], 'metrics' => $metrics];
    }
    [$width, $height] = $size;
    $filesize = @filesize($filepath) ?: 0;

    $metrics['width'] = $width;
    $metrics['height'] = $height;
    $metrics['filesize'] = $filesize;

    $minWidth  = 320;
    $minHeight = 320;
    $minFilesize = 10 * 1024;

    if ($width < $minWidth || $height < $minHeight) {
        $reasons[] = 'Resolução muito baixa';
    }
    if ($filesize > 0 && $filesize < $minFilesize) {
        $reasons[] = 'Arquivo muito comprimido/pequeno';
    }

    $data = @file_get_contents($filepath);
    if ($data === false) {
        return ['ok' => false, 'reasons' => ['Falha ao ler a foto'], 'metrics' => $metrics];
    }
    $img = @imagecreatefromstring($data);
    if (!$img) {
        return ['ok' => false, 'reasons' => ['Falha ao abrir a foto'], 'metrics' => $metrics];
    }

    $targetMax = 256;
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, $targetMax / max($w, $h));
    $rw = max(1, (int)floor($w * $scale));
    $rh = max(1, (int)floor($h * $scale));
    $res = imagecreatetruecolor($rw, $rh);
    imagecopyresampled($res, $img, 0, 0, 0, 0, $rw, $rh, $w, $h);
    imagedestroy($img);

    $sum = 0.0; $sum2 = 0.0; $n = 0;
    $lapSum = 0.0; $lapSum2 = 0.0; $lapN = 0;

    $grayAt = static function ($im, int $x, int $y): float {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    };

    for ($yy = 0; $yy < $rh; $yy++) {
        for ($xx = 0; $xx < $rw; $xx++) {
            $g = $grayAt($res, $xx, $yy);
            $sum += $g;
            $sum2 += $g * $g;
            $n++;
        }
    }

    if ($rw >= 3 && $rh >= 3) {
        for ($yy = 1; $yy < $rh - 1; $yy++) {
            for ($xx = 1; $xx < $rw - 1; $xx++) {
                $c = $grayAt($res, $xx, $yy);
                $l = $grayAt($res, $xx - 1, $yy);
                $r = $grayAt($res, $xx + 1, $yy);
                $t = $grayAt($res, $xx, $yy - 1);
                $b = $grayAt($res, $xx, $yy + 1);
                $lap = 4 * $c - $l - $r - $t - $b;
                $lapSum += $lap;
                $lapSum2 += $lap * $lap;
                $lapN++;
            }
        }
    }
    imagedestroy($res);

    if ($n > 0) {
        $mean = $sum / $n;
        $var = max(0.0, ($sum2 / $n) - ($mean * $mean));
        $std = sqrt($var);
        $metrics['brightness_avg'] = $mean;
        $metrics['brightness_std'] = $std;

        if ($mean < 70) $reasons[] = 'Escura';
        if ($mean > 200) $reasons[] = 'Clara';
        if ($std < 20) $reasons[] = 'Sem contraste';
    }

    if ($lapN > 0) {
        $lapMean = $lapSum / $lapN;
        $lapVar = max(0.0, ($lapSum2 / $lapN) - ($lapMean * $lapMean));
        $metrics['laplacian_var'] = $lapVar;
        if ($lapVar < 80) $reasons[] = 'Desfocada';
    }

    $ok = count($reasons) === 0;

    return ['ok' => $ok, 'reasons' => $reasons, 'metrics' => $metrics];
}

// DIAGNÓSTICO: Medir tempo de cada etapa
$debug_times = [];
$debug_start = microtime(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'method_not_allowed', 'Método não permitido.', []);
}
$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
$debug_times['parse_input'] = round((microtime(true) - $debug_start) * 1000, 2);
if (!is_array($input)) {
    api_error(400, 'invalid_json', 'JSON inválido na requisição.', []);
}

$cpf   = preg_replace('/\D/', '', (string)($input['cpf'] ?? ''));
$geo   = $input['geo'] ?? null;
$previewMode = !empty($input['preview']);
$pinInput = isset($input['pin']) ? preg_replace('/\D/', '', (string)$input['pin']) : '';
$pinAuthMode = $pinInput !== '' && strlen($pinInput) >= 4 && strlen($pinInput) <= 8;
// Cliente indica intenção de usar sessão (payload `use_session: true`). Se a
// sessão já expirou/invalidou, responde session_expired explicitamente — caso
// contrário o fluxo cairia em `cpf_required` (misleading, quebra o drain
// offline que trataria session_expired especificamente).
$wantsSession = !empty($input['use_session']);
$useSessionMode = $wantsSession && is_collaborator_logged();

// S9: defesa em profundidade contra CSRF cross-origin quando há sessão ativa.
// SameSite=Lax já bloqueia POST cross-site, mas validamos Origin/Referer
// explicitamente como segunda barreira (Safari antigo não respeita SameSite
// em alguns cenários, e bugs futuros de header ficam contidos).
if ($wantsSession) {
    $expectedHost = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $originHost = $origin !== '' ? (parse_url($origin, PHP_URL_HOST) ?: '') : '';
    $refererHost = $referer !== '' ? (parse_url($referer, PHP_URL_HOST) ?: '') : '';
    $sourceHost = $originHost !== '' ? $originHost : $refererHost;
    if ($expectedHost !== '' && $sourceHost !== '' && strcasecmp($sourceHost, $expectedHost) !== 0) {
        // Request veio de outro domínio com cookies de sessão — possível CSRF.
        api_error(403, 'origin_mismatch', 'Origem da requisição não autorizada.', []);
    }
    // Sem Origin nem Referer + use_session: aceita só se for fetch same-origin
    // do próprio app (ex: Service Worker drain). Browsers modernos enviam
    // Origin para fetch CORS-elegíveis. Se ambos vazios, assume mobile webview
    // legítimo (alguns WebViews omitem Referer por privacidade).
}

if ($wantsSession && !$useSessionMode) {
    api_error(200, 'session_expired', 'Sessão expirada. Faça login novamente.', [
        'Entre com CPF + PIN para continuar.'
    ], 'session');
}
if ($useSessionMode) {
    $sessCollab = current_collaborator($pdo ?? db());
    if (!$sessCollab) {
        api_error(200, 'session_expired', 'Sessão expirada. Faça login novamente.', [
            'Recarregue a página e entre com CPF + PIN.'
        ], 'session');
    }
    $cpf = preg_replace('/\D/', '', (string)$sessCollab['cpf']);
    // session-mode atua como se fosse pin_mode (auth forte já comprovada no login)
    $pinAuthMode = true;
    $pinInput = '';  // não validamos PIN aqui — a sessão é o fator
}
$faceDescriptor = $input['face_descriptor'] ?? null;
$faceAuthMode = is_array($faceDescriptor) && count($faceDescriptor) === 128;
$faceDescriptorValid = $faceAuthMode;
if ($faceAuthMode) {
    foreach ($faceDescriptor as $v) {
        if (!is_numeric($v) || !is_finite((float)$v)) {
            api_error(400, 'invalid_face_descriptor', 'Descriptor facial inválido.', [
                'Reinicie a captura facial e tente novamente.'
            ], 'face');
        }
    }
}
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$deviceFingerprintInput = isset($input['deviceFingerprint']) ? (string)$input['deviceFingerprint'] : '';

if (!$cpf && !$faceAuthMode && !$pinAuthMode) {
    api_error(400, 'cpf_required', 'CPF é obrigatório.', ['Informe seu CPF para registrar o ponto.'], 'cpf');
}
if ($pinAuthMode && !$cpf) {
    api_error(400, 'cpf_required', 'CPF é obrigatório junto com o PIN.', ['Informe seu CPF.'], 'cpf');
}
// HOTFIX SEGURANÇA 2026-09: o CPF não é segredo. O caminho "somente CPF" (sem
// PIN, sem sessão, sem face) criava marcação em nome de terceiros, devolvia o
// nome do colaborador e emitia token de cadastro facial. Sem uso legítimo desde
// 2026-04-30 (100% das marcações desde então são por PIN/sessão).
if (!$pinAuthMode && !$faceAuthMode) {
    api_error(401, 'pin_required', 'Informe seu CPF e PIN para registrar o ponto.', [
        'Entre com CPF + PIN para continuar.'
    ], 'pin');
}
// Inicializadas aqui porque só o fluxo facial as preenche, mas o INSERT as usa
// sempre (gerava ~16 mil warnings "Undefined variable" no log de produção).
$livenessScore = null;
$livenessData = null;

$pdo = db();
$authIdentifier = hash('sha256', $ip . '|' . substr($ua, 0, 120) . '|' . substr($deviceFingerprintInput, 0, 120));
// PIN tem precedência sobre face/CPF quando presente
$authAttemptType = $pinAuthMode ? 'pin' : ($faceAuthMode ? 'face' : 'cpf');
$rateLimit = auth_attempt_is_limited($pdo, $authAttemptType, $authIdentifier, 300, 10);
if (!empty($rateLimit['limited'])) {
    api_error(429, 'too_many_attempts', 'Muitas tentativas de autenticação. Tente novamente em instantes.', [
        'Aguarde alguns minutos e tente novamente.'
    ], 'cpf');
}

// Janela secundária (30 min / 20 tentativas) para PIN — limite de abuso do spec §2.4
if ($pinAuthMode) {
    $pinAbuse = auth_attempt_is_limited(
        $pdo, 'pin', $authIdentifier,
        1800,
        max(10, (int)(get_setting('pin_max_attempts_30min', '20') ?? '20'))
    );
    if (!empty($pinAbuse['limited'])) {
        api_error(429, 'too_many_attempts', 'Muitas tentativas nos últimos 30 minutos.', [
            'Use "Esqueci meu PIN" para gerar um novo.'
        ], 'pin');
    }
}

// M8: rate-limit dedicado para kiosk — impede que um device comprometido
// bata ponto de muitas pessoas em curto intervalo. Usa hash do UA+IP como
// identificador do "dispositivo kiosk" (não depende de CPF).
// Conta TODAS as tentativas (successes + failures) de check-in no mesmo device.
if (!$useSessionMode && $pinAuthMode) {
    $kioskIdent = 'kiosk:' . hash('sha256', $ip . '|' . substr($ua, 0, 120));
    $kioskMax = max(15, (int)(get_setting('kiosk_max_checkins_10min', '30') ?? '30'));
    try {
        $kioskCntStmt = $pdo->prepare("
            SELECT COUNT(*) FROM auth_attempt_logs
             WHERE attempt_type = 'pin'
               AND identifier = ?
               AND created_at >= (NOW() - INTERVAL 600 SECOND)
        ");
        $kioskCntStmt->execute([$kioskIdent]);
        $kioskCount = (int)$kioskCntStmt->fetchColumn();
        if ($kioskCount >= $kioskMax) {
            api_error(429, 'kiosk_abuse_detected', 'Este dispositivo atingiu o limite de batidas. Aguarde 10 minutos.', [
                'Se for um uso legítimo, procure o administrador.'
            ], 'pin');
        }
    } catch (Throwable $e) { /* não bloqueia fluxo principal */ }
    // Log preventivo — marca como sucesso (ponto será registrado se chegar ao fim)
    auth_attempt_log($pdo, 'pin', $kioskIdent, true, null, 'kiosk_checkin_attempt');
}

// ============================================================================
// IDEMPOTÊNCIA via client_id — se payload tem client_id e já existe no DB,
// retorna OK sem duplicar. Critical para o fluxo de sync offline: se o drain
// rodar 2x no mesmo payload, servidor não cria ponto duplicado.
// Sanitização: aceita apenas UUID-like (hex + hífen), 8-64 chars.
// ============================================================================
$clientId = isset($input['client_id']) ? (string)$input['client_id'] : '';
// Aceita hex (UUID padrão) + base36 (fallback do frontend quando crypto.randomUUID indisponível).
$clientId = substr(preg_replace('/[^0-9a-zA-Z\-]/', '', $clientId), 0, 64);
$expectedAction = normalize_expected_action($input['expected_action'] ?? $input['expectedAction'] ?? null);
if ($clientId !== '' && strlen($clientId) >= 8) {
    try {
        // S5: ownership check — quando há sessão de colaborador, exige que o
        // dedup bata com o MESMO teacher_id. Atacante com sessão de B não
        // consegue ver dados do ponto de A mesmo sabendo o client_id.
        // Em fluxo CPF-only (sem sessão), mantém compat: dedup global porque
        // o teacher só é identificado mais adiante.
        $sessionTeacherId = isset($_SESSION['collaborator_id']) ? (int)$_SESSION['collaborator_id'] : 0;
        if ($sessionTeacherId > 0) {
            $stDup = $pdo->prepare("SELECT id, nsr, check_in, approved FROM attendance WHERE client_id = ? AND teacher_id = ? LIMIT 1");
            $stDup->execute([$clientId, $sessionTeacherId]);
        } else {
            $stDup = $pdo->prepare("SELECT id, nsr, check_in, approved FROM attendance WHERE client_id = ? LIMIT 1");
            $stDup->execute([$clientId]);
        }
        $dup = $stDup->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'status'        => 'ok',
                'code'          => 'already_registered',
                'nsr'           => (int)($dup['nsr'] ?? 0),
                'attendance_id' => (int)$dup['id'],
                'approved'      => (int)($dup['approved'] ?? 0) === 1,
                'message'       => 'Este ponto já estava registrado.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        // Se a coluna não existir (migration não aplicada), continua — dedup fica desabilitado.
        error_log('checkin client_id dedup skipped: ' . $e->getMessage());
        $clientId = ''; // evita tentar inserir na coluna inexistente
    }
}

// Helper: distância euclidiana para comparação facial server-side
function face_euclidean_distance(array $a, array $b): float {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $diff = (float)$a[$i] - (float)$b[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

function issue_face_challenge(int $teacherId, string $deviceIdentifier): string {
    $token = bin2hex(random_bytes(32));
    $_SESSION['face_challenge'] = [
        'token' => $token,
        'teacher_id' => $teacherId,
        'device_id' => $deviceIdentifier,
        'expires_at' => time() + 120, // 2 minutos
    ];
    return $token;
}

function consume_face_challenge(?string $token, int $teacherId, string $deviceIdentifier): bool {
    if (!$token || !isset($_SESSION['face_challenge']) || !is_array($_SESSION['face_challenge'])) {
        return false;
    }
    $stored = $_SESSION['face_challenge'];
    $ok = isset($stored['token'], $stored['teacher_id'], $stored['device_id'], $stored['expires_at'])
        && hash_equals((string)$stored['token'], (string)$token)
        && (int)$stored['teacher_id'] === $teacherId
        && hash_equals((string)$stored['device_id'], $deviceIdentifier)
        && (int)$stored['expires_at'] >= time();
    unset($_SESSION['face_challenge']);
    return $ok;
}

function issue_inline_enrollment_token(int $teacherId, string $deviceIdentifier): string {
    $token = bin2hex(random_bytes(32));
    $_SESSION['inline_face_enroll'] = [
        'token' => $token,
        'teacher_id' => $teacherId,
        'device_id' => $deviceIdentifier,
        'expires_at' => time() + 600, // 10 minutos
    ];
    return $token;
}

// Resolve o colaborador com mensagens claras
$prof = null;
$faceAuthAudit = [];
$stepupReasons = [];
$stepupPerformed = false;

// PATH 0: Autenticação por PIN (prioritária — método padrão após spec de registro adaptativo)
if ($pinAuthMode) {
    if ($useSessionMode) {
        // HOTFIX 2026-09: em modo sessão o colaborador já está identificado pelo
        // id. Resolver de novo pelo CPF (LIMIT 1, sem ORDER BY) gravava o ponto
        // no cadastro errado quando há CPF duplicado ativo (existe em produção).
        $stmt = $pdo->prepare("SELECT id, name, active, network_wide, cpf, face_descriptors, pin_hash
                               FROM teachers WHERE id = ? AND active = 1 LIMIT 1");
        $stmt->execute([(int)$sessCollab['id']]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name, active, network_wide, cpf, face_descriptors, pin_hash
                               FROM teachers WHERE cpf = ? AND active = 1 ORDER BY id LIMIT 1");
        $stmt->execute([$cpf]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        auth_attempt_log($pdo, 'pin', $authIdentifier, false, null, 'cpf_not_found');
        api_error(401, 'cpf_invalid', 'CPF não encontrado ou colaborador inativo.', [
            'Verifique se digitou o CPF corretamente.',
            'Toque em "Primeiro acesso" se ainda não gerou seu PIN.'
        ], 'cpf');
    }

    if (!$useSessionMode) {
        // Fluxo clássico: validar PIN do payload
        if (empty($row['pin_hash'])) {
            auth_attempt_log($pdo, 'pin', $authIdentifier, false, (int)$row['id'], 'pin_not_set');
            api_error(200, 'pin_not_set', 'Você ainda não tem um PIN.', [
                'Toque em "Primeiro acesso" para gerar seu PIN.'
            ], 'pin');
        }

        if (!pin_verify($pinInput, (string)$row['pin_hash'])) {
            auth_attempt_log($pdo, 'pin', $authIdentifier, false, (int)$row['id'], 'pin_invalid');
            api_error(200, 'pin_invalid', 'PIN incorreto.', [
                'Verifique os dígitos e tente novamente.',
                'Toque em "Esqueci meu PIN" se não lembra.'
            ], 'pin');
        }
    }
    // Em session mode, confiamos na sessão (já autenticada no login.php via pin_verify).

    $teacherId = (int)$row['id'];
    $deviceFpTrust = trusted_device_fingerprint($ua, $ip, $deviceFingerprintInput);

    // NC-41: emite o TOKEN DE AUTORIZAÇÃO OFFLINE.
    //
    // Vai num header em vez de no corpo porque este endpoint tem muitos pontos
    // de saída (duplicata, já registrado, sucesso, erro de estado) e o cliente
    // precisa do token em todos eles. Header é definido uma vez e acompanha
    // qualquer resposta emitida daqui em diante.
    //
    // Com ele, o app deixa de precisar guardar o PIN no IndexedDB para
    // sincronizar depois — ver lib/offline_auth.php.
    if (!headers_sent() && function_exists('offline_auth_issue')) {
        $offAuth = offline_auth_issue($teacherId, (string)($row['cpf'] ?? ''),
                                      substr(hash('sha256', $ua . $ip . $deviceFingerprintInput), 0, 64));
        header('X-Offline-Auth: ' . $offAuth['token']);
        header('X-Offline-Auth-Expires: ' . $offAuth['expires_at']);
    }

    // Avalia gatilhos de segurança adaptativa
    $geoLatEval = isset($geo['lat']) ? (float)$geo['lat'] : null;
    $geoLngEval = isset($geo['lng']) ? (float)$geo['lng'] : null;
    $geoOutEval = false;
    if ($geoLatEval !== null && $geoLngEval !== null && (int)$row['network_wide'] !== 1) {
        $schoolsEval = get_teacher_allowed_schools($pdo, $teacherId);
        if (!empty($schoolsEval)) {
            $radiusEval = (float)((get_setting('geofence_radius_m', '300') ?? '300'));
            [$inR] = match_school_by_geo($schoolsEval, $geoLatEval, $geoLngEval, $radiusEval);
            $geoOutEval = !$inR;
        }
    }
    $gpsMockEval = !empty($input['fraudCheck']['mockDetected']);

    // Step-up facial desabilitado (decisão de produto 2026-05): reconhecimento
    // estava bloqueando colaboradores legítimos. Foto do check-in continua
    // sendo capturada e salva em attendance.photo_url para auditoria.
    $stepupReasons = [];

    if (!empty($stepupReasons)) {
        // Precisa validação facial para completar o ponto
        $hasFaceEnrolled = !empty($row['face_descriptors']);

        if (!$hasFaceEnrolled) {
            // Geo/gps_mock não disparam mais step-up (política simplificada).
            // Os únicos motivos atuais são `new_device` e `recent_failures`,
            // ambos compatíveis com auto-cadastro facial.
            $canSelfEnroll = true;
            if ($canSelfEnroll) {
                auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'self_enroll_offered', [
                    'reasons' => $stepupReasons
                ]);
                if (ob_get_level()) ob_clean();
                echo json_encode([
                    'status'  => 'error',
                    'code'    => 'require_face_enroll',
                    'message' => 'Seu cadastro ainda não tem foto. Vamos cadastrar agora para você bater ponto.',
                    'teacher' => [
                        'id'   => $teacherId,
                        'name' => $row['name'],
                        'cpf'  => mask_cpf($row['cpf'] ?? $cpf)
                    ],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'stepup_blocked_no_face', [
                'reasons' => $stepupReasons
            ]);
            audit_log('pin.checkin_blocked', 'teacher', $teacherId, [
                'reasons' => $stepupReasons,
                'cpf' => mask_cpf($cpf)
            ]);
            api_error(200, 'blocked_contact_admin', 'Precisamos de validação facial, mas seu cadastro ainda não tem foto.', [
                'Procure o administrador para concluir o cadastro facial.'
            ], 'pin');
        }

        if (!$faceDescriptorValid) {
            // M10: gera nonce de step-up em sessão para defender contra replay.
            // Frontend devolve o nonce junto com o face_descriptor na reentrada.
            $stepupNonce = bin2hex(random_bytes(16));
            $_SESSION['pin_stepup_nonce'] = [
                'nonce' => $stepupNonce,
                'teacher_id' => $teacherId,
                'expires_at' => time() + 300, // 5 min — dá tempo em rede lenta e para o usuário posicionar o rosto
            ];
            audit_log('pin.checkin_stepup_required', 'teacher', $teacherId, [
                'reasons' => $stepupReasons
            ]);
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'status' => 'require_face',
                'reasons' => $stepupReasons,
                'message' => pin_stepup_reason_message($stepupReasons),
                'stepup_nonce' => $stepupNonce,
                'teacher' => [
                    'id' => $teacherId,
                    'name' => $row['name'],
                    'cpf' => mask_cpf($row['cpf'] ?? $cpf)
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // M10: valida nonce do step-up antes de aceitar a face.
        $clientNonce = isset($input['stepup_nonce']) ? (string)$input['stepup_nonce'] : '';
        $stored = $_SESSION['pin_stepup_nonce'] ?? null;
        $nonceOk = is_array($stored)
            && isset($stored['nonce'], $stored['teacher_id'], $stored['expires_at'])
            && hash_equals((string)$stored['nonce'], $clientNonce)
            && (int)$stored['teacher_id'] === $teacherId
            && (int)$stored['expires_at'] >= time();
        // Consome o nonce independente do resultado (uso único).
        unset($_SESSION['pin_stepup_nonce']);
        if (!$nonceOk) {
            auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'stepup_nonce_invalid');
            api_error(200, 'stepup_nonce_invalid', 'Sessão de validação expirada. Repita o processo.', [
                'Toque em "BATER PONTO" de novo para tentar.'
            ], 'pin');
        }

        // Valida face_descriptor contra os descritores do teacher específico
        $storedPin = face_descriptors_decode($row['face_descriptors']);
        if (!is_array($storedPin) || empty($storedPin)) {
            api_error(200, 'face_corrupt', 'Cadastro facial inconsistente. Procure o administrador.', [], 'face');
        }
        $thPin = get_face_thresholds('identify');
        $mThr = (float)$thPin['match_threshold'];
        $cThr = (float)$thPin['consensus_threshold'];
        $mR   = (float)$thPin['min_consensus_ratio'];
        $bestPin = PHP_FLOAT_MAX; $hitsPin = 0; $totalPin = 0;
        foreach ($storedPin as $ref) {
            if (!is_array($ref) || count($ref) !== 128) continue;
            $okRef = true;
            foreach ($ref as $rv) { if (!is_numeric($rv) || !is_finite((float)$rv)) { $okRef = false; break; } }
            if (!$okRef) continue;
            $totalPin++;
            $dPin = face_euclidean_distance($faceDescriptor, $ref);
            if ($dPin < $bestPin) $bestPin = $dPin;
            if ($dPin <= $cThr) $hitsPin++;
        }
        $minHitsPin = max(1, (int)ceil($totalPin * $mR));
        $acceptPin = $totalPin > 0 && $bestPin < $mThr && $hitsPin >= $minHitsPin;
        if (!$acceptPin) {
            auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'stepup_face_not_matched', [
                'best' => round($bestPin, 4), 'hits' => $hitsPin, 'total' => $totalPin,
                'reasons' => $stepupReasons
            ]);
            api_error(200, 'stepup_face_not_matched', 'Não conseguimos confirmar sua foto.', [
                'Tente novamente em boa iluminação.',
                'Se continuar falhando, procure o administrador.'
            ], 'face');
        }

        $stepupPerformed = true;
        // Face bateu — promove device a trusted automaticamente
        if ($deviceFpTrust !== '') {
            trusted_device_enroll($pdo, $teacherId, $deviceFpTrust, 'face_validated');
        }
        // Zera $faceAuthMode para não executar o bloco de face-identification abaixo
        $faceAuthMode = false;
        $faceAuthAudit = [
            'stepup' => true,
            'stepup_reasons' => $stepupReasons,
            'best_distance' => round($bestPin, 6),
            'consensus_hits' => $hitsPin,
            'consensus_total' => $totalPin
        ];
    }

    $prof = $row;
}
// PATH 1: Autenticação por reconhecimento facial (sem PIN)
elseif ($faceAuthMode) {
    // Matching facial — três camadas independentes para máxima segurança:
    // 1. Threshold principal: distância mínima do colaborador < threshold
    // 2. Margem obrigatória: separação entre 1º e 2º colaborador (sem bypass)
    // 3. Votação por consenso: fração mínima dos samples deve confirmar o match
    // Usa thresholds de 'identify' para face matching em ambos os modos.
    // A seguranca vem das 3 camadas (threshold + margem + consenso) + liveness server-side,
    // nao de thresholds ultra-rigorosos que causam falsos negativos com 1 candidato.
    // Com 1 candidato, strictThreshold(checkin) = 0.23 — impossivel para capturas reais
    // (descriptors do mesmo rosto ja tem distancias de 0.27+).
    $faceThresholds         = get_face_thresholds('identify');
    $faceMatchThreshold     = $faceThresholds['match_threshold'];
    $faceSecondBestMargin   = $faceThresholds['second_best_margin'];
    $faceConsensusThreshold = $faceThresholds['consensus_threshold'];
    $faceMinConsensusRatio  = $faceThresholds['min_consensus_ratio'];

    // Pré-filtragem por escola via geolocalização (reduz pool de 200+ para ~30)
    $geoLat = isset($geo['lat']) ? (float)$geo['lat'] : null;
    $geoLng = isset($geo['lng']) ? (float)$geo['lng'] : null;
    $faceMatchCandidates = get_teachers_for_face_matching($pdo, $geoLat, $geoLng);

    // Rastreamento por colaborador com votação por consenso.
    $teacherDistances = [];
    $comparisonCount = 0;
    foreach ($faceMatchCandidates as $row) {
        $stored = face_descriptors_decode($row['face_descriptors']);
        if (!is_array($stored)) continue;
        $teacherMinDist = PHP_FLOAT_MAX;
        $hitCount = 0;
        $totalSamples = 0;
        $skippedSamples = 0;
        foreach ($stored as $ref) {
            if (!is_array($ref) || count($ref) !== 128) { $skippedSamples++; continue; }
            $comparisonCount++;
            $totalSamples++;
            $dist = face_euclidean_distance($faceDescriptor, $ref);
            if ($dist < $teacherMinDist) {
                $teacherMinDist = $dist;
            }
            if ($dist <= $faceConsensusThreshold) {
                $hitCount++;
            }
        }
        // G7: Log de descriptors corrompidos
        if ($skippedSamples > 0) {
            error_log(sprintf('[FaceMatch] Teacher %d: %d descriptors corrompidos de %d total', (int)$row['id'], $skippedSamples, $skippedSamples + $totalSamples));
        }
        if ($teacherMinDist < PHP_FLOAT_MAX) {
            $teacherDistances[] = [
                'row'          => $row,
                'dist'         => $teacherMinDist,
                'hitCount'     => $hitCount,
                'totalSamples' => $totalSamples,
            ];
        }
    }
    // Ordenar por distância crescente
    usort($teacherDistances, fn($a, $b) => $a['dist'] <=> $b['dist']);
    // bestEntry = colaborador mais próximo; secondBestDist = segundo mais próximo (diferente)
    $bestEntry      = $teacherDistances[0] ?? null;
    $bestMatch      = $bestEntry['row']    ?? null;
    $bestDist       = $bestEntry['dist']   ?? PHP_FLOAT_MAX;
    $secondBestDist = $teacherDistances[1]['dist'] ?? PHP_FLOAT_MAX;

    // Camada 1 — threshold principal
    $isThresholdOk = $bestMatch && $bestDist < $faceMatchThreshold;

    // Camada 2 — margem entre colaboradores (sem bypass: sempre exigida)
    $isMarginOk = false;
    $marginObserved = null;
    if ($bestMatch) {
        if (is_finite($secondBestDist)) {
            $marginObserved = $secondBestDist - $bestDist;
            $isMarginOk = ($marginObserved >= $faceSecondBestMargin);
        } else {
            // Apenas 1 colaborador na base: aceita apenas com threshold mais rígido
            $strictThreshold = max(0.0, $faceMatchThreshold - ($faceSecondBestMargin / 2));
            $isMarginOk = $bestDist <= $strictThreshold;
        }
    }

    // Camada 3 — votação por consenso: fração mínima dos samples deve confirmar
    // min_hits = max(1, ceil(N * ratio)) — sempre exige ao menos 1 hit
    $isConsensusOk = false;
    $consensusHits = 0;
    $consensusTotal = 0;
    $consensusMinHits = 1;
    if ($bestEntry) {
        $consensusHits    = $bestEntry['hitCount'];
        $consensusTotal   = $bestEntry['totalSamples'];
        $consensusMinHits = max(1, (int)ceil($consensusTotal * $faceMinConsensusRatio));
        $isConsensusOk    = ($consensusHits >= $consensusMinHits);
    }

    // G1: Exigir minimo de descriptors para que consenso seja efetivo
    $minDescriptorsForAuth = get_min_face_descriptors_for_auth();
    $insufficientEnrollment = ($consensusTotal > 0 && $consensusTotal < $minDescriptorsForAuth);

    $faceMatchAccepted = $isThresholdOk && $isMarginOk && $isConsensusOk && !$insufficientEnrollment;

    $faceAuditPayload = [
        'best_distance'        => is_finite($bestDist) ? round($bestDist, 6) : null,
        'second_best_distance' => is_finite($secondBestDist) ? round($secondBestDist, 6) : null,
        'threshold'            => round($faceMatchThreshold, 6),
        'second_best_margin'   => round($faceSecondBestMargin, 6),
        'margin_observed'      => is_null($marginObserved) ? null : round($marginObserved, 6),
        'consensus_threshold'  => round($faceConsensusThreshold, 6),
        'consensus_ratio'      => round($faceMinConsensusRatio, 6),
        'consensus_hits'       => $consensusHits,
        'consensus_total'      => $consensusTotal,
        'consensus_min_hits'   => $consensusMinHits,
        'comparison_count'     => $comparisonCount,
        'accepted'             => $faceMatchAccepted ? 1 : 0,
        'insufficient_enrollment' => $insufficientEnrollment ? 1 : 0,
        'min_descriptors_required' => $minDescriptorsForAuth,
    ];

    // G8: Flag de margem proxima para revisao admin (gemeos/irmaos)
    if ($isMarginOk && $marginObserved !== null && $marginObserved < ($faceSecondBestMargin * 2)) {
        $faceAuditPayload['close_margin_flag'] = true;
        $faceAuditPayload['margin_ratio'] = round($marginObserved / $faceSecondBestMargin, 3);
    }

    // G1: Rejeitar com codigo especifico se cadastro facial incompleto
    if ($insufficientEnrollment) {
        auth_attempt_log($pdo, 'face', $authIdentifier, false, null, 'face_insufficient_enrollment', $faceAuditPayload);
        api_error(200, 'face_insufficient_enrollment', 'Cadastro facial incompleto.', [
            'Seu cadastro facial precisa de mais amostras para autenticação.',
            'Use o CPF para registrar o ponto.',
            'Solicite ao administrador a atualização do cadastro facial.'
        ], 'face');
    }

    if ($faceMatchAccepted) {
        // ════════════════════════════════════════════════════════════════
        // C1: Se há sessão de colaborador ativa, o match facial DEVE coincidir
        // com o teacher da sessão — senão, um colega próximo poderia ter seu
        // ponto batido erroneamente no dispositivo de outra pessoa.
        // ════════════════════════════════════════════════════════════════
        if (is_collaborator_logged()) {
            $sessCollabId = (int)($_SESSION['collaborator_id'] ?? 0);
            $matchedId    = (int)($bestMatch['id'] ?? 0);
            if ($sessCollabId > 0 && $matchedId > 0 && $sessCollabId !== $matchedId) {
                auth_attempt_log($pdo, 'face', $authIdentifier, false, $matchedId, 'face_mismatch_session', [
                    'session_teacher_id' => $sessCollabId,
                    'matched_teacher_id' => $matchedId,
                ]);
                api_error(200, 'face_mismatch_session', 'O rosto detectado não corresponde ao colaborador logado.', [
                    'Peça ao dono deste dispositivo para entrar.',
                    'Ou use seu PIN para registrar o ponto.'
                ], 'face');
            }
        }

        // ════════════════════════════════════════════════════════════════
        // Validação de vivacidade (liveness) — server-side
        // O cliente envia liveness_data com métricas de micro-movimentos,
        // piscar, desafio ativo e análise de textura.
        // ════════════════════════════════════════════════════════════════
        $livenessEnabled = (get_setting('liveness_enabled', '1') ?? '1') === '1';
        $livenessData = $input['liveness_data'] ?? null;
        $livenessScore = null;

        // Liveness só é validado na confirmação final (não no preview de identificação)
        if ($livenessEnabled && $faceAuthMode && !$previewMode) {
            if (!is_array($livenessData)) {
                api_error(400, 'liveness_data_missing', 'Dados de verificação de vivacidade ausentes.', [
                    'Atualize o aplicativo para a versão mais recente.',
                    'Tente novamente.'
                ], 'liveness');
            }

            $lMinMovement = (float)(get_setting('liveness_min_movement', '0.002') ?? '0.002');
            $lMinFrames = (int)(get_setting('liveness_min_frames', '6') ?? '6');

            $lStddev = isset($livenessData['micro_movement_stddev']) ? (float)$livenessData['micro_movement_stddev'] : 0;
            $lFrames = isset($livenessData['frames_analyzed']) ? (int)$livenessData['frames_analyzed'] : 0;
            $lBlinks = isset($livenessData['blink_count']) ? (int)$livenessData['blink_count'] : 0;
            $lTexture = isset($livenessData['texture_score']) ? (float)$livenessData['texture_score'] : 0.5;
            $lChallengePassed = !empty($livenessData['challenge_passed']);
            $lNonce = isset($livenessData['nonce']) ? (string)$livenessData['nonce'] : '';
            $lTimestamp = isset($livenessData['timestamp_ms']) ? (int)$livenessData['timestamp_ms'] : 0;
            $lGyroVariance = isset($livenessData['gyro_variance']) ? (float)$livenessData['gyro_variance'] : 0;
            $lGyroAvailable = !empty($livenessData['gyro_available']);

            // ═══ Anti-replay: validar nonce ═══
            if (empty($lNonce) || strlen($lNonce) < 8) {
                api_error(200, 'liveness_failed', 'Dados de verificação inválidos.', [
                    'Tente novamente.'
                ], 'liveness');
            }
            // Verificar que timestamp é recente (max 60 segundos de diferença)
            $nowMs = (int)(microtime(true) * 1000);
            if (abs($nowMs - $lTimestamp) > 60000) {
                auth_attempt_log($pdo, 'face', $authIdentifier, false, null, 'liveness_timestamp_expired', [
                    'client_ts' => $lTimestamp, 'server_ts' => $nowMs
                ]);
                api_error(200, 'liveness_failed', 'Sessão de verificação expirada.', [
                    'Tente novamente — a captura deve ser recente.'
                ], 'liveness');
            }
            // Dedup: rejeitar nonce reutilizado (previne replay attacks)
            try {
                // Limpar nonces antigos (> 5 minutos)
                $pdo->exec("DELETE FROM liveness_nonces WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
                $stNonce = $pdo->prepare("INSERT INTO liveness_nonces (teacher_id, nonce) VALUES (?, ?)");
                $stNonce->execute([$bestMatch['id'], $lNonce]);
            } catch (PDOException $e) {
                if ((int)$e->errorInfo[1] === 1062) {
                    // Duplicate = replay attack
                    auth_attempt_log($pdo, 'face', $authIdentifier, false, null, 'liveness_replay_detected', [
                        'nonce' => $lNonce
                    ]);
                    api_error(200, 'liveness_failed', 'Dados de verificação já utilizados.', [
                        'Faça uma nova captura facial.'
                    ], 'liveness');
                }
                // Ignorar outros erros (tabela pode não existir ainda)
            }

            // ═══ Validações de plausibilidade ═══
            if ($lFrames < $lMinFrames) {
                auth_attempt_log($pdo, 'face', $authIdentifier, false, null, 'liveness_insufficient_frames', [
                    'frames' => $lFrames, 'required' => $lMinFrames
                ]);
                api_error(200, 'liveness_failed', 'Verificação de vivacidade insuficiente.', [
                    'Mantenha o celular firme e olhe para a câmera por mais tempo.',
                    'O ambiente deve ter boa iluminação.'
                ], 'liveness');
            }

            // ═══ Bloqueio de imagem estatica / video ═══
            // G2: Mais rigoroso — bloqueia quando:
            //   (1) sem movimento + sem blink (mesmo com challenge), OU
            //   (2) movimento quase zero sem challenge ativo
            if (($lStddev < $lMinMovement && $lBlinks === 0 && $lTexture < 0.4) || ($lStddev < 0.0005 && !$lChallengePassed)) {
                auth_attempt_log($pdo, 'face', $authIdentifier, false, null, 'liveness_static_image', [
                    'stddev' => $lStddev, 'blinks' => $lBlinks, 'texture' => $lTexture, 'challenge' => $lChallengePassed
                ]);
                api_error(200, 'liveness_failed', 'Verificação de vivacidade falhou.', [
                    'Não foi possível confirmar que você está ao vivo.',
                    'Olhe diretamente para a câmera e pisque naturalmente.'
                ], 'liveness');
            }

            // ═══ Score composto (0-1) — para auditoria e detecção de fraude ═══
            $livenessScore = 0;
            // Micro-movimentos (max 0.35) — sinal mais confiável
            if ($lStddev >= 0.005) $livenessScore += 0.35;
            elseif ($lStddev >= 0.003) $livenessScore += 0.25;
            elseif ($lStddev >= 0.001) $livenessScore += 0.10;
            // Piscar (max 0.25)
            if ($lBlinks >= 2) $livenessScore += 0.25;
            elseif ($lBlinks === 1) $livenessScore += 0.20;
            // Challenge (max 0.15)
            if ($lChallengePassed) $livenessScore += 0.15;
            // Texture — bônus, não penalidade (max 0.15)
            if ($lTexture >= 0.7) $livenessScore += 0.15;
            elseif ($lTexture >= 0.4) $livenessScore += 0.05;
            // Giroscópio — bônus se disponível (max 0.10)
            if ($lGyroAvailable && $lGyroVariance > 0.01) $livenessScore += 0.10;
            $livenessScore = min(1.0, $livenessScore);

            // ═══ Rate limiting para falhas de liveness ═══
            [$livenessLimited] = auth_attempt_is_limited($pdo, 'face_liveness', $authIdentifier, 300, 5);
            if ($livenessLimited) {
                api_error(429, 'too_many_liveness_attempts', 'Muitas tentativas de verificação facial.', [
                    'Aguarde alguns minutos e tente novamente.'
                ], 'liveness');
            }

            // G2: Score minimo obrigatorio — bloqueia se abaixo do limiar
            $lMinScore = (float)(get_setting('liveness_min_score', '0.35') ?? '0.35');
            if ($livenessScore < $lMinScore) {
                auth_attempt_log($pdo, 'face', $authIdentifier, false, null, 'liveness_score_too_low', [
                    'score' => round($livenessScore, 4), 'min' => $lMinScore,
                    'stddev' => $lStddev, 'blinks' => $lBlinks,
                    'texture' => $lTexture, 'challenge' => $lChallengePassed
                ]);
                api_error(200, 'liveness_failed', 'Verificação de vivacidade insuficiente.', [
                    'Olhe diretamente para a câmera e pisque naturalmente.',
                    'Se o problema persistir, use o CPF para registrar.'
                ], 'liveness');
            }

            // G2: Contagem minima de sinais positivos — impede que apenas 1 sinal (ex: video com movimento) passe
            $signalCount = 0;
            if ($lStddev >= 0.003) $signalCount++;
            if ($lBlinks >= 1) $signalCount++;
            if ($lChallengePassed) $signalCount++;
            if ($lTexture >= 0.5) $signalCount++;

            $lMinSignals = (int)(get_setting('liveness_min_signals', '2') ?? '2');
            if ($signalCount < $lMinSignals) {
                auth_attempt_log($pdo, 'face', $authIdentifier, false, null, 'liveness_insufficient_signals', [
                    'signals' => $signalCount, 'min' => $lMinSignals,
                    'stddev' => $lStddev, 'blinks' => $lBlinks,
                    'texture' => $lTexture, 'challenge' => $lChallengePassed
                ]);
                api_error(200, 'liveness_failed', 'Verificação de vivacidade inconclusiva.', [
                    'Mantenha o celular firme e pisque naturalmente.',
                    'Se o problema persistir, use o CPF para registrar.'
                ], 'liveness');
            }

            // Score baixo residual = flag de risco (para auditoria)
            if ($livenessScore < 0.30) {
                $faceAuditPayload['liveness_low_score_flag'] = true;
            }

            $faceAuditPayload['liveness_score'] = round($livenessScore, 4);
            $faceAuditPayload['liveness_stddev'] = $lStddev;
            $faceAuditPayload['liveness_blinks'] = $lBlinks;
            $faceAuditPayload['liveness_texture'] = $lTexture;
            $faceAuditPayload['liveness_challenge'] = $lChallengePassed;
            $faceAuditPayload['liveness_gyro'] = $lGyroVariance;
            $faceAuditPayload['liveness_nonce'] = $lNonce;
        }

        $prof = $bestMatch;
        $faceAuthAudit = $faceAuditPayload;
    } else {
        auth_attempt_log($pdo, 'face', $authIdentifier, false, null, 'face_not_recognized', $faceAuditPayload);
        // Retorna 200 (não 401) para que o browser não logue como erro vermelho no console.
        // O frontend detecta pela chave `code === 'face_not_recognized'` e abre o modal de PIN.
        api_error(200, 'face_not_recognized', 'Rosto não identificado.', [
            'Não foi possível confirmar sua identidade pelo rosto.',
            'Use o CPF para registrar o ponto.'
        ], 'face');
    }
} else {
    // Autenticação por CPF (sem face)
    $stmt = $pdo->prepare("SELECT id, name, active, network_wide, cpf, face_descriptors FROM teachers WHERE cpf = ? AND active = 1 LIMIT 1");
    $stmt->execute([$cpf]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        auth_attempt_log($pdo, 'cpf', $authIdentifier, false, null, 'cpf_not_found');
        api_error(401, 'cpf_invalid', 'CPF não encontrado ou colaborador inativo.', [
            'Verifique se digitou o CPF corretamente.',
            'Procure o Admin/RH em caso de dúvidas.'
        ], 'cpf');
    }

    $prof = $row;
    $debug_times['cpf_verify'] = round((microtime(true) - $debug_start) * 1000, 2);
}

$teacherId = (int)$prof['id'];
$today = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d');
$now = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d H:i:s');

// ============================================================================
// Detector de pontos orfaos (entrada sem saida) FORA da janela de turno em
// andamento (>30h) e ate 14 dias atras. Bloqueia nova batida e devolve ao
// cliente o(s) ponto(s) pendente(s) para que o colaborador escolha:
//   (a) Regularizar — abre modal e cria solicitacao em attendance_checkout_requests
//   (b) Registrar mesmo assim — reenvia com acknowledge_orphan=true
// ----------------------------------------------------------------------------
$acknowledgeOrphan = !empty($input['acknowledge_orphan']) || !empty($_GET['acknowledge_orphan']);
if (!$acknowledgeOrphan) {
    $orphans = detect_checkout_orphans($pdo, $teacherId);
    if (!empty($orphans)) {
        $orphanPayload = [];
        foreach ($orphans as $o) {
            $orphanPayload[] = [
                'attendance_id' => (int)$o['id'],
                'date'          => $o['date'],
                'check_in'      => $o['check_in'],
                'school_name'   => $o['school_name'] ?? null,
                'pending_regularization' => !empty($o['pending_regularization']),
                'request_status' => $o['request_status'] ?? null,
            ];
        }
        if (ob_get_level()) ob_clean();
        // status='blocked' (nao 'ok'): clientes antigos sem o handler novo caem
        // no fallback de erro/toast em vez de mostrar tela de sucesso vazia.
        echo json_encode([
            'status'  => 'blocked',
            'code'    => 'orphan_punch_pending',
            'message' => 'Sua batida NAO foi registrada. Voce tem ponto(s) anteriores sem saida que precisam ser regularizados antes.',
            'orphans' => $orphanPayload,
            'can_acknowledge_and_continue' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
$lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
$lng = isset($geo['lng']) ? (float)$geo['lng'] : null;
$acc = isset($geo['acc']) ? (float)$geo['acc'] : null;

$authOkDetails = $faceAuthMode ? $faceAuthAudit : [];
auth_attempt_log($pdo, $authAttemptType, $authIdentifier, true, $teacherId, 'auth_ok', $authOkDetails);

// ============================================================================
// PORTARIA MTP 671/2021 - Campos obrigatórios
// ----------------------------------------------------------------------------
// B6 (doc): flags de offline/sync cobertas pelas colunas existentes em
// `attendance`:
//   - record_mode    : 'online' | 'offline' (cliente indica se estava conectado)
//   - recorded_at    : timestamp do evento (cliente)
//   - synced_at      : timestamp de recebimento no servidor (null se offline+pendente)
//   - hlb_sync_status: 'synced' | 'failed' | 'pending' | 'legacy'
//   - hlb_offset_seconds: diferença entre relógio cliente e HLB no momento do registro
// Admin audita offline via filtros em attendance; não é necessária flag extra.
// ============================================================================
$recordMode = isset($input['recordMode']) ? (string)$input['recordMode'] : 'online';
// S1: timestamp final é SEMPRE do servidor — anti-fraude. Atacante com PIN
// não pode mais "registrar ponto retroativo" enviando recordedAt no passado.
// O timestamp original do cliente fica em `client_recorded_at` para audit
// trail de pontos que ficaram offline e dropparam tarde.
$clientRecordedAtRaw = isset($input['recordedAt']) ? (string)$input['recordedAt'] : '';
$clientRecordedAt = $clientRecordedAtRaw !== '' ? $clientRecordedAtRaw : $now;
// HOTFIX 2026-09 (fuso): o PWA envia `new Date().toISOString()` cortado para
// "YYYY-MM-DD HH:MM:SS" — hora UTC SEM indicador de fuso — e o strtotime abaixo
// a lia como horário de Brasília. Resultado em produção: client_recorded_at 3h
// adiantado e offline_delay_seconds ≈ 10.800 s em TODAS as marcações online.
// Strings sem fuso vindas do cliente são UTC; strings com Z/offset são
// respeitadas. Grava sempre no fuso de Brasília, formato DATETIME.
if ($clientRecordedAtRaw !== '') {
    try {
        $__rawTs = trim($clientRecordedAtRaw);
        $__hasTz = (bool)preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $__rawTs);
        $__dtClient = new DateTimeImmutable($__rawTs, $__hasTz ? null : new DateTimeZone('UTC'));
        $clientRecordedAt = $__dtClient->setTimezone($tzBR)->format('Y-m-d H:i:s');
    } catch (Throwable $_) {
        $clientRecordedAt = $now; // formato inválido: não confia no valor
    }
}
$recordedAt = $now; // Sempre timestamp do servidor
// Calcula delta para auditoria (positivo = ponto offline antigo; pode haver
// drift de relógio do cliente — usuário com hora errada não compromete o
// timestamp gravado, apenas o delta auditado).
$offlineDelaySeconds = 0;
$tsClient = strtotime($clientRecordedAt);
$tsServer = strtotime($now);
if ($tsClient && $tsServer) {
    // H1: usa magnitude (abs) — preserva info quando relógio do cliente está
    // adiantado (cenário de fraude ou drift). Antes, max(0, ...) zerava deltas
    // negativos e o admin não detectava o problema.
    $offlineDelaySeconds = abs($tsServer - $tsClient);
    // Cap em 30 dias para evitar valores absurdos por relógio do cliente errado
    if ($offlineDelaySeconds > 30 * 86400) $offlineDelaySeconds = 30 * 86400;
}
$syncedAt = $recordMode === 'online' ? $now : null;
$hlbSyncStatus = 'synced'; // Será atualizado se houver sincronização HLB
$hlbOffsetSeconds = isset($input['hlbOffsetSeconds']) ? (int)$input['hlbOffsetSeconds'] : 0;

// ============================================================================
// INSTANTE AUTORITATIVO DA MARCAÇÃO — NC-14 (Fase 6 da auditoria)
//
// O bloco anterior decidia se aceitava o horário informado pelo CLIENTE
// consultando o `hlbOffsetSeconds` que o MESMO cliente enviava. Era circular:
// quem quisesse retrodatar um ponto mandava `hlbOffsetSeconds: 0` junto com o
// horário forjado e passava. As três condições verificavam a coerência interna
// do payload, não sua veracidade.
//
// Agora:
//   online  → relógio do servidor corrigido pela HLB;
//   offline → âncora ASSINADA pelo servidor + tempo monotônico do app.
//             Alterar o relógio do aparelho não muda o resultado.
//
// Sem âncora válida, a marcação NÃO é descartada — grava-se a hora de
// recebimento e a incerteza vai explícita para `pending_reasons`. O registro de
// jornada é direito do trabalhador (art. 74 da CLT) e não pode se perder porque
// o horário não pôde ser comprovado; o que não se pode é fingir que foi.
// ============================================================================
// A resolução em si acontece logo após $deviceIdentifier ser calculado (a
// âncora é vinculada ao dispositivo), e o efeito em pending_reasons é aplicado
// onde esse array existe. Procure por "ÂNCORA DE TEMPO" abaixo.
//
// `hlbOffsetSeconds` continua sendo recebido e gravado, mas REBAIXADO a campo
// de auditoria: não participa mais de nenhuma decisão.

// ============================================================================
// ANTI-FRAUDE - Validação de Localização
// ============================================================================
$fraudCheck = isset($input['fraudCheck']) ? $input['fraudCheck'] : null;
$deviceFingerprint = isset($input['deviceFingerprint']) ? $input['deviceFingerprint'] : null;

// Análise de fraude
$fraudRiskLevel = 0;
$gpsMockDetected = 0;
$fraudDetections = [];

if ($fraudCheck && is_array($fraudCheck)) {
    $fraudRiskLevel = isset($fraudCheck['riskLevel']) ? (int)$fraudCheck['riskLevel'] : 0;
    $gpsMockDetected = isset($fraudCheck['mockDetected']) && $fraudCheck['mockDetected'] ? 1 : 0;
    
    if (!empty($fraudCheck['indicators'])) {
        foreach ($fraudCheck['indicators'] as $indicator) {
            $fraudDetections[] = [
                'type' => $indicator,
                'risk_level' => $fraudRiskLevel
            ];
        }
    }
}

// Identificador do dispositivo (user agent + IP + client fingerprint hash)
$clientFingerprint = isset($input['deviceFingerprint']) ? (string)$input['deviceFingerprint'] : '';
$deviceIdentifier = substr(hash('sha256', $ua . $ip . $clientFingerprint), 0, 64);

// ============================================================================
// ÂNCORA DE TEMPO — instante autoritativo da marcação (NC-14, Fase 6)
//
// Precisa vir depois de $deviceIdentifier porque a âncora é vinculada ao
// dispositivo que a recebeu. O efeito em pending_reasons é aplicado mais
// abaixo, onde o array existe.
// ============================================================================
$tempoAut          = time_anchor_authoritative($pdo, $input, $recordMode, $deviceIdentifier);
$authoritativeTime = $tempoAut['marked_at'];
$hlbSyncStatus     = $tempoAut['hlb_status'];

// Descobre o modo de agenda do colaborador
$stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
$stMode->execute([$teacherId]);
$mode = $stMode->fetchColumn() ?: 'classes';

// Weekday efetivo: por padrão é o dia real; se a data tem exceção workday
// (sábado letivo) com reflects_weekday, usa o dia referenciado para procurar
// a jornada do colaborador.
$weekday = get_effective_weekday($pdo, $today, null);
$currentTime = (new DateTimeImmutable('now', $tzBR))->format('H:i:s');

// Verifica se há ponto aberto (entrada sem saída) — busca o último, sem filtrar
// por data, para suportar turnos noturnos (entrada às 22h ontem fecha às 06h hoje).
//
// Race condition prevention: GET_LOCK serializa requisições concorrentes do
// mesmo teacher. Sem isso, dois POSTs próximos podiam executar:
//   - POST#1: $open=NULL → INSERT entrada (id=N)
//   - POST#2: $open=N    → UPDATE id=N SET check_out=NOW()
// Resultado: UM registro com check_in e check_out quase simultâneos.
// Sintoma reportado: "vai bater entrada e registra entrada+saída ao mesmo tempo".
// Lock por teacher (sem data) — antes incluía $today e abria race entre 23:59 e 00:01.
// Lock auto-libera ao fim da conexão PHP (via shutdown).
$__checkinGate = sprintf('checkin_gate:%d', $teacherId);
try {
    $stLock = $pdo->prepare("SELECT GET_LOCK(?, 5)");
    $stLock->execute([$__checkinGate]);
    $__gotLock = (int)$stLock->fetchColumn();
    $stLock->closeCursor();
    if ($__gotLock !== 1) {
        api_error(429, 'busy_retry', 'Outro registro está em andamento. Aguarde alguns segundos e tente novamente.', []);
    }
    register_shutdown_function(static function () use ($pdo, $__checkinGate) {
        try { $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$__checkinGate]); } catch (Throwable $_) {}
    });
} catch (Throwable $_) {
    // HOTFIX 2026-09: antes seguia SEM o lock, reabrindo a corrida entrada/saída
    // que o lock existe para impedir. Falha de lock = pedir nova tentativa.
    error_log('[checkin] GET_LOCK falhou: ' . $_->getMessage());
    api_error(429, 'busy_retry', 'Outro registro está em andamento. Aguarde alguns segundos e tente novamente.', []);
}

// HOTFIX 2026-09: a checagem de idempotência por client_id do topo roda ANTES
// do lock. Um retry que chega enquanto a requisição original ainda está
// gravando passa por ela, espera o lock e, sem esta segunda checagem, vira uma
// nova ação (ex.: saída logo após a própria entrada). Repete sob o lock.
if ($clientId !== '' && strlen($clientId) >= 8) {
    try {
        $stDupLocked = $pdo->prepare("SELECT id, nsr, approved FROM attendance WHERE client_id = ? AND teacher_id = ? LIMIT 1");
        $stDupLocked->execute([$clientId, $teacherId]);
        if ($dupLocked = $stDupLocked->fetch(PDO::FETCH_ASSOC)) {
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'status'        => 'ok',
                'code'          => 'already_registered',
                'nsr'           => (int)($dupLocked['nsr'] ?? 0),
                'attendance_id' => (int)$dupLocked['id'],
                'approved'      => (int)($dupLocked['approved'] ?? 0) === 1,
                'message'       => 'Este ponto já estava registrado.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        error_log('checkin client_id locked dedup skipped: ' . $e->getMessage());
    }
}

// Dedup global de saida: retry apos checkout bem-sucedido nao pode cair em
// "entrada" so porque o ponto ja foi fechado na primeira tentativa.
if ($clientId !== '' && strlen($clientId) >= 8) {
    try {
        $stDupOutGlobal = $pdo->prepare("SELECT id, nsr, approved FROM attendance WHERE checkout_client_id = ? AND teacher_id = ? LIMIT 1");
        $stDupOutGlobal->execute([$clientId, $teacherId]);
        if ($dupOutGlobal = $stDupOutGlobal->fetch(PDO::FETCH_ASSOC)) {
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'status'        => 'ok',
                'code'          => 'already_registered',
                'action'        => 'saída',
                'nsr'           => (int)($dupOutGlobal['nsr'] ?? 0),
                'attendance_id' => (int)$dupOutGlobal['id'],
                'approved'      => is_null($dupOutGlobal['approved']) ? null : (int)$dupOutGlobal['approved'] === 1,
                'collaborator'  => ['id'=>$teacherId,'name'=>$prof['name'],'cpf'=>$prof['cpf'] ?? null],
                'teacher'       => ['id'=>$teacherId,'name'=>$prof['name'],'cpf'=>$prof['cpf'] ?? null],
                'message'       => 'Saída já registrada anteriormente.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        error_log('checkin checkout_client_id global dedup skipped: ' . $e->getMessage());
    }
}

// Busca registro aberto dentro da janela de "turno em andamento" — suporta
// turnos noturnos (saída no dia seguinte, até 24h depois) mas ignora órfãos
// antigos (esquecimento de bater saída há dias). Default 30h cobre turno de
// 24h + 6h de tolerância. Configurável via app_settings.open_checkin_window_hours.
$openWindow = (int)(function_exists('get_setting') ? get_setting('open_checkin_window_hours', '30') : 30);
if ($openWindow <= 0) $openWindow = 30;
// NC-52: exclui registros anulados/substituídos. Sem isso, um registro ABERTO
// que o admin anulou continuava sendo encontrado como "em aberto" e o
// colaborador conseguia fechá-lo, ressuscitando uma marcação já descartada.
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND check_in IS NOT NULL AND check_out IS NULL AND " . attendance_vigente_sql() . " AND check_in >= (NOW() - INTERVAL ? HOUR) ORDER BY check_in DESC LIMIT 1");
$stmt->execute([$teacherId, $openWindow]);
$open = $stmt->fetch(PDO::FETCH_ASSOC);

// Tipo do registro aberto (NULL se nenhum). Coluna pode não existir em ambientes sem
// a migration 2026_05_25; default 'work' preserva compatibilidade.
$openRecordType = $open ? ((isset($open['record_type']) && $open['record_type'] !== null) ? (string)$open['record_type'] : 'work') : null;

// Determina a ação a partir do estado real do colaborador + intenção do cliente.
// Estados: FORA (sem open), TRABALHANDO (open work), EM_INTERVALO (open break).
// Ações: 'entrada' | 'saida' | 'iniciar_intervalo' | 'retornar_intervalo'.
if (!$open) {
    // FORA: só permite 'entrada'.
    if ($expectedAction === 'iniciar_intervalo') {
        api_error(200, 'no_work_open',
            'Não há entrada aberta. Bata sua entrada antes de iniciar o intervalo.',
            ['Registre a entrada de trabalho para depois iniciar o intervalo.'], 'action');
    }
    if ($expectedAction === 'retornar_intervalo') {
        api_error(200, 'no_break_open',
            'Não há intervalo aberto para retornar.',
            ['Inicie um intervalo antes de tentar retornar dele.'], 'action');
    }
    if ($expectedAction === 'saida') {
        api_error(200, 'action_mismatch',
            'Não há entrada aberta para registrar a saída. Atualize a tela e confira o último ponto.',
            ['Ação solicitada: saída.', 'Ação atual no sistema: entrada.', 'Atualize a página antes de tentar novamente.'], 'action');
    }
    $action = 'entrada';
} elseif ($openRecordType === 'work') {
    // TRABALHANDO: pode sair OU iniciar intervalo.
    if ($expectedAction === 'iniciar_intervalo') {
        $action = 'iniciar_intervalo';
    } elseif ($expectedAction === 'retornar_intervalo') {
        api_error(200, 'no_break_open',
            'Não há intervalo aberto para retornar.',
            ['Você está trabalhando. Para fazer pausa, escolha "Iniciar intervalo".'], 'action');
    } elseif ($expectedAction === 'entrada') {
        api_error(200, 'action_mismatch',
            'Já existe uma entrada aberta hoje. Este envio antigo não será convertido em saída.',
            ['Ação solicitada: entrada.', 'Ação atual no sistema: saída.', 'Atualize a página antes de tentar novamente.'], 'action');
    } else {
        // expectedAction null ou 'saida' → default saída
        $action = 'saida';
    }
} else { // $openRecordType === 'break'
    // EM_INTERVALO: só pode retornar do intervalo.
    if ($expectedAction === 'saida') {
        api_error(200, 'break_open_cannot_clock_out',
            'Você está em intervalo. Retorne do intervalo antes de bater saída.',
            ['Clique em "Retornar do intervalo" para voltar ao trabalho, depois bata a saída.'], 'action');
    }
    if ($expectedAction === 'iniciar_intervalo') {
        api_error(200, 'break_already_open',
            'Já existe um intervalo aberto.',
            ['Retorne do intervalo atual antes de iniciar outro.'], 'action');
    }
    if ($expectedAction === 'entrada') {
        api_error(200, 'action_mismatch',
            'Você está em intervalo. Retorne do intervalo antes de tentar outra ação.',
            ['Ação solicitada: entrada.', 'Estado atual: em intervalo.'], 'action');
    }
    // expectedAction null ou 'retornar_intervalo'
    $action = 'retornar_intervalo';
}

// Threshold temporal: bloqueia saída se check_in foi há menos de N segundos.
// Defense-in-depth contra race entre detecção e INSERT/UPDATE — mesmo se o lock
// acima falhar, o usuário não consegue produzir um registro malformado
// "entrada+saída quase simultâneas". Configurável via app_settings.
if ($action === 'saida' && $open) {
    $minCheckoutGap = (int) (function_exists('get_setting') ? get_setting('min_checkout_gap_seconds', '60') : 60);
    if ($minCheckoutGap < 0) $minCheckoutGap = 60;
    $gapSeconds = max(0, time() - strtotime((string)$open['check_in']));
    if ($gapSeconds < $minCheckoutGap) {
        $remaining = $minCheckoutGap - $gapSeconds;
        api_error(200, 'checkout_too_soon',
            'Você acabou de bater entrada às ' . date('H:i:s', strtotime((string)$open['check_in'])) . '.',
            [
                "Aguarde {$remaining} segundo(s) para registrar a saída.",
                "Esta espera previne registros duplicados causados por cliques acidentais.",
            ]
        );
    }
}

// HOTFIX 2026-09: mesma espera mínima para INICIAR intervalo logo após a
// entrada. Evidência em produção: a maioria das "marcações duplicadas" é
// entrada → iniciar intervalo 5-60 s depois → retorno → nova entrada, porque o
// botão de intervalo aparece ao lado do principal logo após a entrada.
// O retorno do intervalo NÃO é bloqueado (é como o usuário desfaz o engano).
if ($action === 'iniciar_intervalo' && $open) {
    $minBreakGap = (int) (function_exists('get_setting') ? get_setting('min_checkout_gap_seconds', '60') : 60);
    if ($minBreakGap < 0) $minBreakGap = 60;
    $gapSecondsBreak = max(0, time() - strtotime((string)$open['check_in']));
    if ($gapSecondsBreak < $minBreakGap) {
        $remainingBreak = $minBreakGap - $gapSecondsBreak;
        api_error(200, 'break_too_soon',
            'Seu último registro foi às ' . date('H:i:s', strtotime((string)$open['check_in'])) . '.',
            [
                "Aguarde {$remainingBreak} segundo(s) para iniciar o intervalo.",
                "Esta espera previne registros duplicados causados por toques acidentais.",
            ]
        );
    }
}

// Validação adicional backend: distância impossível entre check-in e check-out
if ($action === 'saida' && $open) {
    $lastLat = $open['check_in_lat'];
    $lastLng = $open['check_in_lng'];
    $lastTime = strtotime($open['check_in']);
    
    if ($lastLat !== null && $lastLng !== null && $lat !== null && $lng !== null) {
        $distance = haversineDistance((float)$lastLat, (float)$lastLng, $lat, $lng);
        $timeElapsedMin = (time() - $lastTime) / 60;
        
        if ($timeElapsedMin > 0) {
            $speedKmPerMin = $distance / $timeElapsedMin;
            // Se velocidade > 1.5 km/min (90 km/h), suspeito
            if ($speedKmPerMin > 1.5) {
                $fraudRiskLevel = max($fraudRiskLevel, 2);
                $fraudDetections[] = [
                    'type' => 'impossible_distance_backend',
                    'risk_level' => 2,
                    'details' => [
                        'distance_km' => round($distance, 2),
                        'time_minutes' => round($timeElapsedMin, 2),
                        'speed_kmh' => round($speedKmPerMin * 60, 2)
                    ]
                ];
            }
        }
    }
}

// Verifica se professor usa sistema de grade horária (para pagamento fixo)
$usesPeriodSystem = teacher_uses_period_system($teacherId);

// Hora extra descontinuada: nunca marcamos candidato a hora extra. A coluna
// is_overtime_candidate continua no INSERT por compatibilidade de schema (sempre 0).
// Dias sem jornada prevista são permitidos normalmente e consolidados no mês.
$isOvertimeCandidate = 0;
$overtimeJustification = null;
$currentPeriodId = null;

if ($previewMode) {
    // Em preview, traduz $action interno para um par (key, label) consumível pelo frontend.
    // 'in' = entrada normal; 'out' = saída final; 'break_start' = iniciar intervalo;
    // 'break_end' = retornar do intervalo.
    switch ($action) {
        case 'iniciar_intervalo':
            $pendingActionKey = 'break_start';
            $pendingActionLabel = 'Iniciar intervalo';
            break;
        case 'retornar_intervalo':
            $pendingActionKey = 'break_end';
            $pendingActionLabel = 'Retornar do intervalo';
            break;
        case 'saida':
            $pendingActionKey = 'out';
            $pendingActionLabel = 'Saída';
            break;
        default:
            $pendingActionKey = 'in';
            $pendingActionLabel = 'Entrada';
    }
    $openInfo = null;
    if ($open) {
        try {
            $checkInDt = $open['check_in'] ? new DateTime($open['check_in'], $tzBR) : null;
        } catch (Throwable $e) {
            $checkInDt = null;
        }
        $openInfo = [
            'id' => (int)$open['id'],
            'check_in' => $open['check_in'] ?? null,
            'check_in_time' => $checkInDt ? $checkInDt->format('H:i') : null,
            'date' => $open['date'] ?? $today,
            'record_type' => $openRecordType,
        ];
    }
    switch ($action) {
        case 'iniciar_intervalo':
            $previewMessage = 'Será registrado o início do intervalo. O tempo de trabalho ficará pausado.';
            break;
        case 'retornar_intervalo':
            $previewMessage = 'Será registrado o retorno do intervalo iniciado às ' . ($openInfo['check_in_time'] ?? '—') . '.';
            break;
        case 'saida':
            $previewMessage = 'Será registrada a saída do turno iniciado às ' . ($openInfo['check_in_time'] ?? '—') . '.';
            break;
        default:
            $previewMessage = 'Será registrada uma nova entrada.';
    }

    if (ob_get_level()) ob_clean();
    $faceChallenge = $faceAuthMode ? issue_face_challenge($teacherId, $deviceIdentifier) : null;

    $previewResponse = [
        'status' => 'preview',
        'collaborator' => [
            'id' => $teacherId,
            'name' => $prof['name'],
            'cpf' => $prof['cpf'] ?? null
        ],
        'action' => $pendingActionLabel,
        'action_key' => $pendingActionKey,
        'state' => !$open ? 'fora' : ($openRecordType === 'break' ? 'em_intervalo' : 'trabalhando'),
        'open_record' => $openInfo,
        'message' => $previewMessage,
        'face_enrolled' => !empty($prof['face_descriptors']),
        'face_challenge' => $faceChallenge
    ];
    // Emite token de enrollment no preview para permitir cadastro facial antes do check-in
    if (!$faceAuthMode) {
        $previewResponse['inline_enroll_token'] = issue_inline_enrollment_token($teacherId, $deviceIdentifier);
    }
    echo json_encode($previewResponse, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($faceAuthMode) {
    $faceChallenge = isset($input['face_challenge']) ? (string)$input['face_challenge'] : null;
    if (!consume_face_challenge($faceChallenge, $teacherId, $deviceIdentifier)) {
        api_error(400, 'face_challenge_invalid', 'Sessão facial inválida ou expirada. Refaça o reconhecimento facial.', [
            'Faça uma nova validação facial antes de confirmar o ponto.'
        ], 'face');
    }
}

// Recebe a foto de auditoria (em base64) para garantir integridade e possibilidade de revisão posterior.
// Foto obrigatória APENAS na entrada do turno. Iniciar/retornar intervalo e saída dispensam
// — o colaborador já se identificou na entrada (sessão/PIN/face + geofence).
$filename = null;
$photoUrl = null;
$photoQualityReasons = [];
$photoInput = isset($input['photo']) ? trim((string)$input['photo']) : '';
$requiresPhotoCapture = ($action === 'entrada');

if ($requiresPhotoCapture && $photoInput !== '') {
    $photoData = $photoInput;
    if (strpos($photoData, 'data:image') === 0) {
        $parts = explode(',', $photoData);
        if (count($parts) === 2) {
            $decoded = base64_decode($parts[1], true);
            // Hardening 2026-04: limite 5MB pré-write, MIME real via getimagesizefromstring,
            // filename randomico não previsível (substitui uniqid+time).
            $maxBytes = 5 * 1024 * 1024;
            if ($decoded !== false && strlen($decoded) <= $maxBytes) {
                $info = @getimagesizefromstring($decoded);
                $allowedMimes = ['image/jpeg', 'image/png'];
                if ($info !== false && in_array($info['mime'] ?? '', $allowedMimes, true)) {
                    $ext = ($info['mime'] === 'image/png') ? '.png' : '.jpg';
                    $filename = bin2hex(random_bytes(16)) . $ext;
                    $photoDir = __DIR__ . '/../public/photos/';
                    if (!is_dir($photoDir)) {
                        if (!@mkdir($photoDir, 0777, true) && !is_dir($photoDir)) {
                            error_log("[checkin] falha ao criar diretório de fotos: $photoDir");
                            $filename = null;
                        }
                    }
                    if ($filename !== null) {
                        $filepath = $photoDir . $filename;
                        if (file_put_contents($filepath, $decoded) !== false) {
                            $photoUrl = 'photos/' . $filename;
                            // HOTFIX 2026-09: a análise GD (~390 mil leituras de
                            // pixel, síncrona) só alimentava o error_log — nada
                            // consumia o resultado. Mantida atrás da constante.
                            if (defined('PHOTO_QUALITY_CHECK_ENABLED') && PHOTO_QUALITY_CHECK_ENABLED === 'log') {
                                $quality = analyzePhotoQuality($filepath);
                                if (!$quality['ok']) {
                                    $photoQualityReasons = $quality['reasons'];
                                    error_log("Foto de checkin suspeita/baixa qualidade. Motivos: " . implode(', ', $photoQualityReasons));
                                }
                            }
                        } else {
                            error_log("[checkin] file_put_contents falhou em $filepath");
                            $filename = null;
                        }
                    }
                } else {
                    error_log("[checkin] foto rejeitada — MIME inválido ou conteúdo corrompido");
                }
            } elseif ($decoded !== false) {
                error_log("[checkin] foto rejeitada — tamanho excede {$maxBytes} bytes");
            }
        }
    }
}

if ($requiresPhotoCapture && $photoInput === '') {
    api_error(400, 'photo_required', 'Foto obrigatória para registrar entrada.', [
        'Permita o acesso à câmera e tente novamente.'
    ], 'photo');
}

if ($requiresPhotoCapture && $photoInput !== '' && $filename === null) {
    api_error(400, 'photo_invalid', 'Não foi possível validar a foto da entrada.', [
        'Faça uma nova captura com boa iluminação.'
    ], 'photo');
}

// ==============================
// Validação de Geolocalização — fallback inteligente em 3 camadas
//
// Objetivo: minimizar pontos pendentes por falha de GPS sem comprometer
// auditoria. Identidade já validada por CPF+PIN+device trusted; localização
// é um sinal complementar que pode ter fallback.
//
// Camada 1: captura mais robusta (timeout/cache no frontend — feito em pinGetGeo)
// Camada 2: raio efetivo dinâmico = raio + min(accuracy, gps_radius_extra_max_m)
// Camada 3: fallback declarativo quando GPS nao foi informado
//   3.1 network_wide -> atribui escola para auditoria, mas segue pendente sem GPS
//   3.2 1 escola filiada -> auto-escolhe a escola, mas segue pendente sem GPS
//   3.3 2+ escolas -> pede choose_school via response
//   3.4 0 escolas e nao network_wide -> pendente com mensagem clara
// ==============================
$radiusM = (float)((get_setting('geofence_radius_m', '300') ?? '300'));
$gpsMaxAccM = (float)((get_setting('gps_max_accuracy_m', '200') ?? '200'));
$gpsRadiusExtraMaxM = (float)((get_setting('gps_radius_extra_max_m', '200') ?? '200'));
$geoOk = false;
$matchedSchoolId = null;
$distanceM = null;
$geoReasons = [];
$gpsFallbackPath = null; // 'precise', 'tolerated', 'manual_school', 'single_school', 'network_wide'

$schools = get_teacher_allowed_schools($pdo, $teacherId);
$isNetworkWide = (int)($prof['network_wide'] ?? 0) === 1;
$hasCoordinates = ($lat !== null && $lng !== null);
$gpsAccuracyTooLow = ($hasCoordinates && $acc !== null && (float)$acc > $gpsMaxAccM);
$gpsAcceptable = ($hasCoordinates && !$gpsAccuracyTooLow);
$gpsOutsideRadius = false;

// Camada 2: raio efetivo dinâmico (só quando GPS é confiável)
if ($gpsAcceptable && !empty($schools)) {
    $extraRadius = min((float)($acc ?? 0), $gpsRadiusExtraMaxM);
    $effectiveRadius = $radiusM + $extraRadius;
    [$inRadius, $sid, $dist] = match_school_by_geo($schools, $lat, $lng, $effectiveRadius);
    $matchedSchoolId = $sid;
    $distanceM = $dist;
    if ($inRadius) {
        $geoOk = true;
        // Distingue match preciso de match tolerado (admin pode filtrar audit)
        $gpsFallbackPath = ($dist <= $radiusM) ? 'precise' : 'tolerated';
    } else {
        $gpsOutsideRadius = true;
        $geoReasons[] = 'Fora do perímetro permitido (efetivo ' . (int)$effectiveRadius . 'm, distância ' . (int)$dist . 'm)';
    }
} elseif ($gpsAccuracyTooLow) {
    $geoReasons[] = 'Precisão GPS muito baixa (' . (int)$acc . 'm)';
} elseif (empty($schools) && !$isNetworkWide) {
    $geoReasons[] = 'Instituição sem geolocalização configurada';
} else {
    $geoReasons[] = 'Localização não informada';
}

// Camada 3: fallback declarativo apenas quando GPS nao foi informado.
$manualSchoolIdRaw = $input['manual_school_id'] ?? null;
$manualSchoolId = is_numeric($manualSchoolIdRaw) ? (int)$manualSchoolIdRaw : 0;
$allowDeclarativeFallback = !$hasCoordinates;

if (!$geoOk) {
    if ($isNetworkWide) {
        // 3.1: network_wide pode bater em qualquer instituicao, nao em qualquer lugar.
        if ($allowDeclarativeFallback) {
            $gpsFallbackPath = 'network_wide_no_gps';
        }
        // H6: se não houve match de escola por geo (ponto órfão), tenta
        // resolver school_id por heurística — última escola usada pelo
        // colaborador, ou primeira escola filiada. Evita ponto sem
        // school_id que estraga relatórios e payroll por escola.
        if ($matchedSchoolId === null) {
            try {
                $stLast = $pdo->prepare("
                    SELECT school_id FROM attendance
                     WHERE teacher_id = ? AND school_id IS NOT NULL
                     ORDER BY check_in DESC LIMIT 1
                ");
                $stLast->execute([$teacherId]);
                $lastSchool = (int)($stLast->fetchColumn() ?: 0);
                if ($lastSchool > 0) {
                    $matchedSchoolId = $lastSchool;
                    $gpsFallbackPath = 'network_wide_last_school';
                } elseif (!empty($schools)) {
                    $matchedSchoolId = (int)$schools[0]['id'];
                    $gpsFallbackPath = 'network_wide_first_school';
                }
                // Se ainda NULL (sem filiação e sem histórico), o ponto fica
                // sem school_id mesmo — admin precisa atribuir manualmente.
            } catch (Throwable $_) { /* ignora — comportamento legacy mantém NULL */ }
        }
    } else {
        $allowedSchoolIds = array_map(fn($s) => (int)$s['id'], $schools);

        if ($manualSchoolId > 0) {
            // 3.3 (resubmit): usuário escolheu escola — valida filiação
            if (in_array($manualSchoolId, $allowedSchoolIds, true)) {
                $matchedSchoolId = $manualSchoolId;
                $gpsFallbackPath = 'manual_school';
                $geoReasons[] = 'GPS indisponível — escola declarada pelo colaborador';
            } else {
                // C1: HTTP 403 (não 200) — semântica correta para autorização negada.
                // Frontend identifica via data.code='unauthorized_school' (compat mantida).
                if (ob_get_level()) ob_clean();
                http_response_code(403);
                echo json_encode([
                    'status'  => 'error',
                    'code'    => 'unauthorized_school',
                    'message' => 'Essa escola não está vinculada à sua conta.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } elseif ($allowDeclarativeFallback && count($schools) === 1) {
            // 3.2: escola unica so preenche school_id; sem GPS segue pendente.
            $matchedSchoolId = (int)$schools[0]['id'];
            $gpsFallbackPath = 'single_school';
            $geoReasons[] = 'GPS indisponível — escola única do colaborador';
        } elseif ($allowDeclarativeFallback && count($schools) >= 2) {
            // 3.3 (primeira chamada): pede ao usuário escolher escola
            $schoolList = array_map(function ($s) {
                return [
                    'id'      => (int)$s['id'],
                    'name'    => (string)($s['name'] ?? ''),
                    'address' => (string)($s['address'] ?? ''),
                ];
            }, $schools);
            if (ob_get_level()) ob_clean();
            http_response_code(200);
            echo json_encode([
                'status'  => 'choose_school',
                'message' => 'Não conseguimos detectar sua localização. Em qual escola você está agora?',
                'schools' => $schoolList,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 3.4: sem filiação e não network_wide → cai pra pending abaixo
    }
}

// Construir array de motivos de pendência (apenas para casos verdadeiramente pendentes)
$pendingReasons = [];

// ÂNCORA DE TEMPO (NC-14): marcação offline cujo instante não pôde ser
// comprovado entra para revisão do admin. Não é recusada — o registro de
// jornada é direito do trabalhador (art. 74 da CLT); o que não se pode é
// apresentar como comprovado um horário que o cliente simplesmente afirmou.
if (!$tempoAut['comprovado'] && !empty($tempoAut['motivo'])) {
    $pendingReasons[] = $tempoAut['motivo'];
    $fraudRiskLevel = max($fraudRiskLevel, 1);
}

// INTERJORNADA — art. 66 da CLT (NC-22): 11h consecutivas de descanso entre
// jornadas. A marcação NÃO é bloqueada: o trabalhador está ali, e recusar o
// registro apagaria a prova justamente do fato que gera o direito. A
// irregularidade fica visível para a gestão corrigir a escala.
if (function_exists('clt_interjornada') && $action === 'entrada') {
    $inter = clt_interjornada($pdo, $teacherId, $authoritativeTime);
    if (!$inter['ok'] && $inter['motivo']) {
        $pendingReasons[] = $inter['motivo'];
    }
}

// 3.4: ainda pendente quando colaborador não está vinculado a escolas e
// não tem network_wide. Caso anômalo de configuração — admin precisa agir.
if (!$geoOk && !$isNetworkWide && empty($schools)) {
    $pendingReasons[] = 'no_school_linked';
}

// Pendente se geo falhou e nenhum fallback resolveu
if (!$geoOk && !empty($geoReasons)) {
    if ($lat === null || $lng === null) {
        $pendingReasons[] = 'no_gps';
    } elseif ($gpsAccuracyTooLow) {
        $pendingReasons[] = 'gps_accuracy_low';
    } else {
        $pendingReasons[] = 'out_of_radius';
    }
}

// Sem cadastro facial: só marca como pendente quando o método de auth NÃO é PIN
// (PIN é auth completo e o step-up, quando necessário, já validou a face).
if (empty($prof['face_descriptors']) && !$pinAuthMode) {
    $pendingReasons[] = 'Cadastro facial pendente';
}

// Lacuna (b): HLB_MAX_OFFSET_SECONDS deixou de ser dead code. Drift excessivo
// entre relógio do cliente e servidor → marca como pendente para revisão admin
// e bumpa fraud_risk_level. Não bloqueia: ponto continua aceito (servidor já
// usa $now próprio para recorded_at; o offset é só sinal de manipulação).
if (defined('HLB_MAX_OFFSET_SECONDS') && abs($hlbOffsetSeconds) > HLB_MAX_OFFSET_SECONDS) {
    $pendingReasons[] = 'hlb_drift_excessive';
    $fraudRiskLevel = max($fraudRiskLevel, 2);
    $fraudDetections[] = [
        'type' => 'hlb_drift_excessive',
        'risk_level' => 2,
        'details' => [
            'hlb_offset_seconds' => (int)$hlbOffsetSeconds,
            'limit_seconds' => HLB_MAX_OFFSET_SECONDS,
        ],
    ];
}

$pendingReasonsJson = !empty($pendingReasons) ? json_encode($pendingReasons, JSON_UNESCAPED_UNICODE) : null;

// Motivos de pendência logados apenas com APP_DEBUG
if (defined('APP_DEBUG') && APP_DEBUG) {
    error_log("pending_reasons - Teacher: $teacherId, Reasons: " . ($pendingReasonsJson ?? 'NULL'));
}

// Aprovação automática
// Candidatos a hora extra SEMPRE ficam pendentes (null) para aprovação do admin
$hasGeo = ($lat !== null && $lng !== null);

// Aprovação automática:
//   - Face: exige geo ok, não hora extra, cadastro facial (comportamento legado)
//   - PIN: exige geo ok, não hora extra (o PIN já é a autenticação; step-up adicional se necessário)
//   - CPF legacy: mantém o comportamento antigo exigindo face cadastrada
if ($pinAuthMode) {
    $approvedNow = ($hasGeo && $geoOk && !$isOvertimeCandidate) ? 1 : null;
} else {
    $approvedNow = ($hasGeo && $geoOk && !$isOvertimeCandidate && !empty($prof['face_descriptors'])) ? 1 : null;
}

// Correção self-service da VOLTA do intervalo: o colaborador pode informar a hora
// real do retorno (ex.: esqueceu de registrar). Escopo RESTRITO a retornar_intervalo
// e validado (entre o início do intervalo e agora, dentro da janela). Aplica em
// $authoritativeTime, que fecha o intervalo E reabre o trabalho na mesma hora.
// NÃO permite ponto retroativo de entrada/saída — a regra anti-fraude geral do
// timestamp (S1, acima) continua valendo para as demais ações.
if ($action === 'retornar_intervalo' && $open && !empty($open['check_in'])) {
    $crtRaw = isset($input['corrected_return_time']) ? trim((string)$input['corrected_return_time']) : '';
    if ($crtRaw !== '') {
        $crt = str_replace('T', ' ', $crtRaw);
        $breakDate = substr((string)$open['check_in'], 0, 10);
        $crtTimeOnly = false;
        if (preg_match('/^\d{2}:\d{2}$/', $crt))                       { $crt = $breakDate . ' ' . $crt . ':00'; $crtTimeOnly = true; }
        elseif (preg_match('/^\d{2}:\d{2}:\d{2}$/', $crt))             { $crt = $breakDate . ' ' . $crt; $crtTimeOnly = true; }
        elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $crt))  $crt = $crt . ':00';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $crt)) {
            api_error(200, 'invalid_return_time', 'Hora de retorno inválida.', ['Use o formato HH:MM.']);
        }
        $crtTs   = strtotime($crt);
        $startTs = strtotime((string)$open['check_in']);
        // Intervalo que cruza a meia-noite: a hora-só caiu antes do início → rola +1 dia.
        if ($crtTimeOnly && $crtTs !== false && $crtTs <= $startTs) {
            $crtTs += 86400;
            $crt = date('Y-m-d H:i:s', $crtTs);
        }
        $windowH = (int)(get_setting('open_checkin_window_hours', '30') ?? '30');
        if ($crtTs === false || $crtTs <= $startTs) {
            api_error(200, 'return_before_start', 'A hora da volta deve ser depois do início do intervalo (' . date('H:i', $startTs) . ').', []);
        }
        if ($crtTs > time() + 60) {
            api_error(200, 'return_in_future', 'A hora da volta não pode estar no futuro.', []);
        }
        if ($startTs < time() - $windowH * 3600) {
            api_error(200, 'break_too_old', 'Intervalo muito antigo para informar a volta por aqui. Procure o administrador.', []);
        }
        $authoritativeTime = $crt; // fecha o intervalo e reabre o trabalho nesta hora
    }
}

// Entrada / Iniciar Intervalo / Retornar do Intervalo (todas criam NOVA linha em attendance)
if (in_array($action, ['entrada', 'iniciar_intervalo', 'retornar_intervalo'], true)) {
    // Garantias defensivas (a máquina de estados acima já cobre, mas mantemos como salvaguarda):
    if ($action === 'entrada' && $open) {
        api_error(200, 'already_checked_in', 'Você já registrou uma entrada hoje e ainda não registrou a saída.', [
            'Finalize com o registro de saída para iniciar um novo ciclo.'
        ]);
    }
    if (($action === 'iniciar_intervalo' || $action === 'retornar_intervalo') && !$open) {
        api_error(200, $action === 'iniciar_intervalo' ? 'no_work_open' : 'no_break_open',
            'Estado inconsistente: não há registro aberto.', ['Atualize a página e tente novamente.']);
    }

    // Tipo da nova linha + parent. Apenas BREAKS têm parent (id do work que estava aberto
    // quando o intervalo iniciou). Works (entrada/retornar_intervalo) são linhas
    // independentes, sem parent — por design, parent_attendance_id existe para agrupar
    // breaks ao seu work pai em relatórios; works não precisam disso.
    $insertRecordType = ($action === 'iniciar_intervalo') ? 'break' : 'work';
    $parentAttendanceIdForInsert = ($action === 'iniciar_intervalo' && $open) ? (int)$open['id'] : null;

    // NSR gerado de forma atômica via tabela nsr_sequence com SELECT ... FOR UPDATE.
    // Garante NSR único e sequencial mesmo sob POSTs concorrentes (Portaria MTP 671/2021).
    // O retry abaixo agora cobre apenas colisões de client_id (sync duplicado), não NSR.
    $insert_start = microtime(true);

    $maxRetries = 3;
    $attemptCount = 0;
    $success = false;

    while (!$success && $attemptCount < $maxRetries) {
        $attemptCount++;
        $pdo->beginTransaction();

        try {
            // Para iniciar_intervalo e retornar_intervalo, primeiro fechamos o $open
            // (work ou break) atomicamente, depois inserimos a nova linha.
            if ($action === 'iniciar_intervalo' || $action === 'retornar_intervalo') {
                $closeStmt = $pdo->prepare("UPDATE attendance
                    SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND check_out IS NULL");
                $closeStmt->execute([$authoritativeTime, $lat, $lng, $acc, (int)$open['id']]);
                if ($closeStmt->rowCount() === 0) {
                    // Outra requisição fechou nesse intervalo de tempo. Rollback e reporta.
                    $pdo->rollBack();
                    api_error(200, 'state_changed',
                        'O estado do seu ponto mudou. Atualize a tela.',
                        ['Outra ação fechou seu registro aberto antes desta.']);
                }

                // Livro fiscal — SAÍDA do registro que acabou de ser fechado.
                // Esta é uma marcação distinta e, pela Portaria, tem NSR próprio
                // (NC-09). Participa da MESMA transação do UPDATE acima.
                $nsrOutClose = nsr_ledger_record_mark($pdo, [
                    'teacher_id' => $teacherId,
                    'teacher_cpf' => $prof['cpf'] ?? null,
                    'school_id' => $matchedSchoolId,
                    'attendance_id' => (int)$open['id'],
                    'mark_role' => 'out',
                    'direction' => 'S',
                    'record_type' => ($open['record_type'] ?? 'work'),
                    'marked_at' => $authoritativeTime,
                    'work_date' => (string)($open['date'] ?? $today),
                    'origin' => 'device',
                    'record_mode' => $recordMode,
                    'method' => $pinAuthMode ? 'pin' : ($faceAuthMode ? 'face' : 'cpf'),
                    'recorded_at' => $recordedAt,
                    'client_recorded_at' => $clientRecordedAt,
                    'synced_at' => $syncedAt,
                    'lat' => $lat, 'lng' => $lng, 'acc' => $acc,
                    'ip' => $ip,
                    'device_identifier' => $deviceIdentifier,
                    'device_fingerprint' => $deviceFingerprint,
                ]);
                if ($nsrOutClose !== null) {
                    $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
                        ->execute([$nsrOutClose, (int)$open['id']]);
                }
            }

            // NSR atômico: SELECT FOR UPDATE bloqueia a linha; UPDATE incrementa.
            // Tudo em transação → ROLLBACK em erro de INSERT desfaz o increment.
            // Com o livro fiscal ligado ele é a ÚNICA autoridade de numeração:
            // aqui vem um marcador e o espelho grava o NSR real na mesma
            // transação. Sem o livro, mantém-se o emissor legado. Ver
            // nsr_legacy_reserve() — manter os dois ativos consumia dois
            // números por marcação e abria buracos no livro.
            $nsr_start = microtime(true);
            $nextNsr = nsr_legacy_reserve($pdo);
            $debug_times['get_nsr'] = round((microtime(true) - $nsr_start) * 1000, 2);

            // client_id só vai ao INSERT se não-vazio — aproveita o DEFAULT NULL da coluna.
            $clientIdForInsert = ($clientId !== '' && strlen($clientId) >= 8) ? $clientId : null;
            $stmt = $pdo->prepare("INSERT INTO attendance
                (teacher_id, school_id, date, check_in, method, ip, user_agent, check_in_lat, check_in_lng, check_in_acc, photo, approved,
                 record_mode, recorded_at, client_recorded_at, offline_delay_seconds, synced_at, hlb_sync_status, hlb_offset_seconds, device_identifier,
                 fraud_risk_level, liveness_score, liveness_data, gps_mock_detected, device_fingerprint, pending_reasons,
                 is_overtime_candidate, overtime_justification, nsr, client_id, record_type, parent_attendance_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $livenessDataJson = ($livenessScore !== null && is_array($livenessData))
                ? json_encode($livenessData, JSON_UNESCAPED_UNICODE) : null;
            $methodForInsert = $pinAuthMode ? 'pin' : ($faceAuthMode ? 'face' : 'cpf');
            // Lacuna (a): $authoritativeTime substitui $now em check_in para
            // permitir fidelidade temporal em offline-sync com cliente HLB-sincronizado.
            $stmt->execute([
                $teacherId, $matchedSchoolId, $today, $authoritativeTime, $methodForInsert, $ip, $ua, $lat, $lng, $acc, $filename, $approvedNow,
                $recordMode, $recordedAt, $clientRecordedAt, $offlineDelaySeconds, $syncedAt, $hlbSyncStatus, $hlbOffsetSeconds, $deviceIdentifier,
                $fraudRiskLevel, $livenessScore, $livenessDataJson, $gpsMockDetected, $deviceFingerprint, $pendingReasonsJson,
                $isOvertimeCandidate, $overtimeJustification, $nextNsr, $clientIdForInsert, $insertRecordType, $parentAttendanceIdForInsert
            ]);
            $debug_times['insert'] = round((microtime(true) - $insert_start) * 1000, 2);

            $attendanceId = $pdo->lastInsertId();

            // Livro fiscal — ENTRADA (ou início/retorno de intervalo).
            // Dentro da mesma transação do INSERT: ou os dois gravam, ou nenhum.
            $nsrIn = nsr_ledger_record_mark($pdo, [
                'teacher_id' => $teacherId,
                'teacher_cpf' => $prof['cpf'] ?? null,
                'school_id' => $matchedSchoolId,
                'attendance_id' => (int)$attendanceId,
                'mark_role' => 'in',
                'direction' => 'E',
                'record_type' => $insertRecordType,
                'marked_at' => $authoritativeTime,
                'work_date' => $today,
                'origin' => 'device',
                'record_mode' => $recordMode,
                'method' => $methodForInsert,
                'recorded_at' => $recordedAt,
                'client_recorded_at' => $clientRecordedAt,
                'synced_at' => $syncedAt,
                'lat' => $lat, 'lng' => $lng, 'acc' => $acc,
                'ip' => $ip,
                'device_identifier' => $deviceIdentifier,
                'device_fingerprint' => $deviceFingerprint,
                'photo' => $filename,
            ]);
            if ($nsrIn !== null) {
                // attendance.nsr passa a espelhar o NSR do livro. O NSR legado
                // (emitido por nsr_sequence antes do ledger) é preservado.
                $pdo->prepare("UPDATE attendance SET legacy_nsr = COALESCE(legacy_nsr, nsr), nsr = ? WHERE id = ?")
                    ->execute([$nsrIn, (int)$attendanceId]);
            }

            $pdo->commit();
            $debug_times['commit'] = round((microtime(true) - $insert_start) * 1000, 2);
            $success = true;
            
        } catch (PDOException $e) {
            $pdo->rollBack();

            // Duplicate key: pode ser NSR OU client_id (race entre sync duplicado).
            if ($e->getCode() == 23000) {
                // Se a colisão é no client_id, o ponto já foi registrado por outro request.
                // Busca e retorna o existente como idempotente — não tenta de novo.
                if ($clientId !== '' && strlen($clientId) >= 8) {
                    try {
                        $stExist = $pdo->prepare("SELECT id, nsr, approved FROM attendance WHERE client_id = ? LIMIT 1");
                        $stExist->execute([$clientId]);
                        $existing = $stExist->fetch(PDO::FETCH_ASSOC);
                        if ($existing) {
                            if (ob_get_level()) ob_clean();
                            echo json_encode([
                                'status'        => 'ok',
                                'code'          => 'already_registered',
                                'nsr'           => (int)($existing['nsr'] ?? 0),
                                'attendance_id' => (int)$existing['id'],
                                'approved'      => (int)($existing['approved'] ?? 0) === 1,
                                'message'       => 'Este ponto já estava registrado.',
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                    } catch (Throwable $_) { /* ignore — cai no retry abaixo */ }
                }
                // Colisão de NSR: retry normal
                if ($attemptCount < $maxRetries) {
                    usleep(10000);
                    continue;
                }
            }

            // Outros erros: propaga exceção
            throw $e;
        }
    }
    
    if (!$success) {
        throw new Exception("Falha ao gerar NSR após $maxRetries tentativas");
    }

    $payload = [
        'teacher_id'=>$teacherId,
        'type'=>'checkin',
        'geo_ok'=>$geoOk,
        'matched_school_id'=>$matchedSchoolId,
        'distance_m'=>$distanceM,
        'geo_reasons'=>$geoReasons,
        'gps_fallback_path'=>$gpsFallbackPath,
        'acc'=>$acc
    ];
    
    // $attendanceId e $nextNsr já foram definidos no bloco try acima
    audit_log('create','attendance',$attendanceId,$payload);
    
    // Registra detecções de fraude no log
    if (!empty($fraudDetections)) {
        $stmtFraud = $pdo->prepare("INSERT INTO fraud_detection_log 
            (attendance_id, teacher_id, detection_type, risk_level, details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($fraudDetections as $detection) {
            $detailsJson = isset($detection['details']) ? json_encode($detection['details']) : null;
            $stmtFraud->execute([
                $attendanceId,
                $teacherId,
                $detection['type'],
                $detection['risk_level'],
                $detailsJson,
                $ip,
                $ua
            ]);
        }
    }

    // NSR já foi gerado e inserido
    $nsr = $nextNsr;

    $actionLabelMap = [
        'entrada' => 'Entrada',
        'iniciar_intervalo' => 'Início do intervalo',
        'retornar_intervalo' => 'Retorno do intervalo',
    ];
    $msg = ($actionLabelMap[$action] ?? 'Ponto') . ' registrado';
    if ($hasGeo) $msg .= ' com localização';
    if ($approvedNow === 1) {
        $msg .= '!';
    } else {
        $msg .= ' (aguardando aprovação).';
    }

    $debug_times['total'] = round((microtime(true) - $debug_start) * 1000, 2);

    // Limpa buffer antes de enviar JSON
    if (ob_get_level()) ob_clean();
    
    $authMethodLabel = $pinAuthMode ? 'pin' : ($faceAuthMode ? 'face' : 'cpf');
    $response = [
        'status'=>'ok',
        'collaborator'=>['id'=>$teacherId,'name'=>$prof['name'],'cpf'=>$prof['cpf'] ?? null],
        'teacher'=>['id'=>$teacherId,'name'=>$prof['name'],'cpf'=>$prof['cpf'] ?? null],
        'action'=>$action, // 'entrada' | 'iniciar_intervalo' | 'retornar_intervalo'
        'record_type'=>$insertRecordType,
        'parent_attendance_id'=>$parentAttendanceIdForInsert,
        'time'=>$now,
        'photo'=>$photoUrl,
        'message'=>$msg,
        'nsr'=>$nsr, // Número Sequencial de Registro (Portaria 671/2021)
        'attendance_id'=>$attendanceId,
        'record_mode'=>$recordMode,
        'recorded_at'=>$recordedAt,
        'approved'=>($approvedNow === 1),
        'pending_reasons'=>$pendingReasons,
        'pending_reason_primary'=>!empty($pendingReasons) ? $pendingReasons[0] : null,
        'has_face_descriptors' => !empty($prof['face_descriptors']),
        'auth_method' => $authMethodLabel,
        'stepup_performed' => $stepupPerformed,
    ];

    if ($pinAuthMode) {
        // Após check-in bem-sucedido: atualiza last_used_at se device já é trusted,
        // ou considera promoção por repetição.
        $fpTrustPin = trusted_device_fingerprint($ua, $ip, $deviceFingerprintInput);
        if ($fpTrustPin !== '') {
            if (trusted_device_is_known($pdo, $teacherId, $fpTrustPin)) {
                trusted_device_touch($pdo, $teacherId, $fpTrustPin);
            } else {
                // Passa raw + hashed: query em attendance precisa do raw, enrollment do hashed.
                trusted_device_consider_repeat_enroll($pdo, $teacherId, $fpTrustPin, $deviceFingerprintInput);
            }
        }
        audit_log('pin.checkin_success', 'attendance', $attendanceId, [
            'teacher_id' => $teacherId,
            'nsr' => $nsr,
            'stepup' => $stepupPerformed,
            'stepup_reasons' => $stepupReasons,
        ]);
    }

    if (!$faceAuthMode && !$pinAuthMode) {
        $response['inline_enroll_token'] = issue_inline_enrollment_token($teacherId, $deviceIdentifier);
    }

    // Verificar se descritores faciais estão desatualizados
    if ($faceAuthMode && !empty($prof['face_enrolled_at'])) {
        $reenrollDays = (int)(get_setting('face_reenroll_days', '180') ?? '180');
        try {
            $enrolledAt = new DateTime($prof['face_enrolled_at']);
            $daysSince = (int)$enrolledAt->diff(new DateTime())->days;
            if ($daysSince > $reenrollDays) {
                $response['reenroll_recommended'] = true;
                $response['reenroll_days_since'] = $daysSince;
            }
        } catch (Throwable $e) { /* ignore date parse errors */ }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
// Saída
} else {
    if (!$open) {
        // HTTP 200 para não poluir o console do browser — frontend trata via data.code
        api_error(200, 'no_open_checkin', 'Não há entrada aberta hoje. Registre a entrada antes da saída.', [
            'Faça o registro de entrada para então registrar a saída.'
        ]);
    }

    // BUG-005 (rev): dedup específico para saída via checkout_client_id.
    // O dedup do topo do arquivo só cobre client_id (entrada). Sem este
    // segundo check, retry pós-falha-de-rede de uma saída cairia no ramo
    // entrada (ponto fechado na 1ª tentativa) ou geraria erro genérico.
    // A migration add_attendance_checkout_client_id.sql cria coluna + UNIQUE.
    if ($clientId !== '' && strlen($clientId) >= 8) {
        try {
            $stDupOut = $pdo->prepare("SELECT id, nsr FROM attendance WHERE checkout_client_id = ? AND teacher_id = ? LIMIT 1");
            $stDupOut->execute([$clientId, $teacherId]);
            if ($dupOut = $stDupOut->fetch(PDO::FETCH_ASSOC)) {
                if (ob_get_level()) ob_clean();
                echo json_encode([
                    'status'        => 'ok',
                    'code'          => 'already_registered',
                    'action'        => 'saída',
                    'nsr'           => (int)($dupOut['nsr'] ?? 0),
                    'attendance_id' => (int)$dupOut['id'],
                    'message'       => 'Saída já registrada anteriormente.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (Throwable $e) {
            // Coluna pode não existir (auto-migration ainda não rodou) — não bloqueia.
            error_log('checkin checkout_client_id dedup skipped: ' . $e->getMessage());
        }
    }

    $parseTime = static function (?string $str): ?DateTime {
        if (!$str) return null;
        $str = trim($str);
        $fmt = strlen($str) === 5 ? 'H:i' : 'H:i:s';
        return DateTime::createFromFormat($fmt, $str) ?: null;
    };

    $pdo->beginTransaction();
    try {
      // BUG-005 (rev): grava checkout_client_id no UPDATE para que retries
      // posteriores sejam dedupados pelo pre-check acima. NULL preservado
      // para fluxos sem client_id (compat com queues legadas).
      // Lacuna (a): $authoritativeTime em check_out (híbrido seguro — cliente
      // HLB-sincronizado preserva fidelidade temporal em offline-sync).
      $sql = "UPDATE attendance
              SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP,
                  approved = CASE WHEN approved = 0 THEN 0 WHEN ? = 1 AND approved = 1 THEN 1 ELSE NULL END,
                  fraud_risk_level = ?, gps_mock_detected = ?, device_fingerprint = ?,
                  pending_reasons = ?, checkout_client_id = ?
              WHERE id = ? AND check_out IS NULL AND removed_at IS NULL AND superseded_by_id IS NULL";
      // HOTFIX 2026-09: guarda de estado no UPDATE. Sem ela, uma saída manual do
      // admin (ou uma anulação) feita entre a leitura e a gravação era
      // sobrescrita em silêncio; e uma rejeição (approved=0) virava "pendente".
      $params = [$authoritativeTime, $lat, $lng, $acc];
      $params[] = $approvedNow;
      $params[] = $fraudRiskLevel;
      $params[] = $gpsMockDetected;
      $params[] = $deviceFingerprint;
      $params[] = $pendingReasonsJson;
      $params[] = ($clientId !== '' && strlen($clientId) >= 8) ? $clientId : null;
      $params[] = $open['id'];
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      if ($stmt->rowCount() === 0) {
          $pdo->rollBack();
          api_error(200, 'state_changed', 'Seu último ponto foi alterado enquanto você registrava.', [
              'Atualize a tela para ver o estado atual antes de tentar novamente.'
          ], 'action');
      }

      // Livro fiscal — SAÍDA. Antes desta fase, o checkout reaproveitava o NSR
      // da entrada (NC-09): um par entrada+saída consumia um único número,
      // enquanto a Portaria exige NSR por MARCAÇÃO. Agora a saída tem o seu.
      $nsrOut = nsr_ledger_record_mark($pdo, [
          'teacher_id' => $teacherId,
          'teacher_cpf' => $prof['cpf'] ?? null,
          'school_id' => $open['school_id'] ?? $matchedSchoolId,
          'attendance_id' => (int)$open['id'],
          'mark_role' => 'out',
          'direction' => 'S',
          'record_type' => ($open['record_type'] ?? 'work'),
          'marked_at' => $authoritativeTime,
          'work_date' => (string)($open['date'] ?? $today),
          'origin' => 'device',
          'record_mode' => $recordMode,
          'method' => $pinAuthMode ? 'pin' : ($faceAuthMode ? 'face' : 'cpf'),
          'recorded_at' => $recordedAt,
          'client_recorded_at' => $clientRecordedAt,
          'synced_at' => $syncedAt,
          'lat' => $lat, 'lng' => $lng, 'acc' => $acc,
          'ip' => $ip,
          'device_identifier' => $deviceIdentifier,
          'device_fingerprint' => $deviceFingerprint,
      ]);
      if ($nsrOut !== null) {
          $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
              ->execute([$nsrOut, (int)$open['id']]);
      }

      // Registra detecções de fraude no log
      if (!empty($fraudDetections)) {
          $stmtFraud = $pdo->prepare("INSERT INTO fraud_detection_log 
              (attendance_id, teacher_id, detection_type, risk_level, details, ip_address, user_agent) 
              VALUES (?, ?, ?, ?, ?, ?, ?)");
          
          foreach ($fraudDetections as $detection) {
              $detailsJson = isset($detection['details']) ? json_encode($detection['details']) : null;
              $stmtFraud->execute([
                  $open['id'],
                  $teacherId,
                  $detection['type'],
                  $detection['risk_level'],
                  $detailsJson,
                  $ip,
                  $ua
              ]);
          }
      }

      // Banco de horas automático (recalcula o delta do dia).
      // Usa a data da entrada original ($open['date']), não $today — turno noturno
      // (entrada ontem 22h, saída hoje 02h) deve creditar/debitar o saldo no dia
      // de ontem, não no de hoje.
      $tolerance = (int)(get_setting('tolerance_minutes', '5') ?? '5');
      $openDate = $open['date'];
      // Esperado canônico (helpers.php): cobre modos classes/time/hours, sábado
      // letivo e afastamento pago. Janela completa — intervalo não é descontado.
      $expMin = calculate_expected_minutes($pdo, $teacherId, $openDate);

      // Trabalhado EFETIVO: presença cheia (pares work + break) — o intervalo
      // conta como tempo trabalhado, nunca subtrai (helpers.php).
      $worked = calculate_effective_worked_minutes($pdo, $teacherId, $openDate);
      $delta = $worked - $expMin;
      if (abs($delta) <= $tolerance) $delta = 0;

      // Ressincroniza o ledger do dia (limpa entrada 'auto' antiga). Não grava mais
      // débito automático — a falta de horas é consolidada no mês como informativo.
      recompute_day_hour_bank($pdo, $teacherId, $openDate);

      $pdo->commit();
      audit_log('update','attendance',$open['id'],[
        'teacher_id'=>$teacherId,'type'=>'checkout',
        'geo_ok'=>$geoOk,'distance_m'=>$distanceM,'geo_reasons'=>$geoReasons,'acc'=>$acc,
        'delta'=>$delta
      ]);

      $msg = 'Saída registrada';
      if ($hasGeo) $msg .= ' com localização';
      if ($approvedNow === 1) {
          $msg .= '!';
      } else {
          $msg .= ' (aguardando aprovação).';
      }
      
      // Busca o NSR do registro
      $stmtNsr = $pdo->prepare("SELECT nsr FROM attendance WHERE id = ?");
      $stmtNsr->execute([$open['id']]);
      $nsr = $stmtNsr->fetchColumn();

      if ($pinAuthMode) {
          $fpTrustPinOut = trusted_device_fingerprint($ua, $ip, $deviceFingerprintInput);
          if ($fpTrustPinOut !== '') {
              if (trusted_device_is_known($pdo, $teacherId, $fpTrustPinOut)) {
                  trusted_device_touch($pdo, $teacherId, $fpTrustPinOut);
              } else {
                  // Passa raw + hashed: query em attendance precisa do raw, enrollment do hashed.
                  trusted_device_consider_repeat_enroll($pdo, $teacherId, $fpTrustPinOut, $deviceFingerprintInput);
              }
          }
          audit_log('pin.checkin_success', 'attendance', $open['id'], [
              'teacher_id' => $teacherId,
              'nsr' => $nsr,
              'type' => 'checkout',
              'stepup' => $stepupPerformed,
              'stepup_reasons' => $stepupReasons,
          ]);
      }

      // Limpa buffer antes de enviar JSON
      if (ob_get_level()) ob_clean();

      $authMethodLabelOut = $pinAuthMode ? 'pin' : ($faceAuthMode ? 'face' : 'cpf');
      echo json_encode([
          'status'=>'ok',
          'collaborator'=>['id'=>$teacherId,'name'=>$prof['name']],
          'teacher'=>['id'=>$teacherId,'name'=>$prof['name'],'cpf'=>$prof['cpf'] ?? null],
          'action'=>'saída',
          'time'=>$now,
          'photo'=>$photoUrl,
          'message'=>$msg,
          'nsr'=>$nsr, // Número Sequencial de Registro (Portaria 671/2021)
          'attendance_id'=>$open['id'],
          'record_mode'=>$recordMode,
          'recorded_at'=>$recordedAt,
          'approved'=>($approvedNow === 1),
          'pending_reasons'=>$pendingReasons,
          'pending_reason_primary'=>!empty($pendingReasons) ? $pendingReasons[0] : null,
          'has_face_descriptors' => !empty($prof['face_descriptors']),
          'auth_method' => $authMethodLabelOut,
          'stepup_performed' => $stepupPerformed,
      ], JSON_UNESCAPED_UNICODE);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // BUG-005 (rev): race entre dois SWs/abas reenviando a mesma saída →
      // colisão no UNIQUE de checkout_client_id. Resolve como already_registered
      // em vez de erro genérico (mesmo padrão da entrada e do checkin_bulk).
      if ($clientId !== '' && strlen($clientId) >= 8 && $e instanceof PDOException
          && (int)$e->errorInfo[1] === 1062
          && stripos((string)$e->getMessage(), 'checkout_client_id') !== false) {
        try {
          $stExist = $pdo->prepare("SELECT id, nsr FROM attendance WHERE checkout_client_id = ? AND teacher_id = ? LIMIT 1");
          $stExist->execute([$clientId, $teacherId]);
          if ($row = $stExist->fetch(PDO::FETCH_ASSOC)) {
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'status'        => 'ok',
                'code'          => 'already_registered',
                'action'        => 'saída',
                'nsr'           => (int)($row['nsr'] ?? 0),
                'attendance_id' => (int)$row['id'],
                'message'       => 'Saída já registrada por sync paralelo.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
          }
        } catch (Throwable $_) {}
      }
      api_error(500, 'server_error', 'Falha ao fechar ponto.', [
          'Tente novamente em instantes. Se persistir, contate o Admin.'
      ]);
    }
}

<?php
declare(strict_types=1);
// Verificação facial 1:1 do Quiosque (motor LOCAL face-api.js — sem API externa).
// O colaborador informa o CPF (identifica) e envia o descriptor extraído no
// navegador; aqui confirmamos 1:1 contra teachers.face_descriptors. NÃO registra
// ponto — devolve um token assinado (uso único) consumido por kiosk_checkin.php.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/kiosk_lib.php';

if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

kiosk_require_post();
csrf_verify();

$pdo = db();
$dev = kiosk_guard($pdo);
$deviceId = (int)$dev['id'];

// Fallback CPF+PIN é permitido se habilitado globalmente E no dispositivo.
$fallbackAllowed = ((string)(get_setting('kiosk_fallback_enabled', '0') ?? '0') === '1')
    && ((int)($dev['fallback_allowed'] ?? 0) === 1);

if (!kiosk_rate_limit_ok($pdo, $deviceId)) {
    kiosk_log($pdo, ['device_id' => $deviceId, 'event_type' => 'identify', 'status' => 'rate_limited']);
    kiosk_respond(['status' => 'error', 'code' => 'rate_limited',
        'message' => 'Muitas tentativas neste dispositivo. Aguarde um momento.'], 429);
}

$input = kiosk_json_input();
$cpf = preg_replace('/\D/', '', (string)($input['cpf'] ?? ''));
$descriptor = $input['face_descriptor'] ?? null;
$photoInput = isset($input['photo']) ? (string)$input['photo'] : '';

if (strlen((string)$cpf) < 11) {
    kiosk_respond(['status' => 'refused', 'code' => 'cpf_required',
        'message' => 'Informe seu CPF (11 dígitos).']);
}
if (!kiosk_is_valid_descriptor($descriptor)) {
    kiosk_log($pdo, ['device_id' => $deviceId, 'event_type' => 'identify', 'status' => 'invalid_descriptor']);
    kiosk_respond(['status' => 'refused', 'code' => 'invalid_descriptor',
        'message' => 'Não foi possível ler seu rosto. Tente novamente.']);
}

// Foto de auditoria (opcional — o frame capturado).
$photoRef = null;
if ($photoInput !== '') {
    $img = kiosk_decode_image($photoInput);
    if ($img['ok']) $photoRef = kiosk_save_photo($img['bytes'], $img['ext']);
}

// Identifica o colaborador pelo CPF (mesmo critério do checkin.php).
$st = $pdo->prepare("SELECT id, name, face_descriptors FROM teachers WHERE cpf = ? AND active = 1 LIMIT 1");
$st->execute([$cpf]);
$teacher = $st->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$teacher) {
    kiosk_log($pdo, ['device_id' => $deviceId, 'event_type' => 'identify', 'status' => 'cpf_not_found',
        'photo' => $photoRef, 'detail' => ['cpf_masked' => substr($cpf, 0, 3) . '****']]);
    // Resposta genérica IDÊNTICA à de "rosto não confere", para não revelar se o
    // CPF existe (anti-enumeração). O motivo real (cpf_not_found) fica só no log.
    kiosk_respond(['status' => 'refused', 'code' => 'not_matched',
        'message' => 'Não foi possível confirmar. Verifique o CPF e olhe para a câmera, com boa iluminação.',
        'fallback_allowed' => $fallbackAllowed]);
}
$teacherId = (int)$teacher['id'];

// Anti brute-force: bloqueia o CPF após muitas falhas seguidas.
$lock = kiosk_cpf_locked_out($pdo, $teacherId);
if ($lock['locked']) {
    kiosk_log($pdo, ['device_id' => $deviceId, 'teacher_id' => $teacherId, 'event_type' => 'identify',
        'status' => 'locked_out', 'photo' => $photoRef, 'detail' => ['fails' => $lock['fails']]]);
    kiosk_respond(['status' => 'refused', 'code' => 'locked_out',
        'message' => 'Muitas tentativas sem sucesso. Aguarde ' . (int)$lock['window_min'] . ' min e tente novamente, ou procure o administrador.',
        'fallback_allowed' => $fallbackAllowed]);
}

// Carrega descritores cadastrados (motor local).
$stored = face_descriptors_decode($teacher['face_descriptors'] ?? null);
$valid = [];
if (is_array($stored)) {
    foreach ($stored as $ref) {
        if (is_array($ref) && count($ref) === 128) $valid[] = $ref;
    }
}
$minNeeded = get_min_face_descriptors_for_auth();
if (count($valid) < $minNeeded) {
    kiosk_log($pdo, ['device_id' => $deviceId, 'teacher_id' => $teacherId, 'event_type' => 'identify',
        'status' => 'no_enrollment', 'photo' => $photoRef, 'detail' => ['have' => count($valid), 'need' => $minNeeded]]);
    kiosk_respond(['status' => 'refused', 'code' => 'no_enrollment',
        'message' => 'Seu cadastro facial está pendente. Procure o administrador.',
        'fallback_allowed' => $fallbackAllowed]);
}

// Verificação 1:1.
$res = kiosk_verify_face_1to1($descriptor, $valid);
if (!$res['ok']) {
    kiosk_log($pdo, ['device_id' => $deviceId, 'teacher_id' => $teacherId, 'event_type' => 'identify',
        'status' => 'not_matched', 'confidence' => $res['confidence'], 'photo' => $photoRef,
        'detail' => ['best' => $res['best_distance'], 'hits' => $res['hits'], 'total' => $res['total']]]);
    kiosk_respond(['status' => 'refused', 'code' => 'not_matched',
        'message' => 'Não foi possível confirmar. Verifique o CPF e olhe para a câmera, com boa iluminação.',
        'fallback_allowed' => $fallbackAllowed]);
}

$confidence = (float)$res['confidence'];
$logId = kiosk_log($pdo, [
    'device_id' => $deviceId, 'teacher_id' => $teacherId, 'event_type' => 'identify',
    'status' => 'recognized', 'confidence' => $confidence, 'photo' => $photoRef,
    'detail' => ['mode' => '1to1', 'best' => $res['best_distance'], 'hits' => $res['hits'], 'total' => $res['total']],
]);
if ($logId <= 0) {
    kiosk_respond(['status' => 'error', 'code' => 'log_failed', 'message' => 'Falha temporária. Tente novamente.'], 500);
}

$token = kiosk_sign_recognition([
    'log_id'     => $logId,
    'teacher_id' => $teacherId,
    'confidence' => $confidence,
    'device_id'  => $deviceId,
    'exp'        => time() + 90,
    'jti'        => bin2hex(random_bytes(8)),
]);

kiosk_respond([
    'status'            => 'recognized',
    'teacher'           => ['id' => $teacherId, 'name' => kiosk_display_name((string)$teacher['name'])],
    'confidence'        => $confidence,
    'next'              => kiosk_punch_state($pdo, $teacherId),
    'photo_ref'         => $photoRef,
    'recognition_token' => $token,
]);

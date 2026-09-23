<?php
declare(strict_types=1);
// Breadcrumb de auditoria do fallback (CPF+PIN) acionado no quiosque quando a
// face não confirma. O registro de ponto em si vai pelo api/checkin.php
// existente; aqui apenas marcamos a trilha (event=fallback) para auditoria.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/kiosk_lib.php';

if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

kiosk_require_post();
csrf_verify();

$pdo = db();
$dev = kiosk_device_from_request($pdo); // não exige kiosk_enabled (fallback pode ocorrer em transição)
if (!$dev) {
    kiosk_respond(['status' => 'error', 'code' => 'kiosk_unpaired', 'message' => 'Dispositivo não autorizado.'], 401);
}
$deviceId = (int)$dev['id'];

// Só permite o breadcrumb se o fallback estiver autorizado (global + por device).
$fallbackAllowed = ((string)(get_setting('kiosk_fallback_enabled', '0') ?? '0') === '1')
    && ((int)($dev['fallback_allowed'] ?? 0) === 1);
if (!$fallbackAllowed) {
    kiosk_respond(['status' => 'error', 'code' => 'fallback_not_allowed',
        'message' => 'Fallback não autorizado neste dispositivo.'], 403);
}

$input = kiosk_json_input();
$stage = (string)($input['stage'] ?? 'start'); // 'start' | 'done'
$result = isset($input['result']) ? substr((string)$input['result'], 0, 30) : null;

kiosk_log($pdo, [
    'device_id' => $deviceId,
    'event_type' => 'fallback',
    'status' => $stage === 'done' ? ('done:' . ($result ?? 'unknown')) : 'start',
    'detail' => ['result' => $result],
]);

kiosk_respond(['status' => 'ok']);

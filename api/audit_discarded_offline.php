<?php
// S4: Endpoint para auditoria de pontos descartados durante o drain offline.
//
// Quando um item da fila falha de forma permanente (PIN inválido, face
// conflict, etc.) ou ultrapassa MAX_RETRY ou expira por idade (>7d), o
// frontend deleta do IndexedDB e reporta aqui para que o admin tenha
// VISIBILIDADE de pontos perdidos.
//
// Sem este endpoint, descartes ficam só em console.warn — admin nunca sabe.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Auth: exige sessão de colaborador (anti-spam de admin audit_logs por anônimos).
if (!function_exists('is_collaborator_logged') || !is_collaborator_logged()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'unauthorized']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) $input = [];

// Sanitização aggressive — entrada é client-controlled
$cpfMasked   = substr(preg_replace('/[^0-9*\.\-]/', '', (string)($input['cpf_masked'] ?? '')), 0, 20);
$reason      = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($input['reason'] ?? 'unknown')), 0, 80);
$attemptedAt = (int)($input['attempted_at'] ?? 0);
$attempts    = max(0, min(99, (int)($input['attempts'] ?? 0)));
$action      = substr(preg_replace('/[^a-z]/', '', (string)($input['action'] ?? '')), 0, 16);
$clientId    = substr(preg_replace('/[^0-9a-zA-Z\-]/', '', (string)($input['client_id'] ?? '')), 0, 64);

if ($reason === '') {
    echo json_encode(['status' => 'ok']);
    exit;
}

try {
    $pdo = db();
    $collabId = (int)($_SESSION['collaborator_id'] ?? 0);
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '';

    // Rate limit: 1 report por IP a cada 30s. Drain em batch grande pode
    // gerar múltiplos descartes — não queremos limitar isso, então o
    // throttle é por (IP+reason+action) para diferenciar tipos de erro.
    $stRl = $pdo->prepare("SELECT COUNT(*) FROM audit_logs
                           WHERE action = 'discarded_offline'
                             AND ip = ?
                             AND payload LIKE ?
                             AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)");
    $stRl->execute([$ip, '%"reason":"' . $reason . '"%']);
    if ((int)$stRl->fetchColumn() > 5) {
        // Excesso de reports do mesmo erro — possível bug, mas não bloqueia.
        // Apenas omite este; admin já tem amostra suficiente.
        echo json_encode(['status' => 'ok', 'throttled' => true]);
        exit;
    }

    $payload = json_encode([
        'collaborator_id' => $collabId,
        'cpf_masked'      => $cpfMasked,
        'reason'          => $reason,
        'attempted_at'    => $attemptedAt > 0 ? date('Y-m-d H:i:s', (int)($attemptedAt / 1000)) : null,
        'attempts'        => $attempts,
        'action'          => $action,
        'client_id'       => $clientId,
    ], JSON_UNESCAPED_UNICODE);

    $st = $pdo->prepare("INSERT INTO audit_logs (admin_id, action, entity, entity_id, payload, ip, created_at)
                         VALUES (NULL, 'discarded_offline', 'attendance', ?, ?, ?, NOW())");
    $st->execute([$clientId !== '' ? $clientId : null, $payload, $ip]);

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    // BUG-007: retornar 200 em falha mascarava o erro do frontend, que tratava
    // a chamada como sucesso e nunca reenviava. Agora 500 + status:error
    // dispara o fallback de localStorage no cliente para retry posterior.
    error_log('[audit_discarded_offline] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'code' => 'log_failed']);
}

<?php
// Endpoint leve: registra em audit_logs quando um dispositivo está com muitos
// pontos pendentes acumulados há dias. Usado pelo admin para identificar
// colaboradores que precisam de suporte (rede ruim, app quebrado, etc).
//
// Requer sessão de colaborador logado (anti-spam de audit_logs por anônimos).

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

// Auth: só colaborador logado pode gravar. Recusa silenciosa sem vazar se
// o CPF existia ou não — a ideia é bloquear spam, não afetar o fluxo normal.
if (!function_exists('is_collaborator_logged') || !is_collaborator_logged()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) $input = [];

$pendingCount = (int)($input['pending_count'] ?? 0);
$oldestDays   = (int)($input['oldest_days'] ?? 0);
$tabId        = substr(preg_replace('/[^0-9a-zA-Z\-]/', '', (string)($input['tab_id'] ?? '')), 0, 64);

// Validações simples — tolera payloads malformados silenciosamente.
if ($pendingCount < 1 || $pendingCount > 10000) {
    echo json_encode(['status' => 'ok']);
    exit;
}

try {
    $pdo = db();

    $collabId = (int)($_SESSION['collaborator_id'] ?? 0);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    // Rate limit de defesa em profundidade: 1 registro por IP a cada 60s.
    // O tab_id é controlado pelo cliente — IP é um floor independente.
    $stIp = $pdo->prepare("SELECT COUNT(*) FROM audit_logs
                           WHERE action = 'stale_offline_queue'
                             AND ip = ?
                             AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
    $stIp->execute([$ip]);
    if ((int)$stIp->fetchColumn() > 0) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'code' => 'rate_limited']);
        exit;
    }

    // Throttle de usabilidade: 1 registro por device (tab_id) a cada 24h.
    if ($tabId !== '') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM audit_logs
                             WHERE action = 'stale_offline_queue'
                               AND entity_id = ?
                               AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $st->execute([$tabId]);
        if ((int)$st->fetchColumn() > 0) {
            echo json_encode(['status' => 'ok', 'throttled' => true]);
            exit;
        }
    }

    $payload = json_encode([
        'pending_count'   => $pendingCount,
        'oldest_days'     => $oldestDays,
        'tab_id'          => $tabId,
        'collaborator_id' => $collabId,
        'ua'              => $ua,
    ], JSON_UNESCAPED_UNICODE);

    // admin_id NULL (ação disparada pelo colaborador), entity = device, entity_id = tab_id
    $st = $pdo->prepare("INSERT INTO audit_logs (admin_id, action, entity, entity_id, payload, ip, created_at)
                         VALUES (NULL, 'stale_offline_queue', 'device', ?, ?, ?, NOW())");
    $st->execute([$tabId !== '' ? $tabId : null, $payload, $ip]);

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    // Log preserva o erro real para debug; resposta ao cliente é genérica.
    error_log('[audit_stale_queue] ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'error' => 'log_failed']);
}

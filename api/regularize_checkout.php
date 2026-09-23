<?php
/**
 * API: Regularizar Checkout Esquecido
 *
 * Recebe uma solicitacao do colaborador para fechar um ponto orfao (entrada sem
 * saida). O sistema NAO preenche check_out diretamente — cria registro em
 * attendance_checkout_requests para revisao do admin (opt-in, mesmo padrao da
 * solicitacao de hora extra).
 *
 * POST JSON ou form-encoded:
 *   { attendance_id: int, proposed_check_out: 'YYYY-MM-DD HH:MM[:SS]', justification: string, csrf: string }
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function rco_error(int $http, string $code, string $message): void {
    if (ob_get_level()) ob_clean();
    http_response_code($http);
    echo json_encode(['ok' => false, 'error_code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rco_error(405, 'method_not_allowed', 'Apenas POST eh aceito.');
}

if (!is_collaborator_logged()) {
    rco_error(401, 'auth_required', 'Faca login como colaborador para regularizar pontos.');
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) $payload = $decoded;
}
if (empty($payload)) $payload = $_POST;

$csrfToken = (string)($payload['csrf'] ?? $payload['csrf_token'] ?? '');
csrf_verify($csrfToken !== '' ? $csrfToken : null);

$attendanceId       = (int)($payload['attendance_id'] ?? 0);
$proposedCheckOut   = trim((string)($payload['proposed_check_out'] ?? ''));
$justification      = trim((string)($payload['justification'] ?? ''));

if ($attendanceId <= 0) {
    rco_error(400, 'invalid_attendance', 'ID do ponto invalido.');
}
if ($proposedCheckOut === '') {
    rco_error(400, 'missing_checkout', 'Informe o horario estimado de saida.');
}
if ($justification === '') {
    rco_error(400, 'justification_required', 'A justificativa eh obrigatoria.');
}

$teacherId = (int)($_SESSION['collaborator_id'] ?? 0);
if ($teacherId <= 0) {
    rco_error(401, 'auth_required', 'Sessao expirada. Faca login novamente.');
}

// Aceita formatos comuns (datetime-local devolve 'YYYY-MM-DDTHH:MM').
$proposedCheckOut = str_replace('T', ' ', $proposedCheckOut);
if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $proposedCheckOut)) {
    $proposedCheckOut .= ':00';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $proposedCheckOut)) {
    rco_error(400, 'invalid_checkout_format', 'Horario de saida em formato invalido (use AAAA-MM-DD HH:MM).');
}

$pdo = db();

try {
    $result = create_checkout_regularization_request($pdo, $attendanceId, $teacherId, $proposedCheckOut, $justification);
} catch (Throwable $e) {
    // HOTFIX 2026-09: detalhe da exceção (SQLSTATE, tabelas) só no log.
    error_log('[regularize_checkout] ' . $e->getMessage());
    rco_error(500, 'server_error', 'Erro ao registrar solicitacao. Tente novamente em instantes.');
}

if (empty($result['created'])) {
    // Mensagens de validacao do helper viram 409 (conflito de estado / dados invalidos).
    rco_error(409, 'not_eligible', $result['message']);
}

if (ob_get_level()) ob_clean();
echo json_encode([
    'ok'         => true,
    'request_id' => $result['request_id'],
    'status'     => 'pending',
    'message'    => $result['message'],
], JSON_UNESCAPED_UNICODE);

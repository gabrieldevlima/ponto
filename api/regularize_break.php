<?php
/**
 * API: Solicitar Correcao de Intervalo (datas anteriores ao dia atual)
 *
 * Recebe um pedido do colaborador para corrigir um intervalo registrado em DIA PASSADO
 * (esqueceu de iniciar/finalizar). Cria entrada em attendance_break_requests para
 * revisao do admin (analogo ao regularize_checkout, mas com par check_in/check_out).
 *
 * Para edicao no DIA ATUAL, usar api/edit_own_break.php (sem aprovacao).
 *
 * POST JSON ou form-encoded:
 *   { attendance_id: int,
 *     proposed_check_in:  'YYYY-MM-DD HH:MM[:SS]',
 *     proposed_check_out: 'YYYY-MM-DD HH:MM[:SS]',
 *     justification: string,
 *     csrf: string }
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function rbk_error(int $http, string $code, string $message): void {
    if (ob_get_level()) ob_clean();
    http_response_code($http);
    echo json_encode(['ok' => false, 'error_code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rbk_error(405, 'method_not_allowed', 'Apenas POST eh aceito.');
}

if (!is_collaborator_logged()) {
    rbk_error(401, 'auth_required', 'Faca login como colaborador para solicitar correcao.');
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

$attendanceId    = (int)($payload['attendance_id'] ?? 0);
$proposedIn      = trim((string)($payload['proposed_check_in']  ?? ''));
$proposedOut     = trim((string)($payload['proposed_check_out'] ?? ''));
$justification   = trim((string)($payload['justification'] ?? ''));

if ($attendanceId <= 0) {
    rbk_error(400, 'invalid_attendance', 'ID do registro invalido.');
}
if ($proposedIn === '' || $proposedOut === '') {
    rbk_error(400, 'missing_times', 'Informe os horarios de inicio e retorno do intervalo.');
}
if ($justification === '') {
    rbk_error(400, 'justification_required', 'A justificativa eh obrigatoria.');
}

$teacherId = (int)($_SESSION['collaborator_id'] ?? 0);
if ($teacherId <= 0) {
    rbk_error(401, 'auth_required', 'Sessao expirada. Faca login novamente.');
}

// Aceita datetime-local (YYYY-MM-DDTHH:MM) e completa segundos se necessario.
$normalize = static function (string $t): string {
    $t = str_replace('T', ' ', $t);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $t)) $t .= ':00';
    return $t;
};
$proposedIn  = $normalize($proposedIn);
$proposedOut = $normalize($proposedOut);
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $proposedIn) ||
    !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $proposedOut)) {
    rbk_error(400, 'invalid_format', 'Horarios em formato invalido (use AAAA-MM-DD HH:MM).');
}

$pdo = db();

try {
    $result = create_break_regularization_request($pdo, $attendanceId, $teacherId, $proposedIn, $proposedOut, $justification);
} catch (Throwable $e) {
    // HOTFIX 2026-09: detalhe da exceção (SQLSTATE, tabelas) só no log.
    error_log('[regularize_break] ' . $e->getMessage());
    rbk_error(500, 'server_error', 'Erro ao registrar solicitacao. Tente novamente em instantes.');
}

if (empty($result['created'])) {
    rbk_error(409, 'not_eligible', $result['message']);
}

if (ob_get_level()) ob_clean();
echo json_encode([
    'ok'         => true,
    'request_id' => $result['request_id'],
    'status'     => 'pending',
    'message'    => $result['message'],
], JSON_UNESCAPED_UNICODE);

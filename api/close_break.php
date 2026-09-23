<?php
/**
 * API: Informar volta do intervalo (fecha um intervalo ABERTO numa hora informada).
 *
 * Self-service do colaborador, auditado. Usado quando o colaborador esqueceu de
 * registrar a volta do intervalo e o registro ficou aberto (check_out NULL).
 *
 * POST JSON ou form-encoded:
 *   { attendance_id: int,
 *     return_time: 'HH:MM' | 'HH:MM:SS' | 'YYYY-MM-DD HH:MM[:SS]',
 *     justification?: string,
 *     csrf: string }
 *
 * return_time time-only é combinado com a DATA do intervalo no servidor.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function cb_error(int $http, string $code, string $message): void {
    if (ob_get_level()) ob_clean();
    http_response_code($http);
    echo json_encode(['ok' => false, 'error_code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cb_error(405, 'method_not_allowed', 'Apenas POST eh aceito.');
}
if (!is_collaborator_logged()) {
    cb_error(401, 'auth_required', 'Faca login como colaborador.');
}

$raw = file_get_contents('php://input') ?: '';
$payload = [];
if ($raw !== '') {
    $d = json_decode($raw, true);
    if (is_array($d)) $payload = $d;
}
if (empty($payload)) $payload = $_POST;

csrf_verify(((string)($payload['csrf'] ?? $payload['csrf_token'] ?? '')) ?: null);

$attendanceId = (int)($payload['attendance_id'] ?? 0);
$returnTime   = trim((string)($payload['return_time'] ?? $payload['check_out'] ?? ''));
$note         = trim((string)($payload['justification'] ?? $payload['note'] ?? ''));

if ($attendanceId <= 0) cb_error(400, 'invalid_attendance', 'ID do intervalo invalido.');
if ($returnTime === '') cb_error(400, 'missing_time', 'Informe a hora que voce voltou.');

$teacherId = (int)($_SESSION['collaborator_id'] ?? 0);
if ($teacherId <= 0) cb_error(401, 'auth_required', 'Sessao expirada. Faca login novamente.');

// datetime-local devolve 'YYYY-MM-DDTHH:MM' — normaliza o separador.
$returnTime = str_replace('T', ' ', $returnTime);

$pdo = db();
try {
    $r = close_own_open_break($pdo, $attendanceId, $teacherId, $returnTime, $note);
} catch (Throwable $e) {
    error_log('[close_break] ' . $e->getMessage());
    cb_error(500, 'server_error', 'Erro ao registrar a volta. Tente novamente.');
}

if (empty($r['success'])) cb_error(409, 'not_eligible', $r['message']);

if (ob_get_level()) ob_clean();
echo json_encode(['ok' => true, 'message' => $r['message']], JSON_UNESCAPED_UNICODE);

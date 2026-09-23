<?php
/**
 * API: Editar Intervalo Proprio (apenas no dia atual)
 *
 * Permite ao colaborador corrigir o horario de inicio/retorno de um intervalo seu
 * que aconteceu HOJE. Para datas anteriores, usar api/regularize_break.php que cria
 * uma solicitacao com aprovacao admin.
 *
 * Auditoria: grava em attendance_edits com edited_by=NULL (sinaliza colaborador) e
 * em audit_logs.
 *
 * POST JSON ou form-encoded:
 *   { attendance_id: int,
 *     check_in:  'YYYY-MM-DD HH:MM[:SS]' (opcional),
 *     check_out: 'YYYY-MM-DD HH:MM[:SS]' (opcional),
 *     justification: string,
 *     csrf: string }
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function eob_error(int $http, string $code, string $message): void {
    if (ob_get_level()) ob_clean();
    http_response_code($http);
    echo json_encode(['ok' => false, 'error_code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    eob_error(405, 'method_not_allowed', 'Apenas POST eh aceito.');
}

if (!is_collaborator_logged()) {
    eob_error(401, 'auth_required', 'Faca login como colaborador.');
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

$attendanceId  = (int)($payload['attendance_id'] ?? 0);
$newIn         = trim((string)($payload['check_in']  ?? ''));
$newOut        = trim((string)($payload['check_out'] ?? ''));
$justification = trim((string)($payload['justification'] ?? ''));

if ($attendanceId <= 0) {
    eob_error(400, 'invalid_attendance', 'ID do registro invalido.');
}
if ($newIn === '' && $newOut === '') {
    eob_error(400, 'missing_times', 'Informe ao menos um horario para alterar.');
}
if ($justification === '') {
    eob_error(400, 'justification_required', 'A justificativa eh obrigatoria.');
}

$teacherId = (int)($_SESSION['collaborator_id'] ?? 0);
if ($teacherId <= 0) {
    eob_error(401, 'auth_required', 'Sessao expirada. Faca login novamente.');
}

// Normaliza horarios: datetime-local devolve 'YYYY-MM-DDTHH:MM'.
$normalize = static function (string $t): string {
    if ($t === '') return '';
    $t = str_replace('T', ' ', $t);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $t)) $t .= ':00';
    return $t;
};
$newIn  = $normalize($newIn);
$newOut = $normalize($newOut);

$pdo = db();

try {
    $result = edit_own_break_direct(
        $pdo, $attendanceId, $teacherId,
        $newIn !== '' ? $newIn : null,
        $newOut !== '' ? $newOut : null,
        $justification
    );
} catch (Throwable $e) {
    // HOTFIX 2026-09: detalhe da exceção (SQLSTATE, tabelas) só no log.
    error_log('[edit_own_break] ' . $e->getMessage());
    eob_error(500, 'server_error', 'Erro ao corrigir intervalo. Tente novamente em instantes.');
}

if (empty($result['success'])) {
    eob_error(409, 'not_eligible', $result['message']);
}

if (ob_get_level()) ob_clean();
echo json_encode([
    'ok'      => true,
    'message' => $result['message'],
], JSON_UNESCAPED_UNICODE);

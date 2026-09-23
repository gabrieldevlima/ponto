<?php
/**
 * API: Solicitar Hora Extra — DESCONTINUADA.
 *
 * A instituição não trabalha mais com hora extra. O fluxo de solicitação foi
 * removido do portal do colaborador; este endpoint é mantido apenas para que
 * clientes com a página em cache (PWA/Service Worker) não recebam 404. Sempre
 * responde 410 Gone. Os registros antigos em overtime_requests permanecem no
 * banco apenas para auditoria.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

if (ob_get_level()) ob_clean();
http_response_code(410);
echo json_encode([
    'ok'         => false,
    'error_code' => 'feature_removed',
    'message'    => 'A solicitação de hora extra foi descontinuada. As horas trabalhadas são consolidadas mensalmente.',
], JSON_UNESCAPED_UNICODE);
exit;

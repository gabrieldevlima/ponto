<?php
// Endpoint desabilitado (decisão de produto 2026-05): identificação 1:N por
// face foi removida porque o reconhecimento facial estava causando muitas
// falhas em produção. Bater ponto agora requer CPF + PIN.
//
// Mantido como stub para que clientes antigos recebam erro claro em vez de
// 404. Frontend deve redirecionar o usuário para o fluxo de PIN.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../config.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

http_response_code(410);
echo json_encode([
    'status'  => 'error',
    'code'    => 'face_identify_disabled',
    'message' => 'Identificação por face indisponível. Digite seu CPF e PIN para bater o ponto.',
], JSON_UNESCAPED_UNICODE);

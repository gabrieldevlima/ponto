<?php
/**
 * API: Retorna hora do servidor para sincronização HLB
 * Usado como fallback prioritário antes de APIs externas
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Garante timezone correto
date_default_timezone_set('America/Sao_Paulo');

// Retorna hora atual no formato ISO 8601
$response = [
    'datetime' => date('Y-m-d\TH:i:s.uP'),
    'timezone' => 'America/Sao_Paulo',
    'source' => 'server',
    'timestamp' => time(),
    'date' => date('Y-m-d'),
    'time' => date('H:i:s')
];

echo json_encode($response);
exit;

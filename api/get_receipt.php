<?php
/**
 * API: Obter Comprovante de Registro de Ponto
 * 
 * Endpoint para buscar informações de um comprovante específico
 * Portaria MTP 671/2021
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json');

// Verificar autenticação (sessão já foi iniciada em config.php)
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$isCollaborator = isset($_SESSION['collaborator_id']) && $_SESSION['collaborator_id'] > 0;

if (!$isAdmin && !$isCollaborator) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Acesso negado. Faça login para visualizar comprovantes.'
    ]);
    exit;
}

// Obter ID do registro
$attendanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attendanceId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'ID de registro inválido.'
    ]);
    exit;
}

$pdo = db();

// Buscar dados do registro
$stmt = $pdo->prepare("
    SELECT 
        a.*,
        t.name as teacher_name,
        t.cpf as teacher_cpf
    FROM attendance a
    INNER JOIN teachers t ON a.teacher_id = t.id
    WHERE a.id = ?
");
$stmt->execute([$attendanceId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Registro não encontrado.'
    ]);
    exit;
}

// Verificar permissão (admin pode ver tudo, colaborador só seus próprios registros)
if ($isCollaborator && !$isAdmin) {
    $collaboratorId = $_SESSION['collaborator_id'] ?? 0;
    if ((int)$record['teacher_id'] !== $collaboratorId) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Acesso negado. Você só pode visualizar seus próprios comprovantes.'
        ]);
        exit;
    }
}

// Buscar configuração do empregador
$employerConfig = get_employer_config();

// Formatar dados para resposta
$response = [
    'status' => 'ok',
    'receipt' => [
        'id' => $record['id'],
        'nsr' => $record['nsr'],
        'teacher_name' => $record['teacher_name'],
        'teacher_cpf' => $record['teacher_cpf'],
        'date' => $record['date'],
        'check_in' => $record['check_in'],
        'check_out' => $record['check_out'],
        'action' => $record['check_out'] ? 'saída' : 'entrada',
        'record_mode' => $record['record_mode'] ?? 'online',
        'recorded_at' => $record['recorded_at'],
        'synced_at' => $record['synced_at'],
        'hlb_sync_status' => $record['hlb_sync_status'] ?? 'N/A',
        'latitude' => $record['check_in_lat'],
        'longitude' => $record['check_in_lng'],
        'photo' => $record['photo'],
        'approved' => $record['approved'],
        'device_identifier' => $record['device_identifier'],
        'receipt_generated' => (bool)$record['receipt_generated'],
        'receipt_viewed_at' => $record['receipt_viewed_at']
    ],
    'employer' => [
        'company_name' => $employerConfig['company_name'] ?? 'N/A',
        'cnpj' => $employerConfig['cnpj'] ?? 'N/A',
        'system_name' => $employerConfig['system_name'] ?? SYSTEM_NAME,
        'system_version' => $employerConfig['system_version'] ?? SYSTEM_VERSION,
        'rep_category' => $employerConfig['rep_category'] ?? REP_CATEGORY
    ],
    'pdf_url' => '/public/receipt.php?id=' . $attendanceId
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


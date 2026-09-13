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

// Verificar autenticação via helpers canônicos (consistente com o resto do sistema)
$isAdmin = function_exists('is_admin_logged') ? is_admin_logged() : false;
$isCollaborator = function_exists('is_collaborator_logged') ? is_collaborator_logged() : false;

if (!$isAdmin && !$isCollaborator) {
    http_response_code(401);
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

// Ownership: colaborador só vê o próprio comprovante; admin é limitado ao seu
// escopo de escolas.
// NC-46 (2026-08-05): "Admin vê tudo" ignorava admin_scope_where() — um
// school_admin lia comprovantes de toda a rede iterando ?id=.
if ($isAdmin) {
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $stScope = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id = ? AND {$scopeSql} LIMIT 1");
    $stScope->execute(array_merge([(int)$record['teacher_id']], $scopeParams));
    if (!$stScope->fetchColumn()) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Acesso negado. Este colaborador está fora do seu escopo.'
        ]);
        exit;
    }
} elseif ($isCollaborator) {
    $sessionTeacherId = (int)($_SESSION['collaborator_id'] ?? 0);
    if ($sessionTeacherId <= 0 || (int)$record['teacher_id'] !== $sessionTeacherId) {
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

// Hora extra descontinuada — o recibo não inclui mais status nem solicitação de hora extra.

// Tipo do registro (work/break) — fallback 'work' para compatibilidade pré-migration.
$recordType = $record['record_type'] ?? 'work';

// Rótulo da ação reflete o tipo: intervalos têm rótulos específicos.
if ($recordType === 'break') {
    $actionLabel = $record['check_out'] ? 'retorno do intervalo' : 'início do intervalo';
} else {
    $actionLabel = $record['check_out'] ? 'saída' : 'entrada';
}

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
        'record_type' => $recordType,
        'parent_attendance_id' => $record['parent_attendance_id'] ?? null,
        'action' => $actionLabel,
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
        'receipt_viewed_at' => $record['receipt_viewed_at'],
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


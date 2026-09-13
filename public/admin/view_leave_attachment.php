<?php
/**
 * Visualiza/baixa anexo de atestado médico
 * Com controle de acesso e log de auditoria LGPD
 */

require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$leaveId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'view'; // 'view' ou 'download'

if ($leaveId <= 0) {
    http_response_code(400);
    exit('ID inválido');
}

// Busca afastamento com escopo do admin
list($scopeSql, $scopeParams) = admin_scope_where('t');

$sql = "SELECT l.*, t.name as teacher_name
        FROM leaves l
        JOIN teachers t ON t.id = l.teacher_id
        WHERE l.id = ? AND $scopeSql";

$st = $pdo->prepare($sql);
$st->execute(array_merge([$leaveId], $scopeParams));
$leave = $st->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    http_response_code(404);
    exit('Afastamento não encontrado ou sem permissão');
}

if (empty($leave['attachment'])) {
    http_response_code(404);
    exit('Este afastamento não possui anexo');
}

// Novo caminho seguro (fora do webroot). Fallback para caminho legado (arquivos anteriores à migração).
// Defense in depth: realpath + strpos garante que o path final está DENTRO do diretório autorizado.
// Sem isso, um $leave['attachment'] malicioso ('../../config.php') poderia ler arquivos do sistema.
$allowedDirs = [
    realpath(dirname(__DIR__, 2) . '/storage/leaves'),
    realpath(__DIR__ . '/../attachments/leaves'),
];
$allowedDirs = array_filter($allowedDirs); // remove diretórios inexistentes

$attachmentPath = dirname(__DIR__, 2) . '/storage/leaves/' . $leave['attachment'];
if (!file_exists($attachmentPath)) {
    $attachmentPath = __DIR__ . '/../attachments/leaves/' . $leave['attachment'];
}

if (!file_exists($attachmentPath)) {
    http_response_code(404);
    exit('Arquivo não encontrado no servidor');
}

// Validação anti path-traversal: o caminho resolvido precisa começar com um dos dirs permitidos.
$resolvedPath = realpath($attachmentPath);
$pathOk = false;
if ($resolvedPath !== false) {
    foreach ($allowedDirs as $dir) {
        if (strpos($resolvedPath, $dir . DIRECTORY_SEPARATOR) === 0 || $resolvedPath === $dir) {
            $pathOk = true;
            break;
        }
    }
}
if (!$pathOk) {
    error_log("[view_leave_attachment] Path traversal tentado: leave_id={$leaveId} path=" . ($resolvedPath ?: 'unresolved'));
    http_response_code(403);
    exit('Acesso negado');
}

// Registra acesso no log de auditoria (LGPD)
$logAction = $action === 'download' ? 'download' : 'view';
$adminId = $_SESSION['admin_id'] ?? null;
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$stLog = $pdo->prepare("INSERT INTO leave_attachment_access_log 
                        (leave_id, accessed_by_admin_id, accessed_at, ip_address, user_agent, action) 
                        VALUES (?, ?, NOW(), ?, ?, ?)");
$stLog->execute([$leaveId, $adminId, $ipAddress, $userAgent, $logAction]);

// Determina o tipo MIME
$fileExt = strtolower(pathinfo($leave['attachment'], PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$mimeType = $mimeTypes[$fileExt] ?? 'application/octet-stream';

// Define headers para visualização ou download
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($attachmentPath));

if ($action === 'download') {
    $safeName = 'atestado_' . $leave['teacher_name'] . '_' . date('Y-m-d') . '.' . $fileExt;
    $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $safeName);
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
} else {
    header('Content-Disposition: inline; filename="' . basename($leave['attachment']) . '"');
}

// Cache control
header('Cache-Control: private, max-age=3600');
header('Pragma: private');

// Envia o arquivo
readfile($attachmentPath);
exit;


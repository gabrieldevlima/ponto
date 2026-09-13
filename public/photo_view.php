<?php
declare(strict_types=1);
/**
 * Servidor autenticado de fotos de marcação — NC-03 (auditoria 2026-08-05).
 *
 * Antes desta correção, public/photos/ não tinha .htaccess e as fotos eram
 * linkadas diretamente (admin/attendances.php). O Apache as servia para
 * qualquer visitante; a única proteção era o nome do arquivo ser aleatório.
 * Foto de rosto é dado biométrico (LGPD art. 5º, II e art. 11) — não pode ser
 * servida por obscuridade de URL.
 *
 * O acesso é por ID de REGISTRO, nunca por nome de arquivo. Isso é o que
 * permite aplicar autorização: o nome do arquivo não diz de quem ele é, mas
 * attendance.id diz. Consequência: não há como enumerar o diretório nem
 * adivinhar caminhos — só se chega a uma foto tendo direito ao registro dela.
 *
 * Autorização:
 *   - admin  → limitado pelo escopo de admin_scope_where() (school_admin não
 *              alcança colaborador de outra unidade);
 *   - colaborador → apenas os próprios registros.
 *
 * Uso: photo_view.php?att=<attendance_id>
 */

require_once __DIR__ . '/../config.php';

function photo_deny(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$isAdmin        = !empty($_SESSION['admin_id']);
$isCollaborator = is_collaborator_logged();

if (!$isAdmin && !$isCollaborator) {
    photo_deny(403, 'Acesso negado.');
}

$attendanceId = isset($_GET['att']) ? (int)$_GET['att'] : 0;
if ($attendanceId <= 0) {
    photo_deny(400, 'Registro invalido.');
}

$pdo = db();

if ($isAdmin) {
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $st = $pdo->prepare("
        SELECT a.photo, a.photo_deleted
          FROM attendance a
          JOIN teachers t ON t.id = a.teacher_id
         WHERE a.id = ? AND {$scopeSql}
         LIMIT 1
    ");
    $st->execute(array_merge([$attendanceId], $scopeParams));
} else {
    $st = $pdo->prepare("
        SELECT a.photo, a.photo_deleted
          FROM attendance a
         WHERE a.id = ? AND a.teacher_id = ?
         LIMIT 1
    ");
    $st->execute([$attendanceId, (int)($_SESSION['collaborator_id'] ?? 0)]);
}

$row = $st->fetch(PDO::FETCH_ASSOC);
// Mesma resposta para "não existe" e "não é seu": não vaza a existência do registro.
if (!$row) {
    photo_deny(404, 'Foto nao encontrada.');
}
if (!empty($row['photo_deleted'])) {
    photo_deny(410, 'Foto expurgada pela politica de retencao.');
}

$rel = trim((string)($row['photo'] ?? ''));
if ($rel === '') {
    photo_deny(404, 'Foto nao encontrada.');
}

// A coluna carrega dois formatos históricos:
//   - 'photo_<hex>_<ts>.jpg'   (api/checkin.php)
//   - 'photos/<32hex>.jpg'     (api/kiosk_lib.php, api/checkin_bulk.php)
// Normaliza para o nome puro e recusa qualquer coisa fora do padrão.
$name = basename(str_replace('\\', '/', $rel));
if (!preg_match('/^[A-Za-z0-9_.-]+\.(jpg|jpeg|png)$/i', $name)) {
    photo_deny(400, 'Referencia de foto invalida.');
}

$baseDir = realpath(__DIR__ . '/photos');
$abs     = realpath($baseDir . DIRECTORY_SEPARATOR . $name);

// Defesa em profundidade: confirma que o caminho resolvido continua dentro de
// public/photos/ mesmo que $name tenha escapado da validação acima.
if ($baseDir === false || $abs === false || strncmp($abs, $baseDir, strlen($baseDir)) !== 0 || !is_file($abs)) {
    photo_deny(404, 'Foto nao encontrada.');
}

$ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = $ext === 'png' ? 'image/png' : 'image/jpeg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($abs));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
// Dado biométrico: nunca em cache compartilhado.
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');

readfile($abs);

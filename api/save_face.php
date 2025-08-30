<?php
// Evita HTML de warnings/notices quebrando o JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'Método não permitido']);
    exit;
}
csrf_verify();
if (!is_admin_logged()) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Não autenticado']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'JSON inválido na requisição.']);
    exit;
}

$teacher_id = (int)($input['teacher_id'] ?? 0);
$descriptors = $input['descriptors'] ?? null;

if ($teacher_id <= 0 || !is_array($descriptors) || empty($descriptors)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Dados inválidos']);
    exit;
}

foreach ($descriptors as $d) {
    if (!is_array($d)) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Formato de descritor inválido']);
        exit;
    }
}

$pdo = db();
$stmt = $pdo->prepare("SELECT face_descriptors FROM teachers WHERE id = ?");
$stmt->execute([$teacher_id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'Professor não encontrado']);
    exit;
}

$existing = [];
if (!empty($row['face_descriptors'])) {
    $tmp = json_decode($row['face_descriptors'], true);
    if (is_array($tmp)) $existing = $tmp;
}

$merged = array_values(array_merge($existing, $descriptors));
$stmt = $pdo->prepare("UPDATE teachers SET face_descriptors = ? WHERE id = ?");
$stmt->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $teacher_id]);

echo json_encode(['status'=>'ok','count'=>count($merged)]);
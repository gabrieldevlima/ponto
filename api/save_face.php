<?php
// Evita HTML de warnings/notices quebrando o JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

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

// ============================================================================
// VALIDACAO RIGOROSA DOS DESCRIPTORS
// ============================================================================
foreach ($descriptors as $i => $d) {
    if (!is_array($d) || count($d) !== 128) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>"Descritor $i: formato inválido (esperado array de 128 floats)"]);
        exit;
    }
    foreach ($d as $j => $val) {
        if (!is_numeric($val)) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>"Descritor $i posição $j: valor não numérico"]);
            exit;
        }
    }
    // Normaliza todos os valores para float
    $descriptors[$i] = array_map('floatval', $d);
}

$pdo = db();

// Verifica se o professor existe
$stmt = $pdo->prepare("SELECT id, name, face_descriptors FROM teachers WHERE id = ?");
$stmt->execute([$teacher_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'Professor não encontrado']);
    exit;
}

// ============================================================================
// DETECCAO DE CONFLITO FACIAL — impede mesmo rosto em multiplas pessoas
// ============================================================================
$conflict = find_face_conflict($pdo, $descriptors, $teacher_id);
if ($conflict) {
    http_response_code(409);
    echo json_encode([
        'status' => 'error',
        'code' => 'face_conflict',
        'message' => 'Este rosto já está cadastrado para ' . $conflict['teacher_name'] . ' (ID ' . $conflict['teacher_id'] . ').',
        'conflict' => $conflict
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// MERGE COM LIMITE MAXIMO DE DESCRIPTORS
// ============================================================================
$maxDescriptors = (int)(get_setting('face_max_descriptors', '20') ?? '20');

$existing = [];
if (!empty($row['face_descriptors'])) {
    $tmp = json_decode($row['face_descriptors'], true);
    if (is_array($tmp)) $existing = $tmp;
}

$merged = array_values(array_merge($existing, $descriptors));

// Cap: mantem apenas os mais recentes
if (count($merged) > $maxDescriptors) {
    $merged = array_slice($merged, -$maxDescriptors);
}

$stmt = $pdo->prepare("UPDATE teachers SET face_descriptors = ? WHERE id = ?");
$stmt->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $teacher_id]);

// Audit log
audit_log('face_enroll', 'teacher', $teacher_id, [
    'added' => count($descriptors),
    'total' => count($merged),
    'max_allowed' => $maxDescriptors
]);

echo json_encode([
    'status' => 'ok',
    'count' => count($merged),
    'message' => count($descriptors) . ' amostra(s) salva(s). Total: ' . count($merged) . ' de ' . $maxDescriptors . ' máximo.'
], JSON_UNESCAPED_UNICODE);

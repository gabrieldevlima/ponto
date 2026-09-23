<?php
// M3: evita HTML de warnings quebrando JSON, mas mantém log_errors para debug.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

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
$incomingDescriptors = [];

if ($teacher_id <= 0 || !is_array($descriptors) || empty($descriptors)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Dados inválidos']);
    exit;
}
try {
    $incomingDescriptors = normalize_face_descriptors($descriptors, 20);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    exit;
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
    $tmp = face_descriptors_decode($row['face_descriptors']);
    if (is_array($tmp) && !empty($tmp)) {
        try {
            $existing = normalize_face_descriptors($tmp, 20);
        } catch (Throwable $e) {
            $existing = [];
        }
    }
}

// Validar diversidade das amostras recebidas
if (count($incomingDescriptors) >= 2) {
    $minDiversity = (float)(get_setting('face_enrollment_min_diversity', '0.10') ?? '0.10');
    $tooSimilarCount = 0;
    $totalPairs = 0;
    for ($i = 0; $i < count($incomingDescriptors); $i++) {
        for ($j = $i + 1; $j < count($incomingDescriptors); $j++) {
            $totalPairs++;
            if (euclidean_distance($incomingDescriptors[$i], $incomingDescriptors[$j]) < $minDiversity) {
                $tooSimilarCount++;
            }
        }
    }
    if ($totalPairs > 0 && ($tooSimilarCount / $totalPairs) > 0.5) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'code' => 'samples_not_diverse',
            'message' => 'As amostras faciais são muito parecidas. Capture em ângulos diferentes.'
        ]);
        exit;
    }
}

$merged = array_values(array_merge($existing, $incomingDescriptors));
$merged = deduplicate_face_descriptors($merged);
if (count($merged) > 20) {
    $merged = array_slice($merged, -20);
}

// Conflict check removido (decisão de produto 2026-05): captura aceita
// qualquer descritor; matching/duplicidade não é mais validado.

$stmt = $pdo->prepare("UPDATE teachers SET face_descriptors = ?, face_enrolled_at = NOW(), face_enrollment_version = COALESCE(face_enrollment_version, 0) + 1 WHERE id = ?");
$stmt->execute([face_descriptors_encode($merged), $teacher_id]);

echo json_encode(['status'=>'ok','count'=>count($merged)]);

<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'Método não permitido']);
    exit;
}
$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'JSON inválido na requisição.']);
    exit;
}

$cpf             = preg_replace('/\D/', '', (string)($input['cpf'] ?? ''));
$descriptors     = $input['descriptors'] ?? null;
$enrollmentToken = isset($input['enrollment_token']) ? trim((string)$input['enrollment_token']) : '';
$incomingDescriptors = [];

if (strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'CPF inválido.']);
    exit;
}
if ($enrollmentToken === '') {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Sessão de cadastro facial inválida ou ausente.']);
    exit;
}
if (!is_array($descriptors) || count($descriptors) < 1) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Descritores faciais ausentes ou inválidos.']);
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
$authIdentifier = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120));
$rateLimit = auth_attempt_is_limited($pdo, 'face_inline_enroll', $authIdentifier, 600, 6);
if (!empty($rateLimit['limited'])) {
    http_response_code(429);
    echo json_encode(['status'=>'error','message'=>'Muitas tentativas de cadastro facial. Aguarde e tente novamente.']);
    exit;
}

// Buscar colaborador pelo CPF digitado
$stmt = $pdo->prepare("SELECT id, name, cpf, face_descriptors FROM teachers WHERE cpf = ? AND active = 1 LIMIT 1");
$stmt->execute([$cpf]);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$teacher) {
    auth_attempt_log($pdo, 'face_inline_enroll', $authIdentifier, false, 0, 'cpf_not_found');
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'Colaborador não encontrado para o CPF informado.']);
    exit;
}

$teacher_id = (int)$teacher['id'];

// Validação de sessão: token temporário emitido no preview + vínculo por teacher_id + expiração
// Device_id removido da validação — causa mismatch entre fingerprints async.
// Segurança mantida por: sessão PHP (cookie), CSRF, teacher_id e expiração de 10 min.
$sessionEnroll = $_SESSION['inline_face_enroll'] ?? null;
$validEnrollSession = is_array($sessionEnroll)
    && isset($sessionEnroll['token'], $sessionEnroll['teacher_id'], $sessionEnroll['expires_at'])
    && hash_equals((string)$sessionEnroll['token'], $enrollmentToken)
    && (int)$sessionEnroll['teacher_id'] === $teacher_id
    && (int)$sessionEnroll['expires_at'] >= time();

if (!$validEnrollSession) {
    auth_attempt_log($pdo, 'face_inline_enroll', $authIdentifier, false, $teacher_id, 'invalid_enroll_session');
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Sessão de cadastro facial expirada ou inválida. Refaça o ponto e tente novamente.']);
    exit;
}
unset($_SESSION['inline_face_enroll']);

// Mesclar com descritores existentes (se houver)
$existing = [];
if (!empty($teacher['face_descriptors'])) {
    $tmp = face_descriptors_decode($teacher['face_descriptors']);
    if (is_array($tmp) && !empty($tmp)) {
        try {
            $existing = normalize_face_descriptors($tmp, 20);
        } catch (Throwable $e) {
            $existing = [];
        }
    }
}

// Validar diversidade das amostras recebidas — rejeitar se muito similares entre si
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
    // Se mais de 50% dos pares são muito similares, as amostras não são diversas
    if ($totalPairs > 0 && ($tooSimilarCount / $totalPairs) > 0.5) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'code' => 'samples_not_diverse',
            'message' => 'As amostras faciais são muito parecidas entre si. Vire o rosto em ângulos diferentes durante o cadastro.'
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

auth_attempt_log($pdo, 'face_inline_enroll', $authIdentifier, true, $teacher_id, 'enroll_ok', ['descriptors_count' => count($merged)]);

echo json_encode([
    'status' => 'ok',
    'message' => 'Rosto cadastrado com sucesso.',
    'count' => count($merged)
]);

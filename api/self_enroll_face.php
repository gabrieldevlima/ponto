<?php
// Auto-cadastro de face em contexto confiável.
// Permite que um colaborador sem face cadastrada salve seu próprio rosto
// quando estiver dentro do geofence da escola e sem sinais de fraude de GPS.
// Política complementar a api/pin_enroll.php: primeiro acesso não exige face,
// mas quando step-up é disparado em contexto confiável, o próprio colaborador
// pode concluir o cadastro facial sem depender do admin.
//
// Fluxo:
//   1. Recebe CPF + PIN + geo + deviceFingerprint + descriptors[3]
//   2. Re-valida TUDO server-side (PIN, geo, gps_mock, face ainda vazia)
//   3. Aplica lógica de save_face.php (normaliza, diversidade, dedup, conflict)
//   4. UPDATE teachers + audit log
//   5. Emite pin_stepup_nonce em sessão para que o frontend possa completar
//      o check-in automaticamente na sequência (reusa via de step-up existente)
//   6. Responde com nonce + teacher_id para o retry

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 7200');
    http_response_code(204);
    exit;
}

if (ob_get_level()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../config.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

function self_enroll_error(int $httpCode, string $code, string $message, array $hints = []): void {
    if (ob_get_level()) ob_clean();
    http_response_code($httpCode);
    echo json_encode([
        'status'  => 'error',
        'code'    => $code,
        'message' => $message,
        'hints'   => $hints,
    ], JSON_UNESCAPED_UNICODE);
    if (ob_get_level()) ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    self_enroll_error(405, 'method_not_allowed', 'Método não permitido.');
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    self_enroll_error(400, 'invalid_json', 'Dados inválidos.');
}

// CSRF só é exigido quando há sessão ativa (segue padrão de pin_enroll.php)
if (is_collaborator_logged()) {
    $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfToken || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrfToken)) {
        self_enroll_error(403, 'csrf_invalid', 'Token de sessão inválido. Recarregue a página.');
    }
}

$cpf = preg_replace('/\D/', '', (string)($input['cpf'] ?? ''));
if (strlen($cpf) !== 11 || !validar_cpf($cpf)) {
    self_enroll_error(400, 'cpf_invalid', 'CPF inválido.', ['Verifique se digitou o CPF corretamente.']);
}

$pin = preg_replace('/\D/', '', (string)($input['pin'] ?? ''));
if (strlen($pin) !== 6) {
    self_enroll_error(400, 'pin_invalid', 'PIN inválido.');
}

$descriptors = $input['descriptors'] ?? null;
if (!is_array($descriptors) || count($descriptors) !== 3) {
    self_enroll_error(400, 'descriptors_required', 'São necessárias exatamente 3 capturas faciais.');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$deviceFingerprintInput = isset($input['deviceFingerprint']) ? (string)$input['deviceFingerprint'] : '';
$authIdentifier = hash('sha256', $ip . '|' . substr($ua, 0, 120) . '|' . substr($deviceFingerprintInput, 0, 120));

$pdo = db();

// Rate limit (mesma janela do pin_enroll)
$rateLimit = auth_attempt_is_limited($pdo, 'pin', $authIdentifier, 900, 3);
if (!empty($rateLimit['limited'])) {
    self_enroll_error(429, 'too_many_attempts', 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');
}

// Busca teacher
$stmt = $pdo->prepare("SELECT id, name, active, network_wide, cpf, face_descriptors, pin_hash
                       FROM teachers WHERE cpf = ? AND active = 1 LIMIT 1");
$stmt->execute([$cpf]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    auth_attempt_log($pdo, 'pin', $authIdentifier, false, null, 'self_enroll_cpf_not_found');
    self_enroll_error(401, 'cpf_invalid', 'CPF não encontrado ou colaborador inativo.');
}
$teacherId = (int)$row['id'];

// Re-valida PIN
if (empty($row['pin_hash'])) {
    auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'self_enroll_pin_not_set');
    self_enroll_error(200, 'pin_not_set', 'Você ainda não tem um PIN.', [
        'Toque em "Primeiro acesso" para gerar seu PIN.'
    ]);
}
if (!pin_verify($pin, (string)$row['pin_hash'])) {
    auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'self_enroll_pin_invalid');
    self_enroll_error(200, 'pin_invalid', 'PIN incorreto.');
}

// Confirma que face ainda está vazia
if (!empty($row['face_descriptors'])) {
    auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'self_enroll_already_enrolled');
    self_enroll_error(200, 'already_enrolled', 'Seu rosto já foi cadastrado. Toque em BATER PONTO novamente.');
}

// Re-valida contexto confiável (geo dentro do geofence da escola + sem gps_mock)
$geo = isset($input['geo']) && is_array($input['geo']) ? $input['geo'] : [];
$geoLat = isset($geo['lat']) ? (float)$geo['lat'] : null;
$geoLng = isset($geo['lng']) ? (float)$geo['lng'] : null;
$gpsMock = !empty($input['fraudCheck']['mockDetected']);

$geoTrusted = false;
if ((int)$row['network_wide'] === 1) {
    // Escola com network_wide permite qualquer local. Geo trust = true por desenho.
    $geoTrusted = true;
} elseif ($geoLat !== null && $geoLng !== null) {
    $schools = get_teacher_allowed_schools($pdo, $teacherId);
    if (!empty($schools)) {
        $radiusM = (float)((get_setting('geofence_radius_m', '300') ?? '300'));
        [$inR] = match_school_by_geo($schools, $geoLat, $geoLng, $radiusM);
        $geoTrusted = (bool)$inR;
    }
}

if (!$geoTrusted || $gpsMock) {
    auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'self_enroll_context_not_trusted', [
        'geo_trusted' => $geoTrusted,
        'gps_mock' => $gpsMock,
    ]);
    self_enroll_error(200, 'context_not_trusted',
        'Auto-cadastro facial só pode ser feito na unidade e com GPS válido.',
        ['Procure o administrador para concluir o cadastro facial.']);
}

// Normaliza descritores
try {
    $incomingDescriptors = normalize_face_descriptors($descriptors, 20);
} catch (Throwable $e) {
    self_enroll_error(400, 'invalid_face_descriptor', $e->getMessage());
}

// Validação de diversidade (mesma regra de save_face.php)
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
        self_enroll_error(400, 'samples_not_diverse',
            'As fotos ficaram muito parecidas. Capture em ângulos diferentes.',
            ['Vire levemente a cabeça e varie a expressão.']);
    }
}

$merged = array_values($incomingDescriptors);
$merged = deduplicate_face_descriptors($merged);
if (count($merged) > 20) {
    $merged = array_slice($merged, -20);
}

// Conflict check removido (decisão de produto 2026-05): captura aceita
// qualquer descritor; matching/duplicidade não é mais validado.

// Re-checa que face ainda está vazia antes do UPDATE (protege contra race)
$stmtLock = $pdo->prepare("SELECT face_descriptors FROM teachers WHERE id = ? LIMIT 1");
$stmtLock->execute([$teacherId]);
$lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
if (!empty($lockRow['face_descriptors'])) {
    auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId, 'self_enroll_race_already_enrolled');
    self_enroll_error(200, 'already_enrolled', 'Seu rosto já foi cadastrado. Toque em BATER PONTO novamente.');
}

$upd = $pdo->prepare("UPDATE teachers
                       SET face_descriptors = ?,
                           face_enrolled_at = NOW(),
                           face_enrollment_version = COALESCE(face_enrollment_version, 0) + 1
                     WHERE id = ?");
$upd->execute([face_descriptors_encode($merged), $teacherId]);

auth_attempt_log($pdo, 'pin', $authIdentifier, true, $teacherId, 'face_self_enrolled', [
    'count' => count($merged),
]);
if (function_exists('audit_log')) {
    audit_log('pin.face_self_enrolled', 'teacher', $teacherId, [
        'count' => count($merged),
    ]);
}

// Emite stepup_nonce em sessão para o frontend completar o check-in em seguida
$stepupNonce = bin2hex(random_bytes(16));
$_SESSION['pin_stepup_nonce'] = [
    'nonce' => $stepupNonce,
    'teacher_id' => $teacherId,
    'expires_at' => time() + 300,
];

if (ob_get_level()) ob_clean();
echo json_encode([
    'status' => 'ok',
    'count' => count($merged),
    'stepup_nonce' => $stepupNonce,
    'teacher_id' => $teacherId,
], JSON_UNESCAPED_UNICODE);
if (ob_get_level()) ob_end_flush();

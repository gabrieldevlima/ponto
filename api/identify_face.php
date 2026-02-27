<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
if (ob_get_level()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../config.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método não permitido.']);
    exit;
}
csrf_verify();

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'JSON inválido.']);
    exit;
}

$descriptor = $input['face_descriptor'] ?? null;
if (!$descriptor || !is_array($descriptor) || count($descriptor) !== 128) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Descriptor facial inválido (esperado: array de 128 floats).']);
    exit;
}

function face_euc_dist(array $a, array $b): float {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $diff = (float)$a[$i] - (float)$b[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

$pdo = db();
$stmt = $pdo->query("
    SELECT id, name, cpf, face_descriptors, active, network_wide
    FROM teachers
    WHERE active = 1 AND face_descriptors IS NOT NULL AND face_descriptors != ''
");

$bestMatch = null;
$bestDist = PHP_FLOAT_MAX;
$threshold = 0.6;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stored = json_decode($row['face_descriptors'], true);
    if (!is_array($stored)) continue;
    foreach ($stored as $ref) {
        if (!is_array($ref) || count($ref) !== 128) continue;
        $dist = face_euc_dist($descriptor, $ref);
        if ($dist < $bestDist) {
            $bestDist = $dist;
            $bestMatch = $row;
        }
    }
}

if (!$bestMatch || $bestDist >= $threshold) {
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'status' => 'not_identified',
        'message' => 'Rosto não identificado. Digite seu PIN para continuar.',
        'face_distance' => $bestDist < PHP_FLOAT_MAX ? round($bestDist, 4) : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$teacherId = (int)$bestMatch['id'];
$pct = max(0, round((1 - $bestDist) * 100));

$tzBR = new DateTimeZone('America/Sao_Paulo');
$now = new DateTime('now', $tzBR);
$today = $now->format('Y-m-d');

$stOpen = $pdo->prepare("
    SELECT id, check_in, date
    FROM attendance
    WHERE teacher_id = ? AND date = ? AND check_out IS NULL
    ORDER BY id DESC LIMIT 1
");
$stOpen->execute([$teacherId, $today]);
$open = $stOpen->fetch(PDO::FETCH_ASSOC);

$actionKey = $open ? 'out' : 'in';
$actionLabel = $open ? 'Saída' : 'Entrada';
$openInfo = null;
if ($open) {
    try {
        $checkInDt = new DateTime($open['check_in'], $tzBR);
    } catch (Throwable $e) {
        $checkInDt = null;
    }
    $openInfo = [
        'id' => (int)$open['id'],
        'check_in' => $open['check_in'],
        'check_in_time' => $checkInDt ? $checkInDt->format('H:i') : null,
        'date' => $open['date'] ?? $today
    ];
}

$message = $actionKey === 'in'
    ? 'Identidade confirmada. Será registrada uma nova entrada.'
    : 'Identidade confirmada. Será registrada a saída do turno iniciado às ' . ($openInfo['check_in_time'] ?? '—') . '.';

if (ob_get_level()) ob_clean();
echo json_encode([
    'status' => 'identified',
    'collaborator' => [
        'id' => $teacherId,
        'name' => $bestMatch['name'],
        'cpf' => $bestMatch['cpf'] ?? null
    ],
    'action' => $actionLabel,
    'action_key' => $actionKey,
    'open_record' => $openInfo,
    'message' => $message,
    'face_match' => true,
    'face_distance' => round($bestDist, 4),
    'face_confidence' => $pct
], JSON_UNESCAPED_UNICODE);

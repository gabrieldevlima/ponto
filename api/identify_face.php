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

// ============================================================================
// RATE LIMITING — protege contra brute-force de identificacao facial
// ============================================================================
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$pdo = db();

$rlMax    = (int)(get_setting('face_rate_limit_max', '10') ?? '10');
$rlWindow = (int)(get_setting('face_rate_limit_window', '60') ?? '60');

try {
    // Cria tabela de rate limiting se nao existir (MEMORY engine = auto-limpa no restart)
    $pdo->exec("CREATE TABLE IF NOT EXISTS face_rate_limits (
        rate_key VARCHAR(64) PRIMARY KEY,
        attempts INT DEFAULT 0,
        window_start DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=MEMORY");

    $rateKey = 'face_id_' . substr(hash('sha256', $ip), 0, 48);
    $nowDt = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

    $stRl = $pdo->prepare("SELECT attempts, window_start FROM face_rate_limits WHERE rate_key = ?");
    $stRl->execute([$rateKey]);
    $rl = $stRl->fetch(PDO::FETCH_ASSOC);

    if ($rl) {
        $elapsed = time() - strtotime($rl['window_start']);
        if ($elapsed > $rlWindow) {
            $pdo->prepare("UPDATE face_rate_limits SET attempts = 1, window_start = ? WHERE rate_key = ?")->execute([$nowDt, $rateKey]);
        } elseif ((int)$rl['attempts'] >= $rlMax) {
            if (ob_get_level()) ob_clean();
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Muitas tentativas de identificação. Aguarde ' . $rlWindow . ' segundos.',
                'retry_after' => $rlWindow - $elapsed
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            $pdo->prepare("UPDATE face_rate_limits SET attempts = attempts + 1 WHERE rate_key = ?")->execute([$rateKey]);
        }
    } else {
        $pdo->prepare("INSERT INTO face_rate_limits (rate_key, attempts, window_start) VALUES (?, 1, ?)")->execute([$rateKey, $nowDt]);
    }
} catch (Throwable $e) {
    // Nao bloquear em caso de falha no rate limiting — apenas logar
    error_log("Rate limit check failed: " . $e->getMessage());
}

// ============================================================================
// VALIDACAO DO DESCRIPTOR
// ============================================================================
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

// ============================================================================
// MATCHING FACIAL AVANCADO (3 camadas: threshold + margem + consenso)
// ============================================================================
$matchResult = match_face_against_teachers($pdo, $descriptor, 'identify');

if (!$matchResult['matched']) {
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'status' => 'not_identified',
        'message' => 'Rosto não identificado. Digite seu PIN para continuar.',
        // NAO retorna face_distance para evitar que atacante calibre spoofing
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$teacher = $matchResult['teacher'];
$teacherId = (int)$teacher['id'];
$pct = max(0, round((1 - $matchResult['best_distance']) * 100));

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
        'name' => $teacher['name'],
        'cpf' => $teacher['cpf'] ?? null
    ],
    'action' => $actionLabel,
    'action_key' => $actionKey,
    'open_record' => $openInfo,
    'message' => $message,
    'face_match' => true,
    'face_confidence' => $pct
    // NAO retorna face_distance — seguranca contra calibracao de spoofing
], JSON_UNESCAPED_UNICODE);

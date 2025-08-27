<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json');

$tzBR = new DateTimeZone('America/Sao_Paulo');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'Método não permitido']);
    exit;
}
csrf_verify();

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'JSON inválido na requisição.']);
    exit;
}

$pin = (string)($input['pin'] ?? '');
$cpf = isset($input['cpf']) ? preg_replace('/\D/','',(string)$input['cpf']) : '';
$photo = $input['photo'] ?? null;
$geo = $input['geo'] ?? null;

if (!$pin || !$photo) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'PIN e foto são obrigatórios.']);
    exit;
}

$pdo = db();

// Busca colaborador
$prof = null;
if ($cpf) {
    $stmt = $pdo->prepare("SELECT t.id, t.name, t.pin_hash, t.active, t.type_id, ct.slug AS type_slug, ct.schedule_mode
                           FROM teachers t
                           JOIN collaborator_types ct ON ct.id = t.type_id
                           WHERE t.cpf = ?");
    $stmt->execute([$cpf]);
    $row = $stmt->fetch();
    if ($row && password_verify($pin, $row['pin_hash'])) {
        $prof = $row;
    }
}
if (!$prof) {
    $stmt = $pdo->prepare("SELECT t.id, t.name, t.pin_hash, t.active, t.type_id, ct.slug AS type_slug, ct.schedule_mode
                           FROM teachers t
                           JOIN collaborator_types ct ON ct.id = t.type_id
                           WHERE t.pin_hash IS NOT NULL");
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        if (password_verify($pin, $row['pin_hash'])) {
            $prof = $row;
            break;
        }
    }
}

if (!$prof || !$prof['active']) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'PIN inválido ou colaborador inativo.']);
    exit;
}

$teacherId = (int)$prof['id'];
$mode = (string)($prof['schedule_mode'] ?? 'none');

$today = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d');
$now = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
$lng = isset($geo['lng']) ? (float)$geo['lng'] : null;
$acc = isset($geo['acc']) ? (float)$geo['acc'] : null;

// Verifica ponto aberto
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
$stmt->execute([$teacherId, $today]);
$open = $stmt->fetch();
$action = $open ? 'saída' : 'entrada';

// Validação: se há rotina (classes/time), exigir rotina para o dia
if ($action === 'entrada' && $mode !== 'none') {
    $weekday = (int)(new DateTimeImmutable('now', $tzBR))->format('w');
    $stmt = $pdo->prepare("SELECT classes_count, start_time, end_time FROM collaborator_schedules WHERE teacher_id = ? AND weekday = ?");
    $stmt->execute([$teacherId, $weekday]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    $has = false;
    if ($mode === 'classes') {
        $has = $schedule && (int)$schedule['classes_count'] > 0;
    } elseif ($mode === 'time') {
        $has = $schedule && !empty($schedule['start_time']) && !empty($schedule['end_time']);
    }
    if (!$has) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Não há rotina prevista para você neste dia. Registro de ponto não permitido.']);
        exit;
    }
}

// Salva foto
$dir = __DIR__ . '/../public/photos/';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$filename = 'foto_' . $teacherId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
$filepath = $dir . $filename;
if (preg_match('#^data:image/[^;]+;base64,(.+)$#', (string)$photo, $m)) {
    file_put_contents($filepath, base64_decode($m[1]));
} else {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Foto inválida.']);
    exit;
}
$photoUrl = '/photos/' . $filename;

if ($action === 'entrada') {
    if ($open) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Já há uma entrada aberta hoje sem saída.']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO attendance
        (teacher_id, date, check_in, method, ip, user_agent, check_in_lat, check_in_lng, check_in_acc, photo, approved)
        VALUES (?,?,?,?,?,?,?,?,?,?,1)");
    $stmt->execute([$teacherId, $today, $now, 'pin', $ip, $ua, $lat, $lng, $acc, $filename]);
    echo json_encode([
        'status'=>'ok',
        'collaborator'=>['id'=>$teacherId,'name'=>$prof['name'],'type'=>$prof['type_slug'] ?? ''],
        'teacher'=>['id'=>$teacherId,'name'=>$prof['name']], // compat
        'action'=>'entrada',
        'time'=>$now,
        'photo'=>$photoUrl,
        'message'=>'Entrada registrada com foto!'
    ]);
    exit;
} else {
    if (!$open) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Não há entrada aberta hoje. Registre a entrada antes da saída.']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE attendance
        SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP, photo = ?
        WHERE id = ?");
    $stmt->execute([$now, $lat, $lng, $acc, $filename, $open['id']]);
    echo json_encode([
        'status'=>'ok',
        'collaborator'=>['id'=>$teacherId,'name'=>$prof['name'],'type'=>$prof['type_slug'] ?? ''],
        'teacher'=>['id'=>$teacherId,'name'=>$prof['name']], // compat
        'action'=>'saída',
        'time'=>$now,
        'photo'=>$photoUrl,
        'message'=>'Saída registrada com foto!'
    ]);
    exit;
}
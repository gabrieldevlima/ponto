<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
$email = trim($_POST['email'] ?? '');
$pin = $_POST['pin'] ?? '';
$type_id = isset($_POST['type_id']) && ctype_digit((string)$_POST['type_id']) ? (int)$_POST['type_id'] : 1;

$scheduleClasses = $_POST['schedule_classes'] ?? [];
$scheduleTime = $_POST['schedule_time'] ?? [];

// valida tipo existente
$stmt = $pdo->prepare("SELECT id, schedule_mode FROM collaborator_types WHERE id = ?");
$stmt->execute([$type_id]);
$typeRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$typeRow) die('Tipo de colaborador inválido.');
$scheduleMode = (string)$typeRow['schedule_mode'];

if (!$name || !$cpf) die('Nome e CPF são obrigatórios.');
if (!$id && !$pin) die('PIN obrigatório no cadastro.');
if (!$id && (!preg_match('/^\d{6}$/', $pin))) die('O PIN deve conter exatamente 6 dígitos numéricos.');

if (!$id) {
    // Verifica unicidade do PIN (melhor esforço com hash)
    $pins = $pdo->query("SELECT pin_hash FROM teachers WHERE pin_hash IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pins as $pin_hash_db) {
        if (password_verify($pin, (string)$pin_hash_db)) {
            die('Este PIN já está em uso. Escolha outro.');
        }
    }
    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO teachers (name, cpf, email, pin_hash, active, type_id) VALUES (?, ?, ?, ?, 1, ?)");
    $stmt->execute([$name, $cpf, $email, $pin_hash, $type_id]);
    $id = (int)$pdo->lastInsertId();
} else {
    // Atualiza dados; só altera pin_hash se novo PIN fornecido e válido
    if ($pin) {
        if (!preg_match('/^\d{6}$/', $pin)) die('O PIN deve conter exatamente 6 dígitos numéricos.');
        $pins = $pdo->query("SELECT pin_hash FROM teachers WHERE pin_hash IS NOT NULL AND id <> ".(int)$id)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pins as $pin_hash_db) {
            if (password_verify($pin, (string)$pin_hash_db)) {
                die('Este PIN já está em uso. Escolha outro.');
            }
        }
        $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE teachers SET name = ?, cpf = ?, email = ?, type_id = ?, pin_hash = ? WHERE id = ?");
        $stmt->execute([$name, $cpf, $email, $type_id, $pin_hash, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE teachers SET name = ?, cpf = ?, email = ?, type_id = ? WHERE id = ?");
        $stmt->execute([$name, $cpf, $email, $type_id, $id]);
    }
}

// Salvar rotina semanal conforme schedule_mode
$weekdays = [0,1,2,3,4,5,6];
if ($id > 0) {
    foreach ($weekdays as $weekday) {
        if ($scheduleMode === 'classes') {
            $cc = isset($scheduleClasses[$weekday]['classes_count']) ? max(0, (int)$scheduleClasses[$weekday]['classes_count']) : 0;
            $cm = isset($scheduleClasses[$weekday]['class_minutes']) ? max(0, (int)$scheduleClasses[$weekday]['class_minutes']) : 0;
            $start = null; $end = null; $break = 0;
        } elseif ($scheduleMode === 'time') {
            $start = trim($scheduleTime[$weekday]['start_time'] ?? '');
            $end = trim($scheduleTime[$weekday]['end_time'] ?? '');
            $break = isset($scheduleTime[$weekday]['break_minutes']) ? max(0, (int)$scheduleTime[$weekday]['break_minutes']) : 0;
            $cc = 0; $cm = 0;
            if ($start === '' || $end === '') {
                $start = null; $end = null; $break = 0; // dia sem rotina
            }
        } else {
            // none
            $cc = 0; $cm = 0; $start = null; $end = null; $break = 0;
        }

        // UPSERT simples
        $stmt = $pdo->prepare("SELECT id FROM collaborator_schedules WHERE teacher_id = ? AND weekday = ?");
        $stmt->execute([$id, $weekday]);
        $exists = $stmt->fetchColumn();
        if ($exists) {
            $stmt = $pdo->prepare("UPDATE collaborator_schedules
                SET classes_count = ?, class_minutes = ?, start_time = ?, end_time = ?, break_minutes = ?
                WHERE teacher_id = ? AND weekday = ?");
            $stmt->execute([$cc, $cm, $start, $end, $break, $id, $weekday]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO collaborator_schedules
                (teacher_id, weekday, classes_count, class_minutes, start_time, end_time, break_minutes)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $weekday, $cc, $cm, $start, $end, $break]);
        }
    }
}

header('Location: teachers.php');
exit;
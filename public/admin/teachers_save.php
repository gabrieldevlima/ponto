<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
$email = trim($_POST['email'] ?? '');
$type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
$pin = $_POST['pin'] ?? '';
$base_salary = isset($_POST['base_salary']) ? (float)$_POST['base_salary'] : 0.0;
$network_wide = isset($_POST['network_wide']) ? 1 : 0;
$schools = isset($_POST['schools']) && is_array($_POST['schools']) ? array_values(array_unique(array_map('intval', $_POST['schools']))) : [];

$schedule = $_POST['schedule'] ?? [];          // classes
$timeSchedule = $_POST['time_schedule'] ?? []; // time

if (!$name || !$cpf) { die('Nome e CPF são obrigatórios.'); }
if (!$id && !$pin) { die('PIN obrigatório no cadastro.'); }
if ($type_id <= 0) { die('Tipo de colaborador é obrigatório.'); }
if ($base_salary < 0) { die('Salário base inválido.'); }

// Descobre o modo do tipo selecionado
$stMode = $pdo->prepare("SELECT schedule_mode FROM collaborator_types WHERE id = ?");
$stMode->execute([$type_id]);
$mode = $stMode->fetchColumn() ?: 'classes';

try {
  $pdo->beginTransaction();

  if ($id) {
      $stmt = $pdo->prepare("UPDATE teachers SET name = ?, email = ?, type_id = ?, base_salary = ?, network_wide = ? WHERE id = ?");
      $stmt->execute([$name, $email, $type_id, $base_salary, $network_wide, $id]);
      audit_log('update','teacher',$id,['name'=>$name,'type_id'=>$type_id,'base_salary'=>$base_salary,'network_wide'=>$network_wide]);
  } else {
      $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO teachers (name, cpf, email, pin_hash, active, type_id, base_salary, network_wide) VALUES (?, ?, ?, ?, 1, ?, ?, ?)");
      $stmt->execute([$name, $cpf, $email, $pin_hash, $type_id, $base_salary, $network_wide]);
      $id = (int)$pdo->lastInsertId();
      audit_log('create','teacher',$id,['name'=>$name,'type_id'=>$type_id,'base_salary'=>$base_salary,'network_wide'=>$network_wide]);
  }

  // Vínculos com escolas (somente se NÃO for rede completa)
  $pdo->prepare("DELETE FROM teacher_schools WHERE teacher_id = ?")->execute([$id]);
  if ($network_wide !== 1 && !empty($schools)) {
      $ins = $pdo->prepare("INSERT INTO teacher_schools (teacher_id, school_id) VALUES (?, ?)");
      foreach ($schools as $sid) {
        $ins->execute([$id, (int)$sid]);
      }
  }

  if ($mode === 'classes') {
      // Salvar rotina semanal por aulas
      if (is_array($schedule)) {
          foreach ($schedule as $weekday => $data) {
              $classes_count = max(0, (int)($data['classes_count'] ?? 0));
              $class_minutes = max(0, (int)($data['class_minutes'] ?? 0));
              $stmt = $pdo->prepare("SELECT id FROM teacher_schedules WHERE teacher_id = ? AND weekday = ?");
              $stmt->execute([$id, $weekday]);
              $exists = $stmt->fetchColumn();
              if ($exists) {
                  $stmt = $pdo->prepare("UPDATE teacher_schedules SET classes_count = ?, class_minutes = ? WHERE teacher_id = ? AND weekday = ?");
                  $stmt->execute([$classes_count, $class_minutes, $id, $weekday]);
              } else {
                  $stmt = $pdo->prepare("INSERT INTO teacher_schedules (teacher_id, weekday, classes_count, class_minutes) VALUES (?, ?, ?, ?)");
                  $stmt->execute([$id, $weekday, $classes_count, $class_minutes]);
              }
          }
      }
      // limpar horários "time"
      $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ?")->execute([$id]);
  } elseif ($mode === 'time') {
      // Salvar rotina semanal por horário
      if (is_array($timeSchedule)) {
          foreach ($timeSchedule as $weekday => $ts) {
              $start = trim($ts['start'] ?? '');
              $end = trim($ts['end'] ?? '');
              $break = max(0, (int)($ts['break'] ?? 0));
              if ($start === '' && $end === '') {
                  $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?")->execute([$id, $weekday]);
                  continue;
              }
              if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
                  throw new RuntimeException('Horários inválidos em ' . $weekday . '. Use HH:MM.');
              }
              $stmt = $pdo->prepare("SELECT id FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
              $stmt->execute([$id, $weekday]);
              $exists = $stmt->fetchColumn();
              if ($exists) {
                  $stmt = $pdo->prepare("UPDATE collaborator_time_schedules SET start_time = ?, end_time = ?, break_minutes = ? WHERE teacher_id = ? AND weekday = ?");
                  $stmt->execute([$start, $end, $break, $id, $weekday]);
              } else {
                  $stmt = $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, break_minutes) VALUES (?, ?, ?, ?, ?)");
                  $stmt->execute([$id, $weekday, $start, $end, $break]);
              }
          }
      }
      // limpar rotina "classes"
      $pdo->prepare("DELETE FROM teacher_schedules WHERE teacher_id = ?")->execute([$id]);
  } else {
      // mode 'none': limpar rotinas
      $pdo->prepare("DELETE FROM teacher_schedules WHERE teacher_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ?")->execute([$id]);
  }

  $pdo->commit();
  header('Location: teachers.php?msg=' . urlencode('Colaborador salvo com sucesso.'));
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo 'Erro ao salvar colaborador: ' . esc($e->getMessage());
  exit;
}
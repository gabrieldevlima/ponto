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
$hourly_rate = isset($_POST['hourly_rate']) && $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
$network_wide = isset($_POST['network_wide']) ? 1 : 0;
$schools = isset($_POST['schools']) && is_array($_POST['schools']) ? array_values(array_unique(array_map('intval', $_POST['schools']))) : [];
$face_descriptors_raw = $_POST['face_descriptors'] ?? '';
$face_descriptors_clear = isset($_POST['face_descriptors_clear']) && $_POST['face_descriptors_clear'] === '1';
$face_descriptors = null;
$face_descriptors_provided = false;
if ($face_descriptors_clear) {
    $face_descriptors = null;
    $face_descriptors_provided = true;
} elseif ($face_descriptors_raw !== '') {
    $decoded = json_decode($face_descriptors_raw, true);
    if (is_array($decoded) && !empty($decoded)) {
        $face_descriptors = $face_descriptors_raw;
        $face_descriptors_provided = true;
    }
}

// Verifica se a coluna hourly_rate existe
$hasHourlyRate = false;
try {
  $pdo->query("SELECT hourly_rate FROM teachers LIMIT 1");
  $hasHourlyRate = true;
} catch (PDOException $e) {
  // Coluna não existe
}

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
      $faceExpr = $face_descriptors_provided ? '?' : 'face_descriptors';
      if ($hasHourlyRate) {
          $sql = "UPDATE teachers SET name=?, email=?, type_id=?, base_salary=?, hourly_rate=?, network_wide=?, face_descriptors={$faceExpr} WHERE id=?";
          $params = [$name, $email, $type_id, $base_salary, $hourly_rate, $network_wide];
          if ($face_descriptors_provided) $params[] = $face_descriptors;
          $params[] = $id;
      } else {
          $sql = "UPDATE teachers SET name=?, email=?, type_id=?, base_salary=?, network_wide=?, face_descriptors={$faceExpr} WHERE id=?";
          $params = [$name, $email, $type_id, $base_salary, $network_wide];
          if ($face_descriptors_provided) $params[] = $face_descriptors;
          $params[] = $id;
      }
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      audit_log('update','teacher',$id,['name'=>$name,'type_id'=>$type_id,'base_salary'=>$base_salary,'network_wide'=>$network_wide]);
  } else {
      $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
      if ($hasHourlyRate) {
          $stmt = $pdo->prepare("INSERT INTO teachers (name, cpf, email, pin_hash, active, type_id, base_salary, hourly_rate, network_wide, face_descriptors) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)");
          $stmt->execute([$name, $cpf, $email, $pin_hash, $type_id, $base_salary, $hourly_rate, $network_wide, $face_descriptors]);
      } else {
          $stmt = $pdo->prepare("INSERT INTO teachers (name, cpf, email, pin_hash, active, type_id, base_salary, network_wide, face_descriptors) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)");
          $stmt->execute([$name, $cpf, $email, $pin_hash, $type_id, $base_salary, $network_wide, $face_descriptors]);
      }
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

  // Salvar atribuições de períodos de aula (grade horária)
  $periodAssignments = $_POST['period_assignments'] ?? [];
  
  // Primeiro, remove todas as atribuições existentes
  $pdo->prepare("DELETE FROM teacher_class_assignments WHERE teacher_id = ?")->execute([$id]);
  
  // Depois, insere as novas atribuições
  if (is_array($periodAssignments) && !empty($periodAssignments)) {
      $ins = $pdo->prepare("INSERT INTO teacher_class_assignments (teacher_id, weekday, period_id) VALUES (?, ?, ?)");
      foreach ($periodAssignments as $weekday => $periods) {
          if (is_array($periods)) {
              foreach ($periods as $periodId) {
                  $ins->execute([$id, (int)$weekday, (int)$periodId]);
              }
          }
      }
      audit_log('update', 'teacher_class_assignments', $id, ['count' => count($periodAssignments, COUNT_RECURSIVE) - count($periodAssignments)]);
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
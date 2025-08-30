<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status'=>'error','message'=>'Método não permitido']); exit;
}
csrf_verify();

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'Formato inválido. Esperado {"items":[...]}']); exit;
}

$pdo = db();
$tzBR = new DateTimeZone('America/Sao_Paulo');

function process_check_item(PDO $pdo, array $item, DateTimeZone $tzBR): array {
  $cpf = preg_replace('/\D/', '', (string)($item['cpf'] ?? ''));
  $pin = (string)($item['pin'] ?? '');
  $photo = $item['photo'] ?? null;
  $geo = $item['geo'] ?? null;

  if (!$pin || !$photo) {
    return ['status'=>'error','message'=>'PIN e foto são obrigatórios.'];
  }

  // Resolve colaborador
  $prof = null;
  if ($cpf) {
    $stmt = $pdo->prepare("SELECT id, name, pin_hash, active FROM teachers WHERE cpf = ?");
    $stmt->execute([$cpf]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['active'] === 1 && password_verify($pin, $row['pin_hash'])) {
      $prof = $row;
    }
  } else {
    $stmt = $pdo->query("SELECT id, name, pin_hash, active FROM teachers WHERE active = 1");
    $matches = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if (password_verify($pin, $row['pin_hash'])) {
        $matches[] = $row;
        if (count($matches) > 1) break;
      }
    }
    if (count($matches) === 1) $prof = $matches[0];
  }
  if (!$prof) return ['status'=>'error','message'=>'PIN inválido ou colaborador inativo.'];

  $teacherId = (int)$prof['id'];
  $today = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d');
  $now = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d H:i:s');
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
  $lng = isset($geo['lng']) ? (float)$geo['lng'] : null;
  $acc = isset($geo['acc']) ? (float)$geo['acc'] : null;

  // Já tem ponto aberto?
  $stmt = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
  $stmt->execute([$teacherId, $today]);
  $open = $stmt->fetch(PDO::FETCH_ASSOC);
  $action = $open ? 'saída' : 'entrada';

  // Modo do colaborador
  $stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id=t.type_id WHERE t.id=?");
  $stMode->execute([$teacherId]);
  $mode = $stMode->fetchColumn() ?: 'classes';

  // Validação por modo (apenas para entrada)
  if ($action === 'entrada') {
    $weekday = (int)(new DateTimeImmutable('now', $tzBR))->format('w');
    if ($mode === 'classes') {
      $stmt = $pdo->prepare("SELECT classes_count FROM teacher_schedules WHERE teacher_id = ? AND weekday = ?");
      $stmt->execute([$teacherId, $weekday]);
      $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$schedule || (int)$schedule['classes_count'] <= 0) {
        return ['status'=>'error','message'=>'Não há rotina prevista para você neste dia.'];
      }
    } elseif ($mode === 'time') {
      $stmt = $pdo->prepare("SELECT start_time, end_time FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
      $stmt->execute([$teacherId, $weekday]);
      $ts = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$ts || empty($ts['start_time']) || empty($ts['end_time'])) {
        return ['status'=>'error','message'=>'Não há jornada prevista para você neste dia.'];
      }
    } else {
      return ['status'=>'error','message'=>'Não há rotina configurada para seu perfil.'];
    }
  }

  // Salva foto (diretório público é /public/photos)
  $dir = __DIR__ . '/../public/photos/';
  if (!is_dir($dir)) mkdir($dir, 0777, true);
  $filename = 'foto_' . $teacherId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
  if (preg_match('#^data:image/[^;]+;base64,(.+)$#', (string)$photo, $m)) {
      file_put_contents($dir . $filename, base64_decode($m[1]));
  } else {
      return ['status'=>'error','message'=>'Foto inválida.'];
  }

  if ($action === 'entrada') {
    $stmt = $pdo->prepare("INSERT INTO attendance
      (teacher_id, date, check_in, method, ip, user_agent, check_in_lat, check_in_lng, check_in_acc, photo)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$teacherId, $today, $now, 'pin', $ip, $ua, $lat, $lng, $acc, $filename]);
    audit_log('create','attendance',$pdo->lastInsertId(),['teacher_id'=>$teacherId,'type'=>'checkin','bulk'=>true]);
    return ['status'=>'ok','action'=>'entrada','time'=>$now,'photo'=>'/public/photos/'.$filename,'teacher'=>['id'=>$teacherId,'name'=>$prof['name']]];
  } else {
    // helper para aceitar "HH:MM" e "HH:MM:SS"
    $parseTime = static function (?string $str): ?DateTime {
        if (!$str) return null;
        $str = trim($str);
        $fmt = strlen($str) === 5 ? 'H:i' : 'H:i:s';
        return DateTime::createFromFormat($fmt, $str) ?: null;
    };

    // fechar saída + banco de horas (igual checkin.php)
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("UPDATE attendance
          SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP, photo = ?
          WHERE id = ?");
      $stmt->execute([$now, $lat, $lng, $acc, $filename, $open['id']]);

      // Banco de horas
      $tolerance = (int)(get_setting('tolerance_minutes', '5') ?? '5');
      $weekday = (int)(new DateTimeImmutable($today, $tzBR))->format('w');
      $expMin = 0;

      if ($mode === 'classes') {
        $st = $pdo->prepare("SELECT classes_count, class_minutes FROM teacher_schedules WHERE teacher_id=? AND weekday=?");
        $st->execute([$teacherId, $weekday]);
        if ($sc = $st->fetch(PDO::FETCH_ASSOC)) $expMin = (int)$sc['classes_count'] * (int)$sc['class_minutes'];
      } elseif ($mode === 'time') {
        $st = $pdo->prepare("SELECT start_time, end_time, break_minutes FROM collaborator_time_schedules WHERE teacher_id=? AND weekday=?");
        $st->execute([$teacherId, $weekday]);
        if ($ts = $st->fetch(PDO::FETCH_ASSOC)) {
          if (!empty($ts['start_time']) && !empty($ts['end_time'])) {
            $s = $parseTime($ts['start_time']); $e = $parseTime($ts['end_time']);
            if ($s && $e) {
              if ($e <= $s) $e = (clone $e)->modify('+1 day');
              $expMin = max(0, (int)(($e->getTimestamp()-$s->getTimestamp())/60) - (int)($ts['break_minutes'] ?? 0));
            }
          }
        }
      }

      $stL = $pdo->prepare("SELECT 1 FROM leaves l JOIN leave_types lt ON lt.id=l.type_id WHERE l.teacher_id=? AND l.approved=1 AND lt.paid=1 AND ? BETWEEN l.start_date AND l.end_date LIMIT 1");
      $stL->execute([$teacherId, $today]);
      if ($stL->fetchColumn()) $expMin = 0;

      $stW = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE teacher_id=? AND date=? AND check_in IS NOT NULL AND check_out IS NOT NULL");
      $stW->execute([$teacherId, $today]);
      $worked = 0;
      while ($r = $stW->fetch(PDO::FETCH_ASSOC)) {
        $ci = new DateTime($r['check_in']); $co = new DateTime($r['check_out']);
        if ($co > $ci) $worked += (int) floor(($co->getTimestamp() - $ci->getTimestamp())/60);
      }
      $delta = $worked - $expMin;
      if (abs($delta) <= $tolerance) $delta = 0;

      $pdo->prepare("DELETE FROM hour_bank_entries WHERE teacher_id=? AND date=? AND source='auto'")->execute([$teacherId, $today]);
      $insHb = $pdo->prepare("INSERT INTO hour_bank_entries (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id, created_by_admin_id) VALUES (?, NULL, ?, ?, ?, 'auto', ?, NULL)");
      $insHb->execute([$teacherId, $today, $delta, 'Recalculo diário automático', $open['id']]);

      $pdo->commit();
      audit_log('update','attendance',$open['id'],['teacher_id'=>$teacherId,'type'=>'checkout','delta'=>$delta,'bulk'=>true]);

      return ['status'=>'ok','action'=>'saída','time'=>$now,'photo'=>'/public/photos/'.$filename,'teacher'=>['id'=>$teacherId,'name'=>$prof['name']]];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      return ['status'=>'error','message'=>'Falha ao fechar ponto.'];
    }
  }
}

$results = [];
foreach ($payload['items'] as $idx => $item) {
  try {
    $results[] = ['index'=>$idx, 'response'=>process_check_item($pdo, $item, $tzBR)];
  } catch (Throwable $e) {
    $results[] = ['index'=>$idx, 'response'=>['status'=>'error','message'=>$e->getMessage()]];
  }
}

echo json_encode(['status'=>'ok','results'=>$results]);
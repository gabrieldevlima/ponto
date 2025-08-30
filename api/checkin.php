<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json');

$tzBR = new DateTimeZone('America/Sao_Paulo');

/**
 * Avalia a qualidade de uma foto usando GD (iluminação, contraste e nitidez).
 * Retorna:
 *  - ok: bool (true se a qualidade é aceitável)
 *  - reasons: string[] (motivos quando não ok)
 *  - metrics: array (métricas úteis para depuração)
 */
function analyzePhotoQuality(string $filepath): array
{
    $reasons = [];
    $metrics = [
        'width' => null,
        'height' => null,
        'filesize' => null,
        'brightness_avg' => null,
        'brightness_std' => null,
        'laplacian_var' => null,
    ];

    if (!is_file($filepath)) {
        return ['ok' => false, 'reasons' => ['Arquivo de foto não encontrado'], 'metrics' => $metrics];
    }
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return ['ok' => false, 'reasons' => ['Extensão GD não disponível para avaliar a foto'], 'metrics' => $metrics];
    }

    $size = @getimagesize($filepath);
    if (!$size) {
        return ['ok' => false, 'reasons' => ['Arquivo de imagem inválido'], 'metrics' => $metrics];
    }
    [$width, $height] = $size;
    $filesize = @filesize($filepath) ?: 0;

    $metrics['width'] = $width;
    $metrics['height'] = $height;
    $metrics['filesize'] = $filesize;

    // Regras mínimas de qualidade
    $minWidth  = 320;
    $minHeight = 320;
    $minFilesize = 10 * 1024; // 10 KB

    if ($width < $minWidth || $height < $minHeight) {
        $reasons[] = 'Resolução muito baixa';
    }
    if ($filesize > 0 && $filesize < $minFilesize) {
        $reasons[] = 'Arquivo muito comprimido/pequeno';
    }

    $data = @file_get_contents($filepath);
    if ($data === false) {
        return ['ok' => false, 'reasons' => ['Falha ao ler a foto'], 'metrics' => $metrics];
    }
    $img = @imagecreatefromstring($data);
    if (!$img) {
        return ['ok' => false, 'reasons' => ['Falha ao abrir a foto'], 'metrics' => $metrics];
    }

    // Redimensiona para acelerar a análise
    $targetMax = 256;
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, $targetMax / max($w, $h));
    $rw = max(1, (int)floor($w * $scale));
    $rh = max(1, (int)floor($h * $scale));
    $res = imagecreatetruecolor($rw, $rh);
    imagecopyresampled($res, $img, 0, 0, 0, 0, $rw, $rh, $w, $h);
    imagedestroy($img);

    // Métricas de luminância e nitidez (variância do Laplaciano)
    $sum = 0.0; $sum2 = 0.0; $n = 0;
    $lapSum = 0.0; $lapSum2 = 0.0; $lapN = 0;

    // Função para cinza (0..255)
    $grayAt = static function ($im, int $x, int $y): float {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        // ITU-R BT.601 luma
        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    };

    for ($yy = 0; $yy < $rh; $yy++) {
        for ($xx = 0; $xx < $rw; $xx++) {
            $g = $grayAt($res, $xx, $yy);
            $sum += $g;
            $sum2 += $g * $g;
            $n++;
        }
    }

    // Laplaciano simples: 4*C - L - R - T - B
    if ($rw >= 3 && $rh >= 3) {
        for ($yy = 1; $yy < $rh - 1; $yy++) {
            for ($xx = 1; $xx < $rw - 1; $xx++) {
                $c = $grayAt($res, $xx, $yy);
                $l = $grayAt($res, $xx - 1, $yy);
                $r = $grayAt($res, $xx + 1, $yy);
                $t = $grayAt($res, $xx, $yy - 1);
                $b = $grayAt($res, $xx, $yy + 1);
                $lap = 4 * $c - $l - $r - $t - $b;
                $lapSum += $lap;
                $lapSum2 += $lap * $lap;
                $lapN++;
            }
        }
    }
    imagedestroy($res);

    if ($n > 0) {
        $mean = $sum / $n; // 0..255
        $var = max(0.0, ($sum2 / $n) - ($mean * $mean));
        $std = sqrt($var);
        $metrics['brightness_avg'] = $mean;
        $metrics['brightness_std'] = $std;

        // Faixa de exposição razoável
        if ($mean < 70) $reasons[] = 'Foto muito escura';
        if ($mean > 200) $reasons[] = 'Foto muito clara/estourada';

        // Contraste mínimo
        if ($std < 20) $reasons[] = 'Baixo contraste';
    }

    if ($lapN > 0) {
        $lapMean = $lapSum / $lapN;
        $lapVar = max(0.0, ($lapSum2 / $lapN) - ($lapMean * $lapMean));
        $metrics['laplacian_var'] = $lapVar;

        // Limite empírico para nitidez (quanto maior, mais nítido)
        if ($lapVar < 80) $reasons[] = 'Foto desfocada/sem nitidez';
    }

    // Orientação (apenas sugestão, não reprova)
    if ($height > $width && ($width / max(1, $height)) < 0.6) {
        // retrato bem vertical, ok; não adiciona motivo
    }

    $ok = count($reasons) === 0;

    return ['ok' => $ok, 'reasons' => $reasons, 'metrics' => $metrics];
}

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

$cpf = preg_replace('/\D/', '', (string)($input['cpf'] ?? ''));
$pin = (string)($input['pin'] ?? '');
$photo = $input['photo'] ?? null;
$photos = $input['photos'] ?? null; // New: array of photos
$face_descriptors = $input['face_descriptors'] ?? null; // New: array of face descriptors
$geo = $input['geo'] ?? null;

if (!$pin) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'PIN é obrigatório.']);
    exit;
}

$pdo = db();

// Resolve o colaborador
$prof = null;
if ($cpf) {
    $stmt = $pdo->prepare("SELECT id, name, pin_hash, active FROM teachers WHERE cpf = ?");
    $stmt->execute([$cpf]);
    $row = $stmt->fetch();
    if ($row && (int)$row['active'] === 1 && password_verify($pin, $row['pin_hash'])) {
        $prof = $row;
    }
} else {
    $stmt = $pdo->query("SELECT id, name, pin_hash, active FROM teachers WHERE active = 1");
    $matches = [];
    while ($row = $stmt->fetch()) {
        if (password_verify($pin, $row['pin_hash'])) {
            $matches[] = $row;
            if (count($matches) > 1) break;
        }
    }
    if (count($matches) === 1) {
        $prof = $matches[0];
    } elseif (count($matches) > 1) {
        http_response_code(409);
        echo json_encode(['status'=>'error','message'=>'PIN duplicado para mais de um colaborador. Contate o administrador.']);
        exit;
    }
}
if (!$prof) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'PIN inválido ou colaborador inativo.']);
    exit;
}

$teacherId = (int)$prof['id'];
$today = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d');
$now = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
$lng = isset($geo['lng']) ? (float)$geo['lng'] : null;
$acc = isset($geo['acc']) ? (float)$geo['acc'] : null;

// Verifica se há ponto aberto hoje
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
$stmt->execute([$teacherId, $today]);
$open = $stmt->fetch();
$action = $open ? 'saída' : 'entrada';

// Descobre o modo de agenda do colaborador
$stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
$stMode->execute([$teacherId]);
$mode = $stMode->fetchColumn() ?: 'classes';

// Validação de rotina por modo, somente para entrada
if ($action === 'entrada') {
    $weekday = (int)(new DateTimeImmutable('now', $tzBR))->format('w');
    if ($mode === 'classes') {
        $stmt = $pdo->prepare("SELECT classes_count FROM teacher_schedules WHERE teacher_id = ? AND weekday = ?");
        $stmt->execute([$teacherId, $weekday]);
        $schedule = $stmt->fetch();
        if (!$schedule || (int)$schedule['classes_count'] <= 0) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Não há rotina prevista para você neste dia. Registro de ponto não permitido.']);
            exit;
        }
    } elseif ($mode === 'time') {
        $stmt = $pdo->prepare("SELECT start_time, end_time FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
        $stmt->execute([$teacherId, $weekday]);
        $ts = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ts || empty($ts['start_time']) || empty($ts['end_time'])) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Não há jornada prevista para você neste dia. Registro de ponto não permitido.']);
            exit;
        }
        // Se quiser bloquear entrada fora da janela prevista, pode-se validar horário atual aqui (opcional).
    } else { // 'none' ou desconhecido
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Não há rotina configurada para seu perfil. Contate o administrador.']);
        exit;
    }
}

// Salva foto(s) (opcional; diretório público é /public/photos)
$dir = __DIR__ . '/../public/photos/';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$filename = null;
$photoUrls = [];
$photoQuality = null;
$photoQualityOk = null;

// Handle multiple photos if provided, otherwise fallback to single photo
$photosToProcess = [];
if (is_array($photos) && !empty($photos)) {
    $photosToProcess = array_slice($photos, 0, 5); // Limit to 5 photos max
} else if ($photo) {
    $photosToProcess = [$photo];
}

$savedPhotos = [];
$bestQuality = null;
foreach ($photosToProcess as $index => $photoData) {
    if ($photoData && preg_match('#^data:image/[^;]+;base64,(.+)$#', (string)$photoData, $m)) {
        $currentFilename = 'foto_' . $teacherId . '_' . date('Ymd_His') . '_' . $index . '_' . bin2hex(random_bytes(3)) . '.jpg';
        $filepath = $dir . $currentFilename;
        file_put_contents($filepath, base64_decode($m[1]));
        
        $savedPhotos[] = $currentFilename;
        $photoUrls[] = '/public/photos/' . $currentFilename;
        
        // Analyze quality and keep track of the best one
        $currentQuality = analyzePhotoQuality($filepath);
        if ($bestQuality === null || ($currentQuality['ok'] && !$bestQuality['ok'])) {
            $bestQuality = $currentQuality;
            $filename = $currentFilename; // Use best quality as primary photo
            $photoQuality = $currentQuality;
            $photoQualityOk = $currentQuality['ok'] ?? false;
        }
    }
}

if (!empty($photosToProcess) && empty($savedPhotos)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Foto(s) inválida(s).']);
    exit;
}

$photoUrl = $filename ? '/public/photos/' . $filename : null;

// Aprovação automática somente se houver foto, localização e boa qualidade; senão fica pendente (NULL)
$hasPhoto = (bool)$filename;
$hasGeo = ($lat !== null && $lng !== null);
$approvedNow = ($hasPhoto && $hasGeo && ($photoQualityOk !== false)) ? 1 : null;

if ($action === 'entrada') {
    if ($open) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Você já registrou uma entrada hoje e ainda não registrou a saída.']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO attendance
        (teacher_id, date, check_in, method, ip, user_agent, check_in_lat, check_in_lng, check_in_acc, photo, approved)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$teacherId, $today, $now, 'pin', $ip, $ua, $lat, $lng, $acc, $filename, $approvedNow]);
    audit_log('create','attendance',$pdo->lastInsertId(),['teacher_id'=>$teacherId,'type'=>'checkin']);

    $msg = 'Entrada registrada';
    if ($hasPhoto) {
        if (count($savedPhotos) > 1) {
            $msg .= ' com ' . count($savedPhotos) . ' fotos';
        } else {
            $msg .= ' com foto';
        }
    }
    if ($hasGeo) $msg .= ' e localização';
    if (is_array($face_descriptors) && !empty($face_descriptors)) {
        $msg .= ' (com reconhecimento facial)';
    }
    if ($approvedNow === 1) {
        $msg .= '!';
    } else {
        // Se a foto existe mas foi reprovada, deixa explícito
        if ($hasPhoto && $photoQualityOk === false) {
            $msg .= ' (foto de baixa qualidade; pendente de aprovação).';
        } else {
            $msg .= ' (aguardando aprovação).';
        }
    }

    echo json_encode([
        'status'=>'ok',
        'collaborator'=>['id'=>$teacherId,'name'=>$prof['name']],
        'teacher'=>['id'=>$teacherId,'name'=>$prof['name']],
        'action'=>'entrada',
        'time'=>$now,
        'photo'=>$photoUrl,
        'message'=>$msg
    ]);
    exit;
} else { // saída
    if (!$open) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Não há entrada aberta hoje. Registre a entrada antes da saída.']);
        exit;
    }

    // helper para aceitar "HH:MM" e "HH:MM:SS"
    $parseTime = static function (?string $str): ?DateTime {
        if (!$str) return null;
        $str = trim($str);
        $fmt = strlen($str) === 5 ? 'H:i' : 'H:i:s';
        return DateTime::createFromFormat($fmt, $str) ?: null;
    };

    $pdo->beginTransaction();
    try {
      // Atualiza checkout; só substitui a foto se uma nova foi enviada
      $photoSetSql = $filename ? "photo = ?, " : "";
      $sql = "UPDATE attendance
              SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP, {$photoSetSql}
                  approved = CASE WHEN ? = 1 THEN 1 ELSE approved END
              WHERE id = ?";
      $params = [$now, $lat, $lng, $acc];
      if ($filename) $params[] = $filename;
      $params[] = $approvedNow;
      $params[] = $open['id'];
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);

      // Banco de horas automático (recalcula o delta do dia)
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

      // Leaves pagas => expected = 0
      $stL = $pdo->prepare("SELECT 1 FROM leaves l JOIN leave_types lt ON lt.id=l.type_id WHERE l.teacher_id=? AND l.approved=1 AND lt.paid=1 AND ? BETWEEN l.start_date AND l.end_date LIMIT 1");
      $stL->execute([$teacherId, $today]);
      if ($stL->fetchColumn()) $expMin = 0;

      // Total worked no dia (somar todos os atendimentos fechados do dia)
      $stW = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE teacher_id=? AND date=? AND check_in IS NOT NULL AND check_out IS NOT NULL");
      $stW->execute([$teacherId, $today]);
      $worked = 0;
      while ($r = $stW->fetch(PDO::FETCH_ASSOC)) {
        $ci = new DateTime($r['check_in']); $co = new DateTime($r['check_out']);
        if ($co > $ci) $worked += (int) floor(($co->getTimestamp() - $ci->getTimestamp())/60);
      }
      $delta = $worked - $expMin;
      if (abs($delta) <= $tolerance) $delta = 0;

      // Recria o lançamento 'auto' do dia (evita duplicidade)
      $pdo->prepare("DELETE FROM hour_bank_entries WHERE teacher_id=? AND date=? AND source='auto'")->execute([$teacherId, $today]);
      $insHb = $pdo->prepare("INSERT INTO hour_bank_entries (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id, created_by_admin_id) VALUES (?, NULL, ?, ?, ?, 'auto', ?, NULL)");
      $insHb->execute([$teacherId, $today, $delta, 'Recalculo diário automático', $open['id']]);

      $pdo->commit();
      audit_log('update','attendance',$open['id'],['teacher_id'=>$teacherId,'type'=>'checkout','delta'=>$delta]);

      $msg = 'Saída registrada';
      if ($hasPhoto) {
          if (count($savedPhotos) > 1) {
              $msg .= ' com ' . count($savedPhotos) . ' fotos';
          } else {
              $msg .= ' com foto';
          }
      }
      if ($hasGeo) $msg .= ' e localização';
      if (is_array($face_descriptors) && !empty($face_descriptors)) {
          $msg .= ' (com reconhecimento facial)';
      }
      if ($approvedNow === 1) {
          $msg .= '!';
      } else {
          if ($hasPhoto && $photoQualityOk === false) {
              $msg .= ' (foto de baixa qualidade; pendente de aprovação).';
          } else {
              $msg .= ' (aguardando aprovação).';
          }
      }

      echo json_encode([
          'status'=>'ok',
          'collaborator'=>['id'=>$teacherId,'name'=>$prof['name']],
          'teacher'=>['id'=>$teacherId,'name'=>$prof['name']],
          'action'=>'saída',
          'time'=>$now,
          'photo'=>$photoUrl,
          'message'=>$msg
      ]);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      http_response_code(500);
      echo json_encode(['status'=>'error','message'=>'Falha ao fechar ponto.']);
      exit;
    }
}

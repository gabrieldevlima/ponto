<?php
// Desabilita TODOS os outputs que não sejam JSON
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0); // Desabilita completamente em produção para não corromper JSON

// Limpa qualquer output buffer anterior
if (ob_get_level()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../config.php';

// Define headers ANTES de qualquer output
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

$tzBR = new DateTimeZone('America/Sao_Paulo');

function api_error(int $httpCode, string $code, string $message, array $hints = [], ?string $field = null): void {
    // Limpa qualquer output anterior
    if (ob_get_level()) ob_clean();
    
    http_response_code($httpCode);
    echo json_encode([
        'status'  => 'error',
        'code'    => $code,
        'message' => $message,
        'hints'   => $hints,
        'field'   => $field
    ], JSON_UNESCAPED_UNICODE);
    
    // Força flush e termina
    if (ob_get_level()) ob_end_flush();
    exit;
}

function haversineDistance($lat1, $lon1, $lat2, $lon2): float {
    $R = 6371; // Raio da Terra em km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

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

    $minWidth  = 320;
    $minHeight = 320;
    $minFilesize = 10 * 1024;

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

    $targetMax = 256;
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, $targetMax / max($w, $h));
    $rw = max(1, (int)floor($w * $scale));
    $rh = max(1, (int)floor($h * $scale));
    $res = imagecreatetruecolor($rw, $rh);
    imagecopyresampled($res, $img, 0, 0, 0, 0, $rw, $rh, $w, $h);
    imagedestroy($img);

    $sum = 0.0; $sum2 = 0.0; $n = 0;
    $lapSum = 0.0; $lapSum2 = 0.0; $lapN = 0;

    $grayAt = static function ($im, int $x, int $y): float {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
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
        $mean = $sum / $n;
        $var = max(0.0, ($sum2 / $n) - ($mean * $mean));
        $std = sqrt($var);
        $metrics['brightness_avg'] = $mean;
        $metrics['brightness_std'] = $std;

        if ($mean < 70) $reasons[] = 'Escura';
        if ($mean > 200) $reasons[] = 'Clara';
        if ($std < 20) $reasons[] = 'Sem contraste';
    }

    if ($lapN > 0) {
        $lapMean = $lapSum / $lapN;
        $lapVar = max(0.0, ($lapSum2 / $lapN) - ($lapMean * $lapMean));
        $metrics['laplacian_var'] = $lapVar;
        if ($lapVar < 80) $reasons[] = 'Desfocada';
    }

    $ok = count($reasons) === 0;

    return ['ok' => $ok, 'reasons' => $reasons, 'metrics' => $metrics];
}

// DIAGNÓSTICO: Medir tempo de cada etapa
$debug_times = [];
$debug_start = microtime(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'method_not_allowed', 'Método não permitido.', []);
}
csrf_verify();
$debug_times['csrf_verify'] = round((microtime(true) - $debug_start) * 1000, 2);

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
$debug_times['parse_input'] = round((microtime(true) - $debug_start) * 1000, 2);
if (!is_array($input)) {
    api_error(400, 'invalid_json', 'JSON inválido na requisição.', []);
}

$cpf   = preg_replace('/\D/', '', (string)($input['cpf'] ?? ''));
$pin   = (string)($input['pin'] ?? '');
$photo = $input['photo'] ?? null;
$geo   = $input['geo'] ?? null;
$previewMode = !empty($input['preview']);
$faceDescriptor = $input['face_descriptor'] ?? null;
$faceAuthMode = is_array($faceDescriptor) && count($faceDescriptor) === 128;

if (!$pin && !$faceAuthMode) {
    api_error(400, 'pin_required', 'PIN é obrigatório.', ['Informe os 6 números do seu PIN.'], 'pin');
}

$pdo = db();

// Helper: distância euclidiana para comparação facial server-side
function face_euclidean_distance(array $a, array $b): float {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $diff = (float)$a[$i] - (float)$b[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

// Resolve o colaborador com mensagens claras
$prof = null;

// PATH 1: Autenticação por reconhecimento facial (sem PIN)
if ($faceAuthMode) {
    $stmt = $pdo->query("
        SELECT id, name, pin_hash, active, network_wide, cpf, face_descriptors
        FROM teachers
        WHERE active = 1 AND face_descriptors IS NOT NULL AND face_descriptors != ''
    ");
    $bestMatch = null;
    $bestDist = PHP_FLOAT_MAX;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stored = json_decode($row['face_descriptors'], true);
        if (!is_array($stored)) continue;
        foreach ($stored as $ref) {
            if (!is_array($ref) || count($ref) !== 128) continue;
            $dist = face_euclidean_distance($faceDescriptor, $ref);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestMatch = $row;
            }
        }
    }
    if ($bestMatch && $bestDist < 0.6) {
        $prof = $bestMatch;
    } else {
        api_error(401, 'face_not_recognized', 'Rosto não identificado.', [
            'Não foi possível confirmar sua identidade pelo rosto.',
            'Use o PIN para registrar o ponto.'
        ], 'face');
    }
} elseif ($cpf) {
$stmt = $pdo->prepare("SELECT id, name, pin_hash, active, network_wide, cpf, face_descriptors FROM teachers WHERE cpf = ? LIMIT 1");
    $stmt->execute([$cpf]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        api_error(401, 'pin_invalid', 'PIN incorreto ou colaborador não encontrado.', [
            'Verifique se digitou o PIN corretamente.',
            'Se não souber seu PIN, procure o Admin/RH.'
        ], 'pin');
    }

    if ((int)$row['active'] !== 1) {
        api_error(401, 'collaborator_inactive', 'Colaborador inativo.', [
            'Fale com o Admin/RH para reativar seu cadastro.'
        ], 'pin');
    }

    if (!password_verify($pin, $row['pin_hash'])) {
        api_error(401, 'pin_invalid', 'PIN incorreto.', [
            'Confirme os 6 números informados.',
            'Se esqueceu seu PIN, solicite ao Admin/RH.'
        ], 'pin');
    }

    $prof = $row;
    $debug_times['pin_verify_cpf'] = round((microtime(true) - $debug_start) * 1000, 2);
} else {
    // Sem CPF: valida por PIN entre ativos
    // OTIMIZAÇÃO CACHE: Tenta usar cached_teacher_id primeiro (< 50ms ao invés de 2-10s!)
    $pin_verify_start = microtime(true);
    $prof = null;
    $cachedTeacherId = isset($input['cached_teacher_id']) ? (int)$input['cached_teacher_id'] : 0;
    
    // FAST PATH: Se há cache, tenta validar direto
    if ($cachedTeacherId > 0) {
        $stmt = $pdo->prepare("SELECT id, name, pin_hash, active, network_wide, cpf, face_descriptors FROM teachers WHERE id = ? AND active = 1 LIMIT 1");
        $stmt->execute([$cachedTeacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && password_verify($pin, $row['pin_hash'])) {
            // Cache hit! Encontrado em < 50ms
            $prof = $row;
            $debug_times['pin_verify_cached'] = round((microtime(true) - $pin_verify_start) * 1000, 2);
            $debug_times['cache_hit'] = true;
        }
    }
    
    // SLOW PATH: Se cache falhou, faz busca completa
    if (!$prof) {
        $debug_times['cache_hit'] = false;
        $stmt = $pdo->query("
            SELECT id, name, pin_hash, active, network_wide, cpf, face_descriptors 
            FROM teachers 
            WHERE active = 1 
            ORDER BY id DESC
        ");
        $matchesActive = [];
        $extraChecks = 0;
        $maxExtraChecks = 10;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (count($matchesActive) === 1) {
                $extraChecks++;
                if ($extraChecks > $maxExtraChecks) break;
            }
            
            if (!password_verify($pin, $row['pin_hash'])) continue;
            $matchesActive[] = $row;
            if (count($matchesActive) > 1) break;
        }
        $debug_times['pin_verify_loop'] = round((microtime(true) - $pin_verify_start) * 1000, 2);

        if (count($matchesActive) === 1) {
            $prof = $matchesActive[0];
        } elseif (count($matchesActive) > 1) {
            api_error(409, 'pin_duplicate', 'PIN duplicado para mais de um colaborador. Contate o administrador.', [
                'Por segurança, não é possível prosseguir.',
                'Peça ao Admin/RH para atualizar seu PIN.'
            ], 'pin');
        } else {
            // Verifica inativos
            $stmtInactive = $pdo->query("SELECT id, pin_hash FROM teachers WHERE active = 0");
            $hasInactive = false;
            while ($rowInactive = $stmtInactive->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($pin, $rowInactive['pin_hash'] ?? '')) {
                    $hasInactive = true;
                    break;
                }
            }
            
            if ($hasInactive) {
                api_error(401, 'collaborator_inactive', 'Colaborador inativo.', [
                    'Fale com o Admin/RH para reativar seu cadastro.'
                ], 'pin');
            }
            api_error(401, 'pin_invalid', 'PIN incorreto ou inexistente.', [
                'Confira os 6 números informados.',
                'Se não lembra do PIN, procure o Admin/RH.'
            ], 'pin');
        }
    }
}

$teacherId = (int)$prof['id'];
$today = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d');
$now = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
$lng = isset($geo['lng']) ? (float)$geo['lng'] : null;
$acc = isset($geo['acc']) ? (float)$geo['acc'] : null;

// ============================================================================
// PORTARIA MTP 671/2021 - Campos obrigatórios
// ============================================================================
$recordMode = isset($payload['recordMode']) ? $payload['recordMode'] : 'online';
$recordedAt = isset($payload['recordedAt']) ? $payload['recordedAt'] : $now;
$syncedAt = $recordMode === 'online' ? $now : null;
$hlbSyncStatus = 'synced'; // Será atualizado se houver sincronização HLB
$hlbOffsetSeconds = isset($payload['hlbOffsetSeconds']) ? (int)$payload['hlbOffsetSeconds'] : 0;

// ============================================================================
// ANTI-FRAUDE - Validação de Localização
// ============================================================================
$fraudCheck = isset($input['fraudCheck']) ? $input['fraudCheck'] : null;
$deviceFingerprint = isset($input['deviceFingerprint']) ? $input['deviceFingerprint'] : null;

// Análise de fraude
$fraudRiskLevel = 0;
$gpsMockDetected = 0;
$fraudDetections = [];

if ($fraudCheck && is_array($fraudCheck)) {
    $fraudRiskLevel = isset($fraudCheck['riskLevel']) ? (int)$fraudCheck['riskLevel'] : 0;
    $gpsMockDetected = isset($fraudCheck['mockDetected']) && $fraudCheck['mockDetected'] ? 1 : 0;
    
    if (!empty($fraudCheck['indicators'])) {
        foreach ($fraudCheck['indicators'] as $indicator) {
            $fraudDetections[] = [
                'type' => $indicator,
                'risk_level' => $fraudRiskLevel
            ];
        }
    }
}

// Validação adicional backend: distância impossível entre check-in e check-out
if ($action === 'saida' && $open) {
    $lastLat = $open['check_in_lat'];
    $lastLng = $open['check_in_lng'];
    $lastTime = strtotime($open['check_in']);
    
    if ($lastLat && $lastLng && $lat && $lng) {
        $distance = haversineDistance($lastLat, $lastLng, $lat, $lng);
        $timeElapsedMin = (time() - $lastTime) / 60;
        
        if ($timeElapsedMin > 0) {
            $speedKmPerMin = $distance / $timeElapsedMin;
            // Se velocidade > 1.5 km/min (90 km/h), suspeito
            if ($speedKmPerMin > 1.5) {
                $fraudRiskLevel = max($fraudRiskLevel, 2);
                $fraudDetections[] = [
                    'type' => 'impossible_distance_backend',
                    'risk_level' => 2,
                    'details' => [
                        'distance_km' => round($distance, 2),
                        'time_minutes' => round($timeElapsedMin, 2),
                        'speed_kmh' => round($speedKmPerMin * 60, 2)
                    ]
                ];
            }
        }
    }
}

// Identificador do dispositivo (user agent + IP hash)
$deviceIdentifier = substr(hash('sha256', $ua . $ip), 0, 32);

// Descobre o modo de agenda do colaborador
$stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
$stMode->execute([$teacherId]);
$mode = $stMode->fetchColumn() ?: 'classes';

$weekday = (int)(new DateTimeImmutable('now', $tzBR))->format('w');
$currentTime = (new DateTimeImmutable('now', $tzBR))->format('H:i:s');

// Verifica se há ponto aberto hoje (check-in único por dia)
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
$stmt->execute([$teacherId, $today]);
$open = $stmt->fetch(PDO::FETCH_ASSOC);
$action = $open ? 'saída' : 'entrada';

// Verifica se professor usa sistema de grade horária (para pagamento fixo)
$usesPeriodSystem = teacher_uses_period_system($teacherId);

// Variável para marcar candidato a hora extra
$isOvertimeCandidate = 0;
$overtimeJustification = null;
$currentPeriodId = null;

// Validação de rotina por modo, somente para entrada
if ($action === 'entrada') {
    if ($mode === 'classes') {
        $stmt = $pdo->prepare("SELECT classes_count FROM teacher_schedules WHERE teacher_id = ? AND weekday = ?");
        $stmt->execute([$teacherId, $weekday]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$schedule || (int)$schedule['classes_count'] <= 0) {
            // Sem aulas regulares hoje - pode ser dia especial/evento
            // Permite check-in mas marca como overtime candidate
            $isOvertimeCandidate = 1;
            $overtimeJustification = isset($data['overtime_justification']) ? trim($data['overtime_justification']) : null;
        }
    } elseif ($mode === 'time') {
        $stmt = $pdo->prepare("SELECT start_time, end_time FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
        $stmt->execute([$teacherId, $weekday]);
        $ts = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ts || empty($ts['start_time']) || empty($ts['end_time'])) {
            api_error(400, 'no_schedule_today', 'Não há jornada prevista para você neste dia. Registro de ponto não permitido.', [
                'Peça ao Admin/RH para conferir sua jornada.'
            ]);
        }
    } else { // 'none' ou desconhecido
        api_error(400, 'no_schedule_config', 'Não há rotina configurada para seu perfil. Contate o administrador.', [
            'Admin/RH: configurar agenda/jornada do colaborador.'
        ]);
    }
}

if ($previewMode) {
    $pendingActionKey = $open ? 'out' : 'in';
    $pendingActionLabel = $open ? 'Saída' : 'Entrada';
    $openInfo = null;
    if ($open) {
        try {
            $checkInDt = $open['check_in'] ? new DateTime($open['check_in'], $tzBR) : null;
        } catch (Throwable $e) {
            $checkInDt = null;
        }
        $openInfo = [
            'id' => (int)$open['id'],
            'check_in' => $open['check_in'] ?? null,
            'check_in_time' => $checkInDt ? $checkInDt->format('H:i') : null,
            'date' => $open['date'] ?? $today
        ];
    }
    $previewMessage = $pendingActionKey === 'in'
        ? 'Será registrada uma nova entrada.'
        : 'Será registrada a saída do turno iniciado às ' . ($openInfo['check_in_time'] ?? '—') . '.';

    if (ob_get_level()) ob_clean();
    $faceDescRaw = $prof['face_descriptors'] ?? null;
    $faceDescDecoded = $faceDescRaw ? json_decode($faceDescRaw, true) : null;

    echo json_encode([
        'status' => 'preview',
        'collaborator' => [
            'id' => $teacherId,
            'name' => $prof['name'],
            'cpf' => $prof['cpf'] ?? null
        ],
        'action' => $pendingActionLabel,
        'action_key' => $pendingActionKey,
        'open_record' => $openInfo,
        'overtime_candidate' => (bool)$isOvertimeCandidate,
        'message' => $previewMessage,
        'face_enrolled' => !empty($faceDescDecoded),
        'face_descriptors' => $faceDescDecoded
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Salva foto (opcional; diretório público é /public/photos)
$dir = __DIR__ . '/../public/photos/';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$filename = null;
$photoQuality = null;
$photoQualityOk = null;
if ($photo) {
    if (preg_match('#^data:image/[^;]+;base64,(.+)$#', (string)$photo, $m)) {
        $filename = 'foto_' . $teacherId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
        $filepath = $dir . $filename;
        file_put_contents($filepath, base64_decode($m[1]));

        // OTIMIZAÇÃO: Análise de qualidade DESABILITADA para acelerar registro (de 1-2s para <0.1s)
        // A validação pode ser feita posteriormente de forma assíncrona se necessário
        // Para reabilitar, defina PHOTO_QUALITY_CHECK_ENABLED = true no config.php
        if (defined('PHOTO_QUALITY_CHECK_ENABLED') && PHOTO_QUALITY_CHECK_ENABLED) {
            $photoQuality = analyzePhotoQuality($filepath);
            $photoQualityOk = $photoQuality['ok'] ?? false;
            $photoQualityReasons = $photoQuality['reasons'] ?? [];
        } else {
            // Modo rápido: aceita foto sem análise detalhada
            $photoQualityOk = true;
            $photoQualityReasons = [];
        }
        
        // OTIMIZAÇÃO: Cleanup de fotos DESABILITADO durante registro (executar via cron)
        // Para executar limpeza programada, use um script cron que chama cleanup_old_photos()
        // Isso evita lentidão no momento crítico do registro de ponto
        // if (defined('PHOTO_CLEANUP_ENABLED') && PHOTO_CLEANUP_ENABLED) {
        //     try {
        //         $cleanupStats = cleanup_old_photos($pdo);
        //         if ($cleanupStats['status'] === 'completed') {
        //             error_log(sprintf(
        //                 "Photo cleanup: %d fotos deletadas, %.2f MB liberados",
        //                 $cleanupStats['deleted_count'],
        //                 $cleanupStats['freed_space_mb']
        //             ));
        //         }
        //     } catch (Throwable $e) {
        //         error_log("Photo cleanup error: " . $e->getMessage());
        //     }
        // }
    } else {
        api_error(400, 'photo_invalid', 'Foto inválida.', [
            'Tire uma nova foto com boa iluminação e tente novamente.'
        ]);
    }
} else {
    $photoQualityReasons = [];
}
$photoUrl = $filename ? '/public/photos/' . $filename : null;

// ==============================
// Validação de Geolocalização
// ==============================
$radiusM = (float)((get_setting('geofence_radius_m', '300') ?? '300'));
$maxAccM = 100.0; // se a precisão informada for pior que 100m, deixa pendente
$geoOk = false;
$matchedSchoolId = null;
$distanceM = null;
$geoReasons = [];

if ($lat !== null && $lng !== null) {
    if ($acc !== null && $acc > $maxAccM) {
        $geoReasons[] = 'Precisão de localização insuficiente (>100m)';
    }
    $schools = get_teacher_allowed_schools($pdo, $teacherId);
    if (!$schools) {
        $geoReasons[] = 'Instituição sem geolocalização configurada';
    } else {
        [$inRadius, $sid, $dist] = match_school_by_geo($schools, $lat, $lng, $radiusM);
        $geoOk = ($inRadius === true) && empty($geoReasons);
        $matchedSchoolId = $sid;
        $distanceM = $dist;
        if (!$inRadius) {
            $geoReasons[] = 'Fora do perímetro permitido (>= ' . (int)$radiusM . 'm)';
        }
    }
} else {
    $geoReasons[] = 'Localização não informada';
}

// Verifica se colaborador é network_wide (isento de verificação de localização)
$isNetworkWide = (int)($prof['network_wide'] ?? 0) === 1;

// Se network_wide, não exige localização
if ($isNetworkWide && (!$lat || !$lng)) {
    $lat = null;
    $lng = null;
    $acc = null;
    $geoOk = true; // Considera OK para network_wide
}

// Construir array de motivos de pendência
$pendingReasons = [];

// Verifica reconhecimento facial
$faceMatch = $input['face_match'] ?? null;
if ($faceMatch === false) {
    $pendingReasons[] = 'Rosto não confere';
}

// Verifica foto
if (!$filename || empty($filename)) {
    $pendingReasons[] = 'Sem foto';
} else {
    // Adiciona motivos de qualidade da foto se existirem
    if (!empty($photoQualityReasons)) {
        $pendingReasons = array_merge($pendingReasons, $photoQualityReasons);
    }
}

// Verifica localização (apenas se NÃO for network_wide)
if (!$isNetworkWide && (!$lat || !$lng)) {
    $pendingReasons[] = 'Sem GPS';
}

// Verifica se está fora do raio (apenas se NÃO for network_wide)
if (!$isNetworkWide && !$geoOk && ($lat && $lng)) {
    $pendingReasons[] = 'Fora do raio';
}

$pendingReasonsJson = !empty($pendingReasons) ? json_encode($pendingReasons, JSON_UNESCAPED_UNICODE) : null;

// Debug: Log dos motivos (remover depois)
error_log("DEBUG pending_reasons - Teacher: $teacherId, Network: " . ($isNetworkWide ? 'YES' : 'NO') . ", Reasons: " . ($pendingReasonsJson ?? 'NULL'));

// Aprovação automática: somente se foto+geo presentes e ok, qualidade ok
// Candidatos a hora extra SEMPRE ficam pendentes (null) para aprovação do admin
$hasPhoto = (bool)$filename;
$hasGeo = ($lat !== null && $lng !== null) || $isNetworkWide; // Network_wide não precisa de geo
$approvedNow = ($hasPhoto && $hasGeo && ($photoQualityOk !== false) && $geoOk && !$isOvertimeCandidate) ? 1 : null;

// Entrada
if ($action === 'entrada') {
    if ($open) {
        api_error(400, 'already_checked_in', 'Você já registrou uma entrada hoje e ainda não registrou a saída.', [
            'Finalize com o registro de saída para iniciar um novo ciclo.'
        ]);
    }
    
    // OTIMIZAÇÃO V3: NSR gerado sem FOR UPDATE (muito mais rápido!)
    // UNIQUE constraint em nsr_unique garante que não haverá duplicatas
    $insert_start = microtime(true);
    
    // Retry logic: tenta até 3x em caso de colisão de NSR (raro)
    $maxRetries = 3;
    $attemptCount = 0;
    $success = false;
    
    while (!$success && $attemptCount < $maxRetries) {
        $attemptCount++;
        $pdo->beginTransaction();
        
        try {
            // Busca próximo NSR SEM lock (muito mais rápido!)
            $nsr_start = microtime(true);
            $stmtNsr = $pdo->query("SELECT COALESCE(MAX(nsr), 0) + 1 as next_nsr FROM attendance");
            $nextNsr = (int)$stmtNsr->fetchColumn();
            $debug_times['get_nsr'] = round((microtime(true) - $nsr_start) * 1000, 2);
            
            $stmt = $pdo->prepare("INSERT INTO attendance
                (teacher_id, school_id, date, check_in, method, ip, user_agent, check_in_lat, check_in_lng, check_in_acc, photo, approved,
                 record_mode, recorded_at, synced_at, hlb_sync_status, hlb_offset_seconds, device_identifier,
                 fraud_risk_level, gps_mock_detected, device_fingerprint, pending_reasons,
                 is_overtime_candidate, overtime_justification, nsr)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $teacherId, $matchedSchoolId, $today, $now, 'pin', $ip, $ua, $lat, $lng, $acc, $filename, $approvedNow,
                $recordMode, $recordedAt, $syncedAt, $hlbSyncStatus, $hlbOffsetSeconds, $deviceIdentifier,
                $fraudRiskLevel, $gpsMockDetected, $deviceFingerprint, $pendingReasonsJson,
                $isOvertimeCandidate, $overtimeJustification, $nextNsr
            ]);
            $debug_times['insert'] = round((microtime(true) - $insert_start) * 1000, 2);
            
            $attendanceId = $pdo->lastInsertId();
            
            $pdo->commit();
            $debug_times['commit'] = round((microtime(true) - $insert_start) * 1000, 2);
            $success = true;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            
            // Se for erro de duplicate key no NSR, tenta novamente
            if ($e->getCode() == 23000 && $attemptCount < $maxRetries) {
                usleep(10000); // Aguarda 10ms antes de tentar novamente
                continue;
            }
            
            // Outros erros: propaga exceção
            throw $e;
        }
    }
    
    if (!$success) {
        throw new Exception("Falha ao gerar NSR após $maxRetries tentativas");
    }

    $payload = [
        'teacher_id'=>$teacherId,
        'type'=>'checkin',
        'geo_ok'=>$geoOk,
        'matched_school_id'=>$matchedSchoolId,
        'distance_m'=>$distanceM,
        'geo_reasons'=>$geoReasons,
        'acc'=>$acc
    ];
    
    // $attendanceId e $nextNsr já foram definidos no bloco try acima
    audit_log('create','attendance',$attendanceId,$payload);
    
    // Registra detecções de fraude no log
    if (!empty($fraudDetections)) {
        $stmtFraud = $pdo->prepare("INSERT INTO fraud_detection_log 
            (attendance_id, teacher_id, detection_type, risk_level, details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($fraudDetections as $detection) {
            $detailsJson = isset($detection['details']) ? json_encode($detection['details']) : null;
            $stmtFraud->execute([
                $attendanceId,
                $teacherId,
                $detection['type'],
                $detection['risk_level'],
                $detailsJson,
                $ip,
                $ua
            ]);
        }
    }

    // NSR já foi gerado e inserido
    $nsr = $nextNsr;
    
    $msg = 'Entrada registrada';
    if ($hasPhoto) $msg .= ' com foto';
    if ($hasGeo) $msg .= ' e localização';
    if ($approvedNow === 1) {
        $msg .= '!';
    } else {
        $msg .= ' (aguardando aprovação).';
    }

    $debug_times['total'] = round((microtime(true) - $debug_start) * 1000, 2);

    // Limpa buffer antes de enviar JSON
    if (ob_get_level()) ob_clean();
    
    $response = [
        'status'=>'ok',
        'collaborator'=>['id'=>$teacherId,'name'=>$prof['name'],'cpf'=>$prof['cpf'] ?? null],
        'teacher'=>['id'=>$teacherId,'name'=>$prof['name'],'cpf'=>$prof['cpf'] ?? null],
        'action'=>'entrada',
        'time'=>$now,
        'photo'=>$photoUrl,
        'message'=>$msg,
        'nsr'=>$nsr, // Número Sequencial de Registro (Portaria 671/2021)
        'attendance_id'=>$attendanceId,
        'record_mode'=>$recordMode,
        'recorded_at'=>$recordedAt,
        'is_overtime_candidate'=>$isOvertimeCandidate,
        'debug_performance'=>$debug_times  // DEBUG: Tempos de cada etapa
    ];
    
    // Adiciona mensagem específica para hora extra
    if ($isOvertimeCandidate) {
        $response['overtime_info'] = [
            'message' => 'Registro em dia sem aulas regulares. Aguardando aprovação do administrador para validar como hora extra.',
            'requires_admin_review' => true
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
// Saída
} else {
    if (!$open) {
        api_error(400, 'no_open_checkin', 'Não há entrada aberta hoje. Registre a entrada antes da saída.', [
            'Faça o registro de entrada para então registrar a saída.'
        ]);
    }

    $parseTime = static function (?string $str): ?DateTime {
        if (!$str) return null;
        $str = trim($str);
        $fmt = strlen($str) === 5 ? 'H:i' : 'H:i:s';
        return DateTime::createFromFormat($fmt, $str) ?: null;
    };

    $pdo->beginTransaction();
    try {
      $photoSetSql = $filename ? "photo = ?, " : "";
      $sql = "UPDATE attendance
              SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP, {$photoSetSql}
                  approved = CASE WHEN ? = 1 THEN 1 ELSE approved END,
                  fraud_risk_level = ?, gps_mock_detected = ?, device_fingerprint = ?,
                  pending_reasons = ?
              WHERE id = ?";
      $params = [$now, $lat, $lng, $acc];
      if ($filename) $params[] = $filename;
      $params[] = $approvedNow;
      $params[] = $fraudRiskLevel;
      $params[] = $gpsMockDetected;
      $params[] = $deviceFingerprint;
      $params[] = $pendingReasonsJson;
      $params[] = $open['id'];
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      
      // Registra detecções de fraude no log
      if (!empty($fraudDetections)) {
          $stmtFraud = $pdo->prepare("INSERT INTO fraud_detection_log 
              (attendance_id, teacher_id, detection_type, risk_level, details, ip_address, user_agent) 
              VALUES (?, ?, ?, ?, ?, ?, ?)");
          
          foreach ($fraudDetections as $detection) {
              $detailsJson = isset($detection['details']) ? json_encode($detection['details']) : null;
              $stmtFraud->execute([
                  $open['id'],
                  $teacherId,
                  $detection['type'],
                  $detection['risk_level'],
                  $detailsJson,
                  $ip,
                  $ua
              ]);
          }
      }

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

      $stL = $pdo->prepare("SELECT 1 FROM leaves l JOIN leave_types lt ON lt.id=l.type_id WHERE l.teacher_id=? AND l.approved=1 AND lt.paid=1 AND ? BETWEEN l.start_date AND l.end_date LIMIT 1");
      $stL->execute([$teacherId, $today]);
      if ($stL->fetchColumn()) $expMin = 0;

      $stW = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE teacher_id=? AND date=? AND check_in IS NOT NULL AND check_out IS NOT NULL AND approved = 1");
      $stW->execute([$teacherId, $today]);
      $worked = 0;
      while ($r = $stW->fetch(PDO::FETCH_ASSOC)) {
        if ($r['check_in'] && $r['check_out']) {
          $ci = new DateTime($r['check_in']); $co = new DateTime($r['check_out']);
          if ($co > $ci) $worked += (int) floor(($co->getTimestamp() - $ci->getTimestamp())/60);
        }
      }
      $delta = $worked - $expMin;
      if (abs($delta) <= $tolerance) $delta = 0;

      // Banco de horas: se delta > 0, registra 0 (hora extra será tratada separadamente)
      $deltaForBank = $delta > 0 ? 0 : $delta;
      
      $pdo->prepare("DELETE FROM hour_bank_entries WHERE teacher_id=? AND date=? AND source='auto'")->execute([$teacherId, $today]);
      $insHb = $pdo->prepare("INSERT INTO hour_bank_entries (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id, created_by_admin_id) VALUES (?, NULL, ?, ?, ?, 'auto', ?, NULL)");
      $insHb->execute([$teacherId, $today, $deltaForBank, 'Recalculo diário automático', $open['id']]);

      // Detecta e cria solicitação de hora extra se aplicável
      $overtimeResult = detect_and_create_overtime($pdo, $open['id'], $teacherId, $matchedSchoolId, $today);

      $pdo->commit();
      audit_log('update','attendance',$open['id'],[
        'teacher_id'=>$teacherId,'type'=>'checkout',
        'geo_ok'=>$geoOk,'distance_m'=>$distanceM,'geo_reasons'=>$geoReasons,'acc'=>$acc,
        'delta'=>$delta
      ]);

      $msg = 'Saída registrada';
      if ($hasPhoto) $msg .= ' com foto';
      if ($hasGeo) $msg .= ' e localização';
      if ($approvedNow === 1) {
          $msg .= '!';
      } else {
          $msg .= ' (aguardando aprovação).';
      }
      
      // Adiciona informação de hora extra se foi detectada
      $overtimeNotice = null;
      if (!empty($overtimeResult['created']) && $overtimeResult['overtime_minutes'] > 0) {
          $hours = floor($overtimeResult['overtime_minutes'] / 60);
          $mins = $overtimeResult['overtime_minutes'] % 60;
          $overtimeNotice = sprintf('Detectado %dh%02dm de hora extra (aguarda aprovação)', $hours, $mins);
      }

      // Busca o NSR do registro
      $stmtNsr = $pdo->prepare("SELECT nsr FROM attendance WHERE id = ?");
      $stmtNsr->execute([$open['id']]);
      $nsr = $stmtNsr->fetchColumn();

      // Limpa buffer antes de enviar JSON
      if (ob_get_level()) ob_clean();
      
      echo json_encode([
          'status'=>'ok',
          'collaborator'=>['id'=>$teacherId,'name'=>$prof['name']],
          'teacher'=>['id'=>$teacherId,'name'=>$prof['name'],'cpf'=>$prof['cpf'] ?? null],
          'action'=>'saída',
          'time'=>$now,
          'photo'=>$photoUrl,
          'message'=>$msg,
          'overtime'=>$overtimeNotice,
          'nsr'=>$nsr, // Número Sequencial de Registro (Portaria 671/2021)
          'attendance_id'=>$open['id'],
          'record_mode'=>$recordMode,
          'recorded_at'=>$recordedAt
      ], JSON_UNESCAPED_UNICODE);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      api_error(500, 'server_error', 'Falha ao fechar ponto.', [
          'Tente novamente em instantes. Se persistir, contate o Admin.'
      ]);
    }
}
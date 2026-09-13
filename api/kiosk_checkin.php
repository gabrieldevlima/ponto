<?php
declare(strict_types=1);
// Registro de ponto pelo Quiosque (após reconhecimento facial confirmado).
//
// Honra as regras existentes do sistema reusando os helpers de checkin
// (detect_checkout_orphans, get_effective_weekday, find_logical_duplicate,
// calculate_effective_worked_minutes, compute_overtime_exceedance, etc.) e
// replicando o esqueleto transacional + NSR atômico. NÃO modifica checkin.php.
//
// Simplificações próprias do quiosque (documentadas):
//   - Escola: vem do dispositivo (kiosk_devices.school_id); fallback p/ escola
//     única / última usada. O dispositivo autorizado já valida a localização.
//   - Aprovação: confiança facial (já validada no identify) + dispositivo
//     autorizado são a âncora de confiança → auto-aprova, exceto hora extra
//     (candidato a hora extra fica pendente para o admin, como no fluxo padrão).
//   - Online-only: sem caminhos offline/HLB/sessão/inline-enroll.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/kiosk_lib.php';
require_once __DIR__ . '/../lib/nsr_ledger.php';

if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

kiosk_require_post();
csrf_verify();

$pdo = db();
$dev = kiosk_guard($pdo);
$deviceId = (int)$dev['id'];

if (!kiosk_rate_limit_ok($pdo, $deviceId)) {
    kiosk_respond(['status' => 'error', 'code' => 'rate_limited',
        'message' => 'Muitas tentativas neste dispositivo. Aguarde um momento.'], 429);
}

$input = kiosk_json_input();

// --- Verifica o token de reconhecimento (sem consumir ainda) ----------------
$token = isset($input['recognition_token']) ? (string)$input['recognition_token'] : '';
$rec = kiosk_verify_recognition($token, $deviceId);
if (!$rec) {
    kiosk_respond(['status' => 'error', 'code' => 'recognition_invalid',
        'message' => 'Sessão de reconhecimento inválida ou expirada. Olhe para a câmera novamente.'], 400);
}
$teacherId     = (int)$rec['teacher_id'];
$confidence    = (float)$rec['confidence'];
$identifyLogId = (int)$rec['log_id'];

// --- Colaborador ------------------------------------------------------------
$st = $pdo->prepare("SELECT id, name, cpf, network_wide, active, face_descriptors FROM teachers WHERE id = ? LIMIT 1");
$st->execute([$teacherId]);
$prof = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$prof || (int)$prof['active'] !== 1) {
    kiosk_respond(['status' => 'error', 'code' => 'teacher_inactive',
        'message' => 'Cadastro indisponível. Procure o administrador.'], 409);
}

// --- Ação (o colaborador escolheu no quiosque) ------------------------------
$action = kiosk_normalize_action($input['action'] ?? null);
if ($action === null) {
    kiosk_respond(['status' => 'error', 'code' => 'action_required',
        'message' => 'Selecione a ação: entrada, saída, início ou retorno do intervalo.'], 400);
}

$clientId = isset($input['client_id']) ? (string)$input['client_id'] : '';
$hasClientId = ($clientId !== '' && strlen($clientId) >= 8);

$tzBR = new DateTimeZone('America/Sao_Paulo');
$today = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d');
$now = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d H:i:s');
$authoritativeTime = $now; // online → sempre servidor (anti-fraude)
$ip = kiosk_client_ip();
$ua = substr(kiosk_user_agent(), 0, 255);

$geo = isset($input['geo']) && is_array($input['geo']) ? $input['geo'] : [];
$lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
$lng = isset($geo['lng']) ? (float)$geo['lng'] : null;
$acc = isset($geo['acc']) ? (float)$geo['acc'] : null;

// Helper local: loga em kiosk_face_logs (event=checkin) e responde erro/bloqueio.
$failCheckin = function (string $statusCode, string $message, array $opts = [])
    use ($pdo, $deviceId, $teacherId, $action, $identifyLogId, $confidence) {
    kiosk_log($pdo, [
        'device_id' => $deviceId, 'teacher_id' => $teacherId, 'event_type' => 'checkin',
        'status' => $statusCode, 'confidence' => $confidence, 'action_attempted' => $action,
        'detail' => ['identify_log_id' => $identifyLogId] + ($opts['detail'] ?? []),
    ]);
    kiosk_respond([
        'status'  => $opts['response_status'] ?? 'error',
        'code'    => $statusCode,
        'message' => $message,
    ] + ($opts['extra'] ?? []), $opts['http'] ?? 200);
};

// --- Idempotência (retry de rede não duplica) -------------------------------
if ($hasClientId) {
    try {
        // entrada e intervalos gravam client_id; apenas a saída usa checkout_client_id.
        if ($action !== 'saida') {
            $sd = $pdo->prepare("SELECT id, nsr, approved FROM attendance WHERE client_id = ? AND teacher_id = ? LIMIT 1");
        } else {
            $sd = $pdo->prepare("SELECT id, nsr, approved FROM attendance WHERE checkout_client_id = ? AND teacher_id = ? LIMIT 1");
        }
        $sd->execute([$clientId, $teacherId]);
        if ($already = $sd->fetch(PDO::FETCH_ASSOC)) {
            kiosk_respond([
                'status' => 'ok', 'code' => 'already_registered',
                'action' => $action, 'nsr' => (int)($already['nsr'] ?? 0),
                'attendance_id' => (int)$already['id'],
                'teacher' => ['id' => $teacherId, 'name' => kiosk_display_name((string)$prof['name'])],
                'message' => 'Este ponto já havia sido registrado.',
            ]);
        }
    } catch (Throwable $e) {
        error_log('[kiosk_checkin] dedup client_id pulado: ' . $e->getMessage());
    }
}

// --- Ponto órfão (entrada sem saída): bloqueia (quiosque supervisionado) -----
$orphans = detect_checkout_orphans($pdo, $teacherId);
if (!empty($orphans)) {
    $failCheckin('orphan_punch_pending',
        'Sua batida NÃO foi registrada. Há ponto(s) anteriores sem saída. Procure o administrador para regularizar.',
        ['response_status' => 'blocked', 'detail' => ['orphans' => count($orphans)]]);
}

// --- Lock por colaborador (serializa requisições concorrentes) --------------
$gate = sprintf('kiosk_checkin_gate:%d', $teacherId);
try {
    $stLock = $pdo->prepare("SELECT GET_LOCK(?, 5)");
    $stLock->execute([$gate]);
    $gotLock = (int)$stLock->fetchColumn();
    $stLock->closeCursor();
    if ($gotLock !== 1) {
        kiosk_respond(['status' => 'error', 'code' => 'busy_retry',
            'message' => 'Outro registro está em andamento. Aguarde alguns segundos.'], 429);
    }
    register_shutdown_function(static function () use ($pdo, $gate) {
        try { $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$gate]); } catch (Throwable $_) {}
    });
} catch (Throwable $_) { /* segue sem lock; threshold temporal cobre o caso comum */ }

// --- Registro aberto + máquina de estados -----------------------------------
$openWindow = (int)(get_setting('open_checkin_window_hours', '30') ?? '30');
if ($openWindow <= 0) $openWindow = 30;
// NC-52: exclui anulados/substituídos (ver attendance_vigente_sql em helpers.php)
$stOpen = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND check_in IS NOT NULL AND check_out IS NULL AND " . attendance_vigente_sql() . " AND check_in >= (NOW() - INTERVAL ? HOUR) ORDER BY check_in DESC LIMIT 1");
$stOpen->execute([$teacherId, $openWindow]);
$open = $stOpen->fetch(PDO::FETCH_ASSOC) ?: null;
$openRecordType = $open ? ((isset($open['record_type']) && $open['record_type'] !== null) ? (string)$open['record_type'] : 'work') : null;

// Estados: FORA (sem open), TRABALHANDO (open work), EM_INTERVALO (open break).
if (!$open) {
    if ($action === 'iniciar_intervalo') {
        $failCheckin('no_work_open', 'Não há entrada aberta. Bata sua entrada antes de iniciar o intervalo.');
    }
    if ($action === 'retornar_intervalo') {
        $failCheckin('no_break_open', 'Não há intervalo aberto para retornar.');
    }
    if ($action === 'saida') {
        $failCheckin('action_mismatch', 'Não há entrada aberta para registrar a saída.');
    }
    // entrada → segue
} elseif ($openRecordType === 'work') {
    if ($action === 'retornar_intervalo') {
        $failCheckin('no_break_open', 'Você está trabalhando. Para pausar, escolha "Iniciar intervalo".');
    }
    if ($action === 'entrada') {
        $failCheckin('already_checked_in', 'Você já tem uma entrada aberta. Bata a saída para encerrar o turno.');
    }
    // saida ou iniciar_intervalo → seguem
} else { // EM_INTERVALO (break aberto)
    if ($action === 'saida') {
        $failCheckin('break_open_cannot_clock_out', 'Você está em intervalo. Retorne do intervalo antes de bater saída.');
    }
    if ($action === 'iniciar_intervalo') {
        $failCheckin('break_already_open', 'Já existe um intervalo aberto. Retorne dele primeiro.');
    }
    if ($action === 'entrada') {
        $failCheckin('action_mismatch', 'Você está em intervalo. Retorne do intervalo antes de outra ação.');
    }
    // retornar_intervalo → segue
}

// Threshold temporal: bloqueia saída logo após a entrada (anti duplo-toque).
if ($action === 'saida' && $open) {
    $minGap = (int)(get_setting('min_checkout_gap_seconds', '60') ?? '60');
    if ($minGap < 0) $minGap = 60;
    $gap = max(0, time() - strtotime((string)$open['check_in']));
    if ($gap < $minGap) {
        $failCheckin('checkout_too_soon',
            'Você acabou de bater entrada. Aguarde ' . ($minGap - $gap) . ' segundo(s) para registrar a saída.');
    }
}

// --- Modo de jornada + weekday efetivo (sábado letivo) ----------------------
$stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
$stMode->execute([$teacherId]);
$mode = $stMode->fetchColumn() ?: 'classes';
$weekday = get_effective_weekday($pdo, $today, null);

// Hora extra descontinuada: nunca marca candidato a hora extra. A coluna
// is_overtime_candidate é mantida no INSERT por compatibilidade (sempre 0).
// Dias sem jornada prevista são registrados normalmente e consolidados no mês.
$isOvertimeCandidate = 0;
$overtimeJustification = null;

// --- Dedup ------------------------------------------------------------------
// Dedup feita por client_id/checkout_client_id (UNIQUE) + GET_LOCK por
// colaborador + min_checkout_gap — igual ao caminho ONLINE do api/checkin.php.
// NÃO usamos find_logical_duplicate() aqui: ela não distingue record_type e
// marcaria uma SAÍDA logo após um intervalo como "duplicata" (descartando a
// saída real e deixando o registro aberto). Auditoria 2026-06.

// --- Escola (baseada no dispositivo) ----------------------------------------
$matchedSchoolId = null;
if (!empty($dev['school_id'])) {
    $matchedSchoolId = (int)$dev['school_id'];
} else {
    $schools = get_teacher_allowed_schools($pdo, $teacherId);
    if (count($schools) === 1) {
        $matchedSchoolId = (int)$schools[0]['id'];
    } else {
        try {
            $sl = $pdo->prepare("SELECT school_id FROM attendance WHERE teacher_id = ? AND school_id IS NOT NULL ORDER BY check_in DESC LIMIT 1");
            $sl->execute([$teacherId]);
            $last = (int)($sl->fetchColumn() ?: 0);
            if ($last > 0) $matchedSchoolId = $last;
            elseif (!empty($schools)) $matchedSchoolId = (int)$schools[0]['id'];
        } catch (Throwable $_) {}
    }
}
$geoOk = ($matchedSchoolId !== null);

// --- Foto de auditoria: usa o frame do identify (à prova de adulteração) ----
$photoUrl = null;
try {
    $pp = $pdo->prepare("SELECT photo FROM kiosk_face_logs WHERE id = ?");
    $pp->execute([$identifyLogId]);
    $photoUrl = kiosk_valid_photo_ref($pp->fetchColumn() ?: null);
} catch (Throwable $_) {}

// --- Pendências + aprovação (política do quiosque) --------------------------
$pendingReasons = [];
$pendingReasonsJson = !empty($pendingReasons) ? json_encode($pendingReasons, JSON_UNESCAPED_UNICODE) : null;
$approvedNow = 1;

$deviceIdentifier = substr(hash('sha256', 'kiosk:' . $deviceId . ':' . $ua . ':' . $ip), 0, 64);

// O token é consumido (uso único, atômico) DENTRO da transação de gravação —
// assim um rollback por falha transitória devolve o token e o colaborador pode
// tentar de novo sem refazer o reconhecimento. (Auditoria 2026-06.)

$actionLabelMap = [
    'entrada' => 'Entrada',
    'saida' => 'Saída',
    'iniciar_intervalo' => 'Início do intervalo',
    'retornar_intervalo' => 'Retorno do intervalo',
];

// ===========================================================================
// ENTRADA / INICIAR / RETORNAR INTERVALO → INSERT de nova linha
// ===========================================================================
if (in_array($action, ['entrada', 'iniciar_intervalo', 'retornar_intervalo'], true)) {
    $insertRecordType = ($action === 'iniciar_intervalo') ? 'break' : 'work';
    $parentId = ($action === 'iniciar_intervalo' && $open) ? (int)$open['id'] : null;
    $clientIdForInsert = $hasClientId ? $clientId : null;

    $maxRetries = 3;
    $attempt = 0;
    $success = false;
    $reused = false;
    $attendanceId = null;
    $nsr = null;

    while (!$success && $attempt < $maxRetries) {
        $attempt++;
        $pdo->beginTransaction();
        try {
            // Consome o token (uso único) dentro da transação: se der rollback,
            // o token volta a 'recognized' e permite nova tentativa.
            if (!kiosk_consume_recognition($identifyLogId, $teacherId, $deviceId, $pdo)) {
                $pdo->rollBack();
                $reused = true;
                break;
            }
            // Fecha o registro aberto (work/break) ao iniciar/retornar intervalo.
            if ($action === 'iniciar_intervalo' || $action === 'retornar_intervalo') {
                $close = $pdo->prepare("UPDATE attendance
                    SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND check_out IS NULL");
                $close->execute([$authoritativeTime, $lat, $lng, $acc, (int)$open['id']]);
                if ($close->rowCount() === 0) {
                    $pdo->rollBack();
                    $failCheckin('state_changed', 'O estado do seu ponto mudou. Olhe para a câmera novamente.');
                }

                // Livro fiscal — SAÍDA do registro fechado (NSR próprio, NC-09).
                $nsrOutClose = nsr_ledger_record_mark($pdo, [
                    'teacher_id' => $teacherId, 'teacher_cpf' => $prof['cpf'] ?? null,
                    'school_id' => $matchedSchoolId ?? null, 'attendance_id' => (int)$open['id'],
                    'mark_role' => 'out', 'direction' => 'S',
                    'record_type' => ($open['record_type'] ?? 'work'),
                    'marked_at' => $authoritativeTime,
                    'work_date' => (string)($open['date'] ?? $today),
                    'origin' => 'kiosk', 'record_mode' => 'online', 'method' => 'face',
                    'recorded_at' => $now, 'synced_at' => $now,
                    'lat' => $lat, 'lng' => $lng, 'acc' => $acc,
                    'device_identifier' => $deviceIdentifier,
                ]);
                if ($nsrOutClose !== null) {
                    $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
                        ->execute([$nsrOutClose, (int)$open['id']]);
                }
            }

            // NSR: o livro fiscal é a autoridade quando ligado (ver nsr_legacy_reserve).
            $nextNsr = nsr_legacy_reserve($pdo);

            $ins = $pdo->prepare("INSERT INTO attendance
                (teacher_id, school_id, date, check_in, method, ip, user_agent,
                 check_in_lat, check_in_lng, check_in_acc, photo, approved,
                 record_mode, recorded_at, synced_at, device_identifier, fraud_risk_level,
                 pending_reasons, is_overtime_candidate, overtime_justification, nsr, client_id,
                 record_type, parent_attendance_id, face_match_confidence, kiosk_device_id, kiosk_face_log_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $teacherId, $matchedSchoolId, $today, $authoritativeTime, 'kiosk_face', $ip, $ua,
                $lat, $lng, $acc, $photoUrl, $approvedNow,
                'online', $now, $now, $deviceIdentifier, 0,
                $pendingReasonsJson, $isOvertimeCandidate, $overtimeJustification, $nextNsr, $clientIdForInsert,
                $insertRecordType, $parentId, $confidence, $deviceId, $identifyLogId,
            ]);
            $attendanceId = (int)$pdo->lastInsertId();
            $nsr = $nextNsr;

            // Livro fiscal — ENTRADA registrada no totem.
            $nsrIn = nsr_ledger_record_mark($pdo, [
                'teacher_id' => $teacherId, 'teacher_cpf' => $prof['cpf'] ?? null,
                'school_id' => $matchedSchoolId ?? null, 'attendance_id' => $attendanceId,
                'mark_role' => 'in', 'direction' => 'E',
                'record_type' => $insertRecordType,
                'marked_at' => $authoritativeTime, 'work_date' => $today,
                'origin' => 'kiosk', 'record_mode' => 'online', 'method' => 'face',
                'recorded_at' => $now, 'synced_at' => $now,
                'lat' => $lat, 'lng' => $lng, 'acc' => $acc,
                'device_identifier' => $deviceIdentifier, 'photo' => $filename ?? null,
            ]);
            if ($nsrIn !== null) {
                $pdo->prepare("UPDATE attendance SET legacy_nsr = COALESCE(legacy_nsr, nsr), nsr = ? WHERE id = ?")
                    ->execute([$nsrIn, $attendanceId]);
                $nsr = $nsrIn;
            }

            $pdo->commit();
            $success = true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Colisão de UNIQUE (client_id/nsr) por retry de sync duplicado.
            if ((int)$e->getCode() === 23000 && $hasClientId) {
                try {
                    $ex = $pdo->prepare("SELECT id, nsr FROM attendance WHERE client_id = ? LIMIT 1");
                    $ex->execute([$clientId]);
                    if ($row = $ex->fetch(PDO::FETCH_ASSOC)) {
                        kiosk_respond([
                            'status' => 'ok', 'code' => 'already_registered', 'action' => $action,
                            'nsr' => (int)($row['nsr'] ?? 0), 'attendance_id' => (int)$row['id'],
                            'teacher' => ['id' => $teacherId, 'name' => kiosk_display_name((string)$prof['name'])],
                            'message' => 'Este ponto já havia sido registrado.',
                        ]);
                    }
                } catch (Throwable $_) {}
            }
            if ($attempt >= $maxRetries) {
                error_log('[kiosk_checkin] INSERT falhou: ' . $e->getMessage());
                $failCheckin('insert_failed', 'Não foi possível registrar o ponto. Tente novamente.',
                    ['http' => 500, 'detail' => ['error' => $e->getMessage()]]);
            }
        }
    }

    if ($reused) {
        kiosk_respond(['status' => 'error', 'code' => 'recognition_reused',
            'message' => 'Esse reconhecimento já foi usado. Olhe para a câmera novamente.'], 409);
    }
    if (!$success || $attendanceId === null) {
        $failCheckin('insert_failed', 'Não foi possível registrar o ponto. Tente novamente.', ['http' => 500]);
    }

    audit_log('create', 'attendance', $attendanceId, [
        'via' => 'kiosk', 'device_id' => $deviceId, 'confidence' => $confidence,
        'method' => 'kiosk_face', 'school_id' => $matchedSchoolId, 'action' => $action,
    ]);
    kiosk_log($pdo, [
        'device_id' => $deviceId, 'teacher_id' => $teacherId, 'event_type' => 'checkin',
        'status' => 'registered', 'confidence' => $confidence, 'action_attempted' => $action,
        'attendance_id' => $attendanceId, 'photo' => $photoUrl,
        'detail' => ['nsr' => $nsr, 'approved' => $approvedNow, 'identify_log_id' => $identifyLogId],
    ]);

    $msg = ($actionLabelMap[$action] ?? 'Ponto') . ' registrada' .
        ($approvedNow === 1 ? '!' : ' (aguardando aprovação).');
    kiosk_respond([
        'status' => 'ok', 'action' => $action, 'record_type' => $insertRecordType,
        'time' => $now, 'nsr' => $nsr, 'attendance_id' => $attendanceId,
        'approved' => ($approvedNow === 1),
        'teacher' => ['id' => $teacherId, 'name' => kiosk_display_name((string)$prof['name'])],
        'confidence' => $confidence,
        'message' => $msg,
    ]);
}

// ===========================================================================
// SAÍDA → UPDATE do registro aberto + banco de horas
// ===========================================================================
if (!$open) {
    $failCheckin('no_open_checkin', 'Não há entrada aberta. Registre a entrada antes da saída.');
}

$pdo->beginTransaction();
try {
    // Consome o token (uso único) dentro da transação — rollback devolve o token.
    if (!kiosk_consume_recognition($identifyLogId, $teacherId, $deviceId, $pdo)) {
        $pdo->rollBack();
        kiosk_respond(['status' => 'error', 'code' => 'recognition_reused',
            'message' => 'Esse reconhecimento já foi usado. Olhe para a câmera novamente.'], 409);
    }
    $sql = "UPDATE attendance
            SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP,
                approved = CASE WHEN ? = 1 AND approved = 1 THEN 1 ELSE NULL END,
                pending_reasons = ?, checkout_client_id = ?,
                kiosk_device_id = ?, kiosk_face_log_id = ?,
                face_match_confidence = COALESCE(face_match_confidence, ?)
            WHERE id = ?";
    $pdo->prepare($sql)->execute([
        $authoritativeTime, $lat, $lng, $acc,
        $approvedNow, $pendingReasonsJson, ($hasClientId ? $clientId : null),
        $deviceId, $identifyLogId, $confidence,
        (int)$open['id'],
    ]);

    // Livro fiscal — SAÍDA (NSR próprio; antes reaproveitava o da entrada).
    $nsrOut = nsr_ledger_record_mark($pdo, [
        'teacher_id' => $teacherId, 'teacher_cpf' => $prof['cpf'] ?? null,
        'school_id' => $open['school_id'] ?? null, 'attendance_id' => (int)$open['id'],
        'mark_role' => 'out', 'direction' => 'S',
        'record_type' => ($open['record_type'] ?? 'work'),
        'marked_at' => $authoritativeTime,
        'work_date' => (string)$open['date'],
        'origin' => 'kiosk', 'record_mode' => 'online', 'method' => 'face',
        'recorded_at' => $now, 'synced_at' => $now,
        'lat' => $lat, 'lng' => $lng, 'acc' => $acc,
        'device_identifier' => $deviceIdentifier,
    ]);
    if ($nsrOut !== null) {
        $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
            ->execute([$nsrOut, (int)$open['id']]);
    }

    // Banco de horas automático (recalcula o delta do dia da ENTRADA original).
    $tolerance = (int)(get_setting('tolerance_minutes', '5') ?? '5');
    $openDate = (string)$open['date'];
    // Esperado canônico (helpers.php): cobre modos classes/time/hours, sábado
    // letivo e afastamento pago. Janela completa — intervalo não é descontado.
    $expMin = calculate_expected_minutes($pdo, $teacherId, $openDate);

    // Trabalhado EFETIVO: presença cheia (pares work + break) — o intervalo
    // conta como tempo trabalhado, nunca subtrai (helpers.php).
    $worked = calculate_effective_worked_minutes($pdo, $teacherId, $openDate);
    $delta = $worked - $expMin;
    if (abs($delta) <= $tolerance) $delta = 0;
    // Ressincroniza o ledger do dia (limpa entrada 'auto' antiga). Não grava mais
    // débito automático — a falta de horas é consolidada no mês como informativo.
    recompute_day_hour_bank($pdo, $teacherId, $openDate);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[kiosk_checkin] saída falhou: ' . $e->getMessage());
    $failCheckin('checkout_failed', 'Não foi possível registrar a saída. Tente novamente.',
        ['http' => 500, 'detail' => ['error' => $e->getMessage()]]);
}

audit_log('update', 'attendance', (int)$open['id'], [
    'via' => 'kiosk', 'device_id' => $deviceId, 'confidence' => $confidence, 'type' => 'checkout',
    'school_id' => $matchedSchoolId,
]);
kiosk_log($pdo, [
    'device_id' => $deviceId, 'teacher_id' => $teacherId, 'event_type' => 'checkin',
    'status' => 'registered', 'confidence' => $confidence, 'action_attempted' => 'saida',
    'attendance_id' => (int)$open['id'], 'photo' => $photoUrl,
    'detail' => ['approved' => $approvedNow, 'identify_log_id' => $identifyLogId],
]);

$msg = 'Saída registrada' . ($approvedNow === 1 ? '!' : ' (aguardando aprovação).');
kiosk_respond([
    'status' => 'ok', 'action' => 'saida', 'time' => $now,
    'nsr' => (int)($open['nsr'] ?? 0), 'attendance_id' => (int)$open['id'],
    'approved' => ($approvedNow === 1),
    'teacher' => ['id' => $teacherId, 'name' => kiosk_display_name((string)$prof['name'])],
    'confidence' => $confidence,
    'message' => $msg,
]);

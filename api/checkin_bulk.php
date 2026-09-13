<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/nsr_ledger.php';
require_once __DIR__ . '/../lib/hlb.php';
require_once __DIR__ . '/../lib/time_anchor.php';
require_once __DIR__ . '/../lib/offline_auth.php';
if (!headers_sent()) header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status'=>'error','message'=>'Método não permitido']); exit;
}

// BUG-001: valida CSRF antes de qualquer mutação. O Service Worker captura o
// token no momento do registro offline e reenvia em X-CSRF-Token; sessões
// regeneradas (logout/relogin) invalidam o token e o SW reenfileira o item.
csrf_verify();

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'Formato inválido. Esperado {"items":[...]}']); exit;
}

$pdo = db();
$tzBR = new DateTimeZone('America/Sao_Paulo');

function normalize_expected_action($value): ?string {
  if ($value === null) return null;
  $v = strtolower(trim((string)$value));
  if ($v === '') return null;
  if (in_array($v, ['entrada', 'in', 'checkin', 'check_in', 'entry'], true)) return 'entrada';
  if (in_array($v, ['saida', 'out', 'checkout', 'check_out', 'exit'], true)) return 'saida';
  if (in_array($v, ['break_start', 'iniciar_intervalo', 'sair_intervalo', 'pause'], true)) return 'iniciar_intervalo';
  if (in_array($v, ['break_end', 'retornar_intervalo', 'retorno', 'resume'], true)) return 'retornar_intervalo';
  return null;
}

function save_bulk_entry_photo($value): array {
  $photoData = trim((string)$value);
  if ($photoData === '') {
    return [null, 'photo_required'];
  }
  if (strpos($photoData, 'data:image') !== 0) {
    return [null, 'photo_invalid'];
  }
  $parts = explode(',', $photoData, 2);
  if (count($parts) !== 2) {
    return [null, 'photo_invalid'];
  }
  $decoded = base64_decode($parts[1], true);
  $maxBytes = 5 * 1024 * 1024;
  if ($decoded === false || strlen($decoded) > $maxBytes) {
    return [null, 'photo_invalid'];
  }
  $info = @getimagesizefromstring($decoded);
  $allowedMimes = ['image/jpeg', 'image/png'];
  if ($info === false || !in_array($info['mime'] ?? '', $allowedMimes, true)) {
    return [null, 'photo_invalid'];
  }
  $ext = ($info['mime'] === 'image/png') ? '.png' : '.jpg';
  $filename = bin2hex(random_bytes(16)) . $ext;
  $photoDir = __DIR__ . '/../public/photos/';
  if (!is_dir($photoDir) && !@mkdir($photoDir, 0777, true) && !is_dir($photoDir)) {
    return [null, 'photo_save_failed'];
  }
  if (file_put_contents($photoDir . $filename, $decoded) === false) {
    return [null, 'photo_save_failed'];
  }
  return [$filename, null];
}

function process_check_item(PDO $pdo, array $item, DateTimeZone $tzBR): array {
  $cpf = preg_replace('/\D/', '', (string)($item['cpf'] ?? ''));
  $geo = $item['geo'] ?? null;
  $pin = isset($item['pin']) ? (string)$item['pin'] : null;
  // BUG-005: client_id idempotency key. Mesmo padrão de api/checkin.php.
  // Sem isso, retry de SW podia duplicar registros (mesmo cliente reenviando
  // o batch após timeout). UNIQUE em attendance.client_id ainda barraria,
  // mas levantaria exceção em vez de retornar already_registered limpo.
  $clientId = isset($item['client_id'])
      ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$item['client_id']), 0, 64)
      : '';
  $expectedAction = normalize_expected_action($item['expected_action'] ?? $item['expectedAction'] ?? null);

  if (!$cpf || strlen($cpf) !== 11) {
    return [
      'status'=>'discarded',
      'code'=>'cpf_required',
      'message'=>'Item descartado: CPF é obrigatório para sincronização em lote.'
    ];
  }

  // ==========================================================================
  // AUTORIZAÇÃO DO ITEM — NC-41
  //
  // Preferência pelo TOKEN offline (assinado, vinculado a CPF e dispositivo,
  // com validade curta). O PIN continua aceito apenas para drenar itens que já
  // estavam na fila antes desta correção — sem isso, marcações offline
  // legítimas seriam descartadas na atualização, e o registro de jornada é
  // direito do trabalhador.
  //
  // A janela de compatibilidade se fecha sozinha: itens antigos são drenados e
  // o app novo nunca mais grava PIN.
  // ==========================================================================
  $offToken = (string)($item['offlineAuth'] ?? $item['offline_auth'] ?? '');
  $usouToken = false;

  if (empty($pin) && $offToken === '') {
    return [
      'status'=>'discarded',
      'code'=>'auth_required',
      'message'=>'Item descartado: autorização ausente (token offline ou PIN).'
    ];
  }

  // Geolocalização obrigatória para check-in em lote
  if (!isset($geo['lat']) || !isset($geo['lng'])) {
    return [
      'status'=>'discarded',
      'code'=>'geo_required',
      'message'=>'Item descartado: Geolocalização é obrigatória para registro em lote.'
    ];
  }

  // Resolve colaborador por CPF
  $stmt = $pdo->prepare("SELECT id, name, active, pin_hash FROM teachers WHERE cpf = ? AND active = 1 LIMIT 1");
  $stmt->execute([$cpf]);
  $prof = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$prof) {
    return [
      'status'=>'discarded',
      'code'=>'cpf_invalid',
      'message'=>'Item descartado: CPF não encontrado ou colaborador inativo.'
    ];
  }

  // Caminho preferencial: token offline assinado pelo servidor.
  if ($offToken !== '') {
    $devId = substr(hash('sha256', $ua . $ip), 0, 64);
    $v = offline_auth_verify($offToken, $cpf, $devId);
    if (!$v['ok']) {
      auth_attempt_log($pdo, 'bulk_offauth', $cpf, false, (int)$prof['id'], $v['motivo']);
      return [
        'status'=>'discarded',
        'code'=>'offline_auth_invalid',
        'message'=>'Item descartado: autorização offline inválida (' . $v['motivo'] . ').'
      ];
    }
    if ((int)$v['teacher_id'] !== (int)$prof['id']) {
      return [
        'status'=>'discarded',
        'code'=>'offline_auth_mismatch',
        'message'=>'Item descartado: token não corresponde ao colaborador.'
      ];
    }
    $usouToken = true;
  }

  // Compatibilidade: itens enfileirados antes da NC-41 ainda trazem PIN.
  if (!$usouToken) {
  if (empty($prof['pin_hash'])) {
    return [
      'status'=>'discarded',
      'code'=>'pin_not_set',
      'message'=>'Item descartado: Colaborador não possui PIN configurado. Configure o PIN no painel admin.'
    ];
  }
  if (!password_verify($pin, $prof['pin_hash'])) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // HOTFIX 2026-09: identificador com hash — antes gravava o CPF em claro no log.
    auth_attempt_log($pdo, 'bulk_pin', 'cpf:' . hash('sha256', $cpf), false, (int)$prof['id'], 'pin_invalid');
    return [
      'status'=>'discarded',
      'code'=>'pin_invalid',
      'message'=>'Item descartado: PIN incorreto.'
    ];
  }
  } // fim do caminho de compatibilidade por PIN

  $teacherId = (int)$prof['id'];

  // HOTFIX 2026-09: mesmo lock por colaborador de api/checkin.php. Sem ele, a
  // fila da página e o Service Worker drenando juntos podiam abrir duas
  // entradas ou fechar a mesma saída duas vezes. Liberado ao fim da conexão.
  try {
      $stLockBulk = $pdo->prepare("SELECT GET_LOCK(?, 5)");
      $stLockBulk->execute([sprintf('checkin_gate:%d', $teacherId)]);
      $gotBulkLock = (int)$stLockBulk->fetchColumn();
      $stLockBulk->closeCursor();
  } catch (Throwable $e) {
      $gotBulkLock = 0;
  }
  if ($gotBulkLock !== 1) {
      return ['status' => 'error', 'code' => 'busy_retry', 'message' => 'Outro registro está em andamento. Tente novamente em instantes.'];
  }

  // BUG-005: dedup antes de qualquer write. Se o mesmo client_id já gravou,
  // retorna 'already_registered' em vez de tentar INSERT/UPDATE.
  if ($clientId !== '') {
      try {
          $stDup = $pdo->prepare("SELECT id, nsr, check_in, check_out, approved FROM attendance WHERE client_id = ? AND teacher_id = ? LIMIT 1");
          $stDup->execute([$clientId, $teacherId]);
          if ($dup = $stDup->fetch(PDO::FETCH_ASSOC)) {
              return [
                  'status' => 'ok',
                  'code' => 'already_registered',
                  'message' => 'Item já registrado anteriormente.',
                  'attendance_id' => (int)$dup['id'],
                  'nsr' => (int)$dup['nsr'],
              ];
          }
      } catch (Throwable $e) {
          error_log('checkin_bulk client_id dedup skipped: ' . $e->getMessage());
      }
  }

  // Dedup global de saida: retry apos checkout bem-sucedido nao deve virar
  // nova entrada apenas porque nao existe mais ponto aberto.
  if ($clientId !== '') {
      try {
          $stDupOutGlobal = $pdo->prepare("SELECT id, nsr FROM attendance WHERE checkout_client_id = ? AND teacher_id = ? LIMIT 1");
          $stDupOutGlobal->execute([$clientId, $teacherId]);
          if ($dupOutGlobal = $stDupOutGlobal->fetch(PDO::FETCH_ASSOC)) {
              return [
                  'status' => 'ok',
                  'code' => 'already_registered',
                  'action' => 'saída',
                  'message' => 'Saída já registrada anteriormente.',
                  'attendance_id' => (int)$dupOutGlobal['id'],
                  'nsr' => (int)$dupOutGlobal['nsr'],
              ];
          }
      } catch (Throwable $e) {
          error_log('checkin_bulk checkout_client_id global dedup skipped: ' . $e->getMessage());
      }
  }

  $today = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d');
  $now = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d H:i:s');
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
  $lng = isset($geo['lng']) ? (float)$geo['lng'] : null;
  $acc = isset($geo['acc']) ? (float)$geo['acc'] : null;

  // Portaria 671/2021 - Campos obrigatórios
  $recordMode = isset($item['recordMode']) ? $item['recordMode'] : 'offline'; // bulk geralmente é offline
  $recordedAt = isset($item['recordedAt']) ? $item['recordedAt'] : $now;
  $syncedAt = $now; // Sincronização acontece agora no bulk
  $hlbSyncStatus = 'synced';
  $hlbOffsetSeconds = isset($item['hlbOffsetSeconds']) ? (int)$item['hlbOffsetSeconds'] : 0;
  $deviceIdentifier = substr(hash('sha256', $ua . $ip), 0, 32);

  // ==========================================================================
  // INSTANTE AUTORITATIVO — NC-14 (Fase 6 da auditoria)
  //
  // O bloco anterior aceitava o `recordedAt` do cliente se o `hlbOffsetSeconds`
  // — também enviado pelo cliente — estivesse dentro do limite. Raciocínio
  // circular: bastava mandar offset 0 junto com o horário forjado.
  //
  // A fila offline agora carrega a ÂNCORA assinada pelo servidor e o tempo
  // monotônico decorrido. Sem âncora válida, grava-se a hora da sincronização
  // e a incerteza vai para pending_reasons — a marcação nunca é descartada.
  // ==========================================================================
  $tempoAut = time_anchor_authoritative($pdo, $item, 'offline', $deviceIdentifier);
  $authoritativeTime = $tempoAut['marked_at'];
  $hlbSyncStatus     = $tempoAut['hlb_status'];

  // Modo do colaborador
  $stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id=t.type_id WHERE t.id=?");
  $stMode->execute([$teacherId]);
  $mode = $stMode->fetchColumn() ?: 'classes';

  // Weekday efetivo (respeita sábado letivo). $matchedSchoolId ainda não foi
  // definido aqui — passamos null e a função pega exceções globais (school_id NULL).
  $weekday = get_effective_weekday($pdo, $today, null);
  $currentTime = (new DateTimeImmutable('now', $tzBR))->format('H:i:s');

  // Verifica se há ponto aberto dentro da janela de turno em andamento. Suporta
  // turno noturno (saída até 24h depois) mas ignora órfãos antigos (esquecimento
  // de bater saída há dias). Default 30h, configurável via app_settings.
  $openWindow = (int)(function_exists('get_setting') ? get_setting('open_checkin_window_hours', '30') : 30);
  if ($openWindow <= 0) $openWindow = 30;
  // NC-52: exclui anulados/substituídos (ver attendance_vigente_sql em helpers.php)
  $stmt = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND check_in IS NOT NULL AND check_out IS NULL AND " . attendance_vigente_sql() . " AND check_in >= (NOW() - INTERVAL ? HOUR) ORDER BY check_in DESC LIMIT 1");
  $stmt->execute([$teacherId, $openWindow]);
  $open = $stmt->fetch(PDO::FETCH_ASSOC);
  $openRecordType = $open ? ((isset($open['record_type']) && $open['record_type'] !== null) ? (string)$open['record_type'] : 'work') : null;

  // Máquina de estados análoga ao api/checkin.php (versão simplificada para offline-sync).
  // FORA → entrada; TRABALHANDO → saida (default) ou iniciar_intervalo; EM_INTERVALO → retornar_intervalo.
  if (!$open) {
    $action = 'entrada';
  } elseif ($openRecordType === 'work') {
    $action = ($expectedAction === 'iniciar_intervalo') ? 'iniciar_intervalo' : 'saida';
  } else { // break aberto
    $action = 'retornar_intervalo';
  }

  // BUG-005 (rev): dedup específico para saída via checkout_client_id.
  // O dedup do topo da função só cobre client_id (usado na entrada). Sem este
  // segundo check, retry pós-falha-de-rede de uma saída cairia no ramo entrada
  // (ponto já fechado na 1ª tentativa) e criaria entrada-fantasma com
  // check_in = $now. A migration add_attendance_checkout_client_id.sql cria
  // a coluna + UNIQUE index.
  if ($clientId !== '') {
      try {
          $stDupOut = $pdo->prepare("SELECT id, nsr FROM attendance WHERE checkout_client_id = ? AND teacher_id = ? LIMIT 1");
          $stDupOut->execute([$clientId, $teacherId]);
          if ($dupOut = $stDupOut->fetch(PDO::FETCH_ASSOC)) {
              return [
                  'status' => 'ok',
                  'code' => 'already_registered',
                  'action' => 'saída',
                  'message' => 'Saída já registrada anteriormente.',
                  'attendance_id' => (int)$dupOut['id'],
                  'nsr' => (int)$dupOut['nsr'],
              ];
          }
      } catch (Throwable $e) {
          // Coluna pode não existir (auto-migration ainda não rodou) — não bloqueia.
          error_log('checkin_bulk checkout_client_id dedup skipped: ' . $e->getMessage());
      }
  }

  // Verifica se professor usa sistema de grade horária (para pagamento fixo)
  if ($expectedAction !== null && $expectedAction !== $action) {
      $expectedLabel = $expectedAction === 'saida' ? 'saída' : 'entrada';
      $actualLabel = $action === 'saida' ? 'saída' : 'entrada';
      return [
          'status' => 'discarded',
          'code' => 'action_mismatch',
          'message' => "Item descartado: ação solicitada ({$expectedLabel}) não confere com a ação atual ({$actualLabel}).",
          'expected_action' => $expectedAction,
          'actual_action' => $action,
      ];
  }

  $usesPeriodSystem = teacher_uses_period_system($teacherId);
  
  // Hora extra descontinuada: nunca marca candidato a hora extra. A coluna
  // is_overtime_candidate é mantida no INSERT por compatibilidade (sempre 0).
  $isOvertimeCandidate = 0;
  $overtimeJustification = null;

  // Geofence
  $radiusM = (float)((get_setting('geofence_radius_m', '300') ?? '300'));
  $maxAccM = 100.0;
  $geoOk = false;
  $matchedSchoolId = null;

  if ($lat !== null && $lng !== null) {
    if ($acc !== null && $acc > $maxAccM) {
      // precisão ruim -> deixa pendente
      $geoOk = false;
    }
    $schools = get_teacher_allowed_schools($pdo, $teacherId);
    if ($schools) {
      [$inRadius, $sid] = match_school_by_geo($schools, $lat, $lng, $radiusM);
      $geoOk = ($inRadius === true) && !($acc !== null && $acc > $maxAccM);
      $matchedSchoolId = $sid;
    } else {
      $geoOk = false;
    }
  }

  $filename = null;
  if ($action === 'entrada') {
    [$filename, $photoError] = save_bulk_entry_photo($item['photo'] ?? '');
    if ($photoError !== null) {
      return [
        'status' => 'discarded',
        'code' => $photoError,
        'message' => $photoError === 'photo_required'
          ? 'Item descartado: foto é obrigatória para registrar entrada.'
          : 'Item descartado: foto inválida para registrar entrada.',
      ];
    }
  }

  $hasGeo = ($lat !== null && $lng !== null);
  // Registros em lote: todos ficam pendentes (fraud_risk_level >= 1) para revisão do admin
  $fraudRiskLevel = 1; // Mínimo para bulk
  $pendingReasons = ['Registro em lote (offline sync)'];
  // NC-14: horário não comprovado pela âncora entra explícito na revisão.
  if (!$tempoAut['comprovado'] && !empty($tempoAut['motivo'])) {
    $pendingReasons[] = $tempoAut['motivo'];
  }
  if (!$geoOk) {
    $fraudRiskLevel = max($fraudRiskLevel, 2);
    $pendingReasons[] = 'Fora da área de geofence';
  }
  // Lacuna (b): drift excessivo entre relógio do cliente e HLB no momento da
  // captura offline. Sinal de relógio adulterado ou dispositivo dessincronizado.
  // Não bloqueia (servidor usa $now), só marca como suspeito para o admin.
  if (defined('HLB_MAX_OFFSET_SECONDS') && abs($hlbOffsetSeconds) > HLB_MAX_OFFSET_SECONDS) {
    $fraudRiskLevel = max($fraudRiskLevel, 3);
    $pendingReasons[] = 'hlb_drift_excessive';
  }
  $pendingReasonsJson = json_encode($pendingReasons, JSON_UNESCAPED_UNICODE);
  // Bulk check-ins (sincronização offline) SEMPRE pendentes para revisão do admin.
  $approvedNow = null;

  if (in_array($action, ['entrada', 'iniciar_intervalo', 'retornar_intervalo'], true)) {
    // Tipo da nova linha + parent (apenas para intervalo).
    $insertRecordType = ($action === 'iniciar_intervalo') ? 'break' : 'work';
    $parentAttendanceIdForInsert = ($action === 'iniciar_intervalo' && $open) ? (int)$open['id'] : null;

    // Dedupe lógico apenas faz sentido para 'entrada' (registros novos).
    // Para iniciar/retornar intervalo, o dedup via client_id continua atuante.
    if ($action === 'entrada') {
      $logicalDup = find_logical_duplicate($pdo, $teacherId, $today, 'entrada', $authoritativeTime, 5);
      if ($logicalDup) {
        try {
          audit_log('duplicate_ignored', 'attendance', (int)$logicalDup['id'], [
            'reason'             => 'logical_dedupe_entrada',
            'rejected_client_id' => $clientId,
            'kept_attendance_id' => (int)$logicalDup['id'],
            'kept_nsr'           => (int)$logicalDup['nsr'],
            'teacher_id'         => $teacherId,
            'date'               => $today,
            'client_recorded_at' => $authoritativeTime,
            'bulk'               => true,
          ]);
        } catch (Throwable $_) {}
        return [
          'status'        => 'ok',
          'code'          => 'duplicate_ignored',
          'action'        => 'entrada',
          'message'       => 'Entrada equivalente já registrada — esta tentativa foi ignorada.',
          'attendance_id' => (int)$logicalDup['id'],
          'nsr'           => (int)$logicalDup['nsr'],
        ];
      }
    }
    $pdo->beginTransaction();
    try {
      // Para iniciar_intervalo/retornar_intervalo, fecha o $open atomicamente antes do INSERT.
      if (($action === 'iniciar_intervalo' || $action === 'retornar_intervalo') && $open) {
        $closeStmt = $pdo->prepare("UPDATE attendance
            SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND check_out IS NULL");
        $closeStmt->execute([$authoritativeTime, $lat, $lng, $acc, (int)$open['id']]);
        if ($closeStmt->rowCount() === 0) {
          $pdo->rollBack();
          return [
            'status'  => 'discarded',
            'code'    => 'state_changed',
            'message' => 'Item descartado: estado do colaborador mudou antes do processamento.',
          ];
        }

        // Livro fiscal — SAÍDA do registro fechado (NSR próprio, NC-09).
        $nsrOutClose = nsr_ledger_record_mark($pdo, [
          'teacher_id' => $teacherId, 'teacher_cpf' => $cpf,
          'school_id' => $matchedSchoolId, 'attendance_id' => (int)$open['id'],
          'mark_role' => 'out', 'direction' => 'S',
          'record_type' => ($open['record_type'] ?? 'work'),
          'marked_at' => $authoritativeTime,
          'work_date' => (string)($open['date'] ?? $today),
          'origin' => 'bulk_offline', 'record_mode' => $recordMode,
          'method' => 'bulk_cpf_pin', 'recorded_at' => $recordedAt,
          'synced_at' => $syncedAt,
          'lat' => $lat, 'lng' => $lng, 'acc' => $acc, 'ip' => $ip,
          'device_identifier' => $deviceIdentifier,
        ]);
        if ($nsrOutClose !== null) {
          $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
              ->execute([$nsrOutClose, (int)$open['id']]);
        }
      }

      // NSR: o livro fiscal é a autoridade quando ligado (ver nsr_legacy_reserve).
      $nextNsr = nsr_legacy_reserve($pdo);

      // BUG-005: client_id só vai ao INSERT se não-vazio (DEFAULT NULL da
      // coluna preserva idempotência por NULL ≠ NULL no UNIQUE do MySQL).
      $stmt = $pdo->prepare("INSERT INTO attendance
        (teacher_id, school_id, date, check_in, method, ip, user_agent, check_in_lat, check_in_lng, check_in_acc, photo, approved,
         record_mode, recorded_at, synced_at, hlb_sync_status, hlb_offset_seconds, device_identifier,
         fraud_risk_level, pending_reasons, is_overtime_candidate, overtime_justification, nsr, client_id,
         record_type, parent_attendance_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      // Lacuna (a): $authoritativeTime substitui $now em check_in.
      $stmt->execute([
        $teacherId, $matchedSchoolId, $today, $authoritativeTime, 'bulk_cpf_pin', $ip, $ua, $lat, $lng, $acc, $filename, $approvedNow,
        $recordMode, $recordedAt, $syncedAt, $hlbSyncStatus, $hlbOffsetSeconds, $deviceIdentifier,
        $fraudRiskLevel, $pendingReasonsJson, $isOvertimeCandidate, $overtimeJustification, $nextNsr,
        $clientId !== '' ? $clientId : null,
        $insertRecordType, $parentAttendanceIdForInsert
      ]);
      $attendanceId = $pdo->lastInsertId();

      // Livro fiscal — ENTRADA vinda da fila offline.
      $nsrIn = nsr_ledger_record_mark($pdo, [
        'teacher_id' => $teacherId, 'teacher_cpf' => $cpf,
        'school_id' => $matchedSchoolId, 'attendance_id' => (int)$attendanceId,
        'mark_role' => 'in', 'direction' => 'E',
        'record_type' => $insertRecordType,
        'marked_at' => $authoritativeTime, 'work_date' => $today,
        'origin' => 'bulk_offline', 'record_mode' => $recordMode,
        'method' => 'bulk_cpf_pin', 'recorded_at' => $recordedAt,
        'synced_at' => $syncedAt,
        'lat' => $lat, 'lng' => $lng, 'acc' => $acc, 'ip' => $ip,
        'device_identifier' => $deviceIdentifier, 'photo' => $filename,
      ]);
      if ($nsrIn !== null) {
        $pdo->prepare("UPDATE attendance SET legacy_nsr = COALESCE(legacy_nsr, nsr), nsr = ? WHERE id = ?")
            ->execute([$nsrIn, (int)$attendanceId]);
      }

      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      // BUG-005: race entre dois SWs/abas com o mesmo client_id → o 2º
      // colide no UNIQUE. Resolvido como already_registered, não como erro.
      if ($clientId !== '' && $e instanceof PDOException
          && (int)$e->errorInfo[1] === 1062
          && stripos((string)$e->getMessage(), 'client_id') !== false) {
        try {
          $stExist = $pdo->prepare("SELECT id, nsr FROM attendance WHERE client_id = ? AND teacher_id = ? LIMIT 1");
          $stExist->execute([$clientId, $teacherId]);
          if ($row = $stExist->fetch(PDO::FETCH_ASSOC)) {
            return [
              'status' => 'ok',
              'code' => 'already_registered',
              'message' => 'Item já registrado por sync paralelo.',
              'attendance_id' => (int)$row['id'],
              'nsr' => (int)$row['nsr'],
            ];
          }
        } catch (Throwable $_) {}
      }
      throw $e;
    }
    
    audit_log('create','attendance',$attendanceId,['teacher_id'=>$teacherId,'type'=>'checkin','bulk'=>true,'geo_ok'=>$geoOk,'matched_school_id'=>$matchedSchoolId,'acc'=>$acc,'is_overtime'=>$isOvertimeCandidate]);
    
    // NSR já foi gerado no INSERT
    $nsr = $nextNsr;
    
    $response = [
      'status'=>'ok',
      'action'=>$action, // 'entrada' | 'iniciar_intervalo' | 'retornar_intervalo'
      'record_type'=>$insertRecordType,
      'parent_attendance_id'=>$parentAttendanceIdForInsert,
      'time'=>$now,
      'photo'=>$filename ? 'photos/' . $filename : null,
      'teacher'=>['id'=>$teacherId,'name'=>$prof['name']],
      'nsr'=>$nsr,
      'attendance_id'=>$attendanceId,
      'record_mode'=>$recordMode,
    ];

    return $response;
  } else {
    // Dedupe lógico de saída: já existe check_out equivalente nesta data?
    $logicalDup = find_logical_duplicate($pdo, $teacherId, $today, 'saida', $authoritativeTime, 5);
    if ($logicalDup) {
      try {
        audit_log('duplicate_ignored', 'attendance', (int)$logicalDup['id'], [
          'reason'             => 'logical_dedupe_saida',
          'rejected_client_id' => $clientId,
          'kept_attendance_id' => (int)$logicalDup['id'],
          'kept_nsr'           => (int)$logicalDup['nsr'],
          'teacher_id'         => $teacherId,
          'date'               => $today,
          'client_recorded_at' => $authoritativeTime,
          'bulk'               => true,
        ]);
      } catch (Throwable $_) {}
      return [
        'status'        => 'ok',
        'code'          => 'duplicate_ignored',
        'action'        => 'saída',
        'message'       => 'Saída equivalente já registrada — esta tentativa foi ignorada.',
        'attendance_id' => (int)$logicalDup['id'],
        'nsr'           => (int)$logicalDup['nsr'],
      ];
    }

    $parseTime = static function (?string $str): ?DateTime {
        if (!$str) return null;
        $str = trim($str);
        $fmt = strlen($str) === 5 ? 'H:i' : 'H:i:s';
        return DateTime::createFromFormat($fmt, $str) ?: null;
    };

    $pdo->beginTransaction();
    try {
      // BUG-005 (rev): grava checkout_client_id para que retries posteriores
      // sejam dedupados pelo pre-check acima. NULL preservado para saídas
      // sem client_id (compatibilidade com queues legadas).
      // Lacuna (a): $authoritativeTime substitui $now em check_out.
      $stmt = $pdo->prepare("UPDATE attendance
          SET check_out = ?, check_out_lat = ?, check_out_lng = ?, check_out_acc = ?, updated_at = CURRENT_TIMESTAMP,
              method = 'bulk_cpf_pin',
              fraud_risk_level = GREATEST(COALESCE(fraud_risk_level, 0), ?),
              pending_reasons = ?,
              approved = NULL,
              checkout_client_id = ?
          WHERE id = ? AND check_out IS NULL AND removed_at IS NULL AND superseded_by_id IS NULL");
      $stmt->execute([$authoritativeTime, $lat, $lng, $acc, $fraudRiskLevel, $pendingReasonsJson, ($clientId !== '' ? $clientId : null), $open['id']]);
      // HOTFIX 2026-09: guarda de estado — não sobrescreve saída/anulação feita no meio.
      if ($stmt->rowCount() === 0) {
          throw new RuntimeException('state_changed: registro aberto foi alterado durante a sincronização');
      }

      // Livro fiscal — SAÍDA (NSR próprio; antes reaproveitava o da entrada).
      $nsrOut = nsr_ledger_record_mark($pdo, [
        'teacher_id' => $teacherId, 'teacher_cpf' => $cpf,
        'school_id' => $open['school_id'] ?? null, 'attendance_id' => (int)$open['id'],
        'mark_role' => 'out', 'direction' => 'S',
        'record_type' => ($open['record_type'] ?? 'work'),
        'marked_at' => $authoritativeTime,
        'work_date' => (string)$open['date'],
        'origin' => 'bulk_offline', 'record_mode' => $recordMode,
        'method' => 'bulk_cpf_pin', 'recorded_at' => $recordedAt,
        'synced_at' => $syncedAt,
        'lat' => $lat, 'lng' => $lng, 'acc' => $acc, 'ip' => $ip,
        'device_identifier' => $deviceIdentifier,
      ]);
      if ($nsrOut !== null) {
        $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
            ->execute([$nsrOut, (int)$open['id']]);
      }

      // Recalcula delta usando a data da entrada original ($open['date']) —
      // turno noturno fechado hoje deve creditar/debitar no dia da entrada.
      $tolerance = (int)(get_setting('tolerance_minutes', '5') ?? '5');
      $openDate = $open['date'];
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
      audit_log('update','attendance',$open['id'],['teacher_id'=>$teacherId,'type'=>'checkout','delta'=>$delta,'bulk'=>true,'geo_ok'=>$geoOk,'acc'=>$acc]);

      // Busca NSR
      $stmtNsr = $pdo->prepare("SELECT nsr FROM attendance WHERE id = ?");
      $stmtNsr->execute([$open['id']]);
      $nsr = $stmtNsr->fetchColumn();

      return [
        'status'=>'ok',
        'action'=>'saída',
        'time'=>$now,
        'photo'=>null,
        'teacher'=>['id'=>$teacherId,'name'=>$prof['name']],
        'nsr'=>$nsr,
        'attendance_id'=>$open['id'],
        'record_mode'=>$recordMode
      ];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // BUG-005 (rev): race entre dois SWs/abas reenviando a mesma saída →
      // colisão no UNIQUE de checkout_client_id. Resolve como already_registered
      // em vez de erro genérico (mesmo padrão usado na entrada).
      if ($clientId !== '' && $e instanceof PDOException
          && (int)$e->errorInfo[1] === 1062
          && stripos((string)$e->getMessage(), 'checkout_client_id') !== false) {
        try {
          $stExist = $pdo->prepare("SELECT id, nsr FROM attendance WHERE checkout_client_id = ? AND teacher_id = ? LIMIT 1");
          $stExist->execute([$clientId, $teacherId]);
          if ($row = $stExist->fetch(PDO::FETCH_ASSOC)) {
            return [
              'status' => 'ok',
              'code' => 'already_registered',
              'action' => 'saída',
              'message' => 'Saída já registrada por sync paralelo.',
              'attendance_id' => (int)$row['id'],
              'nsr' => (int)$row['nsr'],
            ];
          }
        } catch (Throwable $_) {}
      }
      return ['status'=>'error','message'=>'Falha ao fechar ponto.'];
    }
  }
}

// Ordenação cronológica: processa primeiro a tentativa mais antiga do usuário.
// Em batch com várias tentativas duplicadas do mesmo ponto, a 1ª (do horário
// real da intenção) é a que vence; as demais ficam como duplicate_ignored.
if (!empty($payload['items']) && is_array($payload['items'])) {
  usort($payload['items'], function ($a, $b) {
    $ta = strtotime((string)($a['recordedAt'] ?? $a['client_recorded_at'] ?? 'now'));
    $tb = strtotime((string)($b['recordedAt'] ?? $b['client_recorded_at'] ?? 'now'));
    return $ta <=> $tb;
  });
}

$results = [];
// HOTFIX 2026-09: lote limitado (cada item faz bcrypt + ~20 queries; um POST com
// centenas de itens prendia o worker até o timeout). O cliente reenvia o resto.
$maxBulkItems = 50;
foreach ($payload['items'] as $idx => $item) {
  if ($idx >= $maxBulkItems) {
    $results[] = ['index'=>$idx, 'response'=>['status'=>'error','code'=>'batch_limit','message'=>'Limite do lote atingido; será reenviado.']];
    continue;
  }
  try {
    $results[] = ['index'=>$idx, 'response'=>process_check_item($pdo, $item, $tzBR)];
  } catch (Throwable $e) {
    // HOTFIX 2026-09: detalhe técnico só no log, nunca na resposta ao usuário.
    error_log('[checkin_bulk] item ' . $idx . ': ' . $e->getMessage());
    $results[] = ['index'=>$idx, 'response'=>['status'=>'error','message'=>'Falha ao processar este registro. Tente novamente.']];
  }
}


echo json_encode(['status'=>'ok','results'=>$results]);

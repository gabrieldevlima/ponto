<?php
declare(strict_types=1);

function esc($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X-CSRF-TOKEN'] ?? $_POST['csrf'] ?? $_POST['csrf_token'] ?? $_GET['csrf'] ?? $_GET['csrf_token'] ?? '';
    if (!$token || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','message'=>'CSRF token inválido']);
        exit;
    }
}

function require_admin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

function is_admin_logged() {
    return !empty($_SESSION['admin_id']);
}

/**
 * Retorna os dados do admin logado (usa a função db() definida em config.php).
 */
function current_admin(PDO $pdo = null): ?array {
    if (empty($_SESSION['admin_id'])) return null;
    if (isset($_SESSION['_admin_cache']) && is_array($_SESSION['_admin_cache'])) {
        return $_SESSION['_admin_cache'];
    }
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("SELECT a.*, s.name AS school_name
                         FROM admins a
                         LEFT JOIN schools s ON s.id = a.school_id
                         WHERE a.id = ?");
    $st->execute([(int)$_SESSION['admin_id']]);
    $adm = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $_SESSION['_admin_cache'] = $adm;
    return $adm;
}

function is_network_admin(array $admin = null): bool {
    $admin = $admin ?? current_admin();
    return $admin && ($admin['role'] ?? '') === 'network_admin';
}

function is_school_admin(array $admin = null): bool {
    $admin = $admin ?? current_admin();
    return $admin && ($admin['role'] ?? '') === 'school_admin';
}

/**
 * Cláusula de escopo por escola.
 * Para admins de rede: sem restrição (1=1).
 * Para admins de escola: apenas professores vinculados à sua school_id.
 */
function admin_scope_where(string $teacherAlias = 't'): array {
    $adm = current_admin();
    if (!$adm || is_network_admin($adm)) {
        return ['1=1', []];
    }
    $schoolId = (int)($adm['school_id'] ?? 0);
    if ($schoolId <= 0) return ['0=1', []];
    $sql = "EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = {$teacherAlias}.id AND ts.school_id = ?)";
    return [$sql, [$schoolId]];
}

/**
 * Log de auditoria.
 */
function audit_log(string $action, string $entity, $entity_id = null, array $payload = []): void {
    try {
        $pdo = db();
        $st = $pdo->prepare("INSERT INTO audit_logs (admin_id, action, entity, entity_id, payload, ip)
                             VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([
            $_SESSION['admin_id'] ?? null,
            $action,
            $entity,
            (string)($entity_id ?? ''),
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Throwable $e) {
        // Não interromper fluxo em caso de falha no log
    }
}

/**
 * Login de admin.
 */
function admin_login(string $username, string $password): bool {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, username, password_hash, role, school_id FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($password, $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_username'] = $row['username'];
        $_SESSION['admin_name'] = $row['username'];
        $_SESSION['_admin_cache'] = $row;
        audit_log('login', 'admin', $row['id'], ['username'=>$row['username']]);
        return true;
    }
    return false;
}

function admin_logout(): void {
    if (!empty($_SESSION['admin_id'])) {
        audit_log('logout', 'admin', $_SESSION['admin_id']);
    }
    unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_name'], $_SESSION['_admin_cache']);
}

/**
 * Cria admin padrão (apenas ambiente de dev). Em produção, remova após criar seu admin.
 */
function ensure_default_admin(): void {
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
          id INT AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(100) UNIQUE NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          role ENUM('network_admin','school_admin') NOT NULL DEFAULT 'network_admin',
          school_id INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        if ($count === 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?, ?, 'network_admin')");
            $stmt->execute(['admin', $hash]);
        }
    } catch (Throwable $e) {
        // Ignora em ambientes onde não pode criar tabela automaticamente
    }
}

/**
 * Key-Value settings.
 */
function get_setting(string $key, $default = null): ?string {
    // OTIMIZAÇÃO: Cache em memória para evitar queries repetidas
    static $cache = [];
    
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT v FROM app_settings WHERE k = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $result = $v !== false ? (string)$v : ($default === null ? null : (string)$default);
        $cache[$key] = $result;
        return $result;
    } catch (Throwable $e) {
        $result = $default === null ? null : (string)$default;
        $cache[$key] = $result;
        return $result;
    }
}

/**
 * Permissões finas por role (baseline).
 * network_admin: permitido por padrão.
 * school_admin: verifica tabela permissions; se não houver regra, permite.
 */
function has_permission(string $permKey): bool {
    $adm = current_admin();
    if (!$adm) return false;
    if (is_network_admin($adm)) return true;
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT allow FROM permissions WHERE role = ? AND perm_key = ?");
        $st->execute([$adm['role'], $permKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return true;
        return (int)$row['allow'] === 1;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Distância Euclidiana entre dois vetores (utilitário).
 */
function euclidean_distance(array $a, array $b): float {
    $sum = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $d = ((float)$a[$i]) - ((float)$b[$i]);
        $sum += $d * $d;
    }
    return sqrt($sum);
}

/**
 * Reconhecimento facial avancado com 3 camadas de validacao.
 *
 * Camada 1: Threshold — distancia minima deve ser menor que o threshold configuravel.
 * Camada 2: Margem — gap minimo entre o melhor e o 2o melhor match (teacher diferente).
 * Camada 3: Consenso — fracao minima dos descriptors armazenados devem bater.
 *
 * @param PDO $pdo Conexao PDO
 * @param array $descriptor Array de 128 floats (descriptor facial capturado)
 * @param string $mode 'checkin' (mais rigoroso) ou 'identify' (mais permissivo)
 * @return array ['matched'=>bool, 'teacher'=>?array, 'best_distance'=>float,
 *                'consensus_hits'=>int, 'consensus_total'=>int, 'margin'=>?float,
 *                'rejection_reason'=>?string, 'all_candidates'=>array]
 */
function match_face_against_teachers(PDO $pdo, array $descriptor, string $mode = 'checkin'): array {
    // Thresholds configuraveis via app_settings
    $thresholdKey = $mode === 'identify' ? 'face_threshold_identify' : 'face_threshold_checkin';
    $threshold     = (float)(get_setting($thresholdKey, $mode === 'identify' ? '0.50' : '0.45') ?? '0.45');
    $marginMin     = (float)(get_setting('face_margin_min', '0.10') ?? '0.10');
    $consensusRatio = (float)(get_setting('face_consensus_ratio', '0.40') ?? '0.40');

    $emptyResult = [
        'matched' => false,
        'teacher' => null,
        'best_distance' => PHP_FLOAT_MAX,
        'consensus_hits' => 0,
        'consensus_total' => 0,
        'margin' => null,
        'rejection_reason' => 'no_candidates',
        'all_candidates' => []
    ];

    // Busca todos os professores ativos com face cadastrada
    $stmt = $pdo->query("
        SELECT id, name, pin_hash, active, network_wide, cpf, face_descriptors
        FROM teachers
        WHERE active = 1 AND face_descriptors IS NOT NULL AND face_descriptors != ''
    ");

    $candidates = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stored = json_decode($row['face_descriptors'], true);
        if (!is_array($stored) || empty($stored)) continue;

        $bestDist = PHP_FLOAT_MAX;
        $hits = 0;
        $total = 0;

        foreach ($stored as $ref) {
            if (!is_array($ref) || count($ref) !== 128) continue;
            $total++;
            $dist = euclidean_distance($descriptor, $ref);
            if ($dist < $bestDist) {
                $bestDist = $dist;
            }
            // Consenso: conta quantos descriptors armazenados estao abaixo do threshold
            if ($dist < $threshold) {
                $hits++;
            }
        }

        if ($total === 0) continue;

        $candidates[] = [
            'teacher' => $row,
            'best_distance' => $bestDist,
            'consensus_hits' => $hits,
            'consensus_total' => $total,
        ];
    }

    if (empty($candidates)) {
        return $emptyResult;
    }

    // Ordena por melhor distancia (menor = melhor match)
    usort($candidates, fn($a, $b) => $a['best_distance'] <=> $b['best_distance']);

    $best = $candidates[0];
    $secondBest = count($candidates) > 1 ? $candidates[1] : null;

    // ========== CAMADA 1: Threshold ==========
    if ($best['best_distance'] >= $threshold) {
        return array_merge($emptyResult, [
            'best_distance' => $best['best_distance'],
            'rejection_reason' => 'threshold',
            'all_candidates' => $candidates
        ]);
    }

    // ========== CAMADA 2: Margem entre 1o e 2o ==========
    $margin = $secondBest ? ($secondBest['best_distance'] - $best['best_distance']) : 1.0;
    if ($margin < $marginMin) {
        return array_merge($emptyResult, [
            'best_distance' => $best['best_distance'],
            'margin' => $margin,
            'rejection_reason' => 'margin',
            'all_candidates' => $candidates
        ]);
    }

    // ========== CAMADA 3: Consenso ==========
    $requiredHits = (int)ceil($best['consensus_total'] * $consensusRatio);
    // Com apenas 1 descriptor armazenado, consenso e automatico se threshold passou
    if ($best['consensus_total'] > 1 && $best['consensus_hits'] < $requiredHits) {
        return array_merge($emptyResult, [
            'best_distance' => $best['best_distance'],
            'consensus_hits' => $best['consensus_hits'],
            'consensus_total' => $best['consensus_total'],
            'margin' => $margin,
            'rejection_reason' => 'consensus',
            'all_candidates' => $candidates
        ]);
    }

    // ========== MATCH APROVADO ==========
    // Remove face_descriptors do retorno para nao vazar dados biometricos
    $teacherData = $best['teacher'];
    unset($teacherData['face_descriptors']);

    return [
        'matched' => true,
        'teacher' => $teacherData,
        'best_distance' => $best['best_distance'],
        'consensus_hits' => $best['consensus_hits'],
        'consensus_total' => $best['consensus_total'],
        'margin' => $margin,
        'rejection_reason' => null,
        'all_candidates' => [] // Nao expor candidatos em caso de sucesso
    ];
}

/**
 * Detecta conflito facial: verifica se um descriptor ja pertence a outro professor.
 * Usado durante o cadastro de face para impedir que o mesmo rosto seja cadastrado
 * em multiplas pessoas.
 *
 * @param PDO $pdo Conexao PDO
 * @param array $descriptors Array de descriptors a verificar
 * @param int $excludeTeacherId ID do professor atual (excluir da busca)
 * @return array|null null se nao houver conflito, ou ['teacher_id'=>int, 'teacher_name'=>string, 'distance'=>float]
 */
function find_face_conflict(PDO $pdo, array $descriptors, int $excludeTeacherId): ?array {
    $conflictThreshold = (float)(get_setting('face_conflict_threshold', '0.35') ?? '0.35');

    $stmt = $pdo->prepare("
        SELECT id, name, face_descriptors
        FROM teachers
        WHERE id != ? AND active = 1 AND face_descriptors IS NOT NULL AND face_descriptors != ''
    ");
    $stmt->execute([$excludeTeacherId]);

    while ($other = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $otherDescs = json_decode($other['face_descriptors'], true);
        if (!is_array($otherDescs)) continue;

        foreach ($descriptors as $newDesc) {
            if (!is_array($newDesc) || count($newDesc) !== 128) continue;
            foreach ($otherDescs as $existingDesc) {
                if (!is_array($existingDesc) || count($existingDesc) !== 128) continue;
                $dist = euclidean_distance($newDesc, $existingDesc);
                if ($dist < $conflictThreshold) {
                    return [
                        'teacher_id' => (int)$other['id'],
                        'teacher_name' => $other['name'],
                        'distance' => round($dist, 4)
                    ];
                }
            }
        }
    }

    return null;
}

/**
 * Distância Haversine (metros) entre dois pontos geográficos.
 */
function haversine_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0; // raio da Terra em metros
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

/**
 * Retorna lista de escolas permitidas para um colaborador com suas geocoordenadas.
 * - Se network_wide = 1: todas as escolas ativas com lat/lng definidos.
 * - Senão: apenas as escolas vinculadas em teacher_schools com lat/lng definidos.
 */
function get_teacher_allowed_schools(PDO $pdo, int $teacherId): array {
    // OTIMIZAÇÃO: Cache em memória para evitar queries repetidas
    static $cache = [];
    
    if (isset($cache[$teacherId])) {
        return $cache[$teacherId];
    }
    
    $st = $pdo->prepare("SELECT network_wide FROM teachers WHERE id = ?");
    $st->execute([$teacherId]);
    $nw = (int)($st->fetchColumn() ?: 0);
    if ($nw === 1) {
        $q = $pdo->query("SELECT id, name, lat, lng FROM schools WHERE active=1 AND lat IS NOT NULL AND lng IS NOT NULL");
        $result = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $cache[$teacherId] = $result;
        return $result;
    }
    $st2 = $pdo->prepare("SELECT s.id, s.name, s.lat, s.lng
                          FROM teacher_schools ts
                          JOIN schools s ON s.id = ts.school_id
                          WHERE ts.teacher_id = ? AND s.active=1 AND s.lat IS NOT NULL AND s.lng IS NOT NULL");
    $st2->execute([$teacherId]);
    $result = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cache[$teacherId] = $result;
    return $result;
}

/**
 * Encontra a escola mais próxima dentro do raio informado (em metros).
 * Retorna [bool dentro, ?int school_id, ?float distancia_m]
 */
function match_school_by_geo(array $schools, float $lat, float $lng, float $radiusM): array {
    $bestId = null;
    $bestDist = null;
    foreach ($schools as $s) {
        if (!isset($s['lat'], $s['lng'])) continue;
        $d = haversine_distance_m((float)$s['lat'], (float)$s['lng'], $lat, $lng);
        if ($bestDist === null || $d < $bestDist) {
            $bestDist = $d;
            $bestId = (int)$s['id'];
        }
    }
    if ($bestDist !== null && $bestDist <= $radiusM) {
        return [true, $bestId, $bestDist];
    }
    return [false, null, $bestDist];
}

/**
 * Calcula minutos esperados de trabalho para um colaborador em uma data específica.
 * Retorna o total de minutos esperados (já descontando intervalo se mode='time').
 */
function calculate_expected_minutes(PDO $pdo, int $teacherId, string $date): int {
    $weekday = (int)(new DateTime($date))->format('w');
    
    // Detecta o modo de agenda do colaborador
    $stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
    $stMode->execute([$teacherId]);
    $mode = $stMode->fetchColumn() ?: 'classes';
    
    $expMin = 0;
    
    if ($mode === 'classes') {
        $st = $pdo->prepare("SELECT classes_count, class_minutes FROM teacher_schedules WHERE teacher_id=? AND weekday=?");
        $st->execute([$teacherId, $weekday]);
        if ($sc = $st->fetch(PDO::FETCH_ASSOC)) {
            $expMin = (int)$sc['classes_count'] * (int)$sc['class_minutes'];
        }
    } elseif ($mode === 'time') {
        $st = $pdo->prepare("SELECT start_time, end_time, break_minutes FROM collaborator_time_schedules WHERE teacher_id=? AND weekday=?");
        $st->execute([$teacherId, $weekday]);
        if ($ts = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($ts['start_time']) && !empty($ts['end_time'])) {
                $s = DateTime::createFromFormat('H:i:s', $ts['start_time']) ?: DateTime::createFromFormat('H:i', $ts['start_time']);
                $e = DateTime::createFromFormat('H:i:s', $ts['end_time']) ?: DateTime::createFromFormat('H:i', $ts['end_time']);
                if ($s && $e) {
                    if ($e <= $s) $e = (clone $e)->modify('+1 day');
                    $expMin = max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60) - (int)($ts['break_minutes'] ?? 0));
                }
            }
        }
    }
    
    // Se há afastamento remunerado aprovado neste dia, minutos esperados = 0
    $stL = $pdo->prepare("SELECT 1 FROM leaves l JOIN leave_types lt ON lt.id=l.type_id WHERE l.teacher_id=? AND l.approved=1 AND lt.paid=1 AND ? BETWEEN l.start_date AND l.end_date LIMIT 1");
    $stL->execute([$teacherId, $date]);
    if ($stL->fetchColumn()) {
        $expMin = 0;
    }
    
    return $expMin;
}

/**
 * Calcula minutos trabalhados APROVADOS em uma data específica.
 * Retorna o total de minutos trabalhados.
 */
function calculate_worked_minutes(PDO $pdo, int $teacherId, string $date): int {
    $stW = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE teacher_id=? AND date=? AND check_in IS NOT NULL AND check_out IS NOT NULL AND approved = 1");
    $stW->execute([$teacherId, $date]);
    $worked = 0;
    while ($r = $stW->fetch(PDO::FETCH_ASSOC)) {
        if ($r['check_in'] && $r['check_out']) {
            $ci = new DateTime($r['check_in']);
            $co = new DateTime($r['check_out']);
            if ($co > $ci) {
                $worked += (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
            }
        }
    }
    return $worked;
}

/**
 * Detecta e cria solicitação de hora extra automaticamente ao registrar saída.
 * Deve ser chamado APÓS o registro de check_out e ANTES do commit da transação.
 * 
 * @param PDO $pdo Conexão PDO
 * @param int $attendanceId ID do registro de attendance que acabou de ser fechado
 * @param int $teacherId ID do colaborador
 * @param int|null $schoolId ID da escola (pode ser null)
 * @param string $date Data do ponto (formato Y-m-d)
 * @return array ['created' => bool, 'overtime_minutes' => int, 'message' => string]
 */
function detect_and_create_overtime(PDO $pdo, int $attendanceId, int $teacherId, ?int $schoolId, string $date): array {
    // Verifica se já existe solicitação para este attendance
    $stCheck = $pdo->prepare("SELECT id FROM overtime_requests WHERE attendance_id = ?");
    $stCheck->execute([$attendanceId]);
    if ($stCheck->fetch()) {
        return ['created' => false, 'overtime_minutes' => 0, 'message' => 'Solicitação de hora extra já existe'];
    }
    
    // Busca o registro de ponto completo
    $stAtt = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE id = ?");
    $stAtt->execute([$attendanceId]);
    $att = $stAtt->fetch(PDO::FETCH_ASSOC);
    
    if (!$att || !$att['check_in'] || !$att['check_out']) {
        return ['created' => false, 'overtime_minutes' => 0, 'message' => 'Ponto incompleto'];
    }
    
    $ci = new DateTime($att['check_in']);
    $co = new DateTime($att['check_out']);
    $workedThisPunch = (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
    
    // Calcula minutos esperados
    $expectedMinutes = calculate_expected_minutes($pdo, $teacherId, $date);
    
    if ($expectedMinutes <= 0) {
        return ['created' => false, 'overtime_minutes' => 0, 'message' => 'Sem jornada esperada neste dia'];
    }
    
    // Calcula total trabalhado no dia (incluindo este punch)
    $workedMinutes = calculate_worked_minutes($pdo, $teacherId, $date);
    
    // Detecta hora extra: qualquer minuto trabalhado acima do esperado (SEM tolerância)
    $overtimeMinutes = $workedMinutes - $expectedMinutes;
    
    if ($overtimeMinutes <= 0) {
        return ['created' => false, 'overtime_minutes' => 0, 'message' => 'Sem horas extras detectadas'];
    }
    
    // Cria solicitação de hora extra
    $stInsert = $pdo->prepare("INSERT INTO overtime_requests (attendance_id, teacher_id, school_id, date, minutes, expected_minutes, worked_minutes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stInsert->execute([$attendanceId, $teacherId, $schoolId, $date, $overtimeMinutes, $expectedMinutes, $workedMinutes]);
    
    audit_log('create', 'overtime_request', $pdo->lastInsertId(), [
        'attendance_id' => $attendanceId,
        'teacher_id' => $teacherId,
        'overtime_minutes' => $overtimeMinutes
    ]);
    
    return [
        'created' => true,
        'overtime_minutes' => $overtimeMinutes,
        'message' => sprintf('Hora extra de %d minutos detectada e aguarda aprovação', $overtimeMinutes)
    ];
}

/**
 * Auto-rejeita solicitações de hora extra quando um ponto é rejeitado.
 * Deve ser chamado quando um attendance.approved é alterado para 0 (rejeitado).
 * 
 * @param PDO $pdo Conexão PDO
 * @param int $attendanceId ID do attendance rejeitado
 * @param int $adminId ID do admin que está rejeitando
 * @return int Número de solicitações auto-rejeitadas
 */
function auto_reject_overtime_on_attendance_rejection(PDO $pdo, int $attendanceId, int $adminId): int {
    $stUpdate = $pdo->prepare("UPDATE overtime_requests SET status = 'rejected', rejection_reason = 'Ponto rejeitado pelo administrador', approved_by_admin_id = ?, approved_at = NOW() WHERE attendance_id = ? AND status = 'pending'");
    $stUpdate->execute([$adminId, $attendanceId]);
    
    $affected = $stUpdate->rowCount();
    
    if ($affected > 0) {
        audit_log('update', 'overtime_request', null, [
            'action' => 'auto_reject',
            'attendance_id' => $attendanceId,
            'count' => $affected,
            'reason' => 'Ponto rejeitado'
        ]);
    }
    
    return $affected;
}

/**
 * Aprova uma solicitação de hora extra.
 * Adiciona os minutos ao banco de horas com source='overtime_approved'.
 * 
 * @param PDO $pdo Conexão PDO
 * @param int $overtimeId ID da solicitação de hora extra
 * @param int $adminId ID do admin aprovando
 * @return array ['success' => bool, 'message' => string]
 */
function approve_overtime_request(PDO $pdo, int $overtimeId, int $adminId): array {
    $pdo->beginTransaction();
    try {
        // Busca a solicitação
        $st = $pdo->prepare("SELECT ot.*, a.approved as attendance_approved FROM overtime_requests ot JOIN attendance a ON a.id = ot.attendance_id WHERE ot.id = ? FOR UPDATE");
        $st->execute([$overtimeId]);
        $ot = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$ot) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitação não encontrada'];
        }
        
        if ($ot['status'] !== 'pending') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitação já foi processada'];
        }
        
        // Valida: só pode aprovar hora extra se o ponto estiver aprovado
        if ($ot['attendance_approved'] != 1) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Não é possível aprovar hora extra de um ponto pendente ou rejeitado'];
        }
        
        // Aprova a solicitação
        $stUpdate = $pdo->prepare("UPDATE overtime_requests SET status = 'approved', approved_by_admin_id = ?, approved_at = NOW() WHERE id = ?");
        $stUpdate->execute([$adminId, $overtimeId]);
        
        // Adiciona ao banco de horas
        $stBank = $pdo->prepare("INSERT INTO hour_bank_entries (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id, created_by_admin_id) VALUES (?, ?, ?, ?, ?, 'overtime_approved', ?, ?)");
        $stBank->execute([
            $ot['teacher_id'],
            $ot['school_id'],
            $ot['date'],
            $ot['minutes'],
            'Hora extra aprovada',
            $ot['attendance_id'],
            $adminId
        ]);
        
        audit_log('update', 'overtime_request', $overtimeId, [
            'action' => 'approve',
            'minutes' => $ot['minutes'],
            'teacher_id' => $ot['teacher_id']
        ]);
        
        $pdo->commit();
        return ['success' => true, 'message' => sprintf('Hora extra de %d minutos aprovada e adicionada ao banco de horas', $ot['minutes'])];
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao aprovar: ' . $e->getMessage()];
    }
}

/**
 * Rejeita uma solicitação de hora extra.
 * 
 * @param PDO $pdo Conexão PDO
 * @param int $overtimeId ID da solicitação de hora extra
 * @param int $adminId ID do admin rejeitando
 * @param string $reason Motivo da rejeição (obrigatório)
 * @return array ['success' => bool, 'message' => string]
 */
function reject_overtime_request(PDO $pdo, int $overtimeId, int $adminId, string $reason): array {
    if (empty(trim($reason))) {
        return ['success' => false, 'message' => 'Motivo da rejeição é obrigatório'];
    }
    
    $pdo->beginTransaction();
    try {
        // Busca a solicitação
        $st = $pdo->prepare("SELECT * FROM overtime_requests WHERE id = ? FOR UPDATE");
        $st->execute([$overtimeId]);
        $ot = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$ot) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitação não encontrada'];
        }
        
        if ($ot['status'] !== 'pending') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitação já foi processada'];
        }
        
        // Rejeita a solicitação
        $stUpdate = $pdo->prepare("UPDATE overtime_requests SET status = 'rejected', rejection_reason = ?, approved_by_admin_id = ?, approved_at = NOW() WHERE id = ?");
        $stUpdate->execute([trim($reason), $adminId, $overtimeId]);
        
        audit_log('update', 'overtime_request', $overtimeId, [
            'action' => 'reject',
            'reason' => $reason,
            'teacher_id' => $ot['teacher_id']
        ]);
        
        $pdo->commit();
        return ['success' => true, 'message' => 'Hora extra rejeitada'];
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao rejeitar: ' . $e->getMessage()];
    }
}

/**
 * Verifica se uma data é dia útil considerando calendário de exceções
 * @param PDO $pdo
 * @param string $date Data no formato Y-m-d
 * @param int|null $schoolId ID da escola (null = toda a rede)
 * @return bool
 */
function is_working_day(PDO $pdo, string $date, ?int $schoolId = null): bool {
    // Verifica dia da semana (0=domingo, 6=sábado)
    $dayOfWeek = (int)date('w', strtotime($date));
    
    // Verifica se há exceção cadastrada
    $sql = "SELECT is_working_day FROM calendar_exceptions 
            WHERE date = ? AND (school_id IS NULL OR school_id = ?) 
            ORDER BY school_id DESC LIMIT 1"; // Prioriza escola específica
    $st = $pdo->prepare($sql);
    $st->execute([$date, $schoolId]);
    $exception = $st->fetchColumn();
    
    if ($exception !== false) {
        // Há exceção: retorna o valor de is_working_day
        return (bool)$exception;
    }
    
    // Sem exceção: seg-sex são úteis (1-5), sab-dom não são (0,6)
    return ($dayOfWeek >= 1 && $dayOfWeek <= 5);
}

/**
 * Calcula o tamanho total de um diretório em bytes
 */
function get_directory_size(string $path): int {
    $totalSize = 0;
    if (!is_dir($path)) {
        return 0;
    }
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao calcular tamanho do diretório {$path}: " . $e->getMessage());
    }
    
    return $totalSize;
}

/**
 * Limpa fotos antigas do sistema de ponto automaticamente
 * 
 * @param PDO $pdo Conexão com banco de dados
 * @param bool $force Forçar limpeza mesmo se threshold não foi atingido
 * @return array Estatísticas da limpeza
 */
function cleanup_old_photos(PDO $pdo, bool $force = false): array {
    $photosDir = __DIR__ . '/public/photos/';
    $retentionDays = defined('PHOTO_RETENTION_DAYS') ? PHOTO_RETENTION_DAYS : 90;
    $thresholdMB = defined('PHOTO_STORAGE_THRESHOLD_MB') ? PHOTO_STORAGE_THRESHOLD_MB : 500;
    $thresholdBytes = $thresholdMB * 1024 * 1024;
    
    // Calcula tamanho atual do armazenamento
    $currentSize = get_directory_size($photosDir);
    $currentSizeMB = round($currentSize / 1024 / 1024, 2);
    
    // Verifica se precisa executar limpeza
    if (!$force && $currentSize < $thresholdBytes) {
        return [
            'status' => 'skipped',
            'reason' => 'threshold_not_reached',
            'current_size_mb' => $currentSizeMB,
            'threshold_mb' => $thresholdMB,
            'retention_days' => $retentionDays
        ];
    }
    
    // Calcula data de corte
    $cutoffDate = date('Y-m-d', strtotime("-{$retentionDays} days"));
    
    // Busca fotos antigas que ainda não foram deletadas
    $stmt = $pdo->prepare("
        SELECT id, photo, date
        FROM attendance 
        WHERE photo IS NOT NULL 
          AND photo != ''
          AND photo_deleted = 0 
          AND date < ?
        ORDER BY date ASC
    ");
    $stmt->execute([$cutoffDate]);
    $oldPhotos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $deleted = 0;
    $freed = 0;
    $errors = [];
    
    foreach ($oldPhotos as $row) {
        $filepath = $photosDir . $row['photo'];
        
        if (file_exists($filepath)) {
            try {
                $size = filesize($filepath);
                
                if (@unlink($filepath)) {
                    // Atualiza registro no banco
                    $updateStmt = $pdo->prepare("
                        UPDATE attendance 
                        SET photo_deleted = 1, photo_deleted_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$row['id']]);
                    
                    $deleted++;
                    $freed += $size;
                } else {
                    $errors[] = "Falha ao deletar arquivo: {$row['photo']}";
                }
            } catch (Exception $e) {
                $errors[] = "Erro ao processar {$row['photo']}: " . $e->getMessage();
                error_log("Photo cleanup error: " . $e->getMessage());
            }
        } else {
            // Arquivo não existe mais, apenas marca como deletado no banco
            try {
                $updateStmt = $pdo->prepare("
                    UPDATE attendance 
                    SET photo_deleted = 1, photo_deleted_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$row['id']]);
            } catch (Exception $e) {
                $errors[] = "Erro ao atualizar registro {$row['id']}: " . $e->getMessage();
            }
        }
    }
    
    $freedMB = round($freed / 1024 / 1024, 2);
    $newSize = get_directory_size($photosDir);
    $newSizeMB = round($newSize / 1024 / 1024, 2);
    
    // Log da operação
    $logMsg = sprintf(
        "Photo cleanup: %d fotos deletadas, %.2f MB liberados (de %.2f MB para %.2f MB)",
        $deleted,
        $freedMB,
        $currentSizeMB,
        $newSizeMB
    );
    error_log($logMsg);
    
    return [
        'status' => 'completed',
        'deleted_count' => $deleted,
        'freed_space_mb' => $freedMB,
        'current_size_mb' => $newSizeMB,
        'previous_size_mb' => $currentSizeMB,
        'cutoff_date' => $cutoffDate,
        'retention_days' => $retentionDays,
        'total_candidates' => count($oldPhotos),
        'errors' => $errors,
        'error_count' => count($errors)
    ];
}

/**
 * Retorna array de datas que são feriados em um período
 * @param PDO $pdo
 * @param string $startDate
 * @param string $endDate
 * @param int|null $schoolId
 * @return array [date => ['name' => ..., 'type' => ...]]
 */
function get_holidays_in_period(PDO $pdo, string $startDate, string $endDate, ?int $schoolId = null): array {
    $sql = "SELECT date, name, type, is_working_day FROM calendar_exceptions 
            WHERE date BETWEEN ? AND ? 
              AND (school_id IS NULL OR school_id = ?)
              AND is_working_day = 0
            ORDER BY date";
    $st = $pdo->prepare($sql);
    $st->execute([$startDate, $endDate, $schoolId]);
    
    $holidays = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $holidays[$row['date']] = [
            'name' => $row['name'],
            'type' => $row['type']
        ];
    }
    
    return $holidays;
}

/**
 * =====================================================================
 * FUNÇÕES PARA GRADE HORÁRIA E PERÍODOS DE AULA
 * =====================================================================
 */

/**
 * Retorna os períodos de aula disponíveis para uma escola (ou globais se school_id = null)
 */
function get_class_periods(?int $schoolId = null): array {
    $pdo = db();
    if ($schoolId === null) {
        // Retorna períodos globais
        $st = $pdo->prepare("SELECT * FROM class_periods WHERE school_id IS NULL AND active = 1 ORDER BY period_number");
        $st->execute();
    } else {
        // Retorna períodos da escola ou globais
        $st = $pdo->prepare("SELECT * FROM class_periods WHERE (school_id = ? OR school_id IS NULL) AND active = 1 ORDER BY period_number");
        $st->execute([$schoolId]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca os horários/períodos atribuídos a um professor em um determinado dia da semana
 */
function buscar_horarios_professor(int $teacherId, int $weekday, ?int $schoolId = null): array {
    $pdo = db();
    
    $sql = "SELECT tca.*, cp.period_number, cp.start_time, cp.end_time 
            FROM teacher_class_assignments tca
            JOIN class_periods cp ON cp.id = tca.period_id
            WHERE tca.teacher_id = ? AND tca.weekday = ? AND cp.active = 1";
    
    $params = [$teacherId, $weekday];
    
    if ($schoolId !== null) {
        $sql .= " AND (tca.school_id = ? OR tca.school_id IS NULL)";
        $params[] = $schoolId;
    }
    
    $sql .= " ORDER BY cp.period_number";
    
    $st = $pdo->prepare($sql);
    $st->execute($params);
    
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Identifica qual período está ativo no momento atual (com tolerância)
 * Retorna o period_id ou null se não houver período ativo
 */
function identificar_periodo_atual(array $assignments, string $nowTime = null, int $toleranceMinutes = 15): ?array {
    if (empty($assignments)) {
        return null;
    }
    
    if ($nowTime === null) {
        $nowTime = date('H:i:s');
    }
    
    $nowTimestamp = strtotime($nowTime);
    
    foreach ($assignments as $assignment) {
        $startTime = strtotime($assignment['start_time']);
        $endTime = strtotime($assignment['end_time']);
        
        // Adiciona tolerância (antes e depois)
        $startWithTolerance = $startTime - ($toleranceMinutes * 60);
        $endWithTolerance = $endTime + ($toleranceMinutes * 60);
        
        if ($nowTimestamp >= $startWithTolerance && $nowTimestamp <= $endWithTolerance) {
            return $assignment;
        }
    }
    
    return null;
}

/**
 * Verifica se um professor já registrou ponto para um determinado período em uma data
 */
function verificar_periodo_registrado(int $teacherId, string $date, int $periodId): bool {
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) FROM attendance 
                         WHERE teacher_id = ? AND date = ? AND class_period_id = ?");
    $st->execute([$teacherId, $date, $periodId]);
    return ((int)$st->fetchColumn()) > 0;
}

/**
 * Retorna o próximo número de sequência para um registro de attendance em uma data
 */
function get_next_attendance_sequence(int $teacherId, string $date): int {
    $pdo = db();
    $st = $pdo->prepare("SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM attendance 
                         WHERE teacher_id = ? AND date = ?");
    $st->execute([$teacherId, $date]);
    return (int)$st->fetchColumn();
}

/**
 * Conta quantos períodos um professor tem em um dia da semana
 */
function count_teacher_periods(int $teacherId, int $weekday, ?int $schoolId = null): int {
    $assignments = buscar_horarios_professor($teacherId, $weekday, $schoolId);
    return count($assignments);
}

/**
 * Retorna todos os registros de attendance de um professor em uma data (múltiplos check-ins)
 */
function get_teacher_attendance_records(int $teacherId, string $date): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT a.*, cp.period_number, cp.start_time, cp.end_time
                         FROM attendance a
                         LEFT JOIN class_periods cp ON cp.id = a.class_period_id
                         WHERE a.teacher_id = ? AND a.date = ?
                         ORDER BY a.sequence_number, a.check_in");
    $st->execute([$teacherId, $date]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calcula o total de minutos trabalhados baseado em múltiplos registros do dia
 * (soma apenas os períodos com check-in e check-out completos)
 */
function calculate_worked_minutes_from_periods(array $records): int {
    $totalMinutes = 0;
    
    foreach ($records as $record) {
        if (!empty($record['check_in']) && !empty($record['check_out'])) {
            $in = new DateTime($record['check_in']);
            $out = new DateTime($record['check_out']);
            
            if ($out > $in) {
                $minutes = (int)(($out->getTimestamp() - $in->getTimestamp()) / 60);
                $totalMinutes += $minutes;
            }
        }
    }
    
    return $totalMinutes;
}

/**
 * Verifica se um professor usa o sistema de grade horária (múltiplos check-ins)
 * ou o sistema tradicional (um check-in por dia)
 */
function teacher_uses_period_system(int $teacherId): bool {
    // OTIMIZAÇÃO: Cache em memória para evitar queries repetidas durante mesma requisição
    static $cache = [];
    
    if (isset($cache[$teacherId])) {
        return $cache[$teacherId];
    }
    
    $pdo = db();
    // Verifica se há atribuições de períodos para este professor
    $st = $pdo->prepare("SELECT COUNT(*) FROM teacher_class_assignments WHERE teacher_id = ? LIMIT 1");
    $st->execute([$teacherId]);
    $result = ((int)$st->fetchColumn()) > 0;
    $cache[$teacherId] = $result;
    return $result;
}

/**
 * =====================================================================
 * FUNÇÕES PARA HORAS EXTRAS (OVERTIME)
 * =====================================================================
 */

/**
 * Verifica se um horário está dentro da grade atribuída ao professor
 * Retorna true se está dentro, false se está fora (candidato a hora extra)
 */
function is_time_within_assigned_periods(int $teacherId, int $weekday, string $time, int $toleranceMinutes = 15): bool {
    $assignments = buscar_horarios_professor($teacherId, $weekday);
    
    if (empty($assignments)) {
        return false; // Sem atribuições = fora da grade
    }
    
    $period = identificar_periodo_atual($assignments, $time, $toleranceMinutes);
    return $period !== null;
}

/**
 * Cria um registro de solicitação de hora extra
 */
function create_overtime_request(PDO $pdo, int $attendanceId, int $teacherId, ?int $schoolId, string $date, int $minutes, int $expectedMinutes, int $workedMinutes, ?string $justification = null): int {
    $stmt = $pdo->prepare("INSERT INTO overtime_requests 
        (attendance_id, teacher_id, school_id, date, minutes, expected_minutes, worked_minutes, justification, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
    
    $stmt->execute([
        $attendanceId,
        $teacherId,
        $schoolId,
        $date,
        $minutes,
        $expectedMinutes,
        $workedMinutes,
        $justification
    ]);
    
    return (int)$pdo->lastInsertId();
}

/**
 * Retorna candidatos a hora extra pendentes de aprovação
 */
function get_pending_overtime_candidates(?int $schoolId = null, ?int $teacherId = null, int $limit = 100): array {
    $pdo = db();
    
    $sql = "SELECT a.*, t.name as teacher_name, s.name as school_name,
                   ct.name as type_name
            FROM attendance a
            JOIN teachers t ON t.id = a.teacher_id
            LEFT JOIN schools s ON s.id = a.school_id
            LEFT JOIN collaborator_types ct ON ct.id = t.type_id
            WHERE a.is_overtime_candidate = 1 AND a.approved IS NULL";
    
    $params = [];
    
    if ($schoolId !== null) {
        $sql .= " AND a.school_id = ?";
        $params[] = $schoolId;
    }
    
    if ($teacherId !== null) {
        $sql .= " AND a.teacher_id = ?";
        $params[] = $teacherId;
    }
    
    $sql .= " ORDER BY a.date DESC, a.check_in DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calcula total de horas extras aprovadas para um professor em um período
 */
function calculate_approved_overtime(int $teacherId, string $startDate, string $endDate): array {
    $pdo = db();
    
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total_requests,
        SUM(minutes) as total_minutes,
        SUM(minutes) / 60.0 as total_hours
        FROM overtime_requests 
        WHERE teacher_id = ? 
        AND date BETWEEN ? AND ? 
        AND status = 'approved'");
    
    $stmt->execute([$teacherId, $startDate, $endDate]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'total_requests' => (int)($result['total_requests'] ?? 0),
        'total_minutes' => (int)($result['total_minutes'] ?? 0),
        'total_hours' => (float)($result['total_hours'] ?? 0.0)
    ];
}

/**
 * Calcula valor monetário de horas extras
 */
function calculate_overtime_payment(int $totalMinutes, float $baseSalary, int $expectedMinutes, float $multiplier = 1.5): float {
    if ($expectedMinutes <= 0) {
        return 0.0;
    }
    
    $minuteValue = $baseSalary / $expectedMinutes;
    return $totalMinutes * $minuteValue * $multiplier;
}

/**
 * Retorna configuração de overtime
 */
function get_overtime_setting(string $key, $default = null) {
    $fullKey = 'overtime_' . $key;
    return get_setting($fullKey, $default);
}
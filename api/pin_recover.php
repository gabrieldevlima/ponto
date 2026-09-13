<?php
// Recuperação de PIN ("Esqueci meu PIN").
// Fluxo:
//   1. Recebe CPF (+ opcional geo + deviceFingerprint + face_descriptor)
//   2. Se device confiável E geofence OK E sem falhas recentes -> gera novo PIN direto
//   3. Caso suspeito -> exige face_descriptor; se OK, gera novo PIN
//   4. Sem face cadastrada + suspeita -> bloqueio (orienta procurar suporte)

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 7200');
    http_response_code(204);
    exit;
}

if (ob_get_level()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../config.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

function recover_error(int $httpCode, string $code, string $message, array $hints = []): void {
    if (ob_get_level()) ob_clean();
    http_response_code($httpCode);
    echo json_encode([
        'status'  => 'error',
        'code'    => $code,
        'message' => $message,
        'hints'   => $hints,
    ], JSON_UNESCAPED_UNICODE);
    if (ob_get_level()) ob_end_flush();
    exit;
}

function face_dist_recover(array $a, array $b): float {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $d = (float)$a[$i] - (float)$b[$i];
        $sum += $d * $d;
    }
    return sqrt($sum);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    recover_error(405, 'method_not_allowed', 'Método não permitido.');
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    recover_error(400, 'invalid_json', 'Dados inválidos.');
}

// M1: CSRF só é exigido quando há sessão ativa (fluxo "Mudar PIN" logado).
if (is_collaborator_logged()) {
    $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfToken || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrfToken)) {
        recover_error(403, 'csrf_invalid', 'Token de sessão inválido. Recarregue a página.');
    }
}

$cpf = preg_replace('/\D/', '', (string)($input['cpf'] ?? ''));
if (strlen($cpf) !== 11 || !validar_cpf($cpf)) {
    recover_error(400, 'cpf_invalid', 'CPF inválido.', ['Verifique se digitou o CPF corretamente.']);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientFp = (string)($input['deviceFingerprint'] ?? '');
$identifier = hash('sha256', $ip . '|' . substr($ua, 0, 120) . '|' . $cpf);
// H6: floor por CPF independente de IP (pré-lookup). Fecha bypass por rotação
// de IP + CPFs variados — sem isso, um atacante que já excedeu o limite por
// (ip|ua|cpf) pode trocar de IP e recomeçar a contagem.
$cpfIdentifier = 'cpf:' . hash('sha256', $cpf);

$pdo = db();

// Rate limit rigoroso: 5 falhas em 30min (spec §1.3 / §2.4)
$rl = auth_attempt_is_limited($pdo, 'pin_recovery', $identifier, 1800, 5);
if (!empty($rl['limited'])) {
    recover_error(429, 'too_many_attempts', 'Muitas tentativas de recuperação. Aguarde 30 minutos.', [
        'Se você esqueceu seu PIN e não consegue recuperar, procure o administrador.'
    ]);
}
// Rate limit composto por CPF (H6): 10 falhas em 1h independente de IP.
[$cpfLimited] = array_values(auth_attempt_is_limited($pdo, 'pin_recovery', $cpfIdentifier, 3600, 10));
if ($cpfLimited) {
    auth_attempt_log($pdo, 'pin_recovery', $cpfIdentifier, false, null, 'cpf_rate_limited');
    recover_error(429, 'too_many_attempts', 'Muitas tentativas para este CPF. Aguarde 1 hora.', [
        'Procure o administrador se precisar de ajuda urgente.'
    ]);
}

$st = $pdo->prepare("SELECT id, name, cpf, active, pin_hash, face_descriptors
                     FROM teachers WHERE cpf = ? LIMIT 1");
$st->execute([$cpf]);
$teacher = $st->fetch(PDO::FETCH_ASSOC);

if (!$teacher || (int)$teacher['active'] !== 1) {
    // Log em ambos: por (ip|ua|cpf) e por CPF isolado (alimenta o floor H6).
    auth_attempt_log($pdo, 'pin_recovery', $identifier, false, null, 'cpf_not_found');
    auth_attempt_log($pdo, 'pin_recovery', $cpfIdentifier, false, null, 'cpf_not_found');
    // Mesma mensagem para não vazar existência do CPF.
    // HTTP 200 para não poluir console; frontend trata via data.code.
    recover_error(200, 'teacher_not_found', 'Não foi possível recuperar o PIN.', [
        'Procure o administrador se precisa de ajuda.'
    ]);
}

$teacherId = (int)$teacher['id'];
$hasFace   = !empty($teacher['face_descriptors']);

// C3: Rate-limit adicional por teacher_id — fecha bypass por IP-hopping.
// 10 recoveries tentadas / 1h para o mesmo teacher bloqueiam novas tentativas.
[$teacherLimited] = array_values(auth_attempt_is_limited($pdo, 'pin_recovery', 'teacher:' . $teacherId, 3600, 10));
if ($teacherLimited) {
    auth_attempt_log($pdo, 'pin_recovery', 'teacher:' . $teacherId, false, $teacherId, 'teacher_rate_limited');
    recover_error(200, 'too_many_attempts', 'Muitas tentativas para este colaborador. Aguarde 1 hora.', [
        'Procure o administrador se precisar de ajuda urgente.'
    ]);
}

// Helper local: registra falha em ambos os identifiers (user-specific e
// teacher-specific). Isso alimenta corretamente a janela C3 por teacher_id,
// fechando o bypass por IP-hopping (sem esse mirror, o contador só cresceria
// depois do bloqueio — inútil).
$logRecoveryFail = function(string $reason, array $details = []) use ($pdo, $identifier, $cpfIdentifier, $teacherId) {
    auth_attempt_log($pdo, 'pin_recovery', $identifier, false, $teacherId, $reason, $details);
    auth_attempt_log($pdo, 'pin_recovery', 'teacher:' . $teacherId, false, $teacherId, $reason, $details);
    // H6: espelha no identifier por CPF para manter o floor coerente.
    auth_attempt_log($pdo, 'pin_recovery', $cpfIdentifier, false, $teacherId, $reason, $details);
};

// Se o colaborador ainda não tem PIN, recuperação não se aplica — direciona
// para o fluxo de "Primeiro acesso".
if (empty($teacher['pin_hash'])) {
    $logRecoveryFail('pin_not_set');
    recover_error(200, 'pin_not_set', 'Você ainda não tem um PIN cadastrado.', [
        'Use "Primeiro acesso" para gerar seu PIN inicial.'
    ]);
}

$fingerprint = trusted_device_fingerprint($ua, $ip, $clientFp);
$deviceKnown = $fingerprint !== '' && trusted_device_is_known($pdo, $teacherId, $fingerprint);

// Geofence: avaliamos se for fornecida geo; sem geo, consideramos "não confiável"
$geo = is_array($input['geo'] ?? null) ? $input['geo'] : null;
$lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
$lng = isset($geo['lng']) ? (float)$geo['lng'] : null;
$geoKnown = false;
if ($lat !== null && $lng !== null) {
    $schools = get_teacher_allowed_schools($pdo, $teacherId);
    if (!empty($schools)) {
        $radiusM = (float)((get_setting('geofence_radius_m', '300') ?? '300'));
        [$inRadius] = match_school_by_geo($schools, $lat, $lng, $radiusM);
        $geoKnown = $inRadius === true;
    } else {
        // network_wide ou sem escola configurada: considera ok
        $geoKnown = true;
    }
}

// Falhas recentes APENAS de PIN nos últimos 15 min (alinhado com a política
// de step-up do checkin: 3 falhas em 15min disparam exigência de face).
$recentFailures = 0;
try {
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM auth_attempt_logs
         WHERE teacher_id = ? AND success = 0
           AND attempt_type IN ('pin','pin_recovery')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $st->execute([$teacherId]);
    $recentFailures = (int)$st->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

// ---------------------------------------------------------------------------
// SEGURANÇA (2026-08-05, auditoria de conformidade — NC-01):
// Entre 2026-05 e 2026-08 esta linha era `$trustable = true;` incondicional, o
// que tornava TODO o bloco `if (!$trustable)` abaixo código morto. Consequência:
// qualquer pessoa que soubesse um CPF válido resetava o PIN do colaborador em
// uma única requisição. Como o CPF circula em crachás, contracheques e listas
// de RH, isso era takeover de conta — permitia bater ponto no lugar de outro.
//
// A justificativa original (falhas de reconhecimento facial em produção por
// iluminação/câmera/óculos) é legítima como problema de usabilidade, mas a
// resposta correta não é remover o segundo fator do fluxo de recuperação de
// credencial: é oferecer caminhos alternativos (dispositivo já confiável dentro
// do geofence, ou atendimento pelo admin).
//
// Contexto confiável = dispositivo já conhecido DESTE colaborador + dentro do
// raio de uma escola dele + sem falhas de autenticação recentes. Isso é
// "algo que você tem" + "algum lugar onde você está" — segundo fator real.
// Qualquer outro contexto cai no step-up facial abaixo.
$trustable = $deviceKnown && $geoKnown && $recentFailures === 0;

$faceDescriptor = $input['face_descriptor'] ?? null;
$faceProvided   = is_array($faceDescriptor) && count($faceDescriptor) === 128;
if ($faceProvided) {
    foreach ($faceDescriptor as $v) {
        if (!is_numeric($v) || !is_finite((float)$v)) {
            recover_error(400, 'invalid_face_descriptor', 'Dados faciais inválidos.');
        }
    }
}

$faceEnrolledNow = false;

if (!$trustable) {
    if (!$hasFace) {
        // ------------------------------------------------------------------
        // Sem face cadastrada em contexto não-confiável (cenário padrão de
        // primeira recuperação em massa). Abrimos a opção de cadastrar face
        // AGORA, mesma lógica de pin_enroll.php no primeiro acesso.
        //
        // Risco aceito: atacante com CPF poderia cadastrar face própria e
        // ganhar acesso. Mitigações:
        //   - find_active_face_conflict bloqueia se a foto colide com outra
        //     pessoa ativa.
        //   - Audit log registra 'face.enrolled_via_recovery' + 'pin.generated_recovery'
        //     no mesmo timestamp — trilha clara para revogação pelo admin.
        //   - Admin pode reativar esta rota setando app_setting
        //     'pin_recovery_allow_face_enroll' = 1 (default: 0).
        //
        // NC-01 (2026-08-05): o default foi invertido de 1 para 0. Com 1, o
        // "risco aceito" acima não tinha mitigação real: find_active_face_conflict
        // só bloqueia se a foto colidir com OUTRA pessoa já cadastrada — a foto
        // de um atacante é nova, não colide com ninguém, e ele sai com o PIN.
        // Para quem não tem face cadastrada, recuperação em dispositivo
        // desconhecido passa a exigir atendimento do administrador.
        // ------------------------------------------------------------------
        $allowInlineEnroll = (get_setting('pin_recovery_allow_face_enroll', '0') ?? '0') === '1';

        if (!$allowInlineEnroll) {
            $logRecoveryFail('blocked_no_face', [
                'device_known' => $deviceKnown,
                'geo_known' => $geoKnown,
                'recent_failures' => $recentFailures
            ]);
            recover_error(200, 'blocked_contact_admin', 'Não foi possível recuperar seu PIN neste dispositivo.', [
                'Procure o administrador para gerar um novo PIN para você.'
            ]);
        }

        if (!$faceProvided) {
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'status'  => 'require_face',
                'reason'  => 'face_enrollment',
                'message' => 'Vamos cadastrar sua foto — ela vai proteger seu ponto daqui para frente.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Cadastro inicial: checa conflito com outros colaboradores ativos
        try {
            $initialDescriptors = normalize_face_descriptors([$faceDescriptor], 20);
        } catch (Throwable $e) {
            recover_error(200, 'invalid_face_descriptor', 'Dados faciais inválidos.');
        }
        $conflict = find_active_face_conflict($pdo, $initialDescriptors, $teacherId);
        if ($conflict !== null) {
            $logRecoveryFail('face_conflict', [
                'conflict_teacher_id' => (int)($conflict['teacher_id'] ?? 0),
                'best_distance' => $conflict['best_distance'] ?? null,
            ]);
            recover_error(200, 'face_conflict', 'Essa foto parece pertencer a outra pessoa.', [
                'Se for realmente você, procure o administrador.'
            ]);
        }

        try {
            $faceJson = face_descriptors_encode($initialDescriptors); // cifrado em repouso (NC-34)
            $pdo->prepare("UPDATE teachers SET face_descriptors = ?, face_enrolled_at = NOW() WHERE id = ?")
                ->execute([$faceJson, $teacherId]);
            $faceEnrolledNow = true;
            audit_log('face.enrolled_via_recovery', 'teacher', $teacherId, [
                'cpf' => mask_cpf($cpf),
                'samples' => count($initialDescriptors)
            ]);
        } catch (Throwable $e) {
            error_log('pin_recover face enroll failed: ' . $e->getMessage());
            recover_error(200, 'face_save_failed', 'Falha ao salvar sua foto. Tente novamente.');
        }

        // Promove device a trusted — usuário acabou de provar identidade pelo cadastro
        if ($fingerprint !== '') {
            trusted_device_enroll($pdo, $teacherId, $fingerprint, 'face_validated');
        }
    } else {
        // Colaborador já tem face — step-up de validação contra a existente.
        if (!$faceProvided) {
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'status'  => 'require_face',
                'reason'  => 'suspicious_context',
                'message' => 'Para sua segurança, precisamos confirmar sua identidade com uma foto.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stored = face_descriptors_decode($teacher['face_descriptors']);
        if (!is_array($stored) || empty($stored)) {
            recover_error(200, 'face_corrupt', 'Cadastro facial inconsistente. Procure o administrador.');
        }
        $thresholds = get_face_thresholds('identify');
        $matchThreshold = (float)$thresholds['match_threshold'];
        $consensusThreshold = (float)$thresholds['consensus_threshold'];
        $minRatio = (float)$thresholds['min_consensus_ratio'];
        $best = PHP_FLOAT_MAX; $hits = 0; $total = 0;
        foreach ($stored as $ref) {
            if (!is_array($ref) || count($ref) !== 128) continue;
            $ok = true;
            foreach ($ref as $rv) { if (!is_numeric($rv) || !is_finite((float)$rv)) { $ok = false; break; } }
            if (!$ok) continue;
            $total++;
            $d = face_dist_recover($faceDescriptor, $ref);
            if ($d < $best) $best = $d;
            if ($d <= $consensusThreshold) $hits++;
        }
        $minHits = max(1, (int)ceil($total * $minRatio));
        $accept = $total > 0 && $best < $matchThreshold && $hits >= $minHits;
        if (!$accept) {
            $logRecoveryFail('face_not_matched', [
                'best' => round($best, 4), 'hits' => $hits, 'total' => $total
            ]);
            recover_error(200, 'face_not_matched', 'Não foi possível confirmar sua identidade pela foto.', [
                'Tente novamente em boa iluminação.',
                'Se continuar falhando, procure o administrador.'
            ]);
        }

        if ($fingerprint !== '') {
            trusted_device_enroll($pdo, $teacherId, $fingerprint, 'face_validated');
        }
    }
}

// Gera novo PIN
try {
    $pin = pin_set_for_teacher($pdo, $teacherId);
} catch (Throwable $e) {
    error_log("pin_recover failed: " . $e->getMessage());
    recover_error(500, 'server_error', 'Erro ao gerar PIN. Tente novamente.');
}

$recoveryPath = $trustable
    ? 'trusted_context'
    : ($faceEnrolledNow ? 'face_first_enroll' : 'face_stepup');

auth_attempt_log($pdo, 'pin_recovery', $identifier, true, $teacherId, 'pin_regenerated', [
    'path' => $recoveryPath
]);
audit_log(
    'pin.generated_recovery',
    'teacher',
    $teacherId,
    [
        'cpf' => mask_cpf($cpf),
        'path' => $recoveryPath,
        'face_enrolled_now' => $faceEnrolledNow,
        'device_known' => $deviceKnown,
        'geo_known' => $geoKnown,
        'ip' => $ip,
        'device_fp_short' => substr($fingerprint, 0, 12)
    ]
);

// NC-01 (2026-08-05): auto-login REMOVIDO deste fluxo.
// Antes, recuperar o PIN estabelecia sessão autenticada e emitia o cookie
// remember-me de 10 anos (helpers.collaborator_login_establish). Isso fazia da
// recuperação de credencial um caminho de autenticação — quem resetava o PIN
// já entrava, sem nunca provar que sabia o PIN.
//
// Recuperar uma credencial e usá-la são passos distintos: o colaborador recebe
// o PIN novo e faz login com ele normalmente. O custo é um passo a mais; o
// ganho é que um reset indevido não vira sessão de 10 anos.
$autoLogin = false;

if (ob_get_level()) ob_clean();
echo json_encode([
    'status'     => 'ok',
    'pin'        => $pin,
    'auto_login' => $autoLogin,
    'teacher' => [
        'id'   => $teacherId,
        'name' => $teacher['name'],
        'cpf'  => mask_cpf($cpf)
    ],
    'message' => 'Novo PIN gerado. Anote com cuidado — ele não será exibido novamente.'
], JSON_UNESCAPED_UNICODE);

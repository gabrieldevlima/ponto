<?php
// Primeiro acesso: geração de PIN inicial para colaborador.
//
// Fluxo deste endpoint:
//   1. Recebe CPF
//   2. Se teacher já tem PIN -> erro (use "Esqueci meu PIN")
//   3. Prova de identidade (ver política detalhada mais abaixo, NC-02):
//        a. face cadastrada            -> exige foto e faz MATCH contra ela
//        b. pin_self_enroll_allowed=1  -> admin liberou nominalmente
//        c. nenhum dos dois            -> bloqueia, direciona ao admin
//   4. Gera PIN, grava hash, audita, retorna PIN em texto claro (exibir UMA vez)
//   5. Registra o device como "trusted" (evita step-up no primeiro check-in)
//
// Segurança ativa:
//   - Rate limit por (ip|ua|cpf) e por CPF isolado (H6)
//   - pin_already_set bloqueia regeneração (atacante não refaz PIN do alvo)
//   - find_active_face_conflict impede vincular rosto de outro colaborador
//   - Audit log registra cada enrollment para visibilidade admin
//
// ATENÇÃO: o CPF NÃO é segredo (circula em crachá, contracheque e listas de
// RH). Nenhum caminho deste endpoint pode tratá-lo como fator de autenticação.

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

function enroll_error(int $httpCode, string $code, string $message, array $hints = []): void {
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

function face_dist_128(array $a, array $b): float {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $d = (float)$a[$i] - (float)$b[$i];
        $sum += $d * $d;
    }
    return sqrt($sum);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    enroll_error(405, 'method_not_allowed', 'Método não permitido.');
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    enroll_error(400, 'invalid_json', 'Dados inválidos.');
}

// M1: CSRF só é exigido quando há sessão ativa (fluxo "Mudar PIN" logado).
// No primeiro acesso anônimo não há sessão, o token é validado por face/admin.
if (is_collaborator_logged()) {
    $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfToken || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrfToken)) {
        enroll_error(403, 'csrf_invalid', 'Token de sessão inválido. Recarregue a página.');
    }
}

$cpf = preg_replace('/\D/', '', (string)($input['cpf'] ?? ''));
if (strlen($cpf) !== 11 || !validar_cpf($cpf)) {
    enroll_error(400, 'cpf_invalid', 'CPF inválido.', ['Verifique se digitou o CPF corretamente.']);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientFp = (string)($input['deviceFingerprint'] ?? '');
$identifier = hash('sha256', $ip . '|' . substr($ua, 0, 120) . '|' . $cpf);
// H6: floor independente de IP — atacante com proxies não pode burlar o limite
// por CPF rotacionando IPs. Usa hash do CPF (não CPF cru nos logs).
$cpfIdentifier = 'cpf:' . hash('sha256', $cpf);

$pdo = db();

// Rate limit composto: por (ip|ua|cpf) E por CPF isolado.
$rl = auth_attempt_is_limited($pdo, 'pin_enrollment', $identifier, 300, 10);
if (!empty($rl['limited'])) {
    enroll_error(429, 'too_many_attempts', 'Muitas tentativas. Aguarde alguns minutos.', [
        'Tente novamente em instantes ou procure o administrador.'
    ]);
}
$rlCpf = auth_attempt_is_limited($pdo, 'pin_enrollment', $cpfIdentifier, 600, 15);
if (!empty($rlCpf['limited'])) {
    enroll_error(429, 'too_many_attempts', 'Muitas tentativas para este CPF. Aguarde 10 minutos.', [
        'Procure o administrador para desbloquear se necessário.'
    ]);
}

// Helper local: espelha falhas em AMBOS os identifiers para que o floor por
// CPF (independente de IP) funcione. auth_attempt_log é silencioso em erro.
$logEnrollFail = function (?int $tId, string $reason, array $details = []) use ($pdo, $identifier, $cpfIdentifier) {
    auth_attempt_log($pdo, 'pin_enrollment', $identifier, false, $tId, $reason, $details);
    auth_attempt_log($pdo, 'pin_enrollment', $cpfIdentifier, false, $tId, $reason, $details);
};

$st = $pdo->prepare("SELECT id, name, cpf, active, pin_hash, face_descriptors, pin_self_enroll_allowed
                     FROM teachers WHERE cpf = ? LIMIT 1");
$st->execute([$cpf]);
$teacher = $st->fetch(PDO::FETCH_ASSOC);

if (!$teacher || (int)$teacher['active'] !== 1) {
    $logEnrollFail(null, 'cpf_not_found');
    enroll_error(200, 'teacher_not_found', 'CPF não encontrado ou colaborador inativo.', [
        'Procure o administrador para verificar seu cadastro.'
    ]);
}

$teacherId = (int)$teacher['id'];

if (!empty($teacher['pin_hash'])) {
    $logEnrollFail($teacherId, 'pin_already_set');
    enroll_error(200, 'pin_already_set', 'Você já tem um PIN.', [
        'Toque em "Esqueci meu PIN" se precisa gerar um novo.'
    ]);
}

$hasFaceEnrolled = !empty($teacher['face_descriptors']);

// ---------------------------------------------------------------------------
// Política do primeiro acesso (revisada em 2026-08-05, auditoria — NC-02).
//
// A política anterior era:
//   - já tem face  → gera PIN direto, sem pedir foto;
//   - não tem face → cadastra QUALQUER foto enviada e gera o PIN.
//
// O "risco aceito" declarado (atacante com CPF cadastra o próprio rosto) não
// tinha mitigação real: find_active_face_conflict só barra uma foto que já
// pertence a OUTRO colaborador — a foto de um atacante é inédita, não colide
// com ninguém, e ele saía com PIN válido e sessão aberta. E o ramo "já tem
// face" não comparava nada: ter rosto no banco não prova quem está do outro
// lado. Resultado: bastava conhecer um CPF.
//
// Política atual, em ordem de precedência:
//   1. pin_self_enroll_allowed = 1 → o admin autorizou nominalmente este
//      colaborador a se auto-cadastrar. É o portão que já existia na coluna
//      (DEFAULT 0) e é gerenciado em admin/teacher_pin_manage.php, mas que
//      este endpoint lia (linha do SELECT) e nunca verificava.
//   2. Tem face cadastrada → exige foto e faz MATCH contra a face armazenada.
//      Prova de identidade de verdade, não mera presença de registro.
//   3. Nenhum dos dois → bloqueia e direciona ao administrador. Não há como
//      provar identidade de quem não tem PIN nem biometria usando só o CPF,
//      que é dado público-por-circulação (crachá, contracheque, lista de RH).
//
// Impacto medido na base atual: 102 colaboradores ativos, 96 já com PIN (nem
// passam por aqui, barrados por pin_already_set) e 5 sem PIN e sem face — os
// únicos afetados pelo caso 3, e exatamente as contas que estavam expostas.
// ---------------------------------------------------------------------------
$selfEnrollAllowed = (int)($teacher['pin_self_enroll_allowed'] ?? 0) === 1;

$faceDescriptor = $input['face_descriptor'] ?? null;
$faceProvided   = is_array($faceDescriptor) && count($faceDescriptor) === 128;
if ($faceProvided) {
    foreach ($faceDescriptor as $v) {
        if (!is_numeric($v) || !is_finite((float)$v)) {
            enroll_error(400, 'invalid_face_descriptor', 'Dados faciais inválidos.');
        }
    }
}

$faceEnrolledNow = false;

if ($hasFaceEnrolled) {
    // -----------------------------------------------------------------------
    // Caso 2 — há biometria cadastrada: ela é a prova de identidade.
    // Exige foto e compara contra a face armazenada (mesma lógica de consenso
    // usada no step-up de pin_recover.php).
    // -----------------------------------------------------------------------
    if (!$faceProvided) {
        if (ob_get_level()) ob_clean();
        echo json_encode([
            'status'  => 'require_face',
            'reason'  => 'identity_check',
            'message' => 'Para sua segurança, confirme sua identidade com uma foto antes de criar o PIN.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stored = face_descriptors_decode($teacher['face_descriptors']);
    if (!is_array($stored) || empty($stored)) {
        $logEnrollFail($teacherId, 'face_corrupt');
        enroll_error(200, 'face_corrupt', 'Cadastro facial inconsistente.', [
            'Procure o administrador para regularizar seu cadastro.'
        ]);
    }

    $thresholds         = get_face_thresholds('identify');
    $matchThreshold     = (float)$thresholds['match_threshold'];
    $consensusThreshold = (float)$thresholds['consensus_threshold'];
    $minRatio           = (float)$thresholds['min_consensus_ratio'];

    $best = PHP_FLOAT_MAX; $hits = 0; $total = 0;
    foreach ($stored as $ref) {
        if (!is_array($ref) || count($ref) !== 128) continue;
        $ok = true;
        foreach ($ref as $rv) { if (!is_numeric($rv) || !is_finite((float)$rv)) { $ok = false; break; } }
        if (!$ok) continue;
        $total++;
        $d = face_dist_128($faceDescriptor, $ref);
        if ($d < $best) $best = $d;
        if ($d <= $consensusThreshold) $hits++;
    }
    $minHits = max(1, (int)ceil($total * $minRatio));
    $accept  = $total > 0 && $best < $matchThreshold && $hits >= $minHits;

    if (!$accept) {
        $logEnrollFail($teacherId, 'face_not_matched', [
            'best' => round($best, 4), 'hits' => $hits, 'total' => $total
        ]);
        enroll_error(200, 'face_not_matched', 'Não foi possível confirmar sua identidade pela foto.', [
            'Tente novamente em boa iluminação.',
            'Se continuar falhando, procure o administrador.'
        ]);
    }
} elseif ($selfEnrollAllowed) {
    // -----------------------------------------------------------------------
    // Caso 1 — sem biometria, mas o admin autorizou o auto-cadastro deste
    // colaborador. Se ele enviar foto, aproveitamos para cadastrar; o conflict
    // check impede vincular um rosto que já pertence a outro colaborador ativo.
    // -----------------------------------------------------------------------
    // Mantém o comportamento em produção (hotfix 2026-09): o cadastro facial
    // acontece ANTES de gerar o PIN para quem pode se auto-cadastrar.
    if (!$faceProvided) {
        if (ob_get_level()) ob_clean();
        echo json_encode([
            'status'  => 'require_face',
            'reason'  => 'face_enrollment',
            'message' => 'Vamos cadastrar sua foto agora — depois geramos seu PIN.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($faceProvided) {
        try {
            $initialDescriptors = normalize_face_descriptors([$faceDescriptor], 20);
        } catch (Throwable $e) {
            enroll_error(200, 'invalid_face_descriptor', 'Dados faciais inválidos.');
        }

        $conflict = find_active_face_conflict($pdo, $initialDescriptors, $teacherId);
        if ($conflict !== null) {
            $logEnrollFail($teacherId, 'face_conflict', [
                'conflict_teacher_id' => (int)($conflict['teacher_id'] ?? 0),
                'best_distance'       => $conflict['best_distance'] ?? null,
            ]);
            enroll_error(200, 'face_conflict', 'Essa foto parece pertencer a outra pessoa.', [
                'Se for realmente você, procure o administrador.'
            ]);
        }

        try {
            $faceJson = face_descriptors_encode($initialDescriptors); // cifrado em repouso (NC-34)
            $pdo->prepare("UPDATE teachers SET face_descriptors = ?, face_enrolled_at = NOW() WHERE id = ?")
                ->execute([$faceJson, $teacherId]);
            $faceEnrolledNow = true;
            audit_log('face.enrolled_first_access', 'teacher', $teacherId, [
                'cpf'     => mask_cpf($cpf),
                'samples' => count($initialDescriptors),
            ]);
        } catch (Throwable $e) {
            error_log("pin_enroll face save failed: " . $e->getMessage());
            enroll_error(200, 'face_save_failed', 'Falha ao salvar sua foto. Tente novamente.');
        }
    }
} else {
    // -----------------------------------------------------------------------
    // Caso 3 — sem biometria e sem autorização do admin. Só há o CPF, que não
    // é segredo. Bloqueia e direciona ao atendimento.
    // -----------------------------------------------------------------------
    $logEnrollFail($teacherId, 'self_enroll_not_allowed');
    enroll_error(200, 'blocked_contact_admin', 'Seu primeiro acesso precisa ser liberado pelo administrador.', [
        'Procure o responsável pelo ponto para liberar seu cadastro.'
    ]);
}

// PIN escolhido pelo usuário. Se ele explicitamente pedir geração, usa
// o aleatório como fallback (botão "Gerar um para mim" no frontend).
$desiredPinRaw = (string)($input['desired_pin'] ?? '');
$desiredPin    = preg_replace('/\D/', '', $desiredPinRaw);
$generateFlag  = !empty($input['generate_pin']);
$customPin     = null;

if ($desiredPin === '' && !$generateFlag) {
    // Nenhuma escolha feita — devolve para o frontend abrir a tela de PIN.
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'status'  => 'require_pin',
        'message' => 'Escolha um PIN de 6 dígitos para usar daqui pra frente.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($desiredPin !== '') {
    [$ok, $weakCode, $weakMsg] = pin_validate_strength($desiredPin, $cpf);
    if (!$ok) {
        $logEnrollFail($teacherId, 'desired_pin_weak', ['code' => $weakCode]);
        enroll_error(200, $weakCode ?: 'pin_too_weak', $weakMsg ?: 'Esse PIN é fácil demais. Escolha outro.');
    }
    $customPin = $desiredPin;
}

try {
    $pin = pin_set_for_teacher($pdo, $teacherId, null, $customPin);
} catch (Throwable $e) {
    error_log("pin_enroll failed: " . $e->getMessage());
    enroll_error(500, 'server_error', 'Erro ao gerar PIN. Tente novamente.');
}
$pinUserChosen = ($customPin !== null);

// Registra device como trusted. Caminho 'face_validated' quando o cadastro
// facial aconteceu nesta request; 'repeated_use' quando já tinha face prévia.
$fingerprint = trusted_device_fingerprint($ua, $ip, $clientFp);
$enrollMethod = $faceEnrolledNow ? 'face_validated' : 'repeated_use';
if ($fingerprint !== '') {
    trusted_device_enroll($pdo, $teacherId, $fingerprint, $enrollMethod);
}

$viaTag = $faceEnrolledNow ? 'face_first_enroll'
        : ($hasFaceEnrolled ? 'with_existing_face' : 'no_face');
auth_attempt_log($pdo, 'pin_enrollment', $identifier, true, $teacherId, 'pin_generated', [
    'via' => $viaTag
]);
audit_log(
    'pin.generated_first_access',
    'teacher',
    $teacherId,
    [
        'cpf' => mask_cpf($cpf),
        'via' => $viaTag,
        'face_enrolled_now'   => $faceEnrolledNow,
        'had_face_previously' => $hasFaceEnrolled,
        'ip'  => $ip,
        'device_fp_short' => substr($fingerprint, 0, 12),
    ]
);

// Auto-login do colaborador: como o PIN acabou de ser gerado e a identidade
// foi validada (face ou flag admin), criamos sessão para dispensar novo login.
$autoLogin = collaborator_login_establish($teacherId, 'pin_enroll');

if (ob_get_level()) ob_clean();
echo json_encode([
    'status'      => 'ok',
    'pin'         => $pin,
    'user_chosen' => $pinUserChosen,
    'auto_login'  => $autoLogin,
    'teacher' => [
        'id'   => $teacherId,
        'name' => $teacher['name'],
        'cpf'  => mask_cpf($cpf)
    ],
    'message' => $pinUserChosen
        ? 'Pronto! Seu PIN foi cadastrado.'
        : 'Seu PIN foi gerado. Anote com cuidado — ele não será exibido novamente.',
], JSON_UNESCAPED_UNICODE);

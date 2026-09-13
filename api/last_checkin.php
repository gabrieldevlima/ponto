<?php
// Endpoint leve: retorna saudação + último registro de ponto do colaborador.
// Usado pelo card "Seu último ponto" na home, quando o CPF está salvo em localStorage.
// NÃO vaza presença de CPF: resposta tem sempre a mesma estrutura (teacher: null quando
// CPF inválido/não existe).

// M3: erros silenciados do cliente (display_errors=0) mas logados no php error log.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
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

// HOTFIX PRIVACIDADE 2026-09: antes respondia nome + estado de presença para
// QUALQUER CPF sem autenticação (rate limit por ip|cpf não impedia varrer CPFs).
// Com sessão de colaborador, usa SEMPRE o próprio cadastro (ignora ?cpf).
// Sem sessão (modo quiosque legado), mantém só o necessário para decidir a
// próxima ação — sem nome — e limita por IP.
$sessionTeacherId = (!empty($_SESSION['collaborator_id']) && is_collaborator_logged())
    ? (int)$_SESSION['collaborator_id'] : 0;
$cpf = preg_replace('/\D/', '', (string)($_GET['cpf'] ?? ''));
$empty = function () {
    if (ob_get_level()) ob_clean();
    echo json_encode(['status' => 'ok', 'teacher' => null, 'last' => null], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($sessionTeacherId === 0 && (strlen($cpf) !== 11 || !validar_cpf($cpf))) {
    $empty();
}

$pdo = db();

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$identifier = hash('sha256', $ip . '|' . $cpf);
if ($sessionTeacherId === 0) {
    $rl = auth_attempt_is_limited($pdo, 'last_checkin', $identifier, 300, 30);
    $ipIdentifier = 'ip:' . hash('sha256', $ip);
    $rlIp = auth_attempt_is_limited($pdo, 'last_checkin', $ipIdentifier, 300, 30);
    if (!empty($rl['limited']) || !empty($rlIp['limited'])) {
        // Silencioso — não bloqueia a home, só não retorna saudação
        $empty();
    }
    // Conta a consulta anônima (auth_attempt_is_limited conta falhas).
    auth_attempt_log($pdo, 'last_checkin', $ipIdentifier, false, null, 'anonymous_lookup');
}

try {
    if ($sessionTeacherId > 0) {
        $stT = $pdo->prepare("SELECT id, name FROM teachers WHERE id = ? AND active = 1 LIMIT 1");
        $stT->execute([$sessionTeacherId]);
    } else {
        $stT = $pdo->prepare("SELECT id, name FROM teachers WHERE cpf = ? AND active = 1 ORDER BY id LIMIT 1");
        $stT->execute([$cpf]);
    }
    $teacher = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$teacher) { $empty(); }

    $teacherId = (int)$teacher['id'];
    $tzBR = new DateTimeZone('America/Sao_Paulo');
    $today = (new DateTimeImmutable('now', $tzBR))->format('Y-m-d');

    // Última interação do colaborador: check-in mais recente de hoje ou do último dia,
    // escolhendo check_out quando existir, senão check_in.
    // record_type pode não existir em ambientes pré-migration — try/catch defensivo.
    try {
        $stA = $pdo->prepare("
            SELECT id, date, check_in, check_out, approved, method, record_type, parent_attendance_id
              FROM attendance
             WHERE teacher_id = ? AND removed_at IS NULL AND superseded_by_id IS NULL
             ORDER BY COALESCE(check_out, check_in) DESC, id DESC
             LIMIT 1
        ");
        $stA->execute([$teacherId]);
        $row = $stA->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $_) {
        $stA = $pdo->prepare("
            SELECT id, date, check_in, check_out, approved, method
              FROM attendance
             WHERE teacher_id = ?
             ORDER BY COALESCE(check_out, check_in) DESC, id DESC
             LIMIT 1
        ");
        $stA->execute([$teacherId]);
        $row = $stA->fetch(PDO::FETCH_ASSOC);
        if ($row) { $row['record_type'] = 'work'; $row['parent_attendance_id'] = null; }
    }

    $last = null;
    if ($row) {
        $hasCheckout = !empty($row['check_out']);
        $ts = $hasCheckout ? $row['check_out'] : $row['check_in'];
        $rowType = $row['record_type'] ?? 'work';
        // Rótulo legível conforme tipo. Para work, detecta contexto:
        //  - check_in com break fechado anterior → "retorno do intervalo"
        //  - check_out com break vinculado (parent_attendance_id) → "saída para intervalo"
        //  - senão, "entrada"/"saída" normais.
        if ($rowType === 'break') {
            $actionLabel = $hasCheckout ? 'retorno do intervalo' : 'início do intervalo';
        } else {
            $actionLabel = $hasCheckout ? 'saída' : 'entrada';
            try {
                if (!$hasCheckout) {
                    // É um work em aberto. É retorno do intervalo se há um break fechado
                    // ANTES desse check_in no mesmo dia.
                    $stPrior = $pdo->prepare("SELECT 1 FROM attendance
                        WHERE teacher_id = ? AND date = ?
                          AND record_type = 'break' AND check_out IS NOT NULL
                          AND check_out <= ? LIMIT 1");
                    $stPrior->execute([$teacherId, (string)$row['date'], (string)$row['check_in']]);
                    if ($stPrior->fetchColumn()) $actionLabel = 'retorno do intervalo';
                } else {
                    // É um work fechado. É "saída para intervalo" se há break com parent=esse work.
                    $stChild = $pdo->prepare("SELECT 1 FROM attendance
                        WHERE parent_attendance_id = ? AND record_type = 'break' LIMIT 1");
                    $stChild->execute([(int)$row['id']]);
                    if ($stChild->fetchColumn()) $actionLabel = 'saída para intervalo';
                }
            } catch (Throwable $_) { /* coluna pode não existir — mantém label padrão */ }
        }
        if ($ts) {
            try {
                $dt = new DateTimeImmutable($ts, $tzBR);
                $last = [
                    'action'   => $actionLabel,
                    'record_type' => $rowType,
                    'time'     => $dt->format('H:i'),
                    'time_iso' => $dt->format(DateTimeInterface::ATOM),
                    'date'     => $dt->format('Y-m-d'),
                    'is_today' => $dt->format('Y-m-d') === $today,
                    'approved' => is_null($row['approved']) ? null : (int)$row['approved'] === 1,
                    'method'   => $row['method'] ?? null,
                ];
            } catch (Throwable $e) { /* ignore */ }
        }
    }

    // Primeira entrada de TRABALHO do dia (record_type='work'), usada pelo PWA para
    // mostrar "Desde HH:MM (primeira entrada) · Retornou às HH:MM" quando o colaborador
    // já passou por um intervalo. Quando ainda está na primeira sessão, esse valor é igual
    // ao check_in da batida atual aberta.
    $firstCheckInToday = null;
    try {
        $stFirst = $pdo->prepare("
            SELECT MIN(check_in) AS first_ci FROM attendance
             WHERE teacher_id = ? AND date = ?
               AND check_in IS NOT NULL
               AND (record_type = 'work' OR record_type IS NULL)
        ");
        $stFirst->execute([$teacherId, $today]);
        $firstRaw = $stFirst->fetchColumn();
        if ($firstRaw) {
            try {
                $firstCheckInToday = (new DateTimeImmutable($firstRaw, $tzBR))->format(DateTimeInterface::ATOM);
            } catch (Throwable $_) {}
        }
    } catch (Throwable $_) { /* coluna pode não existir — segue sem o campo */ }

    // Soma o tempo trabalhado e em intervalo hoje em pares fechados E APROVADOS.
    // - todayClosedMinutes: tempo TRABALHADO (record_type='work', aprovado). NÃO inclui intervalo.
    // - todayBreakMinutes: tempo em INTERVALO (record_type='break', aprovado).
    // - todayNetMinutes: alias de todayClosedMinutes (compatibilidade com frontend antigo).
    //
    // Auditoria: filtro `approved=1` adicionado para alinhar com calculate_effective_worked_minutes
    // (que também filtra) — evita o frontend mostrar valores divergentes entre os dois campos.
    $todayClosedMinutes = 0;
    $todayBreakMinutes  = 0;
    try {
        $stDay = $pdo->prepare("
            SELECT check_in, check_out, record_type FROM attendance
             WHERE teacher_id = ? AND date = ?
               AND check_in IS NOT NULL AND check_out IS NOT NULL
               AND approved = 1
        ");
        $stDay->execute([$teacherId, $today]);
        while ($r = $stDay->fetch(PDO::FETCH_ASSOC)) {
            try {
                $ci = new DateTimeImmutable($r['check_in'], $tzBR);
                $co = new DateTimeImmutable($r['check_out'], $tzBR);
                if ($co > $ci) {
                    $mins = (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
                    if (($r['record_type'] ?? 'work') === 'break') {
                        $todayBreakMinutes += $mins;
                    } else {
                        $todayClosedMinutes += $mins;
                    }
                }
            } catch (Throwable $_) { /* ignore row */ }
        }
    } catch (Throwable $_) {
        // Sem coluna record_type: fallback para query original.
        try {
            $stDay = $pdo->prepare("
                SELECT check_in, check_out FROM attendance
                 WHERE teacher_id = ? AND date = ?
                   AND check_in IS NOT NULL AND check_out IS NOT NULL
            ");
            $stDay->execute([$teacherId, $today]);
            while ($r = $stDay->fetch(PDO::FETCH_ASSOC)) {
                try {
                    $ci = new DateTimeImmutable($r['check_in'], $tzBR);
                    $co = new DateTimeImmutable($r['check_out'], $tzBR);
                    if ($co > $ci) {
                        $todayClosedMinutes += (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
                    }
                } catch (Throwable $_) {}
            }
        } catch (Throwable $_) {}
    }

    // Detecta estado atual (FORA / TRABALHANDO / EM_INTERVALO) e ação sugerida.
    $stateInfo = function_exists('detect_collaborator_state')
        ? detect_collaborator_state($pdo, $teacherId)
        : ['state' => 'fora', 'open_id' => null, 'open_check_in' => null, 'open_record_type' => null, 'parent_work_id' => null];

    $nextActionKey = match ($stateInfo['state']) {
        'fora'         => 'in',
        'trabalhando'  => 'out_or_break_start',
        'em_intervalo' => 'break_end',
        default        => 'in',
    };

    // Cálculo de excesso de intervalo (se há jornada configurada com break_minutes).
    $breakOverage = function_exists('get_break_overage')
        ? get_break_overage($pdo, $teacherId, $today)
        : ['used_min' => $todayBreakMinutes, 'allowed_min' => 0, 'overage_min' => 0];

    // Intervalo PREVISTO na rotina (lido de collaborator_time_schedules.break_minutes).
    // O PWA usa para mostrar "Sua jornada prevê X min de intervalo..." no card de status.
    $expectedBreakMin = function_exists('get_expected_break_minutes')
        ? get_expected_break_minutes($pdo, $teacherId, $today)
        : 0;
    // Trabalhado efetivo (já com desconto automático aplicado quando aplicável).
    $effectiveWorkedMin = function_exists('calculate_effective_worked_minutes')
        ? calculate_effective_worked_minutes($pdo, $teacherId, $today)
        : $todayClosedMinutes;

    if (ob_get_level()) ob_clean();
    echo json_encode([
        'status'  => 'ok',
        'teacher' => ['name' => $sessionTeacherId > 0 ? $teacher['name'] : ''],
        // Entrada aberta de outro dia (saída esquecida): o PWA avisa antes de o
        // colaborador "bater saída" e fechar um registro de 24h sem perceber.
        'open_is_stale'        => !empty($stateInfo['open_check_in'])
            && substr((string)$stateInfo['open_check_in'], 0, 10) !== $today,
        'today_closed_minutes' => $todayClosedMinutes,
        'today_worked_minutes' => $todayClosedMinutes,
        'today_break_minutes'  => $todayBreakMinutes,
        'today_net_minutes'    => $todayClosedMinutes, // já é líquido — não inclui intervalo
        'break_overage_min'    => (int)$breakOverage['overage_min'],
        'break_allowed_min'    => (int)$breakOverage['allowed_min'],
        'expected_break_min'   => (int)$expectedBreakMin,
        'effective_worked_min' => (int)$effectiveWorkedMin,
        'state'                => $stateInfo['state'],
        'next_action_key'      => $nextActionKey,
        'open_id'              => $stateInfo['open_id'] ?? null,
        'open_record_type'     => $stateInfo['open_record_type'],
        'open_check_in'        => $stateInfo['open_check_in'],
        'first_check_in_today' => $firstCheckInToday,
        'today'   => $today,
        'last'    => $last,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('last_checkin failed: ' . $e->getMessage());
    $empty();
}

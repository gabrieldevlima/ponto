<?php
// Ativa exibição de erros para diagnóstico (REMOVER em produção final)
ini_set('display_errors', 0); // 0 para ocultar erros
error_reporting(E_ALL);

// Wrapper de erro para produção
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Dashboard Error: $errstr in $errfile:$errline");
    return true; // Suprime o erro
});

try {
    require_once __DIR__ . '/../../config.php';
    require_admin();
    $pdo = db();
    $admin = current_admin($pdo);
} catch (Throwable $e) {
    // Erro crítico - redireciona para login
    error_log("Dashboard Fatal Error: " . $e->getMessage());
    header('Location: login.php?error=system');
    exit;
}

// Inicializa todas as variáveis com valores seguros
$schoolFilter = isset($_GET['school']) ? (int)$_GET['school'] : 0;
$schools = [];
$totalTeachers = 0;
$expectedToday = 0;
$presentToday = 0;
$pendingToday = 0;
$absentToday = 0;
$workingNow = 0;
$leavesActive = 0;
$toCompensateMin = 0;
$workingNowList = [];
$absentList = [];
$presenceLast30Days = [];
$schoolComparison = [];
$topLeaveTypes = [];
$checkinHeatmap = array_fill(0, 7, array_fill(0, 24, 0));
$presenceTrend = [];
$fraudCount = 0;
$today = date('Y-m-d');
$weekday = (int)date('w');

try {
    // Filtro escola (apenas admin rede)
    if (is_network_admin($admin)) {
      $schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Escopo + filtro escola
    list($scopeSql, $scopeParams) = admin_scope_where('t');
    $whereTeacher = $scopeSql;
    $paramsTeacher = $scopeParams;
    if ($schoolFilter > 0 && is_network_admin($admin)) {
      $whereTeacher .= " AND EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id=t.id AND ts.school_id=?)";
      $paramsTeacher[] = $schoolFilter;
    }

    // Total colaboradores visíveis
    $st = $pdo->prepare("SELECT COUNT(*) FROM teachers t WHERE $whereTeacher");
    $st->execute($paramsTeacher);
    $totalTeachers = (int)$st->fetchColumn();
} catch (Throwable $e) {
    error_log("Dashboard Query Error (basic stats): " . $e->getMessage());
    // Continua com valores padrão
}

// Hoje: esperados (com rotina) e presentes (com attendance)
$today = date('Y-m-d');
$weekday = (int)date('w');

// Esperados (considera classes com cc>0 OU time com start/end)
$stE = $pdo->prepare("
  SELECT COUNT(DISTINCT t.id)
  FROM teachers t
  WHERE $whereTeacher
    AND (
      EXISTS (SELECT 1 FROM teacher_schedules s WHERE s.teacher_id=t.id AND s.weekday=? AND s.classes_count>0)
      OR EXISTS (SELECT 1 FROM collaborator_time_schedules ts WHERE ts.teacher_id=t.id AND ts.weekday=? AND ts.start_time IS NOT NULL AND ts.end_time IS NOT NULL)
    )
");
$stE->execute(array_merge($paramsTeacher, [$weekday, $weekday]));
$expectedToday = (int)$stE->fetchColumn();

// Presentes (attendance APROVADO hoje)
$stP = $pdo->prepare("
  SELECT COUNT(DISTINCT a.teacher_id)
  FROM attendance a
  JOIN teachers t ON t.id=a.teacher_id
  WHERE a.date=? AND a.approved=1 AND $whereTeacher
");
$stP->execute(array_merge([$today], $paramsTeacher));
$presentToday = (int)$stP->fetchColumn();

// Pendentes (tem attendance mas NÃO aprovado ainda)
$stPend = $pdo->prepare("
  SELECT COUNT(DISTINCT a.teacher_id)
  FROM attendance a
  JOIN teachers t ON t.id=a.teacher_id
  WHERE a.date=? AND (a.approved IS NULL OR a.approved=0) AND $whereTeacher
");
$stPend->execute(array_merge([$today], $paramsTeacher));
$pendingToday = (int)$stPend->fetchColumn();

// Ausentes (esperados mas SEM attendance nenhum OU rejeitado).
// Exceção: colaborador em jornada noturna ainda aberta (entrou ontem, sai hoje)
// NÃO é "ausente" — exclui via EXISTS de qualquer registro aberto.
$stAbs = $pdo->prepare("
  SELECT COUNT(DISTINCT t.id)
  FROM teachers t
  WHERE $whereTeacher
    AND (
      EXISTS (SELECT 1 FROM teacher_schedules s WHERE s.teacher_id=t.id AND s.weekday=? AND s.classes_count>0)
      OR EXISTS (SELECT 1 FROM collaborator_time_schedules ts WHERE ts.teacher_id=t.id AND ts.weekday=? AND ts.start_time IS NOT NULL AND ts.end_time IS NOT NULL)
    )
    AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.teacher_id=t.id AND a.date=? AND (a.approved IS NULL OR a.approved IN (0,1)))
    AND NOT EXISTS (SELECT 1 FROM attendance a2 WHERE a2.teacher_id=t.id AND a2.check_in IS NOT NULL AND a2.check_out IS NULL AND " . attendance_vigente_sql('a2') . ")
");
$stAbs->execute(array_merge($paramsTeacher, [$weekday, $weekday, $today]));
$absentToday = (int)$stAbs->fetchColumn();

// Trabalhando agora (check-in feito, check-out ainda não) — sem filtro de date
// para incluir turnos noturnos em curso (entrada de ontem).
$stWorking = $pdo->prepare("
  SELECT COUNT(DISTINCT a.teacher_id)
  FROM attendance a
  JOIN teachers t ON t.id=a.teacher_id
  WHERE a.check_in IS NOT NULL
    AND a.check_out IS NULL
    AND " . attendance_vigente_sql('a') . "
    AND $whereTeacher
");
$stWorking->execute($paramsTeacher);
$workingNow = (int)$stWorking->fetchColumn();

// Lista de nomes dos colaboradores trabalhando agora com cargo, escola e localização
// (inclui turnos noturnos: entrada de ontem ainda aberta).
$stWorkingList = $pdo->prepare("
  SELECT DISTINCT t.id, t.name, a.check_in, ct.name as type_name, s.name as school_name,
         a.check_in_lat, a.check_in_lng
  FROM attendance a
  JOIN teachers t ON t.id=a.teacher_id
  LEFT JOIN collaborator_types ct ON ct.id = t.type_id
  LEFT JOIN schools s ON s.id = a.school_id
  WHERE a.check_in IS NOT NULL
    AND a.check_out IS NULL
    AND " . attendance_vigente_sql('a') . "
    AND $whereTeacher
  ORDER BY t.name
");
$stWorkingList->execute($paramsTeacher);
$workingNowList = $stWorkingList->fetchAll(PDO::FETCH_ASSOC);

// Lista de colaboradores ausentes hoje (esperados mas sem registro)
$stAbsentList = $pdo->prepare("
  SELECT DISTINCT t.id, t.name, ct.name as type_name, 
         GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as schools
  FROM teachers t
  LEFT JOIN collaborator_types ct ON ct.id = t.type_id
  LEFT JOIN teacher_schools ts ON ts.teacher_id = t.id
  LEFT JOIN schools s ON s.id = ts.school_id AND s.active = 1
  WHERE $whereTeacher
    AND (
      EXISTS (SELECT 1 FROM teacher_schedules sch WHERE sch.teacher_id = t.id AND sch.weekday = ? AND sch.classes_count > 0)
      OR EXISTS (SELECT 1 FROM collaborator_time_schedules cts WHERE cts.teacher_id = t.id AND cts.weekday = ? AND cts.start_time IS NOT NULL)
    )
    AND NOT EXISTS (
      SELECT 1 FROM attendance a 
      WHERE a.teacher_id = t.id AND a.date = ? AND (a.approved IS NULL OR a.approved IN (0,1))
    )
    AND NOT EXISTS (
      SELECT 1 FROM leaves l
      WHERE l.teacher_id = t.id AND l.approved = 1 AND l.excuses_absence = 1 AND ? BETWEEN l.start_date AND l.end_date
    )
  GROUP BY t.id, t.name, ct.name
  ORDER BY t.name
");
$stAbsentList->execute(array_merge($paramsTeacher, [$weekday, $weekday, $today, $today]));
$absentList = $stAbsentList->fetchAll(PDO::FETCH_ASSOC);

// Guarda de início de contagem: antes da data global o sistema não conta presenças/faltas.
$countingStart = counting_start_date();
$countingNotStarted = ($countingStart !== null && $today < $countingStart);
if ($countingNotStarted) {
    $expectedToday = 0;
    $presentToday  = 0;
    $pendingToday  = 0;
    $absentToday   = 0;
    $absentList    = [];
}

// Afastados ativos hoje
$stAf = $pdo->prepare("
  SELECT COUNT(DISTINCT l.teacher_id)
  FROM leaves l
  JOIN teachers t ON t.id=l.teacher_id
  WHERE l.approved=1 AND ? BETWEEN l.start_date AND l.end_date
    AND $whereTeacher
");
$stAf->execute(array_merge([$today], $paramsTeacher));
$leavesActive = (int)$stAf->fetchColumn();

// Horas a compensar no mês (informativo): quanto, somando os colaboradores, ainda
// falta para a carga horária prevista. Sem linguagem de hora extra / saldo negativo.
$month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$toCompensateMin = 0;
$compTolerance = (int)(get_setting('tolerance_minutes', '5') ?? '5');

// HOTFIX 2026-09 (desempenho): este bloco fazia ~9.700 consultas por abertura do
// dashboard (colaboradores × dias do mês × 3). A FÓRMULA NÃO MUDOU — as jornadas
// agora são lidas uma vez por colaborador (antes: uma consulta por dia) e o
// total fica em cache por 10 minutos por mês/escopo de admin. Métrica
// informativa; a atualização em até 10 min é aceitável.
$__dashCacheFile = __DIR__ . '/../../logs/.cache_dash_compensate_' . md5($month . '|' . $whereTeacher . '|' . json_encode($paramsTeacher)) . '.json';
$__dashCached = null;
if (is_readable($__dashCacheFile) && (time() - (int)@filemtime($__dashCacheFile)) < 600) {
  $__dashCached = json_decode((string)@file_get_contents($__dashCacheFile), true);
}
if (is_array($__dashCached) && isset($__dashCached['to_compensate_min'])) {
  $toCompensateMin = (int)$__dashCached['to_compensate_min'];
  $teachersList = [];
} else {
// Calcula por colaborador de forma agregada (cuidado: pode custar performance em bases grandes)
$stList = $pdo->prepare("SELECT t.id, t.base_salary, ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id=t.type_id WHERE $whereTeacher");
$stList->execute($paramsTeacher);
$teachersList = $stList->fetchAll(PDO::FETCH_ASSOC);
$stSchedClasses = $pdo->prepare("SELECT weekday, classes_count, class_minutes FROM teacher_schedules WHERE teacher_id=?");
$stSchedTime    = $pdo->prepare("SELECT weekday, start_time, end_time, end_next_day, break_minutes FROM collaborator_time_schedules WHERE teacher_id=?");
foreach ($teachersList as $trow) {
  $tid = (int)$trow['id'];
  $mode = $trow['schedule_mode'] ?? 'classes';
  // Jornadas do colaborador indexadas por dia da semana (1ª linha por weekday,
  // equivalente ao fetch() único da consulta anterior).
  $schedByWeekday = [];
  $stSched = ($mode === 'classes') ? $stSchedClasses : $stSchedTime;
  $stSched->execute([$tid]);
  foreach ($stSched->fetchAll(PDO::FETCH_ASSOC) as $schedRow) {
    $wk = (int)$schedRow['weekday'];
    if (!isset($schedByWeekday[$wk])) $schedByWeekday[$wk] = $schedRow;
  }
  // expected total no mês
  $expected = 0;
  for ($d = new DateTime($monthStart); $d <= new DateTime($monthEnd); $d->modify('+1 day')) {
    $w = (int)$d->format('w');
    if ($mode === 'classes') {
      if ($sc = ($schedByWeekday[$w] ?? null)) $expected += ((int)$sc['classes_count'] * (int)$sc['class_minutes']);
    } else {
      if ($ts = ($schedByWeekday[$w] ?? null)) {
        $win = compute_schedule_window($ts, $d->format('Y-m-d'));
        if ($win) {
          // Janela completa — break_minutes não é descontado (intervalo conta
          // como tempo trabalhado; modelo "cheio vs cheio").
          $expected += max(0, (int)(($win['end']->getTimestamp() - $win['start']->getTimestamp()) / 60));
        }
      }
    }
  }
  // Trabalhado EFETIVO por dia (presença cheia: work + break — helpers.php).
  $worked = 0;
  $cursor = new DateTime($monthStart);
  $endDt  = new DateTime($monthEnd);
  while ($cursor <= $endDt) {
    $worked += calculate_effective_worked_minutes($pdo, (int)$tid, $cursor->format('Y-m-d'));
    $cursor = $cursor->modify('+1 day');
  }
  $delta = $worked - $expected;
  if ($expected > 0 && $delta < 0 && abs($delta) > $compTolerance) {
    $toCompensateMin += -$delta;
  }
}
  @file_put_contents($__dashCacheFile, json_encode(['to_compensate_min' => $toCompensateMin, 'at' => time()]), LOCK_EX);
}

// ============================================================================
// DADOS PARA GRÁFICOS
// ============================================================================

// 1. Evolução de Presença - Últimos 30 dias (com proteção)
$presenceLast30Days = [];
try {
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stDay = $pdo->prepare("
            SELECT COUNT(DISTINCT a.teacher_id) as count
            FROM attendance a
            JOIN teachers t ON t.id = a.teacher_id
            WHERE a.date = ? AND a.approved = 1 AND $whereTeacher
        ");
        $stDay->execute(array_merge([$date], $paramsTeacher));
        $presenceLast30Days[] = [
            'date' => date('d/m', strtotime($date)),
            'count' => (int)$stDay->fetchColumn()
        ];
    }
} catch (Throwable $e) {
    error_log("Dashboard Error (presence 30 days): " . $e->getMessage());
    // Preenche com dados vazios
    for ($i = 29; $i >= 0; $i--) {
        $presenceLast30Days[] = [
            'date' => date('d/m', strtotime("-$i days")),
            'count' => 0
        ];
    }
}

// 3. Comparativo por Escola (apenas network admin)
$schoolComparison = [];
if (is_network_admin($admin)) {
    $stSchools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name");
    while ($school = $stSchools->fetch(PDO::FETCH_ASSOC)) {
        $schoolId = $school['id'];
        
        // Presentes hoje
        $stPres = $pdo->prepare("
            SELECT COUNT(DISTINCT a.teacher_id)
            FROM attendance a
            JOIN teacher_schools ts ON ts.teacher_id = a.teacher_id
            WHERE a.date = ? AND a.approved = 1 AND ts.school_id = ?
        ");
        $stPres->execute([$today, $schoolId]);
        $present = (int)$stPres->fetchColumn();
        
        // Esperados hoje
        $stExp = $pdo->prepare("
            SELECT COUNT(DISTINCT t.id)
            FROM teachers t
            JOIN teacher_schools ts ON ts.teacher_id = t.id
            WHERE ts.school_id = ?
              AND (
                EXISTS (SELECT 1 FROM teacher_schedules s WHERE s.teacher_id = t.id AND s.weekday = ? AND s.classes_count > 0)
                OR EXISTS (SELECT 1 FROM collaborator_time_schedules cts WHERE cts.teacher_id = t.id AND cts.weekday = ? AND cts.start_time IS NOT NULL)
              )
        ");
        $stExp->execute([$schoolId, $weekday, $weekday]);
        $expected = (int)$stExp->fetchColumn();
        
        $absent = max(0, $expected - $present);
        
        $schoolComparison[] = [
            'name' => $school['name'],
            'present' => $present,
            'absent' => $absent
        ];
    }
}

// 4. Top 5 Tipos de Afastamento (mês atual) - com proteção
$topLeaveTypes = [];
try {
    $stLeaves = $pdo->prepare("
        SELECT lt.name, COUNT(*) as count, SUM(DATEDIFF(l.end_date, l.start_date) + 1) as total_days
        FROM leaves l
        JOIN leave_types lt ON lt.id = l.type_id
        JOIN teachers t ON t.id = l.teacher_id
        WHERE l.approved = 1 
          -- Intervalo de datas em vez de DATE_FORMAT(...) = ?: no MariaDB o parâmetro
          -- chega como utf8mb4_general_ci e o DATE_FORMAT como unicode_ci:
          -- Illegal mix of collations (gráfico vazio em produção, erro engolido).
          AND l.start_date >= ? AND l.start_date < (? + INTERVAL 1 MONTH)
          AND $whereTeacher
        GROUP BY lt.id, lt.name
        ORDER BY total_days DESC
        LIMIT 5
    ");
    $stLeaves->execute(array_merge([date('Y-m-01'), date('Y-m-01')], $paramsTeacher));
    while ($row = $stLeaves->fetch(PDO::FETCH_ASSOC)) {
        $topLeaveTypes[] = [
            'name' => $row['name'],
            'count' => (int)$row['count'],
            'days' => (int)$row['total_days']
        ];
    }
} catch (Throwable $e) {
    error_log("Dashboard Error (top leaves): " . $e->getMessage());
    $topLeaveTypes = [];
}

// 5. Horários de Check-in Mais Frequentes (heatmap - últimos 30 dias) - com proteção
$checkinHeatmap = array_fill(0, 7, array_fill(0, 24, 0)); // [weekday][hour] = count
try {
    $stHeat = $pdo->prepare("
        SELECT DAYOFWEEK(a.date) - 1 as weekday, HOUR(a.check_in) as hour, COUNT(*) as count
        FROM attendance a
        JOIN teachers t ON t.id = a.teacher_id
        WHERE a.check_in IS NOT NULL 
          AND a.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND $whereTeacher
        GROUP BY weekday, hour
    ");
    $stHeat->execute($paramsTeacher);
    while ($row = $stHeat->fetch(PDO::FETCH_ASSOC)) {
        $wd = (int)$row['weekday'];
        $hr = (int)$row['hour'];
        $checkinHeatmap[$wd][$hr] = (int)$row['count'];
    }
} catch (Throwable $e) {
    error_log("Dashboard Error (heatmap): " . $e->getMessage());
    $checkinHeatmap = array_fill(0, 7, array_fill(0, 24, 0));
}

// 6. Tendência de Presença (média móvel 7 dias) - com proteção
$presenceTrend = [];
try {
    for ($i = 13; $i >= 0; $i--) {
        $endDate = date('Y-m-d', strtotime("-$i days"));
        $startDate = date('Y-m-d', strtotime("-" . ($i + 6) . " days"));
        
        $stAvg = $pdo->prepare("
            SELECT AVG(daily_count) as avg_presence
            FROM (
                SELECT COUNT(DISTINCT a.teacher_id) as daily_count
                FROM attendance a
                JOIN teachers t ON t.id = a.teacher_id
                WHERE a.date BETWEEN ? AND ? 
                  AND a.approved = 1
                  AND $whereTeacher
                GROUP BY a.date
            ) as daily_counts
        ");
        $stAvg->execute(array_merge([$startDate, $endDate], $paramsTeacher));
        $avg = round((float)$stAvg->fetchColumn(), 1);
        
        $presenceTrend[] = [
            'date' => date('d/m', strtotime($endDate)),
            'avg' => $avg
        ];
    }
} catch (Throwable $e) {
    error_log("Dashboard Error (presence trend): " . $e->getMessage());
    for ($i = 13; $i >= 0; $i--) {
        $presenceTrend[] = [
            'date' => date('d/m', strtotime("-$i days")),
            'avg' => 0
        ];
    }
}

// 7. Detecções de Fraude (últimos 7 dias)
$fraudCount = 0;
$stFraud = $pdo->prepare("
    SELECT COUNT(*) 
    FROM fraud_detection_log fdl
    JOIN teachers t ON t.id = fdl.teacher_id
    WHERE fdl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND fdl.risk_level >= 2
      AND $whereTeacher
");
$stFraud->execute($paramsTeacher);
$fraudCount = (int)$stFraud->fetchColumn();

// 8. Registros com possível esquecimento de saída
$forgottenCheckouts = [];
$forgottenCount = 0;
try {
    $stForgotten = $pdo->prepare("
        SELECT 
            a.id,
            t.name,
            t.id as teacher_id,
            a.date,
            a.check_in,
            TIMESTAMPDIFF(HOUR, a.check_in, NOW()) as hours_open,
            s.name as school_name,
            ct.name as type_name,
            ct.schedule_mode,
            CASE 
                WHEN ct.schedule_mode = 'time' THEN 'Horário Fixo'
                WHEN ct.schedule_mode = 'classes' THEN 'Grade Horária'
                ELSE 'Outro'
            END as mode_label
        FROM attendance a
        JOIN teachers t ON t.id = a.teacher_id
        LEFT JOIN collaborator_types ct ON ct.id = t.type_id
        LEFT JOIN schools s ON s.id = a.school_id
        WHERE a.check_in IS NOT NULL
          AND a.check_out IS NULL
          AND " . attendance_vigente_sql('a') . "
          AND (
            -- Horário fixo: alertar dias anteriores
            (IFNULL(ct.schedule_mode, 'time') = 'time' AND a.date < CURDATE())
            OR
            -- Grade horária: alertar apenas registros muito antigos (>1 dia)
            (ct.schedule_mode = 'classes' AND a.date < DATE_SUB(CURDATE(), INTERVAL 1 DAY))
          )
          AND $whereTeacher
          -- Excluir se tem afastamento aprovado
          AND NOT EXISTS (
            SELECT 1 FROM leaves l 
            WHERE l.teacher_id = t.id 
              AND l.approved = 1 
              AND a.date BETWEEN l.start_date AND l.end_date
          )
        ORDER BY ct.schedule_mode DESC, a.date ASC, a.check_in ASC
        LIMIT 20
    ");
    $stForgotten->execute($paramsTeacher);
    $forgottenCheckouts = $stForgotten->fetchAll(PDO::FETCH_ASSOC);
    $forgottenCount = count($forgottenCheckouts);
} catch (Throwable $e) {
    error_log("Dashboard Error (forgotten checkouts): " . $e->getMessage());
}

?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Painel do Administrador | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  <style>
    body {
      background-color: #f8f9fa;
    }

    .dashboard-card {
      min-height: 150px;
    }

    .navbar-brand {
      font-weight: bold;
    }

    .card-hover {
      transition: transform .15s ease, box-shadow .15s ease;
    }

    .card-hover:hover {
      transform: translateY(-2px);
      box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, .08) !important;
    }
  </style>
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

  <main class="container py-4" role="main" aria-labelledby="admin-title">
    <div class="bg-primary bg-gradient rounded-3 text-white p-4 p-md-5 mb-4 shadow-sm">
      <div class="d-flex align-items-center justify-content-between">
        <div class="me-3">
          <div class="d-flex align-items-center mb-2">
            <i class="bi bi-speedometer2 fs-1 me-2 opacity-75" aria-hidden="true"></i>
            <h1 id="admin-title" class="h3 mb-0">Painel do Administrador</h1>
          </div>
          <p class="mb-1">Visão geral e indicadores principais.</p>
          <small class="opacity-75">Bem-vindo, <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?>.</small>
        </div>
        <div class="text-end d-none d-md-block">
          <div class="fw-semibold small opacity-75">Hoje</div>
          <div class="fs-4 fw-bold"><?= date('d/m/Y') ?></div>
          <div class="small opacity-75"><?= date('H:i') ?></div>
        </div>
      </div>
    </div>

    <?php
    // Alerta de segurança: verificar se algum admin ainda usa a senha padrão (admin123)
    try {
        $stDefaultPwd = $pdo->query("SELECT id, username FROM admins WHERE password_hash IS NOT NULL");
        $defaultPwdAdmins = [];
        while ($adm = $stDefaultPwd->fetch(PDO::FETCH_ASSOC)) {
            // Verificar contra senhas padrão comuns
            foreach (['admin123', '123456', 'password', 'admin'] as $weakPwd) {
                if (password_verify($weakPwd, $adm['password_hash'] ?? '')) {
                    $defaultPwdAdmins[] = $adm['username'];
                    break;
                }
            }
        }
        if (!empty($defaultPwdAdmins)):
    ?>
    <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
      <i class="bi bi-shield-exclamation me-2 fs-4"></i>
      <div>
        <strong>Alerta de Segurança:</strong> Os seguintes administradores estão usando senhas fracas/padrão:
        <strong><?= esc(implode(', ', $defaultPwdAdmins)) ?></strong>.
        Altere as senhas imediatamente para garantir a segurança do sistema.
      </div>
    </div>
    <?php endif; } catch (Throwable $e) { /* non-critical */ } ?>

    <?php if (!empty($countingNotStarted)): ?>
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <div>A contagem de presenças e faltas inicia em <strong><?= esc(date('d/m/Y', strtotime($countingStart))) ?></strong>. Os números de hoje aparecerão a partir dessa data.</div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover">
          <div class="card-body">
            <div class="text-muted">Colaboradores</div>
            <div class="fs-3 fw-bold"><?= (int)$totalTeachers ?></div>
            <div class="small text-muted">Visíveis no seu escopo</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover">
          <div class="card-body">
            <div class="text-muted">Esperados Hoje</div>
            <div class="fs-3 fw-bold"><?= (int)$expectedToday ?></div>
            <div class="small text-muted">Com rotina hoje</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover bg-success bg-opacity-10 border-success border-opacity-25">
          <div class="card-body">
            <div class="text-success-emphasis fw-semibold">
              <i class="bi bi-check-circle me-1"></i>Aprovados Hoje
            </div>
            <div class="fs-3 fw-bold text-success-emphasis"><?= (int)$presentToday ?></div>
            <div class="small text-muted">Pontos confirmados</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover bg-warning bg-opacity-10 border-warning border-opacity-25">
          <div class="card-body">
            <div class="text-warning-emphasis fw-semibold">
              <i class="bi bi-clock-history me-1"></i>Pendentes Hoje
            </div>
            <div class="fs-3 fw-bold text-warning-emphasis"><?= (int)$pendingToday ?></div>
            <div class="small text-muted">Aguardando aprovação</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4 mt-1">
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover bg-danger bg-opacity-10 border-danger border-opacity-25">
          <div class="card-body">
            <div class="text-danger-emphasis fw-semibold">
              <i class="bi bi-x-circle me-1"></i>Ausentes Hoje
            </div>
            <div class="fs-3 fw-bold text-danger-emphasis"><?= (int)$absentToday ?></div>
            <div class="small text-muted">Não registraram ponto</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover bg-primary bg-opacity-10 border-primary border-opacity-25">
          <div class="card-body">
            <div class="text-primary fw-semibold">
              <i class="bi bi-person-check me-1"></i>Trabalhando Agora
            </div>
            <div class="fs-3 fw-bold text-primary"><?= (int)$workingNow ?></div>
            <div class="small text-muted">Em expediente neste momento</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover bg-info bg-opacity-10 border-info border-opacity-25">
          <div class="card-body">
            <div class="text-info-emphasis fw-semibold">
              <i class="bi bi-calendar-x me-1"></i>Afastamentos
            </div>
            <div class="fs-3 fw-bold text-info-emphasis"><?= (int)$leavesActive ?></div>
            <div class="small text-muted">Licenças/férias hoje</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover">
          <div class="card-body">
            <div class="text-muted">
              <i class="bi bi-arrow-repeat me-1"></i>Horas a Compensar (<?= date('m/Y') ?>)
            </div>
            <div class="fs-3 fw-bold"><?= intdiv($toCompensateMin, 60) ?>h<?= str_pad((string)($toCompensateMin % 60), 2, '0', STR_PAD_LEFT) ?></div>
            <div class="small text-muted">Soma do mês (informativo)</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover <?= $forgottenCount > 0 ? 'bg-warning bg-opacity-10 border-warning border-opacity-25' : '' ?>">
          <div class="card-body">
            <div class="<?= $forgottenCount > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
              <i class="bi bi-exclamation-circle me-1"></i>Saídas Esquecidas
            </div>
            <div class="fs-3 fw-bold <?= $forgottenCount > 0 ? 'text-warning-emphasis' : '' ?>">
              <?= $forgottenCount ?>
            </div>
            <div class="small text-muted">Registros incompletos</div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($workingNowList)): ?>
    <div class="row g-4 mt-3">
      <div class="col-12">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-success bg-opacity-10 border-0">
            <h5 class="mb-0 d-flex align-items-center gap-2">
              <i class="bi bi-people-fill text-success"></i>
              Colaboradores Trabalhando Agora
            </h5>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">Cargo</th>
                    <th scope="col">Instituição</th>
                    <th scope="col">Entrada</th>
                    <th scope="col">Tempo Trabalhado</th>
                    <th scope="col">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($workingNowList as $worker): 
                    $checkIn = new DateTime($worker['check_in']);
                    $checkInTimestamp = $checkIn->getTimestamp();
                    $now = new DateTime();
                    $diff = $now->diff($checkIn);
                    $hours = $diff->h + ($diff->days * 24);
                    $minutes = $diff->i;
                    $seconds = $diff->s;
                    
                    $hasLocation = !empty($worker['check_in_lat']) && !empty($worker['check_in_lng']);
                    $mapsUrl = $hasLocation ? 'https://www.google.com/maps?q=' . $worker['check_in_lat'] . ',' . $worker['check_in_lng'] : null;
                  ?>
                  <tr>
                    <td class="fw-semibold"><?= esc($worker['name']) ?></td>
                    <td><?= esc($worker['type_name'] ?? '-') ?></td>
                    <td><?= esc($worker['school_name'] ?? '-') ?></td>
                    <td><?= $checkIn->format('H:i') ?></td>
                    <td>
                      <span class="badge bg-success bg-opacity-75 time-counter" data-checkin="<?= $checkInTimestamp ?>">
                        <?= sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds) ?>
                      </span>
                    </td>
                    <td>
                      <i class="bi bi-circle-fill text-success" style="font-size: 0.6rem;"></i>
                      <small class="text-success">Ativo</small>
                      <?php if ($hasLocation): ?>
                        <a href="<?= esc($mapsUrl) ?>" target="_blank" class="btn btn-sm btn-outline-primary ms-2" title="Ver localização">
                          <i class="bi bi-geo-alt"></i>
                        </a>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Alerta: Possíveis Esquecimentos de Saída -->
    <?php if ($forgottenCount > 0): ?>
    <div class="row g-4 mt-3">
      <div class="col-12">
        <div class="card border-0 shadow-sm border-warning">
          <div class="card-header bg-warning bg-opacity-10 border-0">
            <h5 class="mb-0 d-flex align-items-center gap-2">
              <i class="bi bi-clock-history text-warning"></i>
              Possíveis Esquecimentos de Saída
              <span class="badge bg-warning text-dark"><?= $forgottenCount ?></span>
            </h5>
          </div>
          <div class="card-body">
            <div class="alert alert-warning mb-3">
              <i class="bi bi-exclamation-triangle me-2"></i>
              <strong>Atenção:</strong> Colaboradores com entrada registrada mas sem saída.
              Podem ter esquecido de registrar o ponto ao sair.
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="d-flex align-items-center gap-2">
                <label for="forgottenPageSize" class="mb-0 small">Mostrar:</label>
                <select id="forgottenPageSize" class="form-select form-select-sm" style="width: auto;">
                  <option value="5">5</option>
                  <option value="10" selected>10</option>
                  <option value="20">20</option>
                  <option value="50">50</option>
                </select>
                <span class="small text-muted">por página</span>
              </div>
              <div id="forgottenInfo" class="small text-muted"></div>
            </div>
            <div class="table-responsive">
              <table id="forgottenTable" class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">Tipo</th>
                    <th scope="col">Cargo</th>
                    <th scope="col">Instituição</th>
                    <th scope="col">Data</th>
                    <th scope="col">Entrada</th>
                    <th scope="col">Tempo Decorrido</th>
                    <th scope="col">Ação</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($forgottenCheckouts as $forgotten): 
                    $hoursOpen = (int)$forgotten['hours_open'];
                    $isVeryOld = $hoursOpen > 48; // Mais de 2 dias
                    $rowClass = $isVeryOld ? 'table-danger' : '';
                    $daysAgo = floor($hoursOpen / 24);
                  ?>
                  <tr class="<?= $rowClass ?>">
                    <td class="fw-semibold"><?= esc($forgotten['name']) ?></td>
                    <td>
                      <span class="badge bg-<?= $forgotten['schedule_mode'] === 'time' ? 'info' : 'secondary' ?> bg-opacity-75">
                        <?= esc($forgotten['mode_label'] ?? 'N/D') ?>
                      </span>
                    </td>
                    <td><?= esc($forgotten['type_name'] ?? '-') ?></td>
                    <td class="small"><?= esc($forgotten['school_name'] ?? '-') ?></td>
                    <td class="text-nowrap">
                      <?= date('d/m/Y', strtotime($forgotten['date'])) ?>
                      <?php if ($daysAgo >= 2): ?>
                        <span class="badge bg-danger ms-1"><?= $daysAgo ?> dias!</span>
                      <?php elseif ($daysAgo == 1): ?>
                        <span class="badge bg-warning text-dark ms-1">Ontem</span>
                      <?php endif; ?>
                    </td>
                    <td><?= date('H:i', strtotime($forgotten['check_in'])) ?></td>
                    <td>
                      <span class="badge bg-<?= $isVeryOld ? 'danger' : 'warning' ?> text-<?= $isVeryOld ? 'white' : 'dark' ?>">
                        <?= $hoursOpen ?>h atrás
                      </span>
                    </td>
                    <td>
                      <a href="attendance_edit.php?id=<?= (int)$forgotten['id'] ?>" 
                         class="btn btn-sm btn-outline-primary" 
                         title="Editar e adicionar saída">
                        <i class="bi bi-pencil"></i> Corrigir
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <nav id="forgottenPagination" aria-label="Paginação de esquecimentos">
              <ul class="pagination pagination-sm justify-content-center mb-0"></ul>
            </nav>
            <div class="mt-3">
              <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Horário Fixo:</strong> Alertado em dias anteriores. 
                <strong>Grade Horária:</strong> Alertado apenas após 1+ dia (evita falsos positivos de aulas não finalizadas).
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Lista de Ausentes Hoje -->
    <?php if (!empty($absentList)): ?>
    <div class="row g-4 mt-3">
      <div class="col-12">
        <div class="card border-0 shadow-sm border-danger border-opacity-25">
          <div class="card-header bg-danger bg-opacity-10 border-0">
            <h5 class="mb-0 d-flex align-items-center gap-2">
              <i class="bi bi-person-x-fill text-danger"></i>
              Colaboradores Ausentes Hoje
              <span class="badge bg-danger"><?= count($absentList) ?></span>
            </h5>
          </div>
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="d-flex align-items-center gap-2">
                <label for="absentPageSize" class="mb-0 small">Mostrar:</label>
                <select id="absentPageSize" class="form-select form-select-sm" style="width: auto;">
                  <option value="10">10</option>
                  <option value="20" selected>20</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="small text-muted">por página</span>
              </div>
              <div id="absentInfo" class="small text-muted"></div>
            </div>
            <div class="table-responsive">
              <table id="absentTable" class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">Cargo</th>
                    <th scope="col">Instituição(ões)</th>
                    <th scope="col">Status</th>
                    <th scope="col">Ação</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($absentList as $absent): ?>
                  <tr>
                    <td class="fw-semibold"><?= esc($absent['name']) ?></td>
                    <td><?= esc($absent['type_name'] ?? '-') ?></td>
                    <td>
                      <small><?= esc($absent['schools'] ?? 'Nenhuma') ?></small>
                    </td>
                    <td>
                      <i class="bi bi-circle-fill text-danger" style="font-size: 0.6rem;"></i>
                      <small class="text-danger fw-semibold">Ausente</small>
                    </td>
                    <td>
                      <a href="teachers.php?highlight=<?= (int)$absent['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver colaborador">
                        <i class="bi bi-eye"></i>
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <nav id="absentPagination" aria-label="Paginação de ausentes">
              <ul class="pagination pagination-sm justify-content-center mb-0"></ul>
            </nav>
            <div class="alert alert-warning mb-0 mt-3">
              <i class="bi bi-exclamation-triangle me-2"></i>
              <strong>Atenção:</strong> Estes colaboradores eram esperados hoje mas não registraram ponto até o momento.
              Podem estar em afastamento não registrado ou falta.
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================================ -->
    <!-- SEÇÃO DE GRÁFICOS E ANÁLISES -->
    <!-- ============================================================================ -->
    <div class="row g-4 mt-4">
      <div class="col-12">
        <h4 class="mb-3"><i class="bi bi-graph-up me-2"></i>Análises e Gráficos</h4>
      </div>
      
      <!-- Gráfico 1: Evolução de Presença (30 dias) -->
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header fw-semibold">
            <i class="bi bi-activity me-2"></i>Evolução de Presença - Últimos 30 Dias
          </div>
          <div class="card-body">
            <canvas id="chartPresence30Days" height="200"></canvas>
          </div>
        </div>
      </div>
      
      <!-- Gráfico 2: Distribuição de Status Hoje -->
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header fw-semibold">
            <i class="bi bi-pie-chart me-2"></i>Distribuição de Status - Hoje
          </div>
          <div class="card-body">
            <canvas id="chartStatusToday" height="200"></canvas>
          </div>
        </div>
      </div>
      
      <!-- Gráfico 4: Comparativo por Escola -->
      <?php if (is_network_admin($admin) && !empty($schoolComparison)): ?>
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header fw-semibold">
            <i class="bi bi-building me-2"></i>Comparativo por Escola - Hoje
          </div>
          <div class="card-body">
            <canvas id="chartSchoolComparison" height="200"></canvas>
          </div>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Gráfico 5: Top Tipos de Afastamento -->
      <?php if (!empty($topLeaveTypes)): ?>
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header fw-semibold">
            <i class="bi bi-person-x me-2"></i>Top Afastamentos - Mês Atual
          </div>
          <div class="card-body">
            <canvas id="chartTopLeaves" height="200"></canvas>
          </div>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Gráfico 6: Tendência de Presença (média móvel) -->
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header fw-semibold">
            <i class="bi bi-graph-up-arrow me-2"></i>Tendência de Presença (média 7 dias)
          </div>
          <div class="card-body">
            <canvas id="chartPresenceTrend" height="200"></canvas>
          </div>
        </div>
      </div>
      
      <!-- Card 7: Alerta de Fraude -->
      <?php if ($fraudCount > 0): ?>
      <div class="col-lg-6">
        <div class="card shadow-sm border-warning">
          <div class="card-header bg-warning text-dark fw-semibold">
            <i class="bi bi-exclamation-triangle me-2"></i>Alertas de Segurança
          </div>
          <div class="card-body">
            <div class="alert alert-warning mb-0">
              <h5 class="alert-heading">⚠️ Detecções de Fraude</h5>
              <p class="mb-0"><strong><?= $fraudCount ?></strong> tentativa(s) suspeita(s) de GPS fake nos últimos 7 dias.</p>
              <hr>
              <p class="mb-0 small">
                <a href="fraud_alerts.php" class="alert-link">Ver detalhes das detecções</a>
              </p>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <footer class="mt-5 mb-4">
      <div class="d-flex flex-column align-items-center gap-3">
        <div class="d-flex justify-content-center align-items-center">
          <img src="../img/logo_prefeitura.png" alt="Prefeitura Municipal de Oeiras - PI" style="height: 100px; width: auto;">
        </div>
        <div class="text-center text-muted small">
          <div>Prefeitura Municipal de Oeiras - PI</div>
          <div>&copy; <?= date('Y') ?> DEEDO Sistemas - Sistema de Ponto Eletrônico</div>
        </div>
      </div>
    </footer>
  </main>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
  
  <script>
    // ============================================================================
    // CONFIGURAÇÃO DOS GRÁFICOS
    // ============================================================================
    
    // Gráfico 1: Evolução de Presença (Linha)
    const presenceData = <?= json_encode($presenceLast30Days) ?>;
    new Chart(document.getElementById('chartPresence30Days'), {
      type: 'line',
      data: {
        labels: presenceData.map(d => d.date),
        datasets: [{
          label: 'Presentes',
          data: presenceData.map(d => d.count),
          borderColor: '#0d6efd',
          backgroundColor: 'rgba(13, 110, 253, 0.1)',
          fill: true,
          tension: 0.4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {display: true},
          tooltip: {
            callbacks: {
              label: function(context) {
                return 'Presentes: ' + context.parsed.y;
              }
            }
          }
        },
        scales: {
          y: {beginAtZero: true, ticks: {stepSize: 1}}
        }
      }
    });
    
    // Gráfico 2: Distribuição de Status (Pizza)
    new Chart(document.getElementById('chartStatusToday'), {
      type: 'doughnut',
      data: {
        labels: ['Presentes', 'Ausentes', 'Pendentes', 'Afastados'],
        datasets: [{
          data: [<?= $presentToday ?>, <?= $absentToday ?>, <?= $pendingToday ?>, <?= $leavesActive ?>],
          backgroundColor: ['#198754', '#dc3545', '#ffc107', '#17a2b8'],
          borderWidth: 2,
          borderColor: '#fff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {position: 'right'},
          datalabels: {
            color: '#fff',
            font: {weight: 'bold', size: 14},
            formatter: (value) => value > 0 ? value : ''
          }
        }
      },
      plugins: [ChartDataLabels]
    });
    
    <?php if (is_network_admin($admin) && !empty($schoolComparison)): ?>
    // Gráfico 4: Comparativo por Escola (Barras Empilhadas)
    const schoolData = <?= json_encode($schoolComparison) ?>;
    new Chart(document.getElementById('chartSchoolComparison'), {
      type: 'bar',
      data: {
        labels: schoolData.map(s => s.name),
        datasets: [
          {
            label: 'Presentes',
            data: schoolData.map(s => s.present),
            backgroundColor: '#198754',
            borderColor: '#146c43',
            borderWidth: 1
          },
          {
            label: 'Ausentes',
            data: schoolData.map(s => s.absent),
            backgroundColor: '#dc3545',
            borderColor: '#b02a37',
            borderWidth: 1
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {legend: {display: true}},
        scales: {
          x: {stacked: true},
          y: {stacked: true, beginAtZero: true}
        }
      }
    });
    <?php endif; ?>
    
    <?php if (!empty($topLeaveTypes)): ?>
    // Gráfico 5: Top Afastamentos (Doughnut)
    const leaveData = <?= json_encode($topLeaveTypes) ?>;
    new Chart(document.getElementById('chartTopLeaves'), {
      type: 'doughnut',
      data: {
        labels: leaveData.map(l => l.name),
        datasets: [{
          data: leaveData.map(l => l.days),
          backgroundColor: ['#fd7e14', '#20c997', '#0162cc', '#d63384', '#6c757d']
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {position: 'right'},
          datalabels: {
            color: '#fff',
            font: {weight: 'bold'},
            formatter: (value, context) => value + ' dias'
          }
        }
      },
      plugins: [ChartDataLabels]
    });
    <?php endif; ?>
    
    // Gráfico 6: Tendência de Presença (Linha com área)
    const trendData = <?= json_encode($presenceTrend) ?>;
    new Chart(document.getElementById('chartPresenceTrend'), {
      type: 'line',
      data: {
        labels: trendData.map(d => d.date),
        datasets: [{
          label: 'Média Móvel 7 dias',
          data: trendData.map(d => d.avg),
          borderColor: '#198754',
          backgroundColor: 'rgba(25, 135, 84, 0.2)',
          fill: true,
          tension: 0.4,
          pointRadius: 4,
          pointHoverRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {display: true},
          tooltip: {
            callbacks: {
              label: function(context) {
                return 'Média: ' + context.parsed.y.toFixed(1) + ' presentes';
              }
            }
          }
        },
        scales: {
          y: {beginAtZero: true}
        }
      }
    });
  </script>
  
  <script>
    // Atualiza contadores de tempo em tempo real
    function updateTimeCounters() {
      const counters = document.querySelectorAll('.time-counter');
      const now = Math.floor(Date.now() / 1000);
      
      counters.forEach(counter => {
        const checkIn = parseInt(counter.getAttribute('data-checkin'));
        const elapsed = now - checkIn;
        
        const hours = Math.floor(elapsed / 3600);
        const minutes = Math.floor((elapsed % 3600) / 60);
        const seconds = elapsed % 60;
        
        counter.textContent = 
          String(hours).padStart(2, '0') + ':' + 
          String(minutes).padStart(2, '0') + ':' + 
          String(seconds).padStart(2, '0');
      });
    }
    
    // Atualiza a cada segundo
    if (document.querySelectorAll('.time-counter').length > 0) {
      setInterval(updateTimeCounters, 1000);
    }
  </script>
  
  <script>
    // ============================================================================
    // SISTEMA DE PAGINAÇÃO REUTILIZÁVEL
    // ============================================================================
    
    class TablePagination {
      constructor(tableId, pageSizeId, paginationId, infoId, defaultPageSize = 10) {
        this.table = document.getElementById(tableId);
        if (!this.table) return;
        
        this.tbody = this.table.querySelector('tbody');
        this.pageSize = defaultPageSize;
        this.currentPage = 1;
        this.rows = Array.from(this.tbody.querySelectorAll('tr'));
        this.totalRows = this.rows.length;
        
        this.pageSizeSelect = document.getElementById(pageSizeId);
        this.paginationContainer = document.querySelector(`#${paginationId} ul`);
        this.infoElement = document.getElementById(infoId);
        
        this.init();
      }
      
      init() {
        if (this.totalRows === 0) return;
        
        // Event listener para mudança de tamanho de página
        this.pageSizeSelect.addEventListener('change', (e) => {
          this.pageSize = parseInt(e.target.value);
          this.currentPage = 1;
          this.render();
        });
        
        // Renderização inicial
        this.render();
      }
      
      get totalPages() {
        return Math.ceil(this.totalRows / this.pageSize);
      }
      
      showPage(page) {
        this.currentPage = page;
        this.render();
      }
      
      render() {
        // Esconde todas as linhas
        this.rows.forEach(row => row.style.display = 'none');
        
        // Mostra apenas as linhas da página atual
        const start = (this.currentPage - 1) * this.pageSize;
        const end = start + this.pageSize;
        const visibleRows = this.rows.slice(start, end);
        visibleRows.forEach(row => row.style.display = '');
        
        // Atualiza informação
        const showing = visibleRows.length;
        const from = this.totalRows > 0 ? start + 1 : 0;
        const to = start + showing;
        this.infoElement.textContent = `Mostrando ${from} a ${to} de ${this.totalRows} registros`;
        
        // Renderiza controles de paginação
        this.renderPagination();
      }
      
      renderPagination() {
        this.paginationContainer.innerHTML = '';
        
        if (this.totalPages <= 1) return;
        
        // Botão Anterior
        const prevLi = document.createElement('li');
        prevLi.className = `page-item ${this.currentPage === 1 ? 'disabled' : ''}`;
        prevLi.innerHTML = `<a class="page-link" href="#" aria-label="Anterior"><i class="bi bi-chevron-left"></i></a>`;
        if (this.currentPage > 1) {
          prevLi.querySelector('a').addEventListener('click', (e) => {
            e.preventDefault();
            this.showPage(this.currentPage - 1);
          });
        }
        this.paginationContainer.appendChild(prevLi);
        
        // Números de página (com ellipsis inteligente)
        const pages = this.getPageNumbers();
        pages.forEach(page => {
          const li = document.createElement('li');
          
          if (page === '...') {
            li.className = 'page-item disabled';
            li.innerHTML = '<span class="page-link">...</span>';
          } else {
            li.className = `page-item ${this.currentPage === page ? 'active' : ''}`;
            li.innerHTML = `<a class="page-link" href="#">${page}</a>`;
            li.querySelector('a').addEventListener('click', (e) => {
              e.preventDefault();
              this.showPage(page);
            });
          }
          
          this.paginationContainer.appendChild(li);
        });
        
        // Botão Próximo
        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${this.currentPage === this.totalPages ? 'disabled' : ''}`;
        nextLi.innerHTML = `<a class="page-link" href="#" aria-label="Próximo"><i class="bi bi-chevron-right"></i></a>`;
        if (this.currentPage < this.totalPages) {
          nextLi.querySelector('a').addEventListener('click', (e) => {
            e.preventDefault();
            this.showPage(this.currentPage + 1);
          });
        }
        this.paginationContainer.appendChild(nextLi);
      }
      
      getPageNumbers() {
        const pages = [];
        const total = this.totalPages;
        const current = this.currentPage;
        
        if (total <= 7) {
          // Mostra todas as páginas se forem 7 ou menos
          for (let i = 1; i <= total; i++) {
            pages.push(i);
          }
        } else {
          // Lógica com ellipsis
          if (current <= 3) {
            // Início: [1] 2 3 4 ... 10
            for (let i = 1; i <= 4; i++) pages.push(i);
            pages.push('...');
            pages.push(total);
          } else if (current >= total - 2) {
            // Fim: 1 ... 7 8 9 [10]
            pages.push(1);
            pages.push('...');
            for (let i = total - 3; i <= total; i++) pages.push(i);
          } else {
            // Meio: 1 ... 4 [5] 6 ... 10
            pages.push(1);
            pages.push('...');
            for (let i = current - 1; i <= current + 1; i++) pages.push(i);
            pages.push('...');
            pages.push(total);
          }
        }
        
        return pages;
      }
    }
    
    // Inicializa paginação para tabela de ausentes
    <?php if (!empty($absentList)): ?>
    new TablePagination('absentTable', 'absentPageSize', 'absentPagination', 'absentInfo', 20);
    <?php endif; ?>
    
    // Inicializa paginação para tabela de esquecimentos
    <?php if ($forgottenCount > 0): ?>
    new TablePagination('forgottenTable', 'forgottenPageSize', 'forgottenPagination', 'forgottenInfo', 10);
    <?php endif; ?>
  </script>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// Filtro escola (apenas admin rede)
$schoolFilter = isset($_GET['school']) ? (int)$_GET['school'] : 0;
$schools = [];
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

// Presentes (quem tem qualquer attendance hoje)
$stP = $pdo->prepare("
  SELECT COUNT(DISTINCT a.teacher_id)
  FROM attendance a
  JOIN teachers t ON t.id=a.teacher_id
  WHERE a.date=? AND $whereTeacher
");
$stP->execute(array_merge([$today], $paramsTeacher));
$presentToday = (int)$stP->fetchColumn();

$absentToday = max(0, $expectedToday - $presentToday);

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

// Custos com horas extras no mês (aproximação usando salário/expected)
$month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$extraCost = 0.0;

// Para simplificar KPI, calcula por colaborador de forma agregada (cuidado: pode custar performance em bases grandes)
$stList = $pdo->prepare("SELECT t.id, t.base_salary, ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id=t.type_id WHERE $whereTeacher");
$stList->execute($paramsTeacher);
$teachersList = $stList->fetchAll(PDO::FETCH_ASSOC);
foreach ($teachersList as $trow) {
  $tid = (int)$trow['id'];
  $mode = $trow['schedule_mode'] ?? 'classes';
  // expected total no mês
  $expected = 0;
  for ($d = new DateTime($monthStart); $d <= new DateTime($monthEnd); $d->modify('+1 day')) {
    $w = (int)$d->format('w');
    if ($mode === 'classes') {
      $stS = $pdo->prepare("SELECT classes_count, class_minutes FROM teacher_schedules WHERE teacher_id=? AND weekday=?");
      $stS->execute([$tid, $w]);
      if ($sc = $stS->fetch(PDO::FETCH_ASSOC)) $expected += ((int)$sc['classes_count'] * (int)$sc['class_minutes']);
    } else {
      $stS = $pdo->prepare("SELECT start_time,end_time,break_minutes FROM collaborator_time_schedules WHERE teacher_id=? AND weekday=?");
      $stS->execute([$tid, $w]);
      if ($ts = $stS->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($ts['start_time']) && !empty($ts['end_time'])) {
          $s = DateTime::createFromFormat('H:i:s', $ts['start_time']);
          $e = DateTime::createFromFormat('H:i:s', $ts['end_time']);
          if ($s && $e) {
            if ($e <= $s) $e = (clone $e)->modify('+1 day');
            $expected += max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60) - (int)$ts['break_minutes']);
          }
        }
      }
    }
  }
  // worked total
  $stW = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE teacher_id=? AND date BETWEEN ? AND ?");
  $stW->execute([$tid, $monthStart, $monthEnd]);
  $worked = 0;
  while ($r = $stW->fetch(PDO::FETCH_ASSOC)) {
    if ($r['check_in'] && $r['check_out']) {
      $ci = new DateTime($r['check_in']);
      $co = new DateTime($r['check_out']);
      if ($co > $ci) $worked += (int)(($co->getTimestamp() - $ci->getTimestamp()) / 60);
    }
  }
  $delta = $worked - $expected;
  if ($expected > 0 && $delta > 0) {
    $minuteValue = ((float)$trow['base_salary'] / (float)$expected);
    $extraCost += $delta * $minuteValue * 1.5;
  }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Painel do Administrador | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
        <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
          <li class="nav-item"><a class="nav-link" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
          <li class="nav-item"><a class="nav-link" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
          <?php if (is_network_admin($admin)): ?>
            <li class="nav-item"><a class="nav-link" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
            <li class="nav-item"><a class="nav-link" href="admins.php"><i class="bi bi-people"></i> Administradores</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <?php if (is_network_admin($admin)): ?>
          <form class="d-flex" method="get">
            <select name="school" class="form-select form-select-sm me-2">
              <option value="">(Todas escolas)</option>
              <?php foreach ($schools as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $schoolFilter === (int)$s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-light btn-sm">Aplicar</button>
          </form>
        <?php endif; ?>
        <span class="navbar-text ms-3 d-none d-lg-inline">
          <i class="bi bi-person-circle"></i>
          <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?>
        </span>
        <a href="logout.php" class="btn btn-outline-light ms-2"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>

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
        <div class="card h-100 border-0 shadow-sm card-hover">
          <div class="card-body">
            <div class="text-muted">Presentes Hoje</div>
            <div class="fs-3 fw-bold"><?= (int)$presentToday ?></div>
            <div class="small text-muted">Com registro de ponto</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover">
          <div class="card-body">
            <div class="text-muted">Absenteísmo Hoje</div>
            <div class="fs-3 fw-bold"><?= (int)$absentToday ?></div>
            <div class="small text-muted">Esperados que não registraram</div>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-4">
        <div class="card h-100 border-0 shadow-sm card-hover">
          <div class="card-body">
            <div class="text-muted">Colaboradores Afastados</div>
            <div class="fs-3 fw-bold"><?= (int)$leavesActive ?></div>
            <div class="small text-muted">Afastamentos aprovados (hoje)</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-8">
        <div class="card h-100 border-0 shadow-sm card-hover">
          <div class="card-body">
            <div class="text-muted">Custo Estimado com Horas Extras (<?= date('m/Y') ?>)</div>
            <div class="fs-3 fw-bold">R$ <?= number_format($extraCost, 2, ',', '.') ?></div>
            <div class="small text-muted">Estimativa baseada em salário e jornada esperada</div>
          </div>
        </div>
      </div>
    </div>

    <footer class="mt-5 text-center text-muted">
      &copy; <?= date('Y'); ?> DEEDO Sistemas.
    </footer>
  </main>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</body>

</html>
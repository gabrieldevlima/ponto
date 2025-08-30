<?php
require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$admin = current_admin($pdo);

function minutes_to_hhmm(int $minutes): string
{
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  return sprintf('%02d:%02d', $h, $m);
}

$month = $_GET['month'] ?? '';
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

// Defaults
if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
  $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');
}

// Escopo
list($scopeSql, $scopeParams) = admin_scope_where('t');

// Carrega colaborador (respeita escopo)
$selectedTeacher = null;
$mode = 'classes';
if ($teacherId) {
  $st = $pdo->prepare("SELECT t.id, t.name, t.base_salary, ct.schedule_mode
                       FROM teachers t
                       LEFT JOIN collaborator_types ct ON ct.id = t.type_id
                       WHERE t.id = ? AND $scopeSql");
  $st->execute(array_merge([$teacherId], $scopeParams));
  $selectedTeacher = $st->fetch(PDO::FETCH_ASSOC);
  if ($selectedTeacher) $mode = $selectedTeacher['schedule_mode'] ?? 'classes';
}

if ($teacherId && !$selectedTeacher) {
  http_response_code(403);
  exit('Sem permissão para ver este colaborador.');
}

$periodStart = DateTime::createFromFormat('Y-m-d', $month . '-01');
$periodEnd = (clone $periodStart)->modify('last day of this month');

// Mapa de jornada esperada (semana)
$scheduleMap = [];
if ($selectedTeacher) {
  if ($mode === 'classes') {
    $st = $pdo->prepare("SELECT weekday, classes_count, class_minutes FROM teacher_schedules WHERE teacher_id = ?");
    $st->execute([$selectedTeacher['id']]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $scheduleMap[(int)$r['weekday']] = ['cc' => (int)$r['classes_count'], 'cm' => (int)$r['class_minutes']];
    }
  } elseif ($mode === 'time') {
    $st = $pdo->prepare("SELECT weekday, start_time, end_time, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ?");
    $st->execute([$selectedTeacher['id']]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $scheduleMap[(int)$r['weekday']] = [
        'start' => $r['start_time'],
        'end' => $r['end_time'],
        'break' => (int)$r['break_minutes']
      ];
    }
  }
}

// Per-day skeleton
$totalExpectedMin = 0;
$daily = [];
for ($d = clone $periodStart; $d <= $periodEnd; $d = $d->modify('+1 day')) {
  $dateStr = $d->format('Y-m-d');
  $w = (int)$d->format('w');
  $exp = 0;
  if ($selectedTeacher && isset($scheduleMap[$w])) {
    if ($mode === 'classes') {
      $exp = ($scheduleMap[$w]['cc'] ?? 0) * ($scheduleMap[$w]['cm'] ?? 0);
    } elseif ($mode === 'time') {
      $start = $scheduleMap[$w]['start'] ?? null;
      $end   = $scheduleMap[$w]['end'] ?? null;
      $break = (int)($scheduleMap[$w]['break'] ?? 0);
      if ($start && $end) {
        $s = DateTime::createFromFormat('H:i:s', $start);
        $e = DateTime::createFromFormat('H:i:s', $end);
        if ($s && $e) {
          if ($e <= $s) $e = (clone $e)->modify('+1 day');
          $exp = max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60) - $break);
        }
      }
    }
  }
  $daily[$dateStr] = ['expectedMin' => $exp, 'workedMin' => 0, 'items' => []];
  $totalExpectedMin += $exp;
}

// Afastamentos aprovados (paid => expected=0)
if ($selectedTeacher) {
  $stL = $pdo->prepare("SELECT l.*, lt.paid
                        FROM leaves l
                        JOIN leave_types lt ON lt.id = l.type_id
                        WHERE l.teacher_id = ? AND l.approved = 1 AND l.end_date >= ? AND l.start_date <= ?");
  $stL->execute([$selectedTeacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
  while ($lv = $stL->fetch(PDO::FETCH_ASSOC)) {
    $d0 = new DateTime($lv['start_date']);
    $d1 = new DateTime($lv['end_date']);
    for ($d = clone $d0; $d <= $d1; $d = $d->modify('+1 day')) {
      $k = $d->format('Y-m-d');
      if (!isset($daily[$k])) $daily[$k] = ['expectedMin' => 0, 'workedMin' => 0, 'items' => []];
      if ((int)$lv['paid'] === 1) {
        $totalExpectedMin -= $daily[$k]['expectedMin'];
        $daily[$k]['expectedMin'] = 0;
      }
    }
  }
}

// Registros de ponto do mês
if ($selectedTeacher) {
  $st = $pdo->prepare("SELECT a.*, mr.name AS manual_reason_name
                       FROM attendance a
                       LEFT JOIN manual_reasons mr ON mr.id = a.manual_reason_id
                       WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ?
                       ORDER BY a.date ASC, a.check_in ASC");
  $st->execute([$selectedTeacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $d = $row['date'];
    $worked = 0;
    if (!empty($row['check_in']) && !empty($row['check_out'])) {
      $in = new DateTime($row['check_in']);
      $out = new DateTime($row['check_out']);
      if ($out > $in) $worked = (int) round(($out->getTimestamp() - $in->getTimestamp()) / 60);
    }
    if (!isset($daily[$d])) $daily[$d] = ['expectedMin' => 0, 'workedMin' => 0, 'items' => []];
    $daily[$d]['workedMin'] += $worked;
    $daily[$d]['items'][] = $row;
  }
}

$totalWorkedMin = array_sum(array_column($daily, 'workedMin'));
$saldo = $totalWorkedMin - $totalExpectedMin;

// Export PDF (Dompdf)
if ($selectedTeacher && (isset($_GET['export']) && $_GET['export'] === 'pdf')) {
  $autoload = __DIR__ . '/../../vendor/autoload.php';
  if (file_exists($autoload)) {
    require_once $autoload;
    ob_start();
    include __DIR__ . '/_tpl_teacher_monthly_report_pdf.php';
    $html = ob_get_clean();
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('relatorio_mensal_' . $selectedTeacher['id'] . '_' . $month . '.pdf');
    exit;
  } else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Exportação PDF indisponível. Instale as dependências:\n- composer require dompdf/dompdf\nE tente novamente.";
    exit;
  }
}

// Helper de URL preservando filtros
function build_url_with(array $extra): string
{
  $q = $_GET;
  foreach ($extra as $k => $v) $q[$k] = $v;
  return 'teacher_monthly_report.php?' . http_build_query($q);
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Relatório Mensal | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
        <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
          <li class="nav-item"><a class="nav-link" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
          <li class="nav-item"><a class="nav-link active" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
          <?php if (is_network_admin($admin)): ?>
            <li class="nav-item"><a class="nav-link" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
            <li class="nav-item"><a class="nav-link" href="admins.php"><i class="bi bi-people"></i> Administradores</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <span class="navbar-text me-3 d-none d-lg-inline">
          <i class="bi bi-person-circle"></i>
          <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?>
        </span>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="card mb-3">
      <div class="card-body">
        <form class="row g-3" method="get" autocomplete="off">
          <div class="col-md-4">
            <label class="form-label">Mês</label>
            <input type="month" name="month" class="form-control" value="<?= esc($month) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Colaborador</label>
            <?php
            // lista restrita ao escopo
            $listSt = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE $scopeSql ORDER BY t.name");
            $listSt->execute($scopeParams);
            $opts = $listSt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <select name="teacher_id" class="form-select" required>
              <option value="">Selecione</option>
              <?php foreach ($opts as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $teacherId === (int)$t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 align-self-end d-flex gap-2 flex-wrap">
            <button class="btn btn-primary w-100">Gerar</button>
            <?php if ($selectedTeacher): ?>
              <a class="btn btn-outline-secondary w-100" href="<?= esc(build_url_with(['export' => 'pdf'])) ?>">Exportar PDF</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <?php if ($selectedTeacher): ?>
      <div class="card">
        <div class="card-body">
          <h5 class="mb-3">Relatório Mensal - <?= esc($selectedTeacher['name']) ?> - <?= esc((new DateTime($month . '-01'))->format('m/Y')) ?></h5>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <div class="border rounded p-3 bg-light">
                <div class="text-muted">Horas esperadas</div>
                <div class="fs-4"><?= minutes_to_hhmm($totalExpectedMin) ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="border rounded p-3 bg-light">
                <div class="text-muted">Horas trabalhadas</div>
                <div class="fs-4"><?= minutes_to_hhmm($totalWorkedMin) ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="border rounded p-3 <?= $saldo < 0 ? 'bg-danger-subtle' : 'bg-success-subtle' ?>">
                <div class="text-muted">Saldo</div>
                <div class="fs-4"><?= minutes_to_hhmm($saldo) ?></div>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-striped table-sm align-middle">
              <thead>
                <tr>
                  <th style="width:110px;">Data</th>
                  <th style="width:110px;">Esperado</th>
                  <th style="width:110px;">Trabalhado</th>
                  <th>Pontos</th>
                  <th style="width:240px;">Justificativa</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($daily as $date => $info): ?>
                  <tr>
                    <td><?= esc((new DateTime($date))->format('d/m/Y')) ?></td>
                    <td><?= minutes_to_hhmm($info['expectedMin']) ?></td>
                    <td><?= minutes_to_hhmm($info['workedMin']) ?></td>
                    <td>
                      <?php if (!empty($info['items'])): ?>
                        <?php foreach ($info['items'] as $it): ?>
                          <div class="mb-1">
                            <?php
                            $entrada = !empty($it['check_in']) ? (new DateTime($it['check_in']))->format('H:i:s') : '-';
                            $saida = !empty($it['check_out']) ? (new DateTime($it['check_out']))->format('H:i:s') : '-';
                            ?>
                            <span class="badge bg-primary-subtle text-dark">Entrada: <?= esc($entrada) ?></span>
                            <span class="badge bg-secondary-subtle text-dark">Saída: <?= esc($saida) ?></span>
                            <span class="text-muted ms-2">Método: <?= esc($it['method'] ?? '-') ?></span>
                            <?php if (!empty($it['photo'])): ?>
                              <br>
                              <img src="/photos/<?= esc($it['photo']) ?>" alt="Foto" style="max-width:90px;max-height:90px;border-radius:5px;border:1px solid #ccc;">
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span class="text-muted">Sem pontos</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                      $just = [];
                      foreach ($info['items'] as $it) {
                        if (!empty($it['manual_reason_id'])) {
                          $jr = trim(($it['manual_reason_name'] ?? 'Manual') . (!empty($it['manual_reason_text']) ? ' - ' . $it['manual_reason_text'] : ''));
                          if ($jr !== '') $just[] = $jr;
                        }
                      }
                      echo $just ? esc(implode(' | ', $just)) : '<span class="text-muted">-</span>';
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="fw-bold">
                  <td>Total</td>
                  <td><?= minutes_to_hhmm($totalExpectedMin) ?></td>
                  <td><?= minutes_to_hhmm($totalWorkedMin) ?></td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="no-print mt-3 d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-secondary" onclick="window.print()">Salvar como PDF (navegador)</button>
            <a class="btn btn-outline-secondary" href="<?= esc(build_url_with(['export' => 'pdf'])) ?>">Exportar PDF (servidor)</a>
            <a class="btn btn-outline-secondary" href="reports_financial.php?teacher_id=<?= (int)$selectedTeacher['id'] ?>&month=<?= esc($month) ?>">Financeiro</a>
            <a class="btn btn-secondary" href="teachers.php">Voltar</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>

</html>
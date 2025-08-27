<?php
require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();

function esc($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function minutes_to_hhmm(int $minutes): string {
  $neg = $minutes < 0; $m = abs($minutes); return ($neg?'-':'') . sprintf('%02d:%02d', intdiv($m,60), $m%60);
}
function compute_expected_minutes(array $sched = null, string $mode = 'none'): int {
  if (!$sched) return 0;
  if ($mode === 'classes') { $cc=(int)($sched['classes_count']??0); $cm=(int)($sched['class_minutes']??0); return max(0,$cc*$cm); }
  if ($mode === 'time') {
    $start=$sched['start_time']??null; $end=$sched['end_time']??null; if(!$start||!$end) return 0;
    $break=(int)($sched['break_minutes']??0);
    $s=DateTime::createFromFormat('H:i:s', strlen($start)===5?$start.':00':$start);
    $e=DateTime::createFromFormat('H:i:s', strlen($end)===5?$end.':00':$end);
    if(!$s||!$e) return 0;
    $diff=(int)(($e->getTimestamp()-$s->getTimestamp())/60); if($diff<0) $diff+=24*60;
    return max(0,$diff - max(0,$break));
  }
  return 0;
}

$month = $_GET['month'] ?? '';
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$typeId = isset($_GET['type_id']) && ctype_digit((string)$_GET['type_id']) ? (int)$_GET['type_id'] : 0;

$types = $pdo->query("SELECT id, name FROM collaborator_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// colaboradores por tipo
$teachersSql = "SELECT id, name FROM teachers WHERE active = 1";
$params = [];
if ($typeId > 0) { $teachersSql .= " AND type_id = ?"; $params[] = $typeId; }
$teachersSql .= " ORDER BY name ASC";
$stTeachers = $pdo->prepare($teachersSql);
$stTeachers->execute($params);
$teachers = $stTeachers->fetchAll(PDO::FETCH_ASSOC);

if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');

$selectedTeacher = null; $teacherMode = 'none';
if ($teacherId) {
  $st = $pdo->prepare("SELECT t.id, t.name, t.type_id, ct.schedule_mode, ct.name AS type_name
                       FROM teachers t JOIN collaborator_types ct ON ct.id = t.type_id
                       WHERE t.id = ?");
  $st->execute([$teacherId]);
  $selectedTeacher = $st->fetch(PDO::FETCH_ASSOC);
  $teacherMode = $selectedTeacher['schedule_mode'] ?? 'none';
}

$periodStart = DateTime::createFromFormat('Y-m-d', $month . '-01');
$periodEnd = (clone $periodStart)->modify('last day of this month');

$scheduleMap = [];
if ($selectedTeacher) {
  $st = $pdo->prepare("SELECT weekday, classes_count, class_minutes, start_time, end_time, break_minutes
                       FROM collaborator_schedules WHERE teacher_id = ?");
  $st->execute([$selectedTeacher['id']]);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) $scheduleMap[(int)$row['weekday']] = $row;
}

$totalExpectedMin = 0;
$daily = [];
$dt = clone $periodStart;
while ($dt <= $periodEnd) {
  $dateStr = $dt->format('Y-m-d'); $w = (int)$dt->format('w');
  $exp = 0;
  if ($selectedTeacher && isset($scheduleMap[$w])) $exp = compute_expected_minutes($scheduleMap[$w], $teacherMode);
  $daily[$dateStr] = ['expectedMin'=>$exp, 'workedMin'=>0, 'items'=>[]];
  $totalExpectedMin += $exp;
  $dt = $dt->modify('+1 day');
}

// Carrega batidas com justificativa (se houver)
if ($selectedTeacher) {
  $st = $pdo->prepare("SELECT a.*,
                              (SELECT name FROM manual_reasons WHERE id = a.manual_reason_id) AS manual_reason_name
                       FROM attendance a
                       WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ? AND a.approved = 1
                       ORDER BY a.date ASC, a.check_in ASC");
  $st->execute([$selectedTeacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $dateStr = $row['date'];
    $worked = 0;
    if (!empty($row['check_in']) && !empty($row['check_out'])) {
      $in = new DateTime($row['check_in']); $out = new DateTime($row['check_out']);
      if ($out > $in) $worked = (int) round(($out->getTimestamp() - $in->getTimestamp()) / 60);
    }
    if (!isset($daily[$dateStr])) $daily[$dateStr] = ['expectedMin'=>0,'workedMin'=>0,'items'=>[]];
    $daily[$dateStr]['workedMin'] += $worked;
    $daily[$dateStr]['items'][] = $row;
  }
}

$totalWorkedMin = array_sum(array_column($daily, 'workedMin'));
$saldo = $totalWorkedMin - $totalExpectedMin;
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Relatórios Mensais | Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    @media print {
      html, body { background:#fff !important; color:#000 !important; font-size:11px !important; }
      .no-print, .navbar, .btn, select, input, form { display:none !important; }
      .table { font-size:10px !important; margin-bottom:0 !important; }
      .table th, .table td { padding:2px 4px !important; vertical-align:middle !important; }
      @page { margin: 10mm 7mm 12mm 7mm; }
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4 no-print">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
        <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav me-auto">
          <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
          <li class="nav-item"><a class="nav-link" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros</a></li>
          <li class="nav-item"><a class="nav-link" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
          <li class="nav-item"><a class="nav-link" href="manual_reasons.php"><i class="bi bi-list-check"></i> Motivos</a></li>
          <li class="nav-item"><a class="nav-link" href="collaborator_types.php"><i class="bi bi-people"></i> Tipos</a></li>
        </ul>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="card mb-3">
      <div class="card-body">
        <form class="row g-3" method="get" autocomplete="off">
          <div class="col-md-3">
            <label class="form-label">Mês</label>
            <input type="month" name="month" class="form-control" value="<?= esc($month) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Tipo</label>
            <select name="type_id" class="form-select" onchange="this.form.submit()">
              <option value="">Todos os tipos</option>
              <?php foreach ($types as $tp): ?>
                <option value="<?= (int)$tp['id'] ?>" <?= $typeId === (int)$tp['id'] ? 'selected' : '' ?>><?= esc($tp['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Colaborador</label>
            <select name="teacher_id" class="form-select" required>
              <option value="">Selecione um colaborador</option>
              <?php foreach ($teachers as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $teacherId === (int)$t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 align-self-end">
            <button class="btn btn-primary">Gerar</button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">PDF</button>
          </div>
        </form>
      </div>
    </div>

    <?php if ($selectedTeacher): ?>
      <div class="card shadow-sm">
        <div class="card-body">
          <h4 class="mb-3">Relatório Mensal - <?= esc($selectedTeacher['name']) ?> - <?= esc((new DateTime($month . '-01'))->format('m/Y')) ?></h4>
          <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="text-muted">Horas esperadas no mês</div><div class="fs-4"><?= minutes_to_hhmm($totalExpectedMin) ?></div></div></div>
            <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="text-muted">Horas trabalhadas no mês</div><div class="fs-4"><?= minutes_to_hhmm($totalWorkedMin) ?></div></div></div>
            <div class="col-md-4"><div class="border rounded p-3 <?= $saldo < 0 ? 'bg-danger-subtle' : 'bg-success-subtle' ?>"><div class="text-muted">Saldo</div><div class="fs-4"><?= minutes_to_hhmm($saldo) ?></div></div></div>
          </div>
          <div class="table-responsive">
            <table class="table table-striped table-sm align-middle">
              <thead>
                <tr>
                  <th style="width:110px;">Data</th>
                  <th style="width:150px;">Rotina Prevista</th>
                  <th style="width:110px;">Esperado</th>
                  <th style="width:110px;">Trabalhado</th>
                  <th>Pontos (com justificativa quando houver)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($daily as $date => $info): ?>
                  <?php
                    $w = (int)(new DateTime($date))->format('w');
                    $sched = $scheduleMap[$w] ?? null;
                    $rotina = '-';
                    if ($teacherMode === 'classes') {
                      $cc = (int)($sched['classes_count'] ?? 0);
                      $cm = (int)($sched['class_minutes'] ?? 0);
                      $rotina = $cc . ' aulas x ' . $cm . ' min';
                    } elseif ($teacherMode === 'time') {
                      $st = $sched['start_time'] ?? null; $et = $sched['end_time'] ?? null; $bk = (int)($sched['break_minutes'] ?? 0);
                      if ($st && $et) $rotina = substr($st,0,5) . '–' . substr($et,0,5) . ($bk>0 ? " (-{$bk}min)" : '');
                    }
                  ?>
                  <tr>
                    <td><?= esc((new DateTime($date))->format('d/m/Y')) ?></td>
                    <td><?= esc($rotina) ?></td>
                    <td><?= minutes_to_hhmm($info['expectedMin']) ?></td>
                    <td><?= minutes_to_hhmm($info['workedMin']) ?></td>
                    <td>
                      <?php if (!empty($info['items'])): ?>
                        <?php foreach ($info['items'] as $it): ?>
                          <div class="mb-1">
                            <?php
                              $entrada = !empty($it['check_in']) ? (new DateTime($it['check_in']))->format('H:i') : '-';
                              $saida = !empty($it['check_out']) ? (new DateTime($it['check_out']))->format('H:i') : '-';
                              $just = null;
                              if (strtolower((string)$it['method']) === 'manual' || !empty($it['manual_reason_id'])) {
                                $name = $it['manual_reason_name'] ?? '';
                                $txt = $it['manual_reason_text'] ?? '';
                                $just = trim($name . ($txt ? ' - ' . $txt : ''));
                              }
                            ?>
                            <span class="badge bg-primary-subtle text-dark">Entrada: <?= esc($entrada) ?></span>
                            <span class="badge bg-secondary-subtle text-dark">Saída: <?= esc($saida) ?></span>
                            <?php if ($just): ?><span class="badge bg-warning-subtle text-dark">Justificativa: <?= esc($just) ?></span><?php endif; ?>
                            <span class="text-muted ms-2">Método: <?= esc($it['method'] ?? '') ?></span>
                            <?php if (!empty($it['photo'])): ?><br><img src="../photos/<?= esc($it['photo']) ?>" alt="Foto" style="max-width:90px;max-height:90px;border-radius:5px;border:1px solid #ccc;"><?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span class="text-muted">Sem pontos</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="fw-bold">
                  <td>Total</td>
                  <td></td>
                  <td><?= minutes_to_hhmm($totalExpectedMin) ?></td>
                  <td><?= minutes_to_hhmm($totalWorkedMin) ?></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="no-print mt-3">
            <button class="btn btn-outline-secondary" onclick="window.print()">Salvar como PDF</button>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
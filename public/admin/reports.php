<?php
require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$admin = current_admin($pdo);

function minutes_to_hhmm(int $minutes): string { $h = intdiv($minutes, 60); $m = $minutes % 60; return sprintf('%02d:%02d', $h, $m); }

$month = $_GET['month'] ?? '';
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$schoolFilter = isset($_GET['school']) ? (int)$_GET['school'] : 0;

list($scopeSql, $scopeParams) = admin_scope_where('t');

// Carrega colaboradores (aplica escopo e filtro escola p/ admin rede)
$teachersSql = "SELECT t.id, t.name FROM teachers t WHERE t.active=1 AND $scopeSql";
$paramsTeachers = $scopeParams;
if ($schoolFilter > 0 && is_network_admin($admin)) {
  $teachersSql .= " AND EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id=t.id AND ts.school_id=?)";
  $paramsTeachers[] = $schoolFilter;
}
$teachersSql .= " ORDER BY t.name ASC";
$stTeachers = $pdo->prepare($teachersSql);
$stTeachers->execute($paramsTeachers);
$teachers = $stTeachers->fetchAll(PDO::FETCH_ASSOC);

if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');
}

// Teacher selecionado (respeita escopo + escola)
$selectedTeacher = null;
$mode = 'classes';
if ($teacherId) {
    $sqlSel = "SELECT t.id, t.name, ct.schedule_mode
               FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id
               WHERE t.id = ? AND $scopeSql";
    $paramsSel = array_merge([$teacherId], $scopeParams);
    if ($schoolFilter > 0 && is_network_admin($admin)) {
      $sqlSel .= " AND EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id=t.id AND ts.school_id=?)";
      $paramsSel[] = $schoolFilter;
    }
    $st = $pdo->prepare($sqlSel);
    $st->execute($paramsSel);
    $selectedTeacher = $st->fetch(PDO::FETCH_ASSOC);
    if ($selectedTeacher) $mode = $selectedTeacher['schedule_mode'] ?? 'classes';
}

$periodStart = DateTime::createFromFormat('Y-m-d', $month . '-01');
$periodEnd = (clone $periodStart)->modify('last day of this month');

$scheduleMap = [];
if ($selectedTeacher) {
    if ($mode === 'classes') {
        $st = $pdo->prepare("SELECT weekday, classes_count, class_minutes FROM teacher_schedules WHERE teacher_id = ?");
        $st->execute([$selectedTeacher['id']]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$row['weekday']] = ['cc' => (int)$row['classes_count'], 'cm' => (int)$row['class_minutes']];
        }
    } elseif ($mode === 'time') {
        $st = $pdo->prepare("SELECT weekday, start_time, end_time, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ?");
        $st->execute([$selectedTeacher['id']]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$row['weekday']] = [
                'start' => $row['start_time'],
                'end' => $row['end_time'],
                'break' => (int)$row['break_minutes']
            ];
        }
    }
}

$totalExpectedMin = 0;
$daily = [];
$dt = clone $periodStart;
while ($dt <= $periodEnd) {
    $dateStr = $dt->format('Y-m-d');
    $w = (int)$dt->format('w');
    $exp = 0;
    if ($selectedTeacher && isset($scheduleMap[$w])) {
        if ($mode === 'classes') {
            $exp = ($scheduleMap[$w]['cc'] ?? 0) * ($scheduleMap[$w]['cm'] ?? 0);
        } elseif ($mode === 'time') {
            $start = $scheduleMap[$w]['start'] ?? null;
            $end   = $scheduleMap[$w]['end'] ?? null;
            $break = $scheduleMap[$w]['break'] ?? 0;
            if ($start && $end) {
                $s = DateTime::createFromFormat('H:i:s', $start);
                $e = DateTime::createFromFormat('H:i:s', $end);
                if ($s && $e) $exp = max(0, (int)(($e->getTimestamp()-$s->getTimestamp())/60) - (int)$break);
            }
        }
    }
    $daily[$dateStr] = ['expectedMin' => $exp, 'workedMin' => 0, 'items' => []];
    $totalExpectedMin += $exp;
    $dt = $dt->modify('+1 day');
}

// Leaves: paid => expected=0
$leavesByDay = [];
if ($selectedTeacher) {
  $stL = $pdo->prepare("SELECT l.*, lt.paid FROM leaves l JOIN leave_types lt ON lt.id=l.type_id
                        WHERE l.teacher_id = ? AND l.approved = 1 AND l.end_date >= ? AND l.start_date <= ?");
  $stL->execute([$selectedTeacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
  while ($lv = $stL->fetch(PDO::FETCH_ASSOC)) {
    $d0 = new DateTime($lv['start_date']); $d1 = new DateTime($lv['end_date']);
    for ($d = clone $d0; $d <= $d1; $d = $d->modify('+1 day')) {
      $k = $d->format('Y-m-d');
      $leavesByDay[$k][] = $lv;
    }
  }
}

if ($selectedTeacher) {
    $st = $pdo->prepare("SELECT a.*, mr.name AS manual_reason_name FROM attendance a LEFT JOIN manual_reasons mr ON mr.id = a.manual_reason_id WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ? ORDER BY a.date ASC, a.check_in ASC");
    $st->execute([$selectedTeacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $dateStr = $row['date'];
        $worked = 0;
        if (!empty($row['check_in']) && !empty($row['check_out'])) {
            $in = new DateTime($row['check_in']);
            $out = new DateTime($row['check_out']);
            if ($out > $in) $worked = (int) round(($out->getTimestamp() - $in->getTimestamp()) / 60);
        }
        if (!isset($daily[$dateStr])) $daily[$dateStr] = ['expectedMin' => 0, 'workedMin' => 0, 'items' => []];
        $daily[$dateStr]['workedMin'] += $worked;
        $daily[$dateStr]['items'][] = $row;
    }
}

// Ajuste expected por licenças pagas
foreach ($daily as $k => &$d) {
  $leaves = $leavesByDay[$k] ?? [];
  foreach ($leaves as $lv) {
    if ((int)$lv['paid'] === 1) {
      $totalExpectedMin -= $d['expectedMin'];
      $d['expectedMin'] = 0;
      break;
    }
  }
}
unset($d);

$totalWorkedMin = array_sum(array_column($daily, 'workedMin'));
$saldo = $totalWorkedMin - $totalExpectedMin;

$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['teacher_id']) && !$selectedTeacher) {
    $feedback = '<div class="alert alert-warning">Colaborador não encontrado ou sem permissão.</div>';
}

$schools = [];
if (is_network_admin($admin)) {
  $schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Exportação para PDF (Dompdf)
if (($selectedTeacher) && (isset($_GET['export']) && $_GET['export'] === 'pdf')) {
  $autoload = __DIR__ . '/../../vendor/autoload.php';
  if (file_exists($autoload)) {
    require_once $autoload;
    ob_start();
    include __DIR__ . '/_tpl_reports_pdf.php';
    $html = ob_get_clean();
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('relatorio_'.$selectedTeacher['id'].'_'.$month.'.pdf');
    exit;
  } else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Exportação PDF indisponível. Instale as dependências:\n- composer require dompdf/dompdf\nE tente novamente.";
    exit;
  }
}

// Helper para montar URL preservando filtros
function build_url_with(array $extra): string {
  $q = $_GET;
  foreach ($extra as $k => $v) $q[$k] = $v;
  return 'reports.php?' . http_build_query($q);
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Relatórios Mensais | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg bg-body-tertiary mb-4">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">Admin</a>
            <div class="ms-auto">
                <a class="btn btn-outline-secondary me-2" href="teachers.php">Colaboradores</a>
                <a class="btn btn-outline-secondary me-2" href="attendances.php">Registros</a>
                <a class="btn btn-outline-danger" href="logout.php">Sair</a>
            </div>
        </div>
    </nav>
    <div class="container">
        <?= $feedback ?>
        <div class="card mb-3">
            <div class="card-body">
                <form class="row g-3" method="get" autocomplete="off">
                    <div class="col-md-3">
                        <label class="form-label">Mês</label>
                        <input type="month" name="month" class="form-control" value="<?= esc($month) ?>">
                    </div>
                    <?php if (is_network_admin($admin)): ?>
                      <div class="col-md-3">
                        <label class="form-label">Escola</label>
                        <select name="school" class="form-select">
                          <option value="">Todas</option>
                          <?php foreach ($schools as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $schoolFilter === (int)$s['id'] ? 'selected':'' ?>><?= esc($s['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label">Colaborador</label>
                        <select name="teacher_id" class="form-select" required>
                            <option value="">Selecione um colaborador</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $teacherId === (int)$t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 align-self-end d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary w-100">Gerar</button>
                        <?php if ($selectedTeacher): ?>
                          <a class="btn btn-outline-secondary w-100" href="<?= esc(build_url_with(['export'=>'pdf'])) ?>">Exportar PDF</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selectedTeacher): ?>
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4 class="mb-3">Relatório Mensal - <?= esc($selectedTeacher['name']) ?> - <?= esc((new DateTime($month . '-01'))->format('m/Y')) ?></h4>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="border rounded p-3 bg-light">
                                <div class="text-muted">Horas esperadas no mês</div>
                                <div class="fs-4"><?= minutes_to_hhmm($totalExpectedMin) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 bg-light">
                                <div class="text-muted">Horas trabalhadas no mês</div>
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
                                    <th style="width:220px;">Justificativa</th>
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
                                    <td></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="no-print mt-3 d-flex gap-2">
                        <button class="btn btn-outline-secondary" onclick="window.print()">Salvar como PDF (navegador)</button>
                        <a class="btn btn-outline-secondary" href="<?= esc(build_url_with(['export'=>'pdf'])) ?>">Exportar PDF (servidor)</a>
                        <a class="btn btn-outline-secondary" href="reports_financial.php?teacher_id=<?= (int)$selectedTeacher['id'] ?>&month=<?= esc($month) ?>">Financeiro</a>
                    </div>
                </div>
            </div>
        <?php elseif ($teacherId): ?>
            <div class="alert alert-warning mt-3">Nenhum colaborador selecionado ou encontrado.</div>
        <?php endif; ?>
    </div>
</body>
</html>
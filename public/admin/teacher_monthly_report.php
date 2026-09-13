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
  $st = $pdo->prepare("SELECT t.id, t.name, t.base_salary, t.created_at, ct.schedule_mode
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
    $st = $pdo->prepare("SELECT weekday, start_time, end_time, end_next_day, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ?");
    $st->execute([$selectedTeacher['id']]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $scheduleMap[(int)$r['weekday']] = [
        'start' => $r['start_time'],
        'end' => $r['end_time'],
        'end_next_day' => (int)($r['end_next_day'] ?? 0),
        'break' => (int)$r['break_minutes']
      ];
    }
  } elseif ($mode === 'hours') {
    $st = $pdo->prepare("SELECT weekday, total_minutes, break_minutes FROM collaborator_hours_schedules WHERE teacher_id = ?");
    $st->execute([$selectedTeacher['id']]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $scheduleMap[(int)$r['weekday']] = [
        'total' => (int)$r['total_minutes'],
        'break' => (int)$r['break_minutes'],
      ];
    }
  }
}

// Per-day skeleton
$totalExpectedMin = 0;
$daily = [];
// Escola do colaborador → captura feriados específicos da escola E os da rede.
// Passar null aqui (bug antigo) ignorava feriados com school_id definido.
$reportSchoolId = $selectedTeacher ? primary_school_id_for_teacher($pdo, (int)$selectedTeacher['id']) : null;
$holidays = get_holidays_in_period($pdo, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'), $reportSchoolId);

for ($d = clone $periodStart; $d <= $periodEnd; $d = $d->modify('+1 day')) {
  $dateStr = $d->format('Y-m-d');
  $w = (int)$d->format('w');
  $exp = 0;
  
  // Verifica se é dia útil (considerando feriados e exceções)
  $isWorkday = is_working_day($pdo, $dateStr, $reportSchoolId);
  
  if ($selectedTeacher && isset($scheduleMap[$w]) && $isWorkday) {
    if ($mode === 'classes') {
      $exp = ($scheduleMap[$w]['cc'] ?? 0) * ($scheduleMap[$w]['cm'] ?? 0);
    } elseif ($mode === 'time') {
      $start = $scheduleMap[$w]['start'] ?? null;
      $end   = $scheduleMap[$w]['end'] ?? null;
      $break = (int)($scheduleMap[$w]['break'] ?? 0);
      $endNext = (int)($scheduleMap[$w]['end_next_day'] ?? 0);
      if ($start && $end) {
        $win = compute_schedule_window([
          'start_time' => $start,
          'end_time' => $end,
          'end_next_day' => $endNext,
          'break_minutes' => $break,
        ], $dateStr);
        // Janela completa — break_minutes não é descontado (intervalo conta
        // como tempo trabalhado; modelo "cheio vs cheio").
        if ($win) $exp = max(0, (int)(($win['end']->getTimestamp() - $win['start']->getTimestamp()) / 60));
      }
    } elseif ($mode === 'hours') {
      $exp = max(0, (int)($scheduleMap[$w]['total'] ?? 0));
    }
  }
  
  $daily[$dateStr] = [
    'expectedMin' => $exp, 
    'workedMin' => 0, 
    'items' => [], 
    'leaves' => [],
    'holiday' => isset($holidays[$dateStr]) ? $holidays[$dateStr] : null
  ];
  $totalExpectedMin += $exp;
}

// Afastamentos aprovados (paid => expected=0)
$leaves = []; // Array para armazenar informações dos afastamentos
if ($selectedTeacher) {
  $stL = $pdo->prepare("SELECT l.*, lt.paid, lt.name as leave_type_name
                        FROM leaves l
                        JOIN leave_types lt ON lt.id = l.type_id
                        WHERE l.teacher_id = ? AND l.approved = 1 AND l.end_date >= ? AND l.start_date <= ?");
  $stL->execute([$selectedTeacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
  while ($lv = $stL->fetch(PDO::FETCH_ASSOC)) {
    $d0 = new DateTime($lv['start_date']);
    $d1 = new DateTime($lv['end_date']);
    for ($d = clone $d0; $d <= $d1; $d = $d->modify('+1 day')) {
      $k = $d->format('Y-m-d');
      if (!isset($daily[$k])) $daily[$k] = ['expectedMin' => 0, 'workedMin' => 0, 'items' => [], 'leaves' => []];
      
      // Adiciona informações do afastamento ao dia
      $daily[$k]['leaves'][] = [
        'type' => $lv['leave_type_name'],
        'paid' => (int)$lv['paid'],
        'excuses_absence' => (int)($lv['excuses_absence'] ?? 0),
        'description' => $lv['description'] ?? '',
        'cid_code' => $lv['cid_code'] ?? '',
        'id' => $lv['id']
      ];
      
      if ((int)($lv['excuses_absence'] ?? 0) === 1) { // abona a falta → zera jornada
        $totalExpectedMin -= $daily[$k]['expectedMin'];
        $daily[$k]['expectedMin'] = 0;
      }
    }
    
    // Adiciona à lista de afastamentos para exibição separada
    $leaves[] = $lv;
  }
}

// Registros de ponto do mês (APENAS APROVADOS)
if ($selectedTeacher) {
  $st = $pdo->prepare("SELECT a.*, mr.name AS manual_reason_name, COALESCE(NULLIF(ed.name, ''), ed.username) AS edited_by_username
                       FROM attendance a
                       LEFT JOIN manual_reasons mr ON mr.id = a.manual_reason_id
                       LEFT JOIN admins ed ON ed.id = a.editado_por
                       WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ? AND a.approved = 1
                       ORDER BY a.date ASC, a.check_in ASC");
  $st->execute([$selectedTeacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $d = $row['date'];
    $minutes = 0;
    if (!empty($row['check_in']) && !empty($row['check_out'])) {
      $in = new DateTime($row['check_in']);
      $out = new DateTime($row['check_out']);
      if ($out > $in) $minutes = (int) round(($out->getTimestamp() - $in->getTimestamp()) / 60);
    }
    if (!isset($daily[$d])) $daily[$d] = ['expectedMin' => 0, 'workedMin' => 0, 'breakMin' => 0, 'items' => []];
    if (!isset($daily[$d]['breakMin'])) $daily[$d]['breakMin'] = 0;
    // Modelo "cheio vs cheio": o intervalo CONTA como tempo trabalhado —
    // soma em workedMin (presença cheia) e também em breakMin (informativo).
    if (($row['record_type'] ?? 'work') === 'break') {
      $daily[$d]['breakMin'] += $minutes;
      $daily[$d]['workedMin'] += $minutes;
    } else {
      $daily[$d]['workedMin'] += $minutes;
    }
    $daily[$d]['items'][] = $row;
  }
}

// Janela de contagem: dias antes do início (config/cadastro) ou no futuro NÃO
// contam — zera previsto/trabalhado/efetivo para que totais, saldo e faltas só
// reflitam os dias contados (coerente com o relatório financeiro).
$teacherStartDate = counting_start_for($selectedTeacher['created_at'] ?? null);
$today = date('Y-m-d');
$daily = apply_counting_window($daily, $teacherStartDate, $today, ['expectedMin', 'workedMin', 'effectiveMin']);
$totalExpectedMin = (int) array_sum(array_column($daily, 'expectedMin'));

$totalWorkedMin = array_sum(array_column($daily, 'workedMin'));
$totalBreakMin  = array_sum(array_map(static fn($d) => (int)($d['breakMin'] ?? 0), $daily));

// Saldo usa EFETIVO canônico (helpers.php): presença cheia = work + break.
$totalEffectiveMin = 0;
if ($selectedTeacher) {
    foreach ($daily as $date => $info) {
        // Fora da janela de contagem: não soma (já zerado).
        if ($date < $teacherStartDate || $date > $today) continue;
        if ((int)$info['expectedMin'] === 0 && empty($info['items'])) continue;
        $eff = calculate_effective_worked_minutes($pdo, (int)$selectedTeacher['id'], $date);
        $totalEffectiveMin += $eff;
        $daily[$date]['effectiveMin'] = $eff;
    }
}
$saldo = $totalEffectiveMin - $totalExpectedMin;

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
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

  <div class="container">
    <div class="card mb-3">
      <div class="card-body">
        <form class="admin-filters row g-3" method="get" autocomplete="off">
          <div class="col-12 col-md-4">
            <label class="form-label">Mês</label>
            <input type="month" name="month" class="form-control" value="<?= esc($month) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Colaborador</label>
            <?php
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
          <div class="col-12 col-md-2 align-self-end d-flex gap-2 flex-wrap">
            <button class="btn btn-primary w-100">Gerar</button>
            <?php if ($selectedTeacher): ?>
              <a class="btn btn-outline-secondary w-100" href="<?= esc(build_url_with(['export' => 'pdf'])) ?>">Exportar PDF</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <?php if ($selectedTeacher): ?>
      <?php /* $teacherStartDate já definido no topo (janela de contagem) */ ?>
      <div class="card">
        <div class="card-body">
          <h5 class="mb-3">Relatório Mensal - <?= esc($selectedTeacher['name']) ?> - <?= esc((new DateTime($month . '-01'))->format('m/Y')) ?></h5>
          <div class="admin-kpi-row">
            <div class="admin-kpi-card">
              <div class="admin-kpi-label">Horas esperadas</div>
              <div class="admin-kpi-value"><?= minutes_to_hhmm($totalExpectedMin) ?></div>
            </div>
            <div class="admin-kpi-card">
              <div class="admin-kpi-label">Horas trabalhadas (líquido)</div>
              <?php
                // Exibição = LÍQUIDO (presença − intervalos). O Saldo ao lado segue o
                // modelo "cheio vs cheio" (intervalo conta como trabalhado): por isso
                // líquido + intervalo = presença, e Saldo = presença − previsto.
                $totalNetWorkedMin = max(0, (int)$totalWorkedMin - (int)$totalBreakMin);
              ?>
              <div class="admin-kpi-value"><?= minutes_to_hhmm($totalNetWorkedMin) ?></div>
            </div>
            <?php if ($totalBreakMin > 0): ?>
            <div class="admin-kpi-card">
              <div class="admin-kpi-label">Intervalo</div>
              <div class="admin-kpi-value"><?= minutes_to_hhmm((int)$totalBreakMin) ?></div>
            </div>
            <div class="admin-kpi-card">
              <div class="admin-kpi-label">Presença</div>
              <div class="admin-kpi-value"><?= minutes_to_hhmm((int)$totalEffectiveMin) ?></div>
            </div>
            <?php endif; ?>
            <div class="admin-kpi-card <?= $saldo > 0 ? 'bg-success-subtle' : '' ?>">
              <div class="admin-kpi-label"><?= $saldo < 0 ? 'Horas a compensar' : 'Saldo' ?></div>
              <div class="admin-kpi-value"><?= $saldo < 0 ? minutes_to_hhmm(abs($saldo)) : minutes_to_hhmm($saldo) ?></div>
            </div>
          </div>

          <p class="text-muted small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            "Horas trabalhadas" é o líquido (sem intervalo). O <strong>saldo</strong> usa a <strong>presença</strong> (trabalhado + intervalo) comparada ao esperado — o intervalo é um direito do colaborador e <strong>não gera déficit</strong>.
          </p>

          <div class="admin-table-wrap table-responsive">
            <table class="table table-striped table-sm align-middle table-sticky">
              <thead class="table-light">
                <tr>
                  <th scope="col" style="width:110px;">Data</th>
                  <th scope="col" style="width:110px;">Esperado</th>
                  <th scope="col" style="width:110px;">Trabalhado</th>
                  <th scope="col">Pontos</th>
                  <th scope="col" style="width:240px;">Justificativa</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($daily as $date => $info): ?>
                  <tr <?= !empty($info['holiday']) ? 'class="table-danger"' : '' ?>>
                    <td>
                      <?= esc((new DateTime($date))->format('d/m/Y')) ?>
                      <?php if (!empty($info['holiday'])): ?>
                        <div class="badge bg-danger mt-1"><?= esc($info['holiday']['name']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= minutes_to_hhmm($info['expectedMin']) ?></td>
                    <td><?= minutes_to_hhmm(max(0, (int)$info['workedMin'] - (int)($info['breakMin'] ?? 0))) ?></td>
                    <td>
                      <?php if (!empty($info['items'])):
                        // Consolida: 1 par entrada→saída do dia + sub-lista de intervalos
                        $itemsForConsolidation = array_map(function($it) use ($selectedTeacher) {
                            $it['teacher_id'] = (int)$selectedTeacher['id'];
                            $it['teacher_name'] = $selectedTeacher['name'];
                            return $it;
                        }, $info['items']);
                        $dayCons = consolidate_attendance_by_day($itemsForConsolidation);
                        $dayCon = $dayCons[0] ?? null;
                        if ($dayCon):
                            $dayIn  = $dayCon['check_in']  ? substr($dayCon['check_in'], 0, 5)  : '-';
                            $dayOut = $dayCon['check_out'] ? substr($dayCon['check_out'], 0, 5) : '-';
                            $primaryItem = null;
                            foreach ($info['items'] as $it) {
                                if (($it['record_type'] ?? 'work') === 'work') { $primaryItem = $it; break; }
                            }
                            if ($primaryItem === null) $primaryItem = $info['items'][0];
                            $methodLabels = ['cpf' => 'CPF', 'pin' => 'CPF', 'foto' => 'Foto', 'face' => 'Reconhecimento Facial', 'manual' => 'Manual'];
                            $methodLabel = $methodLabels[strtolower((string)($primaryItem['method'] ?? ''))] ?? ($primaryItem['method'] ?? '-');
                            $editTxt = '';
                            if (!empty($primaryItem['data_edicao'])) {
                                $editTxt = ' | Editado por ' . esc($primaryItem['edited_by_username'] ?? ('#' . (int)($primaryItem['editado_por'] ?? 0))) .
                                           ' em ' . esc(date('d/m/Y H:i', strtotime($primaryItem['data_edicao']))) .
                                           ' - Motivo: ' . esc($primaryItem['motivo_edicao'] ?? '-');
                            }
                        ?>
                          <div class="mb-1">
                            <span class="badge bg-primary-subtle text-dark">Entrada: <?= esc($dayIn) ?></span>
                            <span class="badge bg-secondary-subtle text-dark">Saída: <?= esc($dayOut) ?></span>
                            <span class="text-muted ms-2">Método: <?= esc($methodLabel) ?></span>
                            <?php if (!empty($dayCon['breaks'])): ?>
                              <span class="badge bg-info-subtle text-info-emphasis border ms-2"><i class="bi bi-pause-circle me-1"></i><?= count($dayCon['breaks']) ?> int. (<?= esc(format_duration_minutes((int)$dayCon['total_break_minutes'])) ?>)</span>
                            <?php endif; ?>
                            <?php if ($editTxt): ?>
                              <div class="small text-muted"><?= $editTxt ?></div>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($dayCon['breaks'])): ?>
                            <ul class="list-unstyled mb-0 small text-muted" style="padding-left: 1.25rem;">
                              <?php foreach ($dayCon['breaks'] as $bi => $bb):
                                $bs = $bb['start'] ? substr($bb['start'], 0, 5) : '-';
                                $be = $bb['end']   ? substr($bb['end'], 0, 5)   : '<em>aberto</em>';
                                $bd = $bb['duration_minutes'] !== null ? format_duration_minutes((int)$bb['duration_minutes']) : '-';
                              ?>
                                <li>↳ Intervalo <?= ($bi + 1) ?>: <?= esc($bs) ?> → <?= $be ?> <span class="text-muted">(<?= esc($bd) ?>)</span></li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        <?php endif; ?>
                      <?php else: ?>
                        <?php 
                        // Só marca FALTA se: tinha jornada, data já passou E após data de criação
                        $isFalta = ($info['expectedMin'] > 0) && ($date <= date('Y-m-d')) && ($date >= $teacherStartDate);
                        ?>
                        <?php if ($isFalta): ?>
                          <span class="badge bg-danger text-white fs-6">
                            <i class="bi bi-exclamation-triangle-fill"></i> FALTA
                          </span>
                          <div class="small text-danger mt-1">Jornada prevista não registrada</div>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                      $just = [];
                      // Afastamentos
                      if (!empty($info['leaves'])) {
                        foreach ($info['leaves'] as $leave) {
                          $leaveText = '🏥 ' . $leave['type'];
                          if (!empty($leave['cid_code'])) $leaveText .= ' (CID: ' . $leave['cid_code'] . ')';
                          if ($leave['paid']) $leaveText .= ' - Remunerado';
                          $just[] = $leaveText;
                        }
                      }
                      // Justificativas manuais
                      foreach ($info['items'] as $it) {
                        if (!empty($it['manual_reason_id'])) {
                          $jr = trim(($it['manual_reason_name'] ?? 'Manual') . (!empty($it['manual_reason_text']) ? ' - ' . $it['manual_reason_text'] : ''));
                          if ($jr !== '') $just[] = $jr;
                        }
                      }
                      if ($just) {
                        foreach ($just as $j) {
                          echo '<div class="small mb-1">' . esc($j) . '</div>';
                        }
                      } else {
                        echo '<span class="text-muted">-</span>';
                      }
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="fw-bold">
                  <td>Total</td>
                  <td><?= minutes_to_hhmm($totalExpectedMin) ?></td>
                  <td><?= minutes_to_hhmm(max(0, (int)$totalWorkedMin - (int)$totalBreakMin)) ?></td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <?php if (!empty($leaves)): ?>
            <div class="card shadow-sm mt-4">
              <div class="card-header bg-info text-white">
                <h6 class="mb-0"><i class="bi bi-person-x me-2"></i>Afastamentos no Período</h6>
              </div>
              <div class="card-body">
                <div class="table-responsive">
                  <table class="table table-sm table-bordered">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">Tipo</th>
                        <th scope="col">Período</th>
                        <th scope="col">Dias</th>
                        <th scope="col">Remunerado</th>
                        <th scope="col">Detalhes</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($leaves as $lv): ?>
                        <?php
                        $startFmt = (new DateTime($lv['start_date']))->format('d/m/Y');
                        $endFmt = (new DateTime($lv['end_date']))->format('d/m/Y');
                        $daysCount = $lv['days_count'] ?? ((new DateTime($lv['start_date']))->diff(new DateTime($lv['end_date']))->days + 1);
                        ?>
                        <tr>
                          <td><?= esc($lv['leave_type_name']) ?></td>
                          <td><?= esc($startFmt) ?> até <?= esc($endFmt) ?></td>
                          <td class="text-center"><span class="badge bg-info"><?= $daysCount ?> dia<?= $daysCount != 1 ? 's' : '' ?></span></td>
                          <td class="text-center"><?= (int)$lv['paid'] ? '<span class="badge bg-success">Sim</span>' : '<span class="badge bg-secondary">Não</span>' ?></td>
                          <td>
                            <?php if (!empty($lv['cid_code'])): ?>
                              <div class="small">CID: <?= esc($lv['cid_code']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($lv['description'])): ?>
                              <div class="small text-muted"><?= esc(mb_strimwidth($lv['description'], 0, 100, '...')) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($lv['attachment'])): ?>
                              <a href="view_leave_attachment.php?id=<?= (int)$lv['id'] ?>" class="btn btn-sm btn-outline-primary mt-1" target="_blank">
                                <i class="bi bi-file-earmark-text me-1"></i>Ver Atestado
                              </a>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="alert alert-info mb-0 mt-2">
                  <i class="bi bi-info-circle me-2"></i>
                  <strong>Importante:</strong> Afastamentos remunerados não afetam o cálculo de horas esperadas. 
                  Os dias com afastamento remunerado têm expectativa zerada automaticamente.
                </div>
              </div>
            </div>
          <?php endif; ?>

          <div class="admin-report-actions no-print">
            <button class="btn btn-outline-secondary" onclick="window.print()" title="Imprimir ou salvar como PDF pelo navegador"><i class="bi bi-printer me-1"></i>Imprimir</button>
            <a class="btn btn-outline-secondary" href="<?= esc(build_url_with(['export' => 'pdf'])) ?>" title="Exportar PDF gerado no servidor"><i class="bi bi-filetype-pdf me-1"></i>Exportar PDF</a>
            <a class="btn btn-outline-secondary" href="reports_financial.php?teacher_id=<?= (int)$selectedTeacher['id'] ?>&month=<?= esc($month) ?>"><i class="bi bi-currency-dollar me-1"></i>Financeiro</a>
            <a class="btn btn-secondary" href="teachers.php"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>

</html>
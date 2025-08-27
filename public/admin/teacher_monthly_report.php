<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

$stmt = $pdo->prepare("SELECT t.*, ct.slug AS type_slug, ct.name AS type_name, ct.schedule_mode
                       FROM teachers t
                       JOIN collaborator_types ct ON ct.id = t.type_id
                       WHERE t.id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();
if (!$teacher) { echo "Colaborador não encontrado."; exit; }

$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

$days = [];
$period = new DatePeriod(new DateTime($month_start), new DateInterval('P1D'), (new DateTime($month_end))->modify('+1 day'));
foreach ($period as $dt) {
  $days[$dt->format('Y-m-d')] = ['date'=>$dt->format('Y-m-d'),'weekday'=>(int)$dt->format('w'),'att'=>[],'sched'=>null,'esperado_min'=>0,'realizado_min'=>0,'saldo_min'=>0,'extra_min'=>0];
}

// Rotina
$schedules = [];
$stmt = $pdo->prepare("SELECT * FROM collaborator_schedules WHERE teacher_id = ?");
$stmt->execute([$teacher_id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sch) $schedules[(int)$sch['weekday']] = $sch;

// Registros (apenas aprovados) incluindo justificativa
$stmt = $pdo->prepare("SELECT a.*,(SELECT name FROM manual_reasons WHERE id=a.manual_reason_id) AS manual_reason_name
                       FROM attendance a
                       WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ? AND a.approved = 1
                       ORDER BY a.date, a.check_in");
$stmt->execute([$teacher_id, $month_start, $month_end]);
$atts = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $atts[$r['date']][] = $r;

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

$total_esperado = $total_realizado = $total_extra = $total_falta = 0;
foreach ($days as $d => &$info) {
  $weekday = $info['weekday'];
  $sched = $schedules[$weekday] ?? null;
  $attList = $atts[$d] ?? [];
  $info['sched'] = $sched;
  $info['att'] = $attList;

  $esperado_min = compute_expected_minutes($sched, (string)$teacher['schedule_mode']);
  $realizado_min = 0;
  foreach ($attList as $att) {
    if ($att['check_in'] && $att['check_out']) {
      $inicio = new DateTime($att['check_in']);
      $fim = new DateTime($att['check_out']);
      $interval = $inicio->diff($fim);
      $realizado_min += ($interval->h * 60) + $interval->i + ($interval->d * 24 * 60);
    }
  }
  $saldo_min = $realizado_min - $esperado_min;
  $extra_min = $saldo_min > 0 ? $saldo_min : 0;

  $info['esperado_min'] = $esperado_min;
  $info['realizado_min'] = $realizado_min;
  $info['saldo_min'] = $saldo_min;
  $info['extra_min'] = $extra_min;

  $total_esperado += $esperado_min;
  $total_realizado += $realizado_min;
  $total_extra += $extra_min;
  if ($esperado_min > 0 && empty($attList)) $total_falta++;
}
unset($info);

$weekdays_pt = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
$meses_pt = ['01'=>'Janeiro','02'=>'Fevereiro','03'=>'Março','04'=>'Abril','05'=>'Maio','06'=>'Junho','07'=>'Julho','08'=>'Agosto','09'=>'Setembro','10'=>'Outubro','11'=>'Novembro','12'=>'Dezembro'];
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Relatório Mensal - <?= esc($teacher['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .table th, .table td { font-size: 0.97rem; }
    .saldo-neg { color: #c82333; font-weight: bold; }
    .saldo-pos { color: #218838; font-weight: bold; }
    .saldo-zero { color: #888; }
    .extra { color: #1e7e34; font-weight: bold; }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold" href="dashboard.php"><img src="../img/logo.png" alt="Logo" style="height:auto;max-width:130px;"></a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link" href="dashboard.php">Início</a></li>
          <li class="nav-item"><a class="nav-link" href="attendances.php">Registros</a></li>
          <li class="nav-item"><a class="nav-link active" href="teachers.php">Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
          <li class="nav-item"><a class="nav-link" href="manual_reasons.php"><i class="bi bi-list-check"></i> Motivos</a></li>
          <li class="nav-item"><a class="nav-link" href="collaborator_types.php"><i class="bi bi-people"></i> Tipos</a></li>
        </ul>
        <a href="logout.php" class="btn btn-outline-light">Sair</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="d-flex align-items-center justify-content-between mt-2 mb-3">
      <div>
        <h3>Relatório Mensal de Ponto</h3>
        <h5><?= esc($teacher['name']) ?> <small class="text-muted">(<?= esc($teacher['cpf']) ?>)</small></h5>
        <div class="text-muted mb-2">Mês: <b><?= $meses_pt[substr($month, 5, 2)] ?? $month ?></b> de <b><?= substr($month, 0, 4) ?></b></div>
        <div class="text-muted mb-1">Tipo: <b><?= esc($teacher['type_name'] ?? '-') ?></b></div>
      </div>
      <div><a class="btn btn-outline-secondary" href="teachers.php">Voltar</a></div>
    </div>

    <form class="row row-cols-lg-auto g-3 align-items-end mb-3" method="get" autocomplete="off">
      <input type="hidden" name="teacher_id" value="<?= esc($teacher_id) ?>">
      <div class="col">
        <label class="form-label">Mês</label>
        <input type="month" name="month" class="form-control" value="<?= esc($month) ?>">
      </div>
      <div class="col"><button class="btn btn-primary" type="submit">Ver Relatório</button></div>
      <div class="col"><button class="btn btn-outline-primary" onclick="window.print()" type="button">🖨️ Imprimir</button></div>
    </form>

    <?php $saldo_geral = $total_realizado - $total_esperado; ?>
    <div class="table-responsive mb-4">
      <table class="table table-bordered table-striped align-middle">
        <thead>
          <tr>
            <th>Data</th>
            <th>Dia</th>
            <th>Rotina Prevista</th>
            <th>Total Esperado</th>
            <th>Entradas</th>
            <th>Saídas</th>
            <th>Justificativa</th>
            <th>Trabalhado</th>
            <th>Saldo</th>
            <th>Horas Extras</th>
          </tr>
        </thead>
        <tbody class="text-center">
          <?php foreach ($days as $info):
            if ($info['weekday'] == 0) continue;
            $sched = $info['sched'] ?? null;
            $mode = (string)$teacher['schedule_mode'];
            $rotina = '-';
            if ($mode === 'classes') { $rotina = (int)($sched['classes_count'] ?? 0) . ' aulas x ' . (int)($sched['class_minutes'] ?? 0) . ' min'; }
            elseif ($mode === 'time') {
              $st = $sched['start_time'] ?? null; $et = $sched['end_time'] ?? null; $bk = (int)($sched['break_minutes'] ?? 0);
              if ($st && $et) $rotina = substr($st,0,5) . '–' . substr($et,0,5) . ($bk>0 ? " (-{$bk}min)" : '');
            }
            $justs = [];
            foreach ($info['att'] as $att) {
              if (strtolower((string)$att['method']) === 'manual' || $att['manual_reason_id']) {
                $name = $att['manual_reason_name'] ?? '';
                $txt = $att['manual_reason_text'] ?? '';
                $justs[] = trim($name . ($txt ? ' - ' . $txt : ''));
              }
            }
          ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($info['date'])) ?></td>
            <td><?= esc($weekdays_pt[$info['weekday']]) ?></td>
            <td><?= esc($rotina) ?></td>
            <td><?= sprintf('%dh%02d', intdiv($info['esperado_min'], 60), $info['esperado_min'] % 60) ?></td>
            <td><?php foreach ($info['att'] as $att) if ($att['check_in']) echo date('H:i', strtotime($att['check_in'])) . '<br>'; ?></td>
            <td><?php foreach ($info['att'] as $att) if ($att['check_out']) echo date('H:i', strtotime($att['check_out'])) . '<br>'; ?></td>
            <td><?= !empty($justs) ? esc(implode(' | ', $justs)) : '-' ?></td>
            <td><?= sprintf('%dh%02d', intdiv($info['realizado_min'], 60), $info['realizado_min'] % 60) ?></td>
            <td>
              <?php
                if ($info['saldo_min'] == 0) echo '<span class="text-muted">0h00</span>';
                elseif ($info['saldo_min'] > 0) echo '<span class="text-success">+' . sprintf('%dh%02d', intdiv($info['saldo_min'], 60), $info['saldo_min'] % 60) . '</span>';
                else echo '<span class="text-danger">-' . sprintf('%dh%02d', intdiv(abs($info['saldo_min']), 60), abs($info['saldo_min']) % 60) . '</span>';
              ?>
            </td>
            <td><?= $info['extra_min']>0 ? '<span class="text-success">'.sprintf('%dh%02d', intdiv($info['extra_min'], 60), $info['extra_min'] % 60).'</span>' : '<span class="text-muted">0h00</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mb-4">
      <table class="table table-borderless mb-0">
        <tr>
          <th class="text-end" width="30%">Total Horas Esperadas</th>
          <td width="20%"><b><?= sprintf('%dh%02d', intdiv($total_esperado, 60), $total_esperado % 60) ?></b></td>
          <th class="text-end" width="30%">Total Horas Trabalhadas</th>
          <td width="20%"><b><?= sprintf('%dh%02d', intdiv($total_realizado, 60), $total_realizado % 60) ?></b></td>
        </tr>
        <tr>
          <th class="text-end">Saldo Geral (Mês)</th>
          <td>
            <?php $saldo_geral = $total_realizado - $total_esperado; ?>
            <span class="<?= $saldo_geral > 0 ? 'text-success' : ($saldo_geral < 0 ? 'text-danger' : 'text-muted') ?>">
              <?= ($saldo_geral > 0 ? '+' : ($saldo_geral < 0 ? '-' : '')) . sprintf('%dh%02d', intdiv(abs($saldo_geral), 60), abs($saldo_geral) % 60) ?>
            </span>
          </td>
          <th class="text-end">Horas Extras (Mês)</th>
          <td><span class="text-success"><?= sprintf('%dh%02d', intdiv($total_extra, 60), $total_extra % 60) ?></span></td>
        </tr>
      </table>
    </div>
  </div>
</body>
</html>
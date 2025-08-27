<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

// Ações de aprovação/rejeição
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['reject_attendance_id'])) {
    $id = (int)$_POST['reject_attendance_id'];
    $stmt = $pdo->prepare("UPDATE attendance SET approved = 0 WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: attendances.php?" . http_build_query($_GET));
    exit;
  }
  if (isset($_POST['approve_attendance_id'])) {
    $id = (int)$_POST['approve_attendance_id'];
    $stmt = $pdo->prepare("UPDATE attendance SET approved = 1 WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: attendances.php?" . http_build_query($_GET));
    exit;
  }
}

// Tipos de colaborador para filtro
$types = $pdo->query("SELECT id, name FROM collaborator_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Filtros
$where = [];
$params = [];

if (!empty($_GET['teacher'])) {
  $where[] = "a.teacher_id = ?";
  $params[] = (int)$_GET['teacher'];
}
if (!empty($_GET['type_id']) && ctype_digit((string)$_GET['type_id'])) {
  $where[] = "t.type_id = ?";
  $params[] = (int)$_GET['type_id'];
}
if (!empty($_GET['date1'])) {
  $where[] = "a.date >= ?";
  $params[] = $_GET['date1'];
}
if (!empty($_GET['date2'])) {
  $where[] = "a.date <= ?";
  $params[] = $_GET['date2'];
}

$sql = "SELECT a.*,
               t.name, t.cpf, t.type_id,
               ct.name AS type_name, ct.schedule_mode,
               mr.name AS manual_reason_name,
               ad.username AS manual_by_admin_username
        FROM attendance a
        JOIN teachers t ON a.teacher_id = t.id
        JOIN collaborator_types ct ON ct.id = t.type_id
        LEFT JOIN manual_reasons mr ON mr.id = a.manual_reason_id
        LEFT JOIN admins ad ON ad.id = a.manual_by_admin_id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY a.check_in DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Colaboradores para seletor
$all_teachers = $pdo->query("SELECT id, name FROM teachers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$weekdays = [0=>'Domingo',1=>'Segunda-feira',2=>'Terça-feira',3=>'Quarta-feira',4=>'Quinta-feira',5=>'Sexta-feira',6=>'Sábado'];

// Função para calcular esperado conforme modo
function compute_expected_minutes(array $sched = null, string $mode = 'none'): int {
  if (!$sched) return 0;
  if ($mode === 'classes') {
    $cc = (int)($sched['classes_count'] ?? 0);
    $cm = (int)($sched['class_minutes'] ?? 0);
    return max(0, $cc * $cm);
  }
  if ($mode === 'time') {
    $start = $sched['start_time'] ?? null;
    $end   = $sched['end_time'] ?? null;
    if (!$start || !$end) return 0;
    $break = (int)($sched['break_minutes'] ?? 0);
    $s = DateTime::createFromFormat('H:i:s', strlen($start)===5 ? $start.':00' : $start);
    $e = DateTime::createFromFormat('H:i:s', strlen($end)===5 ? $end.':00' : $end);
    if (!$s || !$e) return 0;
    $diff = (int)(($e->getTimestamp() - $s->getTimestamp()) / 60);
    if ($diff < 0) $diff += 24*60;
    return max(0, $diff - max(0, $break));
  }
  return 0;
}

$resumo = [];
$relatorio = [];
foreach ($rows as $r) {
  $weekday = (int)date('w', strtotime($r['date']));
  $stmtSch = $pdo->prepare("SELECT classes_count, class_minutes, start_time, end_time, break_minutes
                            FROM collaborator_schedules WHERE teacher_id = ? AND weekday = ?");
  $stmtSch->execute([$r['teacher_id'], $weekday]);
  $sched = $stmtSch->fetch();

  $total_esperado_min = compute_expected_minutes($sched, (string)$r['schedule_mode']);

  $total_realizado_min = 0;
  if ($r['check_in'] && $r['check_out']) {
    $inicio = new DateTime($r['check_in']);
    $fim = new DateTime($r['check_out']);
    $interval = $inicio->diff($fim);
    $total_realizado_min = ($interval->h * 60) + $interval->i + ($interval->d * 24 * 60);
  }
  $saldo_min = $total_realizado_min - $total_esperado_min;

  $r['sched'] = $sched;
  $r['total_esperado_min'] = $total_esperado_min;
  $r['total_realizado_min'] = $total_realizado_min;
  $r['saldo_min'] = $saldo_min;
  $r['location_in'] = ($r['check_in_lat'] && $r['check_in_lng']) ? [$r['check_in_lat'], $r['check_in_lng']] : null;
  $r['location_out'] = ($r['check_out_lat'] && $r['check_out_lng']) ? [$r['check_out_lat'], $r['check_out_lng']] : null;

  // Justificativa agregada
  $r['justificativa'] = null;
  if (strtolower((string)$r['method']) === 'manual' || $r['manual_reason_id']) {
    $name = $r['manual_reason_name'] ?: '';
    $txt = $r['manual_reason_text'] ?: '';
    $by  = $r['manual_by_admin_username'] ? ' (por ' . $r['manual_by_admin_username'] . ')' : '';
    $r['justificativa'] = trim($name . ($txt ? ' - ' . $txt : '') . $by);
  }

  $relatorio[] = $r;

  // Resumo
  $semana = date('o-W', strtotime($r['date']));
  $mes = date('Y-m', strtotime($r['date']));
  foreach ([['chave'=>$semana,'tipo'=>'semana'],['chave'=>$mes,'tipo'=>'mes']] as $info) {
    $resumo[$info['tipo']][$info['chave']]['esperado']  = ($resumo[$info['tipo']][$info['chave']]['esperado']  ?? 0) + $total_esperado_min;
    $resumo[$info['tipo']][$info['chave']]['realizado'] = ($resumo[$info['tipo']][$info['chave']]['realizado'] ?? 0) + $total_realizado_min;
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Registros de Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .table-img { max-width: 90px; max-height: 90px; border-radius: 5px; }
    .sticky-header th { position: sticky; top: 0; background: #f8f9fa; z-index: 2; }
    .location-coord { font-size: 0.85rem; color: #555; }
    .location-link { font-size: 0.95rem; }
  </style>
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
          <li class="nav-item"><a class="nav-link active" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
          <li class="nav-item"><a class="nav-link" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <span class="navbar-text me-3 d-none d-lg-inline"><i class="bi bi-person-circle"></i> <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?></span>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>

  <?php
    $totalReg = count($relatorio);
    $sumEsperado = 0; $sumRealizado = 0; $sumSaldoNeg = 0; $sumExtra = 0;
    foreach ($relatorio as $rx) {
      $sumEsperado += (int)$rx['total_esperado_min'];
      $sumRealizado += (int)$rx['total_realizado_min'];
      $smin = (int)$rx['saldo_min'];
      if ($smin > 0) $sumExtra += $smin;
      elseif ($smin < 0) $sumSaldoNeg += abs($smin);
    }
    $fmtMin = function(int $m){ return sprintf('%dh%02d', intdiv($m,60), $m%60); };
  ?>
  <div class="container-fluid">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mt-2 mb-3">
      <div class="d-flex align-items-center gap-3">
        <h3 class="mb-0">Registros de Ponto</h3>
        <span class="badge text-bg-secondary">Registros: <?= (int)$totalReg ?></span>
      </div>
      <div class="d-flex gap-2">
        <a href="attendance_manual.php" class="btn btn-success">
          <i class="bi bi-plus-circle"></i> Inserir Ponto Manual
        </a>
        <a href="dashboard.php" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left"></i> Voltar
        </a>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-funnel"></i> Filtros</span>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-range="today">Hoje</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-range="week">Esta semana</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-range="month">Este mês</button>
        </div>
      </div>
      <div class="card-body">
        <form class="row row-cols-1 row-cols-md-2 row-cols-lg-5 g-3 align-items-end" method="get" autocomplete="off">
          <div class="col">
            <label class="form-label">Colaborador</label>
            <select name="teacher" class="form-select">
              <option value="">Todos</option>
              <?php foreach ($all_teachers as $t): ?>
                <option value="<?= esc($t['id']) ?>" <?= isset($_GET['teacher']) && $_GET['teacher'] == $t['id'] ? ' selected' : '' ?>><?= esc($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col">
            <label class="form-label">Tipo</label>
            <select name="type_id" class="form-select">
              <option value="">Todos</option>
              <?php foreach ($types as $tp): ?>
                <option value="<?= (int)$tp['id'] ?>" <?= (isset($_GET['type_id']) && (int)$_GET['type_id'] === (int)$tp['id']) ? 'selected' : '' ?>><?= esc($tp['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col">
            <label class="form-label">Data Inicial</label>
            <input type="date" name="date1" id="date1" class="form-control" value="<?= esc($_GET['date1'] ?? '') ?>">
          </div>
          <div class="col">
            <label class="form-label">Data Final</label>
            <input type="date" name="date2" id="date2" class="form-control" value="<?= esc($_GET['date2'] ?? '') ?>">
          </div>
          <div class="col d-flex gap-2">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
            <a href="attendances.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-table"></i> Resultados</span>
        <div class="d-flex flex-wrap gap-2">
          <span class="badge text-bg-primary" title="Total esperado"><?= $fmtMin($sumEsperado) ?> esperado</span>
          <span class="badge text-bg-info" title="Total trabalhado"><?= $fmtMin($sumRealizado) ?> trabalhado</span>
          <span class="badge text-bg-success" title="Total extra"><?= $fmtMin($sumExtra) ?> extra</span>
          <span class="badge text-bg-danger" title="Total em falta"><?= $fmtMin($sumSaldoNeg) ?> em falta</span>
        </div>
      </div>

      <?php if (!$relatorio): ?>
        <div class="p-4 text-center text-muted">
          <i class="bi bi-inbox" style="font-size:2rem;"></i>
          <div class="mt-2">Nenhum registro encontrado para os filtros selecionados.</div>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover align-middle mb-0">
            <thead class="table-light sticky-header">
              <tr class="text-center">
                <th>Colaborador</th>
                <th>Tipo</th>
                <th>Data</th>
                <th>Dia</th>
                <th>Rotina Prevista</th>
                <th>Total Esperado</th>
                <th>Trabalhado</th>
                <th>Saldo</th>
                <th>Extra</th>
                <th>Entrada</th>
                <th>Saída</th>
                <th>Foto</th>
                <th>Localização</th>
                <th>Justificativa</th>
                <th class="no-print">Status / Ações</th>
              </tr>
            </thead>
            <tbody class="text-center">
              <?php foreach ($relatorio as $r):
                $extra = $r['saldo_min'] > 0 ? $r['saldo_min'] : 0;
                $saldo = $r['saldo_min'] < 0 ? $r['saldo_min'] : 0;
                $mode = (string)$r['schedule_mode'];
                $rotinaPrevista = '-';
                if ($mode === 'classes') {
                  $cc = (int)($r['sched']['classes_count'] ?? 0);
                  $cm = (int)($r['sched']['class_minutes'] ?? 0);
                  $rotinaPrevista = $cc . ' aulas x ' . $cm . ' min';
                } elseif ($mode === 'time') {
                  $st = $r['sched']['start_time'] ?? null;
                  $et = $r['sched']['end_time'] ?? null;
                  $bk = (int)($r['sched']['break_minutes'] ?? 0);
                  if ($st && $et) $rotinaPrevista = substr($st,0,5) . '–' . substr($et,0,5) . ($bk > 0 ? " (-{$bk}min)" : '');
                }
                $rowClass = ((int)$r['approved'] === 1) ? 'table-success-subtle' : 'table-danger-subtle';
              ?>
              <tr class="<?= $rowClass ?>">
                <td class="text-start">
                  <?= esc($r['name']) ?>
                  <?php if (strtolower((string)$r['method']) === 'manual' || $r['manual_reason_id']): ?>
                    <span class="badge rounded-pill text-bg-warning ms-1" title="Registro manual">Manual</span>
                  <?php endif; ?>
                </td>
                <td><?= esc($r['type_name'] ?? '-') ?></td>
                <td class="text-nowrap"><?= date('d/m/Y', strtotime($r['date'])) ?></td>
                <td><?= esc($weekdays[(int)date('w', strtotime($r['date']))]) ?></td>
                <td><?= esc($rotinaPrevista) ?></td>
                <td><span class="badge text-bg-primary"><?= sprintf('%dh%02d', intdiv($r['total_esperado_min'], 60), $r['total_esperado_min'] % 60) ?></span></td>
                <td><span class="badge text-bg-info"><?= sprintf('%dh%02d', intdiv($r['total_realizado_min'], 60), $r['total_realizado_min'] % 60) ?></span></td>
                <td>
                  <?php if ($saldo < 0): ?>
                    <span class="text-danger"><i class="bi bi-arrow-down"></i> <?= sprintf('-%dh%02d', intdiv(abs($saldo), 60), abs($saldo) % 60) ?></span>
                  <?php else: ?>
                    <span class="text-muted">0h00</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($extra > 0): ?>
                    <span class="text-success"><i class="bi bi-arrow-up"></i> <?= sprintf('+%dh%02d', intdiv($extra, 60), $extra % 60) ?></span>
                  <?php else: ?>
                    <span class="text-muted">0h00</span>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap"><?= $r['check_in'] ? date('d/m/Y H:i', strtotime($r['check_in'])) : '' ?></td>
                <td class="text-nowrap"><?= $r['check_out'] ? date('d/m/Y H:i', strtotime($r['check_out'])) : '' ?></td>
                <td>
                  <?php if (!empty($r['photo'])): ?>
                    <a href="../photos/<?= esc($r['photo']) ?>" target="_blank" title="Ampliar foto">
                      <img src="../photos/<?= esc($r['photo']) ?>" alt="Foto" class="table-img shadow-sm">
                    </a>
                  <?php else: ?>
                    <span class="text-muted"><i class="bi bi-image"></i> Sem foto</span>
                  <?php endif; ?>
                </td>
                <td class="text-start">
                  <?php if ($r['location_in'] || $r['location_out']): ?>
                  <div class="d-flex flex-column gap-2">
                    <?php if ($r['location_in']): ?>
                    <div class="d-flex align-items-center justify-content-between gap-3 py-1 px-2 border rounded-2 bg-body-tertiary">
                      <div class="d-flex align-items-center gap-2">
                      <i class="bi bi-geo-alt-fill text-primary"></i>
                      <a href="https://maps.google.com/?q=<?= esc($r['location_in'][0]) ?>,<?= esc($r['location_in'][1]) ?>" target="_blank" class="text-decoration-none fw-semibold link-primary">
                        Entrada <i class="bi bi-box-arrow-up-right ms-1"></i>
                      </a>
                      </div>
                      <div class="text-muted small font-monospace">
                      <?= number_format($r['location_in'][0], 5, '.', '') ?>, <?= number_format($r['location_in'][1], 5, '.', '') ?>
                      </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($r['location_out']): ?>
                    <div class="d-flex align-items-center justify-content-between gap-3 py-1 px-2 border rounded-2">
                      <div class="d-flex align-items-center gap-2">
                      <i class="bi bi-geo-alt text-secondary"></i>
                      <a href="https://maps.google.com/?q=<?= esc($r['location_out'][0]) ?>,<?= esc($r['location_out'][1]) ?>" target="_blank" class="text-decoration-none fw-semibold link-secondary">
                        Saída <i class="bi bi-box-arrow-up-right ms-1"></i>
                      </a>
                      </div>
                      <div class="text-muted small font-monospace">
                      <?= number_format($r['location_out'][0], 5, '.', '') ?>, <?= number_format($r['location_out'][1], 5, '.', '') ?>
                      </div>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php else: ?>
                  <span class="text-muted">Não informado</span>
                  <?php endif; ?>
                </td>
                <td class="text-start"><?= $r['justificativa'] ? esc($r['justificativa']) : '-' ?></td>
                <td class="no-print">
                  <?php if ((int)$r['approved'] === 1): ?>
                    <span class="badge bg-success mb-1"><i class="bi bi-check-circle-fill"></i> Aprovado</span>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="reject_attendance_id" value="<?= esc($r['id']) ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger mt-1" onclick="return confirm('Tem certeza que deseja rejeitar este registro de ponto?');"><i class="bi bi-x-circle"></i> Rejeitar</button>
                    </form>
                  <?php else: ?>
                    <span class="badge bg-danger mb-1"><i class="bi bi-x-circle-fill"></i> Rejeitado</span>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="approve_attendance_id" value="<?= esc($r['id']) ?>">
                      <button type="submit" class="btn btn-sm btn-outline-success mt-1" onclick="return confirm('Deseja aprovar este registro de ponto?');"><i class="bi bi-check-circle"></i> Aprovar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Atalhos de datas
    (function() {
      const d1 = document.getElementById('date1');
      const d2 = document.getElementById('date2');
      function fmt(dt){ return dt.toISOString().slice(0,10); }
      function setRange(start, end){ if(d1 && d2){ d1.value = fmt(start); d2.value = fmt(end); } }
      document.querySelectorAll('[data-range]').forEach(btn => {
        btn.addEventListener('click', () => {
          const now = new Date();
          now.setHours(0,0,0,0);
          const range = btn.getAttribute('data-range');
          if (range === 'today') {
            setRange(new Date(now), new Date(now));
          } else if (range === 'week') {
            const day = now.getDay(); // 0 Sun..6 Sat
            const monday = new Date(now); monday.setDate(now.getDate() - ((day+6)%7));
            const sunday = new Date(monday); sunday.setDate(monday.getDate() + 6);
            setRange(monday, sunday);
          } else if (range === 'month') {
            const first = new Date(now.getFullYear(), now.getMonth(), 1);
            const last  = new Date(now.getFullYear(), now.getMonth()+1, 0);
            setRange(first, last);
          }
        });
      });
    })();
  </script>
</body>
</html>
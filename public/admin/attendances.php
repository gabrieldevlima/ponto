<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// Mensagens
$messages = [];
if (isset($_GET['msg'])) $messages[] = htmlspecialchars($_GET['msg']);

// Filtros
$where = [];
$params = [];
$schoolFilter = isset($_GET['school']) ? (int)$_GET['school'] : 0;

if (!empty($_GET['teacher'])) {
  $where[] = "a.teacher_id = ?";
  $params[] = (int)$_GET['teacher'];
}
if (!empty($_GET['date1'])) {
  $where[] = "a.date >= ?";
  $params[] = $_GET['date1'];
}
if (!empty($_GET['date2'])) {
  $where[] = "a.date <= ?";
  $params[] = $_GET['date2'];
}
if (isset($_GET['approved']) && $_GET['approved'] !== '') {
  if ($_GET['approved'] === 'null') {
    $where[] = "a.approved IS NULL";
  } else {
    $where[] = "a.approved = ?";
    $params[] = (int)$_GET['approved'];
  }
}

// Escopo
list($scopeSql, $scopeParams) = admin_scope_where('t');
$where[] = $scopeSql;

// Filtro escola (somente admin rede)
if ($schoolFilter > 0 && is_network_admin($admin)) {
  $where[] = "EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = a.teacher_id AND ts.school_id = ?)";
  $params[] = $schoolFilter;
}

// Limit
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$limit = max(10, min(1000, $limit));

// Query
$sql = "
  SELECT
    a.*,
    t.name,
    t.cpf,
    (DAYOFWEEK(a.date) - 1) AS weekday_idx,
    s.classes_count AS sch_classes_count,
    s.class_minutes AS sch_class_minutes,
    mr.name AS manual_reason_name,
    ed.username AS edited_by_username
  FROM attendance a
  JOIN teachers t ON a.teacher_id = t.id
  LEFT JOIN teacher_schedules s
    ON s.teacher_id = a.teacher_id
   AND s.weekday = (DAYOFWEEK(a.date) - 1)
  LEFT JOIN manual_reasons mr
    ON mr.id = a.manual_reason_id
  LEFT JOIN admins ed
    ON ed.id = a.editado_por
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY a.check_in DESC LIMIT {$limit}";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, $scopeParams));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Listas para filtros
$all_teachers = (function () use ($pdo, $scopeSql, $scopeParams) {
  $st = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE $scopeSql ORDER BY t.name");
  $st->execute($scopeParams);
  return $st->fetchAll(PDO::FETCH_ASSOC);
})();

$schools = [];
if (is_network_admin($admin)) {
  $schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$weekdays = [0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'];

$fmtMin = function (int $min): string {
  $sign = $min < 0 ? '-' : '';
  $min = abs($min);
  return $sign . sprintf('%dh%02d', intdiv($min, 60), $min % 60);
};

/**
 * Calcula minutos previstos para um professor em uma data específica.
 * - Se usar sistema de períodos: soma a duração de cada período atribuído para o weekday.
 * - Caso contrário: usa teacher_schedules (classes_count × class_minutes).
 */
function getExpectedMinutes(PDO $pdo, int $teacherId, string $date): int
{
  static $cache = [];
  $cacheKey = $teacherId . '|' . $date;
  if (isset($cache[$cacheKey])) return $cache[$cacheKey];

  // weekday: 0=Dom ... 6=Sáb (compatível com schema)
  $weekday = (int)date('w', strtotime($date));

  $expected = 0;
  if (teacher_uses_period_system($teacherId)) {
    // Busca períodos atribuídos ao professor neste weekday e soma duração
    $st = $pdo->prepare("
      SELECT cp.start_time, cp.end_time
      FROM teacher_class_assignments tca
      JOIN class_periods cp ON cp.id = tca.period_id AND cp.active = 1
      WHERE tca.teacher_id = ? AND tca.weekday = ?
    ");
    $st->execute([$teacherId, $weekday]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $s = DateTime::createFromFormat('H:i:s', $row['start_time']) ?: DateTime::createFromFormat('H:i', $row['start_time']);
      $e = DateTime::createFromFormat('H:i:s', $row['end_time'])   ?: DateTime::createFromFormat('H:i', $row['end_time']);
      if ($s && $e) {
        // Se fim <= início, considera跨-dia (raro, mas seguro)
        if ($e <= $s) $e = (clone $e)->modify('+1 day');
        $expected += max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60));
      }
    }
  } else {
    // Primeiro tenta rotina por aulas (teacher_schedules)
    $st = $pdo->prepare("SELECT classes_count, class_minutes FROM teacher_schedules WHERE teacher_id = ? AND weekday = ?");
    $st->execute([$teacherId, $weekday]);
    if ($sc = $st->fetch(PDO::FETCH_ASSOC)) {
      $expected = ((int)$sc['classes_count'] * (int)$sc['class_minutes']);
    }
    // Se não houver teacher_schedules, tenta rotina por horário (collaborator_time_schedules)
    if ($expected <= 0) {
      $st2 = $pdo->prepare("SELECT start_time, end_time, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
      $st2->execute([$teacherId, $weekday]);
      if ($ts = $st2->fetch(PDO::FETCH_ASSOC)) {
        $start = $ts['start_time'] ?? null;
        $end   = $ts['end_time'] ?? null;
        $break = (int)($ts['break_minutes'] ?? 0);
        if (!empty($start) && !empty($end)) {
          $s = DateTime::createFromFormat('H:i:s', $start) ?: DateTime::createFromFormat('H:i', $start);
          $e = DateTime::createFromFormat('H:i:s', $end)   ?: DateTime::createFromFormat('H:i', $end);
          if ($s && $e) {
            if ($e <= $s) $e = (clone $e)->modify('+1 day');
            $expected = max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60) - $break);
          }
        }
      }
    }
  }

  $cache[$cacheKey] = (int)$expected;
  return $cache[$cacheKey];
}

$resumo = [];
$relatorio = [];
foreach ($rows as $r) {
  $weekdayIdx = isset($r['weekday_idx']) ? (int)$r['weekday_idx'] : (int)date('w', strtotime($r['date']));
  $classes_count = (int)($r['sch_classes_count'] ?? 0);
  $class_minutes = (int)($r['sch_class_minutes'] ?? 0);
  // Calcula previsto de forma híbrida (períodos ou teacher_schedules)
  $total_esperado_min = getExpectedMinutes($pdo, (int)$r['teacher_id'], (string)$r['date']);

  $total_realizado_min = 0;
  if (!empty($r['check_in']) && !empty($r['check_out'])) {
    $inicio = new DateTime($r['check_in']);
    $fim = new DateTime($r['check_out']);
    if ($fim > $inicio) {
      $interval = $fim->getTimestamp() - $inicio->getTimestamp();
      $total_realizado_min = (int) floor($interval / 60);
    }
  }

  $saldo_min = $total_realizado_min - $total_esperado_min;

  $semana = date('o-W', strtotime($r['date']));
  $mes = date('Y-m', strtotime($r['date']));
  foreach ([['chave' => $semana, 'tipo' => 'semana'], ['chave' => $mes, 'tipo' => 'mes']] as $info) {
    $resumo[$info['tipo']][$info['chave']]['esperado'] = ($resumo[$info['tipo']][$info['chave']]['esperado'] ?? 0) + $total_esperado_min;
    $resumo[$info['tipo']][$info['chave']]['realizado'] = ($resumo[$info['tipo']][$info['chave']]['realizado'] ?? 0) + $total_realizado_min;
  }

  $location_in = (!empty($r['check_in_lat']) && !empty($r['check_in_lng'])) ? [floatval($r['check_in_lat']), floatval($r['check_in_lng'])] : null;
  $location_out = (!empty($r['check_out_lat']) && !empty($r['check_out_lng'])) ? [floatval($r['check_out_lat']), floatval($r['check_out_lng'])] : null;

  $r['weekday_label'] = $weekdays[$weekdayIdx] ?? '';
  $r['classes_count'] = $classes_count;
  $r['class_minutes'] = $class_minutes;
  $r['total_esperado_min'] = $total_esperado_min;
  $r['total_realizado_min'] = $total_realizado_min;
  $r['saldo_min'] = $saldo_min;
  $r['location_in'] = $location_in;
  $r['location_out'] = $location_out;

  $relatorio[] = $r;
}

// Exportação PDF com Dompdf
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
  $autoload = __DIR__ . '/../../vendor/autoload.php';
  if (file_exists($autoload)) {
    require_once $autoload;
    ob_start();
    include __DIR__ . '/_tpl_attendances_pdf.php';
    $html = ob_get_clean();
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('registros_ponto_' . date('Ymd_His') . '.pdf');
    exit;
  } else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Exportação PDF indisponível. Instale as dependências:\n- composer require dompdf/dompdf\nE tente novamente.";
    exit;
  }
}

// Helper para montar URL preservando filtros
function build_url_with(array $extra): string
{
  $q = $_GET;
  foreach ($extra as $k => $v) $q[$k] = $v;
  return 'attendances.php?' . http_build_query($q);
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Registros de Ponto | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <div class="container-fluid">

    <?php foreach ($messages as $msg): ?>
      <div class="alert alert-info"><?= $msg ?></div>
    <?php endforeach; ?>

    <div class="card rounded-3 border bg-body mb-4">
      <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
          <div class="bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width:3rem;height:3rem;">
            <i class="bi bi-calendar-check fs-4"></i>
          </div>
          <div>
            <h3 class="mb-0">Registros de Ponto</h3>
            <small class="text-muted"><?= number_format(count($rows)) ?> registros encontrados</small>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="<?= esc(build_url_with(['export' => 'pdf'])) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-filetype-pdf"></i> Exportar PDF
          </a>
          <a href="attendance_manual.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> Inserir Ponto Manual
          </a>
        </div>
      </div>
    </div>

    <form class="row row-cols-lg-auto g-3 align-items-end mb-4" method="get" autocomplete="off">
      <?php if (is_network_admin($admin)): ?>
        <div class="col">
          <label class="form-label">Escola</label>
          <select name="school" class="form-select">
            <option value="">Todas</option>
            <?php foreach ($schools as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $schoolFilter === (int)$s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
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
        <label class="form-label">Data Inicial</label>
        <input type="date" name="date1" class="form-control" value="<?= esc($_GET['date1'] ?? '') ?>">
      </div>
      <div class="col">
        <label class="form-label">Data Final</label>
        <input type="date" name="date2" class="form-control" value="<?= esc($_GET['date2'] ?? '') ?>">
      </div>
      <div class="col">
        <label class="form-label">Status</label>
        <select name="approved" class="form-select">
          <option value="" <?= !isset($_GET['approved']) || $_GET['approved'] === '' ? 'selected' : '' ?>>Todos</option>
          <option value="null" <?= (isset($_GET['approved']) && $_GET['approved'] === 'null') ? 'selected' : '' ?>>Pendente</option>
          <option value="1" <?= (isset($_GET['approved']) && $_GET['approved'] === '1') ? 'selected' : '' ?>>Aprovado</option>
          <option value="0" <?= (isset($_GET['approved']) && $_GET['approved'] === '0') ? 'selected' : '' ?>>Rejeitado</option>
        </select>
      </div>
      <div class="col">
        <label class="form-label">Limite</label>
        <select name="limit" class="form-select">
          <?php foreach ([50, 100, 200, 500, 1000] as $opt): ?>
            <option value="<?= $opt ?>" <?= (isset($_GET['limit']) ? (int)$_GET['limit'] : 200) === $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col d-flex gap-2">
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
        <a href="attendances.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar</a>
      </div>
    </form>

    <?php 
    // Verifica se o professor selecionado usa sistema de grade horária
    $teacherFilter = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 0;
    $selectedTeacherUsesPeriodSystem = false;
    if ($teacherFilter > 0) {
        $selectedTeacherUsesPeriodSystem = teacher_uses_period_system($teacherFilter);
    }
    ?>
    
    <?php if ($selectedTeacherUsesPeriodSystem): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        <strong>Sistema de Grade Horária:</strong>
        Este professor utiliza múltiplos check-ins por dia (um para cada aula/período). 
        Cada registro representa uma aula específica. Períodos ociosos entre aulas não são contabilizados no pagamento.
    </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table id="tbl-attendances" class="table table-bordered table-striped table-hover align-middle">
        <thead class="table-light">
          <tr class="text-center">
            <th>Colaborador(a)</th>
            <th>Data</th>
            <th>Entrada/Saída</th>
            <th>Carga Horária</th>
            <th>Comprovantes</th>
            <th>Status</th>
            <th>Origem/Justificativa</th>
            <th>Edição</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody class="text-center">
          <?php
          $fmtDateBR = function ($date) {
            if (empty($date) || $date === '0000-00-00') return '';
            $ts = strtotime($date);
            return $ts ? date('d/m/Y', $ts) : (string)$date;
          };
          $fmtTime = function ($dt) {
            if (empty($dt)) return '';
            $ts = strtotime($dt);
            return $ts ? date('H:i', $ts) : (string)$dt;
          };
          ?>
          <?php foreach ($relatorio as $r):
            $approved = $r['approved'];
            $saldo = (int)$r['saldo_min'];
            $saldoClass = $saldo > 0 ? 'text-success' : ($saldo < 0 ? 'text-danger' : 'text-muted');
            $workedMin = (int)$r['total_realizado_min'];
            $expectedMin = (int)$r['total_esperado_min'];
            $classesCount = (int)$r['classes_count'];
            $classMinutes = (int)$r['class_minutes'];
            $horaIn = $fmtTime($r['check_in']);
            $horaOut = $fmtTime($r['check_out']);

            // Método
            $methodRaw = $r['method'] ?? ($r['source'] ?? (isset($r['manual']) ? ($r['manual'] ? 'manual' : null) : null));
            $key = strtolower((string)$methodRaw);
            $mode = 'foto';
            if ($key === 'pin') $mode = 'pin';
            elseif ($key === 'manual' || (!empty($r['manual']) && (int)$r['manual'] === 1)) $mode = 'manual';
            elseif (!empty($r['photo'])) $mode = 'foto';
            if (!empty($r['manual_reason_id'])) $mode = 'manual';

            $labels = ['pin' => 'PIN', 'foto' => 'Foto', 'manual' => 'Manual'];
            $icons  = ['pin' => 'bi-123', 'foto' => 'bi-camera', 'manual' => 'bi-pencil-square'];
            $colors = ['pin' => 'primary', 'foto' => 'success', 'manual' => 'secondary'];
            $label = $labels[$mode] ?? ucfirst($mode);
            $icon  = $icons[$mode] ?? 'bi-info-circle';
            $color = $colors[$mode] ?? 'secondary';

            // Justificativa (mesma lógica existente)
            $justStr = '';
            if (!empty($r['manual_reason_id'])) {
              $reasonName = trim((string)($r['manual_reason_name'] ?? ''));
              $reasonText = trim((string)($r['manual_reason_text'] ?? ''));
              if ($reasonName !== '' || $reasonText !== '') {
                $justStr = ($reasonName !== '' ? $reasonName : 'Manual') . ($reasonText !== '' ? ' - ' . $reasonText : '');
              }
            }
            if ($justStr === '') {
              $infoArr = [];
              $rawInfo = $r['info'] ?? '';
              if (is_array($rawInfo)) $infoArr = $rawInfo;
              elseif (is_string($rawInfo) && $rawInfo !== '') {
                $tmp = json_decode($rawInfo, true);
                if (is_array($tmp)) {
                  if (count($tmp) === 1 && is_string(reset($tmp))) {
                    $tmp2 = json_decode(reset($tmp), true);
                    $infoArr = is_array($tmp2) ? $tmp2 : $tmp;
                  } else {
                    $infoArr = $tmp;
                  }
                }
              }
              $items = [];
              if (isset($infoArr['items']) && is_array($infoArr['items'])) $items = $infoArr['items'];
              elseif (is_array($infoArr) && isset($infoArr[0]) && is_array($infoArr[0])) $items = $infoArr;
              $parts = [];
              foreach (($items ?? []) as $it) {
                if (!empty($it['manual_reason_id'])) {
                  $txt = trim(($it['manual_reason_name'] ?? 'Manual') . (!empty($it['manual_reason_text']) ? ' - ' . $it['manual_reason_text'] : ''));
                  if ($txt !== '') $parts[] = $txt;
                }
              }
              if ($parts) $justStr = implode(' | ', $parts);
              if ($justStr !== '') $mode = 'manual';
            }
            if ($justStr === '' && $mode === 'manual') {
              $legacy = trim((string)(
                $r['manual_reason'] ??
                $r['justificativa'] ??
                $r['justification'] ??
                $r['reason'] ??
                $r['motivo'] ??
                $r['note'] ??
                $r['notes'] ??
                $r['obs'] ?? ''
              ));
              if ($legacy !== '') $justStr = $legacy;
            }

            // Edição
            $wasEdited = !empty($r['data_edicao']);
            $editedAt  = $wasEdited ? date('d/m/Y H:i', strtotime($r['data_edicao'])) : null;
            $editedBy  = $r['edited_by_username'] ?? (!empty($r['editado_por']) ? ('#' . (int)$r['editado_por']) : null);
            $editReason = $r['motivo_edicao'] ?? '';
            $editType  = $r['tipo_edicao'] ?? '';
            $editDelta = isset($r['edit_delta_min']) ? (int)$r['edit_delta_min'] : null;
          ?>
            <tr>
              <td class="text-start">
                <div class="fw-semibold"><?= esc($r['name']) ?></div>
              </td>
              <td class="text-nowrap">
                <?= esc($fmtDateBR($r['date'])) ?>
                <div class="text-muted small"><?= esc($r['weekday_label']) ?></div>
              </td>
              <td class="text-nowrap">
                <div class="d-flex flex-column align-items-center gap-1">
                  <div class="d-inline-flex align-items-center gap-2">
                    <?php if ($horaIn): ?>
                      <span class="badge rounded-pill text-bg-success" title="Entrada: <?= esc($r['check_in']) ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?= esc($horaIn) ?></span>
                    <?php else: ?>
                      <span class="badge rounded-pill text-bg-secondary" title="Entrada ausente"><i class="bi bi-box-arrow-in-right me-1"></i>—</span>
                    <?php endif; ?>
                    <span class="text-muted">–</span>
                    <?php if ($horaOut): ?>
                      <span class="badge rounded-pill text-bg-danger" title="Saída: <?= esc($r['check_out']) ?>"><i class="bi bi-box-arrow-left me-1"></i><?= esc($horaOut) ?></span>
                    <?php else: ?>
                      <span class="badge rounded-pill text-bg-secondary" title="Saída ausente"><i class="bi bi-box-arrow-left me-1"></i>—</span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="text-nowrap">
                <div>Trabalhada: <?= $fmtMin($workedMin) ?></div>
                <div class="text-muted small" title="Aulas: <?= (int)$classesCount ?> • Min/Aula: <?= (int)$classMinutes ?>">
                  Prevista: <?= $fmtMin($expectedMin) ?>
                </div>
                <?php if ($workedMin > 0): ?>
                  <?php
                  $expected = max(0, (int)$expectedMin);
                  $worked = max(0, (int)$workedMin);
                  $pct = $expected > 0 ? (int)round(($worked / $expected) * 100) : 100;
                  $delta = $worked - $expected;
                  $deltaStr = ($delta >= 0 ? '+' : '') . $fmtMin($delta);
                  $title = 'Trabalhado: ' . $fmtMin($worked) . ' • Previsto: ' . $fmtMin($expected) . ' • Saldo: ' . $deltaStr . ' • ' . $pct . '%';
                  ?>
                  <div class="w-100" title="<?= esc($title) ?>">
                    <div class="small">
                      <i class="bi bi-clock-history me-1"></i>
                      <span class="text-muted"><?= $pct ?>%</span>
                      <span class="<?= $saldoClass ?> ms-1">(<?= $deltaStr ?>)</span>
                    </div>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-nowrap">
                <?php
                $hasIn = !empty($r['location_in']);
                $hasOut = !empty($r['location_out']);
                $hasPhoto = !empty($r['photo']);
                ?>
                <div class="d-flex align-items-center justify-content-center gap-2 flex-wrap">
                  <?php if ($hasIn): ?>
                    <a class="d-inline-flex align-items-center justify-content-center rounded-circle border border-success-subtle bg-success-subtle text-success" style="width:2rem;height:2rem"
                      href="https://maps.google.com/?q=<?= esc($r['location_in'][0]) ?>,<?= esc($r['location_in'][1]) ?>" target="_blank" rel="noopener"
                      title="Entrada: <?= number_format($r['location_in'][0], 5, '.', '') ?>, <?= number_format($r['location_in'][1], 5, '.', '') ?>">
                      <i class="bi bi-geo-alt-fill"></i>
                    </a>
                  <?php endif; ?>
                  <?php if ($hasOut): ?>
                    <a class="d-inline-flex align-items-center justify-content-center rounded-circle border border-danger-subtle bg-danger-subtle text-danger" style="width:2rem;height:2rem"
                      href="https://maps.google.com/?q=<?= esc($r['location_out'][0]) ?>,<?= esc($r['location_out'][1]) ?>" target="_blank" rel="noopener"
                      title="Saída: <?= number_format($r['location_out'][0], 5, '.', '') ?>, <?= number_format($r['location_out'][1], 5, '.', '') ?>">
                      <i class="bi bi-geo-alt"></i>
                    </a>
                  <?php endif; ?>
                  <?php if ($hasPhoto): ?>
                    <?php if (empty($r['photo_deleted']) || $r['photo_deleted'] == 0): ?>
                      <a class="d-inline-block" href="../photos/<?= esc($r['photo']) ?>" target="_blank" rel="noopener" title="Ver foto" aria-label="Ver foto">
                        <img src="../photos/<?= esc($r['photo']) ?>" alt="Foto do registro" loading="lazy" class="border border-primary-subtle rounded" style="width:2rem;height:2rem;object-fit:cover;">
                      </a>
                    <?php else: ?>
                      <span class="badge bg-secondary" title="Foto excluída automaticamente em <?= isset($r['photo_deleted_at']) ? date('d/m/Y', strtotime($r['photo_deleted_at'])) : 'data desconhecida' ?>">
                        <i class="bi bi-image-fill"></i> Foto deletada
                      </span>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (!$hasIn && !$hasOut && !$hasPhoto): ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-nowrap">
                <?php if ($approved === null): ?>
                  <span class="badge rounded-pill border border-warning text-warning-emphasis bg-warning-subtle px-3 py-2" title="Aguardando análise"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                  <?php if (!empty($r['pending_reasons'])): ?>
                    <?php 
                    $reasons = json_decode($r['pending_reasons'], true);
                    if (is_array($reasons) && count($reasons) > 0):
                    ?>
                    <div class="small text-muted mt-1">
                      <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars(implode(', ', $reasons)) ?>
                    </div>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php elseif ((int)$approved === 1): ?>
                  <span class="badge rounded-pill border border-success text-success-emphasis bg-success-subtle px-3 py-2" title="Registro aprovado"><i class="bi bi-check-circle-fill me-1"></i>Aprovado</span>
                <?php else: ?>
                  <span class="badge rounded-pill border border-danger text-danger-emphasis bg-danger-subtle px-3 py-2" title="Registro rejeitado"><i class="bi bi-x-circle-fill me-1"></i>Rejeitado</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <span class="badge rounded-pill border border-<?= esc($color) ?> text-<?= esc($color) ?>-emphasis bg-<?= esc($color) ?>-subtle px-3 py-2" title="<?= esc($label) ?>">
                  <i class="bi <?= esc($icon) ?> me-1"></i><?= esc($label) ?>
                </span>
                <?php if ($mode === 'manual'): ?>
                  <div class="small text-muted mt-1" style="max-width:420px; white-space:normal;">
                    <i class="bi bi-chat-left-text me-1"></i>
                    <?= $justStr !== '' ? esc($justStr) : '<span class="text-muted">-</span>' ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-start">
                <?php if ($wasEdited): ?>
                  <div class="small">
                    <span class="badge text-bg-info-subtle text-dark" title="Editado"><i class="bi bi-pencil-square me-1"></i>Editado</span>
                  </div>
                  <div class="small text-muted mt-1">
                    <?php if ($editType): ?><div><strong>Tipo:</strong> <?= esc($editType) ?><?= $editDelta !== null ? ' • Δ ' . (int)$editDelta . ' min' : '' ?></div><?php endif; ?>
                    <div><strong>Por:</strong> <?= esc($editedBy ?? '-') ?></div>
                    <div><strong>Em:</strong> <?= esc($editedAt ?? '-') ?></div>
                    <div><strong>Motivo:</strong> <?= esc($editReason ?: '-') ?></div>
                  </div>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                  <a href="attendance_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil-square"></i> Editar
                  </a>
                  <?php if ($approved === null || (int)$approved === 0): ?>
                    <form method="post" action="attendances_action.php" onsubmit="return confirm('Aprovar este registro de ponto?')">
                      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                      <input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="act" value="approve">
                      <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check-circle"></i> Aprovar</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($approved === null || (int)$approved === 1): ?>
                    <form method="post" action="attendances_action.php" onsubmit="return confirm('Rejeitar este registro de ponto?')">
                      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                      <input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="act" value="reject">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Rejeitar</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="row mb-4">
      <div class="col-md-6">
        <div class="card shadow-sm mb-3">
          <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-calendar-week"></i> Resumo Semanal</h5>
          </div>
          <div class="card-body">
            <?php foreach (($resumo['semana'] ?? []) as $semana => $totais): ?>
              <?php $saldo = $totais['realizado'] - $totais['esperado']; ?>
              <p>
                <strong>Semana <?= esc($semana) ?></strong>:
                Esperado: <?= sprintf('%dh%02d', intdiv($totais['esperado'], 60), $totais['esperado'] % 60) ?> |
                Realizado: <?= sprintf('%dh%02d', intdiv($totais['realizado'], 60), $totais['realizado'] % 60) ?> |
                Saldo:
                <span class="<?= $saldo >= 0 ? 'text-success' : 'text-danger' ?>">
                  <?= ($saldo >= 0 ? '+' : '-') . sprintf('%dh%02d', intdiv(abs($saldo), 60), abs($saldo) % 60) ?>
                </span>
              </p>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card shadow-sm mb-3">
          <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-calendar-month"></i> Resumo Mensal</h5>
          </div>
          <div class="card-body">
            <?php foreach (($resumo['mes'] ?? []) as $mes => $totais): ?>
              <?php $saldo = $totais['realizado'] - $totais['esperado']; ?>
              <p>
                <strong>Mês <?= esc($mes) ?></strong>:
                Esperado: <?= sprintf('%dh%02d', intdiv($totais['esperado'], 60), $totais['esperado'] % 60) ?> |
                Realizado: <?= sprintf('%dh%02d', intdiv($totais['realizado'], 60), $totais['realizado'] % 60) ?> |
                Saldo:
                <span class="<?= $saldo >= 0 ? 'text-success' : 'text-danger' ?>">
                  <?= ($saldo >= 0 ? '+' : '-') . sprintf('%dh%02d', intdiv(abs($saldo), 60), abs($saldo) % 60) ?>
                </span>
              </p>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="text-center my-5">
      <a href="dashboard.php" class="btn btn-outline-primary rounded-pill px-4 py-2 shadow-sm">
        <i class="bi bi-arrow-left-circle me-2"></i> Voltar ao Painel
      </a>
    </div>
  </div>
</body>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</html>
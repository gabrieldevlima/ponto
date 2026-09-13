<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// Mensagens
$messages = [];
if (isset($_GET['msg'])) $messages[] = esc($_GET['msg']);

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

// Esconde registros marcados como duplicatas pelo admin (soft-delete).
// Só aparecem se o filtro explícito ?show_superseded=1 estiver presente.
$showSuperseded = isset($_GET['show_superseded']) && $_GET['show_superseded'] === '1';
if (!$showSuperseded) {
  $where[] = "a.superseded_by_id IS NULL";
}

// Escopo
list($scopeSql, $scopeParams) = admin_scope_where('t');
$where[] = $scopeSql;

// Filtro escola (somente admin rede)
if ($schoolFilter > 0 && is_network_admin($admin)) {
  $where[] = "EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = a.teacher_id AND ts.school_id = ?)";
  $params[] = $schoolFilter;
}

// Paginação — whitelist explícito (alinhado com as opções do <select>)
$perPageAllowed = [20, 50, 100];
$perPage = (isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageAllowed, true))
    ? (int)$_GET['per_page']
    : 50;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

$whereSql = $where ? (" WHERE " . implode(" AND ", $where)) : '';

// Total de registros
$countSql = "
  SELECT COUNT(*)
  FROM attendance a
  JOIN teachers t ON a.teacher_id = t.id
  $whereSql
";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute(array_merge($params, $scopeParams));
$totalAttendances = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalAttendances / $perPage));
if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

// Filtro opcional por categoria (work=trabalho, break=intervalo, all=ambos).
$kindFilter = isset($_GET['kind']) && in_array($_GET['kind'], ['work','break','all'], true) ? $_GET['kind'] : 'all';
if ($kindFilter === 'work') {
  $where[] = "(a.record_type = 'work' OR a.record_type IS NULL)";
} elseif ($kindFilter === 'break') {
  $where[] = "a.record_type = 'break'";
}
$whereSql = $where ? (" WHERE " . implode(" AND ", $where)) : '';

// Query paginada
$sql = "
  SELECT
    a.*,
    t.name,
    t.cpf,
    (DAYOFWEEK(a.date) - 1) AS weekday_idx,
    s.classes_count AS sch_classes_count,
    s.class_minutes AS sch_class_minutes,
    mr.name AS manual_reason_name,
    COALESCE(NULLIF(ed.name, ''), ed.username) AS edited_by_username,
    sch.name AS school_name,
    cp.period_number AS class_period_number,
    cp.start_time AS class_period_start,
    cp.end_time AS class_period_end,
    mba.username AS manual_by_admin_username
  FROM attendance a
  JOIN teachers t ON a.teacher_id = t.id
  LEFT JOIN teacher_schedules s
    ON s.teacher_id = a.teacher_id
   AND s.weekday = (DAYOFWEEK(a.date) - 1)
  LEFT JOIN manual_reasons mr
    ON mr.id = a.manual_reason_id
  LEFT JOIN admins ed
    ON ed.id = a.editado_por
  LEFT JOIN schools sch
    ON sch.id = a.school_id
  LEFT JOIN class_periods cp
    ON cp.id = a.class_period_id
  LEFT JOIN admins mba
    ON mba.id = a.manual_by_admin_id
" . $whereSql . " ORDER BY a.check_in DESC LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
$bindParams = array_merge($params, $scopeParams);
$paramIdx = 1;
foreach ($bindParams as $val) {
    $stmt->bindValue($paramIdx++, $val);
}
$stmt->bindValue($paramIdx++, (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIdx, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Expansão por dia: a query paginada (LIMIT N OFFSET M) pode trazer apenas PARTE
// dos registros de um dia (ex: a entrada inicial das 05:00 fica numa página, e o
// intervalo + retorno + saída da tarde caem em outra). Para que consolidate_attendance_by_day()
// veja o dia COMPLETO e retorne entrada/saída/intervalos corretos, expandimos cada
// par (date, teacher_id) da página atual buscando TODOS os registros desses dias.
// Bug reproduzido: registro do Pablo (26/05) mostrava "Entrada 18:09" porque o work
// das 05:00 estava fora da página — sem ele, firstWork era o work pós-intervalo.
if (!empty($rows)) {
    $dayPairs = [];
    foreach ($rows as $rrow) {
        $key = $rrow['date'] . '_' . $rrow['teacher_id'];
        $dayPairs[$key] = ['date' => $rrow['date'], 'teacher_id' => (int)$rrow['teacher_id']];
    }
    $expandPlaceholders = [];
    $expandParams = [];
    foreach ($dayPairs as $pair) {
        $expandPlaceholders[] = '(a.date = ? AND a.teacher_id = ?)';
        $expandParams[] = $pair['date'];
        $expandParams[] = $pair['teacher_id'];
    }
    $expandWhere = '(' . implode(' OR ', $expandPlaceholders) . ')';
    // Mantém regra de superseded da query principal (não mostra duplicatas resolvidas
    // pelo admin), mas remove filtros que cortam registros do mesmo dia (kind, approved).
    $supersededFilter = $showSuperseded ? '' : ' AND a.superseded_by_id IS NULL';
    $expandSql = "
      SELECT
        a.*,
        t.name,
        t.cpf,
        (DAYOFWEEK(a.date) - 1) AS weekday_idx,
        s.classes_count AS sch_classes_count,
        s.class_minutes AS sch_class_minutes,
        mr.name AS manual_reason_name,
        COALESCE(NULLIF(ed.name, ''), ed.username) AS edited_by_username,
        sch.name AS school_name,
        cp.period_number AS class_period_number,
        cp.start_time AS class_period_start,
        cp.end_time AS class_period_end,
        mba.username AS manual_by_admin_username
      FROM attendance a
      JOIN teachers t ON a.teacher_id = t.id
      LEFT JOIN teacher_schedules s ON s.teacher_id = a.teacher_id AND s.weekday = (DAYOFWEEK(a.date) - 1)
      LEFT JOIN manual_reasons mr ON mr.id = a.manual_reason_id
      LEFT JOIN admins ed ON ed.id = a.editado_por
      LEFT JOIN schools sch ON sch.id = a.school_id
      LEFT JOIN class_periods cp ON cp.id = a.class_period_id
      LEFT JOIN admins mba ON mba.id = a.manual_by_admin_id
      WHERE $expandWhere $supersededFilter
      ORDER BY a.date DESC, a.teacher_id, a.check_in ASC
    ";
    $stmtExp = $pdo->prepare($expandSql);
    $stmtExp->execute($expandParams);
    $rows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);
}

// URL base para paginação (preserva filtros)
$attendancesBaseUrl = 'attendances.php?' . http_build_query(array_diff_key($_GET, ['page' => 1]));
if (substr($attendancesBaseUrl, -1) === '?') {
  $attendancesBaseUrl = rtrim($attendancesBaseUrl, '?');
}

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

// Formata data/hora completa (com segundos quando disponível)
$fmtDateTime = function ($dt): string {
  if (empty($dt) || $dt === '0000-00-00 00:00:00') return '—';
  $ts = strtotime((string)$dt);
  return $ts ? date('d/m/Y H:i:s', $ts) : (string)$dt;
};

// Resume um User-Agent em browser+OS amigáveis (quando possível)
$fmtUserAgent = function (?string $ua): string {
  if (empty($ua)) return '—';
  $ua = trim($ua);
  if (strlen($ua) > 110) $ua = substr($ua, 0, 107) . '…';
  return $ua;
};

// Mostra IP em formato curto + rótulo amigável quando loopback/privado.
// Loopback (::1, 127.x) → "localhost"; faixas RFC1918 → marca como "rede interna".
$fmtIp = function (?string $ip): array {
  if (empty($ip)) return ['—', null];
  $ip = trim($ip);
  $display = strlen($ip) > 45 ? substr($ip, 0, 42) . '…' : $ip;
  $tag = null;
  if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1' || strpos($ip, '127.') === 0) {
    $tag = 'localhost';
  } elseif (preg_match('/^10\./', $ip)
         || preg_match('/^192\.168\./', $ip)
         || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)
         || stripos($ip, 'fc') === 0 || stripos($ip, 'fd') === 0 /* IPv6 ULA */) {
    $tag = 'rede interna';
  }
  return [$display, $tag];
};

// Resume um delay offline em ler-amigável (segundos → "2d 3h 14m")
$fmtOfflineDelay = function ($seconds): string {
  $s = (int)$seconds;
  if ($s <= 0) return 'instantâneo';
  if ($s < 60) return $s . 's';
  $m = intdiv($s, 60);
  if ($m < 60) return $m . 'm ' . ($s % 60) . 's';
  $h = intdiv($m, 60);
  if ($h < 24) return $h . 'h ' . ($m % 60) . 'm';
  $d = intdiv($h, 24);
  return $d . 'd ' . ($h % 24) . 'h';
};

// Map fraud_risk_level (0-3) → label + cor
$fmtFraudLevel = function ($level): array {
  $lvl = (int)$level;
  $map = [
    0 => ['Baixo',  'success', 'shield-check'],
    1 => ['Médio',  'warning', 'shield-exclamation'],
    2 => ['Alto',   'danger',  'shield-fill-exclamation'],
    3 => ['Crítico','danger',  'shield-fill-x'],
  ];
  return $map[$lvl] ?? ['Desconhecido', 'secondary', 'shield'];
};

// Map hlb_sync_status → label + cor
$fmtHlbStatus = function ($status): array {
  $map = [
    'synced'  => ['Sincronizado', 'success', 'cloud-check'],
    'failed'  => ['Falha',        'danger',  'cloud-slash'],
    'pending' => ['Pendente',     'warning', 'cloud-arrow-up'],
    'legacy'  => ['Legado',       'secondary','clock-history'],
  ];
  return $map[strtolower((string)$status)] ?? ['—', 'secondary', 'cloud'];
};

// CSS-clamp para textos potencialmente longos (UA, fingerprint)
$truncMid = function (string $s, int $max = 24): string {
  if (strlen($s) <= $max) return $s;
  $half = (int)floor(($max - 1) / 2);
  return substr($s, 0, $half) . '…' . substr($s, -$half);
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
      $st2 = $pdo->prepare("SELECT start_time, end_time, end_next_day, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
      $st2->execute([$teacherId, $weekday]);
      if ($ts = $st2->fetch(PDO::FETCH_ASSOC)) {
        $win = compute_schedule_window($ts, $date);
        if ($win) {
          // Janela completa — break_minutes não é descontado (intervalo conta
          // como tempo trabalhado; modelo "cheio vs cheio").
          $expected = max(0, (int)(($win['end']->getTimestamp() - $win['start']->getTimestamp()) / 60));
        }
      }
    }
  }

  $cache[$cacheKey] = (int)$expected;
  return $cache[$cacheKey];
}

$resumo = [];
$relatorio = [];
// Janela de contagem: dias antes do início da contagem (config global) não geram
// previsto/saldo (evita saldo negativo em dias que o sistema ainda não contava).
// Punches no futuro são impossíveis; o limite superior fica por simetria.
$countingStart = counting_start_date();
$countingToday = date('Y-m-d');
// Controle de "esperado por dia" para evitar dupla contagem: várias linhas de
// attendance no mesmo dia (turno manhã + tarde) compartilham UM esperado.
// Chave: tipo|chave_agrupamento|data → marca que já somamos o esperado.
$expectedCounted = [];
foreach ($rows as $r) {
  $weekdayIdx = isset($r['weekday_idx']) ? (int)$r['weekday_idx'] : (int)date('w', strtotime($r['date']));
  $classes_count = (int)($r['sch_classes_count'] ?? 0);
  $class_minutes = (int)($r['sch_class_minutes'] ?? 0);
  // Calcula previsto de forma híbrida (períodos ou teacher_schedules)
  $total_esperado_min = getExpectedMinutes($pdo, (int)$r['teacher_id'], (string)$r['date']);
  // Fora da janela de contagem: zera o previsto (sem saldo negativo nem soma nos resumos).
  if ($countingStart !== null && ((string)$r['date'] < $countingStart || (string)$r['date'] > $countingToday)) {
    $total_esperado_min = 0;
  }

  $rowMinutes = 0;
  if (!empty($r['check_in']) && !empty($r['check_out'])) {
    $inicio = new DateTime($r['check_in']);
    $fim = new DateTime($r['check_out']);
    if ($fim > $inicio) {
      $interval = $fim->getTimestamp() - $inicio->getTimestamp();
      $rowMinutes = (int) floor($interval / 60);
    }
  }
  // Modelo "cheio vs cheio": intervalo registrado CONTA como tempo trabalhado
  // (direito do colaborador) — entra no realizado e também aparece como
  // informação separada em "intervalo".
  $isBreak = (($r['record_type'] ?? 'work') === 'break');
  $total_realizado_min = $rowMinutes;
  $total_break_min     = $isBreak ? $rowMinutes : 0;

  $saldo_min = $total_realizado_min - $total_esperado_min;

  $semana = date('o-W', strtotime($r['date']));
  $mes = date('Y-m', strtotime($r['date']));
  $dataKey = $r['date'] . '|' . (int)$r['teacher_id'];
  foreach ([['chave' => $semana, 'tipo' => 'semana'], ['chave' => $mes, 'tipo' => 'mes']] as $info) {
    // Realizado e break: sempre soma (cada par contribui).
    $resumo[$info['tipo']][$info['chave']]['realizado'] = ($resumo[$info['tipo']][$info['chave']]['realizado'] ?? 0) + $total_realizado_min;
    $resumo[$info['tipo']][$info['chave']]['intervalo'] = ($resumo[$info['tipo']][$info['chave']]['intervalo'] ?? 0) + $total_break_min;
    // Esperado: soma APENAS uma vez por (tipo, chave, dia, colaborador) — evita
    // multiplicar a jornada quando há múltiplas batidas no mesmo dia.
    $expKey = $info['tipo'] . '|' . $info['chave'] . '|' . $dataKey;
    if (!isset($expectedCounted[$expKey])) {
      $resumo[$info['tipo']][$info['chave']]['esperado'] = ($resumo[$info['tipo']][$info['chave']]['esperado'] ?? 0) + $total_esperado_min;
      $expectedCounted[$expKey] = true;
    }
  }

  $location_in = (!empty($r['check_in_lat']) && !empty($r['check_in_lng'])) ? [floatval($r['check_in_lat']), floatval($r['check_in_lng'])] : null;
  $location_out = (!empty($r['check_out_lat']) && !empty($r['check_out_lng'])) ? [floatval($r['check_out_lat']), floatval($r['check_out_lng'])] : null;

  $r['weekday_label'] = $weekdays[$weekdayIdx] ?? '';
  $r['classes_count'] = $classes_count;
  $r['class_minutes'] = $class_minutes;
  $r['total_esperado_min'] = $total_esperado_min;
  $r['total_realizado_min'] = $total_realizado_min;
  $r['total_break_min'] = $total_break_min;
  $r['saldo_min'] = $saldo_min;
  $r['location_in'] = $location_in;
  $r['location_out'] = $location_out;
  $r['record_type'] = $r['record_type'] ?? 'work';
  // teacher_name e date precisam estar como chaves "puras" para consolidate_attendance_by_day
  $r['teacher_name'] = $r['name'] ?? null;

  $relatorio[] = $r;
}

// Consolida registros por dia/colaborador: cada dia vira 1 linha com sub-detalhes
// de intervalos. O bug que se quer resolver é que múltiplos intervalos no mesmo
// dia apareciam como linhas independentes.
$consolidatedDays = consolidate_attendance_by_day($relatorio);

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
  <link rel="stylesheet" href="css/admin.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <style>
    /* ===== Modal de detalhes do registro de ponto ===== */
    .adm-att-modal .modal-body { padding: 1.5rem 1.75rem; }
    .adm-att-section {
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: #475569;
      border-bottom: 1px solid #e2e8f0;
      padding-bottom: 0.4rem;
      margin-bottom: 0.75rem;
      display: flex; align-items: center; gap: 0.4rem;
    }
    .adm-att-section i { color: #0162cc; }

    /* Pills de identificação técnica no topo */
    .adm-att-pills { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .adm-pill {
      display: inline-flex; align-items: center; gap: 0.35rem;
      background: #f1f5f9; color: #334155;
      border: 1px solid #e2e8f0; border-radius: 999px;
      padding: 0.25rem 0.65rem; font-size: 0.78rem; font-weight: 500;
      line-height: 1.2;
    }
    .adm-pill i { color: #0162cc; font-size: 0.85rem; }
    .adm-pill-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .adm-pill-warn { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
    .adm-pill-warn i { color: #b45309; }

    /* Linha de horários entrada/saída em destaque */
    .adm-att-times {
      display: flex; align-items: center; gap: 1rem;
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
      padding: 0.85rem 1rem;
    }
    .adm-att-times > div { display: flex; flex-direction: column; flex: 1; }
    .adm-time-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 600; }
    .adm-time-val   { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
    .adm-time-sub   { font-size: 0.72rem; color: #94a3b8; font-variant-numeric: tabular-nums; }
    .adm-time-arrow { font-size: 1.2rem; color: #cbd5e1; flex: 0 0 auto !important; }

    /* Definition list em layout grade */
    .adm-kv {
      display: grid;
      grid-template-columns: minmax(110px, max-content) 1fr;
      gap: 0.35rem 1rem;
      margin: 0;
      align-items: baseline;
    }
    .adm-kv dt {
      font-size: 0.78rem;
      color: #64748b;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .adm-kv dd {
      margin: 0;
      font-size: 0.92rem;
      color: #0f172a;
      word-break: break-word;
    }
    .adm-kv-cols {
      grid-template-columns: minmax(80px, max-content) 1fr minmax(80px, max-content) 1fr;
    }
    .adm-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

    /* Foto thumbnail clicável */
    .adm-photo-link {
      position: relative;
      display: inline-block;
      flex: 0 0 auto;
      transition: transform .15s ease;
    }
    .adm-photo-link:hover { transform: scale(1.04); }
    .adm-photo-thumb {
      width: 110px; height: 110px;
      object-fit: cover;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(15, 23, 42, .08);
    }
    .adm-photo-zoom {
      position: absolute; right: 4px; bottom: 4px;
      width: 26px; height: 26px;
      display: inline-flex; align-items: center; justify-content: center;
      background: rgba(15, 23, 42, .75); color: #fff; font-size: .8rem;
      border-radius: 8px;
    }

    @media (max-width: 575.98px) {
      .adm-att-modal .modal-body { padding: 1rem; }
      .adm-att-times { gap: 0.4rem; padding: 0.6rem 0.7rem; }
      .adm-time-val { font-size: 1.15rem; }
      .adm-time-arrow { display: none; }
      .adm-kv { grid-template-columns: 1fr; gap: 0.15rem; }
      .adm-kv dt { margin-top: 0.4rem; }
      .adm-kv-cols { grid-template-columns: 1fr; }
      .adm-photo-thumb { width: 80px; height: 80px; }
    }
  </style>
  <div class="container-fluid admin-content">

    <?php foreach ($messages as $msg): ?>
      <div class="alert alert-info"><?= $msg ?></div>
    <?php endforeach; ?>

    <div class="app-page-header">
      <div class="app-page-header__main">
        <div class="app-page-icon"><i class="bi bi-calendar-check"></i></div>
        <div>
          <h1 class="app-page-title">Registros de Ponto</h1>
          <p class="app-page-subtitle"><?= number_format($totalAttendances) ?> registro(s) encontrado(s)</p>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="<?= esc(build_url_with(['export' => 'pdf'])) ?>" class="btn btn-outline-secondary">
          <i class="bi bi-filetype-pdf me-1"></i><span class="d-none d-md-inline">Exportar </span>PDF
        </a>
        <a href="attendance_manual.php" class="btn btn-success" aria-label="Inserir ponto manual">
          <i class="bi bi-plus-circle me-1"></i>
          <span class="d-none d-md-inline">Inserir Ponto Manual</span>
          <span class="d-inline d-md-none">Manual</span>
        </a>
      </div>
    </div>

    <form class="admin-filters row row-cols-lg-auto g-3 align-items-end mb-4" method="get" autocomplete="off">
      <?php if (is_network_admin($admin)): ?>
        <div class="col">
          <label class="form-label">Instituição</label>
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
        <label class="form-label">Por página</label>
        <select name="per_page" class="form-select">
          <?php foreach ([20, 50, 100] as $opt): ?>
            <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col">
        <label class="form-label">&nbsp;</label>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="show_superseded" value="1" id="filterShowSuperseded"
                 <?= $showSuperseded ? 'checked' : '' ?>>
          <label class="form-check-label small" for="filterShowSuperseded" title="Inclui registros que o admin marcou como duplicata">
            Mostrar duplicatas
          </label>
        </div>
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

    <section class="app-section-card app-table-card">
      <header class="app-section-card__header">
        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-list-check"></i>Lista</span>
        <h2 class="app-section-card__title">Registros de ponto</h2>
        <span class="app-section-card__hint"><?= number_format($totalAttendances) ?> resultado(s)</span>
      </header>
      <div class="table-responsive">
      <table id="tbl-attendances" class="table align-middle mb-0">
        <thead>
          <tr class="text-center">
            <th scope="col">Colaborador(a)</th>
            <th scope="col">Data</th>
            <th scope="col">Entrada/Saída</th>
            <th scope="col">Intervalos</th>
            <th scope="col">Status</th>
            <th scope="col" style="min-width: 80px;">Ações</th>
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
          <?php foreach ($consolidatedDays as $day):
            // Escolhe um registro "representativo" do dia para conteúdo das células
            // que dependem dos campos crus (status, modal, anti-fraude, etc.).
            // Preferimos o PRIMEIRO work CRONOLOGICAMENTE (menor check_in); se não
            // houver work (orphan), pegamos o primeiro registro qualquer.
            // Atenção: a query SQL ordena por check_in DESC, então rawRows[0] é o
            // mais recente — ordenar localmente em ASC garante que o representativo
            // seja o início do turno (ex: 08:00, não 14:00 do retorno do intervalo).
            $rawRows = $day['raw_rows'] ?? [];
            $sortedRows = $rawRows;
            usort($sortedRows, function($a, $b) {
                return strcmp((string)($a['check_in'] ?? ''), (string)($b['check_in'] ?? ''));
            });
            $r = null;
            foreach ($sortedRows as $rr) {
                if (($rr['record_type'] ?? 'work') === 'work') { $r = $rr; break; }
            }
            if ($r === null && !empty($sortedRows)) $r = $sortedRows[0];
            if ($r === null) continue;

            $approved = $r['approved'];
            $saldo = (int)$r['saldo_min'];
            $saldoClass = $saldo > 0 ? 'text-success' : ($saldo < 0 ? 'text-danger' : 'text-muted');
            $workedMin = (int)$r['total_realizado_min'];
            $expectedMin = (int)$r['total_esperado_min'];
            $classesCount = (int)$r['classes_count'];
            $classMinutes = (int)$r['class_minutes'];
            // Entrada/saída vêm do DIA consolidado (não do registro individual),
            // para refletir o turno completo mesmo quando há múltiplos works
            $horaIn = $day['check_in'] ? substr($day['check_in'], 0, 5) : '';
            $horaOut = $day['check_out'] ? substr($day['check_out'], 0, 5) : '';
            $breakCount = count($day['breaks']);
            $totalBreakMinDay = (int)$day['total_break_minutes'];
            $dayCollapseId = 'breaks-day-' . (int)$day['teacher_id'] . '-' . str_replace('-', '', $day['date']);

            // Método
            $methodRaw = $r['method'] ?? ($r['source'] ?? (isset($r['manual']) ? ($r['manual'] ? 'manual' : null) : null));
            $key = strtolower((string)$methodRaw);
            $mode = 'foto';
            if ($key === 'cpf' || $key === 'pin') $mode = 'cpf';
            elseif ($key === 'face') $mode = 'face';
            elseif ($key === 'manual' || (!empty($r['manual']) && (int)$r['manual'] === 1)) $mode = 'manual';
            elseif (!empty($r['photo'])) $mode = 'foto';
            if (!empty($r['manual_reason_id'])) $mode = 'manual';

            $labels = ['cpf' => 'CPF', 'pin' => 'CPF', 'foto' => 'Foto', 'face' => 'Rec. Facial', 'manual' => 'Manual'];
            $icons  = ['cpf' => 'bi-person-vcard', 'pin' => 'bi-person-vcard', 'foto' => 'bi-camera', 'face' => 'bi-person-bounding-box', 'manual' => 'bi-pencil-square'];
            $colors = ['cpf' => 'primary', 'pin' => 'primary', 'foto' => 'success', 'face' => 'info', 'manual' => 'secondary'];
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
                <div class="fw-semibold">
                  <?= esc(mb_convert_case($r['name'] ?? '', MB_CASE_TITLE, 'UTF-8')) ?>
                  <?php if (($r['record_type'] ?? 'work') === 'break'): ?>
                    <span class="badge bg-secondary ms-1" title="Intervalo (não conta como trabalho)"><i class="bi bi-pause-circle"></i></span>
                  <?php endif; ?>
                  <?php if ($wasEdited): ?>
                    <span class="text-warning ms-1" title="Editado em <?= esc($editedAt ?? '') ?> por <?= esc($editedBy ?? '') ?> · Motivo: <?= esc($editReason ?: '—') ?>"><i class="bi bi-pencil-square"></i></span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-nowrap">
                <?= esc($fmtDateBR($r['date'])) ?>
                <div class="text-muted small"><?= esc($r['weekday_label']) ?></div>
              </td>
              <td class="text-nowrap">
                <div class="d-flex flex-column align-items-center gap-1">
                  <div class="d-inline-flex align-items-center gap-2">
                    <?php if ($horaIn): ?>
                      <span class="badge rounded-pill text-bg-success" title="Entrada do dia: <?= esc($day['check_in']) ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?= esc($horaIn) ?></span>
                    <?php else: ?>
                      <span class="badge rounded-pill text-bg-secondary" title="Entrada ausente"><i class="bi bi-box-arrow-in-right me-1"></i>—</span>
                    <?php endif; ?>
                    <span class="text-muted">–</span>
                    <?php if ($horaOut): ?>
                      <span class="badge rounded-pill text-bg-danger" title="Saída do dia: <?= esc($day['check_out']) ?>"><i class="bi bi-box-arrow-left me-1"></i><?= esc($horaOut) ?></span>
                    <?php else: ?>
                      <span class="badge rounded-pill text-bg-secondary" title="Saída ausente"><i class="bi bi-box-arrow-left me-1"></i>—</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($day['status'] === 'in_progress'): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning small" title="Dia em andamento"><i class="bi bi-hourglass-split me-1"></i>Em andamento</span>
                  <?php elseif ($day['status'] === 'orphan'): ?>
                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger small" title="Intervalos sem registro de jornada — verifique manualmente"><i class="bi bi-exclamation-triangle me-1"></i>Sem jornada</span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-center align-middle">
                <?php if ($breakCount === 0): ?>
                  <span class="text-muted small">—</span>
                <?php else: ?>
                  <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none break-toggle" data-target="#<?= esc($dayCollapseId) ?>" aria-expanded="false">
                    <span class="badge text-bg-info-subtle border border-info text-info-emphasis">
                      <i class="bi bi-pause-circle me-1"></i><?= (int)$breakCount ?> <?= $breakCount === 1 ? 'intervalo' : 'intervalos' ?>
                      <span class="text-muted ms-1">(<?= esc(format_duration_minutes($totalBreakMinDay)) ?>)</span>
                    </span>
                    <i class="bi bi-chevron-right break-toggle-icon ms-1"></i>
                  </button>
                <?php endif; ?>
              </td>
              <td class="text-nowrap">
                <?php if ($approved === null): ?>
                  <span class="badge rounded-pill border border-warning text-warning-emphasis bg-warning-subtle px-3 py-2" title="Aguardando análise"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                  <?php
                    $humanReasons = humanize_pending_reasons($r['pending_reasons'] ?? null);
                    if (!empty($humanReasons)):
                  ?>
                    <div class="small text-muted mt-1">
                      <?php foreach ($humanReasons as $hr): ?>
                        <div><i class="bi bi-exclamation-circle me-1"></i><?= esc($hr) ?></div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                <?php elseif ((int)$approved === 1): ?>
                  <span class="badge rounded-pill border border-success text-success-emphasis bg-success-subtle px-3 py-2" title="Registro aprovado"><i class="bi bi-check-circle-fill me-1"></i>Aprovado</span>
                <?php else: ?>
                  <span class="badge rounded-pill border border-danger text-danger-emphasis bg-danger-subtle px-3 py-2" title="Registro rejeitado"><i class="bi bi-x-circle-fill me-1"></i>Rejeitado</span>
                <?php endif; ?>
                <?php
                  // Três estados distintos que antes se confundiam em dois rótulos.
                  // `superseded_by_id` apontando para OUTRO registro com
                  // `removed_at` preenchido significa SUBSTITUÍDO por uma edição
                  // (Fase 3) — não é remoção nem duplicata, e o admin precisa
                  // conseguir chegar ao registro que passou a valer.
                  $supBy      = !empty($r['superseded_by_id']) ? (int)$r['superseded_by_id'] : 0;
                  $foiRemovido    = !empty($r['removed_at']) && ($supBy === 0 || $supBy === (int)$r['id']);
                  $foiSubstituido = !empty($r['removed_at']) && $supBy > 0 && $supBy !== (int)$r['id'];
                  $ehDuplicata    = empty($r['removed_at']) && $supBy > 0;
                ?>
                <?php if ($foiRemovido): ?>
                  <div class="small mt-1">
                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger" title="<?= esc('Removido pelo admin. Motivo: ' . (string)($r['removed_reason'] ?? '')) ?>">
                      <i class="bi bi-trash3 me-1"></i>Removido pelo admin
                    </span>
                  </div>
                <?php elseif ($foiSubstituido): ?>
                  <div class="small mt-1">
                    <span class="badge bg-info-subtle text-info-emphasis border border-info" title="<?= esc('Substituído por uma edição. Motivo: ' . (string)($r['removed_reason'] ?? '')) ?>">
                      <i class="bi bi-arrow-repeat me-1"></i>Substituído por #<?= $supBy ?>
                    </span>
                  </div>
                <?php elseif ($ehDuplicata): ?>
                  <div class="small mt-1">
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning" title="Marcado como duplicata pelo admin">
                      <i class="bi bi-files me-1"></i>Duplicata de #<?= $supBy ?>
                    </span>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-center align-middle">
                <!-- Ações Dropdown -->
                <div class="dropdown">
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Ações">
                    <i class="bi bi-three-dots-vertical"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li>
                      <button type="button" class="dropdown-item text-info" data-bs-toggle="modal" data-bs-target="#modalAttendance<?= $r['id'] ?>">
                        <i class="bi bi-eye"></i> Detalhes
                      </button>
                    </li>
                    <li>
                      <a class="dropdown-item text-primary" href="attendance_edit.php?id=<?= (int)$r['id'] ?>">
                        <i class="bi bi-pencil-square"></i> Editar dia
                      </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <?php if ($approved === null || (int)$approved === 0): ?>
                    <form method="post" action="attendances_action.php" onsubmit="return confirm('Aprovar este registro de ponto?')">
                      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                      <input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="act" value="approve">
                      <button type="submit" class="dropdown-item text-success"><i class="bi bi-check-circle"></i> Aprovar</button>
                    </form>
                  <?php endif; ?>
                    </li>
                    <li>
                      <?php if ($approved === null || (int)$approved === 1): ?>
                    <form method="post" action="attendances_action.php" onsubmit="return confirm('Rejeitar este registro de ponto?')">
                      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                      <input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="act" value="reject">
                      <button type="submit" class="dropdown-item text-danger"><i class="bi bi-x-circle"></i> Rejeitar</button>
                    </form>
                  <?php endif; ?>
                    </li>
                    <?php if (is_network_admin()): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <?php if (empty($r['removed_at'])): ?>
                      <form method="post" action="attendances_action.php" onsubmit="var m=prompt('Remover este registro? Ele sai da folha e dos relatórios, mas fica auditável e pode ser restaurado.\n\nMotivo (obrigatório):'); if(!m||!m.trim())return false; this.reason.value=m.trim(); return true;">
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="act" value="remove">
                        <input type="hidden" name="reason" value="">
                        <button type="submit" class="dropdown-item text-danger fw-semibold"><i class="bi bi-trash3"></i> Remover</button>
                      </form>
                      <?php else: ?>
                      <form method="post" action="attendances_action.php" onsubmit="return confirm('Restaurar este registro removido? Ele volta como pendente.')">
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="attendance_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="act" value="restore">
                        <input type="hidden" name="reason" value="restauração">
                        <button type="submit" class="dropdown-item text-secondary"><i class="bi bi-arrow-counterclockwise"></i> Restaurar</button>
                      </form>
                      <?php endif; ?>
                    </li>
                    <?php endif; ?>
                  </ul>
                </div>

                <!-- Detalhes Modal (Placed outside dropdown to prevent clipping) -->
                <?php
                  // ===== Pré-processamento de campos para o modal =====
                  $fraudInfo = $fmtFraudLevel($r['fraud_risk_level'] ?? 0);
                  $hlbInfo   = $fmtHlbStatus($r['hlb_sync_status'] ?? null);
                  $livenessScore = isset($r['liveness_score']) && $r['liveness_score'] !== null ? (float)$r['liveness_score'] : null;
                  $gpsMock   = !empty($r['gps_mock_detected']);
                  $isOffline = strtolower((string)($r['record_mode'] ?? 'online')) === 'offline';
                  $offlineDelaySec = isset($r['offline_delay_seconds']) ? (int)$r['offline_delay_seconds'] : 0;
                  $clientRecordedAt = $r['client_recorded_at'] ?? null;
                  $serverRecordedAt = $r['recorded_at'] ?? null;
                  $syncedAt = $r['synced_at'] ?? null;
                  $hlbOffset = isset($r['hlb_offset_seconds']) ? (int)$r['hlb_offset_seconds'] : 0;
                  $nsr = $r['nsr'] ?? null;
                  $clientId = $r['client_id'] ?? null;
                  $sequenceNumber = (int)($r['sequence_number'] ?? 1);
                  $deviceFingerprint = $r['device_fingerprint'] ?? null;
                  $deviceIdentifier = $r['device_identifier'] ?? null;
                  $schoolName = $r['school_name'] ?? null;
                  $classPeriodNumber = $r['class_period_number'] ?? null;
                  $classPeriodStart = $r['class_period_start'] ?? null;
                  $classPeriodEnd = $r['class_period_end'] ?? null;
                  $hasIn   = !empty($r['location_in']);
                  $hasOut  = !empty($r['location_out']);
                  $hasPhoto = !empty($r['photo']);
                  $checkInAcc  = isset($r['check_in_acc']) ? (float)$r['check_in_acc'] : null;
                  $checkOutAcc = isset($r['check_out_acc']) ? (float)$r['check_out_acc'] : null;
                  $pendingReasonsArr = humanize_pending_reasons($r['pending_reasons'] ?? null);
                ?>
                <div class="modal fade text-start adm-att-modal" id="modalAttendance<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-fullscreen-md-down modal-dialog-centered modal-dialog-scrollable">
                      <div class="modal-content">
                        <div class="modal-header bg-light">
                          <div class="d-flex align-items-center gap-3 flex-grow-1">
                            <div class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px">
                              <i class="bi bi-clipboard2-pulse fs-5"></i>
                            </div>
                            <div>
                              <h5 class="modal-title mb-0"><?= esc(mb_convert_case($r['name'] ?? '', MB_CASE_TITLE, 'UTF-8')) ?></h5>
                              <div class="text-muted small">
                                <i class="bi bi-calendar3 me-1"></i><?= esc($fmtDateBR($r['date'])) ?>
                                <span class="text-muted">·</span>
                                <?= esc($r['weekday_label']) ?>
                                <?php if ($schoolName): ?>
                                  <span class="text-muted">·</span>
                                  <i class="bi bi-building me-1"></i><?= esc($schoolName) ?>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">

                          <!-- ============ Identificação técnica ============ -->
                          <div class="adm-att-pills mb-4">
                            <span class="adm-pill" title="ID interno do registro"><i class="bi bi-hash"></i>ID <?= (int)$r['id'] ?></span>
                            <?php if ($nsr !== null): ?>
                              <span class="adm-pill" title="Número Sequencial de Registro (Portaria MTP 671/2021)"><i class="bi bi-list-ol"></i>NSR <?= esc($nsr) ?></span>
                            <?php endif; ?>
                            <?php if ($sequenceNumber > 1): ?>
                              <span class="adm-pill" title="Sequência do dia (múltiplos check-ins)"><i class="bi bi-123"></i>Seq <?= (int)$sequenceNumber ?></span>
                            <?php endif; ?>
                            <?php if ($classPeriodNumber !== null): ?>
                              <span class="adm-pill" title="Período da grade horária (<?= esc(substr((string)$classPeriodStart,0,5)) ?>–<?= esc(substr((string)$classPeriodEnd,0,5)) ?>)">
                                <i class="bi bi-bookmark"></i><?= (int)$classPeriodNumber ?>ª aula
                              </span>
                            <?php endif; ?>
                            <span class="adm-pill <?= $isOffline ? 'adm-pill-warn' : '' ?>" title="<?= $isOffline ? 'Registrado offline e sincronizado depois' : 'Registrado online (em tempo real)' ?>">
                              <i class="bi bi-<?= $isOffline ? 'wifi-off' : 'wifi' ?>"></i><?= $isOffline ? 'Offline' : 'Online' ?>
                            </span>
                            <span class="adm-pill" title="Método de autenticação">
                              <i class="bi <?= esc($icon) ?>"></i><?= esc($label) ?>
                            </span>
                            <?php if ($clientId): ?>
                              <span class="adm-pill adm-pill-mono" title="Identificador único do request (idempotência)">
                                <i class="bi bi-fingerprint"></i><?= esc($truncMid((string)$clientId, 18)) ?>
                              </span>
                            <?php endif; ?>
                          </div>

                          <div class="row g-4">

                            <!-- ============ HORÁRIOS / CARGA ============ -->
                            <div class="col-12 col-lg-6">
                              <h6 class="adm-att-section"><i class="bi bi-clock"></i> Horários e Carga</h6>
                              <?php
                                // Horários EXIBIDOS no modal refletem o turno consolidado do dia
                                // (entrada inicial → saída final), não o registro representativo $r
                                // sozinho. Sem isto, em fluxo 8h-intervalo-saida o modal mostrava
                                // "Entrada 08:00 / Saída 11:00" (saída para intervalo) em vez de 16:00.
                                $dayInRaw  = $day['check_in']  ? $day['date'] . ' ' . $day['check_in']  : null;
                                $dayOutRaw = $day['check_out'] ? $day['date'] . ' ' . $day['check_out'] : null;
                              ?>
                              <div class="adm-att-times mb-2">
                                <div>
                                  <span class="adm-time-label">Entrada</span>
                                  <span class="adm-time-val text-success"><?= esc($day['check_in'] ? substr($day['check_in'], 0, 5) : '—') ?></span>
                                  <?php if ($dayInRaw): ?>
                                    <span class="adm-time-sub"><?= esc(date('d/m/Y H:i:s', strtotime($dayInRaw))) ?></span>
                                  <?php endif; ?>
                                </div>
                                <div class="adm-time-arrow">→</div>
                                <div>
                                  <span class="adm-time-label">Saída</span>
                                  <span class="adm-time-val text-danger"><?= esc($day['check_out'] ? substr($day['check_out'], 0, 5) : '—') ?></span>
                                  <?php if ($dayOutRaw): ?>
                                    <span class="adm-time-sub"><?= esc(date('d/m/Y H:i:s', strtotime($dayOutRaw))) ?></span>
                                  <?php endif; ?>
                                </div>
                              </div>
                              <?php if (!empty($day['breaks'])): ?>
                                <div class="mb-2 small">
                                  <strong class="text-muted"><i class="bi bi-pause-circle me-1"></i>Intervalos do dia:</strong>
                                  <ul class="list-unstyled mb-0 mt-1 ps-3">
                                    <?php foreach ($day['breaks'] as $bi => $bb):
                                      $bs = $bb['start'] ? substr($bb['start'], 0, 5) : '—';
                                      $be = $bb['end']   ? substr($bb['end'], 0, 5)   : '<em>em aberto</em>';
                                      $bd = $bb['duration_minutes'] !== null ? format_duration_minutes((int)$bb['duration_minutes']) : '—';
                                    ?>
                                      <li>↳ <?= ($bi + 1) ?>: <strong><?= esc($bs) ?></strong> → <strong><?= $be ?></strong> <span class="text-muted">(<?= esc($bd) ?>)</span></li>
                                    <?php endforeach; ?>
                                  </ul>
                                </div>
                              <?php endif; ?>
                              <?php
                                // Trabalhada do DIA = presença CHEIA: pares work + intervalos REGISTRADOS
                                // (raw_id !== null). O intervalo conta como tempo trabalhado (modelo
                                // "cheio vs cheio"); breaks INFERIDOS (gap entre works, quando a pessoa
                                // bateu saída no almoço) NÃO contam — tempo fora não é presença.
                                $realBreakDay = 0;
                                foreach (($day['breaks'] ?? []) as $bRow) {
                                    if (($bRow['raw_id'] ?? null) !== null && $bRow['duration_minutes'] !== null) {
                                        $realBreakDay += (int)$bRow['duration_minutes'];
                                    }
                                }
                                $workedDay = (int)$day['total_worked_minutes'] + $realBreakDay;
                                // Exibição "Trabalhada" = tempo LÍQUIDO (presença − intervalos), o número
                                // de transparência. O SALDO permanece no modelo "cheio vs cheio" (presença,
                                // com intervalo registrado contando como trabalhado) — por isso $workedDay
                                // é mantido apenas para o cálculo de $balDay e do percentual.
                                $netWorkedDay = (int)$day['total_worked_minutes'];
                                $breakDay  = (int)$day['total_break_minutes'];
                                $balDay    = $workedDay - $expectedMin;
                                $balDayClass = $balDay > 0 ? 'text-success' : ($balDay < 0 ? 'text-danger' : 'text-muted');
                              ?>
                              <dl class="adm-kv">
                                <dt>Trabalhada <span class="text-muted small">(líq.)</span></dt><dd class="fw-semibold"><?= $fmtMin($netWorkedDay) ?></dd>
                                <?php if ($breakDay > 0): ?>
                                  <dt>Intervalos</dt><dd><?= $fmtMin($breakDay) ?> <span class="text-muted small">(<?= (int)$breakCount ?>)</span></dd>
                                <?php endif; ?>
                                <dt title="Presença = trabalhada líquida + intervalo. É o valor comparado à prevista no saldo — o intervalo conta como trabalhado e não gera déficit.">Presença</dt><dd><?= $fmtMin($workedDay) ?></dd>
                                <dt>Prevista</dt><dd title="Aulas: <?= (int)$classesCount ?> • Min/Aula: <?= (int)$classMinutes ?>"><?= $fmtMin($expectedMin) ?></dd>
                                <?php if ($workedDay > 0): ?>
                                  <?php
                                    $pct = $expectedMin > 0 ? (int)round(($workedDay / $expectedMin) * 100) : 100;
                                    $deltaStr = ($balDay >= 0 ? '+' : '') . $fmtMin($balDay);
                                  ?>
                                  <dt>Saldo</dt>
                                  <dd>
                                    <span class="<?= $balDayClass ?> fw-semibold"><?= $deltaStr ?></span>
                                    <span class="text-muted small">(<?= $pct ?>%)</span>
                                  </dd>
                                <?php endif; ?>
                              </dl>
                            </div>

                            <!-- ============ LOCALIZAÇÃO + FOTO ============ -->
                            <div class="col-12 col-lg-6">
                              <h6 class="adm-att-section"><i class="bi bi-geo-alt"></i> Localização & Foto</h6>
                              <div class="d-flex gap-3 align-items-start flex-wrap">
                                <div class="flex-grow-1">
                                  <dl class="adm-kv">
                                    <?php if ($hasIn): ?>
                                      <dt>GPS Entrada</dt>
                                      <dd>
                                        <a href="https://maps.google.com/?q=<?= esc($r['location_in'][0]) ?>,<?= esc($r['location_in'][1]) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                          <i class="bi bi-geo-alt-fill text-success me-1"></i>
                                          <?= number_format($r['location_in'][0], 5, '.', '') ?>, <?= number_format($r['location_in'][1], 5, '.', '') ?>
                                        </a>
                                        <?php if ($checkInAcc !== null): ?>
                                          <span class="text-muted small">· acurácia ±<?= number_format($checkInAcc, 0) ?>m</span>
                                        <?php endif; ?>
                                      </dd>
                                    <?php endif; ?>
                                    <?php if ($hasOut): ?>
                                      <dt>GPS Saída</dt>
                                      <dd>
                                        <a href="https://maps.google.com/?q=<?= esc($r['location_out'][0]) ?>,<?= esc($r['location_out'][1]) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                          <i class="bi bi-geo-alt text-danger me-1"></i>
                                          <?= number_format($r['location_out'][0], 5, '.', '') ?>, <?= number_format($r['location_out'][1], 5, '.', '') ?>
                                        </a>
                                        <?php if ($checkOutAcc !== null): ?>
                                          <span class="text-muted small">· acurácia ±<?= number_format($checkOutAcc, 0) ?>m</span>
                                        <?php endif; ?>
                                      </dd>
                                    <?php endif; ?>
                                    <?php if (!$hasIn && !$hasOut): ?>
                                      <dt>GPS</dt><dd class="text-muted">Sem coordenadas registradas</dd>
                                    <?php endif; ?>
                                  </dl>
                                </div>
                                <?php if ($hasPhoto): ?>
                                  <?php if (empty($r['photo_deleted']) || $r['photo_deleted'] == 0): ?>
                                    <?php // NC-03: servida por photo_view.php (autenticado + escopo), nunca direto de /photos/. ?>
                                    <a href="../photo_view.php?att=<?= (int)$r['id'] ?>" target="_blank" rel="noopener" class="adm-photo-link" title="Abrir foto em tamanho real">
                                      <img src="../photo_view.php?att=<?= (int)$r['id'] ?>" alt="Foto do registro" loading="lazy" class="adm-photo-thumb">
                                      <span class="adm-photo-zoom"><i class="bi bi-arrows-fullscreen"></i></span>
                                    </a>
                                  <?php else: ?>
                                    <span class="badge bg-secondary p-2" title="Foto excluída automaticamente em <?= !empty($r['photo_deleted_at']) ? date('d/m/Y', strtotime($r['photo_deleted_at'])) : 'data desconhecida' ?>">
                                      <i class="bi bi-image-fill"></i> Foto deletada
                                    </span>
                                  <?php endif; ?>
                                <?php endif; ?>
                              </div>
                            </div>

                            <!-- ============ ANTI-FRAUDE ============ -->
                            <div class="col-12 col-lg-6">
                              <h6 class="adm-att-section"><i class="bi bi-shield-check"></i> Anti-fraude</h6>
                              <dl class="adm-kv">
                                <dt>Risco</dt>
                                <dd>
                                  <span class="badge text-bg-<?= esc($fraudInfo[1]) ?>-subtle border border-<?= esc($fraudInfo[1]) ?> text-<?= esc($fraudInfo[1]) ?>-emphasis">
                                    <i class="bi bi-<?= esc($fraudInfo[2]) ?> me-1"></i><?= esc($fraudInfo[0]) ?>
                                  </span>
                                  <span class="text-muted small">(nível <?= (int)($r['fraud_risk_level'] ?? 0) ?>)</span>
                                </dd>
                                <?php if ($livenessScore !== null): ?>
                                  <dt>Liveness</dt>
                                  <dd>
                                    <?php $livColor = $livenessScore >= 0.7 ? 'success' : ($livenessScore >= 0.4 ? 'warning' : 'danger'); ?>
                                    <span class="text-<?= $livColor ?> fw-semibold"><?= number_format($livenessScore, 2) ?></span>
                                    <span class="text-muted small">(0–1, ≥0,7 ok)</span>
                                  </dd>
                                <?php endif; ?>
                                <dt>GPS Mock</dt>
                                <dd>
                                  <?php if ($gpsMock): ?>
                                    <span class="badge text-bg-danger-subtle border border-danger text-danger-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>Detectado</span>
                                  <?php else: ?>
                                    <span class="text-success"><i class="bi bi-check-circle me-1"></i>Não</span>
                                  <?php endif; ?>
                                </dd>
                                <?php if (!empty($pendingReasonsArr)): ?>
                                  <dt>Motivos da pendência</dt>
                                  <dd>
                                    <ul class="list-unstyled mb-0 small">
                                      <?php foreach ($pendingReasonsArr as $reason): ?>
                                        <li class="d-flex gap-2 mb-1">
                                          <i class="bi bi-exclamation-triangle-fill text-warning flex-shrink-0 mt-1"></i>
                                          <span><?= esc($reason) ?></span>
                                        </li>
                                      <?php endforeach; ?>
                                    </ul>
                                  </dd>
                                <?php endif; ?>
                              </dl>
                            </div>

                            <!-- ============ SINCRONIZAÇÃO ============ -->
                            <div class="col-12 col-lg-6">
                              <h6 class="adm-att-section"><i class="bi bi-arrow-down-up"></i> Sincronização</h6>
                              <dl class="adm-kv">
                                <dt>Status HLB</dt>
                                <dd>
                                  <span class="badge text-bg-<?= esc($hlbInfo[1]) ?>-subtle border border-<?= esc($hlbInfo[1]) ?> text-<?= esc($hlbInfo[1]) ?>-emphasis">
                                    <i class="bi bi-<?= esc($hlbInfo[2]) ?> me-1"></i><?= esc($hlbInfo[0]) ?>
                                  </span>
                                </dd>
                                <?php if ($isOffline): ?>
                                  <dt>Atraso offline</dt>
                                  <dd title="Diferença entre relógio do cliente no momento do registro e gravação no servidor">
                                    <i class="bi bi-hourglass me-1 text-warning"></i><?= esc($fmtOfflineDelay($offlineDelaySec)) ?>
                                  </dd>
                                  <dt>Hora cliente</dt>
                                  <dd class="small text-muted"><?= esc($fmtDateTime($clientRecordedAt)) ?></dd>
                                  <dt>Hora servidor</dt>
                                  <dd class="small text-muted"><?= esc($fmtDateTime($serverRecordedAt)) ?></dd>
                                <?php else: ?>
                                  <dt>Gravado em</dt>
                                  <dd class="small"><?= esc($fmtDateTime($serverRecordedAt)) ?></dd>
                                <?php endif; ?>
                                <?php if ($syncedAt): ?>
                                  <dt>Sincronizado</dt>
                                  <dd class="small text-muted"><?= esc($fmtDateTime($syncedAt)) ?></dd>
                                <?php endif; ?>
                                <?php if ($hlbOffset !== 0): ?>
                                  <dt>Offset HLB</dt>
                                  <dd class="small text-muted"><?= ($hlbOffset >= 0 ? '+' : '') . $hlbOffset ?>s</dd>
                                <?php endif; ?>
                              </dl>
                            </div>

                            <!-- ============ DISPOSITIVO ============ -->
                            <div class="col-12 col-lg-6">
                              <h6 class="adm-att-section"><i class="bi bi-laptop"></i> Dispositivo & Origem</h6>
                              <dl class="adm-kv">
                                <dt>IP</dt>
                                <dd>
                                  <?php [$ipDisplay, $ipTag] = $fmtIp($r['ip'] ?? null); ?>
                                  <span class="adm-mono"><?= esc($ipDisplay) ?></span>
                                  <?php if ($ipTag): ?>
                                    <span class="badge text-bg-secondary-subtle border border-secondary-subtle text-secondary-emphasis ms-1" style="font-size:.7rem;">
                                      <?= esc($ipTag) ?>
                                    </span>
                                  <?php endif; ?>
                                </dd>
                                <dt>User-Agent</dt>
                                <dd class="small text-muted" title="<?= esc($r['user_agent'] ?? '') ?>"><?= esc($fmtUserAgent($r['user_agent'] ?? null)) ?></dd>
                                <?php if ($deviceIdentifier): ?>
                                  <dt>Device ID</dt>
                                  <dd class="adm-mono small" title="<?= esc($deviceIdentifier) ?>"><?= esc($truncMid((string)$deviceIdentifier, 28)) ?></dd>
                                <?php endif; ?>
                                <?php if ($deviceFingerprint): ?>
                                  <dt>Fingerprint</dt>
                                  <dd class="adm-mono small" title="<?= esc($deviceFingerprint) ?>"><?= esc($truncMid((string)$deviceFingerprint, 28)) ?></dd>
                                <?php endif; ?>
                              </dl>
                            </div>

                            <!-- ============ JUSTIFICATIVA / MANUAL ============ -->
                            <div class="col-12 col-lg-6">
                              <h6 class="adm-att-section"><i class="bi bi-chat-left-text"></i> Justificativa & Origem</h6>
                              <div class="mb-2">
                                <span class="badge rounded-pill border border-<?= esc($color) ?> text-<?= esc($color) ?>-emphasis bg-<?= esc($color) ?>-subtle px-3 py-2">
                                  <i class="bi <?= esc($icon) ?> me-1"></i><?= esc($label) ?>
                                </span>
                              </div>
                              <?php if ($mode === 'manual'): ?>
                                <dl class="adm-kv">
                                  <dt>Motivo</dt>
                                  <dd><?= $justStr !== '' ? esc($justStr) : '<span class="text-muted">—</span>' ?></dd>
                                  <?php if (!empty($r['manual_by_admin_username'])): ?>
                                    <dt>Lançado por</dt>
                                    <dd class="small"><?= esc($r['manual_by_admin_username']) ?></dd>
                                  <?php endif; ?>
                                  <?php if (!empty($r['manual_created_at'])): ?>
                                    <dt>Em</dt>
                                    <dd class="small text-muted"><?= esc($fmtDateTime($r['manual_created_at'])) ?></dd>
                                  <?php endif; ?>
                                </dl>
                              <?php else: ?>
                                <p class="text-muted small mb-0">Registro automático pelo colaborador.</p>
                              <?php endif; ?>
                            </div>

                            <!-- ============ EDIÇÃO ============ -->
                            <?php if ($wasEdited): ?>
                              <div class="col-12">
                                <h6 class="adm-att-section"><i class="bi bi-pencil-square"></i> Edição administrativa</h6>
                                <dl class="adm-kv adm-kv-cols">
                                  <?php if ($editType): ?>
                                    <dt>Tipo</dt>
                                    <dd><?= esc($editType) ?><?= $editDelta !== null ? ' · Δ ' . (int)$editDelta . ' min' : '' ?></dd>
                                  <?php endif; ?>
                                  <dt>Por</dt>
                                  <dd><?= esc($editedBy ?? '—') ?></dd>
                                  <dt>Em</dt>
                                  <dd class="small text-muted"><?= esc($editedAt ?? '—') ?></dd>
                                  <dt>Motivo</dt>
                                  <dd><?= esc($editReason ?: '—') ?></dd>
                                </dl>
                              </div>
                            <?php endif; ?>

                          </div>
                        </div>
                        <div class="modal-footer d-flex justify-content-between">
                          <small class="text-muted">
                            Atualizado em
                            <?php if (!empty($r['updated_at'])): ?>
                              <?= esc($fmtDateTime($r['updated_at'])) ?>
                            <?php else: ?>
                              —
                            <?php endif; ?>
                          </small>
                          <div class="d-flex gap-2 flex-wrap">
                            <a href="attendance_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-primary btn-sm">
                              <i class="bi bi-pencil-square me-1"></i>Editar
                            </a>
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
              </td>
            </tr>
            <?php if ($breakCount > 0): ?>
              <tr class="break-detail-row d-none" id="<?= esc($dayCollapseId) ?>">
                <td colspan="6" class="bg-light-subtle border-top-0">
                  <div class="px-3 py-2 text-start">
                    <strong class="text-muted small d-block mb-1"><i class="bi bi-pause-circle me-1"></i>Intervalos do dia (<?= esc(format_duration_minutes($totalBreakMinDay)) ?> total)</strong>
                    <ul class="list-unstyled mb-0 small">
                      <?php foreach ($day['breaks'] as $i => $b): ?>
                        <li class="d-flex align-items-center gap-2 py-1">
                          <span class="text-muted">↳ Intervalo <?= ($i + 1) ?>:</span>
                          <strong><?= esc(substr((string)($b['start'] ?? ''), 0, 5)) ?></strong>
                          <span class="text-muted">→</span>
                          <?php if ($b['end']): ?>
                            <strong><?= esc(substr($b['end'], 0, 5)) ?></strong>
                          <?php else: ?>
                            <em class="text-warning">em aberto</em>
                          <?php endif; ?>
                          <?php if ($b['duration_minutes'] !== null): ?>
                            <span class="text-muted">(<?= esc(format_duration_minutes($b['duration_minutes'])) ?>)</span>
                          <?php endif; ?>
                          <?php if (!empty($b['raw_id'])): ?>
                            <a href="attendance_edit.php?id=<?= (int)$b['raw_id'] ?>" class="text-decoration-none ms-2" title="Editar este intervalo">
                              <i class="bi bi-pencil-square small"></i>
                            </a>
                          <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border small ms-2" title="Intervalo inferido entre dois works (sem registro próprio)">inferido</span>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="app-table-card__footer">
        <?php
        $currentPage = $page;
        $baseUrl = $attendancesBaseUrl;
        $totalItems = $totalAttendances;
        include __DIR__ . '/_pagination.php';
        ?>
      </div>
    </section>

    <div class="row g-3 mb-4">
      <div class="col-12 col-md-6">
        <section class="app-section-card">
          <header class="app-section-card__header">
            <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-calendar-week"></i>Resumo</span>
            <h2 class="app-section-card__title">Semanal</h2>
          </header>
          <div class="app-section-card__body">
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
        </section>
      </div>
      <div class="col-12 col-md-6">
        <section class="app-section-card">
          <header class="app-section-card__header">
            <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-calendar-month"></i>Resumo</span>
            <h2 class="app-section-card__title">Mensal</h2>
          </header>
          <div class="app-section-card__body">
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
        </section>
      </div>
    </div>
  </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
  if (typeof bootstrap === 'undefined') return;
  document.querySelectorAll('.admin-table-wrap .dropdown-toggle[data-bs-toggle="dropdown"]').forEach(function(btn) {
    new bootstrap.Dropdown(btn, { popperConfig: { strategy: 'fixed' } });
  });
})();

// Toggle de detalhes de intervalos por dia
(function() {
  document.querySelectorAll('.break-toggle').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      var sel = btn.getAttribute('data-target');
      if (!sel) return;
      var target = document.querySelector(sel);
      if (!target) return;
      var hidden = target.classList.toggle('d-none');
      btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
      var icon = btn.querySelector('.break-toggle-icon');
      if (icon) {
        icon.classList.toggle('bi-chevron-right', hidden);
        icon.classList.toggle('bi-chevron-down', !hidden);
      }
    });
  });
})();
</script>

</html>
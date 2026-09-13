<?php
/**
 * Análises e Rankings — visão consolidada do comportamento da equipe.
 *
 * Mostra 2 rankings dentro de um período:
 *   1. Top Faltosos       — quem mais teve dias úteis sem ponto aprovado
 *   2. Top Pontuais       — quem mais entrou no horário (% dias pontuais)
 *
 * Inclui busca por nome (filtra rankings em tempo real no client) e
 * filtros de período/escola/top N.
 *
 * Performance: 1 query por dataset (attendance, leaves, holidays). O
 * cálculo per-colaborador é em PHP — escala bem até ~500 colaboradores
 * num período de 31 dias (~15k linhas).
 */
require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$admin = current_admin($pdo);

function minutes_to_hhmm_signed(int $min): string {
    $sign = $min < 0 ? '-' : '';
    $min = abs($min);
    return $sign . sprintf('%dh%02d', intdiv($min, 60), $min % 60);
}

// ============================================================================
// FILTROS
// ============================================================================
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$defaultStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
$defaultEnd   = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');

$dateFrom = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from']) ? $_GET['date_from'] : $defaultStart;
$dateTo   = isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])   ? $_GET['date_to']   : $defaultEnd;
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$schoolFilter = isset($_GET['school']) ? (int)$_GET['school'] : 0;
$topN = isset($_GET['top_n']) ? max(5, min(50, (int)$_GET['top_n'])) : 10;
$tolMin = isset($_GET['tol_min']) ? max(0, min(60, (int)$_GET['tol_min'])) : 10; // tolerância pontualidade

// Escopo do admin (network/school)
[$scopeSql, $scopeParams] = admin_scope_where('t');

// ============================================================================
// CARREGA COLABORADORES
// ============================================================================
$teachersSql = "
    SELECT t.id, t.name, t.created_at, ct.schedule_mode
      FROM teachers t
      LEFT JOIN collaborator_types ct ON ct.id = t.type_id
     WHERE t.active = 1 AND $scopeSql
";
$paramsTeachers = $scopeParams;
if ($schoolFilter > 0 && is_network_admin($admin)) {
    $teachersSql .= " AND EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = t.id AND ts.school_id = ?)";
    $paramsTeachers[] = $schoolFilter;
}
$teachersSql .= " ORDER BY t.name";
$st = $pdo->prepare($teachersSql);
$st->execute($paramsTeachers);
$teachers = $st->fetchAll(PDO::FETCH_ASSOC);
$teacherById = [];
foreach ($teachers as $t) {
    $teacherById[(int)$t['id']] = $t + [
        'mode' => $t['schedule_mode'] ?? 'classes',
        'schedule' => [],
        'expected_min' => 0,
        'worked_min' => 0,
        'days_worked' => 0,
        'days_punctual' => 0,
        'absences' => 0,
        'first_punch_min' => [], // por dia: minuto da primeira batida
    ];
}
$teacherIds = array_keys($teacherById);

// ============================================================================
// SCHEDULES (jornada esperada por weekday) — em batch
// ============================================================================
if (!empty($teacherIds)) {
    $place = implode(',', array_fill(0, count($teacherIds), '?'));

    // teacher_schedules (modo classes)
    $stCl = $pdo->prepare("SELECT teacher_id, weekday, classes_count, class_minutes
                           FROM teacher_schedules WHERE teacher_id IN ($place)");
    $stCl->execute($teacherIds);
    while ($row = $stCl->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['teacher_id'];
        $w = (int)$row['weekday'];
        $teacherById[$tid]['schedule'][$w] = [
            'expected_min' => (int)$row['classes_count'] * (int)$row['class_minutes'],
            'start_time' => null, // classes não tem hora de entrada fixa
        ];
    }

    // collaborator_time_schedules (modo time — tem start_time real)
    $stTm = $pdo->prepare("SELECT teacher_id, weekday, start_time, end_time, break_minutes
                           FROM collaborator_time_schedules WHERE teacher_id IN ($place)");
    $stTm->execute($teacherIds);
    while ($row = $stTm->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['teacher_id'];
        $w = (int)$row['weekday'];
        $exp = 0;
        $start = $row['start_time'];
        $end   = $row['end_time'];
        if ($start && $end) {
            $s = DateTime::createFromFormat('H:i:s', $start) ?: DateTime::createFromFormat('H:i', $start);
            $e = DateTime::createFromFormat('H:i:s', $end)   ?: DateTime::createFromFormat('H:i', $end);
            if ($s && $e) {
                if ($e <= $s) $e = (clone $e)->modify('+1 day');
                $exp = max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60) - (int)$row['break_minutes']);
            }
        }
        // Modo time SOBRESCREVE classes (caso ambos existam)
        $teacherById[$tid]['schedule'][$w] = [
            'expected_min' => $exp,
            'start_time' => $start, // necessário para pontualidade
        ];
    }

    // collaborator_hours_schedules (modo hours — total de horas/dia, sem horário fixo)
    $stHr = $pdo->prepare("SELECT teacher_id, weekday, total_minutes
                           FROM collaborator_hours_schedules WHERE teacher_id IN ($place)");
    $stHr->execute($teacherIds);
    while ($row = $stHr->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['teacher_id'];
        $w = (int)$row['weekday'];
        // Modo hours SOBRESCREVE outros (cada colaborador tem só um modo, mas dados orphans podem existir)
        $teacherById[$tid]['schedule'][$w] = [
            'expected_min' => max(0, (int)$row['total_minutes']),
            'start_time' => null, // sem horário fixo → pontualidade não se aplica
        ];
    }
}

// ============================================================================
// EXCEÇÕES DE CALENDÁRIO não-úteis (feriado / ponto facultativo / recesso)
// ============================================================================
// Fonte correta: calendar_exceptions (is_working_day=0). A tabela `holidays`
// referida antes NÃO existe neste schema — o calendário do admin grava em
// calendar_exceptions, então o set ficava sempre vazio e TODO feriado era
// contado como falta. Separa exceções da rede (school_id NULL) das de escola.
$holidayNetwork  = []; // [YYYY-MM-DD] => true (vale para todos)
$holidayBySchool = []; // [school_id][YYYY-MM-DD] => true
try {
    $stH = $pdo->prepare("SELECT date, school_id FROM calendar_exceptions
                          WHERE is_working_day = 0 AND date BETWEEN ? AND ?");
    $stH->execute([$dateFrom, $dateTo]);
    while ($r = $stH->fetch(PDO::FETCH_ASSOC)) {
        if ($r['school_id'] === null) {
            $holidayNetwork[$r['date']] = true;
        } else {
            $holidayBySchool[(int)$r['school_id']][$r['date']] = true;
        }
    }
} catch (Throwable $_) { /* defensivo: coluna/tabela ausente em instâncias antigas */ }

// Escola primária de cada colaborador (para resolver feriados por escola).
$teacherSchool = []; // [teacher_id] => school_id|null
if (!empty($teacherIds)) {
    $placeTS = implode(',', array_fill(0, count($teacherIds), '?'));
    try {
        $stTS = $pdo->prepare("SELECT teacher_id, MIN(school_id) AS school_id
                               FROM teacher_schools WHERE teacher_id IN ($placeTS) GROUP BY teacher_id");
        $stTS->execute($teacherIds);
        while ($r = $stTS->fetch(PDO::FETCH_ASSOC)) {
            $teacherSchool[(int)$r['teacher_id']] = $r['school_id'] !== null ? (int)$r['school_id'] : null;
        }
    } catch (Throwable $_) { /* defensivo */ }
}

// ============================================================================
// LEAVES aprovadas no período (por colaborador, set de dias)
// ============================================================================
$leavesByTeacherDay = []; // [teacher_id][YYYY-MM-DD] = true
if (!empty($teacherIds)) {
    $place = implode(',', array_fill(0, count($teacherIds), '?'));
    $stL = $pdo->prepare("
        SELECT l.teacher_id, l.start_date, l.end_date
          FROM leaves l
         WHERE l.approved = 1
           AND l.excuses_absence = 1
           AND l.teacher_id IN ($place)
           AND l.end_date >= ? AND l.start_date <= ?
    ");
    $stL->execute(array_merge($teacherIds, [$dateFrom, $dateTo]));
    while ($r = $stL->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$r['teacher_id'];
        $d0 = max($r['start_date'], $dateFrom);
        $d1 = min($r['end_date'], $dateTo);
        $cur = new DateTime($d0);
        $end = new DateTime($d1);
        while ($cur <= $end) {
            $leavesByTeacherDay[$tid][$cur->format('Y-m-d')] = true;
            $cur->modify('+1 day');
        }
    }
}

// ============================================================================
// ATTENDANCE no período (apenas aprovadas) — agrupa por teacher+date
// ============================================================================
// dayHits[teacher_id][YYYY-MM-DD] = ['worked' => min, 'first_in_min' => int|null]
$dayHits = [];
if (!empty($teacherIds)) {
    $place = implode(',', array_fill(0, count($teacherIds), '?'));
    $stA = $pdo->prepare("
        SELECT teacher_id, date, check_in, check_out
          FROM attendance
         WHERE approved = 1
           AND teacher_id IN ($place)
           AND date BETWEEN ? AND ?
    ");
    $stA->execute(array_merge($teacherIds, [$dateFrom, $dateTo]));
    while ($r = $stA->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$r['teacher_id'];
        $d   = $r['date'];
        $dayHits[$tid][$d] = $dayHits[$tid][$d] ?? ['worked' => 0, 'first_in_min' => null];

        $worked = 0;
        if (!empty($r['check_in']) && !empty($r['check_out'])) {
            $in  = strtotime($r['check_in']);
            $out = strtotime($r['check_out']);
            if ($out > $in) $worked = (int)floor(($out - $in) / 60);
        }
        $dayHits[$tid][$d]['worked'] += $worked;

        if (!empty($r['check_in'])) {
            $tIn = new DateTime($r['check_in']);
            $minOfDay = (int)$tIn->format('H') * 60 + (int)$tIn->format('i');
            $cur = $dayHits[$tid][$d]['first_in_min'];
            if ($cur === null || $minOfDay < $cur) {
                $dayHits[$tid][$d]['first_in_min'] = $minOfDay;
            }
        }
    }
}

// ============================================================================
// CÁLCULO POR COLABORADOR
// ============================================================================
$periodStartDt = new DateTimeImmutable($dateFrom);
$periodEndDt   = new DateTimeImmutable($dateTo);
$todayDt       = new DateTimeImmutable($today);

foreach ($teacherById as $tid => &$T) {
    $createdAt = counting_start_for($T['created_at'] ?? null);
    $cur = $periodStartDt;

    while ($cur <= $periodEndDt) {
        $dateStr = $cur->format('Y-m-d');
        $w = (int)$cur->format('w');
        $sched = $T['schedule'][$w] ?? null;
        $expMin = $sched['expected_min'] ?? 0;

        // Só conta dias após cadastro do colaborador
        $afterHire = ($dateStr >= $createdAt);
        $isFuture  = ($dateStr > $today);
        $tSchool   = $teacherSchool[$tid] ?? null;
        $isHoliday = isset($holidayNetwork[$dateStr])
                  || ($tSchool !== null && isset($holidayBySchool[$tSchool][$dateStr]));
        $isLeave   = isset($leavesByTeacherDay[$tid][$dateStr]);

        $hits = $dayHits[$tid][$dateStr] ?? null;
        $workedDay = $hits['worked'] ?? 0;
        $firstInMin = $hits['first_in_min'] ?? null;

        // Total previsto / trabalhado: ignora futuro e licenças pagas
        if ($afterHire && !$isFuture && !$isLeave) {
            $T['expected_min'] += $expMin;
            $T['worked_min']   += $workedDay;
        }

        // Conta dia trabalhado (ao menos 1 batida) para % de pontualidade
        if ($hits) {
            $T['days_worked']++;
            // Pontualidade só faz sentido se a jornada tem hora de início definida
            if (!empty($sched['start_time']) && $firstInMin !== null) {
                $st = $sched['start_time'];
                $startMin = (int)substr($st, 0, 2) * 60 + (int)substr($st, 3, 2);
                if ($firstInMin <= $startMin + $tolMin) $T['days_punctual']++;
            }
        }

        // Faltas: dia útil previsto, não-feriado, não-licença, sem batidas, não-futuro, após hire
        if ($afterHire && !$isFuture && $expMin > 0 && !$isHoliday && !$isLeave && !$hits) {
            $T['absences']++;
        }

        $cur = $cur->modify('+1 day');
    }

    $T['balance_min'] = $T['worked_min'] - $T['expected_min'];
    $T['punctual_pct'] = $T['days_worked'] > 0
        ? round(($T['days_punctual'] / $T['days_worked']) * 100, 1)
        : null;
}
unset($T);

// ============================================================================
// RANKINGS
// ============================================================================
$ranking = [
    'absences'  => [],
    'punctual'  => [],
];

foreach ($teacherById as $T) {
    if ($T['absences'] > 0) {
        $ranking['absences'][] = [
            'id' => $T['id'], 'name' => $T['name'],
            'metric' => $T['absences'],
            'subtitle' => $T['absences'] === 1 ? '1 falta' : ($T['absences'] . ' faltas'),
        ];
    }
    if ($T['punctual_pct'] !== null && $T['days_worked'] >= 3) {
        // Mínimo 3 dias trabalhados pra ter base estatística
        $ranking['punctual'][] = [
            'id' => $T['id'], 'name' => $T['name'],
            'metric' => $T['punctual_pct'],
            'subtitle' => $T['punctual_pct'] . '% pontual (' . $T['days_punctual'] . '/' . $T['days_worked'] . ')',
        ];
    }
}

usort($ranking['absences'], fn($a, $b) => $b['metric'] <=> $a['metric']);
usort($ranking['punctual'], fn($a, $b) => $b['metric'] <=> $a['metric']);

$ranking['absences'] = array_slice($ranking['absences'], 0, $topN);
$ranking['punctual'] = array_slice($ranking['punctual'], 0, $topN);

// Totais agregados (para os KPIs do header)
$totals = [
    'collaborators'     => count($teacherById),
    'with_absences'     => count(array_filter($teacherById, fn($t) => $t['absences'] > 0)),
    'total_absences'    => array_sum(array_column($teacherById, 'absences')),
];

// Lista de escolas para filtro
$schools = [];
if (is_network_admin($admin)) {
    $schools = $pdo->query("SELECT id, name FROM schools WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Nome da escola selecionada (para o cabeçalho do PDF)
$schoolName = null;
if ($schoolFilter > 0) {
    foreach ($schools as $s) {
        if ((int)$s['id'] === $schoolFilter) { $schoolName = $s['name']; break; }
    }
}

// ============================================================================
// EXPORTAÇÃO PDF (Dompdf) — usa o template _tpl_reports_insights_pdf.php
// ============================================================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        // Variáveis disponíveis no template: $dateFrom, $dateTo, $tolMin, $topN,
        // $schoolFilter, $schoolName, $totals, $ranking, $teacherById, $adminUsername.
        $adminUsername = trim((string)($admin['username'] ?? ''));
        ob_start();
        include __DIR__ . '/_tpl_reports_insights_pdf.php';
        $html = ob_get_clean();
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $filename = 'analises_rankings_' . str_replace('-', '', $dateFrom) . '_' . str_replace('-', '', $dateTo) . '.pdf';
        $dompdf->stream($filename, ['Attachment' => false]); // inline preview
        exit;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Exportação PDF indisponível. Instale as dependências:\n- composer require dompdf/dompdf\nE tente novamente.";
        exit;
    }
}

// Helper para preservar filtros em links
function build_url_with(array $extra): string {
    $q = $_GET;
    foreach ($extra as $k => $v) $q[$k] = $v;
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($q);
}

// Medalhas pra top 3
function medal(int $rank): string {
    if ($rank === 0) return '<span class="rk-medal rk-gold" title="1º lugar"><i class="bi bi-trophy-fill"></i></span>';
    if ($rank === 1) return '<span class="rk-medal rk-silver" title="2º lugar"><i class="bi bi-award-fill"></i></span>';
    if ($rank === 2) return '<span class="rk-medal rk-bronze" title="3º lugar"><i class="bi bi-award"></i></span>';
    return '<span class="rk-medal rk-default">' . ($rank + 1) . '</span>';
}

$pageTitle = 'Análises e Rankings';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title><?= esc($pageTitle) ?> · DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <style>
        /* ===== Página: Análises e Rankings ===== */
        :root {
            --rk-bg: #f7f9fc;
            --rk-card-bg: #ffffff;
            --rk-border: #e2e8f0;
            --rk-text-muted: #64748b;
            --rk-accent: #0162cc;
            --rk-danger: #dc2626;
            --rk-warning: #d97706;
            --rk-success: #059669;
        }
        body { background: var(--rk-bg); }

        /* KPI strip */
        .rk-kpi-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .rk-kpi {
            background: var(--rk-card-bg);
            border: 1px solid var(--rk-border);
            border-radius: 14px;
            padding: 1rem 1.25rem;
            position: relative;
            overflow: hidden;
        }
        .rk-kpi::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--rk-accent);
        }
        .rk-kpi.rk-kpi-danger::before  { background: var(--rk-danger); }
        .rk-kpi.rk-kpi-warning::before { background: var(--rk-warning); }
        .rk-kpi.rk-kpi-success::before { background: var(--rk-success); }
        .rk-kpi-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--rk-text-muted);
            margin-bottom: 0.25rem;
        }
        .rk-kpi-value {
            font-size: 1.85rem;
            font-weight: 700;
            line-height: 1;
            color: #0f172a;
            font-feature-settings: "tnum";
        }
        .rk-kpi-sub {
            font-size: 0.78rem;
            color: var(--rk-text-muted);
            margin-top: 0.25rem;
        }

        /* Filtro card */
        .rk-filter {
            background: var(--rk-card-bg);
            border: 1px solid var(--rk-border);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .rk-search-wrap { position: relative; }
        .rk-search-wrap i.bi-search {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: var(--rk-text-muted); pointer-events: none;
        }
        .rk-search-input { padding-left: 2.25rem; }

        /* Ranking cards */
        .rk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.25rem;
        }
        .rk-card {
            background: var(--rk-card-bg);
            border: 1px solid var(--rk-border);
            border-radius: 14px;
            overflow: hidden;
            display: flex; flex-direction: column;
        }
        .rk-card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--rk-border);
            display: flex; align-items: center; gap: 0.75rem;
        }
        .rk-card-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1rem;
        }
        .rk-card-absences .rk-card-icon { background: #fef2f2; color: var(--rk-danger); }
        .rk-card-overtime .rk-card-icon { background: #fff7ed; color: var(--rk-warning); }
        .rk-card-punctual .rk-card-icon { background: #ecfdf5; color: var(--rk-success); }
        .rk-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .rk-card-sub {
            font-size: 0.75rem;
            color: var(--rk-text-muted);
            margin-top: 1px;
        }
        .rk-card-body { padding: 0.5rem 0; }
        .rk-card-empty { padding: 2rem 1.5rem; text-align: center; color: var(--rk-text-muted); font-size: 0.92rem; }

        /* Rows */
        .rk-row {
            display: grid;
            grid-template-columns: 36px 1fr auto;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            transition: background-color 0.15s ease;
        }
        .rk-row:last-child { border-bottom: none; }
        .rk-row:hover { background: #f8fafc; }
        .rk-row a { text-decoration: none; color: inherit; display: contents; }
        .rk-medal {
            width: 28px; height: 28px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 50%;
            font-weight: 700;
            font-size: 0.75rem;
        }
        .rk-gold   { background: linear-gradient(135deg, #fef08a, #ca8a04); color: #78350f; }
        .rk-silver { background: linear-gradient(135deg, #e5e7eb, #94a3b8); color: #1e293b; }
        .rk-bronze { background: linear-gradient(135deg, #fed7aa, #c2410c); color: #7c2d12; }
        .rk-default { background: #f1f5f9; color: #64748b; }
        .rk-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.92rem;
            line-height: 1.2;
        }
        .rk-name-sub {
            font-size: 0.78rem;
            color: var(--rk-text-muted);
            margin-top: 1px;
        }
        .rk-bar-wrap {
            grid-column: 2 / -1;
            margin-top: 4px;
            height: 4px;
            background: #f1f5f9;
            border-radius: 999px;
            overflow: hidden;
        }
        .rk-bar {
            height: 100%;
            border-radius: 999px;
            transition: width 0.4s ease;
        }
        .rk-card-absences .rk-bar { background: linear-gradient(90deg, #fca5a5, var(--rk-danger)); }
        .rk-card-overtime .rk-bar { background: linear-gradient(90deg, #fcd34d, var(--rk-warning)); }
        .rk-card-punctual .rk-bar { background: linear-gradient(90deg, #86efac, var(--rk-success)); }

        .rk-row.is-hidden { display: none; }
        .rk-no-match {
            padding: 1.5rem;
            text-align: center;
            color: var(--rk-text-muted);
            font-size: 0.88rem;
            font-style: italic;
        }

        /* Header section */
        .rk-page-header {
            background: linear-gradient(135deg, #ffffff, #f1f5f9);
            border: 1px solid var(--rk-border);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.25rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
        }
        .rk-page-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--rk-accent), #1e40af);
            color: white;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            box-shadow: 0 4px 12px rgba(1, 98, 204, 0.25);
        }

        /* Print */
        @media print {
            .rk-filter, .navbar, footer { display: none !important; }
            .rk-card { break-inside: avoid; box-shadow: none; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>

    <div class="container-fluid admin-content">

        <!-- ============ HEADER ============ -->
        <div class="rk-page-header">
            <div class="d-flex align-items-center gap-3">
                <div class="rk-page-icon"><i class="bi bi-bar-chart-line"></i></div>
                <div>
                    <h1 class="h3 mb-0"><?= esc($pageTitle) ?></h1>
                    <p class="text-muted mb-0 small">
                        <?= esc((new DateTime($dateFrom))->format('d/m/Y')) ?>
                        →
                        <?= esc((new DateTime($dateTo))->format('d/m/Y')) ?>
                        · <?= count($teacherById) ?> colaboradores ativos
                    </p>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= esc(build_url_with(['export' => 'pdf'])) ?>"
                   class="btn btn-danger btn-sm"
                   target="_blank" rel="noopener"
                   title="Gerar PDF profissional do relatório completo">
                    <i class="bi bi-filetype-pdf me-1"></i>Exportar PDF
                </a>
                <a href="reports.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-file-earmark-text me-1"></i>Relatório individual
                </a>
            </div>
        </div>

        <!-- ============ KPI STRIP ============ -->
        <div class="rk-kpi-strip">
            <div class="rk-kpi">
                <div class="rk-kpi-label">Colaboradores</div>
                <div class="rk-kpi-value"><?= number_format($totals['collaborators']) ?></div>
                <div class="rk-kpi-sub">ativos no escopo</div>
            </div>
            <div class="rk-kpi rk-kpi-danger">
                <div class="rk-kpi-label">Total de Faltas</div>
                <div class="rk-kpi-value"><?= number_format($totals['total_absences']) ?></div>
                <div class="rk-kpi-sub"><?= $totals['with_absences'] ?> colab. com faltas</div>
            </div>
            <div class="rk-kpi rk-kpi-success">
                <div class="rk-kpi-label">Tolerância</div>
                <div class="rk-kpi-value"><?= (int)$tolMin ?> min</div>
                <div class="rk-kpi-sub">para considerar pontual</div>
            </div>
        </div>

        <!-- ============ FILTROS ============ -->
        <form class="rk-filter" method="get" autocomplete="off">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-3">
                    <label class="form-label small fw-semibold">Buscar colaborador</label>
                    <div class="rk-search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="search" id="rk-search" class="form-control rk-search-input" placeholder="Filtrar por nome…">
                    </div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold">Data inicial</label>
                    <input type="date" name="date_from" class="form-control" value="<?= esc($dateFrom) ?>">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold">Data final</label>
                    <input type="date" name="date_to" class="form-control" value="<?= esc($dateTo) ?>">
                </div>
                <?php if (is_network_admin($admin)): ?>
                <div class="col-12 col-md-6 col-lg-2">
                    <label class="form-label small fw-semibold">Escola</label>
                    <select name="school" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($schools as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $schoolFilter === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= esc($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-3 col-lg-1">
                    <label class="form-label small fw-semibold">Top</label>
                    <select name="top_n" class="form-select">
                        <?php foreach ([5, 10, 15, 20, 30, 50] as $n): ?>
                            <option value="<?= $n ?>" <?= $topN === $n ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-1">
                    <label class="form-label small fw-semibold" title="Tolerância em minutos para considerar entrada pontual">Tol. (min)</label>
                    <input type="number" min="0" max="60" name="tol_min" class="form-control" value="<?= (int)$tolMin ?>">
                </div>
                <div class="col-12 col-lg-1">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-funnel me-1"></i>Aplicar
                    </button>
                </div>
            </div>
            <div class="text-muted small mt-2">
                <i class="bi bi-info-circle me-1"></i>
                Use a busca para filtrar colaboradores em todos os rankings simultaneamente. Período padrão: mês atual.
            </div>
        </form>

        <!-- ============ RANKINGS ============ -->
        <div class="rk-grid">

            <!-- Faltas -->
            <div class="rk-card rk-card-absences">
                <div class="rk-card-header">
                    <div class="rk-card-icon"><i class="bi bi-x-octagon-fill"></i></div>
                    <div>
                        <h2 class="rk-card-title">Quem mais faltou</h2>
                        <div class="rk-card-sub">Dias úteis sem ponto aprovado nem licença</div>
                    </div>
                </div>
                <div class="rk-card-body" data-ranking="absences">
                    <?php if (empty($ranking['absences'])): ?>
                        <div class="rk-card-empty">
                            <i class="bi bi-check-circle-fill text-success fs-3 d-block mb-2"></i>
                            <strong>Excelente!</strong> Nenhuma falta no período.
                        </div>
                    <?php else: $maxAbs = max(array_column($ranking['absences'], 'metric')); ?>
                        <?php foreach ($ranking['absences'] as $i => $r):
                            $pct = $maxAbs > 0 ? round(($r['metric'] / $maxAbs) * 100) : 0; ?>
                            <a href="reports.php?teacher_id=<?= (int)$r['id'] ?>&month=<?= esc(substr($dateFrom, 0, 7)) ?>"
                               class="rk-row" data-name="<?= esc(mb_strtolower($r['name'], 'UTF-8')) ?>">
                                <?= medal($i) ?>
                                <div>
                                    <div class="rk-name"><?= esc($r['name']) ?></div>
                                    <div class="rk-name-sub"><?= esc($r['subtitle']) ?></div>
                                </div>
                                <div class="text-end">
                                    <strong class="text-danger"><?= (int)$r['metric'] ?></strong>
                                </div>
                                <div class="rk-bar-wrap"><div class="rk-bar" style="width: <?= $pct ?>%"></div></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pontuais -->
            <div class="rk-card rk-card-punctual">
                <div class="rk-card-header">
                    <div class="rk-card-icon"><i class="bi bi-check2-circle"></i></div>
                    <div>
                        <h2 class="rk-card-title">Mais pontuais</h2>
                        <div class="rk-card-sub">% de dias com entrada no horário (tol. <?= (int)$tolMin ?>min)</div>
                    </div>
                </div>
                <div class="rk-card-body" data-ranking="punctual">
                    <?php if (empty($ranking['punctual'])): ?>
                        <div class="rk-card-empty">
                            <i class="bi bi-info-circle fs-3 d-block mb-2"></i>
                            Sem dados suficientes — colaboradores precisam<br>
                            ter horário fixo definido (modo "tempo") e<br>
                            pelo menos 3 dias trabalhados no período.
                        </div>
                    <?php else: ?>
                        <?php foreach ($ranking['punctual'] as $i => $r):
                            $pct = (int)$r['metric']; ?>
                            <a href="reports.php?teacher_id=<?= (int)$r['id'] ?>&month=<?= esc(substr($dateFrom, 0, 7)) ?>"
                               class="rk-row" data-name="<?= esc(mb_strtolower($r['name'], 'UTF-8')) ?>">
                                <?= medal($i) ?>
                                <div>
                                    <div class="rk-name"><?= esc($r['name']) ?></div>
                                    <div class="rk-name-sub"><?= esc($r['subtitle']) ?></div>
                                </div>
                                <div class="text-end">
                                    <strong class="text-success"><?= esc(number_format($r['metric'], 1, ',', '.')) ?>%</strong>
                                </div>
                                <div class="rk-bar-wrap"><div class="rk-bar" style="width: <?= $pct ?>%"></div></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="alert alert-light border mt-4 small">
            <i class="bi bi-lightbulb me-2"></i>
            <strong>Como interpretar:</strong>
            <strong>Faltas</strong> contam dias úteis com jornada prevista, sem batidas aprovadas e sem licença
            (ignora feriados e dias antes do cadastro do colaborador).
            <strong>Horas extras</strong> = trabalhado − previsto (apenas saldo positivo no período).
            <strong>Pontualidade</strong> só considera colaboradores com horário fixo (modo "tempo")
            e mínimo de 3 dias trabalhados — % calculado sobre o total de dias com batida.
        </div>

    </div>
    <?php include __DIR__ . '/../_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filtro de busca: oculta linhas que não combinam em TODOS os rankings.
        (function() {
            const input = document.getElementById('rk-search');
            if (!input) return;

            const allRows = document.querySelectorAll('.rk-row');
            const containers = document.querySelectorAll('[data-ranking]');

            function applyFilter() {
                const q = input.value.trim().toLowerCase();
                allRows.forEach(row => {
                    const name = row.getAttribute('data-name') || '';
                    const match = !q || name.includes(q);
                    row.classList.toggle('is-hidden', !match);
                });
                // Adiciona "sem resultados" em containers vazios pós-filtro
                containers.forEach(c => {
                    const visible = c.querySelectorAll('.rk-row:not(.is-hidden)').length;
                    let empty = c.querySelector('.rk-no-match');
                    if (q && visible === 0 && c.querySelectorAll('.rk-row').length > 0) {
                        if (!empty) {
                            empty = document.createElement('div');
                            empty.className = 'rk-no-match';
                            empty.textContent = 'Nenhum colaborador combina com "' + q + '"';
                            c.appendChild(empty);
                        }
                    } else if (empty) {
                        empty.remove();
                    }
                });
            }
            input.addEventListener('input', applyFilter);
            // Ctrl/Cmd+F focus shortcut
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    input.focus();
                }
            });
        })();
    </script>
</body>
</html>

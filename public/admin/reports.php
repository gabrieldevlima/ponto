<?php
/**
 * Relatório Mensal por Colaborador.
 *
 * Suporta filtro por mês fechado OU range customizado (date_from/date_to).
 * Exporta PDF (Dompdf) e CSV. Calcula faltas, pontualidade, horas extras
 * aprovadas e saldo, com subtotais semanais.
 */
require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$admin = current_admin($pdo);

if (!function_exists('minutes_to_hhmm')) {
    function minutes_to_hhmm(int $minutes): string {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);
        return $sign . sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}

// ============================================================================
// FILTROS
// ============================================================================
$month = $_GET['month'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$schoolFilter = isset($_GET['school']) ? (int)$_GET['school'] : 0;

[$scopeSql, $scopeParams] = admin_scope_where('t');

// Carrega colaboradores
$teachersSql = "SELECT t.id, t.name, t.cpf, t.created_at, t.type_id, ct.schedule_mode
                FROM teachers t
                LEFT JOIN collaborator_types ct ON ct.id = t.type_id
                WHERE t.active = 1 AND $scopeSql";
$paramsTeachers = $scopeParams;
if ($schoolFilter > 0 && is_network_admin($admin)) {
    $teachersSql .= " AND EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = t.id AND ts.school_id = ?)";
    $paramsTeachers[] = $schoolFilter;
}
$teachersSql .= " ORDER BY t.name ASC";
$stTeachers = $pdo->prepare($teachersSql);
$stTeachers->execute($paramsTeachers);
$teachers = $stTeachers->fetchAll(PDO::FETCH_ASSOC);

// Resolve período: range customizado tem prioridade sobre month
$useCustomRange = (!empty($dateFrom) && !empty($dateTo)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo));

if (!$useCustomRange) {
    if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');
    }
    $periodStart = DateTime::createFromFormat('Y-m-d', $month . '-01');
    $periodEnd   = (clone $periodStart)->modify('last day of this month');
} else {
    $periodStart = new DateTime($dateFrom);
    $periodEnd   = new DateTime($dateTo);
    if ($periodEnd < $periodStart) { [$periodStart, $periodEnd] = [$periodEnd, $periodStart]; }
    // Mantém $month sincronizado com início do range pra links/títulos
    $month = $periodStart->format('Y-m');
}

// Teacher selecionado
$selectedTeacher = null;
$mode = 'classes';
if ($teacherId) {
    $sqlSel = "SELECT t.id, t.name, t.created_at, ct.schedule_mode
               FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id
               WHERE t.id = ? AND $scopeSql";
    $paramsSel = array_merge([$teacherId], $scopeParams);
    if ($schoolFilter > 0 && is_network_admin($admin)) {
        $sqlSel .= " AND EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = t.id AND ts.school_id = ?)";
        $paramsSel[] = $schoolFilter;
    }
    $st = $pdo->prepare($sqlSel);
    $st->execute($paramsSel);
    $selectedTeacher = $st->fetch(PDO::FETCH_ASSOC);
    if ($selectedTeacher) $mode = $selectedTeacher['schedule_mode'] ?? 'classes';
}

// ============================================================================
// SCHEDULE MAP
// ============================================================================
$scheduleMap = [];
if ($selectedTeacher) {
    if ($mode === 'classes') {
        $st = $pdo->prepare("SELECT weekday, classes_count, class_minutes FROM teacher_schedules WHERE teacher_id = ?");
        $st->execute([$selectedTeacher['id']]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$row['weekday']] = [
                'cc' => (int)$row['classes_count'],
                'cm' => (int)$row['class_minutes'],
                'start' => null,
            ];
        }
    } elseif ($mode === 'time') {
        $st = $pdo->prepare("SELECT weekday, start_time, end_time, end_next_day, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ?");
        $st->execute([$selectedTeacher['id']]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$row['weekday']] = [
                'start' => $row['start_time'],
                'end'   => $row['end_time'],
                'end_next_day' => (int)($row['end_next_day'] ?? 0),
                'break' => (int)$row['break_minutes'],
            ];
        }
    }
}

// ============================================================================
// EXCEÇÕES DE CALENDÁRIO (feriado / ponto facultativo / recesso)
// Resolve a escola do colaborador para capturar feriados específicos da escola
// E os da rede inteira (school_id IS NULL). Sem isso, dias não-úteis caíam como
// FALTA no relatório mensal.
// ============================================================================
$reportSchoolId = $selectedTeacher ? primary_school_id_for_teacher($pdo, (int)$selectedTeacher['id']) : null;
$holidays = $selectedTeacher
    ? get_holidays_in_period($pdo, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'), $reportSchoolId)
    : [];

// ============================================================================
// MONTAGEM DA TIMELINE DIÁRIA
// ============================================================================
$totalExpectedMin = 0;
$daily = [];
$dt = clone $periodStart;
while ($dt <= $periodEnd) {
    $dateStr = $dt->format('Y-m-d');
    $w = (int)$dt->format('w');
    $exp = 0;
    $startTimeForDay = null;
    $isHolidayDay = isset($holidays[$dateStr]); // exceção não-útil (is_working_day=0)
    if (!$isHolidayDay && $selectedTeacher && isset($scheduleMap[$w])) {
        if ($mode === 'classes') {
            $exp = ($scheduleMap[$w]['cc'] ?? 0) * ($scheduleMap[$w]['cm'] ?? 0);
        } elseif ($mode === 'time') {
            $start = $scheduleMap[$w]['start'] ?? null;
            $end   = $scheduleMap[$w]['end'] ?? null;
            $break = $scheduleMap[$w]['break'] ?? 0;
            $endNext = (int)($scheduleMap[$w]['end_next_day'] ?? 0);
            $startTimeForDay = $start;
            if ($start && $end) {
                $win = compute_schedule_window([
                    'start_time' => $start,
                    'end_time' => $end,
                    'end_next_day' => $endNext,
                    'break_minutes' => $break,
                ], $dateStr);
                // Janela completa — break_minutes não é descontado (intervalo
                // conta como tempo trabalhado; modelo "cheio vs cheio").
                if ($win) $exp = max(0, (int)(($win['end']->getTimestamp() - $win['start']->getTimestamp()) / 60));
            }
        }
    }
    $daily[$dateStr] = [
        'expectedMin' => $exp,
        'workedMin'   => 0,
        'items'       => [],
        'weekdayIdx'  => $w,
        'startTime'   => $startTimeForDay,
        'firstInMin'  => null,
        'holiday'     => $holidays[$dateStr] ?? null,
    ];
    $totalExpectedMin += $exp;
    $dt = $dt->modify('+1 day');
}

// Leaves: paid → expected = 0
$leavesByDay = [];
if ($selectedTeacher) {
    $stL = $pdo->prepare("SELECT l.*, lt.paid, lt.name AS leave_type_name FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
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

// Attendance aprovada — soma TODOS os pares aprovados (múltiplos pontos por dia)
// Separa work (jornada) de break (intervalo) — saldo usa apenas work.
if ($selectedTeacher) {
    $st = $pdo->prepare("
      SELECT a.*, mr.name AS manual_reason_name, COALESCE(NULLIF(ed.name, ''), ed.username) AS edited_by_username
      FROM attendance a
      LEFT JOIN manual_reasons mr ON mr.id = a.manual_reason_id
      LEFT JOIN admins ed ON ed.id = a.editado_por
      WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ? AND a.approved = 1
      ORDER BY a.date ASC, a.check_in ASC
    ");
    $st->execute([$selectedTeacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $dateStr = $row['date'];
        $minutes = 0;
        if (!empty($row['check_in']) && !empty($row['check_out'])) {
            $in = new DateTime($row['check_in']);
            $out = new DateTime($row['check_out']);
            if ($out > $in) $minutes = (int) round(($out->getTimestamp() - $in->getTimestamp()) / 60);
        }
        if (!isset($daily[$dateStr])) $daily[$dateStr] = ['expectedMin' => 0, 'workedMin' => 0, 'breakMin' => 0, 'items' => [], 'weekdayIdx' => (int)date('w', strtotime($dateStr)), 'startTime' => null, 'firstInMin' => null];
        if (!isset($daily[$dateStr]['breakMin'])) $daily[$dateStr]['breakMin'] = 0;

        // Modelo "cheio vs cheio": o intervalo CONTA como tempo trabalhado —
        // soma em workedMin (presença cheia) e também em breakMin (informativo).
        if (($row['record_type'] ?? 'work') === 'break') {
            $daily[$dateStr]['breakMin'] += $minutes;
            $daily[$dateStr]['workedMin'] += $minutes;
        } else {
            $daily[$dateStr]['workedMin'] += $minutes;
        }
        $daily[$dateStr]['items'][] = $row;

        // Pontualidade: olha apenas a PRIMEIRA entrada de trabalho do dia.
        if (!empty($row['check_in']) && ($row['record_type'] ?? 'work') === 'work') {
            $tIn = new DateTime($row['check_in']);
            $minOfDay = (int)$tIn->format('H') * 60 + (int)$tIn->format('i');
            $cur = $daily[$dateStr]['firstInMin'];
            if ($cur === null || $minOfDay < $cur) {
                $daily[$dateStr]['firstInMin'] = $minOfDay;
            }
        }
    }
}

// Ajusta expected por licenças pagas
foreach ($daily as $k => &$d) {
    foreach ($leavesByDay[$k] ?? [] as $lv) {
        if ((int)($lv['excuses_absence'] ?? 0) === 1) { // abona a falta → zera jornada
            $totalExpectedMin -= $d['expectedMin'];
            $d['expectedMin'] = 0;
            break;
        }
    }
}
unset($d);

// Janela de contagem: dias antes do início (config/cadastro) ou no futuro NÃO
// contam — zera previsto/trabalhado/efetivo para que totais, saldo e faltas só
// reflitam os dias contados (coerente com o relatório financeiro).
$teacherStartDate = counting_start_for($selectedTeacher['created_at'] ?? null);
$today = date('Y-m-d');
$daily = apply_counting_window($daily, $teacherStartDate, $today, ['expectedMin', 'workedMin', 'effectiveMin']);
$totalExpectedMin = (int) array_sum(array_column($daily, 'expectedMin'));

$totalWorkedMin = array_sum(array_column($daily, 'workedMin'));
$totalBreakMin  = array_sum(array_map(static fn($d) => (int)($d['breakMin'] ?? 0), $daily));

// EFETIVO por dia (canônico, helpers.php): presença CHEIA = work + break — o
// intervalo conta como tempo trabalhado. Inclui breaks pendentes (workedMin acima
// só soma registros aprovados). Usado no totalizador de saldo.
$totalEffectiveWorkedMin = 0;
if ($selectedTeacher) {
    foreach ($daily as $date => $info) {
        // Fora da janela de contagem: não soma (já zerado).
        if ($date < $teacherStartDate || $date > $today) continue;
        // Skip dias afastamento pago (já zerado em expectedMin)
        if ((int)$info['expectedMin'] === 0 && empty($info['items'])) continue;
        $eff = calculate_effective_worked_minutes($pdo, (int)$selectedTeacher['id'], $date);
        $totalEffectiveWorkedMin += $eff;
        $daily[$date]['effectiveMin'] = $eff;
    }
}
$saldo = $totalEffectiveWorkedMin - $totalExpectedMin;

// ============================================================================
// DERIVADOS: faltas, pontualidade, horas extras aprovadas
// ============================================================================
// $teacherStartDate e $today já definidos acima (antes da janela de contagem).
$tolMin = isset($_GET['tol_min']) ? max(0, min(60, (int)$_GET['tol_min'])) : 10;

$totalAbsences = 0;
$daysWorked = 0;
$daysPunctual = 0;

foreach ($daily as $date => $info) {
    $hasItems = !empty($info['items']);
    $expMin = (int)$info['expectedMin'];
    $isAbonado = leave_day_is_excused($leavesByDay[$date] ?? []);

    if (!$hasItems && $expMin > 0 && $date <= $today && $date >= $teacherStartDate && !$isAbonado) {
        $totalAbsences++;
    }
    if ($hasItems) {
        $daysWorked++;
        if ($info['startTime'] && $info['firstInMin'] !== null) {
            $startMin = (int)substr($info['startTime'], 0, 2) * 60 + (int)substr($info['startTime'], 3, 2);
            if ($info['firstInMin'] <= $startMin + $tolMin) $daysPunctual++;
        }
    }
}
$punctualPct = $daysWorked > 0 ? round(($daysPunctual / $daysWorked) * 100, 1) : null;

// Comparação com período anterior (só pra month fechado, não range custom)
$prevTotalWorked = null;
$prevTotalExpected = null;
if ($selectedTeacher && !$useCustomRange) {
    $prevStart = (clone $periodStart)->modify('first day of previous month');
    $prevEnd   = (clone $prevStart)->modify('last day of this month');
    try {
        // Período anterior: soma o EFETIVO por dia (com desconto automático de intervalo
        // previsto quando aplicável). Loop em PHP para reutilizar o helper canônico.
        $prevTotalWorked = 0;
        $cursor = clone $prevStart;
        while ($cursor <= $prevEnd) {
            $prevTotalWorked += calculate_effective_worked_minutes($pdo, (int)$selectedTeacher['id'], $cursor->format('Y-m-d'));
            $cursor = $cursor->modify('+1 day');
        }
    } catch (Throwable $_) {}
}

// ============================================================================
// EXPORTAÇÃO PDF
// ============================================================================
$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['teacher_id']) && !$selectedTeacher) {
    $feedback = '<div class="alert alert-warning">Colaborador não encontrado ou sem permissão.</div>';
}

$schools = [];
if (is_network_admin($admin)) {
    $schools = $pdo->query("SELECT id, name FROM schools WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================================
// GERAÇÃO EM LOTE (resumo consolidado de todos os colaboradores do escopo)
// ============================================================================
if (isset($_GET['batch']) && in_array($_GET['batch'], ['pdf', 'xlsx'], true)) {
  try {
    $engineFile = __DIR__ . '/_report_engine.php';
    $tplFile    = __DIR__ . '/_tpl_reports_batch_pdf.php';
    $autoload   = __DIR__ . '/../../vendor/autoload.php';
    foreach (['_report_engine.php' => $engineFile, '_tpl_reports_batch_pdf.php' => $tplFile, 'vendor/autoload.php' => $autoload] as $label => $path) {
        if (!file_exists($path)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Geração em lote indisponível: arquivo ausente no servidor ($label). Faça o deploy completo dos arquivos novos.";
            exit;
        }
    }
    require_once $engineFile;
    require_once $autoload;
    @set_time_limit(120);

    $scopeLabel = 'Rede completa';
    $scopeSlug = 'rede';
    if (!is_network_admin($admin)) {
        $scopeLabel = 'Escola: ' . ($admin['school_name'] ?? 'sua escola');
        $scopeSlug = 'escola';
    } elseif ($schoolFilter > 0) {
        foreach ($schools as $s) {
            if ((int)$s['id'] === $schoolFilter) { $scopeLabel = 'Escola: ' . $s['name']; $scopeSlug = 'escola_' . $schoolFilter; break; }
        }
    }
    $adminName = $admin['name'] ?? ($admin['username'] ?? '-');

    $rows = [];
    foreach ($teachers as $t) {
        $tot = report_monthly_totals($pdo, $t, $periodStart, $periodEnd, $tolMin ?? 10);
        $rows[] = ['name' => $t['name'], 'cpf' => $t['cpf'] ?? ''] + $tot;
    }

    $fnameBase = 'mensal_lote_' . $scopeSlug . '_' . $periodStart->format('Ymd') . '_' . $periodEnd->format('Ymd');

    if ($_GET['batch'] === 'pdf') {
        ob_start();
        include __DIR__ . '/_tpl_reports_batch_pdf.php';
        $html = ob_get_clean();
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream($fnameBase . '.pdf', ['Attachment' => false]);
        exit;
    }

    // XLSX
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Resumo Mensal');
    $lastCol = 'D';
    $sheet->setCellValue('A1', 'Relatório Mensal — Resumo Consolidado');
    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A2', $periodStart->format('d/m/Y') . ' a ' . $periodEnd->format('d/m/Y')
        . '  ·  ' . $scopeLabel . '  ·  ' . count($rows) . ' colaborador(es)  ·  Gerado em ' . date('d/m/Y H:i'));
    $sheet->mergeCells("A2:{$lastCol}2");
    $headRow = 4;
    $sheet->fromArray(['Colaborador', 'CPF', 'Horas Trabalhadas', 'Faltas'], null, 'A' . $headRow);
    $r0 = $headRow + 1;
    $matrix = [];
    foreach ($rows as $r) {
        $matrix[] = [$r['name'], mask_cpf((string)($r['cpf'] ?? '')), report_hours_label((int)$r['worked_min']), (int)$r['absences']];
    }
    if ($matrix) $sheet->fromArray($matrix, null, 'A' . $r0, true);
    $dataEnd = (count($rows) > 0) ? ($r0 + count($rows) - 1) : ($r0 - 1);
    $totRow = $dataEnd + 1;
    $sumWorked   = array_sum(array_map(fn($r) => (int)$r['worked_min'], $rows));
    $sumAbsences = array_sum(array_map(fn($r) => (int)$r['absences'], $rows));
    $sheet->fromArray(['TOTAL (' . count($rows) . ')', '', report_hours_label($sumWorked), $sumAbsences], null, 'A' . $totRow, true);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('1F3864');
    $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('64748B');
    $sheet->getRowDimension(1)->setRowHeight(22);
    $hdr = $sheet->getStyle("A{$headRow}:{$lastCol}{$headRow}");
    $hdr->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $hdr->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('2F5597');
    $hdr->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension($headRow)->setRowHeight(20);
    $sheet->getColumnDimension('A')->setWidth(34);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(10);
    if ($totRow >= $r0) {
        $sheet->getStyle("B{$r0}:B{$totRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$r0}:C{$totRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("D{$r0}:D{$totRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }
    for ($i = 0; $i < count($rows); $i++) {
        $rr = $r0 + $i;
        if ((int)$rows[$i]['absences'] > 0) $sheet->getStyle("D{$rr}")->getFont()->getColor()->setRGB('C00000');
        if ($i % 2 === 1) $sheet->getStyle("A{$rr}:{$lastCol}{$rr}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F3F6FB');
    }
    $tot = $sheet->getStyle("A{$totRow}:{$lastCol}{$totRow}");
    $tot->getFont()->setBold(true);
    $tot->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
    if ($totRow >= $headRow) {
        $sheet->getStyle("A{$headRow}:{$lastCol}{$totRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('C9D3E0');
    }
    $sheet->freezePane('A' . $r0);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fnameBase . '.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
  } catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo 'Falha na geração em lote: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    exit;
  }
}

if ($selectedTeacher && isset($_GET['export']) && $_GET['export'] === 'pdf') {
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
        $dompdf->stream('relatorio_' . $selectedTeacher['id'] . '_' . $month . '.pdf');
        exit;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Exportação PDF indisponível. Instale: composer require dompdf/dompdf";
        exit;
    }
}

// ============================================================================
// EXPORTAÇÃO CSV
// ============================================================================
if ($selectedTeacher && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'relatorio_' . $selectedTeacher['id'] . '_' . $periodStart->format('Ymd') . '_' . $periodEnd->format('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 (Excel BR)
    fputcsv($out, ['Colaborador', $selectedTeacher['name']], ';');
    fputcsv($out, ['Período', $periodStart->format('d/m/Y') . ' a ' . $periodEnd->format('d/m/Y')], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Data', 'Dia', 'Esperado', 'Trabalhado (líquido)', 'Saldo', 'Entrada', 'Saída', 'Intervalos (qtd)', 'Intervalos (tempo)', 'Detalhe intervalos', 'Justificativa'], ';');
    $weekdayNames = [0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'];
    foreach ($daily as $d => $v) {
        $entradaDia = '-'; $saidaDia = '-'; $breakQtd = 0; $breakTempo = '-'; $breakDetalhes = '-';
        if (!empty($v['items'])) {
            $itemsForConsolidation = array_map(function($it) use ($selectedTeacher) {
                $it['teacher_id'] = (int)$selectedTeacher['id'];
                $it['teacher_name'] = $selectedTeacher['name'];
                return $it;
            }, $v['items']);
            $dayCons = consolidate_attendance_by_day($itemsForConsolidation);
            $dayCon = $dayCons[0] ?? null;
            if ($dayCon) {
                $entradaDia = $dayCon['check_in']  ? substr($dayCon['check_in'], 0, 5)  : '-';
                $saidaDia   = $dayCon['check_out'] ? substr($dayCon['check_out'], 0, 5) : '-';
                $breakQtd   = count($dayCon['breaks']);
                $breakTempo = $breakQtd > 0 ? format_duration_minutes((int)$dayCon['total_break_minutes']) : '-';
                if ($breakQtd > 0) {
                    $parts = [];
                    foreach ($dayCon['breaks'] as $bb) {
                        $bs = $bb['start'] ? substr($bb['start'], 0, 5) : '-';
                        $be = $bb['end']   ? substr($bb['end'], 0, 5)   : 'aberto';
                        $parts[] = $bs . '→' . $be;
                    }
                    $breakDetalhes = implode(' | ', $parts);
                }
            }
        }
        $justs = [];
        foreach ($v['items'] as $it) {
            if (!empty($it['manual_reason_id'])) {
                $justs[] = ($it['manual_reason_name'] ?? 'Manual') . (!empty($it['manual_reason_text']) ? ' - ' . $it['manual_reason_text'] : '');
            }
        }
        $bal = (int)$v['workedMin'] - (int)$v['expectedMin'];
        fputcsv($out, [
            (new DateTime($d))->format('d/m/Y'),
            $weekdayNames[$v['weekdayIdx']] ?? '',
            minutes_to_hhmm((int)$v['expectedMin']),
            minutes_to_hhmm(max(0, (int)$v['workedMin'] - (int)($v['breakMin'] ?? 0))),
            minutes_to_hhmm($bal),
            $entradaDia,
            $saidaDia,
            $breakQtd > 0 ? $breakQtd : '0',
            $breakTempo,
            $breakDetalhes,
            implode(' | ', $justs),
        ], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['Total esperado', minutes_to_hhmm($totalExpectedMin)], ';');
    fputcsv($out, ['Total trabalhado (líquido)', minutes_to_hhmm(max(0, (int)$totalWorkedMin - (int)$totalBreakMin))], ';');
    fputcsv($out, ['Saldo', minutes_to_hhmm($saldo)], ';');
    fputcsv($out, ['Faltas', $totalAbsences], ';');
    fclose($out);
    exit;
}

function build_url_with(array $extra): string {
    $q = $_GET;
    foreach ($extra as $k => $v) $q[$k] = $v;
    return 'reports.php?' . http_build_query($q);
}

// URL para geração em lote: carrega filtros atuais, remove teacher/export
function batch_url_reports(array $extra): string {
    $q = $_GET;
    unset($q['teacher_id'], $q['export']);
    foreach ($extra as $k => $v) $q[$k] = $v;
    return 'reports.php?' . http_build_query($q);
}

// Rótulo dinâmico do botão de lote
$batchLabel = 'Gerar resumo de todos os colaboradores';
if (is_network_admin($admin)) {
    $batchLabel = 'Gerar resumo de toda a rede';
    if ($schoolFilter > 0) {
        foreach ($schools as $s) {
            if ((int)$s['id'] === $schoolFilter) { $batchLabel = 'Gerar resumo da ' . $s['name']; break; }
        }
    }
}

$weekdayNames = [0 => 'Domingo', 1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];
$weekdayShort = [0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'];

// Pré-calcula subtotais por semana
$weekly = [];
foreach ($daily as $d => $info) {
    $weekKey = (new DateTime($d))->format('o-W'); // ISO 8601 ano-semana
    $weekly[$weekKey] = $weekly[$weekKey] ?? ['expected' => 0, 'worked' => 0, 'break' => 0, 'absences' => 0];
    $weekly[$weekKey]['expected'] += (int)$info['expectedMin'];
    $weekly[$weekKey]['worked']   += (int)$info['workedMin'];
    $weekly[$weekKey]['break']    += (int)($info['breakMin'] ?? 0);
}

$pageTitle = 'Relatório Mensal';
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
        :root {
            --rep-bg: #f7f9fc;
            --rep-border: #e2e8f0;
            --rep-muted: #64748b;
            --rep-accent: #0162cc;
            --rep-success: #059669;
            --rep-danger: #dc2626;
            --rep-warning: #d97706;
        }
        body { background: var(--rep-bg); }

        /* KPI strip */
        .rep-kpi-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .rep-kpi {
            background: white; border: 1px solid var(--rep-border); border-radius: 14px;
            padding: 1rem 1.25rem; position: relative; overflow: hidden;
        }
        .rep-kpi::before { content: ''; position: absolute; inset: 0 auto 0 0; width: 4px; background: var(--rep-accent); }
        .rep-kpi.rep-kpi-success::before { background: var(--rep-success); }
        .rep-kpi.rep-kpi-danger::before  { background: var(--rep-danger); }
        .rep-kpi.rep-kpi-warning::before { background: var(--rep-warning); }
        .rep-kpi-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--rep-muted); margin-bottom: 0.25rem; }
        .rep-kpi-value { font-size: 1.85rem; font-weight: 700; line-height: 1; color: #0f172a; font-feature-settings: "tnum"; }
        .rep-kpi-sub   { font-size: 0.78rem; color: var(--rep-muted); margin-top: 0.25rem; }

        /* Page header */
        .rep-page-header {
            background: linear-gradient(135deg, #ffffff, #f1f5f9);
            border: 1px solid var(--rep-border); border-radius: 14px;
            padding: 1.25rem 1.5rem; margin-bottom: 1.25rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
        }
        .rep-page-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--rep-accent), #1e40af);
            color: white; border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.4rem; box-shadow: 0 4px 12px rgba(1, 98, 204, 0.25);
        }

        /* Filtro card */
        .rep-filter {
            background: white; border: 1px solid var(--rep-border); border-radius: 14px;
            padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;
        }

        /* Empty state */
        .rep-empty {
            background: white; border: 2px dashed var(--rep-border); border-radius: 14px;
            padding: 3rem 2rem; text-align: center; margin-top: 1.5rem;
        }
        .rep-empty-icon {
            width: 72px; height: 72px; margin: 0 auto 1rem;
            background: #f1f5f9; color: var(--rep-accent);
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center; font-size: 2rem;
        }

        /* Tabela */
        .rep-table { background: white; border: 1px solid var(--rep-border); border-radius: 14px; overflow: hidden; }
        .rep-table table { margin: 0; }
        .rep-table .rep-row-today { background: #eff6ff !important; box-shadow: inset 4px 0 0 var(--rep-accent); }
        .rep-table .rep-row-weekend { background: #fafafa; }
        .rep-table .rep-row-leave   { background: #ecfdf5; }
        .rep-table .rep-row-absence td:first-child { box-shadow: inset 4px 0 0 var(--rep-danger); }
        .rep-week-divider td {
            background: #f8fafc !important; font-weight: 600;
            font-size: 0.82rem; color: var(--rep-muted);
            border-top: 2px solid #e2e8f0;
        }

        .rep-weekday { font-size: 0.78rem; color: var(--rep-muted); }
        .rep-punctual-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 0.78rem; padding: 2px 8px; border-radius: 999px; }
        .rep-punctual-yes { background: #ecfdf5; color: var(--rep-success); }
        .rep-punctual-no  { background: #fef2f2; color: var(--rep-danger); }

        /* Sparkline */
        .rep-sparkline { height: 36px; vertical-align: middle; }

        @media print {
            .rep-filter, .navbar, .rep-actions, footer { display: none !important; }
            .rep-table { box-shadow: none; border: none; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>

    <div class="container-fluid admin-content">
        <?= $feedback ?>

        <!-- HEADER -->
        <div class="rep-page-header">
            <div class="d-flex align-items-center gap-3">
                <div class="rep-page-icon"><i class="bi bi-calendar-month"></i></div>
                <div>
                    <h1 class="h3 mb-0"><?= esc($pageTitle) ?></h1>
                    <p class="text-muted mb-0 small">
                        <?= esc($periodStart->format('d/m/Y')) ?> → <?= esc($periodEnd->format('d/m/Y')) ?>
                        <?php if ($selectedTeacher): ?>
                            · <strong><?= esc($selectedTeacher['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php if ($selectedTeacher): ?>
            <div class="d-flex gap-2 flex-wrap rep-actions">
                <a href="<?= esc(build_url_with(['export' => 'pdf'])) ?>" target="_blank" rel="noopener" class="btn btn-danger btn-sm">
                    <i class="bi bi-filetype-pdf me-1"></i>PDF
                </a>
                <a href="<?= esc(build_url_with(['export' => 'csv'])) ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-filetype-csv me-1"></i>CSV
                </a>
                <a href="reports_financial.php?teacher_id=<?= (int)$selectedTeacher['id'] ?>&month=<?= esc($month) ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-currency-dollar me-1"></i>Financeiro
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- FILTROS -->
        <form class="rep-filter" method="get" autocomplete="off" id="rep-form">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-3">
                    <label class="form-label small fw-semibold">Colaborador</label>
                    <input type="text" id="teacher-search" class="form-control"
                           list="teachers-datalist" placeholder="Digite o nome…"
                           value="<?= $selectedTeacher ? esc($selectedTeacher['name']) : '' ?>"
                           autocomplete="off" required>
                    <input type="hidden" id="teacher_id" name="teacher_id" value="<?= $teacherId ?: '' ?>">
                    <datalist id="teachers-datalist">
                        <?php foreach ($teachers as $t): ?>
                            <option data-id="<?= (int)$t['id'] ?>" value="<?= esc($t['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold" for="flt-month">Mês</label>
                    <input type="month" name="month" id="flt-month" class="form-control" value="<?= esc($month) ?>" data-auto-submit>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold">— OU —</label>
                    <input type="date" name="date_from" id="flt-date-from" class="form-control" value="<?= esc($dateFrom) ?>" placeholder="De" aria-label="Data inicial">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold" for="flt-date-to">Até</label>
                    <input type="date" name="date_to" id="flt-date-to" class="form-control" value="<?= esc($dateTo) ?>">
                </div>
                <?php if (is_network_admin($admin)): ?>
                <div class="col-12 col-md-6 col-lg-2">
                    <label class="form-label small fw-semibold">Escola</label>
                    <select name="school" class="form-select" data-auto-submit>
                        <option value="">Todas</option>
                        <?php foreach ($schools as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $schoolFilter === (int)$s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 col-lg-1">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-funnel me-1"></i>Gerar
                    </button>
                </div>
            </div>
            <div class="text-muted small mt-2">
                <i class="bi bi-info-circle me-1"></i>
                Use <kbd>Ctrl</kbd>+<kbd>K</kbd> para focar na busca. Range customizado tem prioridade sobre o mês.
            </div>

            <!-- GERAÇÃO EM LOTE -->
            <div class="mt-3 pt-3 border-top d-flex flex-wrap align-items-center gap-2">
                <span class="small fw-semibold text-muted me-1">
                    <i class="bi bi-collection me-1"></i><?= esc($batchLabel) ?>
                    <span class="badge bg-secondary ms-1"><?= count($teachers) ?></span>
                </span>
                <a href="<?= esc(batch_url_reports(['batch' => 'pdf'])) ?>" target="_blank" rel="noopener" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-filetype-pdf me-1"></i>PDF de todos
                </a>
                <a href="<?= esc(batch_url_reports(['batch' => 'xlsx'])) ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel de todos
                </a>
                <span class="text-muted small ms-1">Resumo: 1 linha por colaborador (totais do período).</span>
            </div>
        </form>

        <?php if (!$selectedTeacher && !$teacherId): ?>
            <!-- EMPTY STATE -->
            <div class="rep-empty">
                <div class="rep-empty-icon"><i class="bi bi-person-bounding-box"></i></div>
                <h4 class="mb-2">Selecione um colaborador para começar</h4>
                <p class="text-muted mb-3">Digite o nome no campo acima ou escolha da lista. Você pode filtrar por mês fechado ou por um intervalo customizado.</p>
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <a href="reports_insights.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-bar-chart-line me-1"></i>Ver rankings de toda equipe
                    </a>
                </div>
            </div>
        <?php elseif ($selectedTeacher): ?>

            <!-- KPI STRIP -->
            <div class="rep-kpi-strip">
                <div class="rep-kpi">
                    <div class="rep-kpi-label">Esperadas</div>
                    <div class="rep-kpi-value"><?= esc(minutes_to_hhmm($totalExpectedMin)) ?></div>
                    <div class="rep-kpi-sub">jornada prevista</div>
                </div>
                <?php
                  // "Trabalhadas" exibido = LÍQUIDO (presença − intervalos). O Saldo ao lado
                  // segue o modelo "cheio vs cheio" (o intervalo conta como trabalhado): logo
                  // Trabalhadas (líquido) + Intervalo = presença, e Saldo = presença − previsto.
                  $totalNetWorkedMin = max(0, (int)$totalWorkedMin - (int)$totalBreakMin);
                ?>
                <div class="rep-kpi rep-kpi-success">
                    <div class="rep-kpi-label">Trabalhadas</div>
                    <div class="rep-kpi-value"><?= esc(minutes_to_hhmm($totalNetWorkedMin)) ?></div>
                    <div class="rep-kpi-sub">líquido (sem intervalo) · aprovados</div>
                </div>
                <?php if ($totalBreakMin > 0): ?>
                <div class="rep-kpi">
                    <div class="rep-kpi-label">Intervalo</div>
                    <div class="rep-kpi-value"><?= esc(minutes_to_hhmm((int)$totalBreakMin)) ?></div>
                    <div class="rep-kpi-sub">não somado às trabalhadas</div>
                </div>
                <?php endif; ?>
                <?php if ($totalBreakMin > 0): ?>
                <div class="rep-kpi">
                    <div class="rep-kpi-label">Presença</div>
                    <div class="rep-kpi-value"><?= esc(minutes_to_hhmm((int)$totalEffectiveWorkedMin)) ?></div>
                    <div class="rep-kpi-sub">trabalhadas + intervalo · base do saldo</div>
                </div>
                <?php endif; ?>
                <div class="rep-kpi <?= $saldo >= 0 ? 'rep-kpi-success' : '' ?>">
                    <div class="rep-kpi-label"><?= $saldo < 0 ? 'Horas a compensar' : 'Saldo' ?></div>
                    <div class="rep-kpi-value <?= $saldo > 0 ? 'text-success' : '' ?>">
                        <?= $saldo < 0 ? esc(minutes_to_hhmm(abs($saldo))) : (($saldo > 0 ? '+' : '') . esc(minutes_to_hhmm($saldo))) ?>
                    </div>
                    <?php if ($prevTotalWorked !== null): ?>
                        <?php $diff = $totalWorkedMin - $prevTotalWorked; ?>
                        <div class="rep-kpi-sub">
                            vs mês anterior:
                            <strong class="<?= $diff >= 0 ? 'text-success' : '' ?>">
                                <?= ($diff >= 0 ? '+' : '') . minutes_to_hhmm($diff) ?>
                            </strong>
                        </div>
                    <?php else: ?>
                        <div class="rep-kpi-sub">trabalhado vs. previsto</div>
                    <?php endif; ?>
                </div>
                <div class="rep-kpi rep-kpi-danger">
                    <div class="rep-kpi-label">Faltas</div>
                    <div class="rep-kpi-value <?= $totalAbsences > 0 ? 'text-danger' : 'text-success' ?>"><?= (int)$totalAbsences ?></div>
                    <div class="rep-kpi-sub">dias úteis sem ponto</div>
                </div>
                <?php if ($punctualPct !== null): ?>
                <div class="rep-kpi rep-kpi-warning">
                    <div class="rep-kpi-label">Pontualidade</div>
                    <div class="rep-kpi-value"><?= esc(number_format($punctualPct, 1, ',', '.')) ?>%</div>
                    <div class="rep-kpi-sub"><?= $daysPunctual ?>/<?= $daysWorked ?> dias (tol. <?= (int)$tolMin ?>min)</div>
                </div>
                <?php endif; ?>
            </div>

            <p class="text-muted small mt-2 mb-2">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Trabalhado</strong> é o tempo líquido (sem intervalo). O <strong>saldo</strong> usa a <strong>presença</strong> (trabalhado + intervalo) comparada ao esperado — o intervalo é um direito do colaborador e <strong>nunca gera déficit</strong>.
            </p>

            <!-- TABELA -->
            <div class="rep-table">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width:130px;">Data</th>
                                <th scope="col" style="width:100px;">Esperado</th>
                                <th scope="col" style="width:100px;">Trabalhado</th>
                                <th scope="col" style="width:90px;">Saldo</th>
                                <?php if ($mode === 'time'): ?>
                                    <th scope="col" style="width:110px;">Pontualidade</th>
                                <?php elseif ($mode === 'hours'): ?>
                                    <th scope="col" style="width:110px;">Cumprimento</th>
                                <?php endif; ?>
                                <th scope="col">Pontos</th>
                                <th scope="col" style="width:200px;">Justificativa / Licença</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $lastWeek = null;
                            $weekExpAccum = 0; $weekWorkedAccum = 0;
                            ?>
                            <?php foreach ($daily as $date => $info):
                                $weekKey = (new DateTime($date))->format('o-W');

                                // Linha divisória de semana
                                if ($lastWeek !== null && $lastWeek !== $weekKey):
                                    $w = $weekly[$lastWeek] ?? ['expected' => 0, 'worked' => 0, 'break' => 0];
                                    $bal = $w['worked'] - $w['expected'];
                                    $wNet = max(0, (int)$w['worked'] - (int)($w['break'] ?? 0));
                            ?>
                                <tr class="rep-week-divider">
                                    <td><i class="bi bi-bookmark-fill me-1 text-muted"></i>Subtotal semana <?= esc($lastWeek) ?></td>
                                    <td><?= esc(minutes_to_hhmm($w['expected'])) ?></td>
                                    <td><?= esc(minutes_to_hhmm($wNet)) ?></td>
                                    <td class="<?= $bal > 0 ? 'text-success' : 'text-muted' ?>"><?= ($bal >= 0 ? '+' : '') . minutes_to_hhmm($bal) ?></td>
                                    <td colspan="<?= ($mode === 'time' || $mode === 'hours') ? 3 : 2 ?>"></td>
                                </tr>
                            <?php endif; $lastWeek = $weekKey; ?>

                            <?php
                                $balDay = (int)$info['workedMin'] - (int)$info['expectedMin'];
                                $hasItems = !empty($info['items']);
                                $w = $info['weekdayIdx'];
                                $isWeekend = ($w === 0 || $w === 6);
                                $isToday = ($date === $today);
                                $isAbonado = leave_day_is_excused($leavesByDay[$date] ?? []);
                                $hasLeave  = !empty($leavesByDay[$date]);
                                $holidayInfo = $info['holiday'] ?? null; // feriado / ponto facultativo
                                $isFalta = !$hasItems && (int)$info['expectedMin'] > 0 && $date <= $today && $date >= $teacherStartDate && !$isAbonado && !$holidayInfo;

                                $rowClasses = [];
                                if ($isToday)   $rowClasses[] = 'rep-row-today';
                                if ($isWeekend) $rowClasses[] = 'rep-row-weekend';
                                if ($isAbonado) $rowClasses[] = 'rep-row-leave';
                                if ($holidayInfo) $rowClasses[] = 'rep-row-leave';
                                if ($isFalta)   $rowClasses[] = 'rep-row-absence';

                                // Pontualidade do dia
                                $punctual = null;
                                if ($mode === 'time' && $info['startTime'] && $info['firstInMin'] !== null) {
                                    $startMin = (int)substr($info['startTime'], 0, 2) * 60 + (int)substr($info['startTime'], 3, 2);
                                    $punctual = $info['firstInMin'] <= $startMin + $tolMin;
                                }
                            ?>
                                <tr class="<?= esc(implode(' ', $rowClasses)) ?>">
                                    <td>
                                        <?= esc((new DateTime($date))->format('d/m/Y')) ?>
                                        <div class="rep-weekday"><?= esc($weekdayShort[$w] ?? '') ?><?= $isToday ? ' · <strong>hoje</strong>' : '' ?></div>
                                        <?php if ($holidayInfo): ?>
                                            <div class="mt-1"><span class="badge bg-danger-subtle text-danger-emphasis border border-danger"><i class="bi bi-flag me-1"></i><?= esc($holidayInfo['name'] ?? 'Feriado') ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= esc(minutes_to_hhmm((int)$info['expectedMin'])) ?></td>
                                    <td>
                                        <?= esc(minutes_to_hhmm(max(0, (int)$info['workedMin'] - (int)($info['breakMin'] ?? 0)))) ?>
                                        <?php if ((int)($info['breakMin'] ?? 0) > 0): ?>
                                            <div class="text-muted small" title="Presença (com intervalo) — base do saldo">pres. <?= esc(minutes_to_hhmm((int)$info['workedMin'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?= $balDay > 0 ? 'text-success' : 'text-muted' ?>">
                                        <?= ($balDay > 0 ? '+' : '') . esc(minutes_to_hhmm($balDay)) ?>
                                    </td>
                                    <?php if ($mode === 'time'): ?>
                                        <td>
                                            <?php if ($punctual === true): ?>
                                                <span class="rep-punctual-badge rep-punctual-yes" title="Entrada dentro da tolerância">
                                                    <i class="bi bi-check-circle-fill"></i>Pontual
                                                </span>
                                            <?php elseif ($punctual === false): ?>
                                                <span class="rep-punctual-badge rep-punctual-no" title="Entrada após tolerância de <?= (int)$tolMin ?>min">
                                                    <i class="bi bi-clock-fill"></i>Atraso
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php elseif ($mode === 'hours'): ?>
                                        <td>
                                            <?php
                                                $expDay = (int)$info['expectedMin'];
                                                $workDay = (int)$info['workedMin'];
                                                if ($expDay > 0) {
                                                    $pctDay = (int)round(min(100, ($workDay / $expDay) * 100));
                                                    $cumprClass = $workDay >= $expDay ? 'rep-punctual-yes' : 'rep-punctual-no';
                                                    $cumprIcon  = $workDay >= $expDay ? 'check-circle-fill' : 'exclamation-triangle-fill';
                                            ?>
                                                <span class="rep-punctual-badge <?= $cumprClass ?>" title="Trabalhado <?= esc(minutes_to_hhmm($workDay)) ?> de <?= esc(minutes_to_hhmm($expDay)) ?>">
                                                    <i class="bi bi-<?= $cumprIcon ?>"></i><?= $pctDay ?>%
                                                </span>
                                            <?php } else { ?>
                                                <span class="text-muted small">—</span>
                                            <?php } ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($hasItems):
                                            // Consolida por dia para mostrar 1 linha principal (entrada/saída do dia)
                                            // e sub-linhas indentadas com cada intervalo.
                                            $itemsForConsolidation = array_map(function($it) use ($selectedTeacher) {
                                                $it['teacher_id'] = (int)$selectedTeacher['id'];
                                                $it['teacher_name'] = $selectedTeacher['name'];
                                                return $it;
                                            }, $info['items']);
                                            $dayCons = consolidate_attendance_by_day($itemsForConsolidation);
                                            $dayCon = $dayCons[0] ?? null;
                                            if ($dayCon):
                                                $dayIn  = $dayCon['check_in']  ? substr($dayCon['check_in'], 0, 5)  : '—';
                                                $dayOut = $dayCon['check_out'] ? substr($dayCon['check_out'], 0, 5) : '—';
                                                // Detecta método e edição usando o primeiro work
                                                $primaryItem = null;
                                                foreach ($info['items'] as $it) {
                                                    if (($it['record_type'] ?? 'work') === 'work') { $primaryItem = $it; break; }
                                                }
                                                if ($primaryItem === null) $primaryItem = $info['items'][0];
                                                $methodLabels = ['cpf' => 'CPF', 'pin' => 'CPF', 'foto' => 'Foto', 'face' => 'Facial', 'kiosk_face' => 'Facial (Quiosque)', 'manual' => 'Manual'];
                                                $methodLabel = $methodLabels[strtolower((string)($primaryItem['method'] ?? ''))] ?? ($primaryItem['method'] ?? '-');
                                            ?>
                                                <div class="mb-1">
                                                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                                        <i class="bi bi-box-arrow-in-right me-1"></i><?= esc($dayIn) ?>
                                                    </span>
                                                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                                        <i class="bi bi-box-arrow-left me-1"></i><?= esc($dayOut) ?>
                                                    </span>
                                                    <span class="badge bg-light text-muted border"><?= esc($methodLabel) ?></span>
                                                    <?php if (isset($primaryItem['face_match_confidence']) && $primaryItem['face_match_confidence'] !== null && $primaryItem['face_match_confidence'] !== ''): ?>
                                                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle" title="Confiança do reconhecimento facial no quiosque">
                                                            <i class="bi bi-person-check me-1"></i><?= (int)round((float)$primaryItem['face_match_confidence'] * 100) ?>%
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($primaryItem['data_edicao'])): ?>
                                                        <span class="badge bg-info-subtle text-info-emphasis border" title="Editado por <?= esc($primaryItem['edited_by_username'] ?? '-') ?> em <?= esc(date('d/m/Y H:i', strtotime($primaryItem['data_edicao']))) ?>: <?= esc($primaryItem['motivo_edicao'] ?? '-') ?>">
                                                            <i class="bi bi-pencil-square"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($dayCon['breaks'])): ?>
                                                        <span class="badge bg-secondary-subtle text-secondary-emphasis border" title="<?= count($dayCon['breaks']) ?> intervalo(s), total <?= esc(format_duration_minutes((int)$dayCon['total_break_minutes'])) ?>">
                                                            <i class="bi bi-pause-circle me-1"></i><?= count($dayCon['breaks']) ?> int. (<?= esc(format_duration_minutes((int)$dayCon['total_break_minutes'])) ?>)
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($dayCon['breaks'])): ?>
                                                    <ul class="list-unstyled mb-0 small text-muted" style="padding-left: 1.25rem;">
                                                        <?php foreach ($dayCon['breaks'] as $bi => $bb):
                                                            $bs = $bb['start'] ? substr($bb['start'], 0, 5) : '—';
                                                            $be = $bb['end']   ? substr($bb['end'], 0, 5)   : '<em>aberto</em>';
                                                            $bd = $bb['duration_minutes'] !== null ? format_duration_minutes((int)$bb['duration_minutes']) : '—';
                                                        ?>
                                                            <li>↳ Intervalo <?= ($bi + 1) ?>: <?= esc($bs) ?> → <?= $be ?> <span class="text-muted">(<?= esc($bd) ?>)</span></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php elseif ($isAbonado): ?>
                                            <?php foreach ($leavesByDay[$date] as $lv): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                                    <i class="bi bi-calendar-check me-1"></i>Abonado<?= !empty($lv['leave_type_name']) ? ' · ' . esc($lv['leave_type_name']) : '' ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php elseif ($isFalta): ?>
                                            <span class="badge bg-danger text-white">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i>FALTA
                                            </span>
                                            <?php if ($hasLeave): ?>
                                                <?php foreach ($leavesByDay[$date] as $lv): ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="Afastamento registrado que NÃO abona a falta">
                                                        <i class="bi bi-info-circle me-1"></i>Justificada · <?= esc($lv['leave_type_name'] ?? 'Afastamento') ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php elseif ($holidayInfo): ?>
                                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger">
                                                <i class="bi bi-flag me-1"></i><?= esc($holidayInfo['name'] ?? 'Feriado') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
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
                                        echo $just ? esc(implode(' · ', $just)) : '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Subtotal da última semana -->
                            <?php if ($lastWeek !== null && isset($weekly[$lastWeek])):
                                $w = $weekly[$lastWeek];
                                $bal = $w['worked'] - $w['expected'];
                                $wNet = max(0, (int)$w['worked'] - (int)($w['break'] ?? 0)); ?>
                                <tr class="rep-week-divider">
                                    <td><i class="bi bi-bookmark-fill me-1 text-muted"></i>Subtotal semana <?= esc($lastWeek) ?></td>
                                    <td><?= esc(minutes_to_hhmm($w['expected'])) ?></td>
                                    <td><?= esc(minutes_to_hhmm($wNet)) ?></td>
                                    <td class="<?= $bal > 0 ? 'text-success' : 'text-muted' ?>"><?= ($bal >= 0 ? '+' : '') . minutes_to_hhmm($bal) ?></td>
                                    <td colspan="<?= ($mode === 'time' || $mode === 'hours') ? 3 : 2 ?>"></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td>TOTAL</td>
                                <td><?= esc(minutes_to_hhmm($totalExpectedMin)) ?></td>
                                <td>
                                    <?= esc(minutes_to_hhmm(max(0, (int)$totalWorkedMin - (int)$totalBreakMin))) ?>
                                    <?php if ((int)$totalBreakMin > 0): ?><div class="text-muted small fw-normal">pres. <?= esc(minutes_to_hhmm((int)$totalEffectiveWorkedMin)) ?></div><?php endif; ?>
                                </td>
                                <td class="<?= $saldo > 0 ? 'text-success' : 'text-muted' ?>">
                                    <?= ($saldo >= 0 ? '+' : '') . esc(minutes_to_hhmm($saldo)) ?>
                                </td>
                                <td colspan="<?= ($mode === 'time' || $mode === 'hours') ? 3 : 2 ?>"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        <?php elseif ($teacherId): ?>
            <div class="alert alert-warning mt-3">Nenhum colaborador selecionado ou encontrado.</div>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Typeahead colaborador
    (function() {
      const search = document.getElementById('teacher-search');
      const hidden = document.getElementById('teacher_id');
      const datalist = document.getElementById('teachers-datalist');
      if (!search || !hidden || !datalist) return;
      const map = {};
      Array.from(datalist.options).forEach(o => { map[o.value.toLowerCase()] = o.dataset.id; });
      function syncId() {
        const v = search.value.trim().toLowerCase();
        hidden.value = map[v] || '';
        search.classList.toggle('is-invalid', search.value.trim() !== '' && !hidden.value);
      }
      search.addEventListener('input', syncId);
      search.addEventListener('change', syncId);
      search.form.addEventListener('submit', (e) => {
        if (!hidden.value) {
          e.preventDefault();
          search.classList.add('is-invalid');
          search.focus();
          alert('Selecione um colaborador da lista.');
        }
      });
    })();

    // Atalho Ctrl/Cmd+K → foca na busca
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const s = document.getElementById('teacher-search');
        if (s) s.focus();
      }
    });

    // Auto-submit ao mudar mês/escola (quando já há colaborador)
    (function() {
      const form = document.getElementById('rep-form');
      const hidden = document.getElementById('teacher_id');
      if (!form) return;
      form.querySelectorAll('[data-auto-submit]').forEach(el => {
        el.addEventListener('change', () => {
          if (hidden && hidden.value) form.submit();
        });
      });
    })();

    // Loading visual ao gerar PDF / exportar / lote
    document.querySelectorAll('a[href*="export="], a[href*="batch="]').forEach(link => {
      link.addEventListener('click', () => {
        const orig = link.innerHTML;
        link.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Gerando…';
        setTimeout(() => { link.innerHTML = orig; }, 6000);
      });
    });
    </script>
</body>
</html>

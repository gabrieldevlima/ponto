<?php
/**
 * Relatório Financeiro Mensal por Colaborador.
 *
 * Calcula salário base + horas extras + adicional automático − descontos.
 * Suporta range customizado, filtro por escola, exportação CSV/XLSX/PDF,
 * subtotais semanais, indicador de "pronto para pagamento" e hash SHA-256
 * do total no rodapé do PDF (anti-adulteração).
 */
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);

// ============================================================================
// ENTRADA
// ============================================================================
$month = $_GET['month'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$schoolFilter = isset($_GET['school']) ? (int)$_GET['school'] : 0;
$export = $_GET['export'] ?? '';

if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');
}

// Range customizado tem prioridade sobre month
$useCustomRange = (!empty($dateFrom) && !empty($dateTo)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo));

if ($useCustomRange) {
    $periodStart = new DateTime($dateFrom);
    $periodEnd   = new DateTime($dateTo);
    if ($periodEnd < $periodStart) [$periodStart, $periodEnd] = [$periodEnd, $periodStart];
    $month = $periodStart->format('Y-m');
} else {
    $periodStart = DateTime::createFromFormat('Y-m-d', $month . '-01');
    $periodEnd   = (clone $periodStart)->modify('last day of this month');
}

// Escopo
[$scopeSql, $scopeParams] = admin_scope_where('t');

// ============================================================================
// CARREGA COLABORADOR
// ============================================================================
$teachersSql = "SELECT t.id, t.name, t.cpf, t.created_at, t.type_id, ct.schedule_mode
                FROM teachers t
                LEFT JOIN collaborator_types ct ON ct.id = t.type_id
                WHERE t.active = 1 AND $scopeSql";
$paramsTeachers = $scopeParams;
if ($schoolFilter > 0 && is_network_admin($adm)) {
    $teachersSql .= " AND EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = t.id AND ts.school_id = ?)";
    $paramsTeachers[] = $schoolFilter;
}
$teachersSql .= " ORDER BY t.name";
$stTeachers = $pdo->prepare($teachersSql);
$stTeachers->execute($paramsTeachers);
$teachersList = $stTeachers->fetchAll(PDO::FETCH_ASSOC);

$teacher = null;
if ($teacherId) {
    $sqlT = "SELECT t.*, ct.schedule_mode FROM teachers t
             LEFT JOIN collaborator_types ct ON ct.id = t.type_id
             WHERE t.id = ? AND $scopeSql";
    $paramsT = array_merge([$teacherId], $scopeParams);
    if ($schoolFilter > 0 && is_network_admin($adm)) {
        $sqlT .= " AND EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = t.id AND ts.school_id = ?)";
        $paramsT[] = $schoolFilter;
    }
    $st = $pdo->prepare($sqlT);
    $st->execute($paramsT);
    $teacher = $st->fetch(PDO::FETCH_ASSOC);
    if (!$teacher) {
        http_response_code(403);
        exit('Sem permissão para ver este colaborador.');
    }
}

if (!function_exists('minutes_to_hhmm')) {
    function minutes_to_hhmm(int $m): string {
        $neg = $m < 0;
        $m = abs($m);
        $s = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        return $neg ? "-$s" : $s;
    }
}

// Lista de escolas
$schools = [];
if (is_network_admin($adm)) {
    $schools = $pdo->query("SELECT id, name FROM schools WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================================
// GERAÇÃO EM LOTE (resumo financeiro consolidado de todos os colaboradores)
// ============================================================================
if (isset($_GET['batch']) && in_array($_GET['batch'], ['pdf', 'xlsx'], true)) {
  try {
    $engineFile = __DIR__ . '/_report_engine.php';
    $tplFile    = __DIR__ . '/_tpl_financial_batch_pdf.php';
    $autoload   = __DIR__ . '/../../vendor/autoload.php';
    foreach (['_report_engine.php' => $engineFile, '_tpl_financial_batch_pdf.php' => $tplFile, 'vendor/autoload.php' => $autoload] as $label => $path) {
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
    if (!is_network_admin($adm)) {
        $scopeLabel = 'Escola: ' . ($adm['school_name'] ?? 'sua escola');
        $scopeSlug = 'escola';
    } elseif ($schoolFilter > 0) {
        foreach ($schools as $s) {
            if ((int)$s['id'] === $schoolFilter) { $scopeLabel = 'Escola: ' . $s['name']; $scopeSlug = 'escola_' . $schoolFilter; break; }
        }
    }
    $adminName = $adm['name'] ?? ($adm['username'] ?? '-');

    $rows = [];
    foreach ($teachersList as $t) {
        $tot = report_financial_totals($pdo, $t, $periodStart, $periodEnd, null);
        $rows[] = ['name' => $t['name'], 'cpf' => $t['cpf'] ?? ''] + $tot;
    }

    $fnameBase = 'financeiro_lote_' . $scopeSlug . '_' . $periodStart->format('Ymd') . '_' . $periodEnd->format('Ymd');

    if ($_GET['batch'] === 'pdf') {
        ob_start();
        include __DIR__ . '/_tpl_financial_batch_pdf.php';
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
    $sheet->setTitle('Resumo Financeiro');
    $lastCol = 'D';
    $sheet->setCellValue('A1', 'Relatório Financeiro — Resumo Consolidado');
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
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('1B4332');
    $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('64748B');
    $sheet->getRowDimension(1)->setRowHeight(22);
    $hdr = $sheet->getStyle("A{$headRow}:{$lastCol}{$headRow}");
    $hdr->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $hdr->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('2E7D46');
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
        if ($i % 2 === 1) $sheet->getStyle("A{$rr}:{$lastCol}{$rr}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F1F8F3');
    }
    $tot = $sheet->getStyle("A{$totRow}:{$lastCol}{$totRow}");
    $tot->getFont()->setBold(true);
    $tot->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D6ECDD');
    if ($totRow >= $headRow) {
        $sheet->getStyle("A{$headRow}:{$lastCol}{$totRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('CBDDD0');
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

// ============================================================================
// SCHEDULE MAP
// ============================================================================
$scheduleMap = [];
if ($teacher) {
    $tMode = $teacher['schedule_mode'] ?? 'classes';
    if ($tMode === 'classes') {
        $st = $pdo->prepare("SELECT weekday, classes_count, class_minutes FROM teacher_schedules WHERE teacher_id = ?");
        $st->execute([$teacher['id']]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$r['weekday']] = ['cc' => (int)$r['classes_count'], 'cm' => (int)$r['class_minutes']];
        }
    } elseif ($tMode === 'hours') {
        $st = $pdo->prepare("SELECT weekday, total_minutes, break_minutes FROM collaborator_hours_schedules WHERE teacher_id = ?");
        $st->execute([$teacher['id']]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$r['weekday']] = ['total' => (int)$r['total_minutes'], 'break' => (int)$r['break_minutes']];
        }
    } else {
        $st = $pdo->prepare("SELECT weekday, start_time, end_time, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ?");
        $st->execute([$teacher['id']]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$r['weekday']] = ['start' => $r['start_time'], 'end' => $r['end_time'], 'break' => (int)$r['break_minutes']];
        }
    }
}

// ============================================================================
// MONTAGEM TIMELINE DIÁRIA
// ============================================================================
$daily = [];
// Escola do colaborador → captura feriados específicos da escola E os da rede.
// Passar null aqui (bug antigo) ignorava feriados com school_id definido.
$reportSchoolId = $teacher ? primary_school_id_for_teacher($pdo, (int)$teacher['id']) : null;
$holidays = get_holidays_in_period($pdo, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'), $reportSchoolId);

$dt = clone $periodStart;
while ($dt <= $periodEnd) {
    $dateStr = $dt->format('Y-m-d');
    $w = (int)$dt->format('w');
    $expectedMin = 0;

    $isWorkday = is_working_day($pdo, $dateStr, $reportSchoolId);

    if ($teacher && $isWorkday) {
        $tMode = $teacher['schedule_mode'] ?? 'classes';
        if ($tMode === 'classes') {
            $cc = (int)($scheduleMap[$w]['cc'] ?? 0);
            $cm = (int)($scheduleMap[$w]['cm'] ?? 0);
            $expectedMin = $cc * $cm;
        } elseif ($tMode === 'hours') {
            $expectedMin = max(0, (int)($scheduleMap[$w]['total'] ?? 0));
        } else {
            $start = $scheduleMap[$w]['start'] ?? null;
            $end   = $scheduleMap[$w]['end'] ?? null;
            $break = (int)($scheduleMap[$w]['break'] ?? 0);
            if ($start && $end) {
                $s = DateTime::createFromFormat('H:i:s', $start) ?: DateTime::createFromFormat('H:i', $start);
                $e = DateTime::createFromFormat('H:i:s', $end)   ?: DateTime::createFromFormat('H:i', $end);
                if ($s && $e) {
                    if ($e <= $s) $e = (clone $e)->modify('+1 day');
                    // Janela completa — break_minutes não é descontado (intervalo
                    // conta como tempo trabalhado; modelo "cheio vs cheio").
                    $expectedMin = max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60));
                }
            }
        }
    }

    $daily[$dateStr] = [
        'expected'  => $expectedMin,
        'worked'    => 0,
        'in'        => null,
        'out'       => null,
        'weekday'   => $w,
        'holiday'   => $holidays[$dateStr] ?? null,
        'leaves'    => [],
        'items'     => [],
        'edited'    => false,
    ];
    $dt = $dt->modify('+1 day');
}

// Leaves aprovadas
$leaves = [];
$stL = $pdo->prepare("SELECT l.*, lt.paid, lt.affects_bank, lt.name as leave_type_name FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
                      WHERE l.teacher_id = ? AND l.approved = 1 AND l.end_date >= ? AND l.start_date <= ?");
$stL->execute([$teacher['id'] ?? 0, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
while ($lv = $stL->fetch(PDO::FETCH_ASSOC)) {
    $d0 = new DateTime($lv['start_date']);
    $d1 = new DateTime($lv['end_date']);
    for ($d = clone $d0; $d <= $d1; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        if (!isset($daily[$k])) continue;
        $daily[$k]['leaves'][] = [
            'type' => $lv['leave_type_name'],
            'paid' => (int)$lv['paid'],
            'excuses_absence' => (int)($lv['excuses_absence'] ?? 0),
            'cid_code' => $lv['cid_code'] ?? '',
        ];
        if ((int)($lv['excuses_absence'] ?? 0) === 1) $daily[$k]['expected'] = 0;
    }
    $leaves[] = $lv;
}

// Attendance aprovada (com edições)
$pendingCount = 0;
if ($teacher) {
    $st = $pdo->prepare("
        SELECT a.*, mr.name AS manual_reason_name, COALESCE(NULLIF(ed.name, ''), ed.username) AS edited_by_username
        FROM attendance a
        LEFT JOIN manual_reasons mr ON mr.id = a.manual_reason_id
        LEFT JOIN admins ed ON ed.id = a.editado_por
        WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ? AND a.approved = 1
        ORDER BY a.date ASC, a.id ASC
    ");
    $st->execute([$teacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $d = $r['date'];
        $minutes = 0;
        if (!empty($r['check_in']) && !empty($r['check_out'])) {
            $in = new DateTime($r['check_in']);
            $out = new DateTime($r['check_out']);
            if ($out > $in) $minutes = (int)(($out->getTimestamp() - $in->getTimestamp()) / 60);
        }
        if (!isset($daily[$d])) continue;
        // Relatório financeiro: só soma WORK; intervalo não conta para custo.
        $worked = (($r['record_type'] ?? 'work') === 'break') ? 0 : $minutes;
        $daily[$d]['worked'] += $worked;
        if ($r['check_in'] && ($daily[$d]['in'] === null || $r['check_in'] < $daily[$d]['in'])) {
            $daily[$d]['in'] = $r['check_in'];
        }
        if ($r['check_out'] && ($daily[$d]['out'] === null || $r['check_out'] > $daily[$d]['out'])) {
            $daily[$d]['out'] = $r['check_out'];
        }
        $daily[$d]['items'][] = $r;
        if (!empty($r['data_edicao'])) $daily[$d]['edited'] = true;
    }
    // Pendentes (não aprovados ainda) — afeta "ready for payment"
    $stP = $pdo->prepare("
        SELECT COUNT(*) FROM attendance
         WHERE teacher_id = ? AND date BETWEEN ? AND ? AND approved IS NULL
    ");
    $stP->execute([$teacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
    $pendingCount = (int)$stP->fetchColumn();
}

// ============================================================================
// JANELA DE CONTAGEM
// Dias antes do início da contagem (configuração global / cadastro) ou no futuro
// NÃO são contados: zera previsto/trabalhado para não gerar saldo, déficit nem
// desconto. A jornada mensal contratual ($monthlyExpected) é preservada para o
// cálculo do valor/minuto. No-op para meses fechados (todos os dias na janela).
// ============================================================================
$today = date('Y-m-d');
$teacherStartDate = $teacher ? counting_start_for($teacher['created_at'] ?? null) : '1900-01-01';
$monthlyExpected = (int) array_sum(array_column($daily, 'expected'));
$daily = apply_counting_window($daily, $teacherStartDate, $today);

$totalExpected = array_sum(array_column($daily, 'expected'));
// Trabalhado total para cálculo financeiro usa o EFETIVO (com desconto de intervalo
// previsto), evitando que dias sem intervalo manual gerem "horas extras" indevidas
// quando a rotina já prevê pausa de almoço.
$totalWorked = 0;
if ($teacher) {
    foreach ($daily as $d => $info) {
        // Dias fora da janela de contagem já foram zerados e não somam.
        if ($d < $teacherStartDate || $d > $today) {
            continue;
        }
        if ((int)$info['expected'] === 0 && empty($info['items'])) {
            $totalWorked += (int)$info['worked'];
            continue;
        }
        $eff = calculate_effective_worked_minutes($pdo, (int)$teacher['id'], $d);
        $daily[$d]['effective'] = $eff;
        $totalWorked += $eff;
    }
}

// Sistema de períodos
$usesPeriodSystem = $teacher ? teacher_uses_period_system((int)$teacher['id']) : false;

// ============================================================================
// CÁLCULOS (informativos — a instituição não paga hora extra nem desconta déficit)
// ============================================================================
$deltaMin = 0;          // saldo do período (trabalhado − previsto), só informativo
$totalAbsences = 0;
// $today e $teacherStartDate já definidos acima (antes da janela de contagem).

if ($teacher) {
    // Saldo do período (trabalhado − previsto): apenas INFORMATIVO. A instituição
    // não paga hora extra nem desconta déficit, então o saldo não afeta o salário.
    // Quando negativo, é apresentado como "horas a compensar".
    $deltaMin = $usesPeriodSystem ? 0 : ($totalWorked - $totalExpected);

    foreach ($daily as $d => $v) {
        if ($v['expected'] > 0 && $v['worked'] == 0 && $d <= $today && $d >= $teacherStartDate && empty($v['holiday']) && !leave_day_is_excused($v['leaves'])) {
            $totalAbsences++;
        }
    }
}

// Total a receber = salário base. Sem adicional de hora extra e sem desconto
// automático por déficit (deduções manuais, quando houver, ficam no holerite).
$totalToReceive = (float)($teacher['base_salary'] ?? 0);

// Hash SHA-256 do total (anti-adulteração para o PDF)
$totalHash = $teacher
    ? substr(hash('sha256', $teacher['id'] . '|' . $month . '|' . number_format($totalToReceive, 2, '.', '')), 0, 16)
    : '';

// "Pronto para pagamento": sem pendentes, sem faltas não justificadas
// $totalAbsences já exclui dias abonados (expected zerado). Faltas não-abonadas contam.
$readyForPayment = $teacher && $pendingCount === 0 && $totalAbsences === 0;

// Hora extra descontinuada — sem lista de solicitações no relatório financeiro.
$overtimeDetails = [];

// ============================================================================
// EXPORTAÇÃO XLSX / CSV / PDF
// ============================================================================
if ($export === 'xlsx' && $teacher) {
    if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Financeiro');
            $sheet->fromArray(['Colaborador', 'Período', 'Salário base', 'Min esperados', 'Min trabalhados', 'Saldo (min)', 'TOTAL (R$)'], null, 'A1');
            $sheet->fromArray([
                $teacher['name'],
                $periodStart->format('d/m/Y') . ' a ' . $periodEnd->format('d/m/Y'),
                number_format((float)$teacher['base_salary'], 2, ',', '.'),
                $totalExpected,
                $totalWorked,
                $deltaMin,
                number_format($totalToReceive, 2, ',', '.'),
            ], null, 'A2');
            $sheet->fromArray(['Data', 'Esperado (min)', 'Trabalhado (min)', 'Saldo (min)', 'Entrada', 'Saída'], null, 'A4');
            $row = 5;
            foreach ($daily as $d => $v) {
                $bal = $v['worked'] - $v['expected'];
                $sheet->fromArray([$d, $v['expected'], $v['worked'], $bal, $v['in'] ?: '-', $v['out'] ?: '-'], null, 'A' . $row);
                $row++;
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="financeiro_' . $teacher['id'] . '_' . $month . '.xlsx"');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        } catch (Throwable $e) {
            header('Location: ?teacher_id=' . (int)$teacher['id'] . '&month=' . $month . '&export=csv');
            exit;
        }
    } else {
        header('Location: ?teacher_id=' . (int)$teacher['id'] . '&month=' . $month . '&export=csv');
        exit;
    }
}

if ($export === 'pdf' && $teacher) {
    if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        try {
            ob_start();
            include __DIR__ . '/_tpl_financial_pdf.php';
            $html = ob_get_clean();
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $dompdf->stream('financeiro_' . $teacher['id'] . '_' . $month . '.pdf', ['Attachment' => false]);
            exit;
        } catch (Throwable $e) { /* fallback abaixo */ }
    }
}

if ($export === 'csv' && $teacher) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=financeiro_' . $teacher['id'] . '_' . $month . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
    fputcsv($out, ['Colaborador', 'Período', 'Salário base', 'Min esperados', 'Min trabalhados', 'Saldo (min)', 'TOTAL (R$)'], ';');
    fputcsv($out, [
        $teacher['name'],
        $periodStart->format('d/m/Y') . ' a ' . $periodEnd->format('d/m/Y'),
        number_format((float)$teacher['base_salary'], 2, ',', '.'),
        $totalExpected,
        $totalWorked,
        $deltaMin,
        number_format($totalToReceive, 2, ',', '.'),
    ], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Data', 'Esperado', 'Trabalhado', 'Saldo', 'Entrada', 'Saída'], ';');
    foreach ($daily as $d => $v) {
        $bal = $v['worked'] - $v['expected'];
        fputcsv($out, [(new DateTime($d))->format('d/m/Y'), minutes_to_hhmm($v['expected']), minutes_to_hhmm($v['worked']), minutes_to_hhmm($bal), $v['in'] ?: '-', $v['out'] ?: '-'], ';');
    }
    fclose($out);
    exit;
}

function fin_build_url(array $extra): string {
    $q = $_GET;
    foreach ($extra as $k => $v) $q[$k] = $v;
    return 'reports_financial.php?' . http_build_query($q);
}

// URL para geração em lote: carrega filtros atuais, remove teacher/export
function fin_batch_url(array $extra): string {
    $q = $_GET;
    unset($q['teacher_id'], $q['export']);
    foreach ($extra as $k => $v) $q[$k] = $v;
    return 'reports_financial.php?' . http_build_query($q);
}

// Rótulo dinâmico do botão de lote
$batchLabel = 'Gerar resumo de todos os colaboradores';
if (is_network_admin($adm)) {
    $batchLabel = 'Gerar resumo de toda a rede';
    if ($schoolFilter > 0) {
        foreach ($schools as $s) {
            if ((int)$s['id'] === $schoolFilter) { $batchLabel = 'Gerar resumo da ' . $s['name']; break; }
        }
    }
}

$weekdayShort = [0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'];

// Subtotais semanais (apenas horas — sem valores financeiros por hora)
$weekly = [];
foreach ($daily as $d => $info) {
    $wk = (new DateTime($d))->format('o-W');
    $weekly[$wk] = $weekly[$wk] ?? ['expected' => 0, 'worked' => 0];
    $weekly[$wk]['expected'] += (int)$info['expected'];
    $weekly[$wk]['worked']   += (int)$info['worked'];
}

$pageTitle = 'Relatório Financeiro';
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
            --fin-bg: #f7f9fc;
            --fin-border: #e2e8f0;
            --fin-muted: #64748b;
            --fin-accent: #0162cc;
            --fin-success: #059669;
            --fin-danger: #dc2626;
            --fin-warning: #d97706;
        }
        body { background: var(--fin-bg); }

        /* Page header */
        .fin-page-header {
            background: linear-gradient(135deg, #ffffff, #f1f5f9);
            border: 1px solid var(--fin-border); border-radius: 14px;
            padding: 1.25rem 1.5rem; margin-bottom: 1.25rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
        }
        .fin-page-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--fin-success), #047857);
            color: white; border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.4rem; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
        }

        /* Hero summary (resumo de pagamento no topo) */
        .fin-hero {
            background: white;
            border: 1px solid var(--fin-border);
            border-radius: 14px;
            padding: 1.75rem 2rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .fin-hero::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 6px;
            background: linear-gradient(90deg, var(--fin-success), #34d399);
        }
        .fin-hero-grid {
            display: grid;
            grid-template-columns: 1.4fr repeat(4, 1fr);
            gap: 1rem;
            align-items: end;
        }
        .fin-hero-total .label {
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
            color: var(--fin-muted); margin-bottom: 0.4rem;
        }
        .fin-hero-total .value {
            font-size: 2.6rem; font-weight: 800; color: var(--fin-success);
            line-height: 1; font-feature-settings: "tnum"; letter-spacing: -0.02em;
        }
        .fin-hero-total .currency { font-size: 1.4rem; color: var(--fin-muted); margin-right: 4px; vertical-align: top; font-weight: 600; }
        .fin-hero-total .sub { font-size: 0.85rem; color: var(--fin-muted); margin-top: 0.4rem; }
        .fin-hero-component {
            text-align: right;
            border-left: 1px solid var(--fin-border);
            padding-left: 1rem;
        }
        .fin-hero-component .label {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--fin-muted);
        }
        .fin-hero-component .value {
            font-size: 1.15rem; font-weight: 700; line-height: 1.2;
            font-feature-settings: "tnum";
        }
        .fin-hero-component.is-add .value { color: var(--fin-success); }
        .fin-hero-component.is-sub .value { color: var(--fin-danger); }

        /* Pronto para pagamento badge */
        .fin-ready-badge {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.78rem; font-weight: 700;
            padding: 4px 12px; border-radius: 999px;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .fin-ready-yes { background: #d1fae5; color: #065f46; }
        .fin-ready-no  { background: #fef3c7; color: #92400e; }

        /* Métricas compactas */
        .fin-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem; margin-bottom: 1.5rem;
        }
        .fin-metric {
            background: white; border: 1px solid var(--fin-border); border-radius: 10px;
            padding: 0.85rem 1rem;
        }
        .fin-metric .label {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--fin-muted); margin-bottom: 0.2rem;
        }
        .fin-metric .value { font-size: 1.15rem; font-weight: 700; color: #0f172a; line-height: 1.1; font-feature-settings: "tnum"; }
        .fin-metric .sub { font-size: 0.72rem; color: var(--fin-muted); margin-top: 2px; }

        /* Filtro card */
        .fin-filter {
            background: white; border: 1px solid var(--fin-border); border-radius: 14px;
            padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;
        }

        /* Empty state */
        .fin-empty {
            background: white; border: 2px dashed var(--fin-border); border-radius: 14px;
            padding: 3rem 2rem; text-align: center; margin-top: 1.5rem;
        }
        .fin-empty-icon {
            width: 72px; height: 72px; margin: 0 auto 1rem;
            background: #ecfdf5; color: var(--fin-success);
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center; font-size: 2rem;
        }

        /* Tabela diária */
        .fin-table {
            background: white; border: 1px solid var(--fin-border); border-radius: 14px; overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .fin-table table { margin: 0; }
        .fin-table .row-today { background: #eff6ff !important; }
        .fin-table .row-leave { background: #ecfdf5; }
        .fin-table .row-absence td:first-child { box-shadow: inset 4px 0 0 var(--fin-danger); }
        .fin-table .row-holiday { background: #fef2f2; }
        .fin-week-divider td {
            background: #f8fafc !important; font-weight: 600;
            font-size: 0.82rem; color: var(--fin-muted);
            border-top: 2px solid #e2e8f0;
        }

        @media print {
            .fin-filter, .navbar, .fin-actions, footer { display: none !important; }
            body { background: white; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>

    <div class="container-fluid admin-content">

        <!-- HEADER -->
        <div class="fin-page-header">
            <div class="d-flex align-items-center gap-3">
                <div class="fin-page-icon"><i class="bi bi-currency-dollar"></i></div>
                <div>
                    <h1 class="h3 mb-0"><?= esc($pageTitle) ?></h1>
                    <p class="text-muted mb-0 small">
                        <?= esc($periodStart->format('d/m/Y')) ?> → <?= esc($periodEnd->format('d/m/Y')) ?>
                        <?php if ($teacher): ?>
                            · <strong><?= esc($teacher['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php if ($teacher): ?>
            <div class="d-flex gap-2 flex-wrap fin-actions">
                <a href="<?= esc(fin_build_url(['export' => 'pdf'])) ?>" target="_blank" rel="noopener" class="btn btn-danger btn-sm">
                    <i class="bi bi-filetype-pdf me-1"></i>PDF
                </a>
                <a href="<?= esc(fin_build_url(['export' => 'xlsx'])) ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel
                </a>
                <a href="<?= esc(fin_build_url(['export' => 'csv'])) ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-filetype-csv me-1"></i>CSV
                </a>
                <button class="btn btn-outline-secondary btn-sm" id="fin-copy-link" title="Copiar link com filtros aplicados">
                    <i class="bi bi-link-45deg me-1"></i>Copiar link
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- FILTROS -->
        <form class="fin-filter" method="get" autocomplete="off" id="fin-form">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-3">
                    <label class="form-label small fw-semibold">Colaborador</label>
                    <input type="text" id="teacher-search" class="form-control"
                           list="teachers-datalist" placeholder="Digite o nome…"
                           value="<?= $teacher ? esc($teacher['name']) : '' ?>"
                           autocomplete="off" required>
                    <input type="hidden" id="teacher_id" name="teacher_id" value="<?= $teacherId ?: '' ?>">
                    <datalist id="teachers-datalist">
                        <?php foreach ($teachersList as $t): ?>
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
                <?php if (is_network_admin($adm)): ?>
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
                <kbd>Ctrl</kbd>+<kbd>K</kbd> para focar na busca. Range customizado tem prioridade sobre o mês.
            </div>

            <!-- GERAÇÃO EM LOTE -->
            <div class="mt-3 pt-3 border-top d-flex flex-wrap align-items-center gap-2">
                <span class="small fw-semibold text-muted me-1">
                    <i class="bi bi-collection me-1"></i><?= esc($batchLabel) ?>
                    <span class="badge bg-secondary ms-1"><?= count($teachersList) ?></span>
                </span>
                <a href="<?= esc(fin_batch_url(['batch' => 'pdf'])) ?>" target="_blank" rel="noopener" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-filetype-pdf me-1"></i>PDF de todos
                </a>
                <a href="<?= esc(fin_batch_url(['batch' => 'xlsx'])) ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Excel de todos
                </a>
                <span class="text-muted small ms-1">Resumo: 1 linha por colaborador (totais do período).</span>
            </div>
        </form>

        <?php if (!$teacher): ?>
            <!-- EMPTY STATE -->
            <div class="fin-empty">
                <div class="fin-empty-icon"><i class="bi bi-cash-coin"></i></div>
                <h4 class="mb-2">Selecione um colaborador para calcular o pagamento</h4>
                <p class="text-muted mb-3">Digite o nome no campo acima ou escolha da lista. O sistema calcula automaticamente salário base, horas extras, adicionais e descontos com base nas batidas aprovadas.</p>
            </div>
        <?php else: ?>

            <!-- ============ HERO: TOTAL A RECEBER NO TOPO ============ -->
            <div class="fin-hero">
                <div class="fin-hero-grid">
                    <div class="fin-hero-total">
                        <div class="label">Total a receber</div>
                        <div class="value"><span class="currency">R$</span><?= number_format($totalToReceive, 2, ',', '.') ?></div>
                        <div class="sub">
                            <?= esc($periodStart->format('m/Y')) ?>
                            ·
                            <?php if ($readyForPayment): ?>
                                <span class="fin-ready-badge fin-ready-yes" title="Sem pendências, pronto para folha"><i class="bi bi-check-circle-fill"></i>Pronto</span>
                            <?php else: ?>
                                <span class="fin-ready-badge fin-ready-no" title="<?= $pendingCount ?> registro(s) pendente(s) e/ou faltas não justificadas">
                                    <i class="bi bi-exclamation-circle-fill"></i>Revisar
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="fin-hero-component">
                        <div class="label">Salário Base</div>
                        <div class="value">R$ <?= number_format((float)$teacher['base_salary'], 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <?php if ($pendingCount > 0): ?>
                <div class="alert alert-warning d-flex align-items-center mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>
                        <strong><?= $pendingCount ?> registro(s) pendente(s)</strong> de aprovação no período.
                        Eles não estão sendo contabilizados neste cálculo —
                        <a href="attendances.php?teacher=<?= (int)$teacher['id'] ?>&date1=<?= esc($periodStart->format('Y-m-d')) ?>&date2=<?= esc($periodEnd->format('Y-m-d')) ?>&approved=null" class="alert-link">revise antes de pagar</a>.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($usesPeriodSystem): ?>
                <div class="alert alert-info py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Sistema de grade horária:</strong> pagamento fixo por número de aulas. Períodos ociosos não geram extras.
                </div>
            <?php endif; ?>

            <!-- MÉTRICAS COMPACTAS -->
            <div class="fin-metrics">
                <div class="fin-metric">
                    <div class="label">Min. Previstos</div>
                    <div class="value"><?= esc(minutes_to_hhmm($totalExpected)) ?></div>
                    <div class="sub"><?= number_format($totalExpected, 0, ',', '.') ?> min · jornada</div>
                </div>
                <div class="fin-metric">
                    <div class="label">Min. Trabalhados</div>
                    <div class="value"><?= esc(minutes_to_hhmm($totalWorked)) ?></div>
                    <div class="sub">apenas aprovados</div>
                </div>
                <div class="fin-metric">
                    <div class="label"><?= $deltaMin < 0 ? 'Horas a compensar' : 'Saldo' ?></div>
                    <div class="value">
                        <?= $deltaMin < 0 ? esc(minutes_to_hhmm(abs($deltaMin))) : (($deltaMin > 0 ? '+' : '') . esc(minutes_to_hhmm($deltaMin))) ?>
                    </div>
                    <div class="sub">trabalhado vs. previsto</div>
                </div>
                <?php if ($totalAbsences > 0): ?>
                <div class="fin-metric">
                    <div class="label">Faltas</div>
                    <div class="value text-danger"><?= (int)$totalAbsences ?></div>
                    <div class="sub">dias úteis sem ponto</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- TABELA DIÁRIA -->
            <div class="fin-table">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width:130px;">Data</th>
                                <th scope="col" style="width:90px;">Esperado</th>
                                <th scope="col" style="width:90px;">Trabalhado</th>
                                <th scope="col" style="width:90px;">Saldo</th>
                                <th scope="col" style="width:80px;">Entrada</th>
                                <th scope="col" style="width:80px;">Saída</th>
                                <th scope="col">Status / Justificativa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $lastWeek = null;
                            ?>
                            <?php foreach ($daily as $d => $v):
                                $weekKey = (new DateTime($d))->format('o-W');

                                if ($lastWeek !== null && $lastWeek !== $weekKey):
                                    $w = $weekly[$lastWeek];
                                    $bal = $w['worked'] - $w['expected']; ?>
                                    <tr class="fin-week-divider">
                                        <td><i class="bi bi-bookmark-fill me-1 text-muted"></i>Semana <?= esc($lastWeek) ?></td>
                                        <td><?= esc(minutes_to_hhmm($w['expected'])) ?></td>
                                        <td><?= esc(minutes_to_hhmm($w['worked'])) ?></td>
                                        <td><?= ($bal >= 0 ? '+' : '') . minutes_to_hhmm($bal) ?></td>
                                        <td colspan="3"></td>
                                    </tr>
                            <?php endif; $lastWeek = $weekKey; ?>

                            <?php
                                $bal = (int)$v['worked'] - (int)$v['expected'];
                                $isToday = ($d === $today);
                                $isAbonado = leave_day_is_excused($v['leaves']);
                                $hasLeave  = !empty($v['leaves']);
                                $isHoliday = !empty($v['holiday']);
                                $isFalta = $v['expected'] > 0 && $v['worked'] == 0 && $d <= $today && $d >= $teacherStartDate && !$isHoliday && !$isAbonado;

                                $cls = [];
                                if ($isToday)   $cls[] = 'row-today';
                                if ($isAbonado) $cls[] = 'row-leave';
                                if ($isHoliday) $cls[] = 'row-holiday';
                                if ($isFalta)   $cls[] = 'row-absence';
                            ?>
                                <tr class="<?= esc(implode(' ', $cls)) ?>">
                                    <td>
                                        <?= esc((new DateTime($d))->format('d/m/Y')) ?>
                                        <div class="small text-muted">
                                            <?= esc($weekdayShort[$v['weekday']] ?? '') ?>
                                            <?= $isToday ? ' · <strong>hoje</strong>' : '' ?>
                                        </div>
                                    </td>
                                    <td><?= esc(minutes_to_hhmm((int)$v['expected'])) ?></td>
                                    <td><?= esc(minutes_to_hhmm((int)$v['worked'])) ?></td>
                                    <td class="<?= $bal > 0 ? 'text-success' : 'text-muted' ?>">
                                        <?= ($bal > 0 ? '+' : '') . esc(minutes_to_hhmm($bal)) ?>
                                    </td>
                                    <td><?= $v['in']  ? esc((new DateTime($v['in']))->format('H:i'))  : '—' ?></td>
                                    <td><?= $v['out'] ? esc((new DateTime($v['out']))->format('H:i')) : '—' ?></td>
                                    <td>
                                        <?php if ($isHoliday): ?>
                                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger"><i class="bi bi-flag me-1"></i><?= esc($v['holiday']['name'] ?? 'Feriado') ?></span>
                                        <?php elseif ($isAbonado): ?>
                                            <?php foreach ($v['leaves'] as $lv): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info">
                                                    <i class="bi bi-calendar-check me-1"></i><?= esc($lv['type']) ?> (abonado)<?= $lv['paid'] ? ' · rem.' : '' ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php elseif ($isFalta): ?>
                                            <span class="badge bg-danger text-white"><i class="bi bi-exclamation-triangle-fill me-1"></i>FALTA</span>
                                            <?php foreach ($v['leaves'] as $lv): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning" title="Afastamento que NÃO abona a falta">
                                                    <i class="bi bi-info-circle me-1"></i>Justificada · <?= esc($lv['type']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php elseif (!empty($v['edited'])): ?>
                                            <?php foreach ($v['items'] as $it):
                                                if (empty($it['data_edicao'])) continue; ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"
                                                      title="Editado por <?= esc($it['edited_by_username'] ?? '-') ?> em <?= esc(date('d/m/Y H:i', strtotime($it['data_edicao']))) ?>: <?= esc($it['motivo_edicao'] ?? '-') ?>">
                                                    <i class="bi bi-pencil-square me-1"></i>Editado
                                                </span>
                                                <?php break; ?>
                                            <?php endforeach; ?>
                                            <?php
                                            $just = [];
                                            foreach ($v['items'] as $it) {
                                                if (!empty($it['manual_reason_id'])) {
                                                    $just[] = trim(($it['manual_reason_name'] ?? 'Manual') . (!empty($it['manual_reason_text']) ? ' - ' . $it['manual_reason_text'] : ''));
                                                }
                                            }
                                            if ($just) echo '<div class="small text-muted mt-1">' . esc(implode(' · ', $just)) . '</div>';
                                            ?>
                                        <?php else:
                                            $just = [];
                                            foreach ($v['items'] as $it) {
                                                if (!empty($it['manual_reason_id'])) {
                                                    $just[] = trim(($it['manual_reason_name'] ?? 'Manual') . (!empty($it['manual_reason_text']) ? ' - ' . $it['manual_reason_text'] : ''));
                                                }
                                            }
                                            echo $just ? '<small>' . esc(implode(' · ', $just)) . '</small>' : '<span class="text-muted">—</span>';
                                        ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($lastWeek !== null && isset($weekly[$lastWeek])):
                                $w = $weekly[$lastWeek];
                                $bal = $w['worked'] - $w['expected']; ?>
                                <tr class="fin-week-divider">
                                    <td><i class="bi bi-bookmark-fill me-1 text-muted"></i>Semana <?= esc($lastWeek) ?></td>
                                    <td><?= esc(minutes_to_hhmm($w['expected'])) ?></td>
                                    <td><?= esc(minutes_to_hhmm($w['worked'])) ?></td>
                                    <td><?= ($bal >= 0 ? '+' : '') . minutes_to_hhmm($bal) ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td>TOTAL</td>
                                <td><?= esc(minutes_to_hhmm($totalExpected)) ?></td>
                                <td><?= esc(minutes_to_hhmm($totalWorked)) ?></td>
                                <td><?= ($deltaMin >= 0 ? '+' : '') . esc(minutes_to_hhmm($deltaMin)) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <?php if (!empty($leaves)): ?>
            <div class="card mb-3">
                <div class="card-header bg-info bg-opacity-10">
                    <strong><i class="bi bi-person-x me-1 text-info"></i>Afastamentos no Período</strong>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th scope="col">Tipo</th><th scope="col">Período</th><th scope="col">Dias</th><th scope="col">Abona falta?</th><th scope="col">Remunerado?</th><th scope="col">Impacto</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaves as $lv):
                                $startFmt = (new DateTime($lv['start_date']))->format('d/m/Y');
                                $endFmt = (new DateTime($lv['end_date']))->format('d/m/Y');
                                $daysCount = $lv['days_count'] ?? ((new DateTime($lv['start_date']))->diff(new DateTime($lv['end_date']))->days + 1);
                                $isPaid = (int)$lv['paid'];
                                $isExcuses = (int)($lv['excuses_absence'] ?? 0); ?>
                                <tr>
                                    <td><?= esc($lv['leave_type_name']) ?></td>
                                    <td><?= esc($startFmt) ?> → <?= esc($endFmt) ?></td>
                                    <td><span class="badge bg-info"><?= $daysCount ?> dia<?= $daysCount != 1 ? 's' : '' ?></span></td>
                                    <td><?= $isExcuses ? '<span class="badge bg-success">Sim</span>' : '<span class="badge bg-warning text-dark">Não</span>' ?></td>
                                    <td><?= $isPaid ? '<span class="badge bg-success">Sim</span>' : '<span class="badge bg-secondary">Não</span>' ?></td>
                                    <td class="small">
                                        <?= $isExcuses ? '<span class="text-success">Abona · jornada zerada</span>' : '<span class="text-warning">Não abona · conta falta + desconto</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Hash de integridade + assinatura -->
            <div class="alert alert-light border d-flex align-items-center justify-content-between flex-wrap gap-2 small">
                <div>
                    <i class="bi bi-shield-check me-1 text-success"></i>
                    <strong>Hash de integridade:</strong>
                    <code><?= esc($totalHash) ?></code>
                    <span class="text-muted ms-2">— anti-adulteração do total calculado.</span>
                </div>
                <div class="text-muted">
                    Gerado em <?= date('d/m/Y H:i') ?>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function() {
        // Tooltips
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

        // Typeahead
        const search = document.getElementById('teacher-search');
        const hidden = document.getElementById('teacher_id');
        const datalist = document.getElementById('teachers-datalist');
        if (search && hidden && datalist) {
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
        }

        // Atalho Ctrl/Cmd+K
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                if (search) search.focus();
            }
        });

        // Auto-submit
        const form = document.getElementById('fin-form');
        if (form && hidden) {
            form.querySelectorAll('[data-auto-submit]').forEach(el => {
                el.addEventListener('change', () => {
                    if (hidden.value) form.submit();
                });
            });
        }

        // Loading visual em links de export / lote
        document.querySelectorAll('a[href*="export="], a[href*="batch="]').forEach(link => {
            link.addEventListener('click', () => {
                const orig = link.innerHTML;
                link.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Gerando…';
                setTimeout(() => { link.innerHTML = orig; }, 4500);
            });
        });

        // Copiar link com filtros aplicados
        const btnCopy = document.getElementById('fin-copy-link');
        if (btnCopy) {
            btnCopy.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(window.location.href);
                    const orig = btnCopy.innerHTML;
                    btnCopy.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado!';
                    btnCopy.classList.add('btn-success');
                    btnCopy.classList.remove('btn-outline-secondary');
                    setTimeout(() => {
                        btnCopy.innerHTML = orig;
                        btnCopy.classList.remove('btn-success');
                        btnCopy.classList.add('btn-outline-secondary');
                    }, 2000);
                } catch (_) {
                    prompt('Copie manualmente:', window.location.href);
                }
            });
        }
    })();
    </script>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

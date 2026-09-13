<?php
/**
 * Motor de cálculo de TOTAIS por colaborador para a geração de relatórios EM LOTE
 * (resumo consolidado: Nome, CPF, Horas Trabalhadas, Faltas).
 *
 * ESPECÍFICO DESTE PROJETO (ponto_oeiras2). Extração comportamento-preservadora do
 * cálculo inline de reports.php (mensal) e reports_financial.php (financeiro) —
 * PARIDADE EXATA com o relatório individual deste projeto. Modelo "cheio vs cheio"
 * (intervalo conta como presença), janela de contagem, feriados por escola,
 * abono via excuses_absence. NÃO reutilizar o motor de outro projeto.
 */

if (!function_exists('report_hours_label')) {
    function report_hours_label(int $minutes): string {
        if ($minutes <= 0) return '0h';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m === 0 ? ($h . 'h') : ($h . 'h ' . sprintf('%02d', $m) . 'min');
    }
}

if (!function_exists('report_monthly_totals')) {
    /**
     * Espelha reports.php (mensal).
     * worked_min (coluna "Trabalhadas") = LÍQUIDO = max(0, ΣworkedMin − ΣbreakMin), após janela.
     * absences = dia com expected>0, sem itens, na janela, não abonado (excuses_absence).
     */
    function report_monthly_totals(PDO $pdo, array $teacher, DateTime $periodStart, DateTime $periodEnd, int $tolMin = 10): array {
        $teacherId = (int)$teacher['id'];
        $mode = $teacher['schedule_mode'] ?? 'classes';

        // Schedule map (reports.php:86-110) — classes / time (mensal não trata 'hours')
        $scheduleMap = [];
        if ($mode === 'classes') {
            $st = $pdo->prepare("SELECT weekday, classes_count, class_minutes FROM teacher_schedules WHERE teacher_id = ?");
            $st->execute([$teacherId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) $scheduleMap[(int)$row['weekday']] = ['cc' => (int)$row['classes_count'], 'cm' => (int)$row['class_minutes']];
        } elseif ($mode === 'time') {
            $st = $pdo->prepare("SELECT weekday, start_time, end_time, end_next_day, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ?");
            $st->execute([$teacherId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) $scheduleMap[(int)$row['weekday']] = ['start' => $row['start_time'], 'end' => $row['end_time'], 'end_next_day' => (int)($row['end_next_day'] ?? 0), 'break' => (int)$row['break_minutes']];
        }

        // Feriados por escola (reports.php:118-121)
        $reportSchoolId = primary_school_id_for_teacher($pdo, $teacherId);
        $holidays = get_holidays_in_period($pdo, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'), $reportSchoolId);

        // Timeline diária (reports.php:126-168) — expected janela COMPLETA (sem descontar break)
        $totalExpectedMin = 0; $daily = [];
        $dt = clone $periodStart;
        while ($dt <= $periodEnd) {
            $dateStr = $dt->format('Y-m-d'); $w = (int)$dt->format('w'); $exp = 0;
            $isHolidayDay = isset($holidays[$dateStr]);
            if (!$isHolidayDay && isset($scheduleMap[$w])) {
                if ($mode === 'classes') {
                    $exp = ($scheduleMap[$w]['cc'] ?? 0) * ($scheduleMap[$w]['cm'] ?? 0);
                } elseif ($mode === 'time') {
                    $start = $scheduleMap[$w]['start'] ?? null; $end = $scheduleMap[$w]['end'] ?? null;
                    $break = $scheduleMap[$w]['break'] ?? 0; $endNext = (int)($scheduleMap[$w]['end_next_day'] ?? 0);
                    if ($start && $end) {
                        $win = compute_schedule_window(['start_time' => $start, 'end_time' => $end, 'end_next_day' => $endNext, 'break_minutes' => $break], $dateStr);
                        if ($win) $exp = max(0, (int)(($win['end']->getTimestamp() - $win['start']->getTimestamp()) / 60));
                    }
                }
            }
            $daily[$dateStr] = ['expectedMin' => $exp, 'workedMin' => 0, 'breakMin' => 0, 'items' => [], 'holiday' => $holidays[$dateStr] ?? null];
            $totalExpectedMin += $exp;
            $dt = $dt->modify('+1 day');
        }

        // Licenças (reports.php:170-183) — mantém linhas completas (têm excuses_absence)
        $leavesByDay = [];
        $stL = $pdo->prepare("SELECT l.*, lt.paid, lt.name AS leave_type_name FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
                              WHERE l.teacher_id = ? AND l.approved = 1 AND l.end_date >= ? AND l.start_date <= ?");
        $stL->execute([$teacherId, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
        while ($lv = $stL->fetch(PDO::FETCH_ASSOC)) {
            $d0 = new DateTime($lv['start_date']); $d1 = new DateTime($lv['end_date']);
            for ($d = clone $d0; $d <= $d1; $d = $d->modify('+1 day')) $leavesByDay[$d->format('Y-m-d')][] = $lv;
        }

        // Attendance (reports.php:187-228) — cheio: work+break somam workedMin; break também em breakMin
        // NC-51: exclui anulados/substituídos — ver attendance_vigente_sql() em helpers.php
        $st = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND date BETWEEN ? AND ? AND approved = 1 AND " . attendance_vigente_sql() . " ORDER BY date ASC, check_in ASC");
        $st->execute([$teacherId, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dateStr = $row['date']; $minutes = 0;
            if (!empty($row['check_in']) && !empty($row['check_out'])) {
                $in = new DateTime($row['check_in']); $out = new DateTime($row['check_out']);
                if ($out > $in) $minutes = (int) round(($out->getTimestamp() - $in->getTimestamp()) / 60);
            }
            if (!isset($daily[$dateStr])) $daily[$dateStr] = ['expectedMin' => 0, 'workedMin' => 0, 'breakMin' => 0, 'items' => [], 'holiday' => null];
            if (!isset($daily[$dateStr]['breakMin'])) $daily[$dateStr]['breakMin'] = 0;
            if (($row['record_type'] ?? 'work') === 'break') { $daily[$dateStr]['breakMin'] += $minutes; $daily[$dateStr]['workedMin'] += $minutes; }
            else { $daily[$dateStr]['workedMin'] += $minutes; }
            $daily[$dateStr]['items'][] = $row;
        }

        // Abono zera expected (reports.php:230-240) — excuses_absence
        foreach ($daily as $k => &$d) {
            foreach ($leavesByDay[$k] ?? [] as $lv) {
                if ((int)($lv['excuses_absence'] ?? 0) === 1) { $totalExpectedMin -= $d['expectedMin']; $d['expectedMin'] = 0; break; }
            }
        }
        unset($d);

        // Janela de contagem (reports.php:245-248)
        $teacherStartDate = counting_start_for($teacher['created_at'] ?? null);
        $today = date('Y-m-d');
        $daily = apply_counting_window($daily, $teacherStartDate, $today, ['expectedMin', 'workedMin', 'effectiveMin']);

        $totalWorkedMin = array_sum(array_column($daily, 'workedMin'));
        $totalBreakMin  = array_sum(array_map(static fn($d) => (int)($d['breakMin'] ?? 0), $daily));
        $netWorked = max(0, (int)$totalWorkedMin - (int)$totalBreakMin);

        // Faltas (reports.php:280-287)
        $totalAbsences = 0;
        foreach ($daily as $date => $info) {
            $hasItems = !empty($info['items']); $expMin = (int)$info['expectedMin'];
            $isAbonado = leave_day_is_excused($leavesByDay[$date] ?? []);
            if (!$hasItems && $expMin > 0 && $date <= $today && $date >= $teacherStartDate && !$isAbonado) $totalAbsences++;
        }

        return ['worked_min' => (int)$netWorked, 'absences' => (int)$totalAbsences, 'expected_min' => (int)array_sum(array_column($daily, 'expectedMin'))];
    }
}

if (!function_exists('report_financial_totals')) {
    /**
     * Espelha reports_financial.php (financeiro).
     * worked_min = Σ trabalhado EFETIVO (com janela de contagem).
     * absences   = expected>0 && worked(bruto)==0 && janela && sem feriado && não abonado.
     */
    function report_financial_totals(PDO $pdo, array $teacher, DateTime $periodStart, DateTime $periodEnd, ?array $holidays = null): array {
        $teacherId = (int)$teacher['id'];
        $mode = $teacher['schedule_mode'] ?? 'classes';

        // Schedule map (reports_financial.php:98-120) — classes / hours / time
        $scheduleMap = [];
        if ($mode === 'classes') {
            $st = $pdo->prepare("SELECT weekday, classes_count, class_minutes FROM teacher_schedules WHERE teacher_id = ?");
            $st->execute([$teacherId]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) $scheduleMap[(int)$r['weekday']] = ['cc' => (int)$r['classes_count'], 'cm' => (int)$r['class_minutes']];
        } elseif ($mode === 'hours') {
            $st = $pdo->prepare("SELECT weekday, total_minutes, break_minutes FROM collaborator_hours_schedules WHERE teacher_id = ?");
            $st->execute([$teacherId]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) $scheduleMap[(int)$r['weekday']] = ['total' => (int)$r['total_minutes'], 'break' => (int)$r['break_minutes']];
        } else {
            $st = $pdo->prepare("SELECT weekday, start_time, end_time, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ?");
            $st->execute([$teacherId]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) $scheduleMap[(int)$r['weekday']] = ['start' => $r['start_time'], 'end' => $r['end_time'], 'break' => (int)$r['break_minutes']];
        }

        // Feriados por escola (reports_financial.php:128-129)
        $reportSchoolId = primary_school_id_for_teacher($pdo, $teacherId);
        if ($holidays === null) $holidays = get_holidays_in_period($pdo, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'), $reportSchoolId);

        // Timeline diária (reports_financial.php:131-176) — expected só em dia útil, janela COMPLETA
        $daily = [];
        $dt = clone $periodStart;
        while ($dt <= $periodEnd) {
            $dateStr = $dt->format('Y-m-d'); $w = (int)$dt->format('w'); $expectedMin = 0;
            if (is_working_day($pdo, $dateStr, $reportSchoolId)) {
                if ($mode === 'classes') {
                    $expectedMin = (int)($scheduleMap[$w]['cc'] ?? 0) * (int)($scheduleMap[$w]['cm'] ?? 0);
                } elseif ($mode === 'hours') {
                    $expectedMin = max(0, (int)($scheduleMap[$w]['total'] ?? 0));
                } else {
                    $start = $scheduleMap[$w]['start'] ?? null; $end = $scheduleMap[$w]['end'] ?? null;
                    if ($start && $end) {
                        $s = DateTime::createFromFormat('H:i:s', $start) ?: DateTime::createFromFormat('H:i', $start);
                        $e = DateTime::createFromFormat('H:i:s', $end)   ?: DateTime::createFromFormat('H:i', $end);
                        if ($s && $e) { if ($e <= $s) $e = (clone $e)->modify('+1 day'); $expectedMin = max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60)); }
                    }
                }
            }
            $daily[$dateStr] = ['expected' => $expectedMin, 'worked' => 0, 'holiday' => $holidays[$dateStr] ?? null, 'leaves' => [], 'items' => []];
            $dt = $dt->modify('+1 day');
        }

        // Licenças (reports_financial.php:179-198) — excuses_absence zera expected
        $stL = $pdo->prepare("SELECT l.*, lt.paid, lt.name as leave_type_name FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
                              WHERE l.teacher_id = ? AND l.approved = 1 AND l.end_date >= ? AND l.start_date <= ?");
        $stL->execute([$teacherId, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
        while ($lv = $stL->fetch(PDO::FETCH_ASSOC)) {
            $d0 = new DateTime($lv['start_date']); $d1 = new DateTime($lv['end_date']);
            for ($d = clone $d0; $d <= $d1; $d = $d->modify('+1 day')) {
                $k = $d->format('Y-m-d'); if (!isset($daily[$k])) continue;
                $daily[$k]['leaves'][] = ['paid' => (int)$lv['paid'], 'excuses_absence' => (int)($lv['excuses_absence'] ?? 0)];
                if ((int)($lv['excuses_absence'] ?? 0) === 1) $daily[$k]['expected'] = 0;
            }
        }

        // Attendance (reports_financial.php:202-232) — só work conta; (int) truncado
        // NC-51: exclui anulados/substituídos — ver attendance_vigente_sql() em helpers.php
        $st = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND date BETWEEN ? AND ? AND approved = 1 AND " . attendance_vigente_sql() . " ORDER BY date ASC, id ASC");
        $st->execute([$teacherId, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $d = $r['date']; $minutes = 0;
            if (!empty($r['check_in']) && !empty($r['check_out'])) {
                $in = new DateTime($r['check_in']); $out = new DateTime($r['check_out']);
                if ($out > $in) $minutes = (int)(($out->getTimestamp() - $in->getTimestamp()) / 60);
            }
            if (!isset($daily[$d])) continue;
            $worked = (($r['record_type'] ?? 'work') === 'break') ? 0 : $minutes;
            $daily[$d]['worked'] += $worked;
            $daily[$d]['items'][] = $r;
        }

        // Janela de contagem (reports_financial.php:249-252)
        $today = date('Y-m-d');
        $teacherStartDate = counting_start_for($teacher['created_at'] ?? null);
        $daily = apply_counting_window($daily, $teacherStartDate, $today);

        // Trabalhado total = EFETIVO (reports_financial.php:258-273)
        $totalWorked = 0;
        foreach ($daily as $d => $info) {
            if ($d < $teacherStartDate || $d > $today) continue;
            if ((int)$info['expected'] === 0 && empty($info['items'])) { $totalWorked += (int)$info['worked']; continue; }
            $totalWorked += calculate_effective_worked_minutes($pdo, $teacherId, $d);
        }

        // Faltas (reports_financial.php:291-295)
        $totalAbsences = 0;
        foreach ($daily as $d => $v) {
            if ($v['expected'] > 0 && $v['worked'] == 0 && $d <= $today && $d >= $teacherStartDate && empty($v['holiday']) && !leave_day_is_excused($v['leaves'])) $totalAbsences++;
        }

        return ['worked_min' => (int)$totalWorked, 'absences' => (int)$totalAbsences, 'expected_min' => (int)array_sum(array_column($daily, 'expected'))];
    }
}

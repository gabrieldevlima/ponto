<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);

// Entrada
$month = $_GET['month'] ?? '';
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$export = $_GET['export'] ?? ''; // 'csv' | 'xlsx' | 'pdf'

// Defaults
if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');
}

// Escopo
list($scopeSql, $scopeParams) = admin_scope_where('t');

// Carrega colaborador (respeita escopo)
$st = $pdo->prepare("SELECT t.*, ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ? AND $scopeSql");
$st->execute(array_merge([$teacherId], $scopeParams));
$teacher = $st->fetch(PDO::FETCH_ASSOC);
if ($teacherId && !$teacher) {
    http_response_code(403);
    exit('Sem permissão para ver este colaborador.');
}

function minutes_to_hhmm(int $m): string
{
    $neg = $m < 0;
    $m = abs($m);
    $s = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    return $neg ? "-$s" : $s;
}

// Mapa de jornada esperada (semana)
$scheduleMap = [];
if ($teacher) {
    if (($teacher['schedule_mode'] ?? 'classes') === 'classes') {
        $st = $pdo->prepare("SELECT weekday, classes_count, class_minutes FROM teacher_schedules WHERE teacher_id = ?");
        $st->execute([$teacher['id']]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$r['weekday']] = ['cc' => (int)$r['classes_count'], 'cm' => (int)$r['class_minutes']];
        }
    } else {
        $st = $pdo->prepare("SELECT weekday, start_time, end_time, break_minutes FROM collaborator_time_schedules WHERE teacher_id = ?");
        $st->execute([$teacher['id']]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$r['weekday']] = ['start' => $r['start_time'], 'end' => $r['end_time'], 'break' => (int)$r['break_minutes']];
        }
    }
}

// Período do mês
$periodStart = DateTime::createFromFormat('Y-m-d', $month . '-01');
$periodEnd = (clone $periodStart)->modify('last day of this month');

// Carrega registros do mês
$daily = [];
$holidays = get_holidays_in_period($pdo, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'), null);

$dt = clone $periodStart;
while ($dt <= $periodEnd) {
    $dateStr = $dt->format('Y-m-d');
    $w = (int)$dt->format('w');
    $expectedMin = 0;
    
    // Verifica se é dia útil
    $isWorkday = is_working_day($pdo, $dateStr, null);
    
    if ($teacher && $isWorkday) {
        if (($teacher['schedule_mode'] ?? 'classes') === 'classes') {
            $cc = (int)($scheduleMap[$w]['cc'] ?? 0);
            $cm = (int)($scheduleMap[$w]['cm'] ?? 0);
            $expectedMin = $cc * $cm;
        } else {
            $start = $scheduleMap[$w]['start'] ?? null;
            $end   = $scheduleMap[$w]['end'] ?? null;
            $break = (int)($scheduleMap[$w]['break'] ?? 0);
            if ($start && $end) {
                $s = DateTime::createFromFormat('H:i:s', $start) ?: DateTime::createFromFormat('H:i', $start);
                $e = DateTime::createFromFormat('H:i:s', $end)   ?: DateTime::createFromFormat('H:i', $end);
                if ($s && $e) {
                    if ($e <= $s) $e = (clone $e)->modify('+1 day');
                    $expectedMin = max(0, (int)(($e->getTimestamp() - $s->getTimestamp()) / 60) - $break);
                }
            }
        }
    }
    
    $daily[$dateStr] = [
        'expected' => $expectedMin, 
        'worked' => 0, 
        'in' => null, 
        'out' => null,
        'holiday' => isset($holidays[$dateStr]) ? $holidays[$dateStr] : null
    ];
    $dt = $dt->modify('+1 day');
}

// Leaves aprovadas: paid => expected=0
$leaves = [];
$stL = $pdo->prepare("SELECT l.*, lt.paid, lt.affects_bank, lt.name as leave_type_name FROM leaves l JOIN leave_types lt ON lt.id=l.type_id
                      WHERE l.teacher_id = ? AND l.approved = 1 AND l.end_date >= ? AND l.start_date <= ?");
$stL->execute([$teacher['id'] ?? 0, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
while ($lv = $stL->fetch(PDO::FETCH_ASSOC)) {
    $d0 = new DateTime($lv['start_date']);
    $d1 = new DateTime($lv['end_date']);
    for ($d = clone $d0; $d <= $d1; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        if (!isset($daily[$k])) $daily[$k] = ['expected' => 0, 'worked' => 0, 'in' => null, 'out' => null, 'leaves' => []];
        
        // Adiciona informações do afastamento
        if (!isset($daily[$k]['leaves'])) $daily[$k]['leaves'] = [];
        $daily[$k]['leaves'][] = [
            'type' => $lv['leave_type_name'],
            'paid' => (int)$lv['paid'],
            'cid_code' => $lv['cid_code'] ?? ''
        ];
        
        if ((int)$lv['paid'] === 1) {
            $daily[$k]['expected'] = 0;
        }
    }
    $leaves[] = $lv;
}

if ($teacher) {
    // SOMENTE REGISTROS APROVADOS
    $st = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND date BETWEEN ? AND ? AND approved = 1 ORDER BY date ASC, id ASC");
    $st->execute([$teacher['id'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $d = $r['date'];
        $worked = 0;
        if (!empty($r['check_in']) && !empty($r['check_out'])) {
            $in = new DateTime($r['check_in']);
            $out = new DateTime($r['check_out']);
            if ($out > $in) $worked = (int)(($out->getTimestamp() - $in->getTimestamp()) / 60);
        }
        if (!isset($daily[$d])) $daily[$d] = ['expected' => 0, 'worked' => 0, 'in' => null, 'out' => null];
        $daily[$d]['worked'] += $worked;

        // Primeira entrada aprovada do dia
        if ($r['check_in'] && ($daily[$d]['in'] === null || $r['check_in'] < $daily[$d]['in'])) {
            $daily[$d]['in'] = $r['check_in'];
        }
        // Última saída aprovada do dia
        if ($r['check_out'] && ($daily[$d]['out'] === null || $r['check_out'] > $daily[$d]['out'])) {
            $daily[$d]['out'] = $r['check_out'];
        }
    }
}

$totalExpected = array_sum(array_column($daily, 'expected'));
$totalWorked = array_sum(array_column($daily, 'worked'));

// Verifica se professor usa sistema de grade horária (múltiplos check-ins por período)
$usesPeriodSystem = false;
if ($teacher) {
    $usesPeriodSystem = teacher_uses_period_system((int)$teacher['id']);
}

// Para professores com grade horária: pagamento SEMPRE baseado em expected (fixo)
// Horas trabalhadas servem apenas para controle de presença
if ($usesPeriodSystem) {
    // Pagamento fixo baseado no número de aulas, não no tempo total
    $deltaMin = 0; // Não considera diferenças para cálculo de extras/descontos
    $minuteValue = ($totalExpected > 0) ? ((float)($teacher['base_salary'] ?? 0) / (float)$totalExpected) : 0.0;
    $extrasMin = 0;
    $deficitMin = 0;
    $extraPay = 0;
    $discountPay = 0;
    
    // HORAS EXTRAS APROVADAS (check-ins fora da grade horária)
    // Para professores com grade: extras vêm APENAS de overtime_requests aprovadas
    $approvedOvertime = calculate_approved_overtime(
        (int)$teacher['id'],
        $periodStart->format('Y-m-d'),
        $periodEnd->format('Y-m-d')
    );
    
    $overtimeMinutes = $approvedOvertime['total_minutes'];
    $overtimeHours = $approvedOvertime['total_hours'];
    $overtimeMultiplier = (float)get_overtime_setting('multiplier', '1.5');
    $overtimePay = calculate_overtime_payment(
        $overtimeMinutes,
        (float)($teacher['base_salary'] ?? 0),
        $totalExpected,
        $overtimeMultiplier
    );
} else {
    // Sistema tradicional: calcula extras/descontos baseado em tempo trabalhado
    $deltaMin = $totalWorked - $totalExpected;
    $minuteValue = ($totalExpected > 0) ? ((float)($teacher['base_salary'] ?? 0) / (float)$totalExpected) : 0.0;
    $extrasMin = max(0, $deltaMin);
    $deficitMin = max(0, -$deltaMin);
    $extraPay = $extrasMin * $minuteValue * 1.5;
    $discountPay = $deficitMin * $minuteValue * 1.0;
    
    // Para sistema tradicional, overtime também pode existir
    $approvedOvertime = calculate_approved_overtime(
        (int)$teacher['id'],
        $periodStart->format('Y-m-d'),
        $periodEnd->format('Y-m-d')
    );
    $overtimeMinutes = $approvedOvertime['total_minutes'];
    $overtimeHours = $approvedOvertime['total_hours'];
    $overtimeMultiplier = (float)get_overtime_setting('multiplier', '1.5');
    $overtimePay = calculate_overtime_payment(
        $overtimeMinutes,
        (float)($teacher['base_salary'] ?? 0),
        $totalExpected,
        $overtimeMultiplier
    );
}

// Conta faltas (dias com jornada prevista, sem registro, já passados, após data de criação)
$totalAbsences = 0;
$today = date('Y-m-d');
$teacherStartDate = isset($teacher['created_at']) ? date('Y-m-d', strtotime($teacher['created_at'])) : '1900-01-01';
foreach ($daily as $d => $v) {
    if (($v['expected'] > 0) && ($v['worked'] == 0) && ($d <= $today) && ($d >= $teacherStartDate) && empty($v['holiday'])) {
        $totalAbsences++;
    }
}

// Exports
if ($export === 'xlsx' && $teacher) {
    if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Financeiro');
            $sheet->fromArray(['Colaborador', 'Mês', 'Salário base', 'Min esperados', 'Min trabalhados', 'Delta', 'Valor/min', 'Extras (min)', 'Adic. Extras (R$)', 'Déficit (min)', 'Descontos (R$)'], null, 'A1');
            $sheet->fromArray([
                $teacher['name'],
                $month,
                number_format((float)$teacher['base_salary'], 2, ',', '.'),
                $totalExpected,
                $totalWorked,
                $deltaMin,
                number_format($minuteValue, 4, ',', '.'),
                $extrasMin,
                number_format($extraPay, 2, ',', '.'),
                $deficitMin,
                number_format($discountPay, 2, ',', '.')
            ], null, 'A2');
            $sheet->fromArray(['Data', 'Esperado (min)', 'Trabalhado (min)', 'Entrada', 'Saída'], null, 'A4');
            $row = 5;
            foreach ($daily as $d => $v) {
                $sheet->fromArray([$d, $v['expected'], $v['worked'], $v['in'] ?: '-', $v['out'] ?: '-'], null, 'A' . $row);
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
            $dompdf->stream('financeiro_' . $teacher['id'] . '_' . $month . '.pdf');
            exit;
        } catch (Throwable $e) {
            // fallback
        }
    }
}

if ($export === 'csv' && $teacher) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=financeiro_' . $teacher['id'] . '_' . $month . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Colaborador', 'Mês', 'Salário base', 'Min esperados', 'Min trabalhados', 'Delta', 'Valor/min', 'Extras (min)', 'Adic. Extras (R$)', 'Déficit (min)', 'Descontos (R$)'], ';');
    fputcsv($out, [
        $teacher['name'],
        $month,
        number_format((float)$teacher['base_salary'], 2, ',', '.'),
        $totalExpected,
        $totalWorked,
        $deltaMin,
        number_format($minuteValue, 4, ',', '.'),
        $extrasMin,
        number_format($extraPay, 2, ',', '.'),
        $deficitMin,
        number_format($discountPay, 2, ',', '.')
    ], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Data', 'Esperado (min)', 'Trabalhado (min)', 'Entrada', 'Saída'], ';');
    foreach ($daily as $d => $v) {
        fputcsv($out, [$d, $v['expected'], $v['worked'], $v['in'] ?: '-', $v['out'] ?: '-'], ';');
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <title>Relatório Financeiro Mensal | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
    <?php include __DIR__ . '/_navbar.php'; ?>
    <div class="container">
        <div class="card mb-3">
            <div class="card-body">
                <form class="row g-3" method="get" autocomplete="off">
                    <div class="col-md-3">
                        <label class="form-label">Mês</label>
                        <input type="month" name="month" class="form-control" value="<?= esc($month) ?>" required aria-describedby="helpMes">
                        <div id="helpMes" class="form-text">Selecione o mês de referência (formato AAAA-MM).</div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Colaborador</label>
                        <?php
                        $listSt = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE $scopeSql ORDER BY t.name");
                        $listSt->execute($scopeParams);
                        $opts = $listSt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <select name="teacher_id" class="form-select" required aria-describedby="helpColab">
                            <option value="">Selecione</option>
                            <?php foreach ($opts as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $teacherId === (int)$t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="helpColab" class="form-text">Apenas colaboradores dentro do seu escopo são listados.</div>
                    </div>
                    <div class="col-md-4 align-self-end d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary" title="Gerar relatório com os dados selecionados">Gerar Relatório</button>
                        <?php if ($teacher): ?>
                            <a class="btn btn-outline-secondary" href="?teacher_id=<?= (int)$teacher['id'] ?>&month=<?= esc($month) ?>&export=csv" title="Exportar em CSV (planilha básica)">CSV</a>
                            <a class="btn btn-outline-secondary" href="?teacher_id=<?= (int)$teacher['id'] ?>&month=<?= esc($month) ?>&export=xlsx" title="Exportar para Excel (.xlsx)">Excel</a>
                            <a class="btn btn-outline-secondary" href="?teacher_id=<?= (int)$teacher['id'] ?>&month=<?= esc($month) ?>&export=pdf" title="Exportar em PDF">PDF</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($teacher): ?>
            <div class="alert alert-info">
                Este relatório mostra os totais do mês escolhido com base apenas em registros de ponto aprovados.
                O valor por minuto é calculado dividindo o salário base pelos minutos previstos no mês.
            </div>

            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Financeiro - <?= esc($teacher['name']) ?> - <?= esc((new DateTime($month . '-01'))->format('m/Y')) ?></h5>
                    
                    <?php if ($usesPeriodSystem): ?>
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Sistema de Grade Horária Ativo:</strong>
                        Este professor utiliza múltiplos check-ins por aula. O pagamento é sempre baseado no número de aulas cadastradas (Min. Previstos), 
                        independente do tempo total registrado. Períodos ociosos entre aulas não são contabilizados.
                    </div>
                    <?php endif; ?>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted">Salário Base</div>
                                <div class="fs-5">R$ <?= number_format((float)$teacher['base_salary'], 2, ',', '.') ?></div>
                                <div class="text-muted small">Valor fixo mensal acordado.</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted">Min. Previstos</div>
                                <div class="fs-5">
                                    <?= (int)$totalExpected ?> (<?= minutes_to_hhmm((int)$totalExpected) ?>)
                                </div>
                                <div class="text-muted small">Com base na jornada cadastrada no sistema.</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted">Min. Trabalhados</div>
                                <div class="fs-5">
                                    <?= (int)$totalWorked ?> (<?= minutes_to_hhmm((int)$totalWorked) ?>)
                                </div>
                                <div class="text-muted small">Soma dos períodos entre entrada e saída aprovados.</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 <?= $deltaMin >= 0 ? 'bg-success-subtle' : 'bg-danger-subtle' ?> h-100">
                                <div class="text-muted">Delta (min)</div>
                                <div class="fs-5">
                                    <?= (int)$deltaMin ?> (<?= minutes_to_hhmm((int)$deltaMin) ?>)
                                </div>
                                <div class="text-muted small">Trabalhados - Previstos. Positivo = extra; negativo = déficit.</div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted">Valor por Minuto</div>
                                <div class="fs-5">R$ <?= number_format($minuteValue, 4, ',', '.') ?></div>
                                <div class="text-muted small">Salário Base / Min. Previstos.</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted"><?= $usesPeriodSystem ? 'Extras (automáticos)' : 'Extras (min) / Adicional 50%' ?></div>
                                <div class="fs-5"><?= (int)$extrasMin ?> min / R$ <?= number_format($extraPay, 2, ',', '.') ?></div>
                                <div class="text-muted small"><?= $usesPeriodSystem ? 'Grade horária: sempre zero' : 'Aplicado 50% sobre o valor do minuto.' ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted">Déficit (min) / Descontos</div>
                                <div class="fs-5"><?= (int)$deficitMin ?> min / R$ <?= number_format($discountPay, 2, ',', '.') ?></div>
                                <div class="text-muted small">Descontos proporcionais ao valor do minuto.</div>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($overtimeMinutes) && $overtimeMinutes > 0): ?>
                    <!-- Horas Extras Aprovadas (fora da grade) -->
                    <div class="alert alert-success border-success">
                        <h6 class="alert-heading">
                            <i class="bi bi-clock-fill"></i> Horas Extras Aprovadas 
                            <?= $usesPeriodSystem ? '(fora da grade horária)' : '' ?>
                        </h6>
                        <div class="row g-3 mt-2">
                            <div class="col-md-3">
                                <strong>Total de Horas:</strong><br>
                                <span class="badge bg-success fs-6">
                                    <?= number_format($overtimeHours, 2) ?>h (<?= $overtimeMinutes ?> min)
                                </span>
                            </div>
                            <div class="col-md-3">
                                <strong>Multiplicador:</strong><br>
                                <span class="badge bg-info fs-6"><?= $overtimeMultiplier ?>x</span>
                            </div>
                            <div class="col-md-3">
                                <strong>Valor Adicional:</strong><br>
                                <span class="badge bg-success fs-6">R$ <?= number_format($overtimePay, 2, ',', '.') ?></span>
                            </div>
                            <div class="col-md-3">
                                <strong>Solicitações:</strong><br>
                                <span class="badge bg-secondary fs-6"><?= $approvedOvertime['total_requests'] ?></span>
                            </div>
                        </div>
                        <?php if ($usesPeriodSystem): ?>
                        <hr>
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            Professores com grade horária: horas extras vêm apenas de check-ins aprovados fora da grade atribuída
                            (reuniões, eventos, reposições, etc). Períodos ociosos entre aulas NÃO geram extras.
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($totalAbsences > 0): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                        <div>
                            <strong>Atenção: <?= $totalAbsences ?> falta(s) detectada(s) no período</strong>
                            <div class="small">Dias com jornada prevista mas sem registro de ponto (marcados na tabela abaixo)</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Resumo Final de Pagamento -->
                    <div class="card bg-primary bg-opacity-10 border-primary mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-3">
                                <i class="bi bi-calculator"></i> Resumo de Pagamento
                            </h5>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="text-muted small">Salário Base</div>
                                    <div class="fs-4 fw-bold text-primary">
                                        R$ <?= number_format((float)$teacher['base_salary'], 2, ',', '.') ?>
                                    </div>
                                </div>
                                <?php if (isset($overtimePay) && $overtimePay > 0): ?>
                                <div class="col-md-3">
                                    <div class="text-muted small">+ Horas Extras</div>
                                    <div class="fs-4 fw-bold text-success">
                                        R$ <?= number_format($overtimePay, 2, ',', '.') ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($extraPay > 0): ?>
                                <div class="col-md-3">
                                    <div class="text-muted small">+ Extras (auto)</div>
                                    <div class="fs-4 fw-bold text-success">
                                        R$ <?= number_format($extraPay, 2, ',', '.') ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($discountPay > 0): ?>
                                <div class="col-md-3">
                                    <div class="text-muted small">- Descontos</div>
                                    <div class="fs-4 fw-bold text-danger">
                                        R$ <?= number_format($discountPay, 2, ',', '.') ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-3">
                                    <div class="text-muted small">= TOTAL A RECEBER</div>
                                    <div class="fs-3 fw-bold text-success">
                                        R$ <?= number_format(
                                            (float)$teacher['base_salary'] + 
                                            ($overtimePay ?? 0) + 
                                            $extraPay - 
                                            $discountPay, 
                                            2, ',', '.'
                                        ) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Previsto (min / hh:mm)</th>
                                    <th>Trabalhado (min / hh:mm)</th>
                                    <th>Primeira Entrada</th>
                                    <th>Última Saída</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily as $d => $v): ?>
                                    <?php
                                    // Só marca FALTA se: tinha jornada, não trabalhou, data já passou, não é feriado E após data de criação
                                    $isFalta = ($v['expected'] > 0) && ($v['worked'] == 0) && ($d <= date('Y-m-d')) && ($d >= $teacherStartDate) && empty($v['holiday']);
                                    ?>
                                    <tr <?= !empty($v['holiday']) ? 'class="table-danger"' : '' ?>>
                                        <td>
                                            <?= esc((new DateTime($d))->format('d/m/Y')) ?>
                                            <?php if (!empty($v['holiday'])): ?>
                                                <div class="badge bg-danger mt-1"><?= esc($v['holiday']['name']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int)$v['expected'] ?> (<?= minutes_to_hhmm((int)$v['expected']) ?>)</td>
                                        <td><?= (int)$v['worked'] ?> (<?= minutes_to_hhmm((int)$v['worked']) ?>)</td>
                                        <td><?= $v['in'] ? esc((new DateTime($v['in']))->format('H:i:s')) : '-' ?></td>
                                        <td><?= $v['out'] ? esc((new DateTime($v['out']))->format('H:i:s')) : '-' ?></td>
                                        <td>
                                            <?php if (!empty($v['leaves'])): ?>
                                                <?php foreach ($v['leaves'] as $leave): ?>
                                                    <div class="badge bg-info mb-1">
                                                        🏥 <?= esc($leave['type']) ?>
                                                        <?= $leave['paid'] ? ' (Rem.)' : '' ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php elseif ($isFalta): ?>
                                                <span class="badge bg-danger text-white">
                                                    <i class="bi bi-exclamation-triangle-fill"></i> FALTA
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td>Total</td>
                                    <td><?= (int)$totalExpected ?> (<?= minutes_to_hhmm((int)$totalExpected) ?>)</td>
                                    <td><?= (int)$totalWorked ?> (<?= minutes_to_hhmm((int)$totalWorked) ?>)</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                        
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
                                                <th>Tipo</th>
                                                <th>Período</th>
                                                <th>Dias</th>
                                                <th>Remunerado</th>
                                                <th>Impacto Financeiro</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($leaves as $lv): ?>
                                                <?php
                                                $startFmt = (new DateTime($lv['start_date']))->format('d/m/Y');
                                                $endFmt = (new DateTime($lv['end_date']))->format('d/m/Y');
                                                $daysCount = $lv['days_count'] ?? ((new DateTime($lv['start_date']))->diff(new DateTime($lv['end_date']))->days + 1);
                                                $isPaid = (int)$lv['paid'];
                                                ?>
                                                <tr>
                                                    <td><?= esc($lv['leave_type_name']) ?></td>
                                                    <td><?= esc($startFmt) ?> até <?= esc($endFmt) ?></td>
                                                    <td class="text-center"><span class="badge bg-info"><?= $daysCount ?> dia<?= $daysCount != 1 ? 's' : '' ?></span></td>
                                                    <td class="text-center"><?= $isPaid ? '<span class="badge bg-success">Sim</span>' : '<span class="badge bg-secondary">Não</span>' ?></td>
                                                    <td>
                                                        <?php if ($isPaid): ?>
                                                            <span class="text-success">✓ Sem impacto - Horas esperadas zeradas</span>
                                                        <?php else: ?>
                                                            <span class="text-warning">⚠ Não remunerado - Horas esperadas mantidas</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Importante:</strong> Afastamentos remunerados não afetam o cálculo financeiro. 
                                    O sistema zera automaticamente as horas esperadas nos dias de afastamento remunerado, 
                                    garantindo que não há desconto no salário do colaborador.
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="text-muted small mt-3">
                            <ul class="mb-0 ps-3">
                                <li>Somente registros de ponto aprovados são considerados nos cálculos.</li>
                                <li>Delta = Min. Trabalhados - Min. Previstos. Se positivo, gera extras; se negativo, gera déficit.</li>
                                <li>Extras aplicam adicional de 50% sobre o valor do minuto. Descontos são proporcionais ao valor do minuto.</li>
                                <li><strong>FALTAS:</strong> Dias com jornada prevista sem registro de ponto (apenas até hoje, dias futuros não são marcados).</li>
                            </ul>
                        </div>
                    </div>

                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inicializa tooltips se houver
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
    </script>
</body>

</html>
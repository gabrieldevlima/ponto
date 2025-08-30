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
$dt = clone $periodStart;
while ($dt <= $periodEnd) {
    $dateStr = $dt->format('Y-m-d');
    $w = (int)$dt->format('w');
    $expectedMin = 0;
    if ($teacher) {
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
    $daily[$dateStr] = ['expected' => $expectedMin, 'worked' => 0, 'in' => null, 'out' => null];
    $dt = $dt->modify('+1 day');
}

// Leaves aprovadas: paid => expected=0
$stL = $pdo->prepare("SELECT l.*, lt.paid, lt.affects_bank FROM leaves l JOIN leave_types lt ON lt.id=l.type_id
                      WHERE l.teacher_id = ? AND l.approved = 1 AND l.end_date >= ? AND l.start_date <= ?");
$stL->execute([$teacher['id'] ?? 0, $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
while ($lv = $stL->fetch(PDO::FETCH_ASSOC)) {
    $d0 = new DateTime($lv['start_date']);
    $d1 = new DateTime($lv['end_date']);
    for ($d = clone $d0; $d <= $d1; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        if (!isset($daily[$k])) $daily[$k] = ['expected' => 0, 'worked' => 0, 'in' => null, 'out' => null];
        if ((int)$lv['paid'] === 1) {
            $daily[$k]['expected'] = 0;
        }
    }
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
$deltaMin = $totalWorked - $totalExpected;
$minuteValue = ($totalExpected > 0) ? ((float)($teacher['base_salary'] ?? 0) / (float)$totalExpected) : 0.0;
$extrasMin = max(0, $deltaMin);
$deficitMin = max(0, -$deltaMin);
$extraPay = $extrasMin * $minuteValue * 1.5;
$discountPay = $deficitMin * $minuteValue * 1.0;

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
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
                <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="adminNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
                    <li class="nav-item"><a class="nav-link" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
                    <li class="nav-item"><a class="nav-link active" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
                    <li class="nav-item"><a class="nav-link" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
                    <?php if (is_network_admin($adm)): ?>
                        <li class="nav-item"><a class="nav-link" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
                        <li class="nav-item"><a class="nav-link" href="admins.php"><i class="bi bi-people"></i> Administradores</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
                </ul>
                <span class="navbar-text me-3 d-none d-lg-inline">
                    <i class="bi bi-person-circle"></i>
                    <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
            </div>
        </div>
    </nav>
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
                    <h5 class="mb-1">Financeiro - <?= esc($teacher['name']) ?> - <?= esc((new DateTime($month . '-01'))->format('m/Y')) ?></h5>
                    <small class="text-muted mb-3 d-block">Apenas registros aprovados são considerados nos cálculos</small>
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
                                <div class="text-muted">Extras (min) / Adicional 50%</div>
                                <div class="fs-5"><?= (int)$extrasMin ?> min / R$ <?= number_format($extraPay, 2, ',', '.') ?></div>
                                <div class="text-muted small">Aplicado 50% sobre o valor do minuto.</div>
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

                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Previsto (min / hh:mm)</th>
                                    <th>Trabalhado (min / hh:mm)</th>
                                    <th>Primeira Entrada</th>
                                    <th>Última Saída</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily as $d => $v): ?>
                                    <tr>
                                        <td><?= esc((new DateTime($d))->format('d/m/Y')) ?></td>
                                        <td><?= (int)$v['expected'] ?> (<?= minutes_to_hhmm((int)$v['expected']) ?>)</td>
                                        <td><?= (int)$v['worked'] ?> (<?= minutes_to_hhmm((int)$v['worked']) ?>)</td>
                                        <td><?= $v['in'] ? esc((new DateTime($v['in']))->format('H:i:s')) : '-' ?></td>
                                        <td><?= $v['out'] ? esc((new DateTime($v['out']))->format('H:i:s')) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td>Total</td>
                                    <td><?= (int)$totalExpected ?> (<?= minutes_to_hhmm((int)$totalExpected) ?>)</td>
                                    <td><?= (int)$totalWorked ?> (<?= minutes_to_hhmm((int)$totalWorked) ?>)</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                        <div class="text-muted small">
                            <ul class="mb-0 ps-3">
                                <li>Somente registros de ponto aprovados são considerados nos cálculos.</li>
                                <li>Delta = Min. Trabalhados - Min. Previstos. Se positivo, gera extras; se negativo, gera déficit.</li>
                                <li>Extras aplicam adicional de 50% sobre o valor do minuto. Descontos são proporcionais ao valor do minuto.</li>
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
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

// Verifica se está logado
if (!isset($_SESSION['collaborator_id'])) {
    header('Location: my_login.php');
    exit;
}

$collaborator_id = (int)$_SESSION['collaborator_id'];
$collaborator_name = $_SESSION['collaborator_name'] ?? 'Colaborador';

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['collaborator_id']);
    unset($_SESSION['collaborator_name']);
    header('Location: my_login.php');
    exit;
}

$pdo = db();
$tzBR = new DateTimeZone('America/Sao_Paulo');

// Mês/Ano selecionado
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$selectedMonth = max(1, min(12, $selectedMonth));
$selectedYear = max(2020, min((int)date('Y') + 1, $selectedYear));

// Busca informações do colaborador
$stmt = $pdo->prepare("SELECT t.*, ct.name as type_name, ct.schedule_mode 
                       FROM teachers t 
                       LEFT JOIN collaborator_types ct ON ct.id = t.type_id 
                       WHERE t.id = ?");
$stmt->execute([$collaborator_id]);
$collaborator = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$collaborator) {
    die('Colaborador não encontrado.');
}

$schedule_mode = $collaborator['schedule_mode'] ?? 'classes';

// Valor por hora (opcional) - pode ser adicionado futuramente na tabela teachers ou collaborator_types
// Para habilitar: ALTER TABLE teachers ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL;
$hourly_rate = (float)($collaborator['hourly_rate'] ?? 0);

// Período do mês
$startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$endDate = date('Y-m-t', strtotime($startDate));

// Busca pontos do mês
$stmt = $pdo->prepare("
    SELECT a.*, s.name as school_name
    FROM attendance a
    LEFT JOIN schools s ON s.id = a.school_id
    WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ?
    ORDER BY a.date ASC, a.check_in ASC
");
$stmt->execute([$collaborator_id, $startDate, $endDate]);
$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Busca banco de horas do mês
$stmt = $pdo->prepare("
    SELECT date, SUM(minutes) as total_minutes
    FROM hour_bank_entries
    WHERE teacher_id = ? AND date BETWEEN ? AND ?
    GROUP BY date
    ORDER BY date ASC
");
$stmt->execute([$collaborator_id, $startDate, $endDate]);
$hourBankByDate = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hourBankByDate[$row['date']] = (int)$row['total_minutes'];
}

// Busca saldo total do banco de horas
$stmt = $pdo->prepare("SELECT SUM(minutes) as total FROM hour_bank_entries WHERE teacher_id = ?");
$stmt->execute([$collaborator_id]);
$totalBankMinutes = (int)($stmt->fetchColumn() ?? 0);

// Agrupa por dia
$dayGroups = [];
foreach ($attendances as $att) {
    $date = $att['date'];
    if (!isset($dayGroups[$date])) {
        $dayGroups[$date] = [];
    }
    $dayGroups[$date][] = $att;
}

// Calcula totais
$totalWorkedMinutes = 0;
$totalApproved = 0;
$totalPending = 0;

foreach ($dayGroups as $date => $atts) {
    foreach ($atts as $att) {
        if ($att['check_in'] && $att['check_out']) {
            $ci = new DateTime($att['check_in']);
            $co = new DateTime($att['check_out']);
            if ($co > $ci) {
                $minutes = (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
                $totalWorkedMinutes += $minutes;
                
                if ($att['approved'] == 1) {
                    $totalApproved++;
                } else {
                    $totalPending++;
                }
            }
        }
    }
}

// Valor financeiro (se configurado)
$totalValue = 0;
if ($hourly_rate > 0) {
    $totalValue = ($totalWorkedMinutes / 60) * $hourly_rate;
}

$appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

function formatMinutes($minutes) {
    if ($minutes == 0) return '0h 00min';
    $h = floor(abs($minutes) / 60);
    $m = abs($minutes) % 60;
    $sign = $minutes < 0 ? '-' : '';
    return sprintf('%s%dh %02dmin', $sign, $h, $m);
}

function statusBadge($approved, $pending_reasons = null) {
    if ($approved == 1) {
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Aprovado</span>';
    }
    
    $badge = '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history"></i> Pendente</span>';
    if (!empty($pending_reasons)) {
        $reasons = json_decode($pending_reasons, true);
        if (is_array($reasons) && count($reasons) > 0) {
            $badge .= '<div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i>Motivo: ' . htmlspecialchars(implode(', ', $reasons)) . '</div>';
        }
    }
    return $badge;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Minha Folha de Ponto - <?= htmlspecialchars($collaborator_name) ?></title>
    <meta name="theme-color" content="#0d6efd">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="shortcut icon" href="img/icone-2.ico" type="image/x-icon">
    <style>
        :root {
            --bg: #f6f7fb;
        }
        body {
            background: var(--bg);
            min-height: 100vh;
            padding-bottom: 2rem;
        }
        .card {
            border-radius: 12px;
            border: 1px solid rgba(2, 6, 23, .08);
            box-shadow: 0 2px 8px rgba(2, 6, 23, .06);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card {
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            color: #0f172a;
        }
        .stat-card.primary {
            background: #0d6efd;
            color: #ffffff;
            border: none;
        }
        .stat-card.success {
            background: #198754;
            color: #ffffff;
            border: none;
        }
        .stat-card.warning {
            background: #fff3cd;
            color: #856404;
            border-color: #ffe69c;
        }
        .stat-card.info {
            background: #cff4fc;
            color: #055160;
            border-color: #b6effb;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }
        .stat-label {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Cards de Ponto */
        .point-card {
            background: rgba(255, 255, 255, .85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(2, 6, 23, .08);
        }
        .point-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(2, 6, 23, .12);
        }

        /* Caixas de Horário */
        .time-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 10px;
            padding: 0.75rem;
            border: 1px solid rgba(2, 6, 23, .06);
            text-align: center;
        }
        .time-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            font-weight: 600;
        }
        .time-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }

        /* Informações do Ponto */
        .point-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.75rem;
        }
        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
            font-size: 0.9rem;
        }
        .info-item:not(:last-child) {
            border-bottom: 1px solid rgba(2, 6, 23, .06);
        }
        .info-item i {
            font-size: 1.1rem;
            min-width: 20px;
        }
        .info-label {
            color: #64748b;
            font-weight: 500;
        }
        .info-value {
            margin-left: auto;
            color: #0f172a;
        }

        /* Badge customizado */
        .badge {
            font-weight: 600;
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
        }

        /* Responsividade */
        @media (max-width: 576px) {
            .stat-value {
                font-size: 1.5rem;
            }
            .time-value {
                font-size: 1.25rem;
            }
            .point-card {
                margin-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">
                    <i class="bi bi-calendar-check me-2"></i>
                    Minha Folha
                </h1>
                <p class="text-muted mb-0">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= htmlspecialchars($collaborator_name) ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= htmlspecialchars($appBase) ?>/index.php" class="btn btn-outline-primary">
                    <i class="bi bi-clock-history"></i>
                    <span class="d-none d-sm-inline ms-1">Registrar Ponto</span>
                </a>
                <a href="?logout=1" class="btn btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="d-none d-sm-inline ms-1">Sair</span>
                </a>
            </div>
        </div>

        <!-- Seletor de Mês -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-auto">
                        <label for="month" class="form-label fw-semibold">Mês</label>
                        <select name="month" id="month" class="form-select">
                            <?php
                            $months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                                      'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
                            foreach ($months as $i => $monthName) {
                                $m = $i + 1;
                                $selected = ($m == $selectedMonth) ? 'selected' : '';
                                echo "<option value='$m' $selected>$monthName</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label for="year" class="form-label fw-semibold">Ano</label>
                        <select name="year" id="year" class="form-select">
                            <?php
                            $currentYear = (int)date('Y');
                            for ($y = $currentYear; $y >= 2020; $y--) {
                                $selected = ($y == $selectedYear) ? 'selected' : '';
                                echo "<option value='$y' $selected>$y</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Consultar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Seção de Holerites -->
        <?php
        $stPayslips = $pdo->prepare("SELECT * FROM payslips WHERE teacher_id = ? ORDER BY reference_month DESC LIMIT 6");
        $stPayslips->execute([$collaborator_id]);
        $myPayslips = $stPayslips->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($myPayslips)):
        ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Meus Holerites</h5>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($myPayslips as $ps): 
                        $refDate = DateTime::createFromFormat('Y-m-d', $ps['reference_month']);
                        $monthName = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][(int)$refDate->format('n')];
                    ?>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="payslip.php?id=<?= (int)$ps['id'] ?>" class="btn btn-outline-success w-100" target="_blank">
                            <i class="bi bi-file-earmark-pdf-fill d-block fs-3 mb-1"></i>
                            <small><?= $monthName ?>/<?= $refDate->format('y') ?></small>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-muted small mt-3">
                    <i class="bi bi-info-circle me-1"></i>Clique para visualizar/baixar seu holerite em PDF
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card primary">
                    <div class="stat-label">
                        <i class="bi bi-clock me-1"></i>
                        Horas Trabalhadas
                    </div>
                    <div class="stat-value"><?= formatMinutes($totalWorkedMinutes) ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card success">
                    <div class="stat-label">
                        <i class="bi bi-check-circle me-1"></i>
                        Pontos Aprovados
                    </div>
                    <div class="stat-value"><?= $totalApproved ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card warning">
                    <div class="stat-label">
                        <i class="bi bi-clock-history me-1"></i>
                        Pontos Pendentes
                    </div>
                    <div class="stat-value"><?= $totalPending ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card info">
                    <div class="stat-label">
                        <i class="bi bi-piggy-bank me-1"></i>
                        Banco de Horas
                    </div>
                    <div class="stat-value" style="font-size: 1.5rem;"><?= formatMinutes($totalBankMinutes) ?></div>
                </div>
            </div>
        </div>

        <?php if ($hourly_rate > 0): ?>
        <!-- Valor Financeiro -->
        <div class="alert alert-info d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-cash-coin fs-4"></i>
            <div>
                <strong>Valor Estimado:</strong>
                R$ <?= number_format($totalValue, 2, ',', '.') ?>
                <small class="ms-2 opacity-75">(<?= number_format($hourly_rate, 2, ',', '.') ?>/hora)</small>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cards de Pontos -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">
                <i class="bi bi-list-ul me-2"></i>
                Registros de Ponto
            </h5>
            <span class="badge bg-primary"><?= count($attendances) ?> registro(s)</span>
        </div>

        <?php if (empty($dayGroups)): ?>
        <div class="card text-center py-5">
            <div class="card-body">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0">Nenhum registro encontrado neste período.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($dayGroups as $date => $atts): ?>
                <?php foreach ($atts as $att): ?>
                <div class="col-12">
                    <div class="card point-card">
                        <div class="card-body">
                            <!-- Header: Data e Status -->
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="mb-1 fw-bold">
                                        <?= date('d/m/Y', strtotime($date)) ?>
                                    </h6>
                                    <small class="text-muted">
                                        <?php 
                                        $days = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
                                        echo $days[date('w', strtotime($date))];
                                        ?>
                                    </small>
                                </div>
                                <div><?= statusBadge($att['approved'], $att['pending_reasons'] ?? null) ?></div>
                            </div>

                            <!-- Horários -->
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <div class="time-box">
                                        <div class="time-label">
                                            <i class="bi bi-box-arrow-in-right text-success me-1"></i>
                                            Entrada
                                        </div>
                                        <div class="time-value">
                                            <?php if ($att['check_in']): ?>
                                                <?= date('H:i', strtotime($att['check_in'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="time-box">
                                        <div class="time-label">
                                            <i class="bi bi-box-arrow-right text-danger me-1"></i>
                                            Saída
                                        </div>
                                        <div class="time-value">
                                            <?php if ($att['check_out']): ?>
                                                <?= date('H:i', strtotime($att['check_out'])) ?>
                                            <?php else: ?>
                                                <small class="text-muted">Em andamento</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Informações Adicionais -->
                            <div class="point-info">
                                <div class="info-item">
                                    <i class="bi bi-clock text-primary"></i>
                                    <span class="info-label">Duração:</span>
                                    <span class="info-value fw-semibold">
                                        <?php 
                                        if ($att['check_in'] && $att['check_out']) {
                                            $ci = new DateTime($att['check_in']);
                                            $co = new DateTime($att['check_out']);
                                            if ($co > $ci) {
                                                $minutes = (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
                                                echo formatMinutes($minutes);
                                            } else {
                                                echo '—';
                                            }
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </span>
                                </div>

                                <?php if ($att['school_name']): ?>
                                <div class="info-item">
                                    <i class="bi bi-geo-alt text-primary"></i>
                                    <span class="info-label">Local:</span>
                                    <span class="info-value"><?= htmlspecialchars($att['school_name']) ?></span>
                                </div>
                                <?php endif; ?>

                                <?php if (isset($hourBankByDate[$date])): ?>
                                <div class="info-item">
                                    <i class="bi bi-piggy-bank text-primary"></i>
                                    <span class="info-label">Banco de Horas:</span>
                                    <span class="info-value">
                                        <?php 
                                        $bankMin = $hourBankByDate[$date];
                                        $color = $bankMin > 0 ? 'success' : ($bankMin < 0 ? 'danger' : 'secondary');
                                        $icon = $bankMin > 0 ? 'arrow-up' : ($bankMin < 0 ? 'arrow-down' : 'dash');
                                        ?>
                                        <span class="badge bg-<?= $color ?>">
                                            <i class="bi bi-<?= $icon ?>"></i>
                                            <?= formatMinutes($bankMin) ?>
                                        </span>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Portaria 671/2021: Link para Comprovante Digital -->
                            <?php if (isset($att['nsr']) && $att['nsr']): ?>
                            <div class="mt-3 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="bi bi-file-earmark-text me-1"></i>
                                        NSR: <strong><?= htmlspecialchars($att['nsr']) ?></strong>
                                    </small>
                                    <a href="receipt.php?id=<?= $att['id'] ?>" 
                                       target="_blank" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-file-pdf me-1"></i>
                                        Ver Comprovante
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Rodapé -->
        <div class="text-center mt-4">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Dúvidas sobre seus pontos? Contate o RH/Admin.
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


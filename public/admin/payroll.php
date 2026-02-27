<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

$msg = $_GET['msg'] ?? '';

// Filtros
$filter_teacher = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 0;
$filter_month = $_GET['month'] ?? date('Y-m');

// POST: ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate_batch') {
        $month = $_POST['month'] ?? '';
        $teacher_ids = $_POST['teacher_ids'] ?? [];
        
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            header('Location: payroll.php?msg=' . urlencode('Mês inválido'));
            exit;
        }
        
        $monthDate = $month . '-01';
        $generated = 0;
        
        foreach ($teacher_ids as $tid) {
            $tid = (int)$tid;
            if ($tid <= 0) continue;
            
            try {
                // Gera holerite via procedure
                $pdo->query("CALL generate_payslip($tid, '$monthDate', " . ($_SESSION['admin_id'] ?? 'NULL') . ")");
                $generated++;
            } catch (Exception $e) {
                // Log erro mas continua
                error_log("Erro ao gerar holerite para teacher $tid: " . $e->getMessage());
            }
        }
        
        header('Location: payroll.php?month=' . urlencode($month) . '&msg=' . urlencode("$generated holerite(s) gerado(s)!"));
        exit;
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM payslips WHERE id=?")->execute([$id]);
            header('Location: payroll.php?msg=' . urlencode('Holerite excluído'));
        }
        exit;
    }
}

// Busca holerites do mês filtrado
list($scopeSql, $scopeParams) = admin_scope_where('t');

$where = ["DATE_FORMAT(p.reference_month, '%Y-%m') COLLATE utf8mb4_unicode_ci = ?"];
$params = [$filter_month];

if ($filter_teacher > 0) {
    $where[] = "p.teacher_id = ?";
    $params[] = $filter_teacher;
}

$where[] = $scopeSql;
$params = array_merge($params, $scopeParams);

$sql = "SELECT p.*, t.name as teacher_name, t.cpf as teacher_cpf
        FROM payslips p
        JOIN teachers t ON t.id = p.teacher_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.name ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$payslips = $st->fetchAll(PDO::FETCH_ASSOC);

// Lista de colaboradores para geração em lote
$teachersForBatch = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE t.active=1 AND $scopeSql ORDER BY t.name");
$teachersForBatch->execute($scopeParams);
$teachers = $teachersForBatch->fetchAll(PDO::FETCH_ASSOC);

function minutes_to_hhmm(int $min): string {
    $h = intdiv($min, 60);
    $m = $min % 60;
    return sprintf('%02d:%02d', $h, $m);
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Holerites | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="mb-4">
            <div class="p-3 p-md-4 rounded-3 border bg-white">
                <div class="d-flex align-items-start gap-3">
                    <span class="bg-success-subtle text-success rounded-circle d-inline-flex align-items-center justify-content-center" style="width:3rem;height:3rem;">
                        <i class="bi bi-receipt fs-4"></i>
                    </span>
                    <div class="flex-grow-1">
                        <h3 class="mb-1 fw-semibold">Holerites (Folha de Pagamento)</h3>
                        <p class="text-muted mb-2">
                            Gere, visualize e gerencie holerites mensais dos colaboradores.
                        </p>
                        <div class="small text-muted">
                            Cálculo automático baseado em horas trabalhadas, extras e descontos.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i><?= esc($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Filtros e Geração em Lote -->
            <div class="col-lg-4">
                <div class="card shadow-sm mb-3">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-funnel me-2"></i>Filtros
                    </div>
                    <div class="card-body">
                        <form method="get">
                            <div class="mb-3">
                                <label class="form-label">Mês/Ano</label>
                                <input type="month" class="form-control" name="month" value="<?= esc($filter_month) ?>" onchange="this.form.submit()">
                            </div>
                            <button class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Filtrar
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="card shadow-sm">
                    <div class="card-header fw-semibold bg-success text-white">
                        <i class="bi bi-lightning-charge me-2"></i>Geração em Lote
                    </div>
                    <div class="card-body">
                        <form method="post" id="batchForm">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="action" value="generate_batch">
                            
                            <div class="mb-3">
                                <label class="form-label">Mês/Ano <span class="text-danger">*</span></label>
                                <input type="month" class="form-control" name="month" value="<?= esc($filter_month) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Colaboradores</label>
                                <div class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="selectAll">
                                        <label class="form-check-label fw-bold" for="selectAll">
                                            Selecionar Todos
                                        </label>
                                    </div>
                                    <hr class="my-2">
                                    <?php foreach ($teachers as $t): ?>
                                        <div class="form-check">
                                            <input class="form-check-input teacher-checkbox" type="checkbox" name="teacher_ids[]" value="<?= (int)$t['id'] ?>" id="teacher_<?= (int)$t['id'] ?>">
                                            <label class="form-check-label" for="teacher_<?= (int)$t['id'] ?>">
                                                <?= esc($t['name']) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-magic me-1"></i>Gerar Holerites
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Lista de Holerites -->
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">
                            <i class="bi bi-list-check me-2"></i>Holerites de <?= DateTime::createFromFormat('Y-m', $filter_month)->format('m/Y') ?>
                        </span>
                        <span class="badge bg-secondary"><?= count($payslips) ?> encontrado(s)</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($payslips)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                <p>Nenhum holerite gerado para este período.</p>
                                <p class="small">Use a geração em lote para criar holerites.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Colaborador</th>
                                            <th>Salário Base</th>
                                            <th>Trabalhadas</th>
                                            <th>Extras</th>
                                            <th>Déficit</th>
                                            <th>Líquido</th>
                                            <th>Status</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payslips as $p): ?>
                                            <tr>
                                                <td>
                                                    <div><?= esc($p['teacher_name']) ?></div>
                                                    <div class="small text-muted">CPF: <?= esc($p['teacher_cpf']) ?></div>
                                                </td>
                                                <td>R$ <?= number_format((float)$p['base_salary'], 2, ',', '.') ?></td>
                                                <td><?= minutes_to_hhmm((int)$p['worked_minutes']) ?></td>
                                                <td>
                                                    <?php if ($p['overtime_minutes'] > 0): ?>
                                                        <span class="text-success">+<?= minutes_to_hhmm((int)$p['overtime_minutes']) ?></span>
                                                        <div class="small text-success">R$ <?= number_format((float)$p['overtime_value'], 2, ',', '.') ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($p['deficit_minutes'] > 0): ?>
                                                        <span class="text-danger">-<?= minutes_to_hhmm((int)$p['deficit_minutes']) ?></span>
                                                        <div class="small text-danger">R$ <?= number_format((float)$p['discount_value'], 2, ',', '.') ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-bold">R$ <?= number_format((float)$p['net_total'], 2, ',', '.') ?></td>
                                                <td>
                                                    <?php if ($p['viewed_by_teacher_at']): ?>
                                                        <span class="badge bg-info" title="Visto em <?= date('d/m/Y H:i', strtotime($p['viewed_by_teacher_at'])) ?>">
                                                            <i class="bi bi-eye-fill me-1"></i>Visto
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Não visto</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-1">
                                                        <a href="../payslip.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="Ver PDF">
                                                            <i class="bi bi-file-pdf"></i>
                                                        </a>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir este holerite?')">
                                                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.getElementById('selectAll')?.addEventListener('change', function() {
        document.querySelectorAll('.teacher-checkbox').forEach(cb => cb.checked = this.checked);
    });
    </script>
</body>
</html>


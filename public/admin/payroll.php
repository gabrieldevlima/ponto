<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

$msg = $_GET['msg'] ?? '';

// Filtros
$filter_teacher = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 0;
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_q = trim((string)($_GET['q'] ?? ''));

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
                $stPayslip = $pdo->prepare("CALL generate_payslip(?, ?, ?)");
                $stPayslip->execute([$tid, $monthDate, $_SESSION['admin_id'] ?? null]);
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
if ($filter_q !== '') {
    $where[] = "(t.name LIKE ? OR t.cpf LIKE ?)";
    $like = "%{$filter_q}%";
    $params[] = $like;
    $params[] = $like;
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
$teachersWhere = "t.active=1 AND $scopeSql";
$teachersParams = $scopeParams;
if ($filter_q !== '') {
    $teachersWhere .= " AND (t.name LIKE ? OR t.cpf LIKE ?)";
    $like = "%{$filter_q}%";
    $teachersParams[] = $like;
    $teachersParams[] = $like;
}
$teachersForBatch = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE $teachersWhere ORDER BY t.name");
$teachersForBatch->execute($teachersParams);
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
    <link rel="stylesheet" href="css/admin.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>

    <div class="container-fluid admin-content">

        <!-- Header (padrão .app-page-header) -->
        <div class="app-page-header">
            <div class="app-page-header__main">
                <div class="app-page-icon is-success"><i class="bi bi-receipt"></i></div>
                <div>
                    <h1 class="app-page-title">Holerites</h1>
                    <p class="app-page-subtitle">Gere, visualize e gerencie holerites mensais dos colaboradores.</p>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i>
                <div><?= esc($msg) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filter + Batch Row -->
        <div class="row g-3 mb-4">
            <!-- Filter -->
            <div class="col-md-4 col-lg-3">
                <section class="app-section-card h-100">
                    <header class="app-section-card__header">
                        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-calendar3"></i>Filtro</span>
                        <h2 class="app-section-card__title">Período</h2>
                    </header>
                    <div class="app-section-card__body">
                        <form method="get" class="d-flex flex-column gap-2">
                            <div>
                                <label class="form-label small text-muted mb-1">Mes/Ano</label>
                                <input type="month" class="form-control" name="month" value="<?= esc($filter_month) ?>">
                            </div>
                            <div>
                                <label class="form-label small text-muted mb-1">Colaborador</label>
                                <input type="text" class="form-control" name="q" value="<?= esc($filter_q) ?>" placeholder="Buscar por nome ou CPF">
                            </div>
                            <button class="btn btn-primary" title="Filtrar">
                                <i class="bi bi-search me-1"></i>Filtrar
                            </button>
                        </form>
                    </div>
                </section>
            </div>

            <!-- Batch Generation -->
            <div class="col-md-8 col-lg-9">
                <section class="app-section-card h-100">
                    <header class="app-section-card__header" role="button" data-bs-toggle="collapse" data-bs-target="#batchBody" style="cursor:pointer;">
                        <span class="app-section-card__eyebrow text-success" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i>Lote</span>
                        <h2 class="app-section-card__title">Geração em Lote</h2>
                        <span class="app-section-card__hint"><i class="bi bi-chevron-down"></i></span>
                    </header>
                    <div class="collapse show" id="batchBody">
                        <div class="app-section-card__body">
                            <form action="" method="post" id="batchForm">
                                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="action" value="generate_batch">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">Mes/Ano <span class="text-danger">*</span></label>
                                        <input type="month" class="form-control" name="month" value="<?= esc($filter_month) ?>" required>
                                    </div>
                                    <div class="col-md-7">
                                        <label class="form-label small fw-semibold">Colaboradores</label>
                                        <div class="border rounded p-2 bg-light" style="max-height:160px;overflow-y:auto;">
                                            <div class="form-check mb-1">
                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                                <label class="form-check-label fw-bold small" for="selectAll">Selecionar Todos</label>
                                            </div>
                                            <hr class="my-1">
                                            <?php foreach ($teachers as $t): ?>
                                                <div class="form-check py-0">
                                                    <input class="form-check-input teacher-checkbox" type="checkbox"
                                                           name="teacher_ids[]" value="<?= (int)$t['id'] ?>"
                                                           id="teacher_<?= (int)$t['id'] ?>">
                                                    <label class="form-check-label small" for="teacher_<?= (int)$t['id'] ?>">
                                                        <?= esc(mb_convert_case($t['name'], MB_CASE_TITLE, 'UTF-8')) ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-grid">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-magic me-1"></i>Gerar
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Payslips Table -->
        <section class="app-section-card app-table-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-list-check"></i>Lista</span>
                <h2 class="app-section-card__title">Holerites de <?= DateTime::createFromFormat('Y-m', $filter_month)->format('m/Y') ?></h2>
                <span class="app-section-card__hint"><?= count($payslips) ?> registro(s)</span>
            </header>
            <div class="table-responsive">
                <?php if (empty($payslips)): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        <p class="mb-1">Nenhum holerite gerado para este periodo.</p>
                        <p class="small">Use a geracao em lote para criar holerites.</p>
                    </div>
                <?php else: ?>
                    <table class="table table-bordered table-hover align-middle table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Colaborador</th>
                                <th scope="col" class="text-end">Salario Base</th>
                                <th scope="col" class="text-center">Trabalhadas</th>
                                <th scope="col" class="text-end fw-bold text-primary">Liquido</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-center" style="width:80px;">Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payslips as $p): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= esc(mb_convert_case($p['teacher_name'], MB_CASE_TITLE, 'UTF-8')) ?></div>
                                        <div class="small text-muted">CPF: <?= esc($p['teacher_cpf']) ?></div>
                                    </td>
                                    <td class="text-end">R$ <?= number_format((float)$p['base_salary'], 2, ',', '.') ?></td>
                                    <td class="text-center small"><?= minutes_to_hhmm((int)$p['worked_minutes']) ?></td>
                                    <td class="text-end fw-bold text-primary fs-6">R$ <?= number_format((float)$p['net_total'], 2, ',', '.') ?></td>
                                    <td class="text-center">
                                        <?php if ($p['viewed_by_teacher_at']): ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle"
                                                  title="Visto em <?= date('d/m/Y H:i', strtotime($p['viewed_by_teacher_at'])) ?>">
                                                <i class="bi bi-eye-fill me-1"></i>Visto
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                <i class="bi bi-eye-slash me-1"></i>Nao visto
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-label="Ações">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li>
                                                    <a class="dropdown-item" href="../payslip.php?id=<?= (int)$p['id'] ?>" target="_blank">
                                                        <i class="bi bi-file-pdf me-2 text-danger"></i>Ver Holerite
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button class="dropdown-item text-danger"
                                                            onclick="deletePayslip(<?= (int)$p['id'] ?>, '<?= esc(mb_convert_case($p['teacher_name'], MB_CASE_TITLE, 'UTF-8')) ?>')">
                                                        <i class="bi bi-trash me-2"></i>Excluir
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <td colspan="3" class="text-end small text-muted">Total Liquido (<?= count($payslips) ?> holerites):</td>
                                <td class="text-end fw-bold text-success">
                                    R$ <?= number_format(array_sum(array_column($payslips, 'net_total')), 2, ',', '.') ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>
        </section>

    </div>

    <!-- Hidden delete form -->
    <form action="" method="post" id="deleteForm" style="display:none">
        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script>
        document.getElementById('selectAll')?.addEventListener('change', function () {
            document.querySelectorAll('.teacher-checkbox').forEach(cb => cb.checked = this.checked);
        });
        function deletePayslip(id, name) {
            if (!confirm('Excluir holerite de ' + name + '?')) return;
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    </script>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

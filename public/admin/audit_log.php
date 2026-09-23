<?php
/**
 * LOG DE AUDITORIA - PORTARIA MTP 671/2021
 * 
 * Visualização de todas as alterações administrativas em registros de ponto
 * Conformidade com Portaria MTP 671/2021
 */

require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// Filtros
$filterAction = isset($_GET['action']) ? $_GET['action'] : '';
$filterAttendanceId = isset($_GET['attendance_id']) ? (int)$_GET['attendance_id'] : 0;
$filterAdminId = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 0;
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Paginação
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Construir query
$where = [];
$params = [];

if ($filterAction) {
    $where[] = "al.action = ?";
    $params[] = $filterAction;
}
if ($filterAttendanceId > 0) {
    $where[] = "al.attendance_id = ?";
    $params[] = $filterAttendanceId;
}
if ($filterAdminId > 0) {
    $where[] = "al.admin_id = ?";
    $params[] = $filterAdminId;
}
if ($filterDateFrom) {
    $where[] = "DATE(al.created_at) >= ?";
    $params[] = $filterDateFrom;
}
if ($filterDateTo) {
    $where[] = "DATE(al.created_at) <= ?";
    $params[] = $filterDateTo;
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Source unificada: attendance_audit_log (preferida) UNION ALL attendance_edits (legado).
// Permite ver histórico de edições registradas antes do helper log_attendance_audit
// começar a alimentar a tabela canônica.
$unifiedSource = "
    SELECT
        al.id,
        al.attendance_id,
        al.admin_id,
        al.action,
        al.field_changed,
        al.old_value,
        al.new_value,
        al.reason,
        al.ip_address,
        al.user_agent,
        al.created_at
      FROM attendance_audit_log al
    UNION ALL
    SELECT
        ae.id + 1000000000 AS id,                  -- evita colisão de PK na exibição
        ae.attendance_id,
        ae.edited_by AS admin_id,
        'UPDATE' AS action,
        ae.changed_fields AS field_changed,        -- JSON array de campos
        ae.before_json AS old_value,
        ae.after_json AS new_value,
        ae.reason,
        NULL AS ip_address,
        NULL AS user_agent,
        ae.edited_at AS created_at
      FROM attendance_edits ae
";

// Buscar logs
$stmt = $pdo->prepare("
    SELECT
        al.*,
        adm.username as admin_username,
        t.name as teacher_name
    FROM ($unifiedSource) al
    LEFT JOIN admins adm ON adm.id = al.admin_id
    LEFT JOIN attendance a ON a.id = al.attendance_id
    LEFT JOIN teachers t ON t.id = a.teacher_id
    $whereClause
    ORDER BY al.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar total
$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM ($unifiedSource) al
    $whereClause
");
$stmtCount->execute($params);
$totalLogs = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

// Buscar lista de admins para filtro
$admins = $pdo->query("SELECT id, username FROM admins ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Log de Auditoria | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .code-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 8px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-row-UPDATE td:first-child { border-left: 3px solid #ffc107 !important; }
        .log-row-DELETE td:first-child { border-left: 3px solid #dc3545 !important; }
        .log-row-APPROVE td:first-child { border-left: 3px solid #198754 !important; }
        .log-row-REJECT td:first-child { border-left: 3px solid #dc3545 !important; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>

    <div class="container-fluid admin-content">

        <!-- Header -->
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-shield-lock"></i></div><div><h1 class="app-page-title">Log de Auditoria</h1><p class="app-page-subtitle">Portaria MTP 671/2021 — todas as alteracoes em registros de ponto sao registradas automaticamente.</p></div></div></div>

        <!-- Filters -->
        <section class="app-section-card">
            <header class="app-section-card__header" role="button" data-bs-toggle="collapse" data-bs-target="#filtersBody" style="cursor:pointer;">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-funnel"></i>Filtros</span>
                <h2 class="app-section-card__title">Buscar registros</h2>
                <span class="app-section-card__hint"><i class="bi bi-chevron-down"></i></span>
            </header>
            <div class="collapse show" id="filtersBody">
                <div class="app-section-card__body">
                    <form method="get" class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold mb-1">Acao</label>
                            <select name="action" class="form-select form-select-sm">
                                <option value="">Todas</option>
                                <option value="UPDATE"  <?= $filterAction === 'UPDATE'  ? 'selected' : '' ?>>UPDATE</option>
                                <option value="DELETE"  <?= $filterAction === 'DELETE'  ? 'selected' : '' ?>>DELETE</option>
                                <option value="APPROVE" <?= $filterAction === 'APPROVE' ? 'selected' : '' ?>>APPROVE</option>
                                <option value="REJECT"  <?= $filterAction === 'REJECT'  ? 'selected' : '' ?>>REJECT</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold mb-1">Administrador</label>
                            <select name="admin_id" class="form-select form-select-sm">
                                <option value="">Todos</option>
                                <?php foreach ($admins as $adm): ?>
                                <option value="<?= $adm['id'] ?>" <?= $filterAdminId === (int)$adm['id'] ? 'selected' : '' ?>>
                                    <?= esc($adm['username']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold mb-1" for="flt-date-from">Data Inicial</label>
                            <input type="date" name="date_from" id="flt-date-from" class="form-control form-control-sm" value="<?= esc($filterDateFrom) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold mb-1" for="flt-date-to">Data Final</label>
                            <input type="date" name="date_to" id="flt-date-to" class="form-control form-control-sm" value="<?= esc($filterDateTo) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold mb-1">ID do Registro</label>
                            <input type="number" name="attendance_id" class="form-control form-control-sm" value="<?= $filterAttendanceId ?: '' ?>" placeholder="Qualquer">
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                <i class="bi bi-search me-1"></i>Filtrar
                            </button>
                            <a href="audit_log.php" class="btn btn-outline-secondary btn-sm" title="Limpar filtros">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- Results table -->
        <section class="app-section-card app-table-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-shield-lock"></i>Auditoria</span>
                <h2 class="app-section-card__title">Registros</h2>
                <span class="app-section-card__hint"><?= number_format($totalLogs, 0, ',', '.') ?> · página <?= $page ?>/<?= max(1,$totalPages) ?></span>
            </header>
            <div class="table-responsive">
                <?php if (count($logs) === 0): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        <p class="mb-0">Nenhum registro encontrado com os filtros aplicados.</p>
                    </div>
                <?php else: ?>
                    <table class="table table-bordered table-hover align-middle table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width:60px">#</th>
                                <th scope="col" class="text-nowrap">Data/Hora</th>
                                <th scope="col">Acao</th>
                                <th scope="col">Registro</th>
                                <th scope="col">Colaborador</th>
                                <th scope="col">Campo</th>
                                <th scope="col">Admin</th>
                                <th scope="col">IP</th>
                                <th scope="col" class="text-center" style="width:60px">Det.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log):
                                $badgeColor = ['UPDATE'=>'warning text-dark','DELETE'=>'danger','APPROVE'=>'success','REJECT'=>'danger'][$log['action']] ?? 'secondary';
                            ?>
                            <tr class="log-row-<?= esc($log['action']) ?>">
                                <td class="small text-muted">#<?= $log['id'] ?></td>
                                <td class="small text-nowrap">
                                    <?= date('d/m/Y', strtotime($log['created_at'])) ?><br>
                                    <span class="text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $badgeColor ?> fw-semibold">
                                        <?= esc($log['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="attendances.php?id=<?= $log['attendance_id'] ?>" target="_blank" class="small">
                                        #<?= $log['attendance_id'] ?>
                                    </a>
                                </td>
                                <td class="small"><?= esc($log['teacher_name'] ?? 'N/A') ?></td>
                                <td class="small text-muted"><?= esc($log['field_changed'] ?? 'N/A') ?></td>
                                <td class="small"><?= esc($log['admin_username'] ?? 'Sistema') ?></td>
                                <td class="small text-muted"><?= esc($log['ip_address'] ?? 'N/A') ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#details-<?= $log['id'] ?>"
                                            title="Ver detalhes">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr class="collapse" id="details-<?= $log['id'] ?>">
                                <td colspan="9" class="bg-light p-3">
                                    <div class="row g-3">
                                        <?php if ($log['old_value']): ?>
                                        <div class="col-md-6">
                                            <div class="small fw-semibold text-danger mb-1"><i class="bi bi-dash-circle me-1"></i>Valor Anterior</div>
                                            <div class="code-box"><?= esc($log['old_value']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($log['new_value']): ?>
                                        <div class="col-md-6">
                                            <div class="small fw-semibold text-success mb-1"><i class="bi bi-plus-circle me-1"></i>Novo Valor</div>
                                            <div class="code-box"><?= esc($log['new_value']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($log['reason']): ?>
                                        <div class="col-12">
                                            <div class="small fw-semibold mb-1"><i class="bi bi-chat-text me-1"></i>Motivo/Justificativa</div>
                                            <div class="alert alert-info py-2 px-3 mb-0 small"><?= esc($log['reason']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($log['user_agent']): ?>
                                        <div class="col-12">
                                            <div class="small fw-semibold text-muted mb-1"><i class="bi bi-browser-chrome me-1"></i>User Agent</div>
                                            <div class="code-box text-muted"><?= esc($log['user_agent']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="app-table-card__footer">
                <span>Página <?= $page ?> de <?= $totalPages ?> · <?= number_format($totalLogs, 0, ',', '.') ?> registros</span>
                <nav aria-label="Paginação">
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>" aria-label="Anterior">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query($_GET) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>" aria-label="Próximo">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </section>

        <!-- Compliance note -->
        <div class="alert alert-info d-flex gap-3 mt-4">
            <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1"></i>
            <div class="small">
                <strong>Conformidade com Portaria MTP 671/2021</strong> — Este log registra automaticamente todas as alteracoes em registros de ponto, garantindo integridade e rastreabilidade conforme a legislacao trabalhista.<br>
                <span class="text-muted"><strong>Retencao:</strong> 5 anos &nbsp;|&nbsp; <strong>Integridade:</strong> Registros nao podem ser alterados ou excluidos.</span>
            </div>
        </div>

    </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

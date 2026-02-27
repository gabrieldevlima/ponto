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

// Buscar logs
$stmt = $pdo->prepare("
    SELECT 
        al.*,
        adm.username as admin_username,
        t.name as teacher_name
    FROM attendance_audit_log al
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
    FROM attendance_audit_log al
    $whereClause
");
$stmtCount->execute($params);
$totalLogs = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

// Buscar lista de admins para filtro
$admins = $pdo->query("SELECT id, username FROM admins ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log de Auditoria - Portaria 671/2021</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f8f9fa; }
        .card-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            color: white;
        }
        .log-entry {
            border-left: 4px solid #dee2e6;
            transition: all 0.3s ease;
        }
        .log-entry:hover {
            border-left-color: #0d6efd;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .log-entry.action-UPDATE { border-left-color: #ffc107; }
        .log-entry.action-DELETE { border-left-color: #dc3545; }
        .log-entry.action-APPROVE { border-left-color: #28a745; }
        .log-entry.action-REJECT { border-left-color: #dc3545; }
        .action-badge { font-weight: bold; }
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
    </style>
</head>
<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

    <div class="container-fluid py-4">

        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-shield-lock me-2"></i>
                    Log de Auditoria - Portaria MTP 671/2021
                </h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Todas as alterações em registros de ponto são registradas automaticamente conforme
                    exigência da Portaria MTP nº 671/2021.
                </p>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filtros</h6>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-3">
                        <label for="action" class="form-label">Ação</label>
                        <select name="action" id="action" class="form-select">
                            <option value="">Todas</option>
                            <option value="UPDATE" <?= $filterAction === 'UPDATE' ? 'selected' : '' ?>>UPDATE</option>
                            <option value="DELETE" <?= $filterAction === 'DELETE' ? 'selected' : '' ?>>DELETE</option>
                            <option value="APPROVE" <?= $filterAction === 'APPROVE' ? 'selected' : '' ?>>APPROVE</option>
                            <option value="REJECT" <?= $filterAction === 'REJECT' ? 'selected' : '' ?>>REJECT</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="admin_id" class="form-label">Administrador</label>
                        <select name="admin_id" id="admin_id" class="form-select">
                            <option value="">Todos</option>
                            <?php foreach ($admins as $adm): ?>
                            <option value="<?= $adm['id'] ?>" <?= $filterAdminId === (int)$adm['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($adm['username']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_from" class="form-label">Data Inicial</label>
                        <input type="date" name="date_from" id="date_from" class="form-control" value="<?= htmlspecialchars($filterDateFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="date_to" class="form-label">Data Final</label>
                        <input type="date" name="date_to" id="date_to" class="form-control" value="<?= htmlspecialchars($filterDateTo) ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Resultados -->
        <div class="card shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-list-check me-2"></i>
                    Registros de Auditoria (<?= number_format($totalLogs, 0, ',', '.') ?>)
                </h6>
                <a href="?export=excel" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-file-earmark-excel"></i> Exportar
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (count($logs) === 0): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                    <p class="text-muted mb-0">Nenhum registro de auditoria encontrado.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Data/Hora</th>
                                <th>Ação</th>
                                <th>Registro</th>
                                <th>Colaborador</th>
                                <th>Campo Alterado</th>
                                <th>Admin</th>
                                <th>IP</th>
                                <th>Detalhes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr class="log-entry action-<?= htmlspecialchars($log['action']) ?>">
                                <td><small class="text-muted">#<?= $log['id'] ?></small></td>
                                <td>
                                    <small>
                                        <?= date('d/m/Y', strtotime($log['created_at'])) ?><br>
                                        <span class="text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                                    </small>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = [
                                        'UPDATE' => 'warning',
                                        'DELETE' => 'danger',
                                        'APPROVE' => 'success',
                                        'REJECT' => 'danger'
                                    ][$log['action']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $badgeClass ?> action-badge">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="attendances.php?id=<?= $log['attendance_id'] ?>" target="_blank">
                                        #<?= $log['attendance_id'] ?>
                                    </a>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($log['teacher_name'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($log['field_changed'] ?? 'N/A') ?>
                                    </small>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($log['admin_username'] ?? 'Sistema') ?></small>
                                </td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" 
                                            data-bs-toggle="collapse" 
                                            data-bs-target="#details-<?= $log['id'] ?>">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr class="collapse" id="details-<?= $log['id'] ?>">
                                <td colspan="9" class="bg-light">
                                    <div class="p-3">
                                        <?php if ($log['old_value']): ?>
                                        <div class="mb-2">
                                            <strong>Valor Anterior:</strong>
                                            <div class="code-box mt-1"><?= htmlspecialchars($log['old_value']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($log['new_value']): ?>
                                        <div class="mb-2">
                                            <strong>Novo Valor:</strong>
                                            <div class="code-box mt-1"><?= htmlspecialchars($log['new_value']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($log['reason']): ?>
                                        <div class="mb-2">
                                            <strong>Motivo/Justificativa:</strong>
                                            <div class="alert alert-info mb-0 mt-1">
                                                <?= htmlspecialchars($log['reason']) ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($log['user_agent']): ?>
                                        <div>
                                            <strong>User Agent:</strong>
                                            <div class="code-box mt-1"><?= htmlspecialchars($log['user_agent']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-light">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>">
                                <i class="bi bi-chevron-left"></i> Anterior
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query($_GET) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>">
                                Próxima <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <div class="text-center mt-2">
                    <small class="text-muted">
                        Página <?= $page ?> de <?= $totalPages ?> (<?= number_format($totalLogs, 0, ',', '.') ?> registros)
                    </small>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Informações de Conformidade -->
        <div class="alert alert-info mt-4">
            <h6 class="alert-heading">
                <i class="bi bi-info-circle me-2"></i>
                Conformidade com Portaria MTP 671/2021
            </h6>
            <p class="mb-0">
                Este log de auditoria registra automaticamente todas as alterações realizadas em registros de ponto,
                garantindo a integridade e rastreabilidade dos dados conforme exigido pela legislação trabalhista brasileira.
            </p>
            <hr>
            <small>
                <strong>Retenção:</strong> Os registros de auditoria são mantidos por 5 anos conforme LGPD e legislação trabalhista.<br>
                <strong>Integridade:</strong> Registros de auditoria não podem ser alterados ou excluídos.
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


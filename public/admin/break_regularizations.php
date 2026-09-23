<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// POST: aprovar / rejeitar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action    = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);

    if ($action === 'approve' && $requestId > 0) {
        $adminCheckIn  = trim((string)($_POST['admin_check_in']  ?? ''));
        $adminCheckOut = trim((string)($_POST['admin_check_out'] ?? ''));
        $observation   = trim((string)($_POST['observation'] ?? ''));
        // datetime-local: 'YYYY-MM-DDTHH:MM' -> normaliza para 'YYYY-MM-DD HH:MM:00'
        $normalize = static function (string $t): string {
            if ($t === '') return '';
            $t = str_replace('T', ' ', $t);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $t)) $t .= ':00';
            return $t;
        };
        $adminCheckIn  = $normalize($adminCheckIn);
        $adminCheckOut = $normalize($adminCheckOut);
        $result = approve_break_regularization($pdo, $requestId, (int)$admin['id'], $adminCheckIn ?: null, $adminCheckOut ?: null, $observation ?: null);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        header('Location: break_regularizations.php?' . http_build_query($_GET));
        exit;
    }

    if ($action === 'reject' && $requestId > 0) {
        $reason = trim((string)($_POST['rejection_reason'] ?? ''));
        $result = reject_break_regularization($pdo, $requestId, (int)$admin['id'], $reason);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        header('Location: break_regularizations.php?' . http_build_query($_GET));
        exit;
    }
}

// Filtros
$filterStatus = $_GET['status'] ?? 'pending';
if (!in_array($filterStatus, ['', 'pending', 'approved', 'rejected'], true)) $filterStatus = 'pending';

list($scopeSql, $scopeParams) = admin_scope_where('t');

$where  = ["1=1"];
$params = [];
if ($filterStatus !== '') { $where[] = "abr.status = ?"; $params[] = $filterStatus; }
$where[] = $scopeSql;
$params = array_merge($params, $scopeParams);
$whereSql = implode(' AND ', $where);

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$sql = "SELECT abr.*,
               t.name AS teacher_name,
               s.name AS school_name,
               adm.username AS approved_by_username
        FROM attendance_break_requests abr
        JOIN teachers t ON t.id = abr.teacher_id
        LEFT JOIN schools s ON s.id = abr.school_id
        LEFT JOIN admins adm ON adm.id = abr.approved_by_admin_id
        WHERE $whereSql
        ORDER BY abr.status = 'pending' DESC, abr.date DESC, abr.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stRecords = $pdo->prepare($sql);
$stRecords->execute($params);
$records = $stRecords->fetchAll(PDO::FETCH_ASSOC);

$stStats = $pdo->prepare("SELECT
    SUM(CASE WHEN abr.status='pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN abr.status='approved' THEN 1 ELSE 0 END) AS approved_count,
    SUM(CASE WHEN abr.status='rejected' THEN 1 ELSE 0 END) AS rejected_count
FROM attendance_break_requests abr
JOIN teachers t ON t.id = abr.teacher_id
WHERE $scopeSql");
$stStats->execute($scopeParams);
$stats = $stStats->fetch(PDO::FETCH_ASSOC) ?: ['pending_count'=>0,'approved_count'=>0,'rejected_count'=>0];

$flashMsg = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Correções de Intervalo | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

  <div class="container-fluid admin-content">
    <div class="app-page-header">
      <div class="app-page-header__main">
        <div class="app-page-icon is-info"><i class="bi bi-pause-circle"></i></div>
        <div>
          <h1 class="app-page-title">Correções de Intervalo</h1>
          <p class="app-page-subtitle">Solicitações de colaboradores para corrigir início/retorno de intervalos.</p>
        </div>
      </div>
      <span class="app-info-badge">
        <i class="bi bi-hourglass-split"></i>
        <strong class="ms-1"><?= (int)$stats['pending_count'] ?></strong> pendente(s)
      </span>
    </div>

    <?php if ($flashMsg): ?>
      <div class="alert alert-<?= esc($flashType ?: 'info') ?> alert-dismissible fade show" role="alert">
        <?= esc($flashMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
      </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <div class="card stat-card warning">
          <div class="card-body">
            <div class="small text-muted text-uppercase">Pendentes</div>
            <div class="display-6 fw-bold"><?= (int)$stats['pending_count'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="card stat-card success">
          <div class="card-body">
            <div class="small text-muted text-uppercase">Aprovadas</div>
            <div class="display-6 fw-bold"><?= (int)$stats['approved_count'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="card stat-card danger">
          <div class="card-body">
            <div class="small text-muted text-uppercase">Rejeitadas</div>
            <div class="display-6 fw-bold"><?= (int)$stats['rejected_count'] ?></div>
          </div>
        </div>
      </div>
    </div>

    <form method="get" class="d-flex gap-2 align-items-end mb-3">
      <div>
        <label class="form-label small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="pending"  <?= $filterStatus === 'pending'  ? 'selected' : '' ?>>Pendentes</option>
          <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Aprovadas</option>
          <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejeitadas</option>
          <option value=""         <?= $filterStatus === ''         ? 'selected' : '' ?>>Todas</option>
        </select>
      </div>
    </form>

    <?php if (empty($records)): ?>
      <div class="alert alert-light text-center">Nenhuma solicitação no filtro atual.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>Colaborador</th>
              <th>Data do intervalo</th>
              <th>Original</th>
              <th>Proposto</th>
              <th>Justificativa</th>
              <th>Status</th>
              <th class="text-end">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $r):
              $origIn  = $r['original_check_in']  ? date('H:i', strtotime($r['original_check_in']))  : '—';
              $origOut = $r['original_check_out'] ? date('H:i', strtotime($r['original_check_out'])) : '—';
              $propIn  = date('H:i', strtotime($r['proposed_check_in']));
              $propOut = date('H:i', strtotime($r['proposed_check_out']));
            ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= esc($r['teacher_name']) ?></div>
                  <?php if ($r['school_name']): ?><small class="text-muted"><?= esc($r['school_name']) ?></small><?php endif; ?>
                </td>
                <td><?= esc(date('d/m/Y', strtotime($r['date']))) ?></td>
                <td><span class="text-muted"><?= esc($origIn) ?> → <?= esc($origOut) ?></span></td>
                <td><strong><?= esc($propIn) ?> → <?= esc($propOut) ?></strong></td>
                <td style="max-width:280px;"><small><?= esc((string)$r['justification']) ?></small></td>
                <td>
                  <?php if ($r['status'] === 'pending'): ?>
                    <span class="badge bg-warning text-dark">Pendente</span>
                  <?php elseif ($r['status'] === 'approved'): ?>
                    <span class="badge bg-success">Aprovada</span>
                  <?php else: ?>
                    <span class="badge bg-danger">Rejeitada</span>
                    <?php if (!empty($r['rejection_reason'])): ?>
                      <div class="small text-muted mt-1">Motivo: <?= esc((string)$r['rejection_reason']) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <?php if ($r['status'] === 'pending'): ?>
                    <button type="button" class="btn btn-sm btn-success me-1"
                            data-bs-toggle="modal" data-bs-target="#approveModal"
                            data-request-id="<?= (int)$r['id'] ?>"
                            data-prop-in="<?= esc(substr((string)$r['proposed_check_in'], 0, 16)) ?>"
                            data-prop-out="<?= esc(substr((string)$r['proposed_check_out'], 0, 16)) ?>"
                            data-teacher="<?= esc($r['teacher_name']) ?>">
                      <i class="bi bi-check2"></i> Aprovar
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#rejectModal"
                            data-request-id="<?= (int)$r['id'] ?>"
                            data-teacher="<?= esc($r['teacher_name']) ?>">
                      <i class="bi bi-x"></i> Rejeitar
                    </button>
                  <?php else: ?>
                    <small class="text-muted">
                      <?= $r['approved_at'] ? esc(date('d/m/Y H:i', strtotime($r['approved_at']))) : '—' ?>
                      <?php if ($r['approved_by_username']): ?> por <?= esc((string)$r['approved_by_username']) ?><?php endif; ?>
                    </small>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Modal: Aprovar -->
  <div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="request_id" id="apvRequestId">
          <div class="modal-header">
            <h5 class="modal-title">Aprovar correção de intervalo</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="small text-muted">Colaborador: <strong id="apvTeacher">—</strong></p>
            <p class="small">Você pode aceitar os horários propostos ou ajustar antes de aprovar.</p>
            <div class="row g-3">
              <div class="col-6">
                <label class="form-label">Início do intervalo</label>
                <input type="datetime-local" name="admin_check_in" id="apvCheckIn" class="form-control" required>
              </div>
              <div class="col-6">
                <label class="form-label">Retorno do intervalo</label>
                <input type="datetime-local" name="admin_check_out" id="apvCheckOut" class="form-control" required>
              </div>
              <div class="col-12">
                <label class="form-label">Observação (opcional)</label>
                <textarea name="observation" class="form-control" rows="2" maxlength="500" placeholder="Visível ao colaborador"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success"><i class="bi bi-check2 me-1"></i>Aprovar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal: Rejeitar -->
  <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="request_id" id="rejRequestId">
          <div class="modal-header">
            <h5 class="modal-title">Rejeitar solicitação</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="small text-muted">Colaborador: <strong id="rejTeacher">—</strong></p>
            <div class="mb-2">
              <label class="form-label">Motivo da rejeição <span class="text-danger">*</span></label>
              <textarea name="rejection_reason" class="form-control" rows="3" maxlength="255" required placeholder="Será visível ao colaborador"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-danger"><i class="bi bi-x me-1"></i>Rejeitar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('approveModal')?.addEventListener('show.bs.modal', function(ev) {
      const btn = ev.relatedTarget;
      document.getElementById('apvRequestId').value = btn.dataset.requestId;
      document.getElementById('apvCheckIn').value  = btn.dataset.propIn  || '';
      document.getElementById('apvCheckOut').value = btn.dataset.propOut || '';
      document.getElementById('apvTeacher').textContent = btn.dataset.teacher || '—';
    });
    document.getElementById('rejectModal')?.addEventListener('show.bs.modal', function(ev) {
      const btn = ev.relatedTarget;
      document.getElementById('rejRequestId').value = btn.dataset.requestId;
      document.getElementById('rejTeacher').textContent = btn.dataset.teacher || '—';
    });
  </script>
</body>
</html>

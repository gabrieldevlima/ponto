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
        $adminCheckOut = trim((string)($_POST['admin_check_out'] ?? ''));
        $observation   = trim((string)($_POST['observation'] ?? ''));
        // datetime-local: 'YYYY-MM-DDTHH:MM' -> normaliza para 'YYYY-MM-DD HH:MM:00'
        if ($adminCheckOut !== '') {
            $adminCheckOut = str_replace('T', ' ', $adminCheckOut);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $adminCheckOut)) {
                $adminCheckOut .= ':00';
            }
        }
        $result = approve_checkout_regularization($pdo, $requestId, (int)$admin['id'], $adminCheckOut ?: null, $observation ?: null);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        header('Location: checkout_regularizations.php?' . http_build_query($_GET));
        exit;
    }

    if ($action === 'reject' && $requestId > 0) {
        $reason = trim((string)($_POST['rejection_reason'] ?? ''));
        $result = reject_checkout_regularization($pdo, $requestId, (int)$admin['id'], $reason);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        header('Location: checkout_regularizations.php?' . http_build_query($_GET));
        exit;
    }
}

// Filtros
$filterStatus   = $_GET['status'] ?? 'pending';
if (!in_array($filterStatus, ['', 'pending', 'approved', 'rejected'], true)) $filterStatus = 'pending';
$filterTeacher  = (int)($_GET['teacher'] ?? 0);
$filterSchool   = (int)($_GET['school'] ?? 0);
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to'] ?? '';

list($scopeSql, $scopeParams) = admin_scope_where('t');

$where  = ["1=1"];
$params = [];
if ($filterStatus !== '') { $where[] = "acr.status = ?"; $params[] = $filterStatus; }
if ($filterTeacher > 0)   { $where[] = "acr.teacher_id = ?"; $params[] = $filterTeacher; }
if ($filterSchool > 0 && is_network_admin($admin)) { $where[] = "acr.school_id = ?"; $params[] = $filterSchool; }
if ($filterDateFrom !== '') { $where[] = "acr.date >= ?"; $params[] = $filterDateFrom; }
if ($filterDateTo !== '')   { $where[] = "acr.date <= ?"; $params[] = $filterDateTo; }
$where[] = $scopeSql;
$params = array_merge($params, $scopeParams);
$whereSql = implode(' AND ', $where);

// Paginacao
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$stCount = $pdo->prepare("SELECT COUNT(*) FROM attendance_checkout_requests acr JOIN teachers t ON t.id = acr.teacher_id WHERE $whereSql");
$stCount->execute($params);
$totalRecords = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

$sql = "SELECT acr.*,
               t.name AS teacher_name,
               s.name AS school_name,
               adm.username AS approved_by_username
        FROM attendance_checkout_requests acr
        JOIN teachers t ON t.id = acr.teacher_id
        LEFT JOIN schools s ON s.id = acr.school_id
        LEFT JOIN admins adm ON adm.id = acr.approved_by_admin_id
        WHERE $whereSql
        ORDER BY acr.status = 'pending' DESC, acr.date DESC, acr.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stRecords = $pdo->prepare($sql);
$stRecords->execute($params);
$records = $stRecords->fetchAll(PDO::FETCH_ASSOC);

// Estatisticas
$stStats = $pdo->prepare("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN acr.status='pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN acr.status='approved' THEN 1 ELSE 0 END) AS approved_count,
    SUM(CASE WHEN acr.status='rejected' THEN 1 ELSE 0 END) AS rejected_count
FROM attendance_checkout_requests acr
JOIN teachers t ON t.id = acr.teacher_id
WHERE $scopeSql");
$stStats->execute($scopeParams);
$stats = $stStats->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'pending_count'=>0,'approved_count'=>0,'rejected_count'=>0];

// Listas para filtros
$stTeachers = $pdo->prepare("SELECT DISTINCT t.id, t.name FROM teachers t WHERE $scopeSql ORDER BY t.name");
$stTeachers->execute($scopeParams);
$teachers = $stTeachers->fetchAll(PDO::FETCH_ASSOC);

$schools = [];
if (is_network_admin($admin)) {
    $schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Flash message
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType    = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Regularizações de Saída | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <style>
    body { background-color: #f8f9fa; }
    .navbar-brand { font-weight: bold; }
    .card-hover { transition: transform .15s ease, box-shadow .15s ease; }
    .card-hover:hover { transform: translateY(-2px); box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,.08) !important; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

  <main class="container py-4">
    <?php if ($flashMessage): ?>
    <div class="alert alert-<?= esc($flashType) ?> alert-dismissible fade show" role="alert">
      <?= esc($flashMessage) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="bg-primary bg-gradient rounded-3 text-white p-4 mb-4 shadow-sm">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <h1 class="h3 mb-1"><i class="bi bi-clock-history"></i> Regularizações de Saída</h1>
          <p class="mb-0 opacity-75">Solicitações de colaboradores para fechar pontos sem saída registrada. Justificativa e horário informados pelo próprio colaborador.</p>
        </div>
      </div>
    </div>

    <!-- Estatísticas -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small">Total</div>
            <div class="fs-3 fw-bold"><?= (int)$stats['total'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm bg-warning bg-opacity-10">
          <div class="card-body">
            <div class="text-muted small">Pendentes</div>
            <div class="fs-3 fw-bold text-warning"><?= (int)$stats['pending_count'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm bg-success bg-opacity-10">
          <div class="card-body">
            <div class="text-muted small">Aprovadas</div>
            <div class="fs-3 fw-bold text-success"><?= (int)$stats['approved_count'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm bg-danger bg-opacity-10">
          <div class="card-body">
            <div class="text-muted small">Rejeitadas</div>
            <div class="fs-3 fw-bold text-danger"><?= (int)$stats['rejected_count'] ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-white">
        <h5 class="mb-0"><i class="bi bi-funnel"></i> Filtros</h5>
      </div>
      <div class="card-body">
        <form method="get" action="checkout_regularizations.php">
          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Todos</option>
                <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pendentes</option>
                <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Aprovadas</option>
                <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejeitadas</option>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Colaborador</label>
              <select name="teacher" class="form-select">
                <option value="">Todos</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= $filterTeacher === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= esc($t['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if (is_network_admin($admin)): ?>
            <div class="col-12 col-md-3">
              <label class="form-label">Instituição</label>
              <select name="school" class="form-select">
                <option value="">Todas</option>
                <?php foreach ($schools as $sch): ?>
                  <option value="<?= (int)$sch['id'] ?>" <?= $filterSchool === (int)$sch['id'] ? 'selected' : '' ?>>
                    <?= esc($sch['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
              <label class="form-label">Data Inicial</label>
              <input type="date" name="date_from" class="form-control" value="<?= esc($filterDateFrom) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Data Final</label>
              <input type="date" name="date_to" class="form-control" value="<?= esc($filterDateTo) ?>">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrar</button>
              <a href="checkout_regularizations.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar</a>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Lista de Solicitações -->
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Solicitações de Regularização</h5>
        <span class="badge bg-secondary"><?= $totalRecords ?> registros</span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($records)): ?>
          <div class="p-4 text-center text-muted">
            <i class="bi bi-inbox fs-1"></i>
            <p class="mb-0">Nenhuma solicitação encontrada</p>
          </div>
        <?php else: ?>
          <div class="admin-table-wrap table-responsive">
            <table class="table table-bordered table-hover align-middle table-sm mb-0">
              <thead class="table-light">
                <tr>
                  <th scope="col">Data do Ponto</th>
                  <th scope="col">Colaborador</th>
                  <th scope="col">Saída Proposta</th>
                  <th scope="col">Status</th>
                  <th scope="col">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($records as $rec):
                    $rid = (int)$rec['id'];
                    $st = $rec['status'];
                    $statusLabel = ['pending' => 'Pendente', 'approved' => 'Aprovada', 'rejected' => 'Rejeitada'][$st] ?? $st;
                    $statusColor = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger'][$st] ?? 'secondary';
                    $canAct = ($st === 'pending');
                    $proposedEffective = $rec['admin_check_out'] ?: $rec['proposed_check_out'];
                    $editedByAdmin = !empty($rec['admin_check_out']) && $rec['admin_check_out'] !== $rec['proposed_check_out'];
                    $teacherNameFmt = mb_convert_case($rec['teacher_name'], MB_CASE_TITLE, 'UTF-8');

                    $checkInTs  = strtotime($rec['check_in']);
                    $proposedTs = strtotime($proposedEffective);
                    $durationMin = ($proposedTs && $checkInTs) ? (int)floor(($proposedTs - $checkInTs) / 60) : 0;
                    $durationFmt = sprintf('%dh%02dm', floor($durationMin / 60), $durationMin % 60);

                    $detailsPayload = [
                        'id'             => $rid,
                        'status'         => $st,
                        'status_label'   => $statusLabel,
                        'status_color'   => $statusColor,
                        'teacher_name'   => $teacherNameFmt,
                        'school_name'    => $rec['school_name'] ? mb_convert_case($rec['school_name'], MB_CASE_TITLE, 'UTF-8') : null,
                        'date'           => date('d/m/Y', strtotime($rec['date'])),
                        'check_in'       => date('d/m/Y H:i', $checkInTs),
                        'proposed'       => date('d/m/Y H:i', $proposedTs),
                        'proposed_orig'  => $editedByAdmin ? date('d/m/Y H:i', strtotime($rec['proposed_check_out'])) : null,
                        'proposed_raw'   => (string)$rec['proposed_check_out'],
                        'duration'       => $durationFmt,
                        'justification'  => $rec['justification'] ?? '',
                        'created_at'     => date('d/m/Y H:i', strtotime($rec['created_at'])),
                        'approved_by'    => $rec['approved_by_username'] ?? null,
                        'approved_at'    => $rec['approved_at'] ? date('d/m/Y H:i', strtotime($rec['approved_at'])) : null,
                        'admin_observation' => $rec['admin_observation'] ?? null,
                        'rejection_reason'  => $rec['rejection_reason'] ?? null,
                    ];
                ?>
                <tr>
                  <td class="small text-nowrap"><?= date('d/m/Y', strtotime($rec['date'])) ?></td>
                  <td class="fw-semibold"><?= esc($teacherNameFmt) ?></td>
                  <td class="text-center small text-nowrap">
                    <?= date('d/m H:i', $proposedTs) ?>
                    <?php if ($editedByAdmin): ?>
                      <div class="text-muted" style="font-size:.75rem;">(orig: <?= date('H:i', strtotime($rec['proposed_check_out'])) ?>)</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge bg-<?= $statusColor ?>"><?= $statusLabel ?></span>
                    <?php if ($st === 'approved'): ?>
                      <div class="small text-muted mt-1"><i class="bi bi-check-circle-fill text-success me-1"></i><?= esc($rec['approved_by_username']) ?> — <?= date('d/m/Y', strtotime($rec['approved_at'])) ?></div>
                    <?php elseif ($st === 'rejected' && !empty($rec['rejection_reason'])): ?>
                      <div class="small text-muted mt-1" title="<?= esc($rec['rejection_reason']) ?>"><i class="bi bi-x-circle-fill text-danger me-1"></i><?= esc(mb_strimwidth($rec['rejection_reason'], 0, 40, '...')) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <div class="dropdown">
                      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-label="Ações">
                        <i class="bi bi-three-dots-vertical"></i>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li>
                          <button class="dropdown-item" onclick='openDetails(<?= json_encode($detailsPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
                            <i class="bi bi-eye me-2 text-primary"></i>Ver detalhes
                          </button>
                        </li>
                        <?php if ($canAct): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <button class="dropdown-item" onclick="openApprove(<?= $rid ?>, '<?= esc($rec['proposed_check_out']) ?>', '<?= esc($teacherNameFmt) ?>')">
                            <i class="bi bi-check-circle me-2 text-success"></i>Aprovar
                          </button>
                        </li>
                        <li>
                          <button class="dropdown-item" onclick="openReject(<?= $rid ?>, '<?= esc($teacherNameFmt) ?>')">
                            <i class="bi bi-x-circle me-2 text-danger"></i>Rejeitar
                          </button>
                        </li>
                        <?php endif; ?>
                      </ul>
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
      <div class="card-footer bg-white">
        <nav aria-label="Paginação">
          <ul class="pagination justify-content-center mb-0">
            <?php
            $getParams = $_GET;
            for ($i = 1; $i <= $totalPages; $i++):
              $getParams['page'] = $i;
              $url = 'checkout_regularizations.php?' . http_build_query($getParams);
            ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= esc($url) ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      </div>
      <?php endif; ?>
    </div>

    <footer class="mt-5 text-center text-muted">
      &copy; <?= date('Y'); ?> DEEDO Sistemas.
    </footer>
  </main>

  <!-- Modal Aprovar -->
  <div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="request_id" id="approve_request_id">
          <div class="modal-header">
            <h5 class="modal-title">Aprovar regularização</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p>Colaborador: <strong id="approve_teacher_name"></strong></p>
            <div class="mb-3">
              <label class="form-label">Horário de saída (opcional — deixe em branco para usar o proposto pelo colaborador)</label>
              <input type="datetime-local" name="admin_check_out" id="approve_admin_check_out" class="form-control">
              <small class="text-muted">Sugerido pelo colaborador: <span id="approve_proposed_label">—</span></small>
            </div>
            <div class="mb-3">
              <label class="form-label">Observação (opcional, visível ao colaborador)</label>
              <textarea name="observation" class="form-control" rows="2" maxlength="500"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Aprovar e fechar ponto</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal Ver Detalhes -->
  <div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-eye me-1"></i>
            Detalhes da Solicitação
            <span class="badge ms-2" id="details_status_badge"></span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="text-muted small">Colaborador</div>
              <div class="fw-semibold" id="details_teacher">—</div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Instituição</div>
              <div id="details_school">—</div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Data do Ponto</div>
              <div id="details_date">—</div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Entrada Original</div>
              <div id="details_check_in">—</div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Saída Proposta</div>
              <div id="details_proposed">—</div>
              <div class="small text-muted" id="details_proposed_orig" style="display:none;"></div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Duração</div>
              <div class="fw-semibold text-primary" id="details_duration">—</div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Solicitado em</div>
              <div id="details_created_at">—</div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">ID da Solicitação</div>
              <div class="text-muted small">#<span id="details_id">—</span></div>
            </div>
            <div class="col-12">
              <div class="text-muted small">Justificativa do Colaborador</div>
              <div class="border rounded p-3 bg-light" id="details_justification" style="white-space: pre-wrap;">—</div>
            </div>
            <div class="col-12" id="details_admin_block" style="display:none;">
              <hr>
              <div class="text-muted small">Decisão do Administrador</div>
              <div id="details_admin_decision">—</div>
              <div class="mt-2" id="details_admin_observation_wrap" style="display:none;">
                <div class="text-muted small">Observação</div>
                <div class="border rounded p-2 bg-light" id="details_admin_observation" style="white-space: pre-wrap;"></div>
              </div>
              <div class="mt-2" id="details_rejection_reason_wrap" style="display:none;">
                <div class="text-muted small">Motivo da Rejeição</div>
                <div class="border rounded p-2 bg-light text-danger" id="details_rejection_reason" style="white-space: pre-wrap;"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
          <div id="details_actions"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Rejeitar -->
  <div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="request_id" id="reject_request_id">
          <div class="modal-header">
            <h5 class="modal-title">Rejeitar regularização</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p>Colaborador: <strong id="reject_teacher_name"></strong></p>
            <div class="mb-3">
              <label class="form-label">Motivo da rejeição *</label>
              <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Ex: horário informado parece incorreto, procure o RH para conferir."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> Confirmar rejeição</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const approveModal = new bootstrap.Modal(document.getElementById('approveModal'));
    const rejectModal  = new bootstrap.Modal(document.getElementById('rejectModal'));
    const detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));

    function openDetails(rec) {
      const $ = (id) => document.getElementById(id);
      $('details_id').textContent          = rec.id ?? '—';
      $('details_teacher').textContent     = rec.teacher_name || '—';
      $('details_school').textContent      = rec.school_name || '— sem instituição —';
      $('details_date').textContent        = rec.date || '—';
      $('details_check_in').textContent    = rec.check_in || '—';
      $('details_proposed').textContent    = rec.proposed || '—';
      $('details_duration').textContent    = rec.duration || '—';
      $('details_created_at').textContent  = rec.created_at || '—';
      $('details_justification').textContent = rec.justification || '— sem justificativa —';

      const origEl = $('details_proposed_orig');
      if (rec.proposed_orig) {
        origEl.textContent = 'Original do colaborador: ' + rec.proposed_orig + ' (admin ajustou)';
        origEl.style.display = '';
      } else {
        origEl.style.display = 'none';
      }

      const badge = $('details_status_badge');
      badge.className = 'badge ms-2 bg-' + (rec.status_color || 'secondary');
      badge.textContent = rec.status_label || '';

      const adminBlock = $('details_admin_block');
      const adminDecision = $('details_admin_decision');
      const obsWrap = $('details_admin_observation_wrap');
      const rejWrap = $('details_rejection_reason_wrap');
      obsWrap.style.display = 'none';
      rejWrap.style.display = 'none';

      // Defesa contra XSS: monta o nó com textContent + ícone fixo, em vez de innerHTML
      // concatenado com dados (approved_by vem de admins.username, que poderia conter HTML).
      const renderDecision = (verb, iconClass) => {
        adminDecision.replaceChildren();
        const icon = document.createElement('i');
        icon.className = iconClass + ' me-1';
        adminDecision.appendChild(icon);
        adminDecision.appendChild(document.createTextNode(' ' + verb + ' por '));
        const strong = document.createElement('strong');
        strong.textContent = rec.approved_by || '—';
        adminDecision.appendChild(strong);
        adminDecision.appendChild(document.createTextNode(' em ' + (rec.approved_at || '—')));
      };

      if (rec.status === 'approved') {
        adminBlock.style.display = '';
        renderDecision('Aprovada', 'bi bi-check-circle-fill text-success');
        if (rec.admin_observation) {
          obsWrap.style.display = '';
          $('details_admin_observation').textContent = rec.admin_observation;
        }
      } else if (rec.status === 'rejected') {
        adminBlock.style.display = '';
        renderDecision('Rejeitada', 'bi bi-x-circle-fill text-danger');
        if (rec.rejection_reason) {
          rejWrap.style.display = '';
          $('details_rejection_reason').textContent = rec.rejection_reason;
        }
      } else {
        adminBlock.style.display = 'none';
      }

      // Ações inline (aprovar/rejeitar) só aparecem se ainda for pending.
      // Usa createElement + addEventListener para evitar problemas de escape
      // ao embutir nomes com aspas em atributos inline.
      const actions = $('details_actions');
      actions.innerHTML = '';
      if (rec.status === 'pending') {
        const btnApprove = document.createElement('button');
        btnApprove.type = 'button';
        btnApprove.className = 'btn btn-success ms-2';
        btnApprove.innerHTML = '<i class="bi bi-check-circle"></i> Aprovar';
        btnApprove.addEventListener('click', () => {
          closeDetailsThen(() => openApprove(rec.id, rec.proposed_raw || '', rec.teacher_name || ''));
        });
        actions.appendChild(btnApprove);

        const btnReject = document.createElement('button');
        btnReject.type = 'button';
        btnReject.className = 'btn btn-danger ms-2';
        btnReject.innerHTML = '<i class="bi bi-x-circle"></i> Rejeitar';
        btnReject.addEventListener('click', () => {
          closeDetailsThen(() => openReject(rec.id, rec.teacher_name || ''));
        });
        actions.appendChild(btnReject);
      }

      detailsModal.show();
    }

    function closeDetailsThen(fn) {
      detailsModal.hide();
      setTimeout(fn, 250);
    }

    function openApprove(reqId, proposedDateTime, teacherName) {
      document.getElementById('approve_request_id').value = reqId;
      document.getElementById('approve_teacher_name').textContent = teacherName;
      const fmt = (s) => {
        const m = /(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/.exec(s || '');
        return m ? (m[1].split('-').reverse().join('/') + ' ' + m[2]) : (s || '');
      };
      const localFmt = (s) => {
        const m = /(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/.exec(s || '');
        return m ? (m[1] + 'T' + m[2]) : '';
      };
      document.getElementById('approve_proposed_label').textContent = fmt(proposedDateTime);
      document.getElementById('approve_admin_check_out').value = localFmt(proposedDateTime);
      approveModal.show();
    }
    function openReject(reqId, teacherName) {
      document.getElementById('reject_request_id').value = reqId;
      document.getElementById('reject_teacher_name').textContent = teacherName;
      rejectModal.show();
    }
  </script>
  <script>
  (function() {
    if (typeof bootstrap === 'undefined') return;
    document.querySelectorAll('.admin-table-wrap .dropdown-toggle[data-bs-toggle="dropdown"]').forEach(function(btn) {
      new bootstrap.Dropdown(btn, { popperConfig: { strategy: 'fixed' } });
    });
  })();
  </script>
  <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

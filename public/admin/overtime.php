<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// Tratamento de ações POST (aprovar/rejeitar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    $overtimeId = (int)($_POST['overtime_id'] ?? 0);
    
    if ($action === 'approve' && $overtimeId > 0) {
        $result = approve_overtime_request($pdo, $overtimeId, (int)$admin['id']);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        header('Location: overtime.php?' . http_build_query($_GET));
        exit;
    }
    
    if ($action === 'reject' && $overtimeId > 0) {
        $reason = trim($_POST['rejection_reason'] ?? '');
        $result = reject_overtime_request($pdo, $overtimeId, (int)$admin['id'], $reason);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        header('Location: overtime.php?' . http_build_query($_GET));
        exit;
    }
    
    // NOVO: Aprovar candidato de attendance como hora extra (professores com grade)
    if ($action === 'approve_attendance_overtime' && isset($_POST['attendance_id'])) {
        $attendanceId = (int)$_POST['attendance_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Busca o registro de attendance
            $stmt = $pdo->prepare("SELECT a.*, t.base_salary FROM attendance a JOIN teachers t ON t.id = a.teacher_id WHERE a.id = ?");
            $stmt->execute([$attendanceId]);
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($attendance && !empty($attendance['check_in']) && !empty($attendance['check_out'])) {
                // Aprova o attendance
                $pdo->prepare("UPDATE attendance SET approved = 1 WHERE id = ?")->execute([$attendanceId]);
                
                // Calcula minutos
                $checkIn = new DateTime($attendance['check_in']);
                $checkOut = new DateTime($attendance['check_out']);
                $workedMinutes = (int)(($checkOut->getTimestamp() - $checkIn->getTimestamp()) / 60);
                
                // Cria overtime_request
                $justification = $attendance['overtime_justification'] ?? 'Hora extra aprovada';
                $overtimeId = create_overtime_request($pdo, $attendanceId, (int)$attendance['teacher_id'], 
                    $attendance['school_id'] ? (int)$attendance['school_id'] : null, $attendance['date'], 
                    $workedMinutes, 0, $workedMinutes, $justification);
                
                // Aprova automaticamente
                $result = approve_overtime_request($pdo, $overtimeId, (int)$admin['id']);
                
                $pdo->commit();
                $_SESSION['flash_message'] = 'Hora extra aprovada com sucesso!';
                $_SESSION['flash_type'] = 'success';
            } else {
                $pdo->rollBack();
                $_SESSION['flash_message'] = 'Registro incompleto (falta check-out)';
                $_SESSION['flash_type'] = 'warning';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_message'] = 'Erro: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'danger';
        }
        
        header('Location: overtime.php');
        exit;
    }
    
    // NOVO: Rejeitar candidato de attendance
    if ($action === 'reject_attendance_overtime' && isset($_POST['attendance_id'])) {
        $attendanceId = (int)$_POST['attendance_id'];
        $reason = trim($_POST['rejection_reason'] ?? 'Sem justificativa');
        
        try {
            $pdo->prepare("UPDATE attendance SET approved = 0, is_overtime_candidate = 0 WHERE id = ?")->execute([$attendanceId]);
            
            audit_log('reject_overtime', 'attendance', $attendanceId, ['reason' => $reason, 'admin_id' => $admin['id']]);
            
            $_SESSION['flash_message'] = 'Solicitação rejeitada.';
            $_SESSION['flash_type'] = 'warning';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Erro: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'danger';
        }
        
        header('Location: overtime.php');
        exit;
    }
}

// Busca candidatos de attendance pendentes (professores com grade horária)
$attendanceCandidates = get_pending_overtime_candidates(null, null, 50);

// Filtros
$filterStatus = $_GET['status'] ?? '';
$filterTeacher = (int)($_GET['teacher'] ?? 0);
$filterSchool = (int)($_GET['school'] ?? 0);
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

// Escopo de acesso
list($scopeSql, $scopeParams) = admin_scope_where('t');

// Monta query base
$where = ["1=1"];
$params = [];

if (!empty($filterStatus)) {
    $where[] = "ot.status = ?";
    $params[] = $filterStatus;
}

if ($filterTeacher > 0) {
    $where[] = "ot.teacher_id = ?";
    $params[] = $filterTeacher;
}

if ($filterSchool > 0 && is_network_admin($admin)) {
    $where[] = "ot.school_id = ?";
    $params[] = $filterSchool;
}

if (!empty($filterDateFrom)) {
    $where[] = "ot.date >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $where[] = "ot.date <= ?";
    $params[] = $filterDateTo;
}

// Aplica escopo de admin
$where[] = $scopeSql;
$params = array_merge($params, $scopeParams);

$whereSql = implode(' AND ', $where);

// Paginação
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Total de registros
$stCount = $pdo->prepare("SELECT COUNT(*) FROM overtime_requests ot JOIN teachers t ON t.id = ot.teacher_id WHERE $whereSql");
$stCount->execute($params);
$totalRecords = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Busca registros
$sql = "SELECT ot.*, 
               t.name as teacher_name, 
               s.name as school_name,
               adm.username as approved_by_username,
               a.approved as attendance_approved
        FROM overtime_requests ot
        JOIN teachers t ON t.id = ot.teacher_id
        LEFT JOIN schools s ON s.id = ot.school_id
        LEFT JOIN admins adm ON adm.id = ot.approved_by_admin_id
        LEFT JOIN attendance a ON a.id = ot.attendance_id
        WHERE $whereSql
        ORDER BY ot.date DESC, ot.created_at DESC
        LIMIT $perPage OFFSET $offset";

$stRecords = $pdo->prepare($sql);
$stRecords->execute($params);
$records = $stRecords->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stStats = $pdo->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN ot.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN ot.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
    SUM(CASE WHEN ot.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
    SUM(CASE WHEN ot.status = 'pending' THEN ot.minutes ELSE 0 END) as pending_minutes,
    SUM(CASE WHEN ot.status = 'approved' THEN ot.minutes ELSE 0 END) as approved_minutes
FROM overtime_requests ot
JOIN teachers t ON t.id = ot.teacher_id
WHERE $scopeSql");
$stStats->execute($scopeParams);
$stats = $stStats->fetch(PDO::FETCH_ASSOC);

// Lista de colaboradores para filtro
$stTeachers = $pdo->prepare("SELECT DISTINCT t.id, t.name FROM teachers t WHERE $scopeSql ORDER BY t.name");
$stTeachers->execute($scopeParams);
$teachers = $stTeachers->fetchAll(PDO::FETCH_ASSOC);

// Lista de escolas para filtro (só para network admin)
$schools = [];
if (is_network_admin($admin)) {
    $schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Flash message
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Horas Extras | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <style>
    body { background-color: #f8f9fa; }
    .navbar-brand { font-weight: bold; }
    .card-hover { transition: transform .15s ease, box-shadow .15s ease; }
    .card-hover:hover { transform: translateY(-2px); box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,.08) !important; }
    .badge-status-pending { background-color: #ffc107; color: #000; }
    .badge-status-approved { background-color: #28a745; }
    .badge-status-rejected { background-color: #dc3545; }
    .overtime-detail { font-size: 0.9rem; color: #6c757d; }
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
          <h1 class="h3 mb-1"><i class="bi bi-clock-history"></i> Gerenciamento de Horas Extras</h1>
          <p class="mb-0 opacity-75">Solicitações automáticas de horas extras detectadas pelo sistema</p>
        </div>
      </div>
    </div>

    <!-- Estatísticas -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small">Total de Solicitações</div>
            <div class="fs-3 fw-bold"><?= (int)$stats['total'] ?></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm bg-warning bg-opacity-10">
          <div class="card-body">
            <div class="text-muted small">Pendentes</div>
            <div class="fs-3 fw-bold text-warning"><?= (int)$stats['pending_count'] ?></div>
            <div class="small text-muted"><?= number_format($stats['pending_minutes'] / 60, 1) ?> horas</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm bg-success bg-opacity-10">
          <div class="card-body">
            <div class="text-muted small">Aprovadas</div>
            <div class="fs-3 fw-bold text-success"><?= (int)$stats['approved_count'] ?></div>
            <div class="small text-muted"><?= number_format($stats['approved_minutes'] / 60, 1) ?> horas</div>
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
        <form method="get" action="overtime.php">
          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="">Todos</option>
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
                  <option value="<?= $t['id'] ?>" <?= $filterTeacher == $t['id'] ? 'selected' : '' ?>>
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
                  <option value="<?= $sch['id'] ?>" <?= $filterSchool == $sch['id'] ? 'selected' : '' ?>>
                    <?= esc($sch['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-2">
              <label class="form-label">Data Inicial</label>
              <input type="date" name="date_from" class="form-control" value="<?= esc($filterDateFrom) ?>">
            </div>
            <div class="col-12 col-md-2">
              <label class="form-label">Data Final</label>
              <input type="date" name="date_to" class="form-control" value="<?= esc($filterDateTo) ?>">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrar</button>
              <a href="overtime.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar</a>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Candidatos de Hora Extra (Professores com Grade Horária) -->
    <?php if (!empty($attendanceCandidates)): ?>
    <div class="card shadow-sm mb-4 border-warning">
      <div class="card-header bg-warning bg-opacity-10">
        <h5 class="mb-0">
          <i class="bi bi-exclamation-triangle-fill text-warning"></i> 
          Pendentes de Revisão - Professores com Grade Horária
        </h5>
        <small class="text-muted">Check-ins em dias sem aulas regulares ou fora da grade atribuída</small>
      </div>
      <div class="card-body">
        <div class="alert alert-info">
          <i class="bi bi-info-circle"></i>
          <strong>Estes são registros de professores com grade horária</strong> que fizeram check-in em dias/horários não previstos.
          Aprove como hora extra se for legítimo (reunião, evento, reposição) ou rejeite.
        </div>
        
        <div class="row">
          <?php foreach ($attendanceCandidates as $candidate): 
            $workedMinutes = 0;
            if (!empty($candidate['check_in']) && !empty($candidate['check_out'])) {
              $in = new DateTime($candidate['check_in']);
              $out = new DateTime($candidate['check_out']);
              $workedMinutes = (int)(($out->getTimestamp() - $in->getTimestamp()) / 60);
            }
            $workedHours = round($workedMinutes / 60, 2);
          ?>
          <div class="col-md-6 col-lg-4 mb-3">
            <div class="card h-100 border-warning">
              <div class="card-header bg-warning bg-opacity-10">
                <strong><?= esc($candidate['teacher_name']) ?></strong><br>
                <small class="text-muted"><?= date('d/m/Y', strtotime($candidate['date'])) ?></small>
              </div>
              <div class="card-body">
                <p class="mb-2">
                  <strong><i class="bi bi-clock"></i> Horário:</strong><br>
                  <?= date('H:i', strtotime($candidate['check_in'])) ?>
                  <?php if ($candidate['check_out']): ?>
                    - <?= date('H:i', strtotime($candidate['check_out'])) ?>
                    <span class="badge bg-info"><?= $workedHours ?>h</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Aguardando saída</span>
                  <?php endif; ?>
                </p>
                
                <?php if ($candidate['overtime_justification']): ?>
                <p class="mb-2">
                  <strong><i class="bi bi-chat-quote"></i> Justificativa:</strong><br>
                  <em><?= esc($candidate['overtime_justification']) ?></em>
                </p>
                <?php endif; ?>
                
                <?php if ($candidate['school_name']): ?>
                <p class="mb-2">
                  <small><i class="bi bi-building"></i> <?= esc($candidate['school_name']) ?></small>
                </p>
                <?php endif; ?>
              </div>
              
              <?php if ($candidate['check_out']): ?>
              <div class="card-footer bg-transparent">
                <form method="POST" style="display: inline;" onsubmit="return confirm('Aprovar como hora extra?')">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="approve_attendance_overtime">
                  <input type="hidden" name="attendance_id" value="<?= $candidate['id'] ?>">
                  <button type="submit" class="btn btn-success btn-sm w-100 mb-2">
                    <i class="bi bi-check-circle"></i> Aprovar como Hora Extra
                  </button>
                </form>
                
                <button type="button" class="btn btn-danger btn-sm w-100" 
                        onclick="rejectAttendanceOT(<?= $candidate['id'] ?>, '<?= esc($candidate['teacher_name']) ?>')">
                  <i class="bi bi-x-circle"></i> Rejeitar
                </button>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Lista de Horas Extras -->
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Solicitações de Horas Extras</h5>
        <span class="badge bg-secondary"><?= $totalRecords ?> registros</span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($records)): ?>
          <div class="p-4 text-center text-muted">
            <i class="bi bi-inbox fs-1"></i>
            <p class="mb-0">Nenhuma solicitação de hora extra encontrada</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Data</th>
                  <th>Colaborador</th>
                  <th>Instituição</th>
                  <th>Horas Trabalhadas</th>
                  <th>Horas Esperadas</th>
                  <th>Hora Extra</th>
                  <th>Status</th>
                  <th>Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($records as $rec): 
                  $statusClass = 'badge-status-' . $rec['status'];
                  $statusLabel = [
                    'pending' => 'Pendente',
                    'approved' => 'Aprovada',
                    'rejected' => 'Rejeitada'
                  ][$rec['status']] ?? $rec['status'];
                  
                  $hoursWorked = floor($rec['worked_minutes'] / 60);
                  $minsWorked = $rec['worked_minutes'] % 60;
                  $hoursExpected = floor($rec['expected_minutes'] / 60);
                  $minsExpected = $rec['expected_minutes'] % 60;
                  $hoursOvertime = floor($rec['minutes'] / 60);
                  $minsOvertime = $rec['minutes'] % 60;
                  
                  $canApprove = $rec['status'] === 'pending' && $rec['attendance_approved'] == 1;
                  $canReject = $rec['status'] === 'pending';
                ?>
                <tr>
                  <td><?= date('d/m/Y', strtotime($rec['date'])) ?></td>
                  <td class="fw-semibold"><?= esc($rec['teacher_name']) ?></td>
                  <td><?= esc($rec['school_name'] ?? '-') ?></td>
                  <td><?= sprintf('%dh%02dm', $hoursWorked, $minsWorked) ?></td>
                  <td><?= sprintf('%dh%02dm', $hoursExpected, $minsExpected) ?></td>
                  <td class="fw-bold text-primary"><?= sprintf('%dh%02dm', $hoursOvertime, $minsOvertime) ?></td>
                  <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                  <td>
                    <?php if ($canApprove): ?>
                      <form method="post" style="display:inline;" onsubmit="return confirm('Aprovar esta hora extra? Será adicionada ao banco de horas.')">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="overtime_id" value="<?= $rec['id'] ?>">
                        <?php foreach ($_GET as $k => $v): ?>
                          <input type="hidden" name="<?= esc($k) ?>" value="<?= esc($v) ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-sm btn-success">
                          <i class="bi bi-check-circle"></i> Aprovar
                        </button>
                      </form>
                    <?php endif; ?>
                    
                    <?php if ($canReject): ?>
                      <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $rec['id'] ?>">
                        <i class="bi bi-x-circle"></i> Rejeitar
                      </button>
                      
                      <!-- Modal de Rejeição -->
                      <div class="modal fade" id="rejectModal<?= $rec['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                          <div class="modal-content">
                            <form method="post">
                              <div class="modal-header">
                                <h5 class="modal-title">Rejeitar Hora Extra</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                              </div>
                              <div class="modal-body">
                                <p><strong><?= esc($rec['teacher_name']) ?></strong> - <?= date('d/m/Y', strtotime($rec['date'])) ?></p>
                                <p>Hora extra: <strong><?= sprintf('%dh%02dm', $hoursOvertime, $minsOvertime) ?></strong></p>
                                <div class="mb-3">
                                  <label class="form-label">Motivo da Rejeição <span class="text-danger">*</span></label>
                                  <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Informe o motivo da rejeição..."></textarea>
                                </div>
                              </div>
                              <div class="modal-footer">
                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="overtime_id" value="<?= $rec['id'] ?>">
                                <?php foreach ($_GET as $k => $v): ?>
                                  <input type="hidden" name="<?= esc($k) ?>" value="<?= esc($v) ?>">
                                <?php endforeach; ?>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-danger">Rejeitar</button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>
                    
                    <?php if ($rec['status'] === 'pending' && $rec['attendance_approved'] != 1): ?>
                      <span class="text-muted small"><i class="bi bi-info-circle"></i> Aguardando aprovação do ponto</span>
                    <?php endif; ?>
                    
                    <?php if ($rec['status'] === 'approved'): ?>
                      <div class="overtime-detail">
                        <i class="bi bi-check-circle-fill text-success"></i> 
                        Por: <?= esc($rec['approved_by_username']) ?><br>
                        Em: <?= date('d/m/Y H:i', strtotime($rec['approved_at'])) ?>
                      </div>
                    <?php endif; ?>
                    
                    <?php if ($rec['status'] === 'rejected'): ?>
                      <div class="overtime-detail">
                        <i class="bi bi-x-circle-fill text-danger"></i> 
                        Por: <?= esc($rec['approved_by_username'] ?? '-') ?><br>
                        Em: <?= $rec['approved_at'] ? date('d/m/Y H:i', strtotime($rec['approved_at'])) : '-' ?><br>
                        <strong>Motivo:</strong> <?= esc($rec['rejection_reason']) ?>
                      </div>
                    <?php endif; ?>
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
              $url = 'overtime.php?' . http_build_query($getParams);
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

  <!-- Modal de Rejeição para Attendance Candidates -->
  <div class="modal fade" id="rejectAttendanceModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST" id="rejectAttendanceForm">
          <div class="modal-header">
            <h5 class="modal-title">Rejeitar Solicitação de Hora Extra</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reject_attendance_overtime">
            <input type="hidden" name="attendance_id" id="reject_attendance_id">
            
            <p>Professor: <strong id="reject_teacher_name"></strong></p>
            
            <div class="mb-3">
              <label class="form-label">Motivo da Rejeição *</label>
              <textarea name="rejection_reason" class="form-control" rows="3" required 
                        placeholder="Ex: Trabalho não autorizado, horário não aprovado..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-danger">
              <i class="bi bi-x-circle"></i> Confirmar Rejeição
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Modal de rejeição para attendance candidates
    const rejectAttendanceModal = new bootstrap.Modal(document.getElementById('rejectAttendanceModal'));
    
    function rejectAttendanceOT(attendanceId, teacherName) {
      document.getElementById('reject_attendance_id').value = attendanceId;
      document.getElementById('reject_teacher_name').textContent = teacherName;
      rejectAttendanceModal.show();
    }
  </script>
</body>
</html>


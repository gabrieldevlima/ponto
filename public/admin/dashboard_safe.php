<?php
/**
 * Dashboard Seguro - 100% compatível com produção
 * Versão sem queries complexas que causam problemas de collation
 */
require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$admin = current_admin($pdo);
$today = date('Y-m-d');

// Inicializa variáveis
$totalTeachers = 0;
$presentToday = 0;
$workingNow = 0;
$pendingToday = 0;
$workingNowList = [];

// ============================================================================
// QUERIES BÁSICAS - SEM JOINS COMPLEXOS
// ============================================================================

try {
    // 1. Total de colaboradores ativos
    $totalTeachers = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE active=1")->fetchColumn();
} catch (Exception $e) {
    error_log("Dashboard error (teachers): " . $e->getMessage());
}

try {
    // 2. Presentes hoje
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT teacher_id) FROM attendance WHERE date=? AND approved=1");
    $stmt->execute([$today]);
    $presentToday = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Dashboard error (present): " . $e->getMessage());
}

try {
    // 3. Trabalhando agora
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT teacher_id) FROM attendance WHERE date=? AND check_in IS NOT NULL AND check_out IS NULL");
    $stmt->execute([$today]);
    $workingNow = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Dashboard error (working): " . $e->getMessage());
}

try {
    // 4. Pendentes de aprovação
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT teacher_id) FROM attendance WHERE date=? AND (approved IS NULL OR approved=0)");
    $stmt->execute([$today]);
    $pendingToday = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Dashboard error (pending): " . $e->getMessage());
}

try {
    // 5. Lista de trabalhando agora (SEM JOINS para evitar collation issues)
    $stmt = $pdo->prepare("
        SELECT DISTINCT teacher_id, check_in, check_in_lat, check_in_lng, school_id
        FROM attendance 
        WHERE date=? AND check_in IS NOT NULL AND check_out IS NULL
        ORDER BY check_in DESC
        LIMIT 20
    ");
    $stmt->execute([$today]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Busca nome do colaborador separadamente
        $teacher = $pdo->prepare("SELECT name FROM teachers WHERE id=?");
        $teacher->execute([$row['teacher_id']]);
        $teacherName = $teacher->fetchColumn() ?: 'Desconhecido';
        
        // Busca escola separadamente (se houver)
        $schoolName = null;
        if ($row['school_id']) {
            $school = $pdo->prepare("SELECT name FROM schools WHERE id=?");
            $school->execute([$row['school_id']]);
            $schoolName = $school->fetchColumn();
        }
        
        $workingNowList[] = [
            'name' => $teacherName,
            'check_in' => $row['check_in'],
            'check_in_lat' => $row['check_in_lat'],
            'check_in_lng' => $row['check_in_lng'],
            'school_name' => $schoolName
        ];
    }
} catch (Exception $e) {
    error_log("Dashboard error (working list): " . $e->getMessage());
}

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Painel Administrativo | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <style>
    body { background-color: #f8f9fa; }
    .card-hover { transition: transform .15s ease, box-shadow .15s ease; }
    .card-hover:hover { transform: translateY(-2px); box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, .08) !important; }
  </style>
</head>
<body>
  <?php 
  try {
    include __DIR__ . '/_navbar.php';
  } catch (Exception $e) {
    echo '<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
            <div class="container-fluid">
              <a class="navbar-brand fw-bold" href="dashboard.php">
                <img src="../img/logo.png" alt="Logo" style="height:40px">
              </a>
              <div class="navbar-nav ms-auto">
                <a class="nav-link" href="logout.php">Sair</a>
              </div>
            </div>
          </nav>';
  }
  ?>

  <main class="container py-4">
    <!-- Header -->
    <div class="bg-primary bg-gradient rounded-3 text-white p-4 p-md-5 mb-4 shadow-sm">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <h1 class="h3 mb-1">
            <i class="bi bi-speedometer2 me-2"></i>Painel Administrativo
          </h1>
          <p class="mb-0">Bem-vindo, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Administrador') ?></p>
        </div>
        <div class="text-end d-none d-md-block">
          <div class="fs-5 fw-bold"><?= date('d/m/Y') ?></div>
          <div class="small opacity-75"><?= date('H:i') ?></div>
        </div>
      </div>
    </div>

    <!-- KPIs -->
    <div class="row g-4 mb-4">
      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover">
          <div class="card-body text-center">
            <i class="bi bi-people-fill text-primary fs-1 mb-2"></i>
            <h3 class="fs-2 fw-bold mb-0"><?= $totalTeachers ?></h3>
            <p class="text-muted mb-0 small">Colaboradores Ativos</p>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover bg-success bg-opacity-10 border-success border-opacity-25">
          <div class="card-body text-center">
            <i class="bi bi-check-circle-fill text-success fs-1 mb-2"></i>
            <h3 class="fs-2 fw-bold text-success mb-0"><?= $presentToday ?></h3>
            <p class="text-muted mb-0 small">Presentes Hoje</p>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover bg-primary bg-opacity-10 border-primary border-opacity-25">
          <div class="card-body text-center">
            <i class="bi bi-person-check-fill text-primary fs-1 mb-2"></i>
            <h3 class="fs-2 fw-bold text-primary mb-0"><?= $workingNow ?></h3>
            <p class="text-muted mb-0 small">Trabalhando Agora</p>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-3">
        <div class="card h-100 border-0 shadow-sm card-hover bg-warning bg-opacity-10 border-warning border-opacity-25">
          <div class="card-body text-center">
            <i class="bi bi-clock-history text-warning fs-1 mb-2"></i>
            <h3 class="fs-2 fw-bold text-warning mb-0"><?= $pendingToday ?></h3>
            <p class="text-muted mb-0 small">Pendentes de Aprovação</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Trabalhando Agora -->
    <?php if (!empty($workingNowList)): ?>
    <div class="row g-4 mb-4">
      <div class="col-12">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-success bg-opacity-10 border-0">
            <h5 class="mb-0 d-flex align-items-center gap-2">
              <i class="bi bi-people-fill text-success"></i>
              Colaboradores Trabalhando Agora
              <span class="badge bg-success"><?= count($workingNowList) ?></span>
            </h5>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th>Nome</th>
                    <th>Instituição</th>
                    <th>Entrada</th>
                    <th>Tempo</th>
                    <th>Localização</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($workingNowList as $worker): 
                    $checkIn = new DateTime($worker['check_in']);
                    $now = new DateTime();
                    $diff = $now->diff($checkIn);
                    $hours = $diff->h + ($diff->days * 24);
                    
                    $hasLocation = !empty($worker['check_in_lat']) && !empty($worker['check_in_lng']);
                    $mapsUrl = $hasLocation ? 'https://www.google.com/maps?q=' . $worker['check_in_lat'] . ',' . $worker['check_in_lng'] : null;
                  ?>
                  <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($worker['name']) ?></td>
                    <td><?= htmlspecialchars($worker['school_name'] ?? '-') ?></td>
                    <td><?= $checkIn->format('H:i') ?></td>
                    <td>
                      <span class="badge bg-success">
                        <?= sprintf('%02d:%02d', $hours, $diff->i) ?>h
                      </span>
                    </td>
                    <td>
                      <?php if ($hasLocation): ?>
                        <a href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                          <i class="bi bi-geo-alt"></i> Ver
                        </a>
                      <?php else: ?>
                        <span class="text-muted small">-</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Acesso Rápido -->
    <div class="row g-4">
      <div class="col-12">
        <div class="card shadow-sm">
          <div class="card-header bg-white border-0">
            <h5 class="mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Acesso Rápido</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4 col-sm-6">
                <a href="teachers.php" class="btn btn-outline-primary w-100 py-3">
                  <i class="bi bi-person-badge fs-4 d-block mb-2"></i>
                  Colaboradores
                </a>
              </div>
              <div class="col-md-4 col-sm-6">
                <a href="attendances.php" class="btn btn-outline-primary w-100 py-3">
                  <i class="bi bi-clock-history fs-4 d-block mb-2"></i>
                  Registros de Ponto
                </a>
              </div>
              <div class="col-md-4 col-sm-6">
                <a href="leaves.php" class="btn btn-outline-primary w-100 py-3">
                  <i class="bi bi-person-x fs-4 d-block mb-2"></i>
                  Afastamentos
                </a>
              </div>
              <div class="col-md-4 col-sm-6">
                <a href="reports_financial.php" class="btn btn-outline-success w-100 py-3">
                  <i class="bi bi-currency-dollar fs-4 d-block mb-2"></i>
                  Relatório Financeiro
                </a>
              </div>
              <div class="col-md-4 col-sm-6">
                <a href="teacher_monthly_report.php" class="btn btn-outline-success w-100 py-3">
                  <i class="bi bi-calendar-month fs-4 d-block mb-2"></i>
                  Relatório Mensal
                </a>
              </div>
              <div class="col-md-4 col-sm-6">
                <a href="attendance_manual.php" class="btn btn-outline-warning w-100 py-3">
                  <i class="bi bi-plus-circle fs-4 d-block mb-2"></i>
                  Inserir Ponto Manual
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="alert alert-info mt-4">
      <i class="bi bi-info-circle me-2"></i>
      <strong>Dashboard Simplificado:</strong> Esta versão funciona sem problemas de collation.
      Para ativar gráficos avançados, execute: <code>fix_collation_production.sql</code> no phpMyAdmin.
    </div>

    <footer class="mt-5 text-center text-muted small">
      <p class="mb-1">&copy; <?= date('Y'); ?> DEEDO Sistemas. Todos os direitos reservados.</p>
      <p class="mb-0">
        <a href="../index.php" class="text-decoration-none">Ir para Registro de Ponto</a>
      </p>
    </footer>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


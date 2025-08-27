<?php
require_once __DIR__ . '/../../config.php';
require_admin();
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Painel do Administrador</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style> body { background-color: #f8f9fa; } .dashboard-card { min-height: 150px; } .navbar-brand { font-weight: bold; } </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
        <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
          <li class="nav-item"><a class="nav-link" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
          <li class="nav-item"><a class="nav-link" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <span class="navbar-text me-3 d-none d-lg-inline"><i class="bi bi-person-circle"></i> <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?></span>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>
  <div class="container mt-4">
    <h2 class="mb-4">Painel do Administrador</h2>
    <div class="row g-4">
      <div class="col-md-4">
        <div class="card dashboard-card shadow-sm">
          <div class="card-body">
            <h5 class="card-title"><i class="bi bi-person-badge"></i> Gerenciar Colaboradores</h5>
            <p class="card-text">Adicione, edite ou remova colaboradores do sistema.</p>
            <a href="teachers.php" class="btn btn-primary">Acessar</a>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card dashboard-card shadow-sm">
          <div class="card-body">
            <h5 class="card-title"><i class="bi bi-calendar-check"></i> Registros de Ponto</h5>
            <p class="card-text">Visualize e gerencie os registros de ponto.</p>
            <a href="attendances.php" class="btn btn-secondary">Acessar</a>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card dashboard-card shadow-sm">
          <div class="card-body">
            <h5 class="card-title"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</h5>
            <p class="card-text">Insira entrada/saída com justificativa por colaborador.</p>
            <a href="attendance_manual.php" class="btn btn-success">Inserir</a>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card dashboard-card shadow-sm">
          <div class="card-body">
            <h5 class="card-title"><i class="bi bi-people"></i> Tipos de Colaboradores</h5>
            <p class="card-text">Gerencie tipos e modos de rotina.</p>
            <a href="collaborator_types.php" class="btn btn-outline-primary">Configurar</a>
          </div>
        </div>
      </div>
    </div>
    <footer class="mt-5 text-center text-muted">&copy; <?= date('Y') ?> DEEDO Ponto. Todos os direitos reservados.</footer>
  </div>
</body>
</html>
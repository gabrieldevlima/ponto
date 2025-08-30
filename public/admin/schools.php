<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);
if (!is_network_admin($adm)) {
  http_response_code(403);
  exit('Acesso restrito a administradores da rede.');
}

$msg = $_GET['msg'] ?? '';
$q = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($q !== '') {
  $where[] = "(name LIKE ? OR code LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}
$sql = "SELECT * FROM schools";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY active DESC, name ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Instituições | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
        <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
          <li class="nav-item"><a class="nav-link" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
          <li class="nav-item"><a class="nav-link" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
          <?php if (is_network_admin($adm)): ?>
            <li class="nav-item"><a class="nav-link active" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
            <li class="nav-item"><a class="nav-link" href="admins.php"><i class="bi bi-people"></i> Administradores</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <span class="navbar-text me-3 d-none d-lg-inline">
          <i class="bi bi-person-circle"></i>
          <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?>
        </span>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>
  <div class="container-fluid">
    <div class="card rounded-3 border bg-body mb-4">
      <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
          <div class="bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width:3rem;height:3rem;">
            <i class="bi bi-building fs-4"></i>
          </div>
          <div>
            <h3 class="mb-0">Instituições</h3>
            <p class="text-muted mb-0">Gerencie as instituições da rede: crie, edite e pesquise instituições cadastradas.</p>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="school_edit.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i>
            <span class="d-none d-sm-inline">Nova Instituição</span>
            <span class="d-inline d-sm-none">Novo</span>
          </a>
        </div>
      </div>
    </div>
    <?php if ($msg): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= esc($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
      </div>
    <?php endif; ?>

    <form class="row g-2 align-items-center mb-3" method="get" autocomplete="off" role="search">
      <div class="col-md-6 col-lg-5">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" class="form-control" name="q" placeholder="Buscar por nome ou código" value="<?= esc($q) ?>" aria-label="Buscar instituições">
        </div>
      </div>
      <div class="col-auto">
        <div class="btn-group">
          <button type="submit" class="btn btn-primary">Filtrar</button>
          <a class="btn btn-outline-secondary" href="schools.php">Limpar</a>
        </div>
      </div>
    </form>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-building me-1"></i>Lista de Instituições</span>
        <span class="text-muted small"><?= count($rows) ?> resultado(s)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover table-bordered align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Nome</th>
              <th>Código</th>
              <th>Status</th>
              <th style="width:160px;">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= esc($r['name']) ?></td>
                <td class="text-center"><code><?= esc($r['code']) ?></code></td>
                <td class="text-center"><?= (int)$r['active'] === 1 ? '<span class="badge bg-success rounded-pill">Ativa</span>' : '<span class="badge bg-secondary rounded-pill">Inativa</span>' ?></td>
                <td class="text-center">
                  <a class="btn btn-sm btn-outline-primary" href="school_edit.php?id=<?= (int)$r['id'] ?>">
                    <i class="bi bi-pencil-square me-1"></i>Editar
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="4" class="text-center text-muted">Nenhuma instituição cadastrada.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="text-center my-5">
      <a href="dashboard.php" class="btn btn-outline-primary rounded-pill px-4 py-2 shadow-sm">
        <i class="bi bi-arrow-left-circle me-2"></i> Voltar ao Painel
      </a>
    </div>
  </div>
</body>

</html>
<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

// Tipos p/ filtro
$types = $pdo->query("SELECT id, name FROM collaborator_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$typeOptions = [];
foreach ($types as $t) { $typeOptions[(int)$t['id']] = $t['name']; }

// Filtros
$where = [];
$params = [];

// Filtro por nome, CPF ou email (busca geral)
if (!empty($_GET['q'])) {
    $where[] = "(t.name LIKE ? OR t.cpf LIKE ? OR t.email LIKE ?)";
    $q = '%' . $_GET['q'] . '%';
    $params[] = $q; $params[] = $q; $params[] = $q;
}

// Filtro por status
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $where[] = "t.active = ?";
    $params[] = $_GET['status'] == '1' ? 1 : 0;
}

// Filtro por tipo
if (!empty($_GET['type_id']) && ctype_digit($_GET['type_id'])) {
    $where[] = "t.type_id = ?";
    $params[] = (int)$_GET['type_id'];
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Paginação simples
$perPage = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Total de colaboradores (para paginação)
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM teachers t $where_sql");
$totalStmt->execute($params);
$totalTeachers = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalTeachers / $perPage));

// Listar colaboradores paginados e filtrados
$sql = "SELECT t.*, ct.name AS type_name
        FROM teachers t
        JOIN collaborator_types ct ON ct.id = t.type_id
        $where_sql
        ORDER BY t.name
        LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$params2 = array_merge($params, [$perPage, $offset]);
$stmt->execute($params2);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mensagens de feedback
$messages = [];
if (isset($_GET['msg'])) {
  $messages[] = htmlspecialchars($_GET['msg']);
}

// Alternar status
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $stmt = $pdo->prepare("UPDATE teachers SET active = NOT active WHERE id = ?");
    if ($stmt->execute([$id])) {
        header('Location: teachers.php?msg=Status+alterado+com+sucesso');
    } else {
        header('Location: teachers.php?msg=Erro+ao+alterar+status');
    }
    exit;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Colaboradores - Administração</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .table-actions .btn { margin-right: 0.25rem; margin-bottom: 0.25rem; }
    @media (max-width: 575.98px) {
      .table-responsive { font-size: 0.95rem; }
      .table-actions { display: flex; flex-direction: column; gap: 0.25rem; }
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
        <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? ' active' : '' ?>" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
          <li class="nav-item"><a class="nav-link<?= basename($_SERVER['PHP_SELF']) == 'attendances.php' ? ' active' : '' ?>" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
          <li class="nav-item"><a class="nav-link active" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <span class="navbar-text me-3 d-none d-lg-inline"><i class="bi bi-person-circle"></i> <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?></span>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>

  <div class="container-fluid">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-2">
      <h3 class="mb-0">Gerenciamento de Colaboradores</h3>
      <div class="d-flex flex-column flex-md-row align-items-md-center gap-2">
        <a href="teacher_edit.php" class="btn btn-success mb-2 mb-md-0 me-md-3">
          <i class="bi bi-plus-lg"></i> Novo Colaborador
        </a>
        <div class="alert alert-info mb-0 py-1 px-2 align-self-start align-self-md-center" role="alert" style="font-size:1rem;">
          Total: <?= $totalTeachers ?>
        </div>
      </div>
    </div>

    <form method="get" class="mb-3">
      <div class="row g-2 align-items-center">
        <div class="col-sm-4 col-md-3 col-lg-3">
          <input type="text" name="q" value="<?= isset($_GET['q']) ? esc($_GET['q']) : '' ?>" class="form-control" placeholder="Buscar por nome, CPF ou email">
        </div>
        <div class="col-sm-3 col-md-2">
          <select name="status" class="form-select">
            <option value="">Todos status</option>
            <option value="1" <?= (isset($_GET['status']) && $_GET['status'] === '1') ? 'selected' : '' ?>>Ativo</option>
            <option value="0" <?= (isset($_GET['status']) && $_GET['status'] === '0') ? 'selected' : '' ?>>Inativo</option>
          </select>
        </div>
        <div class="col-sm-3 col-md-3">
          <select name="type_id" class="form-select">
            <option value="">Todos os tipos</option>
            <?php foreach ($typeOptions as $tid => $tname): ?>
              <option value="<?= (int)$tid ?>" <?= (isset($_GET['type_id']) && (int)$_GET['type_id'] === $tid) ? 'selected' : '' ?>><?= esc($tname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Buscar</button>
        </div>
        <?php if (!empty($_GET['q']) || (isset($_GET['status']) && $_GET['status'] !== '') || !empty($_GET['type_id'])): ?>
          <div class="col-auto">
            <a href="teachers.php" class="btn btn-outline-secondary"><i class="bi bi-x"></i> Limpar</a>
          </div>
        <?php endif; ?>
      </div>
    </form>

    <?php foreach ($messages as $msg): ?>
      <div class="alert alert-info"><?= $msg ?></div>
    <?php endforeach; ?>

    <div class="table-responsive mb-3">
      <table class="table table-bordered align-middle table-hover">
        <thead class="table-light">
          <tr>
            <th>Nome</th>
            <th>CPF</th>
            <th>Email</th>
            <th>Tipo</th>
            <th>Status</th>
            <th class="text-center" style="min-width: 320px;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($teachers)): ?>
            <tr>
              <td colspan="6" class="text-center text-muted">Nenhum colaborador cadastrado.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($teachers as $t): ?>
              <tr>
                <td><?= esc($t['name']) ?></td>
                <td><?= esc($t['cpf']) ?></td>
                <td><?= esc($t['email']) ?></td>
                <td><?= esc($t['type_name'] ?? '-') ?></td>
                <td class="text-center">
                  <?php if ($t['active']): ?>
                    <span class="badge bg-success">Ativo</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Inativo</span>
                  <?php endif; ?>
                </td>
                <td class="text-center table-actions">
                  <a href="teacher_edit.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-primary" title="Editar">
                    <i class="bi bi-pencil"></i> <span class="d-none d-md-inline">Editar</span>
                  </a>
                  <a href="teachers.php?toggle=<?= (int)$t['id'] ?>" class="btn btn-sm btn-warning" onclick="return confirm('Alterar status deste colaborador?')" title="Ativar/Desativar">
                    <i class="bi bi-power"></i> <span class="d-none d-md-inline"><?= $t['active'] ? 'Desativar' : 'Ativar' ?></span>
                  </a>
                  <a href="teacher_pin_reset.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Resetar o PIN desse colaborador?')" title="Resetar PIN">
                    <i class="bi bi-key"></i> <span class="d-none d-md-inline">Resetar PIN</span>
                  </a>
                  <a href="teacher_monthly_report.php?teacher_id=<?= (int)$t['id'] ?>&month=<?= date('Y-m') ?>" class="btn btn-sm btn-info" title="Relatório Mensal">
                    <i class="bi bi-bar-chart-line"></i> <span class="d-none d-md-inline">Relatório</span>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav>
        <ul class="pagination justify-content-center flex-wrap">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item<?= $p == $page ? ' active' : '' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>

    <div class="mt-4">
      <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
  </div>
</body>
</html>
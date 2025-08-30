<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

$admin = current_admin($pdo);

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Helpers
function keep_params(array $overrides = []): string
{
  $allowed = ['q', 'type_id', 'status', 'sort', 'dir', 'page'];
  $params = array_intersect_key($_GET, array_flip($allowed));
  $params = array_merge($params, $overrides);
  return 'teachers.php?' . http_build_query($params);
}
function sanitize_int_or_null($v)
{
  return (isset($v) && is_numeric($v)) ? (int)$v : null;
}

// Messages
$messages = [];
if (!empty($_GET['msg'])) {
  $messages[] = htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8');
}

// Toggle status (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
  $id = sanitize_int_or_null($_POST['id'] ?? null);
  $token = $_POST['csrf'] ?? '';
  if (!$id || !hash_equals($_SESSION['csrf_token'], $token)) {
    header('Location: ' . keep_params(['msg' => 'Requisição inválida']));
    exit;
  }
  // respeita escopo (somente pode alternar status se enxergar o colaborador)
  list($scopeSql, $scopeParams) = admin_scope_where('t');
  $chk = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id = ? AND $scopeSql");
  $chk->execute(array_merge([$id], $scopeParams));
  if (!$chk->fetchColumn()) {
    header('Location: ' . keep_params(['msg' => 'Sem permissão para alterar este colaborador']));
    exit;
  }
  $stmt = $pdo->prepare("UPDATE teachers SET active = 1 - active WHERE id = ?");
  $ok = $stmt->execute([$id]);
  audit_log('update', 'teacher', $id, ['toggle_active' => true, 'result' => $ok]);
  header('Location: ' . keep_params(['msg' => $ok ? 'Status alterado com sucesso' : 'Erro ao alterar status']));
  exit;
}

// Filters
$q = trim($_GET['q'] ?? '');
$typeId = sanitize_int_or_null($_GET['type_id'] ?? null);
$status = $_GET['status'] ?? ''; // '', '1', '0'

// Sorting
$sortMap = [
  'name' => 't.name',
  'cpf' => 't.cpf',
  'email' => 't.email',
  'type' => 'ct.name',
  'status' => 't.active',
  'salary' => 't.base_salary',
];
$sort = $_GET['sort'] ?? 'name';
$sortCol = $sortMap[$sort] ?? $sortMap['name'];
$dir = strtolower($_GET['dir'] ?? 'asc');
$dir = $dir === 'desc' ? 'desc' : 'asc';

// Paging
$perPage = isset($_GET['page_size']) && is_numeric($_GET['page_size']) ? max(1, min(200, (int)$_GET['page_size'])) : 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Build WHERE com placeholders posicionais
$where = [];
$params = [];
if ($q !== '') {
  $where[] = "(t.name LIKE ? OR t.email LIKE ? OR t.cpf LIKE ?)";
  $like = "%{$q}%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}
if ($typeId) {
  $where[] = "t.type_id = ?";
  $params[] = $typeId;
}
if ($status === '1' || $status === '0') {
  $where[] = "t.active = ?";
  $params[] = (int)$status;
}
// Escopo
list($scopeSql, $scopeParams) = admin_scope_where('t');
$where[] = $scopeSql;

// Compose WHERE
$whereSql = $where ? ("WHERE " . implode(' AND ', $where)) : '';

// Total
$totalStmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM teachers t
  LEFT JOIN collaborator_types ct ON ct.id = t.type_id
  $whereSql
");
$totalParams = array_merge($params, $scopeParams);
$totalStmt->execute($totalParams);
$totalTeachers = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalTeachers / $perPage));
if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

// List paginated
// Importante: não usar bind em LIMIT/OFFSET com emulação desativada. Inserir inteiros sanitizados diretamente.
$perPage = (int)$perPage;
$offset = (int)$offset;
$listSql = "
  SELECT t.*, ct.name AS type_name
  FROM teachers t
  LEFT JOIN collaborator_types ct ON ct.id = t.type_id
  $whereSql
  ORDER BY $sortCol $dir
  LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($listSql);
$stmt->execute($totalParams);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Types for filter
$types = $pdo->query("SELECT id, name FROM collaborator_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Sort helpers for headers
function sort_link(string $key, string $label): string
{
  $currSort = $_GET['sort'] ?? 'name';
  $currDir = strtolower($_GET['dir'] ?? 'asc');
  $nextDir = ($currSort === $key && $currDir === 'asc') ? 'desc' : 'asc';
  $url = keep_params(['sort' => $key, 'dir' => $nextDir, 'page' => 1]);
  $icon = '';
  if ($currSort === $key) {
    $icon = $currDir === 'asc' ? '▲' : '▼';
  }
  return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="text-decoration-none">' . $label . ' ' . $icon . '</a>';
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Colaboradores | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  <style>
    .table-actions .btn {
      margin-right: .25rem;
      margin-bottom: .25rem;
    }

    @media (max-width: 575.98px) {
      .table-responsive {
        font-size: .95rem;
      }

      .table-actions {
        display: flex;
        flex-direction: column;
        gap: .25rem;
      }
    }
  </style>
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
          <li class="nav-item"><a class="nav-link active" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
          <?php if (is_network_admin($admin)): ?>
            <li class="nav-item"><a class="nav-link" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
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
            <i class="bi bi-person-badge fs-4"></i>
          </div>
          <div>
            <h3 class="mb-0">Gerenciamento de Colaboradores</h3>
            <small class="text-muted"><i class="bi bi-people me-1"></i>
              <?= (int)$totalTeachers ?> cadastrados</small>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="teacher_edit.php" class="btn btn-success">
            <i class="bi bi-person-plus-fill"></i>
            <span class="d-none d-sm-inline">Novo Colaborador</span>
            <span class="d-inline d-sm-none">Novo</span>
          </a>
        </div>
      </div>
    </div>
    <form class="row g-2 mb-4" method="get" action="teachers.php">
      <div class="col-12 col-md-4">
        <input type="text" name="q" class="form-control" placeholder="Buscar por nome, email ou CPF" value="<?= esc($q) ?>">
      </div>
      <div class="col-6 col-md-3">
        <select name="type_id" class="form-select">
          <option value="">Todos os tipos</option>
          <?php foreach ($types as $tp): ?>
            <option value="<?= (int)$tp['id'] ?>" <?= ($typeId === (int)$tp['id']) ? 'selected' : '' ?>><?= esc($tp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="status" class="form-select">
          <option value="" <?= $status === '' ? 'selected' : '' ?>>Todos</option>
          <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Ativos</option>
          <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <div class="col-12 col-md-3 d-flex gap-2">
        <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search"></i> Filtrar</button>
        <a class="btn btn-outline-secondary" href="teachers.php"><i class="bi bi-x-circle"></i> Limpar</a>
      </div>
      <input type="hidden" name="sort" value="<?= esc($sort) ?>">
      <input type="hidden" name="dir" value="<?= esc($dir) ?>">
    </form>

    <?php foreach ($messages as $msg): ?>
      <div class="alert alert-info"><?= $msg ?></div>
    <?php endforeach; ?>

    <div class="table-responsive mb-3">
      <table class="table table-bordered align-middle table-hover">
        <thead class="table-light">
          <tr>
            <th><?= sort_link('name', 'Nome') ?></th>
            <th><?= sort_link('cpf', 'CPF') ?></th>
            <th><?= sort_link('email', 'Email') ?></th>
            <th><?= sort_link('type', 'Tipo') ?></th>
            <th><?= sort_link('institution', 'Instituição') ?></th>
            <th class="text-center"><?= sort_link('status', 'Status') ?></th>
            <th class="text-center" style="min-width: 420px;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($teachers)): ?>
            <tr>
              <td colspan="7" class="text-center text-muted">Nenhum colaborador encontrado.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($teachers as $t): ?>
              <tr>
                <td><?= esc($t['name']) ?></td>
                <td><?= esc($t['cpf']) ?></td>
                <td><?= esc($t['email']) ?></td>
                <td><?= esc($t['type_name'] ?? 'Não definido') ?></td>
                <td><?= esc($t['institution'] ?? $t['school_name'] ?? $t['network_name'] ?? 'Rede de Ensino') ?></td>
                <td class="text-center">
                  <?php if ((int)$t['active'] === 1): ?>
                    <span class="badge bg-success">Ativo</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Inativo</span>
                  <?php endif; ?>
                </td>
                <td class="text-center table-actions">
                  <a href="teacher_edit.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                    <i class="bi bi-pencil"></i> <span class="d-none d-md-inline">Editar</span>
                  </a>

                  <form action="<?= htmlspecialchars(keep_params(), ENT_QUOTES, 'UTF-8') ?>" method="post" class="d-inline">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('Alterar status deste colaborador?')" title="Ativar/Desativar">
                      <i class="bi bi-power"></i>
                      <span class="d-none d-md-inline"><?= ((int)$t['active'] === 1) ? 'Desativar' : 'Ativar' ?></span>
                    </button>
                  </form>

                  <a href="teacher_pin_reset.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Resetar o PIN desse colaborador?')" title="Resetar PIN">
                    <i class="bi bi-key"></i> <span class="d-none d-md-inline">Resetar PIN</span>
                  </a>

                  <a href="teacher_monthly_report.php?teacher_id=<?= (int)$t['id'] ?>&month=<?= date('Y-m') ?>" class="btn btn-sm btn-outline-info" title="Relatório Mensal">
                    <i class="bi bi-bar-chart-line"></i> <span class="d-none d-md-inline">Relatório</span>
                  </a>

                  <a href="reports_financial.php?teacher_id=<?= (int)$t['id'] ?>&month=<?= date('Y-m') ?>" class="btn btn-sm btn-outline-success" title="Financeiro">
                    <i class="bi bi-cash-coin"></i> <span class="d-none d-md-inline">Financeiro</span>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav aria-label="Paginação">
        <ul class="pagination justify-content-center flex-wrap">
          <?php
          $prevDisabled = $page <= 1 ? ' disabled' : '';
          $nextDisabled = $page >= $totalPages ? ' disabled' : '';
          ?>
          <li class="page-item<?= $prevDisabled ?>">
            <a class="page-link" href="<?= htmlspecialchars(keep_params(['page' => max(1, $page - 1)]), ENT_QUOTES, 'UTF-8') ?>">«</a>
          </li>
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item<?= $p == $page ? ' active' : '' ?>">
              <a class="page-link" href="<?= htmlspecialchars(keep_params(['page' => $p]), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item<?= $nextDisabled ?>">
            <a class="page-link" href="<?= htmlspecialchars(keep_params(['page' => min($totalPages, $page + 1)]), ENT_QUOTES, 'UTF-8') ?>">»</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>

    <div class="text-center my-5">
      <a href="dashboard.php" class="btn btn-outline-primary rounded-pill px-4 py-2 shadow-sm">
        <i class="bi bi-arrow-left-circle me-2"></i> Voltar ao Painel
      </a>
    </div>
  </div>
</body>

</html>
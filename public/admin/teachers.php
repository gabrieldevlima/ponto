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

// Messages (feedback após salvar colaborador, toggle status, etc.)
$messages = [];
if (!empty($_GET['msg'])) {
  $messages[] = [
    'text' => esc($_GET['msg'], ENT_QUOTES, 'UTF-8'),
    'type' => ($_GET['msg_type'] ?? '') === 'success' ? 'success' : (($_GET['msg_type'] ?? '') === 'danger' ? 'danger' : 'info'),
  ];
}

// Aviso de primeiro acesso pendente. Quem acaba de ser cadastrado nasce sem PIN
// e sem face e, pela política de api/pin_enroll.php, é barrado ao tentar bater
// ponto até o admin liberar — o colaborador vê "procure o administrador" e o
// admin não ficava sabendo de nada. O id vem do redirect de teachers_save.php;
// o estado é reconferido no banco para não avisar à toa em refresh ou link velho.
$avisoPrimeiroAcesso = null;
$pinPendingId = sanitize_int_or_null($_GET['pin_pending'] ?? null);
if ($pinPendingId && teacher_first_access_pending($pdo, $pinPendingId)) {
  list($avisoScopeSql, $avisoScopeParams) = admin_scope_where('t');
  $stAviso = $pdo->prepare("SELECT t.id, t.name, t.cpf FROM teachers t WHERE t.id = ? AND {$avisoScopeSql} LIMIT 1");
  $stAviso->execute(array_merge([$pinPendingId], $avisoScopeParams));
  $avisoPrimeiroAcesso = $stAviso->fetch(PDO::FETCH_ASSOC) ?: null;
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

// Inativar / Reativar colaborador (auditável, com motivo). POST + CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['deactivate', 'reactivate'], true)) {
  $id = sanitize_int_or_null($_POST['id'] ?? null);
  $token = $_POST['csrf'] ?? '';
  if (!$id || !hash_equals($_SESSION['csrf_token'], $token)) {
    header('Location: ' . keep_params(['msg' => 'Requisição inválida', 'msg_type' => 'danger']));
    exit;
  }
  $active = ($_POST['action'] === 'reactivate');
  $reason = trim((string)($_POST['reason'] ?? ''));
  try {
    $r = admin_set_collaborator_active($pdo, $id, (int)$admin['id'], $active, $reason);
    $msg = $active ? 'Colaborador reativado.' : 'Colaborador inativado.';
    header('Location: ' . keep_params(['msg' => $r['changed'] ? $msg : 'O status já estava assim.', 'msg_type' => 'success']));
  } catch (RuntimeException $e) {
    header('Location: ' . keep_params(['msg' => $e->getMessage(), 'msg_type' => 'danger']));
  } catch (Throwable $e) {
    error_log('[teachers set_active] ' . $e->getMessage());
    header('Location: ' . keep_params(['msg' => 'Falha ao alterar status.', 'msg_type' => 'danger']));
  }
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
  SELECT t.*, ct.name AS type_name,
         GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as schools_list
  FROM teachers t
  LEFT JOIN collaborator_types ct ON ct.id = t.type_id
  LEFT JOIN teacher_schools ts ON ts.teacher_id = t.id
  LEFT JOIN schools s ON s.id = ts.school_id AND s.active = 1
  $whereSql
  GROUP BY t.id
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
  return '<a href="' . esc($url, ENT_QUOTES, 'UTF-8') . '" class="text-decoration-none">' . $label . ' ' . $icon . '</a>';
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
  <link rel="stylesheet" href="css/admin.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

  <div class="container-fluid admin-content">
    <div class="app-page-header">
      <div class="app-page-header__main">
        <div class="app-page-icon"><i class="bi bi-person-badge"></i></div>
        <div>
          <h1 class="app-page-title">Gerenciamento de Colaboradores</h1>
          <p class="app-page-subtitle"><i class="bi bi-people me-1"></i><?= (int)$totalTeachers ?> cadastrado(s)</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <?php if (is_network_admin($admin)): ?>
        <a href="import_pis.php" class="btn btn-outline-secondary" aria-label="Importar dados contratuais em massa">
          <i class="bi bi-upload me-1"></i>
          <span class="d-none d-sm-inline">Importar cadastro</span>
        </a>
        <?php endif; ?>
        <a href="teacher_edit.php" class="btn btn-success" aria-label="Cadastrar novo colaborador">
          <i class="bi bi-person-plus-fill me-1"></i>
          <span class="d-none d-sm-inline">Novo Colaborador</span>
          <span class="d-inline d-sm-none">Novo</span>
        </a>
      </div>
    </div>
    <form class="row g-2 mb-4" method="get" action="teachers.php">
      <div class="col-12 col-md-4">
        <input type="text" name="q" class="form-control" placeholder="Buscar por nome ou CPF" value="<?= esc($q) ?>">
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
      <?php
        $alertClass = 'alert-' . ($msg['type'] ?? 'info');
        $icon = ($msg['type'] ?? '') === 'success' ? ' <span class="glyphicon glyphicon-ok-sign" aria-hidden="true"></span> ' : '';
      ?>
      <div class="alert <?= $alertClass ?> alert-dismissible fade show" role="alert">
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        <?= $icon ?><?= $msg['text'] ?>
      </div>
    <?php endforeach; ?>

    <?php if ($avisoPrimeiroAcesso): ?>
      <div class="alert alert-warning d-flex flex-column flex-md-row align-items-md-center gap-3" role="alert">
        <div class="flex-fill">
          <strong><i class="bi bi-exclamation-triangle"></i> Falta liberar o primeiro acesso</strong><br>
          <?= esc($avisoPrimeiroAcesso['name']) ?> ainda não tem PIN nem foto cadastrada. Enquanto isso,
          o sistema recusa o primeiro acesso dele com a mensagem &ldquo;precisa ser liberado pelo
          administrador&rdquo; e ele não consegue bater ponto.
        </div>
        <a class="btn btn-warning text-nowrap"
           href="teacher_pin_manage.php?q=<?= urlencode(preg_replace('/\D/', '', (string)$avisoPrimeiroAcesso['cpf'])) ?>">
          <i class="bi bi-key"></i> Liberar primeiro acesso
        </a>
      </div>
    <?php endif; ?>

    <section class="app-section-card app-table-card">
      <header class="app-section-card__header">
        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-people"></i>Lista</span>
        <h2 class="app-section-card__title">Colaboradores</h2>
      </header>
      <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th scope="col"><?= sort_link('name', 'Nome') ?></th>
            <th scope="col"><?= sort_link('cpf', 'CPF') ?></th>
            <th scope="col"><?= sort_link('type', 'Tipo') ?></th>
            <th scope="col" class="text-center"><?= sort_link('status', 'Status') ?></th>
            <th scope="col" class="text-center" style="width: 80px;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($teachers)): ?>
            <tr>
              <td colspan="5" class="text-center text-muted">Nenhum colaborador encontrado.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($teachers as $t): ?>
              <tr>
                <td>
                  <?= esc(mb_convert_case($t['name'], MB_CASE_TITLE, 'UTF-8')) ?>
                  <?php
                    // Badge de status do cadastro facial
                    $hasFace = !empty($t['face_descriptors']) && $t['face_descriptors'] !== '[]';
                    $reenrollDays = (int)(get_setting('face_reenroll_days', '180') ?? '180');
                    if (!$hasFace): ?>
                    <span class="badge bg-secondary ms-1" title="Sem cadastro facial"><i class="bi bi-camera-video-off"></i></span>
                  <?php elseif (!empty($t['face_enrolled_at'])):
                      $enrolledAt = new DateTime($t['face_enrolled_at']);
                      $daysSince = (int)$enrolledAt->diff(new DateTime())->days;
                      if ($daysSince > $reenrollDays): ?>
                    <span class="badge bg-warning text-dark ms-1" title="Cadastro facial desatualizado (<?= $daysSince ?> dias)"><i class="bi bi-exclamation-triangle"></i></span>
                  <?php endif; endif; ?>
                </td>
                <td><?= esc($t['cpf']) ?></td>
                <td><?= esc($t['type_name'] ?? 'Não definido') ?></td>
                <td class="text-center">
                  <?php if ((int)$t['active'] === 1): ?>
                    <span class="badge bg-success">Ativo</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Inativo</span>
                  <?php endif; ?>
                </td>
                <td class="text-center table-actions">
                  <div class="dropdown teachers-actions-dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" aria-expanded="false" aria-haspopup="true" aria-label="Ações">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <a href="teacher_edit.php?id=<?= (int)$t['id'] ?>" class="dropdown-item text-primary">
                          <i class="bi bi-pencil me-2"></i> Editar
                        </a>
                      </li>
                      <li>
                        <a href="teacher_monthly_report.php?teacher_id=<?= (int)$t['id'] ?>&month=<?= date('Y-m') ?>" class="dropdown-item text-info">
                          <i class="bi bi-bar-chart-line me-2"></i> Relatório Mensal
                        </a>
                      </li>
                      <li>
                        <a href="reports_financial.php?teacher_id=<?= (int)$t['id'] ?>&month=<?= date('Y-m') ?>" class="dropdown-item text-success">
                          <i class="bi bi-cash-coin me-2"></i> Financeiro
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <?php if ((int)$t['active'] === 1): ?>
                        <form action="<?= esc(keep_params(), ENT_QUOTES, 'UTF-8') ?>" method="post" class="m-0 p-0"
                              onsubmit="var m=prompt('Inativar este colaborador? Ele deixa de aparecer e não bate ponto, mas o histórico é preservado (reversível).\n\nMotivo (obrigatório):'); if(!m||!m.trim())return false; this.reason.value=m.trim(); return true;">
                          <input type="hidden" name="action" value="deactivate">
                          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                          <input type="hidden" name="reason" value="">
                          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                          <button type="submit" class="dropdown-item text-danger fw-semibold">
                            <i class="bi bi-person-x me-2"></i> Inativar (remover)
                          </button>
                        </form>
                        <?php else: ?>
                        <form action="<?= esc(keep_params(), ENT_QUOTES, 'UTF-8') ?>" method="post" class="m-0 p-0"
                              onsubmit="return confirm('Reativar este colaborador?')">
                          <input type="hidden" name="action" value="reactivate">
                          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                          <button type="submit" class="dropdown-item text-success fw-semibold">
                            <i class="bi bi-person-check me-2"></i> Reativar
                          </button>
                        </form>
                        <?php endif; ?>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="app-table-card__footer">
          <nav aria-label="Paginação">
            <ul class="pagination pagination-sm mb-0">
              <?php
              $prevDisabled = $page <= 1 ? ' disabled' : '';
              $nextDisabled = $page >= $totalPages ? ' disabled' : '';
              ?>
              <li class="page-item<?= $prevDisabled ?>">
                <a class="page-link" href="<?= esc(keep_params(['page' => max(1, $page - 1)])) ?>" aria-label="Anterior">«</a>
              </li>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item<?= $p == $page ? ' active' : '' ?>">
                  <a class="page-link" href="<?= esc(keep_params(['page' => $p])) ?>"><?= $p ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item<?= $nextDisabled ?>">
                <a class="page-link" href="<?= esc(keep_params(['page' => min($totalPages, $page + 1)])) ?>" aria-label="Próximo">»</a>
              </li>
            </ul>
          </nav>
          <span class="text-muted small">Página <?= $page ?> de <?= $totalPages ?></span>
        </div>
      <?php endif; ?>
    </section>
  </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
  if (typeof bootstrap === 'undefined') return;
  document.querySelectorAll('.teachers-actions-dropdown .dropdown-toggle').forEach(function(btn) {
    var dd = new bootstrap.Dropdown(btn, { popperConfig: { strategy: 'fixed' } });
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      dd.toggle();
    });
  });
})();
</script>

</html>
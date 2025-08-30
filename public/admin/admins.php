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

$schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $act = $_POST['act'] ?? '';
  if ($act === 'create') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'school_admin';
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    if ($user === '' || $pass === '' || !in_array($role, ['network_admin', 'school_admin'], true)) {
      $msg = 'Dados inválidos.';
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $st = $pdo->prepare("INSERT INTO admins (username, password_hash, role, school_id) VALUES (?, ?, ?, ?)");
      $st->execute([$user, $hash, $role, $school_id]);
      audit_log('create', 'admin', $pdo->lastInsertId(), ['username' => $user, 'role' => $role, 'school_id' => $school_id]);
      header('Location: admins.php?msg=' . urlencode('Administrador criado.'));
      exit;
    }
  } elseif ($act === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $role = $_POST['role'] ?? 'school_admin';
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    if ($id <= 0 || !in_array($role, ['network_admin', 'school_admin'], true)) {
      $msg = 'Dados inválidos.';
    } else {
      $st = $pdo->prepare("UPDATE admins SET role=?, school_id=? WHERE id=?");
      $st->execute([$role, $school_id, $id]);
      audit_log('update', 'admin', $id, ['role' => $role, 'school_id' => $school_id]);
      header('Location: admins.php?msg=' . urlencode('Administrador atualizado.'));
      exit;
    }
  } elseif ($act === 'resetpass') {
    $id = (int)($_POST['id'] ?? 0);
    $pass = $_POST['password'] ?? '';
    if ($id > 0 && $pass !== '') {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $st = $pdo->prepare("UPDATE admins SET password_hash=? WHERE id=?");
      $st->execute([$hash, $id]);
      audit_log('update', 'admin', $id, ['reset_password' => true]);
      header('Location: admins.php?msg=' . urlencode('Senha redefinida.'));
      exit;
    } else {
      $msg = 'Senha inválida.';
    }
  }
}

$rows = $pdo->query("SELECT a.*, s.name AS school_name FROM admins a LEFT JOIN schools s ON s.id = a.school_id ORDER BY a.role DESC, a.username")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Administradores | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
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
            <li class="nav-item"><a class="nav-link" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
            <li class="nav-item"><a class="nav-link active" href="admins.php"><i class="bi bi-people"></i> Administradores</a></li>
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
            <h3 class="mb-0">Administradores</h3>
            <p class="text-muted mb-0">Gerencie os administradores da rede: crie, edite e pesquise administradores cadastrados.</p>
          </div>
        </div>
      </div>
    </div>
    <?php if ($msg): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif; ?>
    <div class="card mb-4 shadow-sm">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-person-plus"></i>
        <span class="fw-semibold">Novo Administrador</span>
      </div>
      <div class="card-body">
        <form method="post" class="row g-3 align-items-end" autocomplete="off" novalidate>
          <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-md-3">
            <label for="createUsername" class="form-label">Usuário</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input id="createUsername" class="form-control" name="username" placeholder="ex: joao.silva" required autocomplete="username">
            </div>
          </div>

          <div class="col-md-3">
            <label for="createPassword" class="form-label">Senha</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
              <input id="createPassword" class="form-control" type="password" name="password" placeholder="Defina uma senha" required autocomplete="new-password">
            </div>
          </div>

          <div class="col-md-3">
            <label for="createRole" class="form-label">Papel</label>
            <select id="createRole" class="form-select" name="role" required>
              <option value="school_admin">Admin da Escola</option>
              <option value="network_admin">Admin da Rede</option>
            </select>
          </div>

          <div class="col-md-3">
            <div id="createSchoolHelp" class="form-text">Obrigatório se o papel for "Admin da Escola".</div>
            <label for="createSchool" class="form-label">Escola</label>
            <select id="createSchool" class="form-select" name="school_id">
              <option value="">(Sem escola / Rede)</option>
              <?php foreach ($schools as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></option>
              <?php endforeach; ?>
            </select>

          </div>

          <div class="col-12">
            <button class="btn btn-success">
              <i class="bi bi-check2-circle"></i> Criar
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-people"></i>
        <span class="fw-semibold">Administradores Cadastrados</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped table-hover table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Usuário</th>
                <th>Papel</th>
                <th>Escola</th>
                <th>Ações</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <?php
                $isNet = ($r['role'] === 'network_admin');
                $roleLabel = $isNet ? 'Admin da Rede' : 'Admin da Escola';
                $roleBadge = $isNet ? 'primary' : 'secondary';
                ?>
                <tr>
                  <td class="text-break"><?= esc($r['username']) ?></td>
                  <td><span class="badge text-bg-<?= $roleBadge ?>"><?= esc($roleLabel) ?></span></td>
                  <td><?= esc($r['school_name'] ?? '-') ?></td>
                  <td>
                    <div class="d-flex flex-column flex-sm-row gap-2">
                      <!-- Atualização de papel/escola -->
                      <form method="post" class="d-flex flex-wrap gap-2 align-items-center" data-update-form>
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="act" value="update">
                        <div class="d-flex gap-2 flex-wrap">
                          <select class="form-select form-select-sm" name="role" data-role-select style="min-width:170px">
                            <option value="school_admin" <?= $r['role'] === 'school_admin' ? 'selected' : '' ?>>Admin da Escola</option>
                            <option value="network_admin" <?= $r['role'] === 'network_admin' ? 'selected' : '' ?>>Admin da Rede</option>
                          </select>
                          <select class="form-select form-select-sm" name="school_id" data-school-select style="min-width:220px">
                            <option value="">(Sem escola / Rede)</option>
                            <?php foreach ($schools as $s): ?>
                              <option value="<?= (int)$s['id'] ?>" <?= (int)($r['school_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <button class="btn btn-sm btn-primary" title="Salvar alterações">
                          <i class="bi bi-save"></i> Salvar
                        </button>
                      </form>

                      <!-- Redefinição de senha -->
                      <form method="post" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="act" value="resetpass">
                        <input type="password" name="password" class="form-control form-control-sm" placeholder="Nova senha" required autocomplete="new-password" style="max-width:220px;">
                        <button class="btn btn-sm btn-warning" title="Redefinir senha" onclick="return confirm('Confirmar a redefinição de senha para <?= esc($r['username']) ?>?');">
                          <i class="bi bi-shield-lock"></i> Redefinir
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr>
                  <td colspan="4" class="text-center text-muted">Nenhum administrador.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <script>
      (function() {
        const createRole = document.getElementById('createRole');
        const createSchool = document.getElementById('createSchool');
        const createSchoolHelp = document.getElementById('createSchoolHelp');

        function syncSchoolRequirement(roleValue, schoolSelect, helpEl) {
          const requiresSchool = roleValue === 'school_admin';
          schoolSelect.required = requiresSchool;
          schoolSelect.disabled = !requiresSchool && schoolSelect.tagName === 'SELECT' && schoolSelect.name === 'school_id' && false; // keep enabled for optional selection
          if (helpEl) helpEl.classList.toggle('text-danger', requiresSchool);
        }

        if (createRole && createSchool) {
          const updateCreate = () => syncSchoolRequirement(createRole.value, createSchool, createSchoolHelp);
          createRole.addEventListener('change', updateCreate);
          updateCreate();
        }

        document.querySelectorAll('form[data-update-form]').forEach(function(form) {
          const roleSel = form.querySelector('[data-role-select]');
          const schoolSel = form.querySelector('[data-school-select]');
          if (!roleSel || !schoolSel) return;
          const update = () => syncSchoolRequirement(roleSel.value, schoolSel);
          roleSel.addEventListener('change', update);
          update();
        });
      })();
    </script>

    <div class="text-center my-5">
      <a href="dashboard.php" class="btn btn-outline-primary rounded-pill px-4 py-2 shadow-sm">
        <i class="bi bi-arrow-left-circle me-2"></i> Voltar ao Painel
      </a>
    </div>
  </div>
</body>

</html>
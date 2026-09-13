<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);
if (!is_network_admin($adm)) {
  http_response_code(403);
  flash_redirect('error', 'Acesso restrito a administradores da rede.', 'dashboard.php');
}

$msg = $_GET['msg'] ?? '';
$msgType = isset($_GET['msg_type']) ? $_GET['msg_type'] : 'success';
$schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
try {
  $pdo->query("SELECT name FROM admins LIMIT 1");
} catch (Throwable $e) {
  try {
    $pdo->exec("ALTER TABLE admins ADD COLUMN name VARCHAR(120) NULL AFTER username");
    $pdo->exec("UPDATE admins SET name = COALESCE(NULLIF(name, ''), username)");
  } catch (Throwable $ignored) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $act = $_POST['act'] ?? '';
  if ($act === 'create') {
    $name = trim($_POST['name'] ?? '');
    $cpfRaw = trim($_POST['cpf'] ?? '');
    $cpf = preg_replace('/\D/', '', $cpfRaw);
    $pass = $_POST['password'] ?? '';
    $passConfirm = $_POST['password_confirm'] ?? '';
    $role = $_POST['role'] ?? 'school_admin';
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    if ($name === '' || strlen($cpf) !== 11 || !validar_cpf($cpf) || $pass === '' || $passConfirm === '' || $pass !== $passConfirm || !in_array($role, ['network_admin', 'school_admin'], true)) {
      if ($name === '') {
        $msg = 'Nome é obrigatório.';
      } elseif ($pass === '' || $passConfirm === '' || $pass !== $passConfirm) {
        $msg = 'As senhas não conferem.';
      } else {
      $msg = strlen($cpf) !== 11 || !validar_cpf($cpf) ? 'CPF deve ter 11 dígitos válidos.' : 'Dados invalidos.';
      }
    } else {
      $st = $pdo->prepare("SELECT id FROM admins WHERE cpf = ?");
      $st->execute([$cpf]);
      if ($st->fetch()) {
        $msg = 'Este CPF já está cadastrado.';
      } else {
        // NC-43: valida a forca da senha antes de gravar.
        [$pwdOk, , $pwdMsg] = admin_validate_password($pass, $cpf, $name);
        if (!$pwdOk) { $msg = $pwdMsg; } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO admins (username, name, cpf, password_hash, role, school_id) VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([$cpf, $name, $cpf, $hash, $role, $school_id]);
        audit_log('create', 'admin', $pdo->lastInsertId(), ['name' => $name, 'cpf' => mask_cpf($cpf), 'role' => $role]);
        header('Location: admins.php?msg=' . urlencode('Administrador criado.'));
        exit;
        }
      }
    }
  } elseif ($act === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $cpfRaw = trim($_POST['cpf'] ?? '');
    $cpf = preg_replace('/\D/', '', $cpfRaw);
    $pass = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'school_admin';
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    if ($id <= 0 || $name === '' || strlen($cpf) !== 11 || !validar_cpf($cpf) || !in_array($role, ['network_admin', 'school_admin'], true)) {
      $msg = strlen($cpf) !== 11 || !validar_cpf($cpf) ? 'CPF deve ter 11 dígitos válidos.' : 'Dados invalidos.';
    } else {
      $st = $pdo->prepare("SELECT id FROM admins WHERE cpf = ? AND id <> ?");
      $st->execute([$cpf, $id]);
      if ($st->fetch()) {
        $msg = 'Este CPF já está cadastrado para outro administrador.';
      } else {
        if ($pass !== '') {
          [$pwdOk, , $pwdMsg] = admin_validate_password($pass, $cpf, $name);
          if (!$pwdOk) { $msg = $pwdMsg; $st = null; }
          else {
          $hash = password_hash($pass, PASSWORD_DEFAULT);
          $st = $pdo->prepare("UPDATE admins SET username=?, name=?, cpf=?, role=?, school_id=?, password_hash=? WHERE id=?");
          $st->execute([$cpf, $name, $cpf, $role, $school_id, $hash, $id]);
          }
        } else {
          $st = $pdo->prepare("UPDATE admins SET username=?, name=?, cpf=?, role=?, school_id=? WHERE id=?");
          $st->execute([$cpf, $name, $cpf, $role, $school_id, $id]);
        }
        audit_log('update', 'admin', $id, ['name' => $name, 'cpf' => mask_cpf($cpf), 'role' => $role, 'password_updated' => $pass !== '']);
        header('Location: admins.php?msg=' . urlencode('Administrador atualizado.'));
        exit;
      }
    }
  } elseif ($act === 'resetpass') {
    $id = (int)($_POST['id'] ?? 0);
    $pass = $_POST['password'] ?? '';
    if ($id > 0 && $pass !== '') {
      $alvo = $pdo->prepare("SELECT cpf, name FROM admins WHERE id = ?");
      $alvo->execute([$id]);
      $a = $alvo->fetch(PDO::FETCH_ASSOC) ?: [];
      [$pwdOk, , $pwdMsg] = admin_validate_password($pass, (string)($a['cpf'] ?? ''), (string)($a['name'] ?? ''));
      if (!$pwdOk) { $msg = $pwdMsg; } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $st = $pdo->prepare("UPDATE admins SET password_hash=? WHERE id=?");
      $st->execute([$hash, $id]);
      audit_log('update', 'admin', $id, ['reset_password' => true]);
      header('Location: admins.php?msg=' . urlencode('Senha redefinida.'));
      exit;
      }
    } else {
      $msg = 'Senha invalida.';
    }
  } elseif ($act === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $msg = 'ID inválido.';
      $msgType = 'danger';
    } elseif ($id === (int)$adm['id']) {
      $msg = 'Você não pode excluir seu próprio usuário.';
      $msgType = 'danger';
    } else {
      $st = $pdo->prepare("SELECT name, cpf FROM admins WHERE id = ?");
      $st->execute([$id]);
      $target = $st->fetch(PDO::FETCH_ASSOC);
      if (!$target) {
        $msg = 'Administrador não encontrado.';
        $msgType = 'danger';
      } else {
        $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
        audit_log('delete', 'admin', $id, ['name' => $target['name'] ?? '', 'cpf' => mask_cpf($target['cpf'] ?? '')]);
        header('Location: admins.php?msg=' . urlencode('Administrador excluído.'));
        exit;
      }
    }
  }
}

$rows = $pdo->query("SELECT a.*, s.name AS school_name FROM admins a LEFT JOIN schools s ON s.id = a.school_id ORDER BY a.role DESC, COALESCE(a.cpf, a.username)")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Administradores | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <div class="container-fluid">

    <!-- Header -->
    <div class="app-page-header">
      <div class="app-page-header__main">
        <div class="app-page-icon"><i class="bi bi-person-badge"></i></div>
        <div>
          <h1 class="app-page-title">Administradores</h1>
          <p class="app-page-subtitle">Gerencie os administradores da rede: crie, edite e redefina senhas.</p>
        </div>
      </div>
      <button class="btn btn-success" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasCreate" aria-label="Cadastrar novo administrador">
        <i class="bi bi-person-plus me-1"></i> Novo Administrador
      </button>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType === 'danger' ? 'danger' : 'success' ?> alert-dismissible fade show">
        <i class="bi bi-<?= $msgType === 'danger' ? 'exclamation-triangle' : 'check-circle' ?> me-2"></i><?= esc($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <!-- Table -->
    <section class="app-section-card app-table-card">
      <header class="app-section-card__header">
        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-people"></i>Lista</span>
        <h2 class="app-section-card__title">Administradores Cadastrados</h2>
        <span class="app-section-card__hint"><?= count($rows) ?> admin(s)</span>
      </header>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th scope="col">Nome</th>
              <th scope="col">CPF</th>
              <th scope="col">Instituição</th>
              <th scope="col" class="text-center" style="width:90px;">Acoes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
              $rid       = (int)$r['id'];
              ?>
              <?php $cpfDisplay = !empty($r['cpf']) ? mask_cpf($r['cpf']) : esc($r['username']); ?>
              <tr>
                <td class="fw-semibold text-break"><?= esc((string)($r['name'] ?? $r['username'])) ?></td>
                <td class="fw-semibold text-break">
                  <i class="bi bi-person-circle me-1 text-muted"></i><?= esc($cpfDisplay) ?>
                </td>
                <td class="text-muted small"><?= esc($r['school_name'] ?? '—') ?></td>
                <td class="text-center">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-label="Ações">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <button class="dropdown-item" type="button" onclick='openEditPanel(<?= $rid ?>, <?= json_encode($cpfDisplay, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, <?= json_encode((string)($r['cpf'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, <?= json_encode((string)($r['name'] ?? $r['username']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, <?= json_encode((string)$r['role'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, <?= $r['school_id'] ? (int)$r['school_id'] : 'null' ?>)'>
                          <i class="bi bi-pencil-square me-2 text-primary"></i>Editar papel / escola
                        </button>
                      </li>
                      <li>
                        <button class="dropdown-item" type="button" onclick='openPassPanel(<?= $rid ?>, <?= json_encode($cpfDisplay, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                          <i class="bi bi-shield-lock me-2 text-warning"></i>Redefinir senha
                        </button>
                      </li>
                      <?php if ((int)$r['id'] !== (int)$adm['id']): ?>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form action="" method="post" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este administrador?');">
                          <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                          <input type="hidden" name="act" value="delete">
                          <input type="hidden" name="id" value="<?= $rid ?>">
                          <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-trash me-2"></i>Excluir
                          </button>
                        </form>
                      </li>
                      <?php endif; ?>
                    </ul>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">Nenhum administrador cadastrado.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- Offcanvas: Criar -->
  <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasCreate" style="width:min(480px,100vw)">
    <div class="offcanvas-header border-bottom">
      <h5 class="offcanvas-title"><i class="bi bi-person-plus me-2"></i>Novo Administrador</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
      <form action="" method="post" autocomplete="off" novalidate id="formCreateAdmin">
        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="act" value="create">
        <div class="mb-3">
          <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
            <input class="form-control" name="name" placeholder="Nome do administrador" required maxlength="120" autocomplete="name">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">CPF <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input class="form-control" name="cpf" id="createCpf" placeholder="000.000.000-00" required maxlength="14" inputmode="numeric" pattern="[0-9\s\.\-]*" autocomplete="off">
          </div>
          <div class="form-text">Apenas números. Será usado no login.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Senha <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
            <input id="createPassword" class="form-control" type="password" name="password" placeholder="Defina uma senha" required autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" id="toggleCreatePassword" aria-label="Visualizar senha">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Repetir Senha <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-shield-check"></i></span>
            <input id="createPasswordConfirm" class="form-control" type="password" name="password_confirm" placeholder="Repita a senha" required autocomplete="new-password">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Papel <span class="text-danger">*</span></label>
          <select id="createRole" class="form-select" name="role" required>
            <option value="school_admin">Admin da Escola</option>
            <option value="network_admin">Admin da Rede</option>
          </select>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold">Escola</label>
          <select id="createSchool" class="form-select" name="school_id">
            <option value="">(Sem escola / Rede)</option>
            <?php foreach ($schools as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= esc(mb_convert_case($s['name'], MB_CASE_TITLE, 'UTF-8')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-success flex-fill"><i class="bi bi-check2-circle me-1"></i>Criar</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancelar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Offcanvas: Editar -->
  <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasEdit" style="width:min(480px,100vw)">
    <div class="offcanvas-header border-bottom">
      <h5 class="offcanvas-title"><i class="bi bi-pencil-square me-2"></i>Editar Administrador</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
      <p class="text-muted mb-4">Editando: <strong id="editCpfDisplay">—</strong></p>
      <form action="" method="post" autocomplete="off" id="formEditAdmin">
        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="act" value="update">
        <input type="hidden" name="id" id="editId">
        <div class="mb-3">
          <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
            <input id="editName" class="form-control" name="name" required maxlength="120" autocomplete="name">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">CPF <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input class="form-control" name="cpf" id="editCpf" placeholder="000.000.000-00" required maxlength="14" inputmode="numeric" pattern="[0-9\s\.\-]*" autocomplete="off">
          </div>
          <div class="form-text">Apenas números. Será usado no login.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Papel</label>
          <select id="editRole" class="form-select" name="role">
            <option value="school_admin">Admin da Escola</option>
            <option value="network_admin">Admin da Rede</option>
          </select>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold">Escola</label>
          <select id="editSchool" class="form-select" name="school_id">
            <option value="">(Sem escola / Rede)</option>
            <?php foreach ($schools as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= esc(mb_convert_case($s['name'], MB_CASE_TITLE, 'UTF-8')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold">Senha</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" class="form-control" name="password" placeholder="Digite para criar/alterar senha" autocomplete="new-password">
          </div>
          <div class="form-text">Deixe em branco para manter a senha atual.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-primary flex-fill"><i class="bi bi-save me-1"></i>Salvar</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancelar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Offcanvas: Redefinir senha -->
  <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasPass" style="width:min(480px,100vw)">
    <div class="offcanvas-header border-bottom">
      <h5 class="offcanvas-title"><i class="bi bi-shield-lock me-2"></i>Redefinir Senha</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
      <p class="text-muted mb-4">Redefinindo senha de: <strong id="passCpfDisplay">—</strong></p>
      <form action="" method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="act" value="resetpass">
        <input type="hidden" name="id" id="passId">
        <div class="mb-4">
          <label class="form-label fw-semibold">Nova Senha <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input id="newPassword" type="password" class="form-control" name="password" placeholder="Digite a nova senha" required autocomplete="new-password">
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-warning flex-fill"><i class="bi bi-shield-lock me-1"></i>Redefinir</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancelar</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function maskCpf(value) {
      var d = (value || '').replace(/\D/g, '');
      if (d.length <= 3) return d;
      if (d.length <= 6) return d.slice(0, 3) + '.' + d.slice(3);
      if (d.length <= 9) return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6);
      return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6, 9) + '-' + d.slice(9, 11);
    }
    function openEditPanel(id, cpfDisplay, cpfRaw, adminName, role, schoolId) {
      document.getElementById('editId').value = id;
      document.getElementById('editCpfDisplay').textContent = cpfDisplay;
      document.getElementById('editCpf').value = maskCpf(cpfRaw || '');
      document.getElementById('editName').value = adminName || '';
      document.getElementById('editRole').value = role;
      document.getElementById('editSchool').value = schoolId || '';
      bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasEdit')).show();
    }
    function openPassPanel(id, cpfDisplay) {
      document.getElementById('passId').value = id;
      document.getElementById('passCpfDisplay').textContent = cpfDisplay;
      document.getElementById('newPassword').value = '';
      bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasPass')).show();
    }
    (function () {
      var cpfInput = document.getElementById('createCpf');
      var editCpfInput = document.getElementById('editCpf');
      var createPassInput = document.getElementById('createPassword');
      var createPassConfirmInput = document.getElementById('createPasswordConfirm');
      var toggleCreatePasswordBtn = document.getElementById('toggleCreatePassword');
      if (cpfInput) {
        cpfInput.addEventListener('input', function () {
          this.value = maskCpf(this.value);
        });
      }
      if (editCpfInput) {
        editCpfInput.addEventListener('input', function () {
          this.value = maskCpf(this.value);
        });
      }
      var form = document.getElementById('formCreateAdmin');
      function validateCreatePasswords() {
        if (!createPassInput || !createPassConfirmInput) return true;
        if (createPassInput.value !== createPassConfirmInput.value) {
          createPassConfirmInput.setCustomValidity('As senhas não conferem.');
          return false;
        }
        createPassConfirmInput.setCustomValidity('');
        return true;
      }
      if (toggleCreatePasswordBtn && createPassInput && createPassConfirmInput) {
        toggleCreatePasswordBtn.addEventListener('click', function () {
          var showing = createPassInput.type === 'text';
          createPassInput.type = showing ? 'password' : 'text';
          createPassConfirmInput.type = showing ? 'password' : 'text';
          this.innerHTML = showing ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        });
      }
      createPassInput?.addEventListener('input', validateCreatePasswords);
      createPassConfirmInput?.addEventListener('input', validateCreatePasswords);
      if (form && cpfInput) {
        form.addEventListener('submit', function (event) {
          if (!validateCreatePasswords()) {
            event.preventDefault();
            createPassConfirmInput.reportValidity();
            return;
          }
          if (cpfInput.value) cpfInput.value = cpfInput.value.replace(/\D/g, '');
        });
      }
      var editForm = document.getElementById('formEditAdmin');
      if (editForm && editCpfInput) {
        editForm.addEventListener('submit', function () {
          if (editCpfInput.value) editCpfInput.value = editCpfInput.value.replace(/\D/g, '');
        });
      }
    })();
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

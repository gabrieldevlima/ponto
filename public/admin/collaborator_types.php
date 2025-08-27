<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

// Create/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $name = trim($_POST['name'] ?? '');
  $slug = trim($_POST['slug'] ?? '');
  $mode = $_POST['schedule_mode'] ?? 'none';
  if ($name === '' || $slug === '') die('Nome e slug são obrigatórios.');
  if (!in_array($mode, ['none','classes','time'], true)) die('Modo inválido.');

  if ($id > 0) {
    $st = $pdo->prepare("UPDATE collaborator_types SET name=?, slug=?, schedule_mode=?, requires_schedule = IF(?='none',0,1) WHERE id=?");
    $st->execute([$name, $slug, $mode, $mode, $id]);
    header('Location: collaborator_types.php?msg=' . urlencode('Tipo atualizado.'));
  } else {
    $st = $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, ?, ?)");
    $st->execute([$name, $slug, $mode, $mode === 'none' ? 0 : 1]);
    header('Location: collaborator_types.php?msg=' . urlencode('Tipo criado.'));
  }
  exit;
}

$msg = $_GET['msg'] ?? '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = null;
if ($editId) {
  $st = $pdo->prepare("SELECT * FROM collaborator_types WHERE id = ?");
  $st->execute([$editId]);
  $edit = $st->fetch();
}

$rows = $pdo->query("SELECT * FROM collaborator_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Tipos de Colaboradores</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">Admin</a>
    <div class="ms-auto">
      <a class="btn btn-outline-light me-2" href="teachers.php">Colaboradores</a>
      <a class="btn btn-outline-light" href="attendances.php">Registros</a>
    </div>
  </div>
</nav>
<div class="container">
  <h3 class="mb-3">Tipos de Colaboradores</h3>
  <?php if ($msg): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><?= $edit ? 'Editar Tipo' : 'Novo Tipo' ?></div>
        <div class="card-body">
          <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
            <div class="mb-3">
              <label class="form-label">Nome</label>
              <input type="text" name="name" class="form-control" required maxlength="100" value="<?= esc($edit['name'] ?? '') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Slug</label>
              <input type="text" name="slug" class="form-control" required maxlength="50" value="<?= esc($edit['slug'] ?? '') ?>">
              <div class="form-text">Ex.: teacher, director, driver</div>
            </div>
            <div class="mb-3">
              <label class="form-label">Modo de Rotina</label>
              <select name="schedule_mode" class="form-select">
                <?php
                  $modes = ['none'=>'Sem rotina','classes'=>'Aulas (professores)','time'=>'Horário (entrada/saída)'];
                  $current = $edit['schedule_mode'] ?? 'time';
                ?>
                <?php foreach ($modes as $k=>$v): ?>
                  <option value="<?= esc($k) ?>" <?= $k===$current?'selected':'' ?>><?= esc($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <button class="btn btn-success" type="submit"><?= $edit ? 'Salvar' : 'Criar' ?></button>
              <a class="btn btn-secondary" href="collaborator_types.php">Cancelar</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="table-responsive">
        <table class="table table-bordered align-middle">
          <thead class="table-light">
            <tr><th>Nome</th><th>Slug</th><th>Modo</th><th style="width:120px;">Ações</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= esc($row['name']) ?></td>
                <td><code><?= esc($row['slug']) ?></code></td>
                <td><?= esc($row['schedule_mode']) ?></td>
                <td><a href="collaborator_types.php?edit=<?= (int)$row['id'] ?>" class="btn btn-sm btn-primary">Editar</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="4" class="text-center text-muted">Nenhum tipo cadastrado.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
</body>
</html>
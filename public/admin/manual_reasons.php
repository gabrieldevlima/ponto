<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

// Create/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $name = trim($_POST['name'] ?? '');
  $active = isset($_POST['active']) ? 1 : 0;
  $sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
  if ($name === '') die('Nome é obrigatório.');
  if ($id > 0) {
    $st = $pdo->prepare("UPDATE manual_reasons SET name=?, active=?, sort_order=? WHERE id=?");
    $st->execute([$name, $active, $sort_order, $id]);
    header('Location: manual_reasons.php?msg=' . urlencode('Motivo atualizado.'));
  } else {
    $st = $pdo->prepare("INSERT INTO manual_reasons (name, active, sort_order) VALUES (?, ?, ?)");
    $st->execute([$name, $active, $sort_order]);
    header('Location: manual_reasons.php?msg=' . urlencode('Motivo criado.'));
  }
  exit;
}

$msg = $_GET['msg'] ?? '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = null;
if ($editId) {
  $st = $pdo->prepare("SELECT * FROM manual_reasons WHERE id = ?");
  $st->execute([$editId]);
  $edit = $st->fetch();
}

$rows = $pdo->query("SELECT * FROM manual_reasons ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Motivos de Ponto Manual</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">Admin</a>
    <div class="ms-auto">
      <a class="btn btn-outline-light me-2" href="attendance_manual.php">Inserir Ponto Manual</a>
      <a class="btn btn-outline-light" href="attendances.php">Registros</a>
    </div>
  </div>
</nav>
<div class="container">
  <h3 class="mb-3">Motivos de Inserção Manual</h3>
  <?php if ($msg): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><?= $edit ? 'Editar Motivo' : 'Novo Motivo' ?></div>
        <div class="card-body">
          <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
            <div class="mb-3">
              <label class="form-label">Nome</label>
              <input type="text" name="name" class="form-control" required maxlength="150" value="<?= esc($edit['name'] ?? '') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Ordem</label>
              <input type="number" name="sort_order" class="form-control" value="<?= esc($edit['sort_order'] ?? 0) ?>">
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="active" id="active" <?= !isset($edit['active']) || (int)$edit['active'] === 1 ? 'checked' : ((int)$edit['active']===1 ? 'checked':'') ?>>
              <label class="form-check-label" for="active">Ativo</label>
            </div>
            <div>
              <button class="btn btn-success" type="submit"><?= $edit ? 'Salvar' : 'Criar' ?></button>
              <a class="btn btn-secondary" href="manual_reasons.php">Cancelar</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="table-responsive">
        <table class="table table-bordered align-middle">
          <thead class="table-light">
            <tr><th>Nome</th><th>Ordem</th><th>Status</th><th style="width:140px;">Ações</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= esc($row['name']) ?></td>
                <td><?= (int)$row['sort_order'] ?></td>
                <td><?= (int)$row['active'] === 1 ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>' ?></td>
                <td>
                  <a href="manual_reasons.php?edit=<?= (int)$row['id'] ?>" class="btn btn-sm btn-primary">Editar</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="4" class="text-muted text-center">Nenhum motivo cadastrado.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
</body>
</html>
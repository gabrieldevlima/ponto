<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('leaves.manage')) {
    http_response_code(403);
    exit('Sem permissão.');
}
$pdo = db();

$msg = $_GET['msg'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id) {
    $st = $pdo->prepare("SELECT * FROM leave_types WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $paid = isset($_POST['paid']) ? 1 : 0;
    $affects_bank = isset($_POST['affects_bank']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;
    if ($name === '' || $code === '') $msg = 'Nome e código são obrigatórios.';
    else {
        if ($id > 0) {
            $st = $pdo->prepare("UPDATE leave_types SET name=?, code=?, paid=?, affects_bank=?, active=? WHERE id=?");
            $st->execute([$name, $code, $paid, $affects_bank, $active, $id]);
            audit_log('update', 'leave_type', $id, ['name' => $name, 'code' => $code]);
            header('Location: leave_types.php?msg=' . urlencode('Tipo atualizado.'));
            exit;
        } else {
            $st = $pdo->prepare("INSERT INTO leave_types (name, code, paid, affects_bank, active) VALUES (?, ?, ?, ?, ?)");
            $st->execute([$name, $code, $paid, $affects_bank, $active]);
            audit_log('create', 'leave_type', $pdo->lastInsertId(), ['name' => $name, 'code' => $code]);
            header('Location: leave_types.php?msg=' . urlencode('Tipo criado.'));
            exit;
        }
    }
}

$rows = $pdo->query("SELECT * FROM leave_types ORDER BY active DESC, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <title>Tipos de Afastamento</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">Admin</a>
            <div class="ms-auto">
                <a class="btn btn-outline-light me-2" href="leaves.php">Afastamentos</a>
                <a class="btn btn-light" href="leave_types.php">Tipos</a>
            </div>
        </div>
    </nav>
    <div class="container">
        <?php if ($msg): ?><div class="alert alert-info"><?= esc($msg) ?></div><?php endif; ?>
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><?= $row ? 'Editar Tipo' : 'Novo Tipo' ?></div>
                    <div class="card-body">
                        <form method="post" autocomplete="off">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
                            <div class="mb-3">
                                <label class="form-label">Nome</label>
                                <input class="form-control" name="name" required maxlength="100" value="<?= esc($row['name'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Código</label>
                                <input class="form-control" name="code" required maxlength="50" value="<?= esc($row['code'] ?? '') ?>">
                            </div>
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="paid" name="paid" <?= (int)($row['paid'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="paid">Remunerado</label>
                            </div>
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="affects_bank" name="affects_bank" <?= (int)($row['affects_bank'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="affects_bank">Afeta Banco de Horas</label>
                            </div>
                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="active" name="active" <?= (int)($row['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="active">Ativo</label>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-success">Salvar</button>
                                <a href="leave_types.php" class="btn btn-secondary">Cancelar</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Código</th>
                                <th>Remunerado</th>
                                <th>Banco Horas</th>
                                <th>Status</th>
                                <th style="width:120px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= esc($r['name']) ?></td>
                                    <td><code><?= esc($r['code']) ?></code></td>
                                    <td><?= (int)$r['paid'] === 1 ? 'Sim' : 'Não' ?></td>
                                    <td><?= (int)$r['affects_bank'] === 1 ? 'Sim' : 'Não' ?></td>
                                    <td><?= (int)$r['active'] === 1 ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>' ?></td>
                                    <td><a href="leave_types.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">Editar</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Nenhum tipo cadastrado.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
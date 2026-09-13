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
  if ($name === '') flash_redirect('error', 'Nome é obrigatório.');
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
    <title>Motivos de Ponto Manual | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>

    <div class="container-fluid admin-content">

        <!-- Header -->
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-pencil-square"></i></div><div><h1 class="app-page-title">Motivos de Insercao Manual</h1><p class="app-page-subtitle">Gerencie os motivos disponiveis para justificar registros de ponto inseridos manualmente.</p></div></div></div>

        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i>
                <div><?= esc($msg) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- Form Card -->
            <div class="col-lg-4">
                <section class="app-section-card">
                    <header class="app-section-card__header">
                        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-<?= $edit ? 'pencil-square' : 'plus-circle' ?>"></i><?= $edit ? 'Edição' : 'Novo' ?></span>
                        <h2 class="app-section-card__title"><?= $edit ? 'Editar Motivo' : 'Novo Motivo' ?></h2>
                    </header>
                    <div class="app-section-card__body">
                        <form action="" method="post" autocomplete="off">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <?php if ($edit): ?>
                                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Descricao <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required maxlength="150"
                                       value="<?= esc($edit['name'] ?? '') ?>"
                                       placeholder="Ex.: Esquecimento de registro, Falha no sistema">
                                <div class="form-text">Texto que sera exibido ao colaborador ao registrar o ponto.</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Ordem de Exibicao</label>
                                <div class="input-group input-group-sm" style="max-width:140px">
                                    <span class="input-group-text"><i class="bi bi-sort-numeric-up"></i></span>
                                    <input type="number" name="sort_order" class="form-control"
                                           value="<?= esc($edit['sort_order'] ?? 0) ?>" min="0">
                                </div>
                                <div class="form-text">Numeros menores aparecem primeiro.</div>
                            </div>

                            <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-4">
                                <div>
                                    <i class="bi bi-toggle-on me-2 text-info"></i>
                                    <span class="fw-semibold small">Ativo</span>
                                    <div class="text-muted" style="font-size:0.75rem;">Disponivel para selecao no ponto manual</div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="active" name="active"
                                           <?= (!isset($edit['active']) || (int)$edit['active'] === 1) ? 'checked' : '' ?>>
                                </div>
                            </div>

                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-<?= $edit ? 'warning' : 'success' ?> flex-grow-1" type="submit">
                                    <i class="bi bi-<?= $edit ? 'save' : 'plus-lg' ?> me-1"></i>
                                    <?= $edit ? 'Salvar Alteracoes' : 'Criar Motivo' ?>
                                </button>
                                <?php if ($edit): ?>
                                <a class="btn btn-outline-secondary" href="manual_reasons.php">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </section>
            </div>

            <!-- Table Card -->
            <div class="col-lg-8">
                <section class="app-section-card app-table-card">
                    <header class="app-section-card__header">
                        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-list-ul"></i>Lista</span>
                        <h2 class="app-section-card__title">Motivos Cadastrados</h2>
                        <span class="app-section-card__hint"><?= count($rows) ?> motivo(s)</span>
                    </header>
                    <div class="table-responsive">
                        <?php if (empty($rows)): ?>
                            <div class="p-5 text-center text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                <p class="mb-0">Nenhum motivo cadastrado ainda.</p>
                            </div>
                        <?php else: ?>
                            <table class="table table-hover align-middle table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="text-center" style="width:55px;">Ordem</th>
                                        <th scope="col">Descricao</th>
                                        <th scope="col" class="text-center">Status</th>
                                        <th scope="col" class="text-center" style="width:70px;">Acoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row):
                                        $isEditing = ($edit && (int)$edit['id'] === (int)$row['id']);
                                    ?>
                                    <tr class="<?= $isEditing ? 'table-warning' : '' ?>">
                                        <td class="text-center">
                                            <span class="badge bg-light text-secondary border"><?= (int)$row['sort_order'] ?></span>
                                        </td>
                                        <td><?= esc($row['name']) ?></td>
                                        <td class="text-center">
                                            <?php if ((int)$row['active'] === 1): ?>
                                                <span class="badge bg-success rounded-pill">
                                                    <i class="bi bi-check-lg me-1"></i>Ativo
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary rounded-pill">
                                                    <i class="bi bi-dash me-1"></i>Inativo
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="manual_reasons.php?edit=<?= (int)$row['id'] ?>"
                                               class="btn btn-sm btn-outline-primary" title="Editar">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

        </div>
    </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

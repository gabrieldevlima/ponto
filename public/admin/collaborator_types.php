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
  if ($name === '' || $slug === '') flash_redirect('error', 'Nome e slug são obrigatórios.');
  if (!in_array($mode, ['none','classes','time','hours'], true)) flash_redirect('error', 'Modo de jornada inválido.');

  if ($id > 0) {
    $st = $pdo->prepare("UPDATE collaborator_types SET name=?, slug=?, schedule_mode=?, requires_schedule = IF(? COLLATE utf8mb4_unicode_ci = 'none',0,1) WHERE id=?");
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
    <title>Tipos de Colaboradores | DEEDO Ponto</title>
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
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-people"></i></div><div><h1 class="app-page-title">Tipos de Colaboradores</h1><p class="app-page-subtitle">Defina os tipos e modos de jornada dos colaboradores do sistema.</p></div></div></div>

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
                        <h2 class="app-section-card__title"><?= $edit ? 'Editar Tipo' : 'Novo Tipo' ?></h2>
                    </header>
                    <div class="app-section-card__body">
                        <form action="" method="post" autocomplete="off">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <?php if ($edit): ?>
                                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required maxlength="100"
                                       value="<?= esc($edit['name'] ?? '') ?>" placeholder="Ex.: Professor, Diretor">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Slug <span class="text-danger">*</span></label>
                                <input type="text" name="slug" class="form-control" required maxlength="50"
                                       value="<?= esc($edit['slug'] ?? '') ?>" placeholder="Ex.: teacher, director">
                                <div class="form-text">Identificador unico em letras minusculas e hifens.</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Modo de Jornada</label>
                                <?php
                                    $modes = [
                                        'none'    => 'Sem rotina',
                                        'classes' => 'Aulas (professores)',
                                        'time'    => 'Horario (entrada/saida)',
                                        'hours'   => 'Horas/dia (motorista, monitor)',
                                    ];
                                    $modeIcons = [
                                        'none'    => 'dash-circle',
                                        'classes' => 'journal-text',
                                        'time'    => 'clock',
                                        'hours'   => 'stopwatch',
                                    ];
                                    $current = $edit['schedule_mode'] ?? 'time';
                                ?>
                                <div class="d-flex flex-column gap-2 mt-1">
                                    <?php foreach ($modes as $k => $v): ?>
                                    <div class="form-check border rounded p-2 ps-4 <?= $k === $current ? 'border-primary bg-primary-subtle' : '' ?>">
                                        <input class="form-check-input" type="radio" name="schedule_mode"
                                               id="mode_<?= $k ?>" value="<?= esc($k) ?>" <?= $k === $current ? 'checked' : '' ?>>
                                        <label class="form-check-label w-100" for="mode_<?= $k ?>">
                                            <i class="bi bi-<?= $modeIcons[$k] ?> me-1 text-muted"></i>
                                            <strong><?= esc($v) ?></strong>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-<?= $edit ? 'warning' : 'success' ?> flex-grow-1" type="submit">
                                    <i class="bi bi-<?= $edit ? 'save' : 'plus-lg' ?> me-1"></i>
                                    <?= $edit ? 'Salvar Alteracoes' : 'Criar Tipo' ?>
                                </button>
                                <?php if ($edit): ?>
                                <a class="btn btn-outline-secondary" href="collaborator_types.php">
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
                        <h2 class="app-section-card__title">Tipos Cadastrados</h2>
                        <span class="app-section-card__hint"><?= count($rows) ?> tipo(s)</span>
                    </header>
                    <div class="table-responsive">
                        <?php if (empty($rows)): ?>
                            <div class="p-5 text-center text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                <p class="mb-0">Nenhum tipo de colaborador cadastrado ainda.</p>
                            </div>
                        <?php else: ?>
                            <table class="table table-hover align-middle table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Nome</th>
                                        <th scope="col">Slug</th>
                                        <th scope="col">Modo de Jornada</th>
                                        <th scope="col" class="text-center" style="width:80px;">Acoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $modeLabels = [
                                        'none'    => ['label'=>'Sem rotina','color'=>'secondary','icon'=>'dash-circle'],
                                        'classes' => ['label'=>'Aulas','color'=>'info','icon'=>'journal-text'],
                                        'time'    => ['label'=>'Horario','color'=>'primary','icon'=>'clock'],
                                        'hours'   => ['label'=>'Horas/dia','color'=>'success','icon'=>'stopwatch'],
                                    ];
                                    foreach ($rows as $row):
                                        $m = $modeLabels[$row['schedule_mode']] ?? ['label'=>$row['schedule_mode'],'color'=>'secondary','icon'=>'question'];
                                        $isEditing = ($edit && (int)$edit['id'] === (int)$row['id']);
                                    ?>
                                    <tr class="<?= $isEditing ? 'table-warning' : '' ?>">
                                        <td class="fw-semibold"><?= esc($row['name']) ?></td>
                                        <td><code class="small"><?= esc($row['slug']) ?></code></td>
                                        <td>
                                            <span class="badge bg-<?= $m['color'] ?>-subtle text-<?= $m['color'] ?>-emphasis border border-<?= $m['color'] ?>-subtle">
                                                <i class="bi bi-<?= $m['icon'] ?> me-1"></i><?= $m['label'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="collaborator_types.php?edit=<?= (int)$row['id'] ?>"
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

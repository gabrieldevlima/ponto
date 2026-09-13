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
$q = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($q !== '') {
  $where[] = "(name LIKE ? OR code LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}
$sql = "SELECT * FROM schools";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY active DESC, name ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Instituições | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <div class="container-fluid">
    <div class="app-page-header">
      <div class="app-page-header__main">
        <div class="app-page-icon"><i class="bi bi-building"></i></div>
        <div>
          <h1 class="app-page-title">Instituições</h1>
          <p class="app-page-subtitle">Gerencie as instituições da rede: crie, edite e pesquise instituições cadastradas.</p>
        </div>
      </div>
      <a href="school_edit.php" class="btn btn-success" aria-label="Cadastrar nova instituição">
        <i class="bi bi-plus-circle me-1"></i>
        <span class="d-none d-sm-inline">Nova Instituição</span>
        <span class="d-inline d-sm-none">Novo</span>
      </a>
    </div>
    <?php if ($msg): ?>
      <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-check-circle-fill"></i>
        <div class="flex-grow-1"><?= esc($msg) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
      </div>
    <?php endif; ?>

    <form class="app-filter-card" method="get" autocomplete="off" role="search">
      <div class="row g-3 align-items-end">
        <div class="col-12 col-md-7 col-lg-6">
          <label for="q" class="form-label small fw-semibold">Buscar instituição</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="q" class="form-control" name="q" placeholder="Nome ou código (ex: EMEF-CENTRO)" value="<?= esc($q) ?>" aria-label="Buscar instituições">
          </div>
        </div>
        <div class="col-12 col-md-5 col-lg-3 d-flex gap-2 flex-wrap">
          <button type="submit" class="btn btn-primary flex-grow-1">
            <i class="bi bi-funnel me-1"></i>Filtrar
          </button>
          <a class="btn btn-outline-secondary" href="schools.php" aria-label="Limpar filtros">
            <i class="bi bi-x-circle"></i>
          </a>
        </div>
      </div>
    </form>

    <div class="app-section-card app-table-card">
      <div class="app-section-card__header">
        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-list-ul"></i>Instituições</span>
        <h2 class="app-section-card__title">Cadastradas</h2>
        <span class="app-section-card__hint"><?= count($rows) ?> resultado(s)</span>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">Nome</th>
              <th scope="col" style="width:160px;">Código</th>
              <th scope="col" style="width:120px;">Status</th>
              <th scope="col" class="text-end" style="width:120px;">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>
                  <div class="fw-semibold text-dark"><?= esc(mb_convert_case($r['name'] ?? '', MB_CASE_TITLE, 'UTF-8')) ?></div>
                </td>
                <td><code class="text-muted"><?= esc($r['code']) ?></code></td>
                <td>
                  <?php if ((int)$r['active'] === 1): ?>
                    <span class="badge text-bg-success-subtle border border-success-subtle text-success-emphasis">
                      <i class="bi bi-check-circle me-1"></i>Ativa
                    </span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary-subtle border border-secondary-subtle text-secondary-emphasis">
                      <i class="bi bi-pause-circle me-1"></i>Inativa
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="school_edit.php?id=<?= (int)$r['id'] ?>" aria-label="Editar instituição <?= esc($r['name']) ?>">
                    <i class="bi bi-pencil-square me-1"></i>Editar
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="4" class="text-center py-5">
                  <div class="text-muted">
                    <i class="bi bi-building-slash fs-1 d-block mb-2 opacity-50"></i>
                    <strong>Nenhuma instituição encontrada.</strong>
                    <?php if ($q): ?>
                      <div class="small mt-1">Tente ajustar a busca ou <a href="schools.php">limpar o filtro</a>.</div>
                    <?php else: ?>
                      <div class="small mt-1">Comece criando uma <a href="school_edit.php">nova instituição</a>.</div>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>

</html>
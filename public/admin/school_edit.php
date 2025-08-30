<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);
if (!is_network_admin($adm)) {
  http_response_code(403);
  exit('Acesso restrito a administradores da rede.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = $_GET['msg'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $name = trim($_POST['name'] ?? '');
  $code = trim($_POST['code'] ?? '');
  $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
  $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
  $radius_m = isset($_POST['radius_m']) && $_POST['radius_m'] !== '' ? (int)$_POST['radius_m'] : 300;
  $active = isset($_POST['active']) ? 1 : 0;
  if ($name === '' || $code === '') {
    $msg = 'Nome e código são obrigatórios.';
  } else {
    if ($id > 0) {
      $st = $pdo->prepare("UPDATE schools SET name=?, code=?, lat=?, lng=?, radius_m=?, active=? WHERE id=?");
      $st->execute([$name, $code, $lat, $lng, $radius_m, $active, $id]);
      audit_log('update', 'school', $id, ['name' => $name, 'code' => $code, 'lat' => $lat, 'lng' => $lng, 'radius_m' => $radius_m, 'active' => $active]);
      header('Location: school_edit.php?id=' . (int)$id . '&msg=' . urlencode('Instituição atualizada.'));
      exit;
    } else {
      $st = $pdo->prepare("INSERT INTO schools (name, code, lat, lng, radius_m, active) VALUES (?, ?, ?, ?, ?, ?)");
      $st->execute([$name, $code, $lat, $lng, $radius_m, $active]);
      $newId = (int)$pdo->lastInsertId();
      audit_log('create', 'school', $newId, ['name' => $name, 'code' => $code, 'lat' => $lat, 'lng' => $lng, 'radius_m' => $radius_m, 'active' => $active]);
      header('Location: schools.php?msg=' . urlencode('Instituição criada.'));
      exit;
    }
  }
}

$row = null;
if ($id) {
  $st = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(404);
    exit('Instituição não encontrada.');
  }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title><?= $id ? 'Editar' : 'Nova' ?> Instituição | DEEDO Ponto</title>
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
            <li class="nav-item"><a class="nav-link active" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
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
  <div class="container">
    <?php if ($msg): ?><div class="alert alert-info"><?= esc($msg) ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header"><?= $id ? 'Editar Instituição' : 'Nova Instituição' ?></div>
      <div class="card-body">
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <div class="mb-3">
            <label class="form-label">Nome</label>
            <input class="form-control" name="name" required maxlength="150" value="<?= esc($row['name'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Código</label>
            <input class="form-control" name="code" required maxlength="50" value="<?= esc($row['code'] ?? '') ?>">
            <div class="form-text">Identificador único (ex: EMEF-CENTRO, ESC-A-01)</div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Latitude</label>
              <input type="number" step="0.0000001" class="form-control" name="lat" value="<?= esc($row['lat'] ?? '') ?>" placeholder="-15.7942287">
              <div class="form-text">Coordenada geográfica para validação de localização</div>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Longitude</label>
              <input type="number" step="0.0000001" class="form-control" name="lng" value="<?= esc($row['lng'] ?? '') ?>" placeholder="-47.8821658">
              <div class="form-text">Coordenada geográfica para validação de localização</div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Raio de Validação (metros)</label>
            <input type="number" min="50" max="5000" class="form-control" name="radius_m" value="<?= esc($row['radius_m'] ?? 300) ?>">
            <div class="form-text">Distância máxima permitida para check-in (padrão: 300m)</div>
          </div>
          <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="active" name="active" <?= !isset($row['active']) || (int)$row['active'] === 1 ? 'checked' : '' ?>>
            <label for="active" class="form-check-label">Ativa</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-success" type="submit">Salvar</button>
            <a class="btn btn-secondary" href="schools.php">Voltar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>

</html>
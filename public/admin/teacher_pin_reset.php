<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
  header('Location: teachers.php');
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->execute([$id]);
$teacher = $stmt->fetch();
if (!$teacher) {
  header('Location: teachers.php');
  exit;
}

// Gera PIN novo e garante (melhor esforço de) unicidade
do {
  $new_pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $unique = true;
  $stmt2 = $pdo->query("SELECT pin_hash FROM teachers WHERE pin_hash IS NOT NULL");
  while ($row = $stmt2->fetch()) {
    if (password_verify($new_pin, $row['pin_hash'])) {
      $unique = false;
      break;
    }
  }
} while (!$unique);

$pin_hash = password_hash($new_pin, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE teachers SET pin_hash = ? WHERE id = ?")->execute([$pin_hash, $id]);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Reset de PIN</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
  <div class="alert alert-success">
  O novo PIN do colaborador <b><?=esc($teacher['name'])?></b> (CPF <?=esc($teacher['cpf'])?>) é: <span class="badge bg-primary" style="font-size:1.2em;"><?= esc($new_pin) ?></span>
  </div>
  <a href="teachers.php" class="btn btn-secondary">Voltar</a>
</div>
</body>
</html>
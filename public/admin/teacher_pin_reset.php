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

// Carrega todos os hashes de PIN existentes (exceto do próprio professor)
$stmt = $pdo->prepare("SELECT pin_hash FROM teachers WHERE pin_hash IS NOT NULL AND id <> ?");
$stmt->execute([$id]);
$existingHashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Gera um PIN único (que não exista em nenhum outro professor)
function generateUniquePin(array $hashes, int $maxAttempts = 100): array
{
  for ($i = 0; $i < $maxAttempts; $i++) {
    $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $duplicate = false;
    foreach ($hashes as $h) {
      if ($h && password_verify($pin, $h)) {
        $duplicate = true;
        break;
      }
    }
    if (!$duplicate) {
      return [$pin, password_hash($pin, PASSWORD_DEFAULT)];
    }
  }
  throw new RuntimeException('Não foi possível gerar um PIN único. Tente novamente.');
}

try {
  [$new_pin, $pin_hash] = generateUniquePin($existingHashes);
  $pdo->prepare("UPDATE teachers SET pin_hash = ? WHERE id = ?")->execute([$pin_hash, $id]);
} catch (Throwable $e) {
  http_response_code(500);
?>
  <!doctype html>
  <html lang="pt-br">

  <head>
    <meta charset="utf-8">
    <title>Reset de PIN | DEEDO Ponto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  </head>

  <body>
    <div class="container mt-4">
      <div class="alert alert-danger">
        Falha ao gerar PIN único: <?= esc($e->getMessage()) ?>
      </div>
      <a href="teachers.php" class="btn btn-secondary">Voltar</a>
    </div>
  </body>

  </html>
<?php
  exit;
}
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <title>Reset de PIN | DEEDO Ponto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  </head>

<body>
  <div class="container mt-4">
    <div class="alert alert-success">
      O novo PIN do professor <b><?= esc($teacher['name']) ?></b> (CPF <?= esc($teacher['cpf']) ?>) é:
      <span class="badge bg-primary" style="font-size:1.2em;"><?= esc($new_pin) ?></span>
    </div>
    <a href="teachers.php" class="btn btn-secondary">Voltar</a>
  </div>
</body>

</html>
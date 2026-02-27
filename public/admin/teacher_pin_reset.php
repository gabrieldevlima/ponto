<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

// Pega informações do admin
$admin = current_admin($pdo);

// Valida ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
  $_SESSION['error_msg'] = 'ID do colaborador não informado';
  header('Location: teachers.php?msg=' . urlencode('ID do colaborador não informado'));
  exit;
}

// Verifica se o colaborador existe e se o admin tem permissão (escopo)
list($scopeSql, $scopeParams) = admin_scope_where('t');
$stmt = $pdo->prepare("SELECT t.* FROM teachers t WHERE t.id = ? AND $scopeSql");
$stmt->execute(array_merge([$id], $scopeParams));
$teacher = $stmt->fetch();

if (!$teacher) {
  $_SESSION['error_msg'] = 'Colaborador não encontrado ou você não tem permissão para acessá-lo';
  header('Location: teachers.php?msg=' . urlencode('Colaborador não encontrado ou sem permissão'));
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
  
  // Registra no log de auditoria
  audit_log('update', 'teacher', $id, [
    'action' => 'pin_reset',
    'teacher_name' => $teacher['name'],
    'cpf' => $teacher['cpf']
  ]);
} catch (Throwable $e) {
  // Loga o erro para diagnóstico
  error_log('Erro ao resetar PIN do colaborador ' . $id . ': ' . $e->getMessage());
  
  http_response_code(500);
?>
  <!doctype html>
  <html lang="pt-br">

  <head>
    <meta charset="utf-8">
    <title>Reset de PIN - Erro | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  </head>

  <body>
    <?php include __DIR__ . '/_navbar.php'; ?>
    <div class="container mt-4">
      <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>Falha ao gerar PIN único:</strong> <?= esc($e->getMessage()) ?>
      </div>
      <a href="teachers.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Voltar para Colaboradores
      </a>
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
  <title>Reset de PIN - Sucesso | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  <style>
    .pin-display {
      font-size: 2.5rem;
      font-weight: bold;
      letter-spacing: 0.2em;
      padding: 1.5rem;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border-radius: 15px;
      text-align: center;
      margin: 2rem 0;
      box-shadow: 0 10px 30px rgba(0,0,0,0.15);
      user-select: all;
    }
    .info-card {
      background: #f8f9fa;
      border-left: 4px solid #667eea;
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
    }
  </style>
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  
  <div class="container mt-4">
    <div class="card shadow-sm">
      <div class="card-header bg-success text-white">
        <h4 class="mb-0">
          <i class="bi bi-check-circle-fill me-2"></i>
          PIN Resetado com Sucesso
        </h4>
      </div>
      <div class="card-body">
        <div class="info-card">
          <strong>Colaborador:</strong> <?= esc($teacher['name']) ?><br>
          <strong>CPF:</strong> <?= esc($teacher['cpf']) ?><br>
          <strong>Email:</strong> <?= esc($teacher['email']) ?>
        </div>

        <div class="alert alert-warning">
          <i class="bi bi-info-circle-fill me-2"></i>
          <strong>Atenção:</strong> Anote ou copie este PIN agora. Por segurança, ele não será exibido novamente.
        </div>

        <div class="text-center">
          <label class="form-label fw-bold">Novo PIN de Acesso:</label>
          <div class="pin-display" id="pinDisplay"><?= esc($new_pin) ?></div>
          <button class="btn btn-outline-primary" onclick="copyPin()">
            <i class="bi bi-clipboard"></i> Copiar PIN
          </button>
        </div>

        <div class="alert alert-info mt-4">
          <i class="bi bi-lightbulb-fill me-2"></i>
          <strong>Instruções:</strong>
          <ul class="mb-0 mt-2">
            <li>Informe este PIN ao colaborador de forma segura</li>
            <li>O colaborador deve usar este PIN para registrar ponto</li>
            <li>Recomendamos que o PIN seja alterado após o primeiro uso</li>
          </ul>
        </div>

        <div class="d-flex gap-2 justify-content-between mt-4">
          <a href="teachers.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Voltar para Colaboradores
          </a>
          <a href="teacher_edit.php?id=<?= (int)$teacher['id'] ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil"></i> Editar Colaborador
          </a>
        </div>
      </div>
    </div>
  </div>

  <script>
    function copyPin() {
      const pinText = document.getElementById('pinDisplay').innerText;
      navigator.clipboard.writeText(pinText).then(() => {
        // Feedback visual
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-success');
        
        setTimeout(() => {
          btn.innerHTML = originalHTML;
          btn.classList.remove('btn-success');
          btn.classList.add('btn-outline-primary');
        }, 2000);
      }).catch(err => {
        alert('Erro ao copiar PIN. Por favor, copie manualmente.');
      });
    }
  </script>
</body>

</html>
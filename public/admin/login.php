<?php
require_once __DIR__ . '/../../config.php';

if (is_admin_logged()) {
  header('Location: dashboard.php');
  exit;
}

$err = '';
$user = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = trim($_POST['user'] ?? '');
  $pass = $_POST['pass'] ?? '';
  // Troque por validação real de admin (exemplo: consulta ao banco)
  if ($user === 'admin' && $pass === '1234') {
    // Sessão só dura enquanto o navegador estiver aberto (cookie de sessão)
    session_regenerate_id(true);
    $_SESSION['admin'] = [
      'user' => $user,
      'time' => time()
    ];
    // Garante que o cookie de sessão não tenha "lifetime" definido (expira ao fechar o navegador)
    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(session_name(), session_id(), 0, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    header('Location: dashboard.php');
    exit;
  } else {
    $err = 'Usuário ou senha inválidos.';
  }
}
?>

<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Login Administrativo | DEEDO Sistemas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
  body { background: #f8f9fa; }
  .card { border-radius: 14px; }
  .logo { display: block; margin: 0 auto 24px auto; max-width: 180px; }
  .form-label { font-weight: 500; }
  .login-title { font-weight: 600; }
  </style>
</head>
<body>
<div class="container">
  <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
  <div class="col-lg-4 col-md-6">
    <div class="card shadow-sm border-0">
    <div class="card-body p-4">
      <img src="../img/logo_login.png" alt="Logo da Empresa" class="logo mb-2 mt-4">
      <h5 class="mb-4 text-center login-title">Acesso do Administrador</h5>
      <?php if ($err): ?>
      <div class="alert alert-danger"><?= esc($err) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off" novalidate>
      <div class="mb-3">
        <label class="form-label" for="user">Usuário</label>
        <input type="text" name="user" id="user" class="form-control" required autofocus maxlength="50" value="<?= esc($user) ?>">
      </div>
      <div class="mb-3">
        <label class="form-label" for="pass">Senha</label>
        <input type="password" name="pass" id="pass" class="form-control" required maxlength="50" autocomplete="current-password">
      </div>
      <button class="btn btn-primary w-100" type="submit">Entrar</button>
      </form>
      <hr>
      <a href="../index.php" class="btn btn-outline-secondary w-100 mt-2">Ir para Registrar Ponto</a>
    </div>
    </div>
    <p class="text-center text-muted mt-3" style="font-size: 0.9em;">&copy; <?= date('Y') ?> DEEDO Sistemas. Todos os direitos reservados.</p>
  </div>
  </div>
</div>
</body>
</html>
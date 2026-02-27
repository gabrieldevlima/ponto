<?php
require_once __DIR__ . '/../../config.php';

// Cabeçalhos de segurança (antes de qualquer saída)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://use.typekit.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' data: https://cdn.jsdelivr.net https://use.typekit.net; connect-src 'self' https://cdn.jsdelivr.net;");

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (is_admin_logged()) {
  header('Location: dashboard.php');
  exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limit simples
$maxAttempts = 5;
$lockoutSeconds = 300; // 5 min
$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['login_locked_until'] = $_SESSION['login_locked_until'] ?? 0;

$err = '';
$user = '';
$lockedRemaining = max(0, $_SESSION['login_locked_until'] - time());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Honeypot
  $hp = trim($_POST['website'] ?? '');
  $user = trim((string)($_POST['user'] ?? ''));
  $pass = (string)($_POST['pass'] ?? '');
  $token = (string)($_POST['_token'] ?? '');

  if ($lockedRemaining > 0) {
    $err = 'Muitas tentativas. Tente novamente em alguns minutos.';
  } elseif ($hp !== '') {
    $err = 'Falha de validação.';
  } elseif (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
    $err = 'Sessão expirada. Atualize a página e tente novamente.';
  } elseif ($user === '' || $pass === '') {
    $err = 'Preencha usuário e senha.';
  } elseif (mb_strlen($user) > 50 || mb_strlen($pass) > 100) {
    $err = 'Dados inválidos.';
  } else {
    if (admin_login($user, $pass)) {
      session_regenerate_id(true);
      // Reset de proteção
      $_SESSION['login_attempts'] = 0;
      $_SESSION['login_locked_until'] = 0;
      // Novo token CSRF pós-login
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      header('Location: dashboard.php');
      exit;
    } else {
      $_SESSION['login_attempts']++;
      if ($_SESSION['login_attempts'] >= $maxAttempts) {
        $_SESSION['login_locked_until'] = time() + $lockoutSeconds;
      }
      $lockedRemaining = max(0, $_SESSION['login_locked_until'] - time());
      $err = $lockedRemaining > 0
        ? 'Muitas tentativas. Tente novamente em alguns minutos.'
        : 'Usuário ou senha inválidos.';
    }
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Login | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  <style>
    body { background: #f6f7fb; }
    .card { border-radius: 14px; }
    .logo { display: block; margin: 0 auto 24px auto; max-width: 180px; }
    .form-label { font-weight: 500; }
    .login-title { font-weight: 600; }
    .hp { position: absolute !important; left: -9999px !important; width: 1px; height: 1px; overflow: hidden; }
  </style>
</head>
<body>
  <div class="container">
    <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
      <div class="col-lg-4 col-md-6">
        <div class="card shadow-sm border-0">
          <div class="card-body p-4">
            <img src="../img/logo_login.png" alt="Logo da Empresa" class="logo mb-2 mt-4" loading="lazy">
            <h5 class="mb-4 text-center login-title">Acesso do Administrador</h5>

            <?php if ($err): ?>
              <div class="alert alert-danger" role="alert"><?= esc($err) ?></div>
            <?php elseif ($lockedRemaining > 0): ?>
              <div class="alert alert-warning" role="alert">
                Muitas tentativas. Tente novamente em aproximadamente <?= (int)ceil($lockedRemaining/60) ?> minuto(s).
              </div>
            <?php endif; ?>

            <form method="post" autocomplete="off" novalidate class="needs-validation" id="loginForm">
              <input type="hidden" name="_token" value="<?= esc($_SESSION['csrf_token']) ?>">
              <!-- Honeypot -->
              <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">

              <div class="mb-3">
                <label class="form-label" for="user">Usuário</label>
                <input
                  type="text"
                  name="user"
                  id="user"
                  class="form-control"
                  required
                  maxlength="50"
                  inputmode="latin"
                  autocapitalize="off"
                  spellcheck="false"
                  autocomplete="username"
                  value="<?= esc($user) ?>">
                <div class="invalid-feedback">Informe seu usuário.</div>
              </div>

              <div class="mb-3">
                <label class="form-label" for="pass">Senha</label>
                <div class="input-group">
                  <input
                    type="password"
                    name="pass"
                    id="pass"
                    class="form-control"
                    required
                    maxlength="100"
                    autocomplete="current-password">
                  <button class="btn btn-outline-secondary" type="button" id="togglePass" aria-label="Mostrar/ocultar senha">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                  </button>
                  <div class="invalid-feedback">Informe sua senha.</div>
                </div>
              </div>

              <button class="btn btn-primary w-100" type="submit" id="submitBtn" <?= $lockedRemaining > 0 ? 'disabled' : '' ?>>Entrar</button>
            </form>

            <hr>
            <a href="../index.php" class="btn btn-outline-secondary w-100 mt-2">Ir para Registrar Ponto</a>
          </div>
        </div>
        
        <div class="text-center mt-4">
          <div class="d-flex justify-content-center align-items-center mb-2">
            <img src="../img/logo_prefeitura.png" alt="Prefeitura Municipal de Ribeira do Piauí" style="height: 90px; width: auto; opacity: 0.85;">
          </div>
          <p class="text-muted small mb-0">Prefeitura Municipal de Ribeira do Piauí - PI</p>
          <p class="text-muted small">&copy; <?= date('Y') ?> DEEDO Sistemas</p>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Validação Bootstrap
    (function () {
      const form = document.getElementById('loginForm');
      const submitBtn = document.getElementById('submitBtn');
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault();
          event.stopPropagation();
        } else {
          submitBtn.disabled = true; // evita duplo envio
        }
        form.classList.add('was-validated');
      }, false);
    })();

    // Toggle senha
    (function () {
      const btn = document.getElementById('togglePass');
      const input = document.getElementById('pass');
      btn.addEventListener('click', function () {
        const t = input.getAttribute('type') === 'password' ? 'text' : 'password';
        input.setAttribute('type', t);
      });
    })();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
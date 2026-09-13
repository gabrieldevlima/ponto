<?php
require_once __DIR__ . '/../../config.php';

// Cabeçalhos de segurança (antes de qualquer saída)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://use.typekit.net https://fonts.googleapis.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' data: https://cdn.jsdelivr.net https://use.typekit.net https://fonts.gstatic.com; connect-src 'self' https://cdn.jsdelivr.net;");

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

// ----------------------------------------------------------------------------
// Rate limit — NC-42 (auditoria 2026-08-05).
//
// Antes, o contador vivia em $_SESSION['login_attempts']. Como a sessão é
// escolhida pelo cliente (cookie), bastava descartar o cookie a cada tentativa
// para zerar o contador: força bruta sem limite algum. O login do colaborador
// já fazia certo, persistindo em auth_attempt_logs (helpers.auth_attempt_log) —
// aqui reusamos exatamente o mesmo mecanismo.
//
// Dois eixos, como no portal do colaborador:
//   - (ip|ua)      → 5 falhas / 5 min, contém o atacante de um mesmo ponto;
//   - cpf          → 10 falhas / 1h, contém IP-hopping contra uma conta alvo.
// ----------------------------------------------------------------------------
$maxAttempts    = 5;
$lockoutSeconds = 300; // 5 min

$pdoLogin  = db();
$ipLogin   = $_SERVER['REMOTE_ADDR'] ?? '';
$uaLogin   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
$ipIdent   = 'admin_ip:' . hash('sha256', $ipLogin . '|' . $uaLogin);

$rlIp = auth_attempt_is_limited($pdoLogin, 'admin_login', $ipIdent, $lockoutSeconds, $maxAttempts);

$err = '';
$cpfValue = '';
$lockedRemaining = (int)($rlIp['retry_after'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $hp = trim($_POST['website'] ?? '');
  $cpfRaw = trim((string)($_POST['cpf'] ?? ''));
  $pass = (string)($_POST['pass'] ?? '');
  $token = (string)($_POST['_token'] ?? '');

  $cpfNormalized = preg_replace('/\D/', '', $cpfRaw);
  $cpfValue = strlen($cpfNormalized) === 11 ? mask_cpf($cpfNormalized) : $cpfRaw;

  if ($lockedRemaining > 0) {
    $err = 'Muitas tentativas. Tente novamente em alguns minutos.';
  } elseif ($hp !== '') {
    $err = 'Falha de validação.';
  } elseif (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
    $err = 'Sessão expirada. Atualize a página e tente novamente.';
  } elseif ($cpfNormalized === '' || $pass === '') {
    $err = 'Preencha CPF e senha.';
  } elseif (strlen($cpfNormalized) !== 11 || !validar_cpf($cpfNormalized)) {
    $err = 'CPF deve ter 11 dígitos válidos.';
  } elseif (mb_strlen($pass) > 100) {
    $err = 'Dados inválidos.';
  } else {
    // Trava por CPF alvo, avaliada só depois de o CPF ser válido — evita
    // que lixo aleatório consuma a janela de uma conta legítima.
    $cpfIdent = 'admin_cpf:' . hash('sha256', $cpfNormalized);
    $rlCpf = auth_attempt_is_limited($pdoLogin, 'admin_login', $cpfIdent, 3600, 10);

    if (!empty($rlCpf['limited'])) {
      auth_attempt_log($pdoLogin, 'admin_login', $cpfIdent, false, null, 'cpf_rate_limited');
      $lockedRemaining = (int)($rlCpf['retry_after'] ?? 0);
      $err = 'Muitas tentativas para este usuário. Tente novamente mais tarde.';
    } elseif (admin_login_by_cpf($cpfNormalized, $pass)) {
      session_regenerate_id(true);
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      auth_attempt_log($pdoLogin, 'admin_login', $ipIdent, true, null, 'login_ok');
      auth_attempt_log($pdoLogin, 'admin_login', $cpfIdent, true, null, 'login_ok');
      header('Location: dashboard.php');
      exit;
    } else {
      // Registra nos dois eixos: sem o espelho por CPF, a janela de 1h só
      // começaria a contar depois do bloqueio por IP — inútil contra IP-hopping.
      auth_attempt_log($pdoLogin, 'admin_login', $ipIdent, false, null, 'bad_credentials');
      auth_attempt_log($pdoLogin, 'admin_login', $cpfIdent, false, null, 'bad_credentials');

      $rlIp = auth_attempt_is_limited($pdoLogin, 'admin_login', $ipIdent, $lockoutSeconds, $maxAttempts);
      $lockedRemaining = (int)($rlIp['retry_after'] ?? 0);
      $err = !empty($rlIp['limited'])
        ? 'Muitas tentativas. Tente novamente em alguns minutos.'
        : 'CPF ou senha inválidos.';
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
  <link rel="stylesheet" href="css/admin.css">
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

            <form action="" method="post" autocomplete="off" novalidate class="needs-validation" id="loginForm">
              <input type="hidden" name="_token" value="<?= esc($_SESSION['csrf_token']) ?>">
              <!-- Honeypot -->
              <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">

              <div class="mb-3">
                <label class="form-label" for="cpf">CPF</label>
                <input
                  type="text"
                  name="cpf"
                  id="cpf"
                  class="form-control"
                  required
                  maxlength="14"
                  inputmode="numeric"
                  pattern="[0-9\s\.\-]*"
                  autocapitalize="off"
                  spellcheck="false"
                  autocomplete="off"
                  placeholder="000.000.000-00"
                  value="<?= esc($cpfValue) ?>">
                <div class="invalid-feedback">Informe o CPF (11 dígitos).</div>
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
            <a href="../login.php" class="btn btn-outline-secondary w-100 mt-2">
              <i class="bi bi-arrow-left-short"></i> Sou colaborador
            </a>
          </div>
        </div>
        
        <div class="text-center mt-4">
          <div class="d-flex justify-content-center align-items-center mb-2">
            <img src="../img/logo_prefeitura.png" alt="Prefeitura Municipal de Oeiras - PI" style="height: 90px; width: auto; opacity: 0.85;">
          </div>
          <p class="text-muted small mb-0">Prefeitura Municipal de Oeiras - PI</p>
          <p class="text-muted small">&copy; <?= date('Y') ?> DEEDO Sistemas</p>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Máscara CPF (000.000.000-00)
    (function () {
      function maskCpf(value) {
        var d = (value || '').replace(/\D/g, '');
        if (d.length <= 3) return d;
        if (d.length <= 6) return d.slice(0, 3) + '.' + d.slice(3);
        if (d.length <= 9) return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6);
        return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6, 9) + '-' + d.slice(9, 11);
      }
      var cpfInput = document.getElementById('cpf');
      if (cpfInput) {
        cpfInput.addEventListener('input', function () {
          this.value = maskCpf(this.value);
        });
      }
    })();

    // Normaliza CPF no submit (apenas dígitos)
    (function () {
      const form = document.getElementById('loginForm');
      const cpfInput = document.getElementById('cpf');
      form.addEventListener('submit', function () {
        if (cpfInput && cpfInput.value) {
          cpfInput.value = cpfInput.value.replace(/\D/g, '');
        }
      }, false);
    })();

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
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>
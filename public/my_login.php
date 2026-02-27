<?php
require_once __DIR__ . '/../config.php';

// Se já estiver logado como colaborador, redireciona
if (isset($_SESSION['collaborator_id'])) {
    header('Location: my_timesheet.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $pin = trim($_POST['pin'] ?? '');
    
    if (empty($pin)) {
        $error = 'Digite seu PIN de 6 dígitos.';
    } else {
        $pdo = db();
        
        // Busca colaborador por PIN
        $stmt = $pdo->query("SELECT id, name, pin_hash, active FROM teachers WHERE active = 1");
        $matches = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (password_verify($pin, $row['pin_hash'])) {
                $matches[] = $row;
                if (count($matches) > 1) break;
            }
        }
        
        if (count($matches) === 1) {
            // Login bem-sucedido
            $_SESSION['collaborator_id'] = $matches[0]['id'];
            $_SESSION['collaborator_name'] = $matches[0]['name'];
            
            header('Location: my_timesheet.php');
            exit;
        } elseif (count($matches) > 1) {
            $error = 'PIN duplicado. Contate o administrador.';
        } else {
            $error = 'PIN incorreto ou colaborador inativo.';
        }
    }
}

$appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Minha Folha de Ponto - DEEDO</title>
    <meta name="theme-color" content="#0d6efd">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="shortcut icon" href="img/icone-2.ico" type="image/x-icon">
    <style>
        :root {
            --brand: #0d6efd;
            --bg: #f6f7fb;
        }
        body {
            background: radial-gradient(1000px 600px at 15% -10%, rgba(13, 110, 253, .10), transparent 60%),
                        radial-gradient(1000px 600px at 85% -10%, rgba(10, 162, 255, .08), transparent 60%),
                        var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .login-card {
            background: rgba(255, 255, 255, .85);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid rgba(2, 6, 23, .08);
            box-shadow: 0 12px 34px rgba(2, 6, 23, .10);
            max-width: 420px;
            width: 100%;
        }
        .brand-logo {
            width: 120px;
            filter: drop-shadow(0 4px 12px rgba(13, 110, 253, .15));
        }
    </style>
</head>
<body>
    <div class="login-card p-4 p-md-5">
        <div class="text-center mb-4">
            <img src="<?= htmlspecialchars($appBase) ?>/img/logo_login.png" alt="DEEDO Ponto" class="brand-logo mb-3">
            <h3 class="mb-1">Minha Folha de Ponto</h3>
            <p class="text-muted small mb-0">Consulte seus pontos e horas trabalhadas</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            
            <div class="mb-3">
                <label for="pin" class="form-label fw-semibold">
                    <i class="bi bi-key me-1"></i> PIN (6 dígitos)
                </label>
                <div class="input-group input-group-lg">
                    <input type="password" 
                           class="form-control" 
                           id="pin" 
                           name="pin" 
                           required 
                           autocomplete="current-password"
                           inputmode="numeric"
                           autocapitalize="none"
                           spellcheck="false"
                           pattern="\d{6}"
                           minlength="6"
                           maxlength="6"
                           placeholder="••••••"
                           data-remember-pin="input"
                           autofocus>
                    <button 
                        class="btn btn-outline-secondary" 
                        type="button" 
                        id="btnTogglePin" 
                        aria-label="Mostrar PIN">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="form-text">Digite o mesmo PIN que você usa para registrar ponto.</div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" id="remember_pin" name="remember_pin" data-remember-pin="checkbox">
                <label class="form-check-label" for="remember_pin">
                    Lembrar PIN neste dispositivo
                </label>
                <div class="form-text">Use apenas em aparelhos confiáveis. O PIN ficará salvo localmente.</div>
            </div>

            <div class="d-grid gap-2 mb-3">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Acessar
                </button>
            </div>
        </form>

        <hr class="my-3">

        <div class="d-flex justify-content-between align-items-center">
            <a href="<?= htmlspecialchars($appBase) ?>/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Voltar
            </a>
            <a href="<?= htmlspecialchars($appBase) ?>/admin/login.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-shield-lock"></i> Admin
            </a>
        </div>

        <div class="text-center mt-3">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Esqueceu seu PIN? Contate o RH/Admin.
            </small>
        </div>
    </div>

    <script src="<?= htmlspecialchars($appBase) ?>/js/pin-remember.js"></script>
    <script>
        (function() {
            const pinField = document.getElementById('pin');
            const toggleBtn = document.getElementById('btnTogglePin');
            if (!pinField || !toggleBtn) return;

            toggleBtn.addEventListener('click', function() {
                const isPassword = pinField.type === 'password';
                pinField.type = isPassword ? 'text' : 'password';
                toggleBtn.innerHTML = isPassword ? '<i class="bi bi-eye-slash" aria-hidden="true"></i>' : '<i class="bi bi-eye" aria-hidden="true"></i>';
                toggleBtn.setAttribute('aria-label', isPassword ? 'Ocultar PIN' : 'Mostrar PIN');
                pinField.focus();
            });
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


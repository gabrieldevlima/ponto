<?php
require_once __DIR__ . '/../config.php';

// Se já está logado, vai direto para a home
if (is_collaborator_logged()) {
    header('Location: index.php');
    exit;
}

if (function_exists('run_auto_migrations')) {
    run_auto_migrations();
}

$csrfToken = csrf_token();
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$appBase = $scriptDir !== '' ? $scriptDir : '/';
$rootBase = preg_replace('#/public$#', '', $appBase);

$error = null;
$prefillCpf = '';
$attemptedLogin = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    csrf_verify();
    $cpf = preg_replace('/\D/', '', (string)($_POST['cpf'] ?? ''));
    $pin = preg_replace('/\D/', '', (string)($_POST['pin'] ?? ''));
    $prefillCpf = $cpf;
    $attemptedLogin = true;

    // C2: mensagens unificadas para não vazar existência do CPF.
    // "Digite o PIN completo" é UX (campo em branco), permitido.
    $clientFp = (string)($_POST['client_fp'] ?? '');

    $cpfRejeitado = cpf_reject_reason($cpf);

    if (strlen($pin) < 4 || strlen($pin) > 8) {
        $error = 'Digite o PIN completo.';
    } elseif ($cpfRejeitado !== null) {
        // A tela diz o mesmo que diria para PIN errado — não se revela quais
        // CPFs existem. Mas a tentativa passa a deixar rastro: sem isso, uma
        // manhã inteira de falhas some do log e não há como separar "digitou o
        // CPF errado" de "digitou o PIN errado" (23/09/2026).
        try {
            auth_log_cpf_rejected(db(), $cpf, $cpfRejeitado);
        } catch (Throwable $e) {
            error_log('login cpf_invalid_format log failed: ' . $e->getMessage());
        }
        $error = 'CPF ou PIN incorreto.';
    } elseif (collaborator_login_by_pin($cpf, $pin, $clientFp)) {
        header('Location: index.php');
        exit;
    } else {
        // Roteamento automático: se o CPF é válido e existe um colaborador
        // ativo SEM pin_hash, a tentativa só falhou porque ele ainda não fez
        // o primeiro acesso. Em vez de bloquear com "CPF ou PIN incorreto",
        // mandamos direto para a tela de cadastro com o CPF preenchido —
        // economiza um clique e re-digitação para usuário leigo.
        try {
            $pdoCheck = db();
            $stCheck = $pdoCheck->prepare("SELECT pin_hash FROM teachers WHERE cpf = ? AND active = 1 LIMIT 1");
            $stCheck->execute([$cpf]);
            $rowCheck = $stCheck->fetch(PDO::FETCH_ASSOC);
            if ($rowCheck && empty($rowCheck['pin_hash'])) {
                // C3: CPF vai pela sessão (não URL) — evita leak em logs do
                // servidor, histórico do navegador e header Referer.
                $_SESSION['first_access_cpf'] = $cpf;
                $_SESSION['first_access_cpf_at'] = time();
                header('Location: ' . $appBase . '/index.php?first_access=1');
                exit;
            }
        } catch (Throwable $e) {
            // falha silenciosa — cai no erro genérico abaixo
            error_log('login no_pin_yet check failed: ' . $e->getMessage());
        }
        $error = 'CPF ou PIN incorreto.';
    }
}

$sessionExpired = isset($_GET['expired']);
$loggedOut = isset($_GET['out']);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d6efd">
<title>Entrar — DEEDO Ponto</title>
<meta name="csrf-token" content="<?= esc($csrfToken) ?>">
<meta name="app-base" content="<?= esc($appBase) ?>">
<meta name="app-build" content="<?= esc(APP_BUILD_ID) ?>">
<link rel="manifest" href="manifest.json">

<!-- PWA force-update -->
<script src="<?= esc($appBase) ?>/js/pwa-update.js"></script>
<link rel="shortcut icon" href="img/icone-2.ico" type="image/x-icon">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<style>
:root {
  --font-ui: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
  --r-brand: #0d6efd;
  --r-brand-hover: #0958d9;
  --r-brand-soft: #e7f1ff;
  --r-success: #198754;
  --r-danger: #dc3545;
  --r-warn: #f59e0b;
  --r-ink: #0f172a;
  --r-ink-2: #475569;
  --r-ink-3: #6b7280; /* A3: contraste WCAG AA */
  --r-page: #f7f8fb;
  --r-surface: #ffffff;
  --r-edge: #e5e7eb;
  --r-edge-2: #cbd5e1;
  --r-shadow-xs: 0 1px 2px rgba(15,23,42,.04);
  --r-shadow-sm: 0 1px 2px rgba(15,23,42,.05), 0 2px 6px rgba(15,23,42,.04);
  --r-shadow-md: 0 4px 12px rgba(15,23,42,.06), 0 1px 3px rgba(15,23,42,.04);
  --r-focus: 0 0 0 3px rgba(13,110,253,.22);
  /* Hierarquia de alturas de botão (consistente com index.php/my_timesheet.php) */
  --btn-h-sm: 44px;
  --btn-h: 48px;
  --btn-h-lg: 54px;
  --btn-h-xl: 68px;
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
/* Elimina 300ms tap delay em Chrome Android antigo e melhora UX em mobile.
   `manipulation` permite scroll mas desabilita double-tap zoom. */
button, input[type="button"], input[type="submit"], .btn, .login-submit, [role="button"] {
  touch-action: manipulation;
}
html, body { margin: 0; padding: 0; min-height: 100dvh; }
body {
  background: var(--r-page);
  color: var(--r-ink);
  font-family: var(--font-ui);
  font-size: 15px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  letter-spacing: -0.003em;
}
.login-wrap {
  max-width: 440px;
  margin: 0 auto;
  padding: 28px 20px 40px;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
}
/* Brand header — compartilhado com index.php (fluxos sem sessão) */
.app-brand-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 4px 16px 22px;
  margin: 0 auto;
  opacity: 0;
  animation: brandFadeIn .5s cubic-bezier(.22,.61,.36,1) .05s forwards;
}
.app-brand-mark {
  max-width: 118px;
  height: auto;
  display: block;
  filter: drop-shadow(0 2px 6px rgba(15,23,42,.06));
  opacity: .96;
}
.app-brand-tag {
  font-family: var(--font-ui);
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: .22em;
  text-transform: uppercase;
  color: var(--r-ink-3);
  position: relative;
  padding-top: 6px;
}
.app-brand-tag::before {
  content: "";
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 28px;
  height: 1.5px;
  background: var(--r-brand);
  opacity: .55;
  border-radius: 2px;
}
@keyframes brandFadeIn {
  from { opacity: 0; transform: translateY(-6px); }
  to   { opacity: 1; transform: translateY(0); }
}
@media (max-width: 380px) {
  .app-brand-header { padding: 2px 12px 18px; }
  .app-brand-mark { max-width: 104px; }
}
.login-card {
  background: var(--r-surface);
  border: 1px solid var(--r-edge);
  border-radius: 16px;
  box-shadow: var(--r-shadow-md);
  padding: 26px 22px;
}
.login-title {
  font-weight: 700;
  font-size: 22px;
  margin: 2px 0 4px;
  letter-spacing: -0.01em;
}
.login-sub {
  color: var(--r-ink-2);
  font-size: 14.5px;
  margin: 0 0 18px;
}
.login-label {
  display: block;
  font-weight: 600;
  font-size: 13px;
  color: var(--r-ink-2);
  margin: 12px 0 6px;
}
.login-input {
  width: 100%;
  height: 54px;
  background: var(--r-surface);
  border: 1.5px solid var(--r-edge);
  border-radius: 12px;
  padding: 10px 14px;
  color: var(--r-ink);
  font-weight: 500;
  font-size: 16px;
  font-family: var(--font-ui);
  transition: border-color .15s ease, box-shadow .15s ease;
}
.login-input:focus {
  outline: none;
  border-color: var(--r-brand);
  box-shadow: var(--r-focus);
}
.login-pin-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 8px;
  margin: 4px 0 16px;
}
.login-pin-grid input {
  width: 100%;
  height: 58px;
  text-align: center;
  font-weight: 700;
  font-size: 26px;
  background: var(--r-surface);
  border: 1.5px solid var(--r-edge);
  border-radius: 12px;
  color: var(--r-ink);
  padding: 0;
  font-family: var(--font-ui);
  transition: border-color .15s ease, box-shadow .15s ease, transform .12s ease;
}
.login-pin-grid input:focus {
  outline: none;
  border-color: var(--r-brand);
  box-shadow: var(--r-focus);
}
.login-pin-grid input:not(:placeholder-shown) { border-color: var(--r-brand); }
.login-pin-grid.is-error input {
  border-color: var(--r-danger);
  background: #fff5f5;
  color: #a0242f;
}
@keyframes loginShake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-8px)} 40%,80%{transform:translateX(8px)} }
.login-pin-grid.is-error { animation: loginShake .35s ease; }

/* Telas pequenas (<=420px): reduz dígitos do PIN para manter largura usável.
   Cobre iPhone SE (375px), Pixel 3 (393px), Galaxy S8 (360px) e iPhone 12 mini (390px).
   Sem isso, em viewports apertados as 6 caixas ficavam <44px (touch target HIG). */
@media (max-width: 420px) {
  .login-pin-grid { gap: 6px; }
  .login-pin-grid input { font-size: 24px; height: 54px; }
}
@media (max-width: 360px) {
  .login-pin-grid { gap: 4px; }
  .login-pin-grid input { font-size: 22px; height: 50px; }
}

.login-submit {
  width: 100%;
  min-height: var(--btn-h-lg);
  background: var(--r-brand);
  color: #fff;
  border: 0;
  border-radius: 12px;
  font-family: var(--font-ui);
  font-weight: 700;
  font-size: 15px;
  box-shadow: var(--r-shadow-sm), inset 0 1px 0 rgba(255,255,255,.14);
  transition: background .18s ease, transform .12s ease, box-shadow .18s ease;
  cursor: pointer;
  margin-top: 6px;
}
.login-submit:hover:not(:disabled) { background: var(--r-brand-hover); }
.login-submit:active:not(:disabled) { transform: translateY(1px); box-shadow: var(--r-shadow-xs); }
.login-submit:disabled {
  background: var(--r-edge);
  color: var(--r-ink-3);
  opacity: .9;
  cursor: not-allowed;
  box-shadow: none;
}
.login-submit.is-loading {
  position: relative;
  color: transparent !important;
  pointer-events: none;
  cursor: wait;
}
.login-submit.is-loading::after {
  content: "";
  position: absolute;
  top: 50%; left: 50%;
  width: 22px; height: 22px;
  margin: -11px 0 0 -11px;
  border: 2.5px solid rgba(255,255,255,.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: loginSpin .8s linear infinite;
}
@keyframes loginSpin { to { transform: rotate(360deg); } }

.login-links {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 16px;
}
.login-link {
  display: block;
  padding: 10px 4px;
  color: var(--r-ink-2);
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  text-align: center;
  border-radius: 8px;
  transition: background .15s ease, color .15s ease;
}
.login-link:hover { background: var(--r-brand-soft); color: var(--r-brand); }
.login-link.is-primary {
  color: var(--r-brand);
  font-weight: 600;
  background: var(--r-brand-soft);
}
.login-link.is-primary:hover { background: rgba(13,110,253,.14); }

.login-alert {
  border-radius: 10px;
  padding: 11px 14px;
  font-size: 13.5px;
  margin-bottom: 14px;
  line-height: 1.45;
}
.login-alert-danger { background: rgba(220,53,69,.10); color: #8a1a2a; border-left: 3px solid var(--r-danger); }
.login-alert-warn { background: rgba(245,158,11,.10); color: #7c4d00; border-left: 3px solid var(--r-warn); }
.login-alert-info { background: var(--r-brand-soft); color: #0a4fbb; border-left: 3px solid var(--r-brand); }

.login-divider {
  display: flex;
  align-items: center;
  gap: 14px;
  color: var(--r-ink-3);
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  margin: 22px 4px 14px;
}
.login-divider::before, .login-divider::after {
  content: "";
  flex: 1;
  height: 1px;
  background: var(--r-edge);
}
.admin-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--r-ink-3);
  background: transparent;
  border: 1px solid var(--r-edge);
  padding: 10px 16px;
  border-radius: 10px;
  font-size: 13.5px;
  font-weight: 500;
  text-decoration: none;
  transition: color .15s ease, border-color .15s ease, background .15s ease;
}
.admin-link:hover {
  color: var(--r-ink);
  border-color: var(--r-edge-2);
  background: #f8fafc;
}
.admin-link-wrap { text-align: center; }

.login-footer {
  margin-top: auto;
  padding-top: 20px;
  text-align: center;
  color: var(--r-ink-3);
  font-size: 11.5px;
  font-weight: 500;
}
.login-footer strong { color: var(--r-ink-2); font-weight: 600; }

@keyframes fadeUp { from{opacity:0; transform:translateY(6px);} to{opacity:1; transform:translateY(0);} }
.login-wrap > * { animation: fadeUp .4s cubic-bezier(.22,.61,.36,1) both; }
.login-wrap > *:nth-child(1) { animation-delay: 0ms; }
.login-wrap > *:nth-child(2) { animation-delay: 50ms; }
.login-wrap > *:nth-child(3) { animation-delay: 100ms; }
.login-wrap > *:nth-child(4) { animation-delay: 150ms; }
.login-wrap > *:nth-child(5) { animation-delay: 200ms; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
::selection { background: rgba(13,110,253,.22); color: var(--r-ink); }

/* Landscape em telefones: reduz hero + grid do PIN. */
@media (orientation: landscape) and (max-height: 500px) {
  .login-wrap { min-height: auto !important; padding: 12px 14px !important; }
  .login-hero { padding: 10px 0 6px !important; }
  .login-hero img { height: 48px !important; }
  .login-title { font-size: 1.1rem !important; margin-bottom: 4px !important; }
  .login-subtitle { font-size: .82rem !important; }
  .login-card { padding: 16px 18px !important; }
  .login-pin-grid input { height: 48px !important; font-size: 22px !important; }
  .login-submit { min-height: 44px !important; font-size: 14px !important; }
  .form-control { min-height: 44px !important; }
}

/* ============================================================================
   MODAL DE BOAS-VINDAS — explica o novo fluxo CPF + PIN aos colaboradores.
   Aparece apenas no primeiro acesso ao sistema atualizado (flag localStorage).
   ============================================================================ */
.welcome-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, .55);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  z-index: 9000;
  opacity: 0;
  pointer-events: none;
  transition: opacity .25s ease;
}
.welcome-backdrop.is-open {
  opacity: 1;
  pointer-events: auto;
}
.welcome-modal {
  position: fixed;
  z-index: 9001;
  background: var(--r-surface);
  border-radius: 22px 22px 0 0;
  box-shadow: 0 -12px 48px rgba(15, 23, 42, .18);
  left: 0;
  right: 0;
  bottom: 0;
  max-width: 460px;
  margin: 0 auto;
  padding: 22px 22px 26px;
  transform: translateY(110%);
  transition: transform .35s cubic-bezier(.22, .61, .36, 1);
  max-height: 90dvh;
  overflow-y: auto;
}
.welcome-backdrop.is-open .welcome-modal {
  transform: translateY(0);
}
@media (min-width: 560px) {
  .welcome-modal {
    bottom: auto;
    top: 50%;
    border-radius: 20px;
    padding: 28px 26px 26px;
    max-width: 440px;
    transform: translateY(calc(-50% + 24px));
    opacity: 0;
    box-shadow: 0 24px 64px rgba(15, 23, 42, .22), 0 4px 14px rgba(15, 23, 42, .08);
  }
  .welcome-backdrop.is-open .welcome-modal {
    transform: translateY(-50%);
    opacity: 1;
  }
}

.welcome-grab {
  width: 44px;
  height: 4px;
  background: var(--r-edge-2);
  border-radius: 999px;
  margin: 0 auto 14px;
}
@media (min-width: 560px) { .welcome-grab { display: none; } }

.welcome-close {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 36px;
  height: 36px;
  background: transparent;
  border: 0;
  border-radius: 10px;
  color: var(--r-ink-3);
  font-size: 20px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background .15s ease, color .15s ease;
}
.welcome-close:hover {
  background: #f1f5f9;
  color: var(--r-ink);
}
.welcome-close:focus-visible {
  outline: none;
  box-shadow: var(--r-focus);
}

.welcome-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--r-brand-soft);
  color: var(--r-brand);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .14em;
  text-transform: uppercase;
  padding: 5px 10px;
  border-radius: 999px;
  margin-bottom: 10px;
}
.welcome-eyebrow i { font-size: 12px; }

.welcome-title {
  font-size: 22px;
  font-weight: 800;
  color: var(--r-ink);
  letter-spacing: -.012em;
  margin: 0 0 6px;
  line-height: 1.18;
}
.welcome-lede {
  font-size: 14.5px;
  color: var(--r-ink-2);
  margin: 0 0 18px;
  line-height: 1.5;
}

.welcome-steps {
  display: grid;
  gap: 10px;
  margin: 0 0 18px;
  padding: 0;
  list-style: none;
}
.welcome-step {
  display: flex;
  gap: 12px;
  padding: 12px 14px;
  background: #f7f8fb;
  border: 1px solid var(--r-edge);
  border-radius: 14px;
  opacity: 0;
  transform: translateY(8px);
  animation: welcomeStepIn .45s cubic-bezier(.22, .61, .36, 1) forwards;
}
.welcome-step:nth-child(1) { animation-delay: .12s; }
.welcome-step:nth-child(2) { animation-delay: .22s; }
.welcome-step:nth-child(3) { animation-delay: .32s; }
@keyframes welcomeStepIn {
  to { opacity: 1; transform: translateY(0); }
}
.welcome-step-num {
  flex: 0 0 32px;
  width: 32px;
  height: 32px;
  background: var(--r-brand);
  color: #fff;
  border-radius: 10px;
  font-weight: 700;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, .2);
}
.welcome-step-body { min-width: 0; }
.welcome-step-title {
  font-size: 14.5px;
  font-weight: 700;
  color: var(--r-ink);
  margin: 0 0 2px;
}
.welcome-step-desc {
  font-size: 13px;
  color: var(--r-ink-2);
  line-height: 1.45;
  margin: 0;
}
.welcome-step-desc strong { color: var(--r-ink); font-weight: 700; }

.welcome-note {
  font-size: 12.5px;
  color: var(--r-ink-3);
  background: #fffbeb;
  border-left: 3px solid var(--r-warn);
  padding: 10px 12px;
  border-radius: 8px;
  margin: 0 0 16px;
  line-height: 1.45;
}
.welcome-note strong { color: #92400e; font-weight: 700; }

.welcome-cta {
  width: 100%;
  min-height: var(--btn-h-lg);
  background: var(--r-brand);
  color: #fff;
  border: 0;
  border-radius: 12px;
  font-family: var(--font-ui);
  font-weight: 700;
  font-size: 15px;
  letter-spacing: .005em;
  box-shadow: var(--r-shadow-sm), inset 0 1px 0 rgba(255, 255, 255, .14);
  cursor: pointer;
  transition: background .18s ease, transform .12s ease;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.welcome-cta:hover { background: var(--r-brand-hover); }
.welcome-cta:active { transform: translateY(1px); }
.welcome-cta:focus-visible {
  outline: none;
  box-shadow: var(--r-shadow-sm), 0 0 0 3px rgba(13, 110, 253, .35);
}

@media (prefers-reduced-motion: reduce) {
  .welcome-modal,
  .welcome-backdrop,
  .welcome-step {
    transition: none !important;
    animation: none !important;
    opacity: 1 !important;
    transform: none !important;
  }
}
</style>
</head>
<body>
<div class="login-wrap">

  <header class="app-brand-header" role="banner" aria-label="DEEDO Ponto">
    <img src="img/logo_login.png" alt="DEEDO Ponto" class="app-brand-mark" onerror="this.style.display='none'">
    <div class="app-brand-tag">Sistema de Ponto</div>
  </header>

  <div class="login-card">
    <h1 class="login-title">Olá 👋</h1>
    <p class="login-sub">Entre com seu CPF e PIN para bater ponto.</p>

    <?php if ($error): ?>
      <div class="login-alert login-alert-danger">
        <i class="bi bi-exclamation-circle-fill"></i>
        <?= esc($error) ?>
      </div>
    <?php elseif ($sessionExpired): ?>
      <div class="login-alert login-alert-warn">
        Sua sessão expirou. Entre novamente para continuar.
      </div>
    <?php elseif ($loggedOut): ?>
      <div class="login-alert login-alert-info">
        Você saiu com sucesso.
      </div>
    <?php endif; ?>

    <form method="post" id="loginForm" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="pin" id="loginPinHidden" value="">
      <!-- Fingerprint do dispositivo — sincronizado com index.php via localStorage
           para que o device_fingerprint calculado no login bata com o do check-in. -->
      <input type="hidden" name="client_fp" id="loginClientFp" value="">

      <label class="login-label" for="loginCpf">CPF</label>
      <input
        class="login-input"
        id="loginCpf"
        name="cpf"
        type="tel"
        inputmode="numeric"
        maxlength="14"
        placeholder="000.000.000-00"
        value="<?= esc($prefillCpf) ?>"
        autocomplete="username"
        required
      >

      <label class="login-label" style="margin-top:14px;">PIN</label>
      <div class="login-pin-grid<?= $attemptedLogin && $error ? ' is-error' : '' ?>" id="loginPinGrid">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
      </div>

      <button type="submit" class="login-submit" id="loginSubmit" disabled>ENTRAR</button>

      <div class="login-links">
        <a href="index.php?first_access=1" class="login-link is-primary">
          Primeiro acesso • Gerar meu PIN
        </a>
        <a href="index.php?forgot=1" class="login-link">
          Esqueci meu PIN
        </a>
      </div>
    </form>
  </div>

  <div class="login-divider">OU</div>

  <div class="admin-link-wrap">
    <a href="admin/login.php" class="admin-link">
      <i class="bi bi-shield-lock"></i> Sou administrador
    </a>
  </div>

</div>
<?php $footerAppBase = $appBase; include __DIR__ . '/_footer.php'; ?>

<!-- ============================================================================
     Modal de boas-vindas: aparece UMA vez por dispositivo, no primeiro contato
     com o sistema atualizado. Flag em localStorage (ponto_welcome_seen_v1).
     ============================================================================ -->
<div class="welcome-backdrop" id="welcomeBackdrop" hidden role="presentation">
  <div class="welcome-modal" role="dialog" aria-modal="true" aria-labelledby="welcomeTitle" aria-describedby="welcomeLede">
    <div class="welcome-grab" aria-hidden="true"></div>
    <button type="button" class="welcome-close" id="welcomeClose" aria-label="Fechar">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>

    <div class="welcome-eyebrow">
      <i class="bi bi-stars" aria-hidden="true"></i>
      <span>Sistema atualizado</span>
    </div>

    <h2 class="welcome-title" id="welcomeTitle">A partir de agora, seu ponto é com CPF e PIN</h2>
    <p class="welcome-lede" id="welcomeLede">
      Trocamos a foto pelo PIN para deixar o registro mais rápido, seguro e independente da câmera. É só seguir os passos abaixo.
    </p>

    <ol class="welcome-steps" aria-label="Passos para bater ponto">
      <li class="welcome-step">
        <span class="welcome-step-num" aria-hidden="true">1</span>
        <div class="welcome-step-body">
          <p class="welcome-step-title">Entre com seu CPF</p>
          <p class="welcome-step-desc">Digite os <strong>11 números</strong> do seu CPF — sem pontos ou traços, o sistema formata pra você.</p>
        </div>
      </li>
      <li class="welcome-step">
        <span class="welcome-step-num" aria-hidden="true">2</span>
        <div class="welcome-step-body">
          <p class="welcome-step-title">Crie ou informe seu PIN</p>
          <p class="welcome-step-desc">Na primeira vez, toque em <strong>"Primeiro acesso • Gerar meu PIN"</strong>. Depois, basta repetir os <strong>6 dígitos</strong> que você escolheu.</p>
        </div>
      </li>
      <li class="welcome-step">
        <span class="welcome-step-num" aria-hidden="true">3</span>
        <div class="welcome-step-body">
          <p class="welcome-step-title">Bater ponto e pronto</p>
          <p class="welcome-step-desc">Após entrar, é só tocar no botão de <strong>entrada</strong> ou <strong>saída</strong>. Seu comprovante é gerado na hora.</p>
        </div>
      </li>
    </ol>

    <p class="welcome-note">
      <strong>Esqueceu o PIN?</strong> Toque em "Esqueci meu PIN" abaixo do botão de entrar — você pode recuperá-lo com seu CPF.
    </p>

    <button type="button" class="welcome-cta" id="welcomeCta">
      <i class="bi bi-check2-circle" aria-hidden="true"></i>
      Entendi, vamos lá
    </button>
  </div>
</div>

<script>
(function() {
  const cpfEl = document.getElementById('loginCpf');
  const pinInputs = Array.from(document.querySelectorAll('#loginPinGrid input'));
  const pinHidden = document.getElementById('loginPinHidden');
  const clientFpHidden = document.getElementById('loginClientFp');
  const submitBtn = document.getElementById('loginSubmit');
  const pinGrid = document.getElementById('loginPinGrid');
  const form = document.getElementById('loginForm');

  // Device fingerprint estável — cacheia em localStorage para sincronizar com
  // o check-in (index.php). O hash é FNV-1a sobre UA + plataforma + resolução +
  // timezone + língua — muda entre celulares diferentes, estável no mesmo.
  function getStableDeviceFp() {
    try {
      const cached = localStorage.getItem('ponto_device_fp_v1');
      if (cached && cached.length >= 8) return cached;
    } catch (_) {}
    // Dims orientation-independent para FP estável entre rotações de tela.
    const _w = screen.width || 0, _h = screen.height || 0;
    const _dims = Math.min(_w, _h) + 'x' + Math.max(_w, _h);
    const parts = [
      navigator.userAgent || '',
      navigator.platform || '',
      _dims,
      (Intl.DateTimeFormat().resolvedOptions().timeZone || ''),
      navigator.language || ''
    ];
    let h = 0x811c9dc5;
    const s = parts.join('|');
    for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 0x01000193); }
    const fp = 'ponto_' + ('00000000' + (h >>> 0).toString(16)).slice(-8);
    try { localStorage.setItem('ponto_device_fp_v1', fp); } catch (_) {}
    return fp;
  }
  if (clientFpHidden) clientFpHidden.value = getStableDeviceFp();

  function cleanDigits(v) { return (v || '').replace(/\D/g, ''); }
  function maskCpf(v) {
    v = cleanDigits(v).slice(0, 11);
    return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  }
  function collectPin() { return pinInputs.map(i => i.value || '').join(''); }

  function validate() {
    const cpfOk = cleanDigits(cpfEl.value).length === 11;
    const pinOk = collectPin().length === 6;
    submitBtn.disabled = !(cpfOk && pinOk);
  }

  cpfEl.addEventListener('input', () => {
    cpfEl.value = maskCpf(cpfEl.value);
    validate();
  });
  cpfEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); pinInputs[0].focus(); }
  });

  pinInputs.forEach((inp, idx) => {
    inp.addEventListener('input', () => {
      inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
      if (inp.value && idx < pinInputs.length - 1) pinInputs[idx + 1].focus();
      pinGrid.classList.remove('is-error');
      validate();
    });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !inp.value && idx > 0) pinInputs[idx - 1].focus();
      if (e.key === 'Enter' && !submitBtn.disabled) form.requestSubmit();
    });
    inp.addEventListener('paste', (e) => {
      e.preventDefault();
      const txt = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, pinInputs.length);
      let start = pinInputs.findIndex(i => !i.value);
      if (start === -1) start = 0;
      for (let i = 0; i < txt.length && (start + i) < pinInputs.length; i++) pinInputs[start + i].value = txt[i];
      pinInputs[Math.min(start + txt.length, pinInputs.length - 1)].focus();
      validate();
    });
  });

  form.addEventListener('submit', (e) => {
    // Copia PIN para input hidden (servidor lê de $_POST['pin'])
    pinHidden.value = collectPin();
    // A5: spinner no submit — feedback imediato ao usuário
    submitBtn.classList.add('is-loading');
    submitBtn.disabled = true;
  });

  // Se form veio com erro, esvazia PIN inputs e foca no primeiro
  <?php if ($attemptedLogin && $error): ?>
    pinInputs.forEach(i => i.value = '');
    setTimeout(() => pinInputs[0].focus(), 100);
  <?php else: ?>
    setTimeout(() => {
      if (!cpfEl.value) cpfEl.focus();
      else pinInputs[0].focus();
    }, 100);
  <?php endif; ?>

  // Pré-carrega home em background (prefetch) se credenciais válidas — opcional, mas speeds up UX
  validate();

  // PWA registration (se SW disponível)
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }

  // ────────────────────────────────────────────────────────────────────────
  // Modal de boas-vindas — primeiro acesso ao sistema atualizado.
  // Flag versionada (v1) permite re-exibir em mudanças futuras incrementando.
  // Não aparece se o usuário chegou via sessão expirada/logout/erro de form
  // — esses contextos já carregam mensagem própria, evitar empilhar.
  // ────────────────────────────────────────────────────────────────────────
  (function() {
    const WELCOME_KEY = 'ponto_welcome_seen_v1';
    const backdrop = document.getElementById('welcomeBackdrop');
    if (!backdrop) return;

    const errorPresent = <?= ($error || $sessionExpired || $loggedOut) ? 'true' : 'false' ?>;
    let alreadySeen = false;
    try { alreadySeen = localStorage.getItem(WELCOME_KEY) === '1'; } catch (_) {}

    if (alreadySeen || errorPresent) return;

    const cta = document.getElementById('welcomeCta');
    const closeBtn = document.getElementById('welcomeClose');
    let lastFocused = null;

    function open() {
      lastFocused = document.activeElement;
      backdrop.hidden = false;
      // Force reflow para o transition pegar a mudança de classe
      void backdrop.offsetWidth;
      backdrop.classList.add('is-open');
      document.documentElement.style.overflow = 'hidden';
      setTimeout(() => cta && cta.focus(), 200);
    }

    function close() {
      backdrop.classList.remove('is-open');
      document.documentElement.style.overflow = '';
      setTimeout(() => {
        backdrop.hidden = true;
        if (lastFocused && typeof lastFocused.focus === 'function') {
          lastFocused.focus();
        } else if (cpfEl) {
          cpfEl.focus();
        }
      }, 350);
      try { localStorage.setItem(WELCOME_KEY, '1'); } catch (_) {}
    }

    cta && cta.addEventListener('click', close);
    closeBtn && closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) close();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !backdrop.hidden) close();
    });

    // Pequeno delay para dar tempo dos elementos da página animarem primeiro
    setTimeout(open, 380);
  })();
})();
</script>
</body>
</html>

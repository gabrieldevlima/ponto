<?php
require_once __DIR__ . '/../config.php';

// Helper para escapar
if (!function_exists('esc')) {
  function esc($str)
  {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// ========================================================================
// GATE DE SESSÃO DE COLABORADOR
// Modo padrão: exige login de colaborador.
// Exceções (liberam sem login):
//  - ?kiosk=1        → dispositivo compartilhado, fluxo antigo CPF+PIN a cada ponto
//  - ?first_access=1 → abre direto a tela de Primeiro Acesso (fluxo PIN enroll)
//  - ?forgot=1       → abre direto a tela de Esqueci meu PIN
// ========================================================================
$kioskMode       = isset($_GET['kiosk']) && $_GET['kiosk'] === '1';
$openFirstAccess = isset($_GET['first_access']);
$openForgotPin   = isset($_GET['forgot']);
// C3: CPF pré-preenchido vem da $_SESSION (gravado pelo login.php quando
// detecta usuário sem PIN). Aceita query param `?cpf=` apenas como fallback
// legado — será removido em release futuro. TTL de 5min impede reuso stale.
$prefillCpf = '';
if ($openFirstAccess) {
    $sessCpf  = preg_replace('/\D/', '', (string)($_SESSION['first_access_cpf'] ?? ''));
    $sessAt   = (int)($_SESSION['first_access_cpf_at'] ?? 0);
    if (strlen($sessCpf) === 11 && $sessAt > 0 && (time() - $sessAt) < 300) {
        $prefillCpf = $sessCpf;
    }
    // Limpa após uso para não vazar entre fluxos
    unset($_SESSION['first_access_cpf'], $_SESSION['first_access_cpf_at']);
    // Fallback legado (vai sumir em versões futuras): aceita ?cpf=
    if ($prefillCpf === '') {
        $prefillCpfRaw = (string)($_GET['cpf'] ?? '');
        $candidate = preg_replace('/\D/', '', $prefillCpfRaw);
        if (strlen($candidate) === 11) $prefillCpf = $candidate;
    }
}

if (!$kioskMode && !$openFirstAccess && !$openForgotPin && !is_collaborator_logged()) {
    header('Location: login.php');
    exit;
}

$collaborator = !$kioskMode ? current_collaborator() : null;

$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$appBase = $scriptDir !== '' ? $scriptDir : '/';
// M6: renderiza base da API independente de /public no path. Em deploys onde
// /api/ e /public/ compartilham o mesmo parent, basta remover /public.
// Caso o deploy não tenha /public (root direto), usa o próprio $appBase.
$apiBase = preg_replace('#/public$#', '', $appBase);
if ($apiBase === '') $apiBase = '/';

$pdo = db();
$schoolsStmt = $pdo->query("SELECT id, name, lat, lng FROM schools WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL");
$schoolsGeo = $schoolsStmt->fetchAll(PDO::FETCH_ASSOC);

// SEO
$pageTitle = 'DEEDO Ponto | Registro de Ponto Online';
$pageDesc  = 'Registre seu ponto com segurança pelo navegador. Suporte a foto, localização e operação offline.';
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath  = rtrim($appBase, '/');
$canonical = $scheme . '://' . $host . $basePath . '/';
$ogImage   = $canonical . 'img/logo_login.png';
?>

<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0d6efd">
  <meta name="app-base" content="<?= esc($appBase) ?>">
  <meta name="api-base" content="<?= esc($apiBase) ?>">
  <meta name="app-build" content="<?= esc(APP_BUILD_ID) ?>">

  <!-- PWA force-update: detecta versão antiga e recarrega com guards. Carrega cedo
       no <head> pra interceptar fetches do início do ciclo da página. -->
  <script src="<?= esc($appBase) ?>/js/pwa-update.js"></script>

  <!-- PWA Manifest -->
  <link rel="manifest" href="<?= esc($appBase) ?>/manifest.json">

  <!-- Apple Touch Icons -->
  <link rel="apple-touch-icon" sizes="180x180" href="<?= esc($appBase) ?>/img/icon-180x180.png">
  <link rel="apple-touch-icon" sizes="152x152" href="<?= esc($appBase) ?>/img/icon-152x152.png">
  <link rel="apple-touch-icon" sizes="120x120" href="<?= esc($appBase) ?>/img/icon-120x120.png">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="DEEDO Ponto">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <!-- Leaflet.js - Mapa interativo -->

  <!-- face-api.js - Reconhecimento facial (local para suporte offline) -->
  <script src="<?= esc($appBase) ?>/js/face-api.min.js"></script>

  <link rel="shortcut icon" href="img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="img/icone-2.ico" type="image/x-icon">

  <!-- Tipografia: Plus Jakarta Sans (UI sans-serif moderno e limpo) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --edge-pad: max(env(safe-area-inset-left), 16px);
      --edge-pad-r: max(env(safe-area-inset-right), 16px);
      --brand: #0d6efd;
      --brand-2: #0aa2ff;
      --bg: #ffffff;
      --card-radius: 16px;
      --glass: rgba(255, 255, 255, .7);
      --glass-border: rgba(2, 6, 23, .08);
      --shadow: 0 4px 12px rgba(0, 0, 0, .08);
    }

    html,
    body {
      height: 100%;
    }

    body {
      background: var(--bg);
      -webkit-tap-highlight-color: transparent;
      color: #1a1a1a;
      min-height: 100vh;
      min-height: 100dvh;
      padding-bottom: env(safe-area-inset-bottom, 20px);
    }

    /* Cross-browser hardening: box-sizing global (defense-in-depth com Bootstrap),
       eliminação de tap delay em Android Chrome antigo, letter-spacing reset em
       telas estreitas (evita aperto em landscape iPhone SE). */
    *, *::before, *::after { box-sizing: border-box; }
    button, input[type="button"], input[type="submit"], .btn, [role="button"],
    .pin-key, .ponto-btn { touch-action: manipulation; }
    @media (max-width: 380px) {
      body, label, .text-meta, .tag { letter-spacing: 0; }
    }

    /* Garantir fundo uniforme em toda a página */
    main.container {
      background: transparent;
      padding-bottom: 40px;
    }

    /* Container principal com fundo consistente */
    .container {
      background: transparent;
    }

    /* Garantir que não há elementos com fundo diferente */
    html,
    body,
    main,
    .container,
    .mobile-nav-section {
      background: var(--bg) !important;
    }

    /* Garantir que apenas os cards tenham fundo branco/azul */
    .nav-card {
      background: white !important;
    }

    .nav-card-primary {
      background: #0d6efd !important;
    }

    .nav-card-secondary {
      background: white !important;
    }

    /* Cards do formulário com fundo consistente */
    .card-clean {
      background: white;
      border: 1px solid #e5e7eb;
      box-shadow: var(--shadow);
    }

    .screen {
      padding-left: var(--edge-pad);
      padding-right: var(--edge-pad-r);
    }

    header .brand-shadow {
      filter: drop-shadow(0 8px 20px rgba(13, 110, 253, .18));
    }

    .brand-logo {
      width: clamp(96px, 18vw, 140px);
      height: auto;
    }

    .card-clean {
      background: white;
      border-radius: var(--card-radius);
      border: 1px solid #e5e7eb;
      box-shadow: var(--shadow);
      transition: all 0.3s ease;
    }

    /* ============================================================================
       FULLSCREEN CAMERA MODE
       ============================================================================ */

    .fullscreen-camera {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 1000;
      background: #000;
      display: none;
    }

    .fullscreen-camera.active {
      display: block;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
      }
      to {
        opacity: 1;
      }
    }

    .fullscreen-camera video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transform: scaleX(-1);
      -webkit-transform: scaleX(-1);
    }

    /* Floating overlay UI in fullscreen */
    .camera-overlay-top {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      padding: max(env(safe-area-inset-top), 16px) 16px 20px;
      background: linear-gradient(to bottom, rgba(0,0,0,.55) 0%, rgba(0,0,0,.2) 60%, transparent 100%);
      z-index: 10;
    }

    .camera-overlay-bottom {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      padding: 40px 16px max(env(safe-area-inset-bottom), 28px);
      background: linear-gradient(to top, rgba(0,0,0,.55) 0%, rgba(0,0,0,.2) 60%, transparent 100%);
      z-index: 10;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
    }

    .fs-back-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: rgba(255,255,255,.15);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      color: #fff;
      border: 1.5px solid rgba(255,255,255,.25);
      font-size: 20px;
      cursor: pointer;
      transition: all .15s ease;
      flex-shrink: 0;
    }
    .fs-back-btn:hover { background: rgba(255,255,255,.25); }
    .fs-back-btn:active { transform: scale(.9); }

    .fs-datetime {
      text-align: center;
      color: #fff;
      text-shadow: 0 2px 12px rgba(0,0,0,.7);
      flex: 1;
      min-width: 0;
    }
    .fs-time {
      font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, 'Courier New', monospace;
      font-size: clamp(1.6rem, 6vw, 2.2rem);
      font-weight: 700;
      letter-spacing: 2px;
      line-height: 1.2;
    }
    .fs-date {
      font-size: clamp(.78rem, 2.8vw, .92rem);
      font-weight: 500;
      opacity: .85;
      margin-top: 2px;
      text-transform: capitalize;
    }

    .fs-instruction-float {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, calc(-50% + 38vw));
      z-index: 10;
      color: #fff;
      font-size: clamp(.82rem, 3vw, 1rem);
      font-weight: 600;
      text-align: center;
      text-shadow: 0 2px 10px rgba(0,0,0,.8);
      pointer-events: none;
      padding: 8px 20px;
      background: rgba(0,0,0,.3);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.12);
      white-space: nowrap;
      transition: all .3s ease;
    }
    .fs-instruction-float.face-ok {
      background: rgba(25, 135, 84, .45);
      border-color: rgba(25, 135, 84, .5);
    }
    .fs-instruction-float.face-warn {
      background: rgba(220, 53, 69, .35);
      border-color: rgba(220, 53, 69, .4);
    }
    @media (min-width:768px) {
      .fs-instruction-float { transform: translate(-50%, calc(-50% + 200px)); }
    }

    /* Status de detecção facial em tempo real */
    .fs-face-status {
      position: absolute;
      bottom: 140px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 11;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 18px;
      border-radius: 999px;
      font-size: .82rem;
      font-weight: 600;
      color: #fff;
      text-shadow: 0 1px 4px rgba(0,0,0,.5);
      background: rgba(0,0,0,.4);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      border: 1px solid rgba(255,255,255,.12);
      transition: all .35s ease;
      white-space: nowrap;
      opacity: 0;
    }
    .fs-face-status.visible { opacity: 1; }
    .fs-face-status.detected {
      background: rgba(25, 135, 84, .55);
      border-color: rgba(25, 135, 84, .6);
    }
    .fs-face-status.no-face {
      background: rgba(220, 53, 69, .45);
      border-color: rgba(220, 53, 69, .5);
    }
    .fs-face-status.loading {
      background: rgba(0,0,0,.4);
      border-color: rgba(255,255,255,.15);
    }
    .fs-face-status-icon {
      display: flex;
      align-items: center;
      font-size: 1rem;
    }
    @media (min-width:768px) {
      .fs-face-status { bottom: 160px; font-size: .88rem; }
    }

    /* Badge sobre a foto no modal de confirmação */
    .captured-face-badge {
      position: absolute;
      bottom: 8px;
      left: 50%;
      transform: translateX(-50%);
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 12px;
      border-radius: 999px;
      font-size: .75rem;
      font-weight: 600;
      color: #fff;
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      white-space: nowrap;
    }
    .captured-face-badge.ok {
      background: rgba(25, 135, 84, .85);
    }
    .captured-face-badge.fail {
      background: rgba(220, 53, 69, .85);
    }

    /* Large circular capture button */
    .capture-button {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      border: 4px solid white;
      background: #0d6efd;
      box-shadow: 0 8px 24px rgba(13, 110, 253, 0.4);
      transition: transform 150ms cubic-bezier(0.4, 0, 0.2, 1);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 32px;
      position: relative;
      outline: none;
    }

    .capture-button:active {
      transform: scale(0.9);
    }

    .capture-button.capture-button-secondary {
      width: 58px;
      height: 58px;
      font-size: 22px;
      background: rgba(255,255,255,.2);
      border: 2px solid rgba(255,255,255,.5);
      box-shadow: none;
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
    }

    .capture-button:disabled {
      opacity: 0.4;
      filter: grayscale(1);
      cursor: not-allowed;
    }

    /* Instruction text in fullscreen */
    .camera-instruction {
      color: white;
      font-size: 16px;
      font-weight: 600;
      text-align: center;
      text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8);
    }

    /* Container do formulário responsivo */
    .card-form {
      width: 100%;
      max-width: clamp(320px, 96vw, 820px);
    }

    @media (min-width: 992px) {
      .card-form {
        max-width: clamp(540px, 86vw, 900px);
      }
    }

    .stepper {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      margin-bottom: 10px;
      font-weight: 700;
      font-size: .95rem;
    }

    .step {
      background: #ffffffcc;
      border: 1px solid rgba(2, 6, 23, .06);
      padding: 8px 10px;
      border-radius: 12px;
      text-align: center;
      color: #334155;
    }

    .step.active {
      color: #0f172a;
      border-color: rgba(13, 110, 253, .3);
      background: linear-gradient(90deg, #ffffff, #f4f9ff);
      box-shadow: inset 0 1px 0 #fff, 0 6px 16px rgba(13, 110, 253, .08);
    }

    .video-wrap {
      position: relative;
      border-radius: 18px;
      overflow: hidden;
      background: #000;
      width: 100%;
      max-width: 100%;
      /* Altura adaptativa para telas pequenas/altas - MÁXIMA */
      aspect-ratio: 3/4;
      max-height: clamp(600px, 90vh, 1200px);
      border: 1px solid rgba(2, 6, 23, .10);
      box-shadow: 0 10px 30px rgba(2, 6, 23, .12);
      isolation: isolate;
      margin-inline: auto;
    }
    /* Safari < 15 não suporta aspect-ratio nativo. Fallback via padding-top
       trick para manter proporção 3:4 no video face capture. */
    @supports not (aspect-ratio: 3/4) {
      .video-wrap {
        aspect-ratio: auto;
        padding-top: 133.33%;
      }
      .video-wrap > video,
      .video-wrap > canvas,
      .video-wrap > .video-overlay {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
      }
    }

    @media (min-width: 992px) {
      .video-wrap {
        aspect-ratio: 4/3;
        max-height: clamp(650px, 85vh, 1100px);
      }
    }

    video,
    #snapshot,
    #faceOverlay {
      width: 100%;
      height: 100%;
      max-height: inherit;
      object-fit: cover;
      display: block;
    }
    
    /* Espelhamento do vídeo para UX melhor (selfie mode) */
    video {
      transform: scaleX(-1);
      -webkit-transform: scaleX(-1);
    }

    #snapshot {
      display: none;
    }

    /* Overlay de orientação */
    .face-overlay {
      position: absolute;
      top: 0; right: 0; bottom: 0; left: 0;
      inset: 0;
      pointer-events: none;
    }

    .face-guide {
      position: absolute;
      top: 0; right: 0; bottom: 0; left: 0;
      inset: 0;
      pointer-events: none;
      display: grid;
      place-items: center;
      transition: opacity .25s ease;
    }

    .face-ring {
      width: min(72%, 340px);
      aspect-ratio: 1/1;
      border: 3px solid rgba(255, 255, 255, .85);
      border-radius: 999px;
      box-shadow: 0 0 0 9999px rgba(0, 0, 0, .28) inset;
      animation: pulse 2s ease-in-out infinite;
    }

    .face-ring.detected {
      border-color: #198754;
      box-shadow: 0 0 0 9999px rgba(0, 0, 0, .15) inset, 0 0 20px rgba(25, 135, 84, 0.6);
      animation: none;
    }
    .face-ring.no-face {
      border-color: #dc3545;
      box-shadow: 0 0 0 9999px rgba(0, 0, 0, .28) inset, 0 0 14px rgba(220, 53, 69, 0.4);
      animation: none;
    }

    /* Botões flutuantes na câmera (mantém apenas o trocar câmera) */
    .floating {
      position: absolute;
      z-index: 2;
    }

    .btn-fab {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: rgba(0, 0, 0, .4);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      color: white;
      border: 1.5px solid rgba(255, 255, 255, .25);
      box-shadow: 0 4px 12px rgba(0, 0, 0, .2);
      transition: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
      cursor: pointer;
    }

    .btn-fab:hover {
      background: rgba(0, 0, 0, .5);
      border-color: rgba(255, 255, 255, .35);
      transform: scale(1.05);
    }

    .btn-fab:active {
      transform: scale(0.95);
      box-shadow: 0 2px 6px rgba(0, 0, 0, .2);
    }

    .btn-fab i {
      font-size: 18px;
    }

    .floating.switch {
      top: 12px;
      right: 12px;
    }

    .hint {
      font-size: .95rem;
      color: #6c757d;
    }

    .input-hint {
      font-size: .95rem;
      color: #64748b;
    }

    .sticky-actions {
      position: sticky;
      bottom: 0;
      z-index: 3;
      background: transparent;
      padding-top: .5rem;
      padding-bottom: max(.25rem, env(safe-area-inset-bottom));
    }

    /* ============================================================================
       ENHANCED BUTTON STYLES - MOBILE OPTIMIZED
       ============================================================================ */

    .btn {
      min-height: 56px;
      font-weight: 600;
      font-size: 16px;
      border-radius: 12px;
      transition: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .btn i {
      font-size: 24px;
    }

    .btn:active {
      transform: scale(0.95);
    }

    .btn:disabled {
      opacity: 0.4;
      filter: grayscale(1);
      cursor: not-allowed;
      transform: none !important;
    }

    .btn-lg {
      min-height: 64px;
      font-size: 18px;
      font-weight: 700;
    }

    .btn-success-gradient {
      background: #198754;
      color: #fff;
      border: 0;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(25, 135, 84, .3);
      min-height: 56px;
      font-size: 18px;
      font-weight: 700;
      transition: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-success-gradient:hover {
      background: #157347;
      box-shadow: 0 6px 16px rgba(25, 135, 84, .4);
    }

    .btn-success-gradient:active {
      transform: scale(0.95);
      box-shadow: 0 2px 6px rgba(25, 135, 84, .3);
    }

    .btn-outline-primary {
      border: 2px solid #0d6efd;
      color: #0d6efd;
      background: white;
      font-weight: 600;
    }

    .btn-outline-primary:hover {
      background: #0d6efd;
      color: white;
    }

    .btn-outline-secondary {
      border: 2px solid #6c757d;
      color: #6c757d;
      background: white;
      font-weight: 600;
    }

    .btn-outline-secondary:hover {
      background: #6c757d;
      color: white;
    }

    .toastify {
      position: fixed;
      left: 50%;
      transform: translateX(-50%);
      /* M5: padding extra além do safe-area-inset-top para Android com status
         bar colorida (toast aparecia parcialmente coberto). */
      top: calc(env(safe-area-inset-top, 0px) + 12px);
      z-index: 1080;
      min-width: min(92%, 540px);
      display: none;
    }

    .toastify .alert {
      box-shadow: 0 12px 30px rgba(2, 6, 23, .18);
      border-radius: 12px;
    }

    .toastify.show {
      display: block;
      animation: slideDown .25s ease;
    }

    @keyframes slideDown {
      from {
        opacity: 0;
        transform: translate(-50%, -8px);
      }

      to {
        opacity: 1;
        transform: translate(-50%, 0);
      }
    }

    .shake {
      animation: shake .35s cubic-bezier(.36, .07, .19, .97) both;
    }

    @keyframes shake {

      10%,
      90% {
        transform: translateX(-1px);
      }

      20%,
      80% {
        transform: translateX(2px);
      }

      30%,
      50%,
      70% {
        transform: translateX(-4px);
      }

      40%,
      60% {
        transform: translateX(4px);
      }
    }

    /* ============================================================================
       NEW MOBILE-FIRST ANIMATIONS & MICRO-INTERACTIONS
       ============================================================================ */

    /* Button press animation */
    @keyframes buttonPress {
      0% {
        transform: scale(1);
      }
      50% {
        transform: scale(0.95);
      }
      100% {
        transform: scale(1);
      }
    }

    .btn-press {
      animation: buttonPress 150ms cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Success checkmark animation */
    @keyframes successPop {
      0% {
        transform: scale(0) rotate(0deg);
        opacity: 0;
      }
      50% {
        transform: scale(1.2) rotate(180deg);
        opacity: 1;
      }
      100% {
        transform: scale(1) rotate(360deg);
        opacity: 1;
      }
    }

    .success-pop {
      animation: successPop 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    }

    /* Flash effect for photo capture */
    @keyframes flashEffect {
      0% {
        opacity: 0;
      }
      50% {
        opacity: 0.8;
      }
      100% {
        opacity: 0;
      }
    }

    .flash-overlay {
      position: fixed;
      top: 0; right: 0; bottom: 0; left: 0;
      inset: 0;
      background: white;
      pointer-events: none;
      z-index: 9999;
      animation: flashEffect 0.3s ease-out;
    }

    /* Pulsing animation for face guide */
    @keyframes pulse {
      0%, 100% {
        transform: scale(1);
        opacity: 1;
      }
      50% {
        transform: scale(1.05);
        opacity: 0.8;
      }
    }

    .pulse {
      animation: pulse 2s ease-in-out infinite;
    }

    /* Shimmer loading effect */
    @keyframes shimmer {
      0% {
        background-position: -1000px 0;
      }
      100% {
        background-position: 1000px 0;
      }
    }

    .shimmer {
      background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
      background-size: 1000px 100%;
      animation: shimmer 2s infinite;
    }

    /* Slide in from top with bounce */
    @keyframes slideInBounce {
      0% {
        transform: translateY(-100%);
        opacity: 0;
      }
      60% {
        transform: translateY(10px);
        opacity: 1;
      }
      80% {
        transform: translateY(-5px);
      }
      100% {
        transform: translateY(0);
      }
    }

    .slide-in-bounce {
      animation: slideInBounce 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    }

    /* Ripple effect */
    @keyframes ripple {
      0% {
        transform: scale(0);
        opacity: 1;
      }
      100% {
        transform: scale(2);
        opacity: 0;
      }
    }

    .ripple-effect {
      position: absolute;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.6);
      width: 100px;
      height: 100px;
      animation: ripple 0.6s ease-out;
      pointer-events: none;
    }

    /* ============================================================================
       FULL SCREEN ALERTS - WITH ANIMATIONS
       ============================================================================ */
       
    .full-alert {
      position: fixed;
      top: 0; right: 0; bottom: 0; left: 0;
      inset: 0;
      background: rgba(0, 0, 0, .90);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 24px;
      z-index: 9999;
      animation: fadeIn 0.3s ease;
    }

    .full-alert.success {
      background: #198754;
    }

    .full-alert.error {
      background: #dc3545;
    }
    
    .full-alert > div {
      animation: successPop 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    }

    /* Modal refinado e responsivo */
    .modal-content {
      border-radius: 14px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
    }

    .modal-dialog {
      max-width: min(96vw, 900px);
      margin-left: auto;
      margin-right: auto;
    }

    .summary-list {
      width: 100%;
    }

    .summary-list .row+.row {
      margin-top: .4rem;
    }

    .confirm-modal-header {
      border-bottom: 1px solid #f1f5f9;
    }

    .confirm-summary {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: .85rem;
    }

    .confirm-meta-card {
      display: flex;
      align-items: center;
      gap: .55rem;
      padding: .55rem .65rem;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      background: #fff;
      min-height: 58px;
    }

    .confirm-meta-label {
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #64748b;
      font-weight: 700;
      line-height: 1;
      margin-bottom: .2rem;
    }

    .summary-icon {
      width: 30px;
      text-align: center;
      color: #0d6efd;
    }

    .status-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: .85rem;
      font-weight: 600;
      background: #f1f5ff;
      color: #0b57d0;
      border: 1px solid rgba(13, 110, 253, .25);
    }

    .status-chip.ok {
      background: #edfff5;
      color: #0f7b3a;
      border-color: rgba(25, 135, 84, .25);
    }

    .status-chip.warn {
      background: #fff7ed;
      color: #b45309;
      border-color: rgba(245, 158, 11, .25);
    }

    .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
    }

    .dot.ok {
      background: #16a34a;
    }

    .dot.warn {
      background: #f59e0b;
    }

    .dot.err {
      background: #dc2626;
    }

    /* Animação de spin para sincronização */
    @keyframes spin {
      from {
        transform: rotate(0deg);
      }

      to {
        transform: rotate(360deg);
      }
    }

    .spin {
      animation: spin 1s linear infinite;
      display: inline-block;
    }

    /* Switch desabilitado quando offline */
    .form-check-input:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .form-check-input:disabled~.form-check-label {
      opacity: 0.6;
      cursor: not-allowed;
    }

    /* Botão roxo customizado */
    .btn-outline-purple {
      --bs-btn-color: #7c3aed;
      --bs-btn-border-color: #7c3aed;
      --bs-btn-hover-color: #fff;
      --bs-btn-hover-bg: #7c3aed;
      --bs-btn-hover-border-color: #7c3aed;
      --bs-btn-focus-shadow-rgb: 124, 58, 237;
      --bs-btn-active-color: #fff;
      --bs-btn-active-bg: #6d28d9;
      --bs-btn-active-border-color: #6d28d9;
      --bs-btn-disabled-color: #7c3aed;
      --bs-btn-disabled-bg: transparent;
      --bs-btn-disabled-border-color: #7c3aed;
    }

    /* Banner de Instalação PWA */
    .install-banner {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 1070;
      padding: 1rem;
      background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
      color: white;
      box-shadow: 0 -4px 20px rgba(2, 6, 23, .15);
      animation: slideUp 0.3s ease;
    }

    @keyframes slideUp {
      from {
        transform: translateY(100%);
      }

      to {
        transform: translateY(0);
      }
    }

    .install-banner-content {
      max-width: 600px;
      margin: 0 auto;
    }

    .install-icon {
      width: 40px;
      height: 40px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      flex-shrink: 0;
    }

    .install-steps {
      font-size: 0.85rem;
      opacity: 0.95;
      line-height: 1.5;
    }

    .install-steps ol {
      margin: 0.5rem 0 0 0;
      padding-left: 1.25rem;
    }

    .install-steps li {
      margin-bottom: 0.25rem;
    }

    .install-banner .btn-close {
      filter: invert(1) brightness(2);
      opacity: 0.8;
    }

    .install-banner .btn-close:hover {
      opacity: 1;
    }

    /* Melhorias mobile para botões do header */
    @media (max-width: 767px) {
      header .d-flex.gap-2 {
        gap: 0.5rem !important;
      }

      .header-btn-mobile {
        min-width: 48px;
        min-height: 48px;
        padding: 0.6rem 0.85rem !important;
        border-width: 2px;
        font-weight: 600;
        box-shadow: 0 3px 10px rgba(2, 6, 23, .12);
        border-radius: 12px !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      .header-btn-mobile i {
        font-size: 1.25rem !important;
      }

      .header-btn-mobile:active {
        transform: scale(0.95);
        box-shadow: 0 1px 4px rgba(2, 6, 23, .08);
      }

      .btn-outline-purple.header-btn-mobile {
        background: linear-gradient(135deg, rgba(124, 58, 237, 0.1), rgba(109, 40, 217, 0.08));
        border-color: rgba(124, 58, 237, 0.4);
      }

      .btn-outline-purple.header-btn-mobile:hover,
      .btn-outline-purple.header-btn-mobile:active {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        border-color: #7c3aed;
      }

      .btn-outline-primary.header-btn-mobile {
        background: linear-gradient(135deg, rgba(13, 110, 253, 0.1), rgba(11, 94, 215, 0.08));
        border-color: rgba(13, 110, 253, 0.4);
      }

      .btn-outline-primary.header-btn-mobile:hover,
      .btn-outline-primary.header-btn-mobile:active {
        background: linear-gradient(135deg, #0d6efd, #0b5ed7);
        border-color: #0d6efd;
      }

      /* Header mobile melhorado - mesma largura do formulário */
      .mobile-header {
        background: white;
        border-radius: 20px;
        padding: 20px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(13, 110, 253, 0.1);
        width: 100%;
        max-width: clamp(320px, 96vw, 820px);
        margin: 0 auto;
      }

      @media (min-width: 992px) {
        .mobile-header {
          max-width: clamp(540px, 86vw, 900px);
        }
      }

      .mobile-header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
      }

      .mobile-brand {
        flex: 1;
        min-width: 0;
      }

      .brand-logo-container {
        display: flex;
        align-items: center;
        gap: 12px;
      }

      .mobile-logo {
        width: 124px;
        height: auto;
        flex-shrink: 0;
      }

      .brand-text {
        display: flex;
        flex-direction: column;
        min-width: 0;
      }

      .brand-title {
        font-size: 20px;
        font-weight: 800;
        color: #0d6efd;
        margin: 0;
        line-height: 1.2;
        letter-spacing: -0.02em;
      }

      .brand-subtitle {
        font-size: 12px;
        color: #6c757d;
        font-weight: 500;
        margin-top: -2px;
      }

      .mobile-header-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
      }

      .status-badge {
        display: flex;
        align-items: center;
      }

      .connection-status {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        background: linear-gradient(135deg, #d1edff 0%, #b8e6ff 100%);
        border: 1px solid rgba(13, 110, 253, 0.2);
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        color: #0d6efd;
        transition: all 0.3s ease;
      }

      .connection-status.offline-status {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        border-color: rgba(220, 53, 69, 0.2);
        color: #dc3545;
      }

      .connection-status i {
        font-size: 14px;
      }

      .status-text {
        font-weight: 600;
      }

      .sync-button {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        border: none;
        border-radius: 20px;
        color: white;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.2);
      }

      .sync-button:hover {
        background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        color: white;
      }

      .sync-count {
        background: #dc3545;
        color: white;
        border-radius: 50%;
        width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
      }

      .brand-logo {
        width: clamp(80px, 16vw, 120px) !important;
      }

      #netBadge {
        font-size: 0.7rem;
        padding: 0.35rem 0.5rem;
      }

      #btnSyncNow {
        min-width: 48px;
        min-height: 48px;
        padding: 0.5rem !important;
      }
    }

    /* Seção de navegação - responsiva para desktop e mobile */
    .mobile-nav-section {
      padding: 20px 16px;
      background: transparent;
      margin: 0 16px;
    }

    /* Desktop: centralizar e ajustar espaçamento */
    @media (min-width: 768px) {
      .mobile-nav-section {
        padding: 30px 20px;
        margin: 0 auto;
        max-width: 800px;
      }
    }

    /* Grid de botões/cards - Responsivo */
    .mobile-buttons-grid {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    /* Desktop: 2 colunas lado a lado */
    @media (min-width: 768px) {
      .mobile-buttons-grid {
        flex-direction: row;
        gap: 20px;
        max-width: 600px;
        margin: 0 auto;
        justify-content: center;
      }

      .nav-card {
        flex: 1;
        max-width: 280px;
      }
    }

    /* ============================================================================
       ENHANCED NAVIGATION CARDS - MOBILE OPTIMIZED
       ============================================================================ */

    .nav-card {
      display: flex;
      align-items: center;
      padding: 14px 18px;
      border-radius: 14px;
      text-decoration: none;
      transition: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
      border: 2px solid transparent;
      background: white;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
      position: relative;
      overflow: hidden;
      min-height: 64px;
    }

    .nav-card:hover {
      transform: translateY(-2px);
      text-decoration: none;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
    }

    .nav-card:active {
      transform: scale(0.98);
      transition: transform 100ms ease;
    }

    /* Card primário (Minha Folha) */
    .nav-card-primary {
      background: #0d6efd;
      border-color: #0d6efd;
      color: white;
      box-shadow: 0 6px 16px rgba(13, 110, 253, 0.3);
    }

    .nav-card-primary:hover {
      background: #0b5ed7;
      color: white;
      box-shadow: 0 10px 24px rgba(13, 110, 253, 0.4);
    }

    /* Card secundário (Admin) */
    .nav-card-secondary {
      background: white;
      border-color: #e5e7eb;
      color: #1a1a1a;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .nav-card-secondary:hover {
      background: #f8f9fa;
      border-color: #0d6efd;
      box-shadow: 0 8px 20px rgba(13, 110, 253, 0.15);
    }

    /* Ícone do card */
    .nav-card-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 12px;
      flex-shrink: 0;
      transition: all 150ms ease;
    }

    .nav-card-primary .nav-card-icon {
      background: rgba(255, 255, 255, 0.2);
    }

    .nav-card-secondary .nav-card-icon {
      background: #f0f4ff;
      border: 2px solid #e0e9ff;
    }

    .nav-card-secondary:hover .nav-card-icon {
      background: #e0e9ff;
      border-color: #0d6efd;
    }

    .nav-card-icon i {
      font-size: 24px;
      font-weight: 600;
    }

    .nav-card-primary .nav-card-icon i {
      color: white;
    }

    .nav-card-secondary .nav-card-icon i {
      color: #0d6efd;
    }

    /* Conteúdo do card */
    .nav-card-content {
      flex: 1;
      text-align: left;
      min-width: 0;
    }

    .nav-card-title {
      font-size: 16px;
      font-weight: 700;
      margin: 0 0 2px 0;
      line-height: 1.3;
    }

    .nav-card-subtitle {
      font-size: 13px;
      opacity: 0.8;
      margin: 0;
      line-height: 1.4;
      font-weight: 500;
    }

    .nav-card-secondary .nav-card-subtitle {
      color: #6c757d;
    }

    /* Seta do card */
    .nav-card-arrow {
      margin-left: 12px;
      opacity: 0.8;
      transition: all 150ms ease;
      flex-shrink: 0;
    }

    .nav-card-arrow i {
      font-size: 20px;
    }

    .nav-card:hover .nav-card-arrow {
      opacity: 1;
      transform: translateX(6px);
    }

    .nav-card-arrow i {
      font-size: 18px;
      font-weight: 600;
    }

    /* ============================================================================
       HEADER - MOBILE OPTIMIZED
       ============================================================================ */
       
    .app-header {
      background: #ffffff;
      border-radius: 16px;
      padding: 10px 14px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
      border: 1px solid #f0f0f0;
      width: 100%;
      max-width: clamp(320px, 96vw, 820px);
      margin: 0 auto;
    }

    @media (min-width: 992px) {
      .app-header {
        max-width: clamp(540px, 86vw, 900px);
        padding: 12px 18px;
      }
    }

    .header-content {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
    }

    /* Logo e Marca */
    .header-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      flex: 1;
      min-width: 0;
    }

    .header-logo {
      width: clamp(110px, 22vw, 150px);
      height: auto;
      flex-shrink: 0;
      filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.08));
    }

    .brand-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
    }

    .brand-name {
      font-size: 24px;
      font-weight: 800;
      color: #0d6efd;
      margin: 0;
      line-height: 1.2;
      letter-spacing: -0.02em;
      background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .brand-tagline {
      font-size: 13px;
      color: #6c757d;
      margin: 0;
      font-weight: 500;
      line-height: 1.3;
    }

    /* Ações do Header */
    .header-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-shrink: 0;
    }

    /* Badge de Status - Modern Clean */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      color: #0369a1;
      transition: all 150ms ease;
    }

    .status-badge i {
      font-size: 14px;
    }

    .status-badge.offline-status {
      background: #fef2f2;
      border-color: #fecaca;
      color: #dc2626;
    }

    .status-badge i {
      font-size: 16px;
    }

    .status-text {
      font-weight: 600;
    }

    /* Botão de Sincronização */
    .sync-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 16px;
      background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
      border: none;
      border-radius: 12px;
      color: white;
      font-size: 16px;
      font-weight: 600;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(40, 167, 69, 0.25);
      cursor: pointer;
      position: relative;
    }

    .sync-btn:hover {
      background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(40, 167, 69, 0.35);
    }

    .sync-btn:active {
      transform: translateY(0);
    }

    .sync-btn i {
      font-size: 18px;
    }

    .sync-badge {
      background: #dc3545;
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 700;
      position: absolute;
      top: -4px;
      right: -4px;
      box-shadow: 0 2px 6px rgba(220, 53, 69, 0.4);
    }

    /* Mobile: Ajustes */
    @media (max-width: 767px) {
      .app-header {
        padding: 6px 12px;
      }

      .header-brand {
        gap: 6px;
      }

      .header-logo {
        width: clamp(95px, 22vw, 120px);
      }

      .status-badge {
        padding: 5px 10px;
        font-size: 11px;
      }

      .status-badge i {
        font-size: 14px;
      }

      .status-text {
        display: none;
        /* Esconde texto no mobile, mostra só ícone */
      }

      .sync-btn {
        padding: 8px 12px;
      }

      .sync-btn i {
        font-size: 16px;
      }
    }

    /* Tablet: Mostra texto do status */
    @media (min-width: 480px) and (max-width: 767px) {
      .status-text {
        display: inline;
      }
    }

    /* ============================================================================
       RELÓGIO HLB - PORTARIA 671/2021
       ============================================================================ */
    
    /* Desktop */
    .hlb-clock-container {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 4px;
      padding-left: 20px;
      border-left: 2px solid #e9ecef;
      margin-left: 16px;
    }

    .hlb-clock-label {
      font-size: 11px;
      color: #6c757d;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .hlb-clock-display {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .hlb-time {
      font-size: 20px;
      font-weight: 700;
      color: #0d6efd;
      font-variant-numeric: tabular-nums;
      letter-spacing: 1px;
      font-family: 'Courier New', monospace;
    }

    .hlb-status {
      font-size: 14px;
      line-height: 1;
    }

    /* Mobile */
    .hlb-clock-mobile {
      background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
      border-radius: 12px;
      padding: 10px 14px;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
      border: 1px solid #bae6fd;
    }

    .hlb-mobile-content {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .hlb-mobile-label {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 11px;
      color: #64748b;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }

    .hlb-mobile-label i {
      font-size: 13px;
      color: #0369a1;
    }

    .hlb-mobile-label span:first-of-type {
      flex: 1;
    }

    .hlb-status-mobile {
      font-size: 14px;
    }

    .hlb-mobile-time {
      font-size: 22px;
      font-weight: 700;
      color: #0369a1;
      font-variant-numeric: tabular-nums;
      letter-spacing: 1px;
      font-family: 'Courier New', monospace;
      text-align: center;
    }

    /* ============================================================================
       CAMERA CARD FULLSCREEN WITH OVERLAYS
       ============================================================================ */

    /* Card form sem padding para câmera ocupar 100% */
    .card-form {
      position: relative;
      overflow: hidden;
      padding: 0 !important;
    }

    /* Câmera ocupa 100% do card */
    .card-form .video-wrap {
      margin: 0;
      border-radius: 16px;
      max-height: 90vh;
    }

    /* Overlay de status (topo) */
    .camera-status-overlay {
      position: absolute;
      top: 12px;
      left: 12px;
      right: 12px;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      padding: 8px 12px;
      border-radius: 8px;
      color: white;
      font-size: 13px;
      text-align: center;
      z-index: 10;
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
      font-weight: 500;
    }

    /* Overlay de controles (rodapé) */
    .camera-card-controls {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      background: rgba(0, 0, 0, 0.7);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      padding: 16px;
      border-radius: 0 0 16px 16px;
      z-index: 10;
    }

    .camera-card-controls .btn {
      background: rgba(255, 255, 255, 0.2);
      border: 2px solid rgba(255, 255, 255, 0.4);
      color: white;
      font-weight: 700;
    }

    .camera-card-controls .btn:hover,
    .camera-card-controls .btn:active {
      background: rgba(255, 255, 255, 0.3);
      border-color: rgba(255, 255, 255, 0.5);
      color: white;
    }

    .camera-card-controls .form-check-label {
      color: white;
      font-weight: 600;
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
    }

    .camera-card-controls .form-check-input {
      cursor: pointer;
    }

    /* Botão de trocar câmera ACIMA do status overlay */
    .card-form .floating.switch {
      top: 12px;
      right: 12px;
    }

    /* Status overlay com espaço para o botão */
    .camera-status-overlay {
      right: 60px; /* Espaço para o botão à direita */
    }

    /* Esconder sticky-actions (botão continuar está em modal agora) */
    .card-form .sticky-actions {
      display: none !important;
    }

    /* Ajustar camFallback para aparecer sobre a câmera se necessário */
    #camFallback {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: rgba(0, 0, 0, 0.8);
      color: white;
      padding: 16px;
      border-radius: 12px;
      text-align: center;
      z-index: 15;
    }

    #camFallback .btn {
      background: white;
      color: #0d6efd;
      border: none;
      margin-top: 8px;
    }

    /* Controles externos (abaixo da câmera) */
    .camera-external-controls {
      width: 100%;
      max-width: clamp(320px, 96vw, 820px);
    }

    @media (min-width: 992px) {
      .camera-external-controls {
        max-width: clamp(540px, 86vw, 900px);
      }
    }
  </style>
  <style>
    .modal-review {
      background: #f8fafc;
    }
    .modal-review .modal-header {
      background: linear-gradient(135deg, #198754 0%, #146c43 100%);
      border-bottom: none;
      color: #ffffff;
      padding: 1.25rem 1.5rem;
    }
    .modal-review .modal-title {
      font-size: clamp(1.25rem, 4vw, 1.5rem);
      font-weight: 600;
      margin: 0;
    }
    .modal-review .modal-subtitle {
      font-size: clamp(0.85rem, 3vw, 0.95rem);
      opacity: 0.85;
      margin: 0;
    }
    .modal-review .modal-header .badge {
      border-radius: 999px;
      font-weight: 600;
      background: rgba(255, 255, 255, 0.15);
      color: #f8fafc;
    }
    .modal-review .modal-body {
      padding: 2rem 1.5rem;
      background: #f8fafc;
    }
    .modal-review .modal-footer {
      border-top: 1px solid rgba(15, 23, 42, 0.08);
      background: #ffffff;
    }
    .review-summary-card {
      background: #ffffff;
      border: 1px solid rgba(15, 23, 42, 0.08);
      border-radius: 16px;
      padding: 1.25rem;
      box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08);
    }
    .review-summary-card .icon-ring {
      width: 2.75rem;
      height: 2.75rem;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(25, 135, 84, 0.12);
      color: #198754;
    }
    .modal-review .modal-footer .btn {
      font-size: 1.05rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.6rem;
      padding: 0.95rem 1.5rem;
    }
    .review-summary-card.alert {
      border-width: 2px;
    }
    .review-timer {
      font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
      font-size: 1rem;
      color: #0f172a;
      background: #ffffff;
      border: 1px solid rgba(15, 23, 42, 0.15);
      border-radius: 999px;
      padding: 0.55rem 1.35rem;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6), 0 6px 18px rgba(15, 23, 42, 0.12);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    .review-timeout {
      border: 1px solid rgba(255, 193, 7, 0.4);
      border-radius: 12px;
      background: rgba(255, 243, 205, 0.95);
      padding: 1rem 1.25rem;
    }

    /* ============================================================================
       HOME SCREEN
       ============================================================================ */
    #homeScreen {
      max-width: 540px;
      margin: 0 auto;
      animation: homeFadeIn .4s ease;
    }
    #cameraSection {
      display: none;
      animation: homeFadeIn .35s ease;
    }
    @keyframes homeFadeIn {
      from { opacity: 0; transform: translateY(12px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .home-clock-block {
      text-align: center;
      padding: 20px 0 8px;
    }
    .home-clock {
      font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, 'Courier New', monospace;
      font-size: clamp(2.6rem, 10vw, 3.8rem);
      font-weight: 700;
      color: #0f172a;
      letter-spacing: 2px;
      line-height: 1.1;
    }
    .home-date {
      font-size: 1rem;
      color: #64748b;
      margin-top: 2px;
      font-weight: 500;
    }

    .home-info-card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(0,0,0,.06);
      overflow: hidden;
      margin-bottom: 16px;
    }

    .home-status-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      padding: 14px 16px;
      align-items: center;
      justify-content: center;
    }

    .home-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: .82rem;
      font-weight: 600;
      padding: 6px 14px;
      border-radius: 999px;
      border: 1px solid transparent;
      white-space: nowrap;
    }
    .home-badge i { font-size: 1rem; }

    .home-badge.online  { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
    .home-badge.offline { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
    .home-badge.geo-ok  { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
    .home-badge.geo-far { background: #fef9c3; color: #a16207; border-color: #fde68a; }
    .home-badge.geo-wait { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }

    .home-actions {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-top: 8px;
    }
    .home-actions .btn-checkin {
      background: #198754;
      color: #fff;
      border: 0;
      border-radius: 14px;
      min-height: 64px;
      font-size: 1.15rem;
      font-weight: 700;
      box-shadow: 0 6px 20px rgba(25,135,84,.3);
      transition: all .15s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }
    .home-actions .btn-checkin:hover:not(:disabled) { background: #157347; box-shadow: 0 8px 24px rgba(25,135,84,.4); }
    .home-actions .btn-checkin:active:not(:disabled) { transform: scale(.97); }
    .home-actions .btn-checkin:disabled { cursor: not-allowed; }
    .home-actions .btn-checkin i { font-size: 1.5rem; }
    /* Bloco antigo de .btn-checkin-spinner removido — vazava width/border/
       animation:spin no elemento overlay e quebrava o spinner full-button.
       Definição correta está adiante (.btn-checkin-spinner com position:absolute
       e ::before/::after). */

    .home-secondary-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .home-secondary-grid .btn-sec {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 4px;
      background: #fff;
      border: 1.5px solid #e2e8f0;
      border-radius: 14px;
      min-height: 80px;
      font-weight: 600;
      font-size: .9rem;
      color: #334155;
      text-decoration: none;
      transition: all .15s ease;
      box-shadow: 0 2px 8px rgba(0,0,0,.04);
    }
    .home-secondary-grid .btn-sec:hover {
      border-color: #0d6efd;
      color: #0d6efd;
      box-shadow: 0 4px 16px rgba(13,110,253,.12);
    }
    .home-secondary-grid .btn-sec:active { transform: scale(.97); }
    .home-secondary-grid .btn-sec i { font-size: 1.5rem; }

    .btn-back-home {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #fff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 10px 18px;
      font-weight: 600;
      font-size: .95rem;
      color: #334155;
      cursor: pointer;
      transition: all .15s ease;
      margin-bottom: 12px;
    }
    .btn-back-home:hover { border-color: #0d6efd; color: #0d6efd; }
    .btn-back-home:active { transform: scale(.97); }

    /* Footer profissional */
    .home-footer {
      text-align: center;
    }
    .home-footer-divider {
      height: 1px;
      background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
      margin: 0 auto 20px;
      max-width: 80%;
    }
    .home-footer-logos {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 20px;
      margin-bottom: 14px;
    }
    .home-footer-logo-pref {
      height: 56px;
      width: auto;
      object-fit: contain;
    }
    .home-footer-sep {
      width: 1px;
      height: 40px;
      background: #d1d5db;
    }
    .home-footer-logo-deedo {
      height: 32px;
      width: auto;
      object-fit: contain;
    }
    .home-footer-info {
      font-size: .75rem;
      color: #94a3b8;
      line-height: 1.6;
    }
    .home-footer-copy {
      margin-top: 4px;
      font-weight: 600;
      color: #64748b;
    }
    /* ===== PIN FLOW (integrado ao index.php) ===== */
    .pin-section { max-width: 480px; margin: 0 auto; padding: 16px; }
    .pin-card { background: #fff; border-radius: 18px; box-shadow: 0 4px 20px rgba(15,23,42,.08); padding: 24px 20px; }
    .pin-h1 { margin: 6px 0; font-size: 22px; font-weight: 700; color: #0f172a; }
    .pin-sub { color: #64748b; font-size: 15px; margin-bottom: 16px; }
    .pin-label { display: block; font-weight: 600; font-size: 14px; color: #0f172a; margin: 12px 0 6px; }
    .pin-input { width: 100%; height: 54px; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 12px; background: #fff; color: #0f172a; font-size: 17px; }
    .pin-input:focus { outline: none; border-color: var(--brand, #0d6efd); }
    .pin-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin: 6px 0 16px; }
    .pin-grid input { width: 100%; height: 58px; text-align: center; font-size: 26px; font-weight: 700; border: 2px solid #e2e8f0; border-radius: 10px; background: #fff; color: #0f172a; padding: 0; }
    .pin-grid input:focus { outline: none; border-color: var(--brand, #0d6efd); box-shadow: 0 0 0 3px rgba(13,110,253,.15); }
    .btn-pin-submit { display: block; width: 100%; min-height: 58px; padding: 12px 20px; margin-top: 10px; border: none; border-radius: 12px; background: var(--brand, #0d6efd); color: #fff; font-size: 17px; font-weight: 700; cursor: pointer; transition: background .15s ease, opacity .15s ease; }
    .btn-pin-submit:hover:not(:disabled) { background: #0a58ca; }
    .btn-pin-submit:disabled { opacity: .45; cursor: not-allowed; }
    .btn-pin-submit.is-success { background: #16a34a; }
    .btn-pin-submit.is-success:hover:not(:disabled) { background: #15803d; }
    .btn-pin-link { display: block; width: 100%; min-height: 44px; padding: 10px; margin-top: 8px; border: none; background: transparent; color: var(--brand, #0d6efd); font-size: 15px; font-weight: 500; text-decoration: underline; cursor: pointer; }
    .btn-pin-secondary { display: block; width: 100%; min-height: 48px; padding: 10px; margin-top: 8px; border: 2px solid var(--brand, #0d6efd); border-radius: 12px; background: #fff; color: var(--brand, #0d6efd); font-size: 16px; font-weight: 600; cursor: pointer; }
    .btn-back-pin { background: transparent; border: none; color: #64748b; font-size: 15px; padding: 0 0 10px; cursor: pointer; display: inline-flex; gap: 6px; align-items: center; }
    .pin-success-icon { width: 90px; height: 90px; margin: 4px auto 14px; border-radius: 50%; background: #16a34a; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 54px; line-height: 1; }
    .pin-show-value { font-family: "SFMono-Regular", Menlo, monospace; font-size: 44px; font-weight: 800; color: var(--brand, #0d6efd); text-align: center; letter-spacing: 8px; padding: 18px 12px; background: #eef4fb; border-radius: 14px; margin: 12px 0; word-break: break-all; }
    .pin-alert { padding: 11px 14px; border-radius: 10px; font-size: 14px; margin: 12px 0; }
    .pin-alert-warning { background: #fef7e5; color: #8a5a06; border-left: 4px solid #ea9a16; }
    .home-pin-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
    .btn-pin-action { font-size: 14px !important; padding: 10px !important; }
    .btn-face-alt { display: block; width: 100%; padding: 10px; margin-top: 8px; background: transparent; color: #475569; border: 1px dashed #cbd5e1; border-radius: 10px; font-size: 14px; cursor: pointer; }
    .btn-face-alt:hover { background: #f8fafc; }
    .pin-camera-wrap { width: 100%; max-width: 320px; margin: 0 auto 12px; aspect-ratio: 1; border-radius: 14px; overflow: hidden; background: #000; position: relative; }
    .pin-camera-wrap video { width: 100%; height: 100%; object-fit: cover; display: block; }
    /* Shake ao errar PIN */
    @keyframes pinShake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-8px)} 40%,80%{transform:translateX(8px)} }
    .pin-grid.is-error input { border-color: #dc2626 !important; background: #fef2f2; color: #7a1717; }
    .pin-grid.is-error { animation: pinShake .35s ease; }
    .pin-link-highlight { background: #fff7e0 !important; border: 1px solid #f6c552; border-radius: 10px; padding: 10px !important; color: #8a5a06 !important; font-weight: 700 !important; text-decoration: none !important; }
    /* Banner onboarding + card último ponto */
    .pin-onboarding-banner { background: #eef4fb; border-left: 4px solid #0d6efd; color: #0f172a; padding: 12px 14px; border-radius: 10px; margin: 0 0 14px; font-size: 15px; display: flex; gap: 10px; align-items: flex-start; }
    .pin-onboarding-banner .pin-banner-close { margin-left: auto; background: transparent; border: none; color: #64748b; cursor: pointer; padding: 2px 8px; border-radius: 6px; }
    .pin-onboarding-banner .pin-banner-close:hover { background: rgba(100,116,139,.1); }
    /* ============================================================
       SCHOOL PICKER — modal de escolha quando GPS falha (.sp-*)
       Aparece só quando colaborador tem 2+ filiações e GPS não bateu.
       ============================================================ */
    .sp-backdrop {
      position: fixed; inset: 0;
      background: radial-gradient(ellipse at center, rgba(15,23,42,.45), rgba(15,23,42,.78));
      -webkit-backdrop-filter: blur(4px);
      backdrop-filter: blur(4px);
      z-index: 1110;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .sp-backdrop.is-open { display: flex; animation: icFade .25s ease-out; }
    .sp-card {
      background: #fff;
      border-radius: 22px;
      padding: 28px 24px 22px;
      max-width: 420px;
      width: 100%;
      text-align: center;
      box-shadow: 0 24px 64px rgba(15,23,42,.22), 0 0 0 1px rgba(15,23,42,.04);
      animation: icPop .35s cubic-bezier(.22,.61,.36,1) both;
    }
    .sp-icon {
      width: 56px; height: 56px;
      border-radius: 50%;
      background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
      color: #b96305;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      margin: 0 auto 12px;
    }
    .sp-title {
      margin: 0 0 4px;
      font-size: 20px;
      font-weight: 800;
      color: var(--r-ink, #0f172a);
      letter-spacing: -0.01em;
    }
    .sp-sub {
      margin: 0 0 18px;
      color: var(--r-ink-2, #475569);
      font-size: 14.5px;
    }
    .sp-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin: 0 0 14px;
      text-align: left;
    }
    .sp-school {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 14px;
      background: var(--r-page, #f7f8fb);
      border: 1px solid var(--r-edge, #e5e7eb);
      border-radius: 12px;
      cursor: pointer;
      text-align: left;
      width: 100%;
      font-family: inherit;
      transition: background .15s ease, border-color .15s ease, transform .12s ease;
    }
    .sp-school:hover { background: var(--r-brand-soft, #e7f1ff); border-color: var(--r-brand, #0d6efd); }
    .sp-school:active { transform: scale(.98); }
    .sp-school-icon {
      width: 38px; height: 38px;
      border-radius: 10px;
      background: var(--r-brand-soft, #e7f1ff);
      color: var(--r-brand, #0d6efd);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
    }
    .sp-school-info { flex: 1; min-width: 0; }
    .sp-school-name {
      font-weight: 700;
      color: var(--r-ink, #0f172a);
      font-size: 14.5px;
      line-height: 1.2;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .sp-school-addr {
      color: var(--r-ink-3, #6b7280);
      font-size: 12px;
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .sp-card .btn-pin-link {
      display: block;
      margin-top: 6px;
      text-align: center;
      color: var(--r-ink-3, #6b7280);
      text-decoration: none;
      font-size: 13.5px;
      padding: 10px;
    }
    .sp-card .btn-pin-link:hover { color: var(--r-ink, #0f172a); }

    /* ============================================================
       MODAL DE CONFIRMAÇÃO PRÉ-BATIDA  (Identity Confirm — `.ic-*`)

       Aparece SEMPRE antes de registrar ponto. Resolve dois problemas:
       (1) celular emprestado → outro user vê o nome aqui em destaque
           e tem chance clara de "Cancelar" antes do registro errado;
       (2) confirmação consciente → mostra ação esperada (entrada/saída),
           hora exata da batida e o tempo trabalhado quando for saída.

       Estética: refinada, hierarquia tipográfica forte, com Plus Jakarta
       Sans variando de peso 700 (nome) → 600 (ação) → 500 (auxiliares).
       Avatar circular com inicial, divider ornamental, pílula tabular
       para a hora — composição vertical respiraúda.
       ============================================================ */
    .ic-backdrop {
      position: fixed;
      inset: 0;
      background: radial-gradient(ellipse at center, rgba(15,23,42,.45), rgba(15,23,42,.78));
      -webkit-backdrop-filter: blur(4px);
      backdrop-filter: blur(4px);
      z-index: 1100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .ic-backdrop.is-open { display: flex; animation: icFade .25s ease-out; }
    @keyframes icFade { from { opacity: 0; } to { opacity: 1; } }

    .ic-card {
      background: #fff;
      border-radius: 22px;
      padding: 32px 28px 24px;
      max-width: 400px;
      width: 100%;
      text-align: center;
      box-shadow: 0 24px 64px rgba(15,23,42,.22), 0 0 0 1px rgba(15,23,42,.04);
      animation: icPop .35s cubic-bezier(.22,.61,.36,1) both;
    }
    @keyframes icPop {
      0%   { opacity: 0; transform: scale(.85) translateY(20px); }
      100% { opacity: 1; transform: scale(1) translateY(0); }
    }

    .ic-avatar {
      width: 76px; height: 76px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--r-brand-soft, #e7f1ff) 0%, #d4e6ff 100%);
      color: var(--r-brand, #0d6efd);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 30px;
      letter-spacing: -0.02em;
      margin: 0 auto 18px;
      box-shadow: 0 6px 16px rgba(13,110,253,.15);
      animation: icAvatarFade .5s .1s both ease-out;
    }
    @keyframes icAvatarFade {
      from { opacity: 0; transform: scale(.7); }
      to   { opacity: 1; transform: scale(1); }
    }

    .ic-name {
      margin: 0 0 14px;
      font-family: var(--font-ui, "Plus Jakarta Sans", system-ui, sans-serif);
      font-size: 24px;
      font-weight: 800;
      color: var(--r-ink, #0f172a);
      letter-spacing: -0.02em;
      line-height: 1.15;
      word-break: break-word;
      animation: icRise .5s .15s both cubic-bezier(.22,.61,.36,1);
    }
    @keyframes icRise {
      from { opacity: 0; transform: translateY(8px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .ic-divider {
      width: 56px;
      height: 2px;
      background: var(--r-brand, #0d6efd);
      opacity: .4;
      border-radius: 2px;
      margin: 0 auto 18px;
      animation: icDividerGrow .4s .2s both ease-out;
    }
    @keyframes icDividerGrow {
      from { width: 0; opacity: 0; }
      to   { width: 56px; opacity: .4; }
    }

    .ic-statement {
      margin: 0 0 20px;
      color: var(--r-ink-2, #475569);
      font-size: 15px;
      line-height: 1.5;
      animation: icRise .5s .25s both cubic-bezier(.22,.61,.36,1);
    }
    .ic-action {
      display: inline-block;
      margin-top: 4px;
      font-size: 22px;
      font-weight: 800;
      color: var(--r-ink, #0f172a);
      letter-spacing: -0.01em;
      text-transform: uppercase;
    }
    .ic-action.is-entrada           { color: #198754; }  /* verde — entrada */
    .ic-action.is-saida             { color: #d97706; }  /* laranja — saída */
    .ic-action.is-iniciar_intervalo { color: #4b5563; }  /* cinza — pausa (distinto de saída) */
    .ic-action.is-retornar_intervalo{ color: #047857; }  /* verde escuro — retomar trabalho */

    /* ENTRADA — pílula simples com a hora atual */
    .ic-time {
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      padding: 12px 22px;
      background: var(--r-page, #f7f8fb);
      border-radius: 14px;
      margin: 0 0 16px;
      animation: icRise .5s .3s both cubic-bezier(.22,.61,.36,1);
    }
    .ic-time[hidden] { display: none; }
    .ic-time-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--r-ink-3, #6b7280);
    }
    .ic-time-value {
      font-size: 36px;
      font-weight: 800;
      color: var(--r-ink, #0f172a);
      letter-spacing: -0.02em;
      font-variant-numeric: tabular-nums;
      line-height: 1.1;
      margin-top: 2px;
    }

    /* SAÍDA — composição editorial: linha do tempo + número hero
       O foco visual vai para o tempo trabalhado (resultado da jornada);
       a hora atual aparece como ponto-fim da linha cronológica. */
    .ic-journey {
      margin: 0 0 18px;
      padding: 18px 18px 22px;
      background: linear-gradient(180deg, #fff8ef 0%, #fff3df 100%);
      border: 1px solid #fde2b5;
      border-radius: 16px;
      animation: icRise .5s .3s both cubic-bezier(.22,.61,.36,1);
    }
    .ic-journey[hidden] { display: none; }

    /* Timeline horizontal: mark início → linha → mark fim (agora) */
    .ic-journey-timeline {
      display: grid;
      grid-template-columns: auto 1fr auto;
      align-items: center;
      gap: 10px;
      margin-bottom: 18px;
    }
    .ic-journey-mark {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      min-width: 0;
    }
    .ic-journey-dot {
      width: 12px; height: 12px;
      border-radius: 50%;
      background: transparent;
      border: 2px solid #d97706;
      margin-bottom: 2px;
    }
    .ic-journey-dot.is-pulse {
      background: #d97706;
      border-color: #d97706;
      box-shadow: 0 0 0 0 rgba(217,119,6,.55);
      animation: icDotPulse 1.6s ease-in-out infinite;
    }
    @keyframes icDotPulse {
      0%   { box-shadow: 0 0 0 0 rgba(217,119,6,.55); }
      70%  { box-shadow: 0 0 0 8px rgba(217,119,6,0); }
      100% { box-shadow: 0 0 0 0 rgba(217,119,6,0); }
    }
    .ic-journey-time {
      font-size: 17px;
      font-weight: 800;
      color: var(--r-ink, #0f172a);
      letter-spacing: -0.01em;
      font-variant-numeric: tabular-nums;
      line-height: 1;
    }
    .ic-journey-cap {
      font-size: 9.5px;
      font-weight: 700;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--r-ink-3, #92633a);
      margin-top: 1px;
    }
    .ic-journey-mark.is-end .ic-journey-cap { color: #b96305; }

    /* Trilha entre os dois marks: linha tracejada com fill sólido animando
       da esquerda para a direita (sensação de "tempo passando"). */
    .ic-journey-track {
      position: relative;
      height: 4px;
      background-image: linear-gradient(to right, #f0c779 50%, transparent 50%);
      background-size: 8px 2px;
      background-repeat: repeat-x;
      background-position: center;
      border-radius: 2px;
      align-self: center;
      margin-top: 7px; /* alinha com a linha entre os dots */
      overflow: hidden;
    }
    .ic-journey-track-fill {
      position: absolute;
      top: 50%; left: 0;
      transform: translateY(-50%);
      height: 3px;
      width: 0;
      background: linear-gradient(90deg, #d97706, #f59e0b);
      border-radius: 2px;
      animation: icTrackFill 1.1s .35s cubic-bezier(.22,.61,.36,1) forwards;
    }
    @keyframes icTrackFill {
      from { width: 0; }
      to   { width: 100%; }
    }

    /* Hero — número expressivo, tipografia editorial */
    .ic-journey-hero {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      animation: icRise .5s .45s both cubic-bezier(.22,.61,.36,1);
    }
    .ic-journey-hero-row {
      display: inline-flex;
      align-items: baseline;
      gap: 4px;
      color: var(--r-ink, #0f172a);
      font-variant-numeric: tabular-nums;
      letter-spacing: -0.03em;
    }
    .ic-journey-hero-num {
      font-size: 44px;
      font-weight: 800;
      line-height: 1;
      color: #b96305;
    }
    .ic-journey-hero-num.is-secondary { font-size: 36px; opacity: .92; }
    .ic-journey-hero-unit {
      font-size: 14px;
      font-weight: 600;
      color: #92633a;
      text-transform: lowercase;
      margin-right: 2px;
    }
    .ic-journey-hero-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .14em;
      text-transform: uppercase;
      color: #92633a;
      margin-top: 2px;
    }

    /* Variante CINZA para "iniciar intervalo" — sobrescreve a paleta âmbar/laranja
       do card de saída sem duplicar a estrutura. Mesma timeline, cores neutras. */
    .ic-journey--break {
      background: linear-gradient(180deg, #f6f7f9 0%, #eceff3 100%) !important;
      border-color: #d1d5db !important;
    }
    .ic-journey--break .ic-journey-dot { border-color: #6b7280; }
    .ic-journey--break .ic-journey-dot.is-pulse {
      background: #6b7280;
      border-color: #6b7280;
      box-shadow: 0 0 0 0 rgba(107,114,128,.55);
      animation: icDotPulseGray 1.6s ease-in-out infinite;
    }
    @keyframes icDotPulseGray {
      0%   { box-shadow: 0 0 0 0 rgba(107,114,128,.55); }
      70%  { box-shadow: 0 0 0 8px rgba(107,114,128,0); }
      100% { box-shadow: 0 0 0 0 rgba(107,114,128,0); }
    }
    .ic-journey--break .ic-journey-mark.is-end .ic-journey-cap { color: #4b5563; }
    .ic-journey--break .ic-journey-track {
      background-image: linear-gradient(to right, #cbd5e1 50%, transparent 50%);
    }
    .ic-journey--break .ic-journey-track-fill {
      background: linear-gradient(90deg, #6b7280, #9ca3af);
    }
    .ic-journey--break .ic-journey-hero-num { color: #4b5563; }
    .ic-journey--break .ic-journey-hero-unit { color: #6b7280; }
    .ic-journey--break .ic-journey-hero-label { color: #6b7280; }

    /* Mobile: economiza altura sem perder leitura */
    @media (max-width: 380px) {
      .ic-journey { padding: 14px 14px 18px; }
      .ic-journey-timeline { gap: 8px; margin-bottom: 14px; }
      .ic-journey-time { font-size: 15px; }
      .ic-journey-hero-num { font-size: 38px; }
      .ic-journey-hero-num.is-secondary { font-size: 30px; }
    }

    @media (prefers-reduced-motion: reduce) {
      .ic-journey-track-fill { animation: none; width: 100%; }
      .ic-journey-dot.is-pulse { animation: none; }
    }

    .ic-card .ic-confirm {
      width: 100%;
      animation: icRise .5s .4s both cubic-bezier(.22,.61,.36,1);
    }
    .ic-card .ic-confirm.is-entrada {
      background: #198754 !important;
      border-color: #198754 !important;
    }
    .ic-card .ic-confirm.is-entrada:hover:not(:disabled) {
      background: #15703f !important;
    }
    .ic-card .ic-confirm.is-saida {
      background: #d97706 !important;
      border-color: #d97706 !important;
    }
    .ic-card .ic-confirm.is-saida:hover:not(:disabled) {
      background: #b96305 !important;
    }
    /* Iniciar intervalo — cinza (neutro, distinto de saída/laranja) */
    .ic-card .ic-confirm.is-iniciar_intervalo {
      background: #6b7280 !important;
      border-color: #6b7280 !important;
    }
    .ic-card .ic-confirm.is-iniciar_intervalo:hover:not(:disabled) {
      background: #4b5563 !important;
    }
    /* Retornar do intervalo — verde mais escuro */
    .ic-card .ic-confirm.is-retornar_intervalo {
      background: #10b981 !important;
      border-color: #10b981 !important;
    }
    .ic-card .ic-confirm.is-retornar_intervalo:hover:not(:disabled) {
      background: #059669 !important;
    }
    .ic-card .btn-pin-link {
      display: block;
      margin-top: 8px;
      text-align: center;
      color: var(--r-ink-3, #6b7280);
      text-decoration: none;
      font-size: 13.5px;
      padding: 10px;
      animation: icRise .5s .45s both cubic-bezier(.22,.61,.36,1);
    }
    .ic-card .btn-pin-link:hover { color: var(--r-ink, #0f172a); }

    /* Mobile: card mais apertado, avatar/typografia reduzidos */
    @media (max-width: 380px) {
      .ic-card { padding: 24px 20px 18px; border-radius: 18px; }
      .ic-avatar { width: 64px; height: 64px; font-size: 26px; margin-bottom: 14px; }
      .ic-name { font-size: 20px; }
      .ic-action { font-size: 19px; }
      .ic-time-value { font-size: 32px; }
    }

    @media (prefers-reduced-motion: reduce) {
      .ic-card, .ic-avatar, .ic-name, .ic-divider, .ic-statement, .ic-time, .ic-elapsed, .ic-card .ic-confirm, .ic-card .btn-pin-link {
        animation: none !important;
      }
    }

    .pin-last-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(15,23,42,.06); padding: 14px 16px; margin: 0 0 12px; display: flex; gap: 14px; align-items: center; }
    .pin-last-card .pin-last-avatar { width: 44px; height: 44px; border-radius: 50%; background: #eef4fb; color: var(--brand, #0d6efd); display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
    .pin-last-card .pin-last-greet { font-weight: 700; color: #0f172a; font-size: 15px; }
    .pin-last-card .pin-last-status { color: #475569; font-size: 14px; margin-top: 2px; }
    .pin-last-card .pin-last-pending { color: #8a5a06; }
    .pin-not-me { display: inline-block; background: transparent; border: none; color: #64748b; font-size: 13px; text-decoration: underline; padding: 0; cursor: pointer; margin-top: 8px; }
    .pin-not-me:hover { color: #0f172a; }
    /* Modal crítico persistente */
    .pin-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.6); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; animation: pinFadeIn .2s ease; }
    @keyframes pinFadeIn { from { opacity: 0 } to { opacity: 1 } }
    .pin-modal-box { background: #fff; border-radius: 16px; max-width: 420px; width: 100%; padding: 24px 22px; box-shadow: 0 10px 40px rgba(0,0,0,.2); }
    .pin-modal-icon { width: 64px; height: 64px; margin: 0 auto 12px; border-radius: 50%; background: #fef2f2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 32px; }
    .pin-modal-title { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 8px; text-align: center; }
    .pin-modal-msg { color: #475569; font-size: 15px; text-align: center; margin: 0 0 18px; line-height: 1.4; }
    /* Indicador discreto de face-api pronto */
    .pin-face-ready { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #64748b; margin-top: 4px; }
    .pin-face-ready .dot { width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; transition: background .3s ease; }
    .pin-face-ready.is-ready .dot { background: #16a34a; }
    /* Home: esconder secundários quando CPF já salvo */
    .home-pin-actions.is-hidden { display: none !important; }

    /* ===========================================================
       REFINEMENT LAYER — azul corporativo limpo
       Apenas polimento do que já existe (paleta Bootstrap-like,
       sans-serif única, brancos, transições sutis).
       =========================================================== */
    :root {
      --font-ui: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
      --r-brand: #0d6efd;
      --r-brand-hover: #0958d9;
      --r-brand-soft: #e7f1ff;
      --r-success: #198754;
      --r-success-hover: #15713f;
      --r-danger: #dc3545;
      --r-warn: #f59e0b;
      --r-ink: #0f172a;
      --r-ink-2: #475569;
      --r-ink-3: #6b7280; /* A3: contraste WCAG AA ≥ 4.6:1 sobre --r-page */
      --r-page: #f7f8fb;
      --r-surface: #ffffff;
      --r-edge: #e5e7eb;
      --r-edge-2: #cbd5e1;
      --r-shadow-xs: 0 1px 2px rgba(15,23,42,.04);
      --r-shadow-sm: 0 1px 2px rgba(15,23,42,.05), 0 2px 6px rgba(15,23,42,.04);
      --r-shadow-md: 0 4px 12px rgba(15,23,42,.06), 0 1px 3px rgba(15,23,42,.04);
      --r-shadow-lg: 0 10px 28px rgba(15,23,42,.08), 0 2px 6px rgba(15,23,42,.04);
      --r-focus: 0 0 0 3px rgba(13,110,253,.22);
      /* Hierarquia de alturas (WCAG 2.1 touch target >= 44px):
         sm = 44px filtros | 48 padrão | 54 CTA form | 68 hero home */
      --btn-h-sm: 44px;
      --btn-h: 48px;
      --btn-h-lg: 54px;
      --btn-h-xl: 68px;
    }

    html, body {
      background: var(--r-page) !important;
      color: var(--r-ink);
      font-family: var(--font-ui) !important;
      letter-spacing: -0.003em;
    }
    html, body, main, .container, .mobile-nav-section { background: var(--r-page) !important; }

    /* Relógio — sans-serif bold, números tabulares */
    .home-clock {
      font-family: var(--font-ui) !important;
      font-weight: 700 !important;
      font-size: clamp(3rem, 12vw, 4.5rem) !important;
      letter-spacing: -0.02em !important;
      color: var(--r-ink) !important;
      font-variant-numeric: tabular-nums;
      line-height: 1 !important;
      margin: 6px 0 4px !important;
    }
    .home-date {
      font-weight: 500 !important;
      font-size: .92rem !important;
      color: var(--r-ink-3) !important;
    }
    .home-clock-block { text-align: center; padding: 20px 0 14px !important; }

    /* Status — discreto e clean: dot + label, sem card, sem pill, sem borda */
    .home-info-card {
      background: transparent !important;
      border: 0 !important;
      box-shadow: none !important;
    }
    .home-status-bar {
      padding: 6px 0 !important;
      gap: 20px !important;
      justify-content: center !important;
    }
    .home-badge {
      background: transparent !important;
      border: 0 !important;
      padding: 0 !important;
      font-size: 12.5px !important;
      font-weight: 500 !important;
      color: var(--r-ink-3) !important;
      letter-spacing: 0 !important;
      gap: 7px !important;
    }
    .home-badge i { display: none !important; }
    .home-badge::before {
      content: "";
      display: inline-block;
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--r-ink-3);
      flex-shrink: 0;
    }
    .home-badge.online::before  { background: var(--r-success); }
    .home-badge.offline::before { background: var(--r-danger); }
    .home-badge.geo-ok::before  { background: var(--r-success); }
    .home-badge.geo-far::before { background: var(--r-warn); }
    .home-badge.geo-wait::before { background: var(--r-ink-3); animation: rPulse 1.4s ease-in-out infinite; }
    @keyframes rPulse { 0%,100% { opacity: .35 } 50% { opacity: 1 } }

    /* Banner onboarding */
    .pin-onboarding-banner {
      background: var(--r-brand-soft) !important;
      border: 1px solid rgba(13,110,253,.22) !important;
      border-left: 3px solid var(--r-brand) !important;
      border-radius: 12px !important;
      padding: 12px 14px !important;
      color: var(--r-ink) !important;
      font-size: 14px !important;
    }
    .pin-onboarding-banner strong { color: #0a4fbb; font-weight: 700; }
    .pin-onboarding-banner i { color: var(--r-brand) !important; }

    /* Card Último Ponto */
    .pin-last-card {
      background: var(--r-surface) !important;
      border: 1px solid var(--r-edge) !important;
      border-radius: 14px !important;
      padding: 14px 16px !important;
      box-shadow: var(--r-shadow-xs) !important;
    }
    .pin-last-card .pin-last-avatar {
      background: var(--r-brand-soft) !important;
      color: var(--r-brand) !important;
    }
    .pin-last-card .pin-last-greet { font-weight: 700 !important; font-size: 15px !important; color: var(--r-ink) !important; }
    .pin-last-card .pin-last-status { color: var(--r-ink-2) !important; font-size: 13.5px !important; }
    .pin-last-card .pin-not-me { color: var(--r-ink-3) !important; font-size: 12.5px !important; }

    /* Botão principal — Bater Ponto (mantém verde do sistema, só refina acabamento) */
    .home-actions .btn-checkin {
      background: var(--r-success) !important;
      color: #fff !important;
      border: 0 !important;
      border-radius: 14px !important;
      min-height: 68px !important;
      padding: 16px 20px !important;
      font-weight: 700 !important;
      font-size: 16px !important;
      letter-spacing: .02em !important;
      box-shadow: var(--r-shadow-md), inset 0 1px 0 rgba(255,255,255,.14) !important;
      transition: transform .12s ease, background .18s ease, box-shadow .18s ease !important;
      display: flex !important; align-items: center !important; justify-content: center !important; gap: 10px !important;
    }
    .home-actions .btn-checkin:hover { background: var(--r-success-hover) !important; }
    .home-actions .btn-checkin:active { transform: translateY(1px) !important; box-shadow: var(--r-shadow-sm) !important; }
    .home-actions .btn-checkin i { font-size: 1.2em; }

    /* Botão "Iniciar intervalo" — cinza (neutro, distinto de saída/laranja) */
    .home-actions .btn-checkin--break {
      background: #6b7280 !important;
      margin-top: 8px !important;
    }
    .home-actions .btn-checkin--break:hover { background: #4b5563 !important; }

    /* Esconde o botão de intervalo por padrão (CSS forte para sobrescrever
       qualquer estado inline). pinUpdateCheckinIntent remove a classe
       quando state='trabalhando'. */
    .home-actions .btn-checkin--break.is-hidden { display: none !important; }

    /* Estado "Retornar do intervalo" no botão principal — verde (voltar ao trabalho) */
    .home-actions .btn-checkin--resume {
      background: #10b981 !important;
    }
    .home-actions .btn-checkin--resume:hover { background: #059669 !important; }

    /* Card do timer quando em intervalo — fundo CINZA (em vez do azul de trabalho).
       Texto permanece branco (herdado do .work-timer-card) — contraste OK.
       O dot pulsante também troca de verde para âmbar para sinalizar "pausado". */
    .work-timer-card.work-timer--break {
      background: linear-gradient(155deg, #4b5563 0%, #6b7280 100%) !important;
      box-shadow: 0 8px 24px rgba(75,85,99,.22), 0 2px 6px rgba(15,23,42,.06) !important;
    }
    .work-timer-card.work-timer--break .work-timer-dot {
      background: #fbbf24;
      box-shadow: 0 0 0 0 rgba(251,191,36,.7);
      animation: workTimerBreakPulse 1.6s cubic-bezier(.4,0,.6,1) infinite;
    }
    @keyframes workTimerBreakPulse {
      0%   { box-shadow: 0 0 0 0   rgba(251,191,36,.7); }
      70%  { box-shadow: 0 0 0 10px rgba(251,191,36,0); }
      100% { box-shadow: 0 0 0 0   rgba(251,191,36,0); }
    }

    /* Ações secundárias PIN */
    .home-pin-actions .btn-sec {
      background: var(--r-surface) !important;
      border: 1px solid var(--r-edge) !important;
      border-radius: 12px !important;
      color: var(--r-ink) !important;
      padding: 12px 10px !important;
      font-weight: 600 !important;
      font-size: 13.5px !important;
      display: flex !important; flex-direction: column !important; gap: 4px !important;
      box-shadow: var(--r-shadow-xs) !important;
      transition: border-color .15s ease, background .15s ease, transform .12s ease;
    }
    .home-pin-actions .btn-sec:hover { border-color: var(--r-brand) !important; background: var(--r-brand-soft) !important; }
    .home-pin-actions .btn-sec:active { transform: translateY(1px); }
    .home-pin-actions .btn-sec i { color: var(--r-brand); font-size: 1.15em; }

    .btn-face-alt {
      background: transparent !important;
      color: var(--r-ink-2) !important;
      border: 1px dashed var(--r-edge-2) !important;
      border-radius: 10px !important;
      padding: 10px !important;
      font-size: 13.5px !important;
      transition: color .15s ease, border-color .15s ease, background .15s ease;
    }
    .btn-face-alt:hover { color: var(--r-brand) !important; border-color: var(--r-brand) !important; background: var(--r-brand-soft) !important; }

    /* Grid Minha Folha / Admin */
    .home-secondary-grid .btn-sec {
      background: var(--r-surface) !important;
      border: 1px solid var(--r-edge) !important;
      border-radius: 12px !important;
      color: var(--r-ink-2) !important;
      font-weight: 500 !important;
      padding: 12px 8px !important;
    }
    .home-secondary-grid .btn-sec:hover { border-color: var(--r-edge-2) !important; color: var(--r-ink) !important; background: #f8fafc !important; }

    .home-footer-info { color: var(--r-ink-3) !important; font-size: 12px !important; }
    .home-footer-divider { background: var(--r-edge) !important; height: 1px !important; }

    /* ============== TELAS PIN ============== */
    .pin-card {
      background: var(--r-surface) !important;
      border: 1px solid var(--r-edge) !important;
      border-radius: 16px !important;
      box-shadow: var(--r-shadow-md) !important;
      padding: 24px 22px !important;
    }
    .pin-h1 {
      font-weight: 700 !important;
      font-size: 24px !important;
      color: var(--r-ink) !important;
      letter-spacing: -0.01em !important;
      line-height: 1.2 !important;
      margin: 2px 0 6px !important;
    }
    .pin-sub {
      color: var(--r-ink-2) !important;
      font-size: 14.5px !important;
      line-height: 1.5 !important;
      margin-bottom: 18px !important;
    }
    .pin-label {
      font-weight: 600 !important;
      font-size: 13px !important;
      color: var(--r-ink-2) !important;
      letter-spacing: 0 !important;
      text-transform: none !important;
      margin: 12px 0 6px !important;
    }
    .pin-input {
      background: var(--r-surface) !important;
      border: 1.5px solid var(--r-edge) !important;
      border-radius: 12px !important;
      color: var(--r-ink) !important;
      font-weight: 500 !important;
      font-size: 16px !important;
      height: 54px !important;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    .pin-input:focus { border-color: var(--r-brand) !important; box-shadow: var(--r-focus) !important; }

    .pin-grid { gap: 8px !important; margin: 6px 0 18px !important; }
    .pin-grid input {
      background: var(--r-surface) !important;
      border: 1.5px solid var(--r-edge) !important;
      border-radius: 12px !important;
      color: var(--r-ink) !important;
      font-family: var(--font-ui) !important;
      font-weight: 700 !important;
      font-size: 26px !important;
      height: 58px !important;
      transition: border-color .15s ease, box-shadow .15s ease, transform .12s ease;
    }
    .pin-grid input:focus { border-color: var(--r-brand) !important; box-shadow: var(--r-focus) !important; }
    .pin-grid input:not(:placeholder-shown) { border-color: var(--r-brand) !important; }
    .pin-grid.is-error input { border-color: var(--r-danger) !important; background: #fff5f5 !important; color: #a0242f !important; }

    /* Telas pequenas (≤360px): reduz padding do card e gap para manter
       cada dígito com largura usável (>= 40px). Sem isso, 6 inputs em 320px
       ficam com ~33px — abaixo do alvo de toque WCAG. */
    @media (max-width: 360px) {
      .pin-card { padding: 18px 12px !important; }
      .pin-grid { gap: 5px !important; }
      .pin-grid input { font-size: 22px !important; height: 52px !important; }
    }

    /* Hint de força do PIN escolhido pelo usuário */
    .pin-strength-hint {
      margin: 8px 0 0;
      font-size: 13px;
      min-height: 18px;
      transition: color .15s ease;
    }
    .pin-strength-hint.is-ok    { color: #198754; font-weight: 600; }
    .pin-strength-hint.is-weak  { color: #dc3545; font-weight: 600; }
    .pin-strength-hint.is-info  { color: var(--r-ink-3); }

    /* Ações na tela do PIN gerado: copiar + WhatsApp lado a lado */
    .pin-show-actions {
      display: flex;
      gap: 8px;
      margin: 12px 0 14px;
    }
    .pin-show-actions .btn-pin-secondary { flex: 1 1 0; }
    .pin-show-actions .btn-pin-whatsapp {
      background: #25d366 !important;
      color: #fff !important;
      border-color: #25d366 !important;
    }
    .pin-show-actions .btn-pin-whatsapp:hover { background: #1ebe5b !important; }
    .pin-show-actions .btn-pin-whatsapp i { color: #fff; }

    /* Botão primário PIN */
    .btn-pin-submit {
      background: var(--r-brand) !important;
      color: #fff !important;
      border: 0 !important;
      border-radius: 12px !important;
      font-family: var(--font-ui) !important;
      font-weight: 700 !important;
      font-size: 15px !important;
      letter-spacing: .01em !important;
      text-transform: none !important;
      min-height: var(--btn-h-lg) !important;
      box-shadow: var(--r-shadow-sm), inset 0 1px 0 rgba(255,255,255,.14) !important;
      transition: background .18s ease, transform .12s ease, box-shadow .18s ease !important;
    }
    .btn-pin-submit:hover:not(:disabled) { background: var(--r-brand-hover) !important; }
    .btn-pin-submit:active:not(:disabled) { transform: translateY(1px); box-shadow: var(--r-shadow-xs) !important; }
    .btn-pin-submit:disabled {
      background: var(--r-edge) !important;
      color: var(--r-ink-3) !important;
      border-color: var(--r-edge) !important;
      opacity: .9 !important;
      cursor: not-allowed !important;
      box-shadow: none !important;
    }
    .btn-pin-submit.is-success { background: var(--r-success) !important; }
    .btn-pin-submit.is-success:hover:not(:disabled) { background: var(--r-success-hover) !important; }

    .btn-pin-secondary {
      background: var(--r-surface) !important;
      border: 1.5px solid var(--r-brand) !important;
      border-radius: 12px !important;
      color: var(--r-brand) !important;
      font-weight: 600 !important;
      font-size: 14.5px !important;
      min-height: 46px !important;
      transition: background .15s ease;
    }
    .btn-pin-secondary:hover { background: var(--r-brand-soft) !important; }

    .btn-pin-link {
      color: var(--r-ink-2) !important;
      font-size: 13.5px !important;
      font-weight: 500 !important;
      text-decoration: underline !important;
      text-decoration-color: var(--r-edge-2) !important;
      text-underline-offset: 3px;
      transition: color .15s ease, text-decoration-color .15s ease;
    }
    .btn-pin-link:hover { color: var(--r-brand) !important; text-decoration-color: var(--r-brand) !important; }
    .pin-link-highlight {
      background: rgba(245,158,11,.14) !important;
      border: 1px solid rgba(245,158,11,.45) !important;
      color: #7c4d00 !important;
      font-weight: 700 !important;
      text-decoration: none !important;
      padding: 10px !important;
      border-radius: 10px !important;
    }

    .btn-back-pin {
      color: var(--r-ink-3) !important;
      font-weight: 500 !important;
      font-size: 13.5px !important;
      padding: 4px 0 14px !important;
    }
    .btn-back-pin:hover { color: var(--r-ink) !important; }

    /* Ícone de sucesso */
    .pin-success-icon {
      background: var(--r-success) !important;
      color: #fff !important;
      width: 80px !important;
      height: 80px !important;
      font-size: 42px !important;
      margin-bottom: 16px !important;
      box-shadow: 0 8px 22px rgba(25,135,84,.25), inset 0 1px 0 rgba(255,255,255,.18) !important;
    }

    /* Exibição do PIN gerado */
    .pin-show-value {
      background: var(--r-brand-soft) !important;
      border: 1px dashed rgba(13,110,253,.35) !important;
      border-radius: 14px !important;
      padding: 22px 14px !important;
      font-family: var(--font-ui) !important;
      font-weight: 700 !important;
      font-size: clamp(40px, 9vw, 52px) !important;
      color: var(--r-brand) !important;
      letter-spacing: .16em !important;
      line-height: 1 !important;
      text-align: center;
      font-variant-numeric: tabular-nums;
    }

    .pin-alert {
      border-radius: 10px !important;
      font-size: 13.5px !important;
      line-height: 1.5 !important;
      padding: 11px 14px !important;
    }
    .pin-alert-warning {
      background: rgba(245,158,11,.10) !important;
      border-left: 3px solid var(--r-warn) !important;
      color: #7c4d00 !important;
    }

    /* Modal crítico */
    .pin-modal-backdrop { background: rgba(15,23,42,.55) !important; backdrop-filter: blur(3px); }
    .pin-modal-box {
      background: var(--r-surface) !important;
      border: 1px solid var(--r-edge) !important;
      border-radius: 16px !important;
      box-shadow: var(--r-shadow-lg) !important;
      padding: 26px 22px max(26px, env(safe-area-inset-bottom, 26px)) !important;
    }
    .pin-modal-icon {
      background: rgba(220,53,69,.10) !important;
      color: var(--r-danger) !important;
      width: 64px !important;
      height: 64px !important;
      font-size: 32px !important;
      margin-bottom: 12px !important;
    }
    .pin-modal-title {
      font-weight: 700 !important;
      font-size: 20px !important;
      color: var(--r-ink) !important;
      letter-spacing: -0.01em !important;
    }
    .pin-modal-msg { color: var(--r-ink-2) !important; font-size: 14.5px !important; line-height: 1.5 !important; }

    /* Step-up câmera do fluxo PIN */
    .pin-camera-wrap {
      border-radius: 16px !important;
      border: 1px solid var(--r-edge) !important;
      box-shadow: var(--r-shadow-md) !important;
      background: #0f172a !important;
    }

    /* ===========================================================
       STEP-UP AUTO-CAPTURE (guia + status + countdown + flash)
       Fluxo: câmera liga sozinha → detecção em loop → quando rosto
       estiver bem enquadrado por 3 frames, inicia countdown 3→1 →
       captura com flash → envia. Sem cliques do usuário.
       =========================================================== */
    .pin-camera-wrap.pin-autocap { max-width: 340px !important; aspect-ratio: 1 / 1 !important; }
    /* M2: iOS 14 não tem aspect-ratio. Fallback via padding-bottom hack. */
    #btnPinStepupManual.btn-pin-photo-capture {
      display: flex !important;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      max-width: 340px;
      min-height: 62px;
      margin: 16px auto 8px !important;
      border: 0 !important;
      border-radius: 14px !important;
      background: #198754 !important;
      color: #fff !important;
      font-size: 17px !important;
      font-weight: 800 !important;
      text-decoration: none !important;
      letter-spacing: 0;
      box-shadow: 0 12px 26px rgba(25,135,84,.34), 0 0 0 1px rgba(255,255,255,.18) inset;
      transition: background .15s ease, transform .12s ease, box-shadow .15s ease;
    }
    #btnPinStepupManual.btn-pin-photo-capture:hover {
      background: #157347 !important;
      color: #fff !important;
      text-decoration: none !important;
      box-shadow: 0 14px 30px rgba(21,115,71,.38), 0 0 0 1px rgba(255,255,255,.2) inset;
    }
    #btnPinStepupManual.btn-pin-photo-capture:active {
      transform: translateY(1px) scale(.99);
      box-shadow: 0 8px 18px rgba(21,115,71,.3), 0 0 0 1px rgba(255,255,255,.18) inset;
    }
    #btnPinStepupManual.btn-pin-photo-capture i {
      font-size: 22px;
      line-height: 1;
    }
    @supports not (aspect-ratio: 1 / 1) {
      .pin-camera-wrap.pin-autocap { position: relative !important; height: 0 !important; padding-bottom: 100% !important; }
      .pin-camera-wrap.pin-autocap > * { position: absolute !important; top: 0 !important; left: 0 !important; width: 100% !important; height: 100% !important; }
    }

    /* Oval guide: branco fino quando procurando, amarelo quando perto,
       verde quando ok + pulse sutil para indicar "estamos vendo você". */
    .pin-face-guide {
      position: absolute;
      inset: 8%;
      pointer-events: none;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0.9;
    }
    .pin-face-guide svg { width: 100%; height: 100%; }
    .pin-face-guide ellipse {
      fill: none;
      stroke: rgba(255,255,255,.85);
      stroke-width: 1.2;
      stroke-dasharray: 4 3;
      transition: stroke .2s ease, stroke-width .2s ease;
      filter: drop-shadow(0 0 6px rgba(0,0,0,.4));
    }
    .pin-face-guide.is-close ellipse {
      stroke: #f59e0b;
      stroke-dasharray: 6 2;
      stroke-width: 1.6;
    }
    .pin-face-guide.is-ok ellipse {
      stroke: #22c55e;
      stroke-width: 2;
      stroke-dasharray: none;
      animation: pinFaceGuidePulse 1s ease-in-out infinite;
    }
    @keyframes pinFaceGuidePulse {
      0%, 100% { stroke-width: 2; filter: drop-shadow(0 0 6px rgba(34,197,94,.4)); }
      50%      { stroke-width: 2.6; filter: drop-shadow(0 0 12px rgba(34,197,94,.7)); }
    }

    /* Status pill no topo do video — feedback textual em tempo real */
    .pin-capture-status {
      position: absolute;
      top: 10px; left: 50%;
      transform: translateX(-50%);
      background: rgba(15,23,42,.75);
      -webkit-backdrop-filter: blur(6px);
      backdrop-filter: blur(6px);
      color: #fff;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 12.5px;
      font-weight: 600;
      letter-spacing: .01em;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      white-space: nowrap;
      max-width: 90%;
      overflow: hidden;
      text-overflow: ellipsis;
      transition: background .2s ease;
    }
    .pin-capture-status .pin-capture-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      background: #fbbf24;
      box-shadow: 0 0 0 0 rgba(251,191,36,.6);
      animation: pinDotPulse 1.4s ease-in-out infinite;
      flex-shrink: 0;
    }
    .pin-capture-status.is-ok { background: rgba(34,197,94,.92); }
    .pin-capture-status.is-ok .pin-capture-dot {
      background: #fff;
      animation: none;
      box-shadow: 0 0 0 3px rgba(255,255,255,.25);
    }
    .pin-capture-status.is-error { background: rgba(220,38,38,.92); }
    @keyframes pinDotPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(251,191,36,.6); }
      50%      { box-shadow: 0 0 0 6px rgba(251,191,36,0); }
    }

    /* Countdown number grande, fade-scale */
    .pin-capture-countdown {
      position: absolute;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      font-family: var(--font-ui);
      font-weight: 800;
      font-size: clamp(72px, 30vw, 140px);
      color: #fff;
      text-shadow: 0 4px 24px rgba(0,0,0,.5);
      pointer-events: none;
      line-height: 1;
    }
    .pin-capture-countdown.is-active { display: flex; animation: pinCountdownPop .9s ease-out forwards; }
    @keyframes pinCountdownPop {
      0%   { transform: scale(.4); opacity: 0; }
      20%  { transform: scale(1.15); opacity: 1; }
      80%  { transform: scale(1); opacity: 1; }
      100% { transform: scale(1.4); opacity: 0; }
    }

    /* Flash branco no clique */
    .pin-capture-flash {
      position: absolute;
      inset: 0;
      background: #fff;
      opacity: 0;
      pointer-events: none;
    }
    .pin-capture-flash.is-fire {
      animation: pinFlashFire .45s ease-out forwards;
    }
    @keyframes pinFlashFire {
      0%   { opacity: 0; }
      15%  { opacity: .95; }
      100% { opacity: 0; }
    }

    /* Overlay "Enviando..." que cobre o video após captura */
    .pin-capture-sending {
      position: absolute;
      inset: 0;
      display: none;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      background: rgba(15,23,42,.82);
      -webkit-backdrop-filter: blur(4px);
      backdrop-filter: blur(4px);
      color: #fff;
      font-weight: 600;
      font-size: 14px;
    }
    .pin-capture-sending.is-on { display: flex; }
    .pin-capture-spinner {
      width: 38px; height: 38px;
      border: 3px solid rgba(255,255,255,.25);
      border-top-color: #fff;
      border-radius: 50%;
      animation: btnSpin .8s linear infinite;
    }

    /* Respeita usuários com preferência de menos movimento */
    @media (prefers-reduced-motion: reduce) {
      .pin-face-guide.is-ok ellipse { animation: none; }
      .pin-capture-countdown.is-active { animation-duration: .3s; }
      .pin-capture-status .pin-capture-dot { animation: none; }
    }

    /* Toasts */
    .toastify, .toast { border-radius: 12px !important; font-family: var(--font-ui) !important; font-weight: 500 !important; }

    /* Animações de entrada */
    @keyframes rFadeUp { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    #homeScreen > * { animation: rFadeUp .4s cubic-bezier(.22,.61,.36,1) both; }
    #homeScreen > *:nth-child(1) { animation-delay: 0ms; }
    #homeScreen > *:nth-child(2) { animation-delay: 50ms; }
    #homeScreen > *:nth-child(3) { animation-delay: 100ms; }
    #homeScreen > *:nth-child(4) { animation-delay: 150ms; }
    #homeScreen > *:nth-child(5) { animation-delay: 200ms; }
    #homeScreen > *:nth-child(6) { animation-delay: 250ms; }
    .pin-section .pin-card { animation: rFadeUp .35s cubic-bezier(.22,.61,.36,1) both; }

    ::selection { background: rgba(13,110,253,.22); color: var(--r-ink); }

    * {
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
      }
    }

    /* =========== TOPBAR + DRAWER =========== */
    .home-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 4px 2px 10px;
      gap: 12px;
    }
    /* .home-topbar-greet só é usado em modo kiosk (não-logado) como
       espaçador `&nbsp;` para alinhar o hamburger à direita. */
    .home-topbar-greet {
      font-size: 14.5px;
      color: var(--r-ink-2);
      font-weight: 500;
    }
    /* "Não sou eu" inline — anti-cenário de celular emprestado.
       Discreto mas óbvio: vai direto para tela de login limpa. */
    .home-topbar-switch {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 5px 10px;
      border-radius: 8px;
      background: var(--r-surface, #fff);
      border: 1px solid var(--r-edge, #e5e7eb);
      color: var(--r-ink-3, #6b7280);
      font-size: 12px;
      font-weight: 600;
      text-decoration: none;
      transition: background .15s ease, color .15s ease, border-color .15s ease;
    }
    .home-topbar-switch i { font-size: 12.5px; }
    .home-topbar-switch:hover {
      background: var(--r-brand-soft, #e7f1ff);
      border-color: var(--r-brand, #0d6efd);
      color: var(--r-brand, #0d6efd);
    }
    @media (max-width: 360px) {
      .home-topbar-switch span { display: none; }
      .home-topbar-switch { padding: 5px 8px; }
    }
    .home-menu-btn {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: var(--r-surface);
      border: 1px solid var(--r-edge);
      color: var(--r-ink);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      cursor: pointer;
      transition: border-color .15s ease, background .15s ease, transform .12s ease;
      box-shadow: var(--r-shadow-xs);
    }
    .home-menu-btn:hover { background: var(--r-brand-soft); border-color: var(--r-brand); color: var(--r-brand); }
    .home-menu-btn:active { transform: scale(.96); }

    .app-drawer-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,.55);
      backdrop-filter: blur(2px);
      z-index: 1040;
      opacity: 0;
      pointer-events: none;
      transition: opacity .22s ease;
    }
    .app-drawer-backdrop.is-open {
      opacity: 1;
      pointer-events: auto;
    }
    .app-drawer {
      position: fixed;
      top: 0; right: 0;
      bottom: 0;
      width: min(86vw, 320px);
      background: var(--r-surface);
      box-shadow: -8px 0 24px rgba(15,23,42,.14);
      border-left: 1px solid var(--r-edge);
      z-index: 1050;
      transform: translateX(100%);
      transition: transform .28s cubic-bezier(.22,.61,.36,1);
      display: flex;
      flex-direction: column;
      font-family: var(--font-ui);
      overflow-y: auto;
    }
    .app-drawer.is-open { transform: translateX(0); }
    .app-drawer-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px 18px 14px;
      border-bottom: 1px solid var(--r-edge);
    }
    .app-drawer-title {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
      font-size: 16px;
      color: var(--r-ink);
      letter-spacing: -0.005em;
    }
    .app-drawer-title img {
      height: 34px;
      width: auto;
      display: block;
    }
    .app-drawer-close {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background: transparent;
      border: 0;
      color: var(--r-ink-2);
      font-size: 18px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: background .12s ease, color .12s ease;
    }
    .app-drawer-close:hover { background: #f1f5f9; color: var(--r-ink); }

    .app-drawer-profile {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 18px;
      border-bottom: 1px solid var(--r-edge);
    }
    .app-drawer-avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: var(--r-brand-soft);
      color: var(--r-brand);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 18px;
      flex-shrink: 0;
    }
    .app-drawer-profile-body { min-width: 0; }
    .app-drawer-profile-name {
      font-weight: 700;
      color: var(--r-ink);
      font-size: 14.5px;
      line-height: 1.2;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .app-drawer-profile-cpf {
      color: var(--r-ink-3);
      font-size: 12.5px;
      margin-top: 2px;
    }

    .app-drawer-list {
      list-style: none;
      margin: 0;
      padding: 8px 0;
    }
    .app-drawer-item {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 18px;
      color: var(--r-ink);
      text-decoration: none;
      font-size: 15px;
      font-weight: 500;
      background: transparent;
      border: 0;
      width: 100%;
      cursor: pointer;
      text-align: left;
      font-family: var(--font-ui);
      transition: background .12s ease, color .12s ease, padding-left .12s ease;
    }
    .app-drawer-item i { font-size: 19px; color: var(--r-brand); flex-shrink: 0; }
    .app-drawer-item .chev { color: var(--r-ink-3); font-size: 14px; margin-left: auto; }
    .app-drawer-item:hover {
      background: var(--r-brand-soft);
      padding-left: 22px;
    }
    .app-drawer-item-danger { color: var(--r-danger); }
    .app-drawer-item-danger i { color: var(--r-danger); }
    .app-drawer-item-danger:hover { background: rgba(220,53,69,.08); }

    .app-drawer-divider {
      height: 1px;
      background: var(--r-edge);
      margin: 4px 18px;
    }
    .app-drawer-footer {
      margin-top: auto;
      padding: 16px 18px max(16px, env(safe-area-inset-bottom, 16px));
      border-top: 1px solid var(--r-edge);
      color: var(--r-ink-3);
      font-size: 11.5px;
      font-weight: 500;
    }
    /* M4: drawer ganha padding-top respeitando notch/status bar */
    .app-drawer-header { padding-top: max(18px, env(safe-area-inset-top, 18px)); }
    .app-drawer-footer strong { color: var(--r-ink-2); font-weight: 600; }

    /* Anima itens com stagger ao abrir */
    .app-drawer.is-open .app-drawer-list li {
      animation: rFadeUp .35s cubic-bezier(.22,.61,.36,1) both;
    }
    .app-drawer.is-open .app-drawer-list li:nth-child(1) { animation-delay: 60ms; }
    .app-drawer.is-open .app-drawer-list li:nth-child(2) { animation-delay: 100ms; }
    .app-drawer.is-open .app-drawer-list li:nth-child(3) { animation-delay: 140ms; }
    .app-drawer.is-open .app-drawer-list li:nth-child(4) { animation-delay: 180ms; }

    body.drawer-open { overflow: hidden; }

    /* =========== APP BRAND HEADER (compartilhado: login + fluxos sem sessão) =========== */
    .app-brand-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      padding: 28px 16px 22px;
      margin: 0 auto;
      max-width: 480px;
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
      font-family: var(--font-ui, "Plus Jakarta Sans", sans-serif);
      font-size: 10.5px;
      font-weight: 700;
      letter-spacing: .22em;
      text-transform: uppercase;
      color: var(--r-ink-3, #94a3b8);
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
      background: var(--r-brand, #0d6efd);
      opacity: .55;
      border-radius: 2px;
    }
    @keyframes brandFadeIn {
      from { opacity: 0; transform: translateY(-6px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 380px) {
      .app-brand-header { padding: 22px 12px 18px; }
      .app-brand-mark { max-width: 104px; }
    }

    /* =========== WORK TIMER CARD =========== */
    .work-timer-card {
      background: linear-gradient(155deg, #0a58ca 0%, var(--r-brand, #0d6efd) 100%);
      color: #fff;
      border-radius: 16px;
      padding: 18px 20px 20px;
      margin: 10px 0 14px;
      box-shadow: 0 8px 24px rgba(13,110,253,.22), 0 2px 6px rgba(15,23,42,.06);
      position: relative;
      overflow: hidden;
    }
    .work-timer-card::before {
      content: "";
      position: absolute;
      top: -30%;
      right: -20%;
      width: 180px;
      height: 180px;
      background: radial-gradient(circle, rgba(255,255,255,.12) 0%, transparent 70%);
      pointer-events: none;
    }
    .work-timer-head {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: .14em;
      text-transform: uppercase;
      color: rgba(255,255,255,.86);
      margin-bottom: 6px;
      position: relative;
    }
    .work-timer-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #22ff99;
      box-shadow: 0 0 0 0 rgba(34,255,153,.8);
      animation: workTimerPulse 1.6s cubic-bezier(.4,0,.6,1) infinite;
    }
    @keyframes workTimerPulse {
      0%   { box-shadow: 0 0 0 0   rgba(34,255,153,.6); }
      70%  { box-shadow: 0 0 0 10px rgba(34,255,153,0); }
      100% { box-shadow: 0 0 0 0   rgba(34,255,153,0); }
    }
    .work-timer-value {
      font-family: "SFMono-Regular", ui-monospace, Menlo, Consolas, monospace;
      font-size: clamp(2.4rem, 9vw, 3.1rem);
      font-weight: 700;
      line-height: 1;
      letter-spacing: -0.02em;
      font-variant-numeric: tabular-nums;
      margin: 2px 0 6px;
      position: relative;
    }
    .work-timer-sub {
      font-size: 13.5px;
      color: rgba(255,255,255,.84);
      font-weight: 500;
      position: relative;
    }
    .work-timer-sub strong { color: #fff; font-weight: 700; }
    .work-timer-total {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid rgba(255,255,255,.18);
      font-size: 12.5px;
      color: rgba(255,255,255,.86);
      position: relative;
    }
    .work-timer-total strong { color: #fff; font-weight: 700; }
    @media (prefers-reduced-motion: reduce) {
      .work-timer-dot { animation: none; }
    }

    /* =========== BOTÃO LOADING =========== */

    /* Botão BATER PONTO — estrutura para label + spinner */
    .home-actions .btn-checkin {
      position: relative;
    }
    .btn-checkin-label {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      transition: opacity .2s ease, transform .2s ease;
    }
    .btn-checkin-spinner {
      position: absolute;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      gap: 12px;
      font-weight: 700;
      font-size: 16px;
      letter-spacing: .02em;
      color: #fff;
    }
    .btn-checkin-spinner::before {
      content: "";
      width: 22px;
      height: 22px;
      border: 2.5px solid rgba(255,255,255,.35);
      border-top-color: #fff;
      border-radius: 50%;
      animation: btnSpin .8s linear infinite;
    }
    .btn-checkin-spinner::after { content: "Registrando seu ponto..."; }
    @keyframes btnSpin { to { transform: rotate(360deg); } }
    .home-actions .btn-checkin.is-loading {
      pointer-events: none;
      cursor: wait;
      /* Mantém o verde durante o loading (UA cinza ficaria estranho). */
      opacity: 1 !important;
      background: #198754 !important;
      color: #fff !important;
    }
    .home-actions .btn-checkin.is-loading .btn-checkin-label {
      opacity: 0;
      transform: scale(.95);
    }
    .home-actions .btn-checkin.is-loading .btn-checkin-spinner { display: flex; }

    /* =========== LOADING GENÉRICO (.is-loading em .btn-pin-submit / .login-submit) =========== */
    .btn-pin-submit.is-loading, .login-submit.is-loading {
      position: relative;
      color: transparent !important;
      pointer-events: none;
      cursor: wait;
    }
    .btn-pin-submit.is-loading::after, .login-submit.is-loading::after {
      content: "";
      position: absolute;
      top: 50%; left: 50%;
      width: 22px; height: 22px;
      margin: -11px 0 0 -11px;
      border: 2.5px solid rgba(255,255,255,.35);
      border-top-color: #fff;
      border-radius: 50%;
      animation: btnSpin .8s linear infinite;
    }

    /* ============================================================
       LANDSCAPE TIGHT LAYOUT — telefones em paisagem com <=500px de altura
       (iPhone SE landscape=375px, Galaxy S8=360px etc.). Reduz tipografia
       e paddings para que o botão primário "Bater Ponto" fique visível
       acima da dobra sem precisar rolar.
       ============================================================ */
    @media (orientation: landscape) and (max-height: 500px) {
      /* Home */
      .home-topbar { padding: 2px 2px 6px !important; }
      .home-clock-block { padding: 6px 0 4px !important; }
      .home-clock { font-size: clamp(2rem, 7vh, 2.8rem) !important; margin: 2px 0 !important; }
      .home-date { font-size: .78rem !important; }
      .home-info-card { padding: 0 !important; }
      .home-status-bar { padding: 2px 0 !important; gap: 12px !important; }
      .home-actions { gap: 8px !important; margin-top: 6px !important; }
      .btn-checkin { min-height: 56px !important; font-size: 15px !important; }
      .work-timer-card { padding: 10px 14px !important; }
      .work-timer-value { font-size: 1.6rem !important; }
      .pin-onboarding-banner, .pin-last-card { padding: 8px 12px !important; margin: 8px 0 !important; }
      /* Footer compacto */
      .app-footer { margin-top: 10px !important; padding: 10px 12px !important; font-size: 11.5px !important; }

      /* Câmera fullscreen — já é fullscreen, mas o HUD precisa compactar */
      #fullscreenCamera .fs-overlay-top { padding: 6px 10px !important; font-size: 13px !important; }
      #fullscreenCamera .fs-overlay-bottom { padding: 6px 10px !important; }
      #fullscreenCamera .fs-clock { font-size: 1.4rem !important; }

      /* PIN cards em modais centrados: reduz padding do card, grid mais apertado */
      .pin-card { padding: 14px 16px !important; max-width: 560px !important; }
      .pin-grid input { height: 46px !important; font-size: 20px !important; }
      .pin-grid { gap: 6px !important; margin: 4px 0 10px !important; }
      .pin-section h1, .pin-section h2, .pin-section .pin-card h1 { font-size: 1.05rem !important; margin-bottom: 6px !important; }
      .btn-pin-submit { min-height: 44px !important; font-size: 14px !important; }
      /* Modal backdrop: alinha ao topo para não colidir com teclado */
      .pin-modal-backdrop { align-items: flex-start !important; padding-top: 8px !important; }

      /* Step-up e login: mesmo tratamento */
      .login-card { padding: 18px 20px !important; }
      .login-pin-grid input { height: 46px !important; font-size: 20px !important; }
    }
  </style>
</head>

<body class="screen">
  <main class="container py-1">
    <!-- Header - Logo centralizada -->
    <?php if (!$collaborator): ?>
      <!-- Brand header visível em telas sem sessão (login flows, kiosque).
           Na home logada o homeTopbar já identifica o usuário. -->
      <header class="app-brand-header" role="banner" aria-label="DEEDO Ponto">
        <img src="<?= esc($appBase) ?>/img/logo_login.png" alt="DEEDO Ponto" class="app-brand-mark">
        <div class="app-brand-tag">Sistema de Ponto</div>
      </header>
    <?php endif; ?>
    <!-- Elementos ocultos mantidos para compatibilidade JS (HLB sync, net badge, sync btn) -->
    <div style="display:none" aria-hidden="true">
      <span id="hlbClock">--:--:--</span>
      <span id="hlbStatus"></span>
      <span id="hlbClockMobile">--:--:--</span>
      <span id="hlbStatusMobile"></span>
      <span id="netBadge"><i class="bi bi-wifi"></i><span class="status-text">Online</span></span>
      <button id="btnSyncNow"><span id="syncCount">0</span></button>
      <button id="btnOfflineDiag" type="button" class="d-none" title="Diagnóstico da fila offline" style="background:transparent;border:0;color:inherit;padding:0 .25rem;">
        <i class="bi bi-clipboard-pulse"></i>
      </button>
    </div>

    <!-- ===================== HOME SCREEN ===================== -->
    <section id="homeScreen">
      <!-- Topbar: "Não sou eu" + hamburger. A saudação aparece no pin-last-card
           abaixo (evita duplicar nome do usuário no topo). -->
      <div class="home-topbar">
        <?php if ($collaborator): ?>
          <a class="home-topbar-switch" href="<?= esc($appBase) ?>/logout.php?next=login" aria-label="Trocar usuário (não sou eu)">
            <i class="bi bi-arrow-repeat"></i>
            <span>Não sou eu</span>
          </a>
          <button type="button" class="home-menu-btn" id="btnMenuToggle" aria-label="Abrir menu" aria-expanded="false">
            <i class="bi bi-list"></i>
          </button>
        <?php else: ?>
          <div class="home-topbar-greet">&nbsp;</div>
        <?php endif; ?>
      </div>

      <!-- Relógio grande -->
      <div class="home-clock-block">
        <div class="home-clock" id="homeClock">--:--:--</div>
        <div class="home-date" id="homeDate">--</div>
      </div>

      <!-- Banner onboarding (aparece só no primeiro acesso) -->
      <div class="pin-onboarding-banner" id="pinOnboardBanner" style="display:none;">
        <i class="bi bi-info-circle-fill" style="color:#0d6efd;font-size:18px;"></i>
        <div>
          <strong>Primeira vez aqui?</strong><br>
          Toque em <strong>Primeiro acesso</strong> para gerar seu PIN em poucos segundos.
        </div>
        <button type="button" class="pin-banner-close" id="pinOnboardClose" aria-label="Fechar">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>

      <!-- Modal de confirmação ANTES de cada batida — confirma identidade e
           ação (entrada/saída) com hora atual e tempo trabalhado. Disparado
           para TODA batida (não só após idle), para reduzir ponto errado em
           celular emprestado e dar uma checagem mental ao usuário. -->
      <?php if ($collaborator): ?>
      <!-- Modal "Em qual instituição você está?" — disparado quando GPS falha
           e o colaborador tem 2+ instituições filiadas (Camada 3.3 do fallback). -->
      <div class="sp-backdrop" id="schoolPickerBackdrop" role="dialog" aria-modal="true" aria-labelledby="spTitle" aria-hidden="true">
        <div class="sp-card">
          <div class="sp-icon" aria-hidden="true"><i class="bi bi-geo-alt-fill"></i></div>
          <h2 class="sp-title" id="spTitle">GPS indisponível</h2>
          <p class="sp-sub">Em qual instituição você está agora?</p>
          <div class="sp-list" id="schoolPickerList" role="list"></div>
          <button type="button" class="btn-pin-link" id="schoolPickerCancel">Cancelar</button>
        </div>
      </div>

      <div class="ic-backdrop" id="identityConfirmBackdrop" role="dialog" aria-modal="true" aria-labelledby="icName" aria-hidden="true">
        <div class="ic-card">
          <div class="ic-avatar" aria-hidden="true">
            <?= esc(mb_strtoupper(mb_substr($collaborator['name'] ?? '?', 0, 1))) ?>
          </div>
          <h2 class="ic-name" id="icName"><?= esc($collaborator['name']) ?></h2>
          <div class="ic-divider" aria-hidden="true"></div>
          <p class="ic-statement">
            <span id="icStatementText">Você está batendo o ponto de</span><br>
            <strong class="ic-action" id="icAction">—</strong>
          </p>
          <!-- ENTRADA: caixa simples com a hora atual -->
          <div class="ic-time" id="icTimeBox">
            <span class="ic-time-label">Hora atual</span>
            <span class="ic-time-value" id="icTime">--:--</span>
            <!-- Só no RETORNO do intervalo (modo logado): ajustar a hora real da volta. -->
            <div id="icTimeEdit" hidden style="margin-top:12px;">
              <button type="button" id="icTimeEditToggle" style="background:none;border:0;color:#047857;font-weight:600;cursor:pointer;font-size:.85rem;text-decoration:underline;">Voltei em outro horário? Ajustar</button>
              <input type="time" id="icTimeEditInput" hidden style="margin-top:8px;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:1rem;">
            </div>
          </div>

          <!-- SAÍDA: jornada (timeline) + tempo trabalhado em destaque hero.
               Substitui o card simples acima quando a próxima ação é saída. -->
          <div class="ic-journey" id="icJourney" hidden>
            <div class="ic-journey-timeline" aria-hidden="true">
              <div class="ic-journey-mark is-start">
                <span class="ic-journey-dot"></span>
                <span class="ic-journey-time" id="icJourneyStart">--:--</span>
                <span class="ic-journey-cap">Entrada</span>
              </div>
              <div class="ic-journey-track">
                <span class="ic-journey-track-fill"></span>
              </div>
              <div class="ic-journey-mark is-end">
                <span class="ic-journey-dot is-pulse"></span>
                <span class="ic-journey-time" id="icJourneyEnd">--:--</span>
                <span class="ic-journey-cap">Agora</span>
              </div>
            </div>
            <div class="ic-journey-hero">
              <div class="ic-journey-hero-row">
                <span class="ic-journey-hero-num" id="icJourneyHours">—</span>
                <span class="ic-journey-hero-unit">h</span>
                <span class="ic-journey-hero-num is-secondary" id="icJourneyMinutes">—</span>
                <span class="ic-journey-hero-unit">min</span>
              </div>
              <span class="ic-journey-hero-label">trabalhados hoje</span>
            </div>
          </div>
          <button type="button" class="btn-pin-submit ic-confirm" id="identityConfirmYes">
            <span id="icConfirmText">Confirmar</span>
          </button>
          <button type="button" class="btn-pin-link" id="icCancel">Cancelar</button>
        </div>
      </div>
      <?php endif; ?>


      <!-- Card "Seu último ponto" (aparece só com colaborador logado) -->
      <div class="pin-last-card" id="pinLastCard" style="display:none;">
        <div class="pin-last-avatar"><i class="bi bi-person-fill"></i></div>
        <div>
          <div class="pin-last-greet" id="pinLastGreet">Olá 👋</div>
          <div class="pin-last-status" id="pinLastStatus">Carregando…</div>
        </div>
      </div>

      <!-- Contador de tempo trabalhando (aparece só com expediente aberto) -->
      <div class="work-timer-card" id="workTimerCard" style="display:none;" role="status" aria-live="polite">
        <div class="work-timer-head">
          <span class="work-timer-dot" aria-hidden="true"></span>
          <span class="work-timer-label">Trabalhando agora</span>
        </div>
        <div class="work-timer-value" id="workTimerValue" aria-label="Tempo trabalhado">00:00:00</div>
        <div class="work-timer-sub" id="workTimerSub">Desde --:--</div>
        <div class="work-timer-total" id="workTimerTotal" style="display:none;"></div>
      </div>

      <!-- Status: online + localização -->
      <div class="home-info-card">
        <div class="home-status-bar">
          <span class="home-badge geo-wait" id="homeNetBadge">
            <i class="bi bi-wifi"></i> <span>Verificando...</span>
          </span>
          <span class="home-badge geo-wait" id="homeGeoBadge">
            <i class="bi bi-geo-alt"></i> <span>Localizando...</span>
          </span>
        </div>
      </div>

      <!-- Botões principais -->
      <div class="home-actions">
        <button type="button" class="btn-checkin" id="btnGoCheckin">
          <span class="btn-checkin-label">
            <i class="bi bi-check-circle-fill" id="btnCheckinIcon"></i>
            <span id="btnCheckinText">Bater ponto</span>
          </span>
          <span class="btn-checkin-spinner" aria-hidden="true"></span>
        </button>
        <!-- Botão secundário de intervalo. Visibilidade controlada por pinUpdateCheckinIntent
             baseada no estado retornado por /api/last_checkin.php (state=trabalhando → mostra).
             Default oculto via classe is-hidden (display:none !important) — mais robusto
             que style inline pois resiste a sobrescritas. -->
        <button type="button" class="btn-checkin btn-checkin--break is-hidden" id="btnGoBreak">
          <span class="btn-checkin-label">
            <i class="bi bi-pause-circle-fill"></i>
            <span id="btnBreakText">Iniciar intervalo</span>
          </span>
          <span class="btn-checkin-spinner" aria-hidden="true"></span>
        </button>
        <?php if ($kioskMode): ?>
          <!-- Modo kiosque: fluxo antigo (sem login persistente) -->
          <div class="home-pin-actions">
            <button type="button" class="btn-sec btn-pin-action" id="btnPinEnroll">
              <i class="bi bi-key"></i>
              Primeiro acesso
            </button>
            <button type="button" class="btn-sec btn-pin-action" id="btnPinRecover">
              <i class="bi bi-question-circle"></i>
              Esqueci meu PIN
            </button>
          </div>
          <button type="button" class="btn-face-alt" id="btnUseFace">
            <i class="bi bi-person-bounding-box"></i> Usar reconhecimento facial
          </button>
          <div class="home-secondary-grid">
            <a href="<?= esc($appBase) ?>/admin/login.php" class="btn-sec">
              <i class="bi bi-shield-lock"></i>
              Entrar como Admin
            </a>
          </div>
        <?php endif; ?>
        <!-- Em modo logado, as ações secundárias vivem no drawer do topo -->
      </div>

      <?php $footerAppBase = $appBase; include __DIR__ . '/_footer.php'; ?>
    </section>

    <?php if ($collaborator): ?>
    <!-- ===================== DRAWER MENU (lateral direita) ===================== -->
    <div class="app-drawer-backdrop" id="appDrawerBackdrop" aria-hidden="true"></div>
    <nav class="app-drawer" id="appDrawer" role="navigation" aria-label="Menu principal" aria-hidden="true">
      <div class="app-drawer-header">
        <div class="app-drawer-title">
          <img src="<?= esc($appBase) ?>/img/logo_login.png" alt="DEEDO Ponto">
        </div>
        <button type="button" class="app-drawer-close" id="btnMenuClose" aria-label="Fechar menu">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <div class="app-drawer-profile">
        <div class="app-drawer-avatar">
          <?= esc(mb_strtoupper(mb_substr($collaborator['name'] ?? '?', 0, 1))) ?>
        </div>
        <div class="app-drawer-profile-body">
          <div class="app-drawer-profile-name"><?= esc($collaborator['name']) ?></div>
          <div class="app-drawer-profile-cpf">CPF <?= esc(mask_cpf((string)($collaborator['cpf'] ?? ''))) ?></div>
        </div>
      </div>
      <ul class="app-drawer-list" role="menu">
        <li>
          <a class="app-drawer-item" href="<?= esc($appBase) ?>/my_timesheet.php">
            <i class="bi bi-calendar-check"></i>
            <span>Minha Folha</span>
            <i class="bi bi-chevron-right chev"></i>
          </a>
        </li>
        <li>
          <button type="button" class="app-drawer-item" id="menuBtnUseFace">
            <i class="bi bi-person-bounding-box"></i>
            <span>Reconhecimento facial</span>
            <i class="bi bi-chevron-right chev"></i>
          </button>
        </li>
        <li>
          <button type="button" class="app-drawer-item" id="menuBtnChangePin">
            <i class="bi bi-key"></i>
            <span>Mudar meu PIN</span>
            <i class="bi bi-chevron-right chev"></i>
          </button>
        </li>
      </ul>
      <div class="app-drawer-divider"></div>
      <ul class="app-drawer-list" role="menu">
        <li>
          <a class="app-drawer-item app-drawer-item-danger" href="<?= esc($appBase) ?>/logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Sair</span>
            <i class="bi bi-chevron-right chev"></i>
          </a>
        </li>
      </ul>
      <div class="app-drawer-footer">
        <strong><?= esc(SYSTEM_NAME) ?></strong> · v<?= esc(SYSTEM_VERSION) ?>
      </div>
    </nav>
    <?php endif; ?>

    <!-- ===================== PIN: CHECK-IN ===================== -->
    <section id="pinSection" class="pin-section" style="display:none;">
      <div class="pin-card">
        <button type="button" class="btn-back-pin" data-pin-back>
          <i class="bi bi-arrow-left"></i> Voltar
        </button>
        <h2 class="pin-h1">Digite seu PIN</h2>
        <p class="pin-sub">CPF + 6 dígitos do PIN para bater seu ponto.</p>
        <label class="pin-label" for="pinCpf">CPF</label>
        <input class="pin-input" id="pinCpf" type="tel" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
        <label class="pin-label" style="margin-top:14px;">PIN</label>
        <div class="pin-grid" id="pinDigits">
          <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
          <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
          <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
          <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
          <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
          <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        </div>
        <button type="button" class="btn-pin-submit" id="btnPinSubmit" disabled>BATER PONTO</button>
        <button type="button" class="btn-pin-link" data-pin-go="recover">Esqueci meu PIN</button>
      </div>
    </section>

    <!-- ===================== PIN: PRIMEIRO ACESSO ===================== -->
    <section id="pinEnrollSection" class="pin-section" style="display:none;">
      <div class="pin-card">
        <button type="button" class="btn-back-pin" data-pin-back>
          <i class="bi bi-arrow-left"></i> Voltar
        </button>
        <h2 class="pin-h1">Primeiro acesso</h2>
        <p class="pin-sub">Digite seu CPF. Vamos cadastrar sua foto e o PIN que você quiser usar.</p>
        <label class="pin-label" for="pinEnrollCpf">CPF</label>
        <input class="pin-input" id="pinEnrollCpf" type="tel" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="username">
        <button type="button" class="btn-pin-submit" id="btnPinEnrollSubmit" style="margin-top:16px;" disabled>CONTINUAR</button>
      </div>
    </section>

    <!-- ===================== PIN: ESCOLHER PIN ===================== -->
    <section id="pinChooseSection" class="pin-section" style="display:none;">
      <div class="pin-card">
        <h2 class="pin-h1">Escolha seu PIN</h2>
        <p class="pin-sub">Crie um PIN de 6 dígitos. Use algo que você lembre, mas que <strong>não seja fácil</strong> (sem 123456, sem datas óbvias, sem repetições).</p>
        <div class="pin-choose-step">
          <label class="pin-label" id="pinChooseLabel">Seu PIN</label>
          <div class="pin-grid pin-grid-choose" id="pinChooseDigits" role="group" aria-labelledby="pinChooseLabel">
            <input type="tel" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" aria-label="Dígito 1">
            <input type="tel" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" aria-label="Dígito 2">
            <input type="tel" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" aria-label="Dígito 3">
            <input type="tel" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" aria-label="Dígito 4">
            <input type="tel" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" aria-label="Dígito 5">
            <input type="tel" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" aria-label="Dígito 6">
          </div>
          <p class="pin-strength-hint" id="pinChooseHint" aria-live="polite">&nbsp;</p>
        </div>
        <button type="button" class="btn-pin-submit" id="btnPinChooseNext" style="margin-top:16px;" disabled>CONTINUAR</button>
        <button type="button" class="btn-pin-link" id="btnPinChooseGenerate" style="margin-top:8px;">Gerar um para mim</button>
      </div>
    </section>

    <!-- ===================== PIN: ESQUECI ===================== -->
    <section id="pinRecoverSection" class="pin-section" style="display:none;">
      <div class="pin-card">
        <button type="button" class="btn-back-pin" data-pin-back>
          <i class="bi bi-arrow-left"></i> Voltar
        </button>
        <h2 class="pin-h1">Esqueci meu PIN</h2>
        <p class="pin-sub">Digite seu CPF. Se tudo estiver em ordem, geramos um novo PIN na hora.</p>
        <label class="pin-label" for="pinRecoverCpf">CPF</label>
        <input class="pin-input" id="pinRecoverCpf" type="tel" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
        <button type="button" class="btn-pin-submit" id="btnPinRecoverSubmit" style="margin-top:16px;" disabled>RECUPERAR PIN</button>
      </div>
    </section>

    <!-- ===================== PIN: EXIBIR PIN GERADO ===================== -->
    <section id="pinShowSection" class="pin-section" style="display:none;">
      <div class="pin-card">
        <div class="pin-success-icon">✓</div>
        <h2 class="pin-h1" id="pinShowTitle" style="text-align:center;">Seu PIN foi gerado!</h2>
        <p class="pin-sub" style="text-align:center;">Anote com cuidado — ele não será exibido novamente.</p>
        <div class="pin-show-value" id="pinShowValue">------</div>
        <div class="pin-show-actions">
          <button type="button" class="btn-pin-secondary" id="btnPinCopy">
            <i class="bi bi-clipboard"></i> Copiar
          </button>
          <button type="button" class="btn-pin-secondary btn-pin-whatsapp" id="btnPinWhatsapp">
            <i class="bi bi-whatsapp"></i> Salvar no WhatsApp
          </button>
        </div>
        <div class="pin-alert pin-alert-warning">
          <i class="bi bi-exclamation-triangle-fill"></i>
          Anote agora. Este PIN não será mostrado novamente.
        </div>
        <button type="button" class="btn-pin-submit" id="btnPinShowConfirm">OK, JÁ ANOTEI</button>
      </div>
    </section>

    <!-- ===================== PIN: SUCESSO DO CHECK-IN ===================== -->
    <section id="pinSuccessSection" class="pin-section" style="display:none;">
      <div class="pin-card">
        <div class="pin-success-icon">✓</div>
        <h2 class="pin-h1" id="pinSuccessTitle" style="text-align:center;">Ponto registrado!</h2>
        <div style="text-align:center; font-size:18px; color:#0f172a; margin: 6px 0 4px;" id="pinSuccessAction">Entrada • --:--</div>
        <div style="text-align:center; color:#64748b;" id="pinSuccessDate">--</div>
        <div style="display:flex; flex-direction:column; gap:4px; margin-top:14px; align-items:center; color:#475569; font-size:14px;">
          <div>Colaborador: <strong id="pinSuccessName">—</strong></div>
          <div>NSR: <strong id="pinSuccessNsr">—</strong></div>
        </div>
        <div class="pin-alert pin-alert-warning" id="pinSuccessWarning" style="display:none;"></div>
        <button type="button" class="btn-pin-secondary" id="btnPinSuccessReceipt" style="margin-top:16px; display:none;">
          <i class="bi bi-file-earmark-text"></i> Ver comprovante
        </button>
        <button type="button" class="btn-pin-submit" id="btnPinSuccessDone" style="margin-top:10px;">CONCLUIR</button>
      </div>
    </section>

    <!-- ===================== PIN: STEP-UP FACIAL ===================== -->
    <section id="pinStepupSection" class="pin-section" style="display:none;">
      <div class="pin-card">
        <h2 class="pin-h1"><i class="bi bi-shield-lock"></i> Validação extra</h2>
        <p class="pin-sub" id="pinStepupMsg">Enquadre seu rosto no círculo. Tiramos a foto automaticamente.</p>
        <div class="pin-camera-wrap pin-autocap" id="pinCameraWrap">
          <video id="pinStepupVideo" autoplay playsinline muted></video>
          <!-- Guia oval: muda de cor conforme o estado da detecção.
               M4: preserveAspectRatio padrão ("xMidYMid meet") mantém o oval
               circular mesmo se o container estiver distorcido em landscape. -->
          <div class="pin-face-guide" id="pinFaceGuide" aria-hidden="true">
            <svg viewBox="0 0 100 100">
              <ellipse cx="50" cy="50" rx="42" ry="48" />
            </svg>
          </div>
          <!-- Pill com status em tempo real para o usuário -->
          <div class="pin-capture-status" id="pinCaptureStatus" role="status" aria-live="polite">
            <span class="pin-capture-dot"></span>
            <span class="pin-capture-text">Iniciando câmera…</span>
          </div>
          <!-- Overlay de contagem regressiva antes do clique automático -->
          <div class="pin-capture-countdown" id="pinCaptureCountdown" aria-hidden="true"></div>
          <!-- Flash branco no momento da captura -->
          <div class="pin-capture-flash" id="pinCaptureFlash" aria-hidden="true"></div>
          <!-- Spinner/submitting overlay após captura. Spinner é decorativo
               (aria-hidden), mas o texto fica acessível como status. -->
          <div class="pin-capture-sending" id="pinCaptureSending">
            <div class="pin-capture-spinner" aria-hidden="true"></div>
            <span role="status" aria-live="polite">Enviando…</span>
          </div>
        </div>
        <button type="button" class="btn-pin-link" id="btnPinStepupManual" style="display:none;">
          <i class="bi bi-camera"></i> Tirar foto
        </button>
        <button type="button" class="btn-pin-link" id="btnPinStepupCancel">Cancelar</button>
      </div>
    </section>

    <!-- ===================== CAMERA SECTION (oculta por padrão) ===================== -->
    <div id="cameraSection">
      <button type="button" class="btn-back-home" id="btnBackHome">
        <i class="bi bi-arrow-left"></i> Voltar
      </button>

    <!-- Etapa 1: Captura -->
    <form id="pointForm" class="card-clean card-form mx-auto">
      <input type="hidden" id="cpf" name="cpf">

      <!-- Câmera ocupando 100% do card -->
      <div class="video-wrap">
          <video id="video" autoplay playsinline webkit-playsinline muted></video>
          <img id="snapshot" alt="Pré-visualização da foto">
          <canvas id="faceOverlay" class="face-overlay" aria-hidden="true"></canvas>
          <div class="face-guide" aria-hidden="true" id="staticFaceGuide">
            <div class="face-ring"></div>
          </div>

          <!-- Botão de trocar câmera -->
          <div class="floating switch" id="flipCamCtrl" role="group" aria-label="Trocar câmera">
            <button
              type="button"
              id="btnFlipCam"
              class="btn-fab"
              title="Trocar câmera"
              aria-label="Trocar câmera">
              <i class="bi bi-arrow-repeat"></i>
              <span class="visually-hidden">Trocar câmera</span>
            </button>
          </div>
          <!-- Removido: duplicado de dica e botão de captura flutuante -->
      </div>

      <!-- STATUS OVERLAY (Sobre a câmera) -->
      <div class="camera-status-overlay">
        <div id="status">Aguardando câmera...</div>
      </div>

      <!-- MODO RÁPIDO OVERLAY (Rodapé sobre a câmera) -->
      <div class="camera-card-controls">
        <!-- Modo rápido -->
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" id="autoCaptureOverlay" checked>
          <label class="form-check-label" for="autoCaptureOverlay">Modo rápido (auto capturar)</label>
        </div>
      </div>

      <!-- Elementos originais mantidos mas ocultos (para compatibilidade) -->
      <div style="display: none;">
        <!-- Controles alternativos -->
        <div id="camControls" class="d-flex gap-2 mb-2 align-items-stretch d-none" hidden aria-hidden="true" style="display:none!important">
          <div class="btn-group w-100" role="group" aria-label="Selecionar câmera">
            <input type="radio" class="btn-check" name="camFacing" id="camFront" autocomplete="off" checked>
            <label class="btn btn-outline-secondary" for="camFront" title="Usar câmera frontal">
              <i class="bi bi-person-circle me-1"></i> Frontal
            </label>

            <input type="radio" class="btn-check" name="camFacing" id="camBack" autocomplete="off">
            <label class="btn btn-outline-secondary" for="camBack" title="Usar câmera traseira">
              <i class="bi bi-camera-video me-1"></i> Traseira
            </label>
          </div>
        </div>

        <div class="d-grid gap-2 mb-2">
          <button type="button" id="btnCapture" class="btn btn-outline-primary btn-lg d-none">
            <i class="bi bi-camera"></i> Capturar foto
          </button>
          <button type="button" id="btnRetake" class="btn btn-outline-secondary btn-lg d-none">
            <i class="bi bi-arrow-counterclockwise"></i> Tirar outra foto
          </button>
        </div>

        <!-- Modo rápido -->
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="autoCaptureSwitch" checked>
            <label class="form-check-label" for="autoCaptureSwitch">Modo rápido (auto capturar)</label>
          </div>
        </div>

        <input type="file" id="fileInput" accept="image/*" capture="user" class="form-control d-none" aria-label="Selecionar foto pela câmera do aparelho" hidden aria-hidden="true" style="display:none!important">
        <div id="camFallback" class="hint d-none" hidden aria-hidden="true" style="display:none!important">
          Câmera do navegador indisponível.
          <button type="button" id="btnChooseFile" class="btn btn-outline-primary btn-sm ms-2">Abrir câmera do celular</button>
        </div>

        <div class="sticky-actions mt-2">
        <button class="btn btn-success-gradient w-100 d-none" type="button" id="btnReg" hidden aria-hidden="true" tabindex="-1">
          <span class="label"><i class="bi bi-check2-circle me-1"></i> Continuar</span>
          <span class="spinner-border spinner-border-sm d-none ms-2" role="status" aria-hidden="true"></span>
        </button>
        <small>
          <div id="statusHidden" class="text-muted mt-2" aria-live="polite" style="display:none"></div>
        </small>
        </div>
      </div> <!-- Fecha div style="display: none" -->
    </form>

    <!-- Botões de Controle FORA do Card (abaixo da câmera) -->
    <div class="camera-external-controls mx-auto mt-3">
      <!-- Botão Capturar (só quando modo rápido OFF) -->
      <button type="button" id="btnCaptureExternal" class="btn btn-primary btn-lg w-100 mb-2 d-none">
        <i class="bi bi-camera"></i> Capturar foto
      </button>
      
      <!-- Botão Tirar outra (após captura) -->
      <button type="button" id="btnRetakeExternal" class="btn btn-outline-secondary btn-lg w-100 d-none">
        <i class="bi bi-arrow-counterclockwise"></i> Tirar outra foto
      </button>
    </div>

    </div><!-- /#cameraSection -->
  </main>

  <!-- Fullscreen Camera Mode -->
  <div id="fullscreenCamera" class="fullscreen-camera">
    <video id="fullscreenVideo" autoplay playsinline webkit-playsinline muted></video>
    <canvas id="fullscreenFaceOverlay" class="face-overlay" aria-hidden="true"></canvas>
    <div class="face-guide" aria-hidden="true">
      <div class="face-ring" id="fullscreenFaceRing"></div>
    </div>

    <!-- Top overlay -->
    <div class="camera-overlay-top">
      <div class="d-flex justify-content-between align-items-start w-100">
        <!-- Voltar -->
        <button type="button" id="btnExitFullscreen" class="fs-back-btn" aria-label="Voltar">
          <i class="bi bi-arrow-left"></i>
        </button>
        <!-- Data e Hora -->
        <div class="fs-datetime">
          <div class="fs-time" id="fsClock">--:--:--</div>
          <div class="fs-date" id="fsDate">--</div>
        </div>
        <!-- Trocar câmera -->
        <button type="button" id="btnFullscreenFlip" class="fs-back-btn" aria-label="Trocar câmera">
          <i class="bi bi-arrow-repeat"></i>
        </button>
      </div>
    </div>

    <!-- Instrução central -->
    <div class="fs-instruction-float" id="fullscreenInstruction">
      Centralize seu rosto na área indicada
    </div>

    <!-- Status de detecção facial -->
    <div class="fs-face-status" id="fsFaceStatus">
      <div class="fs-face-status-icon" id="fsFaceIcon">
        <div class="spinner-border spinner-border-sm text-light" role="status"></div>
      </div>
      <span id="fsFaceText">Iniciando detecção facial...</span>
    </div>

    <!-- Bottom overlay -->
    <div class="camera-overlay-bottom">
      <button type="button" id="btnFullscreenCapture" class="capture-button capture-button-secondary" aria-label="Capturar foto manualmente">
        <i class="bi bi-camera-fill"></i>
      </button>
      <small class="text-white-50" style="font-size:.72rem;">ou toque para capturar manualmente</small>
    </div>

    <!-- Overlay de processamento automático (face auth) -->
    <div id="fsProcessingOverlay" style="display:none;position:absolute;top:0;right:0;bottom:0;left:0;inset:0;z-index:50;background:rgba(0,0,0,0.75);flex-direction:column;align-items:center;justify-content:center;gap:1rem;">
      <div class="spinner-border text-light" style="width:3rem;height:3rem;" role="status"></div>
      <div id="fsProcessingText" style="color:#fff;font-size:1.15rem;font-weight:600;text-align:center;padding:0 1.5rem;"></div>
    </div>
  </div>

  <!-- Modal LGPD - Termo de Consentimento -->
  <div class="modal fade" id="lgpdConsentModal" tabindex="-1" aria-labelledby="lgpdConsentLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="lgpdConsentLabel">
            <i class="bi bi-shield-check me-2"></i>
            Termo de Consentimento - LGPD
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Lei Geral de Proteção de Dados (LGPD - Lei 13.709/2018)</strong>
          </div>
          
          <h6 class="fw-bold mb-3">Consentimento para Coleta de Dados Biométricos</h6>
          
          <p>Para utilizar o sistema DEEDO Ponto, precisamos de sua autorização expressa para:</p>
          
          <ul class="list-group list-group-flush mb-3">
            <li class="list-group-item">
              <i class="bi bi-camera text-primary me-2"></i>
              <strong>Coletar e armazenar sua fotografia facial</strong> para autenticação no registro de ponto
            </li>
            <li class="list-group-item">
              <i class="bi bi-geo-alt text-primary me-2"></i>
              <strong>Coletar sua localização (GPS)</strong> para validar o local de trabalho
            </li>
            <li class="list-group-item">
              <i class="bi bi-fingerprint text-primary me-2"></i>
              <strong>Processar dados biométricos</strong> exclusivamente para fins de controle de jornada
            </li>
          </ul>
          
          <h6 class="fw-bold mb-2">Informações Importantes:</h6>
          
          <div class="card bg-light mb-3">
            <div class="card-body">
              <ul class="mb-0 small">
                <li>Seus dados serão armazenados de forma <strong>segura e criptografada</strong></li>
                <li>Retenção: <strong>5 anos</strong> após encerramento do contrato de trabalho</li>
                <li>Finalidade: <strong>Controle de jornada</strong> conforme CLT e Portaria MTP 671/2021</li>
                <li>Você pode <strong>revogar este consentimento</strong> a qualquer momento</li>
                <li>A revogação pode impactar sua capacidade de usar o sistema eletrônico</li>
                <li>Você tem direito de <strong>acessar, corrigir e solicitar exclusão</strong> de seus dados</li>
              </ul>
            </div>
          </div>
          
          <h6 class="fw-bold mb-2">Seus Direitos (LGPD):</h6>
          
          <div class="row g-2 mb-3">
            <div class="col-6">
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-check-circle text-success"></i>
                <small>Acesso aos seus dados</small>
              </div>
            </div>
            <div class="col-6">
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-check-circle text-success"></i>
                <small>Correção de dados</small>
              </div>
            </div>
            <div class="col-6">
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-check-circle text-success"></i>
                <small>Portabilidade</small>
              </div>
            </div>
            <div class="col-6">
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-check-circle text-success"></i>
                <small>Revogação de consentimento</small>
              </div>
            </div>
          </div>
          
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="lgpdAccept" required>
            <label class="form-check-label fw-semibold" for="lgpdAccept">
              Li e aceito os termos acima. Autorizo a coleta e tratamento de meus dados biométricos 
              (fotografia facial e geolocalização) para fins de registro eletrônico de ponto.
            </label>
          </div>
          
          <hr>
          
          <small class="text-muted">
            <i class="bi bi-file-text me-1"></i>
            <a href="<?= esc($appBase) ?>/../docs/LGPD_PRIVACY_POLICY.md" target="_blank">
              Leia a Política de Privacidade completa
            </a>
          </small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" id="lgpdDecline">
            Não Aceito
          </button>
          <button type="button" class="btn btn-primary" id="lgpdConfirm" disabled>
            <i class="bi bi-check-circle me-1"></i>
            Aceito e Concordo
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal (Etapa 2: Confirmação) -->
  <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header border-0 pb-0 confirm-modal-header">
          <div class="d-flex w-100 justify-content-between align-items-start">
            <div class="me-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-clipboard-check fs-4" aria-hidden="true"></i>
                <h5 class="modal-title mb-0" id="confirmModalLabel">Revisar registro</h5>
              </div>
              <small class="text-muted">Digite seu CPF para registrar</small>
            </div>

            <div class="d-flex align-items-center gap-2">
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
          </div>
        </div>

        <div class="modal-body pt-3">
          <div class="row g-4 align-items-start">
            <img id="confirmPhoto" src="" alt="" style="display:none;" aria-hidden="true">
            <div id="capturedFaceBadge" style="display:none;" aria-hidden="true">
              <i id="capturedFaceBadgeIcon"></i>
              <span id="capturedFaceBadgeText"></span>
            </div>

            <!-- Resumo + CPF -->
            <div class="col-12">
              <!-- Resumo -->
              <div class="summary-list px-2 confirm-summary">
                <div class="row align-items-center d-none" id="confirmCollaboratorRow">
                  <div class="col-auto">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width:28px;height:28px">
                      <i class="bi bi-person-circle fs-6"></i>
                    </span>
                  </div>
                  <div class="col">
                    <div id="confirmCollaboratorName" class="fw-semibold fs-6">—</div>
                    <div id="confirmCollaboratorAction" class="text-muted small">Confirme seu CPF para carregar os dados.</div>
                  </div>
                </div>
                <div class="row g-2">
                  <div class="col-6">
                    <div class="confirm-meta-card">
                      <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width:28px;height:28px">
                        <i class="bi bi-calendar3 fs-6"></i>
                      </span>
                      <div>
                        <div class="confirm-meta-label">Data</div>
                        <div id="confirmDate" class="fw-semibold fs-6">—</div>
                      </div>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="confirm-meta-card">
                      <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width:28px;height:28px">
                        <i class="bi bi-clock fs-6"></i>
                      </span>
                      <div>
                        <div class="confirm-meta-label">Hora</div>
                        <div id="confirmTime" class="fw-semibold fs-6">—</div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- face status row removido da UI; elementos mantidos ocultos para compatibilidade com JS -->
                <div id="confirmFaceRow" style="display:none;height:0;overflow:hidden;margin:0;padding:0" aria-hidden="true">
                  <span id="confirmFaceIcon"></span>
                  <span id="confirmFaceStatus"></span>
                </div>
              </div>
              <hr class="my-3">

              <!-- CPF Form -->
              <form id="cpfForm" class="w-100" onsubmit="return false;" autocomplete="on">
                <label for="cpfModal" class="form-label mb-1 fw-semibold">CPF</label>
                <input
                  type="text"
                  class="form-control form-control-lg"
                  id="cpfModal"
                  name="cpf"
                  required
                  autocomplete="off"
                  inputmode="numeric"
                  maxlength="14"
                  placeholder="000.000.000-00"
                  aria-describedby="cpfModalFeedback">
                <div id="cpfModalFeedback" class="invalid-feedback"></div>
                <div id="cpfInputHint" class="input-hint mt-1">Digite seu CPF e toque em "Bater ponto".</div>
              </form>
            </div>
          </div>
        </div>

        <div class="modal-footer border-0 pt-0 flex-column align-items-stretch gap-0" style="padding-bottom:1.25rem">

          <!-- Seção de cadastro facial (visível apenas quando rosto não foi reconhecido) -->
          <div id="faceEnrollRecommend" style="display:none;width:100%;margin-bottom:0.5rem;">

            <!-- Card de aviso -->
            <div style="
              background:rgba(245,158,11,0.08);border:1.5px solid rgba(245,158,11,0.35);
              border-radius:12px;padding:0.75rem 1rem;margin-bottom:0.75rem;
            ">
              <div style="font-weight:700;color:#d97706;font-size:0.95rem;margin-bottom:0.25rem;">
                ⚠️ Rosto não identificado
              </div>
              <div style="color:#6b7280;font-size:0.82rem;line-height:1.45;">
                Digite seu CPF e escolha <b>"Cadastro facial"</b> ou <b>"Bater ponto com CPF"</b>. Recomendamos o cadastro facial para um registro mais rápido e seguro.
              </div>
            </div>

            <!-- Botão primário: fazer cadastro facial -->
            <button type="button" id="btnRefazerCadastroFacial"
              style="width:100%;padding:1rem 1.1rem;margin-bottom:0.1rem;
                     background:linear-gradient(135deg,#16a34a,#15803d);
                     border:none;border-radius:14px;color:#fff;
                     font-size:1rem;font-weight:700;cursor:pointer;
                     display:flex;align-items:center;justify-content:center;gap:0.9rem;
                     box-shadow:0 4px 14px rgba(22,163,74,0.3);
                     transition:opacity .15s;"
              onmouseover="this.style.opacity='0.88'"
              onmouseout="this.style.opacity='1'">
              <span style="
                width:42px;height:42px;border-radius:50%;flex-shrink:0;
                background:rgba(255,255,255,0.18);
                display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-camera-fill" style="font-size:1.25rem"></i>
              </span>
              <span style="display:flex;flex-direction:column;align-items:flex-start;gap:0.1rem;">
                <span>Cadastro facial</span>
                <span style="font-size:0.73rem;font-weight:400;opacity:0.85;letter-spacing:0.01em;">
                  ✦ Recomendado — ative o reconhecimento automático
                </span>
              </span>
            </button>

            <!-- Divider -->
            <div style="
              display:flex;align-items:center;gap:0.6rem;
              color:#9ca3af;font-size:0.79rem;margin:0.9rem 0 0.6rem;
            ">
              <div style="flex:1;height:1px;background:#e5e7eb;"></div>
              <span>ou registre com seu CPF</span>
              <div style="flex:1;height:1px;background:#e5e7eb;"></div>
            </div>
          </div>

          <!-- Botão bater ponto -->
          <button type="button" id="btnConfirmSubmit" class="btn btn-primary btn-lg w-100 mb-2">
            <span class="label" id="btnConfirmSubmitLabel"><i class="bi bi-check2-circle me-1"></i> Bater ponto</span>
            <span class="spinner-border spinner-border-sm d-none ms-2" role="status" aria-hidden="true"></span>
          </button>
        </div>
      </div>
    </div>
  </div>

<!-- Modal Final: Confirmação de Registro -->
<div class="modal fade" id="finalConfirmModal" tabindex="-1" aria-labelledby="finalConfirmModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content modal-review">
      <div class="modal-header d-flex flex-column flex-sm-row align-items-center align-items-sm-start justify-content-between gap-2 gap-sm-3 py-3 py-sm-4 px-3 px-sm-4">
        <div class="d-flex flex-column align-items-center align-items-sm-start text-center text-sm-start w-100">
          <div class="badge bg-white text-success fw-semibold text-uppercase mb-2">Confirmação</div>
          <h5 class="modal-title mb-1 text-white" id="finalConfirmModalLabel">Registrar de ponto</h5>
          <p class="modal-subtitle text-white-50">Confira os dados antes de concluir.</p>
        </div>
        <button type="button" class="btn-close btn-close-white ms-sm-2" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body gap-3 pt-3 pb-4">
        <div class="container-fluid">
          <div class="row g-3 flex-column flex-lg-row">
            <div class="col-12 col-lg-4">
              <div class="review-summary-card h-100">
                <div class="d-flex align-items-center gap-3 mb-3">
                  <div class="icon-ring">
                    <i class="bi bi-person-workspace"></i>
                  </div>
                  <div>
                    <div class="text-uppercase text-muted small fw-semibold">Colaborador</div>
                    <div class="fs-5 fw-semibold mb-0" id="finalConfirmName">—</div>
                    <div class="text-muted" id="finalConfirmCpf">—</div>
                  </div>
                </div>
                <p class="text-muted small mb-0">Confirme se o colaborador exibido está correto antes de finalizar o registro.</p>
              </div>
            </div>
            <div class="col-12 col-lg-8">
              <div class="review-summary-card h-100 alert alert-success mb-0" id="finalConfirmActionCard">
                <div class="d-flex align-items-center gap-3 mb-3">
                  <div class="icon-ring">
                    <i class="bi bi-arrow-right-circle" id="finalConfirmActionIcon"></i>
                  </div>
                  <div>
                    <div class="text-uppercase text-muted small fw-semibold">Tipo de registro</div>
                    <div class="fs-5 fw-semibold mb-1" id="finalConfirmAction">Entrada</div>
                    <p class="text-muted small mb-0">Verifique se o tipo (entrada ou saída) e o horário estão corretos.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="d-flex justify-content-center my-3">
            <div class="review-timer fw-semibold">
              Tempo para confirmar: <span id="finalConfirmTimer">00:30</span>
            </div>
          </div>
          <div id="finalConfirmTimeout" class="review-timeout d-none mt-3 text-center">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Tempo esgotado. Clique em “Bater ponto” novamente para reiniciar o processo de confirmação.
          </div>
        </div>
      </div>
      <div class="modal-footer review-footer">
        <button type="button" class="btn btn-success w-100 d-flex align-items-center justify-content-center gap-2" id="btnFinalizeConfirm">
          <span class="label d-inline-flex align-items-center gap-2"><i class="bi bi-check2-circle"></i>Registrar ponto agora</span>
          <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
        </button>
        <button type="button" class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2" id="btnFinalizeCancel">
          <i class="bi bi-arrow-left"></i>
          <span>Voltar e revisar</span>
        </button>
      </div>
    </div>
  </div>
</div>

  <script>
    // Ao fechar o modal, limpar a foto e disponibilizar a câmera para nova captura
    document.addEventListener('DOMContentLoaded', function() {
      const modalEl = document.getElementById('confirmModal');
      if (!modalEl) return;

      // Pausa o guia/detector ao abrir o modal para poupar CPU
      modalEl.addEventListener('show.bs.modal', function() {
        try {
          if (typeof cancelFaceGuide === 'function') cancelFaceGuide();
        } catch {}
      });

      modalEl.addEventListener('hidden.bs.modal', function() {
        // Não executar cleanup se o cadastro facial inline está ativo
        if (window._autoCheckinAfterEnroll || document.getElementById('faceEnrollOverlay')) return;
        try {
          if (typeof capturedDataUrl !== 'undefined') capturedDataUrl = null;
        } catch {}

        const snapshot = document.getElementById('snapshot');
        const video = document.getElementById('video');
        const btnRetake = document.getElementById('btnRetake');
        const statusEl = document.getElementById('status');

        if (snapshot) {
          snapshot.src = '';
          snapshot.style.display = 'none';
        }
        if (video) {
          video.style.display = 'block';
        }
        if (btnRetake) {
          btnRetake.classList.add('d-none');
        }
        if (statusEl) {
          statusEl.textContent = 'Aponte a câmera e toque em Capturar.';
        }
        if (typeof backToStepPhotoUI === 'function') {
          backToStepPhotoUI();
        }
        try {
          if (typeof stream !== 'undefined' && !stream && typeof startWebcam === 'function') {
            startWebcam('user');
          }
        } catch {}

        // Retoma o guia/detector após fechar o modal
        try {
          if (typeof startFaceGuide === 'function') startFaceGuide();
        } catch {}

        resetConfirmStage();
      });
    });
  </script>

  <!-- Toast/banners -->
  <div id="toastify" class="toastify" role="status" aria-live="polite" aria-atomic="true"></div>

  <!-- Banner de Instalação PWA (Safari/iOS) -->
  <div id="installBanner" class="install-banner d-none" role="alert">
    <div class="install-banner-content">
      <div class="d-flex align-items-start gap-3">
        <div class="install-icon">
          <i class="bi bi-download"></i>
        </div>
        <div class="flex-grow-1">
          <div class="fw-bold mb-1">Instalar DEEDO Ponto</div>
          <div class="install-steps" id="installSteps">
            <!-- Preenchido via JavaScript -->
          </div>
        </div>
        <button type="button" class="btn-close" id="closeInstallBanner" aria-label="Fechar"></button>
      </div>
    </div>
  </div>

  <script>
    // PWA Install Banner para Safari/iOS
    (function() {
      const installBanner = document.getElementById('installBanner');
      const installSteps = document.getElementById('installSteps');
      const closeBtn = document.getElementById('closeInstallBanner');

      if (!installBanner || !installSteps) return;

      // Verifica se já foi instalado ou se usuário já fechou
      const hasSeenBanner = localStorage.getItem('pwa-install-banner-closed');
      const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true;

      if (isStandalone || hasSeenBanner === 'true') {
        return; // Já instalado ou usuário não quer ver
      }

      // Detecta browser
      const ua = navigator.userAgent.toLowerCase();
      // iPad OS 13+ reporta-se como "Macintosh" — detectar via maxTouchPoints
      const isIOS = /iphone|ipad|ipod/.test(ua)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      const isSafari = (/safari/.test(ua) && !/chrome/.test(ua) && !/android/.test(ua))
        // Chrome iOS e Firefox iOS rodam sobre WebKit mas não são Safari
        || (isIOS && !/crios|fxios|opios|mercury/.test(ua));
      const isChrome = /chrome/.test(ua) && !/edge/.test(ua) && !/crios/.test(ua);
      const isEdge = /edg/.test(ua);

      let shouldShow = false;
      let steps = '';

      if (isIOS && isSafari) {
        // Safari iOS
        shouldShow = true;
        steps = `
          <div class="mb-1">Use este app sem internet! Instale agora:</div>
          <ol>
            <li>Toque no botão <strong>Compartilhar</strong> <i class="bi bi-box-arrow-up"></i> (abaixo)</li>
            <li>Role para baixo e toque em <strong>"Adicionar à Tela de Início"</strong></li>
            <li>Toque em <strong>"Adicionar"</strong></li>
          </ol>
        `;
      } else if (isSafari && !isIOS) {
        // Safari macOS
        shouldShow = true;
        steps = `
          <div class="mb-1">Instale o app para uso rápido:</div>
          <ol>
            <li>Clique em <strong>Arquivo</strong> no menu</li>
            <li>Selecione <strong>"Adicionar à Dock"</strong></li>
          </ol>
          <small class="d-block mt-1 opacity-75">Ou use Safari 17.4+ para suporte completo</small>
        `;
      } else if (isChrome || isEdge) {
        // Chrome/Edge - geralmente tem botão automático, mas podemos reforçar
        shouldShow = false; // Chrome já mostra automaticamente
      }

      if (shouldShow) {
        installSteps.innerHTML = steps;

        // Mostra banner após 3 segundos
        setTimeout(() => {
          installBanner.classList.remove('d-none');
        }, 3000);
      }

      // Fechar banner
      if (closeBtn) {
        closeBtn.addEventListener('click', () => {
          installBanner.classList.add('d-none');
          localStorage.setItem('pwa-install-banner-closed', 'true');
        });
      }
    })();

    // Service Worker com auto-atualização e comunicação
    // Política 2026-05: PWA desatualizado é forçado a atualizar (sem botão "Depois").
    // O módulo pwa-update.js cuida do toast + reload com guards de segurança
    // (não recarrega no meio de uma captura de PIN/face/checkin). O banner antigo
    // foi removido em favor desse mecanismo, que delega ao pwaUpdate.forceReload.
    function showSwUpdateBanner(/* newWorker */) {
      if (window.pwaUpdate && typeof window.pwaUpdate.forceReload === 'function') {
        window.pwaUpdate.forceReload();
      } else {
        // Fallback se pwa-update.js não tiver carregado ainda — recarrega cru
        // após pequeno delay pra dar tempo do usuário ver alguma indicação.
        setTimeout(function () { window.location.reload(); }, 1500);
      }
    }

    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('<?= esc($appBase) ?>/sw.js')
        .then(reg => {
          console.log('[PWA] Service Worker registrado:', reg.scope);

          // Auto-atualização: checa ao voltar à aba em vez de polling de 60s.
          // Reduz consumo de dados em mobile e evita updates em abas idle.
          let lastUpdateCheck = 0;
          const checkForUpdate = () => {
            const now = Date.now();
            if (now - lastUpdateCheck < 30000) return; // throttle 30s
            lastUpdateCheck = now;
            reg.update().catch(() => {});
          };
          document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') checkForUpdate();
          });
          window.addEventListener('focus', checkForUpdate);

          // Detecta nova versão instalando
          reg.addEventListener('updatefound', () => {
            const newWorker = reg.installing;
            if (!newWorker) return;
            newWorker.addEventListener('statechange', () => {
              if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                // Nova versão disponível — banner não-bloqueante (substitui confirm()).
                try { showSwUpdateBanner(newWorker); }
                catch (_) { /* silencioso — usuário pega na próxima abertura */ }
              }
            });
          });
        })
        .catch(err => console.error('[PWA] Falha ao registrar Service Worker:', err));

      // Recebe mensagens do Service Worker
      navigator.serviceWorker.addEventListener('message', (event) => {
        console.log('[PWA] Mensagem do SW:', event.data);
        if (event.data && event.data.type === 'SYNC_SUCCESS') {
          const count = event.data.count || 0;
          const failed = event.data.failed || 0;
          if (failed > 0) {
            toast('warning', 'Sincronização parcial', `${count} ponto(s) sincronizado(s) e ${failed} pendente(s) para nova tentativa.`, []);
          } else {
            toast('success', 'Sincronização completa', `${count} ponto(s) enviado(s) com sucesso!`, []);
          }
          updatePendingCount();
        }
        // C3: SW informa que sessão expirou durante background sync — mostra
        // banner persistente. Se nenhuma aba estiver aberta no momento, o SW
        // grava sentinela em localStorage que o boot lê na próxima abertura.
        if (event.data && event.data.type === 'SESSION_EXPIRED') {
          const remaining = (event.data.remaining > 0) ? event.data.remaining : 0;
          if (remaining > 0 && typeof showStaleSessionBanner === 'function') {
            showStaleSessionBanner(remaining);
          }
          try { localStorage.setItem('ponto_session_expired_pending', String(Date.now())); } catch (_) {}
        }
      });

      // C3: na abertura do app, se há sentinela de session_expired do background
      // sync anterior (sem aba aberta no momento), mostra banner com contagem
      // atual da fila pendente.
      (async () => {
        try {
          const sentinel = localStorage.getItem('ponto_session_expired_pending');
          if (!sentinel) return;
          // Sentinela "fresca" (<24h) — verifica se ainda há items pendentes
          if (Date.now() - parseInt(sentinel, 10) > 24 * 60 * 60 * 1000) {
            localStorage.removeItem('ponto_session_expired_pending');
            return;
          }
          const cnt = (typeof getPendingCount === 'function') ? await getPendingCount() : 0;
          if (cnt > 0 && typeof showStaleSessionBanner === 'function') {
            showStaleSessionBanner(cnt);
          } else {
            // Fila vazia — sentinela antigo, limpa
            localStorage.removeItem('ponto_session_expired_pending');
          }
        } catch (_) {}
      })();
    }
  </script>

  <!-- Script LGPD - Termo de Consentimento -->
  <script>
    (function() {
      const lgpdModal = document.getElementById('lgpdConsentModal');
      const lgpdAcceptCheckbox = document.getElementById('lgpdAccept');
      const lgpdConfirmBtn = document.getElementById('lgpdConfirm');
      const lgpdDeclineBtn = document.getElementById('lgpdDecline');
      
      if (!lgpdModal || !lgpdAcceptCheckbox || !lgpdConfirmBtn) return;
      
      // Habilita botão apenas quando checkbox marcado
      lgpdAcceptCheckbox.addEventListener('change', () => {
        lgpdConfirmBtn.disabled = !lgpdAcceptCheckbox.checked;
      });
      
      // Confirmar consentimento
      lgpdConfirmBtn.addEventListener('click', () => {
        localStorage.setItem('lgpd-consent-given', 'true');
        localStorage.setItem('lgpd-consent-date', new Date().toISOString());
        const modal = bootstrap.Modal.getInstance(lgpdModal);
        if (modal) modal.hide();
        
        toast('success', 'Consentimento registrado', 'Obrigado por aceitar os termos.', [
          '✅ Seus dados serão tratados com segurança.',
          '📋 Você pode acessar seus dados a qualquer momento.'
        ]);
      });
      
      // Recusar consentimento
      lgpdDeclineBtn.addEventListener('click', () => {
        if (confirm('Sem o consentimento, você não poderá usar o sistema eletrônico de ponto.\n\nDeseja realmente recusar?')) {
          const modal = bootstrap.Modal.getInstance(lgpdModal);
          if (modal) modal.hide();
          
          toast('warning', 'Consentimento não concedido', 'Você não poderá registrar ponto eletronicamente.', [
            '⚠️ Contate o RH para método alternativo de registro.',
            '📞 Você pode aceitar os termos a qualquer momento.'
          ]);
          
          // Desabilita funcionalidades
          document.getElementById('btnCapture')?.setAttribute('disabled', 'true');
          document.getElementById('btnReg')?.setAttribute('disabled', 'true');
          document.getElementById('autoCaptureSwitch')?.setAttribute('disabled', 'true');
        }
      });
      
      // Verificar se já aceitou
      const hasConsent = localStorage.getItem('lgpd-consent-given') === 'true';
      
      if (!hasConsent) {
        // Mostra modal após 2 segundos
        setTimeout(() => {
          const modal = new bootstrap.Modal(lgpdModal);
          modal.show();
        }, 2000);
      } else {
        console.log('[LGPD] Consentimento já registrado em:', localStorage.getItem('lgpd-consent-date'));
      }
    })();
  </script>

  <!-- Fallback para iOS/Firefox: MediaPipe Face Detection (CDN) -->
  <script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/face_detection.js"></script>

  <script>
    const APP_BASE = (document.querySelector('meta[name="app-base"]').getAttribute('content') || '/').replace(/\/+$/, '');
    // M6: prefere api-base explícito (renderizado pelo PHP). Fallback para o
    // replace histórico mantém compat com deploys antigos sem a meta tag.
    const ROOT_BASE = (document.querySelector('meta[name="api-base"]')?.getAttribute('content') || APP_BASE.replace(/\/public$/, '')).replace(/\/+$/, '');

    const apiUrl = (ROOT_BASE || '') + '/api/checkin.php';
    const bulkUrl = (ROOT_BASE || '') + '/api/checkin_bulk.php';

    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas') || (() => {
      const c = document.createElement('canvas');
      c.id = 'canvas';
      c.className = 'd-none';
      document.body.appendChild(c);
      return c;
    })();
    const snapshot = document.getElementById('snapshot');
    const statusEl = document.getElementById('status');
    const btnReg = document.getElementById('btnReg');
    const btnRetake = document.getElementById('btnRetake');
    const btnCapture = document.getElementById('btnCapture');
    const btnFlipCam = document.getElementById('btnFlipCam');
    const fileInput = document.getElementById('fileInput');
    const camFallback = document.getElementById('camFallback');
    const btnChooseFile = document.getElementById('btnChooseFile');
    const camControls = document.getElementById('camControls');
    const camFront = document.getElementById('camFront');
    const camBack = document.getElementById('camBack');
    const staticFaceGuide = document.getElementById('staticFaceGuide');
    const autoCaptureSwitch = document.getElementById('autoCaptureSwitch');

    // Etapas UI (removidas no novo design, mas mantidas para compatibilidade)
    const stepPhoto = null; // document.getElementById('stepPhoto');
    const stepConfirm = null; // document.getElementById('stepConfirm');

    // Modal elements
    const confirmModalEl = document.getElementById('confirmModal');
    const confirmPhotoEl = document.getElementById('confirmPhoto');
    const confirmDateEl = document.getElementById('confirmDate');
    const confirmTimeEl = document.getElementById('confirmTime');
    const confirmLocEl = document.getElementById('confirmLoc');
    const btnConfirmSubmit = document.getElementById('btnConfirmSubmit');
    const btnRefazerCadastroFacial = document.getElementById('btnRefazerCadastroFacial');
    const btnModalRetake = document.getElementById('btnModalRetake');
    const cpfFormEl = document.getElementById('cpfForm');
    const confirmCollaboratorRow = document.getElementById('confirmCollaboratorRow');
    const confirmCollaboratorName = document.getElementById('confirmCollaboratorName');
    const confirmCollaboratorAction = document.getElementById('confirmCollaboratorAction');
    const confirmPreviewAlert = document.getElementById('confirmPreviewAlert');
    const confirmPreviewAlertIcon = document.getElementById('confirmPreviewAlertIcon');
    const confirmPreviewAlertText = document.getElementById('confirmPreviewAlertText');
    const btnConfirmLabel = btnConfirmSubmit?.querySelector('.label');
    const finalConfirmModalEl = document.getElementById('finalConfirmModal');
    const finalConfirmName = document.getElementById('finalConfirmName');
    const finalConfirmCpf = document.getElementById('finalConfirmCpf');
    const finalConfirmAction = document.getElementById('finalConfirmAction');
    const btnFinalizeConfirm = document.getElementById('btnFinalizeConfirm');
    const btnFinalizeCancel = document.getElementById('btnFinalizeCancel');
    const finalConfirmTimer = document.getElementById('finalConfirmTimer');
    const finalConfirmTimeout = document.getElementById('finalConfirmTimeout');

    // CPF (Etapa 2 - modal)
    let cpfInput = document.getElementById('cpfModal');
    let cpfFeedback = document.getElementById('cpfModalFeedback');
    let confirmStage = 'preview'; // preview -> confirmar CPF, confirm -> registrar ponto
    let previewData = null;
    let faceAuthMode = false; // true quando autenticado por reconhecimento facial (sem PIN)
    let faceRecognitionAttempted = false; // rosto foi tentado mas não reconhecido nesta sessão
    let enrollBeforeCheckin = false;      // flag: abrir enrollment antes de submeter o ponto
    let faceChallengeToken = null;
    let finalConfirmModalInstance = null;
    let finalizeTimerId = null;
    let finalizeDeadline = null;

    // Bootstrap Modal
    let confirmModalInstance = null;

    function getConfirmModal() {
      if (!confirmModalInstance && window.bootstrap?.Modal) {
        confirmModalInstance = new window.bootstrap.Modal(confirmModalEl, {
          backdrop: 'static',
          keyboard: true
        });
      }
      return confirmModalInstance;
    }

    // Overlay dinâmico
    const faceOverlay = document.getElementById('faceOverlay');
    const overlayCtx = faceOverlay.getContext?.('2d');

    // Detectores
    let faceDetector = null; // Nativo
    let mpFace = null; // MediaPipe (fallback)
    let mpBusy = false;
    let mpLastRect = null; // { type: 'norm'|'abs', x,y,width,height }
    let faceGuideRAF = null;
    let detecting = false;
    let smoothRect = null;
    let nativeTick = 0; // throttling nativo

    let stream = null;
    let currentFacing = 'user';
    let capturedDataUrl = null;

    // --- Reconhecimento facial (face-api.js) ---
    let faceApiReady = false;        // tinyFaceDetector pronto → loop de detecção pode iniciar
    let faceDescriptorReady = false; // todos os modelos prontos → extração de descriptor liberada
    let capturedDescriptor = null;
    let capturedFaceMatch = null;
    let capturedFaceDistance = null;

    // Definida aqui para ser acessível pelo loadFaceApi (que está num escopo externo ao closure
    // onde btnGoCheckin é declarado). Usa getElementById para não depender de variáveis de closure.
    // P3: se face-api demora >15s, _faceApiLoadTimedOut permite liberar o
    // botão mesmo sem detector. Usuário ainda pode bater ponto (se step-up
    // não for exigido) e recebe erro claro do backend no caso contrário.
    let _faceApiLoadTimedOut = false;
    // Mantém o último texto adaptativo para re-aplicar após loading/timeout.
    // Setado por pinUpdateCheckinIntent quando last_checkin carrega.
    let _checkinIntentText = 'Bater ponto';
    function updateCheckinButtonState() {
      const btn = document.getElementById('btnGoCheckin');
      if (!btn) return;
      const labelEl = btn.querySelector('.btn-checkin-label');
      const textEl  = btn.querySelector('#btnCheckinText');
      if (faceApiReady || _faceApiLoadTimedOut) {
        btn.disabled = false;
        // Preserva a estrutura do span para que pinUpdateCheckinIntent continue
        // funcionando. Só atualiza o textContent do span, não reescreve o HTML.
        if (textEl) textEl.textContent = _checkinIntentText;
        if (labelEl) labelEl.style.display = '';
        btn.style.opacity = '';
        btn.title = _faceApiLoadTimedOut && !faceApiReady
          ? 'Reconhecimento facial indisponível — check-in pode exigir validação extra'
          : '';
      } else {
        btn.disabled = true;
        if (textEl) textEl.textContent = 'Preparando câmera…';
        btn.style.opacity = '0.75';
        btn.title = 'Aguardando carregamento do reconhecimento facial';
      }
    }

    (async function loadFaceApi() {
      try {
        const modelUrl = (typeof APP_BASE !== 'undefined' ? APP_BASE : '') + '/models';

        // P3: timeout de segurança — se em 15s o tinyFaceDetector não carregou
        // (CDN lento, 3G ruim), libera o botão para o usuário pelo menos tentar
        // check-in. Se o backend exigir step-up facial, aí sim erro claro.
        const faceApiTimeoutId = setTimeout(() => {
          if (!faceApiReady) {
            console.warn('[FaceAPI] timeout — liberando botão sem face-api');
            _faceApiLoadTimedOut = true;
            updateCheckinButtonState();
          }
        }, 15000);

        // ETAPA 1: detector (menor, ~190 KB) — libera o loop de detecção imediatamente
        await faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl);
        faceApiReady = true;
        clearTimeout(faceApiTimeoutId);
        updateCheckinButtonState(); // ativa o botão assim que a câmera pode funcionar

        // Pre-warm e etapa 2 rodam em paralelo:
        // - Pre-warm: compila os shaders WebGL do tinyFaceDetector (~100-300ms no iOS)
        // - Etapa 2: carrega faceLandmark68TinyNet + faceRecognitionNet (~800 KB)
        // Ambos ocorrem enquanto o usuário posiciona o rosto, sem bloquear a câmera.
        const warmCanvas = document.createElement('canvas');
        warmCanvas.width = 224;
        warmCanvas.height = 224;
        warmCanvas.getContext('2d', { willReadFrequently: true });

        await Promise.all([
          // Pre-warm do pipeline WebGL — detectSingleFace retorna DetectSingleFaceTask,
          // não uma Promise. Encapsular em async IIFE para poder usar await corretamente.
          (async () => {
            try {
              await faceapi.detectSingleFace(warmCanvas, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.99 }));
              console.log('[FaceAPI] Pre-warm concluído');
            } catch (_) { /* canvas vazio — sem rosto para detectar, comportamento esperado */ }
          })(),

          // Etapa 2: modelos de landmarks + reconhecimento
          Promise.all([
            faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelUrl),
            faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)
          ]).then(() => {
            faceDescriptorReady = true;
            console.log('[FaceAPI] Todos os modelos carregados');
          })
        ]);

      } catch (e) {
        console.warn('[FaceAPI] Erro ao carregar modelos:', e);
        updateCheckinButtonState(); // mesmo com erro, libera o botão
      }
    })();

    async function extractDescriptor(sourceEl) {
      if (!faceApiReady) return null;
      try {
        const det = await faceapi
          .detectSingleFace(sourceEl, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.35 }))
          .withFaceLandmarks(true)
          .withFaceDescriptor();
        return det?.descriptor ? Array.from(det.descriptor) : null;
      } catch (e) {
        console.warn('[FaceAPI] Erro na extração:', e);
        return null;
      }
    }

    // Cria cópia do canvas com brilho/contraste normalizados para melhorar detecção em luz ruim
    function preprocessCanvasCopy(srcCanvas) {
      const dst = document.createElement('canvas');
      dst.width = srcCanvas.width;
      dst.height = srcCanvas.height;
      const ctx = dst.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(srcCanvas, 0, 0);

      const imageData = ctx.getImageData(0, 0, dst.width, dst.height);
      const data = imageData.data;
      const pixelCount = data.length / 4;

      // Calcula luminância média
      let totalLuma = 0;
      for (let i = 0; i < data.length; i += 4) {
        totalLuma += 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
      }
      const avgLuma = totalLuma / pixelCount;

      // Só processa se a imagem estiver muito escura ou muito clara
      if (avgLuma >= 85 && avgLuma <= 175) return dst;

      // Correção gamma para aproximar luminância ao alvo (128)
      const gamma = avgLuma > 1 ? Math.log(128 / 255) / Math.log(avgLuma / 255) : 1;
      const clampedGamma = Math.max(0.4, Math.min(2.5, gamma));

      // Tabela LUT para aplicação rápida
      const lut = new Uint8Array(256);
      for (let i = 0; i < 256; i++) {
        lut[i] = Math.min(255, Math.round(255 * Math.pow(i / 255, clampedGamma)));
      }

      for (let i = 0; i < data.length; i += 4) {
        data[i]     = lut[data[i]];
        data[i + 1] = lut[data[i + 1]];
        data[i + 2] = lut[data[i + 2]];
      }
      ctx.putImageData(imageData, 0, 0);
      console.log(`[FaceAPI] Pré-processamento: luma=${Math.round(avgLuma)}, gamma=${clampedGamma.toFixed(2)}`);
      return dst;
    }

    // Extração robusta com múltiplas tentativas e fallbacks progressivos
    // G5: Validacao de qualidade do descriptor (magnitude L2)
    function isDescriptorQualityOk(det, attemptLabel) {
      if (!det?.descriptor) return false;
      const desc = Array.from(det.descriptor);
      const magnitude = Math.sqrt(desc.reduce((s, v) => s + v * v, 0));
      if (magnitude < 0.5 || magnitude > 2.0) {
        console.warn(`[FaceAPI] ${attemptLabel}: descriptor rejeitado — magnitude=${magnitude.toFixed(3)} (esperado: 0.5-2.0)`);
        return false;
      }
      // Nota: detection score já é filtrado pelo scoreThreshold do TinyFaceDetector em cada tentativa.
      // Não adicionamos threshold extra aqui para evitar rejeitar descriptors válidos.
      return true;
    }

    async function extractDescriptorRobust(canvas) {
      if (!faceDescriptorReady) return null;

      const isMobile = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

      const attempt1Size = isMobile ? 224 : 320;
      const attempt1Thresh = isMobile ? 0.3 : 0.35;

      // Tentativa 1: rápida, adequada para mobile
      try {
        const det = await faceapi
          .detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions({ inputSize: attempt1Size, scoreThreshold: attempt1Thresh }))
          .withFaceLandmarks(true)
          .withFaceDescriptor();
        if (det?.descriptor && isDescriptorQualityOk(det, 'Tentativa 1')) {
          console.log(`[FaceAPI] Descriptor OK na tentativa 1 (inputSize=${attempt1Size}, score=${det.detection.score.toFixed(3)})`);
          return Array.from(det.descriptor);
        }
      } catch (e) { console.warn('[FaceAPI] Tentativa 1 falhou:', e); }

      // Tentativa 2: Canvas pré-processado (normalização de brilho) + resolução 320
      try {
        const processed = preprocessCanvasCopy(canvas);
        const det = await faceapi
          .detectSingleFace(processed, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.3 }))
          .withFaceLandmarks(true)
          .withFaceDescriptor();
        if (det?.descriptor && isDescriptorQualityOk(det, 'Tentativa 2')) {
          console.log('[FaceAPI] Descriptor OK na tentativa 2 (preprocessado, inputSize=320)');
          return Array.from(det.descriptor);
        }
      } catch (e) { console.warn('[FaceAPI] Tentativa 2 falhou:', e); }

      // Tentativa 3: inputSize 416 com threshold mais permissivo — último recurso
      try {
        const det = await faceapi
          .detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.25 }))
          .withFaceLandmarks(true)
          .withFaceDescriptor();
        if (det?.descriptor && isDescriptorQualityOk(det, 'Tentativa 3')) {
          console.log('[FaceAPI] Descriptor OK na tentativa 3 (inputSize=416, fallback)');
          return Array.from(det.descriptor);
        }
      } catch (e) { console.warn('[FaceAPI] Tentativa 3 falhou:', e); }

      console.warn('[FaceAPI] Falhou em todas as tentativas de extração de descriptor');
      return null;
    }

    function compareFaces(desc1, storedDescriptors, threshold = 0.6) {
      if (!desc1 || !storedDescriptors?.length) return { match: null, distance: null };
      let bestDist = Infinity;
      for (const stored of storedDescriptors) {
        const a = new Float32Array(desc1);
        const b = new Float32Array(stored);
        const dist = faceapi.euclideanDistance(a, b);
        if (dist < bestDist) bestDist = dist;
      }
      return { match: bestDist < threshold, distance: Math.round(bestDist * 1000) / 1000 };
    }

    // Auto captura (modo rápido)
    let autoCaptureEnabled = true;

    function updateCaptureButtonVisibility() {
      if (!btnCapture || !autoCaptureSwitch) return;
      const quick = !!autoCaptureSwitch.checked;
      btnCapture.classList.toggle('d-none', quick);
    }
    autoCaptureSwitch?.addEventListener('change', () => {
      autoCaptureEnabled = !!autoCaptureSwitch.checked;
      if (!autoCaptureEnabled) lastOkTs = 0;
      updateCaptureButtonVisibility();
    });
    // Aplica visibilidade inicial do botão conforme o estado do switch
    updateCaptureButtonVisibility();

    // Pré-geo para modal
    let cachedGeo = null;

    // ============================================================================
    // PORTARIA 671/2021 - Sincronização com Hora Legal Brasileira (HLB)
    // ============================================================================
    let hlbTime = null; // Hora sincronizada com HLB
    let hlbOffset = 0; // Diferença em segundos entre hora local e HLB
    let lastHlbSync = null; // Timestamp da última sincronização
    
    async function syncWithHLB() {
      try {
        console.log('[HLB] Iniciando sincronização com Hora Legal Brasileira...');
        const startLocal = Date.now();
        
        // NC-38 / NC-14 (Fase 6 da auditoria): APENAS o servidor próprio.
        //
        // Os serviços estrangeiros (worldtimeapi.org, timeapi.io) foram
        // removidos por dois motivos. Primeiro, privacidade: cada consulta
        // enviava o IP do trabalhador para fora do país, contradizendo a
        // política que declarava não haver transferência internacional.
        // Segundo, prova: hora vinda de terceiro não é oponível a ninguém —
        // o que dá valor legal é a âncora ASSINADA pelo servidor, que é
        // exatamente o que este endpoint agora devolve.
        const timeServers = [
          (ROOT_BASE || '') + '/api/get_server_time.php'
        ];
        
        let serverTime = null;
        let latency = 0;
        let sourceUsed = null;
        
        // Tenta cada servidor até conseguir
        for (const server of timeServers) {
          try {
            const _hlbController = new AbortController();
            const _hlbTimeout = setTimeout(() => _hlbController.abort(), 3000);
            let response;
            try {
              response = await fetch(server, { signal: _hlbController.signal });
            } finally {
              clearTimeout(_hlbTimeout);
            }
            
            if (!response.ok) {
              console.warn('[HLB] Servidor retornou erro:', server, response.status);
              continue;
            }
            
            const data = await response.json();
            const endLocal = Date.now();
            latency = Math.floor((endLocal - startLocal) / 2);
            
            // Parseia resposta de acordo com a API
            if (data.datetime) {
              serverTime = new Date(data.datetime);
              sourceUsed = data.source || 'servidor';
            }

            // ÂNCORA DE TEMPO ASSINADA (NC-14).
            // Guardada junto com o relógio MONOTÔNICO do momento da emissão.
            // performance.now() não muda se o usuário alterar a hora do
            // aparelho — é isso que permite provar, depois e offline, a que
            // instante uma marcação corresponde.
            if (data.anchor && data.anchor.token) {
              window.__timeAnchor = {
                token: data.anchor.token,
                issuedMono: (typeof performance !== 'undefined' && performance.now)
                  ? performance.now() : null,
                expiresAt: data.anchor.expires_at
              };
              if (data.hlb) {
                console.log('[HLB] Fonte do servidor:', data.hlb.source || '(nao sincronizado)',
                            'offset', data.hlb.offset_ms, 'ms, status', data.hlb.status);
              }
            }
            
            if (serverTime) {
              console.log('[HLB] Usando servidor:', sourceUsed);
              break; // Sucesso, sai do loop
            }
          } catch (e) {
            console.warn('[HLB] Servidor falhou:', server.substring(0, 50) + '...', e.message);
            continue; // Tenta próximo
          }
        }
        
        if (!serverTime) {
          throw new Error('Todos os servidores de tempo falharam');
        }
        
        // Ajusta pelo latency
        serverTime.setMilliseconds(serverTime.getMilliseconds() + latency);
        
        hlbTime = serverTime;
        hlbOffset = Math.floor((serverTime.getTime() - Date.now()) / 1000);
        lastHlbSync = new Date();
        
        console.log('[HLB] ✅ Sincronizado com sucesso!');
        console.log('[HLB] Fonte:', sourceUsed);
        console.log('[HLB] Diferença:', hlbOffset, 'segundos');
        console.log('[HLB] Latência:', latency, 'ms');
        
        updateHLBClock();
        
        return true;
      } catch (err) {
        console.warn('[HLB] ⚠️ Falha total na sincronização:', err.message);
        // Usa hora local se falhar
        hlbTime = new Date();
        hlbOffset = 0;
        lastHlbSync = null;
        return false;
      }
    }
    
    /**
     * Campos da âncora de tempo para enviar junto com a marcação (NC-14).
     *
     * `anchorElapsedMs` vem do relógio MONOTÔNICO (performance.now()), não de
     * Date.now(): alterar a hora do aparelho não o afeta. É isso que permite ao
     * servidor reconstruir o instante da marcação sem ter de confiar no que o
     * cliente afirma.
     *
     * Devolve objeto vazio se não houver âncora — o servidor então grava a hora
     * de recebimento e marca o registro para revisão, sem descartá-lo.
     */
    function buildTimeAnchorFields() {
      const a = window.__timeAnchor;
      if (!a || !a.token || a.issuedMono === null) return {};
      if (typeof performance === 'undefined' || !performance.now) return {};
      if (a.expiresAt && (Date.now() / 1000) > a.expiresAt) return {}; // expirada
      return {
        timeAnchor: a.token,
        anchorElapsedMs: Math.max(0, Math.round(performance.now() - a.issuedMono))
      };
    }

    /**
     * Guarda o TOKEN DE AUTORIZAÇÃO OFFLINE devolvido pelo servidor (NC-41).
     *
     * Substitui o PIN na fila offline. O PIN é credencial permanente e ficava
     * em texto claro no IndexedDB até o drain — que pode demorar dias e nunca
     * acontece se o app for desinstalado com a fila cheia. O token só vale para
     * este CPF, neste aparelho, por tempo limitado, e não permite login.
     */
    function captureOfflineAuth(res) {
      try {
        const t = res && res.headers && res.headers.get('X-Offline-Auth');
        if (!t) return;
        const exp = parseInt(res.headers.get('X-Offline-Auth-Expires') || '0', 10);
        window.__offlineAuth = { token: t, expiresAt: exp };
        try { sessionStorage.setItem('ponto_offline_auth', JSON.stringify(window.__offlineAuth)); } catch (_) {}
      } catch (_) {}
    }

    /** Token vigente, ou null. Lido de sessionStorage — some ao fechar a aba. */
    function currentOfflineAuth() {
      let a = window.__offlineAuth;
      if (!a) {
        try { a = JSON.parse(sessionStorage.getItem("ponto_offline_auth") || "null"); } catch (_) { a = null; }
      }
      if (!a || !a.token) return null;
      if (a.expiresAt && (Date.now() / 1000) > a.expiresAt) return null;
      return a.token;
    }

    function getCurrentHLBTime() {
      if (!hlbTime || !lastHlbSync) {
        return new Date(); // Fallback para hora local
      }
      
      // Calcula tempo decorrido desde última sincronização
      const elapsed = Date.now() - lastHlbSync.getTime();
      const current = new Date(hlbTime.getTime() + elapsed);
      
      return current;
    }
    
    // Lacuna (c): banner persistente quando HLB nunca sincronizou. Sem isso,
    // o usuário só via o ícone wifi-off discreto e podia bater ponto com hora
    // local errada sem perceber. Servidor sempre usa $now próprio para
    // check_in/check_out, mas a UX precisa avisar quando a hora exibida
    // não é confiável.
    function ensureHlbBanner() {
      let el = document.getElementById('hlbWarningBanner');
      if (el) return el;
      el = document.createElement('div');
      el.id = 'hlbWarningBanner';
      el.className = 'alert alert-warning d-none mb-0 rounded-0';
      el.style.cssText = 'position:sticky;top:0;z-index:1080;text-align:center;font-size:0.9rem;padding:0.5rem 1rem;';
      el.innerHTML = '<i class="bi bi-clock-history me-2"></i>' +
        '<strong>Hora local em uso</strong> — não foi possível sincronizar com a Hora Legal Brasileira. ' +
        'O ponto será gravado com a hora do servidor, mas a hora exibida na tela pode estar errada.';
      const container = document.body;
      container.insertBefore(el, container.firstChild);
      return el;
    }

    function updateHLBClock() {
      const now = getCurrentHLBTime();
      const hours = String(now.getHours()).padStart(2, '0');
      const minutes = String(now.getMinutes()).padStart(2, '0');
      const seconds = String(now.getSeconds()).padStart(2, '0');
      const timeStr = `${hours}:${minutes}:${seconds}`;

      // Atualiza relógio desktop
      const clockEl = document.getElementById('hlbClock');
      if (clockEl) clockEl.textContent = timeStr;

      // Atualiza relógio mobile
      const clockMobile = document.getElementById('hlbClockMobile');
      if (clockMobile) clockMobile.textContent = timeStr;

      // Banner de aviso quando HLB nunca sincronizou (Lacuna c).
      try {
        const banner = ensureHlbBanner();
        if (!lastHlbSync) {
          banner.classList.remove('d-none');
        } else {
          banner.classList.add('d-none');
        }
      } catch (_) {}

      // Atualiza status de sincronização (desktop)
      const statusEl = document.getElementById('hlbStatus');
      if (statusEl) {
        if (lastHlbSync) {
          const syncAge = Math.floor((Date.now() - lastHlbSync.getTime()) / 1000);
          if (syncAge < 3600) { // Menos de 1 hora
            statusEl.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
            statusEl.title = `Sincronizado há ${syncAge}s`;
          } else {
            statusEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning"></i>';
            statusEl.title = 'Sincronização desatualizada';
          }
        } else {
          statusEl.innerHTML = '<i class="bi bi-wifi-off text-secondary"></i>';
          statusEl.title = 'Usando hora local — sincronização HLB falhou';
        }
      }

      // Atualiza status de sincronização (mobile)
      const statusMobile = document.getElementById('hlbStatusMobile');
      if (statusMobile) {
        if (lastHlbSync) {
          const syncAge = Math.floor((Date.now() - lastHlbSync.getTime()) / 1000);
          if (syncAge < 3600) {
            statusMobile.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
          } else {
            statusMobile.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning"></i>';
          }
        } else {
          statusMobile.innerHTML = '<i class="bi bi-wifi-off text-secondary"></i>';
        }
      }
    }
    
    // Sincronizar HLB ao carregar e a cada 1 hora
    syncWithHLB();
    setInterval(syncWithHLB, 3600000); // 1 hora em milissegundos
    
    // Atualizar relógio a cada segundo
    setInterval(updateHLBClock, 1000);
    
    // IndexedDB (pendências offline)
    let dbi;
    let _idbWarned = false; // só avisa uma vez por sessão
    let _idbUnavailable = false; // set quando openDB falha permanentemente

    // Em modo privado do Safari/iOS ou WebViews restritivos, o openDB falha.
    // Avisa o usuário que o modo offline não poderá salvar pontos localmente.
    function warnIdbUnavailable() {
      if (_idbWarned) return;
      _idbWarned = true;
      _idbUnavailable = true;
      try {
        toast('warning', 'Armazenamento local indisponível',
          'Seu navegador está em modo privado ou restrito. Pontos offline não poderão ser salvos — use uma aba normal.', []);
      } catch (_) { /* toast pode não existir ainda no boot; console fallback */
        console.warn('[PWA] IndexedDB indisponível — modo privado?');
      }
    }

    // ============================================================
    // STORAGE MANAGEMENT — proteção da fila offline
    //
    // Política:
    //  - navigator.storage.persist() no boot: pede ao SO/browser para NÃO
    //    fazer eviction ao ficar apertado de disco.
    //  - Monitora quota com navigator.storage.estimate():
    //      < 80% : verde, nada a fazer
    //      80-89%: toast warning, pede para usuário sincronizar
    //      90-94%: apaga runtime cache antigo (NUNCA toca em 'pending')
    //      >= 95%: bloqueia novos savePending com mensagem clara
    //  - Compliance Portaria MTP 671: pontos offline JAMAIS são descartados
    //    automaticamente. Em caso extremo, usuário é forçado a sincronizar
    //    antes de bater novo ponto.
    //  - Log admin: se há >30 pontos pendentes há >7 dias no mesmo device,
    //    registra em audit_logs para RH investigar (via drain endpoint).
    // ============================================================
    const STORAGE_WARN_PCT     = 0.80;
    const STORAGE_PRUNE_PCT    = 0.90;
    const STORAGE_BLOCK_PCT    = 0.95;
    const PENDING_STALE_LIMIT  = 30;           // items pendentes para alertar
    const PENDING_STALE_DAYS   = 7;            // idade do item mais antigo
    let _storageBlocked        = false;        // savePending bloqueado se true
    let _storagePruneInFlight  = false;
    let _storageWarnedAt       = 0;             // último toast 80-89%
    let _storageHighWarnedAt   = 0;             // último toast 90-94%
    let _storageFullWarnedAt   = 0;             // último toast >=95%
    let _storagePersisted      = false;         // true se persist() concedido
    let _storagePersistAttemptedAt = 0;         // throttle para retry

    // Tenta obter persistência do storage. Alguns navegadores negam na
    // primeira chamada (ex.: Firefox pede interação do usuário; Chrome só
    // concede se o app tem notification permission ou está instalado como
    // PWA). Chamar novamente após o usuário interagir pode ter sucesso.
    async function storageTryPersist() {
      if (_storagePersisted) return true;
      if (!navigator.storage || typeof navigator.storage.persist !== 'function') return false;
      // Throttle: no máximo 1 tentativa a cada 10 minutos.
      if (Date.now() - _storagePersistAttemptedAt < 600000) return false;
      _storagePersistAttemptedAt = Date.now();
      try {
        const already = await navigator.storage.persisted?.();
        if (already) { _storagePersisted = true; return true; }
        const ok = await navigator.storage.persist();
        if (ok) _storagePersisted = true;
        console.log('[Storage] persist() =>', ok);
        return ok;
      } catch (err) {
        console.warn('[Storage] persist falhou:', err);
        return false;
      }
    }

    async function storageInit() {
      await storageTryPersist();
      // Re-tenta após interação do usuário: visibilitychange para visible é
      // um bom trigger (implica retorno à aba), e `click` captura a primeira
      // interação explícita que pode desbloquear a permissão em Firefox.
      const retry = () => { storageTryPersist().catch(() => {}); };
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') retry();
      });
      document.addEventListener('click', retry, { once: false, passive: true });
      // Check inicial de quota (sem bloquear boot)
      setTimeout(() => storageCheckQuota().catch(() => {}), 2000);
    }

    async function storageCheckQuota() {
      if (!navigator.storage || typeof navigator.storage.estimate !== 'function') {
        return { supported: false };
      }
      let est;
      try { est = await navigator.storage.estimate(); }
      catch (_) { return { supported: false }; }
      const usage = Number(est.usage) || 0;
      const quota = Number(est.quota) || 0;
      const pct = quota > 0 ? (usage / quota) : 0;
      console.log(`[Storage] ${(usage/1048576).toFixed(1)} MB / ${(quota/1048576).toFixed(1)} MB (${(pct*100).toFixed(1)}%)`);

      if (pct >= STORAGE_BLOCK_PCT) {
        _storageBlocked = true;
        storageNotifyFull(pct);
      } else if (pct >= STORAGE_PRUNE_PCT) {
        _storageBlocked = false;
        // Apaga runtime cache (CSS/JS/HTML cached pelo SW) SEM tocar em 'pending'.
        storagePruneRuntimeCache().catch(() => {});
        storageNotifyHigh(pct);
      } else if (pct >= STORAGE_WARN_PCT) {
        _storageBlocked = false;
        storageNotifyWarn(pct);
      } else {
        _storageBlocked = false;
      }
      return { supported: true, usage, quota, pct, blocked: _storageBlocked };
    }

    // Lê a versão ATIVA do SW para nunca deletar o cache em uso.
    // Descobre via registration (se disponível) ou lista de caches existentes,
    // mantendo intacto o cache cujo nome casa com o SW controlador corrente.
    async function storageGetActiveCacheNames() {
      try {
        const reg = await navigator.serviceWorker?.getRegistration?.();
        const ctrl = navigator.serviceWorker?.controller;
        if (!reg && !ctrl) return new Set();
        // Sem API direta para inspecionar CACHE_VERSION — usa heurística:
        // o cache "mais novo" (maior sufixo semver) é considerado ativo.
        if (typeof caches === 'undefined') return new Set();
        const allKeys = await caches.keys();
        // Encontra a maior versão presente para cada prefixo (ponto-cache, ponto-runtime).
        const byPrefix = {};
        for (const k of allKeys) {
          // M7: aceita pré-releases como v3.0.0-beta.1 / v3.0.0-rc.2
          const m = k.match(/^(.+?)-(v\d+\.\d+\.\d+(?:-[\w.]+)?)$/);
          if (!m) continue;
          const [, prefix, ver] = m;
          if (!byPrefix[prefix] || byPrefix[prefix].ver < ver) byPrefix[prefix] = { name: k, ver };
        }
        return new Set(Object.values(byPrefix).map(v => v.name));
      } catch (_) { return new Set(); }
    }

    async function storagePruneRuntimeCache() {
      if (_storagePruneInFlight) return;
      _storagePruneInFlight = true;
      try {
        if (typeof caches === 'undefined') return;
        const keys = await caches.keys();
        const keepSet = await storageGetActiveCacheNames();
        // Apenas runtime caches do app; NUNCA toca em IndexedDB 'pending',
        // nem apaga o cache ativo (descoberto dinamicamente via heurística).
        const toDelete = keys.filter(k => /-runtime-/.test(k) && !keepSet.has(k));
        for (const k of toDelete) { try { await caches.delete(k); } catch (_) {} }
        console.log(`[Storage] Runtime caches limpos: ${toDelete.length} (preservados: ${[...keepSet].join(', ')})`);
      } finally { _storagePruneInFlight = false; }
    }

    function storageNotifyWarn(pct) {
      // Throttle independente por nível — 80% não suprime 90% nem vice-versa.
      if (Date.now() - _storageWarnedAt < 600000) return;
      _storageWarnedAt = Date.now();
      try {
        toast('warning', 'Armazenamento quase cheio',
          `Use ${(pct*100).toFixed(0)}% do espaço. Sincronize seus pontos pendentes.`, []);
      } catch (_) {}
    }
    function storageNotifyHigh(pct) {
      if (Date.now() - _storageHighWarnedAt < 300000) return;
      _storageHighWarnedAt = Date.now();
      try {
        toast('warning', 'Espaço crítico',
          `${(pct*100).toFixed(0)}% usado. Cache de páginas foi limpo — sincronize seus pontos.`, []);
      } catch (_) {}
    }
    function storageNotifyFull(pct) {
      // Sempre alerta — cada tentativa de savePending já vê bloqueio.
      if (Date.now() - _storageFullWarnedAt < 60000) return;
      _storageFullWarnedAt = Date.now();
      try {
        toast('error', 'Armazenamento cheio',
          'Sincronize seus pontos pendentes para liberar espaço. Novos pontos offline não poderão ser salvos.', []);
      } catch (_) {}
    }

    async function storageCheckPendingStale() {
      // Se há muitos pendentes acumulados (ou antigos), notifica o backend via
      // audit endpoint — útil para admin saber que este dispositivo precisa atenção.
      try {
        if (_idbUnavailable || !dbi) return;
        const all = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readonly');
          const req = tx.objectStore('pending').getAll();
          req.onsuccess = () => resolve(req.result || []);
          req.onerror = () => reject(req.error);
        });
        if (all.length < PENDING_STALE_LIMIT) return;
        const oldest = all.reduce((m, x) => Math.min(m, x.createdAt || Date.now()), Date.now());
        const daysOld = (Date.now() - oldest) / 86400000;
        if (daysOld < PENDING_STALE_DAYS) return;
        // Reporta (não crítico — falha silenciosa se offline)
        try {
          await fetch(ROOT_BASE + '/api/audit_stale_queue.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
              pending_count: all.length,
              oldest_days: Math.round(daysOld),
              tab_id: TAB_ID,
            }),
          });
        } catch (_) {}
      } catch (_) {}
    }

    function openDB() {
      return new Promise((resolve, reject) => {
        const req = indexedDB.open('ponto-db', 3); // Atualizado para versão 3
        req.onupgradeneeded = (e) => {
          const db = e.target.result;
          const oldVersion = e.oldVersion;

          // Versão 1: criar object store
          if (oldVersion < 1) {
            if (!db.objectStoreNames.contains('pending')) {
              db.createObjectStore('pending', {
                keyPath: 'id',
                autoIncrement: true
              });
            }
          }

          // Versão 2: estrutura já correta
          // Nenhuma mudança de schema necessária
        };
        req.onblocked = () => {
          // Outra aba mantém conexão antiga; rejeita para fallback graceful.
          reject(new Error('indexeddb_blocked'));
        };
        req.onsuccess = (e) => {
          dbi = e.target.result;
          // Fecha conexão se outro contexto iniciar upgrade (evita bloqueio entre abas/SW).
          dbi.onversionchange = () => { try { dbi.close(); dbi = null; } catch (_) {} };
          resolve(dbi);
        };
        req.onerror = () => reject(req.error);
      });
    }
    
    // P5: debounce do quota check. Múltiplos saves seguidos não disparam
    // múltiplas chamadas ao navigator.storage.estimate() (que custa ~5ms cada).
    let _quotaCheckPending = false;
    function storageCheckQuotaDebounced() {
      if (_quotaCheckPending) return;
      _quotaCheckPending = true;
      setTimeout(() => {
        _quotaCheckPending = false;
        if (typeof storageCheckQuota === 'function') storageCheckQuota().catch(() => {});
      }, 1000);
    }

    // P3: cache da Service Worker registration. A registration é estável
    // após o primeiro `ready` — não precisa await em cada savePending.
    let _swRegistrationCache = null;
    async function getSwRegistration() {
      if (_swRegistrationCache) return _swRegistrationCache;
      if (!('serviceWorker' in navigator)) return null;
      try {
        _swRegistrationCache = await navigator.serviceWorker.ready;
      } catch (_) {
        return null;
      }
      return _swRegistrationCache;
    }

    /**
     * Procura no store 'pending' um item equivalente a `payload` — mesmo CPF
     * (digits), mesma data Y-m-d (hoje), mesmo expected_action. Janela: 24h.
     * Garante que múltiplos cliques offline = 1 item na fila.
     */
    async function findEquivalentPending(payload) {
      if (!dbi || !payload) return null;
      const cpf = String(payload.cpf || '').replace(/\D/g, '');
      const action = String(payload.expected_action || '');
      if (!cpf || !action) return null;
      return await new Promise((resolve) => {
        try {
          const tx = dbi.transaction('pending', 'readonly');
          const req = tx.objectStore('pending').openCursor();
          req.onsuccess = (e) => {
            const cur = e.target.result;
            if (!cur) { resolve(null); return; }
            const it = cur.value;
            const p = it && it.payload;
            if (p && String(p.cpf || '').replace(/\D/g, '') === cpf
                  && String(p.expected_action || '') === action) {
              const ageMs = Date.now() - (it.createdAt || 0);
              if (ageMs < 24 * 60 * 60 * 1000) {
                resolve(Object.assign({ _key: cur.key }, it));
                return;
              }
            }
            cur.continue();
          };
          req.onerror = () => resolve(null);
        } catch (_) { resolve(null); }
      });
    }

    async function savePending(payload) {
      try {
        if (_idbUnavailable) return false;
        if (_storageBlocked) {
          // Usuário precisa sincronizar para liberar espaço antes de salvar mais.
          try {
            toast('error', 'Armazenamento cheio',
              'Sincronize seus pontos pendentes antes de bater outro offline.', []);
          } catch (_) {}
          return false;
        }
        if (!dbi) {
          try { await openDB(); }
          catch (e) { warnIdbUnavailable(); return false; }
        }
        const payloadSafe = {
          ...payload
        };

        // NC-41: o PIN NUNCA vai para o disco.
        //
        // A fila exigia `pin` porque checkin_bulk.php autenticava por ele, então
        // a credencial permanente do trabalhador ficava em texto claro no
        // IndexedDB até o drain — horas, dias, ou para sempre se o app fosse
        // desinstalado com a fila cheia.
        //
        // Em seu lugar vai o token de autorização offline: assinado pelo
        // servidor, válido só para este CPF e este aparelho, e com prazo. É o
        // mesmo cuidado que o código já tinha com o descriptor facial logo
        // abaixo — o PIN só não o recebia.
        delete payloadSafe.pin;
        const _offAuth = currentOfflineAuth();
        if (_offAuth) payloadSafe.offlineAuth = _offAuth;
        // Face legacy: nunca persiste descriptor em repouso (LGPD).
        // session_checkin (Item 2): mantém face_descriptor para que o step-up
        // funcione quando o drain reenviar. É aceitável porque:
        //   - kind=session_checkin é drain imediato (primeiro evento online)
        //   - o item é deletado do IDB logo após sucesso
        //   - se sessão expirar, o item é descartado ou usuário reloga
        if (payloadSafe.kind !== 'session_checkin') {
          delete payloadSafe.face_descriptor;
          delete payloadSafe.face_challenge;
        }

        // Dedupe pré-save: se já há item equivalente pendente, atualiza-o
        // (incrementa attempts, atualiza foto/geo) em vez de criar novo.
        const dup = await findEquivalentPending(payloadSafe);

        const success = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readwrite');
          const store = tx.objectStore('pending');
          if (dup) {
            const merged = Object.assign({}, dup, {
              payload: Object.assign({}, dup.payload, {
                // Atualiza foto/geo com a tentativa mais recente (pode ter melhor sinal)
                photo: payloadSafe.photo || dup.payload.photo,
                geo:   payloadSafe.geo   || dup.payload.geo,
                // Mantém client_id e timestamp originais — intenção primeira é a que vale
              }),
              attempts: (dup.attempts || 1) + 1,
              lastAttemptAt: Date.now(),
            });
            delete merged._key;
            store.put(merged);
          } else {
            store.add({
              payload: payloadSafe,
              createdAt: Date.now(),
              attempts: 1,
              lastAttemptAt: Date.now(),
            });
          }
          tx.oncomplete = () => resolve({ ok: true, deduped: !!dup });
          tx.onerror = () => reject(tx.error);
        });

        // Flag global para o caller saber se foi dedupe ou criação.
        try { window.__lastSaveWasDedupe = !!(success && success.deduped); } catch (_) {}

        if (success && success.ok) {
          // P3: cache da SW registration — evita await navigator.serviceWorker.ready
          // a cada savePending. A registration é estável após o primeiro boot.
          if ('serviceWorker' in navigator && 'sync' in navigator.serviceWorker) {
            try {
              const reg = await getSwRegistration();
              if (reg && reg.sync) {
                await reg.sync.register('sync-pending-points');
                console.log('[PWA] Background Sync registrado');
              }
            } catch (err) {
              console.warn('[PWA] Background Sync não disponível:', err);
            }
          }

          // Atualiza contador
          updatePendingCount();
          // P5: re-checa cota com debounce — em batch grande de saves
          // (ex: drain ressalvando), evita N chamadas a estimate().
          storageCheckQuotaDebounced();
        }

        return success;
      } catch {
        return false;
      }
    }
    async function getPendingCount() {
      try {
        if (_idbUnavailable) return 0;
        if (!dbi) {
          try { await openDB(); }
          catch (e) { warnIdbUnavailable(); return 0; }
        }
        return await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readonly');
          const req = tx.objectStore('pending').count();
          req.onsuccess = () => resolve(req.result || 0);
          req.onerror = () => reject(req.error);
        });
      } catch {
        return 0;
      }
    }

    async function updatePendingCount() {
      const count = await getPendingCount();
      const btnSync = document.getElementById('btnSyncNow');
      const syncCount = document.getElementById('syncCount');
      const btnDiag = document.getElementById('btnOfflineDiag');

      if (count > 0) {
        btnSync.classList.remove('d-none');
        syncCount.textContent = count;
        if (btnDiag) btnDiag.classList.remove('d-none');
      } else {
        btnSync.classList.add('d-none');
        syncCount.textContent = '0';
        if (btnDiag) btnDiag.classList.add('d-none');
      }
    }

    /**
     * Marca o botão de bater ponto como "salvo offline" — desabilita e troca o
     * texto. Mantém o texto original em data-original-text para restauração
     * após a sincronização concluir.
     */
    function markButtonSavedOffline(btn) {
      if (!btn) return;
      if (!btn.dataset.originalText) btn.dataset.originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Salvo no aparelho';
      btn.classList.add('punch-saved-offline');
    }

    /**
     * Mostra um banner sticky no topo informando que há um ponto salvo offline
     * aguardando sincronização. Idempotente — chamar várias vezes só atualiza.
     */
    function showPendingBanner(payload) {
      let el = document.getElementById('pendingPunchBanner');
      if (!el) {
        el = document.createElement('div');
        el.id = 'pendingPunchBanner';
        el.className = 'alert alert-info d-flex align-items-center gap-2 m-2 shadow-sm';
        el.style.cssText = 'position:sticky;top:0;z-index:1080;border-left:4px solid #0d6efd;';
        document.body.insertBefore(el, document.body.firstChild);
      }
      const action = (payload && payload.expected_action) === 'saida' ? 'Saída' : 'Entrada';
      el.innerHTML =
        '<i class="bi bi-cloud-arrow-up-fill text-primary fs-5"></i>' +
        '<div class="flex-grow-1"><b>' + action + ' salva no aparelho</b>' +
        '<div class="small text-muted">Aguardando conexão para enviar — não registre de novo.</div>' +
        '</div>';
    }

    function clearPendingBanner() {
      const el = document.getElementById('pendingPunchBanner');
      if (el) el.remove();
      // Restaura qualquer botão que tenha sido bloqueado por savePending offline.
      document.querySelectorAll('[data-original-text]').forEach((b) => {
        if (b.dataset.originalText) {
          b.innerHTML = b.dataset.originalText;
          b.disabled = false;
          b.classList.remove('punch-saved-offline');
          delete b.dataset.originalText;
        }
      });
    }

    /**
     * Lê todos os itens do store 'pending' e classifica em 3 categorias:
     *   - valid: 1 representante por (cpf+expected_action+date)
     *   - duplicates: itens equivalentes a um valid (mesmo cpf+action+24h)
     *   - stale: criados há mais de 7 dias
     */
    async function pwaAnalyzeOfflineQueue() {
      if (!dbi) await openDB();
      const items = await new Promise((resolve) => {
        const tx = dbi.transaction('pending', 'readonly');
        const req = tx.objectStore('pending').getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => resolve([]);
      });
      const STALE_MS = 7 * 24 * 60 * 60 * 1000;
      const WINDOW_MS = 24 * 60 * 60 * 1000;
      const now = Date.now();
      const valid = [];
      const duplicates = [];
      const stale = [];
      // Map de key -> item válido (o "head") para checar janela em duplicados.
      // Armazenar o item diretamente (não índice) evita bug de mismatch entre
      // valid[] e items[] quando há stale antes do primeiro válido.
      const seenHead = new Map();
      // Ordena por createdAt ASC para preservar 1ª intenção
      items.sort((a, b) => (a.createdAt || 0) - (b.createdAt || 0));
      for (const it of items) {
        const age = now - (it.createdAt || 0);
        if (age > STALE_MS) { stale.push(it); continue; }
        const p = it.payload || {};
        const cpf = String(p.cpf || '').replace(/\D/g, '');
        const action = String(p.expected_action || '');
        if (!cpf || !action) { valid.push(it); continue; }
        const key = cpf + '|' + action;
        if (seenHead.has(key)) {
          const head = seenHead.get(key);
          if ((it.createdAt || 0) - (head.createdAt || 0) < WINDOW_MS) {
            duplicates.push(it);
            continue;
          }
        }
        seenHead.set(key, it);
        valid.push(it);
      }
      return { valid, duplicates, stale, total: items.length };
    }

    async function pwaCleanLocalIds(ids) {
      if (!ids || !ids.length) return 0;
      if (!dbi) await openDB();
      await new Promise((resolve, reject) => {
        const tx = dbi.transaction('pending', 'readwrite');
        const store = tx.objectStore('pending');
        ids.forEach((id) => store.delete(Number(id)));
        tx.oncomplete = () => resolve(true);
        tx.onerror = () => reject(tx.error);
      });
      await updatePendingCount();
      return ids.length;
    }

    function pwaRenderQueueDialog(report) {
      const renderRows = (arr, badge) => arr.map((it) => {
        const p = it.payload || {};
        const dt = new Date(it.createdAt || 0).toLocaleString('pt-BR');
        const action = (p.expected_action === 'saida') ? 'Saída' : 'Entrada';
        return '<tr>' +
          '<td>' + badge + '</td>' +
          '<td>#' + it.id + '</td>' +
          '<td>' + action + '</td>' +
          '<td class="small text-muted">' + dt + '</td>' +
          '<td class="small">' + (it.attempts || 1) + 'x</td>' +
          '</tr>';
      }).join('');

      const modalId = 'pwaQueueDiagModal';
      let modal = document.getElementById(modalId);
      if (modal) modal.remove();
      modal = document.createElement('div');
      modal.id = modalId;
      modal.className = 'modal fade';
      modal.tabIndex = -1;
      modal.innerHTML =
        '<div class="modal-dialog modal-lg modal-dialog-scrollable">' +
        '<div class="modal-content">' +
          '<div class="modal-header">' +
            '<h5 class="modal-title"><i class="bi bi-clipboard-pulse me-2"></i>Diagnóstico da Fila Offline</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
          '</div>' +
          '<div class="modal-body">' +
            '<div class="alert alert-info small mb-3">' +
              '<b>Total:</b> ' + report.total + ' item(s) — ' +
              '<b class="text-success">' + report.valid.length + '</b> válidos · ' +
              '<b class="text-warning">' + report.duplicates.length + '</b> duplicados · ' +
              '<b class="text-danger">' + report.stale.length + '</b> antigos (>7 dias)' +
            '</div>' +
            (report.valid.length ?
              '<h6 class="text-success"><i class="bi bi-check2-circle"></i> Válidos para envio</h6>' +
              '<table class="table table-sm"><thead><tr><th></th><th>ID</th><th>Ação</th><th>Quando</th><th>Tent.</th></tr></thead><tbody>' +
              renderRows(report.valid, '<span class="badge bg-success">Válido</span>') +
              '</tbody></table>' : '') +
            (report.duplicates.length ?
              '<h6 class="text-warning mt-3"><i class="bi bi-files"></i> Duplicados (recomendado limpar)</h6>' +
              '<table class="table table-sm"><thead><tr><th></th><th>ID</th><th>Ação</th><th>Quando</th><th>Tent.</th></tr></thead><tbody>' +
              renderRows(report.duplicates, '<span class="badge bg-warning text-dark">Duplicado</span>') +
              '</tbody></table>' : '') +
            (report.stale.length ?
              '<h6 class="text-danger mt-3"><i class="bi bi-hourglass-bottom"></i> Antigos (>7 dias — recomendado descartar)</h6>' +
              '<table class="table table-sm"><thead><tr><th></th><th>ID</th><th>Ação</th><th>Quando</th><th>Tent.</th></tr></thead><tbody>' +
              renderRows(report.stale, '<span class="badge bg-danger">Antigo</span>') +
              '</tbody></table>' : '') +
          '</div>' +
          '<div class="modal-footer">' +
            '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>' +
            (report.duplicates.length ?
              '<button type="button" class="btn btn-warning" id="pwaQueueCleanDups"><i class="bi bi-eraser"></i> Limpar duplicados (' + report.duplicates.length + ')</button>' : '') +
            (report.stale.length ?
              '<button type="button" class="btn btn-danger" id="pwaQueueCleanStale"><i class="bi bi-trash"></i> Descartar antigos (' + report.stale.length + ')</button>' : '') +
            (report.valid.length ?
              '<button type="button" class="btn btn-primary" id="pwaQueueSyncNow"><i class="bi bi-cloud-upload"></i> Sincronizar agora</button>' : '') +
          '</div>' +
        '</div></div>';
      document.body.appendChild(modal);
      const bsModal = new bootstrap.Modal(modal);
      bsModal.show();
      modal.querySelector('#pwaQueueCleanDups')?.addEventListener('click', async () => {
        const n = await pwaCleanLocalIds(report.duplicates.map((it) => it.id));
        bsModal.hide();
        toast('success', 'Limpeza concluída', n + ' duplicata(s) removidas da fila local.', []);
      });
      modal.querySelector('#pwaQueueCleanStale')?.addEventListener('click', async () => {
        if (!confirm('Descartar ' + report.stale.length + ' itens com mais de 7 dias? Esta ação não pode ser desfeita.')) return;
        const n = await pwaCleanLocalIds(report.stale.map((it) => it.id));
        bsModal.hide();
        toast('warning', 'Itens antigos descartados', n + ' item(ns) removido(s) da fila local.', []);
      });
      modal.querySelector('#pwaQueueSyncNow')?.addEventListener('click', async () => {
        bsModal.hide();
        try { await drainPending(); } catch (e) { console.error(e); }
      });
    }

    async function pwaShowOfflineQueueDialog() {
      try {
        const report = await pwaAnalyzeOfflineQueue();
        pwaRenderQueueDialog(report);
      } catch (e) {
        console.error('[diag]', e);
        try { toast('error', 'Falha ao abrir diagnóstico', e.message || 'Erro desconhecido', []); } catch (_) {}
      }
    }

    document.addEventListener('DOMContentLoaded', function () {
      const btn = document.getElementById('btnOfflineDiag');
      if (btn) btn.addEventListener('click', pwaShowOfflineQueueDialog);
    });

    // Leader election entre abas: quando duas abas do app estão abertas,
    // só uma pode drenar a fila. O client_id idempotente do backend é o safety
    // net final, mas evitar POSTs duplicados economiza banda e evita toasts
    // conflitantes. BroadcastChannel: Chrome/Edge/Firefox/Safari 15.4+.
    const TAB_ID = pontoGenClientId();
    let DRAIN_CHANNEL = (typeof BroadcastChannel !== 'undefined')
      ? new BroadcastChannel('ponto-drain') : null;
    let _remoteDrainUntil = 0; // timestamp até quando outra aba reivindicou o drain
    function _drainChannelOnMessage(e) {
      const d = e.data || {};
      if (d.tab === TAB_ID) return;
      if (d.type === 'claim')      _remoteDrainUntil = Date.now() + 30000;
      else if (d.type === 'done')  _remoteDrainUntil = 0;
    }
    if (DRAIN_CHANNEL) DRAIN_CHANNEL.addEventListener('message', _drainChannelOnMessage);
    // H3: fecha o canal em pagehide para evitar leak em bfcache. Reabre em
    // pageshow se a página for restaurada com listeners já registrados.
    window.addEventListener('pagehide', () => {
      try { DRAIN_CHANNEL?.close(); } catch (_) {}
      DRAIN_CHANNEL = null;
    });
    window.addEventListener('pageshow', (e) => {
      if (!e.persisted) return; // hard load: IIFE vai recriar o canal normalmente
      if (!DRAIN_CHANNEL && typeof BroadcastChannel !== 'undefined') {
        try {
          DRAIN_CHANNEL = new BroadcastChannel('ponto-drain');
          DRAIN_CHANNEL.addEventListener('message', _drainChannelOnMessage);
        } catch (_) {}
      }
    });

    // Fallback de leader-election quando BroadcastChannel não existe (Safari < 15.4,
    // alguns WebViews). Usa localStorage com timestamp curto. Se outra aba marcou
    // o lock há menos de DRAIN_LS_TIMEOUT, esta aba desiste.
    const DRAIN_LS_KEY = 'ponto_drain_lock';
    const DRAIN_LS_TIMEOUT_MS = 30000;
    function _lsDrainAcquire() {
      try {
        const raw = localStorage.getItem(DRAIN_LS_KEY);
        if (raw) {
          const parsed = parseInt(raw, 10);
          if (!isNaN(parsed) && (Date.now() - parsed) < DRAIN_LS_TIMEOUT_MS) {
            return false; // outra aba está drenando recentemente
          }
        }
        localStorage.setItem(DRAIN_LS_KEY, String(Date.now()));
        return true;
      } catch (_) {
        return true; // se localStorage falhou, deixa drenar (servidor deduplica via client_id)
      }
    }
    function _lsDrainRelease() {
      try { localStorage.removeItem(DRAIN_LS_KEY); } catch (_) {}
    }

    let isSyncing = false;
    async function drainPending() {
      // Check-then-set atômico: reserva o lock ANTES de qualquer await,
      // evitando que duas chamadas concorrentes (ex: evento `online` + boot)
      // passem ambas pela guarda e disparem POSTs duplicados.
      if (isSyncing) {
        console.log('[Sync] Já está sincronizando, aguarde...');
        return;
      }
      isSyncing = true;

      // Cross-tab leader election: se outra aba já está drenando, desiste.
      let _lsLockAcquired = false;
      if (DRAIN_CHANNEL) {
        if (_remoteDrainUntil > Date.now()) {
          console.log('[Sync] Outra aba já está sincronizando — desistindo.');
          isSyncing = false;
          return;
        }
        let _claimSent = false;
        try { DRAIN_CHANNEL.postMessage({ type: 'claim', tab: TAB_ID }); _claimSent = true; } catch (_) {}
        // Janela de 50ms para outra aba responder com seu próprio claim mais antigo.
        await new Promise(r => setTimeout(r, 50));
        if (_remoteDrainUntil > Date.now()) {
          console.log('[Sync] Outra aba reivindicou primeiro — desistindo.');
          // C3: se enviamos claim, precisamos enviar done — caso contrário,
          // outras abas nos consideram "drenando" por 30s e recusam drenar.
          if (_claimSent) {
            try { DRAIN_CHANNEL.postMessage({ type: 'done', tab: TAB_ID }); } catch (_) {}
          }
          isSyncing = false;
          return;
        }
      } else {
        // Safari < 15.4 e WebViews sem BroadcastChannel: usa localStorage.
        if (!_lsDrainAcquire()) {
          console.log('[Sync] Outra aba está drenando (LS lock) — desistindo.');
          isSyncing = false;
          return;
        }
        _lsLockAcquired = true;
      }

      try {
        if (_idbUnavailable) return;
        if (!dbi) {
          try { await openDB(); }
          catch (e) { warnIdbUnavailable(); return; }
        }
        const all = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readonly');
          const req = tx.objectStore('pending').getAll();
          req.onsuccess = () => resolve(req.result || []);
          req.onerror = () => reject(req.error);
        });

        if (!all.length) {
          console.log('[Sync] Nenhum ponto pendente para sincronizar');
          updatePendingCount();
          return;
        }

        // Item 2: separa session_checkins (nova fila PIN-logado) da fila face legacy.
        // Session items vão unit-a-unit para /api/checkin.php (com client_id idempotente).
        // Face items continuam no bulk /api/checkin_bulk.php.
        const sessionAll = all.filter(x => x && x.payload && x.payload.kind === 'session_checkin');
        const faceAll    = all.filter(x => !x || !x.payload || x.payload.kind !== 'session_checkin');

        // Processa session items primeiro (POST individual). Lock já foi adquirido
        // no topo de drainPending — não libera entre blocos session/face para evitar
        // janela de condição de corrida.
        if (sessionAll.length) {
          const { drained: sd, remaining: sr, expired: sExpired } = await pontoQueueDrainSession(sessionAll);
          if (sd > 0) toast('success', 'Pontos sincronizados', `${sd} ponto(s) enviado(s).`, []);
          // S8: session expired no drain — em vez de toast genérico, mostra
          // banner persistente CTA "Entrar de novo" com contador de pendentes.
          // Usuário pode clicar e ir direto para login sem perder os pontos.
          if (sExpired && sr > 0) showStaleSessionBanner(sr);
          updatePendingCount();
          // HOTFIX 2026-09: após sincronizar, o botão ficava travado em "Salvo no
          // aparelho" e o banner "não registre de novo" permanecia até recarregar.
          if (sr === 0) {
            try { clearPendingBanner(); } catch (_) {}
            try { if (typeof pinLoadLastCheckin === 'function') pinLoadLastCheckin().catch(() => {}); } catch (_) {}
          }
          // Se sobrou só session items (sem face), encerra aqui
          if (!faceAll.length) { updateNetBadge(); return; }
        }

        // Fluxo face legacy (bulk) — restringe `all` apenas aos face items
        all.length = 0;
        Array.prototype.push.apply(all, faceAll);
        if (!all.length) { updatePendingCount(); updateNetBadge(); return; }
        const count = all.length;
        console.log(`[Sync] Sincronizando ${count} ponto(s) face...`, all);

        // Atualiza badge para "Sincronizando"
        const badge = document.getElementById('netBadge');
        if (badge) {
          badge.className = 'status-badge';
          badge.style.background = 'linear-gradient(135deg, #ffc107 0%, #ff9800 100%)';
          badge.style.color = '#000';
          badge.innerHTML = '<i class="bi bi-arrow-repeat spin"></i><span class="status-text">Sincronizando...</span>';
        }

        const items = all.map(x => x.payload);

        console.log('[Sync] Enviando para:', bulkUrl);
        console.log('[Sync] Payload:', {
          items
        });

        const res = await fetch(bulkUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            items
          })
        });

        console.log('[Sync] Resposta HTTP:', res.status, res.statusText);

        if (res.ok) {
          const data = await res.json().catch(() => null);
          console.log('[Sync] Resposta do servidor:', data);

          // Verifica se teve erros individuais
          if (data?.results) {
            const errors = data.results.filter(r => r.response?.status === 'error');
            const discarded = data.results.filter(r => r.response?.status === 'discarded');
            const successIds = data.results
              .filter(r => r.response?.status === 'ok' || r.response?.status === 'discarded')
              .map(r => all?.[Number(r.index)]?.id)
              .filter(id => Number.isInteger(id));

            if (successIds.length > 0) {
              await new Promise((resolve, reject) => {
                const tx = dbi.transaction('pending', 'readwrite');
                const store = tx.objectStore('pending');
                successIds.forEach((id) => store.delete(id));
                tx.oncomplete = () => resolve(true);
                tx.onerror = () => reject(tx.error);
              });
            }

            const dedupedCount = data.results.filter(r => r.response?.code === 'duplicate_ignored').length;

            if (errors.length > 0) {
              console.warn('[Sync] Alguns pontos falharam:', errors);
              toast('warning', 'Sincronização parcial', `${count - errors.length} de ${count} ponto(s) sincronizado(s).`, errors.map(e => e.response?.message || 'Erro desconhecido'));
            } else if (discarded.length > 0) {
              toast('warning', 'Sincronização com descartes', `${count - discarded.length} ponto(s) sincronizado(s) e ${discarded.length} descartado(s).`, discarded.map(d => d.response?.message || 'Item descartado'));
            } else {
              console.log('[Sync] Todos os pontos sincronizados com sucesso!');
              toast('success', 'Sincronização completa', `${count} ponto(s) enviado(s) com sucesso!`, []);
            }
            if (dedupedCount > 0) {
              toast('info', 'Tentativas duplicadas ignoradas',
                `${dedupedCount} tentativa(s) repetida(s) foram identificadas e ignoradas com segurança.`,
                ['Sua folha de ponto não foi afetada — apenas um registro é salvo.']);
            }
          } else {
            console.log('[Sync] Sincronização completa!');
            toast('success', 'Sincronização completa', `${count} ponto(s) enviado(s) com sucesso!`, []);

            // Compatibilidade com backend sem resultado item-a-item
            await new Promise((resolve, reject) => {
              const tx = dbi.transaction('pending', 'readwrite');
              tx.objectStore('pending').clear();
              tx.oncomplete = () => resolve(true);
              tx.onerror = () => reject(tx.error);
            });
          }

          updatePendingCount();
          // Se a fila zerou, libera o botão e remove o banner.
          try {
            const remaining = await getPendingCount();
            if (remaining === 0) clearPendingBanner();
          } catch (_) {}
        } else {
          // Mantém na fila para retry
          const text = await res.text();
          console.error('[Sync] Falha na sincronização:', res.status, text);

          let data = null;
          try {
            data = JSON.parse(text);
          } catch {}

          toast('error', 'Erro na sincronização', data?.message || `Erro HTTP ${res.status}`, ['Os pontos pendentes foram mantidos na fila.', 'Tente novamente ou verifique a conexão.']);
        }
      } catch (err) {
        console.error('[Sync] Erro ao sincronizar:', err);
        toast('error', 'Erro na sincronização', err.message || 'Erro desconhecido', ['Os pontos pendentes foram mantidos na fila.', 'Verifique o console para mais detalhes.']);
      } finally {
        isSyncing = false;
        // Libera o claim para outras abas.
        if (DRAIN_CHANNEL) {
          try { DRAIN_CHANNEL.postMessage({ type: 'done', tab: TAB_ID }); } catch (_) {}
        }
        // Libera o lock LS (fallback) se foi adquirido nesta rodada.
        if (_lsLockAcquired) _lsDrainRelease();
        // H2: re-checa cota logo após drain — se o usuário liberou espaço,
        // _storageBlocked cai na mesma rodada, sem esperar o tick de 5min.
        storageCheckQuota().catch(() => {});
        // Restaura badge de rede
        updateNetBadge();
      }
    }

    // ============================================================
    // FILA OFFLINE — Session Check-in (Item 2)
    // Reutiliza o store 'pending' do IndexedDB, diferenciando items via
    // payload.kind === 'session_checkin'. Idempotência: cada item tem um
    // client_id (UUID) que o backend usa para rejeitar duplicatas.
    // ============================================================
    function pontoGenClientId() {
      try { if (crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID(); } catch (_) {}
      // Fallback seguro (não-UUID formal, mas suficiente para idempotência)
      return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)
           + '-' + Math.random().toString(36).slice(2, 10);
    }

    async function pontoQueueEnqueueSession(payload) {
      const p = { ...payload, kind: 'session_checkin' };
      if (!p.client_id) p.client_id = pontoGenClientId();
      await savePending(p);
      return { client_id: p.client_id, deduped: !!window.__lastSaveWasDedupe };
    }

    const PONTO_MAX_RETRY = 5; // após N tentativas, descarta (evita loop infinito)
    const PONTO_PERMANENT_ERRORS = new Set([
      // Códigos que NUNCA vão funcionar com retry — descarta imediatamente.
      'pin_invalid', 'blocked_contact_admin', 'face_conflict',
      'face_corrupt', 'face_save_failed', 'self_enroll_not_allowed',
      'teacher_not_found', 'cpf_invalid', 'invalid_face_descriptor',
      'csrf_invalid', 'stepup_nonce_invalid', 'action_mismatch',
      'photo_required', 'photo_invalid',
    ]);
    const PONTO_BACKOFF_ERRORS = new Set([
      // Erros transitórios — pára de drenar, tenta depois. NÃO incrementa attempts.
      'offline', 'server_unreachable', 'server_error',
      'too_many_attempts', 'kiosk_abuse_detected',
      // HOTFIX 2026-09: estes não são falha do item — antes contavam tentativa e o
      // ponto era DESCARTADO após 5 envios (perda de marcação).
      'busy_retry', 'orphan_punch_pending',
    ]);

    async function pontoQueueDeleteItem(id) {
      if (!dbi) await openDB();
      return new Promise((resolve) => {
        const tx = dbi.transaction('pending', 'readwrite');
        tx.objectStore('pending').delete(id);
        tx.oncomplete = () => resolve(true);
        tx.onerror    = () => resolve(false);
      });
    }
    async function pontoQueueUpdateItem(id, updates) {
      if (!dbi) await openDB();
      return new Promise((resolve) => {
        const tx = dbi.transaction('pending', 'readwrite');
        const store = tx.objectStore('pending');
        const getReq = store.get(id);
        getReq.onsuccess = () => {
          const cur = getReq.result;
          if (!cur) { resolve(false); return; }
          const merged = { ...cur, ...updates };
          store.put(merged);
        };
        tx.oncomplete = () => resolve(true);
        tx.onerror    = () => resolve(false);
      });
    }

    // P9: backoff exponencial — item recebe `nextRetryAt` ao falhar com erro
    // desconhecido. Drain pula items cujo nextRetryAt > now. Evita item ser
    // descartado em 5 falhas seguidas em poucos segundos quando o servidor
    // tem outage transitório.
    function _computeNextRetryAt(attempts) {
      const base = 30 * 1000; // 30s
      const cap  = 60 * 60 * 1000; // 1h
      const ms   = Math.min(cap, base * Math.pow(2, Math.max(0, attempts - 1)));
      return Date.now() + ms;
    }

    // S7: TTL máximo na fila — items com mais de 7 dias são descartados
    // automaticamente para evitar acúmulo perpétuo. Audit log registra.
    const PONTO_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

    async function pontoQueueDrainSession(sessionItems) {
      if (!sessionItems) {
        if (!dbi) await openDB();
        const all = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readonly');
          const req = tx.objectStore('pending').getAll();
          req.onsuccess = () => resolve(req.result || []);
          req.onerror = () => reject(req.error);
        });
        sessionItems = all.filter(x => x && x.payload && x.payload.kind === 'session_checkin');
      }

      // S7: descarta itens muito antigos antes de drenar
      const now = Date.now();
      const tooOld = sessionItems.filter(it => (it.createdAt || 0) > 0 && (now - it.createdAt) > PONTO_MAX_AGE_MS);
      for (const it of tooOld) {
        await pontoQueueDeleteItem(it.id);
        await reportDiscardedOffline(it, 'too_old');
        console.warn('[PontoQueue] Item descartado por idade >7 dias:', it);
      }
      sessionItems = sessionItems.filter(it => !tooOld.includes(it));

      // P9: pula itens em backoff (nextRetryAt > now)
      const skipped = sessionItems.filter(it => (it.nextRetryAt || 0) > now);
      sessionItems = sessionItems.filter(it => (it.nextRetryAt || 0) <= now);

      let drained = 0, remaining = sessionItems.length + skipped.length, expired = false, discarded = tooOld.length;

      // P1: concorrência limitada — processa até 3 items em paralelo.
      // Backend client_id UNIQUE garante que duplicates seriam capturados
      // como already_registered, então a paralelização é segura.
      // HOTFIX 2026-09: concorrência 1, em ordem de criação. Com 3 em paralelo, a
      // saída podia chegar antes da entrada do mesmo colaborador → action_mismatch
      // → descarte permanente do item (perda de marcação).
      const CONCURRENCY = 1;
      const queue = sessionItems.slice().sort((a, b) => (a.id || 0) - (b.id || 0));
      let stopped = false;
      let sessionExpiredFlag = false;

      async function processOne(it) {
        if (stopped) return { kind: 'skip' };
        const toSend = { ...it.payload };
        delete toSend.kind;
        let data;
        try {
          const r = await pinApiPost(PIN_API.checkin, toSend);
          data = r.data || {};
        } catch (_) {
          stopped = true;
          return { kind: 'network' };
        }
        if (data.status === 'ok' || data.code === 'already_registered') {
          await pontoQueueDeleteItem(it.id);
          return { kind: 'ok' };
        }
        if (data.code === 'session_expired') {
          stopped = true;
          sessionExpiredFlag = true;
          return { kind: 'session' };
        }
        if (PONTO_BACKOFF_ERRORS.has(data.code || '')) {
          stopped = true;
          return { kind: 'backoff' };
        }
        if (PONTO_PERMANENT_ERRORS.has(data.code || '')) {
          await pontoQueueDeleteItem(it.id);
          await reportDiscardedOffline(it, data.code || 'permanent_error');
          console.warn('[PontoQueue] Item descartado por erro permanente:', data.code, it);
          return { kind: 'discarded' };
        }
        // Desconhecido — backoff exponencial
        const attempts = ((it.attempts || 0) + 1);
        if (attempts >= PONTO_MAX_RETRY) {
          await pontoQueueDeleteItem(it.id);
          await reportDiscardedOffline(it, data.code || 'max_retries');
          console.warn('[PontoQueue] Item descartado após ' + PONTO_MAX_RETRY + ' tentativas:', data.code, it);
          return { kind: 'discarded' };
        }
        await pontoQueueUpdateItem(it.id, { attempts, nextRetryAt: _computeNextRetryAt(attempts) });
        return { kind: 'retry_later' };
      }

      const inFlight = new Set();
      while ((queue.length > 0 || inFlight.size > 0) && !stopped) {
        while (queue.length > 0 && inFlight.size < CONCURRENCY && !stopped) {
          const it = queue.shift();
          const p = processOne(it).then((r) => {
            inFlight.delete(p);
            if (r.kind === 'ok')        { drained++; remaining--; }
            if (r.kind === 'discarded') { discarded++; remaining--; }
          });
          inFlight.add(p);
        }
        if (inFlight.size > 0) await Promise.race(inFlight);
      }
      // Drena promises restantes
      await Promise.allSettled(Array.from(inFlight));

      expired = sessionExpiredFlag;
      updatePendingCount();
      if (discarded > 0 && typeof toast === 'function') {
        toast('warning', 'Alguns pontos foram descartados',
          `${discarded} ponto(s) não puderam ser sincronizados. Fale com o administrador se precisar.`, []);
      }
      return { drained, remaining, expired, discarded };
    }

    // S8: banner persistente quando o drain detecta session_expired. O
    // usuário ainda tem pontos na fila esperando login — mostra CTA óbvio
    // em vez de um toast que some em 7s.
    function showStaleSessionBanner(pendingCount) {
      let bar = document.getElementById('staleSessionBanner');
      if (bar) {
        // Atualiza contador se já existe
        const counter = bar.querySelector('.stale-session-count');
        if (counter) counter.textContent = String(pendingCount);
        return;
      }
      bar = document.createElement('div');
      bar.id = 'staleSessionBanner';
      bar.setAttribute('role', 'status');
      bar.setAttribute('aria-live', 'polite');
      bar.style.cssText =
        'position:fixed;left:12px;right:12px;bottom:max(12px,env(safe-area-inset-bottom,12px));' +
        'z-index:1090;background:#f59e0b;color:#fff;border-radius:14px;padding:14px 16px;' +
        'box-shadow:0 12px 28px rgba(15,23,42,.22);display:flex;align-items:center;gap:12px;' +
        'font-family:"Plus Jakarta Sans",system-ui,sans-serif;font-size:14px;' +
        'transform:translateY(120%);transition:transform .3s cubic-bezier(.22,.61,.36,1);';
      bar.innerHTML =
        '<i class="bi bi-exclamation-triangle-fill" style="font-size:22px;flex-shrink:0"></i>' +
        '<div style="flex:1;min-width:0;line-height:1.35">' +
          '<strong>Sua sessão expirou</strong><br>' +
          '<span style="opacity:.95;font-size:13px">' +
            '<span class="stale-session-count">' + pendingCount + '</span> ponto(s) aguardando você entrar de novo.' +
          '</span>' +
        '</div>' +
        '<a href="<?= esc($appBase) ?>/login.php?expired=1" style="background:#fff;color:#b96305;border-radius:10px;' +
          'padding:8px 14px;font-weight:700;text-decoration:none;flex-shrink:0">Entrar</a>';
      document.body.appendChild(bar);
      requestAnimationFrame(() => { bar.style.transform = 'translateY(0)'; });
    }

    // S4: reporta item descartado para o backend antes de deletar do IDB.
    // Permite que o admin visualize "pontos perdidos" (com motivo) na auditoria.
    async function reportDiscardedOffline(item, reason) {
      // H4: limpar primeiro (remove pontos/traços/máscara). Regex original exigia
      // exatos 11 dígitos puros; CPF mascarado vinha em branco no audit log.
      const cpfRaw = (item && item.payload && item.payload.cpf) || (SESSION_COLLAB ? SESSION_COLLAB.cpf : '');
      const cpfDigits = String(cpfRaw || '').replace(/\D/g, '');
      const cpfMasked = (cpfDigits.length === 11)
        ? cpfDigits.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.***.***-$4')
        : '';
      const auditPayload = {
        cpf_masked: cpfMasked,
        reason: String(reason || 'unknown').slice(0, 80),
        attempted_at: item ? (item.createdAt || 0) : 0,
        attempts: item ? (item.attempts || 0) : 0,
        action: item && item.payload ? (item.payload.action || '') : '',
        client_id: item && item.payload ? String(item.payload.client_id || '').slice(0, 64) : '',
      };
      try {
        const resp = await fetch(ROOT_BASE + '/api/audit_discarded_offline.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify(auditPayload),
        });
        // BUG-007: fallback em QUALQUER resposta não-OK, não só 401/403.
        // Antes, falha 5xx do backend retornava status 200 com {error:'log_failed'}
        // e o cliente tratava como sucesso — descarte ficava órfão. Agora o
        // backend retorna 500 e qualquer não-2xx aciona retry via localStorage.
        if (!resp || !resp.ok) {
          stashDiscardedAuditFallback(auditPayload);
        }
      } catch (_) {
        // Falha de rede também → guarda fallback (offline puro).
        stashDiscardedAuditFallback(auditPayload);
      }
    }

    // M5: armazena audits que falharam (401/erro de rede) em localStorage,
    // re-tenta na próxima oportunidade (boot ou drain bem-sucedido).
    const PONTO_AUDIT_FALLBACK_KEY = 'ponto_discarded_audit_fallback';
    const PONTO_AUDIT_FALLBACK_MAX = 50;
    function stashDiscardedAuditFallback(payload) {
      try {
        const raw = localStorage.getItem(PONTO_AUDIT_FALLBACK_KEY);
        const arr = raw ? JSON.parse(raw) : [];
        if (!Array.isArray(arr)) return;
        arr.push(payload);
        // cap pra não estourar localStorage
        while (arr.length > PONTO_AUDIT_FALLBACK_MAX) arr.shift();
        localStorage.setItem(PONTO_AUDIT_FALLBACK_KEY, JSON.stringify(arr));
      } catch (_) {}
    }
    async function flushDiscardedAuditFallback() {
      let arr;
      try {
        const raw = localStorage.getItem(PONTO_AUDIT_FALLBACK_KEY);
        if (!raw) return;
        arr = JSON.parse(raw);
        if (!Array.isArray(arr) || arr.length === 0) {
          localStorage.removeItem(PONTO_AUDIT_FALLBACK_KEY);
          return;
        }
      } catch (_) { return; }
      const remaining = [];
      for (const p of arr) {
        try {
          const resp = await fetch(ROOT_BASE + '/api/audit_discarded_offline.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(p),
          });
          // BUG-007: mantém para próxima tentativa em qualquer resposta não-OK
          // (401/403 = sessão; 5xx = backend falhou). Antes, 500 era descartado
          // como se tivesse sido logado.
          if (!resp || !resp.ok) {
            remaining.push(p);
          }
        } catch (_) {
          remaining.push(p);
        }
      }
      try {
        if (remaining.length === 0) localStorage.removeItem(PONTO_AUDIT_FALLBACK_KEY);
        else localStorage.setItem(PONTO_AUDIT_FALLBACK_KEY, JSON.stringify(remaining));
      } catch (_) {}
    }

    // Listener de reconexão
    window.addEventListener('online', () => {
      console.log('[PWA] Conexão restaurada, iniciando sincronização...');
      setTimeout(drainPending, 1000);
    });

    // visibilitychange — Safari iOS / app standalone reabertos podem não disparar
    // o evento `online` (especialmente em iOS, quando o navegador suspende a aba).
    // Sem isso, fila offline pode ficar parada até o usuário recarregar a página.
    // Debounce de 500ms previne disparos espúrios ao alternar entre apps rapidamente.
    let _visDrainTimer = null;
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState !== 'visible') return;
      if (!navigator.onLine) return;
      if (_visDrainTimer) clearTimeout(_visDrainTimer);
      _visDrainTimer = setTimeout(() => {
        _visDrainTimer = null;
        drainPending().catch(() => {});
      }, 500);
    });

    // Inicializa contador ao carregar
    openDB().then(() => {
      updatePendingCount();
      drainPending();
      // Após abrir o DB, checa se há acumulo suspeito de pendências (alerta admin).
      setTimeout(() => storageCheckPendingStale(), 5000);
      // M5: tenta re-postar audits que ficaram presos por 401 em sessões anteriores.
      setTimeout(() => flushDiscardedAuditFallback().catch(() => {}), 3000);
    }).catch(() => { warnIdbUnavailable(); });

    // Persistência + monitoramento de cota.
    storageInit();
    // Re-checa cota a cada 5 minutos enquanto a aba estiver visível.
    setInterval(() => {
      if (document.visibilityState === 'visible') {
        storageCheckQuota().catch(() => {});
      }
    }, 300000);

    function setLoading(loading, btn = btnReg) {
      if (!btn) return;
      const spinner = btn.querySelector('.spinner-border');
      const label = btn.querySelector('.label');
      btn.disabled = loading;
      if (spinner) spinner.classList.toggle('d-none', !loading);
      if (label) label.style.opacity = loading ? .7 : 1;
    }

function formatCpf(value) {
  const digits = String(value || '').replace(/\D/g, '');
  if (digits.length !== 11) return value || '—';
  return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9)}`;
}

function clearFinalizeTimer() {
  if (finalizeTimerId) {
    clearInterval(finalizeTimerId);
    finalizeTimerId = null;
  }
  finalizeDeadline = null;
}

function updateFinalizeTimerDisplay(seconds) {
  if (!finalConfirmTimer) return;
  const safeSeconds = Math.max(0, seconds);
  finalConfirmTimer.textContent = `00:${safeSeconds.toString().padStart(2, '0')}`;
}

function handleFinalizeTimeout() {
  clearFinalizeTimer();
  updateFinalizeTimerDisplay(0);
  if (btnFinalizeConfirm) btnFinalizeConfirm.disabled = true;
  if (finalConfirmTimeout) finalConfirmTimeout.classList.remove('d-none');
  toast('warning', 'Tempo esgotado', 'Capture a foto novamente para concluir o registro.', []);
  statusEl.textContent = 'Tempo expirado. Capture novamente para continuar.';
  closeFinalizeModal();
  resetConfirmStage();
  const confirmModal = getConfirmModal();
  try {
    confirmModal?.hide();
  } catch {}
}

function updateFinalizeTimer() {
  if (!finalizeDeadline) return;
  const remainingMs = finalizeDeadline - Date.now();
  const remainingSec = Math.ceil(remainingMs / 1000);
  updateFinalizeTimerDisplay(remainingSec);
  if (remainingMs <= 0) {
    handleFinalizeTimeout();
  }
}

function startFinalizeTimer() {
  clearFinalizeTimer();
  if (btnFinalizeConfirm) btnFinalizeConfirm.disabled = false;
  if (finalConfirmTimeout) finalConfirmTimeout.classList.add('d-none');
  finalizeDeadline = Date.now() + 30000;
  updateFinalizeTimerDisplay(30);
  finalizeTimerId = setInterval(updateFinalizeTimer, 250);
  statusEl.textContent = 'Revise as informações e confirme em até 30 segundos.';
}

function getFinalConfirmModal() {
  if (!finalConfirmModalInstance && finalConfirmModalEl && window.bootstrap?.Modal) {
    finalConfirmModalInstance = new window.bootstrap.Modal(finalConfirmModalEl, {
      backdrop: 'static',
      keyboard: false
    });
  }
  return finalConfirmModalInstance;
}

function openFinalizeModal() {
  if (!previewData) return;
  if (finalConfirmName) finalConfirmName.textContent = previewData.collaborator?.name || '—';
  if (finalConfirmCpf) finalConfirmCpf.textContent = formatCpf(previewData.collaborator?.cpf || '');
  // Tipo da ação: 'out' (saída), 'in' (entrada), 'break_start' (iniciar intervalo), 'break_end' (retornar do intervalo).
  const actionType = (() => {
    if (previewData?.action_key) return previewData.action_key;
    const actionLabel = (previewData?.action || '').toLowerCase();
    if (actionLabel.includes('saída')) return 'out';
    if (actionLabel.includes('início do intervalo')) return 'break_start';
    if (actionLabel.includes('retorno do intervalo')) return 'break_end';
    return 'in';
  })();
  const actionDisplay = {
    'in':          { label: 'Entrada',              cardClass: 'alert-success', textClass: 'text-success', icon: 'bi bi-box-arrow-in-right text-success', bg: ['bg-success-subtle', 'text-success'] },
    'out':         { label: 'Saída',                cardClass: 'alert-danger',  textClass: 'text-danger',  icon: 'bi bi-box-arrow-right text-danger',     bg: ['bg-danger-subtle', 'text-danger'] },
    'break_start': { label: 'Iniciar intervalo',    cardClass: 'alert-warning', textClass: 'text-warning', icon: 'bi bi-pause-circle text-warning',       bg: ['bg-warning-subtle', 'text-warning'] },
    'break_end':   { label: 'Retornar do intervalo',cardClass: 'alert-warning', textClass: 'text-warning', icon: 'bi bi-play-circle text-warning',        bg: ['bg-warning-subtle', 'text-warning'] },
  };
  const cfg = actionDisplay[actionType] || actionDisplay['in'];

  if (finalConfirmAction) {
    finalConfirmAction.textContent = cfg.label;
    finalConfirmAction.classList.remove('text-success', 'text-danger', 'text-warning');
    finalConfirmAction.classList.add(cfg.textClass);
  }
  const actionCard = document.getElementById('finalConfirmActionCard');
  const actionIconEl = document.getElementById('finalConfirmActionIcon');
  if (actionCard) {
    actionCard.className = 'review-summary-card h-100 alert mb-0 ' + cfg.cardClass;
    if (actionIconEl) {
      actionIconEl.className = cfg.icon;
      const parent = actionIconEl.parentElement;
      if (parent) {
        ['bg-success-subtle','bg-danger-subtle','bg-warning-subtle','text-success','text-danger','text-warning'].forEach(c => parent.classList.remove(c));
        cfg.bg.forEach(c => parent.classList.add(c));
      }
    }
  }
  startFinalizeTimer();
  getFinalConfirmModal()?.show();
}

function closeFinalizeModal() {
  const modal = getFinalConfirmModal();
  if (!modal) return;
  try {
    modal.hide();
  } catch {}
  clearFinalizeTimer();
  if (finalConfirmTimeout) finalConfirmTimeout.classList.add('d-none');
  if (btnFinalizeConfirm) btnFinalizeConfirm.disabled = false;
  updateFinalizeTimerDisplay(30);
}

function updateConfirmUI() {
  if (faceAuthMode && confirmStage === 'confirm') {
    if (btnConfirmLabel) btnConfirmLabel.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Bater ponto';
    if (confirmPreviewAlert) {
      confirmPreviewAlert.classList.remove('alert-info');
      confirmPreviewAlert.classList.add('alert-success');
    }
    if (confirmPreviewAlertIcon) {
      confirmPreviewAlertIcon.classList.remove('bi-info-circle');
      confirmPreviewAlertIcon.classList.add('bi-check2-circle');
    }
    if (confirmPreviewAlertText) {
      confirmPreviewAlertText.textContent = 'Identidade confirmada por reconhecimento facial. Confira e registre o ponto.';
    }
    if (confirmCollaboratorRow && previewData) confirmCollaboratorRow.classList.remove('d-none');
    return;
  }
  if (btnConfirmLabel) {
    btnConfirmLabel.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Bater ponto';
  }
  if (confirmPreviewAlert) {
    confirmPreviewAlert.classList.toggle('alert-info', confirmStage === 'preview');
    confirmPreviewAlert.classList.toggle('alert-success', confirmStage !== 'preview');
  }
  if (confirmPreviewAlertIcon) {
    confirmPreviewAlertIcon.classList.remove('bi-info-circle', 'bi-check2-circle');
    confirmPreviewAlertIcon.classList.add(confirmStage === 'preview' ? 'bi-info-circle' : 'bi-check2-circle');
  }
  if (confirmPreviewAlertText) {
    confirmPreviewAlertText.textContent = confirmStage === 'preview'
      ? 'Digite o PIN e clique em Bater ponto para continuar.'
      : 'Dados carregados. Clique em Bater ponto para registrar o lançamento.';
  }
  if (confirmCollaboratorRow) {
    if (confirmStage === 'preview' && !previewData) {
      confirmCollaboratorRow.classList.add('d-none');
    } else if (previewData) {
      confirmCollaboratorRow.classList.remove('d-none');
    }
  }
}

function setConfirmStage(stage) {
  confirmStage = stage;
  updateConfirmUI();
}

function resetConfirmStage() {
  // Não resetar durante cadastro facial automático — os dados são necessários para auto-checkin
  if (window._autoCheckinAfterEnroll) return;
  previewData = null;
  capturedFaceMatch = null;
  capturedFaceDistance = null;
  faceAuthMode = false;
  faceChallengeToken = null;
  const faceRow = document.getElementById('confirmFaceRow');
  if (faceRow) faceRow.style.display = 'none';
  const faceBadge = document.getElementById('capturedFaceBadge');
  if (faceBadge) faceBadge.style.display = 'none';
  const cpfForm = document.getElementById('cpfForm');
  if (cpfForm) cpfForm.style.display = '';
  if (btnConfirmSubmit) {
    btnConfirmSubmit.disabled = false;
    const lbl = btnConfirmSubmit.querySelector('.label');
    if (lbl) lbl.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Bater ponto';
  }
  if (confirmCollaboratorName) confirmCollaboratorName.textContent = '—';
  if (confirmCollaboratorAction) confirmCollaboratorAction.textContent = 'Digite seu CPF e clique em Bater ponto para ver os detalhes.';
  if (finalConfirmName) finalConfirmName.textContent = '—';
  if (finalConfirmCpf) finalConfirmCpf.textContent = '—';
  if (finalConfirmAction) finalConfirmAction.textContent = 'Entrada';
  const actionCard = document.getElementById('finalConfirmActionCard');
  if (actionCard) {
    actionCard.className = 'review-summary-card h-100 alert alert-success mb-0';
  }
  const actionIcon = document.getElementById('finalConfirmActionIcon');
  if (actionIcon) {
    actionIcon.className = 'bi bi-box-arrow-in-right text-success';
  }
  if (finalConfirmAction) {
    finalConfirmAction.classList.remove('text-success', 'text-danger');
    finalConfirmAction.classList.add('text-success');
  }
  const actionIconEl = document.getElementById('finalConfirmActionIcon');
  if (actionIconEl) {
    actionIconEl.className = 'bi bi-box-arrow-in-right text-success';
    actionIconEl.parentElement.classList.remove('bg-danger-subtle', 'text-danger');
    actionIconEl.parentElement.classList.add('bg-success-subtle', 'text-success');
  }
  setConfirmStage('preview');
  closeFinalizeModal();
}

function resetFaceAttemptState() {
  faceChallengeToken = null;
  faceAuthMode = false;
  capturedFaceMatch = null;
  capturedFaceDistance = null;
  capturedDescriptor = null;
}

function maybeResetStageForCpfError(code) {
  if (!code) return;
  const resetCodes = ['cpf_invalid', 'cpf_duplicate', 'collaborator_inactive', 'cpf_required'];
  if (resetCodes.includes(code)) resetConfirmStage();
}

updateConfirmUI();

    function vibrate(ms = 30) {
      try {
        navigator.vibrate?.(ms);
      } catch {}
    }

    function toast(type, title, message, hints = []) {
      const el = document.getElementById('toastify');
      const icon = {
        success: 'check-circle',
        error: 'exclamation-octagon',
        warning: 'exclamation-triangle',
        info: 'info-circle'
      } [type] || 'info-circle';
      const color = {
        success: 'success',
        error: 'danger',
        warning: 'warning',
        info: 'primary'
      } [type] || 'primary';
      const hintList = (hints && hints.length) ? `<ul class="mb-0 ps-3">${hints.map(h => `<li>${String(h)}</li>`).join('')}</ul>` : '';
      el.innerHTML = `
        <div class="alert alert-${color} d-flex align-items-start gap-2 mb-0" role="alert">
          <i class="bi bi-${icon} fs-4 mt-1"></i>
          <div>
            <div class="fw-bold">${title}</div>
            <div>${message || ''}</div>
            ${hintList}
          </div>
          <button type="button" class="btn-close ms-auto" aria-label="Fechar"></button>
        </div>`;
      el.classList.add('show');
      el.querySelector('.btn-close').onclick = () => el.classList.remove('show');
      setTimeout(() => el.classList.remove('show'), 7000);
    }

    function markInvalid(inputEl, feedbackEl, text) {
      if (!inputEl) return;
      inputEl.classList.add('is-invalid');
      if (feedbackEl && text) feedbackEl.textContent = text;
      inputEl.addEventListener('input', () => {
        inputEl.classList.remove('is-invalid');
        if (feedbackEl) feedbackEl.textContent = '';
      }, {
        once: true
      });
      vibrate(60);
      document.getElementById('pointForm').classList.remove('shake');
      requestAnimationFrame(() => document.getElementById('pointForm').classList.add('shake'));
      inputEl.focus();
    }

    function handleApiError(httpStatus, data) {
      const code = data?.code || '';
      const msg = data?.message || 'Não foi possível concluir sua solicitação.';
      const hints = Array.isArray(data?.hints) ? data.hints : [];
      statusEl.textContent = msg;

      switch (code) {
        case 'cpf_required':
          markInvalid(cpfInput, cpfFeedback, 'Informe seu CPF (11 dígitos).');
          toast('warning', 'CPF obrigatório', 'Digite seu CPF para seguir.', ['O CPF deve ter 11 dígitos.']);
          break;
        case 'cpf_invalid':
          markInvalid(cpfInput, cpfFeedback, 'CPF não encontrado ou colaborador inativo.');
          toast('error', 'CPF inválido', 'Verifique o CPF informado.', ['Confira os 11 dígitos.', 'Se o problema persistir, contate o Admin/RH.']);
          break;
        case 'collaborator_inactive':
          markInvalid(cpfInput, cpfFeedback, 'Seu cadastro está inativo. Procure o Admin/RH.');
          toast('warning', 'Colaborador inativo', 'Seu acesso está inativo no sistema.', ['Fale com o Admin/RH para regularizar seu cadastro.']);
          break;
        case 'cpf_duplicate':
          markInvalid(cpfInput, cpfFeedback, 'Há duplicidade de CPF. Procure o Admin.');
          toast('warning', 'CPF duplicado', 'Encontramos mais de um colaborador com este CPF.', ['Peça ao Admin/RH para regularizar.', 'Por segurança, o registro foi bloqueado.']);
          break;
        case 'no_schedule_today':
          toast('info', 'Sem rotina hoje', msg, ['Verifique seu cronograma.', 'Se houver exceção (plantão, reposição), peça liberação ao gestor.']);
          break;
        case 'no_schedule_config':
          toast('warning', 'Rotina não configurada', msg, ['Peça ao Admin/RH para configurar sua jornada/agenda.']);
          break;
        case 'already_checked_in':
          toast('info', 'Entrada já registrada', msg, ['Registre a saída quando finalizar seu expediente.']);
          break;
        case 'no_open_checkin':
          toast('warning', 'Saída não permitida', msg, ['Registre a entrada antes de tentar registrar a saída.']);
          break;
        case 'action_mismatch':
          toast('warning', 'Estado do ponto mudou', msg, hints.length ? hints : ['Atualize a página e confira o último ponto antes de tentar novamente.']);
          setTimeout(() => pinLoadLastCheckin && pinLoadLastCheckin().catch(() => {}), 500);
          break;
        case 'face_insufficient_enrollment':
          // Cadastro facial incompleto — precisa de mais amostras
          faceAuthMode = false;
          faceRecognitionAttempted = true;
          toast('info', 'Cadastro facial incompleto', 'Seu cadastro facial precisa de mais amostras. Solicite atualização ao administrador.', ['Use o CPF para registrar o ponto.']);
          setTimeout(() => {
            openConfirmModal();
            showCapturedFaceBadge(true);
            const _fr2 = document.getElementById('confirmFaceRow');
            const _fi2 = document.getElementById('confirmFaceIcon');
            const _fs2 = document.getElementById('confirmFaceStatus');
            if (_fr2) {
              _fr2.style.display = '';
              _fi2.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-info-subtle text-info';
              _fi2.innerHTML = '<i class="bi bi-camera fs-6"></i>';
              _fs2.innerHTML = '<span class="text-info fw-semibold">Cadastro facial incompleto — solicite recadastro</span>';
            }
          }, 100);
          break;
        case 'face_not_recognized':
          // Reconhecimento facial falhou no servidor — abre modal CPF como fallback
          faceAuthMode = false;
          faceRecognitionAttempted = true;
          toast('warning', 'Rosto não identificado', 'Não foi possível confirmar sua identidade. Digite seu CPF para continuar.', []);
          setTimeout(() => {
            openConfirmModal();
            showCapturedFaceBadge(true);
            const _fr = document.getElementById('confirmFaceRow');
            const _fi = document.getElementById('confirmFaceIcon');
            const _fs = document.getElementById('confirmFaceStatus');
            if (_fr) {
              _fr.style.display = '';
              _fi.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning';
              _fi.innerHTML = '<i class="bi bi-person-exclamation fs-6"></i>';
              _fs.innerHTML = '<span class="text-warning fw-semibold">Rosto não identificado</span>';
            }
          }, 100);
          break;
        case 'face_challenge_invalid':
          faceAuthMode = false;
          toast('warning', 'Sessão facial expirada', msg || 'Sua sessão facial expirou. Digite seu CPF para continuar.', [
            'Faça uma nova captura facial ou siga com CPF.'
          ]);
          setTimeout(() => openConfirmModal(), 100);
          break;
        case 'photo_invalid':
          toast('warning', 'Foto inválida', msg, ['Faça uma nova captura com boa iluminação.']);
          break;
        case 'photo_required':
          toast('warning', 'Foto obrigatoria', msg || 'A entrada precisa de foto.', ['Permita a camera e tente novamente.']);
          break;
        case 'liveness_failed':
          faceAuthMode = false;
          faceRecognitionAttempted = true;
          toast('warning', 'Verificação de vivacidade', msg || 'Não foi possível confirmar que você está ao vivo.', [
            'Mantenha o celular firme e olhe diretamente para a câmera.',
            'O ambiente deve ter boa iluminação.',
            'Evite usar fotos ou telas como substituto.',
            'Use o CPF como alternativa.'
          ]);
          setTimeout(() => openConfirmModal(), 100);
          break;
        case 'liveness_data_missing':
          toast('warning', 'Atualização necessária', msg, [
            'Atualize o aplicativo para a versão mais recente.',
            'Se o problema persistir, limpe o cache do navegador.'
          ]);
          break;
        default:
          toast('error', 'Não foi possível registrar', msg, ['Tente novamente em alguns instantes.', 'Se persistir, fale com o Admin/RH.']);
      }
    }

    function inferFacingFromTrack(track) {
      try {
        const s = track.getSettings ? track.getSettings() : {};
        if (s && s.facingMode) return s.facingMode;
        const label = (track.label || '').toLowerCase();
        if (label.includes('back') || label.includes('rear') || label.includes('traseira') || label.includes('environment')) {
          return 'environment';
        }
      } catch {}
      return 'user';
    }

    function updateFacingUI(facing) {
      currentFacing = facing === 'environment' ? 'environment' : 'user';
      if (currentFacing === 'environment') {
        camBack.checked = true;
        camFront.checked = false;
      } else {
        camFront.checked = true;
        camBack.checked = false;
      }
    }

    // Canvas overlay
    function resizeFaceOverlay() {
      if (!faceOverlay) return;
      const w = video.clientWidth || faceOverlay.clientWidth || 0;
      const h = video.clientHeight || faceOverlay.clientHeight || 0;
      if (w && h) {
        if (faceOverlay.width !== w) faceOverlay.width = w;
        if (faceOverlay.height !== h) faceOverlay.height = h;
      }
    }

    function getCoverTransform() {
      const vw = video.videoWidth || 1,
        vh = video.videoHeight || 1;
      const cw = faceOverlay.width || video.clientWidth || 1,
        ch = faceOverlay.height || video.clientHeight || 1;
      const scale = Math.max(cw / vw, ch / vh);
      const dispW = vw * scale,
        dispH = vh * scale;
      const offsetX = (cw - dispW) / 2,
        offsetY = (ch - dispH) / 2;
      return {
        scale,
        offsetX,
        offsetY,
        cw,
        ch,
        vw,
        vh
      };
    }

    function drawEllipsePath(ctx, cx, cy, rx, ry) {
      const k = 0.5522847498307936,
        cpx = rx * k,
        cpy = ry * k;
      ctx.beginPath();
      ctx.moveTo(cx, cy - ry);
      ctx.bezierCurveTo(cx + cpx, cy - ry, cx + rx, cy - cpy, cx + rx, cy);
      ctx.bezierCurveTo(cx + rx, cy + cpy, cx + cpx, cy + ry, cx, cy + ry);
      ctx.bezierCurveTo(cx - cpx, cy + ry, cx - rx, cy + cpy, cx - rx, cy);
      ctx.bezierCurveTo(cx - rx, cy - cpy, cx - cpx, cy - ry, cx, cy - ry);
      ctx.closePath();
    }

    // Mais tolerante e rápido para considerar "OK", agora com verificação de enquadramento facial rigorosa e validação de luminosidade
    function computeQuality(rect, cw, ch) {
      const cx = rect.x + rect.width / 2,
        cy = rect.y + rect.height / 2;
      const centerTol = Math.min(cw, ch) * 0.10; // Reduzido para centralização mais estrita
      const dx = cx - (cw / 2),
        dy = cy - (ch / 2),
        dist = Math.hypot(dx, dy);
      const shortSide = Math.min(cw, ch);
      const desiredWidthMin = shortSide * 0.25; // Exige rosto um pouco maior (mínimo)
      const desiredWidthMax = shortSide * 0.60; // Limite máximo ajustado

      let message = '';
      let warn = false;

      if (rect.width < desiredWidthMin) {
        message = 'Aproxime o rosto';
        warn = true;
      } else if (rect.width > desiredWidthMax) {
        message = 'Afaste um pouco';
        warn = true;
      } else if (dist > centerTol) {
        message = 'Centralize o rosto';
        warn = true;
      }

      // Verificação rápida de luminosidade usando o canvas do vídeo atual
      if (!warn && video.videoWidth > 0) {
        try {
          const lumaCanvas = document.createElement('canvas');
          const lumaCtx = lumaCanvas.getContext('2d', { willReadFrequently: true });
          // Reduz resolução para amostragem rápida
          lumaCanvas.width = 64;
          lumaCanvas.height = 64;
          lumaCtx.drawImage(video, 0, 0, 64, 64);
          const imgData = lumaCtx.getImageData(0, 0, 64, 64).data;
          let sum = 0;
          for (let i = 0; i < imgData.length; i += 4) {
            sum += (imgData[i] * 0.299 + imgData[i + 1] * 0.587 + imgData[i + 2] * 0.114);
          }
          const avgLuma = sum / (64 * 64);
          if (avgLuma < 50) {
            message = 'Ambiente muito escuro';
            warn = true;
          } else if (avgLuma > 240) {
            message = 'Luz muito forte no rosto';
            warn = true;
          }
        } catch (e) {
          // Ignora se falhar
        }
      }

      const ok = !warn;
      return {
        ok,
        warn,
        message: ok ? 'Aguardando enquadramento facial...' : message
      };
    }

    function clearOverlay() {
      if (!overlayCtx || !faceOverlay) return;
      overlayCtx.clearRect(0, 0, faceOverlay.width, faceOverlay.height);
    }

    function drawGuide(rect) {
      if (!overlayCtx || !faceOverlay) return;
      const ctx = overlayCtx,
        cw = faceOverlay.width,
        ch = faceOverlay.height;
      ctx.clearRect(0, 0, cw, ch);
      const cx = rect.x + rect.width / 2,
        cy = rect.y + rect.height / 2;
      const rx = rect.width * 0.65,
        ry = rect.height * 0.95;
      const q = computeQuality(rect, cw, ch);
      const color = q.ok ? 'rgba(25,135,84,0.95)' : 'rgba(255,193,7,0.95)';
      ctx.save();
      ctx.fillStyle = 'rgba(0,0,0,0.20)'; // um pouco mais transparente
      ctx.fillRect(0, 0, cw, ch);
      ctx.globalCompositeOperation = 'destination-out';
      drawEllipsePath(ctx, cx, cy, rx, ry);
      ctx.fill();
      ctx.restore();
      ctx.save();
      ctx.strokeStyle = color;
      ctx.lineWidth = 3;
      ctx.setLineDash([10, 8]);
      ctx.lineDashOffset = -Date.now() / 80;
      drawEllipsePath(ctx, cx, cy, rx, ry);
      ctx.stroke();
      ctx.restore();
      ctx.save();
      ctx.fillStyle = 'rgba(255,255,255,0.95)';
      ctx.font = '600 14px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'bottom';
      
      // Feedback visual do temporizador de estabilidade de 2s
      let displayMessage = q.message;
      if (q.ok && lastOkTs) {
          const elapsed = performance.now() - lastOkTs;
          const remaining = Math.max(0, 2000 - elapsed);
          if (remaining > 0) {
              const secs = (remaining / 1000).toFixed(1);
              displayMessage = `Segure firme... ${secs}s`;
          } else {
              displayMessage = 'Capturando...';
          }
      }
      ctx.fillText(displayMessage || '', cw / 2, ch - 14);
      ctx.restore();

      // Disparo de captura automática quando "ok" por ~2000ms (2 segundos)
      if (autoCaptureEnabled && stream && !capturedDataUrl && !document.body.classList.contains('modal-open')) {
        const now = performance.now();
        if (q.ok) {
          if (!lastOkTs) lastOkTs = now;
          if (now - lastOkTs > 2000) { // Janela estendida para 2 segundos de estabilidade obrigatória
            try {
              doCapture();
            } catch {}
            lastOkTs = 0;
          }
        } else {
          lastOkTs = 0;
        }
      } else {
        lastOkTs = 0;
      }
    }
    const lerp = (a, b, t) => a + (b - a) * t;

    function lerpRect(prev, next, t) {
      if (!prev) return next;
      return {
        x: lerp(prev.x, next.x, t),
        y: lerp(prev.y, next.y, t),
        width: lerp(prev.width, next.width, t),
        height: lerp(prev.height, next.height, t)
      };
    }

    function fadeOutStaticGuide() {
      const el = staticFaceGuide;
      if (!el) return;
      el.style.opacity = '0';
      setTimeout(() => {
        el.style.display = 'none';
      }, 200);
    }

    function showStaticGuide() {
      const el = staticFaceGuide;
      if (!el) return;
      el.style.display = 'grid';
      requestAnimationFrame(() => {
        el.style.opacity = '1';
      });
    }

    let mpTick = 0;

    async function detectAndDraw() {
      if (!stream || !overlayCtx) {
        showStaticGuide();
        clearOverlay();
        return;
      }
      const {
        scale,
        offsetX,
        offsetY,
        vw,
        vh
      } = getCoverTransform();

      const drawRect = (bb) => {
        const mapped = {
          x: offsetX + (bb.x || bb.left || 0) * scale,
          y: offsetY + (bb.y || bb.top || 0) * scale,
          width: (bb.width || 0) * scale,
          height: (bb.height || 0) * scale,
        };
        smoothRect = lerpRect(smoothRect, mapped, 0.35); // um pouco mais responsivo
        const r = smoothRect || mapped;
        drawGuide(r);
        fadeOutStaticGuide();
      };

      // 1) Nativo (levemente "throttled" para suavizar sem perder agilidade)
      if (faceDetector) {
        const nowT = performance.now();
        if (detecting || (nowT - nativeTick) < 80) return;
        nativeTick = nowT;
        detecting = true;
        try {
          const faces = await faceDetector.detect(video);
          const face = faces?.[0];
          if (!face) {
            clearOverlay();
            showStaticGuide();
          } else {
            drawRect(face.boundingBox || face);
          }
        } catch {} finally {
          detecting = false;
        }
        return;
      }

      // 2) Fallback MediaPipe
      if (mpFace) {
        const now = performance.now();
        if (!mpBusy && now - mpTick > 60) { // 60ms (≈16fps -> balanceado)
          mpTick = now;
          mpBusy = true;
          mpFace.send({
            image: video
          }).catch(() => {}).finally(() => {
            mpBusy = false;
          });
        }
        if (mpLastRect) {
          const r = mpLastRect;
          const abs = (r.type === 'abs') ? {
            x: r.x,
            y: r.y,
            width: r.width,
            height: r.height
          } : {
            x: r.x * vw,
            y: r.y * vh,
            width: r.width * vw,
            height: r.height * vh
          };
          drawRect(abs);
        } else {
          clearOverlay();
          showStaticGuide();
        }
        return;
      }

      // 3) Sem detector
      clearOverlay();
      showStaticGuide();
    }

    function startFaceGuide() {
      if (!faceOverlay) return;
      resizeFaceOverlay();
      cancelFaceGuide();
      const loop = () => {
        detectAndDraw();
        faceGuideRAF = requestAnimationFrame(loop);
      };
      faceGuideRAF = requestAnimationFrame(loop);
      window.addEventListener('resize', resizeFaceOverlay);
    }

    function cancelFaceGuide() {
      if (faceGuideRAF) cancelAnimationFrame(faceGuideRAF);
      faceGuideRAF = null;
      smoothRect = null;
      lastOkTs = 0;
      clearOverlay();
      window.removeEventListener('resize', resizeFaceOverlay);
      showStaticGuide();
    }

    async function initFaceEngines() {
      // Desabilita detecção facial quando offline (MediaPipe precisa de CDN)
      if (!navigator.onLine) {
        console.log('[Face Detection] Offline - detecção facial desabilitada');
        faceDetector = null;
        mpFace = null;
        return;
      }

      if ('FaceDetector' in window) {
        try {
          faceDetector = new FaceDetector({
            fastMode: true,
            maxDetectedFaces: 1
          });
          console.log('[Face Detection] Usando FaceDetector nativo');
        } catch {
          faceDetector = null;
        }
      }
      if (!faceDetector && typeof window.FaceDetection !== 'undefined') {
        try {
          mpFace = new window.FaceDetection({
            locateFile: (file) => `https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/${file}`,
          });
          mpFace.setOptions({
            model: 'short',
            minDetectionConfidence: 0.5 // balanceado para velocidade
          });
          mpFace.onResults((results) => {
            const det = results?.detections?.[0];
            const rbb = det?.boundingBox || det?.locationData?.relativeBoundingBox || null;
            if (!rbb) {
              mpLastRect = null;
              return;
            }
            if ('xMin' in rbb && rbb.xMin <= 1 && 'yMin' in rbb && rbb.yMin <= 1 && rbb.width <= 1 && rbb.height <= 1) {
              mpLastRect = {
                type: 'norm',
                x: rbb.xMin,
                y: rbb.yMin,
                width: rbb.width,
                height: rbb.height
              };
            } else if ('xCenter' in rbb && rbb.width <= 1 && rbb.height <= 1) {
              mpLastRect = {
                type: 'norm',
                x: rbb.xCenter - rbb.width / 2,
                y: rbb.yCenter - rbb.height / 2,
                width: rbb.width,
                height: rbb.height
              };
            } else {
              const left = rbb.left ?? rbb.xMin ?? 0,
                top = rbb.top ?? rbb.yMin ?? 0;
              mpLastRect = {
                type: 'abs',
                x: left,
                y: top,
                width: rbb.width ?? 0,
                height: rbb.height ?? 0
              };
            }
          });
          console.log('[Face Detection] Usando MediaPipe (fallback)');
        } catch {
          mpFace = null;
        }
      }
    }

    // ============================================================================
    // HELPERS DE CÂMERA CROSS-BROWSER
    // ============================================================================

    /**
     * Solicita stream de câmera com suporte a navegadores antigos e iOS.
     * Centraliza o fallback para APIs legadas (webkitGetUserMedia, mozGetUserMedia).
     */
    async function requestCameraStream(constraints) {
      // API moderna — suportada em todos os browsers-alvo atuais
      if (navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function') {
        return navigator.mediaDevices.getUserMedia(constraints);
      }
      // Fallback para APIs legadas (Chrome < 53, Firefox < 36, Opera < 40)
      const legacyFn = navigator.getUserMedia
        || navigator.webkitGetUserMedia
        || navigator.mozGetUserMedia
        || navigator.msGetUserMedia;
      if (legacyFn) {
        return new Promise((resolve, reject) => {
          legacyFn.call(navigator, constraints, resolve, reject);
        });
      }
      throw new Error('Camera API não suportada neste navegador.');
    }

    /**
     * Atribui stream a um elemento de vídeo de forma cross-browser.
     * srcObject é padrão moderno; src+createObjectURL é o fallback para browsers antigos.
     */
    function assignStreamToVideo(videoEl, stream) {
      if ('srcObject' in videoEl) {
        videoEl.srcObject = stream;
      } else {
        // Fallback para navegadores que não suportam srcObject (Safari < 11, iOS < 11)
        try {
          videoEl.src = URL.createObjectURL(stream);
        } catch (e) {
          console.warn('[Camera] createObjectURL falhou:', e);
          videoEl.srcObject = stream;
        }
      }
    }

    /**
     * Remove stream de um elemento de vídeo de forma cross-browser.
     */
    function clearVideoStream(videoEl) {
      if (videoEl.srcObject) {
        videoEl.srcObject = null;
      } else if (videoEl.src && videoEl.src !== window.location.href) {
        try { URL.revokeObjectURL(videoEl.src); } catch (_) {}
        videoEl.src = '';
        videoEl.removeAttribute('src');
      }
    }

    async function startWebcam(preferFacing = 'user') {
      stopWebcam();
      statusEl.textContent = 'Abrindo câmera...';
      const constraints = {
        audio: false,
        video: {
          facingMode: {
            ideal: preferFacing
          },
          width: {
            ideal: 720
          },
          height: {
            ideal: 720
          }
        }
      };
      try {
        stream = await requestCameraStream(constraints);
        assignStreamToVideo(video, stream);
        await video.play().catch(() => {});
        const track = stream.getVideoTracks()[0];
        updateFacingUI(inferFacingFromTrack(track));

        // Mensagem diferente se offline
        if (!navigator.onLine) {
          statusEl.textContent = '📡 Offline: detecção facial desabilitada. Centralize o rosto e capture.';
        } else {
          statusEl.textContent = 'Câmera pronta. Centralize o rosto (captura automática ligada).';
        }

        camFallback.classList.add('d-none');
        fileInput.classList.add('d-none');
        camControls.classList.remove('d-none');
        capturedDataUrl = null;
        snapshot.style.display = 'none';
        video.style.display = 'block';
        btnRetake.classList.add('d-none');

        await initFaceEngines();
        startFaceGuide();
      } catch (err) {
        const denied = (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError'));
        statusEl.innerHTML = denied ? 'Permita o acesso à câmera nas permissões do navegador.' : 'Câmera indisponível. Use a câmera do aparelho.';
        camFallback.classList.remove('d-none');
        fileInput.classList.remove('d-none');
        camControls.classList.add('d-none');
        cancelFaceGuide();
      }
    }

    function stopWebcam() {
      if (stream) {
        stream.getTracks().forEach(t => t.stop());
        stream = null;
      }
      cancelFaceGuide();
    }

    // Troca de câmera
    camFront?.addEventListener('change', (e) => {
      if (e.target.checked) startWebcam('user').then(() => toast('info', 'Câmera frontal', 'Usando a câmera frontal.', []));
    });
    camBack?.addEventListener('change', (e) => {
      if (e.target.checked) {
        startWebcam('environment').then(() => {
          const track = stream?.getVideoTracks?.()[0];
          const facing = track ? inferFacingFromTrack(track) : 'user';
          if (facing !== 'environment') {
            toast('warning', 'Câmera traseira', 'Não foi possível abrir a câmera traseira. Continuando com a frontal.', []);
            updateFacingUI('user');
          } else {
            toast('info', 'Câmera traseira', 'Usando a câmera traseira.', []);
          }
        });
      }
    });
    btnFlipCam?.addEventListener('click', () => {
      const next = currentFacing === 'user' ? 'environment' : 'user';
      if (next === 'user') camFront.checked = true;
      else camBack.checked = true;
      camFront.dispatchEvent(new Event('change'));
      camBack.dispatchEvent(new Event('change'));
    });

    // Captura (apenas pelos botões solicitados)
    function downscaleToJpeg(srcCanvas, maxSide = 900, quality = 0.85) {
      const w = srcCanvas.width,
        h = srcCanvas.height;
      const ratio = Math.min(1, maxSide / Math.max(w, h));
      const dw = Math.round(w * ratio),
        dh = Math.round(h * ratio);
      const out = document.createElement('canvas');
      out.width = dw;
      out.height = dh;
      out.getContext('2d').drawImage(srcCanvas, 0, 0, dw, dh);
      return out.toDataURL('image/jpeg', quality);
    }

    function base64SizeBytes(dataUrl) {
      const i = dataUrl.indexOf(',');
      if (i < 0) return 0;
      const b64 = dataUrl.slice(i + 1);
      const padding = (b64.endsWith('==') ? 2 : (b64.endsWith('=') ? 1 : 0));
      return Math.max(0, Math.floor(b64.length * 3 / 4) - padding);
    }

    function getImageSizeFromDataUrl(dataUrl) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve({
          width: img.width,
          height: img.height,
          bytes: base64SizeBytes(dataUrl)
        });
        img.onerror = () => reject(new Error('Falha ao analisar a imagem.'));
        img.src = dataUrl;
      });
    }

    function takeSnapshotFromVideo() {
      const w = video.videoWidth || 720,
        h = video.videoHeight || 720;
      canvas.width = w;
      canvas.height = h;
      canvas.getContext("2d").drawImage(video, 0, 0, w, h);
      // OTIMIZAÇÃO V2: Reduzir qualidade para 0.6 e tamanho máximo para 500px (foto menor = upload mais rápido)
      // Reduz foto de ~300KB para ~200KB (35% menor) mantendo qualidade aceitável
      const dataUrl = downscaleToJpeg(canvas, 500, 0.6);
      snapshot.src = dataUrl;
      snapshot.style.display = 'block';
      video.style.display = 'none';
      btnRetake.classList.remove('d-none');
      return dataUrl;
    }

    function captureAuditPhotoFromVideo(videoEl) {
      if (!videoEl || videoEl.readyState < 2) return null;
      const w = videoEl.videoWidth || 640;
      const h = videoEl.videoHeight || 480;
      if (w <= 0 || h <= 0) return null;
      const c = document.createElement('canvas');
      c.width = w;
      c.height = h;
      c.getContext('2d').drawImage(videoEl, 0, 0, w, h);
      return downscaleToJpeg(c, 500, 0.6);
    }

    async function fileToDataURL(file, maxSide = 500, quality = 0.6) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
          const img = new Image();
          img.onload = () => {
            const cnv = document.createElement('canvas');
            const ratio = Math.min(1, maxSide / Math.max(img.width, img.height));
            cnv.width = Math.round(img.width * ratio);
            cnv.height = Math.round(img.height * ratio);
            cnv.getContext('2d').drawImage(img, 0, 0, cnv.width, cnv.height);
            resolve(cnv.toDataURL('image/jpeg', quality));
          };
          img.onerror = () => reject(new Error('Falha ao ler imagem.'));
          img.src = reader.result;
        };
        reader.onerror = () => reject(new Error('Falha ao carregar arquivo.'));
        reader.readAsDataURL(file);
      });
    }

    // Geolocalização
    // ========================================
    // ANTI-FRAUDE: Device Fingerprint
    // ========================================
    async function generateDeviceFingerprint() {
      try {
        // Canvas fingerprint
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#f60';
        ctx.fillRect(125, 1, 62, 20);
        ctx.fillStyle = '#069';
        ctx.fillText('DeviceFingerprint', 2, 15);
        const canvasHash = canvas.toDataURL().slice(-50);

        // WebGL renderer (GPU-specific)
        let webglRenderer = '';
        try {
          const glCanvas = document.createElement('canvas');
          const gl = glCanvas.getContext('webgl') || glCanvas.getContext('experimental-webgl');
          if (gl) {
            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
              webglRenderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || '';
            }
          }
        } catch (e) {}

        // AudioContext fingerprint
        let audioHash = '';
        try {
          const audioCtx = new (window.OfflineAudioContext || window.webkitOfflineAudioContext)(1, 44100, 44100);
          const oscillator = audioCtx.createOscillator();
          oscillator.type = 'triangle';
          oscillator.frequency.setValueAtTime(10000, audioCtx.currentTime);
          const compressor = audioCtx.createDynamicsCompressor();
          oscillator.connect(compressor);
          compressor.connect(audioCtx.destination);
          oscillator.start(0);
          const renderedBuffer = await audioCtx.startRendering();
          const data = renderedBuffer.getChannelData(0);
          let sum = 0;
          for (let i = 4500; i < 5000; i++) sum += Math.abs(data[i]);
          audioHash = sum.toFixed(6);
        } catch (e) {}

        const components = [
          navigator.userAgent || '',
          navigator.language || '',
          screen.width + 'x' + screen.height,
          screen.colorDepth || '',
          new Date().getTimezoneOffset(),
          canvasHash,
          navigator.hardwareConcurrency || '',
          navigator.deviceMemory || '',
          webglRenderer,
          audioHash,
          navigator.platform || ''
        ];

        // SHA-256 hash (cryptographically strong)
        const str = components.join('|');
        if (window.crypto && window.crypto.subtle) {
          const encoded = new TextEncoder().encode(str);
          const hashBuffer = await crypto.subtle.digest('SHA-256', encoded);
          const hashArray = Array.from(new Uint8Array(hashBuffer));
          return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        }
        // Fallback para navegadores sem crypto.subtle
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
          const char = str.charCodeAt(i);
          hash = ((hash << 5) - hash) + char;
          hash = hash & hash;
        }
        return Math.abs(hash).toString(36);
      } catch (e) {
        return 'unknown_' + Date.now();
      }
    }

    // ========================================
    // ANTI-FRAUDE: Detecção de GPS Mock
    // ========================================
    let lastGeoCheck = {lat: null, lng: null, timestamp: null};
    
    function detectGpsMock(position) {
      const fraudIndicators = [];
      let mockDetected = false;
      
      // 1. Verifica se navigator.geolocation.mocked existe (flag não-standard, apenas em alguns Android/apps)
      try {
        if (navigator.geolocation && navigator.geolocation.mocked === true) {
          fraudIndicators.push('navigator_mocked_flag');
          mockDetected = true;
        }
      } catch (_) { /* propriedade não existe ou inacessível no browser atual */ }
      
      // 2. Precisão suspeitosamente perfeita (<5m constantemente)
      if (position.coords.accuracy < 5) {
        fraudIndicators.push('accuracy_too_perfect');
      }
      
      // 3. Timestamp do GPS vs timestamp do dispositivo
      const now = Date.now();
      const gpsTime = position.timestamp;
      const timeDiff = Math.abs(now - gpsTime);
      if (timeDiff > 5000) { // Mais de 5 segundos de diferença
        fraudIndicators.push('timestamp_mismatch');
      }
      
      // 4. Mudança de localização impossível (>50km em <1min)
      if (lastGeoCheck.lat && lastGeoCheck.lng && lastGeoCheck.timestamp) {
        const timeDeltaMin = (now - lastGeoCheck.timestamp) / 60000;
        if (timeDeltaMin > 0 && timeDeltaMin < 60) { // Apenas se < 1 hora
          const distance = calculateDistance(
            lastGeoCheck.lat, lastGeoCheck.lng,
            position.coords.latitude, position.coords.longitude
          );
          const speedKmPerMin = distance / timeDeltaMin;
          if (speedKmPerMin > 1.5) { // >90km/h
            fraudIndicators.push('impossible_speed');
            mockDetected = true;
          }
        }
      }
      
      // Atualiza última checagem
      lastGeoCheck = {
        lat: position.coords.latitude,
        lng: position.coords.longitude,
        timestamp: now
      };
      
      return {
        mockDetected,
        indicators: fraudIndicators,
        riskLevel: mockDetected ? 2 : (fraudIndicators.length > 0 ? 1 : 0)
      };
    }
    
    // Calcula distância entre dois pontos (Haversine)
    function calculateDistance(lat1, lon1, lat2, lon2) {
      const R = 6371; // Raio da Terra em km
      const dLat = (lat2 - lat1) * Math.PI / 180;
      const dLon = (lon2 - lon1) * Math.PI / 180;
      const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLon/2) * Math.sin(dLon/2);
      const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
      return R * c;
    }

    // ========================================
    // Geolocalização com Validação Anti-Fraude
    // ========================================
    function getGeo() {
      return new Promise((resolve) => {
        if (!('geolocation' in navigator)) return resolve(null);
        navigator.geolocation.getCurrentPosition(
          async (pos) => {
            const fraudCheck = detectGpsMock(pos);
            resolve({
              lat: pos.coords.latitude,
              lng: pos.coords.longitude,
              acc: pos.coords.accuracy,
              fraudCheck: fraudCheck,
              deviceFingerprint: await generateDeviceFingerprint()
            });
          },
          () => resolve(null), {
            enableHighAccuracy: true,
            timeout: 12000,
            maximumAge: 30000  // aceita leitura de até 30s atrás (usuário está parado no mesmo local)
          }
        );
      });
    }
    async function ensureGeoForModal() {
      const geo = await getGeo();
      cachedGeo = geo;
      // Intencionalmente sem exibição de localização na UI.
    }

    // Helpers de UI
    function escHtml(s) {
      return String(s ?? '').replace(/[&<>"'`=\/]/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
        '`': '&#96;',
        '=': '&#61;',
        '/': '&#47;'
      } [c]));
    }

    /**
     * Helper global de tradução `action` → label legível em PT-BR.
     * Cobre os 4 valores possíveis (`entrada`, `saída`, intervalo iniciado, intervalo retornado),
     * tanto nos códigos crus (`iniciar_intervalo`, `saida` sem acento) quanto nos rótulos
     * em PT-BR retornados pelo backend (`início do intervalo`, `retorno do intervalo`).
     *
     * Fonte única de verdade para evitar drift entre tela de sucesso, comprovante,
     * card "Última batida" e toasts.
     */
    function actionLabel(action) {
      const v = String(action || '').toLowerCase().trim();
      if (['saida para intervalo', 'saída para intervalo'].includes(v)) return 'Saída para intervalo';
      if (['saida', 'saída', 'out', 'checkout', 'check_out', 'exit', 'saida_1'].includes(v)) return 'Saída';
      if (['iniciar_intervalo', 'início do intervalo', 'inicio do intervalo', 'break_start', 'pause'].includes(v)) return 'Início do intervalo';
      if (['retornar_intervalo', 'retorno do intervalo', 'break_end', 'resume'].includes(v)) return 'Retorno do intervalo';
      if (['entrada', 'in', 'enter', 'checkin', 'check_in', 'entry', 'entrada_1'].includes(v)) return 'Entrada';
      return v ? v.charAt(0).toUpperCase() + v.slice(1) : '—';
    }

    // Alias retrocompatível para o helper anterior usado em buildDetailsHTML/toasts.
    // Mantém a mesma assinatura, agora cobrindo intervalo.
    function normalizeActionLabel(val) {
      return actionLabel(val);
    }

    function mapPendingReasonLabel(reason) {
      const key = String(reason || '').toLowerCase().trim();
      const normalized = key.normalize ? key.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : key;
      const labels = {
        out_of_radius: 'Você estava fora da área permitida da instituição no momento da batida.',
        out_of_perimeter: 'Você estava fora da área permitida da instituição no momento da batida.',
        no_gps: 'Localização não habilitada no dispositivo no momento da batida.',
        no_location: 'Localização não habilitada no dispositivo no momento da batida.',
        gps_accuracy_low: 'O GPS do aparelho estava com baixa precisão no momento da batida.',
        gps_low_accuracy: 'O GPS do aparelho estava com baixa precisão no momento da batida.',
        no_school_linked: 'Seu cadastro não está vinculado a uma instituição. Procure o administrador.',
        school_not_configured: 'A instituição ainda não possui localização configurada.',
        hlb_drift_excessive: 'O horário do aparelho estava diferente do horário oficial.',
        'cadastro facial pendente': 'Seu cadastro facial ainda está pendente.',
        'registro em lote (offline sync)': 'Registro feito offline e aguardando revisão.',
      };
      if (labels[normalized]) return labels[normalized];
      if (normalized.includes('fora do raio') || normalized.includes('fora do per')) return labels.out_of_radius;
      if (normalized.includes('sem gps') || normalized.includes('localiza')) return labels.no_gps;
      if (normalized.includes('precis')) return labels.gps_accuracy_low;
      if (normalized.includes('cadastro facial') || normalized.includes('face')) return labels['cadastro facial pendente'];
      return reason || 'Validação pendente.';
    }

    function extractPendingReasons(data, localReasons = []) {
      const apiReasons = Array.isArray(data?.pending_reasons) ? data.pending_reasons : [];
      if (apiReasons.length > 0) return apiReasons.map(mapPendingReasonLabel);
      if (Array.isArray(localReasons) && localReasons.length > 0) return localReasons.map(mapPendingReasonLabel);
      if (data?.approved === false) return ['Validação pendente'];
      return [];
    }

    function buildDetailsHTML(data) {
      const name = data.name || data.teacher?.name || data.employee_name || data.employee?.name || '';
      const acao = normalizeActionLabel(data.action) || '';
      const hora = data.time || new Date().toLocaleTimeString('pt-BR');
      const dataBR = data.date || new Date().toLocaleDateString('pt-BR');
      const pendingReasons = extractPendingReasons(data);
      const isPending = data?.approved === false || pendingReasons.length > 0;
      const statusHTML = isPending
        ? `<div><b>Status:</b> Pendente (${escHtml(pendingReasons.join(', ') || 'aguardando aprovação')})</div>`
        : `<div><b>Status:</b> Aprovado</div>`;
      
      // Portaria 671/2021 - Exibir NSR (Número Sequencial de Registro)
      const nsrHTML = data.nsr ? `<div style="margin-top:0.5rem"><b>NSR:</b> ${data.nsr}</div>` : '';
      const recordModeHTML = data.record_mode ? 
        `<div><b>Modo:</b> ${data.record_mode === 'online' ? 'Online' : 'Offline (sincronizado)'}</div>` : '';
      
      return `
        <div style="font-size:2rem;font-weight:700">✅ Ponto registrado!</div>
        ${name ? `<div style="margin-top:0.75rem;font-size:1.4rem;font-weight:600">${escHtml(name)}</div>` : ''}
        <div style="margin-top:0.75rem;font-size:1.1rem">
          ${acao ? `<div><b>Ponto:</b> ${escHtml(acao)}</div>` : ''}
          <div><b>Hora:</b> ${escHtml(hora)}</div>
          <div><b>Data:</b> ${escHtml(dataBR)}</div>
          ${statusHTML}
          ${nsrHTML}
          ${recordModeHTML}
        </div>
        <div style="margin-top:1.5rem;font-size:0.85rem;opacity:0.8;border-top:1px solid rgba(0,0,0,0.1);padding-top:1rem">
          <div>Sistema: <?= esc(SYSTEM_NAME) ?> v<?= esc(SYSTEM_VERSION) ?></div>
          <div>Categoria: REP-P - Portaria MTP 671/2021</div>
        </div>`;
    }

    function showFullScreenAlert(type, htmlContent, autoReload = false) {
      const div = document.createElement('div');
      div.className = `full-alert ${type}`;
      div.innerHTML = `<div>${htmlContent}</div>`;
      document.body.appendChild(div);
      if (type === 'success') vibrate(40);
      if (type === 'error') vibrate(80);
      if (autoReload) setTimeout(() => {
        location.reload();
      }, 4000);
      else {
        // M7: duração proporcional ao tamanho do conteúdo (mín 3200ms, máx 8000ms)
        const charCount = (message ? message.length : 0) + (title ? title.length : 0)
                        + (Array.isArray(hints) ? hints.reduce((s,h)=>s+(h||'').length,0) : 0);
        const dynDuration = Math.min(8000, Math.max(3200, charCount * 45));
        setTimeout(() => div.remove(), dynDuration);
      }
    }

    // ─── Cadastro Facial Inline ──────────────────────────────────────────────────

    /** Salva descritores faciais via CPF + token de sessão temporário */
    async function saveFaceInline(cpf, descriptors, enrollmentToken) {
      const fp = cachedGeo?.deviceFingerprint || await generateDeviceFingerprint();
      const res = await fetch((ROOT_BASE || '') + '/api/save_face_inline.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cpf: cpf, descriptors: descriptors, enrollment_token: enrollmentToken, deviceFingerprint: fp })
      });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Erro ao salvar rosto.');
      }
      return res.json();
    }

    /**
     * Mostra tela de oferta de cadastro facial após check-in via CPF.
     * Captura 5 amostras automaticamente e salva pelo endpoint público.
     */
    async function showFaceEnrollmentOffer(teacherId, cpf, enrollmentToken, checkinData, pendingNote = '', skipFinalAlert = false) {
      const STEPS = [
        { id:'center', label:'Olhe para a câmera',                   icon:'bi-circle-fill' },
        { id:'left',   label:'Vire o rosto para a esquerda  \u2190', icon:'bi-arrow-left-circle-fill' },
        { id:'right',  label:'Vire o rosto para a direita  \u2192',  icon:'bi-arrow-right-circle-fill' },
      ];
      const SAMPLES_NEEDED = 3;
      const TICK_MS = 250;
      const STABLE_MS = 400;

      // Injetar animação pulse uma única vez
      if (!document.getElementById('fe-pulse-style')) {
        const s = document.createElement('style');
        s.id = 'fe-pulse-style';
        s.textContent = '@keyframes fe-pulse{0%,100%{opacity:1}50%{opacity:0.5}}.fe-arc-active{animation:fe-pulse 1.2s ease-in-out infinite}.fe-dot-active{animation:fe-pulse 1.2s ease-in-out infinite}';
        document.head.appendChild(s);
      }

      return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.id = 'faceEnrollOverlay';
        overlay.style.cssText = `
          position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;
          background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);
          display:flex;flex-direction:column;align-items:center;justify-content:flex-start;
          padding:1.5rem 1rem;box-sizing:border-box;overflow-y:auto;
        `;

        // SVG ring: r=127, circunferência≈797.96px, segmentos dinâmicos baseados em STEPS.length
        const circ = 797.96, gapPx = 4, numSegs = STEPS.length;
        const segLen = (circ - gapPx * numSegs) / numSegs;
        const segTotal = segLen + gapPx;
        const arcsHTML = Array.from({length: numSegs}, (_,i) =>
          `<circle id="feArc${i}" cx="140" cy="140" r="127" fill="none"
            stroke="rgba(100,116,139,0.35)" stroke-width="6"
            stroke-dasharray="${segLen.toFixed(2)} ${(circ-segLen).toFixed(2)}"
            stroke-dashoffset="${(-i*segTotal).toFixed(2)}"
            transform="rotate(-90 140 140)"
            style="transition:stroke .4s,stroke-width .2s"/>`
        ).join('');

        const dotsHTML = Array.from({length: numSegs}, (_,i) =>
          `<div id="feD${i}" style="width:14px;height:14px;border-radius:50%;background:rgba(100,116,139,0.4);display:flex;align-items:center;justify-content:center;font-size:9px;color:#fff;transition:background .3s,color .3s"></div>`
        ).join('');

        overlay.innerHTML = `
          ${(!checkinData && skipFinalAlert)
            ? `<div style="background:rgba(59,130,246,0.15);border:1.5px solid rgba(59,130,246,0.4);
                border-radius:14px;padding:0.8rem 1.2rem;width:100%;max-width:420px;
                text-align:center;color:#93c5fd;font-size:0.88rem;margin-bottom:1.2rem;box-sizing:border-box;">
                <b>📸 Refazendo cadastro facial</b><br>
                <span style="color:#94a3b8;font-size:0.82rem;">Após concluir, registre seu ponto na tela inicial.</span>
              </div>`
            : `<div id="feSuccessBanner" style="background:rgba(34,197,94,0.15);border:1.5px solid rgba(34,197,94,0.4);
                border-radius:14px;padding:0.8rem 1.2rem;width:100%;max-width:420px;
                text-align:center;color:#4ade80;font-size:0.95rem;margin-bottom:1.2rem;
                ${(checkinData && !skipFinalAlert) ? '' : 'display:none;'}">✅ <b>Ponto registrado com sucesso!</b></div>`
          }

          <div style="text-align:center;color:#f1f5f9;margin-bottom:0.8rem;max-width:400px">
            <div style="font-size:1.3rem;font-weight:700">📸 Cadastro Facial</div>
            <div id="feStepLabel" style="font-size:0.9rem;color:#94a3b8;margin-top:0.3rem">Olhe para a câmera</div>
          </div>

          <div style="position:relative;width:280px;height:280px;margin:0 auto 1rem;flex-shrink:0">
            <svg id="feRingSvg" viewBox="0 0 280 280" width="280" height="280"
                 style="position:absolute;top:0;left:0;z-index:5;pointer-events:none">
              <circle cx="140" cy="140" r="127" fill="none"
                      stroke="rgba(255,255,255,0.06)" stroke-width="6"/>
              ${arcsHTML}
            </svg>

            <div id="feDirectionIcon" style="
              position:absolute;top:50%;left:50%;
              transform:translate(-50%,-50%);
              z-index:6;pointer-events:none;
              font-size:2.5rem;color:rgba(255,255,255,0.85);
              text-shadow:0 2px 8px rgba(0,0,0,0.5);
              transition:opacity .3s
            "><i class="bi bi-circle-fill"></i></div>

            <video id="feVideo" autoplay muted playsinline style="
              position:absolute;top:10px;left:10px;
              width:260px;height:260px;
              object-fit:cover;border-radius:50%;
              display:block;background:#000;z-index:1
            "></video>

            <div id="feRing" style="
              position:absolute;top:50%;left:50%;
              transform:translate(-50%,-50%);
              width:150px;height:190px;border-radius:50%;
              border:2px solid rgba(255,255,255,0.45);
              z-index:3;pointer-events:none;
              transition:border-color .3s,box-shadow .3s
            "></div>

            <div id="feFlash" style="
              position:absolute;top:10px;left:10px;
              width:260px;height:260px;border-radius:50%;
              background:rgba(255,255,255,0.85);opacity:0;
              pointer-events:none;z-index:4;transition:opacity .12s
            "></div>
            <canvas id="feCanvas" style="display:none"></canvas>
          </div>

          <div id="feStepDots" style="display:flex;gap:10px;margin-bottom:0.6rem">
            ${dotsHTML}
          </div>

          <div id="feStepCounter" style="font-size:0.8rem;color:#64748b;margin-bottom:0.9rem">
            Passo 1 de ${STEPS.length}
          </div>

          <div id="feMsg" style="
            font-size:0.88rem;color:#94a3b8;min-height:1.4em;
            margin-bottom:1rem;text-align:center;max-width:340px
          ">Iniciando câmera...</div>

          <button id="feSkipBtn" style="
            padding:0.7rem 2rem;
            border:1.5px solid rgba(100,116,139,0.5);
            background:transparent;color:#94a3b8;
            border-radius:12px;font-size:0.9rem;cursor:pointer
          ">${(!checkinData && skipFinalAlert) ? 'Cancelar' : 'Pular'}</button>
        `;

        document.body.appendChild(overlay);
        vibrate(30);

        const video       = overlay.querySelector('#feVideo');
        const ring        = overlay.querySelector('#feRing');
        const flash       = overlay.querySelector('#feFlash');
        const msg         = overlay.querySelector('#feMsg');
        const skipBtn     = overlay.querySelector('#feSkipBtn');
        const stepLabel   = overlay.querySelector('#feStepLabel');
        const stepCounter = overlay.querySelector('#feStepCounter');
        const dirIcon     = overlay.querySelector('#feDirectionIcon');

        let stream = null, tickTimer = null, done = false;
        let currentStepIdx = 0, stableStart = null, samples = [];
        let neutralPitch = null, neutralCalibN = 0; // calibração dinâmica do pitch neutro
        async function finish(enrolled) {
          if (done) return; // Prevenir chamadas duplas
          done = true;
          console.log('[FaceEnroll] finish() called, enrolled:', enrolled, 'autoCheckin:', window._autoCheckinAfterEnroll);
          if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
          if (stream) { try { stream.getTracks().forEach(t => t.stop()); } catch(e) {} stream = null; }
          try { overlay.remove(); } catch(e) {}
          if (window._autoCheckinAfterEnroll) {
            // Veio do fluxo CPF — resolve e deixa runPreview() submeter o ponto
            resolve();
            return;
          }
          if (!checkinData && skipFinalAlert) {
            resolve();
            setTimeout(() => location.reload(), 80);
            return;
          }
          if (!skipFinalAlert) {
            const detailsHTML = buildDetailsHTML(checkinData || {});
            if (enrolled) {
              showFullScreenAlert('success',
                detailsHTML + pendingNote +
                '<div style="margin-top:1rem;padding:0.6rem 1rem;background:rgba(34,197,94,0.15);' +
                'border-radius:8px;color:#4ade80;font-size:0.88rem;">Rosto cadastrado! Registrando seu ponto...</div>',
                true);
            } else {
              showFullScreenAlert('success', detailsHTML + pendingNote, true);
            }

            // G6: Prompt de re-enrollment para cadastros faciais antigos
            if (checkinData?.reenroll_recommended) {
              const dias = checkinData.reenroll_days_since || '?';
              setTimeout(() => {
                toast('info', 'Atualização facial sugerida',
                  `Seu cadastro facial tem ${dias} dias. Solicite atualização ao administrador para manter a precisão.`, []);
              }, 3500);
            }
          }
          resolve();
        }

        skipBtn.addEventListener('click', () => finish(false));

        function doFlash() {
          flash.style.opacity = '0.85';
          setTimeout(() => { flash.style.opacity = '0'; }, 120);
        }

        function setRingState(state) {
          if (state === 'idle') {
            ring.style.borderColor = 'rgba(100,116,139,0.5)';
            ring.style.boxShadow = '';
          } else if (state === 'face') {
            ring.style.borderColor = 'rgba(255,255,255,0.5)';
            ring.style.boxShadow = '';
          } else if (state === 'zone') {
            ring.style.borderColor = '#22c55e';
            ring.style.boxShadow = '0 0 12px 4px rgba(34,197,94,0.4)';
          } else if (state === 'saving') {
            ring.style.borderColor = '#3b82f6';
            ring.style.boxShadow = '0 0 12px 4px rgba(59,130,246,0.5)';
          }
        }

        function updateStepUI(stepIdx) {
          for (let i = 0; i < STEPS.length; i++) {
            const arc = overlay.querySelector(`#feArc${i}`);
            const dot = overlay.querySelector(`#feD${i}`);
            if (!arc || !dot) continue;
            if (i < stepIdx) {
              arc.setAttribute('stroke', '#22c55e');
              arc.setAttribute('stroke-width', '6');
              arc.classList.remove('fe-arc-active');
              dot.style.background = '#22c55e';
              dot.textContent = '✓';
              dot.classList.remove('fe-dot-active');
            } else if (i === stepIdx) {
              arc.setAttribute('stroke', '#f59e0b');
              arc.setAttribute('stroke-width', '8');
              arc.classList.add('fe-arc-active');
              dot.style.background = '#f59e0b';
              dot.textContent = '';
              dot.classList.add('fe-dot-active');
            } else {
              arc.setAttribute('stroke', 'rgba(100,116,139,0.35)');
              arc.setAttribute('stroke-width', '6');
              arc.classList.remove('fe-arc-active');
              dot.style.background = 'rgba(100,116,139,0.4)';
              dot.textContent = '';
              dot.classList.remove('fe-dot-active');
            }
          }
          if (stepIdx < STEPS.length) {
            const step = STEPS[stepIdx];
            stepLabel.textContent = step.label;
            dirIcon.innerHTML = `<i class="bi ${step.icon}"></i>`;
            stepCounter.textContent = `Passo ${stepIdx + 1} de ${STEPS.length}`;
          }
        }

        function estimatePose(landmarks) {
          const pts = landmarks.positions;
          const leftEdge = pts[0], rightEdge = pts[16];
          const faceWidth = rightEdge.x - leftEdge.x;
          const faceCenterX = (leftEdge.x + rightEdge.x) / 2;
          const eyeCenterY = ((pts[37].y+pts[38].y+pts[40].y+pts[41].y) +
                              (pts[43].y+pts[44].y+pts[46].y+pts[47].y)) / 8;
          // YAW: usa ponte do nariz (pts[30]) — funciona bem para esq/dir
          const yaw = faceWidth > 0 ? (pts[30].x - faceCenterX) / faceWidth : 0;
          // PITCH: usa ponta real do nariz (pts[33] = centro entre as narinas)
          // pts[33] fica mais baixo no rosto e se move mais com inclinação do que pts[30]
          const pitchRaw = faceWidth > 0 ? (pts[33].y - eyeCenterY) / faceWidth : 0;
          return { yaw, pitchRaw };
        }

        function inStepZone(step, yaw, pitch) {
          // pitch aqui já é calibrado (pitchRaw - neutralPitch)
          const ay = Math.abs(yaw);
          switch (step.id) {
            case 'center': return ay < 0.12;
            // Câmera selfie espelhada: yaw>0 no frame raw = usuário virou p/ esquerda
            case 'left':   return yaw >  0.12;
            case 'right':  return yaw < -0.12;
          }
          return false;
        }

        async function tick() {
          if (done || video.readyState < 2) return;
          try {
            const det = await faceapi
              .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.3 }))
              .withFaceLandmarks(true)
              .withFaceDescriptor();

            if (!det) {
              stableStart = null;
              setRingState('idle');
              msg.textContent = 'Posicione seu rosto na câmera…';
              return;
            }

            // Verificar centralização mínima
            const vw = video.videoWidth || 640, vh = video.videoHeight || 480;
            const box = det.detection.box;
            const cx = box.x + box.width / 2, cy = box.y + box.height / 2;
            const coverage = (box.width * box.height) / (vw * vh);
            if (Math.abs(cx / vw - 0.5) > 0.35 || Math.abs(cy / vh - 0.5) > 0.42 || coverage < 0.03) {
              stableStart = null;
              setRingState('idle');
              msg.textContent = 'Centralize seu rosto na câmera.';
              return;
            }

            const pose = estimatePose(det.landmarks);

            // Calibrar pitch neutro via EMA sempre que o rosto estiver frontal (|yaw| < 0.15)
            // Isso corrige variações por ângulo da câmera e geometria individual de cada pessoa
            if (Math.abs(pose.yaw) < 0.15 && neutralCalibN < 40) {
              neutralCalibN++;
              const alpha = neutralCalibN < 5 ? 0.5 : 0.20;
              neutralPitch = neutralPitch === null
                ? pose.pitchRaw
                : neutralPitch * (1 - alpha) + pose.pitchRaw * alpha;
            }
            // pitch calibrado: zero = posição neutra do usuário específico
            const pitch = pose.pitchRaw - (neutralPitch ?? pose.pitchRaw);

            const step = STEPS[currentStepIdx];
            const inZone = inStepZone(step, pose.yaw, pitch);

            if (!inZone) {
              stableStart = null;
              setRingState('face');
              msg.textContent = step.label;
              return;
            }

            // Na zona correta
            setRingState('zone');
            if (!stableStart) stableStart = Date.now();
            const elapsed = Date.now() - stableStart;
            if (elapsed < STABLE_MS) {
              msg.textContent = `${step.label} — mantenha…`;
              return;
            }

            // Capturar!
            stableStart = null;
            samples.push(Array.from(det.descriptor));
            doFlash();
            vibrate(20);

            const capturedStep = STEPS[currentStepIdx];
            currentStepIdx++;
            updateStepUI(currentStepIdx);
            msg.textContent = `✅ ${capturedStep.label} capturado!`;

            if (currentStepIdx >= STEPS.length) {
              clearInterval(tickTimer);
              setRingState('saving');
              msg.textContent = 'Salvando seu rosto…';
              try {
                await saveFaceInline(cpf, samples, enrollmentToken);
                vibrate([40, 80, 40]);

                // Fase 1 — cadastro facial confirmado
                if (!checkinData && skipFinalAlert) {
                  // Fluxo "Refazer cadastro facial" — aviso em evidência, tempo maior
                  overlay.innerHTML = `
                    <div style="
                      display:flex;flex-direction:column;align-items:center;justify-content:center;
                      height:100%;text-align:center;padding:2rem;box-sizing:border-box;gap:1.4rem;
                    ">
                      <div style="
                        width:100px;height:100px;border-radius:50%;
                        background:rgba(34,197,94,0.15);border:3px solid #22c55e;
                        display:flex;align-items:center;justify-content:center;
                        animation:fe-pulse 1s ease-in-out 2;
                      ">
                        <i class="bi bi-shield-check" style="font-size:3rem;color:#4ade80;"></i>
                      </div>
                      <div style="color:#4ade80;font-size:1.6rem;font-weight:700;line-height:1.2;">
                        Cadastro facial<br>concluído!
                      </div>
                      <div style="
                        background:rgba(251,191,36,0.12);border:2px solid rgba(251,191,36,0.55);
                        border-radius:16px;padding:1.1rem 1.4rem;max-width:340px;
                        display:flex;flex-direction:column;gap:0.6rem;
                      ">
                        <div style="color:#fbbf24;font-size:1rem;font-weight:700;">
                          ⚠️ Isso não registrou seu ponto!
                        </div>
                        <div style="color:#e2e8f0;font-size:0.9rem;line-height:1.5;">
                          O cadastro facial foi salvo.<br>
                          Para registrar seu ponto, volte à<br>
                          <b>tela inicial</b> e use o<br>
                          <b>reconhecimento facial</b>.
                        </div>
                      </div>
                      <div style="color:#64748b;font-size:0.8rem;">Redirecionando em instantes…</div>
                    </div>`;
                  await new Promise(r => setTimeout(r, 6000));
                } else {
                  overlay.innerHTML = `
                    <div style="
                      display:flex;flex-direction:column;align-items:center;justify-content:center;
                      height:100%;text-align:center;padding:2rem;box-sizing:border-box;gap:1.2rem;
                    ">
                      <div style="
                        width:110px;height:110px;border-radius:50%;
                        background:rgba(34,197,94,0.15);border:3px solid #22c55e;
                        display:flex;align-items:center;justify-content:center;
                        animation:fe-pulse 1s ease-in-out 2;
                      ">
                        <i class="bi bi-shield-check" style="font-size:3.2rem;color:#4ade80;"></i>
                      </div>
                      <div style="color:#4ade80;font-size:1.7rem;font-weight:700;line-height:1.2;">
                        Rosto cadastrado<br>com sucesso!
                      </div>
                      <div style="color:#94a3b8;font-size:0.9rem;">
                        Seus próximos registros de ponto<br>serão feitos usando o reconhecimento facial.
                      </div>
                    </div>`;
                  await new Promise(r => setTimeout(r, 2500));
                }

                // Fase 2 — recibo do ponto dentro do mesmo overlay (4 s)
                if (checkinData) {
                  const d = checkinData;
                  const nome    = escHtml(d.teacher?.name || d.collaborator?.name || d.name || '');
                  const acao    = escHtml(normalizeActionLabel(d.action) || '—');
                  const hora    = escHtml(d.time  || new Date().toLocaleTimeString('pt-BR'));
                  const data_br = escHtml(d.date  || new Date().toLocaleDateString('pt-BR'));
                  const nsr     = d.nsr ? `<div><b>NSR:</b> ${escHtml(String(d.nsr))}</div>` : '';
                  const status  = d.approved === false
                    ? '<div style="color:#fbbf24"><b>Status:</b> Pendente de aprovação</div>'
                    : '<div style="color:#4ade80"><b>Status:</b> Aprovado</div>';
                  overlay.innerHTML = `
                    <div style="
                      display:flex;flex-direction:column;align-items:center;justify-content:center;
                      height:100%;text-align:center;padding:2rem;box-sizing:border-box;gap:0.9rem;color:#fff;
                    ">
                      <div style="font-size:2rem;font-weight:700;color:#4ade80;">✅ Ponto registrado!</div>
                      <div style="font-size:1rem;line-height:1.9;background:rgba(255,255,255,0.07);
                                  border-radius:12px;padding:0.8rem 1.4rem;text-align:left;min-width:220px;">
                        <div><b>Ponto:</b> ${acao}</div>
                        <div><b>Hora:</b> ${hora}</div>
                        <div><b>Data:</b> ${data_br}</div>
                        ${nome ? `<div><b>Nome:</b> ${nome}</div>` : ''}
                        ${status}
                        ${nsr}
                      </div>
                      <div style="
                        background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);
                        border-radius:20px;padding:0.45rem 1.2rem;color:#86efac;font-size:0.82rem;
                      ">
                        <i class="bi bi-shield-check"></i> Reconhecimento facial ativado!
                      </div>
                      ${pendingNote ? `<div style="font-size:0.85rem;color:#fbbf24;">${pendingNote}</div>` : ''}
                    </div>`;
                  await new Promise(r => setTimeout(r, 4000));
                }

                // Cleanup direto — não passar por finish(true) para evitar race com resolve()
                done = true;
                if (tickTimer) clearInterval(tickTimer);
                if (stream) stream.getTracks().forEach(t => t.stop());
                overlay.remove();
                if (window._autoCheckinAfterEnroll) {
                  // Cadastro veio do fluxo CPF — mostrar mensagem e resolve para submeter ponto
                  overlay.innerHTML = `
                    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                      height:100%;text-align:center;padding:2rem;gap:1.2rem;">
                      <div style="width:80px;height:80px;border-radius:50%;background:rgba(34,197,94,0.15);
                        border:3px solid #22c55e;display:flex;align-items:center;justify-content:center;
                        animation:fe-pulse 1s ease-in-out 2;">
                        <i class="bi bi-shield-check" style="font-size:2.5rem;color:#4ade80;"></i>
                      </div>
                      <div style="color:#4ade80;font-size:1.5rem;font-weight:700;">Cadastro concluído!</div>
                      <div style="color:#94a3b8;font-size:1rem;">Registrando seu ponto automaticamente...</div>
                    </div>`;
                  await new Promise(r => setTimeout(r, 1500));
                  done = true;
                  if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
                  if (stream) { try { stream.getTracks().forEach(t => t.stop()); } catch(e) {} stream = null; }
                  try { overlay.remove(); } catch(e) {}
                  resolve();
                  return;
                } else if (checkinData) {
                  resolve();
                  setTimeout(() => location.reload(), 80);
                } else if (skipFinalAlert) {
                  resolve();
                  setTimeout(() => location.reload(), 80);
                } else {
                  showFullScreenAlert('success',
                    buildDetailsHTML({}) + pendingNote +
                    '<div style="margin-top:1rem;padding:0.6rem 1rem;background:rgba(34,197,94,0.15);' +
                    'border-radius:8px;color:#4ade80;font-size:0.88rem;">Rosto cadastrado! Registrando seu ponto...</div>',
                    true);
                  resolve();
                }
              } catch (err) {
                console.error('[FaceEnroll]', err);
                msg.textContent = `⚠️ ${err.message || 'Erro ao salvar rosto.'}`;
                setTimeout(() => finish(false), 4000);
              }
            }
          } catch (err) {
            // Ignorar erros individuais de detecção
          }
        }

        // Iniciar câmera — parar qualquer stream anterior para evitar conflito
        (async () => {
          try {
            // Parar streams anteriores que possam estar ativos
            if (typeof fullscreenStream !== 'undefined' && fullscreenStream) {
              try { fullscreenStream.getTracks().forEach(t => t.stop()); } catch(e) {}
            }
            // Pequeno delay para garantir que a câmera foi liberada pelo SO
            await new Promise(r => setTimeout(r, 200));

            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 }, audio: false });
            video.srcObject = stream;
            await new Promise(r => { video.onloadedmetadata = r; });
            await video.play();

            if (!faceApiReady || !faceDescriptorReady) {
              msg.textContent = 'Carregando modelos de IA…';
              const deadline = Date.now() + 15000;
              while ((!faceApiReady || !faceDescriptorReady) && Date.now() < deadline) {
                await new Promise(r => setTimeout(r, 300));
              }
            }

            updateStepUI(0);
            msg.textContent = STEPS[0].label;
            tickTimer = setInterval(tick, TICK_MS);
          } catch (err) {
            console.error('[FaceEnroll] Câmera falhou:', err?.name, err?.message, err);
            // Mensagem específica por tipo de erro (espelha lógica em pinStepup catch).
            let userMsg;
            const ua = (navigator.userAgent || '').toLowerCase();
            const isIOSDev = /iphone|ipad|ipod/.test(ua) || (ua.includes('mac') && navigator.maxTouchPoints > 1);
            switch (err?.name) {
              case 'NotAllowedError':
              case 'PermissionDeniedError':
                userMsg = isIOSDev
                  ? 'Permissão negada. Vá em Ajustes → Safari → Câmera e permita o acesso.'
                  : 'Permissão negada. Clique no ícone de cadeado e permita a câmera.';
                break;
              case 'NotReadableError':
              case 'AbortError':
                userMsg = 'Câmera ocupada por outro app. Feche-o e tente novamente.';
                break;
              case 'NotFoundError':
                userMsg = 'Nenhuma câmera encontrada neste aparelho.';
                break;
              case 'OverconstrainedError':
                userMsg = 'A câmera deste aparelho não suporta a configuração necessária.';
                break;
              case 'SecurityError':
                userMsg = 'Câmera bloqueada por segurança. Acesse via HTTPS.';
                break;
              default:
                userMsg = 'Câmera indisponível: ' + (err?.message || 'erro desconhecido');
            }
            msg.textContent = userMsg;
            skipBtn.textContent = 'Fechar';
            // Não fechar automaticamente — deixar o usuário ler a mensagem e clicar
          }
        })();
      });
    }

    // ─── Fim Cadastro Facial Inline ──────────────────────────────────────────────

    // Badge de rede - atualiza badge do header
    // _netForceState: null = usa navigator.onLine; true/false = força para debug (window.forceOnline/Offline)
    let _netForceState = null;
    function updateNetBadge() {
      console.log('[NetBadge] === INICIANDO ATUALIZAÇÃO ===');

      const badge = document.getElementById('netBadge');
      if (!badge) {
        console.log('[NetBadge] Elemento não encontrado');
        return;
      }

      const on = (_netForceState === null) ? navigator.onLine : _netForceState;
      console.log('[NetBadge] Status da rede:', on ? 'ONLINE' : 'OFFLINE');

      if (on) {
        badge.className = 'status-badge';
        badge.innerHTML = '<i class="bi bi-wifi"></i><span class="status-text">Online</span>';
        console.log('[NetBadge] ✅ Atualizado para ONLINE');
      } else {
        badge.className = 'status-badge offline-status';
        badge.innerHTML = '<i class="bi bi-wifi-off"></i><span class="status-text">Offline</span>';
        console.log('[NetBadge] ✅ Atualizado para OFFLINE');
      }

      console.log('[NetBadge] === FIM DA ATUALIZAÇÃO ===');
    }

    // Debug helpers (só em localhost/dev): alternam exibição do badge sem
    // atribuir navigator.onLine (read-only em WebKit). P2: evita expor na
    // produção onde podem confundir suporte ou serem usados maliciosamente.
    const _isDevHost = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)
                    || location.hostname.endsWith('.local');
    if (_isDevHost) {
      window.forceOffline = function() {
        console.log('[NetBadge] Forçando OFFLINE...');
        _netForceState = false;
        updateNetBadge();
      };
      window.forceOnline = function() {
        console.log('[NetBadge] Forçando ONLINE...');
        _netForceState = true;
        updateNetBadge();
      };
      window.netForceClear = function() {
        _netForceState = null;
        updateNetBadge();
      };
    }

    // Desabilita auto captura quando offline (detecção facial não funciona)
    let userAutoCapturePreference = true; // Preferência do usuário

    function updateAutoCaptureForConnection() {
      if (!autoCaptureSwitch) return;

      const isOnline = navigator.onLine;

      if (!isOnline) {
        // Offline: salva preferência e desabilita
        userAutoCapturePreference = autoCaptureSwitch.checked;
        autoCaptureSwitch.checked = false;
        autoCaptureSwitch.disabled = true;
        autoCaptureEnabled = false;
        updateCaptureButtonVisibility();

        // Mostra botão de captura manual
        if (btnCapture) btnCapture.classList.remove('d-none');
      } else {
        // Online: restaura preferência do usuário
        autoCaptureSwitch.disabled = false;
        autoCaptureSwitch.checked = userAutoCapturePreference;
        autoCaptureEnabled = userAutoCapturePreference;
        updateCaptureButtonVisibility();
      }
    }

    // Listeners de rede mais robustos
    window.addEventListener('online', (event) => {
      console.log('[NetBadge] Evento ONLINE detectado!', event);
      setTimeout(() => {
        updateNetBadge();
        updateAutoCaptureForConnection();
        toast('success', 'Conexão restaurada', 'Você está online novamente!', ['Os pontos salvos serão sincronizados automaticamente.', 'Modo rápido reabilitado.']);
      }, 100);
    });

    window.addEventListener('offline', (event) => {
      console.log('[NetBadge] Evento OFFLINE detectado!', event);
      setTimeout(() => {
        updateNetBadge();
        updateAutoCaptureForConnection();
        toast('info', 'Modo offline ativado', 'Você está sem conexão no momento.', ['✅ Você pode registrar pontos normalmente.', '📡 Serão enviados automaticamente quando reconectar.', '⚠️ Modo rápido desabilitado (use botão de captura).']);
      }, 100);
    });

    // Polling para garantir que o badge funcione
    setInterval(() => {
      const badge = document.getElementById('netBadge');
      const isOnline = navigator.onLine;

      if (badge) {
        const currentText = badge.querySelector('.status-text')?.textContent;
        const shouldBeOnline = currentText === 'Online';

        if ((isOnline && !shouldBeOnline) || (!isOnline && shouldBeOnline)) {
          console.log('[NetBadge] Polling detectou inconsistência, corrigindo...');
          updateNetBadge();
        }
      }
    }, 2000);

    // Aplica estado inicial
    updateAutoCaptureForConnection();

    // Força atualização inicial do badge
    setTimeout(() => {
      updateNetBadge();
      console.log('[NetBadge] Atualização inicial executada');
    }, 100);

    // Teste manual para debug
    window.testNetBadge = function() {
      console.log('[NetBadge] Teste manual - Status atual:', navigator.onLine ? 'Online' : 'Offline');
      updateNetBadge();
    };

    // Botão de sincronização manual
    const btnSyncNow = document.getElementById('btnSyncNow');
    if (btnSyncNow) {
      btnSyncNow.addEventListener('click', async () => {
        if (!navigator.onLine) {
          toast('info', 'Aguardando conexão', 'Seus pontos estão salvos e aguardando conexão.', ['📡 Conecte-se à internet para sincronizar.', '⏰ A sincronização será automática ao conectar.']);
          return;
        }

        const icon = btnSyncNow.querySelector('i');
        icon.classList.add('spin');
        btnSyncNow.disabled = true;

        await drainPending();

        icon.classList.remove('spin');
        btnSyncNow.disabled = false;
      });
    }

    // Navegação entre etapas
    function goStepConfirmUI() {
      // Elementos de etapa foram removidos no novo design
      // Mantém função vazia para compatibilidade
    }

    function backToStepPhotoUI() {
      // Elementos de etapa foram removidos no novo design
      // Mantém função vazia para compatibilidade
    }

    // Abrir modal de confirmação (exige foto já capturada)
    function openConfirmModal() {
      if (!capturedDataUrl) {
        toast('warning', 'Foto necessária', 'Capture a foto antes de continuar.', []);
        statusEl.textContent = 'Capture a foto para continuar.';
        return;
      }
      confirmPhotoEl.src = capturedDataUrl;
      const now = new Date();
      confirmDateEl.textContent = now.toLocaleDateString('pt-BR');
      confirmTimeEl.textContent = now.toLocaleTimeString('pt-BR');

      resetConfirmStage();
      cpfInput.value = '';
      cpfInput.classList.remove('is-invalid');
      cpfFeedback.textContent = '';

      // Atualiza etapa visual e geolocalização
      goStepConfirmUI();
      ensureGeoForModal();

      // Mostrar seção de cadastro facial e ajustar botão principal conforme reconhecimento
      const faceEnrollRecommend = document.getElementById('faceEnrollRecommend');
      const cpfInputHint = document.getElementById('cpfInputHint');
      if (faceEnrollRecommend) {
        // Não mostrar botões de cadastro facial — o fluxo agora é automático via runPreview()
        faceEnrollRecommend.style.display = 'none';
      }
      if (cpfInputHint) {
        cpfInputHint.textContent = 'Digite seu CPF e toque em "Bater ponto".';
      }
      const btnConfirmSubmitLabel = document.getElementById('btnConfirmSubmitLabel');
      if (btnConfirmSubmitLabel) {
        btnConfirmSubmitLabel.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Bater ponto';
      }
      if (btnConfirmSubmit) {
        btnConfirmSubmit.classList.remove('btn-outline-secondary');
        btnConfirmSubmit.classList.add('btn-primary');
      }

      // Mostra modal
      const modal = getConfirmModal();
      modal?.show();
      setTimeout(() => cpfInput?.focus(), 150);
      vibrate(15);
    }

    // Botões (captura e continuar)
    function doCapture() {
      if (stream) {
        capturedDataUrl = takeSnapshotFromVideo();
        statusEl.textContent = 'Foto pronta. Revise no pop-up e confirme.';
        vibrate(20);
        openConfirmModal();
      } else if (fileInput.files?.[0]) {
        // já será tratado no change
      } else {
        statusEl.textContent = 'Câmera indisponível. Use “Abrir câmera do celular”.';
      }
    }
    btnCapture.addEventListener('click', doCapture);

    // Continuar NÃO captura mais automaticamente; exige foto prévia
    btnReg.addEventListener('click', () => {
      openConfirmModal();
    });

    btnRetake.addEventListener('click', () => {
      capturedDataUrl = null;
      snapshot.style.display = 'none';
      video.style.display = 'block';
      btnRetake.classList.add('d-none');
      statusEl.textContent = 'Aponte a câmera e toque em Capturar.';
      backToStepPhotoUI();
      resetConfirmStage();
    });

    // Camera NÃO inicia automaticamente - só ao clicar "Bater Ponto"
    btnChooseFile?.addEventListener('click', () => fileInput.click());

    function startCameraWhenReady() {
      if ('mediaDevices' in navigator && 'getUserMedia' in navigator.mediaDevices) {
        startWebcam('user');
      } else {
        camFallback.classList.remove('d-none');
        fileInput.classList.remove('d-none');
        camControls.classList.add('d-none');
      }
    }

    // Fallback arquivo -> abre modal
    fileInput.addEventListener('change', async () => {
      capturedDataUrl = null;
      if (fileInput.files?.[0]) {
        capturedDataUrl = await fileToDataURL(fileInput.files[0], 1200, 0.85);
        snapshot.src = capturedDataUrl;
        snapshot.style.display = 'block';
        video.style.display = 'none';
        btnRetake.classList.remove('d-none');
        statusEl.textContent = 'Foto pronta. Revise no pop-up.';
        openConfirmModal();
      }
    });

    // CPF UX mobile (máscara e validação)
    (function() {
      if (!cpfInput) return;
      function maskCpf(v) {
        v = (v || '').replace(/\D/g, '');
        if (v.length <= 3) return v;
        if (v.length <= 6) return v.replace(/(\d{3})(\d+)/, '$1.$2');
        if (v.length <= 9) return v.replace(/(\d{3})(\d{3})(\d+)/, '$1.$2.$3');
        return v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/, '$1.$2.$3-$4');
      }
      const sanitize = (v) => (v || '').replace(/\D/g, '').slice(0, 11);
      const dismissKeyboard = () => {
        try { cpfInput.setSelectionRange(0, 0); } catch {}
        cpfInput.blur();
        if (document.activeElement?.blur) document.activeElement.blur();
        const prev = cpfInput.readOnly;
        cpfInput.readOnly = true;
        setTimeout(() => cpfInput.readOnly = prev, 50);
      };
      cpfInput.setAttribute('inputmode', 'numeric');
      cpfInput.setAttribute('enterkeyhint', 'done');
      cpfInput.setAttribute('maxlength', '14');
      cpfInput.addEventListener('input', () => {
        const digits = sanitize(cpfInput.value);
        const masked = maskCpf(digits);
        if (cpfInput.value !== masked) cpfInput.value = masked;
        if (digits.length === 11) dismissKeyboard();
        if (confirmStage !== 'preview') resetConfirmStage();
      });
      cpfInput.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text') || '';
        const digits = sanitize(text);
        cpfInput.value = maskCpf(digits);
        if (digits.length === 11) dismissKeyboard();
        if (confirmStage !== 'preview') resetConfirmStage();
      });
      confirmModalEl.addEventListener('shown.bs.modal', () => setTimeout(() => cpfInput?.focus(), 120));
    })();

    // Submissão final (Etapa 2)
    async function runPreview() {
      if (!cpfInput) return;
      const cpfDigits = (cpfInput.value || '').replace(/\D/g, '');
      if (!faceAuthMode && cpfDigits.length !== 11) {
        statusEl.textContent = 'Informe seu CPF (11 dígitos) para continuar.';
        markInvalid(cpfInput, cpfFeedback, 'O CPF deve ter exatamente 11 números.');
        return;
      }

      setLoading(true, btnConfirmSubmit);
      try {
        statusEl.textContent = 'Validando CPF...';
        if (confirmCollaboratorAction) confirmCollaboratorAction.textContent = 'Validando CPF...';

        const payload = { preview: true };
        if (faceAuthMode && capturedDescriptor) {
          payload.face_descriptor = capturedDescriptor;
        } else {
          payload.cpf = cpfDigits;
        }

        const res = await fetch(apiUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        const text = await res.text();
        let data = null;
        try {
          data = JSON.parse(text);
        } catch {}
        if (!data && !res.ok && text.trim()) {
          try {
            const parsed = JSON.parse(text);
            if (parsed && (parsed.status === 'error' || parsed.code)) data = parsed;
          } catch {}
        }
        if (!data && res.status === 401) {
          data = { status: 'error', code: 'cpf_invalid', message: 'Não autorizado. Verifique seu CPF ou reconhecimento facial.' };
        }
        if (!res.ok || !data) {
          if (data && (data.status === 'error' || data.code)) {
            handleApiError(res.status, data);
            maybeResetStageForCpfError(data?.code);
          } else {
            toast('error', 'Falha na validação', 'Não foi possível validar o CPF.', ['Verifique sua conexão e tente novamente.']);
            statusEl.textContent = 'Não foi possível validar o CPF.';
          }
          return;
        }

        if (data.status === 'preview') {
          previewData = data;
          faceChallengeToken = data.face_challenge || null;
          if (confirmCollaboratorName) confirmCollaboratorName.textContent = data.collaborator?.name || '—';
          if (confirmCollaboratorAction) {
            const label = data.message || (data.action ? `Próximo registro: ${actionLabel(data.action)}` : 'Confirmado. Confira as informações.');
            confirmCollaboratorAction.textContent = label;
          }

          // --- Verificação de identidade facial ---
          const faceRow = document.getElementById('confirmFaceRow');
          const faceIcon = document.getElementById('confirmFaceIcon');
          const faceStatusEl = document.getElementById('confirmFaceStatus');
          capturedFaceMatch = null;
          capturedFaceDistance = null;
          faceRow.style.display = '';

          if (faceAuthMode) {
            faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-success-subtle text-success';
            faceIcon.innerHTML = '<i class="bi bi-shield-check fs-6"></i>';
            faceStatusEl.innerHTML = '<span class="text-success fw-semibold">Identidade confirmada no servidor</span>';
          } else if (data.face_enrolled && !capturedDescriptor) {
            faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning';
            faceIcon.innerHTML = '<i class="bi bi-exclamation-triangle fs-6"></i>';
            faceStatusEl.innerHTML = '<span class="text-warning fw-semibold">Não foi possível analisar o rosto na foto — identidade não verificada</span>';
          } else {
            faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning';
            faceIcon.innerHTML = '<i class="bi bi-person-exclamation fs-6"></i>';
            faceStatusEl.innerHTML = '<span class="text-warning fw-semibold">Colaborador sem cadastro facial — identidade não verificada</span><br><small class="text-muted">Solicite o cadastro facial ao administrador para ativar a verificação.</small>';
          }

          statusEl.textContent = data.message || 'CPF validado. Confira e confirme o registro.';

          if (enrollBeforeCheckin && data.inline_enroll_token) {
            // Fluxo "Refazer cadastro facial": enrollment sem registro de ponto
            enrollBeforeCheckin = false;
            const enrollId = data.collaborator?.id;
            const enrollCpf = (cpfInput.value || '').replace(/\D/g, '');
            try { getConfirmModal()?.hide(); } catch {}
            await showFaceEnrollmentOffer(enrollId, enrollCpf, data.inline_enroll_token, null, '', true);
          } else if (!data.face_enrolled && data.inline_enroll_token && !faceAuthMode) {
            // Colaborador SEM cadastro facial — abrir cadastro antes do ponto
            enrollBeforeCheckin = false;
            const enrollId = data.collaborator?.id;
            const enrollCpf = (cpfInput.value || '').replace(/\D/g, '');
            // Salvar dados do preview
            previewData = data;
            faceChallengeToken = data.face_challenge || null;
            window._autoCheckinAfterEnroll = true;
            // Esconder modal CPF via CSS (sem disparar hidden.bs.modal)
            confirmModalEl.style.display = 'none';
            document.querySelector('.modal-backdrop')?.remove();
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            // Abrir cadastro facial — bloqueia até terminar
            await showFaceEnrollmentOffer(enrollId, enrollCpf, data.inline_enroll_token, null, '', false);
            // Cadastro terminou (sucesso ou skip) — submeter ponto via CPF (sem face auth)
            // Garantir que o submit use CPF puro, sem face_descriptor/challenge
            faceAuthMode = false;
            capturedDescriptor = null;
            faceChallengeToken = null;
            setConfirmStage('confirm');
            await submitPointWithCpf(null);
            // Agora sim limpar tudo
            window._autoCheckinAfterEnroll = false;
            confirmModalEl.style.display = '';
            try { getConfirmModal()?.hide(); } catch {}
            confirmStage = 'done';
          } else {
            enrollBeforeCheckin = false;
            previewData = data;
            setConfirmStage('confirm');
            // Submeter direto sem segunda confirmação
            try { getConfirmModal()?.hide(); } catch {}
            await submitPointWithCpf(null);
            confirmStage = 'done';
          }
        } else if (data.status === 'error') {
          handleApiError(res.status, data);
          maybeResetStageForCpfError(data?.code);
        } else if (data.status === 'ok') {
          // Caso raro: API já registrou o ponto (preview ausente). Reaproveita fluxo existente.
          previewData = data;
          setConfirmStage('confirm');
          await submitPointWithCpf();
        } else {
          toast('error', 'Retorno inesperado', 'Servidor retornou uma resposta desconhecida.', []);
          statusEl.textContent = 'Retorno inesperado do servidor.';
        }
      } catch (error) {
        console.error('[Preview] Erro ao validar CPF:', error);
        toast('error', 'Falha na validação', error?.message || 'Erro inesperado.', []);
        statusEl.textContent = 'Não foi possível validar o CPF.';
      } finally {
        setLoading(false, btnConfirmSubmit);
      }
    }

    async function submitPointWithCpf(actionBtn = btnConfirmSubmit) {
      const actionButton = actionBtn || btnConfirmSubmit;
      const cpfDigits = (cpfInput.value || '').replace(/\D/g, '');

      if (!faceAuthMode && cpfDigits.length !== 11) {
        statusEl.textContent = 'Digite seu CPF (11 dígitos).';
        markInvalid(cpfInput, cpfFeedback, 'O CPF deve ter exatamente 11 números.');
        return { ok: false, code: 'cpf_required' };
      }

      setLoading(true, actionButton);
      try {
        statusEl.textContent = 'Preparando...';

        // Registro sem foto — câmera usada apenas para reconhecimento facial
        let incomplete = false;
        const reasons = [];

        // Geo: usa cache do modal se existir
        statusEl.textContent = 'Obtendo localização...';
        const geo = cachedGeo || await getGeo();
        if (!geo) {
          incomplete = true;
          reasons.push('localização ausente');
        }

        // Portaria 671/2021 - modo de registro é inferido pelo resultado:
        // - sucesso do POST => online
        // - fallback para fila local => offline
        const recordMode = 'online';
        const recordedAt = getCurrentHLBTime().toISOString(); // Usa hora sincronizada com HLB
        const hlbOffsetSeconds = hlbOffset; // Diferença real entre hora local e HLB

        // MÉTRICAS DE PERFORMANCE - Início
        const perfMetrics = {
          inicio: performance.now(),
          preparacao: 0,
          serializacao: 0,
          upload: 0,
          total: 0
        };

        const payload = {
          cpf: cpfDigits,
          geo: geo || null,
          incomplete,
          reasons,
          // Campos Portaria 671/2021
          recordMode,
          recordedAt,
          hlbOffsetSeconds,
          // ÂNCORA DE TEMPO (NC-14). O servidor só a usa em marcação offline,
          // mas ela vai sempre: se este POST cair na fila local por falta de
          // rede, o item enfileirado já carrega a prova do instante.
          ...buildTimeAnchorFields(),
          // Campos Anti-Fraude
          fraudCheck: geo?.fraudCheck || null,
          deviceFingerprint: geo?.deviceFingerprint || await generateDeviceFingerprint(),
        };
        const expectedAction = expectedActionFromPreview();
        if (expectedAction) payload.expected_action = expectedAction;

        // Envia sempre a foto capturada (para auditoria anti-fraude)
        if (capturedDataUrl) {
          payload.photo = capturedDataUrl;
        }

        if (faceAuthMode && capturedDescriptor) {
          payload.face_descriptor = capturedDescriptor;
          if (faceChallengeToken) payload.face_challenge = faceChallengeToken;
          if (window._lastLivenessData) payload.liveness_data = window._lastLivenessData;
        }

        perfMetrics.preparacao = performance.now() - perfMetrics.inicio;
        
        // Medir tempo de serialização JSON
        const serializacaoInicio = performance.now();
        const payloadJSON = JSON.stringify(payload);
        perfMetrics.serializacao = performance.now() - serializacaoInicio;
        
        const payloadTamanhoKB = Math.round(payloadJSON.length / 1024);
        
        console.log(`[Performance] Preparação: ${perfMetrics.preparacao.toFixed(2)}ms | Serialização: ${perfMetrics.serializacao.toFixed(2)}ms | Payload: ${payloadTamanhoKB}KB`);
        
        statusEl.textContent = `Enviando... (${payloadTamanhoKB}KB)`;
        let onlineOk = false,
          data = null;

        try {
          const uploadInicio = performance.now();
          const res = await fetch(apiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: payloadJSON
          });
          
          captureOfflineAuth(res);
          perfMetrics.upload = performance.now() - uploadInicio;
          console.log(`[Performance] Upload (request+response): ${perfMetrics.upload.toFixed(2)}ms`);

          const text = await res.text();
          try {
            data = JSON.parse(text);
          } catch {
            data = null;
          }
          if (!data && res.status === 401) {
            data = { status: 'error', code: 'cpf_invalid', message: 'Não autorizado. Verifique seu CPF ou reconhecimento facial.' };
          }

          if (!res.ok || !data || data.status !== 'ok') {
            // Em fluxo facial automático, challenge inválido é recuperável e tratado pelo chamador.
            if (!(faceAuthMode && data?.code === 'face_challenge_invalid')) {
              handleApiError(res.status, data);
              maybeResetStageForCpfError(data?.code);
            }
            setLoading(false, actionButton);
            return { ok: false, code: data?.code || 'request_failed', data };
          }

          // Calcular tempo total e exibir métricas completas
          perfMetrics.total = performance.now() - perfMetrics.inicio;
          
          console.log(`[Performance] TEMPO TOTAL: ${perfMetrics.total.toFixed(2)}ms`);
          console.log(`[Performance] Breakdown Frontend:`, {
            preparacao: `${perfMetrics.preparacao.toFixed(2)}ms`,
            serializacao: `${perfMetrics.serializacao.toFixed(2)}ms`,
            upload: `${perfMetrics.upload.toFixed(2)}ms`,
            total_frontend: `${perfMetrics.total.toFixed(2)}ms`
          });
          
          // Exibir métricas do backend se disponíveis
          if (data.debug_performance) {
            console.log(`[Performance] Backend (PHP):`, data.debug_performance);
            console.log(`[Performance] DIAGNÓSTICO:`, {
              'Total Frontend→Backend→Frontend': `${perfMetrics.total.toFixed(2)}ms`,
              'Processamento Backend': `${data.debug_performance.total || 'N/A'}ms`,
              'Network Overhead': `${(perfMetrics.upload - (data.debug_performance.total || 0)).toFixed(2)}ms`,
              'Payload': `${payloadTamanhoKB}KB`
            });
          }

          onlineOk = true;
        } catch (err) {
          console.error('[Checkin] Fetch falhou:', {
            url: apiUrl,
            error: err?.message,
            name: err?.name,
            payloadSize: payloadJSON?.length || 0,
            online: navigator.onLine
          });

          const isNetworkError =
            err?.name === 'TypeError' ||
            err?.name === 'AbortError' ||
            /fetch|network|failed to fetch|load failed/i.test(String(err?.message || ''));

          // Se não for erro de rede, não deve cair em modo offline.
          if (!isNetworkError) {
            throw err;
          }

          const hints = navigator.onLine
            ? ['O servidor pode estar temporariamente indisponível.', 'Tente novamente em alguns segundos.']
            : ['Conecte à internet para registrar ponto.'];

          // OFFLINE: CPF não é cacheado por segurança. Exige conexão.
          if (faceAuthMode) {
            faceAuthMode = false;
            statusEl.textContent = navigator.onLine
              ? 'Erro ao comunicar com o servidor. Tente novamente.'
              : 'Sem conexão para validação facial. Conecte à internet.';
            toast('warning', 'Validação facial indisponível', navigator.onLine
              ? 'Não foi possível contactar o servidor.'
              : 'A biometria facial exige validação online.', hints);
            setLoading(false, actionButton);
            return { ok: false, code: 'face_offline_requires_connection' };
          } else {
            statusEl.textContent = 'Conecte à internet para registrar ponto com CPF.';
            markInvalid(cpfInput, cpfFeedback, 'O CPF exige conexão para validação.');
            toast('warning', 'Conexão necessária', 'Não é possível validar CPF offline.', hints);
            setLoading(false, actionButton);
            return { ok: false, code: 'cpf_requires_connection' };
          }
        }

        const resolvedPendingReasons = extractPendingReasons(data, reasons);
        const isPendingApproval = resolvedPendingReasons.length > 0 || data?.approved === false;
        const pendingNote = isPendingApproval ?
          `<div style="margin-top:1rem"><b>Status:</b> Pendente.<br><span class="fs-12">Motivo: ${escHtml(resolvedPendingReasons.join(', ') || 'aguardando aprovação')}.</span></div>` :
          '';

        statusEl.textContent = onlineOk ?
          (isPendingApproval ? 'Ponto salvo como pendente de aprovação.' : 'Ponto registrado com sucesso.') :
          (incomplete ? 'Ponto salvo! Será enviado quando conectar.' : 'Ponto salvo! Será enviado quando conectar.');

        // Fecha modal e mostra sucesso
        try {
          closeFinalizeModal();
          getConfirmModal()?.hide();
        } catch {}
        if (onlineOk) {
          // Oferecer cadastro facial inline se: ponto via CPF, sem face cadastrada ou rosto não reconhecido, e descriptor disponível
          const enrollTeacherId = data?.teacher?.id || data?.collaborator?.id;
          const shouldOfferEnroll = (
            (data?.has_face_descriptors === false || faceRecognitionAttempted) &&
            !faceAuthMode &&
            enrollTeacherId &&
            cpfDigits &&
            data?.inline_enroll_token
          );
          if (shouldOfferEnroll) {
            await showFaceEnrollmentOffer(enrollTeacherId, cpfDigits, data.inline_enroll_token, data, pendingNote);
          } else if (data?.reenroll_recommended && enrollTeacherId && data?.inline_enroll_token) {
            // Descritores faciais desatualizados — sugerir re-cadastramento
            const days = data.reenroll_days_since || '?';
            toast('info', 'Atualização facial recomendada',
              `Seu cadastro facial tem ${days} dias. Recomendamos atualizar para melhor precisão.`, []);
            await showFaceEnrollmentOffer(enrollTeacherId, cpfDigits || '', data.inline_enroll_token, data, pendingNote, false);
          } else {
            const detailsHTML = buildDetailsHTML(data || {});
            showFullScreenAlert('success', detailsHTML + pendingNote, true);
          }
        } else {
          // Modo offline: mostra SUCESSO (verde) para não assustar o colaborador
          const teacherNameHTML = data?.teacher?.teacherName ? 
            `<div style="margin-top:0.5rem">👤 <b>${escHtml(data.teacher.teacherName)}</b></div>` : '';
          
          toast('success', 'Ponto salvo!', 'Registrado localmente. Será enviado automaticamente quando você conectar.', ['📡 Você está offline no momento.']);
          const offlineHTML = `
            <div style="font-size:2rem;font-weight:700">✅ Ponto salvo com sucesso!</div>
            ${teacherNameHTML}
            <div style="margin-top:1rem;font-size:1.1rem">
              <div style="margin-bottom:0.5rem">📡 <b>Você está offline</b></div>
              <div>Seu ponto foi <b>salvo localmente</b> e será enviado automaticamente quando você conectar à internet.</div>
            </div>
            ${pendingNote}
            <div style="margin-top:1.5rem;font-size:0.95rem;opacity:0.9">
              <div>⏰ Data/hora: ${new Date().toLocaleString('pt-BR')}</div>
              <div style="margin-top:0.3rem">📱 Mantenha este dispositivo conectado à internet para sincronizar.</div>
              <div style="margin-top:0.3rem">📱 Registro salvo localmente.</div>
            </div>
          `;
          showFullScreenAlert('success', offlineHTML, false);
        }
        resetFaceAttemptState();
        return { ok: true, online: onlineOk, data };
      } catch (e) {
        statusEl.textContent = e?.message || 'Falha ao registrar.';
        toast('error', 'Erro inesperado', e?.message || 'Tente novamente', []);
        return { ok: false, code: 'runtime_error', error: e };
      } finally {
        setLoading(false, actionButton);
        cachedGeo = null;
        faceChallengeToken = null;
      }
    }

    // Ações do modal
    async function handleConfirmSubmit() {
      if (confirmStage === 'preview') {
        await runPreview();
        // runPreview já submete o ponto diretamente (sem segunda confirmação)
        // e marca confirmStage = 'done' ao finalizar
      }
    }

    btnConfirmSubmit.addEventListener('click', handleConfirmSubmit);
    btnRefazerCadastroFacial?.addEventListener('click', () => {
      enrollBeforeCheckin = true;
      handleConfirmSubmit();
    });
    btnModalRetake?.addEventListener('click', () => {
      try {
        getConfirmModal()?.hide();
      } catch {}
      btnRetake.click();
      resetConfirmStage();
    });
    // Enter no CPF envia
    cpfInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleConfirmSubmit();
      }
    });

    btnFinalizeCancel?.addEventListener('click', () => {
      closeFinalizeModal();
    });

    btnFinalizeConfirm?.addEventListener('click', async () => {
      await submitPointWithCpf(btnFinalizeConfirm);
    });

    // Inicia webcam conforme suporte
    if (!('mediaDevices' in navigator && 'getUserMedia' in navigator.mediaDevices)) {
      camFallback.classList.remove('d-none');
      fileInput.classList.remove('d-none');
      camControls.classList.add('d-none');
      cancelFaceGuide();
    }

    // Fecha webcam ao sair
    window.addEventListener('beforeunload', stopWebcam);

    // ============================================================================
    // FULLSCREEN CAMERA MODE
    // ============================================================================

    function isMobileDevice() {
      return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
             window.innerWidth <= 768;
    }

    const fullscreenCamera = document.getElementById('fullscreenCamera');
    const fullscreenVideo = document.getElementById('fullscreenVideo');
    const btnExitFullscreen = document.getElementById('btnExitFullscreen');
    const btnFullscreenCapture = document.getElementById('btnFullscreenCapture');
    const btnFullscreenFlip = document.getElementById('btnFullscreenFlip');
    const fullscreenFaceRing = document.getElementById('fullscreenFaceRing');
    const fullscreenInstruction = document.getElementById('fullscreenInstruction');
    const fsClockEl = document.getElementById('fsClock');
    const fsDateEl = document.getElementById('fsDate');

    let isFullscreenMode = false;
    let fullscreenStream = null;
    let fsClockInterval = null;
    let faceDetectionTimer = null;
    let lastFaceDetected = null;

    const fsFaceStatus = document.getElementById('fsFaceStatus');
    const fsFaceIcon = document.getElementById('fsFaceIcon');
    const fsFaceText = document.getElementById('fsFaceText');

    function updateFsClock() {
      const now = new Date();
      if (fsClockEl) fsClockEl.textContent = now.toLocaleTimeString('pt-BR', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
      if (fsDateEl) fsDateEl.textContent = now.toLocaleDateString('pt-BR', { weekday:'long', day:'numeric', month:'long', year:'numeric' });
    }

    function setFsDetectionStatus(state, customMessage = null) {
      if (!fsFaceStatus) return;
      fsFaceStatus.classList.remove('detected', 'no-face', 'loading');
      fsFaceStatus.classList.add('visible');
      
      // Atualiza apenas o texto de instrução superior
      if (fullscreenInstruction) {
        if (state === 'detected') {
          fullscreenInstruction.textContent = customMessage || 'Rosto enquadrado — preparando captura...';
          fullscreenInstruction.className = 'fs-instruction-float face-ok';
        } else if (state === 'no-face') {
          fullscreenInstruction.textContent = customMessage || 'Centralize seu rosto na área indicada';
          fullscreenInstruction.className = 'fs-instruction-float face-warn';
        } else {
          fullscreenInstruction.textContent = customMessage || 'Centralize seu rosto na área indicada';
          fullscreenInstruction.className = 'fs-instruction-float';
        }
      }
      
      // Oculta completamente a caixa preta de status inferior
      fsFaceStatus.style.display = 'none';
      
      // Atualiza o anel visual
      if (fullscreenFaceRing) {
          if (state === 'detected') fullscreenFaceRing.className = 'face-ring detected';
          else if (state === 'no-face') fullscreenFaceRing.className = 'face-ring no-face';
          else fullscreenFaceRing.className = 'face-ring';
      }
    }

    let faceDetectionRunning = false;
    let autoCaptureCountdown = 0;
    let autoCaptureTimer = null;
    let autoCaptureFired = false;
    let faceAutoRetryCount = 0;
    const MAX_FACE_AUTO_RETRY = 1;

    // ═══════════════════════════════════════════════════════════════
    // LivenessAnalyzer — Detecção de vivacidade em 4 camadas
    // Camada 1: Micro-movimentos (variação temporal de landmarks)
    // Camada 2: Piscar (Eye Aspect Ratio passivo)
    // Camada 3: Desafio ativo (piscar/virar — só quando Camadas 1+2 são inconclusivas)
    // Camada 4: Análise de textura (detecção de moiré em tela)
    // ═══════════════════════════════════════════════════════════════
    const livenessAnalyzer = {
      // Configurações
      WINDOW_SIZE: 10,
      MIN_MOVEMENT_STDDEV: 0.003,
      BLINK_EAR_LOW: 0.20,
      BLINK_EAR_HIGH: 0.22,
      CHALLENGE_MOVEMENT_LOW: 0.001,
      CHALLENGE_MOVEMENT_HIGH: 0.005,
      CHALLENGE_YAW_THRESHOLD: 0.12,
      CHALLENGE_TIMEOUT_MS: 5000,

      // Estado interno
      landmarkHistory: [],
      earHistory: [],
      blinkCount: 0,
      earMin: 1.0,
      earMax: 0.0,
      _wasEyeClosed: false,
      challengeActive: false,
      challengeType: null,
      challengePassed: false,
      _challengeStartTime: 0,
      _challengeInitialYaw: null,
      framesAnalyzed: 0,
      analysisStartTime: 0,
      _nonce: null,
      _gyroSamples: [],

      reset() {
        this.landmarkHistory = [];
        this.earHistory = [];
        this.blinkCount = 0;
        this.earMin = 1.0;
        this.earMax = 0.0;
        this._wasEyeClosed = false;
        this.challengeActive = false;
        this.challengeType = null;
        this.challengePassed = false;
        this._challengeStartTime = 0;
        this._challengeInitialYaw = null;
        this.framesAnalyzed = 0;
        this.analysisStartTime = Date.now();
        // Anti-replay: nonce único por sessão de liveness. Usa pontoGenClientId
        // (com try/catch para WebViews que bloqueiam acesso a `crypto`).
        this._nonce = pontoGenClientId();
        // Giroscópio: coletar amostras de movimento do dispositivo
        this._gyroSamples = [];
        this._startGyroCollection();
      },

      _gyroHandler: null,
      _startGyroCollection() {
        // Parar coleta anterior
        if (this._gyroHandler) {
          window.removeEventListener('deviceorientation', this._gyroHandler);
        }
        this._gyroSamples = [];
        this._gyroHandler = (e) => {
          if (this._gyroSamples.length < 30) { // Max 30 amostras (~3s a 10Hz)
            this._gyroSamples.push({
              alpha: e.alpha != null ? Math.round(e.alpha * 100) / 100 : null,
              beta: e.beta != null ? Math.round(e.beta * 100) / 100 : null,
              gamma: e.gamma != null ? Math.round(e.gamma * 100) / 100 : null,
            });
          }
        };
        window.addEventListener('deviceorientation', this._gyroHandler);
      },

      _stopGyroCollection() {
        if (this._gyroHandler) {
          window.removeEventListener('deviceorientation', this._gyroHandler);
          this._gyroHandler = null;
        }
      },

      // Calcula variância do giroscópio — dispositivos reais sempre têm ruído
      getGyroVariance() {
        const samples = this._gyroSamples;
        if (samples.length < 5) return { variance: 0, samples: samples.length, hasGyro: false };
        let sumBeta = 0, sumGamma = 0;
        const valid = samples.filter(s => s.beta !== null && s.gamma !== null);
        if (valid.length < 5) return { variance: 0, samples: valid.length, hasGyro: false };
        for (const s of valid) { sumBeta += s.beta; sumGamma += s.gamma; }
        const meanBeta = sumBeta / valid.length, meanGamma = sumGamma / valid.length;
        let varBeta = 0, varGamma = 0;
        for (const s of valid) {
          varBeta += (s.beta - meanBeta) ** 2;
          varGamma += (s.gamma - meanGamma) ** 2;
        }
        return {
          variance: Math.round(((varBeta + varGamma) / valid.length) * 10000) / 10000,
          samples: valid.length,
          hasGyro: true,
        };
      },

      // Calcula EAR (Eye Aspect Ratio) para um olho
      _calcEAR(landmarks, indices) {
        const pts = landmarks.positions;
        const p1 = pts[indices[0]], p2 = pts[indices[1]], p3 = pts[indices[2]];
        const p4 = pts[indices[3]], p5 = pts[indices[4]], p6 = pts[indices[5]];
        const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
        const vertical1 = dist(p2, p6);
        const vertical2 = dist(p3, p5);
        const horizontal = dist(p1, p4);
        return horizontal > 0 ? (vertical1 + vertical2) / (2 * horizontal) : 0.3;
      },

      // Processa um frame com landmarks detectados
      addFrame(landmarks, faceBox) {
        if (!landmarks || !faceBox) return;
        this.framesAnalyzed++;

        const pts = landmarks.positions;
        const faceW = faceBox.width || 1;
        const faceH = faceBox.height || 1;

        // Landmarks-chave normalizados pelo tamanho do rosto
        const keyIndices = [30, 36, 45, 48, 54]; // nariz, olho ext esq, olho ext dir, boca esq, boca dir
        const normalized = keyIndices.map(i => ({
          x: (pts[i].x - faceBox.x) / faceW,
          y: (pts[i].y - faceBox.y) / faceH
        }));
        this.landmarkHistory.push(normalized);
        if (this.landmarkHistory.length > this.WINDOW_SIZE) {
          this.landmarkHistory.shift();
        }

        // Camada 2: EAR — detectar piscar passivo
        const earLeft = this._calcEAR(landmarks, [36, 37, 38, 39, 40, 41]);
        const earRight = this._calcEAR(landmarks, [42, 43, 44, 45, 46, 47]);
        const avgEAR = (earLeft + earRight) / 2;
        this.earHistory.push(avgEAR);
        if (this.earHistory.length > this.WINDOW_SIZE) this.earHistory.shift();
        this.earMin = Math.min(this.earMin, avgEAR);
        this.earMax = Math.max(this.earMax, avgEAR);

        // Detecção de piscar: EAR cai abaixo do limiar e retorna
        if (avgEAR < this.BLINK_EAR_LOW) {
          this._wasEyeClosed = true;
        } else if (this._wasEyeClosed && avgEAR > this.BLINK_EAR_HIGH) {
          this.blinkCount++;
          this._wasEyeClosed = false;
        }
      },

      // Camada 1: Calcula desvio padrão dos micro-movimentos
      getMicroMovementStddev() {
        if (this.landmarkHistory.length < 4) return 0;
        const n = this.landmarkHistory.length;
        const numPoints = this.landmarkHistory[0].length;
        let totalVariance = 0;

        for (let p = 0; p < numPoints; p++) {
          let sumX = 0, sumY = 0;
          for (let f = 0; f < n; f++) {
            sumX += this.landmarkHistory[f][p].x;
            sumY += this.landmarkHistory[f][p].y;
          }
          const meanX = sumX / n, meanY = sumY / n;
          let varX = 0, varY = 0;
          for (let f = 0; f < n; f++) {
            varX += Math.pow(this.landmarkHistory[f][p].x - meanX, 2);
            varY += Math.pow(this.landmarkHistory[f][p].y - meanY, 2);
          }
          totalVariance += (varX + varY) / n;
        }
        return Math.sqrt(totalVariance / numPoints);
      },

      // Camada 4: Análise de textura para detecção de moiré
      analyzeTexture(canvas, faceBox) {
        try {
          const tmpCanvas = document.createElement('canvas');
          tmpCanvas.width = 64;
          tmpCanvas.height = 64;
          const ctx = tmpCanvas.getContext('2d', { willReadFrequently: true });
          ctx.drawImage(canvas, faceBox.x, faceBox.y, faceBox.width, faceBox.height, 0, 0, 64, 64);
          const imgData = ctx.getImageData(0, 0, 64, 64).data;

          // Converter para grayscale
          const gray = new Float32Array(64 * 64);
          for (let i = 0; i < gray.length; i++) {
            gray[i] = imgData[i * 4] * 0.299 + imgData[i * 4 + 1] * 0.587 + imgData[i * 4 + 2] * 0.114;
          }

          // Laplacian 3×3 kernel: energia de alta frequência
          let highFreqEnergy = 0;
          let midFreqEnergy = 0;
          let count = 0;
          for (let y = 1; y < 63; y++) {
            for (let x = 1; x < 63; x++) {
              const idx = y * 64 + x;
              const lap = -4 * gray[idx] + gray[idx - 1] + gray[idx + 1] + gray[idx - 64] + gray[idx + 64];
              highFreqEnergy += lap * lap;
              midFreqEnergy += Math.abs(gray[idx] - gray[idx - 1]) + Math.abs(gray[idx] - gray[idx - 64]);
              count++;
            }
          }
          highFreqEnergy /= count;
          midFreqEnergy /= count;

          // Ratio alto = padrões periódicos (moiré típico de telas)
          // Score: 0 = tela/foto, 1 = rosto real
          const ratio = midFreqEnergy > 0 ? highFreqEnergy / midFreqEnergy : 0;
          // Calibrado empiricamente: rostos reais ~2-15, telas ~20-80+
          if (ratio > 30) return 0.1;
          if (ratio > 20) return 0.4;
          if (ratio > 15) return 0.7;
          return 0.9;
        } catch (e) {
          return 0.5; // Se falhar, score neutro
        }
      },

      // Camada 3: Verifica desafio ativo (yaw ou blink)
      processChallenge(landmarks) {
        if (!this.challengeActive) return null;
        const elapsed = Date.now() - this._challengeStartTime;
        if (elapsed > this.CHALLENGE_TIMEOUT_MS) {
          return { passed: false, reason: 'timeout' };
        }

        if (this.challengeType === 'blink') {
          if (this.blinkCount > 0) {
            this.challengePassed = true;
            return { passed: true, reason: 'blink_detected' };
          }
        } else if (this.challengeType === 'head_turn') {
          const pts = landmarks.positions;
          const leftEdge = pts[0], rightEdge = pts[16];
          const faceWidth = rightEdge.x - leftEdge.x;
          const faceCenterX = (leftEdge.x + rightEdge.x) / 2;
          const currentYaw = faceWidth > 0 ? (pts[30].x - faceCenterX) / faceWidth : 0;
          if (this._challengeInitialYaw === null) {
            this._challengeInitialYaw = currentYaw;
          }
          const yawDelta = Math.abs(currentYaw - this._challengeInitialYaw);
          if (yawDelta > this.CHALLENGE_YAW_THRESHOLD) {
            this.challengePassed = true;
            return { passed: true, reason: 'head_turn_detected' };
          }
        }
        return null; // Still waiting
      },

      // Inicia desafio ativo
      startChallenge(type) {
        this.challengeActive = true;
        this.challengeType = type;
        this._challengeStartTime = Date.now();
        this._challengeInitialYaw = null;
        if (type === 'blink') this.blinkCount = 0; // Reset blinks for challenge
      },

      // Avalia resultado geral de liveness
      evaluate() {
        const stddev = this.getMicroMovementStddev();
        const hasBlink = this.blinkCount > 0;
        const duration = Date.now() - this.analysisStartTime;

        return {
          passed: false, // Definido abaixo
          needsChallenge: false,
          micro_movement_stddev: Math.round(stddev * 10000) / 10000,
          blink_count: this.blinkCount,
          ear_min: Math.round(this.earMin * 100) / 100,
          ear_max: Math.round(this.earMax * 100) / 100,
          challenge_passed: this.challengePassed,
          challenge_type: this.challengeActive ? this.challengeType : 'passive',
          texture_score: 0, // Preenchido externamente
          frames_analyzed: this.framesAnalyzed,
          analysis_duration_ms: duration,
          // Lógica de decisão:
          // - stddev > MIN e (blink ou challenge passou) → PASS
          // - stddev > CHALLENGE_HIGH → PASS (movimento claro)
          // - stddev entre LOW e HIGH sem blink → NEEDS CHALLENGE
          // - stddev < LOW → FAIL (foto estática)
          get _decision() {
            if (stddev >= 0.005 && (hasBlink || this.challengePassed)) return 'pass';
            if (stddev >= 0.005) return 'pass'; // Movimento forte suficiente
            if (stddev >= 0.003 && hasBlink) return 'pass';
            if (stddev < 0.001) return 'fail'; // Foto estática clara
            return 'needs_challenge';
          }
        };
      },

      // Resultado final com decisão
      getResult(textureScore) {
        this._stopGyroCollection();
        const result = this.evaluate();
        result.texture_score = textureScore;
        const stddev = result.micro_movement_stddev;
        const hasBlink = result.blink_count > 0;

        // Anti-replay: nonce + timestamp
        result.nonce = this._nonce;
        result.timestamp_ms = Date.now();

        // Giroscópio: variância do dispositivo
        const gyro = this.getGyroVariance();
        result.gyro_variance = gyro.variance;
        result.gyro_samples = gyro.samples;
        result.gyro_available = gyro.hasGyro;

        // Decisão final — prioriza micro-movimentos e piscar (mais confiáveis que texture)
        // Texture é bonus/penalidade, não gate principal (varia muito por câmera)
        // Regra 1: Foto estática clara (sem movimento nenhum) → rejeitar
        if (stddev < 0.001 && !hasBlink && !this.challengePassed) {
          result.passed = false;
        // Regra 2: Movimento natural + piscar → aceitar (cenário ideal)
        } else if (stddev >= 0.003 && hasBlink) {
          result.passed = true;
        // Regra 3: Movimento forte sem piscar → aceitar (pessoa pode não ter piscado no intervalo)
        } else if (stddev >= 0.005) {
          result.passed = true;
        // Regra 4: Challenge passou → aceitar
        } else if (this.challengePassed) {
          result.passed = true;
        // Regra 5: Movimento mínimo sem piscar → pedir challenge
        } else if (stddev >= 0.001 && !this.challengePassed) {
          result.needsChallenge = true;
          result.passed = false;
        } else {
          result.passed = false;
        }
        return result;
      }
    };

    // Overlay de processamento automático (modo reconhecimento facial)
    const fsProcessingOverlay = document.getElementById('fsProcessingOverlay');
    const fsProcessingText    = document.getElementById('fsProcessingText');

    function showFsProcessingOverlay(msg) {
      if (!fsProcessingOverlay) return;
      if (fsProcessingText) fsProcessingText.textContent = msg || 'Processando...';
      fsProcessingOverlay.style.display = 'flex';
    }

    function hideFsProcessingOverlay() {
      if (!fsProcessingOverlay) return;
      fsProcessingOverlay.style.display = 'none';
    }

    function startFaceDetectionLoop() {
      stopFaceDetectionLoop();
      autoCaptureFired = false;
      autoCaptureCountdown = 0;
      livenessAnalyzer.reset();
      if (!faceApiReady) {
        setFsDetectionStatus('loading');
        faceDetectionTimer = setTimeout(startFaceDetectionLoop, 1000);
        return;
      }
      faceDetectionRunning = true;
      let consecutiveDetections = 0;
      let livenessChallengePending = false;
      let livenessChallengePhase = null; // 'blink' | 'head_turn'

      async function tick() {
        if (!faceDetectionRunning || !fullscreenStream || !isFullscreenMode || autoCaptureFired) return;
        try {
          // Usar .withFaceLandmarks(true) para alimentar liveness (custo +20-40ms, aceitável)
          const det = await faceapi.detectSingleFace(
            fullscreenVideo,
            new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.3 })
          ).withFaceLandmarks(true);
          if (!faceDetectionRunning || !isFullscreenMode || autoCaptureFired) return;
          lastFaceDetected = !!det;

          let warn = false;
          let msg = 'Rosto enquadrado';

          if (det) {
            const box = det.detection.box;
            const vw = fullscreenVideo.videoWidth;
            const vh = fullscreenVideo.videoHeight;
            if (vw > 0 && vh > 0) {
              const cx = box.x + box.width / 2;
              const cy = box.y + box.height / 2;
              const centerTol = Math.min(vw, vh) * 0.15;
              const dx = cx - (vw / 2);
              const dy = cy - (vh / 2);
              const dist = Math.hypot(dx, dy);

              const shortSide = Math.min(vw, vh);
              const desiredWidthMin = shortSide * 0.20;
              const desiredWidthMax = shortSide * 0.70;

              if (box.width < desiredWidthMin) {
                warn = true;
                msg = 'Aproxime o rosto';
              } else if (box.width > desiredWidthMax) {
                warn = true;
                msg = 'Afaste um pouco';
              } else if (dist > centerTol) {
                warn = true;
                msg = 'Centralize o rosto na tela';
              }

              // Verificação rápida de luminosidade (Luma)
              if (!warn) {
                try {
                  const lumaCanvas = document.createElement('canvas');
                  const lumaCtx = lumaCanvas.getContext('2d', { willReadFrequently: true });
                  lumaCanvas.width = 64;
                  lumaCanvas.height = 64;
                  lumaCtx.drawImage(fullscreenVideo, 0, 0, 64, 64);
                  const imgData = lumaCtx.getImageData(0, 0, 64, 64).data;
                  let sum = 0;
                  for (let i = 0; i < imgData.length; i += 4) {
                    sum += (imgData[i] * 0.299 + imgData[i + 1] * 0.587 + imgData[i + 2] * 0.114);
                  }
                  const avgLuma = sum / (64 * 64);
                  if (avgLuma < 50) {
                    warn = true;
                    msg = 'Ambiente muito escuro';
                  } else if (avgLuma > 240) {
                    warn = true;
                    msg = 'Luz muito forte no rosto';
                  }
                } catch(e) {}
              }

              // Alimentar liveness analyzer com landmarks (Camadas 1+2)
              if (!warn && det.landmarks) {
                livenessAnalyzer.addFrame(det.landmarks, box);
              }
            }
          } else {
             warn = true;
          }

          // --- Modo desafio ativo (Camada 3) ---
          if (livenessChallengePending && det && det.landmarks) {
            const challengeResult = livenessAnalyzer.processChallenge(det.landmarks);
            if (challengeResult) {
              if (challengeResult.passed) {
                livenessChallengePending = false;
                livenessChallengePhase = null;
                setFsDetectionStatus('detected', 'Verificação OK! Capturando...');
                startAutoCapture();
                return;
              } else if (challengeResult.reason === 'timeout') {
                // Timeout do desafio atual
                if (livenessChallengePhase === 'blink') {
                  // Escalar para desafio de virar cabeça
                  livenessChallengePhase = 'head_turn';
                  livenessAnalyzer.startChallenge('head_turn');
                  setFsDetectionStatus('detected', 'Vire levemente a cabeça para o lado');
                } else {
                  // Ambos desafios falharam
                  livenessChallengePending = false;
                  livenessChallengePhase = null;
                  consecutiveDetections = 0;
                  setFsDetectionStatus('no-face', 'Verificação de vivacidade falhou. Tente novamente.');
                  livenessAnalyzer.reset();
                }
              }
            } else {
              // Ainda esperando desafio
              if (livenessChallengePhase === 'blink') {
                const elapsed = ((Date.now() - livenessAnalyzer._challengeStartTime) / 1000).toFixed(0);
                setFsDetectionStatus('detected', `Pisque os olhos... ${5 - elapsed}s`);
              } else {
                const elapsed = ((Date.now() - livenessAnalyzer._challengeStartTime) / 1000).toFixed(0);
                setFsDetectionStatus('detected', `Vire a cabeça... ${5 - elapsed}s`);
              }
            }
            if (faceDetectionRunning && isFullscreenMode && !autoCaptureFired) {
              faceDetectionTimer = setTimeout(tick, 300);
            }
            return;
          }

          // --- Fluxo normal com liveness integrado ---
          if (det && !warn) {
            consecutiveDetections++;
            // Exige ~3 segundos de estabilidade para coletar dados de liveness
            // (loop a cada ~300ms, 10 ticks = 3s — janela completa do analyzer)
            const REQUIRED_CONSECUTIVE = 10;

            if (consecutiveDetections >= REQUIRED_CONSECUTIVE && !autoCaptureTimer) {
              // Avaliar liveness antes de capturar
              const livenessResult = livenessAnalyzer.evaluate();
              const stddev = livenessResult.micro_movement_stddev;
              const hasBlink = livenessResult.blink_count > 0;

              if (stddev >= 0.005 || (stddev >= 0.003 && hasBlink)) {
                // Liveness passivo OK — capturar
                setFsDetectionStatus('detected', 'Capturando...');
                startAutoCapture();
                return;
              } else if (stddev < 0.001) {
                // Foto estática clara — rejeitar
                consecutiveDetections = 0;
                setFsDetectionStatus('no-face', 'Imagem estática detectada. Mova naturalmente.');
                livenessAnalyzer.reset();
              } else {
                // Inconclusivo — iniciar desafio ativo
                livenessChallengePending = true;
                livenessChallengePhase = 'blink';
                livenessAnalyzer.startChallenge('blink');
                setFsDetectionStatus('detected', 'Pisque os olhos naturalmente...');
              }
            } else if (!autoCaptureTimer) {
              const remaining = REQUIRED_CONSECUTIVE - consecutiveDetections;
              const secs = (remaining * 0.3).toFixed(1);
              setFsDetectionStatus('detected', `Segure firme... ${secs}s`);
            }
          } else {
            consecutiveDetections = 0;
            cancelAutoCapture();
            livenessChallengePending = false;
            livenessChallengePhase = null;
            if (!det) {
                setFsDetectionStatus('no-face', 'Nenhum rosto encontrado');
            } else {
                setFsDetectionStatus('no-face', msg);
            }
          }
        } catch (e) {
          console.warn('[FaceDetection] tick error:', e);
        }
        if (faceDetectionRunning && isFullscreenMode && !autoCaptureFired) {
          faceDetectionTimer = setTimeout(tick, 300);
        }
      }
      faceDetectionTimer = setTimeout(tick, 300);
    }

    function startAutoCapture() {
      if (autoCaptureTimer || autoCaptureFired) return;
      autoCaptureFired = true;
      if (fullscreenInstruction) {
        fullscreenInstruction.textContent = 'Rosto detectado!';
        fullscreenInstruction.className = 'fs-instruction-float face-ok';
      }
      if (fsFaceText) fsFaceText.textContent = 'Capturando...';
      handleFullscreenCapture();
    }

    function cancelAutoCapture() {
      if (autoCaptureTimer) {
        clearInterval(autoCaptureTimer);
        autoCaptureTimer = null;
      }
      autoCaptureCountdown = 0;
    }

    function updateAutoCaptureUI() {
      // Mantido por compatibilidade — não exibe mais contagem regressiva
    }

    function stopFaceDetectionLoop() {
      faceDetectionRunning = false;
      cancelAutoCapture();
      if (faceDetectionTimer) {
        clearTimeout(faceDetectionTimer);
        faceDetectionTimer = null;
      }
      lastFaceDetected = null;
      if (fsFaceStatus) { fsFaceStatus.classList.remove('visible', 'detected', 'no-face', 'loading'); }
      if (fullscreenFaceRing) { fullscreenFaceRing.className = 'face-ring'; }
      if (fullscreenInstruction) {
        fullscreenInstruction.textContent = 'Centralize seu rosto na área indicada';
        fullscreenInstruction.className = 'fs-instruction-float';
      }
    }

    async function enterFullscreenCamera(resetFaceRetry = true) {
      if (resetFaceRetry) {
        faceAutoRetryCount = 0;
      }
      isFullscreenMode = true;
      fullscreenCamera.classList.add('active');

      updateFsClock();
      fsClockInterval = setInterval(updateFsClock, 1000);
      setFsDetectionStatus('loading');

      // Pré-busca geolocalização em paralelo para ter o dado pronto ao registrar
      if (!cachedGeo) {
        getGeo().then(g => { if (g) cachedGeo = g; }).catch(() => {});
      }

      try {
        // iOS Safari: resolução menor inicia mais rápido (~800ms vs ~2s para 1280px).
        // 640×480 é mais que suficiente para detecção e extração do descriptor facial.
        const isiOS = /iP(hone|ad|od)/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const idealW = isiOS ? 640 : 1280;
        const idealH = isiOS ? 480 : 1280;
        const constraints = {
          audio: false,
          video: {
            facingMode: { ideal: currentFacing || 'user' },
            width: { ideal: idealW },
            height: { ideal: idealH }
          }
        };
        fullscreenStream = await requestCameraStream(constraints);
        assignStreamToVideo(fullscreenVideo, fullscreenStream);
        await fullscreenVideo.play();
        startFaceDetectionLoop();
      } catch (err) {
        console.error('Fullscreen camera error:', err);
        exitFullscreenCamera();
        const errMsg = err?.name === 'NotAllowedError'
          ? 'Permissão de câmera negada. Permita o acesso nas configurações do navegador.'
          : err?.name === 'NotFoundError'
            ? 'Câmera não encontrada neste dispositivo.'
            : 'Não foi possível abrir a câmera.';
        toast('error', 'Erro de câmera', errMsg, []);
      }
    }

    function exitFullscreenCamera() {
      isFullscreenMode = false;
      fullscreenCamera.classList.remove('active');

      if (fsClockInterval) { clearInterval(fsClockInterval); fsClockInterval = null; }
      stopFaceDetectionLoop();

      if (fullscreenStream) {
        fullscreenStream.getTracks().forEach(t => t.stop());
        fullscreenStream = null;
      }
      clearVideoStream(fullscreenVideo);

      if (typeof showHome === 'function') showHome();
    }

    async function handleFullscreenCapture() {
      if (!fullscreenStream) return;

      btnFullscreenCapture.classList.add('btn-press');
      setTimeout(() => btnFullscreenCapture.classList.remove('btn-press'), 150);

      const flash = document.createElement('div');
      flash.className = 'flash-overlay';
      document.body.appendChild(flash);
      setTimeout(() => flash.remove(), 300);

      const canvas = document.createElement('canvas');
      canvas.width = fullscreenVideo.videoWidth;
      canvas.height = fullscreenVideo.videoHeight;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });

      // Capturar SEM transform — o transform de espelho era cosmético e
      // causava luma=0 em Chrome (drawImage com CTM modificado retorna preto).
      ctx.drawImage(fullscreenVideo, 0, 0);

      // Camada 4: Análise de textura antes de parar o loop
      let textureScore = 0.5;
      try {
        const texDet = await faceapi.detectSingleFace(
          canvas,
          new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.25 })
        );
        if (texDet) {
          textureScore = livenessAnalyzer.analyzeTexture(canvas, texDet.box);
        }
      } catch (e) { /* texture analysis is best-effort */ }

      // Coletar dados de liveness antes de resetar
      const livenessData = livenessAnalyzer.getResult(textureScore);

      stopFaceDetectionLoop();

      resetFaceAttemptState();
      if (faceDescriptorReady) {
        showFsProcessingOverlay('Analisando rosto...');
        const [descriptor, dataUrl] = await Promise.all([
          extractDescriptorRobust(canvas),
          Promise.resolve(downscaleToJpeg(canvas, 500, 0.6))
        ]);
        capturedDescriptor = descriptor;
        capturedDataUrl = dataUrl;
        // Armazenar liveness data para envio ao servidor
        window._lastLivenessData = livenessData;
        console.log('[FaceAPI] Descriptor extraído:', capturedDescriptor ? 'sim' : 'não');
        console.log('[Liveness] Resultado:', JSON.stringify(livenessData));
      } else {
        capturedDataUrl = downscaleToJpeg(canvas, 500, 0.6);
        window._lastLivenessData = null;
      }
      exitFullscreenCamera();

      // Se temos descriptor, tentar identificação facial server-side
      if (capturedDescriptor) {
        await tryFaceIdentification();
      } else {
        // G5: Feedback ao usuario quando descriptor nao foi extraido
        toast('warning', 'Rosto não capturado',
          'A câmera não conseguiu processar seu rosto. Tente novamente.',
          ['Melhore a iluminação do ambiente.', 'Olhe diretamente para a câmera.', 'Mantenha o rosto centralizado.']);
        openConfirmModal();
        showCapturedFaceBadge(false);
      }
      vibrate(30);
    }

    function showCapturedFaceBadge(hasDescriptor) {
      return; // badge removido da UI
      const faceBadge = document.getElementById('capturedFaceBadge');
      const faceBadgeIcon = document.getElementById('capturedFaceBadgeIcon');
      const faceBadgeText = document.getElementById('capturedFaceBadgeText');
      if (!faceBadge) return;
      faceBadge.style.display = '';
      if (hasDescriptor && faceAuthMode) {
        faceBadge.className = 'captured-face-badge ok';
        faceBadgeIcon.className = 'bi bi-shield-check';
        faceBadgeText.textContent = 'Identidade confirmada por reconhecimento facial';
      } else if (hasDescriptor) {
        faceBadge.className = 'captured-face-badge ok';
        faceBadgeIcon.className = 'bi bi-camera-fill';
        faceBadgeText.textContent = 'Foto capturada — digite o PIN para verificar';
      } else {
        faceBadge.className = 'captured-face-badge fail';
        faceBadgeIcon.className = 'bi bi-exclamation-triangle';
        faceBadgeText.textContent = 'Rosto não capturado — digite o PIN';
      }
    }

    // Registra o ponto automaticamente após reconhecimento facial, sem abrir modais
    async function autoSubmitFaceMode() {
      showFsProcessingOverlay('Registrando ponto...');
      try {
        // Garante geo — deve estar cacheada da pré-busca, mas aguarda se necessário
        if (!cachedGeo) cachedGeo = await getGeo();
        let challengeRetryCount = 0;
        while (true) {
          const cpf = (document.getElementById('cpf').value || '').trim();
          const previewPayload = { preview: true, face_descriptor: capturedDescriptor };
          if (cpf) previewPayload.cpf = cpf;
          // Incluir deviceFingerprint para que o challenge token use o mesmo hash da confirmação
          previewPayload.deviceFingerprint = cachedGeo?.deviceFingerprint || await generateDeviceFingerprint();
          // Incluir dados de liveness para validação server-side
          if (window._lastLivenessData) {
            previewPayload.liveness_data = window._lastLivenessData;
          }
          faceChallengeToken = null; // Força nova sessão facial por tentativa.
          const previewRes = await fetch(apiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(previewPayload)
          });
          const previewText = await previewRes.text();
          let previewDataResp = null;
          try {
            previewDataResp = previewText ? JSON.parse(previewText) : null;
          } catch {
            previewDataResp = null;
          }

          // "Rosto não identificado" é fluxo esperado: cair para CPF sem erro no console.
          if (!previewRes.ok || previewDataResp?.status !== 'preview') {
            if (previewDataResp && (previewDataResp.status === 'error' || previewDataResp.code)) {
              // UX: em caso de rosto não identificado, tenta 1 recaptura automática
              // antes de cair para o fallback de PIN.
              if (
                previewDataResp?.code === 'face_not_recognized' &&
                faceAutoRetryCount < MAX_FACE_AUTO_RETRY
              ) {
                faceAutoRetryCount += 1;
                toast('info', 'Nova tentativa automática', 'Rosto não identificado na primeira tentativa. Vamos capturar novamente.', []);
                await enterFullscreenCamera(false);
                return;
              }
              handleApiError(previewRes.status, previewDataResp);
              maybeResetStageForCpfError(previewDataResp?.code);
              return;
            }
            throw new Error(previewDataResp?.message || 'Falha ao iniciar sessão facial.');
          }
          faceChallengeToken = previewDataResp.face_challenge || null;
          if (!faceChallengeToken) {
            throw new Error('Sessão facial não retornou challenge válido.');
          }
          previewData = previewDataResp;
          const submitResult = await submitPointWithCpf(null);
          if (submitResult?.ok) {
            return;
          }
          if (submitResult?.code === 'face_challenge_invalid' && challengeRetryCount < 1) {
            challengeRetryCount += 1;
            toast('info', 'Sessão facial renovada', 'A sessão expirou e vamos tentar novamente automaticamente.', []);
            continue;
          }
          if (submitResult?.code === 'face_challenge_invalid') {
            handleApiError(400, {
              code: 'face_challenge_invalid',
              message: 'Sessão facial expirada. Digite seu CPF para continuar.'
            });
            return;
          }
          return;
        }
      } catch (e) {
        console.warn('[FaceID] Falha no auto-submit:', e?.message || e);
        toast('error', 'Erro ao registrar', e?.message || 'Tente novamente.', []);
      } finally {
        hideFsProcessingOverlay();
        faceChallengeToken = null;
      }
    }

    async function tryFaceIdentification() {
      // Fluxo otimizado: vai direto ao checkin.php com face_descriptor.
      // checkin.php já faz o matching facial internamente, eliminando a chamada redundante
      // a identify_face.php e economizando uma ida-e-volta de rede (~500–1500ms).
      // Se o servidor não reconhecer o rosto (face_not_recognized), handleApiError
      // abrirá automaticamente o modal de PIN como fallback.
      faceAuthMode = true;
      capturedFaceMatch = true;
      capturedFaceDistance = null;
      await autoSubmitFaceMode();
    }

    btnFullscreenCapture.addEventListener('click', handleFullscreenCapture);

    btnFullscreenFlip.addEventListener('click', async () => {
      const nextFacing = currentFacing === 'user' ? 'environment' : 'user';
      currentFacing = nextFacing;

      if (fullscreenStream) {
        fullscreenStream.getTracks().forEach(t => t.stop());
      }

      try {
        const constraints = {
          audio: false,
          video: {
            facingMode: { ideal: nextFacing },
            width: { ideal: 1280 },
            height: { ideal: 1280 }
          }
        };
        fullscreenStream = await requestCameraStream(constraints);
        assignStreamToVideo(fullscreenVideo, fullscreenStream);
        await fullscreenVideo.play();
      } catch (err) {
        console.error('Camera flip error:', err);
      }
    });

    btnExitFullscreen.addEventListener('click', () => {
      exitFullscreenCamera();
    });


    // Add button animations for all buttons
    document.querySelectorAll('.btn, .nav-card').forEach(btn => {
      btn.addEventListener('click', function(e) {
        // Add haptic feedback simulation
        this.classList.add('btn-press');
        setTimeout(() => this.classList.remove('btn-press'), 150);
      });
    });

    // Tap-anywhere-to-capture on mobile (standard video view)
    if (isMobileDevice()) {
      video.addEventListener('click', () => {
        if (stream && video.style.display !== 'none' && !capturedDataUrl) {
          // Flash effect
          const flash = document.createElement('div');
          flash.className = 'flash-overlay';
          document.body.appendChild(flash);
          setTimeout(() => flash.remove(), 300);
          
          // Trigger capture
          if (autoCaptureSwitch.checked) {
            // Let auto-capture handle it
          } else {
            doCapture();
          }
          vibrate(20);
        }
      });
    }

    // Enhance face detection visual feedback
    const faceGuideEl = document.getElementById('staticFaceGuide');
    if (faceGuideEl) {
      const faceRing = faceGuideEl.querySelector('.face-ring');
      if (faceRing) {
        // This will be controlled by face detection in the existing code
        // Just ensure the pulse animation is active
      }
    }

    // ============================================================================
    // CAMERA CARD OVERLAY SYNC & EXTERNAL CONTROLS
    // ============================================================================

    // Elementos externos
    const btnCaptureExternal = document.getElementById('btnCaptureExternal');
    const btnRetakeExternal = document.getElementById('btnRetakeExternal');
    const autoCaptureOverlay = document.getElementById('autoCaptureOverlay');

    // Sincronizar checkbox auto-capture (bidirectional)
    if (autoCaptureSwitch && autoCaptureOverlay) {
      autoCaptureOverlay.checked = autoCaptureSwitch.checked;
      
      autoCaptureSwitch.addEventListener('change', () => {
        autoCaptureOverlay.checked = autoCaptureSwitch.checked;
        autoCaptureEnabled = !!autoCaptureSwitch.checked;
        if (!autoCaptureEnabled) lastOkTs = 0;
        updateExternalButtons();
      });
      
      autoCaptureOverlay.addEventListener('change', () => {
        autoCaptureSwitch.checked = autoCaptureOverlay.checked;
        autoCaptureEnabled = !!autoCaptureOverlay.checked;
        if (!autoCaptureEnabled) lastOkTs = 0;
        updateExternalButtons();
      });
    }

    // Conectar botões externos aos originais
    if (btnCaptureExternal) {
      btnCaptureExternal.addEventListener('click', () => {
        if (btnCapture) btnCapture.click();
      });
    }

    if (btnRetakeExternal) {
      btnRetakeExternal.addEventListener('click', () => {
        if (btnRetake) btnRetake.click();
      });
    }

    // Atualizar visibilidade dos botões externos
    function updateExternalButtons() {
      const autoCapture = autoCaptureSwitch ? autoCaptureSwitch.checked : true;
      const hasPhoto = capturedDataUrl ? true : false;

      // Botão Capturar: só mostra se modo rápido OFF e não tem foto
      if (btnCaptureExternal) {
        if (!autoCapture && !hasPhoto && stream) {
          btnCaptureExternal.classList.remove('d-none');
        } else {
          btnCaptureExternal.classList.add('d-none');
        }
      }

      // Botão Retake: só mostra se tem foto capturada
      if (btnRetakeExternal) {
        if (hasPhoto) {
          btnRetakeExternal.classList.remove('d-none');
        } else {
          btnRetakeExternal.classList.add('d-none');
        }
      }
    }

    // Observer para mudanças nos botões originais (captura e retake)
    if (typeof MutationObserver !== 'undefined') {
      const btnObserver = new MutationObserver(updateExternalButtons);
      
      if (btnCapture) {
        btnObserver.observe(btnCapture, { attributes: true, attributeFilter: ['class'] });
      }
      if (btnRetake) {
        btnObserver.observe(btnRetake, { attributes: true, attributeFilter: ['class'] });
      }
    }

    // Atualizar quando foto é capturada ou removida
    const originalDoCapture = doCapture;
    doCapture = function() {
      originalDoCapture();
      setTimeout(updateExternalButtons, 100);
    };

    const originalRetake = btnRetake ? btnRetake.onclick : null;
    if (btnRetake) {
      btnRetake.addEventListener('click', () => {
        setTimeout(updateExternalButtons, 100);
      });
    }

    // Atualizar periodicamente
    setInterval(updateExternalButtons, 1000);

    // Sync inicial
    setTimeout(() => {
      updateExternalButtons();
    }, 500);

    console.log('Camera card overlay initialized');
  </script>

  <!-- Home Screen Logic -->
  <script>
  (function() {
    const SCHOOLS = <?= json_encode(array_map(function($s) {
      return ['id' => (int)$s['id'], 'name' => $s['name'], 'lat' => (float)$s['lat'], 'lng' => (float)$s['lng']];
    }, $schoolsGeo), JSON_UNESCAPED_UNICODE) ?>;

    const homeScreen    = document.getElementById('homeScreen');
    const cameraSection = document.getElementById('cameraSection');
    const btnGoCheckin  = document.getElementById('btnGoCheckin');
    const btnBackHome   = document.getElementById('btnBackHome');
    const homeClockEl   = document.getElementById('homeClock');
    const homeDateEl    = document.getElementById('homeDate');
    const homeNetBadge  = document.getElementById('homeNetBadge');
    const homeGeoBadge  = document.getElementById('homeGeoBadge');

    let geoWatchId = null;

    function updateHomeClock() {
      const now = new Date();
      homeClockEl.textContent = now.toLocaleTimeString('pt-BR', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
      homeDateEl.textContent = now.toLocaleDateString('pt-BR', { weekday:'long', day:'numeric', month:'long', year:'numeric' });
    }

    function updateNetStatus() {
      const online = navigator.onLine;
      const icon = homeNetBadge.querySelector('i');
      const text = homeNetBadge.querySelector('span');
      homeNetBadge.className = 'home-badge ' + (online ? 'online' : 'offline');
      icon.className = online ? 'bi bi-wifi' : 'bi bi-wifi-off';
      text.textContent = online ? 'Online' : 'Offline';
    }

    function updateGeoStatus(state, detail) {
      if (!homeGeoBadge) return;
      const icon = homeGeoBadge.querySelector('i');
      const text = homeGeoBadge.querySelector('span');
      if (state === 'ok') {
        homeGeoBadge.className = 'home-badge geo-ok';
        if (icon) icon.className = 'bi bi-geo-alt-fill';
        if (text) text.textContent = 'Localização capturada';
      } else if (state === 'error') {
        homeGeoBadge.className = 'home-badge geo-far';
        if (icon) icon.className = 'bi bi-geo';
        if (text) text.textContent = detail || 'Ative a localização';
      } else {
        homeGeoBadge.className = 'home-badge geo-wait';
        if (icon) icon.className = 'bi bi-geo-alt';
        if (text) text.textContent = 'Localizando...';
      }
    }

    function stopGeoWatch() {
      if (geoWatchId != null && 'geolocation' in navigator) {
        try { navigator.geolocation.clearWatch(geoWatchId); } catch (_) {}
      }
      geoWatchId = null;
    }

    // Cache da última posição capturada pelo watch — reusada em pinGetGeo
    // quando o GPS pontual demora ou falha (Camada 1 do plano de fallback).
    let pinLastWatchedGeo = null;
    function startGeoWatch() {
      if (!('geolocation' in navigator)) {
        updateGeoStatus('error', 'Sem suporte');
        return;
      }
      // Evita acumular múltiplos watchers em navegações SPA (camera → home → camera).
      stopGeoWatch();
      updateGeoStatus('wait');
      geoWatchId = navigator.geolocation.watchPosition(
        (pos) => {
          updateGeoStatus('ok');
          pinLastWatchedGeo = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            acc: pos.coords.accuracy,
            at:  Date.now(),
          };
        },
        (err) => {
          const msg = err && err.code === 1 ? 'Permissão negada' : 'Indisponível';
          updateGeoStatus('error', msg);
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 }
      );
    }

    // Libera watcher quando o usuário sai da página (limpa drain de bateria).
    window.addEventListener('pagehide', stopGeoWatch);

    async function showCamera() {
      // Pré-checagem de ambiente antes de abrir câmera
      const checks = [];
      let allOk = true;

      // 1. Câmera
      try {
        const camPerm = await navigator.permissions.query({ name: 'camera' });
        if (camPerm.state === 'denied') {
          checks.push({ ok: false, label: 'Câmera bloqueada — ative nas configurações do navegador' });
          allOk = false;
        } else {
          checks.push({ ok: true, label: 'Câmera disponível' });
        }
      } catch (e) {
        checks.push({ ok: true, label: 'Câmera (verificação indisponível)' });
      }

      // 2. Geolocalização
      try {
        const geoPerm = await navigator.permissions.query({ name: 'geolocation' });
        if (geoPerm.state === 'denied') {
          checks.push({ ok: false, label: 'Localização bloqueada — ative nas configurações' });
          allOk = false;
        } else {
          checks.push({ ok: true, label: 'Localização disponível' });
        }
      } catch (e) {
        checks.push({ ok: true, label: 'Localização (verificação indisponível)' });
      }

      // 3. Rede
      if (!navigator.onLine) {
        checks.push({ ok: false, label: 'Sem conexão de rede' });
        allOk = false;
      } else {
        checks.push({ ok: true, label: 'Conectado à rede' });
      }

      // 4. Modelos face-api
      if (!faceApiReady) {
        checks.push({ ok: false, label: 'Modelos de reconhecimento facial carregando...' });
        allOk = false;
      } else {
        checks.push({ ok: true, label: 'Reconhecimento facial pronto' });
      }

      // Se alguma checagem falhou, mostrar toast com detalhes
      if (!allOk) {
        const failedChecks = checks.filter(c => !c.ok).map(c => c.label);
        toast('warning', 'Verificação de ambiente', failedChecks.join('. ') + '.', []);
        // Se câmera ou modelos não disponíveis, bloquear
        const critical = checks.some(c => !c.ok && (c.label.includes('Câmera bloqueada') || c.label.includes('Modelos')));
        if (critical) return;
      }

      if (typeof enterFullscreenCamera === 'function') {
        enterFullscreenCamera();
      }
    }

    function showHome() {
      homeScreen.style.display = 'block';
      homeScreen.style.animation = 'homeFadeIn .35s ease';
    }

    // Estado inicial enquanto modelos carregam
    updateCheckinButtonState();

    // O botão principal agora abre o fluxo PIN (método padrão).
    // O reconhecimento facial ficou acessível pelo link "Usar reconhecimento facial" na home.
    btnGoCheckin.addEventListener('click', () => {
      _breakActionRequested = false;
      pinEntryMain();
    });
    // Botão secundário "Iniciar Intervalo": sinaliza intenção e chama o mesmo fluxo.
    // pinExpectedActionForCpf consulta a flag para mapear o expected_action correto.
    const btnGoBreak = document.getElementById('btnGoBreak');
    if (btnGoBreak) {
      btnGoBreak.addEventListener('click', () => {
        _breakActionRequested = true;
        pinEntryMain();
      });
    }
    btnBackHome.addEventListener('click', showHome);

    // H2: detecta cookies bloqueados (Safari Private, modo incógnito agressivo).
    // Sem cookies, o trust de device cai no fingerprint legacy a cada request,
    // e o usuário pode passar por step-up facial mesmo após autenticar uma vez.
    // Avisa uma única vez para reduzir confusão.
    if (typeof navigator.cookieEnabled !== 'undefined' && !navigator.cookieEnabled) {
      setTimeout(() => {
        if (typeof toast === 'function') {
          toast('warning', 'Cookies bloqueados',
            'Seu navegador está em modo privado ou bloqueando cookies. O sistema vai funcionar, mas pode pedir foto com mais frequência.', []);
        }
      }, 1500);
    }

    updateHomeClock();
    setInterval(updateHomeClock, 1000);

    updateNetStatus();
    window.addEventListener('online', updateNetStatus);
    window.addEventListener('offline', updateNetStatus);

    startGeoWatch();

    // ============================================================
    // FLUXO PIN — reutiliza utilitários do fluxo face existente:
    //   toast(), generateDeviceFingerprint(), syncWithHLB(), detectGpsMock().
    // Step-up facial usa face-api (já carregada para o fluxo legado).
    // Endpoints: api/pin_enroll.php | api/pin_recover.php | api/checkin.php
    // ============================================================
    // Reconstrói ROOT_BASE localmente (mesma convenção do IIFE face em /api/checkin.php:3196-3197).
    // O projeto usa .htaccess que reescreve / para /public/, então precisamos tirar o sufixo /public
    // do meta app-base para apontar para /api/ na raiz.
    const PIN_APP_BASE = (document.querySelector('meta[name="app-base"]')?.getAttribute('content') || '/').replace(/\/+$/, '');
    const PIN_ROOT_BASE = PIN_APP_BASE.replace(/\/public$/, '');
    const PIN_API = {
      enroll:  (PIN_ROOT_BASE || '') + '/api/pin_enroll.php',
      recover: (PIN_ROOT_BASE || '') + '/api/pin_recover.php',
      checkin: (PIN_ROOT_BASE || '') + '/api/checkin.php',
    };
    // Dados do colaborador logado (null em modo kiosk)
    const SESSION_COLLAB = <?= json_encode($collaborator ? [
      'id'   => (int)$collaborator['id'],
      'name' => (string)$collaborator['name'],
      'cpf'  => preg_replace('/\D/', '', (string)$collaborator['cpf']),
    ] : null, JSON_UNESCAPED_UNICODE) ?>;
    const SESSION_MODE = SESSION_COLLAB !== null;
    const SESSION_CSRF = <?= json_encode($collaborator ? csrf_token() : '') ?>;
    const KIOSK_MODE   = <?= $kioskMode ? 'true' : 'false' ?>;
    const OPEN_FIRST_ACCESS = <?= $openFirstAccess ? 'true' : 'false' ?>;
    const OPEN_FORGOT_PIN   = <?= $openForgotPin ? 'true' : 'false' ?>;
    const PREFILL_CPF       = <?= json_encode($prefillCpf) ?>;

    const pinSectionEl        = document.getElementById('pinSection');
    const pinEnrollSectionEl  = document.getElementById('pinEnrollSection');
    const pinChooseSectionEl  = document.getElementById('pinChooseSection');
    const pinRecoverSectionEl = document.getElementById('pinRecoverSection');
    const pinShowSectionEl    = document.getElementById('pinShowSection');
    const pinStepupSectionEl  = document.getElementById('pinStepupSection');
    const pinAllSections = [pinSectionEl, pinEnrollSectionEl, pinChooseSectionEl, pinRecoverSectionEl, pinShowSectionEl, pinStepupSectionEl];

    function pinHideAll() { pinAllSections.forEach(el => { if (el) el.style.display = 'none'; }); }
    function pinGoHome()  {
      pinHideAll();
      pinStopStepupCamera();
      if (typeof cameraSection !== 'undefined' && cameraSection) cameraSection.style.display = 'none';
      if (typeof homeScreen !== 'undefined' && homeScreen) {
        homeScreen.style.display = 'block';
      }
      // Limpa PIN plain da memória quando usuário navega para fora de pin-show
      window.__pinLastPlain = null;
      // H1: limpa face capturada e CPF intermediários para evitar reuso stale.
      if (typeof pinEnrollClearState === 'function') pinEnrollClearState();
    }
    function pinShowSection(el) {
      pinHideAll();
      if (typeof homeScreen !== 'undefined' && homeScreen) homeScreen.style.display = 'none';
      if (typeof cameraSection !== 'undefined' && cameraSection) cameraSection.style.display = 'none';
      if (el) { el.style.display = 'block'; window.scrollTo({ top: 0 }); }
    }

    function pinMaskCpf(v) {
      v = (v || '').replace(/\D/g, '').slice(0, 11);
      return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    }
    function pinCleanCpf(v) { return (v || '').replace(/\D/g, ''); }

    const pinCpfEl          = document.getElementById('pinCpf');
    const pinEnrollCpfEl    = document.getElementById('pinEnrollCpf');
    const pinRecoverCpfEl   = document.getElementById('pinRecoverCpf');
    const pinSubmitBtn      = document.getElementById('btnPinSubmit');
    const pinEnrollSubmitBtn  = document.getElementById('btnPinEnrollSubmit');
    const pinRecoverSubmitBtn = document.getElementById('btnPinRecoverSubmit');

    [pinCpfEl, pinEnrollCpfEl, pinRecoverCpfEl].forEach(el => {
      if (!el) return;
      el.addEventListener('input', () => { el.value = pinMaskCpf(el.value); pinValidate(); });
    });

    const pinDigitsEls = Array.from(document.querySelectorAll('#pinDigits input'));
    pinDigitsEls.forEach((inp, idx) => {
      inp.addEventListener('input', () => {
        inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
        if (inp.value && idx < pinDigitsEls.length - 1) pinDigitsEls[idx + 1].focus();
        pinValidate();
      });
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !inp.value && idx > 0) pinDigitsEls[idx - 1].focus();
        if (e.key === 'Enter' && !pinSubmitBtn.disabled) pinCheckinSubmit();
      });
      inp.addEventListener('paste', (e) => {
        e.preventDefault();
        const txt = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, pinDigitsEls.length);
        let start = pinDigitsEls.findIndex(i => !i.value);
        if (start === -1) start = 0;
        for (let i = 0; i < txt.length && (start + i) < pinDigitsEls.length; i++) {
          pinDigitsEls[start + i].value = txt[i];
        }
        pinDigitsEls[Math.min(start + txt.length, pinDigitsEls.length - 1)].focus();
        pinValidate();
      });
    });
    function pinCollect() { return pinDigitsEls.map(i => i.value || '').join(''); }
    function pinResetDigits() { pinDigitsEls.forEach(i => i.value = ''); }

    function pinValidate() {
      if (pinSubmitBtn)         pinSubmitBtn.disabled         = !(pinCleanCpf(pinCpfEl.value).length === 11 && pinCollect().length === 6);
      if (pinEnrollSubmitBtn)   pinEnrollSubmitBtn.disabled   = pinCleanCpf(pinEnrollCpfEl.value).length !== 11;
      if (pinRecoverSubmitBtn)  pinRecoverSubmitBtn.disabled  = pinCleanCpf(pinRecoverCpfEl.value).length !== 11;
    }

    // Back: em modo sem sessão e sem kiosk (user veio de login.php via ?first_access ou ?forgot),
    // voltar significa ir para a tela de login — não para uma home que ainda não existe.
    function pinBackOrLogin() {
      if (!SESSION_MODE && !KIOSK_MODE) {
        window.location.href = 'login.php';
      } else {
        pinGoHome();
      }
    }
    document.querySelectorAll('[data-pin-back]').forEach(btn => btn.addEventListener('click', pinBackOrLogin));
    document.querySelectorAll('[data-pin-go="recover"]').forEach(btn => btn.addEventListener('click', () => pinEntryRecover()));

    function pinEntryMain() {
      pinResetDigits();
      pinShowSection(pinSectionEl);
      setTimeout(() => pinCpfEl && pinCpfEl.focus(), 50);
    }
    function pinEntryEnroll() {
      if (pinEnrollCpfEl) pinEnrollCpfEl.value = '';
      // H1: começa enrollment do zero — descarta qualquer face/cpf de fluxo
      // anterior cancelado.
      if (typeof pinEnrollClearState === 'function') pinEnrollClearState();
      pinValidate();
      pinShowSection(pinEnrollSectionEl);
      setTimeout(() => pinEnrollCpfEl && pinEnrollCpfEl.focus(), 50);
    }
    function pinEntryRecover() {
      if (pinRecoverCpfEl) pinRecoverCpfEl.value = '';
      pinValidate();
      pinShowSection(pinRecoverSectionEl);
      setTimeout(() => pinRecoverCpfEl && pinRecoverCpfEl.focus(), 50);
    }

    const pinBtnEnroll  = document.getElementById('btnPinEnroll');
    const pinBtnRecover = document.getElementById('btnPinRecover');
    const pinBtnUseFace = document.getElementById('btnUseFace');
    if (pinBtnEnroll)  pinBtnEnroll.addEventListener('click', pinEntryEnroll);
    if (pinBtnRecover) pinBtnRecover.addEventListener('click', pinEntryRecover);
    if (pinBtnUseFace) pinBtnUseFace.addEventListener('click', () => {
      if (typeof showCamera === 'function') showCamera();
    });

    async function pinApiPost(url, body) {
      try {
        // M1: anexa CSRF automaticamente quando há sessão ativa.
        // Backend valida em endpoints que exigem (pin_enroll, pin_recover).
        const headers = { 'Content-Type': 'application/json' };
        if (SESSION_MODE && SESSION_CSRF) {
          headers['X-CSRF-Token'] = SESSION_CSRF;
          if (typeof body === 'object' && body !== null && !body.csrf) body.csrf = SESSION_CSRF;
        }
        const res = await fetch(url, { method: 'POST', headers, body: JSON.stringify(body) });
        let data = null;
        try {
          data = await res.json();
        } catch (_) {
          // Resposta não-JSON: se HTTP não-OK, servidor com problema.
          if (!res.ok) {
            return {
              http: res.status,
              data: { status: 'error', code: 'server_error', message: 'Servidor indisponível (erro ' + res.status + '). Tente novamente em alguns segundos.' }
            };
          }
          data = { status: 'error', code: 'invalid_response', message: 'Resposta inesperada do servidor. Tente novamente.' };
        }
        return { http: res.status, data };
      } catch (e) {
        // Fetch falhou antes de receber resposta: tipicamente rede offline ou servidor fora.
        const msg = navigator.onLine
          ? 'Servidor indisponível agora. Tente novamente em instantes.'
          : 'Sem conexão com a internet. Verifique sua rede e tente de novo.';
        return {
          http: 0,
          data: { status: 'error', code: navigator.onLine ? 'server_unreachable' : 'offline', message: msg }
        };
      }
    }

    // Camada 1 do fallback de GPS:
    //  - timeout 12s (era 5s) para tolerar celulares antigos que demoram a fixar
    //  - maximumAge 120s (era 30s) — reusa posição recente sem repedir hardware
    //  - cache do startGeoWatch como fallback rápido se o getCurrentPosition demorar
    // Pega ~80% dos casos onde o usuário "está na escola mas o GPS não respondeu a tempo".
    function pinGetGeo() {
      return new Promise((resolve) => {
        if (!navigator.geolocation) return resolve(null);
        let settled = false;
        const settle = (v) => { if (!settled) { settled = true; resolve(v); } };

        // Timeout duro (13s) acima do timeout interno do getCurrentPosition (12s)
        const t = setTimeout(() => settle(null), 13000);

        // Tenta primeiro o getCurrentPosition (preciso). Se demorar mais que
        // 4s e existir cache do watch ≤60s atrás, resolve com cache imediato.
        const earlyFallback = setTimeout(() => {
          if (settled) return;
          if (pinLastWatchedGeo && (Date.now() - pinLastWatchedGeo.at) <= 60000) {
            const g = pinLastWatchedGeo;
            settle({ lat: g.lat, lng: g.lng, acc: g.acc });
          }
        }, 4000);

        navigator.geolocation.getCurrentPosition(
          pos => {
            clearTimeout(t); clearTimeout(earlyFallback);
            settle({ lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy });
          },
          () => {
            clearTimeout(t); clearTimeout(earlyFallback);
            // Em erro, ainda tenta usar cache do watch
            if (pinLastWatchedGeo && (Date.now() - pinLastWatchedGeo.at) <= 60000) {
              const g = pinLastWatchedGeo;
              settle({ lat: g.lat, lng: g.lng, acc: g.acc });
            } else {
              settle(null);
            }
          },
          { enableHighAccuracy: true, timeout: 12000, maximumAge: 120000 }
        );
      });
    }

    // Device fingerprint estável — SEMPRE lê do localStorage primeiro para
    // garantir consistência com o valor enrolado no login. Se inexistente,
    // gera via mesma fórmula do login.php (FNV-1a). Só cai em
    // generateDeviceFingerprint (canvas+WebGL pesado) como último recurso.
    function pinGetStableDeviceFp() {
      try {
        const cached = localStorage.getItem('ponto_device_fp_v1');
        if (cached && cached.length >= 8) return cached;
      } catch (_) {}
      try {
        // Dims orientation-independent para FP estável entre rotações.
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
      } catch (_) { return ''; }
    }

    async function pinBuildCommonPayload() {
      let deviceFingerprint = pinGetStableDeviceFp();
      // Fallback legado (só se localStorage falhou — ex.: modo privado restrito)
      if (!deviceFingerprint) {
        try { if (typeof generateDeviceFingerprint === 'function') deviceFingerprint = await generateDeviceFingerprint(); } catch (_) {}
      }
      let recordedAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
      let hlbOffsetSeconds = 0;
      try {
        if (typeof syncWithHLB === 'function') {
          const hlb = await syncWithHLB();
          if (hlb) {
            if (hlb.now && typeof hlb.now === 'string') recordedAt = hlb.now.slice(0, 19).replace('T', ' ');
            if (typeof hlb.offsetSeconds === 'number') hlbOffsetSeconds = hlb.offsetSeconds;
          }
        }
      } catch (_) {}
      return { deviceFingerprint, recordedAt, hlbOffsetSeconds };
    }

    // ============================================================
    // STEP-UP AUTO-CAPTURE
    // ============================================================
    // Estado interno
    let pinStepupStream        = null;
    let pinStepupPendingFlow   = null;  // 'main' | 'enroll' | 'recover' | 'session'
    let pinStepupPhotoOnly     = false; // captura simples para foto obrigatoria da entrada
    let pinStepupNonce         = null;  // guarda nonce devolvido por api/checkin.php
    let pinAutoCapRunning      = false; // loop de detecção ativo?
    let pinAutoCapCancelled    = false; // flag de cancelamento (botão voltar)
    let pinAutoCapCountdownId  = null;  // timeoutId do countdown
    let pinAutoCapStableFrames = 0;     // frames consecutivos com face boa
    let pinAutoCapFallbackId   = null;  // timeoutId do fallback manual
    let pinAutoCapStartedAt    = 0;
    const PIN_AUTOCAP_FRAMES_REQUIRED = 2;      // frames consecutivos OK (440ms estável — UX mais responsiva)
    const PIN_AUTOCAP_LOOP_MS         = 220;    // ~4.5 fps — suficiente para feedback
    const PIN_AUTOCAP_FALLBACK_MS     = 12000;  // 12s sem rosto → oferece manual

    function _pinStepupEl(id) { return document.getElementById(id); }

    // H4: leitores de tela anunciam a cada mudança de textContent. O loop
    // atualiza texto ~4x/s. Rastreamos o último estado/texto anunciado e só
    // atualizamos o DOM quando algo MUDA — drasticamente menos anúncios.
    let _pinStatusLastText = '';
    let _pinStatusLastState = '';
    function pinStepupSetStatus(text, state) {
      const el = _pinStepupEl('pinCaptureStatus');
      if (!el) return;
      const nextState = state || '';
      const nextText = String(text || '');
      // Debounce: se texto E estado iguais, não mexe no DOM (evita re-anúncio).
      if (nextText === _pinStatusLastText && nextState === _pinStatusLastState) return;
      _pinStatusLastText = nextText;
      _pinStatusLastState = nextState;
      const textEl = el.querySelector('.pin-capture-text');
      if (textEl) textEl.textContent = nextText;
      el.classList.remove('is-ok', 'is-error');
      if (nextState === 'ok') el.classList.add('is-ok');
      if (nextState === 'error') el.classList.add('is-error');
    }

    // H5 + M3: região visualmente oculta dedicada para anunciar eventos
    // pontuais (countdown, aparição do botão manual). Evita acumular no status
    // pill (que está em loop) e garante assertive priority.
    function pinStepupAnnounce(text) {
      let el = _pinStepupEl('pinStepupSrAnnounce');
      if (!el) {
        el = document.createElement('span');
        el.id = 'pinStepupSrAnnounce';
        el.setAttribute('aria-live', 'assertive');
        el.setAttribute('aria-atomic', 'true');
        el.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;';
        document.body.appendChild(el);
      }
      // força re-anúncio mesmo se texto repetir
      el.textContent = '';
      requestAnimationFrame(() => { el.textContent = text; });
    }
    function pinStepupSetGuide(state) {
      const el = _pinStepupEl('pinFaceGuide');
      if (!el) return;
      el.classList.remove('is-close', 'is-ok');
      if (state === 'close') el.classList.add('is-close');
      if (state === 'ok') el.classList.add('is-ok');
    }
    function pinStepupShowCountdown(n) {
      const el = _pinStepupEl('pinCaptureCountdown');
      if (!el) return;
      el.classList.remove('is-active');
      // reflow para reiniciar animação
      void el.offsetWidth;
      el.textContent = String(n);
      el.classList.add('is-active');
      // H5: anuncia para screen readers (visual é aria-hidden).
      pinStepupAnnounce('Capturando em ' + n);
    }
    function pinStepupFireFlash() {
      const el = _pinStepupEl('pinCaptureFlash');
      if (!el) return;
      el.classList.remove('is-fire');
      void el.offsetWidth;
      el.classList.add('is-fire');
    }
    function pinStepupShowSending(on) {
      const el = _pinStepupEl('pinCaptureSending');
      if (el) el.classList.toggle('is-on', !!on);
    }
    function pinStepupShowManualFallback(on) {
      const btn = _pinStepupEl('btnPinStepupManual');
      if (!btn) return;
      const wasHidden = btn.style.display === 'none';
      btn.classList.toggle('btn-pin-photo-capture', !!pinStepupPhotoOnly);
      btn.setAttribute('aria-label', pinStepupPhotoOnly ? 'Tirar foto da entrada' : 'Tirar foto manualmente');
      btn.innerHTML = pinStepupPhotoOnly
        ? '<i class="bi bi-camera"></i> Tirar foto'
        : '<i class="bi bi-camera"></i> Tirar foto manualmente';
      btn.style.display = on ? '' : 'none';
      // M3: anuncia para screen readers quando o botão aparece — mudança
      // silenciosa passaria despercebida por usuários cegos.
      if (on && wasHidden && pinStepupPhotoOnly) {
        pinStepupAnnounce('Botao Tirar foto disponivel abaixo.');
        return;
      }
      if (on && wasHidden) {
        pinStepupAnnounce('Com dificuldade? Botão de captura manual disponível abaixo.');
      }
    }

    /**
     * Analisa uma detecção face-api e decide o estado do guia:
     *  - 'ok'      : rosto centralizado + tamanho adequado → conta frame estável
     *  - 'close'   : rosto detectado mas precisa ajustar (longe, de lado, borda)
     *  - 'missing' : sem rosto detectável
     */
    function pinAutoCapAnalyze(detection, video) {
      if (!detection || !detection.box) return { state: 'missing', hint: 'Procurando rosto…' };
      const box = detection.box;
      const vw = video.videoWidth || 640;
      const vh = video.videoHeight || 480;
      const faceW = box.width / vw;
      const faceH = box.height / vh;
      // Centro relativo do rosto (0..1)
      const cx = (box.x + box.width / 2) / vw;
      const cy = (box.y + box.height / 2) / vh;
      // Qualidade:
      //   - ocupação vertical entre 38% e 78% da câmera (não muito longe, não muito perto)
      //   - centro perto do meio (tolerância 0.18)
      const tooSmall = faceH < 0.32;
      const tooLarge = faceH > 0.82;
      const offCenterX = Math.abs(cx - 0.5) > 0.20;
      const offCenterY = Math.abs(cy - 0.5) > 0.22;

      if (tooSmall) return { state: 'close', hint: 'Aproxime um pouco' };
      if (tooLarge) return { state: 'close', hint: 'Afaste um pouco' };
      if (offCenterX || offCenterY) return { state: 'close', hint: 'Centralize o rosto' };
      return { state: 'ok', hint: 'Segure firme!' };
    }

    async function pinStartStepupCamera() {
      pinAutoCapCancelled = false;
      pinAutoCapStableFrames = 0;
      pinAutoCapStartedAt = Date.now();
      pinStepupSetStatus('Iniciando câmera…', null);
      pinStepupSetGuide(null);
      pinStepupShowSending(false);
      pinStepupShowManualFallback(false);

      try {
        pinStepupStream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
          audio: false
        });
        const v = _pinStepupEl('pinStepupVideo');
        v.srcObject = pinStepupStream;
        // Espelha o vídeo horizontalmente (selfie-view) — é o que os usuários esperam.
        v.style.transform = 'scaleX(-1)';
        await v.play();
        pinStepupSetStatus('Posicione seu rosto no círculo', null);
        // Inicia o loop de detecção e o timer de fallback.
        pinAutoCapStartedAt = Date.now();
        if (pinStepupPhotoOnly) {
          pinStepupSetStatus('Posicione seu rosto e toque em Tirar foto.', null);
          pinStepupShowManualFallback(true);
          pinAutoCapRunning = false;
          return;
        }

        pinAutoCapRunning = true;
        pinAutoCapLoop();
        if (pinAutoCapFallbackId) clearTimeout(pinAutoCapFallbackId);
        pinAutoCapFallbackId = setTimeout(() => {
          // Se após 12s ainda não capturou, oferece botão manual.
          if (pinAutoCapRunning && !pinAutoCapCancelled) {
            pinStepupShowManualFallback(true);
            pinStepupSetStatus('Com dificuldade? Tire manualmente', 'error');
          }
        }, PIN_AUTOCAP_FALLBACK_MS);
      } catch (e) {
        // Mensagens específicas por tipo de erro — preserva tratamento existente.
        let title = 'Câmera indisponível';
        let msg = 'Não foi possível acessar a câmera.';
        if (e && e.name === 'NotAllowedError') {
          title = 'Permissão negada';
          msg = 'Vá em Configurações → Navegador → Câmera e permita o acesso. Depois volte e tente novamente.';
        } else if (e && e.name === 'NotFoundError') {
          title = 'Câmera não detectada';
          msg = 'Nenhuma câmera foi encontrada neste aparelho.';
        } else if (e && e.name === 'NotReadableError') {
          title = 'Câmera em uso';
          msg = 'Outra aplicação está usando a câmera. Feche-a e tente novamente.';
        } else if (e && e.name === 'OverconstrainedError') {
          title = 'Câmera incompatível';
          msg = 'A câmera deste aparelho não atende aos requisitos.';
        }
        pinStepupSetStatus('Câmera indisponível', 'error');
        if (typeof toast === 'function') toast('error', title, msg, []);
        // H6: não deixa o usuário preso numa tela com câmera morta — cancela
        // o flow pendente e volta pra home após alguns segundos para ler o toast.
        pinStepupPendingFlow = null;
        pinStepupPhotoOnly = false;
        pinStopStepupCamera();
        setTimeout(() => { try { pinGoHome(); } catch (_) {} }, 2800);
      }
    }

    function pinStopStepupCamera() {
      pinAutoCapRunning = false;
      pinAutoCapCancelled = true;
      pinAutoCapStableFrames = 0;
      if (pinAutoCapFallbackId) { clearTimeout(pinAutoCapFallbackId); pinAutoCapFallbackId = null; }
      if (pinAutoCapCountdownId) { clearTimeout(pinAutoCapCountdownId); pinAutoCapCountdownId = null; }
      if (pinStepupStream) {
        try { pinStepupStream.getTracks().forEach(t => t.stop()); } catch (_) {}
        pinStepupStream = null;
      }
      pinStepupShowSending(false);
      pinStepupShowManualFallback(false);
    }

    // Loop de detecção: executa enquanto a seção estiver visível. Em caso de
    // falha temporária (ex: rosto saiu do quadro), reseta o contador de frames
    // estáveis. A captura final usa withFaceLandmarks + withFaceDescriptor,
    // mas o loop só roda detectSingleFace "cru" para economizar CPU.
    async function pinAutoCapLoop() {
      if (!pinAutoCapRunning || pinAutoCapCancelled) return;
      const v = _pinStepupEl('pinStepupVideo');
      if (!v || !window.faceapi || !faceApiReady || v.readyState < 2) {
        // Ainda carregando — tenta de novo em breve.
        setTimeout(pinAutoCapLoop, 300);
        return;
      }
      try {
        // M6: race com timeout — se o detector travar em hardware ruim ou
        // driver GPU congelado, não bloqueamos o loop indefinidamente.
        const detPromise = faceapi.detectSingleFace(
          v, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.55 })
        );
        const det = await Promise.race([
          detPromise,
          new Promise((_, rej) => setTimeout(() => rej(new Error('detect_timeout')), 1500))
        ]);
        const analysis = pinAutoCapAnalyze(det, v);
        if (analysis.state === 'missing') {
          pinAutoCapStableFrames = 0;
          pinStepupSetGuide(null);
          pinStepupSetStatus(analysis.hint, null);
        } else if (analysis.state === 'close') {
          pinAutoCapStableFrames = 0;
          pinStepupSetGuide('close');
          pinStepupSetStatus(analysis.hint, null);
        } else { // ok
          pinAutoCapStableFrames++;
          pinStepupSetGuide('ok');
          pinStepupSetStatus(analysis.hint, 'ok');
          if (pinAutoCapStableFrames >= PIN_AUTOCAP_FRAMES_REQUIRED) {
            pinAutoCapRunning = false;
            pinStartCountdownAndCapture();
            return;
          }
        }
      } catch (err) {
        // Falha transiente na detecção — continua o loop.
        console.warn('[stepup] detect loop error:', err);
      }
      if (pinAutoCapRunning) setTimeout(pinAutoCapLoop, PIN_AUTOCAP_LOOP_MS);
    }

    // Countdown 3→2→1 + flash + captura + submit. Se o rosto sair durante o
    // countdown, cancela e volta a monitorar (evita capturar foto ruim).
    function pinStartCountdownAndCapture() {
      if (pinAutoCapCancelled) return;
      let n = 3;
      const tick = async () => {
        if (pinAutoCapCancelled) return;
        // H1: se o video perdeu prontidão (app em background, câmera suspensa),
        // aborta o countdown — evita capturar frame preto/congelado.
        const v = _pinStepupEl('pinStepupVideo');
        if (!v || v.readyState < 2) {
          pinStepupSetGuide(null);
          pinStepupSetStatus('Reiniciando câmera…', null);
          pinAutoCapStableFrames = 0;
          if (pinStepupPhotoOnly) {
            setTimeout(pinStartCountdownAndCapture, 300);
          } else {
            pinAutoCapRunning = true;
            pinAutoCapLoop();
          }
          return;
        }
        if (pinStepupPhotoOnly) {
          // Foto obrigatoria da entrada nao depende do detector facial.
        } else {
        // Revalida presença de rosto a cada tick do countdown
        try {
          const det = await faceapi.detectSingleFace(
            v, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.55 })
          );
          const a = pinAutoCapAnalyze(det, v);
          if (a.state !== 'ok') {
            // Perdeu o enquadramento — aborta countdown e volta ao loop.
            pinStepupSetGuide(a.state === 'missing' ? null : 'close');
            pinStepupSetStatus(a.hint, null);
            pinAutoCapStableFrames = 0;
            pinAutoCapRunning = true;
            pinAutoCapLoop();
            return;
          }
        } catch (_) { /* tolera — segue o countdown */ }
        }

        if (n > 0) {
          pinStepupShowCountdown(n);
          n--;
          pinAutoCapCountdownId = setTimeout(tick, 650);
        } else {
          // FIRE!
          pinStepupFireFlash();
          pinStepupShowSending(true);
          pinStepupSetStatus('Processando foto…', 'ok');
          // Pequeno delay para o flash ser percebido
          setTimeout(() => pinAutoCapFinalize(), 180);
        }
      };
      tick();
    }

    // Captura o descriptor final (com landmarks + recognition) e dispara o
    // submit no flow pendente. Em caso de falha, volta ao loop.
    async function pinAutoCapFinalize() {
      if (pinAutoCapCancelled) return;
      const v = _pinStepupEl('pinStepupVideo');
      const auditPhoto = (typeof captureAuditPhotoFromVideo === 'function')
        ? captureAuditPhotoFromVideo(v)
        : null;

      if (pinStepupPhotoOnly) {
        if (!auditPhoto) {
          pinStepupShowSending(false);
          pinStepupSetStatus('Nao consegui tirar a foto. Tente novamente.', 'error');
          pinStepupShowManualFallback(true);
          return;
        }
        const flow = pinStepupPendingFlow;
        pinStepupPendingFlow = null;
        pinStepupPhotoOnly = false;
        pinStopStepupCamera();
        if (flow === 'session_photo' && typeof window.__pinSubmitSessionCheckin === 'function') {
          window.__pinSubmitSessionCheckin(null, auditPhoto);
          pinGoHome();
        } else {
          pinShowSection(pinSectionEl);
          pinCheckinSubmit(null, auditPhoto);
        }
        return;
      }

      let descriptor = null;
      try {
        const det = await faceapi
          .detectSingleFace(v, new faceapi.TinyFaceDetectorOptions({ inputSize: 320 }))
          .withFaceLandmarks(true)
          .withFaceDescriptor();
        descriptor = det && det.descriptor ? Array.from(det.descriptor) : null;
      } catch (e) {
        console.warn('[stepup] finalize error:', e);
      }

      // C2: segundo guard após o await — se o usuário cancelou durante a
      // captura, nenhum submit é disparado (o stream já foi parado).
      if (pinAutoCapCancelled) return;

      if (!descriptor || descriptor.length !== 128) {
        // Falha na captura — volta ao loop e avisa o usuário
        pinStepupShowSending(false);
        pinStepupSetStatus('Não consegui detectar. Tente de novo.', 'error');
        pinAutoCapStableFrames = 0;
        pinAutoCapRunning = true;
        pinAutoCapLoop();
        return;
      }

      // Sucesso — para a câmera e dispara o fluxo pendente
      const flow = pinStepupPendingFlow; pinStepupPendingFlow = null;
      pinStepupPhotoOnly = false;
      pinStopStepupCamera();
      if (flow === 'enroll')       { pinShowSection(pinEnrollSectionEl);  pinEnrollSubmit(descriptor); }
      else if (flow === 'recover') { pinShowSection(pinRecoverSectionEl); pinRecoverSubmit(descriptor); }
      else if (flow === 'session' && typeof window.__pinSubmitSessionCheckin === 'function') {
        // M5: dispara o submit ANTES de voltar para home — evita piscar home
        // por ~200ms enquanto o submit inicia. submitSessionCheckin cuida do
        // estado visual do botão enquanto a request está em voo.
        window.__pinSubmitSessionCheckin(descriptor, auditPhoto);
        pinGoHome();
      }
      else { pinShowSection(pinSectionEl); pinCheckinSubmit(descriptor, auditPhoto); }
    }

    // Compatibilidade: função chamada por outros fluxos que ainda podem tentar
    // capturar descriptor ponto-a-ponto fora do loop.
    async function pinCaptureDescriptor() {
      if (!window.faceapi || !faceApiReady) return null;
      const v = _pinStepupEl('pinStepupVideo');
      // M2: se o video foi removido do DOM durante lifecycle, sai cedo.
      if (!v || v.readyState < 2) return null;
      try {
        const det = await faceapi
          .detectSingleFace(v, new faceapi.TinyFaceDetectorOptions({ inputSize: 320 }))
          .withFaceLandmarks(true)
          .withFaceDescriptor();
        return det && det.descriptor ? Array.from(det.descriptor) : null;
      } catch (e) {
        console.warn('[stepup] pinCaptureDescriptor:', e);
        return null;
      }
    }

    function pinBeginStepup(message, pendingFlow, reason) {
      // H3: reset defensivo de estado — cobre re-entrada (session expirou,
      // usuário voltou, novo step-up). pinStartStepupCamera fará o reset
      // principal, mas garantimos aqui que o loop antigo termina primeiro.
      pinAutoCapRunning = false;
      pinAutoCapCancelled = true; // pausa loop anterior em andamento
      if (pinAutoCapCountdownId) { clearTimeout(pinAutoCapCountdownId); pinAutoCapCountdownId = null; }
      if (pinAutoCapFallbackId)  { clearTimeout(pinAutoCapFallbackId);  pinAutoCapFallbackId = null; }

      pinStepupPendingFlow = pendingFlow;
      pinStepupPhotoOnly = (reason === 'entry_photo' || pendingFlow === 'main_photo' || pendingFlow === 'session_photo');
      const msgEl = _pinStepupEl('pinStepupMsg');
      // H7: mensagem única em step-up — backend pode passar hint extra, mas a
      // instrução principal não varia entre flows. Se `message` vier, anexamos
      // como segunda linha em vez de substituir.
      const baseMsg = pinStepupPhotoOnly
        ? 'Posicione seu rosto e toque em Tirar foto quando estiver pronto.'
        : 'Enquadre seu rosto no círculo. Tiramos a foto automaticamente.';
      if (msgEl) {
        if (message && message !== baseMsg) {
          msgEl.innerHTML = baseMsg + '<br><small style="opacity:.8">' + message.replace(/</g, '&lt;') + '</small>';
        } else {
          msgEl.textContent = baseMsg;
        }
      }
      const titleEl = document.querySelector('#pinStepupSection .pin-h1');
      if (titleEl) {
        titleEl.innerHTML = pinStepupPhotoOnly
          ? '<i class="bi bi-camera"></i> Foto da entrada'
          : reason === 'face_enrollment'
          ? '<i class="bi bi-person-badge"></i> Cadastro facial'
          : '<i class="bi bi-shield-lock"></i> Validação extra';
      }
      pinShowSection(pinStepupSectionEl);
      // Dispara a câmera automaticamente ao entrar na seção — sem cliques.
      setTimeout(() => pinStartStepupCamera().catch(() => {}), 80);
    }

    const pinStepupCancelBtn  = _pinStepupEl('btnPinStepupCancel');
    const pinStepupManualBtn  = _pinStepupEl('btnPinStepupManual');
    if (pinStepupCancelBtn) pinStepupCancelBtn.addEventListener('click', () => {
      pinStepupPendingFlow = null;
      pinStepupPhotoOnly = false;
      pinStopStepupCamera();
      pinGoHome();
    });
    if (pinStepupManualBtn) pinStepupManualBtn.addEventListener('click', async () => {
      // Atalho manual — força uma captura mesmo fora dos critérios do loop.
      // H2: try/finally garante reset de UI mesmo se finalize throw inesperado.
      pinStepupManualBtn.disabled = true;
      pinStepupShowSending(true);
      try {
        await pinAutoCapFinalize();
      } finally {
        pinStepupManualBtn.disabled = false;
        // Se finalize falhou e voltou ao loop, ele já chamou showSending(false).
        // Se a captura foi OK, a câmera foi parada. Em qualquer caso, desliga o overlay.
        pinStepupShowSending(false);
      }
    });

    // ============================================================
    // CHECK-IN VIA PIN
    // ============================================================
    async function pinCheckinSubmit(faceDescriptor, auditPhoto) {
      if (!pinSubmitBtn || pinSubmitBtn.disabled) return;
      const cpf = pinCleanCpf(pinCpfEl.value);
      const pin = pinCollect();
      const expectedAction = pinExpectedActionForCpf(cpf);
      if (expectedAction !== 'saida' && !auditPhoto) {
        pinBeginStepup('A entrada precisa de foto para auditoria.', 'main_photo', 'entry_photo');
        return;
      }
      pinSubmitBtn.disabled = true;
      pinSubmitBtn.classList.add('is-loading');
      const common = await pinBuildCommonPayload();
      const geo = await pinGetGeo();
      let fraudCheck = null;
      try { if (typeof detectGpsMock === 'function' && geo) fraudCheck = detectGpsMock(geo); } catch (_) {}
      const payload = Object.assign({}, common, {
        cpf, pin, geo, fraudCheck,
        recordMode: navigator.onLine ? 'online' : 'offline',
      });
      if (expectedAction) payload.expected_action = expectedAction;
      if (auditPhoto) payload.photo = auditPhoto;
      if (faceDescriptor) {
        payload.face_descriptor = faceDescriptor;
        if (pinStepupNonce) { payload.stepup_nonce = pinStepupNonce; pinStepupNonce = null; }
      }
      const { data } = await pinApiPost(PIN_API.checkin, payload);
      pinSubmitBtn.classList.remove('is-loading'); pinValidate();

      if (data.status === 'require_face') {
        pinStepupNonce = data.stepup_nonce || null;
        pinBeginStepup(data.message || 'Vamos confirmar com sua foto.', 'main', data.reason);
        return;
      }
      if (data.status === 'ok') {
        const msg = data.message || ('Ponto registrado! NSR #' + (data.nsr || ''));
        if (typeof toast === 'function') toast('success', 'Ponto registrado!', msg, []);
        pinResetDigits();
        setTimeout(() => pinGoHome(), 2500);
        return;
      }
      pinHandleError(data, 'main');
    }
    if (pinSubmitBtn) pinSubmitBtn.addEventListener('click', () => pinCheckinSubmit());

    // ============================================================
    // PRIMEIRO ACESSO
    // ============================================================
    // Estado intermediário do enrollment — face capturada antes do PIN.
    // Mantemos em memória para que a chamada final POST inclua TUDO de uma vez
    // (CPF + face_descriptor + desired_pin), sem depender de sessão entre etapas.
    let _enrollPendingFace = null;
    let _enrollPendingCpf  = '';

    // H1: limpa estado intermediário de enrollment para evitar reuso stale
    // entre fluxos (cancelar → reentrar com OUTRO CPF, ou bfcache restore).
    function pinEnrollClearState() {
      _enrollPendingFace = null;
      _enrollPendingCpf  = '';
    }

    async function pinEnrollSubmit(faceDescriptor, opts) {
      // Fluxo de Primeiro Acesso (em etapas, todas REST + idempotentes):
      //   1) usuário digita CPF → enviamos sem face/pin
      //      → backend pode pedir face ou pedir PIN
      //   2) frontend abre câmera, captura face → reentra aqui com descriptor
      //      → backend salva face e PEDE PIN
      //   3) tela de escolha de PIN → reentra aqui com desired_pin (ou flag
      //      generate_pin para fallback aleatório) → backend retorna ok
      opts = opts || {};
      if (!pinEnrollSubmitBtn || pinEnrollSubmitBtn.disabled) return;
      const cpf = pinCleanCpf(pinEnrollCpfEl.value || _enrollPendingCpf);
      _enrollPendingCpf = cpf;
      if (faceDescriptor) _enrollPendingFace = faceDescriptor;

      pinEnrollSubmitBtn.disabled = true;
      pinEnrollSubmitBtn.classList.add('is-loading');
      const common = await pinBuildCommonPayload();
      const payload = Object.assign({}, common, { cpf });
      if (_enrollPendingFace) payload.face_descriptor = _enrollPendingFace;
      if (opts.desiredPin)    payload.desired_pin    = opts.desiredPin;
      if (opts.generate)      payload.generate_pin   = true;

      const { data } = await pinApiPost(PIN_API.enroll, payload);
      pinEnrollSubmitBtn.classList.remove('is-loading'); pinValidate();

      if (data.status === 'ok') {
        // Limpa estado intermediário ANTES de mostrar resultado
        _enrollPendingFace = null; _enrollPendingCpf = '';
        const headline = data.user_chosen ? 'PIN cadastrado!' : 'Seu PIN foi gerado!';
        pinShowResult(data.pin, headline, data.auto_login, { userChosen: !!data.user_chosen });
        return;
      }
      if (data.status === 'require_face') {
        pinBeginStepup(data.message || 'Vamos cadastrar sua foto antes de criar o PIN.', 'enroll', data.reason);
        return;
      }
      if (data.status === 'require_pin') {
        // Abre tela de escolha do PIN (reaproveita o estado armazenado).
        pinChooseOpen();
        return;
      }
      pinHandleError(data, 'enroll');
    }
    if (pinEnrollSubmitBtn) pinEnrollSubmitBtn.addEventListener('click', () => pinEnrollSubmit());

    // ============================================================
    // ESCOLHA DE PIN (Primeiro acesso — usuário define o próprio PIN)
    // ============================================================
    const pinChooseDigitsEls = pinChooseSectionEl
      ? Array.from(pinChooseSectionEl.querySelectorAll('#pinChooseDigits input'))
      : [];
    const pinChooseHintEl    = document.getElementById('pinChooseHint');
    const pinChooseNextBtn   = document.getElementById('btnPinChooseNext');
    const pinChooseGenBtn    = document.getElementById('btnPinChooseGenerate');

    function pinChooseRead() { return pinChooseDigitsEls.map(i => (i.value || '').replace(/\D/g, '')).join(''); }
    function pinChooseClear() { pinChooseDigitsEls.forEach(i => { i.value = ''; }); pinChooseSetHint('', 'info'); pinChooseNextBtn.disabled = true; }
    function pinChooseSetHint(text, kind) {
      if (!pinChooseHintEl) return;
      pinChooseHintEl.classList.remove('is-ok', 'is-weak', 'is-info');
      pinChooseHintEl.classList.add('is-' + (kind || 'info'));
      pinChooseHintEl.textContent = text || '';
    }

    // Validação client-side espelhando pin_validate_strength (rápida, evita
    // round-trip ao backend para feedback imediato; backend ainda valida).
    function pinChooseClientCheck(pin, cpf) {
      if (pin.length !== 6) return { ok: false, msg: '' }; // ainda incompleto
      if (!/^\d{6}$/.test(pin)) return { ok: false, msg: 'Use apenas números.' };
      if (new Set(pin).size === 1) return { ok: false, msg: 'Evite 6 dígitos iguais.' };
      let asc = true, desc = true;
      for (let k = 1; k < pin.length; k++) {
        if (+pin[k] - +pin[k-1] !== 1) asc = false;
        if (+pin[k-1] - +pin[k] !== 1) desc = false;
      }
      if (asc || desc) return { ok: false, msg: 'Evite sequências (1-2-3-4-5-6).' };
      const trivial = ['121212','123123','111222','112233','010101','101010','212121','202020'];
      if (trivial.indexOf(pin) >= 0) return { ok: false, msg: 'Tente uma combinação menos previsível.' };
      // pares repetidos
      let pairs = true;
      for (let k = 0; k < 6; k += 2) { if (pin[k] !== pin[k+1]) { pairs = false; break; } }
      if (pairs) return { ok: false, msg: 'Evite pares repetidos.' };
      const cpfDigits = (cpf || '').replace(/\D/g, '');
      if (cpfDigits.length === 11 && (cpfDigits.slice(0,6) === pin || cpfDigits.slice(-6) === pin)) {
        return { ok: false, msg: 'Não use parte do seu CPF.' };
      }
      return { ok: true, msg: 'PIN válido — toque em CONTINUAR.' };
    }

    function pinChooseRevalidate() {
      const pin = pinChooseRead();
      if (pin.length === 0) { pinChooseSetHint('', 'info'); pinChooseNextBtn.disabled = true; return; }
      if (pin.length < 6)   { pinChooseSetHint(`Faltam ${6 - pin.length} dígito(s)…`, 'info'); pinChooseNextBtn.disabled = true; return; }
      const r = pinChooseClientCheck(pin, _enrollPendingCpf);
      pinChooseSetHint(r.msg, r.ok ? 'ok' : 'weak');
      pinChooseNextBtn.disabled = !r.ok;
    }

    // Wires de teclado: digitação avança, backspace volta — mesmo padrão dos
    // outros pin-grid do app (mantém consistência e evita fadiga).
    pinChooseDigitsEls.forEach((input, idx) => {
      input.addEventListener('input', () => {
        input.value = (input.value || '').replace(/\D/g, '').slice(0, 1);
        if (input.value && idx < pinChooseDigitsEls.length - 1) pinChooseDigitsEls[idx + 1].focus();
        pinChooseRevalidate();
      });
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !input.value && idx > 0) pinChooseDigitsEls[idx - 1].focus();
        if (e.key === 'Enter' && !pinChooseNextBtn.disabled) pinChooseNextBtn.click();
      });
      input.addEventListener('paste', (e) => {
        e.preventDefault();
        const txt = (e.clipboardData || window.clipboardData).getData('text') || '';
        const digits = txt.replace(/\D/g, '').slice(0, 6);
        if (!digits) return;
        // M4: avisa quando o paste tem menos de 6 dígitos completos
        if (digits.length < 6 && typeof toast === 'function') {
          toast('warning', 'PIN incompleto', 'Cole um PIN com 6 dígitos completos.', []);
        }
        digits.split('').forEach((d, k) => { if (pinChooseDigitsEls[k]) pinChooseDigitsEls[k].value = d; });
        if (pinChooseDigitsEls[digits.length]) pinChooseDigitsEls[digits.length].focus();
        else pinChooseDigitsEls[5].focus();
        pinChooseRevalidate();
      });
    });

    function pinChooseOpen() {
      pinChooseClear();
      pinShowSection(pinChooseSectionEl);
      setTimeout(() => pinChooseDigitsEls[0] && pinChooseDigitsEls[0].focus(), 80);
    }

    if (pinChooseNextBtn) pinChooseNextBtn.addEventListener('click', () => {
      if (pinChooseNextBtn.disabled) return;
      const pin = pinChooseRead();
      pinEnrollSubmit(undefined, { desiredPin: pin });
    });
    if (pinChooseGenBtn) pinChooseGenBtn.addEventListener('click', () => {
      pinEnrollSubmit(undefined, { generate: true });
    });

    // ============================================================
    // RECUPERAR PIN
    // ============================================================
    async function pinRecoverSubmit(faceDescriptor) {
      if (!pinRecoverSubmitBtn || pinRecoverSubmitBtn.disabled) return;
      const cpf = pinCleanCpf(pinRecoverCpfEl.value);
      pinRecoverSubmitBtn.disabled = true;
      pinRecoverSubmitBtn.classList.add('is-loading');
      const common = await pinBuildCommonPayload();
      const geo = await pinGetGeo();
      const payload = Object.assign({}, common, { cpf, geo });
      if (faceDescriptor) payload.face_descriptor = faceDescriptor;
      const { data } = await pinApiPost(PIN_API.recover, payload);
      pinRecoverSubmitBtn.classList.remove('is-loading'); pinValidate();

      if (data.status === 'require_face') {
        pinBeginStepup(data.message || 'Vamos confirmar com sua foto para gerar novo PIN.', 'recover', data.reason);
        return;
      }
      if (data.status === 'ok') { pinShowResult(data.pin, 'Seu novo PIN', data.auto_login); return; }
      pinHandleError(data, 'recover');
    }
    if (pinRecoverSubmitBtn) pinRecoverSubmitBtn.addEventListener('click', () => pinRecoverSubmit());

    // ============================================================
    // Tratamento de erros unificado (enter-flow para toast padrão)
    // ============================================================
    function pinHandleError(data, flow) {
      const code = data && data.code || '';
      const msg = data && data.message || 'Tente novamente.';
      const toastFn = typeof toast === 'function' ? toast : (t, a, b) => alert(b || a);
      if (code === 'pin_invalid') {
        toastFn('error', 'PIN incorreto', 'Verifique os números.', []);
        pinResetDigits(); if (pinDigitsEls[0]) pinDigitsEls[0].focus();
        return;
      }
      if (code === 'pin_not_set') {
        toastFn('warning', 'Você ainda não tem PIN', 'Vamos te levar para o primeiro acesso.', []);
        setTimeout(() => pinEntryEnroll(), 800);
        return;
      }
      if (code === 'pin_already_set') {
        toastFn('warning', 'Você já tem PIN', 'Use "Esqueci meu PIN" para gerar um novo.', []);
        return;
      }
      // PIN escolhido fraco — backend rejeitou. Volta para a tela de escolha
      // com hint da razão. Cliente já valida boa parte, mas o backend tem a
      // palavra final (pode ter regras adicionais futuras).
      if (code === 'pin_too_weak' || code === 'pin_format_invalid' || code === 'pin_length_invalid') {
        toastFn('warning', 'PIN fraco', msg || 'Escolha um PIN mais difícil de adivinhar.', []);
        if (typeof pinChooseOpen === 'function') {
          pinChooseOpen();
          if (typeof pinChooseSetHint === 'function') pinChooseSetHint(msg, 'weak');
        }
        return;
      }
      if (code === 'self_enroll_not_allowed' || code === 'blocked_contact_admin') {
        toastFn('error', 'Precisa de ajuda', msg, []);
        return;
      }
      if (code === 'stepup_face_not_matched' || code === 'face_not_matched') {
        toastFn('error', 'Foto não reconhecida', 'Tente novamente em boa iluminação.', []);
        pinBeginStepup('Vamos tentar de novo — centralize seu rosto no quadro.', flow);
        return;
      }
      // Nonce expirou — usuário ficou muito tempo na tela de step-up. Volta pra home
      // e instrui a começar de novo.
      if (code === 'stepup_nonce_invalid') {
        pinStepupNonce = null;
        toastFn('warning', 'Validação expirou',
          'A janela da validação facial expirou. Toque em BATER PONTO para começar de novo.', []);
        setTimeout(() => pinGoHome(), 2500);
        return;
      }
      if (code === 'too_many_attempts') {
        toastFn('warning', 'Muitas tentativas', msg, []);
        return;
      }
      if (code === 'already_checked_in' || code === 'no_open_checkin') {
        toastFn('warning', 'Atenção', msg, []);
        setTimeout(() => pinGoHome(), 2500);
        return;
      }
      if (code === 'cpf_invalid' || code === 'cpf_not_found' || code === 'teacher_not_found' || code === 'cpf_required') {
        toastFn('error', 'CPF inválido', msg, []);
        return;
      }
      toastFn('error', 'Erro', msg, []);
    }

    // ============================================================
    // EXIBIR PIN GERADO (1 vez)
    // ============================================================
    // Quando auto_login=true retorna do backend, armazenamos para recarregar
    // a página ao confirmar — assim a home ganha sessão, greet, drawer, etc.
    let pinShowAutoLogged = false;
    function pinShowResult(pin, title, autoLogged, opts) {
      opts = opts || {};
      window.__pinLastPlain = pin;
      pinShowAutoLogged = !!autoLogged;
      const t = document.getElementById('pinShowTitle');
      const subEl = document.querySelector('#pinShowSection .pin-sub');
      const alertEl = document.querySelector('#pinShowSection .pin-alert');
      if (t) t.textContent = title || 'Seu PIN';
      const v = document.getElementById('pinShowValue'); if (v) v.textContent = (pin || '').split('').join(' ');
      // Quando o PIN foi escolhido pelo usuário, o aviso "anote agora" é
      // desnecessário (ele já sabe). Suaviza a copy nesses casos.
      if (subEl) {
        subEl.textContent = opts.userChosen
          ? 'Tudo certo. Use este PIN nos próximos acessos.'
          : 'Anote com cuidado — ele não será exibido novamente.';
      }
      if (alertEl) alertEl.style.display = opts.userChosen ? 'none' : '';
      pinShowSection(pinShowSectionEl);
    }
    const pinCopyBtn = document.getElementById('btnPinCopy');
    if (pinCopyBtn) pinCopyBtn.addEventListener('click', async () => {
      const pin = window.__pinLastPlain; if (!pin) return;
      try {
        await navigator.clipboard.writeText(pin);
        if (typeof toast === 'function') toast('success', 'Copiado', 'PIN copiado para a área de transferência.', []);
      } catch (_) {
        if (typeof toast === 'function') toast('warning', 'Copie manualmente', 'Não foi possível copiar. Anote o PIN.', []);
      }
    });
    // Salvar no WhatsApp: tenta na ordem
    //   1) navigator.share (Web Share API — abre seletor nativo no mobile)
    //   2) window.open de wa.me (desktop / browsers sem WebShare)
    //   3) Clipboard fallback — se popup bloqueado, copia o PIN e instrui
    //      o usuário a colar manualmente no WhatsApp. NUNCA navega a página
    //      atual para fora (perderia o PIN exibido).
    const pinWhatsappBtn = document.getElementById('btnPinWhatsapp');
    if (pinWhatsappBtn) pinWhatsappBtn.addEventListener('click', async () => {
      const pin = window.__pinLastPlain; if (!pin) return;
      const msg = `Meu PIN do DEEDO Ponto: ${pin}\n\n(Não compartilhe este PIN com ninguém.)`;

      // 1) Web Share API — preferida em mobile (iOS 13+, Android Chrome 75+)
      if (typeof navigator.share === 'function') {
        try {
          await navigator.share({ title: 'PIN DEEDO Ponto', text: msg });
          return;
        } catch (e) {
          // AbortError = usuário cancelou; outros erros caem no fallback abaixo
          if (e && e.name === 'AbortError') return;
        }
      }

      // 2) wa.me em nova aba
      const url = `https://wa.me/?text=${encodeURIComponent(msg)}`;
      const win = window.open(url, '_blank', 'noopener');
      if (win) return;

      // 3) Clipboard fallback (popup bloqueado): copia o PIN e instrui
      try {
        await navigator.clipboard.writeText(pin);
        if (typeof toast === 'function') {
          toast('success', 'PIN copiado',
            'Abra o WhatsApp manualmente e cole o PIN onde quiser salvar.', []);
        }
      } catch (_) {
        if (typeof toast === 'function') {
          toast('warning', 'Anote manualmente',
            'Não foi possível abrir o WhatsApp nem copiar. Anote o PIN antes de fechar.', []);
        }
      }
    });
    const pinShowConfirmBtn = document.getElementById('btnPinShowConfirm');
    if (pinShowConfirmBtn) pinShowConfirmBtn.addEventListener('click', () => {
      window.__pinLastPlain = null;
      if (pinShowAutoLogged && !SESSION_MODE) {
        // Backend criou sessão via collaborator_login_establish. Recarrega
        // sem query params para a home renderizar com sessão ativa.
        window.location.href = 'index.php';
        return;
      }
      pinGoHome();
    });

    // ============================================================
    // MELHORIAS DE UX — v2.6
    // ============================================================
    const PIN_LS = {
      CPF: 'ponto_last_cpf',
      WELCOME: 'ponto_seen_welcome',
      NO_LS: 'ponto_no_localstorage',
    };
    const pinKioskMode = new URLSearchParams(location.search).get('kiosk') === '1'
                       || window.PONTO_NO_LOCALSTORAGE === true
                       || localStorage.getItem(PIN_LS.NO_LS) === '1';
    function pinLsGetCpf() {
      if (pinKioskMode) return '';
      try { return localStorage.getItem(PIN_LS.CPF) || ''; } catch (_) { return ''; }
    }
    function pinLsSetCpf(cpf) {
      if (pinKioskMode) return;
      try { localStorage.setItem(PIN_LS.CPF, cpf || ''); } catch (_) {}
    }
    function pinLsClearCpf() {
      try { localStorage.removeItem(PIN_LS.CPF); } catch (_) {}
    }

    // ---------- Card "Seu último ponto" ----------
    const pinLastCardEl  = document.getElementById('pinLastCard');
    const pinLastGreetEl = document.getElementById('pinLastGreet');
    const pinLastStatusEl= document.getElementById('pinLastStatus');
    // Cache do payload de last_checkin para o modal de confirmação consultar
    // a próxima ação (entrada/saída) sem refetchar.
    let pinLastDataCache = null;
    // Hora de volta editada no modal de confirmação do RETORNO do intervalo (HH:MM).
    // null = usar "agora". Enviada como corrected_return_time só p/ retornar_intervalo.
    let pinCorrectedReturnTime = null;

    // Flag de intenção: setada ao clicar em "Iniciar intervalo" (btnGoBreak), consumida
    // por pinExpectedActionForCpf para sinalizar 'iniciar_intervalo' ao servidor. Resetada
    // após cada POST de check-in.
    let _breakActionRequested = false;

    // Decide a próxima ação esperada com base no estado do colaborador.
    // Estados (de /api/last_checkin.php):
    //   - 'fora'         → próxima ação = 'entrada'
    //   - 'trabalhando'  → próxima ação = 'saida' (e botão secundário oferece 'iniciar_intervalo')
    //   - 'em_intervalo' → próxima ação = 'retornar_intervalo' (sem botão secundário)
    // Fallback para compatibilidade: usa data.last quando state não está disponível.
    function pinComputeNextAction(last, state) {
      if (state === 'em_intervalo') return 'retornar_intervalo';
      if (state === 'trabalhando') return 'saida';
      if (state === 'fora') return 'entrada';
      // Fallback (versão sem campo state)
      if (last && last.is_today && last.action === 'entrada') return 'saida';
      return 'entrada';
    }

    function pinExpectedActionForCpf(cpf) {
      const sourceCpf = SESSION_COLLAB ? SESSION_COLLAB.cpf : pinLsGetCpf();
      if (cpf && sourceCpf && cpf !== sourceCpf) return null;
      if (!pinLastDataCache || !pinLastDataCache.teacher) return null;
      // Se o usuário clicou em "Iniciar intervalo" e o estado atual é TRABALHANDO,
      // o expected_action vira 'iniciar_intervalo'. Em outros estados, ignoramos a flag
      // (o servidor rejeitaria com action_mismatch).
      if (_breakActionRequested && pinLastDataCache.state === 'trabalhando') {
        return 'iniciar_intervalo';
      }
      return pinComputeNextAction(pinLastDataCache.last, pinLastDataCache.state);
    }

    function expectedActionFromPreview() {
      if (previewData?.action_key === 'out') return 'saida';
      if (previewData?.action_key === 'in') return 'entrada';
      if (previewData?.action_key === 'break_start') return 'iniciar_intervalo';
      if (previewData?.action_key === 'break_end') return 'retornar_intervalo';
      return null;
    }

    // Atualiza o texto/visibilidade dos botões com intenção adaptativa baseada no estado.
    //   FORA          → principal "Bater ponto de entrada", secundário oculto
    //   TRABALHANDO   → principal "Bater ponto de saída", secundário "Iniciar intervalo"
    //   EM_INTERVALO  → principal "Retornar do intervalo", secundário oculto
    function pinUpdateCheckinIntent(last, state) {
      const next = pinComputeNextAction(last, state);
      const textEl = document.getElementById('btnCheckinText');
      const iconEl = document.getElementById('btnCheckinIcon');
      const mainBtn = document.getElementById('btnGoCheckin');
      const breakBtn = document.getElementById('btnGoBreak');
      const breakTextEl = document.getElementById('btnBreakText');

      // Texto do botão principal
      switch (next) {
        case 'saida':
          _checkinIntentText = 'Bater ponto de saída';
          if (iconEl) iconEl.className = 'bi bi-box-arrow-right';
          break;
        case 'retornar_intervalo':
          _checkinIntentText = 'Retornar do intervalo';
          if (iconEl) iconEl.className = 'bi bi-play-circle-fill';
          break;
        default:
          _checkinIntentText = 'Bater ponto de entrada';
          if (iconEl) iconEl.className = 'bi bi-check-circle-fill';
      }
      if (textEl) textEl.textContent = _checkinIntentText;

      // Cor do botão principal sinaliza estado em intervalo
      if (mainBtn) {
        mainBtn.classList.toggle('btn-checkin--resume', next === 'retornar_intervalo');
      }

      // Botão secundário de intervalo aparece apenas em TRABALHANDO.
      // Usa classList.toggle('is-hidden') que tem display:none !important — resiste a
      // qualquer estilo inline conflitante e cobre o caso "estado anterior cached".
      if (breakBtn) {
        const showBreak = (state === 'trabalhando');
        breakBtn.classList.toggle('is-hidden', !showBreak);
        if (showBreak && breakTextEl) breakTextEl.textContent = 'Iniciar intervalo';
      }
    }

    async function pinLoadLastCheckin() {
      // Reset da flag de intervalo: a próxima ação volta ao default baseado no estado real.
      _breakActionRequested = false;

      // Defensiva: esconde o botão de intervalo imediatamente via classe (resiste a
      // qualquer inline-style residual). pinUpdateCheckinIntent re-exibe se for o caso.
      const _breakBtnInit = document.getElementById('btnGoBreak');
      if (_breakBtnInit) _breakBtnInit.classList.add('is-hidden');

      // Prioridade 1: sessão do colaborador logado; fallback: localStorage (modo kiosk).
      const savedCpf = SESSION_COLLAB ? SESSION_COLLAB.cpf : pinLsGetCpf();
      if (!savedCpf || savedCpf.length !== 11) {
        pinLastDataCache = null;
        if (pinLastCardEl) pinLastCardEl.style.display = 'none';
        pinUpdateCheckinIntent(null, null);
        return;
      }

      // Timeout defensivo: se a API demorar >3s, mostra estado "limbo" ao invés
      // de deixar o card sumir silenciosamente.
      const controller = new AbortController();
      const tid = setTimeout(() => controller.abort(), 3000);
      try {
        const res = await fetch(
          (PIN_ROOT_BASE || '') + '/api/last_checkin.php?cpf=' + encodeURIComponent(savedCpf),
          { signal: controller.signal }
        );
        clearTimeout(tid);
        const data = await res.json();
        if (!data || !data.teacher) {
          pinLastDataCache = null;
          if (pinLastCardEl) pinLastCardEl.style.display = 'none';
          pinUpdateCheckinIntent(null, null);
          return;
        }
        const firstName = (data.teacher.name || '').split(/\s+/)[0];
        if (pinLastGreetEl) pinLastGreetEl.textContent = `Olá, ${firstName} 👋`;

        // Usa actionLabel global (declarado em torno da linha 8253).
        let status;
        if (data.last && data.last.is_today) {
          const labelAction = actionLabel(data.last.action);

          // ===== Estado EM INTERVALO: bloco explicativo destacado =====
          // 4 mensagens claras + timer em tempo real (HH:MM:SS) atualizando 1×/s
          // via pinTickBreakTimer (criado abaixo na função do timer).
          if (data.state === 'em_intervalo') {
            status =
              '<strong>⏸ Você está em intervalo. O tempo trabalhado está pausado.</strong>' +
              `<br>Intervalo iniciado às <strong>${data.last.time}</strong> · Tempo em intervalo: <span id="breakTickValue" style="font-variant-numeric:tabular-nums;font-weight:700;color:#4b5563;">00:00:00</span>` +
              '<br><small style="color:#6b7280;">Este registro não conta como ponto batido, apenas como intervalo.</small>' +
              '<br><small style="color:#6b7280;">Ao finalizar o intervalo, sua contagem de trabalho será retomada.</small>';
            if (data.last.approved === false) {
              pinLastStatusEl && pinLastStatusEl.classList.add('pin-last-pending');
              status += ' <em>(aguardando revisão)</em>';
            } else {
              pinLastStatusEl && pinLastStatusEl.classList.remove('pin-last-pending');
            }
            if (data.break_overage_min && data.break_overage_min > 0) {
              status += `<br><em style="color:#dc2626;">⚠ Intervalo excedido em ${data.break_overage_min} min.</em>`;
            }
          } else {
            // ===== Demais estados: linha única padrão =====
            let nextText;
            switch (data.state) {
              case 'trabalhando':
                nextText = ' · Próximo: registre sua <strong>saída</strong> ou inicie o intervalo.';
                break;
              default:
                nextText = ' · Expediente concluído por hoje.';
            }
            // Usa "Última batida: X às HH:MM" — evita o problema de concordância de gênero
            // ("Retorno do intervalo registrada" soaria errado; o verbo é eliminado da frase).
            status = `Última batida: <strong>${labelAction}</strong> às <strong>${data.last.time}</strong>${nextText}`;

            // Nota sobre intervalo automático da rotina: quando o colaborador tem break
            // previsto na jornada (ex: 120min) mas não registrou nenhum break manual hoje,
            // o sistema descontará automaticamente do trabalhado para fins de saldo.
            if (data.state === 'trabalhando' &&
                Number(data.expected_break_min || 0) > 0 &&
                Number(data.today_break_minutes || 0) === 0) {
              const expMin = Number(data.expected_break_min);
              const expFmt = expMin >= 60
                ? Math.floor(expMin/60) + 'h' + (expMin%60 > 0 ? String(expMin%60).padStart(2,'0') : '')
                : expMin + 'min';
              status += `<br><small style="color:#6b7280;"><i class="bi bi-info-circle me-1"></i>Sua jornada prevê <strong>${expFmt}</strong> de intervalo. Se você não registrar manualmente, esse tempo é descontado automaticamente do total trabalhado.</small>`;
            }
            if (data.last.approved === false) {
              pinLastStatusEl && pinLastStatusEl.classList.add('pin-last-pending');
              status += ' <em>(aguardando revisão)</em>';
            } else {
              pinLastStatusEl && pinLastStatusEl.classList.remove('pin-last-pending');
            }
            if (data.break_overage_min && data.break_overage_min > 0) {
              status += ` <em style="color:#d97706">(intervalo excedido em ${data.break_overage_min} min)</em>`;
            }
          }
        } else if (data.last) {
          const labelAction = actionLabel(data.last.action);
          const when = data.last.date.split('-').reverse().join('/');
          status = `Última batida: ${labelAction} em ${when} às ${data.last.time}.`;
        } else {
          status = 'Nenhum ponto hoje ainda. Toque em <strong>Bater Ponto</strong> para registrar.';
        }

        if (pinLastStatusEl) pinLastStatusEl.innerHTML = status;
        if (pinLastCardEl) pinLastCardEl.style.display = 'flex';

        // Cache para o modal de confirmação consultar a próxima ação esperada
        // sem precisar refetchar last_checkin. Atualiza junto com cada poll.
        pinLastDataCache = data;

        pinUpdateCheckinIntent(data.last, data.state);
        pinUpdateWorkTimer(data);
      } catch (err) {
        clearTimeout(tid);
        // Rede falhou ou timeout: não quebra, só deixa hint em modo neutro.
        if (pinLastCardEl) pinLastCardEl.style.display = 'none';
        pinUpdateCheckinIntent(null, null);
        pinUpdateWorkTimer(null);
      }
    }

    // ---------- Contador de tempo trabalhando ----------
    const workTimerCardEl  = document.getElementById('workTimerCard');
    const workTimerValueEl = document.getElementById('workTimerValue');
    const workTimerSubEl   = document.getElementById('workTimerSub');
    const workTimerTotalEl = document.getElementById('workTimerTotal');
    let workTimerIntervalId = null;
    let workTimerStartMs    = null;
    let workTimerTodayClosedMin = 0;

    function pinFormatElapsed(ms) {
      if (ms < 0) ms = 0;
      const total = Math.floor(ms / 1000);
      const h = Math.floor(total / 3600);
      const m = Math.floor((total % 3600) / 60);
      const s = total % 60;
      const pad = (n) => (n < 10 ? '0' + n : '' + n);
      return pad(h) + ':' + pad(m) + ':' + pad(s);
    }
    function pinFormatMinutesShort(min) {
      if (!min || min < 1) return '0 min';
      const h = Math.floor(min / 60);
      const m = min % 60;
      if (h <= 0) return m + ' min';
      if (m === 0) return h + ' h';
      return h + ' h ' + m + ' min';
    }
    function pinTickWorkTimer() {
      if (!workTimerValueEl || !workTimerStartMs) return;
      const elapsedSinceStartMs = Date.now() - workTimerStartMs;
      const isBreakMode = workTimerCardEl && workTimerCardEl.classList.contains('work-timer--break');

      // Timer GRANDE:
      //  - Em TRABALHANDO: tempo TOTAL trabalhado hoje (períodos fechados anteriores + atual).
      //    Assim, ao retornar de um intervalo, a contagem continua de onde parou em vez de zerar.
      //  - Em EM_INTERVALO: tempo do intervalo atual.
      const bigMs = isBreakMode
        ? elapsedSinceStartMs
        : (workTimerTodayClosedMin * 60000) + elapsedSinceStartMs;
      workTimerValueEl.textContent = pinFormatElapsed(bigMs);

      // Span inline dentro do card de status (modo intervalo): sempre o tempo do intervalo atual.
      const breakTick = document.getElementById('breakTickValue');
      if (breakTick) breakTick.textContent = pinFormatElapsed(elapsedSinceStartMs);

      // Linha "Total hoje": só aparece em modo intervalo (mostra o trabalho acumulado pausado).
      // Em modo trabalhando, o timer grande já é o total — repetir embaixo seria redundante.
      if (workTimerTotalEl) {
        if (isBreakMode && workTimerTodayClosedMin > 0) {
          workTimerTotalEl.innerHTML = 'Trabalhado hoje (pausado): <strong>' + pinFormatMinutesShort(workTimerTodayClosedMin) + '</strong>';
          workTimerTotalEl.style.display = 'block';
        } else {
          workTimerTotalEl.style.display = 'none';
        }
      }
    }
    function pinStopWorkTimer() {
      if (workTimerIntervalId) { clearInterval(workTimerIntervalId); workTimerIntervalId = null; }
      workTimerStartMs = null;
      if (workTimerCardEl) workTimerCardEl.style.display = 'none';
    }
    function pinUpdateWorkTimer(data) {
      // Mostra o timer quando há registro aberto hoje (entrada de trabalho OU início de intervalo).
      // Distingue visualmente os dois casos: cor/rótulo diferentes para EM_INTERVALO.
      if (!workTimerCardEl) return;
      const isOpenWork = data && data.state === 'trabalhando' && data.last && data.last.time_iso;
      const isOpenBreak = data && data.state === 'em_intervalo' && data.last && data.last.time_iso;
      if (!isOpenWork && !isOpenBreak) {
        pinStopWorkTimer();
        // Fallback para versões antigas sem `state`
        if (!data || !data.last || !data.last.is_today || data.last.action !== 'entrada' || !data.last.time_iso) {
          return;
        }
      }
      const startDate = new Date(data.last.time_iso);
      if (isNaN(startDate.getTime())) { pinStopWorkTimer(); return; }
      workTimerStartMs = startDate.getTime();
      workTimerTodayClosedMin = Math.max(0, parseInt(data.today_closed_minutes, 10) || 0);

      // Cor/rótulo distintos para intervalo (visual + label do header)
      workTimerCardEl.classList.toggle('work-timer--break', !!isOpenBreak);
      const workTimerLabelEl = workTimerCardEl.querySelector('.work-timer-label');
      if (workTimerLabelEl) {
        workTimerLabelEl.textContent = isOpenBreak ? 'Em intervalo' : 'Trabalhando agora';
      }
      if (workTimerSubEl) {
        if (isOpenBreak) {
          workTimerSubEl.innerHTML = 'Em intervalo desde <strong>' + data.last.time + '</strong>';
        } else {
          // Modo trabalhando: mostra a primeira entrada do dia + (se for diferente da batida
          // atual) também a hora de retorno do intervalo, deixando claro a continuidade.
          let firstHHMM = null;
          if (data.first_check_in_today) {
            try {
              const fd = new Date(data.first_check_in_today);
              if (!isNaN(fd.getTime())) {
                firstHHMM = fd.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
              }
            } catch (_) {}
          }
          const currentHHMM = data.last.time;
          if (firstHHMM && firstHHMM !== currentHHMM) {
            // Já houve intervalo hoje: mostra primeira entrada + retorno atual.
            workTimerSubEl.innerHTML = 'Desde <strong>' + firstHHMM + '</strong> · Retorno do intervalo às <strong>' + currentHHMM + '</strong>';
          } else {
            // Primeira sessão do dia (sem intervalo ainda).
            workTimerSubEl.innerHTML = 'Desde <strong>' + currentHHMM + '</strong>';
          }
        }
      }
      workTimerCardEl.style.display = 'block';
      pinTickWorkTimer();
      if (workTimerIntervalId) clearInterval(workTimerIntervalId);
      workTimerIntervalId = setInterval(pinTickWorkTimer, 1000);
    }

    // Quando a aba fica oculta, pausa o tick para economizar bateria;
    // ao voltar, atualiza imediatamente (valor é baseado em Date.now, não acumula drift).
    document.addEventListener('visibilitychange', () => {
      if (!workTimerStartMs) return;
      if (document.hidden) {
        if (workTimerIntervalId) { clearInterval(workTimerIntervalId); workTimerIntervalId = null; }
      } else {
        pinTickWorkTimer();
        if (!workTimerIntervalId) workTimerIntervalId = setInterval(pinTickWorkTimer, 1000);
      }
    });

    // ---------- Banner onboarding ----------
    const pinOnboardBanner = document.getElementById('pinOnboardBanner');
    const pinOnboardClose = document.getElementById('pinOnboardClose');
    function pinUpdateOnboardingBanner() {
      if (!pinOnboardBanner) return;
      // Logado: nunca mostra onboarding
      if (SESSION_MODE) { pinOnboardBanner.style.display = 'none'; return; }
      const hasCpf = !!pinLsGetCpf();
      let seenWelcome = false;
      try { seenWelcome = localStorage.getItem(PIN_LS.WELCOME) === '1'; } catch (_) {}
      pinOnboardBanner.style.display = (!hasCpf && !seenWelcome) ? 'flex' : 'none';
    }
    if (pinOnboardClose) pinOnboardClose.addEventListener('click', () => {
      try { localStorage.setItem(PIN_LS.WELCOME, '1'); } catch (_) {}
      pinOnboardBanner.style.display = 'none';
    });

    // ---------- Estado geral da home ----------
    function pinUpdateHomeState() {
      const identified = SESSION_MODE || !!pinLsGetCpf();
      const pinActionsEl = document.querySelector('.home-pin-actions');
      if (pinActionsEl) pinActionsEl.classList.toggle('is-hidden', identified);
      pinUpdateOnboardingBanner();
      if (identified) pinLoadLastCheckin();
      else if (pinLastCardEl) pinLastCardEl.style.display = 'none';
    }

    // ---------- Pré-preencher CPF ao abrir a tela PIN (kiosk/legacy) ----------
    const _origPinEntryMain = pinEntryMain;
    pinEntryMain = function() {
      pinResetDigits();
      pinShowSection(pinSectionEl);
      const saved = pinLsGetCpf();
      if (saved && pinCpfEl) {
        pinCpfEl.value = pinMaskCpf(saved);
        pinValidate();
        setTimeout(() => { if (pinDigitsEls[0]) pinDigitsEls[0].focus(); }, 60);
      } else {
        setTimeout(() => pinCpfEl && pinCpfEl.focus(), 60);
      }
    };

    // ---------- Check-in direto via sessão (sem digitar PIN) ----------
    async function submitSessionCheckin(faceDescriptor, auditPhoto) {
      const btn = document.getElementById('btnGoCheckin');
      if (!btn || btn.disabled) return;
      const expectedAction = pinExpectedActionForCpf(SESSION_COLLAB ? SESSION_COLLAB.cpf : '');
      // Foto é obrigatória APENAS na entrada do dia. Saída, iniciar/retornar intervalo
      // dispensam foto — o colaborador já se identificou (sessão + GPS).
      const requiresPhoto = (expectedAction === 'entrada' || expectedAction === null);
      if (requiresPhoto && !auditPhoto) {
        pinBeginStepup('A entrada precisa de foto para auditoria.', 'session_photo', 'entry_photo');
        return;
      }
      // M1: se step-up foi pedido (pinStepupNonce presente) mas o modelo de
      // reconhecimento facial ainda não terminou de carregar, evita enviar
      // payload sem descriptor — o backend rejeitaria com mensagem genérica.
      if (pinStepupNonce && !faceDescriptor && !faceDescriptorReady) {
        if (typeof toast === 'function') {
          toast('info', 'Aguarde',
            'Carregando reconhecimento facial — tente novamente em alguns segundos.', []);
        }
        return;
      }
      btn.disabled = true;
      btn.classList.add('is-loading');
      const common = await pinBuildCommonPayload();
      const geo = await pinGetGeo();
      let fraudCheck = null;
      try { if (typeof detectGpsMock === 'function' && geo) fraudCheck = detectGpsMock(geo); } catch (_) {}
      // Item 2: gera client_id ANTES de tentar enviar — permite retry idempotente.
      const clientId = (typeof pontoGenClientId === 'function') ? pontoGenClientId() : null;
      const payload = Object.assign({}, common, {
        use_session: true,
        geo, fraudCheck,
        recordMode: navigator.onLine ? 'online' : 'offline',
      });
      if (clientId) payload.client_id = clientId;
      if (expectedAction) payload.expected_action = expectedAction;
      // Hora real da volta, quando o colaborador ajustou no modal de confirmação.
      if (pinCorrectedReturnTime && expectedAction === 'retornar_intervalo') {
        payload.corrected_return_time = pinCorrectedReturnTime;
      }
      if (auditPhoto) payload.photo = auditPhoto;
      if (faceDescriptor) {
        payload.face_descriptor = faceDescriptor;
        if (pinStepupNonce) { payload.stepup_nonce = pinStepupNonce; pinStepupNonce = null; }
      }

      // Item 2: se offline, enfileira e encerra. Botão fica BLOQUEADO até o
      // drain confirmar — assim o usuário NÃO clica de novo achando que falhou.
      if (!navigator.onLine && typeof pontoQueueEnqueueSession === 'function') {
        try {
          const res = await pontoQueueEnqueueSession(payload);
          btn.classList.remove('is-loading');
          markButtonSavedOffline(btn);
          showPendingBanner(payload);
          if (typeof toast === 'function') {
            if (res && res.deduped) {
              toast('info', 'Já estava salvo',
                'Seu ponto já estava salvo no aparelho — não é necessário registrar de novo.',
                ['Vamos enviar assim que a conexão voltar.']);
            } else {
              toast('warning', 'Sem conexão',
                'Seu ponto foi salvo no aparelho. Não é necessário registrar novamente.',
                ['Assim que a conexão voltar, enviaremos automaticamente.']);
            }
          }
          return;
        } catch (e) {
          // Se falhar o enqueue, cai pro fluxo normal de erro (toast)
          console.warn('[SessionCheckin] falha ao enfileirar:', e);
        }
      }

      const { data } = await pinApiPost(PIN_API.checkin, payload);
      btn.disabled = false;
      btn.classList.remove('is-loading');

      // Item 2: se navegador dizia online mas fetch falhou OU servidor indisponível,
      // enfileira em vez de perder o registro. Botão também fica bloqueado.
      if ((data.code === 'offline' || data.code === 'server_unreachable' || data.code === 'server_error')
          && typeof pontoQueueEnqueueSession === 'function') {
        try {
          const res = await pontoQueueEnqueueSession(payload);
          markButtonSavedOffline(btn);
          showPendingBanner(payload);
          if (typeof toast === 'function') {
            toast('warning', 'Servidor indisponível',
              (res && res.deduped)
                ? 'Já estava salvo no aparelho — não é necessário registrar de novo.'
                : 'Ponto salvo no aparelho — vamos enviar assim que possível.',
              ['Não tente registrar de novo enquanto não houver conexão.']);
          }
          return;
        } catch (e) {
          console.warn('[SessionCheckin] falha ao enfileirar retry:', e);
          // Cai no pinHandleError abaixo
        }
      }

      // already_registered (sync duplo): trata como sucesso para o usuário
      if (data.code === 'already_registered') {
        pinShowCheckinSuccess(Object.assign({}, data, {
          message: 'Este ponto já havia sido registrado.',
          teacher: SESSION_COLLAB ? { name: SESSION_COLLAB.name } : undefined
        }));
        return;
      }

      if (data.status === 'require_face') {
        pinStepupNonce = data.stepup_nonce || null;
        pinBeginStepup(data.message || 'Vamos confirmar com sua foto.', 'session', data.reason);
        return;
      }
      if (data.status === 'choose_school') {
        // Camada 3.3: GPS falhou e o colaborador tem 2+ filiações.
        // Abre modal pra ele escolher; depois resubmete com manual_school_id.
        const chosen = await pinShowSchoolPicker(data.schools || []);
        if (chosen == null) {
          // usuário cancelou
          if (typeof toast === 'function') {
            toast('warning', 'Ponto não registrado',
              'Selecione a instituição para continuar ou habilite o GPS.', []);
          }
          return;
        }
        // Resubmit com manual_school_id (mantém todos os outros campos do payload)
        payload.manual_school_id = chosen;
        btn.disabled = true;
        btn.classList.add('is-loading');
        const { data: data2 } = await pinApiPost(PIN_API.checkin, payload);
        btn.disabled = false;
        btn.classList.remove('is-loading');
        if (data2.status === 'ok' || data2.code === 'already_registered') {
          pinShowCheckinSuccess(data2);
          setTimeout(() => pinLoadLastCheckin().catch(() => {}), 500);
          return;
        }
        pinHandleError(data2, 'session');
        return;
      }
      if (data.status === 'ok') {
        pinShowCheckinSuccess(data);
        // Refresca card "último ponto"
        setTimeout(() => pinLoadLastCheckin().catch(() => {}), 500);
        return;
      }
      pinHandleError(data, 'session');
    }

    // ============================================================
    // SCHOOL PICKER — promise-based modal para escolha de escola
    // quando o GPS falhou e o colaborador tem múltiplas filiações.
    // Resolve com schoolId selecionado ou null se cancelar.
    // ============================================================
    function pinShowSchoolPicker(schools) {
      return new Promise((resolve) => {
        const backdrop = document.getElementById('schoolPickerBackdrop');
        const list = document.getElementById('schoolPickerList');
        const cancelBtn = document.getElementById('schoolPickerCancel');
        if (!backdrop || !list) return resolve(null);

        // Renderiza opções
        list.innerHTML = '';
        const items = Array.isArray(schools) ? schools : [];
        items.forEach((s) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'sp-school';
          btn.setAttribute('role', 'listitem');
          const id = parseInt(s.id, 10);
          const name = String(s.name || 'Instituição sem nome');
          const addr = String(s.address || '');
          btn.innerHTML =
            '<span class="sp-school-icon" aria-hidden="true"><i class="bi bi-building"></i></span>' +
            '<span class="sp-school-info">' +
              '<span class="sp-school-name"></span>' +
              (addr ? '<span class="sp-school-addr"></span>' : '') +
            '</span>';
          btn.querySelector('.sp-school-name').textContent = name;
          if (addr) btn.querySelector('.sp-school-addr').textContent = addr;
          btn.addEventListener('click', () => {
            close();
            resolve(id);
          });
          list.appendChild(btn);
        });

        function close() {
          backdrop.classList.remove('is-open');
          backdrop.setAttribute('aria-hidden', 'true');
          cancelBtn.removeEventListener('click', onCancel);
          backdrop.removeEventListener('click', onBackdrop);
          document.removeEventListener('keydown', onKey);
        }
        function onCancel() { close(); resolve(null); }
        function onBackdrop(e) { if (e.target === backdrop) onCancel(); }
        function onKey(e) {
          if (e.key === 'Escape' && backdrop.classList.contains('is-open')) {
            e.preventDefault();
            onCancel();
          }
        }
        cancelBtn.addEventListener('click', onCancel);
        backdrop.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKey);

        backdrop.classList.add('is-open');
        backdrop.setAttribute('aria-hidden', 'false');
        // Foco na primeira escola
        setTimeout(() => {
          // M3: protege contra elemento desconectado caso modal seja fechado em <80ms.
          const first = list.querySelector('.sp-school');
          if (first && first.isConnected) first.focus();
        }, 80);
      });
    }

    // ============================================================
    // CONFIRMAÇÃO PRÉ-BATIDA — sempre exibida em modo session.
    //
    // Mostra: nome do usuário em destaque, ação esperada (entrada/saída),
    // hora atual e tempo trabalhado (apenas saída). Combate o cenário de
    // celular emprestado (B usa session de A sem perceber) e dá ao usuário
    // legítimo uma checagem mental antes de registrar.
    // ============================================================
    const identityConfirmBackdrop = document.getElementById('identityConfirmBackdrop');
    const identityConfirmYes      = document.getElementById('identityConfirmYes');
    const identityConfirmCancel   = document.getElementById('icCancel');
    const icActionEl     = document.getElementById('icAction');
    const icTimeBoxEl    = document.getElementById('icTimeBox');
    const icTimeEl       = document.getElementById('icTime');
    const icJourneyEl    = document.getElementById('icJourney');
    const icJourneyStart = document.getElementById('icJourneyStart');
    const icJourneyEnd   = document.getElementById('icJourneyEnd');
    const icJourneyHours = document.getElementById('icJourneyHours');
    const icJourneyMins  = document.getElementById('icJourneyMinutes');
    const icConfirmText  = document.getElementById('icConfirmText');
    let _identityConfirmResolve = null;
    let _icTimerId = null;

    function icFormatHHMM(d) {
      const h = String(d.getHours()).padStart(2, '0');
      const m = String(d.getMinutes()).padStart(2, '0');
      return h + ':' + m;
    }
    function icFormatElapsedMin(min) {
      if (!min || min < 1) return '0 min';
      const h = Math.floor(min / 60);
      const m = min % 60;
      if (h <= 0) return m + ' min';
      if (m === 0) return h + ' h';
      return h + ' h ' + String(m).padStart(2, '0') + ' min';
    }
    function icPopulate() {
      // Determina a próxima ação considerando estado + flag de intervalo.
      // Estados: 'entrada' | 'saida' | 'iniciar_intervalo' | 'retornar_intervalo'.
      let next = 'entrada';
      try {
        if (typeof pinLastDataCache !== 'undefined' && pinLastDataCache) {
          // Se o usuário clicou no botão de intervalo (_breakActionRequested=true) e está
          // trabalhando, força 'iniciar_intervalo'. Caso contrário, pega da máquina.
          if (typeof _breakActionRequested !== 'undefined' && _breakActionRequested
              && pinLastDataCache.state === 'trabalhando') {
            next = 'iniciar_intervalo';
          } else {
            next = pinComputeNextAction(pinLastDataCache.last, pinLastDataCache.state);
          }
        }
      } catch (_) {}

      // Mapeamento ação → texto/label/classe CSS
      const actionMap = {
        'entrada':             { big: 'ENTRADA',                btnText: 'Confirmar entrada',          cls: 'is-entrada' },
        'saida':               { big: 'SAÍDA',                  btnText: 'Confirmar saída',            cls: 'is-saida' },
        'iniciar_intervalo':   { big: 'INTERVALO',              btnText: 'Confirmar início do intervalo', cls: 'is-iniciar_intervalo' },
        'retornar_intervalo':  { big: 'RETORNO DO INTERVALO',   btnText: 'Confirmar retorno',          cls: 'is-retornar_intervalo' },
      };
      const cfg = actionMap[next] || actionMap['entrada'];
      const allClasses = ['is-entrada', 'is-saida', 'is-iniciar_intervalo', 'is-retornar_intervalo'];

      // Statement adaptativo — usuário precisa entender CLARAMENTE que NÃO é saída.
      const statementMap = {
        'entrada':            'Você está batendo o ponto de',
        'saida':              'Você está batendo o ponto de',
        'iniciar_intervalo':  'Você está iniciando o',
        'retornar_intervalo': 'Você está registrando',
      };
      const icStatementTextEl = document.getElementById('icStatementText');
      if (icStatementTextEl) {
        icStatementTextEl.textContent = statementMap[next] || statementMap['entrada'];
      }

      // Action big text — usa icActionEl original (nunca recriado)
      if (icActionEl) {
        icActionEl.textContent = cfg.big;
        allClasses.forEach(c => icActionEl.classList.remove(c));
        icActionEl.classList.add(cfg.cls);
      }

      // Botão de confirmar — texto + cor
      if (icConfirmText) icConfirmText.textContent = cfg.btnText;
      if (identityConfirmYes) {
        allClasses.forEach(c => identityConfirmYes.classList.remove(c));
        identityConfirmYes.classList.add(cfg.cls);
      }

      // Hora atual (live)
      const now = new Date();
      const nowHHMM = icFormatHHMM(now);
      if (icTimeEl) icTimeEl.textContent = nowHHMM;

      // Ajuste opcional da hora de RETORNO do intervalo (modo logado): o colaborador
      // pode informar a hora real se esqueceu de registrar na hora. Reseta a cada abertura.
      pinCorrectedReturnTime = null;
      const icTimeEditEl     = document.getElementById('icTimeEdit');
      const icTimeEditToggle = document.getElementById('icTimeEditToggle');
      const icTimeEditInput  = document.getElementById('icTimeEditInput');
      if (icTimeEditEl && icTimeEditToggle && icTimeEditInput) {
        const allowEdit = (next === 'retornar_intervalo') && (typeof SESSION_MODE !== 'undefined' && SESSION_MODE);
        icTimeEditEl.hidden     = !allowEdit;
        icTimeEditInput.hidden  = true;
        icTimeEditToggle.hidden = false;
        icTimeEditInput.value   = nowHHMM;
        if (allowEdit && !icTimeEditEl._bound) {
          icTimeEditEl._bound = true;
          icTimeEditToggle.addEventListener('click', () => {
            icTimeEditInput.hidden  = false;
            icTimeEditToggle.hidden = true;
            try { icTimeEditInput.focus(); } catch (_) {}
          });
          icTimeEditInput.addEventListener('input', () => {
            const v = (icTimeEditInput.value || '').trim();
            pinCorrectedReturnTime = v || null;
            if (icTimeEl) icTimeEl.textContent = v || nowHHMM;
          });
        }
      }

      // Card de informação no meio:
      //  - ENTRADA / RETORNAR_INTERVALO: caixa simples com hora atual (começando agora).
      //  - SAÍDA / INICIAR_INTERVALO: timeline (entrada → agora) + tempo trabalhado.
      const showsTimeline = (next === 'saida' || next === 'iniciar_intervalo')
                            && typeof workTimerStartMs === 'number' && workTimerStartMs > 0;
      if (icTimeBoxEl) icTimeBoxEl.hidden = showsTimeline;
      if (icJourneyEl) {
        icJourneyEl.hidden = !showsTimeline;
        // Aplica variante cinza quando é "iniciar intervalo" (distinção da saída laranja)
        icJourneyEl.classList.toggle('ic-journey--break', next === 'iniciar_intervalo');
        if (showsTimeline) {
          const startDate = new Date(workTimerStartMs);
          const startHHMM = icFormatHHMM(startDate);
          if (icJourneyStart) icJourneyStart.textContent = startHHMM;
          if (icJourneyEnd)   icJourneyEnd.textContent   = nowHHMM;

          const elapsedMin = Math.max(0, Math.floor((Date.now() - workTimerStartMs) / 60000));
          const h = Math.floor(elapsedMin / 60);
          const m = elapsedMin % 60;
          if (icJourneyHours) icJourneyHours.textContent = String(h);
          if (icJourneyMins)  icJourneyMins.textContent  = String(m).padStart(2, '0');

          // Ajusta o rótulo "trabalhados hoje" para refletir o contexto
          const heroLabel = document.querySelector('.ic-journey-hero-label');
          if (heroLabel) {
            heroLabel.textContent = (next === 'iniciar_intervalo')
              ? 'trabalhados antes do intervalo'
              : 'trabalhados hoje';
          }
        }
      }

      // HOTFIX 2026-09: entrada aberta de OUTRO dia (saída esquecida). O modal só
      // mostrava HH:MM e "trabalhados hoje", e o colaborador confirmava a saída
      // fechando um registro de ~24h (241 registros > 16h em produção).
      let staleWarn = document.getElementById('icStaleWarn');
      const cacheForStale = (typeof pinLastDataCache !== 'undefined') ? pinLastDataCache : null;
      const isStale = !!(cacheForStale && cacheForStale.open_is_stale && cacheForStale.open_check_in
                         && (next === 'saida' || next === 'iniciar_intervalo'));
      if (isStale && !staleWarn && icJourneyEl && icJourneyEl.parentNode) {
        staleWarn = document.createElement('div');
        staleWarn.id = 'icStaleWarn';
        staleWarn.setAttribute('role', 'alert');
        staleWarn.style.cssText = 'margin:10px 0;padding:10px 12px;border-radius:10px;background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;font-size:14px;line-height:1.35;text-align:left;';
        icJourneyEl.parentNode.insertBefore(staleWarn, icJourneyEl);
      }
      if (staleWarn) {
        staleWarn.hidden = !isStale;
        if (isStale) {
          const oc = String(cacheForStale.open_check_in);
          const d = oc.slice(0, 10).split('-').reverse().join('/');
          staleWarn.innerHTML = '<strong>Atenção:</strong> sua entrada aberta é de <strong>' + d + '</strong> às <strong>'
            + oc.slice(11, 16) + '</strong>. Se você esqueceu de registrar a saída nesse dia, toque em '
            + '<strong>Cancelar</strong> e informe a saída correta em "Meu ponto".';
        }
      }
    }
    function identityShowConfirm() {
      return new Promise((resolve) => {
        if (!identityConfirmBackdrop) return resolve(true);
        // M2: guard contra duplo-clique. Se modal já está aberto (resolve pendente),
        // retorna false sem sobrescrever — evita Promise órfã pendurada para sempre.
        if (_identityConfirmResolve) return resolve(false);
        _identityConfirmResolve = resolve;
        icPopulate();
        identityConfirmBackdrop.classList.add('is-open');
        identityConfirmBackdrop.setAttribute('aria-hidden', 'false');
        // Tick que mantém hora/tempo atualizados enquanto modal aberto.
        // Em saída, atualiza também o "agora" da timeline e o total trabalhado.
        if (_icTimerId) clearInterval(_icTimerId);
        _icTimerId = setInterval(() => {
          const now = new Date();
          // Não sobrescreve a hora se o colaborador editou a volta do intervalo.
          if (icTimeEl && !pinCorrectedReturnTime) icTimeEl.textContent = icFormatHHMM(now);
          if (icJourneyEl && !icJourneyEl.hidden && typeof workTimerStartMs === 'number' && workTimerStartMs > 0) {
            if (icJourneyEnd) icJourneyEnd.textContent = icFormatHHMM(now);
            const elapsedMin = Math.max(0, Math.floor((Date.now() - workTimerStartMs) / 60000));
            const h = Math.floor(elapsedMin / 60);
            const m = elapsedMin % 60;
            if (icJourneyHours) icJourneyHours.textContent = String(h);
            if (icJourneyMins)  icJourneyMins.textContent  = String(m).padStart(2, '0');
          }
        }, 30000);
        setTimeout(() => identityConfirmYes && identityConfirmYes.focus(), 100);
      });
    }
    function identityHideConfirm(result) {
      if (!identityConfirmBackdrop) return;
      identityConfirmBackdrop.classList.remove('is-open');
      identityConfirmBackdrop.setAttribute('aria-hidden', 'true');
      if (_icTimerId) { clearInterval(_icTimerId); _icTimerId = null; }
      if (_identityConfirmResolve) {
        _identityConfirmResolve(result);
        _identityConfirmResolve = null;
      }
    }
    if (identityConfirmYes) identityConfirmYes.addEventListener('click', () => identityHideConfirm(true));
    if (identityConfirmCancel) identityConfirmCancel.addEventListener('click', () => identityHideConfirm(false));
    // Esc fecha sem registrar
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && identityConfirmBackdrop && identityConfirmBackdrop.classList.contains('is-open')) {
        e.preventDefault();
        identityHideConfirm(false);
      }
    });
    // Click no backdrop também cancela (UX comum em mobile)
    if (identityConfirmBackdrop) identityConfirmBackdrop.addEventListener('click', (e) => {
      if (e.target === identityConfirmBackdrop) identityHideConfirm(false);
    });

    // Reatacha o listener do botão principal: comportamento depende do modo.
    if (typeof btnGoCheckin !== 'undefined' && btnGoCheckin) {
      btnGoCheckin.replaceWith(btnGoCheckin.cloneNode(true));
      const newBtn = document.getElementById('btnGoCheckin');
      if (newBtn) {
        newBtn.addEventListener('click', async () => {
          _breakActionRequested = false;
          if (SESSION_MODE) {
            // Sempre confirma antes de bater ponto
            const ok = await identityShowConfirm();
            if (!ok) return;
            submitSessionCheckin();
          } else {
            pinEntryMain();
          }
        });
      }
    }

    // Reatacha listener do botão de intervalo com o MESMO fluxo do principal
    // (identityShowConfirm em modo logado, pinEntryMain em kiosk). A única
    // diferença é setar _breakActionRequested=true para sinalizar a intenção
    // de iniciar/retornar intervalo via pinExpectedActionForCpf.
    const _breakBtnEl = document.getElementById('btnGoBreak');
    if (_breakBtnEl) {
      _breakBtnEl.replaceWith(_breakBtnEl.cloneNode(true));
      const newBreakBtn = document.getElementById('btnGoBreak');
      if (newBreakBtn) {
        newBreakBtn.addEventListener('click', async () => {
          _breakActionRequested = true;
          if (SESSION_MODE) {
            const ok = await identityShowConfirm();
            if (!ok) { _breakActionRequested = false; return; }
            submitSessionCheckin();
          } else {
            pinEntryMain();
          }
        });
      }
    }

    // Expor handler de session para o capture do step-up (adicionado no router abaixo)
    window.__pinSubmitSessionCheckin = submitSessionCheckin;

    // ---------- Erro inteligente de PIN ----------
    let pinFailCount = 0;
    function pinTriggerErrorShake() {
      const grid = document.getElementById('pinDigits');
      if (!grid) return;
      grid.classList.remove('is-error');
      // reflow para reiniciar animação
      void grid.offsetWidth;
      grid.classList.add('is-error');
      setTimeout(() => grid.classList.remove('is-error'), 400);
    }
    function pinHighlightForgotLink() {
      document.querySelectorAll('[data-pin-go="recover"]').forEach(el => {
        el.classList.add('pin-link-highlight');
      });
    }
    function pinResetErrorState() {
      pinFailCount = 0;
      document.querySelectorAll('[data-pin-go="recover"]').forEach(el => el.classList.remove('pin-link-highlight'));
    }

    // ---------- Modal crítico (M2 + M4: aria + focus trap + safe-area) ----------
    function pinShowCriticalError(title, msg, actions) {
      document.querySelectorAll('.pin-modal-backdrop').forEach(el => el.remove());
      const previouslyFocused = document.activeElement;
      const backdrop = document.createElement('div');
      backdrop.className = 'pin-modal-backdrop';
      const box = document.createElement('div');
      box.className = 'pin-modal-box';
      box.setAttribute('role', 'alertdialog');
      box.setAttribute('aria-modal', 'true');
      const titleId = 'pin-modal-title-' + Date.now();
      const msgId   = 'pin-modal-msg-' + Date.now();
      box.setAttribute('aria-labelledby', titleId);
      box.setAttribute('aria-describedby', msgId);
      const actionsArr = Array.isArray(actions) && actions.length > 0 ? actions : [{ label: 'Entendi', primary: true, onClick: () => {} }];
      box.innerHTML = `
        <div class="pin-modal-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div class="pin-modal-title" id="${titleId}">${title || 'Atenção'}</div>
        <div class="pin-modal-msg" id="${msgId}">${msg || ''}</div>
      `;
      const buttons = [];
      actionsArr.forEach((a, i) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = a.primary ? 'btn-pin-submit' : 'btn-pin-link';
        if (i > 0 && a.primary) b.style.marginTop = '10px';
        b.textContent = a.label;
        b.addEventListener('click', () => {
          closeModal();
          if (typeof a.onClick === 'function') a.onClick();
        });
        buttons.push(b);
        box.appendChild(b);
      });
      backdrop.appendChild(box);
      document.body.appendChild(backdrop);
      document.body.classList.add('drawer-open'); // reaproveita lock de scroll

      // Focus trap: Tab cycle entre primeiro/último botão, Esc fecha
      const focusableEls = () => Array.from(box.querySelectorAll('button'));
      const firstEl = focusableEls()[0];
      if (firstEl) setTimeout(() => firstEl.focus(), 30);
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); closeModal(); return; }
        if (e.key !== 'Tab') return;
        const items = focusableEls();
        if (items.length === 0) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
      backdrop.addEventListener('keydown', onKey);
      function closeModal() {
        backdrop.removeEventListener('keydown', onKey);
        backdrop.remove();
        document.body.classList.remove('drawer-open');
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
          try { previouslyFocused.focus(); } catch (_) {}
        }
      }
    }

    // ---------- Tela de sucesso do check-in PIN ----------
    const pinSuccessSectionEl = document.getElementById('pinSuccessSection');
    if (pinSuccessSectionEl) pinAllSections.push(pinSuccessSectionEl);
    let pinSuccessAutoDismissTid = null;
    function pinShowCheckinSuccess(data) {
      if (!pinSuccessSectionEl) return;
      if (Array.isArray(data?.pending_reasons)) {
        data.pending_reasons = data.pending_reasons.map(mapPendingReasonLabel);
      }
      const tzBR = 'America/Sao_Paulo';
      const tsStr = data.time || data.recorded_at || '';
      let hh = '--:--', dateStr = '—';
      try {
        if (tsStr) {
          const dt = new Date(tsStr.replace(' ', 'T'));
          hh = dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
          const meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
          dateStr = `${dt.getDate()} de ${meses[dt.getMonth()]}, ${dt.getFullYear()}`;
        }
      } catch (_) {}
      // Usa actionLabel (helper global) — cobre entrada, saída e ambos os intervalos.
      const labelAction = actionLabel(data.action);
      document.getElementById('pinSuccessAction').textContent = `${labelAction} • ${hh}`;
      document.getElementById('pinSuccessDate').textContent = dateStr;
      document.getElementById('pinSuccessName').textContent = (data.teacher && data.teacher.name) || (data.collaborator && data.collaborator.name) || '—';
      document.getElementById('pinSuccessNsr').textContent = data.nsr ? ('#' + data.nsr) : '—';
      const warn = document.getElementById('pinSuccessWarning');
      if (data.approved === false) {
        warn.style.display = 'block';
        const reasons = Array.isArray(data.pending_reasons) && data.pending_reasons.length ? data.pending_reasons.join(', ') : 'pendências identificadas';
        warn.innerHTML = `<i class="bi bi-info-circle"></i> Seu ponto foi registrado, mas aguarda revisão: ${reasons}.`;
      } else {
        warn.style.display = 'none';
      }
      const recBtn = document.getElementById('btnPinSuccessReceipt');
      if (data.attendance_id) {
        recBtn.style.display = 'block';
        recBtn.onclick = () => window.open((PIN_ROOT_BASE || '') + '/public/receipt.php?id=' + encodeURIComponent(data.attendance_id), '_blank');
      } else {
        recBtn.style.display = 'none';
      }
      pinShowSection(pinSuccessSectionEl);

      // Auto-dismiss em 4s: usuário leigo espera retorno automático.
      // O botão CONCLUIR continua disponível como escape hatch manual.
      // Cancelado se usuário abrir outra tela ou clicar CONCLUIR.
      if (pinSuccessAutoDismissTid) clearTimeout(pinSuccessAutoDismissTid);
      pinSuccessAutoDismissTid = setTimeout(() => {
        pinSuccessAutoDismissTid = null;
        if (pinSuccessSectionEl.style.display !== 'none') pinGoHome();
      }, 4000);
    }
    const pinSuccessDoneBtn = document.getElementById('btnPinSuccessDone');
    if (pinSuccessDoneBtn) pinSuccessDoneBtn.addEventListener('click', () => {
      if (pinSuccessAutoDismissTid) { clearTimeout(pinSuccessAutoDismissTid); pinSuccessAutoDismissTid = null; }
      pinGoHome();
    });

    // ---------- Preload de modelos face-api em background ----------
    function pinMaybePreloadFace() {
      try {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (conn && (conn.saveData || conn.effectiveType === '2g' || conn.effectiveType === 'slow-2g')) return;
      } catch (_) {}
      if (typeof loadFaceModels === 'function') {
        try { loadFaceModels().catch(() => {}); } catch (_) {}
      }
    }
    // dispara após 800ms para não competir com render inicial
    setTimeout(pinMaybePreloadFace, 800);

    // ============================================================
    // Interceptar pinCheckinSubmit e pinHandleError para novas UX
    // (os originais permanecem válidos; apenas adicionamos comportamento)
    // ============================================================
    const _origPinCheckinSubmit = pinCheckinSubmit;
    pinCheckinSubmit = async function(faceDescriptor) {
      if (!pinSubmitBtn || pinSubmitBtn.disabled) return;
      const cpf = pinCleanCpf(pinCpfEl.value);
      const pin = pinCollect();
      pinSubmitBtn.disabled = true;
      pinSubmitBtn.classList.add('is-loading');
      const common = await pinBuildCommonPayload();
      const geo = await pinGetGeo();
      let fraudCheck = null;
      try { if (typeof detectGpsMock === 'function' && geo) fraudCheck = detectGpsMock(geo); } catch (_) {}
      const payload = Object.assign({}, common, {
        cpf, pin, geo, fraudCheck,
        recordMode: navigator.onLine ? 'online' : 'offline',
      });
      const expectedAction = pinExpectedActionForCpf(cpf);
      if (expectedAction) payload.expected_action = expectedAction;
      if (faceDescriptor) {
        payload.face_descriptor = faceDescriptor;
        if (pinStepupNonce) { payload.stepup_nonce = pinStepupNonce; pinStepupNonce = null; }
      }
      const { data } = await pinApiPost(PIN_API.checkin, payload);
      pinSubmitBtn.classList.remove('is-loading'); pinValidate();

      if (data.status === 'require_face') {
        pinBeginStepup(data.message || 'Vamos confirmar com sua foto.', 'main', data.reason);
        return;
      }
      if (data.status === 'ok') {
        pinLsSetCpf(cpf);
        pinResetErrorState();
        pinResetDigits();
        pinShowCheckinSuccess(data);
        return;
      }
      pinHandleError(data, 'main');
    };
    if (pinSubmitBtn) {
      // Reatacha listener ao wrapper novo (remove o anterior via clone)
      pinSubmitBtn.replaceWith(pinSubmitBtn.cloneNode(true));
      const newSubmitBtn = document.getElementById('btnPinSubmit');
      if (newSubmitBtn) newSubmitBtn.addEventListener('click', () => pinCheckinSubmit());
    }

    // Intercepta pin_invalid e códigos críticos para UX melhorada
    const _origPinHandleError = pinHandleError;
    pinHandleError = function(data, flow) {
      const code = data && data.code || '';
      const msg = data && data.message || 'Tente novamente.';

      if (code === 'pin_invalid') {
        pinFailCount++;
        pinTriggerErrorShake();
        if (typeof toast === 'function') toast('error', 'PIN incorreto', 'Verifique os números.', []);
        // destaca último dígito para sobrescrever
        const lastFilled = pinDigitsEls.slice().reverse().find(i => i.value);
        if (lastFilled) { lastFilled.focus(); lastFilled.select && lastFilled.select(); }
        if (pinFailCount >= 2) pinHighlightForgotLink();
        return;
      }

      if (code === 'blocked_contact_admin' || code === 'face_conflict' || code === 'self_enroll_not_allowed') {
        pinShowCriticalError(
          code === 'face_conflict' ? 'Foto não aceita' : 'Precisamos da ajuda do administrador',
          msg,
          [
            { label: 'Voltar para o início', primary: true, onClick: () => pinGoHome() },
          ]
        );
        return;
      }

      if (code === 'too_many_attempts') {
        pinShowCriticalError(
          'Muitas tentativas',
          msg,
          [
            { label: 'Voltar para o início', primary: true, onClick: () => pinGoHome() },
          ]
        );
        return;
      }

      if (code === 'action_mismatch') {
        if (typeof toast === 'function') {
          toast('warning', 'Estado do ponto mudou', msg, ['Atualize a página e confira o último ponto antes de tentar novamente.']);
        }
        setTimeout(() => pinLoadLastCheckin().catch(() => {}), 500);
        return;
      }

      // Erros de rede: feedback claro do que aconteceu.
      if (code === 'offline') {
        if (typeof toast === 'function') toast('warning', 'Sem internet', msg, []);
        return;
      }
      if (code === 'server_unreachable' || code === 'server_error' || code === 'invalid_response') {
        if (typeof toast === 'function') toast('error', 'Servidor indisponível', msg, []);
        return;
      }
      if (code === 'session_expired') {
        pinShowCriticalError('Sessão expirada', msg || 'Faça login novamente para continuar.', [
          { label: 'Fazer login', primary: true, onClick: () => { window.location.href = 'login.php?expired=1'; } },
        ]);
        return;
      }

      // HOTFIX 2026-09: saída esquecida há mais de 30h bloqueia a batida. Antes caía
      // no toast genérico "Erro", sem caminho para resolver — o colaborador ficava
      // sem conseguir bater ponto até descobrir "Meu ponto" sozinho.
      if (code === 'orphan_punch_pending') {
        const orphans = Array.isArray(data.orphans) ? data.orphans : [];
        const first = orphans[0] || null;
        const fmtDate = (d) => String(d || '').slice(0, 10).split('-').reverse().join('/');
        const fmtTime = (dt) => String(dt || '').slice(11, 16);
        const detail = first
          ? `Você registrou entrada em <strong>${fmtDate(first.date)}</strong> às <strong>${fmtTime(first.check_in)}</strong> e não registrou a saída.`
          : 'Você tem ponto(s) anteriores sem saída.';
        const monthUrl = first
          ? ('my_timesheet.php?month=' + parseInt(String(first.date).slice(5, 7), 10) + '&year=' + parseInt(String(first.date).slice(0, 4), 10))
          : 'my_timesheet.php';
        pinShowCriticalError(
          'Sua batida NÃO foi registrada',
          detail + '<br><br>Informe o horário de saída desse dia em "Meu ponto" (botão amarelo) e depois bata o ponto normalmente.',
          [
            { label: 'Regularizar a saída', primary: true, onClick: () => { window.location.href = monthUrl; } },
            { label: 'Voltar para o início', primary: false, onClick: () => pinGoHome() },
          ]
        );
        return;
      }

      // HOTFIX 2026-09: códigos de estado que antes viravam "Erro" genérico e
      // perdiam a dica do servidor (ex.: "aguarde N segundos"). Mostra a dica e
      // recarrega o estado do botão para não induzir a ação errada.
      if (code === 'checkout_too_soon' || code === 'break_too_soon' || code === 'state_changed'
          || code === 'busy_retry' || code === 'break_open_cannot_clock_out' || code === 'no_break_open'
          || code === 'break_already_open' || code === 'no_work_open') {
        const hints = Array.isArray(data.hints) ? data.hints : [];
        if (typeof toast === 'function') {
          toast(code === 'busy_retry' ? 'info' : 'warning',
            code === 'busy_retry' ? 'Aguarde um instante' : 'Ponto não registrado', msg, hints);
        }
        setTimeout(() => pinLoadLastCheckin().catch(() => {}), 500);
        return;
      }
      if (code === 'pin_required') {
        pinShowCriticalError('Entre com seu PIN', msg || 'Faça login com CPF e PIN para registrar o ponto.', [
          { label: 'Fazer login', primary: true, onClick: () => { window.location.href = 'login.php'; } },
        ]);
        return;
      }

      // Qualquer outro cai no handler original
      _origPinHandleError(data, flow);
    };

    // ---------- Inicialização do estado da home ----------
    pinUpdateHomeState();

    // ---------- DRAWER (menu lateral) ----------
    const appDrawer = document.getElementById('appDrawer');
    const appDrawerBackdrop = document.getElementById('appDrawerBackdrop');
    const btnMenuToggle = document.getElementById('btnMenuToggle');
    const btnMenuClose = document.getElementById('btnMenuClose');

    let _drawerPreviouslyFocused = null;
    // Flag usado pelo popstate para distinguir fechamento por back-button vs. click:
    // - click/Esc/backdrop: precisa consumir 1 entry de history (history.back())
    // - popstate (back-button): NÃO chama history.back (já estamos saindo)
    let _drawerHistoryPushed = false;
    function drawerOpen() {
      if (!appDrawer) return;
      _drawerPreviouslyFocused = document.activeElement;
      appDrawer.classList.add('is-open');
      appDrawerBackdrop.classList.add('is-open');
      appDrawer.setAttribute('aria-hidden', 'false');
      btnMenuToggle && btnMenuToggle.setAttribute('aria-expanded', 'true');
      document.body.classList.add('drawer-open');
      // Empilha um estado para que o botão "voltar" do Android feche o drawer
      // em vez de sair do app instalado (PWA).
      try {
        history.pushState({ drawer: true }, '');
        _drawerHistoryPushed = true;
      } catch (_) { _drawerHistoryPushed = false; }
      const firstItem = appDrawer.querySelector('.app-drawer-item');
      if (firstItem) setTimeout(() => firstItem.focus(), 220);
    }
    function drawerClose(fromPopstate) {
      if (!appDrawer) return;
      appDrawer.classList.remove('is-open');
      appDrawerBackdrop.classList.remove('is-open');
      appDrawer.setAttribute('aria-hidden', 'true');
      btnMenuToggle && btnMenuToggle.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('drawer-open');
      // Consome o history entry empilhado em drawerOpen, exceto quando fechamos
      // justamente por causa de um popstate (aí já estamos no novo estado).
      if (_drawerHistoryPushed && !fromPopstate) {
        try { history.back(); } catch (_) {}
      }
      _drawerHistoryPushed = false;
      if (_drawerPreviouslyFocused && typeof _drawerPreviouslyFocused.focus === 'function') {
        try { _drawerPreviouslyFocused.focus(); } catch (_) {}
        _drawerPreviouslyFocused = null;
      }
    }
    window.addEventListener('popstate', () => {
      if (appDrawer && appDrawer.classList.contains('is-open')) {
        drawerClose(true);
      }
    });
    // H4: bfcache restore → a history entry pushada por drawerOpen não volta
    // junto. Se _drawerHistoryPushed ficasse true, history.back() consumiria
    // uma entry REAL. Resetamos para evitar navegação acidental pra trás.
    window.addEventListener('pageshow', (e) => {
      if (e.persisted) {
        _drawerHistoryPushed = false;
        // H1: bfcache restore — descarta estado intermediário de enrollment
        // (face capturada ou CPF de fluxo anterior cancelado).
        if (typeof pinEnrollClearState === 'function') pinEnrollClearState();
      }
    });
    if (btnMenuToggle) btnMenuToggle.addEventListener('click', drawerOpen);
    if (btnMenuClose) btnMenuClose.addEventListener('click', drawerClose);
    if (appDrawerBackdrop) appDrawerBackdrop.addEventListener('click', drawerClose);
    document.addEventListener('keydown', (e) => {
      if (!appDrawer || !appDrawer.classList.contains('is-open')) return;
      if (e.key === 'Escape') { e.preventDefault(); drawerClose(); return; }
      // M3: focus trap — Tab cicla entre primeiro e último item do drawer.
      if (e.key === 'Tab') {
        const items = Array.from(appDrawer.querySelectorAll('.app-drawer-item, .app-drawer-close'));
        if (items.length === 0) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });

    // Itens do drawer que disparam fluxos existentes
    const menuBtnUseFace = document.getElementById('menuBtnUseFace');
    if (menuBtnUseFace) menuBtnUseFace.addEventListener('click', () => {
      drawerClose();
      setTimeout(() => { if (typeof showCamera === 'function') showCamera(); }, 220);
    });
    const menuBtnChangePin = document.getElementById('menuBtnChangePin');
    if (menuBtnChangePin) menuBtnChangePin.addEventListener('click', () => {
      drawerClose();
      setTimeout(() => pinEntryRecover(), 220);
    });

    // ---------- Auto-open de fluxos via query param (vindos de /login.php) ----------
    if (OPEN_FIRST_ACCESS) {
      setTimeout(() => {
        pinEntryEnroll();
        // CPF veio pré-preenchido (rota auto do login). Aplica a máscara
        // e dispara validação para já habilitar o botão CONTINUAR.
        if (PREFILL_CPF && pinEnrollCpfEl) {
          pinEnrollCpfEl.value = (typeof formatCpf === 'function') ? formatCpf(PREFILL_CPF) : PREFILL_CPF;
          pinValidate();
          // Pequeno aviso para o usuário entender por que está aqui sem ter clicado.
          if (typeof toast === 'function') {
            toast('info', 'Vamos fazer seu primeiro acesso',
              'Detectamos que esse CPF ainda não tem PIN. Em alguns toques, você está pronto.', []);
          }
          // Move foco direto para o botão (CPF já preenchido)
          setTimeout(() => pinEnrollSubmitBtn && pinEnrollSubmitBtn.focus(), 60);
        }
      }, 80);
    } else if (OPEN_FORGOT_PIN) {
      setTimeout(() => pinEntryRecover(), 80);
    }

    pinValidate();
  })();
  </script>

  <!-- Bootstrap bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>

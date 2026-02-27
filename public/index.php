<?php
require_once __DIR__ . '/../config.php';

// Helper para escapar
if (!function_exists('esc')) {
  function esc($str)
  {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$appBase = $scriptDir !== '' ? $scriptDir : '/';

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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
  <meta name="theme-color" content="#0d6efd">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <meta name="app-base" content="<?= esc($appBase) ?>">

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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <!-- face-api.js - Reconhecimento facial (local para suporte offline) -->
  <script src="<?= esc($appBase) ?>/js/face-api.min.js"></script>

  <link rel="shortcut icon" href="img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="img/icone-2.ico" type="image/x-icon">

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
      min-height: 100dvh;
      padding-bottom: env(safe-area-inset-bottom, 20px);
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
      inset: 0;
      pointer-events: none;
    }

    .face-guide {
      position: absolute;
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
      top: env(safe-area-inset-top, 8px);
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
      border-radius: 18px;
      border: 1px solid rgba(2, 6, 23, .08);
      box-shadow: var(--shadow);
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

    #homeMap {
      width: 100%;
      height: 200px;
      z-index: 1;
    }
    @media (min-width: 576px) {
      #homeMap { height: 240px; }
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
    .home-actions .btn-checkin:hover { background: #157347; box-shadow: 0 8px 24px rgba(25,135,84,.4); }
    .home-actions .btn-checkin:active { transform: scale(.97); }
    .home-actions .btn-checkin i { font-size: 1.5rem; }

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
  </style>
</head>

<body class="screen">
  <main class="container py-1">
    <!-- Header - Logo centralizada -->
    <header class="app-header mb-1" role="banner" aria-label="Topo">
      <div class="header-content" style="justify-content:center;">
        <img src="<?= esc($appBase) ?>/img/logo_login.png" alt="DEEDO Ponto" class="header-logo" style="width:clamp(150px,30vw,200px);">
      </div>
      <!-- Elementos ocultos mantidos para compatibilidade JS -->
      <div style="display:none">
        <span id="hlbClock">--:--:--</span>
        <span id="hlbStatus"></span>
        <span id="hlbClockMobile">--:--:--</span>
        <span id="hlbStatusMobile"></span>
        <span id="netBadge"><i class="bi bi-wifi"></i><span class="status-text">Online</span></span>
        <button id="btnSyncNow"><span id="syncCount">0</span></button>
      </div>
    </header>

    <!-- ===================== HOME SCREEN ===================== -->
    <section id="homeScreen">
      <!-- Relógio grande -->
      <div class="home-clock-block">
        <div class="home-clock" id="homeClock">--:--:--</div>
        <div class="home-date" id="homeDate">--</div>
      </div>

      <!-- Card com mapa + status -->
      <div class="home-info-card">
        <div id="homeMap"></div>
        <div class="home-status-bar">
          <span class="home-badge geo-wait" id="homeNetBadge">
            <i class="bi bi-wifi"></i> <span>Verificando...</span>
          </span>
        </div>
      </div>

      <!-- Botões principais -->
      <div class="home-actions">
        <button type="button" class="btn-checkin" id="btnGoCheckin">
          <i class="bi bi-check-circle-fill"></i> Bater Ponto
        </button>
        <div class="home-secondary-grid">
          <a href="<?= esc($appBase) ?>/my_login.php" class="btn-sec">
            <i class="bi bi-calendar-check"></i>
            Minha Folha
          </a>
          <a href="<?= esc($appBase) ?>/admin/login.php" class="btn-sec">
            <i class="bi bi-shield-lock"></i>
            Entrar como Admin
          </a>
        </div>
      </div>

      <!-- Footer -->
      <footer class="home-footer mt-4 mb-2">
        <div class="home-footer-divider"></div>
        <div class="home-footer-logos">
          <img src="<?= esc($appBase) ?>/img/logo_prefeitura.png" alt="Prefeitura Municipal de Ribeira do Piauí" class="home-footer-logo-pref">
          <div class="home-footer-sep"></div>
          <img src="<?= esc($appBase) ?>/img/deedo_ponto_logo.png" alt="DEEDO Ponto" class="home-footer-logo-deedo">
        </div>
        <div class="home-footer-info">
          <div>Prefeitura Municipal de Ribeira do Piauí - PI</div>
          <div>Sistema de Ponto Eletrônico &bull; Portaria MTP 671/2021</div>
          <div class="home-footer-copy">&copy; <?= date('Y') ?> DEEDO Sistemas &mdash; Todos os direitos reservados</div>
        </div>
      </footer>
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
          <video id="video" autoplay playsinline muted></video>
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
    <video id="fullscreenVideo" autoplay playsinline muted></video>
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
  </div>

  <!-- Modal LGPD - Termo de Consentimento -->
  <div class="modal fade" id="lgpdConsentModal" tabindex="-1" aria-labelledby="lgpdConsentLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="lgpdConsentLabel">
            <i class="bi bi-shield-check me-2"></i>
            Termo de Consentimento - LGPD
          </h5>
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
        <div class="modal-header border-0 pb-0">
          <div class="d-flex w-100 justify-content-between align-items-start">
            <div class="me-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-clipboard-check fs-4" aria-hidden="true"></i>
                <h5 class="modal-title mb-0" id="confirmModalLabel">Revisar registro</h5>
              </div>
              <small class="text-muted">Revise os dados e informe seu PIN</small>
            </div>

            <div class="d-flex align-items-center gap-2">
              <!-- evita duplicidade de id no documento -->
              <a id="btnAdminModal" class="btn btn-outline-primary btn-sm" href="<?= esc($appBase) ?>/admin/login.php" aria-label="Área administrativa">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <span class="d-none d-sm-inline">Acessar como admin</span>
              </a>

              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
          </div>
        </div>

        <div class="modal-body pt-3">
          <div class="row g-4 align-items-start">
            <div class="col-12 col-lg-5">
              <div style="position:relative;">
                <img
                  id="confirmPhoto"
                  src=""
                  alt="Sua foto"
                  class="rounded-3 d-block mx-auto"
                  style="max-width: 100%; width: 100%; height: auto;">
                <div id="capturedFaceBadge" class="captured-face-badge" style="display:none;">
                  <i id="capturedFaceBadgeIcon" class="bi bi-person-check"></i>
                  <span id="capturedFaceBadgeText">Rosto detectado</span>
                </div>
              </div>
            </div>

            <!-- Resumo + PIN -->
            <div class="col-12 col-lg-7">
              <!-- Resumo -->
              <div class="summary-list px-2">
                <div class="row align-items-center d-none" id="confirmCollaboratorRow">
                  <div class="col-auto">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width:28px;height:28px">
                      <i class="bi bi-person-circle fs-6"></i>
                    </span>
                  </div>
                  <div class="col">
                    <div id="confirmCollaboratorName" class="fw-semibold fs-6">—</div>
                    <div id="confirmCollaboratorAction" class="text-muted small">Confirme seu PIN para carregar os dados.</div>
                  </div>
                </div>
                <div class="row align-items-center">
                  <div class="col-auto">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width:28px;height:28px">
                      <i class="bi bi-calendar3 fs-6"></i>
                    </span>
                  </div>
                  <div class="col">
                    <div id="confirmDate" class="fw-semibold fs-6">—</div>
                  </div>
                </div>

                <div class="row align-items-center">
                  <div class="col-auto">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width:28px;height:28px">
                      <i class="bi bi-clock fs-6"></i>
                    </span>
                  </div>
                  <div class="col">
                    <div id="confirmTime" class="fw-semibold fs-6">—</div>
                  </div>
                </div>

                <div class="row align-items-center">
                  <div class="col-auto">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width:28px;height:28px">
                      <i class="bi bi-geo-alt fs-6"></i>
                    </span>
                  </div>
                  <div class="col">
                    <div id="confirmLoc" class="fs-6">
                      <span class="status-chip warn"><span class="dot warn"></span> Solicitando permissão...</span>
                    </div>
                  </div>
                </div>

                <div class="row align-items-center" id="confirmFaceRow" style="display:none">
                  <div class="col-auto">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width:28px;height:28px" id="confirmFaceIcon">
                      <i class="bi bi-person-check fs-6"></i>
                    </span>
                  </div>
                  <div class="col">
                    <div id="confirmFaceStatus" class="fs-6">Verificando rosto...</div>
                  </div>
                </div>
              </div>
              <hr class="my-3">

              <!-- PIN Form -->
              <form id="pinForm" class="w-100" onsubmit="return false;" autocomplete="on">
                <label for="pinModal" class="form-label mb-1 fw-semibold">PIN (6 dígitos)</label>
                <div class="input-group input-group-lg">
                  <input
                    type="password"
                    class="form-control"
                    id="pinModal"
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
                    aria-describedby="pinModalFeedback">
                  <button
                    class="btn btn-outline-secondary"
                    type="button"
                    id="btnTogglePinModal"
                    aria-label="Mostrar PIN">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                  </button>
                </div>
                <div id="pinModalFeedback" class="invalid-feedback"></div>
                <div class="input-hint mt-1">Digite seu PIN e toque em "Revisar ponto".</div>
                <div class="form-check mt-3">
                  <input class="form-check-input" type="checkbox" value="1" id="rememberPinModal" data-remember-pin="checkbox">
                  <label class="form-check-label" for="rememberPinModal">
                    Lembrar PIN neste dispositivo
                  </label>
                  <div class="form-text">Só marque em aparelhos confiáveis.</div>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div class="modal-footer border-0 pt-0">
          <!-- Botão principal: Registrar ponto -->
          <button type="button" id="btnConfirmSubmit" class="btn btn-success-gradient btn-lg w-100 mb-3">
            <span class="label"><i class="bi bi-search me-1"></i> Revisar</span>
            <span class="spinner-border spinner-border-sm d-none ms-2" role="status" aria-hidden="true"></span>
          </button>

          <!-- Botões secundários -->
          <div class="d-flex flex-wrap gap-2 justify-content-between w-100">
            <button type="button" class="btn btn-outline-secondary btn-lg w-100 w-md-auto" id="btnModalRetake">
              <i class="bi bi-arrow-counterclockwise"></i> Tirar outra foto
            </button>
            <a href="<?= esc($appBase) ?>/my_login.php" class="btn btn-outline-primary btn-lg w-100 w-md-auto d-md-none" title="Consulte sua folha de ponto">
              <i class="bi bi-calendar-check me-2"></i>Minha Folha
            </a>
          </div>
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
            Tempo esgotado. Clique em “Revisar ponto” novamente para reiniciar o processo de confirmação.
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

  <script src="<?= esc($appBase) ?>/js/pin-remember.js"></script>
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
  <div id="toastify" class="toastify" aria-live="polite" aria-atomic="true"></div>

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
      const isSafari = /safari/.test(ua) && !/chrome/.test(ua) && !/android/.test(ua);
      const isIOS = /iphone|ipad|ipod/.test(ua);
      const isChrome = /chrome/.test(ua) && !/edge/.test(ua);
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
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('<?= esc($appBase) ?>/sw.js')
        .then(reg => {
          console.log('[PWA] Service Worker registrado:', reg.scope);

          // Auto-atualização: verifica por atualizações periodicamente
          setInterval(() => {
            reg.update();
          }, 60000); // a cada 1 minuto

          // Detecta nova versão instalando
          reg.addEventListener('updatefound', () => {
            const newWorker = reg.installing;
            newWorker.addEventListener('statechange', () => {
              if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                // Nova versão disponível
                if (confirm('Nova versão disponível! Deseja atualizar agora?')) {
                  newWorker.postMessage({
                    type: 'SKIP_WAITING'
                  });
                  window.location.reload();
                }
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
          toast('success', 'Sincronização completa', `${count} ponto(s) enviado(s) com sucesso!`, []);
          updatePendingCount();
        }
      });
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
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const APP_BASE = (document.querySelector('meta[name="app-base"]').getAttribute('content') || '/').replace(/\/+$/, '');
    const ROOT_BASE = APP_BASE.replace(/\/public$/, '');

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
    const btnModalRetake = document.getElementById('btnModalRetake');
    const btnTogglePinModal = document.getElementById('btnTogglePinModal');
    const pinFormEl = document.getElementById('pinForm');
    const rememberPinModal = document.getElementById('rememberPinModal');
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

    // PIN (Etapa 2 - modal)
    let pinInput = document.getElementById('pinModal');
    let pinFeedback = document.getElementById('pinModalFeedback');
    let confirmStage = 'preview'; // preview -> confirmar PIN, confirm -> registrar ponto
    let previewData = null;
    let faceAuthMode = false; // true quando autenticado por reconhecimento facial (sem PIN)
    let finalConfirmModalInstance = null;
    let finalizeTimerId = null;
    let finalizeDeadline = null;

    if (window.PinRemember?.apply) {
      window.PinRemember.apply({ root: pinFormEl || document });
    }

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
    let faceApiReady = false;
    let capturedDescriptor = null;
    let capturedFaceMatch = null;
    let capturedFaceDistance = null;

    (async function loadFaceApi() {
      try {
        const modelUrl = (typeof APP_BASE !== 'undefined' ? APP_BASE : '') + '/models';
        await Promise.all([
          faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),
          faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelUrl),
          faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)
        ]);
        faceApiReady = true;
        console.log('[FaceAPI] Modelos carregados com sucesso');
      } catch (e) {
        console.warn('[FaceAPI] Erro ao carregar modelos:', e);
      }
    })();

    async function extractDescriptor(sourceEl) {
      if (!faceApiReady) return null;
      try {
        const det = await faceapi
          .detectSingleFace(sourceEl, new faceapi.TinyFaceDetectorOptions())
          .withFaceLandmarks(true)
          .withFaceDescriptor();
        return det?.descriptor ? Array.from(det.descriptor) : null;
      } catch (e) {
        console.warn('[FaceAPI] Erro na extração:', e);
        return null;
      }
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
        
        // Lista de servidores de tempo (prioriza servidor próprio)
        // Path relativo funciona em qualquer estrutura (raiz ou subpasta)
        // Se index.php está em /public/, então ../api/ sobe um nível
        const timeServers = [
          '../api/get_server_time.php', // Nosso servidor PHP (mais rápido e confiável)
          'https://worldtimeapi.org/api/timezone/America/Sao_Paulo',
          'https://timeapi.io/api/Time/current/zone?timeZone=America/Sao_Paulo'
        ];
        
        let serverTime = null;
        let latency = 0;
        let sourceUsed = null;
        
        // Tenta cada servidor até conseguir
        for (const server of timeServers) {
          try {
            const response = await fetch(server, { 
              signal: AbortSignal.timeout(3000) // 3s timeout
            });
            
            if (!response.ok) {
              console.warn('[HLB] Servidor retornou erro:', server, response.status);
              continue;
            }
            
            const data = await response.json();
            const endLocal = Date.now();
            latency = Math.floor((endLocal - startLocal) / 2);
            
            // Parseia resposta de acordo com a API
            if (data.datetime) {
              // Nossa API ou WorldTimeAPI
              serverTime = new Date(data.datetime);
              sourceUsed = data.source || 'worldtimeapi';
            } else if (data.dateTime) {
              // TimeAPI.io
              serverTime = new Date(data.dateTime);
              sourceUsed = 'timeapi.io';
            } else if (data.currentDateTime) {
              // WorldClockAPI
              serverTime = new Date(data.currentDateTime);
              sourceUsed = 'worldclockapi';
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
    
    function getCurrentHLBTime() {
      if (!hlbTime || !lastHlbSync) {
        return new Date(); // Fallback para hora local
      }
      
      // Calcula tempo decorrido desde última sincronização
      const elapsed = Date.now() - lastHlbSync.getTime();
      const current = new Date(hlbTime.getTime() + elapsed);
      
      return current;
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
          statusEl.title = 'Usando hora local';
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

          // Versão 2: estrutura já correta (csrf adicionado aos dados, não ao schema)
          // Nenhuma mudança de schema necessária
          
          // Versão 3: adicionar cache de PINs válidos
          if (oldVersion < 3) {
            if (!db.objectStoreNames.contains('validPins')) {
              const pinStore = db.createObjectStore('validPins', {
                keyPath: 'pin'
              });
              pinStore.createIndex('lastUsed', 'lastUsed', { unique: false });
            }
          }
        };
        req.onsuccess = (e) => {
          dbi = e.target.result;
          resolve(dbi);
        };
        req.onerror = () => reject(req.error);
      });
    }
    
    // Cache de PINs válidos para validação offline
    async function cachePinAsValid(pin, teacherName = null) {
      try {
        if (!dbi) await openDB();
        await new Promise((resolve, reject) => {
          const tx = dbi.transaction('validPins', 'readwrite');
          tx.objectStore('validPins').put({
            pin,
            teacherName,
            lastUsed: Date.now()
          });
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => reject(tx.error);
        });
        console.log('[PIN Cache] PIN válido armazenado:', pin);
      } catch (err) {
        console.warn('[PIN Cache] Falha ao cachear PIN:', err);
      }
    }
    
function getCachedTeacherId(pin) {
  if (!pin) return null;
  try {
    const cached = localStorage.getItem('pin_cache_' + pin);
    const parsed = cached ? parseInt(cached, 10) : NaN;
    return Number.isNaN(parsed) ? null : parsed;
  } catch (e) {
    console.warn('[PIN Cache] Erro ao ler cache:', e);
    return null;
  }
}

    async function isPinCached(pin) {
      try {
        if (!dbi) await openDB();
        const result = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('validPins', 'readonly');
          const req = tx.objectStore('validPins').get(pin);
          req.onsuccess = () => resolve(req.result);
          req.onerror = () => reject(req.error);
        });
        return result ? true : false;
      } catch {
        return false;
      }
    }
    
    async function getCachedPinInfo(pin) {
      try {
        if (!dbi) await openDB();
        const result = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('validPins', 'readonly');
          const req = tx.objectStore('validPins').get(pin);
          req.onsuccess = () => resolve(req.result);
          req.onerror = () => reject(req.error);
        });
        return result || null;
      } catch {
        return null;
      }
    }
    async function savePending(payload) {
      try {
        if (!dbi) await openDB();
        const success = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readwrite');
          tx.objectStore('pending').add({
            payload,
            csrf: csrf, // Armazena CSRF token junto
            createdAt: Date.now()
          });
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => reject(tx.error);
        });

        if (success) {
          // Registra Background Sync se disponível
          if ('serviceWorker' in navigator && 'sync' in navigator.serviceWorker) {
            try {
              const reg = await navigator.serviceWorker.ready;
              await reg.sync.register('sync-pending-points');
              console.log('[PWA] Background Sync registrado');
            } catch (err) {
              console.warn('[PWA] Background Sync não disponível:', err);
            }
          }

          // Atualiza contador
          updatePendingCount();
        }

        return success;
      } catch {
        return false;
      }
    }
    async function getPendingCount() {
      try {
        if (!dbi) await openDB();
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

      if (count > 0) {
        btnSync.classList.remove('d-none');
        syncCount.textContent = count;
      } else {
        btnSync.classList.add('d-none');
        syncCount.textContent = '0';
      }
    }

    let isSyncing = false;
    async function drainPending() {
      if (isSyncing) {
        console.log('[Sync] Já está sincronizando, aguarde...');
        return;
      }

      try {
        if (!dbi) await openDB();
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

        isSyncing = true;
        const count = all.length;
        console.log(`[Sync] Sincronizando ${count} ponto(s)...`, all);

        // Atualiza badge para "Sincronizando"
        const badge = document.getElementById('netBadge');
        if (badge) {
          badge.className = 'status-badge';
          badge.style.background = 'linear-gradient(135deg, #ffc107 0%, #ff9800 100%)';
          badge.style.color = '#000';
          badge.innerHTML = '<i class="bi bi-arrow-repeat spin"></i><span class="status-text">Sincronizando...</span>';
        }

        const items = all.map(x => x.payload);
        // Usa o CSRF token do primeiro item (ou o atual se todos falharem)
        const csrfToken = all[0]?.csrf || csrf;

        console.log('[Sync] Enviando para:', bulkUrl);
        console.log('[Sync] CSRF Token:', csrfToken ? '✅ Presente' : '❌ Ausente');
        console.log('[Sync] Payload:', {
          items
        });

        const res = await fetch(bulkUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
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
            if (errors.length > 0) {
              console.warn('[Sync] Alguns pontos falharam:', errors);
              toast('warning', 'Sincronização parcial', `${count - errors.length} de ${count} ponto(s) sincronizado(s).`, errors.map(e => e.response?.message || 'Erro desconhecido'));
            } else {
              console.log('[Sync] Todos os pontos sincronizados com sucesso!');
              toast('success', 'Sincronização completa', `${count} ponto(s) enviado(s) com sucesso!`, []);
            }
          } else {
            console.log('[Sync] Sincronização completa!');
            toast('success', 'Sincronização completa', `${count} ponto(s) enviado(s) com sucesso!`, []);
          }

          // Limpa pendências
          await new Promise((resolve, reject) => {
            const tx = dbi.transaction('pending', 'readwrite');
            tx.objectStore('pending').clear();
            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error);
          });

          updatePendingCount();
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
        // Restaura badge de rede
        updateNetBadge();
      }
    }

    // Listener de reconexão
    window.addEventListener('online', () => {
      console.log('[PWA] Conexão restaurada, iniciando sincronização...');
      setTimeout(drainPending, 1000);
    });

    // Inicializa contador ao carregar
    openDB().then(() => {
      updatePendingCount();
      drainPending();
    });

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
  const actionType = (() => {
    if (previewData?.action_key === 'out') return 'out';
    if (previewData?.action_key === 'in') return 'in';
    const actionLabel = (previewData?.action || '').toLowerCase();
    if (actionLabel.includes('saída')) return 'out';
    return 'in';
  })();
  if (finalConfirmAction) {
    finalConfirmAction.textContent = actionType === 'out' ? 'Saída' : 'Entrada';
    finalConfirmAction.classList.remove('text-success', 'text-danger');
    if (actionType === 'out') finalConfirmAction.classList.add('text-danger');
    else finalConfirmAction.classList.add('text-success');
  }
  const actionCard = document.getElementById('finalConfirmActionCard');
  const actionIconEl = document.getElementById('finalConfirmActionIcon');
  if (actionCard) {
    actionCard.className = 'review-summary-card h-100 alert mb-0';
    if (actionType === 'out') {
      actionCard.classList.add('alert-danger');
      if (actionIconEl) {
        actionIconEl.className = 'bi bi-box-arrow-right text-danger';
        actionIconEl.parentElement.classList.remove('bg-success-subtle', 'text-success');
        actionIconEl.parentElement.classList.add('bg-danger-subtle', 'text-danger');
      }
    } else {
      actionCard.classList.add('alert-success');
      if (actionIconEl) {
        actionIconEl.className = 'bi bi-box-arrow-in-right text-success';
        actionIconEl.parentElement.classList.remove('bg-danger-subtle', 'text-danger');
        actionIconEl.parentElement.classList.add('bg-success-subtle', 'text-success');
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
    if (btnConfirmLabel) btnConfirmLabel.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Registrar Ponto';
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
    btnConfirmLabel.innerHTML = '<i class="bi bi-search me-1"></i> Revisar ponto';
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
      ? 'Digite o PIN e clique em Revisar ponto para carregar os dados do colaborador.'
      : 'Dados carregados. Clique em Revisar ponto para verificar e registrar o lançamento.';
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
  previewData = null;
  capturedFaceMatch = null;
  capturedFaceDistance = null;
  faceAuthMode = false;
  const faceRow = document.getElementById('confirmFaceRow');
  if (faceRow) faceRow.style.display = 'none';
  const faceBadge = document.getElementById('capturedFaceBadge');
  if (faceBadge) faceBadge.style.display = 'none';
  const pinForm = document.getElementById('pinForm');
  if (pinForm) pinForm.style.display = '';
  if (btnConfirmSubmit) {
    btnConfirmSubmit.disabled = false;
    const lbl = btnConfirmSubmit.querySelector('.label');
    if (lbl) lbl.innerHTML = '<i class="bi bi-search me-1"></i> Revisar ponto';
  }
  if (confirmCollaboratorName) confirmCollaboratorName.textContent = '—';
  if (confirmCollaboratorAction) confirmCollaboratorAction.textContent = 'Digite seu PIN e clique em Revisar ponto para ver os detalhes.';
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

function maybeResetStageForPinError(code) {
  if (!code) return;
  const resetCodes = ['pin_invalid', 'pin_duplicate', 'collaborator_inactive', 'pin_required'];
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
        case 'pin_required':
          markInvalid(pinInput, pinFeedback, 'Informe os 6 números do seu PIN.');
          toast('warning', 'PIN obrigatório', 'Digite seu PIN para seguir.', ['Seu PIN tem 6 dígitos.']);
          break;
        case 'pin_invalid':
          markInvalid(pinInput, pinFeedback, 'PIN incorreto. Verifique e tente novamente.');
          toast('error', 'PIN incorreto', 'Confira os 6 números informados.', ['Dica: o PIN não possui letras.', 'Se esqueceu seu PIN, contate o Admin/RH.']);
          break;
        case 'collaborator_inactive':
          markInvalid(pinInput, pinFeedback, 'Seu cadastro está inativo. Procure o Admin/RH.');
          toast('warning', 'Colaborador inativo', 'Seu acesso está inativo no sistema.', ['Fale com o Admin/RH para regularizar seu cadastro.']);
          break;
        case 'pin_duplicate':
          markInvalid(pinInput, pinFeedback, 'Há duplicidade de PIN. Procure o Admin.');
          toast('warning', 'PIN duplicado', 'Encontramos mais de um colaborador com este PIN.', ['Peça ao Admin/RH para atualizar seu PIN.', 'Por segurança, o registro foi bloqueado.']);
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
        case 'photo_invalid':
          toast('warning', 'Foto inválida', msg, ['Faça uma nova captura com boa iluminação.']);
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

    // Mais tolerante e rápido para considerar "OK"
    function computeQuality(rect, cw, ch) {
      const cx = rect.x + rect.width / 2,
        cy = rect.y + rect.height / 2;
      const centerTol = Math.min(cw, ch) * 0.12; // antes ~0.06
      const dx = cx - (cw / 2),
        dy = cy - (ch / 2),
        dist = Math.hypot(dx, dy);
      const shortSide = Math.min(cw, ch);
      const desiredWidthMin = shortSide * 0.18; // antes ~0.22
      const desiredWidthMax = shortSide * 0.65; // antes ~0.50

      let message = '';
      let warn = false;

      if (rect.width < desiredWidthMin) {
        message = 'Aproxime o rosto';
        warn = true;
      } else if (rect.width > desiredWidthMax) {
        message = 'Afaste um pouco';
        warn = true;
      }

      if (dist > centerTol) {
        message = 'Centralize o rosto';
        warn = true;
      }

      const ok = !warn;
      return {
        ok,
        warn,
        message: ok ? 'Pronto! (captura automática)' : message
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
      ctx.fillText(q.message || '', cw / 2, ch - 14);
      ctx.restore();

      // Disparo de captura automática quando "ok" por ~600ms
      if (autoCaptureEnabled && stream && !capturedDataUrl && !document.body.classList.contains('modal-open')) {
        const now = performance.now();
        if (q.ok) {
          if (!lastOkTs) lastOkTs = now;
          if (now - lastOkTs > 600) { // janela curta e ágil
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
        stream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = stream;
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
    function generateDeviceFingerprint() {
      try {
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
        
        const components = [
          navigator.userAgent || '',
          navigator.language || '',
          screen.width + 'x' + screen.height,
          screen.colorDepth || '',
          new Date().getTimezoneOffset(),
          canvasHash,
          navigator.hardwareConcurrency || '',
          navigator.deviceMemory || ''
        ];
        
        // Simple hash
        let hash = 0;
        const str = components.join('|');
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
      
      // 1. Verifica se navigator.geolocation.mocked existe (Android)
      if (navigator.geolocation.mocked) {
        fraudIndicators.push('navigator_mocked_flag');
        mockDetected = true;
      }
      
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
          (pos) => {
            const fraudCheck = detectGpsMock(pos);
            resolve({
              lat: pos.coords.latitude,
              lng: pos.coords.longitude,
              acc: pos.coords.accuracy,
              fraudCheck: fraudCheck,
              deviceFingerprint: generateDeviceFingerprint()
            });
          },
          () => resolve(null), {
            enableHighAccuracy: true,
            timeout: 12000,
            maximumAge: 0
          }
        );
      });
    }
    async function ensureGeoForModal() {
      confirmLocEl.innerHTML = `<span class="status-chip warn"><span class="dot warn"></span> Solicitando permissão...</span>`;
      const geo = await getGeo();
      cachedGeo = geo;
      if (!geo) {
        confirmLocEl.innerHTML = `<span class="status-chip warn"><span class="dot warn"></span> Não autorizado/indisponível</span>`;
      } else {
        const acc = Math.round(geo.acc ?? 0);
        const cls = acc <= 50 ? 'ok' : 'warn';
        confirmLocEl.innerHTML = `<span class="status-chip ${cls}"><span class="dot ${cls}"></span> Precisão ~${acc}m</span>`;
      }
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

    function normalizeActionLabel(val) {
      const v = String(val || '').toLowerCase();
      if (['in', 'entrada', 'enter', 'checkin', 'entrada_1'].includes(v)) return 'Entrada';
      if (['out', 'saida', 'saída', 'exit', 'checkout', 'saida_1'].includes(v)) return 'Saída';
      return v ? v.charAt(0).toUpperCase() + v.slice(1) : '—';
    }

    function buildDetailsHTML(data) {
      const name = data.name || data.teacher?.name || data.employee_name || data.employee?.name || '';
      const acao = normalizeActionLabel(data.action) || '';
      const hora = data.time || new Date().toLocaleTimeString('pt-BR');
      const dataBR = data.date || new Date().toLocaleDateString('pt-BR');
      
      // Portaria 671/2021 - Exibir NSR (Número Sequencial de Registro)
      const nsrHTML = data.nsr ? `<div style="margin-top:0.5rem"><b>NSR:</b> ${data.nsr}</div>` : '';
      const recordModeHTML = data.record_mode ? 
        `<div><b>Modo:</b> ${data.record_mode === 'online' ? 'Online' : 'Offline (sincronizado)'}</div>` : '';
      
      return `
        <div style="font-size:2rem;font-weight:700">✅ Ponto registrado!</div>
        <div style="margin-top:1rem;font-size:1.1rem">
          ${acao ? `<div><b>Ponto:</b> ${escHtml(acao)}</div>` : ''}
          <div><b>Hora:</b> ${escHtml(hora)}</div>
          <div><b>Data:</b> ${escHtml(dataBR)}</div>
          ${name ? `<div><b>Nome:</b> ${escHtml(name)}</div>` : ''}
          ${nsrHTML}
          ${recordModeHTML}
        </div>
        <div style="margin-top:1.5rem;font-size:0.85rem;opacity:0.8;border-top:1px solid rgba(0,0,0,0.1);padding-top:1rem">
          <div>Sistema: DEEDO Ponto v1.0.0</div>
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
      else setTimeout(() => div.remove(), 3200);
    }

    // Badge de rede - atualiza badge do header
    function updateNetBadge() {
      console.log('[NetBadge] === INICIANDO ATUALIZAÇÃO ===');

      const badge = document.getElementById('netBadge');
      if (!badge) {
        console.log('[NetBadge] Elemento não encontrado');
        return;
      }

      const on = navigator.onLine;
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

    // Função para testar manualmente
    window.forceOffline = function() {
      console.log('[NetBadge] Forçando OFFLINE...');
      navigator.onLine = false;
      updateNetBadge();
    };

    window.forceOnline = function() {
      console.log('[NetBadge] Forçando ONLINE...');
      navigator.onLine = true;
      updateNetBadge();
    };

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

      // Preenche PIN salvo (se existir)
      const storedPin = window.PinRemember?.load?.();
      if (storedPin) {
        pinInput.value = storedPin;
        if (rememberPinModal) rememberPinModal.checked = true;
      } else {
        pinInput.value = '';
        if (rememberPinModal) rememberPinModal.checked = false;
      }
      resetConfirmStage();
      pinInput.classList.remove('is-invalid');
      pinFeedback.textContent = '';

      // Atualiza etapa visual e geolocalização
      goStepConfirmUI();
      ensureGeoForModal();

      // Mostra modal
      const modal = getConfirmModal();
      modal?.show();
      setTimeout(() => pinInput?.focus(), 150);
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

    // Toggle PIN visível (modal)
    btnTogglePinModal?.addEventListener('click', () => {
      const isPwd = pinInput.type === 'password';
      pinInput.type = isPwd ? 'text' : 'password';
      btnTogglePinModal.innerHTML = isPwd ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
      btnTogglePinModal.setAttribute('aria-label', isPwd ? 'Ocultar PIN' : 'Mostrar PIN');
      pinInput.focus();
    });

    // PIN UX mobile
    (function() {
      if (!pinInput) return;
      pinInput.setAttribute('inputmode', 'numeric');
      pinInput.setAttribute('enterkeyhint', 'done');
      pinInput.setAttribute('maxlength', '6');
      const sanitize = (v) => (v || '').replace(/\D/g, '').slice(0, 6);
      const dismissKeyboard = () => {
        try {
          pinInput.setSelectionRange(0, 0);
        } catch {}
        pinInput.blur();
        if (document.activeElement?.blur) document.activeElement.blur();
        const prev = pinInput.readOnly;
        pinInput.readOnly = true;
        setTimeout(() => pinInput.readOnly = prev, 50);
      };
      pinInput.addEventListener('input', () => {
        const clean = sanitize(pinInput.value);
        if (pinInput.value !== clean) pinInput.value = clean;
        if (clean.length === 6) dismissKeyboard();
        if (confirmStage !== 'preview') resetConfirmStage();
      });
      pinInput.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text') || '';
        const clean = sanitize(text);
        pinInput.value = clean;
        if (clean.length === 6) dismissKeyboard();
        if (confirmStage !== 'preview') resetConfirmStage();
      });
      confirmModalEl.addEventListener('shown.bs.modal', () => setTimeout(() => pinInput?.focus(), 120));
    })();

    // Submissão final (Etapa 2)
    async function runPreview() {
      if (!pinInput) return;
      const cpfValue = (document.getElementById('cpf')?.value || '').trim();
      const pin = (pinInput.value || '').trim();
      if (!/^\d{6}$/.test(pin)) {
        statusEl.textContent = 'Informe os 6 dígitos do PIN para continuar.';
        markInvalid(pinInput, pinFeedback, 'O PIN deve ter exatamente 6 números.');
        return;
      }

      setLoading(true, btnConfirmSubmit);
      try {
        statusEl.textContent = 'Validando PIN...';
        if (confirmCollaboratorAction) confirmCollaboratorAction.textContent = 'Validando PIN...';

        const payload = { pin, preview: true };
        if (cpfValue) payload.cpf = cpfValue;
        const cachedTeacherId = getCachedTeacherId(pin);
        if (cachedTeacherId) payload.cached_teacher_id = cachedTeacherId;

        const res = await fetch(apiUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf
          },
          body: JSON.stringify(payload)
        });

        const text = await res.text();
        let data = null;
        try {
          data = JSON.parse(text);
        } catch {}

        if (!res.ok || !data) {
          if (data && data.status === 'error') {
            handleApiError(res.status, data);
            maybeResetStageForPinError(data?.code);
          } else {
            toast('error', 'Falha na validação', 'Não foi possível validar o PIN.', ['Verifique sua conexão e tente novamente.']);
            statusEl.textContent = 'Não foi possível validar o PIN.';
          }
          return;
        }

        if (data.status === 'preview') {
          previewData = data;
          if (confirmCollaboratorName) confirmCollaboratorName.textContent = data.collaborator?.name || '—';
          if (confirmCollaboratorAction) {
            const label = data.message || (data.action ? `Próximo registro: ${data.action}` : 'Confirmado. Confira as informações.');
            confirmCollaboratorAction.textContent = label;
          }

          // --- Verificação de identidade facial ---
          const faceRow = document.getElementById('confirmFaceRow');
          const faceIcon = document.getElementById('confirmFaceIcon');
          const faceStatusEl = document.getElementById('confirmFaceStatus');
          capturedFaceMatch = null;
          capturedFaceDistance = null;
          faceRow.style.display = '';

          if (data.face_enrolled && capturedDescriptor) {
            faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-info-subtle text-info';
            faceIcon.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div>';
            faceStatusEl.textContent = 'Verificando identidade facial...';

            const result = compareFaces(capturedDescriptor, data.face_descriptors);
            capturedFaceMatch = result.match;
            capturedFaceDistance = result.distance;

            if (result.match === true) {
              const pct = Math.round((1 - result.distance) * 100);
              faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-success-subtle text-success';
              faceIcon.innerHTML = '<i class="bi bi-shield-check fs-6"></i>';
              faceStatusEl.innerHTML = '<span class="text-success fw-semibold">Identidade confirmada (' + pct + '% de compatibilidade)</span>';
            } else {
              faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-danger-subtle text-danger';
              faceIcon.innerHTML = '<i class="bi bi-shield-x fs-6"></i>';
              faceStatusEl.innerHTML = '<span class="text-danger fw-semibold">Identidade NÃO confirmada — o rosto não confere com o cadastro</span>';
              if (btnConfirmSubmit) btnConfirmSubmit.disabled = true;
            }
          } else if (data.face_enrolled && !capturedDescriptor) {
            faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning';
            faceIcon.innerHTML = '<i class="bi bi-exclamation-triangle fs-6"></i>';
            faceStatusEl.innerHTML = '<span class="text-warning fw-semibold">Não foi possível analisar o rosto na foto — identidade não verificada</span>';
          } else {
            faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning';
            faceIcon.innerHTML = '<i class="bi bi-person-exclamation fs-6"></i>';
            faceStatusEl.innerHTML = '<span class="text-warning fw-semibold">Colaborador sem cadastro facial — identidade não verificada</span><br><small class="text-muted">Solicite o cadastro facial ao administrador para ativar a verificação.</small>';
          }

          statusEl.textContent = data.message || 'PIN validado. Confira e confirme o registro.';
          setConfirmStage('confirm');
          openFinalizeModal();
        } else if (data.status === 'error') {
          handleApiError(res.status, data);
          maybeResetStageForPinError(data?.code);
        } else if (data.status === 'ok') {
          // Caso raro: API já registrou o ponto (preview ausente). Reaproveita fluxo existente.
          previewData = data;
          setConfirmStage('confirm');
          await submitPointWithPin();
        } else {
          toast('error', 'Retorno inesperado', 'Servidor retornou uma resposta desconhecida.', []);
          statusEl.textContent = 'Retorno inesperado do servidor.';
        }
      } catch (error) {
        console.error('[Preview] Erro ao validar PIN:', error);
        toast('error', 'Falha na validação', error?.message || 'Erro inesperado.', []);
        statusEl.textContent = 'Não foi possível validar o PIN.';
      } finally {
        setLoading(false, btnConfirmSubmit);
      }
    }

    async function submitPointWithPin(actionBtn = btnConfirmSubmit) {
      const actionButton = actionBtn || btnConfirmSubmit;
      const cpf = (document.getElementById('cpf').value || '').trim();
      const pin = (pinInput.value || '').trim();

      if (!faceAuthMode && (!pin || !/^\d{6}$/.test(pin))) {
        statusEl.textContent = 'Digite seu PIN de 6 dígitos.';
        markInvalid(pinInput, pinFeedback, 'O PIN deve ter exatamente 6 números.');
        return;
      }

      setLoading(true, actionButton);
      try {
        statusEl.textContent = 'Preparando foto...';

        // Foto deve existir (não capturamos aqui)
        if (!capturedDataUrl) {
          statusEl.textContent = 'Sem foto. Capture antes de registrar.';
          toast('warning', 'Foto necessária', 'Capture a foto antes de registrar o ponto.', []);
          setLoading(false, actionButton);
          return;
        }

        // Avaliação simples da foto
        let incomplete = false;
        const reasons = [];
        const MIN_W = 300,
          MIN_H = 300,
          MIN_BYTES = 10 * 1024;
        if (capturedDataUrl) {
          try {
            const meta = await getImageSizeFromDataUrl(capturedDataUrl);
            if (meta.width < MIN_W || meta.height < MIN_H || meta.bytes < MIN_BYTES) {
              incomplete = true;
              reasons.push('foto de baixa qualidade');
            }
          } catch {
            incomplete = true;
            reasons.push('falha ao analisar a foto');
          }
        } else {
          incomplete = true;
          reasons.push('foto ausente');
        }

        // Geo: usa cache do modal se existir
        statusEl.textContent = 'Obtendo localização...';
        const geo = cachedGeo || await getGeo();
        if (!geo) {
          incomplete = true;
          reasons.push('localização ausente');
        }

        // Portaria 671/2021 - Adicionar campos obrigatórios
        const recordMode = navigator.onLine ? 'online' : 'offline';
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
          cpf,
          photo: capturedDataUrl || null,
          geo: geo || null,
          incomplete,
          reasons,
          // Campos Portaria 671/2021
          recordMode,
          recordedAt,
          hlbOffsetSeconds,
          // Campos Anti-Fraude
          fraudCheck: geo?.fraudCheck || null,
          deviceFingerprint: geo?.deviceFingerprint || generateDeviceFingerprint(),
          // Reconhecimento facial
          face_match: capturedFaceMatch,
          face_distance: capturedFaceDistance
        };

        if (faceAuthMode && capturedDescriptor) {
          payload.face_descriptor = capturedDescriptor;
        } else {
          payload.pin = pin;
          const cachedTeacherId = getCachedTeacherId(pin);
          if (cachedTeacherId) payload.cached_teacher_id = cachedTeacherId;
        }

        perfMetrics.preparacao = performance.now() - perfMetrics.inicio;
        
        // Medir tempo de serialização JSON
        const serializacaoInicio = performance.now();
        const payloadJSON = JSON.stringify(payload);
        perfMetrics.serializacao = performance.now() - serializacaoInicio;
        
        const fotoTamanhoKB = capturedDataUrl ? Math.round((capturedDataUrl.length * 3/4) / 1024) : 0;
        const payloadTamanhoKB = Math.round(payloadJSON.length / 1024);
        
        console.log(`[Performance] Preparação: ${perfMetrics.preparacao.toFixed(2)}ms | Serialização: ${perfMetrics.serializacao.toFixed(2)}ms | Foto: ${fotoTamanhoKB}KB | Payload: ${payloadTamanhoKB}KB`);
        
        statusEl.textContent = `Enviando... (${payloadTamanhoKB}KB)`;
        let onlineOk = false,
          data = null;

        try {
          const uploadInicio = performance.now();
          const res = await fetch(apiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrf
            },
            body: payloadJSON
          });
          
          perfMetrics.upload = performance.now() - uploadInicio;
          console.log(`[Performance] Upload (request+response): ${perfMetrics.upload.toFixed(2)}ms`);

          const text = await res.text();
          try {
            data = JSON.parse(text);
          } catch {
            data = null;
          }

        if (!res.ok || !data || data.status !== 'ok') {
            handleApiError(res.status, data);
          maybeResetStageForPinError(data?.code);
            setLoading(false, actionButton);
            return;
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
              'Foto': `${fotoTamanhoKB}KB`,
              'Payload': `${payloadTamanhoKB}KB`
            });
          }

          // PIN validado com sucesso pelo servidor - cachear para uso offline
          await cachePinAsValid(pin, data.teacher?.name || null);
          
          // CACHE DE PIN: Salvar ID do colaborador para acelerar próximos registros
          if (data.teacher?.id || data.collaborator?.id) {
            try {
              const teacherId = data.teacher?.id || data.collaborator?.id;
              const cacheKey = 'pin_cache_' + pin;
              localStorage.setItem(cacheKey, teacherId.toString());
              console.log('[Cache] PIN salvo em cache:', teacherId);
              
              // Exibir status do cache se foi usado
              if (data.debug_performance?.cache_hit === true) {
                console.log('[Cache] ✅ CACHE HIT - Validação rápida!');
              } else if (data.debug_performance?.cache_hit === false) {
                console.log('[Cache] ⚠️ CACHE MISS - Busca completa realizada (cache salvo para próxima vez)');
              }
            } catch (e) {
              console.warn('[Cache] Erro ao salvar cache:', e);
            }
          }
          
          onlineOk = true;
        } catch (err) {
          // OFFLINE: Validar PIN usando cache local
          console.log('[Offline] Tentando validar PIN localmente...');
          
          const pinIsValid = await isPinCached(pin);
          
          if (!pinIsValid) {
            // PIN não está em cache - não pode validar offline
            statusEl.textContent = 'PIN não pode ser validado offline.';
            markInvalid(pinInput, pinFeedback, 'Este PIN nunca foi usado online neste dispositivo.');
            toast('warning', 'Validação offline impossível', 'Não é possível validar este PIN offline.', [
              '⚠️ Este PIN nunca foi usado com sucesso neste dispositivo.',
              '📡 Conecte à internet para validar um novo PIN.',
              '✅ PINs já usados funcionam offline.'
            ]);
            setLoading(false, actionButton);
            return;
          }
          
          // PIN está em cache - permitir registro offline
          const pinInfo = await getCachedPinInfo(pin);
          console.log('[Offline] PIN validado localmente:', pinInfo);
          
          await savePending(payload);
          data = {
            status: 'ok',
            action: 'offline',
            message: 'Sem conexão. Registro salvo e será sincronizado.',
            teacher: pinInfo
          };
        }

        const pendingNote = incomplete ?
          `<div style="margin-top:1rem"><b>Status:</b> Aguardando confirmação.<br><span class="fs-12">Motivo: ${escHtml(reasons.join(', ') || 'informações incompletas')}.</span></div>` :
          '';

        statusEl.textContent = onlineOk ?
          (incomplete ? 'Ponto salvo como aguardando confirmação.' : 'Ponto registrado com sucesso.') :
          (incomplete ? 'Ponto salvo! Será enviado quando conectar.' : 'Ponto salvo! Será enviado quando conectar.');

        // Fecha modal e mostra sucesso
        try {
          closeFinalizeModal();
          getConfirmModal()?.hide();
        } catch {}
        if (onlineOk) {
          const detailsHTML = buildDetailsHTML(data || {});
          showFullScreenAlert('success', detailsHTML + pendingNote, true);
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
              <div style="margin-top:0.3rem">🔐 PIN validado usando cache local.</div>
            </div>
          `;
          showFullScreenAlert('success', offlineHTML, false);
        }
      } catch (e) {
        statusEl.textContent = e?.message || 'Falha ao registrar.';
        toast('error', 'Erro inesperado', e?.message || 'Tente novamente', []);
      } finally {
        setLoading(false, actionButton);
        cachedGeo = null;
      }
    }

    // Ações do modal
    async function handleConfirmSubmit() {
      if (confirmStage === 'preview') {
        await runPreview();
      } else {
        openFinalizeModal();
      }
    }

    btnConfirmSubmit.addEventListener('click', handleConfirmSubmit);
    btnModalRetake.addEventListener('click', () => {
      try {
        getConfirmModal()?.hide();
      } catch {}
      btnRetake.click();
      resetConfirmStage();
    });
    // Enter no PIN envia
    pinInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleConfirmSubmit();
      }
    });

    btnFinalizeCancel?.addEventListener('click', () => {
      closeFinalizeModal();
    });

    btnFinalizeConfirm?.addEventListener('click', async () => {
      await submitPointWithPin(btnFinalizeConfirm);
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

    function setFsDetectionStatus(state) {
      if (!fsFaceStatus) return;
      fsFaceStatus.classList.remove('detected', 'no-face', 'loading');
      fsFaceStatus.classList.add('visible');
      if (state === 'detected') {
        fsFaceStatus.classList.add('detected');
        fsFaceIcon.innerHTML = '<i class="bi bi-crop"></i>';
        fsFaceText.textContent = 'Rosto enquadrado';
        if (fullscreenFaceRing) { fullscreenFaceRing.className = 'face-ring detected'; }
        if (fullscreenInstruction && !autoCaptureTimer) {
          fullscreenInstruction.textContent = 'Rosto enquadrado — preparando captura...';
          fullscreenInstruction.className = 'fs-instruction-float face-ok';
        }
      } else if (state === 'no-face') {
        fsFaceStatus.classList.add('no-face');
        fsFaceIcon.innerHTML = '<i class="bi bi-person-x"></i>';
        fsFaceText.textContent = 'Nenhum rosto encontrado';
        if (fullscreenFaceRing) { fullscreenFaceRing.className = 'face-ring no-face'; }
        if (fullscreenInstruction) {
          fullscreenInstruction.textContent = 'Centralize seu rosto na área indicada';
          fullscreenInstruction.className = 'fs-instruction-float face-warn';
        }
      } else {
        fsFaceStatus.classList.add('loading');
        fsFaceIcon.innerHTML = '<div class="spinner-border spinner-border-sm text-light" role="status"></div>';
        fsFaceText.textContent = 'Iniciando detecção facial...';
        if (fullscreenFaceRing) { fullscreenFaceRing.className = 'face-ring'; }
        if (fullscreenInstruction) {
          fullscreenInstruction.textContent = 'Centralize seu rosto na área indicada';
          fullscreenInstruction.className = 'fs-instruction-float';
        }
      }
    }

    let faceDetectionRunning = false;
    let autoCaptureCountdown = 0;
    let autoCaptureTimer = null;
    let autoCaptureFired = false;

    function startFaceDetectionLoop() {
      stopFaceDetectionLoop();
      autoCaptureFired = false;
      autoCaptureCountdown = 0;
      if (!faceApiReady) {
        setFsDetectionStatus('loading');
        faceDetectionTimer = setTimeout(startFaceDetectionLoop, 1000);
        return;
      }
      faceDetectionRunning = true;
      let consecutiveDetections = 0;
      const REQUIRED_CONSECUTIVE = 3;

      async function tick() {
        if (!faceDetectionRunning || !fullscreenStream || !isFullscreenMode || autoCaptureFired) return;
        try {
          const det = await faceapi.detectSingleFace(
            fullscreenVideo,
            new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.45 })
          );
          if (!faceDetectionRunning || !isFullscreenMode || autoCaptureFired) return;
          lastFaceDetected = !!det;

          if (det) {
            consecutiveDetections++;
            if (consecutiveDetections >= REQUIRED_CONSECUTIVE && !autoCaptureTimer) {
              setFsDetectionStatus('detected');
              startAutoCapture();
              return;
            } else if (!autoCaptureTimer) {
              setFsDetectionStatus('detected');
            }
          } else {
            consecutiveDetections = 0;
            cancelAutoCapture();
            setFsDetectionStatus('no-face');
          }
        } catch (e) {
          console.warn('[FaceDetection] tick error:', e);
        }
        if (faceDetectionRunning && isFullscreenMode && !autoCaptureFired) {
          faceDetectionTimer = setTimeout(tick, 400);
        }
      }
      faceDetectionTimer = setTimeout(tick, 300);
    }

    function startAutoCapture() {
      if (autoCaptureTimer || autoCaptureFired) return;
      autoCaptureCountdown = 3;
      updateAutoCaptureUI();

      autoCaptureTimer = setInterval(() => {
        autoCaptureCountdown--;
        if (autoCaptureCountdown <= 0) {
          clearInterval(autoCaptureTimer);
          autoCaptureTimer = null;
          autoCaptureFired = true;
          handleFullscreenCapture();
        } else {
          updateAutoCaptureUI();
        }
      }, 800);
    }

    function cancelAutoCapture() {
      if (autoCaptureTimer) {
        clearInterval(autoCaptureTimer);
        autoCaptureTimer = null;
      }
      autoCaptureCountdown = 0;
    }

    function updateAutoCaptureUI() {
      if (!fullscreenInstruction || !fsFaceText) return;
      fullscreenInstruction.textContent = 'Fotografando em ' + autoCaptureCountdown + '...';
      fullscreenInstruction.className = 'fs-instruction-float face-ok';
      fsFaceText.textContent = 'Fotografando em ' + autoCaptureCountdown + '...';
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

    async function enterFullscreenCamera() {
      isFullscreenMode = true;
      fullscreenCamera.classList.add('active');

      updateFsClock();
      fsClockInterval = setInterval(updateFsClock, 1000);
      setFsDetectionStatus('loading');

      try {
        const constraints = {
          audio: false,
          video: {
            facingMode: { ideal: currentFacing || 'user' },
            width: { ideal: 1280 },
            height: { ideal: 1280 }
          }
        };
        fullscreenStream = await navigator.mediaDevices.getUserMedia(constraints);
        fullscreenVideo.srcObject = fullscreenStream;
        await fullscreenVideo.play();
        startFaceDetectionLoop();
      } catch (err) {
        console.error('Fullscreen camera error:', err);
        exitFullscreenCamera();
        toast('error', 'Erro', 'Não foi possível abrir a câmera', []);
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
      fullscreenVideo.srcObject = null;

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
      const ctx = canvas.getContext('2d');

      if (currentFacing === 'user') {
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
      }

      ctx.drawImage(fullscreenVideo, 0, 0);

      stopFaceDetectionLoop();

      capturedDescriptor = null;
      capturedFaceMatch = null;
      capturedFaceDistance = null;
      faceAuthMode = false;
      if (faceApiReady) {
        capturedDescriptor = await extractDescriptor(canvas);
        console.log('[FaceAPI] Descriptor extraído:', capturedDescriptor ? 'sim' : 'não');
      }

      capturedDataUrl = downscaleToJpeg(canvas, 500, 0.6);
      exitFullscreenCamera();

      // Se temos descriptor, tentar identificação facial server-side
      if (capturedDescriptor) {
        await tryFaceIdentification();
      } else {
        openConfirmModal();
        showCapturedFaceBadge(false);
      }
      vibrate(30);
    }

    function showCapturedFaceBadge(hasDescriptor) {
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

    async function tryFaceIdentification() {
      const identifyUrl = (ROOT_BASE || '') + '/api/identify_face.php';
      try {
        const res = await fetch(identifyUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ face_descriptor: capturedDescriptor })
        });
        const data = await res.json();

        if (data.status === 'identified') {
          faceAuthMode = true;
          previewData = data;
          capturedFaceMatch = true;
          capturedFaceDistance = data.face_distance;

          openConfirmModal();
          showCapturedFaceBadge(true);

          if (confirmCollaboratorName) confirmCollaboratorName.textContent = data.collaborator?.name || '—';
          if (confirmCollaboratorAction) confirmCollaboratorAction.textContent = data.message || 'Identidade confirmada.';
          if (confirmCollaboratorRow) confirmCollaboratorRow.classList.remove('d-none');

          const faceRow = document.getElementById('confirmFaceRow');
          const faceIcon = document.getElementById('confirmFaceIcon');
          const faceStatusEl = document.getElementById('confirmFaceStatus');
          if (faceRow) {
            faceRow.style.display = '';
            faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-success-subtle text-success';
            faceIcon.innerHTML = '<i class="bi bi-shield-check fs-6"></i>';
            faceStatusEl.innerHTML = '<span class="text-success fw-semibold">Identidade confirmada (' + (data.face_confidence || 0) + '% de compatibilidade)</span>';
          }

          // Esconder PIN e ir direto para confirm
          const pinForm = document.getElementById('pinForm');
          if (pinForm) pinForm.style.display = 'none';
          setConfirmStage('confirm');

          if (btnConfirmSubmit) {
            btnConfirmSubmit.disabled = false;
            const lbl = btnConfirmSubmit.querySelector('.label');
            if (lbl) lbl.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Registrar Ponto';
          }

          console.log('[FaceID] Identificado:', data.collaborator?.name, '- confiança:', data.face_confidence + '%');
          return;
        }
      } catch (e) {
        console.warn('[FaceID] Erro na identificação:', e);
      }

      // Fallback: não identificado, pedir PIN
      faceAuthMode = false;
      openConfirmModal();
      showCapturedFaceBadge(true);

      const faceRow = document.getElementById('confirmFaceRow');
      const faceIcon = document.getElementById('confirmFaceIcon');
      const faceStatusEl = document.getElementById('confirmFaceStatus');
      if (faceRow) {
        faceRow.style.display = '';
        faceIcon.className = 'd-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning';
        faceIcon.innerHTML = '<i class="bi bi-person-exclamation fs-6"></i>';
        faceStatusEl.innerHTML = '<span class="text-warning fw-semibold">Rosto não identificado — digite seu PIN</span>';
      }
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
        fullscreenStream = await navigator.mediaDevices.getUserMedia(constraints);
        fullscreenVideo.srcObject = fullscreenStream;
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

    let homeMap = null;
    let userMarker = null;
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

    function initMap() {
      const defaultCenter = SCHOOLS.length ? [SCHOOLS[0].lat, SCHOOLS[0].lng] : [-7.69, -42.71];
      homeMap = L.map('homeMap', { zoomControl: false, attributionControl: false }).setView(defaultCenter, 15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(homeMap);

      const schoolIcon = L.divIcon({
        html: '<i class="bi bi-building" style="font-size:20px;color:#0d6efd"></i>',
        className: '', iconSize: [24, 24], iconAnchor: [12, 12]
      });
      for (const s of SCHOOLS) {
        const m = L.marker([s.lat, s.lng], { icon: schoolIcon }).addTo(homeMap);
        m.bindPopup('<b>' + s.name + '</b>');
      }
    }

    function startGeoWatch() {
      if (!('geolocation' in navigator)) return;

      const userIcon = L.divIcon({
        html: '<div style="display:flex;align-items:center;justify-content:center;width:40px;height:40px;position:relative">'
            + '<div style="position:absolute;width:40px;height:40px;background:rgba(13,110,253,.12);border-radius:50%;animation:pulse 2s ease-in-out infinite"></div>'
            + '<div style="width:28px;height:28px;background:#fff;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,.18);display:flex;align-items:center;justify-content:center;position:relative;z-index:1">'
            + '<svg width="16" height="16" viewBox="0 0 16 16" fill="#0d6efd"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"/></svg>'
            + '</div></div>',
        className: '', iconSize: [40, 40], iconAnchor: [20, 20]
      });

      geoWatchId = navigator.geolocation.watchPosition(
        (pos) => {
          const lat = pos.coords.latitude;
          const lng = pos.coords.longitude;
          if (!userMarker) {
            userMarker = L.marker([lat, lng], { icon: userIcon, zIndexOffset: 1000 }).addTo(homeMap);
            homeMap.setView([lat, lng], 16);
          } else {
            userMarker.setLatLng([lat, lng]);
          }
        },
        () => {},
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 }
      );
    }

    function showCamera() {
      if (typeof enterFullscreenCamera === 'function') {
        enterFullscreenCamera();
      }
    }

    function showHome() {
      homeScreen.style.display = 'block';
      homeScreen.style.animation = 'homeFadeIn .35s ease';
      setTimeout(() => { if (homeMap) homeMap.invalidateSize(); }, 100);
    }

    btnGoCheckin.addEventListener('click', showCamera);
    btnBackHome.addEventListener('click', showHome);

    updateHomeClock();
    setInterval(updateHomeClock, 1000);

    updateNetStatus();
    window.addEventListener('online', updateNetStatus);
    window.addEventListener('offline', updateNetStatus);

    initMap();
    startGeoWatch();
  })();
  </script>

  <!-- Bootstrap bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
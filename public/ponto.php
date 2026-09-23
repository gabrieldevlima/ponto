<?php
require_once __DIR__ . '/../config.php';

// Roda auto-migrações para garantir colunas PIN/trusted_devices
if (function_exists('run_auto_migrations')) {
    run_auto_migrations();
}

// No-cache para garantir que mudanças de CSS/JS inline sejam vistas imediatamente
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$csrfToken = csrf_token();
// API base path: a pasta /api/ esta na raiz do projeto. Remove o sufixo /public
// se presente em SCRIPT_NAME para que as URLs sejam absolutas e funcionem mesmo
// quando o ponto.php for acessado via /<projeto>/public/ponto.php.
$apiBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBasePath = preg_replace('#/public$#', '', $apiBasePath);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0066cc">
<meta name="app-build" content="<?= htmlspecialchars(APP_BUILD_ID, ENT_QUOTES, 'UTF-8') ?>">
<title>Bater Ponto</title>
<link rel="manifest" href="manifest.json">

<!-- PWA force-update: detecta versão antiga e recarrega com guards. Carrega cedo
     no <head> pra interceptar fetches do início do ciclo da página. -->
<script src="js/pwa-update.js"></script>
<!-- Apple Touch Icons (iOS Safari ignora manifest icons; usa apple-touch-icon) -->
<link rel="apple-touch-icon" sizes="180x180" href="img/icon-180x180.png">
<link rel="apple-touch-icon" sizes="152x152" href="img/icon-152x152.png">
<link rel="apple-touch-icon" sizes="120x120" href="img/icon-120x120.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Bater Ponto">
<style>
  :root {
    --primary: #0066cc;
    --primary-dark: #0052a3;
    --success: #16a34a;
    --danger: #dc2626;
    --warning: #ea9a16;
    --ink: #0f172a;
    --muted: #64748b;
    --surface: #ffffff;
    --bg: #f1f5f9;
    --border: #e2e8f0;
    --radius: 14px;
    --shadow: 0 2px 14px rgba(15,23,42,.08);
  }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: var(--bg);
    color: var(--ink);
    font-size: 17px;
    line-height: 1.4;
    /* iOS Safari: 100vh inclui a área da URL bar e corta conteúdo.
       100svh (small viewport) resolve em iOS 15.4+ / Chrome Android 108+.
       Fallback para 100vh em browsers antigos. */
    min-height: 100vh;
    min-height: 100dvh;
  }
  .container {
    max-width: 480px;
    margin: 0 auto;
    padding: 24px 20px 40px;
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
  }
  .brand {
    text-align: center;
    padding: 8px 0 20px;
  }
  .brand img { max-width: 140px; height: auto; }
  .brand .tag {
    color: var(--muted);
    font-size: 14px;
    margin-top: 4px;
  }
  .greeting {
    font-size: 22px;
    font-weight: 600;
    margin: 12px 0 20px;
    text-align: center;
  }
  .card {
    background: var(--surface);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 24px 20px;
    margin-bottom: 14px;
  }
  .card h1 {
    font-size: 22px;
    margin: 0 0 6px;
  }
  .card p {
    color: var(--muted);
    margin: 0 0 18px;
  }
  .btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    min-height: 64px;
    padding: 14px 20px;
    border: none;
    border-radius: var(--radius);
    background: var(--primary);
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.2;
    text-align: center;
    cursor: pointer;
    transition: transform .08s ease, background .15s ease, opacity .15s ease;
  }
  .btn:active { transform: scale(.98); }
  .btn:hover { background: var(--primary-dark); }
  .btn:disabled { opacity: .45; cursor: not-allowed; }
  .btn.secondary {
    background: #fff;
    color: var(--primary);
    border: 2px solid var(--primary);
    min-height: 56px;
  }
  .btn.secondary:hover { background: #eef4fb; }
  .btn.ghost {
    background: transparent;
    color: var(--primary);
    min-height: 48px;
    text-decoration: underline;
    font-weight: 500;
  }
  .btn.success { background: var(--success); }
  .btn.success:hover { background: #15803d; }
  .btn + .btn { margin-top: 10px; }
  .btn-bar { display: flex; gap: 10px; }
  .btn-bar .btn { margin: 0; }
  .input-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 8px;
  }
  .input {
    width: 100%;
    height: 56px;
    font-size: 18px;
    padding: 10px 14px;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    background: #fff;
    color: var(--ink);
  }
  .input:focus { outline: none; border-color: var(--primary); }
  .pin-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 8px;
    margin: 6px 0 16px;
  }
  .pin-grid input {
    width: 100%;
    height: 60px;
    text-align: center;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: 0;
    border: 2px solid var(--border);
    border-radius: 10px;
    background: #fff;
    color: var(--ink);
    padding: 0;
  }
  .pin-grid input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,102,204,.15);
  }
  .pin-show {
    font-family: "SFMono-Regular", Menlo, monospace;
    font-size: 48px;
    font-weight: 700;
    color: var(--primary);
    text-align: center;
    letter-spacing: 8px;
    padding: 20px 12px;
    background: #eef4fb;
    border-radius: var(--radius);
    margin: 14px 0;
    word-break: break-all;
  }
  .note {
    font-size: 14px;
    color: var(--muted);
    margin-top: 10px;
  }
  .alert {
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 15px;
    margin-bottom: 14px;
  }
  .alert-warning { background: #fef7e5; color: #8a5a06; border-left: 4px solid var(--warning); }
  .alert-danger { background: #fde6e6; color: #7a1717; border-left: 4px solid var(--danger); }
  .alert-success { background: #e3f6e8; color: #0b5e2b; border-left: 4px solid var(--success); }
  .screen { display: none; }
  .screen.active { display: block; animation: fadeIn .2s ease; }
  @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
  .toast {
    position: fixed;
    left: 50%;
    bottom: 24px;
    transform: translateX(-50%);
    background: #1f2937;
    color: #fff;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 15px;
    opacity: 0;
    transition: opacity .2s ease;
    z-index: 9999;
    max-width: 90%;
    text-align: center;
  }
  .toast.show { opacity: 1; }
  .toast.success { background: var(--success); }
  .toast.error { background: var(--danger); }
  .toast.warning { background: var(--warning); color: #fff; }
  .back-link {
    color: var(--muted);
    background: transparent;
    border: none;
    font-size: 15px;
    padding: 0 0 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .back-link:hover { color: var(--ink); }
  .success-icon {
    width: 100px;
    height: 100px;
    margin: 6px auto 16px;
    border-radius: 50%;
    background: var(--success);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 62px;
    line-height: 1;
  }
  .camera-wrap {
    width: 100%;
    max-width: 320px;
    margin: 0 auto 12px;
    aspect-ratio: 1;
    border-radius: var(--radius);
    overflow: hidden;
    background: #000;
    position: relative;
  }
  .camera-wrap video { width: 100%; height: 100%; object-fit: cover; display: block; }
  .stepup-msg {
    text-align: center;
    font-size: 16px;
    color: var(--ink);
    margin-bottom: 14px;
  }
  .spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,.65);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    flex-shrink: 0;
    vertical-align: middle;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  .btn.is-loading {
    pointer-events: none;
    cursor: wait;
  }
  .btn.is-loading .spinner {
    margin-right: 10px;
  }
  .btn .btn-label {
    display: inline-block;
    vertical-align: middle;
    line-height: 1;
  }
  .row-between {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    font-size: 14px;
    color: var(--muted);
    margin-top: 6px;
  }
</style>
</head>
<body>
<div class="container">
  <div class="brand">
    <img src="img/logo.png" alt="DEEDO Ponto" onerror="this.style.display='none'">
    <div class="tag">Sistema de Ponto Eletrônico</div>
  </div>

  <!-- SCREEN: HOME -->
  <div class="screen active" id="screen-home">
    <div class="greeting">Olá! 👋</div>
    <div class="card">
      <button class="btn" id="btn-home-punch">BATER PONTO</button>
      <button class="btn secondary" id="btn-home-first">Primeiro acesso • Gerar meu PIN</button>
      <button class="btn ghost" id="btn-home-forgot">Esqueci meu PIN</button>
    </div>
    <div id="home-last-punch" class="note" style="text-align:center;"></div>
  </div>

  <!-- SCREEN: PIN INPUT -->
  <div class="screen" id="screen-pin">
    <button class="back-link" data-back="home">&larr; Voltar</button>
    <div class="card">
      <h1>Digite seu PIN</h1>
      <p>Digite seu CPF e seu PIN de 6 dígitos para bater o ponto.</p>
      <label class="input-label" for="pin-cpf">CPF</label>
      <input class="input" id="pin-cpf" type="tel" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
      <label class="input-label" for="pin-digits" style="margin-top:12px;">PIN</label>
      <div class="pin-grid" id="pin-digits">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
        <input type="tel" inputmode="numeric" maxlength="1" autocomplete="off">
      </div>
      <button class="btn" id="btn-pin-submit" disabled>BATER PONTO</button>
      <button class="btn ghost" data-go="forgot">Esqueci meu PIN</button>
    </div>
  </div>

  <!-- SCREEN: FIRST ACCESS (CPF only, optional face on next step) -->
  <div class="screen" id="screen-first">
    <button class="back-link" data-back="home">&larr; Voltar</button>
    <div class="card">
      <h1>Primeiro acesso</h1>
      <p>Digite seu CPF. Nós vamos gerar seu PIN pessoal.</p>
      <label class="input-label" for="first-cpf">CPF</label>
      <input class="input" id="first-cpf" type="tel" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
      <button class="btn" id="btn-first-submit" style="margin-top:16px;" disabled>CONTINUAR</button>
    </div>
  </div>

  <!-- SCREEN: FORGOT / RECOVER -->
  <div class="screen" id="screen-forgot">
    <button class="back-link" data-back="home">&larr; Voltar</button>
    <div class="card">
      <h1>Esqueci meu PIN</h1>
      <p>Digite seu CPF. Se tudo estiver em ordem, vamos gerar um novo PIN na hora.</p>
      <label class="input-label" for="forgot-cpf">CPF</label>
      <input class="input" id="forgot-cpf" type="tel" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
      <button class="btn" id="btn-forgot-submit" style="margin-top:16px;" disabled>RECUPERAR PIN</button>
    </div>
  </div>

  <!-- SCREEN: STEPUP (face validation) -->
  <div class="screen" id="screen-stepup">
    <button class="back-link" data-back="home">&larr; Cancelar</button>
    <div class="card">
      <h1>🔒 Validação extra</h1>
      <p class="stepup-msg" id="stepup-msg">Vamos confirmar com sua foto — é rápido.</p>
      <div class="camera-wrap" id="camera-wrap" style="display:none;">
        <video id="stepup-video" autoplay playsinline muted></video>
      </div>
      <button class="btn" id="btn-stepup-start">ABRIR CÂMERA</button>
      <button class="btn success" id="btn-stepup-capture" style="display:none;">CONFIRMAR FOTO</button>
      <button class="btn ghost" data-back="home">Cancelar</button>
    </div>
  </div>

  <!-- SCREEN: SELF-ENROLL (auto-cadastro facial em contexto confiável) -->
  <div class="screen" id="screen-selfenroll">
    <button class="back-link" data-back="pin">&larr; Voltar</button>
    <div class="card">
      <h1>📸 Cadastrar seu rosto</h1>
      <p id="selfenroll-msg">Seu cadastro ainda não tem foto. Vamos tirar 3 fotos agora para você poder bater ponto.</p>
      <div class="note" style="margin: 8px 0 14px;">
        <strong>Foto <span id="selfenroll-n">1</span> de 3</strong>
        — <span id="selfenroll-hint">olhe para a câmera</span>
      </div>
      <div class="camera-wrap" id="selfenroll-wrap" style="display:none;">
        <video id="selfenroll-video" autoplay playsinline muted></video>
      </div>
      <button class="btn" id="btn-selfenroll-start">ABRIR CÂMERA</button>
      <button class="btn success" id="btn-selfenroll-capture" style="display:none;">CAPTURAR FOTO</button>
      <button class="btn ghost" data-back="pin">Cancelar</button>
    </div>
  </div>

  <!-- SCREEN: PIN SHOW (generated) -->
  <div class="screen" id="screen-pin-show">
    <div class="card">
      <div class="success-icon">✓</div>
      <h1 style="text-align:center;" id="pin-show-title">Tudo certo!</h1>
      <p style="text-align:center;">Seu PIN é:</p>
      <div class="pin-show" id="pin-show-value">••••••</div>
      <div class="btn-bar">
        <button class="btn secondary" id="btn-pin-copy" style="flex:1;">📋 Copiar</button>
      </div>
      <div class="alert alert-warning" style="margin-top:14px;">
        ⚠ Anote agora. Este PIN não será mostrado novamente.
      </div>
      <button class="btn" id="btn-pin-confirm">OK, JÁ ANOTEI</button>
    </div>
  </div>

  <!-- SCREEN: SUCCESS -->
  <div class="screen" id="screen-success">
    <div class="card">
      <div class="success-icon">✓</div>
      <h1 style="text-align:center;" id="success-title">Ponto registrado!</h1>
      <div style="text-align:center; font-size:18px; margin: 12px 0 8px;" id="success-action-time"></div>
      <div style="text-align:center; color: var(--muted);" id="success-date"></div>
      <div class="row-between" style="justify-content:center; margin-top:14px; gap: 14px;">
        <span>Nome: <strong id="success-name">—</strong></span>
      </div>
      <div class="row-between" style="justify-content:center;">
        <span>NSR: <strong id="success-nsr">—</strong></span>
      </div>
      <div id="success-warning" class="alert alert-warning" style="display:none; margin-top:14px;"></div>
      <div class="btn-bar" style="margin-top:16px;">
        <button class="btn secondary" id="btn-success-receipt" style="flex:1;">VER COMPROVANTE</button>
      </div>
      <button class="btn ghost" data-back="home">Concluir</button>
    </div>
  </div>

  <!-- SCREEN: BLOCKED -->
  <div class="screen" id="screen-blocked">
    <div class="card">
      <h1 style="color: var(--danger);">Precisamos de ajuda</h1>
      <p id="blocked-msg">Não foi possível concluir sua solicitação. Procure o administrador para apoio.</p>
      <button class="btn" data-back="home">VOLTAR</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<!-- Modal compartilhado (regularizacao de checkout esquecido) -->
<script src="js/checkout_regularize.js"></script>

<!-- face-api.js (carregado sob demanda, só quando step-up é necessário) -->
<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const API_BASE = <?= json_encode($apiBasePath) ?>;
const API = {
  pinEnroll:       API_BASE + '/api/pin_enroll.php',
  pinRecover:      API_BASE + '/api/pin_recover.php',
  checkin:         API_BASE + '/api/checkin.php',
  selfEnrollFace:  API_BASE + '/api/self_enroll_face.php',
  regularizeCheckout: API_BASE + '/api/regularize_checkout.php',
};

// Inicializa o modal compartilhado de regularizacao de checkout com o CSRF da pagina.
if (window.CheckoutRegularize) {
  window.CheckoutRegularize.init({ csrfToken: CSRF_TOKEN, endpoint: API.regularizeCheckout });
}

// ---------- Utils ----------
function $(s, root=document) { return root.querySelector(s); }
function $$(s, root=document) { return Array.from(root.querySelectorAll(s)); }
function showScreen(name) {
  // Sempre que sair da tela "pin-show", apaga o PIN em claro da memória
  // (defesa contra back-swipe/back-button deixar a variável acessível via DevTools).
  if (name !== 'pin-show') window.__lastPinPlain = null;
  $$('.screen').forEach(s => s.classList.remove('active'));
  const el = $('#screen-' + name);
  if (el) el.classList.add('active');
  window.scrollTo({ top: 0, behavior: 'instant' });
}
function toast(msg, kind='') {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast show ' + kind;
  clearTimeout(window.__toastT);
  window.__toastT = setTimeout(() => t.classList.remove('show'), 3200);
}
function cleanDigits(v) { return (v || '').replace(/\D/g, ''); }
function maskCpf(v) {
  v = cleanDigits(v).slice(0, 11);
  return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
}
function formatDateBR(d) {
  const meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  const dt = d ? new Date(d.replace(' ', 'T')) : new Date();
  return `${dt.getDate()} de ${meses[dt.getMonth()]}, ${dt.getFullYear()}`;
}
function formatTimeBR(d) {
  const dt = d ? new Date(d.replace(' ', 'T')) : new Date();
  return dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}
function buildDeviceFingerprint() {
  // Fonte canônica: localStorage.ponto_device_fp_v1, mesmo gerador de
  // login.php e index.php (pinGetStableDeviceFp).
  //
  // Importante: NÃO recalcular a partir de screen.width/height aqui — em
  // mobile, rotação altera as dimensões e gerava FP diferente a cada
  // request, fazendo o servidor tratar o aparelho como novo a cada
  // check-in (e exigir face step-up infinitamente em produção).
  try {
    const cached = localStorage.getItem('ponto_device_fp_v1');
    if (cached && cached.length >= 8) return cached;
  } catch (_) {}
  // Fallback: usa orientation-independent dims (sempre min/max para
  // ser estável entre portrait/landscape) e cacheia.
  const w = screen.width || 0, h = screen.height || 0;
  const stableDims = Math.min(w, h) + 'x' + Math.max(w, h);
  const parts = [
    navigator.userAgent || '',
    (navigator.platform || ''),
    stableDims,
    (Intl.DateTimeFormat().resolvedOptions().timeZone || ''),
    (navigator.language || ''),
  ];
  const fp = 'ponto_' + hashString(parts.join('|'));
  try { localStorage.setItem('ponto_device_fp_v1', fp); } catch (_) {}
  return fp;
}
function hashString(s) {
  // FNV-1a 32 bits (não-criptográfico — o servidor faz SHA-256 depois).
  let h = 0x811c9dc5;
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return ('00000000' + (h >>> 0).toString(16)).slice(-8);
}
function getGeo() {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve(null);
    const t = setTimeout(() => resolve(null), 6000);
    navigator.geolocation.getCurrentPosition(
      pos => {
        clearTimeout(t);
        resolve({
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          acc: pos.coords.accuracy
        });
      },
      () => { clearTimeout(t); resolve(null); },
      { enableHighAccuracy: true, timeout: 5000, maximumAge: 30000 }
    );
  });
}

// ---------- CPF masks ----------
['pin-cpf','first-cpf','forgot-cpf'].forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('input', () => {
    el.value = maskCpf(el.value);
    validateInputs();
  });
});

// ---------- PIN grid ----------
const pinInputs = $$('#pin-digits input');
pinInputs.forEach((inp, idx) => {
  inp.addEventListener('input', () => {
    inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
    if (inp.value && idx < pinInputs.length - 1) {
      pinInputs[idx + 1].focus();
    }
    validateInputs();
  });
  inp.addEventListener('keydown', (e) => {
    if (e.key === 'Backspace' && !inp.value && idx > 0) {
      pinInputs[idx - 1].focus();
    }
  });
  inp.addEventListener('paste', (e) => {
    e.preventDefault();
    const txt = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, pinInputs.length);
    // Sempre preenche a partir do primeiro input vazio (ou do zero se todos estão preenchidos),
    // independente de onde o usuário tocou para colar.
    let startIdx = pinInputs.findIndex(i => !i.value);
    if (startIdx === -1) startIdx = 0;
    for (let i = 0; i < txt.length && (startIdx + i) < pinInputs.length; i++) {
      pinInputs[startIdx + i].value = txt[i];
    }
    const lastIdx = Math.min(startIdx + txt.length, pinInputs.length - 1);
    pinInputs[lastIdx].focus();
    validateInputs();
  });
});
function collectPin() { return pinInputs.map(i => i.value || '').join(''); }
function resetPinInputs() { pinInputs.forEach(i => i.value = ''); }

function validateInputs() {
  const pinCpfOk = cleanDigits($('#pin-cpf').value).length === 11;
  const pinOk = collectPin().length === 6;
  $('#btn-pin-submit').disabled = !(pinCpfOk && pinOk);

  const firstOk = cleanDigits($('#first-cpf').value).length === 11;
  $('#btn-first-submit').disabled = !firstOk;

  const forgotOk = cleanDigits($('#forgot-cpf').value).length === 11;
  $('#btn-forgot-submit').disabled = !forgotOk;
}

// ---------- Navigation ----------
$$('[data-back]').forEach(b => b.addEventListener('click', () => {
  stopStepupCamera();
  if (typeof stopSelfEnrollCamera === 'function') stopSelfEnrollCamera();
  // Limpa state transiente que não deve vazar entre colaboradores no mesmo device
  window.__stepupNonce = null;
  window.__pendingSelfEnroll = null;
  if (typeof selfEnrollDescriptors !== 'undefined') selfEnrollDescriptors = [];
  showScreen(b.getAttribute('data-back'));
}));
$$('[data-go]').forEach(b => b.addEventListener('click', () => showScreen(b.getAttribute('data-go'))));
$('#btn-home-punch').addEventListener('click', () => {
  resetPinInputs();
  showScreen('pin');
  setTimeout(() => $('#pin-cpf').focus(), 50);
});
$('#btn-home-first').addEventListener('click', () => {
  $('#first-cpf').value = '';
  validateInputs();
  showScreen('first');
  setTimeout(() => $('#first-cpf').focus(), 50);
});
$('#btn-home-forgot').addEventListener('click', () => {
  $('#forgot-cpf').value = '';
  validateInputs();
  showScreen('forgot');
  setTimeout(() => $('#forgot-cpf').focus(), 50);
});

// ---------- API wrappers ----------
function setBtnLoading(btn, label) {
  if (btn.dataset.originalHtml == null) btn.dataset.originalHtml = btn.innerHTML;
  btn.classList.add('is-loading');
  btn.setAttribute('aria-busy', 'true');
  btn.innerHTML =
    '<span class="spinner" aria-hidden="true"></span>'
    + '<span class="btn-label">' + label + '</span>';
}
function clearBtnLoading(btn) {
  btn.classList.remove('is-loading');
  btn.removeAttribute('aria-busy');
  if (btn.dataset.originalHtml != null) {
    btn.innerHTML = btn.dataset.originalHtml;
    delete btn.dataset.originalHtml;
  }
}

async function apiPost(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify(body)
  });
  let data = null;
  try { data = await res.json(); } catch (_) { data = { status: 'error', message: 'Resposta inválida do servidor.' }; }
  return { http: res.status, data };
}

// ---------- Check-in via PIN ----------
async function submitPin(faceDescriptor=null, stepupNonce=null) {
  const cpf = cleanDigits($('#pin-cpf').value);
  const pin = collectPin();
  const btn = $('#btn-pin-submit');
  setBtnLoading(btn, 'Registrando...');

  const geo = await getGeo();
  const payload = {
    cpf, pin,
    deviceFingerprint: buildDeviceFingerprint(),
    geo,
    recordMode: navigator.onLine ? 'online' : 'offline',
    recordedAt: new Date().toISOString().slice(0,19).replace('T',' ')
  };
  if (faceDescriptor) payload.face_descriptor = faceDescriptor;
  if (stepupNonce) payload.stepup_nonce = stepupNonce;

  const { data } = await apiPost(API.checkin, payload);

  clearBtnLoading(btn);
  validateInputs();

  // Pontos orfaos: API bloqueou a batida. Mostra modal pedindo regularizacao
  // ou confirmacao explicita pra prosseguir mesmo assim.
  if (data && data.code === 'orphan_punch_pending' && Array.isArray(data.orphans)) {
    showOrphanModal(data.orphans, payload);
    return;
  }

  if (data.status === 'require_face') {
    // step-up
    window.__pendingPinPayload = payload;
    window.__stepupNonce = data.stepup_nonce || null;
    $('#stepup-msg').textContent = data.message || 'Vamos confirmar com sua foto.';
    showScreen('stepup');
    return;
  }
  if (data.status === 'ok') {
    showSuccess(data);
    resetPinInputs();
    return;
  }

  const code = data.code || '';
  if (code === 'require_face_enroll') {
    // Auto-cadastro facial em contexto confiável
    startSelfEnrollFlow({
      cpf, pin, geo,
      deviceFingerprint: payload.deviceFingerprint,
      teacherName: (data.teacher && data.teacher.name) || ''
    });
    return;
  }
  if (code === 'pin_not_set') {
    toast('Você ainda não tem PIN. Toque em "Primeiro acesso".', 'warning');
    return;
  }
  if (code === 'pin_invalid') {
    toast('PIN incorreto. Verifique os números.', 'error');
    resetPinInputs();
    pinInputs[0].focus();
    return;
  }
  if (code === 'cpf_invalid') {
    toast(data.message || 'CPF inválido.', 'error');
    return;
  }
  if (code === 'too_many_attempts') {
    toast(data.message || 'Muitas tentativas. Tente mais tarde.', 'warning');
    return;
  }
  if (code === 'blocked_contact_admin') {
    $('#blocked-msg').textContent = data.message || 'Procure o administrador para apoio.';
    showScreen('blocked');
    return;
  }
  if (code === 'stepup_face_not_matched') {
    toast('Foto não reconhecida. Tente novamente em boa iluminação.', 'error');
    showScreen('stepup');
    return;
  }
  if (code === 'already_checked_in' || code === 'no_open_checkin') {
    toast(data.message || 'Operação não permitida.', 'warning');
    showSuccess({
      action: 'info',
      message: data.message || '',
      approved: false,
      teacher: { name: '' },
      nsr: '—',
      time: ''
    }, true);
    return;
  }
  toast(data.message || 'Erro ao registrar. Tente novamente.', 'error');
}

$('#btn-pin-submit').addEventListener('click', () => submitPin());

// ---------- Primeiro acesso ----------
async function submitFirstAccess(faceDescriptor=null) {
  const cpf = cleanDigits($('#first-cpf').value);
  const btn = $('#btn-first-submit');
  setBtnLoading(btn, 'Verificando...');

  const payload = {
    cpf,
    deviceFingerprint: buildDeviceFingerprint()
  };
  if (faceDescriptor) payload.face_descriptor = faceDescriptor;

  const { data } = await apiPost(API.pinEnroll, payload);
  clearBtnLoading(btn);
  validateInputs();

  if (data.status === 'require_face') {
    window.__pendingFirstCpf = cpf;
    window.__pendingFlow = 'first';
    $('#stepup-msg').textContent = data.message || 'Vamos confirmar com sua foto antes de gerar o PIN.';
    showScreen('stepup');
    return;
  }
  if (data.status === 'ok') {
    showPin(data.pin, 'Seu PIN foi gerado!');
    return;
  }

  const code = data.code || '';
  if (code === 'pin_already_set') {
    toast(data.message || 'Você já tem um PIN. Use "Esqueci meu PIN".', 'warning');
    return;
  }
  if (code === 'self_enroll_not_allowed') {
    $('#blocked-msg').textContent = data.message || 'Procure o administrador para liberar seu primeiro PIN.';
    showScreen('blocked');
    return;
  }
  if (code === 'teacher_not_found' || code === 'cpf_invalid') {
    toast(data.message || 'CPF não encontrado.', 'error');
    return;
  }
  if (code === 'too_many_attempts') {
    toast(data.message || 'Muitas tentativas. Aguarde.', 'warning');
    return;
  }
  if (code === 'face_not_matched') {
    toast('Foto não reconhecida. Tente novamente em boa iluminação.', 'error');
    showScreen('stepup');
    return;
  }
  toast(data.message || 'Erro inesperado.', 'error');
}
$('#btn-first-submit').addEventListener('click', () => submitFirstAccess());

// ---------- Esqueci meu PIN ----------
async function submitForgot(faceDescriptor=null) {
  const cpf = cleanDigits($('#forgot-cpf').value);
  const btn = $('#btn-forgot-submit');
  setBtnLoading(btn, 'Verificando...');

  const geo = await getGeo();
  const payload = {
    cpf,
    deviceFingerprint: buildDeviceFingerprint(),
    geo
  };
  if (faceDescriptor) payload.face_descriptor = faceDescriptor;

  const { data } = await apiPost(API.pinRecover, payload);
  clearBtnLoading(btn);
  validateInputs();

  if (data.status === 'require_face') {
    window.__pendingForgotCpf = cpf;
    window.__pendingFlow = 'forgot';
    $('#stepup-msg').textContent = data.message || 'Vamos confirmar com sua foto para gerar um novo PIN.';
    showScreen('stepup');
    return;
  }
  if (data.status === 'ok') {
    showPin(data.pin, 'Seu novo PIN é:');
    return;
  }

  const code = data.code || '';
  if (code === 'blocked_contact_admin') {
    $('#blocked-msg').textContent = data.message || 'Procure o administrador para gerar um novo PIN.';
    showScreen('blocked');
    return;
  }
  if (code === 'face_not_matched') {
    toast('Foto não reconhecida. Tente novamente em boa iluminação.', 'error');
    showScreen('stepup');
    return;
  }
  if (code === 'too_many_attempts') {
    toast(data.message || 'Muitas tentativas. Aguarde alguns minutos.', 'warning');
    return;
  }
  toast(data.message || 'Não foi possível recuperar o PIN.', 'error');
}
$('#btn-forgot-submit').addEventListener('click', () => submitForgot());

// ---------- PIN show ----------
function showPin(pin, title) {
  $('#pin-show-title').textContent = title || 'Seu PIN:';
  $('#pin-show-value').textContent = (pin || '').split('').join(' ');
  window.__lastPinPlain = pin;
  showScreen('pin-show');
}
$('#btn-pin-copy').addEventListener('click', async () => {
  const pin = window.__lastPinPlain;
  if (!pin) return;
  try {
    await navigator.clipboard.writeText(pin);
    toast('PIN copiado', 'success');
  } catch (_) {
    toast('Não foi possível copiar. Anote o PIN manualmente.', 'warning');
  }
});
$('#btn-pin-confirm').addEventListener('click', () => {
  window.__lastPinPlain = null;
  showScreen('home');
});

// ---------- Success ----------
function mapPendingReasonLabel(reason) {
  const key = String(reason || '').toLowerCase().trim();
  const normalized = key.normalize ? key.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : key;
  const labels = {
    out_of_radius: 'Você estava fora da área permitida da instituição no momento da batida.',
    out_of_perimeter: 'Você estava fora da área permitida da instituição no momento da batida.',
    no_gps: 'Não conseguimos confirmar sua localização no momento da batida.',
    no_location: 'Não conseguimos confirmar sua localização no momento da batida.',
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

// Modal de pontos orfaos — entrada sem saida detectada pelo backend.
// Acao primaria: "Regularizar saida" (abre o form diretamente).
// Acao secundaria (link): "Registrar mesmo assim" (reenvia com acknowledge_orphan=true).
// Se houver 1 orfao SEM solicitacao pendente, abre o form de regularizacao
// direto, sem etapa intermediaria (caso comum: usuario esqueceu a saida ontem).
function showOrphanModal(orphans, originalPayload) {
  // Se 1 orfao sem solicitacao pendente: abre o form direto. Pula a lista.
  if (Array.isArray(orphans) && orphans.length === 1 && !orphans[0].pending_regularization) {
    openRegularizeForOrphan(orphans[0], originalPayload);
    return;
  }
  // Caso geral: lista de orfaos (ou alguns ja com solicitacao pendente).
  const fmtDate = (ymd) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd||''); return m ? (m[3]+'/'+m[2]+'/'+m[1]) : (ymd||''); };
  const fmtTime = (dt)  => { const m = /(\d{2}):(\d{2})/.exec(dt||''); return m ? (m[1]+':'+m[2]) : '--:--'; };

  let wrap = document.getElementById('orphan-punch-modal');
  if (wrap) wrap.remove();
  wrap = document.createElement('div');
  wrap.id = 'orphan-punch-modal';
  wrap.style.cssText = 'position:fixed;inset:0;z-index:9000;display:flex;align-items:center;justify-content:center;';
  wrap.innerHTML = [
    '<div class="opm-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.55);"></div>',
    '<div class="opm-card" style="position:relative;background:#fff;border-radius:10px;padding:20px 22px;max-width:520px;width:92%;box-shadow:0 18px 50px rgba(0,0,0,0.3);font-family:inherit;max-height:90vh;overflow-y:auto;">',
    '  <h3 style="margin:0 0 8px;color:#b91c1c;font-size:1.1rem;"><span aria-hidden="true">&#9888;</span> Sua nova batida NAO foi registrada</h3>',
    '  <p style="margin:0 0 14px;font-size:0.95rem;color:#1f2937;background:#fff7ed;border:1px solid #fed7aa;padding:10px 12px;border-radius:6px;">Voce tem ponto(s) sem saida registrada. Regularize abaixo para continuar.</p>',
    '  <ul class="opm-list" style="margin:0 0 14px;padding:0;list-style:none;"></ul>',
    '  <div class="opm-actions" style="display:flex;flex-direction:column;gap:8px;margin-top:14px;">',
    '    <button type="button" class="opm-continue" style="background:transparent;color:#b45309;border:1px solid #f59e0b;border-radius:6px;padding:9px 16px;cursor:pointer;font-size:0.92rem;">Registrar nova entrada mesmo assim (deixa o ponto antigo para o admin)</button>',
    '    <button type="button" class="opm-cancel" style="background:transparent;color:#666;border:0;padding:6px;cursor:pointer;font-size:0.88rem;text-decoration:underline;">Cancelar</button>',
    '  </div>',
    '</div>',
  ].join('\n');
  document.body.appendChild(wrap);

  const list = wrap.querySelector('.opm-list');
  orphans.forEach((o) => {
    const li = document.createElement('li');
    li.style.cssText = 'border:1px solid #e3e8ee;border-radius:8px;padding:12px;margin-bottom:8px;background:#fafbfc;';
    const dateBR = fmtDate(o.date);
    const timeStr = fmtTime(o.check_in);
    const isPending = !!o.pending_regularization;

    // Texto descritivo construido com createElement/textContent — evita XSS via school_name
    // (que poderia conter HTML malicioso vindo da tabela schools).
    const infoDiv = document.createElement('div');
    infoDiv.style.cssText = 'font-size:0.95rem;color:#222;margin-bottom:8px;';
    const strongDate = document.createElement('strong');
    strongDate.textContent = dateBR;
    infoDiv.appendChild(strongDate);
    infoDiv.appendChild(document.createTextNode(' — entrada as '));
    const strongTime = document.createElement('strong');
    strongTime.textContent = timeStr;
    infoDiv.appendChild(strongTime);
    if (o.school_name) {
      infoDiv.appendChild(document.createTextNode(' · ' + o.school_name));
    }
    li.appendChild(infoDiv);

    if (isPending) {
      const pendingDiv = document.createElement('div');
      pendingDiv.style.cssText = 'font-size:0.85rem;color:#1a3a5c;background:#e7f1ff;padding:8px 10px;border-radius:5px;';
      pendingDiv.textContent = '⏳ Regularizacao ja solicitada — aguardando analise do administrador.';
      li.appendChild(pendingDiv);
    } else {
      const fixBtn = document.createElement('button');
      fixBtn.type = 'button';
      fixBtn.className = 'opm-fix';
      fixBtn.style.cssText = 'display:block;width:100%;background:#16a34a;color:#fff;border:0;border-radius:6px;padding:11px 16px;cursor:pointer;font-size:0.95rem;font-weight:600;';
      fixBtn.textContent = 'Regularizar saida deste ponto';
      fixBtn.addEventListener('click', () => openRegularizeForOrphan(o, originalPayload));
      li.appendChild(fixBtn);
    }
    list.appendChild(li);
  });

  const backdrop = wrap.querySelector('.opm-backdrop');
  const cancelBtn = wrap.querySelector('.opm-cancel');
  const continueBtn = wrap.querySelector('.opm-continue');
  function close() { if (wrap && wrap.parentNode) wrap.remove(); }
  backdrop.onclick = close;
  cancelBtn.onclick = close;
  continueBtn.onclick = async () => {
    close();
    const newPayload = Object.assign({}, originalPayload, { acknowledge_orphan: true });
    const { data } = await apiPost(API.checkin, newPayload);
    if (data && data.status === 'ok') { showSuccess(data); return; }
    toast((data && data.message) || 'Erro ao registrar. Tente novamente.', 'error');
  };
}

// Abre o form de regularizacao para um orfao especifico, vinculando o sucesso
// ao retry da batida original.
function openRegularizeForOrphan(orphan, originalPayload) {
  if (!window.CheckoutRegularize) {
    toast('Erro: modulo de regularizacao nao carregado.', 'error');
    return;
  }
  const modal = document.getElementById('orphan-punch-modal');
  if (modal) modal.remove();
  window.CheckoutRegularize.open({
    attendanceId: orphan.attendance_id,
    date: orphan.date,
    checkIn: orphan.check_in,
    schoolName: orphan.school_name,
    onSuccess: () => {
      toast('Solicitacao de regularizacao enviada! Tentando registrar sua batida...', 'success');
      retryAfterOrphanHandled(originalPayload);
    },
  });
}

// Refaz a chamada de checkin sem a flag acknowledge — para que o backend
// reavalie a presenca de orfaos pendentes apos uma regularizacao.
async function retryAfterOrphanHandled(originalPayload) {
  const { data } = await apiPost(API.checkin, originalPayload);
  if (data && data.code === 'orphan_punch_pending' && Array.isArray(data.orphans)) {
    showOrphanModal(data.orphans, originalPayload);
    return;
  }
  if (data.status === 'ok') { showSuccess(data); return; }
  toast(data.message || 'Erro ao registrar. Tente novamente.', 'error');
}

function showSuccess(data, isInfo=false) {
  const title = isInfo ? 'Aviso' : 'Ponto registrado!';
  $('#success-title').textContent = title;
  const actionLabel = (data.action || '').toString();
  const timeStr = data.time ? formatTimeBR(data.time) : '';
  $('#success-action-time').textContent = actionLabel && timeStr
    ? `${actionLabel.charAt(0).toUpperCase() + actionLabel.slice(1)} • ${timeStr}`
    : (data.message || '');
  $('#success-date').textContent = data.time ? formatDateBR(data.time) : '';
  $('#success-name').textContent = data?.teacher?.name || '—';
  $('#success-nsr').textContent = data.nsr ? ('#' + data.nsr) : '—';

  if (Array.isArray(data.pending_reasons)) {
    data.pending_reasons = data.pending_reasons.map(mapPendingReasonLabel);
  }

  const warn = $('#success-warning');
  if (data.approved === false && !isInfo) {
    warn.style.display = 'block';
    warn.textContent = 'Seu ponto foi registrado, mas aguarda revisão: ' +
      (Array.isArray(data.pending_reasons) && data.pending_reasons.length ? data.pending_reasons.join(', ') : 'pendências identificadas');
  } else {
    warn.style.display = 'none';
  }

  const rec = $('#btn-success-receipt');
  if (data.attendance_id) {
    rec.style.display = 'block';
    rec.onclick = () => window.open('receipt.php?id=' + encodeURIComponent(data.attendance_id), '_blank');
  } else {
    rec.style.display = 'none';
  }

  showScreen('success');
}

// ---------- Step-up (face) ----------
let faceApiReady = false;
let stepupStream = null;

// Mapeia DOMException de getUserMedia para mensagem específica que ensine o usuário
// o que fazer. Necessário porque mensagens genéricas em iOS Safari deixam o usuário
// sem entender que precisa ir em Ajustes para liberar a câmera.
function gumErrorMessage(e) {
  const ua = (navigator.userAgent || '').toLowerCase();
  const isIOS = /iphone|ipad|ipod/.test(ua) || (ua.includes('mac') && navigator.maxTouchPoints > 1);
  switch (e && e.name) {
    case 'NotAllowedError':
    case 'PermissionDeniedError':
      return isIOS
        ? 'Permissão de câmera negada. Vá em Ajustes → Safari → Câmera e permita o acesso.'
        : 'Permissão de câmera negada. Clique no ícone de cadeado ao lado do endereço e permita a câmera.';
    case 'NotReadableError':
    case 'AbortError':
    case 'TrackStartError':
      return 'Câmera ocupada. Feche outros apps que possam estar usando-a (Zoom, FaceTime, WhatsApp).';
    case 'OverconstrainedError':
    case 'ConstraintNotSatisfiedError':
      return 'A câmera deste aparelho não suporta a configuração necessária.';
    case 'NotFoundError':
    case 'DevicesNotFoundError':
      return 'Nenhuma câmera foi encontrada neste aparelho.';
    case 'SecurityError':
      return 'Acesso à câmera bloqueado por segurança. Verifique se o site está em HTTPS.';
    default:
      return 'Não foi possível acessar a câmera. Tente novamente ou contate o suporte.';
  }
}
async function loadFaceApi() {
  if (faceApiReady) return;
  if (!window.faceapi) {
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = './js/face-api.min.js';
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }
  const MODEL_URL = 'models';
  await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
  await faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODEL_URL);
  await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
  faceApiReady = true;
}
async function startStepupCamera() {
  try {
    stepupStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 640 } },
      audio: false
    });
    const v = $('#stepup-video');
    v.srcObject = stepupStream;
    await v.play();
    $('#camera-wrap').style.display = 'block';
    $('#btn-stepup-start').style.display = 'none';
    $('#btn-stepup-capture').style.display = 'block';
    // Pré-carrega modelos em paralelo
    loadFaceApi().catch(() => toast('Falha ao carregar módulo de reconhecimento.', 'error'));
  } catch (e) {
    toast(gumErrorMessage(e), 'error');
  }
}
function stopStepupCamera() {
  if (stepupStream) {
    stepupStream.getTracks().forEach(t => t.stop());
    stepupStream = null;
  }
  $('#btn-stepup-capture').style.display = 'none';
  $('#btn-stepup-start').style.display = 'block';
  $('#camera-wrap').style.display = 'none';
}
// iOS Safari pode resolver `play()` antes do primeiro frame estar pronto, fazendo
// detectSingleFace() rodar contra um frame preto. Espera readyState>=2 (HAVE_CURRENT_DATA).
async function waitForVideoFrame(video, timeoutMs = 1500) {
  if (video.readyState >= 2) return;
  await new Promise((resolve) => {
    const t = setTimeout(resolve, timeoutMs);
    video.addEventListener('loadeddata', () => { clearTimeout(t); resolve(); }, { once: true });
  });
  // Um requestAnimationFrame extra no iOS para garantir frame desenhado
  await new Promise(r => requestAnimationFrame(r));
}

async function captureFaceDescriptor() {
  await loadFaceApi();
  const v = $('#stepup-video');
  await waitForVideoFrame(v);
  const det = await faceapi.detectSingleFace(v, new faceapi.TinyFaceDetectorOptions({ inputSize: 320 }))
    .withFaceLandmarks(true)
    .withFaceDescriptor();
  return det && det.descriptor ? Array.from(det.descriptor) : null;
}
$('#btn-stepup-start').addEventListener('click', () => startStepupCamera());
$('#btn-stepup-capture').addEventListener('click', async () => {
  const btn = $('#btn-stepup-capture');
  setBtnLoading(btn, 'Confirmando...');
  let descriptor = null;
  try {
    descriptor = await captureFaceDescriptor();
  } catch (e) {
    toast('Falha ao analisar foto. Tente novamente.', 'error');
  }
  clearBtnLoading(btn);

  if (!descriptor || descriptor.length !== 128) {
    toast('Não conseguimos ver seu rosto. Centralize e tente novamente.', 'warning');
    return;
  }

  // Continua o fluxo original
  stopStepupCamera();
  if (window.__pendingFlow === 'first') {
    await submitFirstAccess(descriptor);
  } else if (window.__pendingFlow === 'forgot') {
    await submitForgot(descriptor);
  } else {
    await submitPin(descriptor, window.__stepupNonce || null);
    window.__stepupNonce = null;
  }
});

// ---------- Self-enroll (auto-cadastro facial em contexto confiável) ----------
let selfEnrollStream = null;
let selfEnrollDescriptors = [];
const SELFENROLL_HINTS = [
  'olhe para a câmera',
  'vire levemente a cabeça',
  'pequeno sorriso'
];

function startSelfEnrollFlow(ctx) {
  window.__pendingSelfEnroll = ctx;
  selfEnrollDescriptors = [];
  $('#selfenroll-n').textContent = '1';
  $('#selfenroll-hint').textContent = SELFENROLL_HINTS[0];
  $('#btn-selfenroll-start').style.display = 'block';
  $('#btn-selfenroll-capture').style.display = 'none';
  $('#selfenroll-wrap').style.display = 'none';
  showScreen('selfenroll');
}

async function startSelfEnrollCamera() {
  try {
    selfEnrollStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 640 } },
      audio: false
    });
    const v = $('#selfenroll-video');
    v.srcObject = selfEnrollStream;
    await v.play();
    $('#selfenroll-wrap').style.display = 'block';
    $('#btn-selfenroll-start').style.display = 'none';
    $('#btn-selfenroll-capture').style.display = 'block';
    loadFaceApi().catch(() => toast('Falha ao carregar módulo de reconhecimento.', 'error'));
  } catch (e) {
    toast(gumErrorMessage(e), 'error');
  }
}

function stopSelfEnrollCamera() {
  if (selfEnrollStream) {
    selfEnrollStream.getTracks().forEach(t => t.stop());
    selfEnrollStream = null;
  }
  $('#btn-selfenroll-capture').style.display = 'none';
  $('#btn-selfenroll-start').style.display = 'block';
  $('#selfenroll-wrap').style.display = 'none';
}

async function captureSelfEnrollDescriptor() {
  await loadFaceApi();
  const v = $('#selfenroll-video');
  await waitForVideoFrame(v);
  const det = await faceapi.detectSingleFace(v, new faceapi.TinyFaceDetectorOptions({ inputSize: 320 }))
    .withFaceLandmarks(true)
    .withFaceDescriptor();
  return det && det.descriptor ? Array.from(det.descriptor) : null;
}

function euclideanDist(a, b) {
  let s = 0;
  for (let i = 0; i < a.length; i++) {
    const d = a[i] - b[i];
    s += d * d;
  }
  return Math.sqrt(s);
}

$('#btn-selfenroll-start').addEventListener('click', () => startSelfEnrollCamera());

$('#btn-selfenroll-capture').addEventListener('click', async () => {
  const btn = $('#btn-selfenroll-capture');
  setBtnLoading(btn, 'Analisando...');
  let descriptor = null;
  try {
    descriptor = await captureSelfEnrollDescriptor();
  } catch (e) {
    toast('Falha ao analisar foto. Tente novamente.', 'error');
  }
  clearBtnLoading(btn);

  if (!descriptor || descriptor.length !== 128) {
    toast('Não vi seu rosto. Centralize e tente de novo.', 'warning');
    return;
  }

  // Valida diversidade local: rejeita se muito parecida com alguma anterior
  for (const prev of selfEnrollDescriptors) {
    if (euclideanDist(prev, descriptor) < 0.10) {
      toast('Foto muito parecida com a anterior. Mude um pouco o ângulo.', 'warning');
      return;
    }
  }

  selfEnrollDescriptors.push(descriptor);
  const n = selfEnrollDescriptors.length;

  if (n < 3) {
    $('#selfenroll-n').textContent = String(n + 1);
    $('#selfenroll-hint').textContent = SELFENROLL_HINTS[n];
    toast(`Foto ${n} de 3 capturada!`, 'success');
    return;
  }

  // 3 fotos capturadas → envia ao servidor
  stopSelfEnrollCamera();
  await submitSelfEnroll();
});

async function submitSelfEnroll() {
  const ctx = window.__pendingSelfEnroll || {};
  const { data, http } = await apiPost(API.selfEnrollFace, {
    cpf: ctx.cpf,
    pin: ctx.pin,
    geo: ctx.geo,
    deviceFingerprint: ctx.deviceFingerprint,
    descriptors: selfEnrollDescriptors
  });

  if (data.status === 'ok') {
    const lastDescriptor = selfEnrollDescriptors[selfEnrollDescriptors.length - 1];
    selfEnrollDescriptors = [];
    window.__pendingSelfEnroll = null;
    toast('Cadastro facial concluído! Registrando seu ponto...', 'success');
    showScreen('pin');
    await submitPin(lastDescriptor, data.stepup_nonce || null);
    return;
  }

  // Falhas
  const code = data.code || '';
  selfEnrollDescriptors = [];

  if (code === 'samples_not_diverse') {
    toast(data.message || 'Fotos muito parecidas. Vamos começar de novo.', 'warning');
    // Reinicia fluxo para foto 1
    $('#selfenroll-n').textContent = '1';
    $('#selfenroll-hint').textContent = SELFENROLL_HINTS[0];
    $('#btn-selfenroll-start').style.display = 'block';
    $('#btn-selfenroll-capture').style.display = 'none';
    return;
  }
  if (code === 'face_duplicate_active') {
    $('#blocked-msg').textContent = data.message || 'Este rosto já está vinculado a outro colaborador. Procure o administrador.';
    showScreen('blocked');
    return;
  }
  if (code === 'context_not_trusted') {
    $('#blocked-msg').textContent = data.message || 'Auto-cadastro só pode ser feito na unidade. Procure o administrador.';
    showScreen('blocked');
    return;
  }
  if (code === 'already_enrolled') {
    toast('Sua foto já foi cadastrada. Toque em BATER PONTO novamente.', 'success');
    showScreen('pin');
    return;
  }
  if (code === 'pin_invalid' || code === 'pin_not_set') {
    toast(data.message || 'PIN incorreto.', 'error');
    resetPinInputs();
    showScreen('pin');
    if (pinInputs && pinInputs[0]) pinInputs[0].focus();
    return;
  }
  if (code === 'too_many_attempts') {
    toast(data.message || 'Muitas tentativas. Aguarde alguns minutos.', 'warning');
    showScreen('pin');
    return;
  }

  toast(data.message || 'Erro ao cadastrar foto. Tente novamente.', 'error');
  showScreen('pin');
}

// ---------- Offline awareness + foreground drain da fila ----------
// iOS Safari não suporta Background Sync API; o evento `sync` do SW nunca dispara.
// Para que check-ins offline desta tela (kiosk-style) sejam re-enviados, postamos
// SYNC_NOW para o SW ao ficar online ou ao reabrir a aba (visibilitychange).
function triggerFgSync() {
  if (!navigator.onLine) return;
  if (navigator.serviceWorker && navigator.serviceWorker.controller) {
    try { navigator.serviceWorker.controller.postMessage({ type: 'SYNC_NOW' }); } catch (_) {}
  }
}

window.addEventListener('offline', () => toast('Sem internet. Alguns recursos podem não funcionar.', 'warning'));
window.addEventListener('online',  () => {
  toast('Conectado novamente.', 'success');
  setTimeout(triggerFgSync, 1000);
});

let _ptVisDrainTimer = null;
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') return;
  if (!navigator.onLine) return;
  if (_ptVisDrainTimer) clearTimeout(_ptVisDrainTimer);
  _ptVisDrainTimer = setTimeout(() => { _ptVisDrainTimer = null; triggerFgSync(); }, 500);
});

// ---------- PWA ----------
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').then(() => {
    // Dispara um drain inicial após o SW assumir controle, caso haja fila pendente
    // de sessões anteriores (ex: kiosk reaberto após queda de internet).
    setTimeout(triggerFgSync, 1500);
  }).catch(() => {});
}

// Init
validateInputs();
</script>
    <?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>

<?php
require_once __DIR__ . '/../../config.php';
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pdo = db();
$stmt = $pdo->prepare("SELECT id, name, email FROM teachers WHERE id = ?");
$stmt->execute([$id]);
$teacher = $stmt->fetch();
if (!$teacher) {
    flash_redirect('error', 'Colaborador não encontrado.', 'teachers.php');
}
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$appBase = preg_replace('#/public(?:/.*)?$#', '', $scriptDir);
if ($appBase === '') { $appBase = '/'; }
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Cadastro Facial — <?= esc($teacher['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <meta name="app-base" content="<?= esc($appBase) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/admin.css">
  <style>
    :root {
      --ring-color-ok: #22c55e;
      --ring-color-wait: #f59e0b;
      --ring-color-none: rgba(255,255,255,0.35);
    }

    body { background: #0f172a; color: #f1f5f9; }
    .navbar { background: #1e293b !important; border-bottom: 1px solid #334155; }
    .navbar-brand, .nav-link, .btn-outline-secondary, .btn-outline-danger { color: #cbd5e1 !important; border-color: #475569 !important; }

    .capture-card {
      background: #1e293b;
      border-radius: 20px;
      overflow: hidden;
      border: 1px solid #334155;
    }

    /* ── Área da câmera ── */
    .video-wrap {
      position: relative;
      width: 100%;
      aspect-ratio: 3/4;
      background: #000;
      overflow: hidden;
      border-radius: 16px;
    }
    @media (min-width: 576px) {
      .video-wrap { aspect-ratio: 4/3; }
    }

    #video {
      width: 100%; height: 100%;
      object-fit: cover;
      display: block;
    }

    /* Anel oval guia */
    .face-ring {
      position: absolute;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      width: 55%;
      aspect-ratio: 3/4;
      border-radius: 50%;
      border: 3px solid var(--ring-color-none);
      box-shadow: 0 0 0 0 transparent;
      transition: border-color .35s, box-shadow .35s;
      pointer-events: none;
    }
    .face-ring.detected {
      border-color: var(--ring-color-ok);
      box-shadow: 0 0 20px 4px rgba(34,197,94,.45),
                  inset 0 0 20px 2px rgba(34,197,94,.15);
      animation: ringPulse 1.5s ease-in-out infinite;
    }
    .face-ring.capturing {
      border-color: var(--ring-color-wait);
      box-shadow: 0 0 22px 6px rgba(245,158,11,.5),
                  inset 0 0 20px 2px rgba(245,158,11,.18);
    }
    @keyframes ringPulse {
      0%, 100% { box-shadow: 0 0 18px 3px rgba(34,197,94,.4), inset 0 0 18px 1px rgba(34,197,94,.12); }
      50%       { box-shadow: 0 0 28px 8px rgba(34,197,94,.6), inset 0 0 24px 4px rgba(34,197,94,.22); }
    }

    /* Flash de captura */
    .flash-overlay {
      position: absolute; inset: 0;
      background: #fff;
      opacity: 0;
      pointer-events: none;
      transition: opacity .07s;
    }
    .flash-overlay.flash { opacity: 0.75; }

    /* Instrução flutuante */
    .instruction-pill {
      position: absolute;
      bottom: 16px; left: 50%;
      transform: translateX(-50%);
      background: rgba(15,23,42,.78);
      backdrop-filter: blur(8px);
      color: #f1f5f9;
      padding: 8px 18px;
      border-radius: 999px;
      font-size: .82rem;
      font-weight: 600;
      white-space: nowrap;
      max-width: 90%;
      text-align: center;
      transition: background .3s;
      border: 1px solid rgba(255,255,255,.12);
    }
    .instruction-pill.ok   { background: rgba(22,101,52,.88); }
    .instruction-pill.warn { background: rgba(120,53,15,.88); }

    /* Barra de progresso de amostras */
    .samples-bar-wrap {
      background: #0f172a;
      border-radius: 12px;
      padding: 20px 24px;
    }
    .sample-dots {
      display: flex; gap: 10px; justify-content: center; margin-bottom: 12px;
    }
    .dot {
      width: 16px; height: 16px;
      border-radius: 50%;
      background: #334155;
      transition: background .35s, transform .25s;
    }
    .dot.filled {
      background: #22c55e;
      transform: scale(1.15);
    }
    .dot.active {
      background: #f59e0b;
      transform: scale(1.2);
      box-shadow: 0 0 8px rgba(245,158,11,.6);
    }

    /* Status / mensagens */
    .status-msg {
      font-size: .9rem;
      color: #94a3b8;
      text-align: center;
      min-height: 22px;
    }
    .status-msg.ok   { color: #4ade80; }
    .status-msg.warn { color: #fbbf24; }
    .status-msg.err  { color: #f87171; }

    /* Loader de modelos */
    .model-loader {
      display: flex; align-items: center; gap: 10px;
      color: #94a3b8; font-size: .88rem;
    }
    .spinner-sm {
      width: 18px; height: 18px;
      border: 2px solid #334155;
      border-top-color: #38bdf8;
      border-radius: 50%;
      animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* Success overlay */
    .success-overlay {
      position: absolute; inset: 0;
      background: rgba(15,23,42,.92);
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      gap: 14px;
      opacity: 0; pointer-events: none;
      transition: opacity .4s;
      border-radius: 16px;
    }
    .success-overlay.show { opacity: 1; pointer-events: auto; }
    .success-icon { font-size: 4rem; color: #4ade80; animation: popIn .5s ease-out; }
    @keyframes popIn {
      0%   { transform: scale(0) rotate(-30deg); opacity: 0; }
      70%  { transform: scale(1.2) rotate(5deg); }
      100% { transform: scale(1) rotate(0deg); opacity: 1; }
    }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg mb-4">
  <div class="container">
    <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-shield-check me-1"></i>Admin</a>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="teachers.php"><i class="bi bi-people me-1"></i>Colaboradores</a>
      <a class="btn btn-outline-danger btn-sm" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Sair</a>
    </div>
  </div>
</nav>

<div class="container" style="max-width:680px;">

  <!-- Cabeçalho -->
  <div class="mb-4">
    <a href="teacher_edit.php?id=<?= (int)$teacher['id'] ?>" class="text-decoration-none text-secondary small">
      <i class="bi bi-arrow-left me-1"></i>Voltar ao cadastro
    </a>
    <h4 class="mt-2 mb-0 fw-bold text-white">
      <i class="bi bi-person-bounding-box me-2 text-info"></i>Cadastro Facial
    </h4>
    <p class="text-secondary mb-0"><?= esc($teacher['name']) ?></p>
  </div>

  <div class="capture-card p-3 p-md-4">

    <!-- Área da câmera -->
    <div class="video-wrap mb-3">
      <video id="video" autoplay muted playsinline></video>
      <div class="face-ring" id="faceRing"></div>
      <div class="flash-overlay" id="flashOverlay"></div>
      <div class="instruction-pill" id="instructionPill">
        <span class="spinner-sm d-inline-block me-2" id="loaderSpinner"></span>
        <span id="instructionText">Carregando modelos...</span>
      </div>
      <div class="success-overlay" id="successOverlay">
        <div class="success-icon"><i class="bi bi-person-check-fill"></i></div>
        <div class="text-white fw-bold fs-5">Cadastro realizado!</div>
        <div class="text-secondary small text-center">Rosto vinculado com sucesso ao colaborador.</div>
        <a href="teacher_edit.php?id=<?= (int)$teacher['id'] ?>" class="btn btn-success btn-sm mt-2 px-4">
          <i class="bi bi-arrow-left me-1"></i>Voltar ao cadastro
        </a>
      </div>
    </div>

    <!-- Barra de progresso de amostras -->
    <div class="samples-bar-wrap mb-3">
      <div class="sample-dots" id="sampleDots">
        <!-- gerados via JS -->
      </div>
      <div class="text-center mb-2 fw-semibold" id="sampleCount" style="color:#94a3b8; font-size:.88rem;">
        0 / 5 amostras coletadas
      </div>
      <div class="status-msg" id="statusMsg">Aguardando câmera...</div>
    </div>

    <!-- Botões manuais (fallback / limpar) -->
    <div class="d-flex gap-2 flex-wrap justify-content-center" id="btnArea">
      <button class="btn btn-outline-secondary btn-sm" id="btnRetry" style="display:none;">
        <i class="bi bi-arrow-clockwise me-1"></i>Tentar novamente
      </button>
      <button class="btn btn-outline-danger btn-sm" id="btnClear" style="display:none;">
        <i class="bi bi-trash me-1"></i>Reiniciar
      </button>
    </div>

  </div><!-- /capture-card -->
</div><!-- /container -->

<!-- face-api local -->
<script src="<?= esc($appBase) ?>/js/face-api.min.js"></script>
<script>
'use strict';

const CSRF       = document.querySelector('meta[name="csrf-token"]').content;
const APP_BASE   = document.querySelector('meta[name="app-base"]').content || '';
const TEACHER_ID = <?= (int)$teacher['id'] ?>;
const SAMPLES_NEEDED = 5;

// ── Estado ─────────────────────────────────────────────────────────────────
let samples        = [];       // descritores coletados
let modelsReady    = false;
let cameraReady    = false;
let detecting      = false;
let saving         = false;
let done           = false;
let stableTs       = 0;        // timestamp da primeira detecção estável contínua
let lastDetected   = false;
const STABLE_MS    = 1400;     // tempo mínimo com face detectada antes de capturar

// ── Referências DOM ─────────────────────────────────────────────────────────
const video          = document.getElementById('video');
const faceRing       = document.getElementById('faceRing');
const flashOverlay   = document.getElementById('flashOverlay');
const instructionPill= document.getElementById('instructionPill');
const loaderSpinner  = document.getElementById('loaderSpinner');
const instructionText= document.getElementById('instructionText');
const sampleDotsEl   = document.getElementById('sampleDots');
const sampleCountEl  = document.getElementById('sampleCount');
const statusMsg      = document.getElementById('statusMsg');
const successOverlay = document.getElementById('successOverlay');
const btnRetry       = document.getElementById('btnRetry');
const btnClear       = document.getElementById('btnClear');

// ── Inicializar dots de amostras ────────────────────────────────────────────
function initDots() {
  sampleDotsEl.innerHTML = '';
  for (let i = 0; i < SAMPLES_NEEDED; i++) {
    const d = document.createElement('div');
    d.className = 'dot';
    d.id = 'dot-' + i;
    sampleDotsEl.appendChild(d);
  }
}
initDots();

function updateDots() {
  for (let i = 0; i < SAMPLES_NEEDED; i++) {
    const d = document.getElementById('dot-' + i);
    if (i < samples.length)          { d.className = 'dot filled'; }
    else if (i === samples.length)   { d.className = 'dot active';  }
    else                              { d.className = 'dot'; }
  }
  sampleCountEl.textContent = `${samples.length} / ${SAMPLES_NEEDED} amostras coletadas`;
}

// ── Utilitários de UI ───────────────────────────────────────────────────────
function setInstruction(text, cls = '') {
  loaderSpinner.style.display = 'none';
  instructionText.textContent = text;
  instructionPill.className = 'instruction-pill' + (cls ? ' ' + cls : '');
}

function setStatus(text, cls = '') {
  statusMsg.textContent = text;
  statusMsg.className = 'status-msg' + (cls ? ' ' + cls : '');
}

function flash() {
  flashOverlay.classList.add('flash');
  setTimeout(() => flashOverlay.classList.remove('flash'), 120);
}

// ── Carregamento de modelos ─────────────────────────────────────────────────
async function loadModels() {
  const modelUrl = APP_BASE + '/models';
  loaderSpinner.style.display = 'inline-block';
  instructionText.textContent = 'Carregando modelos...';

  await faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl);
  await Promise.all([
    faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelUrl),
    faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl),
  ]);
  modelsReady = true;
  setStatus('Modelos prontos. Aguardando câmera...', 'ok');
}

// ── Câmera ──────────────────────────────────────────────────────────────────
async function openCamera() {
  const constraints = {
    video: {
      facingMode: 'user',
      width: { ideal: 1280 },
      height: { ideal: 960 }
    }
  };
  try {
    const stream = await navigator.mediaDevices.getUserMedia(constraints);
    video.srcObject = stream;
    await new Promise(r => video.addEventListener('loadedmetadata', r, { once: true }));
    cameraReady = true;
    setInstruction('Posicione seu rosto no oval');
    setStatus('Pronto. Detectando automaticamente...');
    startDetectionLoop();
  } catch (e) {
    setInstruction('Câmera indisponível', 'warn');
    setStatus('Erro ao acessar a câmera: ' + e.message, 'err');
    btnRetry.style.display = '';
  }
}

// ── Loop de detecção ────────────────────────────────────────────────────────
function startDetectionLoop() {
  if (detecting) return;
  detecting = true;
  requestAnimationFrame(detectionTick);
}

let lastTickTs = 0;
const TICK_INTERVAL_MS = 400;

async function detectionTick(ts) {
  if (done || saving) return;

  if (ts - lastTickTs < TICK_INTERVAL_MS) {
    requestAnimationFrame(detectionTick);
    return;
  }
  lastTickTs = ts;

  if (!modelsReady || !cameraReady || video.readyState < 2) {
    requestAnimationFrame(detectionTick);
    return;
  }

  try {
    const detection = await faceapi
      .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.35 }))
      .withFaceLandmarks(true);

    if (detection) {
      // Avaliar qualidade: face precisa cobrir área mínima
      const { width: vw, height: vh } = video.getBoundingClientRect();
      const { width: bw, height: bh } = detection.detection.box;
      const coverage = (bw * bh) / (vw * vh);
      const centered = isCentered(detection.detection.box, vw, vh);

      if (coverage >= 0.06 && centered) {
        faceRing.className = 'face-ring detected';
        if (!lastDetected) { stableTs = Date.now(); lastDetected = true; }
        const elapsed = Date.now() - stableTs;
        const remaining = Math.max(0, STABLE_MS - elapsed);

        if (remaining > 0) {
          setInstruction(`Mantenha-se parado... ${Math.ceil(remaining / 1000)}s`, 'ok');
        } else {
          // Estável o suficiente — capturar amostra
          faceRing.className = 'face-ring capturing';
          setInstruction('Capturando...', 'ok');
          await captureSample();
          lastDetected = false;
          stableTs = 0;
        }
      } else {
        faceRing.className = 'face-ring';
        lastDetected = false;
        stableTs = 0;
        const hint = !centered ? 'Centralize o rosto no oval' : 'Aproxime-se da câmera';
        setInstruction(hint, 'warn');
      }
    } else {
      faceRing.className = 'face-ring';
      lastDetected = false;
      stableTs = 0;
      setInstruction('Posicione seu rosto no oval');
    }
  } catch (_) { /* ignora erros momentâneos de detecção */ }

  requestAnimationFrame(detectionTick);
}

function isCentered(box, vw, vh) {
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;
  const dx = Math.abs(cx / vw - 0.5);
  const dy = Math.abs(cy / vh - 0.5);
  return dx < 0.28 && dy < 0.28;
}

// ── Captura de amostra ──────────────────────────────────────────────────────
async function captureSample() {
  if (samples.length >= SAMPLES_NEEDED) return;

  try {
    const detection = await faceapi
      .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.3 }))
      .withFaceLandmarks(true)
      .withFaceDescriptor();

    if (!detection || !detection.descriptor) {
      setStatus('Não foi possível extrair descritor. Tente novamente.', 'warn');
      return;
    }

    flash();
    samples.push(Array.from(detection.descriptor));
    updateDots();
    setStatus(`Amostra ${samples.length} de ${SAMPLES_NEEDED} capturada.`, 'ok');

    if (samples.length >= SAMPLES_NEEDED) {
      await saveSamples();
    } else {
      setInstruction('Ótimo! Continue olhando para a câmera', 'ok');
    }
  } catch (e) {
    setStatus('Erro ao capturar: ' + e.message, 'err');
  }
}

// ── Salvar descritores ──────────────────────────────────────────────────────
async function saveSamples() {
  if (saving || done) return;
  saving = true;
  detecting = false;

  setInstruction('Salvando dados faciais...', 'ok');
  setStatus('Enviando para o servidor...', 'ok');

  try {
    const res = await fetch(`${APP_BASE}/api/save_face.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ teacher_id: TEACHER_ID, descriptors: samples })
    });
    const data = await res.json();
    if (!res.ok || data.status !== 'ok') throw new Error(data.message || 'Falha ao salvar');

    done = true;
    successOverlay.classList.add('show');
    // Parar câmera
    if (video.srcObject) {
      video.srcObject.getTracks().forEach(t => t.stop());
    }
  } catch (e) {
    saving = false;
    detecting = true;
    setInstruction('Erro ao salvar — tente novamente', 'warn');
    setStatus(e.message, 'err');
    btnClear.style.display = '';
    btnRetry.style.display = '';
    requestAnimationFrame(detectionTick);
  }
}

// ── Botões ──────────────────────────────────────────────────────────────────
btnClear.addEventListener('click', () => {
  samples = [];
  saving = false;
  done = false;
  lastDetected = false;
  stableTs = 0;
  initDots();
  updateDots();
  setStatus('Reiniciado. Posicione o rosto para capturar.');
  setInstruction('Posicione seu rosto no oval');
  faceRing.className = 'face-ring';
  btnClear.style.display = 'none';
  btnRetry.style.display = 'none';
  if (!detecting) { detecting = true; requestAnimationFrame(detectionTick); }
});

btnRetry.addEventListener('click', () => {
  btnRetry.style.display = 'none';
  openCamera();
});

// ── Inicializar ─────────────────────────────────────────────────────────────
(async () => {
  try {
    await loadModels();
    await openCamera();
  } catch (e) {
    setInstruction('Erro de inicialização', 'warn');
    setStatus(e.message, 'err');
    btnRetry.style.display = '';
  }
})();
</script>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

<?php
require_once __DIR__ . '/../../config.php';
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pdo = db();
$stmt = $pdo->prepare("SELECT id, name, email FROM teachers WHERE id = ?");
$stmt->execute([$id]);
$teacher = $stmt->fetch();
if (!$teacher) {
    http_response_code(404);
    exit('Professor não encontrado.');
}
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$appBase = preg_replace('#/public(?:/.*)?$#', '', $scriptDir);
if ($appBase === '') { $appBase = '/'; }

// Conta descriptors existentes
$stFace = $pdo->prepare("SELECT face_descriptors FROM teachers WHERE id = ?");
$stFace->execute([$id]);
$faceRow = $stFace->fetch(PDO::FETCH_ASSOC);
$existingCount = 0;
if (!empty($faceRow['face_descriptors'])) {
    $tmp = json_decode($faceRow['face_descriptors'], true);
    if (is_array($tmp)) $existingCount = count($tmp);
}
$maxDescriptors = (int)(get_setting('face_max_descriptors', '20') ?? '20');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Capturar Face - <?= esc($teacher['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <meta name="app-base" content="<?= esc($appBase) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    .video-container { position: relative; display: inline-block; width: 100%; }
    .video-container video, .video-container canvas { max-width: 100%; border-radius: 8px; display: block; }
    .video-container canvas { position: absolute; top: 0; left: 0; pointer-events: none; }
    .sample-item { padding: 4px 0; }
    .sample-item .badge { font-size: 0.75rem; }
    .capture-btn { width: 64px; height: 64px; border-radius: 50%; font-size: 1.5rem; }
    @keyframes pulse-ring { 0%,100%{box-shadow:0 0 0 0 rgba(25,135,84,0.4)} 50%{box-shadow:0 0 0 12px rgba(25,135,84,0)} }
    .auto-capture-active { animation: pulse-ring 1.5s ease-in-out infinite; }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary mb-4">
  <div class="container">
    <a class="navbar-brand" href="dashboard.php">Admin</a>
    <div class="ms-auto">
      <a class="btn btn-outline-secondary me-2" href="teachers.php">Professores</a>
      <a class="btn btn-outline-danger" href="logout.php">Sair</a>
    </div>
  </div>
</nav>

<div class="container">
  <h3><i class="bi bi-camera"></i> Capturar Face: <?= esc($teacher['name']) ?></h3>
  <p class="text-muted">
    Capture pelo menos <strong>3 amostras</strong> para melhor precisao.
    <?php if ($existingCount > 0): ?>
      <br><span class="badge bg-info"><?= $existingCount ?> de <?= $maxDescriptors ?> descritores ja cadastrados</span>
    <?php endif; ?>
  </p>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="video-container">
        <video id="video" autoplay muted playsinline></video>
        <canvas id="faceOverlay"></canvas>
      </div>
      <div class="mt-2 d-flex gap-2 align-items-center flex-wrap">
        <button class="btn btn-primary capture-btn" id="btn-capture" disabled title="Capturar amostra">
          <i class="bi bi-camera-fill"></i>
        </button>
        <button class="btn btn-success" id="btn-save" disabled>
          <i class="bi bi-cloud-upload"></i> Salvar descritores
        </button>
        <button class="btn btn-outline-secondary" id="btn-clear">
          <i class="bi bi-trash"></i> Limpar
        </button>
        <div class="form-check form-switch ms-auto">
          <input class="form-check-input" type="checkbox" id="autoCapture" checked>
          <label class="form-check-label" for="autoCapture">Auto-captura</label>
        </div>
      </div>
      <div class="mt-2" id="status">
        <div class="spinner-border spinner-border-sm me-1" role="status"></div>
        Carregando biblioteca de reconhecimento...
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="bi bi-collection"></i> Amostras coletadas</span>
          <span class="badge bg-primary" id="count">0</span>
        </div>
        <ul class="list-group list-group-flush" id="samples">
          <li class="list-group-item text-muted fst-italic" id="no-samples">Nenhuma amostra ainda. Posicione o rosto na camera.</li>
        </ul>
      </div>
      <div id="result" class="mt-2"></div>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const APP_BASE = document.querySelector('meta[name="app-base"]').getAttribute('content') || '/';
const teacherId = <?= (int)$teacher['id'] ?>;
const MIN_SAMPLES = 3;

const statusEl = document.getElementById('status');
const samplesEl = document.getElementById('samples');
const countEl = document.getElementById('count');
const resultEl = document.getElementById('result');
const video = document.getElementById('video');
const overlay = document.getElementById('faceOverlay');
const overlayCtx = overlay?.getContext('2d');
const btnCapture = document.getElementById('btn-capture');
const btnSave = document.getElementById('btn-save');
const btnClear = document.getElementById('btn-clear');
const autoCaptureCb = document.getElementById('autoCapture');
const noSamplesEl = document.getElementById('no-samples');

let samples = [];
let modelsLoaded = false;
let stream = null;
let detectionLoop = null;
let lastOkTs = 0;
let smoothRect = null;

// ============================================================================
// CARREGAR FACE-API.JS (modelos ALINHADOS com index.php)
// ============================================================================
async function loadFaceApi() {
  if (window.faceapi) return;
  const cdns = [
    'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js',
    'https://unpkg.com/@vladmandic/face-api/dist/face-api.min.js'
  ];
  for (const src of cdns) {
    try {
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = () => reject(new Error('Falha: ' + src));
        document.head.appendChild(s);
      });
      if (window.faceapi) return;
    } catch (e) { console.warn(e.message); }
  }
  throw new Error('Nao foi possivel carregar face-api.js');
}

async function load() {
  try {
    await loadFaceApi();
    statusEl.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Carregando modelos...';

    // ALINHADO com index.php: usa tinyFaceDetector + faceLandmark68TinyNet + faceRecognitionNet
    const modelUrl = APP_BASE + '/models';
    await Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),
      faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelUrl),
      faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)
    ]);
    modelsLoaded = true;

    statusEl.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Solicitando acesso a camera...';
    btnCapture.disabled = false;

    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
    video.srcObject = stream;
    video.addEventListener('loadedmetadata', () => {
      overlay.width = video.clientWidth;
      overlay.height = video.clientHeight;
      startDetectionLoop();
    });
    statusEl.innerHTML = '<i class="bi bi-check-circle text-success"></i> Pronto. Posicione o rosto dentro do guia.';
  } catch (e) {
    statusEl.innerHTML = '<i class="bi bi-exclamation-triangle text-danger"></i> Erro: ' + e.message;
    btnCapture.disabled = true;
    btnSave.disabled = true;
  }
}
load();

// ============================================================================
// DETECCAO FACIAL + GUIA VISUAL (portado de index.php)
// ============================================================================
function drawEllipsePath(ctx, cx, cy, rx, ry) {
  const k = 0.5522847498307936;
  ctx.beginPath();
  ctx.moveTo(cx, cy - ry);
  ctx.bezierCurveTo(cx + rx*k, cy - ry, cx + rx, cy - ry*k, cx + rx, cy);
  ctx.bezierCurveTo(cx + rx, cy + ry*k, cx + rx*k, cy + ry, cx, cy + ry);
  ctx.bezierCurveTo(cx - rx*k, cy + ry, cx - rx, cy + ry*k, cx - rx, cy);
  ctx.bezierCurveTo(cx - rx, cy - ry*k, cx - rx*k, cy - ry, cx, cy - ry);
  ctx.closePath();
}

function computeQuality(rect, cw, ch) {
  const cx = rect.x + rect.width / 2;
  const cy = rect.y + rect.height / 2;
  const centerTol = Math.min(cw, ch) * 0.12;
  const dx = cx - cw / 2, dy = cy - ch / 2;
  const dist = Math.hypot(dx, dy);
  const shortSide = Math.min(cw, ch);
  const desiredWidthMin = shortSide * 0.18;
  const desiredWidthMax = shortSide * 0.65;

  let message = '', warn = false;
  if (rect.width < desiredWidthMin) { message = 'Aproxime o rosto'; warn = true; }
  else if (rect.width > desiredWidthMax) { message = 'Afaste um pouco'; warn = true; }
  if (dist > centerTol) { message = 'Centralize o rosto'; warn = true; }

  return { ok: !warn, message: !warn ? 'Pronto! (captura automatica)' : message };
}

function drawGuide(rect) {
  if (!overlayCtx) return;
  const cw = overlay.width, ch = overlay.height;
  const ctx = overlayCtx;
  ctx.clearRect(0, 0, cw, ch);

  const cx = rect.x + rect.width / 2, cy = rect.y + rect.height / 2;
  const rx = rect.width * 0.65, ry = rect.height * 0.95;
  const q = computeQuality(rect, cw, ch);
  const color = q.ok ? 'rgba(25,135,84,0.95)' : 'rgba(255,193,7,0.95)';

  // Dark overlay
  ctx.save();
  ctx.fillStyle = 'rgba(0,0,0,0.15)';
  ctx.fillRect(0, 0, cw, ch);
  ctx.globalCompositeOperation = 'destination-out';
  drawEllipsePath(ctx, cx, cy, rx, ry);
  ctx.fill();
  ctx.restore();

  // Ellipse stroke
  ctx.save();
  ctx.strokeStyle = color;
  ctx.lineWidth = 3;
  ctx.setLineDash([10, 8]);
  ctx.lineDashOffset = -Date.now() / 80;
  drawEllipsePath(ctx, cx, cy, rx, ry);
  ctx.stroke();
  ctx.restore();

  // Text
  ctx.save();
  ctx.fillStyle = 'rgba(255,255,255,0.95)';
  ctx.font = '600 14px system-ui, sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'bottom';
  ctx.fillText(q.message, cw / 2, ch - 14);
  ctx.restore();

  // Auto-capture: se OK por 800ms
  if (autoCaptureCb?.checked && q.ok) {
    const now = performance.now();
    if (!lastOkTs) lastOkTs = now;
    if (now - lastOkTs > 800) {
      capture();
      lastOkTs = 0;
    }
    btnCapture.classList.add('auto-capture-active');
  } else {
    lastOkTs = 0;
    btnCapture.classList.remove('auto-capture-active');
  }
}

function lerp(a, b, t) { return a + (b - a) * t; }
function lerpRect(prev, next, t) {
  if (!prev) return next;
  return { x: lerp(prev.x, next.x, t), y: lerp(prev.y, next.y, t), width: lerp(prev.width, next.width, t), height: lerp(prev.height, next.height, t) };
}

function startDetectionLoop() {
  if (detectionLoop) return;
  let busy = false;
  detectionLoop = setInterval(async () => {
    if (busy || !modelsLoaded || !stream) return;
    busy = true;
    try {
      // Resize overlay
      if (overlay.width !== video.clientWidth || overlay.height !== video.clientHeight) {
        overlay.width = video.clientWidth;
        overlay.height = video.clientHeight;
      }

      const det = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions());
      if (det) {
        const scaleX = overlay.width / video.videoWidth;
        const scaleY = overlay.height / video.videoHeight;
        const mapped = {
          x: det.box.x * scaleX,
          y: det.box.y * scaleY,
          width: det.box.width * scaleX,
          height: det.box.height * scaleY
        };
        smoothRect = lerpRect(smoothRect, mapped, 0.35);
        drawGuide(smoothRect || mapped);
      } else {
        smoothRect = null;
        if (overlayCtx) overlayCtx.clearRect(0, 0, overlay.width, overlay.height);
        lastOkTs = 0;
        btnCapture.classList.remove('auto-capture-active');
      }
    } catch {} finally { busy = false; }
  }, 100);
}

// ============================================================================
// CAPTURA DE AMOSTRA (ALINHADA com index.php: TinyFaceDetectorOptions)
// ============================================================================
async function capture() {
  if (!modelsLoaded) return;
  statusEl.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Detectando rosto...';
  try {
    const det = await faceapi
      .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
      .withFaceLandmarks(true)
      .withFaceDescriptor();
    if (!det) {
      statusEl.innerHTML = '<i class="bi bi-exclamation-circle text-warning"></i> Rosto nao detectado. Tente novamente.';
      return;
    }
    const desc = Array.from(det.descriptor);

    // Validacao de consistencia: novo sample nao deve ser muito distante dos anteriores
    if (samples.length > 0) {
      for (let i = 0; i < samples.length; i++) {
        const dist = faceapi.euclideanDistance(new Float32Array(desc), new Float32Array(samples[i]));
        if (dist > 0.5) {
          statusEl.innerHTML = '<i class="bi bi-exclamation-triangle text-warning"></i> Amostra inconsistente (dist ' + dist.toFixed(3) + '). Reposicione o rosto e tente novamente.';
          return;
        }
      }
    }

    samples.push(desc);
    if (noSamplesEl) noSamplesEl.style.display = 'none';
    const li = document.createElement('li');
    li.className = 'list-group-item sample-item d-flex justify-content-between align-items-center';
    li.innerHTML = '<span><i class="bi bi-check-circle text-success"></i> Amostra ' + samples.length + '</span><span class="badge bg-success">OK</span>';
    samplesEl.appendChild(li);
    countEl.textContent = samples.length.toString();

    btnSave.disabled = samples.length < MIN_SAMPLES;
    statusEl.innerHTML = '<i class="bi bi-check-circle text-success"></i> Amostra ' + samples.length + ' coletada.' +
      (samples.length < MIN_SAMPLES ? ' (minimo ' + MIN_SAMPLES + ')' : ' <strong>Pronto para salvar!</strong>');
  } catch (e) {
    statusEl.innerHTML = '<i class="bi bi-x-circle text-danger"></i> Erro: ' + e.message;
  }
}

btnCapture.addEventListener('click', capture);

btnClear.addEventListener('click', () => {
  samples = [];
  samplesEl.innerHTML = '<li class="list-group-item text-muted fst-italic" id="no-samples">Nenhuma amostra ainda.</li>';
  countEl.textContent = '0';
  resultEl.innerHTML = '';
  btnSave.disabled = true;
  statusEl.innerHTML = '<i class="bi bi-info-circle"></i> Amostras limpas. Posicione o rosto para capturar.';
});

btnSave.addEventListener('click', async () => {
  if (samples.length < MIN_SAMPLES) {
    resultEl.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Colete pelo menos ' + MIN_SAMPLES + ' amostras.</div>';
    return;
  }

  // Validacao final: consistencia entre TODAS as amostras
  for (let i = 0; i < samples.length; i++) {
    for (let j = i + 1; j < samples.length; j++) {
      const dist = faceapi.euclideanDistance(new Float32Array(samples[i]), new Float32Array(samples[j]));
      if (dist > 0.5) {
        resultEl.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Amostras ' + (i+1) + ' e ' + (j+1) + ' inconsistentes (dist ' + dist.toFixed(3) + '). Limpe e tente novamente.</div>';
        return;
      }
    }
  }

  statusEl.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Enviando...';
  btnSave.disabled = true;
  try {
    const res = await fetch(`${APP_BASE}/api/save_face.php`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify({ teacher_id: teacherId, descriptors: samples })
    });
    const txt = await res.text();
    let data;
    try { data = JSON.parse(txt); } catch(e) { throw new Error('Resposta invalida: ' + txt.slice(0, 120)); }

    if (data.code === 'face_conflict') {
      resultEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-shield-exclamation"></i> <strong>Conflito!</strong> ' + (data.message || 'Este rosto ja esta cadastrado em outra pessoa.') + '</div>';
      statusEl.innerHTML = '<i class="bi bi-shield-exclamation text-danger"></i> Conflito facial detectado.';
      btnSave.disabled = false;
      return;
    }

    if (!res.ok || data.status !== 'ok') throw new Error(data.message || 'Falha ao salvar');
    resultEl.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle"></i> ' + (data.message || 'Descritores salvos com sucesso!') + '</div>';
    statusEl.innerHTML = '<i class="bi bi-check-circle text-success"></i> Salvo com sucesso!';
  } catch (e) {
    resultEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle"></i> ' + e.message + '</div>';
    statusEl.innerHTML = '<i class="bi bi-x-circle text-danger"></i> Erro ao salvar.';
    btnSave.disabled = false;
  }
});
</script>
</body>
</html>

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
  <style>video, canvas { max-width: 100%; border-radius: 8px; }</style>
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
  <h3>Capturar Face: <?= esc($teacher['name']) ?> (ID <?= (int)$teacher['id'] ?>)</h3>
  <p class="text-muted">Capture 3–5 amostras para melhor precisão.</p>

  <div class="row g-3">
    <div class="col-lg-6">
      <video id="video" autoplay muted playsinline></video>
      <div class="mt-2 d-flex gap-2">
        <button class="btn btn-primary" id="btn-capture" disabled>Capturar amostra</button>
        <button class="btn btn-success" id="btn-save" disabled>Salvar descritores</button>
        <button class="btn btn-outline-secondary" id="btn-clear">Limpar</button>
      </div>
      <div class="mt-2" id="status">Carregando biblioteca de reconhecimento...</div>
    </div>
    <div class="col-lg-6">
      <h6>Amostras coletadas: <span id="count">0</span></h6>
      <ul id="samples"></ul>
      <div id="result"></div>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const APP_BASE = document.querySelector('meta[name="app-base"]').getAttribute('content') || '/';
const teacherId = <?= (int)$teacher['id'] ?>;
const statusEl = document.getElementById('status');
const samplesEl = document.getElementById('samples');
const countEl = document.getElementById('count');
const resultEl = document.getElementById('result');
const video = document.getElementById('video');
const btnCapture = document.getElementById('btn-capture');
const btnSave = document.getElementById('btn-save');
const btnClear = document.getElementById('btn-clear');

let samples = [];
let modelUrl = '';
let modelsLoaded = false;

async function loadFaceApi() {
  if (window.faceapi) return modelUrl || 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/';
  const cdns = [
    { lib: 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js', model: 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/' },
    { lib: 'https://unpkg.com/@vladmandic/face-api/dist/face-api.min.js', model: 'https://unpkg.com/@vladmandic/face-api/model/' }
  ];
  for (const c of cdns) {
    try {
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = c.lib;
        s.onload = resolve;
        s.onerror = () => reject(new Error('Falha ao carregar ' + c.lib));
        document.head.appendChild(s);
      });
      if (window.faceapi) return c.model;
    } catch (e) { console.warn(e.message); }
  }
  throw new Error('Não foi possível carregar face-api.js de nenhum CDN.');
}

async function load() {
  try {
    modelUrl = await loadFaceApi();
    statusEl.textContent = 'Carregando modelos...';
    await faceapi.nets.ssdMobilenetv1.loadFromUri(modelUrl);
    await faceapi.nets.faceLandmark68Net.loadFromUri(modelUrl);
    await faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl);
    modelsLoaded = true;
    statusEl.textContent = 'Modelos carregados. Solicitando acesso à câmera...';
    btnCapture.disabled = false;
    btnSave.disabled = false;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }});
      video.srcObject = stream;
      statusEl.textContent = 'Pronto. Posicione o rosto e capture.';
    } catch (e) {
      statusEl.textContent = 'Erro ao acessar a câmera: ' + e.message;
      btnCapture.disabled = true;
      btnSave.disabled = true;
    }
  } catch (e) {
    statusEl.textContent = 'Erro ao carregar modelos: ' + e.message;
    btnCapture.disabled = true;
    btnSave.disabled = true;
  }
}
load();

async function capture() {
  if (!modelsLoaded) return;
  statusEl.textContent = 'Detectando rosto...';
  try {
    const det = await faceapi.detectSingleFace(video).withFaceLandmarks().withFaceDescriptor();
    if (!det) {
      statusEl.textContent = 'Rosto não detectado. Tente novamente.';
      return;
    }
    const desc = Array.from(det.descriptor);
    samples.push(desc);
    const li = document.createElement('li');
    li.textContent = 'Amostra ' + samples.length + ' coletada.';
    samplesEl.appendChild(li);
    countEl.textContent = samples.length.toString();
    statusEl.textContent = 'Amostra coletada.';
  } catch (e) {
    statusEl.textContent = 'Erro ao detectar rosto: ' + e.message;
  }
}

btnCapture.addEventListener('click', capture);
btnClear.addEventListener('click', () => {
  samples = [];
  samplesEl.innerHTML = '';
  countEl.textContent = '0';
  resultEl.innerHTML = '';
  statusEl.textContent = 'Amostras limpas.';
});

btnSave.addEventListener('click', async () => {
  if (samples.length === 0) {
    resultEl.innerHTML = '<div class="alert alert-warning">Colete ao menos uma amostra.</div>';
    return;
  }
  statusEl.textContent = 'Enviando...';
  try {
    const res = await fetch(`${APP_BASE}/api/save_face.php`, {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify({ teacher_id: teacherId, descriptors: samples })
    });
    const txt = await res.text();
    let data;
    try { data = JSON.parse(txt); } catch(e) { throw new Error('Resposta inválida do servidor: ' + txt.slice(0,120)); }
    if (!res.ok || data.status !== 'ok') throw new Error(data.message || 'Falha ao salvar');
    resultEl.innerHTML = '<div class="alert alert-success">Descritores salvos com sucesso.</div>';
    statusEl.textContent = 'Salvo.';
  } catch (e) {
    resultEl.innerHTML = '<div class="alert alert-danger">' + e.message + '</div>';
    statusEl.textContent = 'Erro.';
  }
});
</script>
</body>
</html>
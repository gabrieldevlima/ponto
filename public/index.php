
<?php
require_once __DIR__ . '/../config.php';

// Helper
if (!function_exists('esc')) {
  function esc($str)
  {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// Calculate application base path
$scriptName = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
$scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$appBase = preg_replace('#/public(?:/.*)?$#', '', $scriptDir);
// In root, use empty string to avoid generating URLs like //api/checkin.php
if ($appBase === '/' || $appBase === '') {
  $appBase = '';
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Ponto Eletrônico - Colaboradores</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0d6efd">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <meta name="app-base" content="<?= esc($appBase) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root {
      --surface: #ffffff;
      --bg: #f5f7fb;
      --text: #212529;
      --muted: #6c757d;
      --radius: 14px;
      --shadow: 0 10px 30px rgba(16, 24, 40, .08);
    }

    html,
    body {
      height: 100%;
    }

    body {
      background: var(--bg);
      color: var(--text);
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    .navbar {
      box-shadow: var(--shadow);
      border: 0;
    }

    .navbar-brand img {
      width: 140px;
      height: auto;
      object-fit: contain;
    }

    @media (max-width: 480px) {
      .navbar-brand img {
        width: 120px;
      }
    }

    .form-section {
      background: var(--surface);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .form-section .header {
      padding: 1rem 1.25rem;
      border-bottom: 1px solid rgba(108, 117, 125, .15);
      background: linear-gradient(180deg, rgba(13, 110, 253, .06), transparent);
    }

    .form-section .body {
      padding: 1.25rem;
    }

    .form-label {
      font-weight: 600;
    }

    .help {
      color: var(--muted);
      font-size: .9rem;
    }

    .media-wrap {
      position: relative;
      border: 1px solid rgba(108, 117, 125, .2);
      border-radius: 12px;
      overflow: hidden;
      background: #000;
    }

    .media-toolbar {
      position: absolute;
      top: .5rem;
      right: .5rem;
      display: flex;
      gap: .5rem;
      z-index: 5;
    }

    video,
    canvas,
    #snapshot {
      width: 100%;
      display: block;
      aspect-ratio: 4/3;
      object-fit: cover;
      border: 0;
    }

    video.mirror {
      transform: scaleX(-1);
    }

    #status {
      min-height: 1.5rem;
      font-size: .95rem;
      color: var(--muted);
    }

    .alert-info {
      font-size: .95rem;
    }

    .btn-lg {
      padding-top: .9rem;
      padding-bottom: .9rem;
    }

    .full-alert {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .85);
      color: #fff;
      display: none;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 2rem;
      z-index: 9999;
    }

    .full-alert.show {
      display: flex;
    }

    .full-alert .box {
      max-width: 560px;
      width: 100%;
      background: rgba(255, 255, 255, .06);
      backdrop-filter: blur(8px);
      border: 1px solid rgba(255, 255, 255, .12);
      border-radius: 16px;
      padding: 1.5rem;
    }

    .safe {
      padding-top: env(safe-area-inset-top);
      padding-bottom: env(safe-area-inset-bottom);
    }
  </style>
</head>

<body class="safe">
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" href="#">
        <img src="<?= esc($appBase) ?>/img/logo.png" alt="Logo da Empresa">
        <span class="fw-semibold d-none d-sm-inline">Ponto Eletrônico</span>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
        aria-controls="navbarNav" aria-expanded="false" aria-label="Alternar navegação">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
        <ul class="navbar-nav align-items-center">
          <li class="nav-item ms-2">
            <a class="btn btn-outline-light btn-sm" href="<?= esc($appBase) ?>/admin/login.php">
              <i class="bi bi-shield-lock me-1"></i> Admin
            </a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <main class="container">
    <div class="row g-4">
      <section class="col-12 col-lg-6">
        <form id="pointForm" method="post" action="#" class="form-section" autocomplete="off">
          <div class="header">
            <h4 class="m-0 d-flex align-items-center gap-2">
              <i class="bi bi-clock-history"></i>
              Registrar Ponto
            </h4>
          </div>
          <div class="body">
            <div class="mb-3">
              <label for="cpf" class="form-label">CPF (opcional)</label>
              <input
                type="text"
                class="form-control"
                id="cpf"
                name="cpf"
                maxlength="14"
                inputmode="numeric"
                autocomplete="off"
                placeholder="000.000.000-00">
              <div class="help">Informe o CPF para agilizar a identificação.</div>
            </div>

            <div class="mb-3">
              <label for="pin" class="form-label">PIN</label>
              <input
                type="password"
                class="form-control"
                id="pin"
                name="pin"
                required
                inputmode="numeric"
                pattern="[0-9]{4,10}"
                minlength="4"
                maxlength="10"
                autocomplete="new-password"
                placeholder="Digite seu PIN">
            </div>

            <div class="mb-3">
              <label class="form-label d-flex align-items-center justify-content-between">
                <span>Foto (via câmera)</span>
              </label>

              <div class="media-wrap">
                <div class="media-toolbar">
                  <button type="button" id="btnFlip" class="btn btn-light btn-sm" title="Alternar câmera">
                    <i class="bi bi-camera-reverse"></i>
                  </button>
                  <button type="button" id="btnRetake" class="btn btn-light btn-sm d-none" title="Tirar outra foto">
                    <i class="bi bi-arrow-counterclockwise"></i>
                  </button>
                </div>
                <video id="video" autoplay playsinline class="mirror"></video>
                <canvas id="canvas" class="d-none"></canvas>
                <img id="snapshot" class="d-none" alt="Foto da câmera">
              </div>
            </div>

            <div class="d-grid gap-2">
              <button class="btn btn-success btn-lg" type="submit" id="btnReg">
                <span class="spinner-border spinner-border-sm me-2 d-none" id="btnSpin" role="status" aria-hidden="true"></span>
                Registrar ponto com foto
              </button>
              <span id="status" class="mt-1"></span>
            </div>
          </div>
        </form>
      </section>

      <section class="col-12 col-lg-6">
        <div id="result"></div>
        <div class="alert alert-info mt-3">
          <strong>Atenção:</strong> Sua localização será registrada junto com a foto para maior segurança e controle.
        </div>
      </section>
    </div>
  </main>

  <div id="fullAlert" class="full-alert">
    <div class="box">
      <div id="fullAlertIcon" class="display-5 mb-2"></div>
      <div id="fullAlertTitle" class="h3 mb-2"></div>
      <div id="fullAlertMsg" class="lead"></div>
    </div>
  </div>

  <script>
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const APP_BASE = document.querySelector('meta[name="app-base"]').getAttribute('content') || '/';

    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const snapshot = document.getElementById('snapshot');
    const statusEl = document.getElementById('status');
    const resultEl = document.getElementById('result');
    const btnReg = document.getElementById('btnReg');
    const btnSpin = document.getElementById('btnSpin');
    const btnFlip = document.getElementById('btnFlip');
    const btnRetake = document.getElementById('btnRetake');

    const fullAlert = document.getElementById('fullAlert');
    const fullAlertIcon = document.getElementById('fullAlertIcon');
    const fullAlertTitle = document.getElementById('fullAlertTitle');
    const fullAlertMsg = document.getElementById('fullAlertMsg');

    let stream = null;
    let useBackCamera = false;

    function setBusy(busy) {
      btnReg.disabled = busy;
      btnSpin.classList.toggle('d-none', !busy);
    }

    function formatCPF(value) {
      const v = value.replace(/\D/g, '').slice(0, 11);
      const parts = [];
      if (v.length > 0) parts.push(v.substring(0, 3));
      if (v.length > 3) parts.push(v.substring(3, 6));
      if (v.length > 6) parts.push(v.substring(6, 9));
      let tail = v.substring(9, 11);
      let out = parts.join('.');
      if (v.length > 9) out += (parts.length ? '-' : '') + tail;
      return out;
    }
    document.getElementById('cpf').addEventListener('input', (e) => {
      const pos = e.target.selectionStart;
      e.target.value = formatCPF(e.target.value);
      e.target.setSelectionRange(pos, pos);
    });

    async function stopWebcam() {
      if (stream) {
        stream.getTracks().forEach(t => t.stop());
        stream = null;
      }
    }

    async function startWebcam() {
      try {
        await stopWebcam();
        const constraints = {
          video: {
            facingMode: useBackCamera ? {
              exact: 'environment'
            } : 'user',
            width: {
              ideal: 1280
            },
            height: {
              ideal: 720
            }
          },
          audio: false
        };
        stream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = stream;
        video.classList.toggle('mirror', !useBackCamera);
        statusEl.textContent = 'Câmera pronta.';
        btnRetake.classList.add('d-none');
        snapshot.classList.add('d-none');
        video.classList.remove('d-none');
      } catch (e) {
        statusEl.textContent = 'Erro ao acessar a câmera: ' + e.message;
      }
    }

    btnFlip.addEventListener('click', async () => {
      await startWebcam();
    });

    btnRetake.addEventListener('click', async () => {
      snapshot.classList.add('d-none');
      video.classList.remove('d-none');
      btnRetake.classList.add('d-none');
    });

    async function getGeo() {
      return new Promise((resolve) => {
        if (!('geolocation' in navigator)) return resolve(null);
        navigator.geolocation.getCurrentPosition(
          (pos) => {
            if (pos.coords.accuracy > 100) {
              alert('A precisão da localização está baixa (~' + Math.round(pos.coords.accuracy) + 'm). Ative o GPS para maior exatidão.');
            }
            resolve({
              lat: pos.coords.latitude,
              lng: pos.coords.longitude,
              acc: pos.coords.accuracy
            });
          },
          () => resolve(null), {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
          }
        );
      });
    }

    function takeSnapshot() {
      // Downscale to max 1280x960 to reduzir payload, mantendo aspecto
      const vw = video.videoWidth || 1280;
      const vh = video.videoHeight || 960;
      const maxW = 1280,
        maxH = 960;
      const scale = Math.min(maxW / vw, maxH / vh, 1);
      const w = Math.round(vw * scale);
      const h = Math.round(vh * scale);

      canvas.width = w;
      canvas.height = h;

      const ctx = canvas.getContext('2d');
      // Se câmera frontal, espelha para a foto ficar natural
      if (!useBackCamera) {
        ctx.translate(w, 0);
        ctx.scale(-1, 1);
      }
      ctx.drawImage(video, 0, 0, w, h);
      const dataUrl = canvas.toDataURL('image/jpeg', 0.9);

      snapshot.src = dataUrl;
      snapshot.classList.remove('d-none');
      video.classList.add('d-none');
      btnRetake.classList.remove('d-none');

      return dataUrl;
    }

    function showFullScreenAlert(type, messageHtml, title) {
      fullAlertIcon.innerHTML = type === 'success' ?
        '<i class="bi bi-check-circle-fill text-success"></i>' :
        '<i class="bi bi-x-circle-fill text-danger"></i>';
      fullAlertTitle.textContent = title || (type === 'success' ? 'Ponto Registrado!' : 'Erro ao Registrar Ponto');
      fullAlertMsg.innerHTML = messageHtml;
      fullAlert.classList.add('show');
      setTimeout(() => {
        fullAlert.classList.remove('show');
        location.reload();
      }, 3500);
    }

    async function init() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        statusEl.textContent = 'Este dispositivo/navegador não suporta captura de câmera.';
        return;
      }
      await startWebcam();
    }
    init();

    document.getElementById('pointForm').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      setBusy(true);
      resultEl.innerHTML = '';
      statusEl.textContent = 'Capturando foto...';

      const fd = new FormData(ev.target);
      const pin = String(fd.get('pin') || '').trim();
      const cpf = String(fd.get('cpf') || '').replace(/\D/g, '');

      if (!pin) {
        statusEl.textContent = 'Informe o PIN.';
        setBusy(false);
        return;
      }

      const photo = takeSnapshot();
      statusEl.textContent = 'Obtendo localização...';
      const geo = await getGeo();

      statusEl.textContent = 'Enviando registro...';

      try {
        const body = {
          pin,
          photo,
          geo
        };
        if (cpf) body.cpf = cpf;

        const res = await fetch(`${APP_BASE}/api/checkin.php`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf
          },
          body: JSON.stringify(body),
          credentials: 'same-origin'
        });

        const txt = await res.text();
        let data;
        try {
          data = JSON.parse(txt);
        } catch (e) {
          throw new Error('Resposta inválida do servidor: ' + txt.slice(0, 160));
        }
        if (!res.ok || data.status !== 'ok') throw new Error(data.message || 'Erro no registro');

        const nome = data.collaborator?.name || data.teacher?.name || '';
        resultEl.innerHTML = `<div class="alert alert-success">
          <strong>${data.message}</strong><br>
          Colaborador: <b>${nome}</b><br>
          Ponto: <b>${data.action}</b><br>
          Horário: ${data.time}<br>
          <img src="${data.photo}" alt="Foto registrada" style="max-width:200px; border-radius:8px; margin-top:8px;">
        </div>`;
        statusEl.textContent = 'Ponto registrado!';

        showFullScreenAlert(
          'success',
          `${data.message}<br>Colaborador: <b>${nome}</b><br>Ponto: <b>${data.action}</b><br>Horário: ${data.time}`
        );
      } catch (e) {
        resultEl.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
        statusEl.textContent = 'Erro.';
        showFullScreenAlert('error', e.message);
      } finally {
        setBusy(false);
      }
    });
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
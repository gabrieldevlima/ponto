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

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <link rel="shortcut icon" href="img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="img/icone-2.ico" type="image/x-icon">

  <style>
    :root {
      --edge-pad: max(env(safe-area-inset-left), 16px);
      --edge-pad-r: max(env(safe-area-inset-right), 16px);
    }

    html,
    body {
      height: 100%;
    }

    body {
      background: #f6f7fb;
      -webkit-tap-highlight-color: transparent;
    }

    .screen {
      padding-left: var(--edge-pad);
      padding-right: var(--edge-pad-r);
    }

    .card-clean {
      background: #fff;
      border-radius: 14px;
      border: 1px solid rgba(0, 0, 0, .05);
      box-shadow: 0 6px 24px rgba(0, 0, 0, .06);
    }

    .form-label {
      font-weight: 600;
    }

    .touch-input {
      font-size: 20px !important;
      letter-spacing: .12rem;
      text-align: center;
    }

    .video-wrap {
      position: relative;
      border-radius: 12px;
      overflow: hidden;
      background: #000;
      aspect-ratio: 3/4;
    }

    video,
    #snapshot {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    #snapshot {
      display: none;
    }

    .cam-toggle {
      position: absolute;
      top: .5rem;
      right: .5rem;
      z-index: 2;
      border-radius: 999px;
    }

    .sticky-actions {
      position: sticky;
      bottom: 0;
      z-index: 3;
      background: #fff;
      padding-top: .5rem;
      padding-bottom: .5rem;
      border-top: 1px solid rgba(0, 0, 0, .06);
    }

    #status {
      min-height: 1.5rem;
      font-size: 1rem;
    }

    .hint {
      font-size: .95rem;
      color: #6c757d;
    }

    .full-alert {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .85);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 24px;
      z-index: 9999;
    }

    .full-alert.success {
      background: rgba(25, 135, 84, .98);
    }

    .full-alert.error {
      background: rgba(220, 53, 69, .98);
    }

    .fs-12 {
      font-size: 12px;
    }

    .d-none-important {
      display: none !important;
    }

    @media (min-width: 992px) {
      .video-wrap {
        aspect-ratio: 4/3;
      }
    }
  </style>
</head>

<body class="screen">
  <main class="container py-3">
    <header class="d-flex align-items-center justify-content-between mb-3" role="banner" aria-label="Topo">
      <a href="#pointForm" class="visually-hidden-focusable">Ir para o formulário</a>
      <div class="d-flex align-items-center gap-2">
        <span id="netBadge" class="badge bg-secondary ms-1" role="status" aria-live="polite">Verificando...</span>
      </div>
      <a id="btnAdmin" class="btn btn-outline-primary btn-sm" href="<?= esc($appBase) ?>/admin/login.php" aria-label="Área administrativa">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <span class="d-none d-sm-inline">Admin</span>
      </a>
    </header>

    <script>
      (function() {
        const badge = document.getElementById('netBadge');

        function updateNet() {
          const on = navigator.onLine;
          badge.className = 'badge ' + (on ? 'bg-success' : 'bg-secondary');
          const icon = on ? 'bi-wifi' : 'bi-wifi-off';
          const label = on ? 'Online' : 'Offline';
          badge.innerHTML = `<i class="bi ${icon} me-1" aria-hidden="true"></i>${label}`;
        }
        window.addEventListener('online', updateNet);
        window.addEventListener('offline', updateNet);
        updateNet();
      })();
    </script>

    <form id="pointForm" class="card-clean p-3 p-md-4 mx-auto" style="max-width:520px">
      <div class="d-flex justify-content-center mb-3">
        <img src="<?= esc($appBase) ?>/img/logo_login.png" alt="Ponto Eletrônico" width="200" loading="eager" decoding="async" style="height:auto;width:200px;">
      </div>
      <input type="hidden" id="cpf" name="cpf">
      <div class="mb-3">
        <label for="pin" class="form-label">PIN (6 dígitos)</label>
        <input type="password" class="form-control touch-input" id="pin" name="pin" required
          autocomplete="off" inputmode="numeric" pattern="\d{6}" minlength="6" maxlength="6"
          placeholder="••••••" autofocus>
      </div>

      <div class="mb-2">
        <label class="form-label d-block mb-0">Sua foto <small>(Posicione seu rosto aqui)</small></label>
        <div class="video-wrap mb-2">
          <video id="video" autoplay playsinline muted></video>
          <img id="snapshot" alt="Pré-visualização">
          <canvas id="canvas" class="d-none"></canvas>

          <button type="button" id="btnSwitchCam" class="btn btn-light btn-sm cam-toggle d-none">
            <i class="bi bi-arrow-repeat"></i>
          </button>
        </div>

        <input type="file" id="fileInput" accept="image/*" capture="user" class="form-control d-none">
        <div id="camFallback" class="hint d-none">
          Câmera do navegador indisponível.
          <button type="button" id="btnChooseFile" class="btn btn-outline-primary btn-sm ms-2">Usar câmera do aparelho</button>
        </div>

        <button type="button" id="btnRetake" class="btn btn-outline-secondary w-100 d-none">
          <i class="bi bi-camera"></i> Trocar foto
        </button>
      </div>

      <div class="sticky-actions mt-2">
        <button class="btn btn-success w-100 btn-icon" type="submit" id="btnReg">
          <span class="label"><i class="bi bi-check2-circle me-1"></i> Bater Ponto</span>
          <span class="spinner-border spinner-border-sm d-none ms-2" role="status" aria-hidden="true"></span>
        </button>
        <div id="status" class="text-muted mt-2" aria-live="polite"></div>
      </div>
    </form>
  </main>

  <script>
    // Service Worker
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('<?= esc($appBase) ?>/sw.js').catch(() => {});
    }
  </script>

  <script>
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const APP_BASE = (document.querySelector('meta[name="app-base"]').getAttribute('content') || '/').replace(/\/+$/, '');
    const ROOT_BASE = APP_BASE.replace(/\/public$/, '');

    const apiUrl = (ROOT_BASE || '') + '/api/checkin.php';
    const bulkUrl = (ROOT_BASE || '') + '/api/checkin_bulk.php';

    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const snapshot = document.getElementById('snapshot');
    const statusEl = document.getElementById('status');
    const btnReg = document.getElementById('btnReg');
    const btnSwitchCam = document.getElementById('btnSwitchCam');
    const btnRetake = document.getElementById('btnRetake');
    const fileInput = document.getElementById('fileInput');
    const camFallback = document.getElementById('camFallback');
    const btnChooseFile = document.getElementById('btnChooseFile');

    let stream = null;
    let currentFacing = 'user';
    let capturedDataUrl = null;

    // IndexedDB (pendências offline)
    let dbi;

    function openDB() {
      return new Promise((resolve, reject) => {
        const req = indexedDB.open('ponto-db', 1);
        req.onupgradeneeded = (e) => {
          const db = e.target.result;
          if (!db.objectStoreNames.contains('pending')) db.createObjectStore('pending', {
            keyPath: 'id',
            autoIncrement: true
          });
        };
        req.onsuccess = (e) => {
          dbi = e.target.result;
          resolve(dbi);
        };
        req.onerror = () => reject(req.error);
      });
    }
    async function savePending(payload) {
      try {
        if (!dbi) await openDB();
        return await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readwrite');
          tx.objectStore('pending').add({
            payload,
            createdAt: Date.now()
          });
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => reject(tx.error);
        });
      } catch {
        return false;
      }
    }
    async function drainPending() {
      try {
        if (!dbi) await openDB();
        const all = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readonly');
          const req = tx.objectStore('pending').getAll();
          req.onsuccess = () => resolve(req.result || []);
          req.onerror = () => reject(req.error);
        });
        if (!all.length) return;
        const items = all.map(x => x.payload);
        const res = await fetch(bulkUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf
          },
          body: JSON.stringify({
            items
          })
        });
        if (res.ok) {
          await new Promise((resolve, reject) => {
            const tx = dbi.transaction('pending', 'readwrite');
            tx.objectStore('pending').clear();
            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error);
          });
        }
      } catch {}
    }
    window.addEventListener('online', drainPending);
    drainPending();

    function setLoading(loading) {
      const spinner = btnReg.querySelector('.spinner-border');
      const label = btnReg.querySelector('.label');
      btnReg.disabled = loading;
      spinner.classList.toggle('d-none', !loading);
      label.style.opacity = loading ? .7 : 1;
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
        video.play().catch(() => {});
        currentFacing = preferFacing;
        statusEl.textContent = 'Câmera pronta.';
        camFallback.classList.add('d-none');
        fileInput.classList.add('d-none');
        btnSwitchCam.classList.remove('d-none');

        capturedDataUrl = null;
        snapshot.style.display = 'none';
        video.style.display = 'block';
        btnRetake.classList.add('d-none');
      } catch {
        statusEl.textContent = 'Câmera indisponível. Use a câmera do aparelho.';
        camFallback.classList.remove('d-none');
        fileInput.classList.remove('d-none');
        btnSwitchCam.classList.add('d-none');
      }
    }

    function stopWebcam() {
      if (stream) {
        stream.getTracks().forEach(t => t.stop());
        stream = null;
      }
    }
    btnSwitchCam.addEventListener('click', () => {
      const next = currentFacing === 'user' ? 'environment' : 'user';
      startWebcam(next);
    });
    btnRetake.addEventListener('click', () => {
      capturedDataUrl = null;
      snapshot.style.display = 'none';
      video.style.display = 'block';
      btnRetake.classList.add('d-none');
      statusEl.textContent = 'Aponte a câmera e toque em Registrar.';
    });

    if ('mediaDevices' in navigator && 'getUserMedia' in navigator.mediaDevices) {
      startWebcam('user');
    } else {
      camFallback.classList.remove('d-none');
      fileInput.classList.remove('d-none');
    }
    btnChooseFile?.addEventListener('click', () => fileInput.click());

    function downscaleToJpeg(srcCanvas, maxSide = 900, quality = 0.85) {
      const {
        width: w,
        height: h
      } = srcCanvas;
      const ratio = Math.min(1, maxSide / Math.max(w, h));
      const dw = Math.round(w * ratio);
      const dh = Math.round(h * ratio);
      const out = document.createElement('canvas');
      out.width = dw;
      out.height = dh;
      out.getContext('2d').drawImage(srcCanvas, 0, 0, dw, dh);
      return out.toDataURL('image/jpeg', quality);
    }

    // Helpers de avaliação de foto
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
      const w = video.videoWidth || 720;
      const h = video.videoHeight || 720;
      canvas.width = w;
      canvas.height = h;
      canvas.getContext("2d").drawImage(video, 0, 0, w, h);
      const dataUrl = downscaleToJpeg(canvas, 900, 0.85);
      snapshot.src = dataUrl;
      snapshot.style.display = 'block';
      video.style.display = 'none';
      btnRetake.classList.remove('d-none');
      return dataUrl;
    }

    function fileToDataURL(file, maxSide = 1200, quality = 0.85) {
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

    function getGeo() {
      return new Promise((resolve) => {
        if (!('geolocation' in navigator)) return resolve(null);
        navigator.geolocation.getCurrentPosition(
          (pos) => resolve({
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            acc: pos.coords.accuracy
          }),
          () => resolve(null), {
            enableHighAccuracy: true,
            timeout: 12000,
            maximumAge: 0
          }
        );
      });
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

    function buildDetailsHTML(data, fallbackCpf) {
      const name = data.name || data.teacher?.name || data.employee_name || data.employee?.name || '';
      const acao = normalizeActionLabel(data.action) || '';
      const hora = data.time || new Date().toLocaleTimeString('pt-BR');
      const dataBR = data.date || new Date().toLocaleDateString('pt-BR');
      return `
        <div style="font-size:2rem;font-weight:700">Ponto registrado!</div>
        <div style="margin-top:1rem;font-size:1.1rem">
          ${acao ? `<div><b>Ponto:</b> ${escHtml(acao)}</div>` : ''}
          <div><b>Hora:</b> ${escHtml(hora)}</div>
          <div><b>Data:</b> ${escHtml(dataBR)}</div>
          ${name ? `<div><b>Nome:</b> ${escHtml(name)}</div>` : ''}
        </div>`;
    }

    function showFullScreenAlert(type, htmlContent, autoReload = false) {
      const div = document.createElement('div');
      div.className = `full-alert ${type}`;
      div.innerHTML = `<div>${htmlContent}</div>`;
      document.body.appendChild(div);
      if (autoReload) {
        setTimeout(() => {
          location.reload();
        }, 4000);
      } else {
        setTimeout(() => div.remove(), 3200);
      }
    }

    document.getElementById('pointForm').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const cpf = (document.getElementById('cpf').value || '').trim();
      const pin = (document.getElementById('pin').value || '').trim();

      if (!pin || !/^\d{6}$/.test(pin)) {
        statusEl.textContent = 'Digite seu PIN de 6 dígitos.';
        document.getElementById('pin').focus();
        return;
      }

      setLoading(true);
      try {
        statusEl.textContent = 'Tirando foto...';

        // Tenta obter foto, mas não bloqueia caso não tenha
        if (!capturedDataUrl) {
          if (stream) {
            capturedDataUrl = takeSnapshotFromVideo();
          } else if (fileInput.files?.[0]) {
            capturedDataUrl = await fileToDataURL(fileInput.files[0], 1200, 0.85);
            snapshot.src = capturedDataUrl;
            snapshot.style.display = 'block';
            video.style.display = 'none';
            btnRetake.classList.remove('d-none');
          }
        }

        // Avalia qualidade da foto (mínimo simples)
        let incomplete = false;
        const reasons = [];
        const MIN_W = 300;
        const MIN_H = 300;
        const MIN_BYTES = 10 * 1024; // ~10KB

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
          statusEl.textContent = 'Sem foto. O ponto será salvo como aguardando confirmação.';
        }

        statusEl.textContent = 'Pegando localização...';
        const geo = await getGeo();
        if (!geo) {
          incomplete = true;
          reasons.push('localização ausente');
        }

        const payload = {
          cpf,
          pin,
          photo: capturedDataUrl || null,
          geo: geo || null,
          incomplete,
          reasons
        };

        statusEl.textContent = 'Enviando...';
        let onlineOk = false,
          data = null;
        try {
          const res = await fetch(apiUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrf
            },
            body: JSON.stringify(payload)
          });
          const text = await res.text();
          try {
            data = JSON.parse(text);
          } catch {
            throw new Error('Resposta inválida do servidor.');
          }
          if (!res.ok || data.status !== 'ok') throw new Error(data.message || 'Erro ao registrar ponto.');
          onlineOk = true;
        } catch (e) {
          await savePending(payload);
          data = {
            status: 'ok',
            action: 'offline',
            message: 'Sem conexão. Registro salvo e será sincronizado.'
          };
        }

        const pendingNote = incomplete ?
          `<div style="margin-top:1rem"><b>Status:</b> Aguardando confirmação.<br><span class="fs-12">Motivo: ${escHtml(reasons.join(', ') || 'informações incompletas')}.</span></div>` :
          '';

        statusEl.textContent = onlineOk ?
          (incomplete ? 'Ponto salvo como aguardando confirmação.' : 'Ponto registrado.') :
          (incomplete ? 'Sem internet: salvo para enviar. Aguardando confirmação.' : 'Sem internet: salvo para enviar depois.');

        if (onlineOk) {
          const detailsHTML = buildDetailsHTML(data || {}, cpf) + pendingNote;
          showFullScreenAlert('success', detailsHTML, true);
        } else {
          showFullScreenAlert(
            'error',
            `<div style="font-size:2rem;font-weight:700">Sem conexão</div>
             <div style="margin-top:1rem;font-size:1.1rem">Seu ponto foi salvo e será enviado automaticamente.</div>
             ${pendingNote}`,
            false
          );
        }
      } catch (e) {
        statusEl.textContent = e.message || 'Falha ao registrar.';
        showFullScreenAlert('error',
          `<div style="font-size:2rem;font-weight:700">Erro</div>
           <div style="margin-top:1rem;font-size:1.1rem">${escHtml(e.message || 'Tente novamente')}</div>`,
          false
        );
      } finally {
        setLoading(false);
      }
    });

    fileInput.addEventListener('change', async () => {
      capturedDataUrl = null;
      if (fileInput.files?.[0]) {
        capturedDataUrl = await fileToDataURL(fileInput.files[0], 1200, 0.85);
        snapshot.src = capturedDataUrl;
        snapshot.style.display = 'block';
        video.style.display = 'none';
        btnRetake.classList.remove('d-none');
        statusEl.textContent = 'Foto pronta. Toque em Registrar.';
      }
    });

    // Melhor foco no PIN em aparelhos que abrem o teclado depois
    window.setTimeout(() => document.getElementById('pin')?.focus(), 200);

    window.addEventListener('beforeunload', stopWebcam);

    (function() {
      const pinInput = document.getElementById('pin');
      if (!pinInput) return;

      // Sinaliza ao teclado mobile que é um campo numérico e a ação é "Concluir"
      pinInput.setAttribute('inputmode', 'numeric');
      pinInput.setAttribute('enterkeyhint', 'done');
      pinInput.setAttribute('maxlength', '6');

      const sanitize = (v) => (v || '').replace(/\D/g, '').slice(0, 6);

      const dismissKeyboard = () => {
        // Remove seleção/caret
        try {
          pinInput.setSelectionRange(0, 0);
        } catch {}
        // Blur padrão
        pinInput.blur();
        if (document.activeElement && typeof document.activeElement.blur === 'function') {
          document.activeElement.blur();
        }
        // iOS hack: alternar readonly para forçar o teclado a fechar
        const prev = pinInput.readOnly;
        pinInput.readOnly = true;
        setTimeout(() => {
          pinInput.readOnly = prev;
        }, 50);
      };

      pinInput.addEventListener('input', () => {
        const clean = sanitize(pinInput.value);
        if (pinInput.value !== clean) pinInput.value = clean;

        if (clean.length === 6) {
          dismissKeyboard();
        }
      });

      pinInput.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text') || '';
        const clean = sanitize(text);
        pinInput.value = clean;
        if (clean.length === 6) {
          dismissKeyboard();
        }
      });

      // Garante caret ao final quando focar (evita seleção do conteúdo)
      pinInput.addEventListener('focus', () => {
        requestAnimationFrame(() => {
          try {
            const len = pinInput.value.length;
            pinInput.setSelectionRange(len, len);
          } catch {}
        });
      });
    })();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?= esc($appBase) ?>/js/pin-mobile-blur.js"></script>
</body>

</html>
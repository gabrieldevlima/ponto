/**
 * PWA force-update (2026-05)
 * --------------------------------------------------------------------
 * Garante que clientes PWA instalados não fiquem rodando uma versão
 * antiga depois de um deploy. Detecta updates por DUAS vias:
 *
 *   1. Service Worker `updatefound` / `waiting` — quando o navegador
 *      busca o sw.js em segundo plano e percebe byte-diff.
 *   2. Header `X-App-Version` em qualquer fetch — pega o caso onde só
 *      o HTML/PHP mudou mas o sw.js não foi bumpado, e também serve
 *      como sinal mais rápido (não depende de revalidação do SW).
 *
 * Quando detecta, executa um auto-reload com guards de segurança:
 *   - Toast 'Atualizando para a nova versão...'
 *   - Aguarda janela segura (sem câmera, sem PIN sendo digitado, sem
 *     fetch em voo, sem modal aberto). Adia em incrementos de 5s.
 *   - Após no máximo 30s, força mesmo (não trava o usuário pra sempre).
 *   - Trottle: se já recarregou nos últimos 30s, não recarrega de novo
 *     (defense contra loop de reload se houver mismatch transitório).
 * --------------------------------------------------------------------
 */
(function () {
  'use strict';

  var meta = document.querySelector('meta[name="app-build"]');
  var CURRENT_BUILD = meta ? (meta.getAttribute('content') || '') : '';
  if (!CURRENT_BUILD) {
    if (window.console) console.warn('[PWA] meta name="app-build" ausente — force-update desabilitado');
    return;
  }

  var RELOAD_KEY = '__pwa_force_reload_at';
  var MIN_RELOAD_GAP_MS = 30000;
  var SAFE_CHECK_INTERVAL_MS = 5000;
  var MAX_DEFER_MS = 30000;
  var INITIAL_TOAST_DELAY_MS = 3000;

  var scheduled = false;
  var scheduledAt = 0;

  // -------- Detecção de "usuário ocupado" --------
  function isBusy() {
    // 1. Câmera ativa em alguma das streams conhecidas
    var camKeys = ['stepupStream', 'selfEnrollStream', 'cameraStream', 'faceStream', 'stream', '_stream'];
    for (var i = 0; i < camKeys.length; i++) {
      var s = window[camKeys[i]];
      if (s && typeof s.getTracks === 'function') {
        var tracks = s.getTracks();
        for (var j = 0; j < tracks.length; j++) {
          if (tracks[j].readyState === 'live') return true;
        }
      }
    }
    // 2. Foco em input com digitação recente
    var ae = document.activeElement;
    if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.isContentEditable)) {
      var lastInput = window.__pwaLastInput || 0;
      if (Date.now() - lastInput < 5000) return true;
    }
    // 3. Fetch em voo (contador atualizado abaixo)
    if (window.__pwaFetchInflight && window.__pwaFetchInflight > 0) return true;
    // 4. Modal aberto (Bootstrap, ou ARIA)
    if (document.querySelector('.modal.show, .modal.in, [aria-modal="true"][aria-hidden="false"]')) return true;
    return false;
  }

  document.addEventListener('input', function () { window.__pwaLastInput = Date.now(); }, true);

  // -------- Toast (usa o do app se existir) --------
  function notify() {
    if (typeof window.toast === 'function') {
      try {
        if (window.toast.length >= 3) {
          window.toast('info', 'Atualização disponível', 'Atualizando para a nova versão...', []);
        } else {
          window.toast('Atualizando para a nova versão...', 'info');
        }
        return;
      } catch (_) {}
    }
    var d = document.createElement('div');
    d.textContent = 'Atualizando para a nova versão...';
    d.setAttribute('role', 'status');
    d.style.cssText = [
      'position:fixed', 'left:50%', 'bottom:24px',
      'transform:translateX(-50%)',
      'background:#0066cc', 'color:#fff',
      'padding:12px 20px', 'border-radius:10px',
      'z-index:99999',
      'font:14px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
      'box-shadow:0 4px 16px rgba(0,0,0,.25)',
      'pointer-events:none'
    ].join(';');
    document.body.appendChild(d);
    setTimeout(function () { try { d.remove(); } catch (_) {} }, 4500);
  }

  // -------- Reload --------
  function performReload() {
    var last = 0;
    try { last = parseInt(sessionStorage.getItem(RELOAD_KEY) || '0', 10); } catch (_) {}
    if (last && (Date.now() - last) < MIN_RELOAD_GAP_MS) {
      if (window.console) console.warn('[PWA] reload throttled (loop guard)');
      scheduled = false; // libera para próxima tentativa após o gap
      return;
    }
    try { sessionStorage.setItem(RELOAD_KEY, String(Date.now())); } catch (_) {}

    if (!('serviceWorker' in navigator)) {
      window.location.reload();
      return;
    }

    var reloaded = false;
    var fire = function () {
      if (reloaded) return;
      reloaded = true;
      window.location.reload();
    };

    // Pede ao SW waiting que assuma. Quando ele assumir, controllerchange dispara
    // e a gente recarrega. Failsafe de 1.5s pra cobrir browsers/cenários estranhos.
    try {
      navigator.serviceWorker.addEventListener('controllerchange', fire, { once: true });
    } catch (_) {
      // older Safari pode não suportar { once: true }
      navigator.serviceWorker.addEventListener('controllerchange', fire);
    }

    navigator.serviceWorker.getRegistration().then(function (reg) {
      if (reg && reg.waiting) {
        try { reg.waiting.postMessage({ type: 'SKIP_WAITING' }); } catch (_) {}
      } else if (reg && reg.installing) {
        // novo worker ainda instalando: espera ele ficar pronto e então skip
        var nw = reg.installing;
        nw.addEventListener('statechange', function () {
          if (nw.state === 'installed') {
            try { nw.postMessage({ type: 'SKIP_WAITING' }); } catch (_) {}
          }
        });
      }
    }).catch(function () { /* ignore */ });

    setTimeout(fire, 1500);
  }

  function tick() {
    var elapsed = Date.now() - scheduledAt;
    if (elapsed >= MAX_DEFER_MS || !isBusy()) {
      performReload();
      return;
    }
    setTimeout(tick, SAFE_CHECK_INTERVAL_MS);
  }

  function scheduleForceReload(reason) {
    if (scheduled) return;
    scheduled = true;
    scheduledAt = Date.now();
    if (window.console) console.log('[PWA] update detected:', reason, '— current=' + CURRENT_BUILD);
    notify();
    setTimeout(tick, INITIAL_TOAST_DELAY_MS);
  }

  // -------- Estratégia 1: monkey-patch de fetch para olhar X-App-Version --------
  if (window.fetch) {
    var origFetch = window.fetch.bind(window);
    window.__pwaFetchInflight = 0;
    window.fetch = function () {
      window.__pwaFetchInflight++;
      var p;
      try { p = origFetch.apply(this, arguments); }
      catch (e) {
        window.__pwaFetchInflight = Math.max(0, window.__pwaFetchInflight - 1);
        throw e;
      }
      // Não usamos await: just observa
      p.then(function (res) {
        try {
          if (res && res.headers && typeof res.headers.get === 'function') {
            var v = res.headers.get('X-App-Version');
            if (v && v !== CURRENT_BUILD) {
              scheduleForceReload('header_mismatch:' + v);
            }
          }
        } catch (_) {}
      }).catch(function () {}).finally(function () {
        window.__pwaFetchInflight = Math.max(0, window.__pwaFetchInflight - 1);
      });
      return p;
    };
  }

  // -------- Estratégia 2: SW updatefound / waiting --------
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistration().then(function (reg) {
      if (!reg) return;
      // Se já há um worker waiting na hora do load, é porque essa página foi
      // servida antes do SW assumir o byte-diff — força update já.
      if (reg.waiting) scheduleForceReload('waiting_worker_on_load');

      reg.addEventListener('updatefound', function () {
        var nw = reg.installing;
        if (!nw) return;
        nw.addEventListener('statechange', function () {
          if (nw.state === 'installed' && navigator.serviceWorker.controller) {
            scheduleForceReload('sw_installed_new');
          }
        });
      });
    }).catch(function () { /* ignore */ });

    // Re-check ao voltar pra aba — index.php já faz isso, mas reforçamos aqui
    // pro ponto.php (que não tinha esse hook).
    var lastUpdateCheck = 0;
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState !== 'visible') return;
      var now = Date.now();
      if (now - lastUpdateCheck < 30000) return;
      lastUpdateCheck = now;
      navigator.serviceWorker.getRegistration().then(function (reg) {
        if (reg) reg.update().catch(function () {});
      }).catch(function () {});
    });
  }

  // Exposto pra debug e gatilho manual (ex: admin pode chamar pwaUpdate.forceReload())
  window.pwaUpdate = {
    currentBuild: CURRENT_BUILD,
    forceReload: function () { scheduleForceReload('manual'); },
    isBusy: isBusy
  };
})();

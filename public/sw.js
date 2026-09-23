const CACHE_VERSION = 'v2.60.0'; // 2026-09: versão local (ledger NSR, AFD/AEJ, âncora de tempo, LGPD) — força atualização dos PWAs instalados.
const CACHE_NAME = `ponto-cache-${CACHE_VERSION}`;
const CACHE_RUNTIME = `ponto-runtime-${CACHE_VERSION}`;
// C5: cache SEPARADO para os modelos face-api (~3MB). Bump de CACHE_VERSION
// (frequente) NÃO invalida MODEL_CACHE — modelos só são re-baixados quando
// MODEL_CACHE_VERSION sobe (raríssimo, só se o pipeline de face mudar).
// Em 3G, isso significa zero download repetido a cada release de UI.
const MODEL_CACHE_VERSION = 'v1';
const MODEL_CACHE = `ponto-models-${MODEL_CACHE_VERSION}`;
const MODEL_URLS = [
  './models/tiny_face_detector_model-weights_manifest.json',
  './models/tiny_face_detector_model.bin',
  './models/face_landmark_68_tiny_model-weights_manifest.json',
  './models/face_landmark_68_tiny_model.bin',
  './models/face_recognition_model-weights_manifest.json',
  './models/face_recognition_model.bin',
];

// Recursos essenciais a cachear durante install.
// Todos os paths são resolvidos relativos ao escopo do SW (self.registration.scope),
// portanto funcionam em qualquer base de deploy sem hardcode.
// BUG-008: index.php NÃO entra no precache. Capturar HTML autenticado em
// install (sem CSRF/sessão estabelecida) congela um token genérico que
// invalida o primeiro POST de sync. smartPageStrategy ainda popula o cache
// runtime na primeira visita online — a UX offline pós-login fica preservada.
const PRECACHE_URLS = [
  // Páginas principais
  './',
  './login.php',
  './ponto.php',
  './my_timesheet.php',
  // Manifest e ícones
  './manifest.json',
  './img/logo_login.png',
  './img/logo.png',
  './img/icone-2.ico',
  // Ícones PWA
  './img/icon-192x192.png',
  './img/icon-512x512.png',
  // Bootstrap CSS
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  // Bootstrap Icons
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff',
  // Bootstrap JS
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
  // face-api.js (loader)
  './js/face-api.min.js',
  // Modelos face-api ficam em MODEL_CACHE (longa duração)
];

// Install: pre-cachear recursos essenciais
self.addEventListener('install', (event) => {
  console.log('[SW] Installing...');
  event.waitUntil((async () => {
    try {
    const cache = await caches.open(CACHE_NAME);
      const runtime = await caches.open(CACHE_RUNTIME);

      // C5: modelos face-api em cache long-lived. Só baixa se o cache
      // ainda não os tem — evita re-download a cada bump de UI.
      const modelCache = await caches.open(MODEL_CACHE);
      await Promise.allSettled(MODEL_URLS.map(async (url) => {
        try {
          const existing = await modelCache.match(url);
          if (!existing) await modelCache.add(url);
        } catch (err) {
          console.warn(`[SW] Model cache miss for ${url}:`, err);
        }
      }));

      // Adiciona recursos com fallback para erros individuais
      const results = await Promise.allSettled(
        PRECACHE_URLS.map(url =>
          cache.add(url).catch(err => {
            console.warn(`[SW] Failed to cache: ${url}`, err);
            return null;
          })
        )
      );

      const successCount = results.filter(r => r.status === 'fulfilled').length;
      console.log(`[SW] Precache complete: ${successCount}/${PRECACHE_URLS.length} recursos`);
      
      // BUG-008: NÃO precachear index.php. smartPageStrategy popula em runtime
      // após primeira visita online — assim o HTML cacheado já tem CSRF/sessão
      // do usuário corrente. Precache em install pegava HTML pré-login.
      try {
        const pontoResponse = await fetch('./ponto.php');
        if (pontoResponse.ok) {
          await cache.put('./ponto.php', pontoResponse.clone());
          console.log('[SW] ponto.php cached successfully (alternative entry)');
        }
      } catch (err) {
        console.warn('[SW] Failed to cache ponto.php:', err);
      }
      
    } catch (err) {
      console.error('[SW] Precache failed:', err);
    }
    // Ativa imediatamente
    await self.skipWaiting();
  })());
});

// Activate: limpar caches antigos
self.addEventListener('activate', (event) => {
  console.log('[SW] Activating...');
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys.map(key => {
        // Mantém: cache atual de página + runtime atual + modelo (long-lived,
        // independente da versão de UI).
        if (key === CACHE_NAME || key === CACHE_RUNTIME || key === MODEL_CACHE) return;
        // Antigos ponto-models-* de versões anteriores também são limpos —
        // só preserva o MODEL_CACHE_VERSION atual.
        console.log('[SW] Deleting old cache:', key);
        return caches.delete(key);
      })
    );
    await self.clients.claim();
    console.log('[SW] Activated');
  })());
});

// Fetch: estratégias de cache inteligentes
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Apenas GET requests
  if (request.method !== 'GET') {
    return;
  }

  // IMPORTANTE: Páginas de administração não devem ser cacheadas
  // Sempre buscar da rede para garantir dados atualizados e evitar problemas de cache
  if (url.pathname.includes('/admin/')) {
    // Admin: Network-Only (sem cache)
    return; // Deixa o navegador fazer a requisição normalmente
  }

  // C5: modelos face-api — cache-first em MODEL_CACHE (long-lived).
  // Isso evita re-download de ~3MB a cada bump de UI.
  if (url.pathname.includes('/models/') && (url.pathname.endsWith('.bin') || url.pathname.endsWith('.json'))) {
    event.respondWith((async () => {
      try {
        const cached = await caches.match(request, { cacheName: MODEL_CACHE });
        if (cached) return cached;
        const fresh = await fetch(request);
        if (fresh && fresh.ok) {
          const cache = await caches.open(MODEL_CACHE);
          cache.put(request, fresh.clone()).catch(() => {});
        }
        return fresh;
      } catch (err) {
        // Última tentativa em qualquer cache antes de falhar
        const fallback = await caches.match(request);
        if (fallback) return fallback;
        throw err;
      }
    })());
    return;
  }

  // Estratégia por tipo de recurso
  // HOTFIX 2026-09: /api/ precisa ser testado ANTES do ramo '.php'. Antes, GETs
  // como /api/last_checkin.php caíam em smartPageStrategy, eram gravados no
  // cache e servidos velhos com rede lenta (>3 s) — estado "trabalhando/fora"
  // desatualizado na home e hora do servidor antiga.
  if (url.pathname.includes('/api/')) {
    event.respondWith(fetch(request).catch(() => new Response('{"status":"error","code":"offline","message":"Sem conexão"}', {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    })));
    return;
  }
  if (url.pathname.endsWith('.php') || url.pathname === '/' || url.pathname.endsWith('/') || url.pathname.includes('/public')) {
    // HTML/PHP: Cache-First quando offline, Network-First quando online
    event.respondWith(smartPageStrategy(request));
  } else if (url.hostname.includes('cdn.jsdelivr.net') || url.hostname.includes('cdnjs.cloudflare.com')) {
    // CDNs: Cache-First com timeout de rede
    event.respondWith(cacheFirstWithTimeout(request, 5000));
  } else if (url.pathname.match(/\.(css|js|woff2?|ttf|eot|svg|jpg|jpeg|png|gif|webp|ico)$/)) {
    // Assets estáticos: Cache-First com update em background
    event.respondWith(cacheFirstStrategy(request));
  } else if (url.pathname.includes('/api/')) {
    // APIs: Network-Only (IndexedDB é tratado no app)
    event.respondWith(fetch(request).catch(() => new Response('{"status":"error","message":"Sem conexão"}', {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    })));
  } else {
    // Padrão: Smart strategy
    event.respondWith(smartPageStrategy(request));
  }
});

// Background Sync: sincronizar pontos pendentes
self.addEventListener('sync', (event) => {
  console.log('[SW] Sync event:', event.tag);
  if (event.tag === 'sync-pending-points') {
    event.waitUntil(syncPendingPoints());
  }
});

// Notificações (opcional, para quando sincronização completar)
self.addEventListener('message', (event) => {
  console.log('[SW] Message received:', event.data);
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  if (event.data && event.data.type === 'SYNC_NOW') {
    syncPendingPoints();
  }
});

// ====== ESTRATÉGIAS DE CACHE ======

// Smart Page Strategy: Cache-First quando offline, Network-First quando online
async function smartPageStrategy(request) {
  const cache = await caches.open(CACHE_RUNTIME);
  
  // Tenta buscar do cache primeiro
  const cachedResponse = await cache.match(request);

  // navigator.onLine em SW é notoriamente estale (iOS) e false-positive em
  // captive portals. Sempre tenta a rede com timeout curto e cai no cache
  // apenas em falha real de fetch.

  // Tenta network primeiro com timeout curto
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 3000);
    
    const networkResponse = await fetch(request, { 
      signal: controller.signal 
    });
    
    clearTimeout(timeoutId);

    // HOTFIX 2026-09: navegação usa redirect 'manual' → resposta 'opaqueredirect'
    // com ok=false. Antes caía no cache: quem já estava logado e abria login.php
    // recebia o formulário velho (CSRF vencido) e logava de novo — um colaborador
    // acumulou 169 tokens "lembrar-me". O redirect deve ser seguido.
    if (networkResponse.type === 'opaqueredirect' || networkResponse.redirected) {
      return networkResponse;
    }

    if (networkResponse.ok) {
      // Atualiza cache em background
      cache.put(request, networkResponse.clone());
      console.log('[SW] Online: Network success, updated cache:', request.url);
      return networkResponse;
    }
    
    // Se rede retornou erro, usa cache se disponível
    if (cachedResponse) {
      console.log('[SW] Network error, serving from cache:', request.url);
      return cachedResponse;
    }
    
    return networkResponse;
  } catch (err) {
    // Se falhou (timeout ou offline), usa cache
    if (cachedResponse) {
      console.log('[SW] Network failed, serving from cache:', request.url);
      return cachedResponse;
    }
    
    // Fallback final: página offline
    return offlineFallback();
  }
}

// Network-First: tenta rede, fallback para cache
async function networkFirstStrategy(request) {
  const cache = await caches.open(CACHE_RUNTIME);
  const swController = new AbortController();
  const swTimeout = setTimeout(() => swController.abort(), 8000);
  try {
    const networkResponse = await fetch(request, { signal: swController.signal });
    clearTimeout(swTimeout);
    if (networkResponse.ok) {
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (err) {
    clearTimeout(swTimeout);
    const cached = await cache.match(request);
    if (cached) {
      console.log('[SW] Serving from cache (network failed):', request.url);
      return cached;
    }
    // Fallback final: página offline
    return offlineFallback();
  }
}

// Página offline genérica
function offlineFallback() {
  return new Response(`
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Sem conexão - DEEDO Ponto</title>
      <style>
        body { font-family: system-ui; text-align: center; padding: 2rem; background: #f6f7fb; color: #0f172a; }
        h1 { color: #dc2626; }
        p { max-width: 400px; margin: 1rem auto; line-height: 1.6; }
        button { background: #0d6efd; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-size: 1rem; }
        button:hover { background: #0b5ed7; }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
      </style>
    </head>
    <body>
      <div class="icon">📡</div>
      <h1>Sem conexão</h1>
      <p>Esta página não está disponível offline ainda. Por favor, conecte-se à internet e recarregue.</p>
      <button onclick="location.reload()">Tentar novamente</button>
    </body>
    </html>
  `, {
    status: 503,
    headers: { 'Content-Type': 'text/html; charset=utf-8' }
  });
}

// Cache-First: cache primeiro, atualiza em background
async function cacheFirstStrategy(request) {
  const cache = await caches.open(CACHE_RUNTIME);
  const cached = await cache.match(request);
  
  if (cached) {
    // Retorna do cache imediatamente
    // Atualiza em background (fire-and-forget)
    fetch(request).then(response => {
      if (response.ok) {
        cache.put(request, response);
      }
    }).catch(() => {});
    return cached;
  }

  // Se não estiver em cache, busca na rede
  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (err) {
    return new Response('Offline', { status: 503 });
  }
}

// Cache-First com timeout de rede
async function cacheFirstWithTimeout(request, timeout = 5000) {
  const cache = await caches.open(CACHE_RUNTIME);
  const cached = await cache.match(request);
  
  if (cached) {
    // Tenta atualizar com timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);
    
    fetch(request, { signal: controller.signal })
      .then(response => {
        clearTimeout(timeoutId);
        if (response.ok) {
          cache.put(request, response);
        }
      })
      .catch(() => {});
    
    return cached;
  }

  // Não está em cache, tenta rede com timeout
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);
  
  try {
    const response = await fetch(request, { signal: controller.signal });
    clearTimeout(timeoutId);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (err) {
    clearTimeout(timeoutId);
    return new Response('Offline', { status: 503 });
  }
}

// ====== SINCRONIZAÇÃO DE PONTOS PENDENTES ======

async function syncPendingPoints() {
  console.log('[SW] Starting sync of pending points...');

  try {
    // Abre IndexedDB
    const db = await openIndexedDB();
    const pending = await getAllPending(db);

    if (!pending || pending.length === 0) {
      console.log('[SW] No pending points to sync');
      return;
    }

    console.log(`[SW] Found ${pending.length} pending point(s) to sync`, pending);

    const appBasePath = self.registration.scope
      .replace(self.location.origin, '')
      .replace(/\/+$/, '');
    const rootBasePath = appBasePath.replace(/\/public$/, '');

    // Item 2: separa session_checkins (nova fila PIN-logado) da fila face legacy.
    // Session items vão unit-a-unit para checkin.php (idempotente via client_id).
    const sessionPending = pending.filter(p => p?.payload?.kind === 'session_checkin');
    const facePending    = pending.filter(p => p?.payload?.kind !== 'session_checkin');

    if (sessionPending.length) {
      const checkinUrl = self.location.origin + rootBasePath + '/api/checkin.php';
      const syncedIds = [];
      const PERMANENT_ERRORS = new Set([
        'pin_invalid','blocked_contact_admin','face_conflict','face_corrupt',
        'face_save_failed','self_enroll_not_allowed','teacher_not_found',
        'cpf_invalid','invalid_face_descriptor','csrf_invalid','stepup_nonce_invalid',
        'action_mismatch','photo_required','photo_invalid'
      ]);
      const BACKOFF_ERRORS = new Set([
        'offline','server_unreachable','server_error',
        'too_many_attempts','kiosk_abuse_detected'
      ]);
      for (const item of sessionPending) {
        const toSend = { ...item.payload };
        delete toSend.kind;
        // BUG-001: extrai CSRF capturado no momento da gravação offline e
        // envia em X-CSRF-Token. Sem isso, o servidor (csrf_verify) rejeita
        // o sync com 403 e o item fica preso em IDB até MAX_AGE_MS expirar.
        const itemCsrf = (item.payload && item.payload.csrf) || toSend.csrf || '';
        delete toSend.csrf;
        const sessionHeaders = { 'Content-Type': 'application/json' };
        if (itemCsrf) sessionHeaders['X-CSRF-Token'] = itemCsrf;
        try {
          // timeout 15s por request — rede "semi-morta" não trava o SW
          const controller = new AbortController();
          const timeoutId = setTimeout(() => controller.abort(), 15000);
          let r;
          try {
            r = await fetch(checkinUrl, {
              method: 'POST',
              headers: sessionHeaders,
              body: JSON.stringify(toSend),
              credentials: 'include',
              signal: controller.signal
            });
          } finally {
            clearTimeout(timeoutId);
          }
          const txt = await r.text();
          let rd = null; try { rd = JSON.parse(txt); } catch (_) {}
          const code = rd ? rd.code : '';
          if (rd && (rd.status === 'ok' || code === 'already_registered')) {
            syncedIds.push(item.id);
          } else if (code === 'session_expired') {
            // C3: notifica todas as abas abertas (postMessage) para que o
            // banner persistente apareça — sem isso, drain via background sync
            // expirava sem feedback ao usuário.
            try {
              const remaining = sessionPending.length - syncedIds.length;
              const allClients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
              for (const c of allClients) {
                try { c.postMessage({ type: 'SESSION_EXPIRED', remaining }); } catch (_) {}
              }
            } catch (_) {}
            break; // usuário precisa relogar; items ficam na fila
          } else if (BACKOFF_ERRORS.has(code) || r.status === 429) {
            // rate-limited ou servidor ocupado — espera próximo sync
            break;
          } else if (PERMANENT_ERRORS.has(code)) {
            // erro que não muda com retry — remove do IDB
            syncedIds.push(item.id);
            console.warn('[SW] item descartado por erro permanente:', code, item);
          }
          // Outros erros transitórios: mantém item para próximo sync
        } catch (e) {
          // Rede ainda instável (abort/timeout/offline) — para aqui
          break;
        }
      }
      if (syncedIds.length) await deletePendingByIds(db, syncedIds);
      console.log(`[SW] session sync: ${syncedIds.length}/${sessionPending.length} processados`);
      if (!facePending.length) return;
    }

    // Fluxo face legacy (bulk) — Fix #3: agora item-a-item para que cada
    // marcação leve seu próprio X-CSRF-Token. Antes, um único token (o do
    // primeiro item) era reusado no batch inteiro; se o primeiro era de
    // sessão obsoleta, todos eram rejeitados juntos. Cada POST usa
    // {items:[único]} — o servidor aceita batch de 1 sem mudança.
    const pending_face = facePending;
    const bulkUrl = self.location.origin + rootBasePath + '/api/checkin_bulk.php';
    const PERMANENT_BULK_ERRORS = new Set([
      'pin_invalid','pin_required','cpf_invalid','cpf_required','geo_required',
      'pin_not_set','self_enroll_not_allowed','teacher_not_found','csrf_invalid',
      'action_mismatch','photo_required','photo_invalid','photo_save_failed'
    ]);
    const BACKOFF_BULK_ERRORS = new Set([
      'offline','server_unreachable','server_error',
      'too_many_attempts','kiosk_abuse_detected'
    ]);

    console.log('[SW] Bulk URL:', bulkUrl, 'items:', pending_face.length);

    const successIds = [];
    let syncedCount = 0;
    let failedCount = 0;
    let discardedCount = 0;
    let csrfRejectedAll = false;

    for (const pendingItem of pending_face) {
      const single = { ...(pendingItem.payload || {}) };
      const itemCsrf = single.csrf || '';
      delete single.csrf;
      const headers = { 'Content-Type': 'application/json' };
      if (itemCsrf) headers['X-CSRF-Token'] = itemCsrf;

      const ctrl = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), 15000);
      let resp;
      try {
        try {
          resp = await fetch(bulkUrl, {
            method: 'POST',
            headers,
            body: JSON.stringify({ items: [single] }),
            credentials: 'include',
            signal: ctrl.signal,
          });
        } finally {
          clearTimeout(timer);
        }
      } catch (e) {
        // Erro de rede/timeout — para o drain inteiro; retry em próximo sync.
        console.warn('[SW] bulk item fetch failed:', e && e.message);
        break;
      }

      // 403 com csrf_invalid no nível HTTP (csrf_verify rejeitou antes do JSON):
      // não conta como falha permanente do item, mas o token está obsoleto e
      // todos os próximos itens deste drain provavelmente terão o mesmo destino.
      if (resp.status === 403) {
        const txt = await resp.text();
        if (txt && txt.indexOf('CSRF') !== -1) {
          csrfRejectedAll = true;
          failedCount++;
          break;
        }
      }

      if (!resp.ok) {
        console.warn('[SW] bulk item HTTP', resp.status);
        failedCount++;
        // Mantém na fila para retry (não trava o loop por status isolado).
        continue;
      }

      let body = null;
      try { body = await resp.json(); } catch (_) {}
      const result = body?.results?.[0]?.response;
      const itemStatus = result?.status;
      const itemCode = result?.code;

      if (itemStatus === 'ok' || itemCode === 'already_registered') {
        successIds.push(pendingItem.id);
        syncedCount++;
        continue;
      }
      if (itemStatus === 'discarded' && PERMANENT_BULK_ERRORS.has(itemCode)) {
        successIds.push(pendingItem.id);
        syncedCount++;
        discardedCount++;
        continue;
      }
      if (BACKOFF_BULK_ERRORS.has(itemCode)) {
        // Servidor pediu para parar; mantém o resto na fila.
        break;
      }
      // Outros erros transitórios: mantém para próximo sync.
      failedCount++;
    }

    if (successIds.length) await deletePendingByIds(db, successIds);

    console.log('[SW] Bulk sync finished:', { syncedCount, failedCount, discardedCount, csrfRejectedAll });

    if (csrfRejectedAll) {
      // Mesma semântica do session_checkin: CSRF inválido = sessão expirada.
      try {
        const remaining = pending_face.length - successIds.length;
        const allClients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
        for (const c of allClients) {
          try { c.postMessage({ type: 'SESSION_EXPIRED', remaining }); } catch (_) {}
        }
      } catch (_) {}
    }

    // Notifica clientes do resultado total
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
      client.postMessage({
        type: 'SYNC_SUCCESS',
        count: syncedCount,
        failed: failedCount
      });
    });
  } catch (err) {
    console.error('[SW] Sync error:', err);
    // Retry será tentado pelo Background Sync ou próximo 'online'
  }
}

// ====== HELPERS INDEXEDDB ======

function openIndexedDB() {
  return new Promise((resolve, reject) => {
    // Alinhado com public/index.php (versão 3) para evitar VersionError entre página e SW.
    const request = indexedDB.open('ponto-db', 3);

    request.onupgradeneeded = (e) => {
      const db = e.target.result;
      const oldVersion = e.oldVersion;

      if (oldVersion < 1) {
        if (!db.objectStoreNames.contains('pending')) {
          db.createObjectStore('pending', { keyPath: 'id', autoIncrement: true });
        }
      }
      // Versões 2/3: sem mudanças de schema — bump apenas para sincronizar entre contextos.
    };

    request.onblocked = () => {
      // Outra aba/SW mantém conexão antiga aberta; falha suave.
      reject(new Error('indexeddb_blocked'));
    };

    request.onsuccess = (e) => {
      const db = e.target.result;
      db.onversionchange = () => { try { db.close(); } catch (_) {} };
      resolve(db);
    };
    request.onerror = () => reject(request.error);
  });
}

function getAllPending(db) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction('pending', 'readonly');
    const store = tx.objectStore('pending');
    const request = store.getAll();
    
    request.onsuccess = () => resolve(request.result || []);
    request.onerror = () => reject(request.error);
  });
}

function clearPending(db) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction('pending', 'readwrite');
    const store = tx.objectStore('pending');
    const request = store.clear();
    
    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error);
  });
}

function deletePendingByIds(db, ids) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction('pending', 'readwrite');
    const store = tx.objectStore('pending');
    for (const id of ids) {
      store.delete(id);
    }
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

console.log('[SW] Service Worker loaded');

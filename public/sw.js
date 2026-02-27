const CACHE_VERSION = 'v2.2.1'; // fix face-api model filenames
const CACHE_NAME = `ponto-cache-${CACHE_VERSION}`;
const CACHE_RUNTIME = `ponto-runtime-${CACHE_VERSION}`;

// Recursos essenciais a cachear durante install
const PRECACHE_URLS = [
  // Páginas principais (múltiplas URLs para cobrir diferentes caminhos)
  '.',
  './',
  './index.php',
  '../public/',
  '../public/index.php',
  '/ponto/public/',
  '/ponto/public/index.php',
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
  // face-api.js (reconhecimento facial)
  './js/face-api.min.js',
  './models/tiny_face_detector_model-weights_manifest.json',
  './models/tiny_face_detector_model.bin',
  './models/face_landmark_68_tiny_model-weights_manifest.json',
  './models/face_landmark_68_tiny_model.bin',
  './models/face_recognition_model-weights_manifest.json',
  './models/face_recognition_model.bin'
];

// Install: pre-cachear recursos essenciais
self.addEventListener('install', (event) => {
  console.log('[SW] Installing...');
  event.waitUntil((async () => {
    try {
    const cache = await caches.open(CACHE_NAME);
      const runtime = await caches.open(CACHE_RUNTIME);
      
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
      
      // Cachear a página principal de forma garantida
      try {
        const indexResponse = await fetch('./index.php');
        if (indexResponse.ok) {
          await cache.put('./index.php', indexResponse.clone());
          await runtime.put('./', indexResponse.clone());
          await runtime.put('./index.php', indexResponse.clone());
          console.log('[SW] index.php cached successfully');
        }
      } catch (err) {
        console.warn('[SW] Failed to cache index.php:', err);
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
        if (key !== CACHE_NAME && key !== CACHE_RUNTIME) {
          console.log('[SW] Deleting old cache:', key);
          return caches.delete(key);
        }
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

  // Estratégia por tipo de recurso
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
  
  // Se estiver offline, retorna cache imediatamente
  if (!navigator.onLine && cachedResponse) {
    console.log('[SW] Offline: Serving from cache:', request.url);
    return cachedResponse;
  }
  
  // Se online, tenta network primeiro com timeout curto
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 3000);
    
    const networkResponse = await fetch(request, { 
      signal: controller.signal 
    });
    
    clearTimeout(timeoutId);
    
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
  try {
    const networkResponse = await fetch(request, { 
      signal: AbortSignal.timeout ? AbortSignal.timeout(8000) : undefined 
    });
    if (networkResponse.ok) {
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (err) {
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

    // Envia em lote
    const items = pending.map(p => p.payload);
    // Usa o CSRF token armazenado com o primeiro item
    const csrfToken = pending[0]?.csrf || null;
    
    const bulkUrl = self.location.origin + self.location.pathname.replace(/\/sw\.js.*/, '') + '/api/checkin_bulk.php';
    
    console.log('[SW] Bulk URL:', bulkUrl);
    console.log('[SW] CSRF Token:', csrfToken ? '✅ Presente' : '❌ Ausente');
    console.log('[SW] Sending items:', items);
    
    const headers = {
      'Content-Type': 'application/json'
    };
    
    // Adiciona CSRF token se disponível
    if (csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }
    
    const response = await fetch(bulkUrl, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify({ items })
    });

    console.log('[SW] Response status:', response.status);
    const responseText = await response.text();
    console.log('[SW] Response body:', responseText);

    if (response.ok) {
      // Limpa pendências
      await clearPending(db);
      console.log('[SW] Sync successful, cleared pending points');
      
      // Notifica todos os clientes
      const clients = await self.clients.matchAll();
      clients.forEach(client => {
        client.postMessage({
          type: 'SYNC_SUCCESS',
          count: pending.length
        });
      });
    } else {
      console.warn('[SW] Sync failed with status:', response.status, responseText);
      // Mantém na fila para retry
    }
  } catch (err) {
    console.error('[SW] Sync error:', err);
    // Retry será tentado pelo Background Sync ou próximo 'online'
  }
}

// ====== HELPERS INDEXEDDB ======

function openIndexedDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('ponto-db', 2); // Atualizado para versão 2
    
    request.onupgradeneeded = (e) => {
      const db = e.target.result;
      const oldVersion = e.oldVersion;
      
      // Versão 1: criar object store
      if (oldVersion < 1) {
        if (!db.objectStoreNames.contains('pending')) {
          db.createObjectStore('pending', { keyPath: 'id', autoIncrement: true });
        }
      }
      
      // Versão 2: estrutura já correta
      // Nenhuma mudança de schema necessária
    };
    
    request.onsuccess = (e) => resolve(e.target.result);
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

console.log('[SW] Service Worker loaded');

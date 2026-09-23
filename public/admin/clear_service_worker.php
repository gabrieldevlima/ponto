<?php
// BUG-002: a página deletava IndexedDB sem auth e sem aviso sobre pontos
// pendentes. Agora exige admin e o JS confirma quantos itens serão perdidos.
require_once __DIR__ . '/../../config.php';
require_admin();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Limpar Service Worker | DEEDO Ponto</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .log-item { 
      padding: 0.5rem; 
      margin: 0.25rem 0; 
      border-left: 3px solid #0d6efd; 
      background: #f8f9fa;
      font-family: monospace;
      font-size: 0.9rem;
    }
    .log-item.success { border-color: #198754; color: #198754; }
    .log-item.error { border-color: #dc3545; color: #dc3545; }
    .log-item.info { border-color: #0dcaf0; color: #0c63e4; }
  </style>
</head>
<body>
  <div class="container mt-5">
    <div class="card shadow">
      <div class="card-header bg-primary text-white">
        <h3 class="mb-0">
          <i class="bi bi-gear-fill me-2"></i>
          Limpeza de Service Worker e Cache
        </h3>
      </div>
      <div class="card-body">
        <div class="alert alert-info">
          <i class="bi bi-info-circle-fill me-2"></i>
          Esta ferramenta remove o Service Worker e limpa todo o cache do navegador.
          Use isso se estiver tendo problemas com páginas cacheadas incorretamente.
        </div>

        <div id="log" class="mb-3"></div>

        <div class="d-flex gap-2 flex-wrap">
          <button id="btnClear" class="btn btn-danger" onclick="clearEverything()">
            <i class="bi bi-trash-fill me-2"></i>
            Limpar Service Worker e Cache
          </button>
          <a href="teachers.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar para Colaboradores
          </a>
        </div>
      </div>
    </div>
  </div>

  <script>
    function log(message, type = 'info') {
      const logDiv = document.getElementById('log');
      const item = document.createElement('div');
      item.className = `log-item ${type}`;
      item.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
      logDiv.appendChild(item);
      logDiv.scrollTop = logDiv.scrollHeight;
    }

    async function clearEverything() {
      const btn = document.getElementById('btnClear');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Limpando...';
      
      document.getElementById('log').innerHTML = '';
      log('Iniciando limpeza...', 'info');

      try {
        // 1. Desregistrar Service Workers
        if ('serviceWorker' in navigator) {
          log('Buscando Service Workers registrados...', 'info');
          const registrations = await navigator.serviceWorker.getRegistrations();
          
          if (registrations.length === 0) {
            log('Nenhum Service Worker encontrado', 'info');
          } else {
            log(`Encontrados ${registrations.length} Service Worker(s)`, 'info');
            
            for (let registration of registrations) {
              log(`Desregistrando: ${registration.scope}`, 'info');
              await registration.unregister();
              log('✓ Service Worker desregistrado com sucesso', 'success');
            }
          }
        } else {
          log('Service Workers não suportados neste navegador', 'info');
        }

        // 2. Limpar todos os caches
        if ('caches' in window) {
          log('Buscando caches...', 'info');
          const cacheNames = await caches.keys();
          
          if (cacheNames.length === 0) {
            log('Nenhum cache encontrado', 'info');
          } else {
            log(`Encontrados ${cacheNames.length} cache(s)`, 'info');
            
            for (let name of cacheNames) {
              log(`Deletando cache: ${name}`, 'info');
              await caches.delete(name);
              log(`✓ Cache "${name}" deletado`, 'success');
            }
          }
        }

        // 3. Limpar IndexedDB (se existir)
        // BUG-002: antes de apagar, conta itens pendentes na fila offline.
        // Apagar IDB com pontos não sincronizados causa perda silenciosa
        // (violação Portaria 671). Exige confirmação explícita.
        try {
          log('Verificando IndexedDB...', 'info');
          const dbs = await indexedDB.databases();
          for (let db of dbs) {
            if (db.name && db.name.includes('ponto')) {
              const pendingCount = await countPendingInDb(db.name);
              if (pendingCount > 0) {
                const ok = confirm(
                  `ATENÇÃO: o banco "${db.name}" tem ${pendingCount} ponto(s) pendente(s) ` +
                  `de sincronização.\n\nSe continuar, esses pontos serão PERDIDOS ` +
                  `permanentemente (não há como recuperar).\n\nDeseja realmente apagar?`
                );
                if (!ok) {
                  log(`✗ Apagamento cancelado: "${db.name}" tem ${pendingCount} pendente(s)`, 'error');
                  continue;
                }
                log(`⚠ Apagando "${db.name}" com ${pendingCount} ponto(s) pendente(s) (admin confirmou)`, 'error');
              }
              log(`Deletando IndexedDB: ${db.name}`, 'info');
              await new Promise((resolve, reject) => {
                const request = indexedDB.deleteDatabase(db.name);
                request.onsuccess = () => {
                  log(`✓ IndexedDB "${db.name}" deletado`, 'success');
                  resolve();
                };
                request.onerror = () => reject(request.error);
              });
            }
          }
        } catch (err) {
          log(`IndexedDB: ${err.message}`, 'info');
        }

        log('═══════════════════════════════════', 'success');
        log('✓ LIMPEZA CONCLUÍDA COM SUCESSO!', 'success');
        log('═══════════════════════════════════', 'success');
        log('Por favor, RECARREGUE a página (Ctrl+Shift+R)', 'info');

        btn.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>Concluído!';
        btn.classList.remove('btn-danger');
        btn.classList.add('btn-success');

        // Auto-reload em 3 segundos
        setTimeout(() => {
          log('Recarregando página em 3... 2... 1...', 'info');
          setTimeout(() => location.reload(true), 1000);
        }, 2000);

      } catch (error) {
        log(`✗ ERRO: ${error.message}`, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash-fill me-2"></i>Tentar Novamente';
      }
    }

    // BUG-002: abre o IDB em modo somente-leitura e conta itens em 'pending'.
    // Não bloqueia: se a store não existir ou abrir falhar, retorna 0 (não há
    // o que perder).
    function countPendingInDb(dbName) {
      return new Promise((resolve) => {
        try {
          const req = indexedDB.open(dbName);
          req.onerror = () => resolve(0);
          // Fix #2: outra aba/SW pode estar com transação de versão aberta;
          // sem este handler, a Promise nunca resolveria e o botão ficaria
          // travado em "Limpando...". Resolve(0) força confirm sem contagem.
          req.onblocked = () => resolve(0);
          req.onsuccess = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('pending')) {
              try { db.close(); } catch (_) {}
              return resolve(0);
            }
            try {
              const tx = db.transaction('pending', 'readonly');
              const store = tx.objectStore('pending');
              const cnt = store.count();
              cnt.onsuccess = () => {
                resolve(cnt.result || 0);
                try { db.close(); } catch (_) {}
              };
              cnt.onerror = () => {
                resolve(0);
                try { db.close(); } catch (_) {}
              };
            } catch (_) {
              try { db.close(); } catch (_) {}
              resolve(0);
            }
          };
        } catch (_) { resolve(0); }
      });
    }

    // Log inicial
    log('Pronto para limpar. Clique no botão para começar.', 'info');
  </script>
</body>
</html>





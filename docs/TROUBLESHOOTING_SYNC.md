# 🔧 Troubleshooting: Sincronização Offline

## 🚨 Problema: Pontos não aparecem após sincronizar

Quando você registra pontos offline e depois conecta, mas os pontos não aparecem no admin.

---

## 📋 Checklist de Verificação

### 1️⃣ **Verificar se os pontos foram salvos localmente**

Abra o Console do navegador (F12 → Console) e execute:

```javascript
// Abrir IndexedDB e listar pontos pendentes
const req = indexedDB.open('ponto-db', 1);
req.onsuccess = (e) => {
  const db = e.target.result;
  const tx = db.transaction('pending', 'readonly');
  const store = tx.objectStore('pending');
  const getAll = store.getAll();
  getAll.onsuccess = () => {
    console.log('📦 Pontos pendentes:', getAll.result);
    if (getAll.result.length === 0) {
      console.log('⚠️ Nenhum ponto pendente encontrado!');
    } else {
      console.log(`✅ ${getAll.result.length} ponto(s) pendente(s)`);
      getAll.result.forEach((item, i) => {
        console.log(`Ponto ${i+1}:`, item);
      });
    }
  };
};
```

**Resultado esperado:**
- Se mostrar os pontos: ✅ Foram salvos localmente
- Se não mostrar: ❌ Não foram salvos (problema no salvamento offline)

---

### 2️⃣ **Forçar sincronização manual**

No Console, execute:

```javascript
// Forçar sincronização
drainPending();
```

**Observe os logs que aparecem:**
- `[Sync] Sincronizando X ponto(s)...`
- `[Sync] Enviando para: URL`
- `[Sync] Resposta HTTP: 200 OK`
- `[Sync] Sincronização completa!`

**Se aparecer erro:**
- Anote a mensagem de erro
- Veja o código de status HTTP

---

### 3️⃣ **Verificar CSRF Token**

No Console, verifique se o CSRF token é válido:

```javascript
console.log('CSRF Token:', csrf);
```

**Problema comum:**
- Se o token for `null` ou `undefined`: o sistema pode rejeitar a requisição
- Se você ficou muito tempo offline, o token pode ter expirado

**Solução:**
- Recarregue a página (F5)
- Isso pegará um novo CSRF token

---

### 4️⃣ **Verificar URL da API**

No Console, verifique se a URL está correta:

```javascript
console.log('API Bulk URL:', bulkUrl);
console.log('APP_BASE:', APP_BASE);
console.log('ROOT_BASE:', ROOT_BASE);
```

**Resultado esperado:**
```
API Bulk URL: /api/checkin_bulk.php
ou
API Bulk URL: http://localhost/ponto/api/checkin_bulk.php
```

**Se estiver errado:**
- A URL pode estar apontando para lugar errado
- Verifique o `APP_BASE` no HTML

---

### 5️⃣ **Verificar Logs do Service Worker**

Abra: **Application → Service Workers → Console do SW**

Ou execute no Console:

```javascript
navigator.serviceWorker.ready.then(reg => {
  reg.active.postMessage({ type: 'SYNC_NOW' });
});
```

**Observe os logs:**
- `[SW] Starting sync of pending points...`
- `[SW] Found X pending point(s) to sync`
- `[SW] Bulk URL: ...`
- `[SW] Response status: 200`

---

### 6️⃣ **Teste Manual Completo**

Execute este teste completo no Console:

```javascript
// Teste completo de sincronização
(async function testSync() {
  console.log('🧪 === TESTE DE SINCRONIZAÇÃO ===');
  
  // 1. Verificar IndexedDB
  console.log('\n1️⃣ Verificando IndexedDB...');
  const count = await getPendingCount();
  console.log(`   Pontos pendentes: ${count}`);
  
  if (count === 0) {
    console.log('   ⚠️ Nenhum ponto pendente! Registre um ponto offline primeiro.');
    return;
  }
  
  // 2. Verificar conexão
  console.log('\n2️⃣ Verificando conexão...');
  console.log(`   navigator.onLine: ${navigator.onLine}`);
  
  if (!navigator.onLine) {
    console.log('   ⚠️ Você está offline! Conecte-se primeiro.');
    return;
  }
  
  // 3. Verificar CSRF
  console.log('\n3️⃣ Verificando CSRF Token...');
  console.log(`   Token: ${csrf ? '✅ Presente' : '❌ Ausente'}`);
  
  // 4. Verificar URL
  console.log('\n4️⃣ Verificando URL da API...');
  console.log(`   Bulk URL: ${bulkUrl}`);
  
  // 5. Tentar sincronizar
  console.log('\n5️⃣ Tentando sincronizar...');
  await drainPending();
  
  // 6. Verificar resultado
  console.log('\n6️⃣ Verificando resultado...');
  const countAfter = await getPendingCount();
  console.log(`   Pontos pendentes após sync: ${countAfter}`);
  
  if (countAfter === 0) {
    console.log('   ✅ SUCESSO! Todos os pontos foram sincronizados!');
  } else {
    console.log('   ⚠️ ATENÇÃO! Ainda há pontos pendentes.');
    console.log('   Veja os logs acima para identificar o erro.');
  }
  
  console.log('\n🧪 === FIM DO TESTE ===');
})();
```

---

## 🐛 Problemas Comuns e Soluções

### Problema 1: CSRF Token Inválido

**Sintoma:**
```
[Sync] Resposta HTTP: 403 Forbidden
ou
"CSRF token inválido"
```

**Solução:**
1. Recarregue a página (F5) para pegar novo token
2. Registre os pontos novamente se necessário
3. Sincronize

---

### Problema 2: PIN Inválido

**Sintoma:**
```
[Sync] Alguns pontos falharam
"PIN inválido ou colaborador inativo"
```

**Solução:**
1. Verifique se o PIN está correto (6 dígitos)
2. Verifique se o colaborador está ativo no sistema
3. Limpe o IndexedDB se necessário:
```javascript
indexedDB.deleteDatabase('ponto-db');
location.reload();
```

---

### Problema 3: URL Incorreta

**Sintoma:**
```
[Sync] Resposta HTTP: 404 Not Found
```

**Solução:**
Verifique o `APP_BASE` no HTML (`meta[name="app-base"]`)

Se estiver errado, corrija no `config.php` ou no servidor.

---

### Problema 4: Dados Não Salvos

**Sintoma:**
IndexedDB mostra 0 pontos pendentes, mas você registrou.

**Solução:**
1. Verifique se o salvamento offline está funcionando:
```javascript
// Após registrar offline, execute:
getPendingCount().then(count => console.log('Pontos salvos:', count));
```

2. Se for 0, o problema é no salvamento, não na sincronização.

---

### Problema 5: Sincronização Não Dispara

**Sintoma:**
Você conecta, mas nada acontece (sem logs de sincronização).

**Solução:**
1. Force a sincronização manual:
```javascript
drainPending();
```

2. Ou clique no botão de sincronização no header (se tiver pontos pendentes).

3. Ou recarregue a página (que tenta sincronizar ao carregar).

---

## 🔍 Análise de Logs

### Logs de Sucesso (O que você DEVE ver):

```
[Sync] Sincronizando 2 ponto(s)...
[Sync] Enviando para: /api/checkin_bulk.php
[Sync] Payload: {items: Array(2)}
[Sync] Resposta HTTP: 200 OK
[Sync] Resposta do servidor: {status: "ok", results: Array(2)}
[Sync] Todos os pontos sincronizados com sucesso!
```

### Logs de Erro (Problemas a investigar):

```
❌ [Sync] Resposta HTTP: 403 Forbidden
   → CSRF token inválido ou expirado

❌ [Sync] Resposta HTTP: 404 Not Found
   → URL da API incorreta

❌ [Sync] Resposta HTTP: 500 Internal Server Error
   → Erro no servidor (verifique logs do PHP)

❌ [Sync] Alguns pontos falharam
   → PIN inválido, colaborador inativo, ou sem rotina no dia

❌ [Sync] Erro: Failed to fetch
   → Você ainda está offline
```

---

## 🛠️ Ferramentas de Debug

### Ver todos os pontos pendentes:

```javascript
async function showPending() {
  if (!dbi) await openDB();
  const all = await new Promise((resolve) => {
    const tx = dbi.transaction('pending', 'readonly');
    const req = tx.objectStore('pending').getAll();
    req.onsuccess = () => resolve(req.result || []);
  });
  console.table(all.map(x => ({
    id: x.id,
    criado: new Date(x.createdAt).toLocaleString('pt-BR'),
    pin: x.payload.pin,
    geo: x.payload.geo ? 'Sim' : 'Não'
  })));
}

showPending();
```

### Limpar todos os pontos pendentes (USE COM CUIDADO):

```javascript
async function clearAllPending() {
  if (!confirm('⚠️ ATENÇÃO! Isso vai apagar TODOS os pontos pendentes sem enviar. Confirma?')) {
    return;
  }
  if (!dbi) await openDB();
  await new Promise((resolve) => {
    const tx = dbi.transaction('pending', 'readwrite');
    tx.objectStore('pending').clear();
    tx.oncomplete = () => resolve();
  });
  console.log('✅ Todos os pontos pendentes foram removidos.');
  updatePendingCount();
}

clearAllPending();
```

---

## 📞 Próximos Passos

1. **Execute o teste completo** (seção 6️⃣)
2. **Copie todos os logs** que aparecem
3. **Verifique qual erro específico** está acontecendo
4. **Siga a solução** correspondente acima

---

## 🎯 Checklist Final

Antes de considerar que há um bug no sistema, verifique:

- [ ] Os pontos foram realmente salvos no IndexedDB?
- [ ] Você está realmente online quando tenta sincronizar?
- [ ] O CSRF token está presente e válido?
- [ ] A URL da API está correta?
- [ ] O PIN usado é válido e o colaborador está ativo?
- [ ] Há rotina configurada para o dia em questão?
- [ ] Os logs mostram algum erro específico?

---

**📧 Se ainda não funcionar após seguir todos os passos, compartilhe:**
1. Todos os logs do Console
2. Resposta do teste completo (seção 6️⃣)
3. Dados do IndexedDB (seção 1️⃣)

*Última atualização: Outubro 2025*


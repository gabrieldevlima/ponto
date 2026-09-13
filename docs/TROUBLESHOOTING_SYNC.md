# 🔧 Troubleshooting: Sincronização Offline

## Saneamento de pontos offline antigos

Antes do fix de maio/2026 múltiplos cliques offline geravam registros duplicados no banco (cada clique = novo UUID, escapava do dedupe por UNIQUE). Para limpar os duplicados que já existem, use a tela admin:

**Admin → Registros → Saneamento de Pontos Offline**

Permissão necessária: `attendance.dedupe`.

### Como funciona

- A tela detecta GRUPOS — registros do mesmo colaborador, mesma data, mesma ação (entrada ou saída), com horário a menos de 5 min uns dos outros, todos `approved=NULL` e `record_mode='offline'`.
- O 1º registro de cada grupo (mais antigo por `client_recorded_at`) é o recomendado para MANTER.
- Os demais ficam com `approved=0` + `superseded_by_id=<id_do_keeper>`. Continuam no banco para auditoria, mas saem da folha (`my_timesheet.php`) e da lista padrão de `attendances.php` — só aparecem com checkbox "Mostrar duplicatas".

### Modos

- **Aplicar recomendação (por grupo)** — 1 clique aplica a heurística automática.
- **Manual** — abre modal com radio buttons para escolher qual registro manter.
- **Aplicar a todos** — botão global processa todos os grupos da janela atual.
- **Pré-visualizar tudo** — devolve resumo (manteria N, soft-deletaria M) sem alterar nada.

### Auditoria

Cada decisão gera:
- 1 entrada em `audit_logs` com `action='admin_dedupe_keep'` para o keeper.
- 1 entrada em `audit_logs` com `action='admin_soft_delete_duplicate'` para cada superseded.
- 1 linha em `attendance_edits` com `type='admin_soft_delete_duplicate'` + snapshot completo do registro antes e depois.

Consultar:
```sql
SELECT created_at, action, entity_id, payload
FROM audit_logs
WHERE action IN ('admin_dedupe_keep','admin_soft_delete_duplicate')
ORDER BY created_at DESC LIMIT 50;
```

### Reversão

```sql
UPDATE attendance SET approved=NULL, superseded_by_id=NULL WHERE id=?;
```

### Lado PWA (fila local do dispositivo)

O usuário também pode inspecionar a própria fila local. Quando há itens pendentes, aparece um ícone `bi-clipboard-pulse` ao lado do contador de pendentes no header do PWA. Abre um modal que classifica os itens em:
- **Válidos** — serão enviados normalmente
- **Duplicados** — equivalentes a outro item válido na janela de 24h
- **Antigos** — criados há mais de 7 dias (recomendado descartar)

E permite: "Limpar duplicados", "Descartar antigos", "Sincronizar agora".

---

## Dedupe automático de tentativas múltiplas

A partir de maio/2026 o sistema usa três camadas de dedupe para evitar registros duplicados quando o usuário clica várias vezes offline:

1. **Frontend (IndexedDB):** antes de salvar no aparelho, o sistema procura um item pendente equivalente (mesmo CPF + mesma data + mesmo tipo de ponto na janela de 24 h). Se achar, **atualiza** o item existente (incrementa `attempts`, atualiza foto/geo) em vez de criar novo.
2. **Backend (UNIQUE constraint):** colunas `client_id` e `checkout_client_id` em `attendance` são UNIQUE. Mesmo UUID nunca insere duas vezes.
3. **Backend (dedupe lógico):** se chegarem dois UUIDs diferentes para o mesmo teacher + mesma data + mesmo tipo dentro de 5 minutos, o segundo recebe `status: 'ok'`, `code: 'duplicate_ignored'` e vira log de auditoria em `audit_logs.action='duplicate_ignored'`.

### Código de resposta novo: `duplicate_ignored`

| Campo | Valor |
|---|---|
| `status` | `ok` |
| `code` | `duplicate_ignored` |
| `message` | "Entrada/Saída equivalente já registrada — esta tentativa foi ignorada." |
| `attendance_id` | id do registro mantido |
| `nsr` | nsr do registro mantido |

**Não é erro.** O frontend trata como sucesso (deleta o item local) e mostra toast informativo:

> "X tentativa(s) repetida(s) foram identificadas e ignoradas com segurança. Sua folha de ponto não foi afetada."

### Para auditar tentativas duplicadas

```sql
SELECT created_at, entity_id AS attendance_kept, payload
FROM audit_logs
WHERE action = 'duplicate_ignored'
  AND entity = 'attendance'
ORDER BY created_at DESC
LIMIT 50;
```

O campo `payload` contém `rejected_client_id`, `kept_attendance_id`, `client_recorded_at` e o motivo (`logical_dedupe_entrada` ou `logical_dedupe_saida`).

---

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

### Problema 2: CPF Inválido

**Sintoma:**
```
[Sync] Alguns pontos falharam
"CPF não encontrado ou colaborador inativo"
```

**Solução:**
1. Verifique se o CPF está correto (11 dígitos)
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
   → CPF inválido, colaborador inativo, ou sem rotina no dia

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
    cpf: x.payload.cpf,
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
- [ ] O CPF usado é válido e o colaborador está ativo?
- [ ] Há rotina configurada para o dia em questão?
- [ ] Os logs mostram algum erro específico?

---

**📧 Se ainda não funcionar após seguir todos os passos, compartilhe:**
1. Todos os logs do Console
2. Resposta do teste completo (seção 6️⃣)
3. Dados do IndexedDB (seção 1️⃣)

*Última atualização: Outubro 2025*


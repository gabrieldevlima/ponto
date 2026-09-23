# Guia do Sistema PWA Offline - DEEDO Ponto

## 📋 Resumo da Implementação

O sistema de ponto foi transformado em um **PWA (Progressive Web App) completo** que funciona **100% offline**, permitindo que colaboradores registrem ponto sem internet e sincronizem automaticamente quando reconectarem.

---

## 🎯 Funcionalidades Implementadas

### ✅ PWA Completo
- **Manifest Web App** (`manifest.json`) configurado
- **Ícones PWA** em 9 tamanhos diferentes (48px até 512px)
- **Instalável** em dispositivos móveis e desktop
- **Apple Touch Icons** para suporte iOS
- **Theme color** e **background color** configurados

### ✅ Service Worker Avançado
- **Cache estratégico** de todos os recursos necessários
- **Cache de CDNs externos** (Bootstrap, Bootstrap Icons, MediaPipe)
- **Estratégias de cache inteligentes**:
  - **HTML/PHP**: Network-First com fallback para cache
  - **Assets estáticos (CSS/JS/fontes)**: Cache-First com update em background
  - **CDNs externos**: Cache-First com timeout de 5s
  - **Imagens**: Cache-First
  - **APIs**: Network-Only com fallback para IndexedDB
- **Background Sync API** para sincronização automática
- **Auto-atualização** com notificação ao usuário

### ✅ Modo Offline Completo
- **Salvamento local** em IndexedDB quando offline
- **Sincronização automática** quando reconectar
- **Background Sync** (Chrome/Edge) + fallback para outros navegadores
- **Retry logic** com tratamento de erros
- **Contador de pontos pendentes** no header
- **Botão de sincronização manual**
- **Feedback visual** durante sincronização

### ✅ UX Offline Melhorada
- **Badge de status**: Online/Offline/Sincronizando
- **Detecção facial desabilitada** quando offline (economia de recursos)
- **Mensagens informativas** contextuais
- **Toasts** de feedback ao salvar/sincronizar
- **Animações** de loading durante sincronização

---

## 🗂️ Arquivos Criados/Modificados

### Novos Arquivos
1. **`public/manifest.json`**
   - Configuração do PWA
   - Nome, ícones, theme color, display mode
   
2. **`public/img/icon-*.png`** (9 ícones)
   - 48x48, 72x72, 96x96, 120x120, 144x144
   - 152x152, 180x180, 192x192, 512x512

3. **`docs/PWA_OFFLINE_GUIDE.md`** (este arquivo)
   - Documentação completa da implementação

### Arquivos Modificados
1. **`public/sw.js`**
   - Cache estratégico completo
   - Background Sync implementation
   - Estratégias de cache inteligentes
   - Sincronização de pontos pendentes
   - Limpeza de caches antigos

2. **`public/index.php`**
   - Link para manifest.json
   - Apple Touch Icons
   - Service Worker com auto-atualização
   - Background Sync registration
   - Contador de pontos pendentes
   - Botão de sincronização manual
   - Badge de status melhorado
   - Detecção facial desabilitada offline
   - Mensagens contextuais de offline
   - Animação de spin para sincronização

---

## 🔄 Fluxo de Funcionamento

### 1️⃣ Registro Offline
```
Usuário tira foto → Confirma com CPF → Sistema detecta offline
→ Salva em IndexedDB com timestamp
→ Mostra toast: "Salvo offline - será enviado automaticamente"
→ Registra Background Sync (Chrome/Edge)
→ Contador de pendências aparece no header
```

### 2️⃣ Reconexão Automática
```
Sistema detecta conexão online
→ Background Sync dispara (Chrome/Edge) OU listener 'online' dispara
→ Badge muda para "Sincronizando..."
→ Recupera todos os pontos pendentes do IndexedDB
→ Envia em lote via checkin_bulk.php
→ Se sucesso: limpa IndexedDB + toast de sucesso + contador some
→ Se falha: mantém na fila + notifica erro (ex: CPF inválido)
```

### 3️⃣ Sincronização Manual
```
Usuário clica no botão "Sincronizar" (com contador)
→ Verifica se está online
→ Ícone começa a girar (animação)
→ Tenta sincronizar todos os pontos pendentes
→ Mostra resultado (sucesso ou erro)
→ Atualiza contador
```

---

## 🧪 Como Testar

### Teste 1: Modo Offline Básico
1. Abra o sistema no navegador
2. Abra as **DevTools** (F12)
3. Vá em **Application → Service Workers**
4. Marque **"Offline"**
5. Tente registrar um ponto
6. ✅ Deve salvar offline e mostrar toast
7. ✅ Contador deve aparecer no header (ex: "1")
8. Desmarque **"Offline"**
9. ✅ Deve sincronizar automaticamente em ~1s

### Teste 2: Múltiplos Pontos Offline
1. Simule offline (DevTools)
2. Registre 3 pontos diferentes
3. ✅ Contador deve mostrar "3"
4. Retorne online
5. ✅ Todos os 3 devem sincronizar em lote
6. ✅ Toast: "3 ponto(s) enviado(s) com sucesso!"

### Teste 3: Sincronização Manual
1. Simule offline e registre 1 ponto
2. ✅ Botão de sincronização aparece com badge "1"
3. Clique no botão (ainda offline)
4. ✅ Deve mostrar: "Conecte-se à internet para sincronizar"
5. Retorne online
6. Clique no botão
7. ✅ Ícone gira, sincroniza e contador desaparece

### Teste 4: CPF Inválido Offline
1. Simule offline
2. Registre um ponto com CPF errado
3. ✅ Salva offline normalmente
4. Retorne online
5. ✅ Tenta sincronizar e mostra erro: "CPF não encontrado"
6. ✅ Ponto **permanece na fila** para correção

### Teste 5: Instalação PWA
#### Chrome/Edge (Desktop/Mobile)
1. Acesse o sistema
2. ✅ Deve aparecer ícone de instalação na barra de endereço
3. Clique em "Instalar"
4. ✅ App abre em janela standalone

#### Safari iOS
1. Acesse o sistema no Safari
2. Toque em "Compartilhar" → "Adicionar à Tela de Início"
3. ✅ Ícone aparece na tela inicial
4. ✅ Abre como app standalone

### Teste 6: Cache e Velocidade
1. Acesse o sistema normalmente
2. Abra DevTools → Network
3. Recarregue a página (Ctrl+R)
4. ✅ Segunda carga deve ser **muito mais rápida**
5. ✅ Recursos marcados como "from ServiceWorker"

### Teste 7: Detecção Facial Offline
1. Simule offline
2. Abra a câmera
3. ✅ Deve mostrar: "📡 Offline: detecção facial desabilitada"
4. ✅ Guia estático circular aparece (sem overlay dinâmico)
5. ✅ Pode capturar manualmente normalmente

---

## 🔧 Estratégias de Cache

| Tipo de Recurso | Estratégia | Descrição |
|-----------------|-----------|-----------|
| HTML/PHP | Network-First | Busca na rede primeiro, fallback para cache |
| CSS/JS/Fontes | Cache-First | Cache primeiro, atualiza em background |
| CDNs (Bootstrap, etc) | Cache-First (5s timeout) | Cache primeiro, tenta rede com limite |
| Imagens | Cache-First | Cache primeiro sempre |
| APIs | Network-Only | Sempre busca na rede, fallback IndexedDB |

---

## 📱 Compatibilidade

### ✅ Totalmente Suportado
- **Chrome** 40+ (Desktop/Android)
- **Edge** 17+
- **Firefox** 44+
- **Safari** 11.1+ (Desktop/iOS)
- **Samsung Internet** 4+

### ⚠️ Suporte Parcial
- **iOS Safari**: Background Sync não suportado (usa listener 'online')
- **Firefox iOS**: Usa WebKit, limitações do Safari

### Funcionalidades por Browser

| Funcionalidade | Chrome/Edge | Firefox | Safari | iOS Safari |
|---------------|-------------|---------|--------|------------|
| Service Worker | ✅ | ✅ | ✅ | ✅ (11.1+) |
| Background Sync | ✅ | ❌ | ❌ | ❌ |
| IndexedDB | ✅ | ✅ | ✅ | ✅ |
| PWA Install | ✅ | ⚠️ | ✅ (16.4+) | ⚠️ |
| Offline Mode | ✅ | ✅ | ✅ | ✅ |

**Nota**: Mesmo sem Background Sync, o sistema funciona perfeitamente usando o listener 'online' como fallback.

---

## 🔒 Segurança

### ✅ Mantidas
- **CSRF token** continua validado no servidor
- **CPF** sempre validado no servidor
- **Fotos** armazenadas como base64 temporariamente (IndexedDB)
- **Dados sensíveis** não vão para cache (apenas IndexedDB)

### ⚠️ Considerações
- **IndexedDB** é acessível pelo JavaScript (mesma origem)
- **Cache** pode crescer (implementar limpeza periódica)
- **Limite de armazenamento**: ~50MB+ (varia por browser/SO)
- **Background Sync** não garante execução imediata

---

## 🛠️ Manutenção e Atualizações

### Atualização do Service Worker
1. Modifique `public/sw.js`
2. **Altere `CACHE_VERSION`** (ex: `v2.0.0` → `v2.0.1`)
3. Deploy
4. ✅ Usuários serão notificados: "Nova versão disponível!"

### Adicionar Novo Recurso ao Cache
Edite `PRECACHE_URLS` em `sw.js`:
```javascript
const PRECACHE_URLS = [
  // ... existentes
  './novo-recurso.js',
  './nova-imagem.png'
];
```

### Limpar Cache Manualmente
No console do navegador:
```javascript
caches.keys().then(keys => {
  keys.forEach(key => caches.delete(key));
  location.reload();
});
```

### Resetar IndexedDB
No console do navegador:
```javascript
indexedDB.deleteDatabase('ponto-db');
location.reload();
```

---

## 📊 Monitoramento

### Verificar Status do Service Worker
**Chrome DevTools**:
1. F12 → Application → Service Workers
2. ✅ Status: "activated and is running"

### Verificar Cache
**Chrome DevTools**:
1. F12 → Application → Cache Storage
2. Expandir `ponto-cache-v2.0.0`
3. Ver recursos cacheados

### Verificar IndexedDB
**Chrome DevTools**:
1. F12 → Application → IndexedDB
2. Expandir `ponto-db` → `pending`
3. Ver pontos pendentes

### Verificar Background Sync
**Chrome DevTools**:
1. F12 → Application → Background Sync
2. Ver tags registradas: `sync-pending-points`

---

## 🐛 Troubleshooting

### Problema: Service Worker não registra
**Solução**:
- Certifique-se de estar em HTTPS (ou localhost)
- Limpe cache e cookies
- Verifique se SW está habilitado nas configurações do browser

### Problema: Background Sync não funciona
**Solução**:
- Normal em Firefox/Safari (usa fallback 'online')
- Em Chrome/Edge, verifique permissões de background

### Problema: Cache não atualiza
**Solução**:
- Altere `CACHE_VERSION` no `sw.js`
- Force update: DevTools → Application → Service Workers → "Update"

### Problema: Pontos não sincronizam
**Solução**:
- Verifique conexão de rede
- Abra DevTools → Console para ver logs
- Verifique IndexedDB se há pontos pendentes
- Clique no botão de sincronização manual

### Problema: Ícones não aparecem
**Solução**:
- Certifique-se que os arquivos `icon-*.png` existem
- Verifique permissões de leitura dos arquivos
- Limpe cache e recarregue

---

## 📈 Melhorias Futuras (Opcional)

### Performance
- [ ] Lazy loading de MediaPipe quando online
- [ ] Web Worker para processamento de imagens
- [ ] Compression de fotos antes de salvar

### Features
- [ ] Notificações push quando sincronizar
- [ ] Indicador de progresso durante sync de múltiplos pontos
- [ ] Histórico local de pontos registrados
- [ ] Modo "Voo" explícito

### Analytics
- [ ] Track de uso offline vs online
- [ ] Métricas de tempo de sincronização
- [ ] Taxa de sucesso/falha de sync

---

## 📞 Suporte

Para dúvidas ou problemas:
1. Verifique o **Console do navegador** (F12)
2. Veja os **logs do Service Worker** em `chrome://serviceworker-internals`
3. Revise esta documentação

---

## ✅ Checklist de Implantação

- [x] Manifest.json criado e configurado
- [x] Service Worker implementado com cache estratégico
- [x] Background Sync implementado
- [x] IndexedDB configurado
- [x] Ícones PWA gerados (9 tamanhos)
- [x] UI de status offline implementada
- [x] Contador de pendências adicionado
- [x] Botão de sincronização manual
- [x] Mensagens de feedback implementadas
- [x] Detecção facial desabilitada offline
- [x] Documentação completa criada
- [ ] **Testado em produção**
- [ ] **Testado em dispositivos móveis reais**

---

**🎉 Sistema PWA Offline está completo e pronto para uso!**

*Última atualização: Outubro 2025*


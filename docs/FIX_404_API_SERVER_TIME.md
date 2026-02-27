# 🔧 FIX: Erro 404 em api/get_server_time.php

## 🎯 PROBLEMA

O arquivo **existe** no servidor, mas retorna **404** quando chamado.

**Causa:** Path incorreto sendo construído pelo JavaScript.

---

## ✅ SOLUÇÃO APLICADA

Atualizei `public/index.php` para construir o path corretamente:

### **Antes:**
```javascript
const appBase = document.querySelector('meta[name="app-base"]')?.content || '';
const timeServers = [
  appBase + '/api/get_server_time.php', // ❌ Path errado na produção
  ...
];
```

### **Depois:**
```javascript
const appBase = document.querySelector('meta[name="app-base"]')?.content || '';

// Constrói URL com fallback para path relativo
let serverTimeUrl;
if (appBase && appBase !== '/') {
  serverTimeUrl = appBase.replace(/\/public$/, '') + '/api/get_server_time.php';
} else {
  serverTimeUrl = '../api/get_server_time.php'; // ✅ Path relativo
}

const timeServers = [
  serverTimeUrl,
  ...
];
```

---

## 📦 FAÇA UPLOAD

```
Arquivo Local:
C:\xampp\htdocs\ponto\public\index.php

Destino:
public_html/ponto/public/index.php

(SOBRESCREVER)
```

---

## 🧪 TESTE

### **1. Limpe o Cache do Navegador**
- Ctrl + Shift + Delete
- Ou use aba anônima

### **2. Acesse o Registro de Ponto**
```
https://deedoponto.com/ponto/
```

### **3. Abra DevTools (F12) → Console**

### **4. Registre um Ponto**

**O que você deve ver:**
```
[HLB] Iniciando sincronização...
[HLB] Usando servidor: server
[HLB] ✅ Sincronizado com sucesso!
```

**NÃO deve aparecer:**
```
❌ GET .../api/get_server_time.php 404
```

---

## 🔍 DEBUG (Se ainda não funcionar)

### **Verifique o path sendo usado:**

No Console do navegador, digite:
```javascript
const appBase = document.querySelector('meta[name="app-base"]')?.content || '';
console.log('appBase:', appBase);
console.log('Path construído:', appBase.replace(/\/public$/, '') + '/api/get_server_time.php');
```

**Me envie o resultado!**

### **Teste direto o endpoint:**

Acesse cada uma destas URLs no navegador:

1. `https://deedoponto.com/api/get_server_time.php`
2. `https://deedoponto.com/ponto/api/get_server_time.php`
3. `https://deedoponto.com/ponto/public/../api/get_server_time.php`

**Qual delas funciona?** Me avise!

---

## 🎯 SOLUÇÃO ALTERNATIVA (Se nada funcionar)

### **Opção 1: Usar Apenas APIs Externas**

Edite `public/index.php` linha ~2074:

```javascript
const timeServers = [
  // serverTimeUrl, // ← Comente esta linha
  'https://worldtimeapi.org/api/timezone/America/Sao_Paulo',
  'https://timeapi.io/api/Time/current/zone?timeZone=America/Sao_Paulo'
];
```

**Desvantagem:** Um pouco mais lento, mas funciona.

### **Opção 2: URL Absoluta**

Se descobrir qual é o path correto (ex: teste 1, 2 ou 3 acima), force o path:

```javascript
const timeServers = [
  'https://deedoponto.com/ponto/api/get_server_time.php', // ← URL completa
  'https://worldtimeapi.org/api/timezone/America/Sao_Paulo',
  ...
];
```

---

## 📋 CHECKLIST

- [ ] Upload de `index.php` atualizado ✅
- [ ] Limpeza de cache do navegador ✅
- [ ] Teste registro de ponto ✅
- [ ] Verificação no console (sem erro 404) ✅

---

## 🆘 SE AINDA DER ERRO

**Execute este teste e me envie o resultado:**

No Console do DevTools:
```javascript
fetch('../api/get_server_time.php')
  .then(r => r.json())
  .then(data => console.log('✅ Funcionou!', data))
  .catch(e => console.error('❌ Erro:', e));
```

**Me avise o resultado! 🚀**


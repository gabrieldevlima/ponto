# 📁 ESTRUTURA: Produção vs Desenvolvimento

## 🔍 DIFERENÇA IDENTIFICADA

### **Desenvolvimento (Local):**
```
URL: http://localhost/ponto/
Estrutura física:
C:\xampp\htdocs\ponto\
├── public\
│   └── index.php (acesso: /ponto/)
├── api\
│   └── get_server_time.php
└── config.php
```

### **Produção (Hostinger):**
```
URL: https://deedoponto.com/
Estrutura física:
/home/u803039033/domains/deedoponto.com/public_html/
├── public\
│   └── index.php (acesso: /)
├── api\
│   └── get_server_time.php
└── config.php
```

**Diferença chave:** 
- **Dev:** Sistema em `/ponto/` (subpasta)
- **Prod:** Sistema em `/` (raiz do domínio)

---

## ✅ SOLUÇÃO APLICADA

Usei **path relativo** que funciona em ambos os cenários:

```javascript
// Path relativo desde o index.php
const timeServers = [
  '../api/get_server_time.php', // Sempre funciona!
  ...
];
```

### **Como funciona:**

#### **Em Desenvolvimento:**
```
URL atual: http://localhost/ponto/ (aponta para /ponto/public/)
Path relativo: ../api/get_server_time.php
Resolve para: http://localhost/ponto/api/get_server_time.php ✅
```

#### **Em Produção:**
```
URL atual: https://deedoponto.com/ (aponta para /public/)
Path relativo: ../api/get_server_time.php
Resolve para: https://deedoponto.com/api/get_server_time.php ✅
```

---

## 📦 ESTRUTURA CORRETA DE ARQUIVOS

### **Estrutura Física (Produção):**
```
public_html/
├── api/
│   ├── checkin.php
│   ├── checkin_bulk.php
│   ├── get_receipt.php
│   ├── get_server_time.php ✅
│   └── save_face.php
├── public/
│   ├── admin/
│   │   ├── dashboard.php
│   │   └── ... (outras páginas admin)
│   ├── img/
│   ├── photos/
│   ├── index.php ✅ (página principal)
│   └── my_timesheet.php
├── config.php
├── helpers.php
└── install_production_complete.sql
```

### **URLs de Acesso (Produção):**
```
https://deedoponto.com/                        → public/index.php
https://deedoponto.com/admin/dashboard.php     → public/admin/dashboard.php
https://deedoponto.com/../api/get_server_time.php → api/get_server_time.php
```

---

## 🚀 AÇÃO NECESSÁRIA

**Faça upload de:**
```
Local:  C:\xampp\htdocs\ponto\public\index.php
Remoto: public_html/public/index.php
```

**SOBRESCREVER o arquivo existente!**

---

## 🧪 TESTE

### **1. Acesse:**
```
https://deedoponto.com/
```

### **2. Abra DevTools (F12) → Console**

### **3. Registre um ponto**

**Console deve mostrar:**
```
[HLB] Iniciando sincronização...
[HLB] Tentando: ../api/get_server_time.php
[HLB] ✅ Sincronizado com sucesso!
[HLB] Fonte: server
```

### **4. Teste direto o endpoint:**

No Console, execute:
```javascript
fetch('../api/get_server_time.php')
  .then(r => r.json())
  .then(d => console.log('✅ API funciona!', d))
  .catch(e => console.error('❌ Erro:', e));
```

**Resultado esperado:**
```javascript
✅ API funciona! {
  datetime: "2025-01-22T15:30:00...",
  timezone: "America/Sao_Paulo",
  source: "server",
  ...
}
```

---

## 📋 CHECKLIST DE VALIDAÇÃO

- [ ] Upload de `public/index.php` atualizado ✅
- [ ] Limpar cache do navegador ✅
- [ ] Acesso a `https://deedoponto.com/` funciona ✅
- [ ] Registro de ponto sem erro 404 ✅
- [ ] Console mostra "Fonte: server" ✅

---

## 🆘 SE AINDA DER 404

### **Verifique se o arquivo existe:**

Teste cada URL diretamente no navegador:

1. `https://deedoponto.com/api/get_server_time.php`
2. `https://deedoponto.com/../api/get_server_time.php`

**Qual funciona?** Me avise!

### **Verifique a estrutura de pastas:**

Via SSH ou Gerenciador de Arquivos:
```bash
ls -la ~/public_html/
# Deve ter: api/, public/, config.php, helpers.php

ls -la ~/public_html/api/
# Deve ter: get_server_time.php (entre outros)

ls -la ~/public_html/public/
# Deve ter: index.php, admin/, img/
```

---

**Faça upload do `index.php` e teste! Agora deve funcionar! 🚀**


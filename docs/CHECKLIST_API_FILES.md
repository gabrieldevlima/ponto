# ✅ CHECKLIST - Arquivos da Pasta API

## 📦 ARQUIVOS QUE DEVEM ESTAR NA PRODUÇÃO

Verifique se TODOS estes arquivos existem em: `public_html/ponto/api/`

### **Lista Completa:**

1. ✅ `checkin.php` - Registra ponto individual
2. ✅ `checkin_bulk.php` - Registra múltiplos pontos (sync offline)
3. ✅ `get_receipt.php` - Retorna dados do comprovante digital
4. ✅ `get_server_time.php` - **[FALTANDO]** Sincronização HLB
5. ✅ `save_face.php` - Salva descritores faciais (reconhecimento facial)

---

## 🚀 UPLOAD RÁPIDO (Via FTP)

Faça upload de TODA a pasta `api/` de uma vez:

```
Local:
C:\xampp\htdocs\ponto\api\

Destino:
public_html/ponto/api/

(SUBSTITUIR todos os arquivos)
```

**Tempo:** ~30 segundos

---

## 🔍 VERIFICAÇÃO PÓS-UPLOAD

### **Via Navegador:**

Teste cada endpoint:

```
1. https://deedoponto.com/ponto/api/get_server_time.php
   ✅ Deve retornar JSON com hora

2. https://deedoponto.com/ponto/api/checkin.php
   ⚠️ Deve dar erro (precisa de POST), mas não 404

3. https://deedoponto.com/ponto/api/get_receipt.php
   ⚠️ Deve dar erro (precisa de params), mas não 404
```

### **Via SSH:**

```bash
cd ~/public_html/ponto/api
ls -la

# Deve listar:
# checkin.php
# checkin_bulk.php
# get_receipt.php
# get_server_time.php
# save_face.php
```

---

## 📋 PERMISSÕES CORRETAS

Todos os arquivos devem ter permissão `644`:

```bash
cd ~/public_html/ponto/api
chmod 644 *.php
```

**Ou via Gerenciador de Arquivos:**
- Selecione todos os `.php`
- Clique com direito → Permissões
- Defina: `644` (rw-r--r--)

---

## 🎯 FOCO IMEDIATO

**Arquivo crítico que está faltando:**
```
api/get_server_time.php
```

**Upload este primeiro** e teste o registro de ponto.

Os outros arquivos provavelmente já existem, mas é bom conferir!

---

**FAÇA UPLOAD DA PASTA API COMPLETA! 📦**


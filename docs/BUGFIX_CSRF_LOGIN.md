# 🐛 Correção: CSRF Token Inválido no Login do Colaborador

## 🚨 Problema

Ao tentar fazer login no Portal do Colaborador (`my_login.php`), aparecia o erro:

```json
{
  "status": "error",
  "message": "CSRF token inválido"
}
```

---

## 🔍 Causa Raiz

### Incompatibilidade de Nomes

O formulário enviava o token com o nome **`csrf_token`**:
```html
<input type="hidden" name="csrf_token" value="...">
```

Mas a função `csrf_verify()` procurava por **`csrf`**:
```php
$token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
```

**Resultado:** Token não era encontrado → validação falhava → erro 403.

---

## ✅ Solução Aplicada

### Correção 1: Formulário (`my_login.php`)

**ANTES:**
```html
<input type="hidden" name="csrf_token" value="...">
```

**DEPOIS:**
```html
<input type="hidden" name="csrf" value="...">
```

### Correção 2: Validação (`helpers.php`)

Melhorei o `csrf_verify()` para aceitar **ambos** os nomes:

**ANTES:**
```php
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
         $_SERVER['HTTP_X-CSRF-TOKEN'] ?? 
         $_POST['csrf'] ?? 
         $_GET['csrf'] ?? '';
```

**DEPOIS:**
```php
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
         $_SERVER['HTTP_X-CSRF-TOKEN'] ?? 
         $_POST['csrf'] ?? 
         $_POST['csrf_token'] ?? 
         $_GET['csrf'] ?? 
         $_GET['csrf_token'] ?? '';
```

**Benefício:** Agora aceita tanto `csrf` quanto `csrf_token` (compatibilidade).

---

## 🧪 Como Testar

### Teste 1: Login do Colaborador
```bash
1. Acesse: http://localhost/ponto/public/my_login.php
2. Digite um PIN válido (ex: 123456)
3. Clique em "Acessar"
4. ✅ Deve fazer login sem erro
5. ✅ Deve redirecionar para my_timesheet.php
```

### Teste 2: Verificar Token no HTML
```bash
1. Acesse my_login.php
2. Clique com botão direito → Inspecionar
3. Procure por: <input type="hidden" name="csrf"
4. ✅ Deve existir com um valor longo (token)
```

### Teste 3: Console do Navegador
```bash
1. Abra Console (F12)
2. Tente fazer login
3. ✅ NÃO deve aparecer erro 403
4. ✅ NÃO deve aparecer "CSRF token inválido"
```

---

## 📊 Comparação

| Aspecto | ANTES | DEPOIS |
|---------|-------|--------|
| **Campo no form** | `csrf_token` ❌ | `csrf` ✅ |
| **Validação aceita** | Apenas `csrf` | `csrf` E `csrf_token` ✅ |
| **Login funciona** | ❌ Erro 403 | ✅ Funciona |
| **Compatibilidade** | Limitada | Completa ✅ |

---

## 🔐 Segurança Mantida

A correção **não afeta a segurança**:

✅ Token continua sendo gerado aleatoriamente  
✅ Validação com `hash_equals()` (timing-attack safe)  
✅ Token armazenado na sessão  
✅ Verificação em todas as requisições POST  

**Mudou apenas:** O nome do campo aceito na validação.

---

## 📝 Lições Aprendidas

### 1. Padronização de Nomes
É importante manter consistência nos nomes dos campos entre:
- Formulários HTML
- Funções de validação
- APIs

### 2. Flexibilidade na Validação
A função agora aceita múltiplos nomes:
- `csrf` (padrão)
- `csrf_token` (alternativo)
- Headers HTTP (para AJAX)

### 3. Mensagens de Erro Claras
O erro era claro: "CSRF token inválido"
Facilitou identificar o problema rapidamente.

---

## 🔄 Compatibilidade

### Formulários Existentes

A mudança é **retrocompatível**:

**Formulários com `csrf`:**
```html
<input name="csrf" value="..."> ✅ Funciona
```

**Formulários com `csrf_token`:**
```html
<input name="csrf_token" value="..."> ✅ Funciona
```

**Requisições AJAX:**
```javascript
headers: {
  'X-CSRF-Token': token  ✅ Funciona
}
```

---

## 🐛 Troubleshooting

### Ainda recebo erro de CSRF

**Verifique:**

1. **Token existe no formulário?**
```bash
# Inspecionar elemento e procurar:
<input type="hidden" name="csrf" value="...">
```

2. **Sessão está ativa?**
```php
// No topo do arquivo PHP:
session_start(); // Deve existir
```

3. **Token está sendo enviado?**
```bash
# No Console do navegador, aba Network:
# Veja o POST request
# FormData deve incluir: csrf: "..."
```

4. **Valor do token é o mesmo?**
```php
// Debug temporário:
echo '<pre>';
echo 'Token Sessão: ' . $_SESSION['csrf_token'] . "\n";
echo 'Token Enviado: ' . ($_POST['csrf'] ?? 'AUSENTE') . "\n";
echo '</pre>';
```

---

## ✅ Checklist de Verificação

- [x] Campo do formulário corrigido (name="csrf")
- [x] Validação aceita csrf e csrf_token
- [x] Login funcionando sem erro 403
- [x] Sessão sendo mantida após login
- [x] Redirecionamento para my_timesheet.php
- [x] Compatibilidade com outros formulários
- [x] Documentação atualizada

---

## 📚 Referências

### Arquivos Modificados

1. **`public/my_login.php`**
   - Linha 106: `name="csrf"` (antes: `csrf_token`)

2. **`helpers.php`**
   - Linha 16: Aceita `csrf` e `csrf_token`

### Funções Relacionadas

- `csrf_token()` - Gera/retorna token
- `csrf_verify()` - Valida token
- `session_start()` - Inicia sessão (config.php)

---

## 🎯 Resumo

**Problema:** Campo `csrf_token` não era reconhecido  
**Causa:** Incompatibilidade de nomes  
**Solução:** Padronizar para `csrf` + aceitar ambos na validação  
**Resultado:** Login funcionando perfeitamente ✅

---

**🎉 Correção aplicada com sucesso!**

*Data: Outubro 2025*
*Afeta: Portal do Colaborador*


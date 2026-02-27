# 🧪 TESTE - Motivos de Pendência

## 🎯 PROBLEMA

Coluna `pending_reasons` foi adicionada, mas os motivos não estão sendo salvos.

---

## 🔍 DEBUG ATIVADO

Adicionei um log de debug no `api/checkin.php` (linha ~461):

```php
error_log("DEBUG pending_reasons - Teacher: $teacherId, Network: " . ($isNetworkWide ? 'YES' : 'NO') . ", Reasons: " . ($pendingReasonsJson ?? 'NULL'));
```

---

## 📦 UPLOAD NECESSÁRIO

Faça upload do arquivo atualizado:
```
api/checkin.php → public_html/api/checkin.php (SOBRESCREVER)
```

---

## 🧪 TESTE PASSO A PASSO

### **Teste 1: Verificar se coluna existe**

No phpMyAdmin, execute:
```sql
DESCRIBE attendance;
```

**Deve aparecer:**
```
pending_reasons | text | YES | NULL
```

✅ Se aparecer, a coluna está OK.

### **Teste 2: Registrar Ponto de Teste**

1. Acesse: `https://deedoponto.com/`
2. Registre um ponto (com ou sem foto/geo)
3. Anote o horário exato

### **Teste 3: Verificar o que foi salvo**

No phpMyAdmin:
```sql
SELECT 
    id,
    teacher_id,
    date,
    check_in,
    approved,
    pending_reasons,
    photo,
    check_in_lat,
    check_in_lng
FROM attendance 
ORDER BY id DESC 
LIMIT 1;
```

**Analise:**
- `pending_reasons` está NULL? ❌ Problema na lógica
- `pending_reasons` tem JSON? ✅ Funcionando!
  - Exemplo: `["Sem foto","Sem localização"]`

### **Teste 4: Verificar Logs do Servidor**

Se a Hostinger permite acesso a logs de erro PHP:

**Via hPanel → Arquivos → Error Logs**

Procure por:
```
DEBUG pending_reasons - Teacher: X, Network: NO, Reasons: ["Sem foto"]
```

Isso confirma que a lógica está executando.

---

## 🐛 POSSÍVEIS PROBLEMAS

### **Problema 1: Coluna não existe na produção**

**Solução:**
```sql
-- Execute novamente:
ALTER TABLE attendance 
ADD COLUMN pending_reasons TEXT NULL;
```

### **Problema 2: INSERT não está incluindo o campo**

**Verificação:** Conte os placeholders no INSERT

Linha ~463:
```sql
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
```

Deve ter **22 placeholders** (21 campos originais + 1 pending_reasons).

### **Problema 3: Lógica não está construindo motivos**

**Debug:**

Adicione no `api/checkin.php` antes da linha do `pendingReasonsJson`:

```php
error_log("DEBUG - hasFilename: " . ($filename ? 'YES' : 'NO'));
error_log("DEBUG - hasGeo: " . (($lat && $lng) ? 'YES' : 'NO'));
error_log("DEBUG - isNetworkWide: " . ($isNetworkWide ? 'YES' : 'NO'));
error_log("DEBUG - photoQualityReasons: " . json_encode($photoQualityReasons));
error_log("DEBUG - pendingReasons array: " . json_encode($pendingReasons));
```

---

## 🎯 CENÁRIOS DE TESTE

### **Cenário A: Colaborador Normal sem Foto e sem Geo**

```
Input:
- Colaborador: network_wide = 0
- Foto: NÃO
- Localização: NÃO

Esperado:
- approved = NULL (pendente)
- pending_reasons = ["Sem foto", "Sem localização"]

Visualização:
⏰ Pendente
ℹ️ Motivo(s): Sem foto, Sem localização
```

### **Cenário B: Colaborador Network-Wide sem Geo**

```
Input:
- Colaborador: network_wide = 1
- Foto: SIM
- Localização: NÃO

Esperado:
- approved = 1 (aprovado)
- pending_reasons = NULL (sem motivos)

Visualização:
✅ Aprovado
```

### **Cenário C: Foto com Baixa Qualidade**

```
Input:
- Colaborador: network_wide = 0
- Foto: SIM (mas escura/borrada)
- Localização: SIM

Esperado:
- approved = NULL (pendente)
- pending_reasons = ["Foto muito escura"] (ou outro motivo)

Visualização:
⏰ Pendente
ℹ️ Motivo(s): Foto muito escura
```

---

## 📝 CHECKLIST DE VERIFICAÇÃO

- [ ] Upload de `api/checkin.php` atualizado ✅
- [ ] Coluna `pending_reasons` existe no banco ✅
- [ ] Registro de ponto de teste ✅
- [ ] Verificação no banco (SELECT com pending_reasons) ✅
- [ ] pending_reasons tem valor JSON ou NULL conforme esperado ✅
- [ ] Motivos aparecem em attendances.php ✅
- [ ] Motivos aparecem em my_timesheet.php ✅

---

## 🆘 SE AINDA NÃO SALVAR

**Me envie:**

1. Resultado do `DESCRIBE attendance;` (confirmar coluna)
2. Resultado do `SELECT` após registrar ponto (últimos 2 registros)
3. Valor de `network_wide` do colaborador testado

**Com essas informações, identifico exatamente o que falta! 🚀**



# 🚀 DEPLOY - Motivos de Pendência

## ✅ SQL CORRIGIDO

Execute este SQL simplificado no **phpMyAdmin**:

```sql
-- Adiciona coluna para motivos de pendência
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS pending_reasons TEXT NULL 
COMMENT 'Motivos JSON quando ponto fica pendente';

SELECT '✓ Coluna adicionada com sucesso!' AS status;
```

---

## 📦 UPLOAD DOS ARQUIVOS

Faça upload para **public_html/**:

```
1. api/checkin.php
2. public/admin/attendances.php  
3. public/my_timesheet.php
```

---

## 🧪 TESTE

### **1. Configure um colaborador network_wide:**

No phpMyAdmin:
```sql
UPDATE teachers SET network_wide = 1 WHERE id = 1;
```

### **2. Teste Registro:**

**Colaborador Normal (network_wide = 0):**
- Registre sem localização → ⚠️ Pendente
- Motivo: "Sem localização"

**Colaborador Rede (network_wide = 1):**
- Registre sem localização → ✅ Aprovado (se tiver foto)
- Sem motivo de localização

### **3. Visualize:**

- **Admin:** `attendances.php` → Veja motivos abaixo do badge
- **Colaborador:** `my_timesheet.php` → Veja motivos abaixo do badge

---

## ✅ PRONTO!

Execute o SQL e faça upload! 🎯


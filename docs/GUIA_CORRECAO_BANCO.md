# 🔧 Guia de Correção do Banco de Dados

## ❌ Problemas Identificados

1. ❌ Coluna `pending_reasons` não existe em `attendance`
2. ❌ Colunas `editado_por`, `data_edicao`, `motivo_edicao` não existem em `attendance`
3. ❌ Tabela `attendance_edits` não existe
4. ❌ Tabela `audit_logs` não existe

---

## ✅ Solução: Execute 1 Script Consolidado

### **📄 Arquivo: `fix_missing_tables_and_columns.sql`**

Este script resolve **todos os problemas** de uma vez:
- ✅ Adiciona `pending_reasons` na tabela `attendance`
- ✅ Adiciona colunas de rastreamento de edição (`editado_por`, `data_edicao`, `motivo_edicao`)
- ✅ Cria tabela `attendance_edits` (histórico de edições)
- ✅ Cria tabela `audit_logs` (auditoria geral)

---

## 🚀 Passo a Passo

### **1️⃣ Acesse o phpMyAdmin no Hostinger**
```
https://hpanel.hostinger.com/
```

### **2️⃣ Selecione o banco `u803039033_ponto`**

### **3️⃣ Vá em "SQL"**

### **4️⃣ Cole o conteúdo completo do arquivo:**
`fix_missing_tables_and_columns.sql`

### **5️⃣ Clique em "Executar"**

---

## ✅ Verificação

Após executar, verifique se as tabelas foram criadas:

```sql
-- Verificar se as colunas foram adicionadas
SHOW COLUMNS FROM attendance LIKE '%edit%';
SHOW COLUMNS FROM attendance LIKE 'pending_reasons';

-- Verificar se as tabelas foram criadas
SHOW TABLES LIKE 'attendance_edits';
SHOW TABLES LIKE 'audit_logs';
```

**Resultado esperado:**
```
editado_por         | int          | YES | NULL
data_edicao         | datetime     | YES | NULL
motivo_edicao       | text         | YES | NULL
pending_reasons     | text         | YES | NULL

attendance_edits    | BASE TABLE
audit_logs          | BASE TABLE
```

---

## 📝 O Que Cada Tabela Faz

### **`attendance_edits`** (Histórico de Edições)
Registra **cada edição** feita em um ponto:
- Quem editou
- O que foi mudado
- Estado antes/depois (JSON)
- Delta de minutos

### **`audit_logs`** (Auditoria Geral)
Registra **todas as ações administrativas**:
- Aprovações/rejeições
- Criação/edição de colaboradores
- Criação/edição de escolas
- Alterações de configurações

---

## 🎯 Após Executar

Faça upload do arquivo **`attendance_edit.php`** atualizado e teste a edição de ponto novamente!

---

## 🆘 Se Der Erro

Se aparecer erro de **foreign key**, execute linha por linha:

```sql
-- 1. Adicionar colunas primeiro
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS pending_reasons TEXT NULL;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS editado_por INT NULL;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS data_edicao DATETIME NULL;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS motivo_edicao TEXT NULL;

-- 2. Criar tabelas SEM foreign keys primeiro
CREATE TABLE IF NOT EXISTS attendance_edits (...) -- Sem FOREIGN KEY
CREATE TABLE IF NOT EXISTS audit_logs (...) -- Sem FOREIGN KEY

-- 3. Adicionar foreign keys depois
ALTER TABLE attendance_edits ADD FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE;
ALTER TABLE attendance_edits ADD FOREIGN KEY (edited_by) REFERENCES admins(id) ON DELETE RESTRICT;
ALTER TABLE audit_logs ADD FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT;
```



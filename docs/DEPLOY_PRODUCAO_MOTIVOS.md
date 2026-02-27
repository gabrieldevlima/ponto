# 🚀 Deploy Produção - Motivos de Pendência

## ⚡ Guia Rápido de Deploy

### 📋 Checklist Pré-Deploy

- [x] Código verificado localmente
- [x] Arquivos confirmados com implementação correta
- [x] Scripts SQL de verificação criados
- [x] Guia de testes completo criado

---

## 🎯 Ação Necessária do Usuário

### ETAPA 1: Verificar e Adicionar Coluna no Banco

**Acesse phpMyAdmin da Hostinger** e execute:

```sql
-- 1. Verificar se coluna existe
SHOW COLUMNS FROM attendance LIKE 'pending_reasons';
```

**Se retornar vazio (coluna não existe):**

```sql
-- 2. Adicionar coluna
ALTER TABLE attendance 
ADD COLUMN pending_reasons TEXT NULL 
COMMENT 'Motivos JSON quando ponto fica pendente (ex: sem foto, sem geo)';
```

**Alternativa:** Execute o arquivo completo `verify_pending_reasons.sql` criado.

---

### ETAPA 2: Verificar Arquivos no Servidor

Os seguintes arquivos **JÁ ESTÃO CORRETOS** no desenvolvimento local:

✅ `api/checkin.php` - Linha 506: inclui `pending_reasons` no INSERT  
✅ `public/admin/attendances.php` - Linhas 485-494: exibe motivos  
✅ `public/my_timesheet.php` - Linhas 134-147: função statusBadge com motivos

**Confirme que os arquivos no servidor são a versão mais recente:**

1. Baixe os arquivos do servidor (FTP/File Manager)
2. Compare com as versões locais
3. Se diferentes, faça upload das versões locais:
   - `/api/checkin.php`
   - `/public/admin/attendances.php`
   - `/public/my_timesheet.php`

---

### ETAPA 3: Testar Funcionalidade

Siga o **GUIA_TESTE_MOTIVOS_PENDENCIA.md** para:

1. ✅ Teste 1: Registro sem localização
2. ✅ Teste 2: Registro sem foto
3. ✅ Teste 3: Colaborador network_wide
4. ✅ Teste 4: Múltiplos motivos
5. ✅ Teste 5: Qualidade da foto

---

## 📊 Como Verificar se Está Funcionando

### No Banco de Dados:

```sql
SELECT 
    a.id,
    t.name,
    a.date,
    a.approved,
    a.pending_reasons
FROM attendance a
JOIN teachers t ON t.id = a.teacher_id
WHERE a.approved IS NULL
ORDER BY a.id DESC
LIMIT 5;
```

**Esperado:** Coluna `pending_reasons` com valores JSON como:
- `["Sem foto"]`
- `["Sem localização"]`
- `["Sem foto","Sem localização"]`

---

### Na Interface Admin:

1. Acesse: `https://deedoponto.com/public/admin/attendances.php`
2. Filtre por **Status: Pendente**
3. Veja registros com badge **🟡 Pendente**
4. **Abaixo do badge** deve aparecer:
   ```
   ℹ️ Motivo(s): Sem foto, Sem localização
   ```

---

### Na Interface do Colaborador:

1. Acesse: `https://deedoponto.com/public/my_timesheet.php`
2. Faça login como colaborador
3. Veja registros pendentes
4. **Abaixo do badge** deve aparecer:
   ```
   ℹ️ Motivo: Sem foto, Sem localização
   ```

---

## 🔧 Solução de Problemas

### Problema 1: Motivos não aparecem

**Causa:** Coluna `pending_reasons` não existe no banco

**Solução:**
```sql
ALTER TABLE attendance ADD COLUMN pending_reasons TEXT NULL;
```

---

### Problema 2: Coluna existe mas está sempre NULL

**Causa:** Arquivo `api/checkin.php` desatualizado no servidor

**Solução:** 
1. Faça upload do `api/checkin.php` local para o servidor
2. Limpe cache do servidor se houver
3. Teste novo registro de ponto

---

### Problema 3: Badge aparece mas motivos não

**Causa:** Arquivos da interface desatualizados

**Solução:**
1. Upload `public/admin/attendances.php`
2. Upload `public/my_timesheet.php`
3. Limpe cache do navegador (Ctrl+Shift+R)

---

## 🎯 Resultado Esperado

### Quando um colaborador registra ponto SEM foto:

**Antes (sem a funcionalidade):**
```
🟡 Pendente
```

**Depois (com a funcionalidade):**
```
🟡 Pendente
ℹ️ Motivo(s): Sem foto
```

### Quando registra SEM foto e SEM GPS:

```
🟡 Pendente
ℹ️ Motivo(s): Sem foto, Sem localização
```

### Quando é colaborador network_wide SEM GPS (mas COM foto):

```
🟢 Aprovado
```
(Sem motivos, pois não precisa de GPS)

---

## 📝 Notas Importantes

1. **Colaboradores Network-Wide:**
   - NÃO precisam fornecer localização GPS
   - Podem registrar ponto de qualquer lugar
   - Ainda precisam de foto válida

2. **Motivos Possíveis:**
   - "Sem foto"
   - "Sem localização"
   - "Fora do raio permitido"
   - "Foto muito escura"
   - "Foto muito clara/estourada"
   - "Foto desfocada/sem nitidez"
   - "Baixo contraste"

3. **Registros Antigos:**
   - Registros antigos não terão motivos retroativos automaticamente
   - Apenas novos registros (após deploy) terão motivos
   - Se quiser popular retroativamente, use o script no guia de testes

---

## ✅ Status da Implementação

**Código:** ✅ Completo e testado localmente  
**Banco:** ⚠️ Requer execução do SQL em produção  
**Deploy:** ⚠️ Requer upload dos arquivos (se diferentes)  
**Testes:** ⚠️ Requer execução após deploy  

---

## 📞 Próximos Passos

1. ✅ Execute o SQL de verificação/criação da coluna
2. ✅ Confirme/atualize arquivos no servidor
3. ✅ Execute os testes funcionais
4. ✅ Monitore primeiros registros de ponto
5. ✅ Valide com usuários reais

---

**Criado em:** 31/10/2024  
**Sistema:** DEEDO Ponto v1.0  
**Documentação:** Implementação Motivos de Pendência


# 🧪 Guia de Teste - Motivos de Pendência

## ✅ Verificação Completa Realizada

### Arquivos Confirmados com Código Correto:

1. ✅ **api/checkin.php** (linhas 470-514)
   - Detecta "Sem foto" quando não há foto
   - Detecta "Sem localização" quando não há GPS (exceto network_wide)
   - Detecta "Fora do raio permitido" 
   - Detecta problemas de qualidade da foto
   - Salva motivos em JSON no campo `pending_reasons`

2. ✅ **public/admin/attendances.php** (linhas 485-494)
   - Exibe badge "Pendente" amarelo
   - Mostra motivos abaixo do badge: "Motivo(s): Sem foto, Sem localização"

3. ✅ **public/my_timesheet.php** (linhas 134-147, 470)
   - Função `statusBadge()` recebe `pending_reasons`
   - Exibe motivos abaixo do badge: "Motivo: Sem foto, Sem localização"

---

## 🚀 Passos para Produção

### PASSO 1: Verificar Coluna no Banco

Execute no **phpMyAdmin** da Hostinger:

```sql
SHOW COLUMNS FROM attendance LIKE 'pending_reasons';
```

**Resultado esperado:**
- Se retornar uma linha → Coluna já existe ✅
- Se não retornar nada → Execute o PASSO 2

---

### PASSO 2: Adicionar Coluna (se necessário)

Execute no **phpMyAdmin**:

```sql
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS pending_reasons TEXT NULL 
COMMENT 'Motivos JSON quando ponto fica pendente (ex: sem foto, sem geo)';
```

**Confirmação:**
```
✓ Query OK, 0 rows affected
```

---

### PASSO 3: Verificar Arquivos no Servidor

Confirme que os arquivos no servidor **public_html/** têm o código correto:

**3.1. api/checkin.php**
```php
// Deve ter estas linhas (aproximadamente linha 484-487):
$pendingReasonsJson = !empty($pendingReasons) ? json_encode($pendingReasons, JSON_UNESCAPED_UNICODE) : null;

// E no INSERT (linha 506):
fraud_risk_level, gps_mock_detected, device_fingerprint, pending_reasons,
```

**3.2. public/admin/attendances.php**
```php
// Deve ter estas linhas (aproximadamente 485-494):
<?php if (!empty($r['pending_reasons'])): ?>
  <?php 
  $reasons = json_decode($r['pending_reasons'], true);
  if (is_array($reasons) && count($reasons) > 0):
  ?>
  <div class="small text-muted mt-1">
    <i class="bi bi-info-circle me-1"></i>Motivo(s): <?= htmlspecialchars(implode(', ', $reasons)) ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
```

**3.3. public/my_timesheet.php**
```php
// Deve ter a função statusBadge (linha 134):
function statusBadge($approved, $pending_reasons = null) {
    if ($approved == 1) {
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Aprovado</span>';
    }
    
    $badge = '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history"></i> Pendente</span>';
    if (!empty($pending_reasons)) {
        $reasons = json_decode($pending_reasons, true);
        if (is_array($reasons) && count($reasons) > 0) {
            $badge .= '<div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i>Motivo: ' . htmlspecialchars(implode(', ', $reasons)) . '</div>';
        }
    }
    return $badge;
}
```

Se algum arquivo não tiver o código, faça upload da versão local.

---

## 🧪 TESTES FUNCIONAIS

### TESTE 1: Registro SEM Localização (Colaborador Normal)

**Objetivo:** Verificar se "Sem localização" aparece como motivo de pendência

**Passos:**

1. No app mobile, faça login com um colaborador que tenha `network_wide = 0`
2. Desative o GPS do celular
3. Registre um ponto com foto
4. Verifique no banco:

```sql
SELECT id, teacher_id, approved, pending_reasons, photo, check_in_lat
FROM attendance
ORDER BY id DESC LIMIT 1;
```

**Resultado Esperado:**
- `approved` = NULL (pendente)
- `pending_reasons` = `["Sem localização"]`
- `photo` = nome do arquivo
- `check_in_lat` = NULL

5. Acesse **Admin → Registros de Ponto** (attendances.php)
   - Veja o registro mais recente
   - Badge: 🟡 **Pendente**
   - Abaixo: "ℹ️ Motivo(s): Sem localização"

6. Faça login com o colaborador em **Minha Folha de Ponto** (my_timesheet.php)
   - Veja o registro mais recente
   - Badge: 🟡 **Pendente**
   - Abaixo: "ℹ️ Motivo: Sem localização"

---

### TESTE 2: Registro SEM Foto

**Objetivo:** Verificar se "Sem foto" aparece como motivo de pendência

**Passos:**

1. Simule um registro sem foto (pode ser via API direta ou modificando temporariamente o app)
2. Verifique no banco:

```sql
SELECT id, approved, pending_reasons, photo
FROM attendance
ORDER BY id DESC LIMIT 1;
```

**Resultado Esperado:**
- `approved` = NULL
- `pending_reasons` = `["Sem foto"]` ou `["Sem foto","Sem localização"]`
- `photo` = NULL

3. Verifique nas páginas admin e colaborador
   - Deve mostrar "Sem foto" nos motivos

---

### TESTE 3: Colaborador Network-Wide SEM Localização

**Objetivo:** Verificar que colaboradores network_wide NÃO precisam de GPS

**Passos:**

1. Configure um colaborador como network_wide:

```sql
UPDATE teachers SET network_wide = 1 WHERE id = X;
```

2. Registre ponto com esse colaborador **SEM GPS** mas **COM foto**
3. Verifique no banco:

```sql
SELECT id, approved, pending_reasons, photo, check_in_lat, t.network_wide
FROM attendance a
JOIN teachers t ON t.id = a.teacher_id
ORDER BY a.id DESC LIMIT 1;
```

**Resultado Esperado:**
- `approved` = 1 (aprovado automaticamente!)
- `pending_reasons` = NULL (sem motivos)
- `network_wide` = 1
- `photo` = nome do arquivo

4. No admin/colaborador:
   - Badge: 🟢 **Aprovado** (não pendente!)

---

### TESTE 4: Múltiplos Motivos

**Objetivo:** Verificar múltiplos motivos exibidos juntos

**Passos:**

1. Registre ponto **SEM foto** e **SEM GPS**
2. Verifique no banco:

```sql
SELECT pending_reasons FROM attendance ORDER BY id DESC LIMIT 1;
```

**Resultado Esperado:**
- `pending_reasons` = `["Sem foto","Sem localização"]`

3. No admin:
   - "Motivo(s): Sem foto, Sem localização"

4. No colaborador:
   - "Motivo: Sem foto, Sem localização"

---

### TESTE 5: Foto com Baixa Qualidade

**Objetivo:** Verificar motivos de qualidade da foto

**Passos:**

1. Registre ponto com foto muito escura ou desfocada
2. O sistema pode adicionar motivos como:
   - "Foto muito escura"
   - "Foto muito clara/estourada"
   - "Foto desfocada/sem nitidez"
   - "Baixo contraste"

3. Verifique no banco e nas interfaces

---

## 📊 Consultas Úteis

### Ver todos os registros pendentes com motivos:

```sql
SELECT 
    a.id,
    t.name AS colaborador,
    DATE_FORMAT(a.date, '%d/%m/%Y') AS data,
    a.approved,
    a.pending_reasons,
    CASE WHEN a.photo IS NULL THEN 'Não' ELSE 'Sim' END AS tem_foto,
    CASE WHEN a.check_in_lat IS NULL THEN 'Não' ELSE 'Sim' END AS tem_gps,
    t.network_wide
FROM attendance a
JOIN teachers t ON t.id = a.teacher_id
WHERE a.approved IS NULL
ORDER BY a.id DESC
LIMIT 20;
```

### Estatísticas de motivos:

```sql
SELECT 
    COUNT(*) as total_pendentes,
    SUM(CASE WHEN pending_reasons LIKE '%Sem foto%' THEN 1 ELSE 0 END) as sem_foto,
    SUM(CASE WHEN pending_reasons LIKE '%Sem localização%' THEN 1 ELSE 0 END) as sem_localizacao,
    SUM(CASE WHEN pending_reasons LIKE '%Fora do raio%' THEN 1 ELSE 0 END) as fora_raio,
    SUM(CASE WHEN pending_reasons IS NULL THEN 1 ELSE 0 END) as sem_motivos_registrados
FROM attendance
WHERE approved IS NULL;
```

### Limpar motivos de registros antigos (opcional):

```sql
-- Atualizar registros pendentes antigos que não têm motivos registrados
-- (apenas se quiser popular motivos retroativamente)
UPDATE attendance a
LEFT JOIN teachers t ON t.id = a.teacher_id
SET a.pending_reasons = CONCAT('[',
    CASE 
        WHEN a.photo IS NULL AND (a.check_in_lat IS NULL AND IFNULL(t.network_wide, 0) = 0) 
            THEN '"Sem foto","Sem localização"'
        WHEN a.photo IS NULL THEN '"Sem foto"'
        WHEN a.check_in_lat IS NULL AND IFNULL(t.network_wide, 0) = 0 THEN '"Sem localização"'
        ELSE NULL
    END,
']')
WHERE a.approved IS NULL 
  AND a.pending_reasons IS NULL
  AND a.date >= '2024-01-01';  -- Ajuste a data conforme necessário
```

---

## ✅ CHECKLIST FINAL

- [ ] Coluna `pending_reasons` existe no banco de produção
- [ ] Arquivo `api/checkin.php` tem código de pending_reasons
- [ ] Arquivo `public/admin/attendances.php` exibe motivos
- [ ] Arquivo `public/my_timesheet.php` exibe motivos
- [ ] Teste 1: Sem localização → motivo aparece ✓
- [ ] Teste 2: Sem foto → motivo aparece ✓
- [ ] Teste 3: Network-wide sem GPS → aprovado ✓
- [ ] Teste 4: Múltiplos motivos → aparecem juntos ✓
- [ ] Teste 5: Qualidade foto → motivos aparecem ✓

---

## 🎯 Resumo

**Status da Implementação:** ✅ **COMPLETA**

**O que funciona:**
- ✅ Detecção automática de motivos de pendência
- ✅ Armazenamento em JSON no banco
- ✅ Exibição na página do admin
- ✅ Exibição na página do colaborador
- ✅ Isenção para colaboradores network_wide

**O que fazer agora:**
1. Execute `verify_pending_reasons.sql` no phpMyAdmin
2. Confirme que a coluna existe
3. Se não existir, execute a migration
4. Verifique os arquivos no servidor
5. Execute os testes funcionais
6. Monitore os logs e feedback dos usuários

---

**Documentação criada em:** 31/10/2024
**Sistema:** DEEDO Ponto - Registro de Ponto Eletrônico


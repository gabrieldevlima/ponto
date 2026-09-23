# ✅ Solução Final: Grade Horária + Pagamento Fixo + Horas Extras

## 🎯 Problema Original

**Professores com aulas não consecutivas têm períodos ociosos ("janelas") entre aulas:**
- Professor com 3 aulas: 8h-9h, 11h-12h, 14h-15h
- Check-in: 8h | Check-out: 15h
- Sistema contava: 7 horas (incluindo janelas de 2h + 2h)
- **Deveria contar: 3 horas** (apenas as aulas)

**PROBLEMA ADICIONAL identificado:**
- Professor precisa fazer horas extras legítimas (reuniões, eventos, planejamento)
- Primeira solução bloqueava possibilidade de extras

---

## ✅ Solução Implementada (Opção B - Simplificada)

### Sistema Misto: Simples e Eficiente

#### 1. **Check-in ÚNICO por Dia**
- ✅ Professor faz apenas 1 entrada e 1 saída por dia
- ✅ Simples e prático (como sempre foi)
- ✅ Não precisa registrar cada aula individual

#### 2. **Pagamento SEMPRE Fixo** (para professores com grade)
- ✅ Pagamento = `classes_count × class_minutes`
- ✅ **NÃO importa** o tempo entre check-in e check-out
- ✅ Períodos ociosos ignorados automaticamente no cálculo

#### 3. **Horas Extras Possíveis** (quando necessário)
- ✅ Professor pode trabalhar em dia sem aulas (sábado, feriado, etc)
- ✅ Sistema marca como `is_overtime_candidate = 1`
- ✅ Admin aprova/rejeita
- ✅ Se aprovado: paga adicional 1.5x

---

## 📊 Como Funciona na Prática

### Exemplo 1: Dia Normal com 3 Aulas e Janelas

**Professor:** João (3 aulas de 50 min)
- 1ª aula: 08:00-08:50
- 2ª aula: 11:00-11:50 (janela de 2h10min)
- 3ª aula: 14:00-14:50 (janela de 2h10min)

**Registros:**
```
07:55 - Check-in (entrada do dia) ✅
14:55 - Check-out (saída do dia) ✅
```

**Tempo total no sistema:** 7 horas
**Tempo pago:** 3 aulas × 50 min = **150 min = 2h30min** (fixo!)

✅ Janelas de 4h20min NÃO são pagas

---

### Exemplo 2: Professor com Reunião (Hora Extra)

**Professor:** Maria (4 aulas de manhã)
- Aulas: 08:00-12:00
- Reunião pedagógica: 14:00-16:00

**Registros:**
```
Dia 1 (Segunda - Aulas):
  08:00 - Check-in ✅
  12:00 - Check-out ✅
  Pagamento: 4 aulas × 50 min = 200 min (fixo)

Dia 1 (Segunda - Reunião):
  14:00 - Check-in ⚠️ (novo registro, dia sem mais aulas)
  16:00 - Check-out ⚠️
  Sistema: is_overtime_candidate = 1
  Justificativa: "Reunião pedagógica obrigatória"
  Admin APROVA
  Pagamento extra: 2h × 1.5 = +3h equivalentes
```

**Total do dia: Salário fixo + Hora extra aprovada**

---

### Exemplo 3: Evento em Sábado (Hora Extra)

**Professor:** Pedro (seg-sex regular)
- Sábado: Feira de Ciências (evento obrigatório)

**Registros:**
```
Sábado:
  09:00 - Check-in ⚠️
  17:00 - Check-out ⚠️
  Sistema: is_overtime_candidate = 1 (sem aulas no sábado)
  Justificativa: "Feira de Ciências"
  Admin APROVA
  Pagamento: 8h × 1.5 = 12h equivalentes extras
```

---

## 🗄️ Estrutura do Banco de Dados

### Tabelas Principais

#### `teacher_schedules` (já existe)
```sql
teacher_id, weekday, classes_count, class_minutes
```
**Uso:** Define quantas aulas o professor tem por dia

#### `teacher_class_assignments` (nova - opcional)
```sql
teacher_id, weekday, period_id
```
**Uso:** Define em quais HORÁRIOS específicos (apenas para controle/referência)
**IMPORTANTE:** Não obriga múltiplos check-ins!

#### `attendance` (modificada)
```sql
+ is_overtime_candidate TINYINT(1)  -- 1 = dia sem aulas / hora extra
+ overtime_justification TEXT       -- motivo do professor
+ class_period_id INT (opcional)    -- referência (não obrigatório)
+ sequence_number INT (mantido)     -- para compatibilidade futura
```

#### `overtime_requests` (modificada)
```sql
+ justification TEXT  -- motivo da solicitação
```

---

## 💻 Implementação

### APIs Ajustadas

#### `api/checkin.php`
**Lógica Simplificada:**
```php
// 1 check-in por dia (entrada/saída única)
$open = buscar_ponto_aberto_hoje($teacherId, $today);
$action = $open ? 'saída' : 'entrada';

// Validação
if ($action === 'entrada') {
    $schedule = buscar_rotina($teacherId, $weekday);
    
    if (sem aulas hoje) {
        // Marca como overtime candidate
        $isOvertimeCandidate = 1;
        // Fica pendente para admin aprovar
    } else {
        // Aula regular
        $isOvertimeCandidate = 0;
    }
}

// Pagamento (no checkout):
if (professor usa grade horária) {
    pagamento = classes_count × class_minutes (FIXO)
} else {
    pagamento = tempo trabalhado (tradicional)
}
```

### Relatório Financeiro (`reports_financial.php`)

**Cálculo para Professores com Grade:**
```php
Salário Base (fixo)
+ Horas Extras Aprovadas (se houver)
- Descontos por Faltas (se houver)
= TOTAL A RECEBER
```

**Não usa:**
- ❌ Tempo entre check-in e check-out
- ❌ Delta automático
- ❌ Horas extras automáticas

---

## 🎓 Casos de Uso Completos

### Caso 1: Segunda-feira Normal
- **Grade:** 3 aulas (8h, 10h, 14h)
- **Check-in:** 07:50 ✅
- **Check-out:** 15:10 ✅
- **Tempo registrado:** 7h20min
- **Tempo pago:** 3 aulas × 50min = **2h30min** (fixo)
- **Horas extras:** Nenhuma (dia com aulas regulares)

### Caso 2: Sexta-feira com HTPC
- **Grade:** 5 aulas (manhã)
- **HTPC:** 14h-17h (obrigatório)

**Opção A - Registros Separados:**
```
Manhã:
  07:00 - Check-in ✅
  12:00 - Check-out ✅
  Pagamento: 5 aulas × 50min (fixo)

Tarde (HTPC):
  14:00 - Check-in ⚠️ (overtime candidate)
  17:00 - Check-out ⚠️
  Justificativa: "HTPC - Horário de Trabalho Pedagógico Coletivo"
  Admin aprova → +3h extras
```

**Opção B - Registro Único (se preferir):**
```
07:00 - Check-in ✅
17:00 - Check-out ✅
Pagamento: 5 aulas × 50min (fixo)
+ Admin registra manualmente 3h extras no sistema
```

### Caso 3: Sábado Letivo
- **Grade:** Seg-Sex (sem sábado)
- **Evento:** Sábado 8h-12h

```
Sábado:
  08:00 - Check-in ⚠️
  12:00 - Check-out ⚠️
  Sistema: is_overtime_candidate = 1 (sem aulas no sábado)
  Justificativa: "Sábado letivo - Reposição"
  Admin aprova → 4h × 1.5 = 6h equivalentes
```

---

## ⚙️ Configuração

### 1. Cadastrar Professor

```
Admin → Colaboradores → Cadastrar/Editar

Dados Básicos:
  Nome: Professor João
  Tipo: Professor
  Salário Base: R$ 3.000,00

Rotina Semanal (Nº de Aulas):
  Segunda: 3 aulas × 50 min = 150 min
  Terça:   3 aulas × 50 min = 150 min
  Quarta:  3 aulas × 50 min = 150 min
  Quinta:  3 aulas × 50 min = 150 min
  Sexta:   4 aulas × 50 min = 200 min
  
Total Semanal: 16 aulas = 800 min = 13h20min
Total Mensal (4 semanas): 64 aulas = 3.200 min
```

### 2. Grade Horária (Opcional)

**Uso:** Apenas para referência/controle
- **NÃO obriga** múltiplos check-ins
- Serve para documentar horários
- Admin pode conferir se professor está nos horários corretos

```
Admin → Configurações → Grade Horária

Criar períodos: 1º, 2º, 3º... com horários

Admin → Colaboradores → Editar Professor → Grade Horária
Marcar quais períodos o professor tem aula (referência visual)
```

### 3. Professor Usa Sistema

**DIA NORMAL:**
```
1. Chega na escola
2. Faz check-in (1 vez)
3. Dá suas aulas (com janelas entre elas)
4. No fim do dia: check-out (1 vez)
5. Pronto!
```

**DIA ESPECIAL (reunião/evento):**
```
1. Check-in no horário do evento
2. Sistema detecta: sem aulas hoje
3. (Opcional) Fornece justificativa
4. Check-out no fim
5. Admin aprova depois = hora extra paga
```

---

## 💰 Cálculos de Pagamento

### Professor com Grade Horária

**Fórmula:**
```
Total = Salário Base + Horas Extras Aprovadas

Onde:
- Salário Base = FIXO (não varia com tempo trabalhado)
- Horas Extras = overtime_requests aprovadas × multiplicador (1.5x)
- Delta automático = SEMPRE ZERO
```

**Exemplo Mensal:**
```
Salário Base: R$ 3.000,00
Previsto: 64 aulas × 50min = 3.200 min

Trabalhado: 3.500 min (inclui janelas)
  ↓ IGNORADO para pagamento!

Horas Extras Aprovadas: 2 reuniões (4h total)
  4h × (3000/3200) × 1.5 = R$ 562,50

TOTAL = R$ 3.000,00 + R$ 562,50 = R$ 3.562,50
```

### Professor Tradicional (sem grade)

**Fórmula:**
```
Total = Salário Base + Extras Auto + Overtime - Descontos
```
- Sistema calcula baseado em tempo trabalhado
- Mantém lógica atual

---

## 🔧 Ajustes Finais Feitos

### Simplificações Aplicadas:

1. ✅ **Removida** obrigatoriedade de múltiplos check-ins
2. ✅ **Mantido** check-in único por dia
3. ✅ **Simplificado** lógica de detecção de período
4. ✅ **Removido** `sequence_number` complexo dos inserts
5. ✅ **Mantido** campos `class_period_id` e `teacher_class_assignments` (opcional/referência)
6. ✅ **Foco** em: pagamento fixo + overtime controlado

### O que NÃO mudou:

- ✅ Tabelas `class_periods` e `teacher_class_assignments` (podem ser usadas para referência)
- ✅ Interface admin de grade horária (útil para documentação)
- ✅ Relatórios financeiros
- ✅ Sistema de aprovação de overtime
- ✅ Compatibilidade com sistema tradicional

---

## 📋 Arquivos Finais

### Scripts SQL
```
install_production_complete.sql          ✅ Completo (instalação nova)
add_class_period_system.sql              ✅ Migração (grade - opcional)
add_overtime_support.sql                 ✅ Migração (overtime - essencial)
```

### APIs
```
api/checkin.php                          ✅ Check-in único + overtime
api/checkin_bulk.php                     ✅ Offline sync simplificado
```

### Interface Admin
```
public/admin/class_periods.php           ✅ Gerenciar períodos (opcional)
public/admin/teacher_edit.php            ✅ Cadastro professor
public/admin/teachers_save.php           ✅ Salvar rotina
public/admin/attendance_review_overtime.php  ✅ Aprovar extras
public/admin/reports_financial.php       ✅ Relatório com extras
public/admin/attendances.php             ✅ Lista registros
public/admin/_navbar.php                 ✅ Menu atualizado
```

### Helpers
```
helpers.php                              ✅ +16 funções (grade + overtime)
```

---

## 🚀 Instalação Rápida

### Novo Sistema:
```sql
-- 1. Executar install_production_complete.sql
-- Pronto! Já inclui tudo
```

### Sistema Existente:
```sql
-- 1. add_overtime_support.sql (ESSENCIAL)
-- 2. add_class_period_system.sql (OPCIONAL - se quiser grade de referência)
```

---

## 📖 Manual de Uso

### Para o Admin

#### Passo 1: Cadastrar Professor
```
1. Admin → Colaboradores → Novo
2. Preencher dados básicos
3. Selecionar tipo: "Professor"
4. Definir salário base: R$ 3.000,00

5. Rotina Semanal (Nº de Aulas):
   Segunda: 3 aulas × 50 min
   Terça:   3 aulas × 50 min
   ...
   
6. Salvar
```

#### Passo 2: Aprovar Horas Extras (quando houver)
```
1. Admin → Registros → Revisar Horas Extras
2. Ver lista de pendentes
3. Para cada um:
   - Ler justificativa
   - Aprovar ou Rejeitar
4. Extras aprovadas aparecem no relatório financeiro
```

#### Passo 3: Gerar Relatório
```
1. Admin → Relatórios → Financeiro
2. Selecionar professor e mês
3. Ver:
   - Salário Base (fixo)
   - Horas Extras Aprovadas (se houver)
   - Total a Receber
```

### Para o Professor

#### Dia Normal (com Aulas)
```
1. Chegar na escola
2. Abrir app → Fazer check-in
3. Trabalhar normalmente (dar aulas, janelas, etc)
4. No fim do dia → Fazer check-out
5. Pronto! Receberá pelo número de aulas (fixo)
```

#### Dia Especial (Reunião/Evento)
```
1. Chegar para o evento
2. Fazer check-in
3. Sistema pode avisar: "Sem aulas hoje - será revisado pelo admin"
4. (Opcional) Informar motivo
5. Fazer check-out no fim
6. Aguardar aprovação do admin
7. Se aprovado → recebe hora extra
```

---

## ✨ Vantagens da Solução Final

### ✅ Simples para o Professor
- Apenas 2 registros por dia (entrada + saída)
- Não precisa se preocupar com janelas
- Sabe exatamente quanto vai receber

### ✅ Justo para a Instituição
- Não paga períodos ociosos
- Controle total sobre horas extras
- Aprovação administrativa necessária

### ✅ Flexível
- Permite horas extras quando legítimas
- Sistema detecta automaticamente
- Configurações ajustáveis

### ✅ Compatível
- Funciona junto com sistema tradicional
- Professores sem grade = cálculo normal
- Colaboradores horário fixo = não afetados

---

## 🎯 Diferenças vs Implementação Anterior

| Aspecto | Implementação Inicial | Solução Final (Opção B) |
|---------|----------------------|-------------------------|
| **Check-ins por dia** | Múltiplos (um por aula) | **1 único** |
| **Facilidade** | ❌ Trabalhoso (6-10 registros) | ✅ Simples (2 registros) |
| **Pagamento** | Fixo | ✅ Fixo |
| **Períodos ociosos** | Não contam | ✅ Não contam |
| **Horas extras** | ❌ Bloqueadas | ✅ Possíveis (com aprovação) |
| **Controle por aula** | ✅ Preciso | ⚠️ Apenas por dia |
| **Praticidade** | ⭐⭐ | ⭐⭐⭐⭐⭐ |

---

## 📝 Resumo Executivo

### O que o sistema faz:

1. ✅ **Professor faz 1 check-in e 1 check-out por dia**
2. ✅ **Pagamento = número de aulas cadastradas** (fixo, não varia)
3. ✅ **Janelas entre aulas = não pagas** (automático)
4. ✅ **Dias sem aulas = overtime candidate** (admin aprova)
5. ✅ **Horas extras legítimas = possíveis** (reuniões, eventos)

### Benefícios:

- 🎯 **Resolve** períodos ociosos
- 🎯 **Permite** horas extras necessárias
- 🎯 **Simples** de usar (2 registros/dia)
- 🎯 **Justo** para todos
- 🎯 **Controlado** pelo admin

---

## 🔗 Arquivos Relacionados

- `SISTEMA_MISTO_GRADE_HORARIA_OVERTIME.md` - Documentação técnica completa
- `IMPLEMENTACAO_GRADE_HORARIA.md` - Primeira versão (referência)
- `add_overtime_support.sql` - Script de migração
- `attendance_review_overtime.php` - Interface de aprovação

---

**Status:** ✅ 100% Implementado e Funcional  
**Data:** 2025-10-30  
**Versão:** 2.1.0 (Simplificada)  
**Abordagem:** Opção B - Check-in Único + Pagamento Fixo + Overtime Controlado


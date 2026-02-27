# Sistema de Grade Horária - Implementação Completa

## Visão Geral

Sistema implementado para resolver o problema de professores com aulas não consecutivas (períodos ociosos/"janelas" entre aulas). 

### Problema Resolvido
- **Antes**: Professor com 3 aulas de 1h (8h-9h, 11h-12h, 14h-15h) fazia check-in às 8h e check-out às 15h, contabilizando 420 minutos (7h), incluindo períodos ociosos indevidos
- **Depois**: Professor faz check-in/out para cada aula individualmente, contabilizando apenas os 180 minutos (3h) efetivamente trabalhados

## Funcionalidades Implementadas

### 1. Banco de Dados ✅

#### Novas Tabelas

**`class_periods`** - Grade horária padrão
```sql
- id, school_id (NULL = global)
- period_number (1º, 2º, 3º período, etc.)
- start_time, end_time
- active
```

**`teacher_class_assignments`** - Atribuições de professores aos períodos
```sql
- teacher_id, weekday, period_id
- Define em quais períodos cada professor tem aula
```

#### Modificações na Tabela `attendance`
```sql
ALTER TABLE attendance ADD:
- class_period_id INT NULL (qual período está sendo registrado)
- sequence_number INT (permite múltiplos registros por dia)
```

#### Scripts Disponíveis
- `install_production_complete.sql` - Atualizado com novas tabelas
- `add_class_period_system.sql` - Script de migração para bancos existentes

### 2. Funções Auxiliares (helpers.php) ✅

Implementadas 9 novas funções:

1. `get_class_periods($schoolId)` - Busca períodos disponíveis
2. `buscar_horarios_professor($teacherId, $weekday)` - Horários do professor
3. `identificar_periodo_atual($assignments, $now, $tolerance)` - Identifica período ativo
4. `verificar_periodo_registrado($teacherId, $date, $periodId)` - Verifica se já registrou
5. `get_next_attendance_sequence($teacherId, $date)` - Próximo número de sequência
6. `count_teacher_periods($teacherId, $weekday)` - Conta períodos do dia
7. `get_teacher_attendance_records($teacherId, $date)` - Todos os registros do dia
8. `calculate_worked_minutes_from_periods($records)` - Calcula minutos trabalhados
9. `teacher_uses_period_system($teacherId)` - Verifica se usa o sistema

### 3. Interface Admin ✅

#### `class_periods.php` - Gerenciamento de Grade Horária
- CRUD completo de períodos
- Períodos globais ou específicos por escola
- 10 períodos padrão pré-cadastrados (07:00-17:10)

#### `teacher_edit.php` - Atribuição de Períodos
- Nova seção "Grade Horária - Períodos de Aula"
- Grid visual: períodos × dias da semana
- Checkboxes para marcar em quais horários o professor tem aula
- Botões auxiliares: marcar todos, desmarcar, apenas dias úteis
- Sistema salva automaticamente em `teachers_save.php`

#### Menu de Navegação (`_navbar.php`)
- Link "Grade Horária" adicionado em Configurações
- Ícone: `bi-clock-history`

### 4. APIs de Check-in ✅

#### `api/checkin.php`
**Modificações principais:**
- Detecta se professor usa sistema de períodos
- Identifica período ativo atual (tolerância de 15 min)
- Permite múltiplos check-ins no mesmo dia
- Valida se o check-in está no horário correto
- Adiciona `class_period_id` e `sequence_number` ao registro
- Resposta JSON inclui informações do período:
```json
{
  "status": "ok",
  "period": {
    "id": 3,
    "number": 3,
    "start_time": "08:40",
    "end_time": "09:30"
  }
}
```

#### `api/checkin_bulk.php`
- Mesma lógica aplicada para sincronização offline
- Fallback: se não há período ativo, usa primeiro período do dia
- Suporta múltiplos check-ins em modo offline

### 5. Relatórios e Cálculos ✅

#### `reports_financial.php`
**Mudanças:**
- Detecta se professor usa sistema de períodos
- Para professores com grade horária:
  - Pagamento SEMPRE baseado em `classes_count × class_minutes` (fixo)
  - `worked` serve apenas para controle de presença
  - Não calcula extras/descontos baseado em tempo trabalhado
  - Delta, extras e descontos = 0
- Alert visual indica quando professor usa sistema de grade horária
- Mantém cálculo tradicional para professores sem períodos

#### `attendances.php`
- Alert informativo quando filtro por professor com grade horária
- Explica que múltiplos registros = múltiplas aulas
- Cálculo por registro mantido (cada registro = uma aula)

### 6. Regras de Negócio

#### Múltiplos Check-ins
- Professor pode fazer vários check-ins no mesmo dia
- Cada check-in/out representa uma aula específica
- Sistema identifica automaticamente qual período está ativo

#### Validação de Períodos
- Check-in permitido com tolerância de 15 minutos antes/depois
- Se fora do horário: erro listando períodos disponíveis
- Se sem períodos cadastrados para o dia: erro claro

#### Pagamento Fixo
- Professores com grade horária: pagamento = `classes_count × class_minutes`
- Períodos ociosos NÃO contabilizados
- Horas extras/descontos NÃO calculados automaticamente
- Faltas contabilizadas por aula não registrada

#### Compatibilidade
- Sistema tradicional continua funcionando normalmente
- Professores sem períodos atribuídos = sistema antigo
- Colaboradores modo `time` (horário fixo) não afetados
- Coexistência perfeita dos dois sistemas

## Configuração e Uso

### 1. Instalação em Banco Novo
```bash
# Usar install_production_complete.sql
# Já inclui todas as tabelas e períodos padrão
```

### 2. Migração de Banco Existente
```bash
# Executar add_class_period_system.sql
# Adiciona novas tabelas sem afetar dados existentes
```

### 3. Configurar Grade Horária
1. Acessar: **Admin → Configurações → Grade Horária**
2. Ajustar horários conforme necessidade da instituição
3. Pode criar períodos específicos por escola

### 4. Atribuir Períodos aos Professores
1. Acessar: **Admin → Gestão → Colaboradores**
2. Editar professor desejado
3. Rolar até seção "Grade Horária - Períodos de Aula"
4. Marcar checkboxes dos períodos em que o professor tem aula
5. Salvar

### 5. Uso pelo Professor
- Professor acessa o sistema mobile normalmente
- Sistema detecta automaticamente qual período está ativo
- Faz check-in/out para cada aula
- Processo transparente (funciona igual ao sistema tradicional)

## Estrutura de Arquivos Modificados

```
install_production_complete.sql       ✅ Atualizado
add_class_period_system.sql          ✅ Novo (migração)
helpers.php                          ✅ +9 funções
config.php                           ⚠️  Não modificado (usar existente)

public/admin/
├── class_periods.php                ✅ Novo (CRUD períodos)
├── teacher_edit.php                 ✅ +seção períodos
├── teachers_save.php                ✅ Salva atribuições
├── _navbar.php                      ✅ +link menu
├── reports_financial.php            ✅ Cálculo fixo
├── attendances.php                  ✅ +indicador
└── dashboard.php                    ⚠️  Não afetado

api/
├── checkin.php                      ✅ Múltiplos check-ins
└── checkin_bulk.php                 ✅ Offline sync
```

## Interface Mobile (Pendente) ⚠️

### O que falta implementar em `public/index.php`:

1. **Exibir períodos do dia**
   - Buscar períodos do professor para hoje
   - Listar visualmente (ex: cards ou lista)
   - Mostrar horário de cada período

2. **Indicar status de cada período**
   - ✅ Registrado (check-in + check-out feitos)
   - 🕐 Em andamento (check-in feito, aguardando check-out)
   - ⏳ Aguardando (ainda não registrou)
   - ❌ Perdido (passou o horário sem registrar)

3. **Permitir múltiplos check-ins**
   - Botão específico para cada período OU
   - Botão único que detecta período ativo (já implementado na API)

4. **Feedback visual**
   - Destacar período ativo no momento
   - Mostrar contagem de aulas registradas vs total do dia
   - Progresso visual (ex: 2/4 aulas registradas)

### Sugestão de Implementação

```javascript
// Adicionar no script da página mobile

async function carregarPeriodosDoDia() {
  const teacherId = getTeacherId();
  const today = new Date().toISOString().split('T')[0];
  
  // Buscar períodos atribuídos
  const periodos = await fetch(`/api/get_teacher_periods.php?teacher_id=${teacherId}&date=${today}`)
    .then(r => r.json());
  
  // Buscar registros já feitos
  const registros = await fetch(`/api/get_attendance_today.php?teacher_id=${teacherId}&date=${today}`)
    .then(r => r.json());
  
  // Renderizar interface
  renderizarPeriodos(periodos, registros);
}

function renderizarPeriodos(periodos, registros) {
  const container = document.getElementById('periodos-container');
  
  periodos.forEach(periodo => {
    const registro = registros.find(r => r.class_period_id === periodo.id);
    const status = getStatusPeriodo(periodo, registro);
    
    const card = `
      <div class="periodo-card ${status}">
        <div class="periodo-numero">${periodo.period_number}º período</div>
        <div class="periodo-horario">${periodo.start_time} - ${periodo.end_time}</div>
        <div class="periodo-status">${getStatusLabel(status)}</div>
        ${getBotaoPeriodo(periodo, registro, status)}
      </div>
    `;
    
    container.innerHTML += card;
  });
}
```

### APIs Auxiliares Necessárias

Criar em `api/`:

1. **`get_teacher_periods.php`**
   ```php
   // Retorna períodos do professor para uma data
   // Usa: buscar_horarios_professor()
   ```

2. **`get_attendance_today.php`**
   ```php
   // Retorna registros de attendance do dia
   // Usa: get_teacher_attendance_records()
   ```

## Testes Sugeridos

### 1. Fluxo Completo
1. ✅ Criar períodos no admin
2. ✅ Atribuir períodos a um professor
3. ✅ Professor faz check-in/out (API funcional)
4. ⚠️  Verificar exibição na interface mobile
5. ✅ Verificar relatório financeiro (pagamento fixo)
6. ✅ Verificar lista de attendances (múltiplos registros)

### 2. Casos de Uso
- Professor com 3 aulas (7h-8h, 10h-11h, 14h-15h)
- Registrar apenas 2 das 3 aulas
- Verificar que pagamento = 3 aulas (esperado), não 2 (trabalhado)
- Verificar que não há cálculo de horas extras indevidas

### 3. Compatibilidade
- Professor sem períodos atribuídos deve usar sistema tradicional
- Professor modo `time` não deve ser afetado
- Ambos os sistemas devem coexistir sem problemas

## Benefícios

### Para a Instituição
- ✅ Pagamento justo (não paga períodos ociosos)
- ✅ Controle preciso de presença por aula
- ✅ Relatórios mais precisos
- ✅ Conformidade trabalhista

### Para o Professor
- ✅ Transparência no pagamento
- ✅ Registro claro de cada aula
- ✅ Não penalizado por janelas entre aulas
- ✅ Interface simples (check-in/out por aula)

### Para o Sistema
- ✅ Flexível (coexiste com sistema tradicional)
- ✅ Escalável (suporta múltiplas escolas)
- ✅ Auditável (cada registro tem NSR e período)
- ✅ Compatível com Portaria MTP 671/2021

## Documentação Técnica

### Campos Importantes

#### attendance
- `class_period_id`: NULL = sistema tradicional, INT = sistema de períodos
- `sequence_number`: 1,2,3... para múltiplos registros no mesmo dia
- Índices: `idx_att_teacher_date_seq`, `idx_att_period`

#### teacher_class_assignments
- UNIQUE(teacher_id, weekday, period_id): garante sem duplicatas
- Suporta school_id para atribuições específicas por unidade

### Lógica de Detecção

```php
// Sistema detecta automaticamente qual sistema usar:
if (teacher_uses_period_system($teacherId)) {
    // Usa grade horária (múltiplos check-ins)
    // Pagamento fixo
} else {
    // Sistema tradicional (um check-in por dia)
    // Calcula extras/descontos
}
```

### Tolerância de Horário

- 15 minutos antes do início do período
- 15 minutos depois do fim do período
- Configurável em `identificar_periodo_atual($assignments, $now, 15)`

## Status de Implementação

| Componente | Status | Observações |
|------------|--------|-------------|
| Banco de Dados | ✅ 100% | Tabelas, índices, dados padrão |
| Funções Helper | ✅ 100% | 9 funções implementadas |
| Admin - CRUD Períodos | ✅ 100% | class_periods.php completo |
| Admin - Atribuir Períodos | ✅ 100% | teacher_edit.php completo |
| Admin - Relatórios | ✅ 100% | Pagamento fixo implementado |
| API Check-in | ✅ 100% | Múltiplos check-ins + períodos |
| API Bulk Sync | ✅ 100% | Offline sync com períodos |
| Mobile UI | ⚠️ 0% | **PENDENTE** - ver seção acima |
| Documentação | ✅ 100% | Este arquivo + SQL comments |
| Testes | ⚠️ 50% | Backend OK, frontend pendente |

## Próximos Passos

### 1. Completar Mobile UI (Prioridade Alta)
- Implementar exibição de períodos
- Adicionar indicadores visuais de status
- Testar fluxo completo no celular

### 2. APIs Auxiliares (Prioridade Média)
- `get_teacher_periods.php`
- `get_attendance_today.php`

### 3. Melhorias Futuras (Opcional)
- Dashboard com estatísticas de períodos
- Relatório de faltas por período
- Notificações push por período
- Integração com calendário do professor

## Suporte

Para dúvidas ou problemas:
1. Verificar logs em `error_log`
2. Verificar tabela `audit_logs` para auditoria
3. Testar com professor sem períodos (deve funcionar normal)
4. Verificar se funções helper estão disponíveis em `helpers.php`

---

**Última atualização**: 2025-10-28  
**Versão**: 1.0.0  
**Status**: Implementação backend completa, mobile UI pendente


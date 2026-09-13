# Sistema de Horas Extras - DEEDO Ponto

> **ATUALIZACAO 2026-05-17 — Fluxo opt-in:** o sistema NAO cria mais solicitacoes de hora
> extra automaticamente no checkout. Quando o ponto ultrapassa a jornada, a tela de
> sucesso (e a "Minha Folha") mostra um botao **"Solicitar hora extra"** que o
> colaborador clica e fornece justificativa para abrir a solicitacao. So entao a
> solicitacao entra na fila administrativa em `/admin/overtime.php`.
>
> - Registros antigos (criados pelo sistema antes desta mudanca) permanecem com
>   `requested_by_employee = 0` e ficam ocultos no filtro padrao. O filtro "Origem"
>   na tela admin permite ver os legados quando necessario.
> - Janela retroativa: solicitacao via Minha Folha so eh permitida no mes corrente.
> - A funcao antiga `detect_and_create_overtime()` foi substituida por
>   `compute_overtime_exceedance()` (apenas calcula, nao grava) + `create_overtime_request()`
>   (grava quando o colaborador solicita).
> - Endpoint novo: `api/request_overtime.php` recebe `{attendance_id, justification, csrf}`
>   e cria a solicitacao no mes corrente, com auditoria.

## 📋 Índice
- [Visão Geral](#visão-geral)
- [Fluxo de Funcionamento](#fluxo-de-funcionamento)
- [Arquitetura](#arquitetura)
- [Banco de Dados](#banco-de-dados)
- [API e Detecção Automática](#api-e-detecção-automática)
- [Interface Administrativa](#interface-administrativa)
- [Regras de Negócio](#regras-de-negócio)
- [Guia de Instalação](#guia-de-instalação)

---

## Visão Geral

O **Sistema de Horas Extras** é um módulo integrado ao sistema DEEDO Ponto que registra e gerencia as horas extras trabalhadas pelos colaboradores. **A solicitacao eh opt-in:** o sistema calcula a extrapolacao, mas o registro na fila de analise so eh criado quando o colaborador clica em "Solicitar hora extra" e fornece justificativa.

### Características Principais

✅ **Solicitacao Opt-In pelo Colaborador**: Botao "Solicitar hora extra" na tela de batida e na Minha Folha; justificativa obrigatoria
✅ **Aprovação Independente**: Ponto e hora extra têm fluxos de aprovação separados
✅ **Banco de Horas Integrado**: Horas aprovadas são adicionadas automaticamente ao banco
✅ **Interface Completa**: Painel administrativo com filtros (incluindo Origem), estatísticas e ações em lote
✅ **Auditoria Total**: Registro de quem solicitou, quem aprovou/rejeitou e quando
✅ **Validações Inteligentes**: Recalculo server-side de minutos (anti-fraude); janela retroativa limitada ao mes corrente

---

## Fluxo de Funcionamento

### 1. Detecção Automática (Check-out)

```
┌─────────────────────────────────────────┐
│ Colaborador registra SAÍDA             │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ Sistema calcula:                        │
│ • Minutos esperados (jornada do dia)    │
│ • Minutos trabalhados (aprovados)       │
│ • Delta = trabalhado - esperado         │
└──────────────┬──────────────────────────┘
               │
               ▼
         ┌────┴────┐
         │ Delta > 0? │
         └────┬────┘
              │ SIM
              ▼
┌─────────────────────────────────────────┐
│ Cria solicitação de hora extra         │
│ Status: PENDING                         │
│ • Armazena minutos extras               │
│ • Vincula ao attendance                 │
│ • Registra data e colaborador           │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ Notifica colaborador                    │
│ "Detectado Xh Ym de hora extra"        │
└─────────────────────────────────────────┘
```

### 2. Aprovação pelo Administrador

```
┌─────────────────────────────────────────┐
│ Admin acessa painel de Horas Extras     │
│ /admin/overtime.php                     │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ Visualiza solicitações pendentes        │
│ • Colaborador, data, minutos            │
│ • Status do ponto (aprovado/pendente)   │
│ • Detalhes: esperado vs trabalhado      │
└──────────────┬──────────────────────────┘
               │
        ┌──────┴──────┐
        │             │
     APROVAR      REJEITAR
        │             │
        ▼             ▼
┌────────────┐  ┌────────────────┐
│ Validações │  │ Motivo obriga- │
│ • Ponto    │  │ tório          │
│   aprovado │  └────────┬───────┘
└─────┬──────┘           │
      │                  │
      ▼                  ▼
┌────────────┐  ┌────────────────┐
│ Adiciona   │  │ Registra       │
│ ao banco   │  │ rejeição       │
│ de horas   │  │ com motivo     │
└────────────┘  └────────────────┘
```

### 3. Casos Especiais

**Rejeição Automática de Hora Extra**
- Quando um ponto é REJEITADO pelo admin
- Todas as horas extras pendentes daquele ponto são AUTO-REJEITADAS
- Motivo: "Ponto rejeitado pelo administrador"

**Validações de Aprovação**
- ❌ Não pode aprovar hora extra se o ponto estiver pendente
- ❌ Não pode aprovar hora extra se o ponto estiver rejeitado
- ✅ Só pode aprovar após o ponto ser aprovado

---

## Arquitetura

### Componentes do Sistema

```
┌─────────────────────────────────────────────────────┐
│                   FRONTEND                          │
│  • public/index.php (PWA - Registro de Ponto)       │
│  • public/admin/overtime.php (Painel Admin)         │
└──────────────┬──────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────┐
│                   API LAYER                         │
│  • api/checkin.php (Detecção automática)            │
└──────────────┬──────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────┐
│                 BUSINESS LOGIC                      │
│  helpers.php:                                       │
│  • detect_and_create_overtime()                     │
│  • approve_overtime_request()                       │
│  • reject_overtime_request()                        │
│  • auto_reject_overtime_on_attendance_rejection()   │
│  • calculate_expected_minutes()                     │
│  • calculate_worked_minutes()                       │
└──────────────┬──────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────┐
│                   DATABASE                          │
│  • overtime_requests                                │
│  • hour_bank_entries (source='overtime_approved')   │
│  • attendance                                       │
│  • teachers, schools, etc.                          │
└─────────────────────────────────────────────────────┘
```

### Arquivos Principais

| Arquivo | Descrição |
|---------|-----------|
| `install.sql` | Schema da tabela `overtime_requests` |
| `helpers.php` | Funções de negócio para horas extras |
| `api/checkin.php` | Endpoint de registro de ponto (detecção) |
| `public/admin/overtime.php` | Interface administrativa |
| `public/admin/attendances_action.php` | Auto-rejeição ao rejeitar ponto |

---

## Banco de Dados

### Tabela: `overtime_requests`

```sql
CREATE TABLE IF NOT EXISTS overtime_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,              -- Vinculado ao registro de ponto
  teacher_id INT NOT NULL,                 -- Colaborador
  school_id INT NULL,                      -- Instituição (se aplicável)
  date DATE NOT NULL,                      -- Data do trabalho
  minutes INT NOT NULL,                    -- Minutos de hora extra detectados
  expected_minutes INT NOT NULL DEFAULT 0, -- Minutos esperados no dia
  worked_minutes INT NOT NULL DEFAULT 0,   -- Minutos efetivamente trabalhados
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL,           -- Quem aprovou/rejeitou
  approved_at DATETIME NULL,               -- Quando foi processado
  rejection_reason VARCHAR(255) NULL,      -- Motivo da rejeição
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  
  CONSTRAINT fk_ot_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
  CONSTRAINT fk_ot_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_ot_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_ot_admin FOREIGN KEY (approved_by_admin_id) REFERENCES admins(id),
  UNIQUE KEY uq_attendance_overtime (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Índices

```sql
CREATE INDEX idx_overtime_teacher_date ON overtime_requests(teacher_id, date);
CREATE INDEX idx_overtime_status ON overtime_requests(status);
CREATE INDEX idx_overtime_attendance ON overtime_requests(attendance_id);
CREATE INDEX idx_overtime_date ON overtime_requests(date);
```

### Integração com `hour_bank_entries`

Quando uma hora extra é **aprovada**, um registro é inserido em `hour_bank_entries`:

```sql
INSERT INTO hour_bank_entries 
  (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id, created_by_admin_id)
VALUES 
  (?, ?, ?, ?, 'Hora extra aprovada', 'overtime_approved', ?, ?);
```

Campo `source`:
- `'auto'` - Cálculo automático do banco de horas (delta normal)
- `'manual'` - Lançamento manual pelo admin
- `'overtime_approved'` - ✨ **NOVO**: Hora extra aprovada

---

## API e Detecção Automática

### Endpoint: `api/checkin.php` (Saída)

#### Lógica de Detecção

```php
// 1. Registra check_out
UPDATE attendance SET check_out = ?, ... WHERE id = ?;

// 2. Calcula minutos esperados
$expectedMinutes = calculate_expected_minutes($pdo, $teacherId, $date);

// 3. Calcula minutos trabalhados (aprovados)
$workedMinutes = calculate_worked_minutes($pdo, $teacherId, $date);

// 4. Detecta hora extra (SEM tolerância)
$overtimeMinutes = $workedMinutes - $expectedMinutes;

// 5. Se > 0, cria solicitação
if ($overtimeMinutes > 0) {
    INSERT INTO overtime_requests (...) VALUES (...);
}

// 6. Atualiza banco de horas normal (delta > 0 = 0)
$deltaForBank = $delta > 0 ? 0 : $delta;
INSERT INTO hour_bank_entries (source='auto') VALUES (...);

COMMIT;
```

#### Resposta da API

```json
{
  "status": "ok",
  "action": "saída",
  "time": "2025-10-10 18:35:00",
  "message": "Saída registrada com foto e localização!",
  "overtime": "Detectado 1h30m de hora extra (aguarda aprovação)"
}
```

### Funções Helper

#### `detect_and_create_overtime()`

```php
function detect_and_create_overtime(
    PDO $pdo, 
    int $attendanceId, 
    int $teacherId, 
    ?int $schoolId, 
    string $date
): array
```

**Retorno:**
```php
[
    'created' => true,
    'overtime_minutes' => 90,
    'message' => 'Hora extra de 90 minutos detectada e aguarda aprovação'
]
```

#### `calculate_expected_minutes()`

Calcula minutos esperados com suporte a:
- ✅ Modo `classes` (aulas × duração)
- ✅ Modo `time` (janela completa: fim − início; `break_minutes` é **informativo**, não desconta)
- ✅ Modo `hours` (total de minutos do dia)
- ✅ Afastamentos remunerados (retorna 0)

#### `calculate_worked_minutes()`

Soma todos os pares `work` **APROVADOS** do colaborador no dia.

#### `calculate_effective_worked_minutes()` — modelo "cheio vs cheio"

Função **canônica** para saldo, banco de horas e hora extra. Retorna a presença
**cheia**: pares `work` aprovados + pares `break` (aprovados ou pendentes).

> **Regra de negócio:** o intervalo é um direito do colaborador (almoço/descanso)
> e **conta como tempo trabalhado** — nunca subtrai. Ele aparece nas telas e
> relatórios apenas como informação ("do total, X foi de intervalo").
> A contrapartida é que o esperado é a **janela completa** da jornada.
> Consequência: quem bate SAÍDA no almoço (em vez do botão de intervalo) tem o
> tempo fora não contado.

```
delta = presença_cheia − janela_completa
delta > 0  → banco recebe 0 (candidato a hora extra via overtime_requests)
delta ≤ 0  → banco recebe delta (débito)
```

---

## Interface Administrativa

### Página: `/admin/overtime.php`

#### Estatísticas (Cards)

```
┌─────────────┬─────────────┬─────────────┬─────────────┐
│   Total     │  Pendentes  │  Aprovadas  │  Rejeitadas │
│     124     │      45     │      65     │      14     │
│             │  22.5 horas │  32.5 horas │             │
└─────────────┴─────────────┴─────────────┴─────────────┘
```

#### Filtros Disponíveis

- **Status**: Todos, Pendentes, Aprovadas, Rejeitadas
- **Colaborador**: Dropdown com todos os colaboradores
- **Instituição**: (Apenas para network_admin)
- **Período**: Data inicial e final

#### Listagem

| Data | Colaborador | Instituição | Trab. | Esper. | H.Extra | Status | Ações |
|------|-------------|-------------|-------|--------|---------|--------|-------|
| 10/10 | João Silva | Escola A | 9h00m | 8h00m | **1h00m** | 🟡 Pendente | [Aprovar] [Rejeitar] |
| 09/10 | Maria Costa | Escola B | 8h30m | 8h00m | **0h30m** | ✅ Aprovada | Por: admin<br>Em: 09/10 15:30 |
| 08/10 | Pedro Lima | - | 9h00m | 8h00m | **1h00m** | ❌ Rejeitada | Motivo: Não autorizado |

#### Modal de Rejeição

```
┌─────────────────────────────────────────────┐
│  Rejeitar Hora Extra                    [X] │
├─────────────────────────────────────────────┤
│  João Silva - 10/10/2025                    │
│  Hora extra: 1h00m                          │
│                                             │
│  Motivo da Rejeição *                       │
│  ┌────────────────────────────────────────┐ │
│  │ [Digite o motivo...]                   │ │
│  │                                        │ │
│  └────────────────────────────────────────┘ │
│                                             │
│           [Cancelar]  [Rejeitar]            │
└─────────────────────────────────────────────┘
```

---

## Regras de Negócio

### 1. Detecção de Horas Extras

| Condição | Comportamento |
|----------|---------------|
| Trabalhado > Esperado | Cria solicitação de hora extra |
| Trabalhado ≤ Esperado | Não cria solicitação |
| Sem jornada configurada | Não cria solicitação |
| Afastamento remunerado | Esperado = 0, não cria hora extra |

**Importante:**
- ⚠️ Detecção é feita **SEM tolerância** (qualquer minuto extra conta)
- ✅ Usa apenas pontos **APROVADOS** no cálculo
- 📅 Considera apenas o dia específico (não acumula)

### 2. Banco de Horas vs Hora Extra

```php
// Cálculo do delta normal
$delta = $workedMinutes - $expectedMinutes;

// Se delta > 0 (hora extra):
if ($delta > 0) {
    // Banco de horas recebe 0
    $deltaForBank = 0;
    
    // Hora extra vai para overtime_requests
    INSERT INTO overtime_requests (...);
} else {
    // Negativo: vai para banco de horas normalmente
    $deltaForBank = $delta;
}
```

### 3. Aprovação de Hora Extra

**Validações:**

```php
✅ Status deve ser 'pending'
✅ Ponto deve estar aprovado (attendance.approved = 1)
❌ Não pode aprovar se ponto pendente
❌ Não pode aprovar se ponto rejeitado
```

**Ação:**
1. Atualiza `overtime_requests.status = 'approved'`
2. Registra `approved_by_admin_id` e `approved_at`
3. Insere em `hour_bank_entries` com `source='overtime_approved'`
4. Registra auditoria

### 4. Rejeição de Hora Extra

**Validações:**

```php
✅ Status deve ser 'pending'
✅ Motivo da rejeição é OBRIGATÓRIO
```

**Ação:**
1. Atualiza `overtime_requests.status = 'rejected'`
2. Registra `rejection_reason`, `approved_by_admin_id`, `approved_at`
3. Registra auditoria
4. ❌ NÃO adiciona ao banco de horas

### 5. Auto-Rejeição

**Gatilho:** Administrador **rejeita** um ponto

**Ação Automática:**
```php
// Em attendances_action.php
if ($action === 'reject') {
    auto_reject_overtime_on_attendance_rejection($pdo, $attendanceId, $adminId);
}
```

**Resultado:**
- Todas as horas extras **pendentes** daquele ponto são rejeitadas
- Motivo: "Ponto rejeitado pelo administrador"
- Registra admin que fez a ação

---

## Guia de Instalação

### 1. Atualização do Banco de Dados

Execute o script SQL atualizado:

```bash
mysql -u usuario -p nome_do_banco < install.sql
```

**OU** execute manualmente:

```sql
-- Adiciona novo valor ao enum source
ALTER TABLE hour_bank_entries 
MODIFY source ENUM('auto','manual','overtime_approved') NOT NULL DEFAULT 'manual';

-- Cria tabela de horas extras
CREATE TABLE IF NOT EXISTS overtime_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  minutes INT NOT NULL,
  expected_minutes INT NOT NULL DEFAULT 0,
  worked_minutes INT NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL,
  approved_at DATETIME NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ot_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
  CONSTRAINT fk_ot_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_ot_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_ot_admin FOREIGN KEY (approved_by_admin_id) REFERENCES admins(id),
  UNIQUE KEY uq_attendance_overtime (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_overtime_teacher_date ON overtime_requests(teacher_id, date);
CREATE INDEX idx_overtime_status ON overtime_requests(status);
CREATE INDEX idx_overtime_attendance ON overtime_requests(attendance_id);
CREATE INDEX idx_overtime_date ON overtime_requests(date);
```

### 2. Verificação

Verifique se as tabelas foram criadas corretamente:

```sql
SHOW TABLES LIKE 'overtime%';
DESCRIBE overtime_requests;
```

### 3. Teste do Sistema

#### 3.1 Teste de Detecção Automática

1. Configure jornada de um colaborador (ex: 8h/dia)
2. Registre entrada via PWA
3. Registre saída após trabalhar mais que o esperado (ex: 9h)
4. Verifique se a solicitação foi criada:

```sql
SELECT * FROM overtime_requests 
WHERE teacher_id = ? AND date = CURDATE();
```

#### 3.2 Teste de Aprovação

1. Acesse `/admin/overtime.php`
2. Verifique se a solicitação aparece como "Pendente"
3. Aprove a hora extra
4. Verifique se foi adicionada ao banco de horas:

```sql
SELECT * FROM hour_bank_entries 
WHERE source = 'overtime_approved' 
  AND teacher_id = ? 
  AND date = CURDATE();
```

#### 3.3 Teste de Rejeição

1. Crie outra hora extra
2. Rejeite informando um motivo
3. Verifique se o motivo foi salvo:

```sql
SELECT status, rejection_reason, approved_by_admin_id 
FROM overtime_requests 
WHERE id = ?;
```

#### 3.4 Teste de Auto-Rejeição

1. Registre ponto com hora extra
2. Rejeite o ponto via `/admin/attendances.php`
3. Verifique se a hora extra foi auto-rejeitada:

```sql
SELECT status, rejection_reason 
FROM overtime_requests 
WHERE attendance_id = ?;
```

### 4. Permissões

Certifique-se de que os arquivos têm as permissões corretas:

```bash
chmod 644 helpers.php
chmod 644 api/checkin.php
chmod 644 public/admin/overtime.php
chmod 644 public/admin/attendances_action.php
```

### 5. Configuração do Menu

O menu "Horas Extras" foi adicionado automaticamente em todas as páginas admin. Verifique se está aparecendo corretamente.

---

## Segurança

### CSRF Protection

Todas as ações de aprovação/rejeição usam tokens CSRF:

```php
csrf_verify();
```

### Validação de Escopo

Administradores só podem gerenciar horas extras de colaboradores do seu escopo:

```php
list($scopeSql, $scopeParams) = admin_scope_where('t');
```

### SQL Injection Prevention

Todas as queries usam prepared statements:

```php
$st = $pdo->prepare("SELECT * FROM overtime_requests WHERE id = ?");
$st->execute([$id]);
```

### Auditoria

Todas as ações são registradas em `audit_logs`:

```php
audit_log('update', 'overtime_request', $id, [
    'action' => 'approve',
    'minutes' => $minutes,
    'teacher_id' => $teacherId
]);
```

---

## Manutenção e Suporte

### Logs de Auditoria

Consultar histórico de aprovações/rejeições:

```sql
SELECT * FROM audit_logs 
WHERE entity = 'overtime_request' 
ORDER BY created_at DESC 
LIMIT 50;
```

### Estatísticas

Total de horas extras por mês:

```sql
SELECT 
    DATE_FORMAT(date, '%Y-%m') as mes,
    COUNT(*) as total,
    SUM(minutes) as minutos_totais,
    SUM(CASE WHEN status = 'approved' THEN minutes ELSE 0 END) as aprovados
FROM overtime_requests
GROUP BY DATE_FORMAT(date, '%Y-%m')
ORDER BY mes DESC;
```

### Limpeza de Dados Antigos (Opcional)

Remover solicitações rejeitadas com mais de 1 ano:

```sql
DELETE FROM overtime_requests 
WHERE status = 'rejected' 
  AND DATE(created_at) < DATE_SUB(CURDATE(), INTERVAL 1 YEAR);
```

---

## FAQ

**P: A detecção considera a tolerância de 5 minutos?**  
R: Não. A detecção de hora extra é feita SEM tolerância. Qualquer minuto trabalhado acima do esperado é considerado.

**P: Posso aprovar hora extra de um ponto pendente?**  
R: Não. O ponto deve estar aprovado primeiro. Esta é uma validação de segurança.

**P: O que acontece se eu rejeitar um ponto que tem hora extra pendente?**  
R: A hora extra é automaticamente rejeitada com o motivo "Ponto rejeitado pelo administrador".

**P: Como calculo o custo das horas extras?**  
R: Você pode usar a consulta SQL e multiplicar os minutos pelo valor-minuto do colaborador (salário / minutos mensais) × 1.5 (ou conforme legislação).

**P: Posso editar uma hora extra já aprovada/rejeitada?**  
R: Não diretamente pela interface. Se necessário, você pode fazer manualmente via SQL ou criar uma nova solicitação manual em `hour_bank_entries`.

---

## Suporte

Para dúvidas ou problemas:
- 📧 Email: suporte@deedo.com.br
- 📞 Telefone: (00) 0000-0000
- 🌐 Site: https://deedo.com.br

---

**Versão:** 1.0.0  
**Data:** Outubro 2025  
**Autor:** DEEDO Sistemas


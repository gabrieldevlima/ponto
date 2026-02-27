# 📊 Esquema Completo do Banco de Dados - Sistema de Ponto

## Visão Geral do Sistema

Sistema de ponto eletrônico com suporte especial para professores com aulas não consecutivas, incluindo:
- Grade horária flexível
- Pagamento fixo por número de aulas
- Horas extras controladas
- Conformidade com Portaria MTP 671/2021

---

## 🗂️ Tabelas Principais

### 1. SCHOOLS (Instituições)
```sql
CREATE TABLE schools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  lat DOUBLE NULL,                    -- Latitude (geolocalização)
  lng DOUBLE NULL,                    -- Longitude (geolocalização)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (code)
```

**Uso:** Armazena as escolas/unidades da rede

---

### 2. ADMINS (Administradores)
```sql
CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('network_admin','school_admin') NOT NULL DEFAULT 'network_admin',
  school_id INT NULL,                  -- NULL se network_admin
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (username)
- INDEX idx_admin_role (role)
- INDEX idx_admin_school (school_id)
```

**Uso:** Usuários administrativos do sistema

---

### 3. COLLABORATOR_TYPES (Tipos de Colaborador)
```sql
CREATE TABLE collaborator_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(50) NOT NULL UNIQUE,
  schedule_mode ENUM('none','classes','time') NOT NULL DEFAULT 'classes',
  requires_schedule TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

VALORES PADRÃO:
1 | Professor      | professor      | classes | 1
2 | Coordenador    | coordenador    | time    | 1
3 | Administrativo | administrativo | time    | 1

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (slug)
```

**Uso:** Define tipos de colaboradores e como sua jornada é calculada
- `classes`: Por número de aulas (professores)
- `time`: Por horário fixo (coordenadores, administrativos)
- `none`: Sem controle de jornada

---

### 4. TEACHERS (Colaboradores)
```sql
CREATE TABLE teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  cpf VARCHAR(14) NOT NULL UNIQUE,
  pin_hash VARCHAR(255) NOT NULL,
  email VARCHAR(120),
  active TINYINT(1) NOT NULL DEFAULT 1,
  type_id INT NULL,
  base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  hourly_rate DECIMAL(10,2) NULL,     -- Valor/hora (opcional)
  network_wide TINYINT(1) NOT NULL DEFAULT 0,  -- Atua em toda rede?
  face_descriptors JSON NULL,          -- Reconhecimento facial
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (type_id) REFERENCES collaborator_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (cpf)
- INDEX idx_teachers_type (type_id)
- INDEX idx_teachers_active (active)
- INDEX idx_teachers_name (name)
```

**Uso:** Armazena todos os colaboradores (professores, coordenadores, etc)

---

### 5. TEACHER_SCHOOLS (Vínculo Colaborador-Escola)
```sql
CREATE TABLE teacher_schools (
  teacher_id INT NOT NULL,
  school_id INT NOT NULL,
  PRIMARY KEY (teacher_id, school_id),
  
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (teacher_id, school_id)
- INDEX idx_ts_teacher (teacher_id)
- INDEX idx_ts_school (school_id)
```

**Uso:** Relacionamento N:N entre colaboradores e escolas

---

## 📚 Tabelas de Jornada/Rotina

### 6. TEACHER_SCHEDULES (Rotina por Aulas - PRINCIPAL PARA PROFESSORES)
```sql
CREATE TABLE teacher_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL,        -- 0=Dom, 1=Seg, ..., 6=Sáb
  classes_count INT NOT NULL DEFAULT 0,        -- ⭐ Número de aulas
  class_minutes INT NOT NULL DEFAULT 60,       -- ⭐ Duração por aula
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  
  UNIQUE KEY (teacher_id, weekday),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

EXEMPLO:
teacher_id=1, weekday=1 (segunda), classes_count=3, class_minutes=50
= Professor tem 3 aulas de 50min na segunda = 150 min fixos

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (teacher_id, weekday)
```

**💰 PAGAMENTO FIXO:** `classes_count × class_minutes` (não varia!)

---

### 7. COLLABORATOR_TIME_SCHEDULES (Rotina por Horário)
```sql
CREATE TABLE collaborator_time_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  break_minutes INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  
  UNIQUE KEY (teacher_id, weekday),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

EXEMPLO:
teacher_id=2, weekday=1, start_time='08:00', end_time='17:00', break_minutes=60
= Coordenador trabalha 8h-17h com 1h de intervalo

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (teacher_id, weekday)
```

**Uso:** Para colaboradores com horário fixo (coordenadores, administrativos)

---

### 8. CLASS_PERIODS (Grade Horária - Períodos) ⭐ NOVA
```sql
CREATE TABLE class_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NULL,                 -- NULL = global (todas escolas)
  period_number INT NOT NULL,         -- 1º período, 2º período, etc
  start_time TIME NOT NULL,           -- Horário início
  end_time TIME NOT NULL,             -- Horário fim
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  UNIQUE KEY (school_id, period_number),
  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

PERÍODOS PADRÃO (10):
1  | 07:00-07:50
2  | 07:50-08:40
3  | 08:40-09:30
4  | 09:50-10:40
5  | 10:40-11:30
6  | 11:30-12:20
7  | 13:30-14:20
8  | 14:20-15:10
9  | 15:10-16:00
10 | 16:20-17:10

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (school_id, period_number)
- INDEX idx_period_school (school_id)
- INDEX idx_period_active (active)
```

**Uso:** Define a grade horária padrão da instituição (referência)

---

### 9. TEACHER_CLASS_ASSIGNMENTS (Atribuições de Períodos) ⭐ NOVA
```sql
CREATE TABLE teacher_class_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL,        -- 0=Dom, 1=Seg, ..., 6=Sáb
  period_id INT NOT NULL,             -- Qual período o professor tem aula
  school_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  UNIQUE KEY (teacher_id, weekday, period_id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  FOREIGN KEY (period_id) REFERENCES class_periods(id) ON DELETE CASCADE,
  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

EXEMPLO:
Professor João tem aulas nas segundas:
- weekday=1, period_id=1  (1º período: 07:00-07:50)
- weekday=1, period_id=3  (3º período: 08:40-09:30)
- weekday=1, period_id=5  (5º período: 10:40-11:30)

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (teacher_id, weekday, period_id)
- INDEX idx_assignment_teacher (teacher_id)
- INDEX idx_assignment_period (period_id)
- INDEX idx_assignment_weekday (weekday)
```

**Uso:** Define em quais horários específicos o professor tem aula (OPCIONAL - apenas referência/controle)

---

## 📝 Tabela Central: ATTENDANCE (Registros de Ponto)

### 10. ATTENDANCE ⭐核心
```sql
CREATE TABLE attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  
  -- HORÁRIOS
  check_in DATETIME NULL,
  check_out DATETIME NULL,
  
  -- GEOLOCALIZAÇÃO
  check_in_lat DOUBLE NULL,
  check_in_lng DOUBLE NULL,
  check_in_acc DOUBLE NULL,
  check_out_lat DOUBLE NULL,
  check_out_lng DOUBLE NULL,
  check_out_acc DOUBLE NULL,
  
  -- MÉTODO E ORIGEM
  method VARCHAR(50) NULL DEFAULT 'pin',
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  photo VARCHAR(255) NULL,
  
  -- APROVAÇÃO
  approved TINYINT(1) DEFAULT NULL,    -- NULL=pendente, 1=aprovado, 0=rejeitado
  
  -- EDIÇÃO MANUAL
  manual_reason_id INT NULL,
  manual_reason_text VARCHAR(255) NULL,
  editado_por INT NULL,
  data_edicao DATETIME NULL,
  motivo_edicao VARCHAR(255) NULL,
  
  -- TIMESTAMPS
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  
  -- PORTARIA MTP 671/2021 ⭐
  nsr BIGINT UNSIGNED NULL UNIQUE,     -- Número Sequencial de Registro
  record_mode ENUM('online','offline') NOT NULL DEFAULT 'online',
  recorded_at DATETIME NULL,           -- Quando foi registrado
  synced_at DATETIME NULL,             -- Quando sincronizou
  hlb_sync_status ENUM('synced','failed','pending','legacy') NOT NULL DEFAULT 'pending',
  hlb_offset_seconds INT DEFAULT 0,
  device_identifier VARCHAR(255) NULL,
  receipt_generated TINYINT(1) DEFAULT 0,
  receipt_viewed_at TIMESTAMP NULL,
  
  -- ANTI-FRAUDE ⭐
  fraud_risk_level TINYINT DEFAULT 0,
  gps_mock_detected TINYINT(1) DEFAULT 0,
  device_fingerprint VARCHAR(255) NULL,
  pending_reasons TEXT NULL,           -- Motivos JSON de pendência
  
  -- CONTROLE DE FOTOS
  photo_deleted TINYINT(1) DEFAULT 0,
  photo_deleted_at DATETIME NULL,
  
  -- GRADE HORÁRIA E OVERTIME ⭐⭐⭐ NOVOS
  class_period_id INT NULL,            -- Referência ao período (opcional)
  sequence_number INT NOT NULL DEFAULT 1,  -- Sequencial (compatibilidade)
  is_overtime_candidate TINYINT(1) NOT NULL DEFAULT 0,  -- ⭐ Hora extra?
  overtime_justification TEXT NULL,    -- ⭐ Motivo do professor
  
  -- FOREIGN KEYS
  FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (manual_reason_id) REFERENCES manual_reasons(id),
  FOREIGN KEY (editado_por) REFERENCES admins(id),
  FOREIGN KEY (class_period_id) REFERENCES class_periods(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (nsr)
- INDEX idx_att_teacher_date (teacher_id, date)
- INDEX idx_att_teacher_date_seq (teacher_id, date, sequence_number)
- INDEX idx_att_period (class_period_id)
- INDEX idx_att_overtime_candidate (is_overtime_candidate, approved) ⭐
- INDEX idx_att_date (date)
- INDEX idx_att_approved (approved)
- INDEX idx_att_fraud_risk (fraud_risk_level)
- INDEX idx_att_device_fp (device_fingerprint)
- INDEX idx_att_photo_cleanup (photo_deleted, date)
```

**CAMPOS CHAVE PARA SOLUÇÃO:**

| Campo | Uso | Valores |
|-------|-----|---------|
| `teacher_id` + `date` | Identifica o registro | - |
| `check_in` / `check_out` | Horários (1 por dia) | DATETIME |
| `approved` | Status | NULL=pendente, 1=OK, 0=rejeitado |
| `is_overtime_candidate` ⭐ | Hora extra? | 0=normal, 1=overtime |
| `overtime_justification` ⭐ | Motivo | "Reunião pedagógica" |
| `class_period_id` | Período (opcional) | NULL ou ID |
| `nsr` | Portaria 671 | Número único sequencial |

---

## 💰 Tabelas de Horas e Pagamento

### 11. HOUR_BANK_ENTRIES (Banco de Horas)
```sql
CREATE TABLE hour_bank_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  minutes INT NOT NULL,               -- +crédito / -débito
  reason VARCHAR(150) NULL,
  source ENUM('auto','manual','overtime_approved') NOT NULL DEFAULT 'manual',
  ref_attendance_id INT NULL,
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (ref_attendance_id) REFERENCES attendance(id),
  FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (id)
- INDEX idx_hour_bank_teacher_date (teacher_id, date)
```

**Uso:** Registra créditos/débitos no banco de horas

---

### 12. OVERTIME_REQUESTS (Solicitações de Hora Extra) ⭐
```sql
CREATE TABLE overtime_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  minutes INT NOT NULL,               -- Minutos de hora extra
  expected_minutes INT NOT NULL DEFAULT 0,
  worked_minutes INT NOT NULL DEFAULT 0,
  justification TEXT NULL,            -- ⭐ NOVO: Justificativa
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL,
  approved_at DATETIME NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  
  UNIQUE KEY (attendance_id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (approved_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (id)
- UNIQUE KEY (attendance_id)
- INDEX idx_overtime_teacher_date (teacher_id, date)
- INDEX idx_overtime_status (status)
```

**FLUXO:**
1. Professor faz check-in em dia sem aulas → `attendance.is_overtime_candidate=1`
2. Admin aprova em `attendance_review_overtime.php`
3. Sistema cria registro aqui com `status='approved'`
4. Relatório financeiro calcula pagamento adicional

---

## 📅 Tabelas de Afastamentos

### 13. LEAVE_TYPES (Tipos de Afastamento)
```sql
CREATE TABLE leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  paid TINYINT(1) NOT NULL DEFAULT 1,          -- Remunerado?
  affects_bank TINYINT(1) NOT NULL DEFAULT 0,  -- Afeta banco de horas?
  requires_attachment TINYINT(1) NOT NULL DEFAULT 0,
  description TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

EXEMPLOS:
- Férias (paid=1)
- Licença Médica (paid=1)
- Falta Justificada (paid=0)
```

---

### 14. LEAVES (Afastamentos)
```sql
CREATE TABLE leaves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  type_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  days_count INT NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  description TEXT NULL,
  cid_code VARCHAR(10) NULL,
  attachment VARCHAR(255) NULL,
  attachment_uploaded_at TIMESTAMP NULL,
  approved TINYINT(1) DEFAULT NULL,
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (type_id) REFERENCES leave_types(id),
  FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (id)
- INDEX idx_leaves_teacher (teacher_id)
- INDEX idx_leaves_type (type_id)
- INDEX idx_leaves_approved (approved)
- INDEX idx_leaves_dates (start_date, end_date)
```

---

## 📋 Tabelas Auxiliares

### 15. MANUAL_REASONS (Motivos de Inserção Manual)
```sql
CREATE TABLE manual_reasons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

EXEMPLOS:
- Esquecimento de registro
- Problema técnico
- Atestado médico
```

---

### 16. APP_SETTINGS (Configurações)
```sql
CREATE TABLE app_settings (
  k VARCHAR(100) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CONFIGURAÇÕES PRINCIPAIS:
geofence_radius_m = 300
tolerance_minutes = 5
auto_approve_with_photo_geo = 1

⭐ OVERTIME (NOVOS):
overtime_tolerance_minutes = 30
overtime_requires_justification = 1
overtime_auto_approve = 0
overtime_multiplier = 1.5
overtime_max_daily_hours = 4
```

---

### 17. AUDIT_LOGS (Auditoria)
```sql
CREATE TABLE audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(80) NOT NULL,
  entity_id VARCHAR(64) NULL,
  payload JSON NULL,
  ip VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ÍNDICES:
- PRIMARY KEY (id)
- INDEX idx_audit_entity (entity, entity_id)
- INDEX idx_audit_admin (admin_id)
- INDEX idx_audit_created (created_at)
```

**Uso:** Registra todas as ações administrativas (segurança e conformidade)

---

## 🔗 Relacionamentos Principais

```
┌─────────────┐
│   SCHOOLS   │
└──────┬──────┘
       │
       ├─────────────────────┐
       │                     │
┌──────▼──────┐      ┌───────▼────────────┐
│   ADMINS    │      │ TEACHER_SCHOOLS    │
└─────────────┘      └───────┬────────────┘
                             │
                     ┌───────▼─────────┐
                     │    TEACHERS     │
                     └───────┬─────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
┌───────▼──────────┐ ┌──────▼──────────┐ ┌──────▼──────────────┐
│ TEACHER_SCHEDULES│ │  TIME_SCHEDULES │ │ CLASS_ASSIGNMENTS ⭐│
│  (Nº de aulas)   │ │  (Horário fixo) │ │ (Períodos - ref)   │
└──────────────────┘ └─────────────────┘ └──────┬──────────────┘
                                                 │
                                         ┌───────▼──────────┐
                                         │  CLASS_PERIODS ⭐│
                                         │  (Grade horária) │
                                         └──────────────────┘

┌──────────────────┐
│   ATTENDANCE     │ ← Registro central de ponto
└────────┬─────────┘
         │
    ┌────┴─────────────────┐
    │                      │
┌───▼──────────────┐  ┌───▼───────────────────┐
│ HOUR_BANK_ENTRIES│  │ OVERTIME_REQUESTS ⭐  │
│ (Banco de horas) │  │ (Horas extras)        │
└──────────────────┘  └───────────────────────┘
```

---

## 💡 Lógica de Pagamento

### Para Professor com Grade Horária (teacher_uses_period_system = true)

```sql
-- 1. BUSCAR AULAS ESPERADAS
SELECT classes_count, class_minutes 
FROM teacher_schedules 
WHERE teacher_id = ? AND weekday = ?;

-- Exemplo: 3 aulas × 50 min = 150 min (FIXO)

-- 2. BUSCAR HORAS EXTRAS APROVADAS
SELECT SUM(minutes) 
FROM overtime_requests 
WHERE teacher_id = ? 
  AND date BETWEEN ? AND ? 
  AND status = 'approved';

-- Exemplo: 2h = 120 min

-- 3. CALCULAR PAGAMENTO
base_salary = 3000.00
expected_minutes_month = 3200  -- (64 aulas × 50min)
overtime_minutes = 120

minute_value = base_salary / expected_minutes_month = 0.9375
overtime_payment = overtime_minutes × minute_value × 1.5 = 168.75

TOTAL = 3000.00 + 168.75 = R$ 3.168,75
```

**IMPORTANTE:** Tempo entre check-in e check-out é **IGNORADO** no cálculo!

---

## 📊 Queries Úteis

### Verificar se Professor Usa Grade Horária
```sql
SELECT COUNT(*) 
FROM teacher_class_assignments 
WHERE teacher_id = ?;

-- Se > 0: usa grade horária (pagamento fixo)
-- Se = 0: sistema tradicional
```

### Buscar Horas Extras Pendentes
```sql
SELECT a.*, t.name as teacher_name
FROM attendance a
JOIN teachers t ON t.id = a.teacher_id
WHERE a.is_overtime_candidate = 1 
  AND a.approved IS NULL
ORDER BY a.date DESC;
```

### Calcular Pagamento Mensal Completo
```sql
-- 1. Salário base
SELECT base_salary FROM teachers WHERE id = ?;

-- 2. Horas esperadas no mês
SELECT SUM(classes_count * class_minutes) 
FROM teacher_schedules 
WHERE teacher_id = ?;

-- 3. Horas extras aprovadas
SELECT SUM(minutes) 
FROM overtime_requests 
WHERE teacher_id = ? 
  AND date BETWEEN ? AND ? 
  AND status = 'approved';

-- 4. Calcular
-- Total = base_salary + (overtime × (base/expected) × 1.5)
```

### Listar Registros de um Dia
```sql
SELECT * 
FROM attendance 
WHERE teacher_id = ? 
  AND date = ?
ORDER BY check_in;

-- Professores com grade: normalmente 1 registro
-- Dias especiais com overtime: pode ter 2+ registros
```

---

## 🎯 Campos Críticos para a Solução

### attendance Table

| Campo | Tipo | Propósito | Exemplo |
|-------|------|-----------|---------|
| `teacher_id` | INT | Quem registrou | 1 |
| `date` | DATE | Quando | 2025-10-30 |
| `check_in` | DATETIME | Entrada | 07:50:00 |
| `check_out` | DATETIME | Saída | 15:10:00 |
| `approved` | TINYINT(1) | Status | NULL/0/1 |
| ⭐ `is_overtime_candidate` | TINYINT(1) | **Hora extra?** | **0 ou 1** |
| ⭐ `overtime_justification` | TEXT | **Motivo** | **"Reunião"** |
| `class_period_id` | INT | Período (ref) | NULL |
| `nsr` | BIGINT | Portaria 671 | 123456789 |

### teacher_schedules Table

| Campo | Tipo | Propósito | Exemplo |
|-------|------|-----------|---------|
| `teacher_id` | INT | Qual professor | 1 |
| `weekday` | TINYINT(1) | Dia semana | 1 (segunda) |
| ⭐ `classes_count` | INT | **Nº aulas** | **3** |
| ⭐ `class_minutes` | INT | **Min/aula** | **50** |

**PAGAMENTO FIXO = classes_count × class_minutes** (não varia!)

---

## 🔐 Constraints e Regras

### Chaves Primárias
- Todas as tabelas têm `id INT AUTO_INCREMENT PRIMARY KEY`

### Chaves Únicas
- `teachers.cpf` - CPF único no sistema
- `schools.code` - Código único da escola
- `attendance.nsr` - Número Sequencial único (Portaria 671)
- `teacher_schedules(teacher_id, weekday)` - Uma rotina por dia
- `teacher_class_assignments(teacher_id, weekday, period_id)` - Sem duplicatas

### Foreign Keys com CASCADE
- `teacher_schools` - DELETE CASCADE (remove vínculos)
- `teacher_schedules` - DELETE CASCADE (remove rotina)
- `teacher_class_assignments` - DELETE CASCADE (remove atribuições)

### Foreign Keys com SET NULL
- `attendance.class_period_id` - SET NULL (mantém registro)
- `leaves.created_by_admin_id` - Mantém histórico

---

## 📐 Diagrama Simplificado - Fluxo Principal

```
CADASTRO:
Teacher (Professor João)
   ↓
teacher_schedules (3 aulas × 50min/dia)
   ↓
teacher_class_assignments (opcional - períodos 1,3,5)

REGISTRO DIÁRIO:
Professor faz check-in 07:50
   ↓
attendance (1 registro)
   - is_overtime_candidate = 0 (dia normal)
   - approved = 1 (auto)
   ↓
Professor faz check-out 15:10
   ↓
attendance atualizado

PAGAMENTO:
reports_financial.php calcula:
   ↓
Pagamento = classes_count × class_minutes
          = 3 × 50 = 150 min (FIXO)
   ↓
Ignora tempo 07:50-15:10 (7h20min)
   ↓
Paga apenas 150 min (2h30min)

HORA EXTRA:
Professor check-in sábado (sem aulas)
   ↓
attendance (is_overtime_candidate=1)
   ↓
Admin aprova em attendance_review_overtime.php
   ↓
overtime_requests (status=approved)
   ↓
Pagamento adicional = minutes × multiplier
```

---

## 📦 Migração de Dados Existentes

### Se já tem professores cadastrados:

```sql
-- 1. Executar add_overtime_support.sql

-- 2. Todos os registros existentes:
UPDATE attendance 
SET is_overtime_candidate = 0,
    sequence_number = 1
WHERE is_overtime_candidate IS NULL;

-- 3. Pronto! Sistema funcionando
```

**Sem impacto:** Dados históricos preservados

---

## 🔍 Consultas de Verificação

### Verificar Estrutura
```sql
DESCRIBE attendance;
DESCRIBE teacher_schedules;
DESCRIBE overtime_requests;
```

### Verificar Configurações
```sql
SELECT * FROM app_settings WHERE k LIKE 'overtime_%';
```

### Verificar Grade de um Professor
```sql
SELECT 
  weekday,
  classes_count,
  class_minutes,
  (classes_count * class_minutes) as total_minutes
FROM teacher_schedules
WHERE teacher_id = 1;
```

### Verificar Horas Extras Aprovadas
```sql
SELECT 
  o.*,
  t.name as teacher_name,
  a.check_in,
  a.check_out
FROM overtime_requests o
JOIN teachers t ON t.id = o.teacher_id
JOIN attendance a ON a.id = o.attendance_id
WHERE o.status = 'approved'
ORDER BY o.date DESC;
```

---

## 📊 Estatísticas do Banco

### Tabelas Total: ~30 tabelas
- Core: 5 tabelas (schools, admins, teachers, etc)
- Jornada: 4 tabelas (schedules, periods, assignments)
- Registros: 3 tabelas (attendance, leaves, overtime)
- Financeiro: 2 tabelas (hour_bank, payroll)
- Sistema: 16+ tabelas (audit, settings, calendar, etc)

### Campos em attendance: ~45 campos
- Básicos: 13 (id, teacher, school, dates, etc)
- Geo: 6 (lat/lng entrada/saída)
- Portaria 671: 9 (nsr, record_mode, etc)
- Anti-fraude: 4 (fraud_risk, gps_mock, etc)
- **Grade/Overtime: 4** ⭐ (novos)
- Outros: 9 (photos, manual, audit)

---

**✅ Esquema Completo e Otimizado**  
**📅 Data**: 2025-10-30  
**🔖 Versão**: 2.1.0  
**👨‍💻 Sistema**: DEEDO Ponto - Solução para Professores com Grade Horária


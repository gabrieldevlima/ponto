# Relatório de Gap v1 → v2 — `ponto_oeiras2`

**Data:** 2026-04-27
**Banco analisado:** `ponto_oeiras2` (XAMPP local, MySQL/MariaDB)
**Volume preservar:** 108 teachers, 293 attendances, 624 audit_logs, 2358 auth_attempt_logs, 239 hour_bank_entries, 79 overtime_requests, 269 fraud_detection_log

---

## Sumário executivo

O banco está **muito mais próximo do v2 do que parecia inicialmente**: 34 das 36 tabelas v2 já existem, com a maioria das colunas populadas. O gap real é cirúrgico:

- **2 tabelas faltando**: `teacher_trusted_devices`, `permissions`.
- **6 colunas faltando** distribuídas em 3 tabelas (attendance, teachers, applied_migrations).
- **0 índices secundários** existem (só PKs); 30+ precisam ser criados.
- **0 foreign keys** declaradas.
- **3 tabelas com collation drift** (utf8mb4_general_ci em vez de utf8mb4_unicode_ci).
- **`nsr_sequence` vazia** (deveria ter 1 linha com current_nsr ≥ 294).
- **`v_payslips_full` é BASE TABLE** (deveria ser VIEW; nas outras 4 instâncias do projeto está como VIEW).
- **`pin_hash` em `teachers` é NOT NULL** mas todos os 108 registros têm string vazia (provável herança de `remove_pin_hash.sql` parcialmente revertido).
- **`audit_logs.admin_id` é NOT NULL** mas o código v2 grava eventos de colaborador com admin_id=NULL → INSERTs falham silenciosamente.
- **1 linha corrompida** em `attendance` (id=1754 com date='0001-01-01').

**Boa notícia:** todas as correções já estão escritas como migrations em `sql/migrations/` mas nunca foram aplicadas. As 10 órfãs identificadas SÃO o caminho v1→v2 completo.

---

## Gap detalhado

### Tabelas ausentes

| Tabela | Origem v2 | Migration que cria |
|--------|-----------|--------------------|
| `teacher_trusted_devices` | helpers.php (auth flow), api/checkin.php (device verification) | `add_pin_and_trusted_devices.sql` |
| `permissions` | install.sql (declarado mas não usado ativamente) | `consolidate_schema_2026_04.sql` |

### Colunas ausentes

| Tabela | Coluna | Tipo esperado v2 | Usado em |
|--------|--------|------------------|----------|
| `attendance` | `client_id` | VARCHAR(64) NULL UNIQUE | api/checkin.php:1499, api/checkin_bulk.php:279 (idempotência offline) |
| `attendance` | `checkout_client_id` | VARCHAR(64) NULL UNIQUE | api/checkin.php (idempotência checkout) |
| `attendance` | `client_recorded_at` | DATETIME NULL | api/checkin.php:1497 (anti-fraude timestamp) |
| `attendance` | `offline_delay_seconds` | INT NULL | api/checkin.php:1497 |
| `applied_migrations` | `content_sha` | CHAR(64) NULL | helpers.php → run_auto_migrations (drift detection) |
| `applied_migrations` | `duration_ms` | INT NULL | telemetria |
| `applied_migrations` | `statement_count` | INT NULL | telemetria |
| `applied_migrations` | `failure_reason` | TEXT NULL | telemetria |

### Colunas com constraint divergente

| Tabela | Coluna | Estado v1 | Estado v2 esperado | Risco |
|--------|--------|-----------|--------------------|-------|
| `teachers` | `pin_hash` | VARCHAR(255) NOT NULL (todos = '') | VARCHAR(255) NULL | Bloqueia self-enroll de PIN; INSERTs sem PIN falham |
| `teachers` | `pin_changed_at` | AUSENTE | DATETIME NULL | Tracking de rotação de PIN |
| `teachers` | `pin_self_enroll_allowed` | AUSENTE | TINYINT(1) NOT NULL DEFAULT 0 | Flag por colaborador para auto-cadastro de PIN |
| `audit_logs` | `admin_id` | INT NOT NULL | INT NULL | INSERTs de eventos de colaborador falham silenciosamente |

### Configurações faltantes em `app_settings`

`add_gps_fallback_settings.sql`:
- `gps_max_accuracy_m` = 200
- `gps_radius_extra_max_m` = 200

`set_face_thresholds_3rd_layer.sql` (14 settings):
- `face_checkin_threshold` = 0.70
- `face_checkin_consensus_threshold` = 0.70
- `face_checkin_consensus_ratio` = 0.20
- `face_checkin_margin` = 0.05
- `face_identify_threshold` = 0.65
- `face_identify_consensus_threshold` = 0.65
- `face_identify_consensus_ratio` = 0.30
- `face_identify_margin` = 0.08
- `face_threshold_checkin` = 0.70
- `face_threshold_identify` = 0.65
- `face_conflict_threshold` = 0.40
- `face_uniqueness_threshold` = 0.45
- `face_single_descriptor_strict_factor` = 0.95
- `face_min_descriptors_for_auth` = 1

`add_pin_and_trusted_devices.sql` (10 settings):
- `pin_length`, `pin_max_failures_window`, `pin_rate_limit_window_sec`, `pin_max_attempts_30min`, `trusted_device_inactive_days`, `trusted_device_max_per_teacher`, `trusted_device_repeat_threshold`, `trusted_device_repeat_window_days`, `adaptive_time_check`, `pin_stepup_after_reset_hours`

### Índices ausentes

`consolidate_schema_2026_04.sql` define 30+ `CREATE INDEX IF NOT EXISTS` cobrindo:
- `attendance` (teacher_date, school_date, date, nsr, approved, method, fraud_risk_level)
- `audit_logs` (entity+entity_id, action)
- `auth_attempt_logs` (ip, identifier, type+success+created_at)
- `hour_bank_entries` (teacher_date, ref_attendance)
- `overtime_requests` (teacher, attendance, status)
- `leaves` (teacher, dates)
- `attendance_edits` (attendance, admin)
- `teachers` (active, cpf)
- `liveness_nonces` (teacher+nonce, created)
- `collaborator_time_schedules` (teacher)
- `teacher_schedules` (teacher)
- `applied_migrations` (UNIQUE filename)

`add_attendance_client_id.sql` + `add_attendance_checkout_client_id.sql`: índices UNIQUE.

`add_client_recorded_at.sql`: `idx_attendance_offline_delay`.

`add_device_token.sql`: `uk_ttd_device_token`.

### Foreign keys

**Nenhuma** declarada no banco v1. install.sql v2 declara ~20. **Auditoria já confirmou 0 órfãos** em attendance, hour_bank_entries, fraud_detection_log, auth_attempt_logs (queries reais).

Essa migration **não está coberta pelas 10 órfãs** — `consolidate_schema_2026_04.sql` deixa explicitamente para depois ("FKs ficam para uma migration posterior — exigem revisão de ON DELETE/UPDATE caso a caso"). Decidir se aplica agora junto.

### Collation drift

3 tabelas em `utf8mb4_general_ci`, deveriam estar `utf8mb4_unicode_ci`:
- `applied_migrations`
- `face_rate_limits`
- `liveness_nonces`

`consolidate_schema_2026_04.sql` faz a conversão.

### Dados a corrigir / inicializar

| Item | Estado atual | Estado v2 esperado | Migration |
|------|-------------|--------------------|-----------|
| `nsr_sequence` | 0 linhas | 1 linha com `id=1, current_nsr=MAX(attendance.nsr)+1` | Necessária NOVA migration ou seed manual |
| `v_payslips_full` | BASE TABLE | VIEW | Necessária NOVA migration: DROP TABLE + CREATE VIEW |
| `attendance.id=1754` | date='0001-01-01' (corrompido) | DELETE | `consolidate_schema_2026_04.sql` |
| `attendance.school_id` NULL | 768 linhas (auditoria interna) | recovered via `teacher_schools` | `consolidate_schema_2026_04.sql` |
| `teacher_trusted_devices` | tabela inexistente | ~140 enrollments (backfill de últimos 30 dias) | `backfill_trusted_devices_2026_04_27.sql` |

---

## Classificação das 10 migrations órfãs

Ordem recomendada de execução:

| # | Arquivo | Classificação | Motivo |
|---|---------|--------------|--------|
| 1 | `consolidate_schema_2026_04.sql` | **APLICAR** (master) | Faz índices + permissions + applied_migrations cols + collation + school_id recovery + remove corrupt row. Idempotente. |
| 2 | `add_pin_and_trusted_devices.sql` | **APLICAR** | Cria teacher_trusted_devices + colunas pin_*. Pre-requisito para #6, #9. |
| 3 | `add_device_token.sql` | **APLICAR** | Adiciona device_token a teacher_trusted_devices. Pre-requisito para #9. |
| 4 | `add_attendance_client_id.sql` | **APLICAR** | Idempotência check-in offline. |
| 5 | `add_attendance_checkout_client_id.sql` | **APLICAR** | Idempotência checkout offline. |
| 6 | `add_client_recorded_at.sql` | **APLICAR** | Anti-fraude timestamp + backfill seguro (preserva dados). |
| 7 | `add_gps_fallback_settings.sql` | **APLICAR** | 2 settings via INSERT IGNORE (idempotente). |
| 8 | `set_face_thresholds_3rd_layer.sql` | **APLICAR** | 14 settings via ON DUPLICATE KEY UPDATE (sobrescreve — confirmar com user que tunings manuais podem ser sobrepujados). |
| 9 | `fix_audit_logs_nullable_admin.sql` | **APLICAR** | ALTER MODIFY admin_id NULL. Idempotente. |
| 10 | `backfill_trusted_devices_2026_04_27.sql` | **APLICAR POR ÚLTIMO** | Depende de #2 e #3 estarem prontos. Insere ~140 enrollments. Idempotente (LEFT JOIN + IS NULL). |

---

## Migrations adicionais necessárias (não cobertas pelas órfãs)

### Nova migration A: `init_nsr_sequence_v2.sql`
```sql
-- Inicializa nsr_sequence preservando MAX(nsr) atual
INSERT INTO nsr_sequence (id, current_nsr)
VALUES (1, COALESCE((SELECT MAX(nsr) FROM attendance), 0))
ON DUPLICATE KEY UPDATE current_nsr = GREATEST(current_nsr, VALUES(current_nsr));
```

### Nova migration B: `fix_v_payslips_full_view.sql`
v_payslips_full está como BASE TABLE (vazia, 0 registros). Outras 4 instâncias do projeto (ponto, ponto_oeiras, ponto_robson, ponto_saomiguel) estão como VIEW. Necessário extrair definição da view de uma instância correta:
```sql
SHOW CREATE VIEW ponto.v_payslips_full;
DROP TABLE ponto_oeiras2.v_payslips_full;
CREATE VIEW ponto_oeiras2.v_payslips_full AS [definição];
```

### Nova migration C: `relax_pin_hash_nullable.sql`
```sql
-- v2 permite teachers sem PIN (self-enroll). v1 tinha NOT NULL com '' default.
ALTER TABLE teachers MODIFY COLUMN pin_hash VARCHAR(255) NULL;
-- Limpar empty strings (108 linhas) → NULL para semântica correta
UPDATE teachers SET pin_hash = NULL WHERE pin_hash = '';
```

### Nova migration D (OPCIONAL — após Fase 2 estabilizar): `add_foreign_keys_v2.sql`
Adiciona ~20 FKs com `ON DELETE RESTRICT ON UPDATE CASCADE`. **Pré-validação obrigatória** de 0 órfãos por relação. Pode ser deferida para Fase 3 se preferir.

---

## Operações necessárias pós-órfãs (em código, não banco)

Investigar bug em `helpers.php → run_auto_migrations()`: as colunas de telemetria (content_sha, duration_ms, statement_count, failure_reason) deveriam ter sido criadas em runtime mas falharam silenciosamente. Após `consolidate_schema_2026_04.sql` rodar, conferir se a função volta a popular essas colunas em próximas migrations.

---

## Plano de aplicação seguro (Fase 2)

1. **Backup obrigatório:**
   ```
   "C:/xampp/mysql/bin/mysqldump.exe" -uroot --single-transaction --routines --triggers --hex-blob ponto_oeiras2 > backup_pre_v2_migration_2026-04-27.sql
   ```
2. **Snapshot de contagens** (10 tabelas críticas) — salvar em `audit_count_pre.txt`.
3. **Aplicar 10 órfãs na ordem** acima, registrando cada em `applied_migrations` após sucesso.
4. **Aplicar 3 migrations adicionais** (A, B, C). FKs (D) decidir depois.
5. **Snapshot de contagens pós** — comparar com pré, ROLLBACK do dump se divergência inesperada.
6. **Smoke test** de checkin, login admin/colaborador, geração de NSR.

---

## Riscos identificados

| Risco | Mitigação |
|-------|-----------|
| `set_face_thresholds_3rd_layer.sql` SOBRESCREVE settings (não é INSERT IGNORE) | Confirmar com user se algum threshold foi manualmente tunado. Se sim, capturar valores antes. |
| `add_pin_and_trusted_devices.sql` declara pin_hash NULLABLE com IF NOT EXISTS — não modifica constraint atual NOT NULL | Migration C explicita resolve isso. |
| `consolidate_schema_2026_04.sql` deleta `attendance.id=1754` | Confirmar via `SELECT * FROM attendance WHERE id=1754` antes; salvar em backup separado se contiver dados úteis. |
| `consolidate_schema_2026_04.sql` faz UPDATE em ~768 attendance.school_id NULL | Reversível via dump. Beneficia consistência. |
| FKs podem falhar se algum dado órfão escapou da auditoria | Rodar query de detecção por relação ANTES de cada FK; deferir para Fase 3 se em dúvida. |

---

**Próximo passo:** revisar este relatório e aprovar a sequência de migrations antes de aplicar.

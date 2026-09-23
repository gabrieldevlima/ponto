# Auditoria do Banco — Ponto Ribeira

**Data**: 2026-04-26
**Banco**: `ponto_ribeira` em MariaDB 10.4.32 (XAMPP local)
**Modo**: Diagnóstico read-only — nenhuma alteração feita.

---

## 1. Estado geral

| Métrica | Valor |
|---|---|
| Total de tabelas | 34 (32 base + 2 views) |
| Migrations aplicadas | 23 de 31 em disco |
| Tabelas esperadas ausentes | `permissions`, `teacher_trusted_devices` |
| Tabela inesperada (não no audit map) | `face_rate_limits` |
| **Foreign keys totais** | **0** ⚠️ |
| Engine | InnoDB em todas |
| Linhas em `attendance` | 2.172 |
| Linhas em `teachers` | 215 ativos |
| Linhas em `audit_logs` | 1.992 |
| Linhas em `auth_attempt_logs` | 13.166 |

---

## 2. Migrations aplicadas (23)

```
1   add_admins_cpf.sql                         2026-03-23 02:17:52
2   add_attendance_edit_tracking.sql           2026-03-23 02:20:56
3   add_attendance_edits_table.sql             2026-03-23 02:20:56
4   add_audit_logs_table.sql                   2026-03-23 02:20:56
5   add_class_period_system.sql                2026-03-23 05:20:56
6   add_face_enrollment_tracking.sql           2026-03-23 05:20:56
7   add_liveness_columns.sql                   2026-03-23 05:20:56
8   add_manual_tracking_columns.sql            2026-03-23 05:20:56
9   add_overtime_support.sql                   2026-03-23 05:20:56
10  add_pending_reasons.sql                    2026-03-23 05:20:56
11  add_pending_reasons_simple.sql             2026-03-23 05:20:56
12  add_photo_deleted_flag.sql                 2026-03-23 05:20:56
13  add_photo_deleted_flag_simple.sql          2026-03-23 05:20:56
14  fix_attendance_manual.sql                  2026-03-23 05:20:56
15  fix_collation_production.sql               2026-03-23 02:22:50
16  fix_face_recognition_thresholds.sql        2026-03-23 02:22:50
17  fix_missing_tables_and_columns.sql         2026-03-23 02:22:50
18  harden_auth_and_attendance_schema.sql      2026-03-23 02:22:50
19  remove_pin_hash.sql                        2026-03-23 02:22:50
20  unify_face_thresholds.sql                  2026-03-23 02:23:02
21  add_liveness_nonce_dedup.sql               2026-03-23 03:36:00
22  update_face_thresholds_hardened.sql        2026-03-23 03:36:00
23  harden_face_recognition_v3.sql             2026-03-24 16:24:34
```

## 3. Migrations em disco mas NÃO aplicadas (8)

```
add_attendance_checkout_client_id.sql
add_attendance_client_id.sql
add_client_recorded_at.sql
add_device_token.sql
add_gps_fallback_settings.sql
add_pin_and_trusted_devices.sql
fix_audit_logs_nullable_admin.sql
set_face_thresholds_3rd_layer.sql
```

→ **Causa provável**: `helpers.php:run_auto_migrations()` engole exceções (linha ~207) e segue. Falhas silenciosas explicam por que 8 migrations existem em disco há mais de um mês sem entrar.

---

## 4. Schema da `applied_migrations` desatualizado

```
id        int(11) PK auto_increment
filename  varchar(255)
applied_at datetime DEFAULT current_timestamp
```

Faltam `content_sha`, `duration_ms`, `statement_count`, `failure_reason` que `helpers.php` deveria adicionar via auto-upgrade — **upgrade nunca rodou**.

---

## 5. Collation drift (4 tabelas)

| Tabela | Collation atual | Esperado |
|---|---|---|
| `applied_migrations` | utf8mb4_general_ci | utf8mb4_unicode_ci |
| `face_rate_limits` | utf8mb4_general_ci | utf8mb4_unicode_ci |
| `liveness_nonces` | utf8mb4_general_ci | utf8mb4_unicode_ci |
| `v_payslips_full` (view) | utf8mb4_general_ci | utf8mb4_unicode_ci |

Restante: `utf8mb4_unicode_ci` ✓

---

## 6. Foreign keys: ZERO

```sql
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA='ponto_ribeira' AND CONSTRAINT_TYPE='FOREIGN KEY';
-- → 0
```

`install.sql` declara 24 FKs, `install_production_complete.sql` declara 42 — nenhuma chegou ao banco. Integridade referencial é responsabilidade exclusiva do código PHP hoje.

---

## 7. Índices secundários: praticamente inexistentes

Quase todas as tabelas têm apenas `PRIMARY KEY (id)`. Sem índices em colunas de busca como:

- `attendance.teacher_id` ❌
- `attendance.date` ❌
- `attendance.school_id` ❌
- `attendance.nsr` ❌ (impacto Portaria 671)
- `auth_attempt_logs.ip`, `auth_attempt_logs.cpf` ❌
- `audit_logs.entity_type, entity_id` ❌
- `hour_bank_entries.teacher_id, date` ❌
- `leaves.teacher_id` ❌
- `overtime_requests.teacher_id` ❌

Com 2.172 attendances + 13.166 auth logs, queries de relatório fazem **full table scan**. Performance vai degradar rapidamente.

Única tabela com índice composto: `teacher_schools (teacher_id, school_id)` — primary key.

---

## 8. Órfãos: ZERO ✓

```
orphan_attendance         0
orphan_leaves             0
orphan_hour_bank          0
orphan_overtime           0
orphan_attend_edits       0
orphan_teacher_schools    0
orphan_teacher_schedules  0
orphan_collab_time_sched  0
```

Aplicação está disciplinada — não cria registros sem pai.

---

## 9. Problemas de dados detectados

### 9.1 Linha de attendance corrompida
```
id=1754, teacher_id=247, date='0001-01-01', check_in='0001-01-01 00:00:00',
method='face', created_at='2026-04-16 16:18:07'
```
→ Bug de input validation no checkin (data zerada aceita).

### 9.2 Regressão recente em `school_id` (CRÍTICA)
- **777 attendance com `school_id = NULL`** (~36% do total).
- 100% dessas linhas estão no intervalo **2026-03-25 → 2026-04-24** (~30 dias).
- Distribuição por método: `cpf=512`, `face=84`, `manual=181`.
- Período antes de 2026-03-25: `school_id` sempre preenchido.

→ Há heurística em `api/checkin.php:1304-1324` para resolver `school_id` por última attendance do professor, mas está caindo no fallback NULL na maioria das vezes. **Investigação obrigatória** — provavelmente quebra relatórios financeiros e por escola.

### 9.3 Cobertura de auth incompleta
- 16 teachers sem PIN (de 215 ativos = 7,4%).
- 90 teachers sem face descriptors (= 41,9%).

→ Esses só conseguem bater ponto via CPF (modo manual/admin). Pode ser intencional, mas vale validar.

### 9.4 NSR (Portaria 671) ✓
- Zero NSRs duplicados.
- Zero NSRs nulos/zero.
- `nsr_sequence` (counter) presente.

---

## 10. Resumo de ações para Fase 2

| # | Item | Tipo | Prioridade |
|---|---|---|---|
| 1 | Backup `mysqldump` antes de qualquer ALTER | Operacional | CRÍTICO |
| 2 | Investigar e corrigir regressão de `school_id` | Backend | ALTA |
| 3 | Corrigir/remover row corrompida `attendance.id=1754` | Dado | ALTA |
| 4 | Adicionar índices secundários em colunas de busca | Schema | ALTA |
| 5 | Adicionar FKs (todas zero hoje) — após zero órfãos confirmado | Schema | MÉDIA |
| 6 | Padronizar collation `utf8mb4_unicode_ci` em 4 tabelas/views | Schema | MÉDIA |
| 7 | Aplicar 8 migrations pendentes manualmente, com diagnóstico | Schema | ALTA |
| 8 | Auto-upgrade do schema `applied_migrations` (telemetria) | Schema | MÉDIA |
| 9 | Criar tabelas faltantes: `permissions`, `teacher_trusted_devices` | Schema | ALTA |
| 10 | Consolidar migrations duplicadas `*_simple.sql` (deletar) | Limpeza | BAIXA |

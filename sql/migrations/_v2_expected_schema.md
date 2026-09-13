# Schema Esperado pelo Código v2.0

Relatório de auditoria de schema: tabelas e colunas referenciadas pelo código PHP v2.0
Data: 2026-04-27
Thoroughness: Very Thorough

---

## Executive Summary

O código v2.0 referencia 29 tabelas principais + 1 VIEW.

**Novas tabelas no v2**:
- teacher_trusted_devices
- attendance_edits
- audit_logs
- class_periods
- teacher_class_assignments
- fraud_detection_log
- auth_attempt_logs
- liveness_nonces
- permissions

**Coluna-chave nova**: nsr (número sequencial único em attendance)

---

## Tabelas Críticas Modificadas

### 1. attendance - 28 COLUNAS NOVAS

Colunas adicionadas em v2:
- client_id VARCHAR(64) NULL (idempotência check-in offline)
- checkout_client_id VARCHAR(64) NULL (idempotência checkout offline)
- nsr BIGINT UNSIGNED UNIQUE (número sequencial)
- record_mode ENUM('online','offline')
- recorded_at DATETIME (timestamp servidor)
- synced_at DATETIME
- hlb_sync_status ENUM('synced','failed','pending','legacy')
- hlb_offset_seconds INT
- device_identifier VARCHAR(255)
- receipt_generated TINYINT(1)
- receipt_viewed_at TIMESTAMP
- fraud_risk_level TINYINT
- gps_mock_detected TINYINT(1)
- device_fingerprint VARCHAR(255)
- pending_reasons TEXT (JSON)
- class_period_id INT (FK class_periods)
- sequence_number INT
- is_overtime_candidate TINYINT(1)
- overtime_justification TEXT
- client_recorded_at DATETIME
- offline_delay_seconds INT
- liveness_score FLOAT
- liveness_data JSON
- editado_por INT
- data_edicao DATETIME
- motivo_edicao TEXT
- manual_by_admin_id INT
- manual_created_at DATETIME

### 2. teachers - 5 COLUNAS NOVAS

- pin_hash VARCHAR(255) NULL
- pin_changed_at DATETIME NULL
- pin_self_enroll_allowed TINYINT(1) DEFAULT 0
- face_enrolled_at DATETIME NULL
- face_enrollment_version INT DEFAULT 1

### 3. applied_migrations - 4 COLUNAS NOVAS

- content_sha CHAR(64) NULL
- duration_ms INT NULL
- statement_count INT NULL
- failure_reason TEXT NULL

---

## Tabelas Completamente Novas (9)

1. teacher_trusted_devices
   - id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
   - teacher_id INT NOT NULL
   - device_fingerprint CHAR(64) NOT NULL
   - device_token CHAR(64) NULL
   - label VARCHAR(100) NULL
   - enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP
   - last_used_at DATETIME NULL
   - enrollment_method ENUM('face_validated','repeated_use','admin')
   - is_active TINYINT(1) DEFAULT 1
   - UK: uk_teacher_fp (teacher_id, device_fingerprint)
   - UK: uk_ttd_device_token (device_token)

2. attendance_edits
   - id INT AUTO_INCREMENT PRIMARY KEY
   - attendance_id INT NOT NULL FK attendance
   - edited_by INT NOT NULL FK admins
   - edited_at DATETIME NOT NULL
   - reason TEXT NOT NULL
   - type VARCHAR(50)
   - diff_minutes INT
   - changed_fields TEXT
   - before_json TEXT
   - after_json TEXT

3. audit_logs
   - id INT AUTO_INCREMENT PRIMARY KEY
   - admin_id INT NOT NULL FK admins
   - action VARCHAR(50) NOT NULL
   - entity VARCHAR(50) NOT NULL
   - entity_id VARCHAR(50) NULL
   - payload TEXT NULL
   - ip VARCHAR(45) NULL
   - created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

4. auth_attempt_logs
   - id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
   - attempt_type VARCHAR(32) NOT NULL
   - identifier VARCHAR(191) NOT NULL
   - teacher_id INT NULL FK teachers
   - ip_address VARCHAR(64) NULL
   - user_agent VARCHAR(255) NULL
   - success TINYINT(1) DEFAULT 0
   - reason VARCHAR(100) NULL
   - details TEXT NULL
   - created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

5. liveness_nonces
   - id, teacher_id, nonce, created_at

6. fraud_detection_log
   - id, attendance_id, teacher_id, detection_type, risk_level, details, ip_address, user_agent, created_at

7. class_periods
   - id INT AUTO_INCREMENT PRIMARY KEY
   - school_id INT NULL FK schools
   - period_number INT NOT NULL
   - start_time TIME NOT NULL
   - end_time TIME NOT NULL
   - active TINYINT(1) DEFAULT 1
   - created_at TIMESTAMP
   - UK: uq_school_period (school_id, period_number)

8. teacher_class_assignments
   - id INT AUTO_INCREMENT PRIMARY KEY
   - teacher_id INT NOT NULL FK teachers
   - weekday TINYINT(1) NOT NULL
   - period_id INT NOT NULL FK class_periods
   - school_id INT NULL FK schools
   - created_at TIMESTAMP
   - UK: uq_teacher_weekday_period (teacher_id, weekday, period_id)

9. permissions
   - id INT AUTO_INCREMENT PRIMARY KEY
   - admin_id INT NOT NULL
   - permission_key VARCHAR(64) NOT NULL
   - granted_at DATETIME DEFAULT CURRENT_TIMESTAMP
   - UK: uk_admin_perm (admin_id, permission_key)

---

## Índices a Criar (20+)

attendance (16 novos):
- uk_attendance_client_id
- uk_attendance_checkout_client_id
- idx_attendance_teacher_date
- idx_attendance_school_date
- idx_attendance_date
- idx_attendance_nsr
- idx_attendance_approved
- idx_attendance_method
- idx_attendance_fraud
- idx_attendance_teacher_date_seq
- idx_attendance_period
- idx_attendance_offline_delay
- idx_attendance_liveness
- idx_att_overtime_candidate
- idx_attendance_device_fp
- idx_attendance_fraud_risk

Outros:
- applied_migrations.uk_applied_migrations_filename
- audit_logs.idx_audit_logs_entity, idx_audit_logs_action
- auth_attempt_logs.idx_auth_attempts_ip, idx_auth_attempts_identifier
- class_periods.idx_period_school, idx_period_active
- teacher_class_assignments.idx_assignment_teacher, idx_assignment_period, idx_assignment_weekday
- liveness_nonces.idx_liveness_nonces_teacher, idx_liveness_nonces_created
- attendance_edits.idx_attendance_edits_attendance, idx_attendance_edits_admin
- teachers.idx_teachers_active, idx_teachers_cpf

---

## Migrações Aplicadas (33 arquivos em /sql/migrations/)

Principais:
1. add_pin_and_trusted_devices.sql
2. add_device_token.sql
3. backfill_trusted_devices_2026_04_27.sql
4. add_overtime_support.sql
5. add_attendance_client_id.sql
6. add_attendance_checkout_client_id.sql
7. add_class_period_system.sql
8. add_client_recorded_at.sql
9. harden_auth_and_attendance_schema.sql
10. add_attendance_edits_table.sql
11. add_audit_logs_table.sql
12. add_liveness_columns.sql
13. add_face_enrollment_tracking.sql
14. add_manual_tracking_columns.sql
15. consolidate_schema_2026_04.sql (consolida tudo)

---

## Checklist de Migração v1 → v2

- Criar 9 tabelas novas
- Adicionar 28 colunas em attendance
- Adicionar 5 colunas em teachers
- Adicionar 4 colunas em applied_migrations
- Criar 20+ índices secundários
- Backfill ~140 trusted_devices
- Validar FKs
- Verificar collation utf8mb4_unicode_ci
- Executar consolidate_schema_2026_04.sql por último
- Testar helpers.php::run_auto_migrations()

---

Gerado: 2026-04-27
Análise completa do código v2.0

-- =====================================================================
-- CONSOLIDATE_SCHEMA_2026_04 — Auditoria de 2026-04-26
-- =====================================================================
-- Objetivo: levar o banco a 100% de consistência.
--
-- 1. Atualiza schema da tabela `applied_migrations` (telemetria que
--    helpers.php tenta criar mas falhou silenciosamente).
-- 2. Padroniza collation utf8mb4_unicode_ci nas 3 tabelas em drift.
-- 3. Recupera school_id NULL em attendance via primeira filiação
--    do colaborador em teacher_schools.
-- 4. Remove linha corrompida attendance.id=1754 (date='0001-01-01').
-- 5. Cria índices secundários ausentes (full table scan → index lookup).
-- 6. Cria tabela `permissions` ausente.
-- 7. (FKs ficam para uma migration posterior — exigem revisão de
--     ON DELETE/UPDATE caso a caso e podem afetar performance de DELETE.)
--
-- Idempotente: pode ser re-executado sem efeito colateral.
-- =====================================================================

-- 1. APPLIED_MIGRATIONS — adicionar colunas de telemetria + UNIQUE em filename
ALTER TABLE applied_migrations
  ADD COLUMN IF NOT EXISTS content_sha CHAR(64) NULL AFTER applied_at,
  ADD COLUMN IF NOT EXISTS duration_ms INT NULL AFTER content_sha,
  ADD COLUMN IF NOT EXISTS statement_count INT NULL AFTER duration_ms,
  ADD COLUMN IF NOT EXISTS failure_reason TEXT NULL AFTER statement_count;

-- UNIQUE em filename (não há duplicatas hoje, seguro)
CREATE UNIQUE INDEX IF NOT EXISTS uk_applied_migrations_filename ON applied_migrations(filename);

-- 2. COLLATION DRIFT — converter para utf8mb4_unicode_ci
ALTER TABLE applied_migrations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE face_rate_limits CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE liveness_nonces CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. RECUPERAR school_id NULL — usa primeira filiação ativa em teacher_schools.
--    Resolve 768 das 777 NULLs (os 9 sem teacher_schools ficam NULL para ação manual).
UPDATE attendance a
JOIN (
  SELECT teacher_id, MIN(school_id) AS first_school_id
    FROM teacher_schools
   GROUP BY teacher_id
) ts ON ts.teacher_id = a.teacher_id
SET a.school_id = ts.first_school_id
WHERE a.school_id IS NULL;

-- 4. REMOVER LINHA CORROMPIDA — attendance.id=1754 com date='0001-01-01'
--    (Mantém-se em backup pre_audit_2026-04-26.sql se precisar reverter.)
DELETE FROM attendance WHERE id = 1754 AND `date` = '0001-01-01';

-- 5. ÍNDICES SECUNDÁRIOS — performance de relatórios e auth
CREATE INDEX IF NOT EXISTS idx_attendance_teacher_date ON attendance(teacher_id, `date`);
CREATE INDEX IF NOT EXISTS idx_attendance_school_date ON attendance(school_id, `date`);
CREATE INDEX IF NOT EXISTS idx_attendance_date ON attendance(`date`);
CREATE INDEX IF NOT EXISTS idx_attendance_nsr ON attendance(nsr);
CREATE INDEX IF NOT EXISTS idx_attendance_approved ON attendance(approved);
CREATE INDEX IF NOT EXISTS idx_attendance_method ON attendance(method);
CREATE INDEX IF NOT EXISTS idx_attendance_fraud ON attendance(fraud_risk_level);

CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action);

CREATE INDEX IF NOT EXISTS idx_auth_attempts_ip ON auth_attempt_logs(ip_address);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_identifier ON auth_attempt_logs(identifier);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_type_success ON auth_attempt_logs(attempt_type, success, created_at);

CREATE INDEX IF NOT EXISTS idx_hour_bank_teacher_date ON hour_bank_entries(teacher_id, `date`);
CREATE INDEX IF NOT EXISTS idx_hour_bank_ref ON hour_bank_entries(ref_attendance_id);

CREATE INDEX IF NOT EXISTS idx_overtime_teacher ON overtime_requests(teacher_id);
CREATE INDEX IF NOT EXISTS idx_overtime_attendance ON overtime_requests(attendance_id);
CREATE INDEX IF NOT EXISTS idx_overtime_status ON overtime_requests(status);

CREATE INDEX IF NOT EXISTS idx_leaves_teacher ON leaves(teacher_id);
CREATE INDEX IF NOT EXISTS idx_leaves_dates ON leaves(start_date, end_date);

CREATE INDEX IF NOT EXISTS idx_attendance_edits_attendance ON attendance_edits(attendance_id);
CREATE INDEX IF NOT EXISTS idx_attendance_edits_admin ON attendance_edits(edited_by);

CREATE INDEX IF NOT EXISTS idx_teachers_active ON teachers(active);
CREATE INDEX IF NOT EXISTS idx_teachers_cpf ON teachers(cpf);

CREATE INDEX IF NOT EXISTS idx_liveness_nonces_teacher ON liveness_nonces(teacher_id, nonce);
CREATE INDEX IF NOT EXISTS idx_liveness_nonces_created ON liveness_nonces(created_at);

CREATE INDEX IF NOT EXISTS idx_collab_time_sched_teacher ON collaborator_time_schedules(teacher_id);
CREATE INDEX IF NOT EXISTS idx_teacher_schedules_teacher ON teacher_schedules(teacher_id);

-- 6. PERMISSIONS — tabela ausente que install.sql declara
CREATE TABLE IF NOT EXISTS permissions (
  id INT NOT NULL AUTO_INCREMENT,
  admin_id INT NOT NULL,
  permission_key VARCHAR(64) NOT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_admin_perm (admin_id, permission_key),
  KEY idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

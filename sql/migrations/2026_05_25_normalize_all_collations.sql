-- Normaliza collation de TODAS as tabelas e colunas para utf8mb4_unicode_ci.
--
-- BUG ORIGINAL: produção tem collation_server=utf8mb4_general_ci (default MariaDB),
-- então tabelas e views criadas em momentos diferentes acabaram com collations
-- misturadas (general_ci e unicode_ci). Qualquer comparação cross-collation gera
-- erro 1267 "Illegal mix of collations".
--
-- Fix: converte cada tabela (CONVERT TO) para unicode_ci. CONVERT TO é seguro porque
-- preserva dados — apenas reescreve com a nova collation. Idempotente: aplicar 2x não
-- causa problema (apenas reescreve com mesma collation).
--
-- Foreign keys: MariaDB permite converter mesmo com FKs (não muda tipo de dado, só
-- collation). Erros 1832 (FK altered column) são tolerados pelo runner de migrations.
--
-- IMPORTANTE: ALTER em tabelas grandes pode demorar. O sistema continua respondendo
-- — apenas as queries DDL bloqueiam temporariamente cada tabela. Aceitável para
-- janela de manutenção.

ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE attendance CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teachers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teacher_schools CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teacher_schedules CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE collaborator_time_schedules CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE collaborator_types CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE calendar_exceptions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE manual_reasons CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE admins CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE schools CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE nsr_sequence CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leaves CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leave_types CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE attendance_edits CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE attendance_audit_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE attendance_checkout_requests CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE attendance_break_requests CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE audit_logs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE overtime_requests CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE hour_bank_entries CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE applied_migrations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE app_settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE employer_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE class_periods CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teacher_class_assignments CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE fraud_detection_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE payslips CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE trusted_devices CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE pin_recovery_codes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE auth_throttle CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE collaborator_remember_tokens CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE academic_calendar CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE mobile_holidays CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE antifraud_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
-- Script de correção: PRIMARY KEY + AUTO_INCREMENT para produção
-- Rodar via phpMyAdmin ou mysql CLI no banco de produção
-- Seguro para rodar múltiplas vezes (idempotente)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- PROCEDIMENTO: Para cada tabela, tenta adicionar PK e AI
-- Se PK já existe, o ADD PRIMARY KEY falha silenciosamente
-- ============================================================

-- Procedure temporária para adicionar PK + AI de forma segura
DROP PROCEDURE IF EXISTS fix_pk_ai;
DELIMITER //
CREATE PROCEDURE fix_pk_ai(IN tbl VARCHAR(64), IN col_type VARCHAR(50))
BEGIN
    DECLARE has_pk INT DEFAULT 0;

    -- Verifica se já tem PRIMARY KEY na coluna id
    SELECT COUNT(*) INTO has_pk
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.KEY_COLUMN_USAGE kcu
      ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
      AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
      AND tc.TABLE_NAME = kcu.TABLE_NAME
    WHERE tc.TABLE_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = tbl
      AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
      AND kcu.COLUMN_NAME = 'id';

    -- Se não tem PK, primeiro remove AI, adiciona PK, depois recoloca AI
    IF has_pk = 0 THEN
        SET @sql1 = CONCAT('ALTER TABLE `', tbl, '` MODIFY `id` ', col_type, ' NOT NULL');
        PREPARE stmt FROM @sql1;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;

        SET @sql2 = CONCAT('ALTER TABLE `', tbl, '` ADD PRIMARY KEY (`id`)');
        PREPARE stmt FROM @sql2;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    -- Garante AUTO_INCREMENT
    SET @sql3 = CONCAT('ALTER TABLE `', tbl, '` MODIFY `id` ', col_type, ' NOT NULL AUTO_INCREMENT');
    PREPARE stmt FROM @sql3;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END //
DELIMITER ;

-- ============================================================
-- Executar para todas as tabelas com coluna id
-- ============================================================

CALL fix_pk_ai('academic_calendar', 'INT(11)');
CALL fix_pk_ai('admins', 'INT(11)');
CALL fix_pk_ai('antifraud_config', 'INT(11)');
CALL fix_pk_ai('app_settings', 'INT(11)');
CALL fix_pk_ai('applied_migrations', 'INT(11)');
CALL fix_pk_ai('attendance', 'INT(11)');
CALL fix_pk_ai('attendance_audit_log', 'BIGINT(20) UNSIGNED');
CALL fix_pk_ai('attendance_edits', 'INT(11)');
CALL fix_pk_ai('audit_logs', 'INT(11)');
CALL fix_pk_ai('auth_attempt_logs', 'BIGINT(20) UNSIGNED');
CALL fix_pk_ai('calendar_exceptions', 'INT(11)');
CALL fix_pk_ai('class_periods', 'INT(11)');
CALL fix_pk_ai('collaborator_time_schedules', 'INT(11)');
CALL fix_pk_ai('collaborator_types', 'INT(11)');
CALL fix_pk_ai('employer_config', 'INT(11)');
CALL fix_pk_ai('fraud_detection_log', 'BIGINT(20) UNSIGNED');
CALL fix_pk_ai('hour_bank_entries', 'INT(11)');
CALL fix_pk_ai('leave_attachment_access_log', 'BIGINT(20) UNSIGNED');
CALL fix_pk_ai('leave_types', 'INT(11)');
CALL fix_pk_ai('leaves', 'INT(11)');
CALL fix_pk_ai('lgpd_consent', 'BIGINT(20) UNSIGNED');
CALL fix_pk_ai('liveness_nonces', 'BIGINT(20)');
CALL fix_pk_ai('manual_reasons', 'INT(11)');
CALL fix_pk_ai('mobile_holidays', 'INT(11)');
CALL fix_pk_ai('nsr_sequence', 'INT(11)');
CALL fix_pk_ai('overtime_requests', 'INT(11)');
CALL fix_pk_ai('payslip_items', 'INT(11)');
CALL fix_pk_ai('payslips', 'INT(11)');
CALL fix_pk_ai('schools', 'INT(11)');
CALL fix_pk_ai('teacher_class_assignments', 'INT(11)');
CALL fix_pk_ai('teacher_schedules', 'INT(11)');
CALL fix_pk_ai('teachers', 'INT(11)');

-- ============================================================
-- Tabelas com chave composta (sem coluna id)
-- ============================================================

-- teacher_schools: PK composta (teacher_id, school_id)
SET @has_pk_ts = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_schools' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @sql_ts = IF(@has_pk_ts = 0,
    'ALTER TABLE `teacher_schools` ADD PRIMARY KEY (`teacher_id`, `school_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql_ts;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- face_rate_limits: PK (rate_key)
SET @has_pk_frl = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'face_rate_limits' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @sql_frl = IF(@has_pk_frl = 0,
    'ALTER TABLE `face_rate_limits` ADD PRIMARY KEY (`rate_key`)',
    'SELECT 1');
PREPARE stmt FROM @sql_frl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Limpeza
-- ============================================================
DROP PROCEDURE IF EXISTS fix_pk_ai;

SET FOREIGN_KEY_CHECKS = 1;

-- Pronto! Todas as tabelas agora têm PRIMARY KEY + AUTO_INCREMENT.

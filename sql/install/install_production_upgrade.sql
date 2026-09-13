-- =====================================================================
-- DEEDO PONTO - UPGRADE PARA VERSÃO COMPLETA (Produção Hostinger)
-- =====================================================================
-- Use este script se JÁ TEM o sistema base instalado
-- Adiciona: Portaria 671 + Afastamentos + Anti-Fraude + Calendário + Holerites
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- UPGRADE 1: CAMPOS PORTARIA 671/2021 EM ATTENDANCE
-- =====================================================================

ALTER TABLE attendance 
  ADD COLUMN IF NOT EXISTS nsr BIGINT UNSIGNED NULL UNIQUE COMMENT 'Número Sequencial de Registro',
  ADD COLUMN IF NOT EXISTS record_mode ENUM('online','offline') NOT NULL DEFAULT 'online',
  ADD COLUMN IF NOT EXISTS recorded_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS synced_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS hlb_sync_status ENUM('synced','failed','pending','legacy') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS hlb_offset_seconds INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS device_identifier VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS receipt_generated TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS receipt_viewed_at TIMESTAMP NULL;

CREATE INDEX IF NOT EXISTS idx_att_nsr ON attendance(nsr);

-- =====================================================================
-- UPGRADE 2: CAMPOS ANTI-FRAUDE EM ATTENDANCE
-- =====================================================================

ALTER TABLE attendance 
  ADD COLUMN IF NOT EXISTS fraud_risk_level TINYINT DEFAULT 0 COMMENT '0=baixo, 1=médio, 2=alto',
  ADD COLUMN IF NOT EXISTS gps_mock_detected TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS device_fingerprint VARCHAR(255) NULL;

CREATE INDEX IF NOT EXISTS idx_att_fraud_risk ON attendance(fraud_risk_level);
CREATE INDEX IF NOT EXISTS idx_att_device_fp ON attendance(device_fingerprint);

-- =====================================================================
-- UPGRADE 3: CAMPOS AFASTAMENTOS EM LEAVES
-- =====================================================================

ALTER TABLE leaves 
  ADD COLUMN IF NOT EXISTS days_count INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS description TEXT NULL,
  ADD COLUMN IF NOT EXISTS cid_code VARCHAR(10) NULL,
  ADD COLUMN IF NOT EXISTS attachment VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS attachment_uploaded_at TIMESTAMP NULL;

ALTER TABLE leave_types
  ADD COLUMN IF NOT EXISTS requires_attachment TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS description TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_leaves_attachment ON leaves(attachment);

UPDATE leaves SET days_count = GREATEST(1, DATEDIFF(end_date, start_date) + 1) WHERE days_count = 0;

-- =====================================================================
-- UPGRADE 4: NOVAS TABELAS
-- =====================================================================

-- NSR Sequence
CREATE TABLE IF NOT EXISTS nsr_sequence (
  id INT PRIMARY KEY DEFAULT 1,
  current_nsr BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO nsr_sequence (id, current_nsr) VALUES (1, 0);

-- Audit Log
CREATE TABLE IF NOT EXISTS attendance_audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NULL,
  admin_id INT NULL,
  action VARCHAR(50) NOT NULL,
  field_changed VARCHAR(100) NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  reason TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Employer Config
CREATE TABLE IF NOT EXISTS employer_config (
  id INT PRIMARY KEY DEFAULT 1,
  company_name VARCHAR(255) NOT NULL DEFAULT 'Empresa LTDA',
  cnpj VARCHAR(18) NULL,
  address TEXT NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(2) NULL,
  phone VARCHAR(20) NULL,
  system_name VARCHAR(100) NOT NULL DEFAULT 'DEEDO Ponto',
  system_version VARCHAR(20) NOT NULL DEFAULT '1.0.0',
  rep_category VARCHAR(50) NOT NULL DEFAULT 'REP-P',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO employer_config (id) VALUES (1);

-- LGPD Consent
CREATE TABLE IF NOT EXISTS lgpd_consent (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  consent_given TINYINT(1) NOT NULL DEFAULT 0,
  consent_date DATETIME NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_consent_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fraud Detection Log
CREATE TABLE IF NOT EXISTS fraud_detection_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NULL,
  teacher_id INT NOT NULL,
  detection_type VARCHAR(50) NOT NULL,
  risk_level TINYINT NOT NULL DEFAULT 1,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fraud_log_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
  CONSTRAINT fk_fraud_log_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Antifraud Config
CREATE TABLE IF NOT EXISTS antifraud_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  config_key VARCHAR(100) NOT NULL UNIQUE,
  config_value TEXT NOT NULL,
  description TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO antifraud_config (config_key, config_value, description) VALUES
('max_distance_km_per_minute', '1', 'Distância máxima permitida'),
('fraud_alert_threshold', '3', 'Detecções para alertar');

-- Leave Attachment Access Log
CREATE TABLE IF NOT EXISTS leave_attachment_access_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  leave_id INT NOT NULL,
  accessed_by_admin_id INT NULL,
  accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  action VARCHAR(50) NOT NULL DEFAULT 'view',
  CONSTRAINT fk_attachment_log_leave FOREIGN KEY (leave_id) REFERENCES leaves(id) ON DELETE CASCADE,
  CONSTRAINT fk_attachment_log_admin FOREIGN KEY (accessed_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Calendar Exceptions
CREATE TABLE IF NOT EXISTS calendar_exceptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NULL,
  date DATE NOT NULL,
  type ENUM('holiday','workday','compensation','academic_event','exam_day','recess') NOT NULL DEFAULT 'holiday',
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  recurrence ENUM('none','yearly','biannual') NOT NULL DEFAULT 'none',
  is_working_day TINYINT(1) NOT NULL DEFAULT 0,
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_calendar_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  UNIQUE KEY uq_school_date_type (school_id, date, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Academic Calendar
CREATE TABLE IF NOT EXISTS academic_calendar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  year INT NOT NULL,
  semester TINYINT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_academic_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  UNIQUE KEY uq_school_year_semester (school_id, year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mobile Holidays
CREATE TABLE IF NOT EXISTS mobile_holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  calculation_rule TEXT NULL,
  days_offset INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payslips
CREATE TABLE IF NOT EXISTS payslips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  reference_month DATE NOT NULL,
  base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  worked_minutes INT NOT NULL DEFAULT 0,
  expected_minutes INT NOT NULL DEFAULT 0,
  overtime_minutes INT NOT NULL DEFAULT 0,
  overtime_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  deficit_minutes INT NOT NULL DEFAULT 0,
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  gross_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  net_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  notes TEXT NULL,
  generated_by_admin_id INT NULL,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  viewed_by_teacher_at TIMESTAMP NULL,
  CONSTRAINT fk_payslip_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_payslip_admin FOREIGN KEY (generated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  UNIQUE KEY uq_teacher_month (teacher_id, reference_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payslip Items
CREATE TABLE IF NOT EXISTS payslip_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payslip_id INT NOT NULL,
  type ENUM('earning','deduction') NOT NULL,
  description VARCHAR(150) NOT NULL,
  value DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payslip_item_payslip FOREIGN KEY (payslip_id) REFERENCES payslips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- UPGRADE 5: TRIGGERS
-- =====================================================================

DELIMITER //

-- Trigger de NSR REMOVIDO (auditoria 2026-08-05): o NSR é emitido pelo PHP
-- (SELECT ... FOR UPDATE em nsr_sequence, na mesma transação do INSERT) e, a
-- partir da Fase 1 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md, pelo livro
-- fiscal `nsr_ledger`. Duas fontes incrementando a sequência geram saltos e
-- duplicidade de NSR. Para limpar um ambiente:
--     DROP TRIGGER IF EXISTS attendance_before_insert_nsr;
DROP TRIGGER IF EXISTS attendance_before_insert_nsr//

DROP TRIGGER IF EXISTS attendance_update_audit//
CREATE TRIGGER attendance_update_audit
AFTER UPDATE ON attendance FOR EACH ROW
BEGIN
  IF OLD.approved != NEW.approved OR OLD.check_in != NEW.check_in OR OLD.check_out != NEW.check_out THEN
    INSERT INTO attendance_audit_log (attendance_id, action, field_changed)
    VALUES (NEW.id, 'UPDATE', 'attendance_modified');
  END IF;
END//

DELIMITER ;

-- =====================================================================
-- UPGRADE 6: DADOS INICIAIS
-- =====================================================================

INSERT IGNORE INTO mobile_holidays (name, days_offset, is_active) VALUES
('Páscoa', 0, 1), ('Carnaval', -47, 1), ('Sexta-feira Santa', -2, 1), ('Corpus Christi', 60, 1);

INSERT IGNORE INTO calendar_exceptions (school_id, date, type, name, recurrence, is_working_day) VALUES
(NULL, '2025-01-01', 'holiday', 'Confraternização Universal', 'yearly', 0),
(NULL, '2025-04-21', 'holiday', 'Tiradentes', 'yearly', 0),
(NULL, '2025-05-01', 'holiday', 'Dia do Trabalho', 'yearly', 0),
(NULL, '2025-09-07', 'holiday', 'Independência do Brasil', 'yearly', 0),
(NULL, '2025-10-12', 'holiday', 'Nossa Senhora Aparecida', 'yearly', 0),
(NULL, '2025-11-02', 'holiday', 'Finados', 'yearly', 0),
(NULL, '2025-11-15', 'holiday', 'Proclamação da República', 'yearly', 0),
(NULL, '2025-12-25', 'holiday', 'Natal', 'yearly', 0);

INSERT IGNORE INTO leave_types (name, code, paid, requires_attachment) VALUES
('Atestado Médico', 'ATESTADO', 1, 1),
('Férias', 'FERIAS', 1, 0),
('Licença Maternidade', 'LICENCA_MATERNIDADE', 1, 1);

-- Popula campos novos em registros existentes
UPDATE attendance 
SET recorded_at = COALESCE(check_in, check_out),
    synced_at = COALESCE(check_in, check_out),
    record_mode = 'online',
    hlb_sync_status = 'legacy'
WHERE recorded_at IS NULL;

UPDATE leaves 
SET days_count = GREATEST(1, DATEDIFF(end_date, start_date) + 1)
WHERE days_count = 0 OR days_count IS NULL;

SET FOREIGN_KEY_CHECKS = 1;

SELECT '✓ Upgrade executado com sucesso!' AS status,
       'Novos módulos adicionados: Anti-Fraude, Calendário, Holerites' AS info;


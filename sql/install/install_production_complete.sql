-- =====================================================================
-- DEEDO PONTO - INSTALAÇÃO COMPLETA PARA PRODUÇÃO
-- =====================================================================
-- Script consolidado para deploy em ambiente de produção (Hostinger)
-- Inclui: Base + Portaria 671 + Afastamentos + Anti-Fraude + Calendário + Holerites
-- Versão: 1.0.0
-- Data: 2025-10-21
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- Selecione o banco de dados (ajuste o nome se necessário)
-- USE seu_banco_aqui;

-- =====================================================================
-- PARTE 1: TABELAS CORE
-- =====================================================================

CREATE TABLE IF NOT EXISTS schools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  lat DOUBLE NULL,
  lng DOUBLE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  cpf VARCHAR(11) UNIQUE NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('network_admin','school_admin') NOT NULL DEFAULT 'network_admin',
  school_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_admin_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_admin_role ON admins(role);
CREATE INDEX IF NOT EXISTS idx_admin_school ON admins(school_id);

CREATE TABLE IF NOT EXISTS collaborator_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(50) NOT NULL UNIQUE,
  schedule_mode ENUM('none','classes','time') NOT NULL DEFAULT 'classes',
  requires_schedule TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manual_reasons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  cpf VARCHAR(14) NOT NULL UNIQUE,
  email VARCHAR(120),
  active TINYINT(1) NOT NULL DEFAULT 1,
  type_id INT NULL,
  base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  network_wide TINYINT(1) NOT NULL DEFAULT 0,
  face_descriptors JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_teachers_type FOREIGN KEY (type_id) REFERENCES collaborator_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_teachers_type ON teachers(type_id);
CREATE INDEX IF NOT EXISTS idx_teachers_active ON teachers(active);
CREATE INDEX IF NOT EXISTS idx_teachers_name ON teachers(name);

CREATE TABLE IF NOT EXISTS teacher_schools (
  teacher_id INT NOT NULL,
  school_id INT NOT NULL,
  PRIMARY KEY (teacher_id, school_id),
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_ts_teacher ON teacher_schools(teacher_id);
CREATE INDEX IF NOT EXISTS idx_ts_school ON teacher_schools(school_id);

CREATE TABLE IF NOT EXISTS teacher_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL,
  classes_count INT NOT NULL DEFAULT 0,
  class_minutes INT NOT NULL DEFAULT 60,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_teacher_weekday (teacher_id, weekday),
  CONSTRAINT fk_schedule_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS collaborator_time_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  break_minutes INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_teacher_weekday_time (teacher_id, weekday),
  CONSTRAINT fk_time_schedule_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- Grade horária padrão e atribuições por período
-- =====================================================================

CREATE TABLE IF NOT EXISTS class_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NULL,
  period_number INT NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_period_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  UNIQUE KEY uq_school_period (school_id, period_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_period_school ON class_periods(school_id);
CREATE INDEX IF NOT EXISTS idx_period_active ON class_periods(active);

CREATE TABLE IF NOT EXISTS teacher_class_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL,
  period_id INT NOT NULL,
  school_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_assignment_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_period FOREIGN KEY (period_id) REFERENCES class_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  UNIQUE KEY uq_teacher_weekday_period (teacher_id, weekday, period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_assignment_teacher ON teacher_class_assignments(teacher_id);
CREATE INDEX IF NOT EXISTS idx_assignment_period ON teacher_class_assignments(period_id);
CREATE INDEX IF NOT EXISTS idx_assignment_weekday ON teacher_class_assignments(weekday);

CREATE TABLE IF NOT EXISTS leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  paid TINYINT(1) NOT NULL DEFAULT 1,
  affects_bank TINYINT(1) NOT NULL DEFAULT 0,
  requires_attachment TINYINT(1) NOT NULL DEFAULT 0,
  description TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leaves (
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
  CONSTRAINT fk_leave_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_leave_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_leave_type FOREIGN KEY (type_id) REFERENCES leave_types(id),
  CONSTRAINT fk_leave_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_leaves_teacher ON leaves(teacher_id);
CREATE INDEX IF NOT EXISTS idx_leaves_type ON leaves(type_id);
CREATE INDEX IF NOT EXISTS idx_leaves_approved ON leaves(approved);
CREATE INDEX IF NOT EXISTS idx_leaves_dates ON leaves(start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_leaves_attachment ON leaves(attachment);

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

CREATE INDEX IF NOT EXISTS idx_attachment_log_leave ON leave_attachment_access_log(leave_id);
CREATE INDEX IF NOT EXISTS idx_attachment_log_admin ON leave_attachment_access_log(accessed_by_admin_id);

CREATE TABLE IF NOT EXISTS hour_bank_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  minutes INT NOT NULL,
  reason VARCHAR(150) NULL,
  source ENUM('auto','manual','overtime_approved') NOT NULL DEFAULT 'manual',
  ref_attendance_id INT NULL,
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hb_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_hb_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_hb_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_hour_bank_teacher_date ON hour_bank_entries(teacher_id, date);

CREATE TABLE IF NOT EXISTS overtime_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  minutes INT NOT NULL,
  expected_minutes INT NOT NULL DEFAULT 0,
  worked_minutes INT NOT NULL DEFAULT 0,
  justification TEXT NULL COMMENT 'Justificativa fornecida pelo professor',
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL,
  approved_at DATETIME NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ot_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_ot_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_ot_admin FOREIGN KEY (approved_by_admin_id) REFERENCES admins(id),
  UNIQUE KEY uq_attendance_overtime (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_overtime_teacher_date ON overtime_requests(teacher_id, date);
CREATE INDEX IF NOT EXISTS idx_overtime_status ON overtime_requests(status);

CREATE TABLE IF NOT EXISTS app_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  k VARCHAR(100) UNIQUE NOT NULL,
  v TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- PARTE 2: TABELA DE ATTENDANCE (PONTO) - COMPLETA
-- =====================================================================

CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  check_in DATETIME NULL,
  check_out DATETIME NULL,
  check_in_lat DOUBLE NULL,
  check_in_lng DOUBLE NULL,
  check_in_acc DOUBLE NULL,
  check_out_lat DOUBLE NULL,
  check_out_lng DOUBLE NULL,
  check_out_acc DOUBLE NULL,
  method VARCHAR(50) NULL DEFAULT 'pin',
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  photo VARCHAR(255) NULL,
  approved TINYINT(1) DEFAULT NULL,
  manual_reason_id INT NULL,
  manual_reason_text VARCHAR(255) NULL,
  editado_por INT NULL,
  data_edicao DATETIME NULL,
  motivo_edicao VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  -- Portaria MTP 671/2021
  nsr BIGINT UNSIGNED NULL UNIQUE,
  record_mode ENUM('online','offline') NOT NULL DEFAULT 'online',
  recorded_at DATETIME NULL,
  synced_at DATETIME NULL,
  hlb_sync_status ENUM('synced','failed','pending','legacy') NOT NULL DEFAULT 'pending',
  hlb_offset_seconds INT DEFAULT 0,
  device_identifier VARCHAR(255) NULL,
  receipt_generated TINYINT(1) DEFAULT 0,
  receipt_viewed_at TIMESTAMP NULL,
  -- Anti-Fraude
  fraud_risk_level TINYINT DEFAULT 0,
  gps_mock_detected TINYINT(1) DEFAULT 0,
  device_fingerprint VARCHAR(255) NULL,
  -- Motivos de Pendência
  pending_reasons TEXT NULL COMMENT 'Motivos JSON quando ponto fica pendente',
  -- Controle de Exclusão de Fotos
  photo_deleted TINYINT(1) DEFAULT 0 COMMENT 'Flag indicando se a foto foi deletada automaticamente',
  photo_deleted_at DATETIME NULL COMMENT 'Data/hora em que a foto foi deletada',
  -- Grade horária e múltiplos check-ins por dia
  class_period_id INT NULL COMMENT 'ID do período/aula (grade horária)',
  sequence_number INT NOT NULL DEFAULT 1 COMMENT 'Número sequencial do registro no dia',
  is_overtime_candidate TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Check-in fora da grade - candidato a hora extra',
  overtime_justification TEXT NULL COMMENT 'Justificativa do professor para hora extra',
  CONSTRAINT fk_att_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_att_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_att_manual_reason FOREIGN KEY (manual_reason_id) REFERENCES manual_reasons(id),
  CONSTRAINT fk_att_editor FOREIGN KEY (editado_por) REFERENCES admins(id),
  CONSTRAINT fk_att_period FOREIGN KEY (class_period_id) REFERENCES class_periods(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_att_teacher_date ON attendance(teacher_id, date);
CREATE INDEX IF NOT EXISTS idx_att_teacher_date_seq ON attendance(teacher_id, date, sequence_number);
CREATE INDEX IF NOT EXISTS idx_att_period ON attendance(class_period_id);
CREATE INDEX IF NOT EXISTS idx_att_overtime_candidate ON attendance(is_overtime_candidate, approved);
CREATE INDEX IF NOT EXISTS idx_att_date ON attendance(date);
CREATE INDEX IF NOT EXISTS idx_att_approved ON attendance(approved);
CREATE INDEX IF NOT EXISTS idx_att_nsr ON attendance(nsr);
CREATE INDEX IF NOT EXISTS idx_att_fraud_risk ON attendance(fraud_risk_level);
CREATE INDEX IF NOT EXISTS idx_att_device_fp ON attendance(device_fingerprint);
CREATE INDEX IF NOT EXISTS idx_att_photo_cleanup ON attendance(photo_deleted, date);

-- Constraint para attendance_id em overtime (adicionado após attendance)
ALTER TABLE overtime_requests ADD CONSTRAINT fk_ot_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE;
ALTER TABLE hour_bank_entries ADD CONSTRAINT fk_hb_att FOREIGN KEY (ref_attendance_id) REFERENCES attendance(id);

-- =====================================================================
-- PARTE 3: PORTARIA MTP 671/2021
-- =====================================================================

CREATE TABLE IF NOT EXISTS nsr_sequence (
  id INT PRIMARY KEY DEFAULT 1,
  current_nsr BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO nsr_sequence (id, current_nsr) VALUES (1, 0);

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

CREATE INDEX IF NOT EXISTS idx_audit_attendance ON attendance_audit_log(attendance_id);
CREATE INDEX IF NOT EXISTS idx_audit_admin ON attendance_audit_log(admin_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON attendance_audit_log(created_at);

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

INSERT IGNORE INTO employer_config (id, company_name, system_name, system_version, rep_category) 
VALUES (1, 'Sua Empresa LTDA', 'DEEDO Ponto', '1.0.0', 'REP-P');

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

CREATE INDEX IF NOT EXISTS idx_consent_teacher ON lgpd_consent(teacher_id);

-- ============================================================================
-- NOTA (auditoria de conformidade 2026-08-05) — trigger de NSR REMOVIDO daqui.
--
-- O trigger `attendance_before_insert_nsr` existia neste instalador mas NÃO
-- existe no banco de produção (a verificação encontrou zero triggers). Na
-- prática o NSR sempre foi emitido pelo PHP, com `SELECT current_nsr FROM
-- nsr_sequence WHERE id = 1 FOR UPDATE` dentro da transação do INSERT
-- (api/checkin.php, api/checkin_bulk.php, api/kiosk_checkin.php,
-- public/admin/attendance_manual.php e helpers.php).
--
-- Manter o trigger aqui é perigoso: quem reimportasse este arquivo passaria a
-- ter DUAS fontes incrementando `nsr_sequence` — o trigger e o PHP — gerando
-- saltos e duplicidades de NSR. E ele conflita frontalmente com o livro fiscal
-- append-only (`nsr_ledger`) previsto na Fase 1 de
-- docs/AUDITORIA_CONFORMIDADE_2026-08-05.md, que passa a ser a única autoridade
-- de numeração.
--
-- Se o trigger existir em algum ambiente, remova-o:
--     DROP TRIGGER IF EXISTS attendance_before_insert_nsr;
-- ============================================================================

DELIMITER //

DROP TRIGGER IF EXISTS attendance_update_audit//
CREATE TRIGGER attendance_update_audit
AFTER UPDATE ON attendance
FOR EACH ROW
BEGIN
  IF OLD.approved != NEW.approved OR OLD.check_in != NEW.check_in OR OLD.check_out != NEW.check_out THEN
    INSERT INTO attendance_audit_log (attendance_id, action, field_changed, old_value, new_value)
    VALUES (NEW.id, 'UPDATE', 'multiple', 'see_attendance', 'updated');
  END IF;
END//

DROP TRIGGER IF EXISTS attendance_delete_audit//
CREATE TRIGGER attendance_delete_audit
BEFORE DELETE ON attendance
FOR EACH ROW
BEGIN
  INSERT INTO attendance_audit_log (attendance_id, action, old_value)
  VALUES (OLD.id, 'DELETE', CONCAT('NSR:', OLD.nsr));
END//

DELIMITER ;

-- =====================================================================
-- PARTE 4: ANTI-FRAUDE
-- =====================================================================

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

CREATE INDEX IF NOT EXISTS idx_fraud_log_teacher ON fraud_detection_log(teacher_id);
CREATE INDEX IF NOT EXISTS idx_fraud_log_type ON fraud_detection_log(detection_type);
CREATE INDEX IF NOT EXISTS idx_fraud_log_risk ON fraud_detection_log(risk_level);
CREATE INDEX IF NOT EXISTS idx_fraud_log_created ON fraud_detection_log(created_at);

CREATE TABLE IF NOT EXISTS auth_attempt_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_type VARCHAR(32) NOT NULL,
  identifier VARCHAR(191) NOT NULL,
  teacher_id INT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  reason VARCHAR(100) NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_auth_attempt_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_auth_attempt_lookup ON auth_attempt_logs(attempt_type, identifier, success, created_at);
CREATE INDEX IF NOT EXISTS idx_auth_attempt_created ON auth_attempt_logs(created_at);

CREATE TABLE IF NOT EXISTS antifraud_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  config_key VARCHAR(100) NOT NULL UNIQUE,
  config_value TEXT NOT NULL,
  description TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO antifraud_config (config_key, config_value, description) VALUES
('max_distance_km_per_minute', '1', 'Distância máxima em km por minuto'),
('min_suspicious_accuracy', '5', 'Precisão GPS suspeita quando menor que X metros'),
('max_checkins_per_day', '10', 'Máximo de check-ins por dia'),
('block_on_mock_detection', '0', 'Bloquear se GPS mock detectado (0=não)'),
('fraud_alert_threshold', '3', 'Detecções em 24h para alertar');

CREATE OR REPLACE VIEW v_fraud_analysis AS
SELECT 
    t.id as teacher_id,
    t.name as teacher_name,
    COUNT(DISTINCT DATE(fdl.created_at)) as suspicious_days,
    COUNT(fdl.id) as total_detections,
    SUM(CASE WHEN fdl.detection_type = 'gps_mock' THEN 1 ELSE 0 END) as mock_count,
    SUM(CASE WHEN fdl.risk_level >= 2 THEN 1 ELSE 0 END) as high_risk_count,
    MAX(fdl.created_at) as last_detection
FROM teachers t
LEFT JOIN fraud_detection_log fdl ON fdl.teacher_id = t.id
WHERE fdl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY t.id, t.name
HAVING total_detections > 0
ORDER BY high_risk_count DESC, total_detections DESC;

-- =====================================================================
-- PARTE 5: CALENDÁRIO E EXCEÇÕES
-- =====================================================================

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
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_calendar_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_calendar_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  UNIQUE KEY uq_school_date_type (school_id, date, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_calendar_date ON calendar_exceptions(date);
CREATE INDEX IF NOT EXISTS idx_calendar_school ON calendar_exceptions(school_id);

CREATE TABLE IF NOT EXISTS academic_calendar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  year INT NOT NULL,
  semester TINYINT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  description TEXT NULL,
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_academic_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_academic_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  UNIQUE KEY uq_school_year_semester (school_id, year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_academic_school ON academic_calendar(school_id);
CREATE INDEX IF NOT EXISTS idx_academic_year ON academic_calendar(year);

CREATE TABLE IF NOT EXISTS mobile_holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  calculation_rule TEXT NULL,
  days_offset INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Feriados móveis padrão
INSERT IGNORE INTO mobile_holidays (name, calculation_rule, days_offset, is_active) VALUES
('Páscoa', 'Domingo de Páscoa (algoritmo de Computus)', 0, 1),
('Carnaval', '47 dias antes da Páscoa (terça-feira)', -47, 1),
('Sexta-feira Santa', '2 dias antes da Páscoa', -2, 1),
('Corpus Christi', '60 dias após a Páscoa (quinta-feira)', 60, 1);

-- Feriados nacionais fixos
INSERT IGNORE INTO calendar_exceptions (school_id, date, type, name, recurrence, is_working_day) VALUES
(NULL, '2025-01-01', 'holiday', 'Confraternização Universal', 'yearly', 0),
(NULL, '2025-04-21', 'holiday', 'Tiradentes', 'yearly', 0),
(NULL, '2025-05-01', 'holiday', 'Dia do Trabalho', 'yearly', 0),
(NULL, '2025-09-07', 'holiday', 'Independência do Brasil', 'yearly', 0),
(NULL, '2025-10-12', 'holiday', 'Nossa Senhora Aparecida', 'yearly', 0),
(NULL, '2025-11-02', 'holiday', 'Finados', 'yearly', 0),
(NULL, '2025-11-15', 'holiday', 'Proclamação da República', 'yearly', 0),
(NULL, '2025-11-20', 'holiday', 'Dia da Consciência Negra', 'yearly', 0),
(NULL, '2025-12-25', 'holiday', 'Natal', 'yearly', 0);

-- Funções e procedures para calendário
DELIMITER //

DROP FUNCTION IF EXISTS calculate_easter//
CREATE FUNCTION calculate_easter(year INT) RETURNS DATE
DETERMINISTIC
BEGIN
    DECLARE a, b, c, d, e, f, g, h, i, k, l, m, month, day INT;
    SET a = year % 19;
    SET b = FLOOR(year / 100);
    SET c = year % 100;
    SET d = FLOOR(b / 4);
    SET e = b % 4;
    SET f = FLOOR((b + 8) / 25);
    SET g = FLOOR((b - f + 1) / 3);
    SET h = (19 * a + b - d - g + 15) % 30;
    SET i = FLOOR(c / 4);
    SET k = c % 4;
    SET l = (32 + 2 * e + 2 * i - h - k) % 7;
    SET m = FLOOR((a + 11 * h + 22 * l) / 451);
    SET month = FLOOR((h + l - 7 * m + 114) / 31);
    SET day = ((h + l - 7 * m + 114) % 31) + 1;
    RETURN STR_TO_DATE(CONCAT(year, '-', LPAD(month, 2, '0'), '-', LPAD(day, 2, '0')), '%Y-%m-%d');
END//

DROP PROCEDURE IF EXISTS generate_mobile_holidays//
CREATE PROCEDURE generate_mobile_holidays(IN target_year INT)
BEGIN
    DECLARE easter_date DATE;
    DECLARE done INT DEFAULT 0;
    DECLARE holiday_name VARCHAR(100);
    DECLARE offset_days INT;
    DECLARE holiday_date DATE;
    DECLARE cur CURSOR FOR SELECT name, days_offset FROM mobile_holidays WHERE is_active = 1;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
    
    SET easter_date = calculate_easter(target_year);
    
    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO holiday_name, offset_days;
        IF done THEN LEAVE read_loop; END IF;
        SET holiday_date = DATE_ADD(easter_date, INTERVAL offset_days DAY);
        INSERT INTO calendar_exceptions (school_id, date, type, name, recurrence, is_working_day)
        VALUES (NULL, holiday_date, 'holiday', holiday_name, 'yearly', 0)
        ON DUPLICATE KEY UPDATE name = VALUES(name);
    END LOOP;
    CLOSE cur;
END//

DROP FUNCTION IF EXISTS is_working_day//
CREATE FUNCTION is_working_day(check_date DATE, check_school_id INT) RETURNS TINYINT(1)
DETERMINISTIC
BEGIN
    DECLARE exception_count INT;
    DECLARE is_workday INT;
    DECLARE day_of_week INT;
    SET day_of_week = DAYOFWEEK(check_date) - 1;
    SELECT COUNT(*), MAX(is_working_day) INTO exception_count, is_workday
    FROM calendar_exceptions WHERE date = check_date AND (school_id IS NULL OR school_id = check_school_id);
    IF exception_count > 0 THEN RETURN is_workday; END IF;
    IF day_of_week >= 1 AND day_of_week <= 5 THEN RETURN 1; ELSE RETURN 0; END IF;
END//

DELIMITER ;

-- Gera feriados móveis de 2025 e 2026
CALL generate_mobile_holidays(2025);
CALL generate_mobile_holidays(2026);

-- =====================================================================
-- PARTE 6: HOLERITES (FOLHA DE PAGAMENTO)
-- =====================================================================

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

CREATE INDEX IF NOT EXISTS idx_payslip_teacher ON payslips(teacher_id);
CREATE INDEX IF NOT EXISTS idx_payslip_month ON payslips(reference_month);

CREATE TABLE IF NOT EXISTS payslip_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payslip_id INT NOT NULL,
  type ENUM('earning','deduction') NOT NULL,
  description VARCHAR(150) NOT NULL,
  value DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payslip_item_payslip FOREIGN KEY (payslip_id) REFERENCES payslips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_payslip_item_payslip ON payslip_items(payslip_id);

-- Procedure para gerar holerite
DELIMITER //

DROP PROCEDURE IF EXISTS generate_payslip//
CREATE PROCEDURE generate_payslip(IN p_teacher_id INT, IN p_month DATE, IN p_admin_id INT)
BEGIN
    DECLARE v_base_salary DECIMAL(10,2);
    DECLARE v_worked_minutes INT DEFAULT 0;
    DECLARE v_expected_minutes INT DEFAULT 0;
    DECLARE v_overtime_minutes INT DEFAULT 0;
    DECLARE v_deficit_minutes INT DEFAULT 0;
    DECLARE v_overtime_value DECIMAL(10,2) DEFAULT 0;
    DECLARE v_discount_value DECIMAL(10,2) DEFAULT 0;
    DECLARE v_gross_total DECIMAL(10,2);
    DECLARE v_net_total DECIMAL(10,2);
    DECLARE v_minute_value DECIMAL(10,4);
    
    SELECT base_salary INTO v_base_salary FROM teachers WHERE id = p_teacher_id;
    IF v_base_salary IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Colaborador não encontrado'; END IF;
    
    -- A instituição NÃO paga hora extra nem desconta déficit automaticamente.
    -- Holerite = salário base; extras/déficit zerados; horas informativas não calculadas aqui.
    SET v_worked_minutes = 0;
    SET v_expected_minutes = 0;
    SET v_overtime_minutes = 0;
    SET v_deficit_minutes = 0;
    SET v_minute_value = 0;
    SET v_overtime_value = 0;
    SET v_discount_value = 0;
    SET v_gross_total = v_base_salary;
    SET v_net_total = v_base_salary;
    
    INSERT INTO payslips (teacher_id, reference_month, base_salary, worked_minutes, expected_minutes, overtime_minutes,
        overtime_value, deficit_minutes, discount_value, gross_total, net_total, generated_by_admin_id)
    VALUES (p_teacher_id, p_month, v_base_salary, v_worked_minutes, v_expected_minutes, v_overtime_minutes,
        v_overtime_value, v_deficit_minutes, v_discount_value, v_gross_total, v_net_total, p_admin_id)
    ON DUPLICATE KEY UPDATE base_salary = VALUES(base_salary), generated_at = CURRENT_TIMESTAMP;
END//

DELIMITER ;

-- =====================================================================
-- PARTE 7: DADOS INICIAIS
-- =====================================================================

-- Tipos de afastamento padrão
INSERT IGNORE INTO leave_types (name, code, paid, affects_bank, requires_attachment, description) VALUES
('Atestado Médico', 'ATESTADO', 1, 0, 1, 'Afastamento por motivo de saúde com atestado'),
('Licença Médica', 'LICENCA_MEDICA', 1, 0, 1, 'Licença para tratamento superior a 15 dias'),
('Férias', 'FERIAS', 1, 0, 0, 'Período de férias regulamentares'),
('Abono', 'ABONO', 1, 0, 0, 'Abono de falta por decisão da gestão'),
('Licença Maternidade', 'LICENCA_MATERNIDADE', 1, 0, 1, 'Licença maternidade de 120 dias'),
('Licença Paternidade', 'LICENCA_PATERNIDADE', 1, 0, 1, 'Licença paternidade de 5 a 20 dias'),
('Falta Justificada', 'FALTA_JUSTIFICADA', 0, 1, 1, 'Falta com justificativa mas sem remuneração'),
('Suspensão', 'SUSPENSAO', 0, 1, 0, 'Suspensão disciplinar sem remuneração'),
('Licença Sem Vencimentos', 'LICENCA_SEM_VENC', 0, 1, 0, 'Licença não remunerada');

-- Tipo de colaborador padrão
INSERT IGNORE INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES
('Professor', 'professor', 'classes', 1),
('Coordenador', 'coordenador', 'time', 1),
('Administrativo', 'administrativo', 'time', 1);

-- Grade horária padrão (escola = NULL significa global)
INSERT IGNORE INTO class_periods (id, school_id, period_number, start_time, end_time, active) VALUES
(1, NULL, 1, '07:00:00', '07:50:00', 1),
(2, NULL, 2, '07:50:00', '08:40:00', 1),
(3, NULL, 3, '08:40:00', '09:30:00', 1),
(4, NULL, 4, '09:50:00', '10:40:00', 1),
(5, NULL, 5, '10:40:00', '11:30:00', 1),
(6, NULL, 6, '11:30:00', '12:20:00', 1),
(7, NULL, 7, '13:30:00', '14:20:00', 1),
(8, NULL, 8, '14:20:00', '15:10:00', 1),
(9, NULL, 9, '15:10:00', '16:00:00', 1),
(10, NULL, 10, '16:20:00', '17:10:00', 1);

-- Settings padrão
INSERT IGNORE INTO app_settings (k, v) VALUES
('geofence_radius_m', '300'),
('tolerance_minutes', '5'),
('auto_approve_with_photo_geo', '1'),
('overtime_tolerance_minutes', '30'),
('overtime_requires_justification', '1'),
('overtime_auto_approve', '0'),
('overtime_multiplier', '1.5'),
('overtime_max_daily_hours', '4');

-- Admin padrão (senha: admin123 - ALTERAR!)
-- Hash gerado e testado localmente: password_hash('admin123', PASSWORD_BCRYPT)
INSERT IGNORE INTO admins (username, cpf, password_hash, role) VALUES
('admin', '00000000191', '$2y$10$Yf3IrInDQp7patjgPNmWCuNHp7CjXNGTVYS2Y6TuMcngP3/vLQiiq', 'network_admin');

-- =====================================================================
-- PARTE 8: VIEWS AUXILIARES
-- =====================================================================

CREATE OR REPLACE VIEW v_attendance_receipts AS
SELECT 
    a.id, a.nsr, a.teacher_id, t.name as teacher_name, t.cpf as teacher_cpf,
    a.date, a.check_in, a.check_out,
    CASE WHEN a.check_out IS NOT NULL THEN 'saida' ELSE 'entrada' END as action,
    a.record_mode, a.recorded_at, a.synced_at, a.check_in_lat as latitude, a.check_in_lng as longitude,
    a.photo, a.approved, a.hlb_sync_status, a.device_identifier,
    a.receipt_generated, a.receipt_viewed_at,
    e.company_name, e.cnpj, e.system_name, e.system_version, e.rep_category
FROM attendance a
INNER JOIN teachers t ON a.teacher_id = t.id
CROSS JOIN employer_config e
ORDER BY a.nsr DESC;

CREATE OR REPLACE VIEW v_calendar_full AS
SELECT 
    ce.id, ce.date, ce.type, ce.name, ce.description, ce.is_working_day, ce.recurrence,
    ce.school_id, s.name as school_name,
    CASE WHEN ce.school_id IS NULL THEN 'Toda a rede' ELSE s.name END as scope,
    DAYNAME(ce.date) as day_of_week,
    DATE_FORMAT(ce.date, '%d/%m/%Y') as date_formatted
FROM calendar_exceptions ce
LEFT JOIN schools s ON s.id = ce.school_id
ORDER BY ce.date DESC;

CREATE OR REPLACE VIEW v_payslips_full AS
SELECT 
    p.id, p.reference_month, DATE_FORMAT(p.reference_month, '%m/%Y') as month_formatted,
    YEAR(p.reference_month) as year,
    p.teacher_id, t.name as teacher_name, t.cpf as teacher_cpf,
    p.base_salary, p.worked_minutes, p.expected_minutes, p.overtime_minutes, p.overtime_value,
    p.deficit_minutes, p.discount_value, p.gross_total, p.net_total,
    p.generated_at, p.viewed_by_teacher_at,
    CASE WHEN p.viewed_by_teacher_at IS NOT NULL THEN 1 ELSE 0 END as was_viewed
FROM payslips p
INNER JOIN teachers t ON t.id = p.teacher_id
ORDER BY p.reference_month DESC, t.name ASC;

-- =====================================================================
-- PARTE 9: ATUALIZAÇÃO DE DADOS EXISTENTES (se aplicável)
-- =====================================================================

-- Popula campos Portaria 671 em registros existentes (se houver)
UPDATE attendance 
SET recorded_at = COALESCE(check_in, check_out),
    synced_at = COALESCE(check_in, check_out),
    record_mode = 'online',
    hlb_sync_status = 'legacy'
WHERE recorded_at IS NULL AND (check_in IS NOT NULL OR check_out IS NOT NULL);

-- Popula days_count em leaves existentes (se houver)
UPDATE leaves 
SET days_count = GREATEST(1, DATEDIFF(end_date, start_date) + 1)
WHERE days_count = 0 OR days_count IS NULL;

-- =====================================================================
-- PARTE 10: PERMISSÕES E FINALIZAÇÕES
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SUMÁRIO DA INSTALAÇÃO
-- =====================================================================
-- Tabelas criadas: 28
-- - Core: 8 (schools, admins, teachers, types, schedules)
-- - Ponto: 3 (attendance, audit_log, nsr_sequence)
-- - Afastamentos: 3 (leave_types, leaves, access_log)
-- - Horas: 2 (hour_bank, overtime)
-- - Calendário: 3 (exceptions, academic, mobile_holidays)
-- - Folha: 2 (payslips, payslip_items)
-- - Segurança: 4 (lgpd_consent, fraud_log, antifraud_config, app_settings)
-- - Config: 1 (employer_config)
--
-- Views: 3 (v_attendance_receipts, v_calendar_full, v_payslips_full, v_fraud_analysis)
-- Procedures: 2 (generate_mobile_holidays, generate_payslip)
-- Functions: 2 (calculate_easter, is_working_day)
-- Triggers: 3 (nsr auto, audit update, audit delete)
--
-- PRÓXIMOS PASSOS:
-- 1. Ajuste employer_config com dados da empresa
-- 2. Altere senha do admin padrão
-- 3. Cadastre instituições e colaboradores
-- 4. Configure permissões de diretórios (chmod 755)
-- 5. Teste sistema completo
-- =====================================================================

SELECT '✓ Instalação completa executada com sucesso!' AS status,
       'Total de tabelas: 28 | Views: 4 | Procedures: 2 | Functions: 2 | Triggers: 3' AS info,
       'Sistema DEEDO Ponto v1.0.0 pronto para uso!' AS message;


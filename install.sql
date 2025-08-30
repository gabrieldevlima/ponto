-- SQL Schema (MySQL 5.7+/8.0+ or MariaDB 10.3+) for the Attendance/HR system
-- Character set/collation: utf8mb4 recommended

-- Optional: choose your database
-- CREATE DATABASE IF NOT EXISTS your_database_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE your_database_name;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================
-- Core entities
-- =========================================

-- Schools
CREATE TABLE IF NOT EXISTS schools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  lat DECIMAL(10,7) NULL COMMENT 'Latitude for geolocation validation',
  lng DECIMAL(10,7) NULL COMMENT 'Longitude for geolocation validation',
  radius_m INT DEFAULT 300 COMMENT 'Radius in meters for geolocation validation',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin users
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('network_admin','school_admin') NOT NULL DEFAULT 'network_admin',
  school_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_admin_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_admin_role ON admins(role);
CREATE INDEX idx_admin_school ON admins(school_id);

-- Collaborator (employee) types
CREATE TABLE IF NOT EXISTS collaborator_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(50) NOT NULL UNIQUE,
  schedule_mode ENUM('none','classes','time') NOT NULL DEFAULT 'classes',
  requires_schedule TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manual reasons for attendance adjustments
CREATE TABLE IF NOT EXISTS manual_reasons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Collaborators (kept as "teachers" for compatibility)
CREATE TABLE IF NOT EXISTS teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  cpf VARCHAR(14) NOT NULL UNIQUE,
  pin_hash VARCHAR(255) NOT NULL,
  email VARCHAR(120),
  active TINYINT(1) NOT NULL DEFAULT 1,
  type_id INT NULL,
  base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  network_wide TINYINT(1) NOT NULL DEFAULT 0, -- acts on whole network
  face_descriptors JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_teachers_type FOREIGN KEY (type_id) REFERENCES collaborator_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_teachers_type ON teachers(type_id);
CREATE INDEX idx_teachers_active ON teachers(active);
CREATE INDEX idx_teachers_name ON teachers(name);

-- N:N relation between teachers and schools
CREATE TABLE IF NOT EXISTS teacher_schools (
  teacher_id INT NOT NULL,
  school_id INT NOT NULL,
  PRIMARY KEY (teacher_id, school_id),
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_ts_teacher ON teacher_schools(teacher_id);
CREATE INDEX idx_ts_school ON teacher_schools(school_id);

-- Weekly schedule by number of classes (for schedule_mode='classes')
CREATE TABLE IF NOT EXISTS teacher_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL, -- 0=Sun,1=Mon,...,6=Sat
  classes_count INT NOT NULL DEFAULT 0,
  class_minutes INT NOT NULL DEFAULT 60,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_teacher_weekday (teacher_id, weekday),
  CONSTRAINT fk_schedule_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weekly schedule by time (for schedule_mode='time')
CREATE TABLE IF NOT EXISTS collaborator_time_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  break_minutes INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_time_teacher_weekday (teacher_id, weekday),
  CONSTRAINT fk_time_schedule_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance records
CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  check_in DATETIME DEFAULT NULL,
  check_out DATETIME DEFAULT NULL,
  method VARCHAR(20) DEFAULT NULL,                -- pin, manual, etc.
  ip VARCHAR(50) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  check_in_lat DOUBLE DEFAULT NULL,
  check_in_lng DOUBLE DEFAULT NULL,
  check_in_acc DOUBLE DEFAULT NULL,
  check_out_lat DOUBLE DEFAULT NULL,
  check_out_lng DOUBLE DEFAULT NULL,
  check_out_acc DOUBLE DEFAULT NULL,
  photo VARCHAR(255) DEFAULT NULL,
  additional_photos_count INT DEFAULT 0 COMMENT 'Number of additional photos beyond the main one',
  face_score DECIMAL(5,3) NULL COMMENT 'Best face recognition score from multiple descriptors',
  validation_notes JSON NULL COMMENT 'Validation details including face and geo checks',
  approved TINYINT(1) DEFAULT NULL,               -- null=pending, 1=approved, 0=rejected
  manual_reason_id INT NULL,
  manual_reason_text VARCHAR(255) NULL,
  manual_by_admin_id INT NULL,
  manual_created_at DATETIME NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_att_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_att_manual_reason FOREIGN KEY (manual_reason_id) REFERENCES manual_reasons(id),
  CONSTRAINT fk_att_manual_admin FOREIGN KEY (manual_by_admin_id) REFERENCES admins(id),
  CONSTRAINT fk_att_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_attendance_teacher_date ON attendance(teacher_id, date);
CREATE INDEX idx_attendance_manual ON attendance(manual_reason_id, manual_by_admin_id);
CREATE INDEX idx_attendance_school ON attendance(school_id);
CREATE INDEX idx_attendance_approved ON attendance(approved);
CREATE INDEX idx_attendance_date ON attendance(date);

-- Additional photos for attendance records (multiple photos per check-in)
CREATE TABLE IF NOT EXISTS attendance_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  sha256 VARCHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_att_photos_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_attendance_photos_attendance ON attendance_photos(attendance_id);
CREATE INDEX idx_attendance_photos_sha256 ON attendance_photos(sha256);

-- =========================================
-- Leaves / Absences
-- =========================================

-- Leave types (absences, licenses, vacations, etc.)
CREATE TABLE IF NOT EXISTS leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,         -- e.g., ABONO, AFAST, FERIAS, LICENCA
  paid TINYINT(1) NOT NULL DEFAULT 1,       -- remunerated?
  affects_bank TINYINT(1) NOT NULL DEFAULT 0, -- affects hour bank?
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leaves
CREATE TABLE IF NOT EXISTS leaves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  type_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  notes VARCHAR(255) NULL,
  approved TINYINT(1) DEFAULT NULL, -- null=pending, 1=approved, 0=rejected
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_leave_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_leave_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_leave_type FOREIGN KEY (type_id) REFERENCES leave_types(id),
  CONSTRAINT fk_leave_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_leaves_teacher ON leaves(teacher_id);
CREATE INDEX idx_leaves_type ON leaves(type_id);
CREATE INDEX idx_leaves_approved ON leaves(approved);
CREATE INDEX idx_leaves_dates ON leaves(start_date, end_date);

-- =========================================
-- Hour bank and audit
-- =========================================

-- Hour bank entries
CREATE TABLE IF NOT EXISTS hour_bank_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  minutes INT NOT NULL, -- +credit, -debit
  reason VARCHAR(150) NULL,
  source ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  ref_attendance_id INT NULL,
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hb_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_hb_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_hb_att FOREIGN KEY (ref_attendance_id) REFERENCES attendance(id),
  CONSTRAINT fk_hb_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_hour_bank_teacher_date ON hour_bank_entries(teacher_id, date);
CREATE INDEX idx_hour_bank_source ON hour_bank_entries(source);

-- Audit logs
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  action VARCHAR(80) NOT NULL,       -- create, update, delete, login, logout
  entity VARCHAR(80) NOT NULL,       -- teacher, attendance, school, leave, admin, etc.
  entity_id VARCHAR(64) NULL,
  payload JSON NULL,
  ip VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_audit_entity ON audit_logs(entity, entity_id);
CREATE INDEX idx_audit_admin ON audit_logs(admin_id);
CREATE INDEX idx_audit_created ON audit_logs(created_at);

-- =========================================
-- Settings and permissions
-- =========================================

-- Fine-grained permissions (optional baseline)
CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('network_admin','school_admin') NOT NULL,
  perm_key VARCHAR(120) NOT NULL,   -- e.g., 'leaves.manage', 'exports.xlsx'
  allow TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_role_perm (role, perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- App settings / key-value store
CREATE TABLE IF NOT EXISTS app_settings (
  k VARCHAR(100) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================
-- Seeds / defaults
-- =========================================

-- Collaborator types (idempotent)
INSERT IGNORE INTO collaborator_types (id, name, slug, schedule_mode, requires_schedule) VALUES
  (1, 'Professor', 'teacher', 'classes', 1),
  (2, 'Diretor', 'director', 'time', 1),
  (3, 'Secretário', 'secretary', 'time', 1),
  (4, 'Motorista', 'driver', 'time', 1),
  (5, 'Coordenador', 'coordinator', 'time', 1),
  (6, 'Administrativo', 'administrative', 'time', 1),
  (7, 'Auxiliar', 'assistant', 'time', 1);

-- Manual reasons (idempotent)
INSERT IGNORE INTO manual_reasons (id, name, active, sort_order) VALUES
  (1, 'Falta de internet', 1, 10),
  (2, 'Falha no sistema', 1, 20),
  (3, 'Esquecimento do colaborador', 1, 30),
  (4, 'Outro', 1, 100);

-- Default app settings (idempotent)
INSERT IGNORE INTO app_settings (k, v) VALUES
  ('tolerance_minutes', '5');

-- Common leave types (optional baseline; idempotent)
INSERT IGNORE INTO leave_types (id, name, code, paid, affects_bank, active) VALUES
  (1, 'Abono', 'ABONO', 1, 0, 1),
  (2, 'Afastamento', 'AFAST', 0, 1, 1),
  (3, 'Férias', 'FERIAS', 1, 0, 1),
  (4, 'Licença', 'LICENCA', 1, 0, 1);

-- Note: Default admin creation (username 'admin' with a password) should be handled by application logic
-- to ensure hashing (see ensure_default_admin in the app). This script intentionally does not insert a plaintext password.

-- EOF
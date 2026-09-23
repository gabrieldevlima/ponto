-- Harden auth + align attendance schema with application usage

ALTER TABLE attendance
  ADD COLUMN IF NOT EXISTS nsr BIGINT UNSIGNED NULL UNIQUE,
  ADD COLUMN IF NOT EXISTS record_mode ENUM('online','offline') NOT NULL DEFAULT 'online',
  ADD COLUMN IF NOT EXISTS recorded_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS synced_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS hlb_sync_status ENUM('synced','failed','pending','legacy') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS hlb_offset_seconds INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS device_identifier VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS receipt_generated TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS receipt_viewed_at TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS fraud_risk_level TINYINT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS gps_mock_detected TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS device_fingerprint VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS pending_reasons TEXT NULL,
  ADD COLUMN IF NOT EXISTS class_period_id INT NULL,
  ADD COLUMN IF NOT EXISTS sequence_number INT NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS is_overtime_candidate TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS overtime_justification TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_attendance_nsr ON attendance(nsr);
CREATE INDEX IF NOT EXISTS idx_attendance_teacher_date_seq ON attendance(teacher_id, date, sequence_number);
CREATE INDEX IF NOT EXISTS idx_attendance_period ON attendance(class_period_id);
CREATE INDEX IF NOT EXISTS idx_attendance_overtime_candidate ON attendance(is_overtime_candidate, approved);
CREATE INDEX IF NOT EXISTS idx_attendance_fraud_risk ON attendance(fraud_risk_level);
CREATE INDEX IF NOT EXISTS idx_attendance_device_fp ON attendance(device_fingerprint);

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

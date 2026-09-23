-- Reintroduz PIN como método padrão de registro de ponto, com segurança adaptativa.
-- Adiciona tabela teacher_trusted_devices para rastrear dispositivos confiáveis.
-- Mantém compatibilidade com face/CPF existentes; method já é VARCHAR, aceita 'pin'.

ALTER TABLE teachers
  ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) NULL AFTER cpf,
  ADD COLUMN IF NOT EXISTS pin_changed_at DATETIME NULL AFTER pin_hash,
  ADD COLUMN IF NOT EXISTS pin_self_enroll_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER pin_changed_at;

CREATE TABLE IF NOT EXISTS teacher_trusted_devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id INT NOT NULL,
  device_fingerprint CHAR(64) NOT NULL,
  label VARCHAR(100) NULL,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  enrollment_method ENUM('face_validated','repeated_use','admin') NOT NULL DEFAULT 'repeated_use',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_teacher_fp (teacher_id, device_fingerprint),
  KEY idx_teacher_active (teacher_id, is_active),
  KEY idx_last_used (last_used_at),
  CONSTRAINT fk_ttd_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (k, v) VALUES
  ('pin_length', '6'),
  ('pin_max_failures_window', '10'),
  ('pin_rate_limit_window_sec', '300'),
  ('pin_max_attempts_30min', '20'),
  ('trusted_device_inactive_days', '90'),
  ('trusted_device_max_per_teacher', '5'),
  ('trusted_device_repeat_threshold', '3'),
  ('trusted_device_repeat_window_days', '7'),
  ('adaptive_time_check', '0'),
  ('pin_stepup_after_reset_hours', '24');

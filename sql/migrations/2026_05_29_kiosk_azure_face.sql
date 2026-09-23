-- ============================================================================
-- QUIOSQUE DE PONTO COM RECONHECIMENTO FACIAL (Azure Face API)
-- ============================================================================
-- Adiciona o suporte de banco para o modo Quiosque:
--   1) Vinculo do colaborador (teachers) com a pessoa cadastrada no Azure Face
--      PersonGroup (azure_person_id) + metadados de cadastro.
--   2) Colunas em attendance para rastrear pontos batidos via quiosque.
--   3) Tabela de dispositivos autorizados (kiosk_devices).
--   4) Tabela de auditoria de reconhecimento facial (kiosk_face_logs).
--   5) Configuracoes padrao em app_settings.
--
-- Idempotencia: o runner (run_auto_migrations em helpers.php) roda o arquivo
-- como multi-statement e, se reexecutado, faz fallback statement-a-statement
-- tolerando 1050/1060/1061/1062/1068/1091/1146. Por isso ADD COLUMN / ADD INDEX
-- simples + CREATE TABLE IF NOT EXISTS + INSERT IGNORE, sem clausulas AFTER,
-- sem stored procedures, sem comentarios inline (apenas linhas de comentario
-- inteiras, que o runner remove antes de executar).
--
-- Permissoes: NAO inserimos em `permissions` de proposito. Neste banco a tabela
-- usa modelo por-admin (admin_id, permission_key) e has_permission() faz
-- bypass para network_admin e fail-open para os demais. O gate das telas usa
-- has_permission('kiosk.manage'), consistente com o resto do app.
--
-- Tabelas de log NAO usam FOREIGN KEY: mantem o log mesmo se o colaborador for
-- desativado e evita falha de migracao por incompatibilidade entre ambientes.
-- ============================================================================

-- 1) teachers: vinculo com Azure Face PersonGroup
ALTER TABLE teachers ADD COLUMN azure_person_id VARCHAR(64) NULL;
ALTER TABLE teachers ADD COLUMN azure_persisted_face_ids JSON NULL;
ALTER TABLE teachers ADD COLUMN azure_enrollment_status ENUM('none','pending','enrolled','error') NOT NULL DEFAULT 'none';
ALTER TABLE teachers ADD COLUMN azure_enrolled_at DATETIME NULL;
ALTER TABLE teachers ADD COLUMN azure_enrollment_error VARCHAR(255) NULL;
ALTER TABLE teachers ADD COLUMN azure_enrolled_by_admin_id INT NULL;
ALTER TABLE teachers ADD UNIQUE INDEX uq_teachers_azure_person (azure_person_id);

-- 2) attendance: rastreamento de pontos batidos via quiosque facial
ALTER TABLE attendance ADD COLUMN face_match_confidence DECIMAL(5,4) NULL;
ALTER TABLE attendance ADD COLUMN kiosk_device_id INT NULL;
ALTER TABLE attendance ADD COLUMN kiosk_face_log_id INT NULL;
ALTER TABLE attendance ADD INDEX idx_att_kiosk_device (kiosk_device_id);

-- 3) kiosk_devices: dispositivos (totens/tablets) autorizados
-- device_token_hash = sha256 do token (o token cru nunca e persistido)
-- fallback_allowed   = permite fallback CPF+PIN se a Azure estiver indisponivel
CREATE TABLE IF NOT EXISTS kiosk_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_token_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  school_id INT NULL,
  location_label VARCHAR(160) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  fallback_allowed TINYINT(1) NOT NULL DEFAULT 0,
  last_seen_at DATETIME NULL,
  last_ip VARCHAR(45) NULL,
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kiosk_device_token (device_token_hash),
  KEY idx_kiosk_device_active (active),
  KEY idx_kiosk_device_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) kiosk_face_logs: auditoria de TODA tentativa de reconhecimento facial
-- event_type: identify | checkin | enroll | reenroll | fallback
-- status:     recognized | no_face | multiple_faces | low_quality |
--             low_confidence | not_recognized | registered | api_unavailable |
--             api_error | duplicate | rejected | ...
CREATE TABLE IF NOT EXISTS kiosk_face_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NULL,
  teacher_id INT NULL,
  event_type ENUM('identify','checkin','enroll','reenroll','fallback') NOT NULL,
  status VARCHAR(40) NOT NULL,
  confidence DECIMAL(5,4) NULL,
  action_attempted VARCHAR(24) NULL,
  attendance_id INT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  photo VARCHAR(255) NULL,
  azure_request_id VARCHAR(64) NULL,
  detail JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kioskfl_device (device_id),
  KEY idx_kioskfl_teacher (teacher_id),
  KEY idx_kioskfl_status (status),
  KEY idx_kioskfl_event (event_type),
  KEY idx_kioskfl_created (created_at),
  KEY idx_kioskfl_attendance (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Configuracoes padrao (INSERT IGNORE: nao sobrescreve ajustes do admin)
-- kiosk_enabled            modo quiosque ligado/desligado
-- kiosk_min_confidence     confianca minima Azure para aceitar (0..1)
-- kiosk_fallback_enabled   habilita fallback CPF+PIN global (tambem por-device)
-- kiosk_dedup_window_seconds  janela anti-duplo-toque
-- kiosk_max_checkins_10min rate-limit por dispositivo
-- azure_face_driver        mock | live
-- kiosk_mock_*             parametros usados apenas pelo driver mock (sem chaves)
INSERT IGNORE INTO app_settings (k, v) VALUES
  ('kiosk_enabled', '0'),
  ('kiosk_min_confidence', '0.6'),
  ('kiosk_fallback_enabled', '0'),
  ('kiosk_dedup_window_seconds', '120'),
  ('kiosk_max_checkins_10min', '30'),
  ('azure_face_driver', 'mock'),
  ('azure_face_person_group_id', 'deedo_ponto'),
  ('azure_face_detection_model', 'detection_03'),
  ('azure_face_recognition_model', 'recognition_04'),
  ('kiosk_mock_force', 'none'),
  ('kiosk_mock_identify_teacher_id', '0'),
  ('kiosk_mock_confidence', '0.92');

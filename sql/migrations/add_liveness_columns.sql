-- Migração: Adicionar colunas de liveness detection na tabela attendance
-- Armazena score e dados brutos da verificação de vivacidade

ALTER TABLE attendance
  ADD COLUMN liveness_score FLOAT NULL DEFAULT NULL AFTER fraud_risk_level,
  ADD COLUMN liveness_data JSON NULL DEFAULT NULL AFTER liveness_score;

-- Índice para consultas de auditoria por score de liveness
ALTER TABLE attendance ADD INDEX idx_attendance_liveness (liveness_score);

-- Configurações padrão de liveness em app_settings
INSERT INTO app_settings (`k`, `v`) VALUES
    ('liveness_enabled', '1'),
    ('liveness_min_movement', '0.002'),
    ('liveness_min_frames', '6'),
    ('liveness_challenge_timeout_s', '5')
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);

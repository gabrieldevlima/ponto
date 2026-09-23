-- ========================================================
-- SISTEMA ANTI-FRAUDE DE LOCALIZAÇÃO
-- ========================================================
-- Adiciona campos e tabelas para detecção de fraudes em GPS
-- Validação básica: mock apps, timestamps, distâncias impossíveis
-- ========================================================

USE ponto;

-- Adiciona campos anti-fraude na tabela attendance
ALTER TABLE attendance 
  ADD COLUMN IF NOT EXISTS fraud_risk_level TINYINT DEFAULT 0 COMMENT '0=baixo, 1=médio, 2=alto',
  ADD COLUMN IF NOT EXISTS gps_mock_detected TINYINT(1) DEFAULT 0 COMMENT 'GPS falso detectado',
  ADD COLUMN IF NOT EXISTS device_fingerprint VARCHAR(255) NULL COMMENT 'Hash do dispositivo';

-- Índices para queries de fraude
CREATE INDEX IF NOT EXISTS idx_attendance_fraud_risk ON attendance(fraud_risk_level);
CREATE INDEX IF NOT EXISTS idx_attendance_gps_mock ON attendance(gps_mock_detected);
CREATE INDEX IF NOT EXISTS idx_attendance_device_fp ON attendance(device_fingerprint);

-- Tabela de log de detecções de fraude
CREATE TABLE IF NOT EXISTS fraud_detection_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NULL COMMENT 'ID do registro de ponto (se houver)',
  teacher_id INT NOT NULL COMMENT 'ID do colaborador',
  detection_type VARCHAR(50) NOT NULL COMMENT 'Tipo: gps_mock, impossible_distance, timestamp_mismatch, accuracy_suspicious',
  risk_level TINYINT NOT NULL DEFAULT 1 COMMENT '0=info, 1=baixo, 2=médio, 3=alto',
  details JSON NULL COMMENT 'Detalhes da detecção em JSON',
  ip_address VARCHAR(45) NULL COMMENT 'IP de origem',
  user_agent TEXT NULL COMMENT 'User agent',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fraud_log_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
  CONSTRAINT fk_fraud_log_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Log de tentativas suspeitas de fraude';

CREATE INDEX idx_fraud_log_teacher ON fraud_detection_log(teacher_id);
CREATE INDEX idx_fraud_log_type ON fraud_detection_log(detection_type);
CREATE INDEX idx_fraud_log_risk ON fraud_detection_log(risk_level);
CREATE INDEX idx_fraud_log_created ON fraud_detection_log(created_at);

-- View para análise de fraudes
CREATE OR REPLACE VIEW v_fraud_analysis AS
SELECT 
    t.id as teacher_id,
    t.name as teacher_name,
    COUNT(DISTINCT DATE(fdl.created_at)) as suspicious_days,
    COUNT(fdl.id) as total_detections,
    SUM(CASE WHEN fdl.detection_type = 'gps_mock' THEN 1 ELSE 0 END) as mock_count,
    SUM(CASE WHEN fdl.detection_type = 'impossible_distance' THEN 1 ELSE 0 END) as distance_count,
    SUM(CASE WHEN fdl.detection_type = 'timestamp_mismatch' THEN 1 ELSE 0 END) as timestamp_count,
    SUM(CASE WHEN fdl.risk_level >= 2 THEN 1 ELSE 0 END) as high_risk_count,
    MAX(fdl.created_at) as last_detection,
    GROUP_CONCAT(DISTINCT fdl.device_fingerprint) as devices_used
FROM teachers t
LEFT JOIN fraud_detection_log fdl ON fdl.teacher_id = t.id
WHERE fdl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY t.id, t.name
HAVING total_detections > 0
ORDER BY high_risk_count DESC, total_detections DESC;

-- Configurações anti-fraude
CREATE TABLE IF NOT EXISTS antifraud_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  config_key VARCHAR(100) NOT NULL UNIQUE,
  config_value TEXT NOT NULL,
  description TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações padrão
INSERT INTO antifraud_config (config_key, config_value, description) VALUES
('max_distance_km_per_minute', '1', 'Distância máxima em km que alguém pode percorrer por minuto (60km/h = 1km/min)'),
('min_suspicious_accuracy', '5', 'Precisão GPS menor que X metros é suspeita quando constante'),
('max_checkins_per_day', '10', 'Número máximo de check-ins por dia antes de marcar como suspeito'),
('block_on_mock_detection', '0', 'Bloquear registro se GPS mock for detectado (0=não, 1=sim)'),
('fraud_alert_threshold', '3', 'Número de detecções em 24h para alertar admin')
ON DUPLICATE KEY UPDATE config_value=VALUES(config_value);

-- ========================================================
-- SUMÁRIO DA MIGRAÇÃO
-- ========================================================
-- 1. Campos adicionados à tabela 'attendance':
--    - fraud_risk_level (nível de risco)
--    - gps_mock_detected (flag de GPS falso)
--    - device_fingerprint (identificação do dispositivo)
--
-- 2. Tabela 'fraud_detection_log':
--    - Registro de todas as tentativas suspeitas
--    - Detalhes em JSON para análise posterior
--
-- 3. View 'v_fraud_analysis':
--    - Análise agregada por colaborador
--    - Últimos 30 dias de detecções
--
-- 4. Tabela 'antifraud_config':
--    - Configurações ajustáveis do sistema
--    - Thresholds e parâmetros
-- ========================================================

SELECT '✓ Sistema anti-fraude instalado com sucesso!' AS status,
       'Campos adicionados: fraud_risk_level, gps_mock_detected, device_fingerprint' AS info,
       'Consulte v_fraud_analysis para relatórios de fraude' AS tip;


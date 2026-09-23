-- Migração: Endurecer thresholds de reconhecimento facial
-- Reduz falsos positivos e aumenta segurança do matching

UPDATE app_settings SET v = '0.32' WHERE k = 'face_checkin_threshold';
UPDATE app_settings SET v = '0.18' WHERE k = 'face_checkin_margin';
UPDATE app_settings SET v = '0.42' WHERE k = 'face_checkin_consensus_threshold';
UPDATE app_settings SET v = '0.67' WHERE k = 'face_checkin_consensus_ratio';
UPDATE app_settings SET v = '0.40' WHERE k = 'face_identify_threshold';
UPDATE app_settings SET v = '0.15' WHERE k = 'face_identify_margin';
UPDATE app_settings SET v = '0.45' WHERE k = 'face_identify_consensus_threshold';
UPDATE app_settings SET v = '0.50' WHERE k = 'face_identify_consensus_ratio';

-- Configuração de diversidade mínima para enrollment
INSERT INTO app_settings (`k`, `v`) VALUES ('face_enrollment_min_diversity', '0.10')
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);

-- Migração: Unificar thresholds de reconhecimento facial por contexto
-- Os thresholds agora usam prefixo por contexto (checkin/identify)
-- Fallback para chaves genéricas mantém retrocompatibilidade

INSERT INTO app_settings (`k`, `v`) VALUES
    ('face_checkin_threshold', '0.38'),
    ('face_checkin_margin', '0.15'),
    ('face_checkin_consensus_threshold', '0.45'),
    ('face_checkin_consensus_ratio', '0.50'),
    ('face_identify_threshold', '0.45'),
    ('face_identify_margin', '0.12'),
    ('face_identify_consensus_threshold', '0.50'),
    ('face_identify_consensus_ratio', '0.30')
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);

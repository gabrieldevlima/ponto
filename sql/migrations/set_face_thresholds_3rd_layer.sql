-- Política: face é a 3ª camada de identificação (após CPF + PIN). Como a
-- identidade já é provada pelos 2 fatores anteriores, o reconhecimento
-- facial pode ser permissivo: aceita variação real (luz, óculos, ângulo)
-- sem rejeitar a pessoa certa. A guardrail contra pessoa errada é o
-- `second_best_margin` (delta entre 1º e 2º best match).
--
-- Esses valores foram calibrados após auditoria nesta sessão. Se já
-- existirem (admin tunado), são SOBRESCRITOS — assumimos que esta
-- migration estabelece o baseline correto pós-deploy.

INSERT INTO app_settings (k, v) VALUES
  ('face_checkin_threshold',                '0.70'),
  ('face_checkin_consensus_threshold',      '0.70'),
  ('face_checkin_consensus_ratio',          '0.20'),
  ('face_checkin_margin',                   '0.05'),
  ('face_identify_threshold',               '0.65'),
  ('face_identify_consensus_threshold',     '0.65'),
  ('face_identify_consensus_ratio',         '0.30'),
  ('face_identify_margin',                  '0.08'),
  ('face_threshold_checkin',                '0.70'),
  ('face_threshold_identify',               '0.65'),
  ('face_conflict_threshold',               '0.40'),
  ('face_uniqueness_threshold',             '0.45'),
  ('face_single_descriptor_strict_factor',  '0.95'),
  ('face_min_descriptors_for_auth',         '1')
ON DUPLICATE KEY UPDATE v = VALUES(v);

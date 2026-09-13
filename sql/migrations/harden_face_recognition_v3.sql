-- Hardening v3: Eliminar falsos positivos e falsos negativos
-- G1: Minimo de descriptors para autenticacao facial
-- G2: Liveness score e sinais minimos
-- G3: Threshold de conflito mais rigoroso
-- G6: Re-enrollment apos 180 dias

INSERT INTO app_settings (k, v) VALUES
  ('face_min_descriptors_for_auth', '3'),
  ('liveness_min_score', '0.10'),
  ('liveness_min_signals', '1'),
  ('liveness_min_movement', '0.0008'),
  ('face_reenroll_days', '180')
ON DUPLICATE KEY UPDATE v = VALUES(v);

-- G3: Reduzir threshold de conflito (0.46 -> 0.38) e min_hits (2 -> 1)
INSERT INTO app_settings (k, v) VALUES ('face_uniqueness_threshold', '0.38')
ON DUPLICATE KEY UPDATE v = '0.38';

INSERT INTO app_settings (k, v) VALUES ('face_uniqueness_min_hits', '1')
ON DUPLICATE KEY UPDATE v = '1';

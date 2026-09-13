-- =============================================================================
-- Migração: Correção completa dos thresholds de reconhecimento facial
-- Data: 2026-03-19
-- Tabela: app_settings (colunas: k, v)
-- =============================================================================

INSERT INTO app_settings (k, v) VALUES ('face_match_threshold', '0.45')
ON DUPLICATE KEY UPDATE v = '0.45';

INSERT INTO app_settings (k, v) VALUES ('face_second_best_margin', '0.12')
ON DUPLICATE KEY UPDATE v = '0.12';

INSERT INTO app_settings (k, v) VALUES ('face_consensus_threshold', '0.50')
ON DUPLICATE KEY UPDATE v = '0.50';

INSERT INTO app_settings (k, v) VALUES ('face_min_consensus_ratio', '0.30')
ON DUPLICATE KEY UPDATE v = '0.30';

DELETE FROM app_settings WHERE k = 'face_strong_match_threshold';

-- Verificação
SELECT k, v FROM app_settings WHERE k LIKE 'face_%' ORDER BY k;

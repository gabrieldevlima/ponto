-- Migration: Adicionar configuracoes de reconhecimento facial avancado
-- Data: 2026-03-21
-- Descricao: Thresholds configuraveis para matching facial com 3 camadas de validacao

-- Thresholds de reconhecimento facial
INSERT IGNORE INTO app_settings (k, v) VALUES
  ('face_threshold_checkin', '0.45'),   -- Threshold para check-in (mais rigoroso)
  ('face_threshold_identify', '0.50'),  -- Threshold para identificacao facial pura
  ('face_margin_min', '0.10'),          -- Margem minima entre 1o e 2o melhor match
  ('face_consensus_ratio', '0.40'),     -- Fracao minima de descriptors que devem bater
  ('face_conflict_threshold', '0.35'),  -- Threshold para deteccao de conflito facial no cadastro
  ('face_max_descriptors', '20'),       -- Maximo de descriptors por professor
  ('face_liveness_min_variance', '0.01'), -- Variancia minima inter-frame para liveness
  ('face_rate_limit_max', '10'),        -- Max tentativas de identificacao por minuto por IP
  ('face_rate_limit_window', '60');     -- Janela de rate limiting em segundos

-- Verificacao
SELECT k, v FROM app_settings WHERE k LIKE 'face_%';

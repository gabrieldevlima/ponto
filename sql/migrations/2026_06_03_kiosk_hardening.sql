-- Parâmetros de robustez/segurança do quiosque facial local.
-- INSERT IGNORE: não sobrescreve ajustes do admin.
--   kiosk_cpf_max_fails        nº de falhas (not_matched) por CPF antes de bloquear
--   kiosk_cpf_lockout_minutes  janela/duração do bloqueio temporário
--   kiosk_face_match_threshold override do limiar de distância do quiosque
--                              (vazio = usa face_identify_threshold compartilhado)
--   kiosk_photo_retention_days retenção das imagens de auditoria do quiosque
INSERT IGNORE INTO app_settings (k, v) VALUES
  ('kiosk_cpf_max_fails', '5'),
  ('kiosk_cpf_lockout_minutes', '10'),
  ('kiosk_face_match_threshold', ''),
  ('kiosk_photo_retention_days', '90');

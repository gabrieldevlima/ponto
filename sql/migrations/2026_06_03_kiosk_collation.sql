-- Normaliza a collation das tabelas do quiosque para a do app (utf8mb4_unicode_ci).
-- As tabelas foram criadas com `DEFAULT CHARSET=utf8mb4` sem COLLATE, herdando o
-- default do servidor (general_ci), enquanto attendance/teachers usam unicode_ci.
-- Isso previne erros de "Illegal mix of collations" (1267) em joins futuros por
-- colunas de texto. CONVERT TO é idempotente (reexecução não falha).
ALTER TABLE kiosk_face_logs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE kiosk_devices CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

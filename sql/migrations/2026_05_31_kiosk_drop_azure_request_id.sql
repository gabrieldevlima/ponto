-- Remove a última referência à Azure no schema do quiosque: a coluna
-- azure_request_id em kiosk_face_logs (rastreava o apim-request-id da Azure).
-- O motor agora é local (face-api.js), sem id de requisição externa.
-- Idempotente: o runner tolera 1091 (DROP de coluna inexistente) em re-run.

ALTER TABLE kiosk_face_logs DROP COLUMN azure_request_id;

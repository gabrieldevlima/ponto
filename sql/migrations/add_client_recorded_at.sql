-- S1: separa timestamp do servidor (recorded_at, sempre NOW() na chegada)
-- do timestamp do cliente (client_recorded_at). Anti-fraude: cliente não
-- pode mais "datar" pontos no passado/futuro. O delta é registrado para
-- auditoria de pontos que ficaram offline e dropparam tarde.

ALTER TABLE attendance
  ADD COLUMN IF NOT EXISTS client_recorded_at DATETIME NULL AFTER recorded_at,
  ADD COLUMN IF NOT EXISTS offline_delay_seconds INT NULL AFTER client_recorded_at;

-- Backfill: pontos antigos que tinham só recorded_at — assume que client = server
UPDATE attendance
   SET client_recorded_at = recorded_at,
       offline_delay_seconds = 0
 WHERE client_recorded_at IS NULL
   AND recorded_at IS NOT NULL;

-- Índice para admin filtrar pontos com delay suspeito
CREATE INDEX IF NOT EXISTS idx_attendance_offline_delay ON attendance(offline_delay_seconds);

-- Idempotência para check-ins que vêm de fila offline.
-- O frontend gera um client_id (UUID) antes de tentar enviar. Se o sync
-- disparar duas vezes (evento online + Background Sync + reload), o segundo
-- POST detecta o client_id já gravado e retorna 'already_registered' em vez
-- de criar um ponto duplicado.
--
-- NULL é aceito em múltiplas linhas pelo UNIQUE do MySQL (semântica NULL ≠ NULL).
-- Registros antigos continuam com client_id = NULL sem violar a constraint.

ALTER TABLE attendance
  ADD COLUMN IF NOT EXISTS client_id VARCHAR(64) NULL AFTER id;

-- UNIQUE índice com tratamento idempotente (CREATE UNIQUE INDEX IF NOT EXISTS
-- não é universal; usamos add + ignora erro via auto-migration).
CREATE UNIQUE INDEX uk_attendance_client_id ON attendance(client_id);

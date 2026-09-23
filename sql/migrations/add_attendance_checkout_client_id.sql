-- Idempotência para checkouts (saída) que vêm de fila offline.
-- Complementa add_attendance_client_id.sql (que cobre apenas a entrada).
--
-- Sem isso, retry de SW após falha de rede mid-flight criava entrada-fantasma:
-- 1ª tentativa fechava o ponto via UPDATE; resposta perdia no caminho;
-- 2ª tentativa não achava ponto aberto e caía no ramo entrada, gerando
-- novo registro com check_in = NOW() (timestamp errado).
--
-- NULL é aceito em múltiplas linhas pelo UNIQUE do MySQL (semântica NULL ≠ NULL).
-- Registros antigos continuam com checkout_client_id = NULL sem violar a constraint.

ALTER TABLE attendance
  ADD COLUMN IF NOT EXISTS checkout_client_id VARCHAR(64) NULL AFTER client_id;

-- UNIQUE índice; auto-migration tolera erro 1061 (duplicate key) em re-run.
CREATE UNIQUE INDEX uk_attendance_checkout_client_id ON attendance(checkout_client_id);

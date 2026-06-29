-- =====================================================================
-- Migration: Afastamento que abona vs nao abona a falta
-- =====================================================================
-- Adiciona flag por registro em leaves. Desacopla "abona a falta" da
-- flag de remuneracao leave_types.paid. Quando excuses_absence=1 o dia
-- tem a jornada prevista zerada (nao conta falta nem desconto). Quando
-- 0/NULL, o dia continua contando como falta.
-- =====================================================================

ALTER TABLE leaves
  ADD COLUMN excuses_absence TINYINT(1) NULL DEFAULT NULL
  COMMENT 'Se 1, este afastamento abona a falta (zera jornada). 0/NULL = conta falta'
  AFTER approved;

-- Backfill idempotente: preserva o comportamento atual (tipo pago abonava)
-- e nao sobrescreve registros ja definidos por usuario (preenche so os NULL).
UPDATE leaves l
  JOIN leave_types lt ON lt.id = l.type_id
  SET l.excuses_absence = lt.paid
  WHERE l.excuses_absence IS NULL;

-- Verificacao
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'leaves'
  AND COLUMN_NAME = 'excuses_absence';

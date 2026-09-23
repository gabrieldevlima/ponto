-- =====================================================================
-- 2026-04-27 — FINALIZAÇÃO v1→v2
-- =====================================================================
-- Após aplicar as 10 migrations órfãs (consolidate, pin_trusted, device_token,
-- client_id, checkout_client_id, client_recorded_at, gps_fallback,
-- face_thresholds, fix_audit_logs_nullable, backfill_trusted_devices), restam
-- 3 ajustes para fechar o gap v1→v2:
--   A) Inicializar nsr_sequence preservando MAX(nsr) atual.
--   B) Recriar v_payslips_full como VIEW (estava como BASE TABLE neste schema,
--      diferente das outras 4 instâncias que estão como VIEW).
--   C) Relaxar teachers.pin_hash de NOT NULL para NULL (v2 permite teachers
--      sem PIN — self-enroll). Limpar empty strings → NULL.
-- =====================================================================

-- A) NSR sequence — seedar com MAX(nsr) existente para não quebrar Portaria 671
INSERT INTO nsr_sequence (id, current_nsr)
VALUES (1, COALESCE((SELECT MAX(nsr) FROM attendance), 0))
ON DUPLICATE KEY UPDATE current_nsr = GREATEST(current_nsr, VALUES(current_nsr));

-- B) v_payslips_full: BASE TABLE → VIEW
-- Definição extraída de outra instância (ponto.v_payslips_full).
-- Tabela está vazia (0 registros) → DROP é seguro.
DROP TABLE IF EXISTS v_payslips_full;
CREATE OR REPLACE VIEW v_payslips_full AS
SELECT
  p.id AS id,
  p.reference_month AS reference_month,
  DATE_FORMAT(p.reference_month, '%m/%Y') AS month_formatted,
  YEAR(p.reference_month) AS year,
  p.teacher_id AS teacher_id,
  t.name AS teacher_name,
  t.cpf AS teacher_cpf,
  p.base_salary AS base_salary,
  p.worked_minutes AS worked_minutes,
  p.expected_minutes AS expected_minutes,
  p.overtime_minutes AS overtime_minutes,
  p.overtime_value AS overtime_value,
  p.deficit_minutes AS deficit_minutes,
  p.discount_value AS discount_value,
  p.gross_total AS gross_total,
  p.net_total AS net_total,
  p.generated_at AS generated_at,
  p.viewed_by_teacher_at AS viewed_by_teacher_at,
  CASE WHEN p.viewed_by_teacher_at IS NOT NULL THEN 1 ELSE 0 END AS was_viewed
FROM payslips p
JOIN teachers t ON t.id = p.teacher_id
ORDER BY p.reference_month DESC, t.name;

-- C) teachers.pin_hash: NOT NULL → NULL (v2 permite self-enroll)
ALTER TABLE teachers MODIFY COLUMN pin_hash VARCHAR(255) NULL;
UPDATE teachers SET pin_hash = NULL WHERE pin_hash = '';

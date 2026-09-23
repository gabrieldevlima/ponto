-- Atualiza a view v_attendance_receipts para considerar o campo record_type
-- introduzido pela migration 2026_05_25_add_attendance_break_support.sql.
--
-- Antes, a view determinava o rótulo `action` apenas com check_in/check_out:
--   WHEN check_out IS NOT NULL THEN 'saída'
--   WHEN check_in  IS NOT NULL THEN 'entrada'
--
-- Isso fazia com que registros de intervalo (record_type='break') aparecessem como
-- "entrada" ou "saída" no comprovante, confundindo o colaborador.
--
-- Esta migration adiciona dois novos rótulos: 'início do intervalo' e 'retorno do
-- intervalo' E expõe a coluna record_type para o template do comprovante.
-- CREATE OR REPLACE garante idempotência sem precisar de DROP.

CREATE OR REPLACE VIEW v_attendance_receipts AS
SELECT
  a.id,
  a.nsr,
  a.teacher_id,
  t.name AS teacher_name,
  t.cpf  AS teacher_cpf,
  a.date,
  a.check_in,
  a.check_out,
  a.record_type,
  CASE
    WHEN a.record_type = 'break' AND a.check_out IS NOT NULL THEN 'retorno do intervalo'
    WHEN a.record_type = 'break' AND a.check_in  IS NOT NULL THEN 'início do intervalo'
    WHEN a.check_out IS NOT NULL THEN 'saída'
    WHEN a.check_in  IS NOT NULL THEN 'entrada'
    ELSE 'indefinido'
  END AS action,
  a.record_mode,
  a.recorded_at,
  a.synced_at,
  a.check_in_lat  AS latitude,
  a.check_in_lng  AS longitude,
  a.photo,
  a.approved,
  a.hlb_sync_status,
  a.device_identifier,
  a.receipt_generated,
  a.receipt_viewed_at,
  e.company_name,
  e.cnpj,
  e.system_name,
  e.system_version,
  e.rep_category
FROM attendance a
INNER JOIN teachers t ON a.teacher_id = t.id
CROSS JOIN employer_config e
ORDER BY a.nsr DESC;

-- Corrige collation da coluna `action` em v_attendance_receipts.
--
-- BUG ORIGINAL EM PRODUÇÃO: ao salvar ponto manual ou consultar comprovante, MySQL
-- retornava "Illegal mix of collations (utf8mb4_general_ci,COERCIBLE) and
-- (utf8mb4_unicode_ci,COERCIBLE)". A causa: o CASE WHEN ... THEN 'string' END na view
-- gera literals com collation default da conexão (utf8mb4_general_ci), enquanto as
-- tabelas estão em utf8mb4_unicode_ci. Qualquer comparação entre a view e tabelas
-- (JOINs, INSERTs com SELECT, triggers) explodia.
--
-- Fix: recriar a view aplicando COLLATE utf8mb4_unicode_ci EXPLÍCITO em cada literal
-- do CASE. Isso força a coluna `action` a herdar a collation correta, eliminando o
-- conflito em qualquer operação que envolva a view.
--
-- CREATE OR REPLACE garante idempotência.

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
    WHEN a.record_type = 'break' AND a.check_out IS NOT NULL THEN CONVERT('retorno do intervalo' USING utf8mb4) COLLATE utf8mb4_unicode_ci
    WHEN a.record_type = 'break' AND a.check_in  IS NOT NULL THEN CONVERT('início do intervalo'  USING utf8mb4) COLLATE utf8mb4_unicode_ci
    WHEN a.check_out IS NOT NULL AND EXISTS (
      SELECT 1 FROM attendance b
       WHERE b.parent_attendance_id = a.id AND b.record_type = 'break'
    ) THEN CONVERT('saída para intervalo' USING utf8mb4) COLLATE utf8mb4_unicode_ci
    WHEN a.check_out IS NOT NULL THEN CONVERT('saída' USING utf8mb4) COLLATE utf8mb4_unicode_ci
    WHEN a.check_in IS NOT NULL AND EXISTS (
      SELECT 1 FROM attendance b
       WHERE b.teacher_id = a.teacher_id
         AND b.date = a.date
         AND b.record_type = 'break'
         AND b.check_out IS NOT NULL
         AND b.check_out <= a.check_in
    ) THEN CONVERT('retorno do intervalo' USING utf8mb4) COLLATE utf8mb4_unicode_ci
    WHEN a.check_in IS NOT NULL THEN CONVERT('entrada' USING utf8mb4) COLLATE utf8mb4_unicode_ci
    ELSE CONVERT('indefinido' USING utf8mb4) COLLATE utf8mb4_unicode_ci
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

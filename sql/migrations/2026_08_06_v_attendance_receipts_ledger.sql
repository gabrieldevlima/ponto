-- ============================================================================
-- v_attendance_receipts: estado de anulação + campos do livro fiscal e do art. 80
-- Fase 2.5 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- Resolve:
--   NC-53  a view não expunha `removed_at`/`superseded_by_id`, então
--          public/receipt.php emitia comprovante alegre de registro ANULADO.
--   NC-17  faltavam no comprovante o CPF do empregador, o local da prestação
--          de serviço e a identificação do REP (art. 80 da Portaria 671).
--   NC-09  a saída não tinha NSR próprio; agora `nsr_out` vem para o recibo.
--
-- REGRAS DE OURO DESTA VIEW (aprendidas a duras penas — cinco migrations em
-- 2026-05 até acertar; ver 2026_05_25_zz3_simplify_v_attendance_receipts.sql):
--   1. NADA de EXISTS aninhado dentro de CASE WHEN. O serializador do MariaDB
--      corrompe a definição e SHOW CREATE VIEW passa a devolver SQL inválido.
--   2. Usar `_utf8mb4'texto'` (charset introducer), nunca
--      CONVERT('texto' USING utf8mb4).
--   3. COLLATE utf8mb4_unicode_ci explícito em todo literal, para não cair no
--      erro 1267 de mistura de collation.
--   4. DROP antes de criar: em produção a definição pode estar corrompida a
--      ponto de CREATE OR REPLACE também falhar.
--
-- Nota sobre o CROSS JOIN em employer_config: é herdado da definição original.
-- `employer_config` é tabela de linha única; se ficar VAZIA, a view não devolve
-- nada e nenhum comprovante é emitido. Preservado como estava para não misturar
-- mudança de comportamento com esta correção, mas fica registrado como risco.
-- ============================================================================

DROP VIEW IF EXISTS v_attendance_receipts;

CREATE VIEW v_attendance_receipts AS
SELECT
  a.id,
  a.nsr,
  a.nsr_out,
  a.legacy_nsr,
  a.teacher_id,
  t.name AS teacher_name,
  t.cpf  AS teacher_cpf,
  t.pis  AS teacher_pis,
  a.date,
  a.check_in,
  a.check_out,
  a.record_type,
  CASE
    WHEN a.record_type = 'break' AND a.check_out IS NOT NULL THEN _utf8mb4'retorno do intervalo' COLLATE utf8mb4_unicode_ci
    WHEN a.record_type = 'break' AND a.check_in  IS NOT NULL THEN _utf8mb4'início do intervalo'  COLLATE utf8mb4_unicode_ci
    WHEN a.check_out IS NOT NULL THEN _utf8mb4'saída'    COLLATE utf8mb4_unicode_ci
    WHEN a.check_in  IS NOT NULL THEN _utf8mb4'entrada'  COLLATE utf8mb4_unicode_ci
    ELSE _utf8mb4'indefinido' COLLATE utf8mb4_unicode_ci
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
  a.removed_at,
  a.removed_reason,
  a.superseded_by_id,
  e.company_name,
  e.cnpj,
  e.cpf AS employer_cpf,
  e.employer_type,
  e.cei_caepf_cno,
  e.rep_identifier,
  e.service_location,
  e.address AS employer_address,
  e.city    AS employer_city,
  e.state   AS employer_state,
  e.system_name,
  e.system_version,
  e.rep_category
FROM attendance a
INNER JOIN teachers t ON a.teacher_id = t.id
CROSS JOIN employer_config e
ORDER BY a.nsr DESC;

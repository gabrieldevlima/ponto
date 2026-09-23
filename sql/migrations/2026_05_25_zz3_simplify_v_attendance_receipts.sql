-- Simplifica v_attendance_receipts removendo EXISTS aninhado e CONVERT(...USING)
-- que causam erro 1064 ao serializar/recarregar a view em algumas versões do
-- MariaDB (Hostinger/produção em 2026).
--
-- BUG OBSERVADO EM PRODUÇÃO:
-- O parser/serializer do MariaDB corrompe a definição da view quando há
-- EXISTS (SELECT 1 FROM ...) dentro de CASE WHEN combinado com CONVERT(...
-- USING utf8mb4). Ao tentar fazer SHOW CREATE VIEW (ou ao reaplicar a view
-- via import), retorna SQL inválido como:
--   THEN convert('texto' using utf8mb4) 1 AS `ENDselect` END FROM `attendance`
--   AS `b` WHERE `b`.`parent_attendance_id` = `a`.`id` ...
-- ou seja, o EXISTS perde os parênteses externos e a inner subquery vaza
-- para o nível do CASE.
--
-- FIX:
-- 1. Remove EXISTS aninhado. Os 2 rótulos que dependiam disso ("saída para
--    intervalo" e "retorno do intervalo" para registros work) são detectados
--    agora no PHP (public/receipt.php / api/get_receipt.php) consultando
--    record_type + parent_attendance_id + breaks adjacentes do mesmo dia.
-- 2. Troca CONVERT('texto' USING utf8mb4) por _utf8mb4'texto' — sintaxe
--    equivalente (charset introducer), mais antiga e melhor suportada.
-- 3. Mantém COLLATE utf8mb4_unicode_ci explícito para evitar conflito 1267.
--
-- DEPOIS DESTA MIGRATION:
-- - View tem 5 labels possíveis: entrada, saída, início do intervalo,
--   retorno do intervalo, indefinido.
-- - Detecção contextual ocorre só na camada PHP do receipt.
--
-- DEFENSIVO: dropamos a view antes de recriar. Em produção a view pode estar
-- com definição corrompida tal que CREATE OR REPLACE também falhe. DROP IF
-- EXISTS sempre funciona porque opera no metadata, não na definição da view.

DROP VIEW IF EXISTS v_attendance_receipts;

CREATE VIEW v_attendance_receipts AS
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
  e.company_name,
  e.cnpj,
  e.system_name,
  e.system_version,
  e.rep_category
FROM attendance a
INNER JOIN teachers t ON a.teacher_id = t.id
CROSS JOIN employer_config e
ORDER BY a.nsr DESC;

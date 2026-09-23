-- =====================================================================
-- HOTFIX: afastamentos pre-feature devem ABONAR a falta
-- =====================================================================
-- Antes desta funcionalidade, QUALQUER afastamento aprovado ja suprimia a
-- falta (a logica usava a existencia do afastamento no dia, nao a flag
-- leave_types.paid). O backfill da migration 2026_06_29 gravou
-- excuses_absence = lt.paid, o que marcou afastamentos de tipos
-- NAO-remunerados (paid=0) como "nao abona" (0) -- fazendo esses dias
-- virarem FALTA indevidamente em producao.
--
-- Correcao: todo afastamento criado ANTES do deploy abona a falta
-- (excuses_absence=1), restaurando o comportamento anterior. Afastamentos
-- novos, criados pelo formulario com o toggle "abona a falta?", NAO sao
-- afetados (created_at >= corte do deploy).
--
-- Cobre tambem o caso de o backfill original nao ter rodado (excuses NULL).
-- =====================================================================

UPDATE leaves
   SET excuses_absence = 1
 WHERE (excuses_absence = 0 OR excuses_absence IS NULL)
   AND created_at < '2026-06-30';

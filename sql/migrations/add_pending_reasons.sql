-- =====================================================================
-- Migration: Adicionar Motivos de Pendência
-- =====================================================================
-- Adiciona coluna para armazenar motivos quando ponto fica pendente
-- Permite isentar colaboradores network_wide da verificação de localização
-- =====================================================================

-- Adiciona coluna para armazenar motivos de pendência em JSON
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS pending_reasons TEXT NULL 
COMMENT 'Motivos JSON quando ponto fica pendente (ex: sem foto, sem geo)';

SELECT '✓ Coluna pending_reasons adicionada com sucesso!' AS status;


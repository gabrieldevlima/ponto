-- =====================================================================
-- Migration: Adicionar Rastreamento de Exclusão de Fotos
-- =====================================================================
-- Adiciona colunas para rastrear quando fotos são deletadas automaticamente
-- pelo sistema de limpeza de armazenamento
-- =====================================================================

-- Adiciona coluna photo_deleted
-- Se a coluna já existir, o MySQL irá retornar um erro que pode ser ignorado
ALTER TABLE attendance 
ADD COLUMN photo_deleted TINYINT(1) DEFAULT 0 
COMMENT 'Flag indicando se a foto foi deletada automaticamente';

-- Adiciona coluna photo_deleted_at
-- Se a coluna já existir, o MySQL irá retornar um erro que pode ser ignorado
ALTER TABLE attendance 
ADD COLUMN photo_deleted_at DATETIME NULL 
COMMENT 'Data/hora em que a foto foi deletada';

-- Criar índice para consultas de limpeza
-- Se o índice já existir, o MySQL irá retornar um erro que pode ser ignorado
CREATE INDEX idx_att_photo_cleanup ON attendance(photo_deleted, date);


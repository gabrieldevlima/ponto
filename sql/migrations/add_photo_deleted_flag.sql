-- =====================================================================
-- Migration: Adicionar Rastreamento de Exclusão de Fotos
-- =====================================================================
-- Adiciona colunas para rastrear quando fotos são deletadas automaticamente
-- pelo sistema de limpeza de armazenamento
-- =====================================================================

ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS photo_deleted TINYINT(1) DEFAULT 0 
COMMENT 'Flag indicando se a foto foi deletada automaticamente';

ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS photo_deleted_at DATETIME NULL 
COMMENT 'Data/hora em que a foto foi deletada';

-- Criar índice para consultas de limpeza
CREATE INDEX IF NOT EXISTS idx_att_photo_cleanup ON attendance(photo_deleted, date);

-- Verificar se foi adicionado
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'attendance' 
  AND COLUMN_NAME LIKE 'photo_deleted%';


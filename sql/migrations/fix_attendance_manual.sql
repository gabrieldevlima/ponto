-- =====================================================================
-- FIX: Adicionar Colunas para Ponto Manual Funcionar
-- =====================================================================
-- O arquivo attendance_manual.php requer estas colunas
-- =====================================================================

-- 1. Adicionar colunas de rastreamento de inserção manual
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS manual_by_admin_id INT NULL 
COMMENT 'ID do admin que inseriu/editou manualmente';

ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS manual_created_at DATETIME NULL 
COMMENT 'Data/hora da inserção/edição manual';

-- 2. Verificar se foi adicionado
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'attendance' 
  AND COLUMN_NAME LIKE 'manual_%';

-- 3. (OPCIONAL) Adicionar foreign key para rastreamento
-- Descomente se quiser garantir integridade referencial
-- ALTER TABLE attendance 
-- ADD CONSTRAINT fk_att_manual_admin 
-- FOREIGN KEY (manual_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL;



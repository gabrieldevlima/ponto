-- =====================================================================
-- Migration: Adicionar Colunas de Rastreamento de Pontos Manuais
-- =====================================================================
-- Adiciona colunas para rastrear quem inseriu ponto manual e quando
-- =====================================================================

ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS manual_by_admin_id INT NULL 
COMMENT 'ID do admin que inseriu manualmente';

ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS manual_created_at DATETIME NULL 
COMMENT 'Data/hora de inserção manual';

-- Adicionar foreign key (se não existir)
-- ALTER TABLE attendance 
-- ADD CONSTRAINT fk_att_manual_admin 
-- FOREIGN KEY (manual_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL;

-- Verificar
SHOW COLUMNS FROM attendance LIKE 'manual_%';



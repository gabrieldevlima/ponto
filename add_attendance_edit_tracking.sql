-- =====================================================================
-- Migration: Campos de Rastreamento de Edição na Tabela Attendance
-- =====================================================================
-- Adiciona campos para rastrear quem editou, quando e por quê
-- =====================================================================

-- Adiciona colunas se não existirem
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS editado_por INT UNSIGNED NULL COMMENT 'ID do admin que editou',
ADD COLUMN IF NOT EXISTS data_edicao DATETIME NULL COMMENT 'Data/hora da última edição',
ADD COLUMN IF NOT EXISTS motivo_edicao TEXT NULL COMMENT 'Justificativa da edição';

-- Adiciona foreign key se não existir
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                  WHERE CONSTRAINT_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'attendance' 
                  AND CONSTRAINT_NAME = 'fk_attendance_editado_por');

SET @sql = IF(@fk_exists = 0, 
    'ALTER TABLE attendance ADD CONSTRAINT fk_attendance_editado_por FOREIGN KEY (editado_por) REFERENCES admins(id) ON DELETE SET NULL', 
    'SELECT "FK já existe"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;



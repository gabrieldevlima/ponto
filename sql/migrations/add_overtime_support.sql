-- =====================================================================
-- MIGRAÇÃO: Suporte a Horas Extras para Sistema de Grade Horária
-- =====================================================================
-- Adiciona suporte para check-ins fora da grade horária atribuída,
-- permitindo registro de horas extras legítimas (reuniões, eventos, etc)
-- com aprovação administrativa.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =====================================================================
-- 1. MODIFICAR TABELA ATTENDANCE
-- =====================================================================

-- Adiciona campo para identificar candidatos a hora extra
ALTER TABLE attendance 
  ADD COLUMN IF NOT EXISTS is_overtime_candidate TINYINT(1) NOT NULL DEFAULT 0 
  COMMENT 'Check-in fora da grade horária - candidato a hora extra'
  AFTER class_period_id;

-- Adiciona campo para justificativa do professor
ALTER TABLE attendance 
  ADD COLUMN IF NOT EXISTS overtime_justification TEXT NULL 
  COMMENT 'Justificativa fornecida pelo professor para hora extra'
  AFTER is_overtime_candidate;

-- Índice para facilitar busca de candidatos pendentes
CREATE INDEX IF NOT EXISTS idx_att_overtime_candidate ON attendance(is_overtime_candidate, approved);

-- =====================================================================
-- 2. MODIFICAR TABELA OVERTIME_REQUESTS
-- =====================================================================

-- Adiciona campo justification se não existir
SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                      WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'overtime_requests' 
                      AND COLUMN_NAME = 'justification');

SET @sql = IF(@column_exists = 0,
              'ALTER TABLE overtime_requests ADD COLUMN justification TEXT NULL COMMENT ''Justificativa do professor'' AFTER worked_minutes',
              'SELECT "Coluna justification já existe" as info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 3. ADICIONAR CONFIGURAÇÕES PADRÃO
-- =====================================================================

-- Settings para controle de horas extras
INSERT IGNORE INTO app_settings (k, v) VALUES
('overtime_tolerance_minutes', '30'),
('overtime_requires_justification', '1'),
('overtime_auto_approve', '0'),
('overtime_multiplier', '1.5'),
('overtime_max_daily_hours', '4');

-- =====================================================================
-- 4. ATUALIZAR REGISTROS EXISTENTES
-- =====================================================================

-- Marca todos os registros existentes como NÃO sendo candidatos a hora extra
UPDATE attendance 
SET is_overtime_candidate = 0 
WHERE is_overtime_candidate IS NULL;

-- =====================================================================
-- 5. VERIFICAÇÃO
-- =====================================================================

SELECT '✓ Migração de suporte a horas extras concluída!' as status;

SELECT 
  COUNT(*) as total_attendance_records,
  SUM(CASE WHEN is_overtime_candidate = 1 THEN 1 ELSE 0 END) as overtime_candidates,
  SUM(CASE WHEN is_overtime_candidate = 0 THEN 1 ELSE 0 END) as regular_records
FROM attendance;

SELECT 'Configurações de overtime:' as info;
SELECT k, v FROM app_settings WHERE k LIKE 'overtime_%';

-- =====================================================================
-- INSTRUÇÕES DE USO:
-- =====================================================================
-- 
-- 1. Professores com grade horária podem fazer check-in fora dos períodos
--    atribuídos, fornecendo uma justificativa
--
-- 2. Check-ins fora da grade ficam pendentes (approved = NULL)
--    e marcados como is_overtime_candidate = 1
--
-- 3. Admin revisa em: /public/admin/attendance_review_overtime.php
--    - Aprovar como hora extra (cria overtime_request)
--    - Rejeitar (com motivo)
--    - Converter em aula regular (ajustar grade)
--
-- 4. Horas extras aprovadas são calculadas em reports_financial.php
--    com multiplicador configurável (padrão 1.5x)
--
-- 5. Compatibilidade total:
--    - Aulas regulares = pagamento fixo (não afetado)
--    - Sistema tradicional continua igual
--    - Apenas adiciona flexibilidade para extras legítimas
--
-- =====================================================================


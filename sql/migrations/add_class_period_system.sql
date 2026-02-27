-- =====================================================================
-- MIGRAÇÃO: Sistema de Grade Horária e Múltiplos Check-ins
-- =====================================================================
-- Este script adiciona o sistema de grade horária para professores
-- permitindo múltiplos check-ins por dia (um para cada aula/período)
-- e evitando contabilizar períodos ociosos entre aulas.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =====================================================================
-- 1. CRIAR TABELAS NOVAS
-- =====================================================================

-- Tabela de períodos de aula (grade horária padrão)
CREATE TABLE IF NOT EXISTS class_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NULL COMMENT 'NULL = global (todas as escolas)',
  period_number INT NOT NULL COMMENT 'Número do período (1ª aula, 2ª aula, etc.)',
  start_time TIME NOT NULL COMMENT 'Horário de início',
  end_time TIME NOT NULL COMMENT 'Horário de término',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_period_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  UNIQUE KEY uq_school_period (school_id, period_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Grade horária padrão da instituição';

CREATE INDEX IF NOT EXISTS idx_period_school ON class_periods(school_id);
CREATE INDEX IF NOT EXISTS idx_period_active ON class_periods(active);

-- Tabela de atribuições de professores aos períodos
CREATE TABLE IF NOT EXISTS teacher_class_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL COMMENT '0=Dom, 1=Seg, ..., 6=Sáb',
  period_id INT NOT NULL COMMENT 'Qual período o professor tem aula',
  school_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_assignment_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_period FOREIGN KEY (period_id) REFERENCES class_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  UNIQUE KEY uq_teacher_weekday_period (teacher_id, weekday, period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Define em quais períodos cada professor tem aula';

CREATE INDEX IF NOT EXISTS idx_assignment_teacher ON teacher_class_assignments(teacher_id);
CREATE INDEX IF NOT EXISTS idx_assignment_period ON teacher_class_assignments(period_id);
CREATE INDEX IF NOT EXISTS idx_assignment_weekday ON teacher_class_assignments(weekday);

-- =====================================================================
-- 2. MODIFICAR TABELA ATTENDANCE
-- =====================================================================

-- Adiciona colunas para suportar múltiplos check-ins por dia
ALTER TABLE attendance 
  ADD COLUMN IF NOT EXISTS class_period_id INT NULL COMMENT 'ID do período/aula (grade horária)' AFTER pending_reasons,
  ADD COLUMN IF NOT EXISTS sequence_number INT NOT NULL DEFAULT 1 COMMENT 'Número sequencial do registro no dia' AFTER class_period_id;

-- Adiciona constraint de foreign key (ignora erro se já existir)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                  WHERE CONSTRAINT_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'attendance' 
                  AND CONSTRAINT_NAME = 'fk_att_period');

SET @sql = IF(@fk_exists = 0, 
              'ALTER TABLE attendance ADD CONSTRAINT fk_att_period FOREIGN KEY (class_period_id) REFERENCES class_periods(id) ON DELETE SET NULL', 
              'SELECT "FK fk_att_period já existe" as info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adiciona índices
CREATE INDEX IF NOT EXISTS idx_att_teacher_date_seq ON attendance(teacher_id, date, sequence_number);
CREATE INDEX IF NOT EXISTS idx_att_period ON attendance(class_period_id);

-- =====================================================================
-- 3. INSERIR DADOS PADRÃO
-- =====================================================================

-- Grade horária padrão (escola = NULL significa global)
-- Exemplo: sistema com 10 períodos de 50 minutos
INSERT IGNORE INTO class_periods (id, school_id, period_number, start_time, end_time, active) VALUES
(1, NULL, 1, '07:00:00', '07:50:00', 1),
(2, NULL, 2, '07:50:00', '08:40:00', 1),
(3, NULL, 3, '08:40:00', '09:30:00', 1),
(4, NULL, 4, '09:50:00', '10:40:00', 1),
(5, NULL, 5, '10:40:00', '11:30:00', 1),
(6, NULL, 6, '11:30:00', '12:20:00', 1),
(7, NULL, 7, '13:30:00', '14:20:00', 1),
(8, NULL, 8, '14:20:00', '15:10:00', 1),
(9, NULL, 9, '15:10:00', '16:00:00', 1),
(10, NULL, 10, '16:20:00', '17:10:00', 1);

-- =====================================================================
-- 4. VERIFICAÇÃO E RELATÓRIO
-- =====================================================================

SELECT '✓ Migração concluída!' as status;
SELECT COUNT(*) as total_periods FROM class_periods;
SELECT COUNT(*) as total_assignments FROM teacher_class_assignments;
SELECT 
  COUNT(*) as attendance_records_with_period 
FROM attendance 
WHERE class_period_id IS NOT NULL;

-- =====================================================================
-- INSTRUÇÕES DE USO:
-- =====================================================================
-- 
-- 1. Configure os períodos de aula no Admin:
--    - Acesse: /public/admin/class_periods.php
--    - Ajuste os horários conforme a grade da instituição
--    - Pode criar períodos específicos por escola
--
-- 2. Atribua períodos aos professores:
--    - Acesse: /public/admin/teacher_edit.php?id=X
--    - Na seção "Grade Horária - Períodos de Aula"
--    - Marque em quais períodos o professor tem aula
--
-- 3. O professor poderá fazer check-in/out para cada aula
--    - Sistema identifica automaticamente qual período está ativo
--    - Permite múltiplos check-ins no mesmo dia
--    - Pagamento baseado em número de aulas, não tempo total
--
-- 4. Compatibilidade:
--    - Sistema tradicional continua funcionando
--    - Professores sem períodos atribuídos usam sistema antigo
--    - Colaboradores com horário fixo (time) não são afetados
--
-- =====================================================================


-- ========================================
-- FEATURE OPCIONAL: Valor por Hora
-- ========================================
-- 
-- Este script adiciona a coluna hourly_rate para calcular
-- valores financeiros estimados na folha de ponto do colaborador.
--
-- COMO USAR:
-- 1. Execute este SQL no seu banco de dados
-- 2. Vá em Admin → Colaboradores → Editar
-- 3. Configure o valor/hora para cada colaborador
-- 4. O colaborador verá o valor estimado em "Minha Folha de Ponto"
--
-- ========================================

-- Adiciona coluna hourly_rate na tabela teachers
ALTER TABLE teachers 
ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL 
COMMENT 'Valor por hora trabalhada (para cálculo estimado)';

-- Adiciona índice para melhor performance
CREATE INDEX idx_teachers_hourly_rate ON teachers(hourly_rate);

-- Exemplos de como configurar valores:
-- UPDATE teachers SET hourly_rate = 50.00 WHERE id = 1;
-- UPDATE teachers SET hourly_rate = 75.00 WHERE id = 2;

-- Verificar configuração:
-- SELECT id, name, hourly_rate FROM teachers WHERE hourly_rate IS NOT NULL;


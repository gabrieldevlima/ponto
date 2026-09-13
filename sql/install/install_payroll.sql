-- ========================================================
-- SISTEMA DE HOLERITES (FOLHA DE PAGAMENTO)
-- ========================================================
-- Geração e gerenciamento de holerites mensais em PDF
-- Cálculo automático baseado em horas trabalhadas vs esperadas
-- ========================================================

USE ponto;

-- Tabela de holerites gerados
CREATE TABLE IF NOT EXISTS payslips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL COMMENT 'Colaborador',
  reference_month DATE NOT NULL COMMENT 'Mês de referência (primeiro dia do mês)',
  base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Salário base do colaborador',
  worked_minutes INT NOT NULL DEFAULT 0 COMMENT 'Minutos efetivamente trabalhados',
  expected_minutes INT NOT NULL DEFAULT 0 COMMENT 'Minutos esperados no período',
  overtime_minutes INT NOT NULL DEFAULT 0 COMMENT 'Horas extras em minutos',
  overtime_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor adicional de horas extras',
  deficit_minutes INT NOT NULL DEFAULT 0 COMMENT 'Déficit de horas em minutos',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Desconto por déficit',
  gross_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total bruto (base + extras)',
  net_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total líquido (bruto - descontos)',
  notes TEXT NULL COMMENT 'Observações administrativas',
  generated_by_admin_id INT NULL COMMENT 'Admin que gerou',
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de geração',
  viewed_by_teacher_at TIMESTAMP NULL COMMENT 'Quando o colaborador visualizou',
  CONSTRAINT fk_payslip_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_payslip_admin FOREIGN KEY (generated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  UNIQUE KEY uq_teacher_month (teacher_id, reference_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Holerites mensais dos colaboradores';

CREATE INDEX idx_payslip_teacher ON payslips(teacher_id);
CREATE INDEX idx_payslip_month ON payslips(reference_month);
CREATE INDEX idx_payslip_generated ON payslips(generated_at);

-- Tabela de itens adicionais do holerite (benefícios, deduções personalizadas)
CREATE TABLE IF NOT EXISTS payslip_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payslip_id INT NOT NULL COMMENT 'Referência ao holerite',
  type ENUM('earning','deduction') NOT NULL COMMENT 'Provento ou desconto',
  description VARCHAR(150) NOT NULL COMMENT 'Descrição do item',
  value DECIMAL(10,2) NOT NULL COMMENT 'Valor',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payslip_item_payslip FOREIGN KEY (payslip_id) REFERENCES payslips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Itens adicionais dos holerites';

CREATE INDEX idx_payslip_item_payslip ON payslip_items(payslip_id);
CREATE INDEX idx_payslip_item_type ON payslip_items(type);

-- View para facilitar consulta de holerites com informações do colaborador
CREATE OR REPLACE VIEW v_payslips_full AS
SELECT 
    p.id,
    p.reference_month,
    DATE_FORMAT(p.reference_month, '%m/%Y') as month_formatted,
    MONTHNAME(p.reference_month) as month_name,
    YEAR(p.reference_month) as year,
    p.teacher_id,
    t.name as teacher_name,
    t.cpf as teacher_cpf,
    p.base_salary,
    p.worked_minutes,
    p.expected_minutes,
    p.overtime_minutes,
    p.overtime_value,
    p.deficit_minutes,
    p.discount_value,
    p.gross_total,
    p.net_total,
    p.notes,
    p.generated_by_admin_id,
    a.username as generated_by_admin_name,
    p.generated_at,
    p.viewed_by_teacher_at,
    CASE WHEN p.viewed_by_teacher_at IS NOT NULL THEN 1 ELSE 0 END as was_viewed,
    -- Cálculos auxiliares
    CONCAT(FLOOR(p.worked_minutes / 60), 'h ', LPAD(p.worked_minutes % 60, 2, '0'), 'm') as worked_formatted,
    CONCAT(FLOOR(p.expected_minutes / 60), 'h ', LPAD(p.expected_minutes % 60, 2, '0'), 'm') as expected_formatted,
    CONCAT(FLOOR(p.overtime_minutes / 60), 'h ', LPAD(p.overtime_minutes % 60, 2, '0'), 'm') as overtime_formatted,
    CONCAT(FLOOR(p.deficit_minutes / 60), 'h ', LPAD(p.deficit_minutes % 60, 2, '0'), 'm') as deficit_formatted
FROM payslips p
INNER JOIN teachers t ON t.id = p.teacher_id
LEFT JOIN admins a ON a.id = p.generated_by_admin_id
ORDER BY p.reference_month DESC, t.name ASC;

-- Procedure para gerar holerite de um colaborador em um mês específico
DELIMITER //

DROP PROCEDURE IF EXISTS generate_payslip//
CREATE PROCEDURE generate_payslip(
    IN p_teacher_id INT,
    IN p_month DATE,
    IN p_admin_id INT
)
BEGIN
    DECLARE v_base_salary DECIMAL(10,2);
    DECLARE v_worked_minutes INT;
    DECLARE v_expected_minutes INT;
    DECLARE v_overtime_minutes INT;
    DECLARE v_deficit_minutes INT;
    DECLARE v_overtime_value DECIMAL(10,2);
    DECLARE v_discount_value DECIMAL(10,2);
    DECLARE v_gross_total DECIMAL(10,2);
    DECLARE v_net_total DECIMAL(10,2);
    DECLARE v_minute_value DECIMAL(10,4);
    
    -- Busca salário base
    SELECT base_salary INTO v_base_salary
    FROM teachers
    WHERE id = p_teacher_id;
    
    IF v_base_salary IS NULL THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Colaborador não encontrado';
    END IF;
    
    -- A instituição NÃO paga hora extra nem desconta déficit automaticamente.
    -- O holerite considera apenas o salário base; horas extras/déficit ficam zerados.
    -- Horas trabalhadas/esperadas são informativas e não são calculadas aqui.
    SET v_worked_minutes = 0;
    SET v_expected_minutes = 0;
    SET v_overtime_minutes = 0;
    SET v_deficit_minutes = 0;
    SET v_minute_value = 0;
    SET v_overtime_value = 0;
    SET v_discount_value = 0;
    SET v_gross_total = v_base_salary;
    SET v_net_total = v_base_salary;
    
    -- Insere ou atualiza holerite
    INSERT INTO payslips (
        teacher_id, reference_month, base_salary,
        worked_minutes, expected_minutes, overtime_minutes,
        overtime_value, deficit_minutes, discount_value,
        gross_total, net_total, generated_by_admin_id
    ) VALUES (
        p_teacher_id, p_month, v_base_salary,
        v_worked_minutes, v_expected_minutes, v_overtime_minutes,
        v_overtime_value, v_deficit_minutes, v_discount_value,
        v_gross_total, v_net_total, p_admin_id
    )
    ON DUPLICATE KEY UPDATE
        base_salary = VALUES(base_salary),
        worked_minutes = VALUES(worked_minutes),
        expected_minutes = VALUES(expected_minutes),
        overtime_minutes = VALUES(overtime_minutes),
        overtime_value = VALUES(overtime_value),
        deficit_minutes = VALUES(deficit_minutes),
        discount_value = VALUES(discount_value),
        gross_total = VALUES(gross_total),
        net_total = VALUES(net_total),
        generated_by_admin_id = VALUES(generated_by_admin_id),
        generated_at = CURRENT_TIMESTAMP;
    
    SELECT 'Holerite gerado com sucesso!' as message;
END//

DELIMITER ;

-- ========================================================
-- SUMÁRIO DA MIGRAÇÃO
-- ========================================================
-- 1. Tabela 'payslips':
--    - Holerites mensais por colaborador
--    - Cálculo automático de extras e descontos
--    - Registro de visualização pelo colaborador
--
-- 2. Tabela 'payslip_items':
--    - Itens adicionais (benefícios, outras deduções)
--    - Flexibilidade para proventos/descontos customizados
--
-- 3. View 'v_payslips_full':
--    - Consulta facilitada com dados do colaborador
--    - Formatação de horas (Xh Ym)
--    - Indicador de visualização
--
-- 4. Procedure 'generate_payslip':
--    - Geração automática de holerite
--    - Cálculo baseado em horas trabalhadas
--    - Atualização se já existir
--
-- IMPORTANTE: A procedure generate_payslip tem lógica de exemplo.
-- Deve ser integrada com o sistema real de cálculo de horas.
-- ========================================================

SELECT '✓ Sistema de holerites instalado com sucesso!' AS status,
       'Tabela payslips criada' AS info,
       'Use generate_payslip(teacher_id, month, admin_id) para gerar holerites' AS tip;


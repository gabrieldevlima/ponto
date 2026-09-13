-- Holerite sem hora extra e sem desconto automatico de deficit.
-- A instituicao nao paga hora extra nem desconta deficit do salario, entao a
-- procedure generate_payslip passa a gerar SEMPRE: extras=0, deficit=0 e
-- liquido = salario base. Colunas de payslips sao mantidas (zeradas) para
-- compatibilidade e auditoria. Nenhum dado e apagado.
--
-- IMPORTANTE: o runner de migracoes nao entende DELIMITER e tem fallback que faz
-- split por ';'. Por isso a procedure usa CORPO DE STATEMENT UNICO (INSERT ...
-- SELECT ... ON DUPLICATE KEY UPDATE), sem BEGIN/END e sem ';' interno. Assim o
-- arquivo tem exatamente dois statements e sobrevive a ambos os caminhos.
-- Comentarios nao podem conter ';' (o runner faz split por ';').

DROP PROCEDURE IF EXISTS generate_payslip;

CREATE PROCEDURE generate_payslip(IN p_teacher_id INT, IN p_month DATE, IN p_admin_id INT)
INSERT INTO payslips (teacher_id, reference_month, base_salary, worked_minutes, expected_minutes,
    overtime_minutes, overtime_value, deficit_minutes, discount_value, gross_total, net_total, generated_by_admin_id)
SELECT t.id, p_month, t.base_salary, 0, 0, 0, 0, 0, 0, t.base_salary, t.base_salary, p_admin_id
FROM teachers t
WHERE t.id = p_teacher_id
ON DUPLICATE KEY UPDATE
    base_salary = VALUES(base_salary),
    worked_minutes = 0,
    expected_minutes = 0,
    overtime_minutes = 0,
    overtime_value = 0,
    deficit_minutes = 0,
    discount_value = 0,
    gross_total = VALUES(gross_total),
    net_total = VALUES(net_total),
    generated_by_admin_id = VALUES(generated_by_admin_id),
    generated_at = CURRENT_TIMESTAMP;

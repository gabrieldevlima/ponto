-- =====================================================================
-- Seed para Lighthouse CI
-- =====================================================================
-- Cria 1 escola, 1 admin de rede e 1 colaborador básicos para que o lhci
-- consiga renderizar páginas autenticadas durante o workflow do GitHub.
--
-- ⚠️  ATENÇÃO: Este arquivo NÃO deve rodar em produção. É consumido apenas
-- pelo .github/workflows/a11y.yml após `mysql ... ponto < sql/install/install.sql`.
--
-- Senha do admin lhci: "lhci-test-2025"
-- Hash gerado via: php -r "echo password_hash('lhci-test-2025', PASSWORD_DEFAULT);"
--
-- Guard de produção: este SELECT vai FALHAR (Error 1305: PROCEDURE not found ou
-- Erro de sintaxe controlado) se o hostname do MySQL contiver "prod". Use isso
-- como circuit-breaker — adicione "prod" ao hostname do servidor de produção
-- para impedir execução acidental do seed.
-- =====================================================================

-- Bloqueia execução se hostname contiver 'prod' (case-insensitive).
-- Em CI/dev, este SELECT retorna 'OK' silenciosamente.
-- Em prod (hostname com 'prod'), provoca SIGNAL SQLSTATE → execução aborta.
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS _lhci_seed_guard()
BEGIN
    DECLARE host VARCHAR(255);
    SELECT @@hostname INTO host;
    IF host LIKE '%prod%' OR host LIKE '%production%' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOQUEADO: lhci_seed.sql não pode rodar em ambiente de produção (hostname contém "prod")';
    END IF;
END$$
DELIMITER ;

CALL _lhci_seed_guard();
DROP PROCEDURE _lhci_seed_guard;


-- Escola de teste
INSERT INTO schools (id, name, code, active) VALUES
    (9999, 'Escola CI Lighthouse', 'LHCI-TEST', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Admin de rede para o lhci (CPF e username = '00000000000')
-- Hash bcrypt válido para "lhci-test-2025" (cost=10)
INSERT INTO admins (username, name, cpf, password_hash, role, school_id) VALUES
    ('00000000000', 'CI Lighthouse', '00000000000',
     '$2y$10$O4QcJDxr9pJ2KcMMoSbEiun53z5v9ZCj0XJ6kPWWBp3DZAhEvlvAq',
     'network_admin', NULL)
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role);

-- Tipo de colaborador básico (se a tabela existir)
INSERT INTO collaborator_types (id, name, slug, schedule_mode, requires_schedule) VALUES
    (9999, 'Teste CI', 'lhci-test', 'time', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Colaborador de teste (CPF de teste válido por checksum: 11144477735)
INSERT INTO teachers (id, name, cpf, pin_hash, type_id, base_salary, network_wide, active) VALUES
    (9999, 'Colaborador CI Test', '11144477735',
     '$2y$10$O4QcJDxr9pJ2KcMMoSbEiun53z5v9ZCj0XJ6kPWWBp3DZAhEvlvAq',
     9999, 1500.00, 0, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Vincula colaborador à escola CI
INSERT IGNORE INTO teacher_schools (teacher_id, school_id) VALUES (9999, 9999);

-- Tipo de licença básico
INSERT INTO leave_types (id, name, code, paid, affects_bank, active) VALUES
    (9999, 'Licença Médica CI', 'CI-MED', 1, 0, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Motivo manual básico (para attendance_manual)
INSERT INTO manual_reasons (id, name, active, sort_order) VALUES
    (9999, 'Esquecimento (CI test)', 1, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name);

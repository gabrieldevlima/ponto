-- ========================================================
-- SISTEMA DE CALENDÁRIO E EXCEÇÕES
-- ========================================================
-- Gerenciamento completo de feriados, dias letivos, calendário acadêmico
-- Suporte a feriados móveis, recorrência anual e exceções por escola
-- ========================================================

USE ponto;

-- Tabela de exceções de calendário (feriados, sábados letivos, etc)
CREATE TABLE IF NOT EXISTS calendar_exceptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NULL COMMENT 'NULL = toda a rede, senão específico da escola',
  date DATE NOT NULL,
  type ENUM('holiday','workday','compensation','academic_event','exam_day','recess') NOT NULL DEFAULT 'holiday',
  name VARCHAR(150) NOT NULL COMMENT 'Nome do feriado/evento',
  description TEXT NULL COMMENT 'Descrição detalhada',
  recurrence ENUM('none','yearly','biannual') NOT NULL DEFAULT 'none' COMMENT 'Recorrência',
  is_working_day TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = dia de trabalho (ex: sábado letivo)',
  reflects_weekday TINYINT NULL COMMENT 'Dia da semana referenciado (0=dom..6=sáb) para type=workday',
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_calendar_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_calendar_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  UNIQUE KEY uq_school_date_type (school_id, date, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Exceções de calendário: feriados, dias letivos especiais';

CREATE INDEX idx_calendar_date ON calendar_exceptions(date);
CREATE INDEX idx_calendar_school ON calendar_exceptions(school_id);
CREATE INDEX idx_calendar_type ON calendar_exceptions(type);

-- Tabela de calendário acadêmico (semestres)
CREATE TABLE IF NOT EXISTS academic_calendar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL COMMENT 'Escola específica',
  year INT NOT NULL COMMENT 'Ano letivo',
  semester TINYINT NOT NULL COMMENT '1 ou 2',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  description TEXT NULL,
  created_by_admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_academic_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
  CONSTRAINT fk_academic_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  UNIQUE KEY uq_school_year_semester (school_id, year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Calendário acadêmico por semestre';

CREATE INDEX idx_academic_school ON academic_calendar(school_id);
CREATE INDEX idx_academic_year ON academic_calendar(year);

-- Tabela de feriados móveis (Páscoa, Carnaval, Corpus Christi)
CREATE TABLE IF NOT EXISTS mobile_holidays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL COMMENT 'Nome do feriado móvel',
  calculation_rule TEXT NULL COMMENT 'Descrição de como é calculado',
  days_offset INT NOT NULL COMMENT 'Offset em dias a partir da Páscoa (0=Páscoa)',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Definição de feriados móveis';

-- Feriados móveis padrão do Brasil
INSERT INTO mobile_holidays (name, calculation_rule, days_offset, is_active) VALUES
('Páscoa', 'Domingo de Páscoa (algoritmo de Computus)', 0, 1),
('Carnaval', '47 dias antes da Páscoa (terça-feira)', -47, 1),
('Sexta-feira Santa', '2 dias antes da Páscoa', -2, 1),
('Corpus Christi', '60 dias após a Páscoa (quinta-feira)', 60, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Feriados nacionais fixos do Brasil
INSERT INTO calendar_exceptions (school_id, date, type, name, recurrence, is_working_day) VALUES
(NULL, '2025-01-01', 'holiday', 'Confraternização Universal', 'yearly', 0),
(NULL, '2025-04-21', 'holiday', 'Tiradentes', 'yearly', 0),
(NULL, '2025-05-01', 'holiday', 'Dia do Trabalho', 'yearly', 0),
(NULL, '2025-09-07', 'holiday', 'Independência do Brasil', 'yearly', 0),
(NULL, '2025-10-12', 'holiday', 'Nossa Senhora Aparecida', 'yearly', 0),
(NULL, '2025-11-02', 'holiday', 'Finados', 'yearly', 0),
(NULL, '2025-11-15', 'holiday', 'Proclamação da República', 'yearly', 0),
(NULL, '2025-11-20', 'holiday', 'Dia da Consciência Negra', 'yearly', 0),
(NULL, '2025-12-25', 'holiday', 'Natal', 'yearly', 0)
ON DUPLICATE KEY UPDATE name=VALUES(name), recurrence=VALUES(recurrence);

-- View para facilitar consulta de exceções
CREATE OR REPLACE VIEW v_calendar_full AS
SELECT 
    ce.id,
    ce.date,
    ce.type,
    ce.name,
    ce.description,
    ce.is_working_day,
    ce.recurrence,
    ce.school_id,
    s.name as school_name,
    CASE 
        WHEN ce.school_id IS NULL THEN 'Toda a rede'
        ELSE s.name
    END as scope,
    DAYNAME(ce.date) as day_of_week,
    DATE_FORMAT(ce.date, '%d/%m/%Y') as date_formatted
FROM calendar_exceptions ce
LEFT JOIN schools s ON s.id = ce.school_id
ORDER BY ce.date DESC;

-- Função auxiliar: Calcular Páscoa (algoritmo de Computus de Gauss)
DELIMITER //

DROP FUNCTION IF EXISTS calculate_easter//
CREATE FUNCTION calculate_easter(year INT) RETURNS DATE
DETERMINISTIC
BEGIN
    DECLARE a, b, c, d, e, f, g, h, i, k, l, m, month, day INT;
    
    SET a = year % 19;
    SET b = FLOOR(year / 100);
    SET c = year % 100;
    SET d = FLOOR(b / 4);
    SET e = b % 4;
    SET f = FLOOR((b + 8) / 25);
    SET g = FLOOR((b - f + 1) / 3);
    SET h = (19 * a + b - d - g + 15) % 30;
    SET i = FLOOR(c / 4);
    SET k = c % 4;
    SET l = (32 + 2 * e + 2 * i - h - k) % 7;
    SET m = FLOOR((a + 11 * h + 22 * l) / 451);
    SET month = FLOOR((h + l - 7 * m + 114) / 31);
    SET day = ((h + l - 7 * m + 114) % 31) + 1;
    
    RETURN STR_TO_DATE(CONCAT(year, '-', LPAD(month, 2, '0'), '-', LPAD(day, 2, '0')), '%Y-%m-%d');
END//

DELIMITER ;

-- Procedure para gerar feriados móveis de um ano
DELIMITER //

DROP PROCEDURE IF EXISTS generate_mobile_holidays//
CREATE PROCEDURE generate_mobile_holidays(IN target_year INT)
BEGIN
    DECLARE easter_date DATE;
    DECLARE done INT DEFAULT 0;
    DECLARE holiday_name VARCHAR(100);
    DECLARE offset_days INT;
    DECLARE holiday_date DATE;
    
    DECLARE cur CURSOR FOR 
        SELECT name, days_offset FROM mobile_holidays WHERE is_active = 1;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
    
    -- Calcula data da Páscoa
    SET easter_date = calculate_easter(target_year);
    
    -- Percorre todos os feriados móveis ativos
    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO holiday_name, offset_days;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        SET holiday_date = DATE_ADD(easter_date, INTERVAL offset_days DAY);
        
        -- Insere ou atualiza o feriado
        INSERT INTO calendar_exceptions (school_id, date, type, name, recurrence, is_working_day)
        VALUES (NULL, holiday_date, 'holiday', holiday_name, 'yearly', 0)
        ON DUPLICATE KEY UPDATE name = VALUES(name);
        
    END LOOP;
    CLOSE cur;
    
    SELECT CONCAT('Feriados móveis de ', target_year, ' gerados com sucesso!') as message;
END//

DELIMITER ;

-- Gera feriados móveis para 2025 e 2026
CALL generate_mobile_holidays(2025);
CALL generate_mobile_holidays(2026);

-- Helper function: Verificar se uma data é dia útil
DELIMITER //

DROP FUNCTION IF EXISTS is_working_day//
CREATE FUNCTION is_working_day(check_date DATE, check_school_id INT) RETURNS TINYINT(1)
DETERMINISTIC
BEGIN
    DECLARE exception_count INT;
    DECLARE is_workday INT;
    DECLARE day_of_week INT;
    
    -- Verifica dia da semana (0=domingo, 6=sábado)
    SET day_of_week = DAYOFWEEK(check_date) - 1;
    
    -- Verifica se há exceção cadastrada
    SELECT COUNT(*), MAX(is_working_day) INTO exception_count, is_workday
    FROM calendar_exceptions
    WHERE date = check_date
      AND (school_id IS NULL OR school_id = check_school_id);
    
    IF exception_count > 0 THEN
        -- Há exceção: se is_working_day=1 é dia útil, senão é feriado
        RETURN is_workday;
    END IF;
    
    -- Sem exceção: seg-sex são úteis, sab-dom não são
    IF day_of_week >= 1 AND day_of_week <= 5 THEN
        RETURN 1;
    ELSE
        RETURN 0;
    END IF;
END//

DELIMITER ;

-- ========================================================
-- SUMÁRIO DA MIGRAÇÃO
-- ========================================================
-- 1. Tabela 'calendar_exceptions':
--    - Feriados, sábados letivos, eventos acadêmicos
--    - Suporte a recorrência anual
--    - Por escola ou toda a rede
--
-- 2. Tabela 'academic_calendar':
--    - Calendário acadêmico semestral
--    - Datas de início e fim de período letivo
--
-- 3. Tabela 'mobile_holidays':
--    - Definição de feriados móveis (Páscoa, Carnaval, etc)
--    - Cálculo automático baseado na Páscoa
--
-- 4. Funções e Procedures:
--    - calculate_easter(): Calcula data da Páscoa (Computus)
--    - generate_mobile_holidays(): Gera feriados móveis de um ano
--    - is_working_day(): Verifica se data é dia útil
--
-- 5. View 'v_calendar_full':
--    - Consulta facilitada de todas as exceções
--
-- 6. Dados iniciais:
--    - Feriados nacionais do Brasil inseridos
--    - Feriados móveis de 2025 e 2026 gerados
-- ========================================================

SELECT '✓ Sistema de calendário instalado com sucesso!' AS status,
       'Feriados nacionais e móveis cadastrados' AS info,
       'Use generate_mobile_holidays(ano) para gerar feriados de outros anos' AS tip;


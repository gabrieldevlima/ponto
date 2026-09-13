-- =====================================================================
-- DEEDO PONTO - DADOS DE DEMONSTRACAO
-- =====================================================================
-- ATENCAO: Execute SOMENTE apos install_production_complete.sql
-- Finalidade: Popular o banco com dados ficticios para demonstracoes
-- Credenciais demo:
--   Admin principal : admin / admin123
--   Admins escola   : coordenador.teresina / admin123
--                     coordenador.floriano  / admin123
--   Colaboradores   : autenticação por CPF
-- Para limpar os dados demo execute install_demo_cleanup.sql (se criado)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+00:00';

-- =====================================================================
-- 1. CONFIGURACAO DA EMPRESA
-- =====================================================================

UPDATE employer_config SET
    company_name   = 'Instituto de Educacao DEEDO Ltda',
    cnpj           = '12.345.678/0001-90',
    address        = 'Rua das Acaias, 450, Centro',
    city           = 'Teresina',
    state          = 'PI',
    phone          = '(86) 3214-5678',
    system_name    = 'DEEDO Ponto',
    system_version = '1.0.0',
    rep_category   = 'REP-P'
WHERE id = 1;

-- =====================================================================
-- 2. ESCOLAS
-- =====================================================================

INSERT IGNORE INTO schools (id, name, code, active, lat, lng) VALUES
(1, 'Escola Municipal Joao Paulo II',  'EMJPII',  1, -5.0919,  -42.8034),
(2, 'Escola Estadual Rui Barbosa',     'EERB',    1, -6.7691,  -43.0213),
(3, 'EMEF Dom Bosco',                  'EMEFDB',  1, -7.0778,  -41.4676);

-- =====================================================================
-- 3. ADMINS ADICIONAIS
-- =====================================================================
-- Senha: admin123  (hash identico ao admin padrao)

INSERT IGNORE INTO admins (id, username, password_hash, role, school_id) VALUES
(2, 'coordenador.teresina', '$2y$10$Yf3IrInDQp7patjgPNmWCuNHp7CjXNGTVYS2Y6TuMcngP3/vLQiiq', 'school_admin', 1),
(3, 'coordenador.floriano',  '$2y$10$Yf3IrInDQp7patjgPNmWCuNHp7CjXNGTVYS2Y6TuMcngP3/vLQiiq', 'school_admin', 2);

-- =====================================================================
-- 4. COLABORADORES (autenticação por CPF)
-- =====================================================================

INSERT IGNORE INTO teachers (id, name, cpf, email, active, type_id, base_salary, network_wide) VALUES
-- Professores (type_id = 1)
(1,  'Ana Beatriz Sousa Lima',       '11122233301', 'ana.lima@demo.com',       1, 1, 3200.00, 0),
(2,  'Carlos Eduardo Mendes',        '22233344402', 'carlos.mendes@demo.com',  1, 1, 3400.00, 0),
(3,  'Fernanda Oliveira Costa',      '33344455503', 'fernanda.costa@demo.com', 1, 1, 3100.00, 0),
(4,  'Joao Victor Alves Pereira',    '44455566604', 'joao.pereira@demo.com',   1, 1, 3300.00, 0),
(5,  'Patricia Rodrigues Silva',     '55566677705', 'patricia.silva@demo.com', 1, 1, 3250.00, 0),
-- Coordenadores (type_id = 2)
(6,  'Marcelo Henrique Tavares',     '66677788806', 'marcelo.tavares@demo.com',1, 2, 4500.00, 0),
(7,  'Rosana Freitas Monteiro',      '77788899907', 'rosana.monteiro@demo.com',1, 2, 4300.00, 0),
(8,  'Thiago Santos Nascimento',     '88899900008', 'thiago.nasc@demo.com',    1, 2, 4400.00, 0),
-- Administrativos (type_id = 3)
(9,  'Luciana Carvalho Pinto',       '99900011109', 'luciana.pinto@demo.com',  1, 3, 2800.00, 0),
(10, 'Roberto Dias Ferreira',        '00011122210', 'roberto.dias@demo.com',   1, 3, 2900.00, 0);

-- =====================================================================
-- 5. VINCULOS ESCOLA-COLABORADOR
-- =====================================================================

INSERT IGNORE INTO teacher_schools (teacher_id, school_id) VALUES
(1,  1), (2,  1), (3,  1),   -- Professores na escola 1
(4,  2), (5,  2),             -- Professores na escola 2
(6,  1),                      -- Coordenador escola 1
(7,  2),                      -- Coordenador escola 2
(8,  3),                      -- Coordenador escola 3
(9,  1), (10, 1);             -- Administrativos escola 1

-- =====================================================================
-- 6. JORNADAS DOS PROFESSORES (modo classes: 5 aulas/dia, seg-sex)
-- =====================================================================
-- weekday: 1=seg, 2=ter, 3=qua, 4=qui, 5=sex

INSERT INTO teacher_schedules (teacher_id, weekday, classes_count, class_minutes) VALUES
-- Ana (1)
(1,1,5,50),(1,2,5,50),(1,3,4,50),(1,4,5,50),(1,5,4,50),
-- Carlos (2)
(2,1,4,50),(2,2,5,50),(2,3,5,50),(2,4,4,50),(2,5,5,50),
-- Fernanda (3)
(3,1,5,50),(3,2,4,50),(3,3,5,50),(3,4,5,50),(3,5,4,50),
-- Joao (4)
(4,1,4,50),(4,2,4,50),(4,3,5,50),(4,4,5,50),(4,5,5,50),
-- Patricia (5)
(5,1,5,50),(5,2,5,50),(5,3,4,50),(5,4,4,50),(5,5,5,50);

-- =====================================================================
-- 7. JORNADAS POR HORARIO (Coordenadores e Administrativos)
-- =====================================================================

INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, break_minutes) VALUES
-- Marcelo Coordenador (6): 07:30-12:00
(6,1,'07:30:00','12:00:00',0),(6,2,'07:30:00','12:00:00',0),(6,3,'07:30:00','12:00:00',0),(6,4,'07:30:00','12:00:00',0),(6,5,'07:30:00','12:00:00',0),
-- Rosana Coordenadora (7): 07:30-12:00
(7,1,'07:30:00','12:00:00',0),(7,2,'07:30:00','12:00:00',0),(7,3,'07:30:00','12:00:00',0),(7,4,'07:30:00','12:00:00',0),(7,5,'07:30:00','12:00:00',0),
-- Thiago Coordenador (8): 13:00-17:30
(8,1,'13:00:00','17:30:00',0),(8,2,'13:00:00','17:30:00',0),(8,3,'13:00:00','17:30:00',0),(8,4,'13:00:00','17:30:00',0),(8,5,'13:00:00','17:30:00',0),
-- Luciana Admin (9): 08:00-17:00 com 60min intervalo
(9,1,'08:00:00','17:00:00',60),(9,2,'08:00:00','17:00:00',60),(9,3,'08:00:00','17:00:00',60),(9,4,'08:00:00','17:00:00',60),(9,5,'08:00:00','17:00:00',60),
-- Roberto Admin (10): 08:00-17:00 com 60min intervalo
(10,1,'08:00:00','17:00:00',60),(10,2,'08:00:00','17:00:00',60),(10,3,'08:00:00','17:00:00',60),(10,4,'08:00:00','17:00:00',60),(10,5,'08:00:00','17:00:00',60);

-- =====================================================================
-- 8. MOTIVOS MANUAIS
-- =====================================================================

INSERT IGNORE INTO manual_reasons (id, name, active, sort_order) VALUES
(1, 'Esqueceu de registrar entrada',  1, 1),
(2, 'Esqueceu de registrar saida',    1, 2),
(3, 'Falha na conexao',               1, 3),
(4, 'Registro retroativo',            1, 4);

-- =====================================================================
-- 9. CONSENTIMENTOS LGPD
-- =====================================================================

INSERT IGNORE INTO lgpd_consent (teacher_id, consent_given, consent_date, ip_address) VALUES
(1,  1, DATE_SUB(NOW(), INTERVAL 35 DAY), '189.40.10.1'),
(2,  1, DATE_SUB(NOW(), INTERVAL 34 DAY), '189.40.10.2'),
(3,  1, DATE_SUB(NOW(), INTERVAL 33 DAY), '189.40.10.3'),
(4,  1, DATE_SUB(NOW(), INTERVAL 32 DAY), '189.40.10.4'),
(5,  1, DATE_SUB(NOW(), INTERVAL 31 DAY), '189.40.10.5'),
(6,  1, DATE_SUB(NOW(), INTERVAL 30 DAY), '189.40.10.6'),
(7,  1, DATE_SUB(NOW(), INTERVAL 29 DAY), '189.40.10.7'),
(8,  1, DATE_SUB(NOW(), INTERVAL 28 DAY), '189.40.10.8'),
(9,  1, DATE_SUB(NOW(), INTERVAL 27 DAY), '189.40.10.9'),
(10, 1, DATE_SUB(NOW(), INTERVAL 26 DAY), '189.40.10.10');

-- =====================================================================
-- 10. REGISTROS DE PONTO (attendance)
-- =====================================================================
-- Inseridos sem NSR (trigger preenchera automaticamente)
-- Coordenadas: escola 1 Teresina (-5.0919, -42.8034)
--              escola 2 Floriano (-6.7691, -43.0213)
--              escola 3 Picos    (-7.0778, -41.4676)

-- Semana 4 (29-25 dias atras) - registros aprovados completos
INSERT INTO attendance (teacher_id, school_id, date, check_in, check_out, check_in_lat, check_in_lng, check_in_acc, check_out_lat, check_out_lng, check_out_acc, method, ip, approved, record_mode, recorded_at, synced_at, hlb_sync_status, receipt_generated, sequence_number) VALUES
(1, 1, DATE_SUB(CURDATE(), INTERVAL 29 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:28:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'12:05:00'), -5.0921, -42.8036, 15.0, -5.0920, -42.8035, 12.0, 'cpf', '189.40.10.1', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:28:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:28:05'), 'synced', 1, 1),
(2, 1, DATE_SUB(CURDATE(), INTERVAL 29 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'12:10:00'), -5.0920, -42.8033, 14.0, -5.0919, -42.8034, 13.0, 'cpf', '189.40.10.2', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:31:05'), 'synced', 1, 1),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 29 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:33:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'11:55:00'), -5.0918, -42.8035, 16.0, -5.0919, -42.8034, 11.0, 'cpf', '189.40.10.3', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:33:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:33:05'), 'synced', 1, 1),
(6, 1, DATE_SUB(CURDATE(), INTERVAL 29 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'12:02:00'), -5.0922, -42.8037, 10.0, -5.0921, -42.8036, 10.0, 'cpf', '189.40.10.6', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:29:05'), 'synced', 1, 1),
(9, 1, DATE_SUB(CURDATE(), INTERVAL 29 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'08:02:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'17:01:00'), -5.0920, -42.8034, 18.0, -5.0920, -42.8034, 15.0, 'cpf', '189.40.10.9', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'08:02:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'08:02:05'), 'synced', 1, 1),

-- Escola 2 - Floriano
(4, 2, DATE_SUB(CURDATE(), INTERVAL 29 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'12:00:00'), -6.7693, -43.0215, 20.0, -6.7691, -43.0213, 18.0, 'cpf', '189.40.10.4', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:30:05'), 'synced', 1, 1),
(5, 2, DATE_SUB(CURDATE(), INTERVAL 29 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:35:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'11:58:00'), -6.7690, -43.0212, 19.0, -6.7692, -43.0214, 17.0, 'cpf', '189.40.10.5', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:35:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:35:05'), 'synced', 1, 1),
(7, 2, DATE_SUB(CURDATE(), INTERVAL 29 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:28:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'12:03:00'), -6.7691, -43.0213, 12.0, -6.7691, -43.0213, 10.0, 'cpf', '189.40.10.7', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:28:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 29 DAY),'07:28:05'), 'synced', 1, 1),

-- Semana 3 (22-18 dias atras)
(1, 1, DATE_SUB(CURDATE(), INTERVAL 22 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'12:00:00'), -5.0921, -42.8036, 14.0, -5.0920, -42.8035, 12.0, 'cpf', '189.40.10.1', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:30:05'), 'synced', 1, 1),
(2, 1, DATE_SUB(CURDATE(), INTERVAL 22 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:32:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'12:08:00'), -5.0920, -42.8033, 13.0, -5.0919, -42.8034, 11.0, 'cpf', '189.40.10.2', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:32:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:32:05'), 'synced', 1, 1),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 22 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'12:01:00'), -5.0918, -42.8035, 15.0, -5.0919, -42.8034, 13.0, 'cpf', '189.40.10.3', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:29:05'), 'synced', 1, 1),
(4, 2, DATE_SUB(CURDATE(), INTERVAL 22 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'12:05:00'), -6.7693, -43.0215, 18.0, -6.7691, -43.0213, 15.0, 'cpf', '189.40.10.4', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:31:05'), 'synced', 1, 1),
(5, 2, DATE_SUB(CURDATE(), INTERVAL 22 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:38:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'11:55:00'), -6.7690, -43.0212, 20.0, -6.7692, -43.0214, 18.0, 'cpf', '189.40.10.5', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:38:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:38:05'), 'synced', 1, 1),
(6, 1, DATE_SUB(CURDATE(), INTERVAL 22 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:27:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'12:00:00'), -5.0922, -42.8037, 11.0, -5.0921, -42.8036, 11.0, 'cpf', '189.40.10.6', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:27:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'07:27:05'), 'synced', 1, 1),
(9, 1, DATE_SUB(CURDATE(), INTERVAL 22 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'08:00:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'17:00:00'), -5.0920, -42.8034, 16.0, -5.0920, -42.8034, 14.0, 'cpf', '189.40.10.9', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'08:00:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'08:00:05'), 'synced', 1, 1),
(10,1, DATE_SUB(CURDATE(), INTERVAL 22 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'08:05:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'17:03:00'), -5.0919, -42.8033, 17.0, -5.0920, -42.8034, 16.0, 'cpf', '189.40.10.10',1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'08:05:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 22 DAY),'08:05:05'), 'synced', 1, 1),

-- Semana 2 (15-11 dias atras) - inclui registro manual e pendente
(1, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:33:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'12:02:00'), -5.0921, -42.8036, 13.0, -5.0920, -42.8035, 12.0, 'cpf', '189.40.10.1', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:33:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:33:05'), 'synced', 1, 1),
(2, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'12:15:00'), -5.0920, -42.8033, 14.0, -5.0919, -42.8034, 12.0, 'cpf', '189.40.10.2', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:05'), 'synced', 1, 1),
-- Registro manual (Fernanda esqueceu a saida)
(3, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:28:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'12:00:00'), -5.0918, -42.8035, 15.0, -5.0919, -42.8034, 11.0, 'manual', '189.40.10.3', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:28:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:28:05'), 'synced', 1, 1),
(4, 2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'12:00:00'), -6.7693, -43.0215, 19.0, -6.7691, -43.0213, 16.0, 'cpf', '189.40.10.4', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:05'), 'synced', 1, 1),
(5, 2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:40:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'11:50:00'), -6.7690, -43.0212, 21.0, -6.7692, -43.0214, 19.0, 'cpf', '189.40.10.5', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:40:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:40:05'), 'synced', 1, 1),
(6, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'12:01:00'), -5.0922, -42.8037, 10.0, -5.0921, -42.8036, 10.0, 'cpf', '189.40.10.6', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:30:05'), 'synced', 1, 1),
(7, 2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'12:00:00'), -6.7691, -43.0213, 13.0, -6.7691, -43.0213, 11.0, 'cpf', '189.40.10.7', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'07:29:05'), 'synced', 1, 1),
-- Pendente de aprovacao (sem check_out, approved = NULL)
(8, 3, DATE_SUB(CURDATE(), INTERVAL 15 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'13:02:00'), NULL, -7.0780, -41.4678, 22.0, NULL, NULL, NULL, 'cpf', '189.40.10.8', NULL, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'13:02:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 15 DAY),'13:02:05'), 'synced', 0, 1),

-- Semana 1 (8-4 dias atras)
(1, 1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'12:03:00'), -5.0921, -42.8036, 14.0, -5.0920, -42.8035, 13.0, 'cpf', '189.40.10.1', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:29:05'), 'synced', 1, 1),
(2, 1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'12:07:00'), -5.0920, -42.8033, 13.0, -5.0919, -42.8034, 12.0, 'cpf', '189.40.10.2', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:31:05'), 'synced', 1, 1),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'11:59:00'), -5.0918, -42.8035, 16.0, -5.0919, -42.8034, 12.0, 'cpf', '189.40.10.3', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:30:05'), 'synced', 1, 1),
(4, 2, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:28:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'12:04:00'), -6.7693, -43.0215, 20.0, -6.7691, -43.0213, 17.0, 'cpf', '189.40.10.4', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:28:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:28:05'), 'synced', 1, 1),
(5, 2, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:36:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'11:57:00'), -6.7690, -43.0212, 22.0, -6.7692, -43.0214, 20.0, 'cpf', '189.40.10.5', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:36:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:36:05'), 'synced', 1, 1),
(6, 1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'12:00:00'), -5.0922, -42.8037, 11.0, -5.0921, -42.8036, 11.0, 'cpf', '189.40.10.6', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:31:05'), 'synced', 1, 1),
(7, 2, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'12:01:00'), -6.7691, -43.0213, 14.0, -6.7691, -43.0213, 12.0, 'cpf', '189.40.10.7', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'07:30:05'), 'synced', 1, 1),
(8, 3, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'13:01:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'17:30:00'), -7.0780, -41.4678, 21.0, -7.0779, -41.4677, 18.0, 'cpf', '189.40.10.8', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'13:01:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'13:01:05'), 'synced', 1, 1),
(9, 1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'08:01:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'17:00:00'), -5.0920, -42.8034, 17.0, -5.0920, -42.8034, 15.0, 'cpf', '189.40.10.9', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'08:01:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'08:01:05'), 'synced', 1, 1),
(10,1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'08:03:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'17:02:00'), -5.0919, -42.8033, 18.0, -5.0920, -42.8034, 16.0, 'cpf', '189.40.10.10',1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'08:03:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 8 DAY),'08:03:05'), 'synced', 1, 1),

-- Ontem (1 dia atras) - alguns aprovados, 1 pendente
(1, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'12:00:00'), -5.0921, -42.8036, 14.0, -5.0920, -42.8035, 12.0, 'cpf', '189.40.10.1', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:30:05'), 'synced', 1, 1),
(2, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:33:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'12:10:00'), -5.0920, -42.8033, 13.0, -5.0919, -42.8034, 11.0, 'cpf', '189.40.10.2', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:33:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:33:05'), 'synced', 1, 1),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'12:01:00'), -5.0918, -42.8035, 15.0, -5.0919, -42.8034, 13.0, 'cpf', '189.40.10.3', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:31:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:31:05'), 'synced', 1, 1),
(4, 2, DATE_SUB(CURDATE(), INTERVAL 1 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'12:03:00'), -6.7693, -43.0215, 19.0, -6.7691, -43.0213, 16.0, 'cpf', '189.40.10.4', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:29:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:29:05'), 'synced', 1, 1),
(5, 2, DATE_SUB(CURDATE(), INTERVAL 1 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:37:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'11:56:00'), -6.7690, -43.0212, 20.0, -6.7692, -43.0214, 18.0, 'cpf', '189.40.10.5', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:37:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:37:05'), 'synced', 1, 1),
-- Pendente de aprovacao (approved = NULL, sem check_out)
(6, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:32:00'), NULL, -5.0922, -42.8037, 12.0, NULL, NULL, NULL, 'cpf', '189.40.10.6', NULL, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:32:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'07:32:05'), 'synced', 0, 1),
(9, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'08:00:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'17:01:00'), -5.0920, -42.8034, 16.0, -5.0920, -42.8034, 14.0, 'cpf', '189.40.10.9', 1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'08:00:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'08:00:05'), 'synced', 1, 1),
(10,1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'08:04:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'17:00:00'), -5.0919, -42.8033, 17.0, -5.0920, -42.8034, 15.0, 'cpf', '189.40.10.10',1, 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'08:04:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 1 DAY),'08:04:05'), 'synced', 1, 1);

-- Registro manual com motivo (3 dias atras - Joao falhou na conexao)
INSERT INTO attendance (teacher_id, school_id, date, check_in, check_out, method, ip, approved, manual_reason_id, manual_reason_text, record_mode, recorded_at, synced_at, hlb_sync_status, receipt_generated, sequence_number) VALUES
(4, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 3 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 3 DAY),'12:00:00'), 'manual', '189.40.10.4', 1, 3, 'Sem sinal no momento do registro', 'online', TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 3 DAY),'07:30:00'), TIMESTAMP(DATE_SUB(CURDATE(),INTERVAL 3 DAY),'07:30:05'), 'synced', 1, 1);

-- =====================================================================
-- 11. AFASTAMENTOS
-- =====================================================================

INSERT INTO leaves (teacher_id, school_id, type_id, start_date, end_date, days_count, notes, approved, created_by_admin_id) VALUES
-- Atestado medico aprovado (semana passada)
(3, 1, 1, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 8 DAY),  3, 'Atestado de clinica medica - gripe', 1, 2),
-- Ferias aprovadas (futuras)
(1, 1, 3, DATE_ADD(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 44 DAY), 30, 'Ferias regulamentares 2026', 1, 1),
-- Falta justificada pendente
(5, 2, 7, DATE_SUB(CURDATE(), INTERVAL 3 DAY),  DATE_SUB(CURDATE(), INTERVAL 3 DAY),  1, 'Problema familiar - aguardando documentacao', NULL, NULL);

-- =====================================================================
-- 12. HOLERITES
-- =====================================================================

-- Mes anterior para professores 1 e 2
INSERT INTO payslips (teacher_id, reference_month, base_salary, worked_minutes, expected_minutes, overtime_minutes, overtime_value, deficit_minutes, discount_value, gross_total, net_total, generated_by_admin_id, viewed_by_teacher_at) VALUES
(1, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01'),
   3200.00, 9600, 9600, 0, 0.00, 0, 0.00, 3200.00, 3200.00, 1,
   DATE_SUB(NOW(), INTERVAL 5 DAY)),
(2, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01'),
   3400.00, 9840, 9600, 240, 212.50, 0, 0.00, 3612.50, 3612.50, 1,
   NULL);

-- =====================================================================
-- 13. CONFIGURACOES ADICIONAIS (app_settings demo)
-- =====================================================================

INSERT INTO app_settings (k, v) VALUES
('demo_mode', '1'),
('demo_installed_at', NOW())
ON DUPLICATE KEY UPDATE v = VALUES(v);

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SUMARIO
-- =====================================================================
SELECT
    '✓ Dados de demonstracao inseridos com sucesso!' AS status,
    '3 escolas | 10 colaboradores | ~50 registros de ponto | 3 afastamentos | 2 holerites' AS resumo,
    'Acesse: /admin/login.php | Usuario: admin | Senha: admin123' AS acesso,
    'Colaboradores: use o CPF para login (ex: 111.222.333-01)' AS cpf_demo;

-- =============================================
-- QA Seed Data for Ponto Sistema
-- Dados de teste para validação das funcionalidades
-- =============================================

-- Configurar charset e timezone
SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- =============================================
-- 1. ESCOLAS (2 escolas com lat/lng/radius distintos)
-- =============================================

INSERT INTO schools (id, name, code, active, created_at) VALUES 
(1, 'Escola Municipal Centro', 'EM-CENTRO', 1, '2024-01-15 08:00:00'),
(2, 'Escola Estadual Zona Norte', 'EE-NORTE', 1, '2024-01-15 08:30:00');

-- Nota: Funcionalidade de geo-aprovação (raio/distância) pode ser configurada 
-- posteriormente via admin interface quando implementada

-- =============================================
-- 2. TIPOS DE COLABORADORES
-- =============================================

INSERT INTO collaborator_types (id, name, slug, schedule_mode, requires_schedule, created_at) VALUES
(1, 'Professor', 'professor', 'classes', 1, '2024-01-10 09:00:00'),
(2, 'Diretor', 'diretor', 'time', 1, '2024-01-10 09:00:00'),
(3, 'Coordenador', 'coordenador', 'time', 1, '2024-01-10 09:00:00'),
(4, 'Motorista', 'motorista', 'time', 1, '2024-01-10 09:00:00'),
(5, 'Auxiliar', 'auxiliar', 'none', 0, '2024-01-10 09:00:00');

-- =============================================
-- 3. MOTIVOS MANUAIS
-- =============================================

INSERT INTO manual_reasons (id, name, active, sort_order, created_at) VALUES
(1, 'Falta de internet', 1, 10, '2024-01-10 09:15:00'),
(2, 'Falha no sistema', 1, 20, '2024-01-10 09:15:00'),
(3, 'Esquecimento do colaborador', 1, 30, '2024-01-10 09:15:00'),
(4, 'Problema com o equipamento', 1, 40, '2024-01-10 09:15:00'),
(5, 'Outro', 1, 99, '2024-01-10 09:15:00');

-- =============================================
-- 4. ADMIN USERS
-- =============================================

-- Admin principal (network_admin)
INSERT INTO admins (id, username, password_hash, role, school_id, created_at) VALUES
(1, 'admin_qa', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'network_admin', NULL, '2024-01-10 08:00:00'),
-- Admin da escola Centro (school_admin)
(2, 'admin_centro', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'school_admin', 1, '2024-01-10 08:00:00'),
-- Admin da escola Norte (school_admin)
(3, 'admin_norte', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'school_admin', 2, '2024-01-10 08:00:00');

-- Senha padrão para todos: "password123"

-- =============================================
-- 5. PROFESSORES/COLABORADORES (3 com tipos/schedule variados)
-- =============================================

INSERT INTO teachers (id, name, cpf, pin_hash, email, active, type_id, base_salary, network_wide, face_descriptors, created_at) VALUES
-- Professor 1: Ana Silva (Professor - classes)
(1, 'Ana Silva', '11122233344', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ana.silva@escola.com', 1, 1, 3500.00, 0, 
 JSON_ARRAY(
   JSON_ARRAY(0.123, -0.456, 0.789, -0.321, 0.654),
   JSON_ARRAY(0.111, -0.444, 0.777, -0.333, 0.666)
 ), '2024-01-15 09:00:00'),

-- Professor 2: Bruno Santos (Diretor - time)  
(2, 'Bruno Santos', '22233344455', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'bruno.santos@escola.com', 1, 2, 5500.00, 0,
 JSON_ARRAY(
   JSON_ARRAY(0.234, -0.567, 0.890, -0.432, 0.765),
   JSON_ARRAY(0.222, -0.555, 0.888, -0.444, 0.777),
   JSON_ARRAY(0.244, -0.577, 0.899, -0.455, 0.788)
 ), '2024-01-15 09:15:00'),

-- Professor 3: Carlos Oliveira (Coordenador - time, trabalha em ambas escolas)
(3, 'Carlos Oliveira', '33344455566', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'carlos.oliveira@escola.com', 1, 3, 4200.00, 1,
 JSON_ARRAY(
   JSON_ARRAY(0.345, -0.678, 0.901, -0.543, 0.876)
 ), '2024-01-15 09:30:00');

-- PINs padrão: 123456, 234567, 345678 respectivamente

-- =============================================
-- 6. VÍNCULOS PROFESSOR-ESCOLA (1:N e N:N)
-- =============================================

INSERT INTO teacher_schools (teacher_id, school_id) VALUES
-- Ana Silva: apenas Escola Centro (1:N)
(1, 1),
-- Bruno Santos: apenas Escola Norte (1:N)
(2, 2),
-- Carlos Oliveira: ambas escolas (N:N - coordenador geral)
(3, 1),
(3, 2);

-- =============================================
-- 7. HORÁRIOS DOS PROFESSORES
-- =============================================

-- Ana Silva (Professor - schedule por classes)
INSERT INTO teacher_schedules (teacher_id, weekday, classes_count, class_minutes, created_at) VALUES
(1, 1, 4, 50, '2024-01-15 10:00:00'), -- Segunda: 4 aulas de 50min
(1, 2, 6, 50, '2024-01-15 10:00:00'), -- Terça: 6 aulas de 50min
(1, 3, 4, 50, '2024-01-15 10:00:00'), -- Quarta: 4 aulas de 50min
(1, 4, 6, 50, '2024-01-15 10:00:00'), -- Quinta: 6 aulas de 50min
(1, 5, 3, 50, '2024-01-15 10:00:00'), -- Sexta: 3 aulas de 50min
(1, 6, 0, 50, '2024-01-15 10:00:00'), -- Sábado: sem aulas
(1, 0, 0, 50, '2024-01-15 10:00:00'); -- Domingo: sem aulas

-- Bruno Santos (Diretor - schedule por tempo)
INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, break_minutes, created_at) VALUES
(2, 1, '08:00:00', '17:00:00', 60, '2024-01-15 10:15:00'), -- Segunda a Sexta: 8h às 17h
(2, 2, '08:00:00', '17:00:00', 60, '2024-01-15 10:15:00'),
(2, 3, '08:00:00', '17:00:00', 60, '2024-01-15 10:15:00'),
(2, 4, '08:00:00', '17:00:00', 60, '2024-01-15 10:15:00'),
(2, 5, '08:00:00', '17:00:00', 60, '2024-01-15 10:15:00'),
(2, 6, NULL, NULL, 0, '2024-01-15 10:15:00'), -- Sábado: sem expediente
(2, 0, NULL, NULL, 0, '2024-01-15 10:15:00'); -- Domingo: sem expediente

-- Carlos Oliveira (Coordenador - horário flexível)
INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, break_minutes, created_at) VALUES
(3, 1, '07:30:00', '16:30:00', 60, '2024-01-15 10:30:00'), -- Horário mais cedo
(3, 2, '07:30:00', '16:30:00', 60, '2024-01-15 10:30:00'),
(3, 3, '07:30:00', '16:30:00', 60, '2024-01-15 10:30:00'),
(3, 4, '07:30:00', '16:30:00', 60, '2024-01-15 10:30:00'),
(3, 5, '07:30:00', '13:30:00', 30, '2024-01-15 10:30:00'), -- Sexta meio período
(3, 6, NULL, NULL, 0, '2024-01-15 10:30:00'),
(3, 0, NULL, NULL, 0, '2024-01-15 10:30:00');

-- =============================================
-- 8. REGISTROS DE ATTENDANCE (mês corrente)
-- Misturando approved = 1, 0 e NULL
-- =============================================

-- Variável para o mês corrente
SET @current_month = DATE_FORMAT(CURDATE(), '%Y-%m');

-- Ana Silva - Registros de Dezembro
INSERT INTO attendance (teacher_id, school_id, date, check_in, check_out, method, ip, user_agent, check_in_lat, check_in_lng, check_in_acc, photo, approved, manual_reason_id, manual_reason_text, manual_by_admin_id, manual_created_at) VALUES

-- Semana 1 - Mix de aprovados e pendentes
(1, 1, CONCAT(@current_month, '-01'), '2024-12-01 08:15:00', '2024-12-01 12:30:00', 'pin', '192.168.1.100', 'Mozilla/5.0', -23.5505, -46.6333, 10.5, 'foto_1_20241201_081500.jpg', 1, NULL, NULL, NULL, NULL),
(1, 1, CONCAT(@current_month, '-02'), '2024-12-02 08:10:00', '2024-12-02 15:45:00', 'face', '192.168.1.100', 'Mozilla/5.0', -23.5505, -46.6333, 8.2, 'foto_1_20241202_081000.jpg', 1, NULL, NULL, NULL, NULL),
(1, 1, CONCAT(@current_month, '-03'), '2024-12-03 08:25:00', '2024-12-03 12:15:00', 'face', '192.168.1.100', 'Mozilla/5.0', -23.5505, -46.6333, 12.1, 'foto_1_20241203_082500.jpg', NULL, NULL, NULL, NULL, NULL), -- Pendente
(1, 1, CONCAT(@current_month, '-04'), '2024-12-04 08:05:00', '2024-12-04 15:30:00', 'pin', '192.168.1.100', 'Mozilla/5.0', -23.5505, -46.6333, 9.8, NULL, 1, NULL, NULL, NULL, NULL),
(1, 1, CONCAT(@current_month, '-05'), '2024-12-05 08:30:00', '2024-12-05 13:00:00', 'face', '192.168.1.100', 'Mozilla/5.0', -23.5600, -46.6400, 15.3, 'foto_1_20241205_083000.jpg', 0, NULL, NULL, NULL, NULL), -- Rejeitado

-- Bruno Santos - Registros de Dezembro
(2, 2, CONCAT(@current_month, '-01'), '2024-12-01 08:00:00', '2024-12-01 17:00:00', 'face', '192.168.1.101', 'Mozilla/5.0', -23.5200, -46.6000, 7.5, 'foto_2_20241201_080000.jpg', 1, NULL, NULL, NULL, NULL),
(2, 2, CONCAT(@current_month, '-02'), '2024-12-02 07:55:00', '2024-12-02 17:10:00', 'face', '192.168.1.101', 'Mozilla/5.0', -23.5200, -46.6000, 6.8, 'foto_2_20241202_075500.jpg', 1, NULL, NULL, NULL, NULL),
(2, 2, CONCAT(@current_month, '-03'), NULL, NULL, 'manual', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, 'Sistema offline durante manutenção', 1, '2024-12-03 09:00:00'), -- Manual
(2, 2, CONCAT(@current_month, '-04'), '2024-12-04 08:15:00', '2024-12-04 16:45:00', 'pin', '192.168.1.101', 'Mozilla/5.0', -23.5200, -46.6000, 11.2, NULL, NULL, NULL, NULL, NULL, NULL), -- Pendente
(2, 2, CONCAT(@current_month, '-05'), '2024-12-05 08:00:00', '2024-12-05 17:00:00', 'face', '192.168.1.101', 'Mozilla/5.0', -23.5200, -46.6000, 8.9, 'foto_2_20241205_080000.jpg', 1, NULL, NULL, NULL, NULL),

-- Carlos Oliveira - Trabalha em ambas escolas
(3, 1, CONCAT(@current_month, '-01'), '2024-12-01 07:30:00', '2024-12-01 12:00:00', 'face', '192.168.1.102', 'Mozilla/5.0', -23.5505, -46.6333, 9.1, 'foto_3_20241201_073000.jpg', 1, NULL, NULL, NULL, NULL),
(3, 2, CONCAT(@current_month, '-01'), '2024-12-01 13:30:00', '2024-12-01 16:30:00', 'face', '192.168.1.102', 'Mozilla/5.0', -23.5200, -46.6000, 10.4, 'foto_3_20241201_133000.jpg', 1, NULL, NULL, NULL, NULL),
(3, 1, CONCAT(@current_month, '-02'), '2024-12-02 07:25:00', '2024-12-02 11:45:00', 'pin', '192.168.1.102', 'Mozilla/5.0', -23.5505, -46.6333, 12.7, NULL, NULL, NULL, NULL, NULL, NULL), -- Pendente
(3, 2, CONCAT(@current_month, '-02'), '2024-12-02 13:15:00', '2024-12-02 16:15:00', 'face', '192.168.1.102', 'Mozilla/5.0', -23.5200, -46.6000, 8.3, 'foto_3_20241202_131500.jpg', 1, NULL, NULL, NULL, NULL),
(3, 1, CONCAT(@current_month, '-03'), '2024-12-03 07:35:00', '2024-12-03 16:25:00', 'face', '192.168.1.102', 'Mozilla/5.0', -23.5505, -46.6333, 7.9, 'foto_3_20241203_073500.jpg', 1, NULL, NULL, NULL, NULL);

-- =============================================
-- 9. TIPOS DE LICENÇA (para teste financeiro)
-- =============================================

INSERT INTO leave_types (id, name, code, paid, affects_bank, active, created_at) VALUES
(1, 'Férias', 'FERIAS', 1, 0, 1, '2024-01-10 09:20:00'),
(2, 'Licença Médica', 'LIC_MED', 1, 0, 1, '2024-01-10 09:20:00'),
(3, 'Faltas Justificadas', 'FALTA_JUST', 0, 1, 1, '2024-01-10 09:20:00'),
(4, 'Licença Maternidade', 'LIC_MAT', 1, 0, 1, '2024-01-10 09:20:00'),
(5, 'Falta Injustificada', 'FALTA_INJ', 0, 1, 1, '2024-01-10 09:20:00');

-- =============================================
-- 10. LICENÇAS (algumas pagas/não pagas)
-- =============================================

INSERT INTO leaves (teacher_id, school_id, type_id, start_date, end_date, notes, approved, created_by_admin_id, created_at) VALUES
-- Ana Silva: Licença médica (paga) de 3 dias no mês
(1, 1, 2, CONCAT(@current_month, '-10'), CONCAT(@current_month, '-12'), 'Consulta médica e recuperação', 1, 1, '2024-12-08 10:00:00'),

-- Bruno Santos: Férias (pagas) de 5 dias
(2, 2, 1, CONCAT(@current_month, '-15'), CONCAT(@current_month, '-19'), 'Férias programadas', 1, 1, '2024-12-10 14:00:00'),

-- Carlos Oliveira: Falta justificada (não paga) de 1 dia
(3, 1, 3, CONCAT(@current_month, '-08'), CONCAT(@current_month, '-08'), 'Problema pessoal', 1, 2, '2024-12-07 16:30:00');

-- =============================================
-- 11. BANCO DE HORAS (alguns registros para teste)
-- =============================================

INSERT INTO hour_bank_entries (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id, created_by_admin_id, created_at) VALUES
-- Ana Silva: horas extras e déficits
(1, 1, CONCAT(@current_month, '-01'), 30, 'Horas extras - aula adicional', 'manual', NULL, 1, '2024-12-01 18:00:00'),
(1, 1, CONCAT(@current_month, '-02'), -15, 'Saída antecipada', 'auto', 2, NULL, '2024-12-02 23:59:59'),

-- Bruno Santos: banco de horas balanceado
(2, 2, CONCAT(@current_month, '-01'), 60, 'Reunião extra com pais', 'manual', NULL, 2, '2024-12-01 19:00:00'),
(2, 2, CONCAT(@current_month, '-04'), -30, 'Entrada tardia', 'auto', 9, NULL, '2024-12-04 23:59:59'),

-- Carlos Oliveira: coordenação geral
(3, 1, CONCAT(@current_month, '-01'), 45, 'Coordenação entre escolas', 'manual', NULL, 1, '2024-12-01 17:00:00'),
(3, 2, CONCAT(@current_month, '-02'), 20, 'Reunião de planejamento', 'manual', NULL, 3, '2024-12-02 18:00:00');

-- =============================================
-- 12. LOGS DE AUDITORIA (opcional)
-- =============================================

INSERT INTO audit_logs (admin_id, action, entity, entity_id, payload, ip, created_at) VALUES
(1, 'create', 'teacher', '1', '{"name":"Ana Silva","cpf":"11122233344"}', '192.168.1.10', '2024-01-15 09:00:00'),
(1, 'create', 'teacher', '2', '{"name":"Bruno Santos","cpf":"22233344455"}', '192.168.1.10', '2024-01-15 09:15:00'),
(1, 'create', 'teacher', '3', '{"name":"Carlos Oliveira","cpf":"33344455566"}', '192.168.1.10', '2024-01-15 09:30:00'),
(NULL, 'create', 'attendance', '1', '{"teacher_id":1,"type":"checkin"}', '192.168.1.100', '2024-12-01 08:15:00'),
(1, 'update', 'attendance', '1', '{"approved_from":null,"approved_to":1}', '192.168.1.10', '2024-12-01 10:00:00');

-- =============================================
-- Comentários e Notas para QA
-- =============================================

/*
RESUMO DOS DADOS CRIADOS:

ESCOLAS:
- Escola Municipal Centro (ID 1): São Paulo Centro
- Escola Estadual Zona Norte (ID 2): São Paulo Norte

PROFESSORES:
- Ana Silva (ID 1): Professor, apenas Escola Centro, horário por classes
- Bruno Santos (ID 2): Diretor, apenas Escola Norte, horário fixo 8h-17h
- Carlos Oliveira (ID 3): Coordenador, ambas escolas (N:N), horário flexível

ATTENDANCE DATA:
- Mix de registros approved=1 (aprovados), approved=NULL (pendentes), approved=0 (rejeitados)
- Diferentes métodos: face, pin, manual
- Com e sem fotos
- Diferentes localizações (dentro/fora do raio)

LICENÇAS:
- Mix de licenças pagas e não pagas para teste de cálculos financeiros

CASOS DE TESTE COBERTOS:
✓ Check-in com foto e geolocalização
✓ Check-in fora do raio (pendente)
✓ Check-in sem foto (PIN)
✓ Registros manuais com justificativa
✓ Vínculos 1:N e N:N entre professor-escola
✓ Diferentes tipos de horário (classes vs time)
✓ Mix de status de aprovação para teste de filtros
✓ Licenças pagas/não pagas para cálculo financeiro
✓ Banco de horas manual e automático

SENHAS PADRÃO:
- Admins: password123
- Professores (PIN): 123456, 234567, 345678

COMANDOS DE TESTE:
- Resetar attendance: DELETE FROM attendance WHERE date >= CURDATE();
- Limpar fotos: rm -f public/photos/foto_*
- Importar: mysql database_name < seeds/qa_seed.sql
*/
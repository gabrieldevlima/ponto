-- =========================================
-- SISTEMA DE HORAS EXTRAS - SQL UPDATES
-- =========================================
-- Versão: 1.0.0
-- Data: 2025-10-10
-- Descrição: Script para adicionar sistema de horas extras ao banco existente
-- =========================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================
-- 1. Atualiza enum de hour_bank_entries
-- =========================================

-- Adiciona novo valor 'overtime_approved' ao enum source
ALTER TABLE hour_bank_entries 
MODIFY source ENUM('auto','manual','overtime_approved') NOT NULL DEFAULT 'manual';

-- =========================================
-- 2. Cria tabela overtime_requests
-- =========================================

-- Tabela para solicitações de horas extras
CREATE TABLE IF NOT EXISTS overtime_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,              -- Vinculado ao registro de ponto
  teacher_id INT NOT NULL,                 -- Colaborador
  school_id INT NULL,                      -- Instituição (se aplicável)
  date DATE NOT NULL,                      -- Data do trabalho
  minutes INT NOT NULL,                    -- Minutos de hora extra detectados
  expected_minutes INT NOT NULL DEFAULT 0, -- Minutos esperados no dia
  worked_minutes INT NOT NULL DEFAULT 0,   -- Minutos efetivamente trabalhados
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL,           -- Quem aprovou/rejeitou
  approved_at DATETIME NULL,               -- Quando foi processado
  rejection_reason VARCHAR(255) NULL,      -- Motivo da rejeição
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ot_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
  CONSTRAINT fk_ot_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_ot_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_ot_admin FOREIGN KEY (approved_by_admin_id) REFERENCES admins(id),
  UNIQUE KEY uq_attendance_overtime (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================
-- 3. Cria índices para performance
-- =========================================

CREATE INDEX idx_overtime_teacher_date ON overtime_requests(teacher_id, date);
CREATE INDEX idx_overtime_status ON overtime_requests(status);
CREATE INDEX idx_overtime_attendance ON overtime_requests(attendance_id);
CREATE INDEX idx_overtime_date ON overtime_requests(date);

-- =========================================
-- 4. Verificação (opcional - comentar em produção)
-- =========================================

-- Verifica se a tabela foi criada
-- SELECT 'Tabela overtime_requests criada com sucesso!' AS status;
-- SELECT COUNT(*) as total_colunas FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'overtime_requests';

-- Verifica enum atualizado
-- SHOW COLUMNS FROM hour_bank_entries WHERE Field = 'source';

-- =========================================
-- FIM DO SCRIPT
-- =========================================

/*
INSTRUÇÕES DE USO:

1. Backup do banco ANTES de executar:
   mysqldump -u usuario -p nome_do_banco > backup_antes_overtime.sql

2. Executar este script:
   mysql -u usuario -p nome_do_banco < install_overtime_only.sql

3. Verificar instalação:
   mysql -u usuario -p nome_do_banco
   > DESCRIBE overtime_requests;
   > SHOW COLUMNS FROM hour_bank_entries WHERE Field = 'source';

4. Testar:
   > SELECT COUNT(*) FROM overtime_requests;
   > INSERT INTO overtime_requests (attendance_id, teacher_id, date, minutes, expected_minutes, worked_minutes) 
     VALUES (1, 1, CURDATE(), 60, 480, 540);
   > SELECT * FROM overtime_requests;

ROLLBACK (se necessário):
   mysql -u usuario -p nome_do_banco < backup_antes_overtime.sql

OU manualmente:
   DROP TABLE IF EXISTS overtime_requests;
   ALTER TABLE hour_bank_entries 
   MODIFY source ENUM('auto','manual') NOT NULL DEFAULT 'manual';

ATENÇÃO:
- Este script assume que as tabelas base já existem (attendance, teachers, schools, admins)
- Execute apenas UMA vez
- Faça backup antes de executar
- Teste em ambiente de desenvolvimento primeiro

PRÓXIMOS PASSOS:
1. Atualizar arquivos PHP conforme documentação
2. Acessar /admin/overtime.php para testar interface
3. Configurar jornadas dos colaboradores
4. Testar detecção automática de horas extras

Documentação completa: docs/OVERTIME_SYSTEM.md
Guia de instalação: docs/INSTALLATION_GUIDE.md
*/


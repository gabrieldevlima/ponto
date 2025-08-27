-- ============================================================
-- Sistema de Ponto - Instalação Completa (MySQL/MariaDB)
-- Cria todas as tabelas necessárias e popula dados básicos.
-- Compatível com colaboradores de diferentes tipos, rotina por aulas/horário,
-- registros manuais com justificativa e relatórios com aprovação.
--
-- Observações:
-- - Execute este script dentro do banco já selecionado (ex.: USE ponto;)
-- - Charset: utf8mb4
-- - Admin padrão (opcional): usuário "admin" com senha "admin123"
-- ============================================================

-- Recomendado (opcional):
-- SET NAMES utf8mb4;
-- SET time_zone = '+00:00';

-- ============================================================
-- Tabela de administradores
-- ============================================================
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin padrão (senha: admin123)
-- Altere após o primeiro login!
INSERT INTO admins (username, password_hash)
SELECT 'admin', '$2y$10$uQ8Fyv1I3VzBkYOPxfN3XO3xaQa58aeGeq/QAdzTZziEtGlUZEMyW'
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE username = 'admin');

-- ============================================================
-- Tipos de colaboradores
-- schedule_mode: 'none' (sem rotina), 'classes' (aulas), 'time' (horário)
-- requires_schedule: mantido por compatibilidade (deriva de schedule_mode <> 'none')
-- ============================================================
CREATE TABLE IF NOT EXISTS collaborator_types (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  schedule_mode ENUM('none','classes','time') NOT NULL DEFAULT 'none',
  requires_schedule TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_collaborator_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tipos padrão (idempotente)
INSERT INTO collaborator_types (slug, name, schedule_mode, requires_schedule)
VALUES
  ('teacher',     'Professor',    'classes', 1),
  ('director',    'Diretor',      'time',    1),
  ('secretary',   'Secretário',   'time',    1),
  ('driver',      'Motorista',    'time',    1),
  ('coordinator', 'Coordenador',  'time',    1),
  ('admin_staff', 'Administrativo','time',   1),
  ('assistant',   'Auxiliar',     'time',    1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  schedule_mode = VALUES(schedule_mode),
  requires_schedule = VALUES(requires_schedule);

-- ============================================================
-- Colaboradores (mantido nome 'teachers' por compatibilidade)
-- ============================================================
CREATE TABLE IF NOT EXISTS teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  cpf VARCHAR(14) NOT NULL,
  pin_hash VARCHAR(255) NOT NULL,
  email VARCHAR(120),
  active TINYINT(1) NOT NULL DEFAULT 1,
  type_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  face_descriptors LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_teachers_cpf UNIQUE (cpf),
  CONSTRAINT fk_teachers_type FOREIGN KEY (type_id) REFERENCES collaborator_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_teachers_type_active ON teachers(type_id, active);
CREATE INDEX idx_teachers_name ON teachers(name);

-- ============================================================
-- Motivos para inserção manual de pontos
-- ============================================================
CREATE TABLE IF NOT EXISTS manual_reasons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Motivos padrão (idempotente)
INSERT INTO manual_reasons (name, active, sort_order)
VALUES
  ('Falta de internet', 1, 10),
  ('Falha no sistema', 1, 20),
  ('Esquecimento do colaborador', 1, 30),
  ('Outro', 1, 100)
ON DUPLICATE KEY UPDATE
  active = VALUES(active),
  sort_order = VALUES(sort_order);

-- ============================================================
-- Registros de ponto
-- - approved: 1=aprovado, 0=rejeitado
-- - Campos "manual_*" para inserções manuais (motivo, admin, data)
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  date DATE NOT NULL,
  check_in DATETIME DEFAULT NULL,
  check_out DATETIME DEFAULT NULL,
  method VARCHAR(20) DEFAULT NULL,           -- 'pin' | 'manual' | outros
  ip VARCHAR(50) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  check_in_lat DOUBLE DEFAULT NULL,
  check_in_lng DOUBLE DEFAULT NULL,
  check_in_acc DOUBLE DEFAULT NULL,
  check_out_lat DOUBLE DEFAULT NULL,
  check_out_lng DOUBLE DEFAULT NULL,
  check_out_acc DOUBLE DEFAULT NULL,
  photo VARCHAR(255) DEFAULT NULL,           -- armazena o nome do arquivo salvo
  approved TINYINT(1) NOT NULL DEFAULT 1,
  manual_reason_id INT NULL,
  manual_reason_text VARCHAR(255) NULL,
  manual_by_admin_id INT NULL,
  manual_created_at DATETIME NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_attendance_manual_reason FOREIGN KEY (manual_reason_id) REFERENCES manual_reasons(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_attendance_manual_admin FOREIGN KEY (manual_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_attendance_teacher_date ON attendance(teacher_id, date);
CREATE INDEX idx_attendance_manual ON attendance(manual_reason_id, manual_by_admin_id);

-- ============================================================
-- Rotina semanal genérica (suporta aulas e horário)
-- - weekday: 0=Dom, 1=Seg, ... 6=Sáb
-- - Para professores (schedule_mode='classes'): usar classes_count e class_minutes
-- - Para demais (schedule_mode='time'): usar start_time, end_time e break_minutes
-- ============================================================
CREATE TABLE IF NOT EXISTS collaborator_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT(1) NOT NULL,          -- 0..6
  classes_count INT NULL DEFAULT 0,     -- modo classes
  class_minutes INT NULL DEFAULT 60,    -- modo classes
  start_time TIME NULL,                 -- modo time
  end_time TIME NULL,                   -- modo time
  break_minutes INT NULL DEFAULT 0,     -- modo time
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_collab_weekday (teacher_id, weekday),
  CONSTRAINT fk_collab_schedule_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FIM
-- Após importar:
-- - Acesse /admin com usuário "admin" e senha "admin123" e troque a senha.
-- - Cadastre colaboradores, defina tipos e rotinas (aulas ou horários).
-- - Configure motivos adicionais em "Motivos" se necessário.
-- ============================================================
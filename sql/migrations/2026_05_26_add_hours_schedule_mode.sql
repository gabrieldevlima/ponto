-- Adiciona um quarto schedule_mode 'hours' para tipos de colaborador cuja
-- jornada eh definida em TOTAL DE HORAS POR DIA (motorista, monitor, etc),
-- sem horario fixo de entrada/saida.
--
-- Motivacao: os modos existentes 'classes' (aulas × minutos) e 'time'
-- (entrada → saida) nao representam corretamente o modelo onde o colaborador
-- "trabalha 8h por dia em qualquer horario". Hoje, marcar esses tipos como
-- 'time' obriga a definir um horario fictio de entrada/saida e gera "atrasos"
-- artificiais nos relatorios.
--
-- Modelo: a nova tabela collaborator_hours_schedules guarda total_minutes
-- e break_minutes por weekday. Cumprimento do dia = trabalhado >= esperado.

-- 1) Expandir o ENUM de schedule_mode para aceitar 'hours'
ALTER TABLE collaborator_types
  MODIFY COLUMN schedule_mode ENUM('none','classes','time','hours')
  NOT NULL DEFAULT 'classes';

-- 2) Nova tabela: schedule por weekday em formato "total de minutos/dia"
CREATE TABLE IF NOT EXISTS collaborator_hours_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  weekday TINYINT NOT NULL,                  -- 0=Dom..6=Sab (padrao do projeto)
  total_minutes INT NOT NULL DEFAULT 0,      -- ex: 480 = 8h
  break_minutes INT NOT NULL DEFAULT 0,      -- intervalo previsto (descontado se nao bateu)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_teacher_weekday (teacher_id, weekday),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

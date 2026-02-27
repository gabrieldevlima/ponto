-- =====================================================================
-- Migration: Corrigir Tabelas e Colunas Ausentes
-- =====================================================================
-- Script consolidado para adicionar todas as estruturas faltantes
-- Execute este script no phpMyAdmin da produção
-- =====================================================================

-- 1. Adicionar coluna pending_reasons na tabela attendance
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS pending_reasons TEXT NULL 
COMMENT 'Motivos JSON quando ponto fica pendente (ex: sem foto, sem geo)';

-- 2. Adicionar colunas de rastreamento de edição na tabela attendance
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS editado_por INT NULL COMMENT 'ID do admin que editou',
ADD COLUMN IF NOT EXISTS data_edicao DATETIME NULL COMMENT 'Data/hora da última edição',
ADD COLUMN IF NOT EXISTS motivo_edicao TEXT NULL COMMENT 'Justificativa da edição';

-- 3. Criar tabela de histórico de edições
CREATE TABLE IF NOT EXISTS attendance_edits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL COMMENT 'ID do registro de ponto editado',
  edited_by INT NOT NULL COMMENT 'ID do admin que fez a edição',
  edited_at DATETIME NOT NULL COMMENT 'Data/hora da edição',
  reason TEXT NOT NULL COMMENT 'Justificativa da edição',
  type VARCHAR(50) NULL COMMENT 'Tipo da edição (add_hours, remove_hours, change_date, etc)',
  diff_minutes INT NULL COMMENT 'Delta de minutos trabalhados (positivo=adicionou, negativo=removeu)',
  changed_fields TEXT NULL COMMENT 'JSON com array de campos alterados',
  before_json TEXT NULL COMMENT 'JSON com estado antes da edição',
  after_json TEXT NULL COMMENT 'JSON com estado após a edição',
  FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
  FOREIGN KEY (edited_by) REFERENCES admins(id) ON DELETE RESTRICT,
  INDEX idx_attendance_id (attendance_id),
  INDEX idx_edited_by (edited_by),
  INDEX idx_edited_at (edited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico detalhado de edições em registros de ponto';

-- 4. Criar tabela de auditoria geral
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL COMMENT 'ID do admin que realizou a ação',
  action VARCHAR(50) NOT NULL COMMENT 'Tipo da ação (create, update, delete, approve, reject, etc)',
  entity VARCHAR(50) NOT NULL COMMENT 'Entidade afetada (attendance, teacher, school, etc)',
  entity_id VARCHAR(50) NULL COMMENT 'ID da entidade afetada',
  payload TEXT NULL COMMENT 'JSON com detalhes da operação',
  ip VARCHAR(45) NULL COMMENT 'IP de origem da ação',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Data/hora da ação',
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT,
  INDEX idx_admin_id (admin_id),
  INDEX idx_action (action),
  INDEX idx_entity (entity, entity_id),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de auditoria de ações administrativas';



-- =====================================================================
-- Migration: Tabela de Auditoria Geral
-- =====================================================================
-- Cria tabela para registrar todas as ações administrativas
-- Rastreia criações, edições, exclusões e outras operações
-- =====================================================================

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



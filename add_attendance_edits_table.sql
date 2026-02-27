-- =====================================================================
-- Migration: Tabela de Histórico de Edições de Ponto
-- =====================================================================
-- Cria tabela para rastrear todas as edições feitas em registros de ponto
-- Permite auditoria completa com before/after JSON
-- =====================================================================

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


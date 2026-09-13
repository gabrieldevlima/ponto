-- Anulacao (soft-delete) auditavel de registros de ponto pelo admin.
-- O sistema NUNCA apaga registro legal: o NSR e imutavel (Portaria MTP 671/2021).
-- A remocao marca approved=0 + superseded_by_id=id (reusa a exclusao que folha e
-- relatorios ja respeitam) e estas colunas distinguem "removido pelo admin" de
-- "duplicata de dedupe", alem de permitir restaurar com seguranca.
-- IMPORTANTE: comentarios NAO podem conter ';' (o runner faz split por ';').

ALTER TABLE attendance
  ADD COLUMN removed_at DATETIME NULL
    COMMENT 'Quando o admin anulou (soft-delete) este registro. NULL = registro ativo'
    AFTER superseded_by_id;

ALTER TABLE attendance
  ADD COLUMN removed_by_admin_id INT NULL
    COMMENT 'Admin que anulou o registro'
    AFTER removed_at;

ALTER TABLE attendance
  ADD COLUMN removed_reason VARCHAR(255) NULL
    COMMENT 'Motivo informado pelo admin ao anular'
    AFTER removed_by_admin_id;

ALTER TABLE attendance
  ADD INDEX idx_attendance_removed_at (removed_at);

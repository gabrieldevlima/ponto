-- Marca registros de attendance considerados duplicatas de outro registro
-- (deduplicação manual feita pelo admin pós-fato). NULL = registro normal.
-- Quando preenchido, aponta para o registro "vencedor" que foi mantido na folha.
--
-- NOTA: a tabela `permissions` neste projeto usa schema (admin_id, permission_key,
-- granted_at) — diferente do schema (role, perm_key, allow) que `has_permission()`
-- consulta. Por causa disso, e porque `has_permission()` já default-allows quando
-- a linha não existe, NÃO inserimos linha de permissão aqui — admins existentes
-- conseguem acessar a tela automaticamente.
--
-- IMPORTANTE: comentários NÃO podem conter ';' (o runner faz split por ';' no
-- fallback). A FK usa DROP-antes-de-ADD para ser idempotente em reexecuções
-- (DROP de FK inexistente = 1091, tolerado; assim o ADD nunca colide).

ALTER TABLE attendance
  ADD COLUMN superseded_by_id INT NULL
    COMMENT 'Se preenchido, o registro foi marcado como duplicata por admin (aponta o mantido)'
    AFTER manual_reason_text;

ALTER TABLE attendance
  ADD INDEX idx_superseded_by (superseded_by_id);

ALTER TABLE attendance DROP FOREIGN KEY fk_attendance_superseded;

ALTER TABLE attendance
  ADD CONSTRAINT fk_attendance_superseded
    FOREIGN KEY (superseded_by_id) REFERENCES attendance(id) ON DELETE SET NULL;

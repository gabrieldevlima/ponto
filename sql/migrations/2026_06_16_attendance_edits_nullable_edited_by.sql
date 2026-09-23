-- attendance_edits.edited_by precisa aceitar NULL: a convencao do codigo e que
-- edited_by=NULL identifica uma edicao feita pelo PROPRIO COLABORADOR (admin tem id).
-- Sem isso, edit_own_break_direct() e close_own_open_break() falham com erro 1048
-- (Column 'edited_by' cannot be null) ao registrar a auditoria da correcao.
-- Idempotente: re-aplicar o MODIFY para a mesma definicao e inofensivo.
ALTER TABLE attendance_edits MODIFY COLUMN edited_by INT NULL;

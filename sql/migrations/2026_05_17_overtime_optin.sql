-- Hora extra opt-in: distingue solicitacoes feitas pelo colaborador (requested_by_employee=1)
-- das criadas automaticamente pelo sistema no checkout (legado, requested_by_employee=0).
--
-- A partir desta migracao, o sistema NAO cria mais solicitacoes automaticamente. Apenas
-- registros criados via api/request_overtime.php (clique do colaborador no botao "Solicitar
-- hora extra") gravam essa coluna com 1. Registros antigos permanecem com 0 e a tela
-- admin (public/admin/overtime.php) filtra por padrao requested_by_employee=1, escondendo
-- os legados.
--
-- Sem risco para historico ou relatorios: a coluna eh aditiva com DEFAULT 0 e nenhuma
-- query existente referencia esse campo. Relatorios continuam usando status='approved'.

ALTER TABLE overtime_requests
  ADD COLUMN requested_by_employee TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Se 1, a solicitacao foi feita explicitamente pelo colaborador (botao Solicitar hora extra). Se 0, eh registro legado auto-criado pelo sistema.'
    AFTER justification;

ALTER TABLE overtime_requests
  ADD INDEX idx_requested_by_employee (requested_by_employee, status);

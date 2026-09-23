-- Fix: audit_logs.admin_id precisa ser NULL para aceitar ações de colaboradores.
-- A migration original `add_audit_logs_table.sql` criou a coluna como NOT NULL,
-- mas o sistema grava eventos de colaborador (ex: login/logout) onde
-- admin_id é NULL. Sem essa correção, os INSERTs falham silenciosamente no
-- try/catch de helpers.php `audit_log()` — log nunca é gravado.
--
-- Idempotente: executar N vezes é seguro (MODIFY não cria duplicata).

ALTER TABLE audit_logs MODIFY COLUMN admin_id INT NULL;

-- ============================================================================
-- Imutabilidade do livro fiscal, garantida pelo BANCO
-- Fase 1.2 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- NC-11: o banco de produção não tem nenhum trigger. Toda a proteção dos
-- registros de ponto vivia na aplicação — quem tivesse acesso ao MySQL
-- (phpMyAdmin, cliente, backup restaurado) alterava marcação sem deixar rastro.
-- Para um REP isso é exatamente o que a Portaria não admite.
--
-- POR QUE ISTO FUNCIONA SEM `DELIMITER`:
-- O runner de migrations (helpers.run_auto_migrations) faz `explode(';')` e não
-- entende `DELIMITER` — por isso nenhuma trigger com corpo BEGIN...END pode ser
-- criada por migration automática. Um corpo de statement ÚNICO, porém, não tem
-- `;` interno e passa pelo split ingênuo sem problema. É o caso de SIGNAL.
--
-- Requisitos ao editar este arquivo:
--   - nenhum `;` dentro do corpo do trigger
--   - MESSAGE_TEXT em ASCII e com no máximo 128 caracteres (limite do SIGNAL)
--
-- Isto só é possível porque o ledger é append-only DE VERDADE: anular é
-- INSERIR uma linha `event_type='void'` apontando para o NSR alvo, nunca um
-- UPDATE. Se algum dia surgir a tentação de "só um UPDATE nesse campinho",
-- toda esta construção cai junto.
--
-- Requer privilégio TRIGGER no banco. Se a migration falhar por permissão, o
-- painel de migrations mostra o erro e a proteção precisa ser aplicada à mão.
-- ============================================================================

DROP TRIGGER IF EXISTS trg_nsr_ledger_no_update;
CREATE TRIGGER trg_nsr_ledger_no_update BEFORE UPDATE ON nsr_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nsr_ledger e imutavel (Portaria MTP 671/2021) - para anular, INSERT com event_type=void';

DROP TRIGGER IF EXISTS trg_nsr_ledger_no_delete;
CREATE TRIGGER trg_nsr_ledger_no_delete BEFORE DELETE ON nsr_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nsr_ledger e imutavel (Portaria MTP 671/2021) - registro fiscal nao pode ser apagado';

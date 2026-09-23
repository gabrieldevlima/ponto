-- ============================================================================
-- Contador PRÓPRIO para o livro fiscal
-- Correção durante a Fase 1 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- Defeito detectado pelos testes: o livro (`nsr_ledger`) reservava NSR do MESMO
-- contador `nsr_sequence.current_nsr` usado pelo emissor legado que numera
-- `attendance.nsr`. Consequências:
--
--   1. Todo caminho de escrita ainda NÃO convertido ao livro (inserção manual
--      pelo admin, edição de dia, regularizações) continuava consumindo números
--      da sequência sem gerar linha no livro — abrindo BURACOS permanentes na
--      numeração fiscal. Buraco, para um auditor, é registro emitido e ausente.
--   2. Alternar `ledger_mode` entre off e shadow também abria buracos.
--
-- A numeração fiscal precisa ser uma sequência contínua e exclusiva do livro.
-- Compartilhá-la com qualquer outro emissor é frágil por construção: bastaria
-- um caminho esquecido para corromper a evidência.
--
-- `current_nsr` continua servindo ao emissor legado (usado quando o livro está
-- desligado), agora sem interferência mútua.
-- ============================================================================

ALTER TABLE nsr_sequence ADD COLUMN ledger_current_nsr BIGINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Sequencia EXCLUSIVA do nsr_ledger (Portaria MTP 671/2021)';

-- ============================================================================
-- Expiração do token "lembrar de mim" — NC-44
-- Fase 9 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- O cookie era emitido com validade de DEZ ANOS e a tabela não tinha coluna de
-- expiração — a query que consome o token não filtrava por data alguma. Não
-- havia expiração em lugar nenhum: um token vazado (aparelho perdido, backup,
-- log de proxy) valia uma década.
--
-- O backfill dá aos tokens já existentes 90 dias a partir da CRIAÇÃO, não de
-- agora: token antigo deve expirar logo, e não ganhar sobrevida por causa da
-- própria correção.
-- ============================================================================

ALTER TABLE collaborator_remember_tokens ADD COLUMN expires_at DATETIME NULL
  COMMENT 'validade do token; NULL apos backfill indica token legado sem data';

UPDATE collaborator_remember_tokens
   SET expires_at = DATE_ADD(COALESCE(created_at, NOW()), INTERVAL 90 DAY)
 WHERE expires_at IS NULL;

CREATE INDEX idx_remember_expires ON collaborator_remember_tokens (expires_at);

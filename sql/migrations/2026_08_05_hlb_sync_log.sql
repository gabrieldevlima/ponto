-- ============================================================================
-- Registro de sincronização com a Hora Legal Brasileira (HLB)
-- Fase 0b.4 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- NC-13: config.php define HLB_NTP_SERVER = 'a.st1.ntp.br' desde sempre, mas a
-- constante NUNCA foi lida por nenhum código. A "sincronização" real acontecia
-- no NAVEGADOR, via fetch a worldtimeapi.org, e o offset resultante era enviado
-- pelo próprio cliente e usado pelo servidor para decidir se aceitava o horário
-- que o cliente informava (NC-14 — raciocínio circular).
--
-- Esta tabela guarda medições feitas pelo SERVIDOR. Nesta fase o sistema apenas
-- OBSERVA: mede, registra e expõe no admin. Nenhuma marcação é alterada ainda —
-- isso é a Fase 6, depois de haver série histórica suficiente para saber se o
-- ambiente de hospedagem permite NTP e qual a magnitude real do desvio.
--
-- `emitted_nsr` fica reservado para a Fase 4: um ajuste de relógio relevante
-- vira registro tipo 4 do AFD.
-- ============================================================================

CREATE TABLE IF NOT EXISTS hlb_sync_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  checked_at  DATETIME NOT NULL,
  source      VARCHAR(48) NOT NULL COMMENT 'ntp:a.st1.ntp.br | http:ntp.br | server_clock',
  stratum     TINYINT UNSIGNED NULL,
  offset_ms   INT NULL COMMENT 'relogio do servidor - referencia; positivo = servidor adiantado',
  rtt_ms      INT NULL,
  ok          TINYINT(1) NOT NULL DEFAULT 0,
  error       VARCHAR(255) NULL,
  emitted_nsr BIGINT UNSIGNED NULL COMMENT 'reservado: NSR do registro AFD tipo 4 de ajuste de relogio',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hlb_checked (checked_at),
  KEY idx_hlb_ok (ok, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Selo diário do livro fiscal + registro de chaves
-- Fase 2.1/2.2 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- POR QUE O HMAC DA CADEIA NÃO BASTA
-- A cadeia HMAC-SHA256 da Fase 1 detecta adulteração feita por quem NÃO tem a
-- chave. Mas quem comprometer servidor e chave ao mesmo tempo pode reescrever
-- um trecho do passado e recalcular todos os elos seguintes: a cadeia voltaria
-- a fechar e a verificação não acusaria nada. É a limitação intrínseca de um
-- esquema simétrico.
--
-- O que fecha essa brecha é ANCORAR o estado do livro FORA do sistema. Todo
-- dia o head da cadeia é assinado com Ed25519 (chave assimétrica) e o hash é
-- publicado para o empregador — e-mail, ata, o que a instituição adotar. A
-- partir do momento em que o head de ontem existe fora do servidor, nem chave
-- + banco comprometidos permitem retratá-lo sem que a divergência apareça.
--
-- `ledger_keys` guarda apenas o FINGERPRINT das chaves, jamais as chaves.
-- Serve para provar, mais tarde, qual chave assinou o quê — inclusive depois
-- de uma rotação.
-- ============================================================================

CREATE TABLE IF NOT EXISTS nsr_ledger_seals (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  seal_date   DATE NOT NULL COMMENT 'dia coberto pelo selo',
  first_nsr   BIGINT UNSIGNED NOT NULL,
  last_nsr    BIGINT UNSIGNED NOT NULL,
  event_count INT UNSIGNED NOT NULL DEFAULT 0,
  head_hash   CHAR(64) NOT NULL COMMENT 'record_hash do ultimo evento do dia',
  signature   VARCHAR(128) NOT NULL COMMENT 'Ed25519 detached, base64',
  pubkey_id   VARCHAR(16) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seal_date (seal_date),
  KEY idx_seal_nsr (first_nsr, last_nsr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A ancoragem (publicação do head fora do sistema) é um FATO POSTERIOR ao selo
-- e mora em tabela própria, append-only. Guardá-la como colunas mutáveis dentro
-- de nsr_ledger_seals exigiria permitir UPDATE na tabela do selo — e um selo
-- que aceita UPDATE não é selo. Um mesmo dia pode ter mais de uma ancoragem
-- (e-mail ao empregador e ata de reunião, por exemplo), o que a modelagem em
-- coluna única também não comportaria.
CREATE TABLE IF NOT EXISTS nsr_ledger_seal_anchors (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  seal_date   DATE NOT NULL,
  anchored_at DATETIME NOT NULL,
  channel     VARCHAR(32) NOT NULL COMMENT 'email | ata | protocolo | outro',
  anchor_ref  VARCHAR(255) NOT NULL COMMENT 'Message-ID, numero de protocolo, etc',
  recorded_by INT NULL COMMENT 'admin que registrou, NULL se automatico',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_anchor_date (seal_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de chaves — FINGERPRINT apenas. Guardar a chave aqui anularia o
-- propósito: quem tivesse o banco teria também com que reassinar tudo.
CREATE TABLE IF NOT EXISTS ledger_keys (
  key_id            VARCHAR(16) NOT NULL,
  kind              ENUM('hmac','ed25519') NOT NULL,
  fingerprint       CHAR(64) NOT NULL COMMENT 'SHA-256 da chave (HMAC) ou da chave publica (Ed25519)',
  public_key        VARCHAR(128) NULL COMMENT 'chave PUBLICA Ed25519 em base64 (nao e segredo)',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at        DATETIME NULL,
  note              VARCHAR(255) NULL,
  PRIMARY KEY (key_id, kind),
  KEY idx_ledger_keys_kind (kind, retired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imutabilidade total do selo: um selo emitido é a prova de um dia fechado.
-- Nenhum UPDATE, nenhum DELETE. Registrar ancoragem é INSERT em
-- nsr_ledger_seal_anchors — mesma disciplina append-only do livro.
-- Statement único, sem `;` interno e sem acento: o runner de migrations faz
-- explode(';') e não entende DELIMITER.
DROP TRIGGER IF EXISTS trg_ledger_seals_no_delete;
CREATE TRIGGER trg_ledger_seals_no_delete BEFORE DELETE ON nsr_ledger_seals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nsr_ledger_seals e imutavel - selo emitido nao pode ser apagado';

DROP TRIGGER IF EXISTS trg_ledger_seals_no_update;
CREATE TRIGGER trg_ledger_seals_no_update BEFORE UPDATE ON nsr_ledger_seals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nsr_ledger_seals e imutavel - para registrar ancoragem use nsr_ledger_seal_anchors';

-- ============================================================================
-- LIVRO FISCAL APPEND-ONLY (nsr_ledger) — Portaria MTP 671/2021
-- Fase 1 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- Problema que resolve:
--   NC-09  a marcação de SAÍDA não recebia NSR próprio — `attendance` guarda
--          entrada e saída na MESMA linha, e o par consumia um único NSR;
--   NC-08  não havia cadeia de integridade: nada detectava alteração;
--   NC-10  `attendance.nsr` não tem UNIQUE no banco de produção;
--   NC-11  produção não tem nenhum trigger — acesso direto ao MySQL alterava
--          marcação sem deixar rastro.
--
-- Arquitetura: LEDGER PARALELO, não normalização de `attendance`.
--   `nsr_ledger`  = livro fiscal, uma linha por MARCAÇÃO, append-only, imutável.
--                   É a fonte do AFD, do AEJ e do comprovante.
--   `attendance`  = tabela OPERACIONAL, segue com entrada+saída na mesma linha.
--                   É o que alimenta jornada, banco de horas e todas as telas.
--
-- Por que não normalizar `attendance`: há 210 leituras em 65 arquivos, 4
-- máquinas de estado que dependem de "linha aberta = check_out IS NULL" e 8
-- chaves estrangeiras apontando para attendance.id — duas delas UNIQUE. Dividir
-- cada linha em duas não tem resposta óbvia sobre qual metade herda o id. O
-- ledger toca os 14 pontos de ESCRITA e mais nada.
--
-- Uma tabela só, com discriminador `event_type`: o NSR da Portaria é uma
-- sequência ÚNICA para todos os tipos de registro do AFD (empregador, empregado,
-- ajuste de relógio, marcação). Separar marcações num livro e o resto em outro
-- fragmentaria a cadeia de hash e exigiria uma segunda sequência.
--
-- APPEND-ONLY DE VERDADE: anular é INSERIR uma linha `event_type='void'` que
-- aponta para o NSR alvo — nunca um UPDATE. É isso que permite os triggers de
-- imutabilidade abaixo e mantém a cadeia coerente.
-- ============================================================================

CREATE TABLE IF NOT EXISTS nsr_ledger (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nsr                BIGINT UNSIGNED NOT NULL,
  event_type         ENUM('mark','void','clock_adjust','employer_change',
                          'employee_change','chain_genesis','system_migration')
                     NOT NULL DEFAULT 'mark',

  teacher_id         INT NULL,
  teacher_cpf        VARCHAR(11) NULL COMMENT 'congelado no momento da marcacao',
  teacher_pis        VARCHAR(11) NULL COMMENT 'congelado; NULL ate o cadastro ser preenchido',
  school_id          INT NULL,
  attendance_id      INT NULL COMMENT 'linha operacional de origem',
  mark_role          ENUM('in','out') NULL COMMENT 'qual metade do par originou',
  record_type        ENUM('work','break') NULL,
  direction          ENUM('E','S') NULL COMMENT 'Entrada / Saida',
  marked_at          DATETIME NULL COMMENT 'instante LEGAL da marcacao',
  work_date          DATE NULL COMMENT 'dia de competencia (noturno = dia da entrada)',

  origin             ENUM('device','kiosk','bulk_offline','admin_manual',
                          'admin_edit','regularization','import_legacy','system')
                     NOT NULL DEFAULT 'system',
  record_mode        ENUM('online','offline') NOT NULL DEFAULT 'online',
  method             VARCHAR(50) NULL,
  recorded_at        DATETIME NULL COMMENT 'carimbo do servidor no recebimento',
  client_recorded_at DATETIME NULL,
  synced_at          DATETIME NULL,

  hlb_source         VARCHAR(48) NULL,
  hlb_offset_ms      INT NULL,
  hlb_synced_at      DATETIME NULL,
  hlb_status         ENUM('synced','stale','failed','unknown') NOT NULL DEFAULT 'unknown',

  lat                DOUBLE NULL,
  lng                DOUBLE NULL,
  acc                DOUBLE NULL,
  ip                 VARCHAR(45) NULL,
  device_identifier  VARCHAR(64) NULL,
  device_fingerprint VARCHAR(64) NULL,
  photo              VARCHAR(255) NULL,

  target_nsr         BIGINT UNSIGNED NULL COMMENT 'void/clock_adjust: NSR alvo',
  reason             VARCHAR(255) NULL,
  admin_id           INT NULL,
  legacy_nsr         BIGINT UNSIGNED NULL COMMENT 'NSR antigo de attendance (backfill)',

  payload_canon      TEXT NOT NULL COMMENT 'string canonica exata que foi assinada',
  prev_hash          CHAR(64) NOT NULL,
  record_hash        CHAR(64) NOT NULL,
  hmac_key_id        VARCHAR(16) NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_ledger_nsr (nsr),
  UNIQUE KEY uq_ledger_record_hash (record_hash),
  UNIQUE KEY uq_ledger_prev_hash (prev_hash),
  KEY idx_ledger_teacher_date (teacher_id, work_date),
  KEY idx_ledger_marked_at (marked_at),
  KEY idx_ledger_attendance (attendance_id, mark_role),
  KEY idx_ledger_afd (event_type, marked_at, nsr),
  KEY idx_ledger_legacy (legacy_nsr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- uq_ledger_prev_hash é o detalhe que mais importa: torna fisicamente
-- impossível dois registros apontarem para o mesmo predecessor. Sem ele um
-- atacante poderia reescrever um ramo da cadeia e mantê-la "válida".

-- ----------------------------------------------------------------------------
-- Âncora da cadeia em nsr_sequence.
-- O `SELECT current_nsr ... FOR UPDATE` que já existe em 5 pontos do código
-- passa a devolver, na MESMA linha travada, o NSR e o prev_hash — atômicos de
-- graça, sem `ORDER BY nsr DESC LIMIT 1` (que seria race + varredura).
-- ----------------------------------------------------------------------------
ALTER TABLE nsr_sequence ADD COLUMN last_record_hash CHAR(64) NULL;
ALTER TABLE nsr_sequence ADD COLUMN chain_key_id VARCHAR(16) NULL;
ALTER TABLE nsr_sequence ADD COLUMN chain_started_at DATETIME NULL;

-- ----------------------------------------------------------------------------
-- Espelhos em attendance — é o que evita reescrever 65 arquivos.
-- `attendance.nsr` passa a espelhar o NSR da marcação de ENTRADA e `nsr_out` o
-- da SAÍDA. Assim receipt.php, v_attendance_receipts, get_receipt.php e todas
-- as leituras existentes continuam funcionando sem alteração.
-- ----------------------------------------------------------------------------
ALTER TABLE attendance ADD COLUMN nsr_out BIGINT UNSIGNED NULL COMMENT 'NSR da marcacao de SAIDA no nsr_ledger';
ALTER TABLE attendance ADD COLUMN legacy_nsr BIGINT UNSIGNED NULL COMMENT 'NSR emitido antes da migracao para o ledger';
CREATE INDEX idx_attendance_nsr_out ON attendance (nsr_out);
CREATE INDEX idx_attendance_legacy_nsr ON attendance (legacy_nsr);

-- ----------------------------------------------------------------------------
-- Modo de operação do ledger:
--   off      = não grava (estado inicial)
--   shadow   = grava, mas falha de gravação NUNCA derruba o registro de ponto
--   enforced = falha de gravação aborta a transação
-- Rodar semanas em `shadow` com verificação diária antes de virar `enforced`.
-- ----------------------------------------------------------------------------
INSERT INTO app_settings (k, v) VALUES ('ledger_mode', 'off')
  ON DUPLICATE KEY UPDATE v = v;

-- ============================================================================
-- LGPD: consentimento por finalidade, registro de tratamento e retenção
-- Fase 7 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- Resolve NC-31 (consentimento só em localStorage), NC-32 (sem consentimento
-- para geolocalização), NC-35 (sem anonimização) e NC-36 (sem registro de
-- operações de tratamento — art. 37).
--
-- A tabela `lgpd_consent` já existia e NUNCA foi usada: zero linhas. O aceite
-- do termo vivia em `localStorage.setItem('lgpd-consent-given')` — limpar o
-- navegador apagava a "prova". Para dado biométrico, que é sensível (art. 11),
-- isso não é consentimento oponível a ninguém.
--
-- As colunas novas endereçam o que faltava para o registro valer:
--   purpose      — o art. 9º exige finalidade ESPECÍFICA. Consentir com
--                  biometria não é consentir com geolocalização.
--   term_version — sem saber QUAL texto a pessoa aceitou, o registro não prova
--                  o conteúdo do consentimento.
--   revoked_*    — o art. 8º §5º garante revogação a qualquer momento, e ela
--                  precisa ficar registrada com data e motivo.
-- ============================================================================

ALTER TABLE lgpd_consent ADD COLUMN purpose VARCHAR(32) NOT NULL DEFAULT 'biometria'
  COMMENT 'biometria | geolocalizacao | foto | outro';
ALTER TABLE lgpd_consent ADD COLUMN term_version VARCHAR(20) NOT NULL DEFAULT '1.0'
  COMMENT 'versao do termo aceito';
ALTER TABLE lgpd_consent ADD COLUMN term_hash CHAR(64) NULL
  COMMENT 'SHA-256 do texto exato aceito — prova o conteudo, nao so a versao';
ALTER TABLE lgpd_consent ADD COLUMN revoked_reason VARCHAR(255) NULL;
ALTER TABLE lgpd_consent ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'portal'
  COMMENT 'portal | kiosk | admin | importacao';

CREATE INDEX idx_consent_teacher_purpose ON lgpd_consent (teacher_id, purpose, revoked_at);
CREATE INDEX idx_consent_date ON lgpd_consent (consent_date);

-- ----------------------------------------------------------------------------
-- Registro de operações de tratamento (art. 37 da LGPD).
--
-- O controlador precisa manter registro das operações que realiza sobre dados
-- pessoais. Aqui ele é ALIMENTADO PELO PRÓPRIO SISTEMA a cada operação
-- relevante (expurgo, anonimização, exportação, acesso a biometria), em vez de
-- ser uma planilha que envelhece — que é o modo como esse registro costuma
-- deixar de refletir a realidade.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lgpd_processing_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  operation    VARCHAR(48) NOT NULL COMMENT 'purge_photos | anonymize | export | consent_given | consent_revoked | biometric_access',
  data_category VARCHAR(32) NOT NULL COMMENT 'biometrico | geolocalizacao | identificacao | jornada | imagem',
  legal_basis  VARCHAR(48) NOT NULL COMMENT 'consentimento | obrigacao_legal | execucao_contrato',
  teacher_id   INT NULL COMMENT 'titular, quando individual',
  affected     INT NOT NULL DEFAULT 0 COMMENT 'quantidade de registros afetados',
  actor        VARCHAR(64) NULL COMMENT 'admin:<id> | cron | sistema',
  detail       VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_proc_when (occurred_at),
  KEY idx_proc_op (operation, occurred_at),
  KEY idx_proc_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Marcação de anonimização no cadastro do trabalhador.
-- Anonimizar não é apagar: os registros de jornada precisam sobreviver pelo
-- prazo legal (a Portaria exige preservação do NSR), mas os dados que
-- identificam a pessoa podem e devem ser removidos quando não há mais
-- finalidade que os justifique.
-- ----------------------------------------------------------------------------
ALTER TABLE teachers ADD COLUMN anonymized_at DATETIME NULL
  COMMENT 'quando os dados identificadores foram removidos (LGPD art. 16)';

-- Configurações de retenção, para deixarem de ser constantes espalhadas.
INSERT INTO app_settings (k, v) VALUES ('retention_photos_days', '90')
  ON DUPLICATE KEY UPDATE v = v;
INSERT INTO app_settings (k, v) VALUES ('retention_auth_logs_days', '365')
  ON DUPLICATE KEY UPDATE v = v;
INSERT INTO app_settings (k, v) VALUES ('retention_audit_logs_days', '1825')
  ON DUPLICATE KEY UPDATE v = v;
INSERT INTO app_settings (k, v) VALUES ('lgpd_term_version', '1.0')
  ON DUPLICATE KEY UPDATE v = v;

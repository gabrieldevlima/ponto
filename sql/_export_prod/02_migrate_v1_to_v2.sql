-- =====================================================================
-- MIGRAÇÃO v1 → v2 — DEEDO Ponto (PACOTE CONSOLIDADO PARA PRODUÇÃO)
-- =====================================================================
-- Gerado: 2026-04-28
-- Origem: ponto_oeiras2 local (XAMPP) — testado idempotente
--
-- ⚠️  ANTES DE RODAR: faça backup completo da produção:
--     mysqldump -u USER -p --single-transaction --routines --triggers \
--       --hex-blob NOME_DO_BANCO > backup_pre_v2_PROD.sql
--
-- ⚠️  Cada migration é idempotente — re-execução é segura. Se uma falhar,
--     o script para via mysql -v --halt-on-error.
--
-- Ordem de execução (12 blocos + register):
--  01. consolidate_schema_2026_04
--  02. add_pin_and_trusted_devices
--  02b. ALTER COLLATE teacher_trusted_devices (anti collation drift)
--  03. add_device_token
--  04. add_attendance_client_id
--  05. add_attendance_checkout_client_id
--  06. add_client_recorded_at
--  07. add_gps_fallback_settings
--  08. set_face_thresholds_3rd_layer  (SOBRESCREVE 14 settings!)
--  09. fix_audit_logs_nullable_admin
--  10. backfill_trusted_devices_2026_04_27
--  11. v1_to_v2_finalize  (nsr_sequence + view + pin_hash)
--  12. add_min_checkout_gap  (anti race entrada/saída)
--  13. register all in applied_migrations
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────
-- 00. DEDUP + UNIQUE em app_settings.k
-- ─────────────────────────────────────────────────────────────────────
-- v1 schema definiu app_settings com PK em id mas SEM UNIQUE em k.
-- Resultado: INSERT IGNORE / ON DUPLICATE KEY UPDATE não deduplicam,
-- e múltiplos runs geram linhas duplicadas. Sem este passo, blocos 07/08/12
-- seriam não-idempotentes em produção.
--
-- Estratégia: para cada k duplicada, mantém a linha de maior id (mais recente
-- via auto_increment) e deleta as outras. Depois adiciona UNIQUE.

-- 0a. Deleta duplicatas mantendo a de maior id por chave
DELETE s1 FROM app_settings s1
INNER JOIN app_settings s2
  ON s1.k = s2.k
 AND s1.id < s2.id;

-- 0b. Adiciona UNIQUE em k (idempotente — só cria se não existir)
SET @_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'app_settings'
                AND INDEX_NAME = 'uk_app_settings_k');
SET @_sql := IF(@_idx = 0,
  'ALTER TABLE app_settings ADD UNIQUE INDEX uk_app_settings_k (k)',
  'DO 0');
PREPARE _stmt FROM @_sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ─────────────────────────────────────────────────────────────────────
-- 01. consolidate_schema_2026_04
-- ─────────────────────────────────────────────────────────────────────
-- =====================================================================
-- CONSOLIDATE_SCHEMA_2026_04 — Auditoria de 2026-04-26
-- =====================================================================
-- Objetivo: levar o banco a 100% de consistência.
--
-- 1. Atualiza schema da tabela `applied_migrations` (telemetria que
--    helpers.php tenta criar mas falhou silenciosamente).
-- 2. Padroniza collation utf8mb4_unicode_ci nas 3 tabelas em drift.
-- 3. Recupera school_id NULL em attendance via primeira filiação
--    do colaborador em teacher_schools.
-- 4. Remove linha corrompida attendance.id=1754 (date='0001-01-01').
-- 5. Cria índices secundários ausentes (full table scan → index lookup).
-- 6. Cria tabela `permissions` ausente.
-- 7. (FKs ficam para uma migration posterior — exigem revisão de
--     ON DELETE/UPDATE caso a caso e podem afetar performance de DELETE.)
--
-- Idempotente: pode ser re-executado sem efeito colateral.
-- =====================================================================

-- 1. APPLIED_MIGRATIONS — adicionar colunas de telemetria + UNIQUE em filename
ALTER TABLE applied_migrations
  ADD COLUMN IF NOT EXISTS content_sha CHAR(64) NULL AFTER applied_at,
  ADD COLUMN IF NOT EXISTS duration_ms INT NULL AFTER content_sha,
  ADD COLUMN IF NOT EXISTS statement_count INT NULL AFTER duration_ms,
  ADD COLUMN IF NOT EXISTS failure_reason TEXT NULL AFTER statement_count;

-- UNIQUE em filename (não há duplicatas hoje, seguro)
CREATE UNIQUE INDEX IF NOT EXISTS uk_applied_migrations_filename ON applied_migrations(filename);

-- 2. COLLATION DRIFT — converter para utf8mb4_unicode_ci
ALTER TABLE applied_migrations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE face_rate_limits CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE liveness_nonces CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. RECUPERAR school_id NULL — usa primeira filiação ativa em teacher_schools.
--    Resolve 768 das 777 NULLs (os 9 sem teacher_schools ficam NULL para ação manual).
UPDATE attendance a
JOIN (
  SELECT teacher_id, MIN(school_id) AS first_school_id
    FROM teacher_schools
   GROUP BY teacher_id
) ts ON ts.teacher_id = a.teacher_id
SET a.school_id = ts.first_school_id
WHERE a.school_id IS NULL;

-- 4. REMOVER LINHA CORROMPIDA — attendance.id=1754 com date='0001-01-01'
--    (Mantém-se em backup pre_audit_2026-04-26.sql se precisar reverter.)
DELETE FROM attendance WHERE id = 1754 AND `date` = '0001-01-01';

-- 5. ÍNDICES SECUNDÁRIOS — performance de relatórios e auth
CREATE INDEX IF NOT EXISTS idx_attendance_teacher_date ON attendance(teacher_id, `date`);
CREATE INDEX IF NOT EXISTS idx_attendance_school_date ON attendance(school_id, `date`);
CREATE INDEX IF NOT EXISTS idx_attendance_date ON attendance(`date`);
CREATE INDEX IF NOT EXISTS idx_attendance_nsr ON attendance(nsr);
CREATE INDEX IF NOT EXISTS idx_attendance_approved ON attendance(approved);
CREATE INDEX IF NOT EXISTS idx_attendance_method ON attendance(method);
CREATE INDEX IF NOT EXISTS idx_attendance_fraud ON attendance(fraud_risk_level);

CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action);

CREATE INDEX IF NOT EXISTS idx_auth_attempts_ip ON auth_attempt_logs(ip_address);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_identifier ON auth_attempt_logs(identifier);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_type_success ON auth_attempt_logs(attempt_type, success, created_at);

CREATE INDEX IF NOT EXISTS idx_hour_bank_teacher_date ON hour_bank_entries(teacher_id, `date`);
CREATE INDEX IF NOT EXISTS idx_hour_bank_ref ON hour_bank_entries(ref_attendance_id);

CREATE INDEX IF NOT EXISTS idx_overtime_teacher ON overtime_requests(teacher_id);
CREATE INDEX IF NOT EXISTS idx_overtime_attendance ON overtime_requests(attendance_id);
CREATE INDEX IF NOT EXISTS idx_overtime_status ON overtime_requests(status);

CREATE INDEX IF NOT EXISTS idx_leaves_teacher ON leaves(teacher_id);
CREATE INDEX IF NOT EXISTS idx_leaves_dates ON leaves(start_date, end_date);

CREATE INDEX IF NOT EXISTS idx_attendance_edits_attendance ON attendance_edits(attendance_id);
CREATE INDEX IF NOT EXISTS idx_attendance_edits_admin ON attendance_edits(edited_by);

CREATE INDEX IF NOT EXISTS idx_teachers_active ON teachers(active);
CREATE INDEX IF NOT EXISTS idx_teachers_cpf ON teachers(cpf);

CREATE INDEX IF NOT EXISTS idx_liveness_nonces_teacher ON liveness_nonces(teacher_id, nonce);
CREATE INDEX IF NOT EXISTS idx_liveness_nonces_created ON liveness_nonces(created_at);

CREATE INDEX IF NOT EXISTS idx_collab_time_sched_teacher ON collaborator_time_schedules(teacher_id);
CREATE INDEX IF NOT EXISTS idx_teacher_schedules_teacher ON teacher_schedules(teacher_id);

-- 6. PERMISSIONS — tabela ausente que install.sql declara
CREATE TABLE IF NOT EXISTS permissions (
  id INT NOT NULL AUTO_INCREMENT,
  admin_id INT NOT NULL,
  permission_key VARCHAR(64) NOT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_admin_perm (admin_id, permission_key),
  KEY idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- 02. add_pin_and_trusted_devices
-- ─────────────────────────────────────────────────────────────────────
-- Reintroduz PIN como método padrão de registro de ponto, com segurança adaptativa.
-- Adiciona tabela teacher_trusted_devices para rastrear dispositivos confiáveis.
-- Mantém compatibilidade com face/CPF existentes; method já é VARCHAR, aceita 'pin'.

ALTER TABLE teachers
  ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) NULL AFTER cpf,
  ADD COLUMN IF NOT EXISTS pin_changed_at DATETIME NULL AFTER pin_hash,
  ADD COLUMN IF NOT EXISTS pin_self_enroll_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER pin_changed_at;

CREATE TABLE IF NOT EXISTS teacher_trusted_devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id INT NOT NULL,
  device_fingerprint CHAR(64) NOT NULL,
  label VARCHAR(100) NULL,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  enrollment_method ENUM('face_validated','repeated_use','admin') NOT NULL DEFAULT 'repeated_use',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_teacher_fp (teacher_id, device_fingerprint),
  KEY idx_teacher_active (teacher_id, is_active),
  KEY idx_last_used (last_used_at),
  CONSTRAINT fk_ttd_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (k, v) VALUES
  ('pin_length', '6'),
  ('pin_max_failures_window', '10'),
  ('pin_rate_limit_window_sec', '300'),
  ('pin_max_attempts_30min', '20'),
  ('trusted_device_inactive_days', '90'),
  ('trusted_device_max_per_teacher', '5'),
  ('trusted_device_repeat_threshold', '3'),
  ('trusted_device_repeat_window_days', '7'),
  ('adaptive_time_check', '0'),
  ('pin_stepup_after_reset_hours', '24');

-- ─────────────────────────────────────────────────────────────────────
-- 02b. Corrige collation de teacher_trusted_devices (antes do backfill)
-- ─────────────────────────────────────────────────────────────────────
ALTER TABLE teacher_trusted_devices CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- 03. add_device_token
-- ─────────────────────────────────────────────────────────────────────
-- Persistência do dispositivo via token server-issued em cookie httpOnly,
-- em vez de depender do fingerprint cacheado no localStorage do cliente.
--
-- Motivação: limpar cache do navegador não deveria fazer o sistema entender
-- que é um aparelho novo. O cookie httpOnly sobrevive à limpeza de cache
-- padrão (é preciso limpar cookies explicitamente para removê-lo), alinhando
-- o comportamento com a expectativa do usuário.
--
-- Fluxo:
--   1. Primeira autenticação bem-sucedida neste device → servidor gera token
--      aleatório de 64 hex, grava em teacher_trusted_devices.device_token,
--      e define cookie `ponto_device` (httpOnly, SameSite=Lax, 180 dias).
--   2. Próximas requests trazem o cookie automaticamente. O backend consulta
--      device_token → se match + teacher ok → device trusted (sem step-up).
--   3. Se cookie ausente (browser novo, cookies limpos, etc.), fallback para
--      device_fingerprint legacy. Se também não bater → step-up facial.
--   4. Step-up bem-sucedido em device novo → servidor emite novo token +
--      cookie → device passa a ser trusted.

ALTER TABLE teacher_trusted_devices
  ADD COLUMN IF NOT EXISTS device_token CHAR(64) NULL AFTER device_fingerprint,
  ADD UNIQUE INDEX IF NOT EXISTS uk_ttd_device_token (device_token);

-- ─────────────────────────────────────────────────────────────────────
-- 04. add_attendance_client_id
-- ─────────────────────────────────────────────────────────────────────
-- Idempotência para check-ins que vêm de fila offline.
-- O frontend gera um client_id (UUID) antes de tentar enviar. Se o sync
-- disparar duas vezes (evento online + Background Sync + reload), o segundo
-- POST detecta o client_id já gravado e retorna 'already_registered' em vez
-- de criar um ponto duplicado.
--
-- NULL é aceito em múltiplas linhas pelo UNIQUE do MySQL (semântica NULL ≠ NULL).
-- Registros antigos continuam com client_id = NULL sem violar a constraint.

ALTER TABLE attendance
  ADD COLUMN IF NOT EXISTS client_id VARCHAR(64) NULL AFTER id;

-- Idempotência cross-engine (MariaDB e MySQL 8): só cria se não existir.
SET @_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'attendance'
                AND INDEX_NAME = 'uk_attendance_client_id');
SET @_sql := IF(@_idx = 0,
  'CREATE UNIQUE INDEX uk_attendance_client_id ON attendance(client_id)',
  'DO 0');
PREPARE _stmt FROM @_sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ─────────────────────────────────────────────────────────────────────
-- 05. add_attendance_checkout_client_id
-- ─────────────────────────────────────────────────────────────────────
-- Idempotência para checkouts (saída) que vêm de fila offline.
-- Complementa add_attendance_client_id.sql (que cobre apenas a entrada).
--
-- Sem isso, retry de SW após falha de rede mid-flight criava entrada-fantasma:
-- 1ª tentativa fechava o ponto via UPDATE; resposta perdia no caminho;
-- 2ª tentativa não achava ponto aberto e caía no ramo entrada, gerando
-- novo registro com check_in = NOW() (timestamp errado).
--
-- NULL é aceito em múltiplas linhas pelo UNIQUE do MySQL (semântica NULL ≠ NULL).
-- Registros antigos continuam com checkout_client_id = NULL sem violar a constraint.

ALTER TABLE attendance
  ADD COLUMN IF NOT EXISTS checkout_client_id VARCHAR(64) NULL AFTER client_id;

-- Idempotência cross-engine: só cria se não existir.
SET @_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'attendance'
                AND INDEX_NAME = 'uk_attendance_checkout_client_id');
SET @_sql := IF(@_idx = 0,
  'CREATE UNIQUE INDEX uk_attendance_checkout_client_id ON attendance(checkout_client_id)',
  'DO 0');
PREPARE _stmt FROM @_sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ─────────────────────────────────────────────────────────────────────
-- 06. add_client_recorded_at
-- ─────────────────────────────────────────────────────────────────────
-- S1: separa timestamp do servidor (recorded_at, sempre NOW() na chegada)
-- do timestamp do cliente (client_recorded_at). Anti-fraude: cliente não
-- pode mais "datar" pontos no passado/futuro. O delta é registrado para
-- auditoria de pontos que ficaram offline e dropparam tarde.

ALTER TABLE attendance
  ADD COLUMN IF NOT EXISTS client_recorded_at DATETIME NULL AFTER recorded_at,
  ADD COLUMN IF NOT EXISTS offline_delay_seconds INT NULL AFTER client_recorded_at;

-- Backfill: pontos antigos que tinham só recorded_at — assume que client = server
UPDATE attendance
   SET client_recorded_at = recorded_at,
       offline_delay_seconds = 0
 WHERE client_recorded_at IS NULL
   AND recorded_at IS NOT NULL;

-- Índice para admin filtrar pontos com delay suspeito
CREATE INDEX IF NOT EXISTS idx_attendance_offline_delay ON attendance(offline_delay_seconds);

-- ─────────────────────────────────────────────────────────────────────
-- 07. add_gps_fallback_settings
-- ─────────────────────────────────────────────────────────────────────
-- Configurações para o fallback inteligente de GPS no check-in.
-- Documentação completa em api/checkin.php (bloco "Validação de Geolocalização").
--
--  gps_max_accuracy_m       — Acima deste valor, GPS é considerado "não
--                              confiável" e cai pra fallback declarativo
--                              (escolha manual de escola). Default: 200m.
--
--  gps_radius_extra_max_m   — Cap da margem extra adicionada ao raio do
--                              geofence quando o GPS é impreciso. Raio
--                              efetivo = geofence_radius_m + min(accuracy,
--                              gps_radius_extra_max_m). Default: 200m.
--
-- Use INSERT IGNORE para não sobrescrever valores se admin tunou via UI.

INSERT IGNORE INTO app_settings (k, v) VALUES
  ('gps_max_accuracy_m',     '200'),
  ('gps_radius_extra_max_m', '200');

-- ─────────────────────────────────────────────────────────────────────
-- 08. set_face_thresholds_3rd_layer  (SOBRESCREVE 14 settings)
-- ─────────────────────────────────────────────────────────────────────
-- Política: face é a 3ª camada de identificação (após CPF + PIN). Como a
-- identidade já é provada pelos 2 fatores anteriores, o reconhecimento
-- facial pode ser permissivo: aceita variação real (luz, óculos, ângulo)
-- sem rejeitar a pessoa certa. A guardrail contra pessoa errada é o
-- `second_best_margin` (delta entre 1º e 2º best match).
--
-- Esses valores foram calibrados após auditoria nesta sessão. Se já
-- existirem (admin tunado), são SOBRESCRITOS — assumimos que esta
-- migration estabelece o baseline correto pós-deploy.

INSERT INTO app_settings (k, v) VALUES
  ('face_checkin_threshold',                '0.70'),
  ('face_checkin_consensus_threshold',      '0.70'),
  ('face_checkin_consensus_ratio',          '0.20'),
  ('face_checkin_margin',                   '0.05'),
  ('face_identify_threshold',               '0.65'),
  ('face_identify_consensus_threshold',     '0.65'),
  ('face_identify_consensus_ratio',         '0.30'),
  ('face_identify_margin',                  '0.08'),
  ('face_threshold_checkin',                '0.70'),
  ('face_threshold_identify',               '0.65'),
  ('face_conflict_threshold',               '0.40'),
  ('face_uniqueness_threshold',             '0.45'),
  ('face_single_descriptor_strict_factor',  '0.95'),
  ('face_min_descriptors_for_auth',         '1')
ON DUPLICATE KEY UPDATE v = VALUES(v);

-- ─────────────────────────────────────────────────────────────────────
-- 09. fix_audit_logs_nullable_admin
-- ─────────────────────────────────────────────────────────────────────
-- Fix: audit_logs.admin_id precisa ser NULL para aceitar ações de colaboradores.
-- A migration original `add_audit_logs_table.sql` criou a coluna como NOT NULL,
-- mas o sistema grava eventos de colaborador (ex: login/logout) onde
-- admin_id é NULL. Sem essa correção, os INSERTs falham silenciosamente no
-- try/catch de helpers.php `audit_log()` — log nunca é gravado.
--
-- Idempotente: executar N vezes é seguro (MODIFY não cria duplicata).

ALTER TABLE audit_logs MODIFY COLUMN admin_id INT NULL;

-- ─────────────────────────────────────────────────────────────────────
-- 10. backfill_trusted_devices_2026_04_27
-- ─────────────────────────────────────────────────────────────────────
-- =====================================================================
-- BACKFILL_TRUSTED_DEVICES — 2026-04-27
-- =====================================================================
-- Contexto: a tabela `teacher_trusted_devices` só passou a existir em
-- 2026-04-26 (auditoria). Antes disso, todas as chamadas a
-- `trusted_device_enroll()` falhavam silenciosamente (table missing,
-- caught no try/catch). Resultado em produção: cada check-in pedia step-up
-- facial como se o aparelho fosse sempre novo.
--
-- Adicionalmente, `helpers.php::trusted_device_consider_repeat_enroll()`
-- tinha um bug onde recebia o FP em formato hashed (SHA-256) e
-- buscava na tabela `attendance` (que armazena o FP cru do cliente). A
-- assinatura foi corrigida para receber ambos os formatos.
--
-- Este backfill enrolla retroativamente os colaboradores que já tinham
-- 3+ check-ins do mesmo aparelho nos últimos 30 dias — política idêntica
-- à do `trusted_device_consider_repeat_enroll()`. Sem isso, cada um
-- desses ~140 colaboradores teria que passar por face step-up uma vez,
-- causando atrito desnecessário no primeiro ponto pós-deploy.
--
-- Idempotente: re-execução não duplica registros (LEFT JOIN + IS NULL).
-- =====================================================================

INSERT INTO teacher_trusted_devices
    (teacher_id, device_fingerprint, device_token, enrollment_method, enrolled_at, last_used_at, is_active)
SELECT
    a.teacher_id,
    a.device_fingerprint,
    LOWER(SHA2(CONCAT(a.teacher_id, ':', a.device_fingerprint, ':', UUID()), 256)) AS device_token,
    'repeated_use' AS enrollment_method,
    NOW() AS enrolled_at,
    MAX(a.check_in) AS last_used_at,
    1 AS is_active
FROM attendance a
JOIN teachers t
    ON t.id = a.teacher_id
   AND t.active = 1
LEFT JOIN teacher_trusted_devices ttd
    ON ttd.teacher_id = a.teacher_id
   AND ttd.device_fingerprint = a.device_fingerprint
WHERE a.device_fingerprint IS NOT NULL
  AND a.device_fingerprint != ''
  AND LENGTH(a.device_fingerprint) = 64        -- só FPs já no formato SHA-256 (64 hex)
  AND a.check_in >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  AND ttd.id IS NULL                            -- evita duplicar enrollments existentes
GROUP BY a.teacher_id, a.device_fingerprint
HAVING COUNT(*) >= 3;

-- ─────────────────────────────────────────────────────────────────────
-- 11. 2026_04_27_v1_to_v2_finalize
-- ─────────────────────────────────────────────────────────────────────
-- =====================================================================
-- 2026-04-27 — FINALIZAÇÃO v1→v2
-- =====================================================================
-- Após aplicar as 10 migrations órfãs (consolidate, pin_trusted, device_token,
-- client_id, checkout_client_id, client_recorded_at, gps_fallback,
-- face_thresholds, fix_audit_logs_nullable, backfill_trusted_devices), restam
-- 3 ajustes para fechar o gap v1→v2:
--   A) Inicializar nsr_sequence preservando MAX(nsr) atual.
--   B) Recriar v_payslips_full como VIEW (estava como BASE TABLE neste schema,
--      diferente das outras 4 instâncias que estão como VIEW).
--   C) Relaxar teachers.pin_hash de NOT NULL para NULL (v2 permite teachers
--      sem PIN — self-enroll). Limpar empty strings → NULL.
-- =====================================================================

-- A) NSR sequence — seedar com MAX(nsr) existente para não quebrar Portaria 671
INSERT INTO nsr_sequence (id, current_nsr)
VALUES (1, COALESCE((SELECT MAX(nsr) FROM attendance), 0))
ON DUPLICATE KEY UPDATE current_nsr = GREATEST(current_nsr, VALUES(current_nsr));

-- B) v_payslips_full: BASE TABLE → VIEW
-- Definição extraída de outra instância (ponto.v_payslips_full).
-- Tabela está vazia (0 registros) → DROP é seguro.
DROP TABLE IF EXISTS v_payslips_full;
CREATE OR REPLACE VIEW v_payslips_full AS
SELECT
  p.id AS id,
  p.reference_month AS reference_month,
  DATE_FORMAT(p.reference_month, '%m/%Y') AS month_formatted,
  YEAR(p.reference_month) AS year,
  p.teacher_id AS teacher_id,
  t.name AS teacher_name,
  t.cpf AS teacher_cpf,
  p.base_salary AS base_salary,
  p.worked_minutes AS worked_minutes,
  p.expected_minutes AS expected_minutes,
  p.overtime_minutes AS overtime_minutes,
  p.overtime_value AS overtime_value,
  p.deficit_minutes AS deficit_minutes,
  p.discount_value AS discount_value,
  p.gross_total AS gross_total,
  p.net_total AS net_total,
  p.generated_at AS generated_at,
  p.viewed_by_teacher_at AS viewed_by_teacher_at,
  CASE WHEN p.viewed_by_teacher_at IS NOT NULL THEN 1 ELSE 0 END AS was_viewed
FROM payslips p
JOIN teachers t ON t.id = p.teacher_id
ORDER BY p.reference_month DESC, t.name;

-- C) teachers.pin_hash: NOT NULL → NULL (v2 permite self-enroll)
ALTER TABLE teachers MODIFY COLUMN pin_hash VARCHAR(255) NULL;
UPDATE teachers SET pin_hash = NULL WHERE pin_hash = '';

-- ─────────────────────────────────────────────────────────────────────
-- 12. 2026_04_27_add_min_checkout_gap
-- ─────────────────────────────────────────────────────────────────────
-- Adiciona setting `min_checkout_gap_seconds` que controla o intervalo mínimo
-- entre check_in e check_out aceito pelo backend.
--
-- Bug que motivou: colaboradores reportavam que clicar em "registrar entrada"
-- às vezes gravava entrada e saída ao mesmo tempo. Causa raiz: race condition
-- entre 2 POSTs próximos do mesmo teacher (double-click, multi-aba, SW retry
-- com client_id distinto). O 1º POST cria entrada; o 2º vê a entrada e cai
-- na branch de saída, atualizando o mesmo registro com check_out=NOW().
-- Evidência em produção: 12 registros com gap < 60s, 3 com gap < 30s.
--
-- Mitigação: api/checkin.php agora rejeita saída quando o check_in existente
-- foi há menos de min_checkout_gap_seconds segundos. Combinado com GET_LOCK
-- por (teacher_id, date), a janela de race fecha.
--
-- Default 60s é conservador (ninguém bate ponto entrando e saindo em <1min).
-- Admin pode tunar via UI de settings se necessário.

INSERT IGNORE INTO app_settings (k, v) VALUES ('min_checkout_gap_seconds', '60');

-- ─────────────────────────────────────────────────────────────────────
-- 13. Registrar todas migrations em applied_migrations (idempotente)
-- ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO applied_migrations (filename, applied_at) VALUES
  ('consolidate_schema_2026_04.sql', NOW()),
  ('add_pin_and_trusted_devices.sql', NOW()),
  ('add_device_token.sql', NOW()),
  ('add_attendance_client_id.sql', NOW()),
  ('add_attendance_checkout_client_id.sql', NOW()),
  ('add_client_recorded_at.sql', NOW()),
  ('add_gps_fallback_settings.sql', NOW()),
  ('set_face_thresholds_3rd_layer.sql', NOW()),
  ('fix_audit_logs_nullable_admin.sql', NOW()),
  ('backfill_trusted_devices_2026_04_27.sql', NOW()),
  ('2026_04_27_v1_to_v2_finalize.sql', NOW()),
  ('2026_04_27_add_min_checkout_gap.sql', NOW());

SET FOREIGN_KEY_CHECKS = 1;

-- Migração v1→v2 completa. Rode 03_post_check.sql para validar.

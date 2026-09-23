-- ============================================================================
-- Snapshot do schema de PRODUÇÃO — base do CI. NÃO EDITE À MÃO.
-- Gerado por bin/schema_snapshot.php em 2026-09-23T16:46:27Z
-- Servidor: 11.8.9-MariaDB-log
--
-- Só estrutura e a lista de migrações aplicadas. Nenhum dado de negócio.
-- Regenere quando uma migração for aplicada em produção.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ---------------------------------------------------------------- tabelas (42)

CREATE TABLE `academic_calendar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `semester` tinyint(4) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `cpf` varchar(11) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('network_admin','school_admin') NOT NULL DEFAULT 'network_admin',
  `school_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `antifraud_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `applied_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  `content_sha` char(64) DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `statement_count` int(11) DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_applied_migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `k` varchar(100) NOT NULL,
  `v` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_settings_k` (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` varchar(64) DEFAULT NULL,
  `checkout_client_id` varchar(64) DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `check_in_lat` double DEFAULT NULL,
  `check_in_lng` double DEFAULT NULL,
  `check_in_acc` double DEFAULT NULL,
  `check_out_lat` double DEFAULT NULL,
  `check_out_lng` double DEFAULT NULL,
  `check_out_acc` double DEFAULT NULL,
  `method` varchar(50) DEFAULT 'pin',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `approved` tinyint(1) DEFAULT NULL,
  `manual_reason_id` int(11) DEFAULT NULL,
  `manual_reason_text` varchar(255) DEFAULT NULL,
  `superseded_by_id` int(11) DEFAULT NULL COMMENT 'Se preenchido, este registro foi marcado como duplicata por admin; ID do registro mantido',
  `removed_at` datetime DEFAULT NULL COMMENT 'Quando o admin anulou (soft-delete) este registro. NULL = registro ativo',
  `removed_by_admin_id` int(11) DEFAULT NULL COMMENT 'Admin que anulou o registro',
  `removed_reason` varchar(255) DEFAULT NULL COMMENT 'Motivo informado pelo admin ao anular',
  `editado_por` int(11) DEFAULT NULL,
  `data_edicao` datetime DEFAULT NULL,
  `motivo_edicao` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `nsr` bigint(20) unsigned NOT NULL,
  `record_mode` enum('online','offline') NOT NULL DEFAULT 'online',
  `recorded_at` datetime DEFAULT NULL,
  `client_recorded_at` datetime DEFAULT NULL,
  `offline_delay_seconds` int(11) DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `hlb_sync_status` enum('synced','failed','pending','legacy') NOT NULL DEFAULT 'pending',
  `hlb_offset_seconds` int(11) DEFAULT 0,
  `device_identifier` varchar(255) DEFAULT NULL,
  `receipt_generated` tinyint(1) DEFAULT 0,
  `receipt_viewed_at` timestamp NULL DEFAULT NULL,
  `fraud_risk_level` tinyint(4) DEFAULT 0,
  `liveness_score` float DEFAULT NULL,
  `liveness_data` longtext DEFAULT NULL CHECK (json_valid(`liveness_data`)),
  `gps_mock_detected` tinyint(1) DEFAULT 0,
  `device_fingerprint` varchar(255) DEFAULT NULL,
  `pending_reasons` text DEFAULT NULL COMMENT 'Motivos JSON quando ponto fica pendente',
  `photo_deleted` tinyint(1) DEFAULT 0 COMMENT 'Flag indicando se a foto foi deletada automaticamente',
  `photo_deleted_at` datetime DEFAULT NULL COMMENT 'Data/hora em que a foto foi deletada',
  `class_period_id` int(11) DEFAULT NULL COMMENT 'ID do período/aula (grade horária)',
  `record_type` enum('work','break') NOT NULL DEFAULT 'work' COMMENT 'work=jornada produtiva (par check_in/check_out de trabalho), break=intervalo',
  `parent_attendance_id` int(11) DEFAULT NULL COMMENT 'Para record_type=break: id do work do mesmo turno (auditoria/agrupamento)',
  `sequence_number` int(11) NOT NULL DEFAULT 1 COMMENT 'Número sequencial do registro no dia',
  `is_overtime_candidate` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Check-in fora da grade - candidato a hora extra',
  `overtime_justification` text DEFAULT NULL COMMENT 'Justificativa do professor para hora extra',
  `manual_by_admin_id` int(11) DEFAULT NULL COMMENT 'ID do admin que inseriu manualmente',
  `manual_created_at` datetime DEFAULT NULL COMMENT 'Data/hora de inserção manual',
  `face_match_confidence` decimal(5,4) DEFAULT NULL,
  `kiosk_device_id` int(11) DEFAULT NULL,
  `kiosk_face_log_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_attendance_client_id` (`client_id`),
  UNIQUE KEY `uk_attendance_checkout_client_id` (`checkout_client_id`),
  KEY `idx_attendance_teacher_date` (`teacher_id`,`date`),
  KEY `idx_attendance_school_date` (`school_id`,`date`),
  KEY `idx_attendance_date` (`date`),
  KEY `idx_attendance_nsr` (`nsr`),
  KEY `idx_attendance_approved` (`approved`),
  KEY `idx_attendance_method` (`method`),
  KEY `idx_attendance_fraud` (`fraud_risk_level`),
  KEY `idx_attendance_offline_delay` (`offline_delay_seconds`),
  KEY `idx_dedupe_in` (`teacher_id`,`date`,`check_in`),
  KEY `idx_dedupe_out` (`teacher_id`,`date`,`check_out`),
  KEY `idx_superseded_by` (`superseded_by_id`),
  KEY `idx_att_teacher_date_type` (`teacher_id`,`date`,`record_type`),
  KEY `fk_att_parent` (`parent_attendance_id`),
  KEY `idx_att_kiosk_device` (`kiosk_device_id`),
  KEY `idx_attendance_removed_at` (`removed_at`),
  CONSTRAINT `fk_att_parent` FOREIGN KEY (`parent_attendance_id`) REFERENCES `attendance` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_superseded` FOREIGN KEY (`superseded_by_id`) REFERENCES `attendance` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `field_changed` varchar(100) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_break_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) NOT NULL COMMENT 'FK attendance.id (registro de intervalo a corrigir)',
  `teacher_id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `date` date NOT NULL COMMENT 'Data do intervalo (snapshot)',
  `original_check_in` datetime DEFAULT NULL COMMENT 'check_in atual antes da correção (auditoria)',
  `original_check_out` datetime DEFAULT NULL COMMENT 'check_out atual antes da correção (auditoria)',
  `proposed_check_in` datetime NOT NULL COMMENT 'check_in proposto pelo colaborador',
  `proposed_check_out` datetime NOT NULL COMMENT 'check_out proposto pelo colaborador',
  `justification` text NOT NULL COMMENT 'Motivo da correção (obrigatório)',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `admin_check_in` datetime DEFAULT NULL COMMENT 'Se admin editou o check_in proposto antes de aprovar',
  `admin_check_out` datetime DEFAULT NULL COMMENT 'Se admin editou o check_out proposto antes de aprovar',
  `admin_observation` text DEFAULT NULL COMMENT 'Observação livre do admin (visível ao colaborador)',
  `rejection_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_break_req_attendance` (`attendance_id`) COMMENT 'Um pedido pendente/aprovado por intervalo (rejeitado pode ser substituído via UPDATE)',
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_teacher_date` (`teacher_id`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_checkout_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `check_in` datetime NOT NULL COMMENT 'Snapshot do check_in original, para auditoria',
  `proposed_check_out` datetime NOT NULL COMMENT 'Horario que o colaborador estima ter saido',
  `justification` text NOT NULL COMMENT 'Motivo do esquecimento (obrigatorio)',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `admin_check_out` datetime DEFAULT NULL COMMENT 'Se admin editou o horario antes de aprovar, registra o ajustado aqui',
  `admin_observation` text DEFAULT NULL COMMENT 'Observacao livre do admin (visivel ao colaborador)',
  `rejection_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_checkout_request` (`attendance_id`),
  KEY `fk_acr_school` (`school_id`),
  KEY `fk_acr_admin` (`approved_by_admin_id`),
  KEY `idx_acr_status` (`status`),
  KEY `idx_acr_teacher_status` (`teacher_id`,`status`),
  KEY `idx_acr_date` (`date`),
  CONSTRAINT `fk_acr_admin` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_acr_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_acr_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_acr_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_edits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) NOT NULL,
  `edited_by` int(11) DEFAULT NULL,
  `edited_at` datetime NOT NULL,
  `reason` text NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `diff_minutes` int(11) DEFAULT NULL,
  `changed_fields` text DEFAULT NULL,
  `before_json` text DEFAULT NULL,
  `after_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attendance_edits_attendance` (`attendance_id`),
  KEY `idx_attendance_edits_admin` (`edited_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL COMMENT 'Tipo da ação (create, update, delete, approve, reject, etc)',
  `entity` varchar(50) NOT NULL COMMENT 'Entidade afetada (attendance, teacher, school, etc)',
  `entity_id` varchar(50) DEFAULT NULL COMMENT 'ID da entidade afetada',
  `payload` text DEFAULT NULL COMMENT 'JSON com detalhes da operação',
  `ip` varchar(45) DEFAULT NULL COMMENT 'IP de origem da ação',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora da ação',
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_entity` (`entity`,`entity_id`),
  KEY `idx_audit_logs_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de auditoria de ações administrativas';

CREATE TABLE `auth_attempt_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attempt_type` varchar(32) NOT NULL,
  `identifier` varchar(191) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `reason` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_auth_attempts_ip` (`ip_address`),
  KEY `idx_auth_attempts_identifier` (`identifier`),
  KEY `idx_auth_attempts_type_success` (`attempt_type`,`success`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `calendar_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `type` enum('holiday','workday','compensation','academic_event','exam_day','recess') NOT NULL DEFAULT 'holiday',
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `recurrence` enum('none','yearly','biannual') NOT NULL DEFAULT 'none',
  `is_working_day` tinyint(1) NOT NULL DEFAULT 0,
  `reflects_weekday` tinyint(4) DEFAULT NULL COMMENT 'Dia da semana referenciado (0=dom..6=sáb). Usado em type=workday p/ aprovar check-in',
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `class_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) DEFAULT NULL,
  `period_number` int(11) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `collaborator_hours_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `weekday` tinyint(4) NOT NULL,
  `total_minutes` int(11) NOT NULL DEFAULT 0,
  `break_minutes` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teacher_weekday` (`teacher_id`,`weekday`),
  CONSTRAINT `collaborator_hours_schedules_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `collaborator_remember_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_teacher` (`teacher_id`),
  CONSTRAINT `fk_remember_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `collaborator_time_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `weekday` tinyint(1) NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `end_next_day` tinyint(1) NOT NULL DEFAULT 0,
  `break_minutes` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_collab_time_sched_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `collaborator_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `schedule_mode` enum('none','classes','time','hours') NOT NULL DEFAULT 'classes',
  `requires_schedule` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employer_config` (
  `id` int(11) NOT NULL DEFAULT 1,
  `company_name` varchar(255) NOT NULL DEFAULT 'Empresa LTDA',
  `cnpj` varchar(18) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(2) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `system_name` varchar(100) NOT NULL DEFAULT 'DEEDO Ponto',
  `system_version` varchar(20) NOT NULL DEFAULT '1.0.0',
  `rep_category` varchar(50) NOT NULL DEFAULT 'REP-P',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `face_rate_limits` (
  `rate_key` varchar(64) NOT NULL,
  `attempts` int(11) DEFAULT 0,
  `window_start` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`rate_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fraud_detection_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `detection_type` varchar(50) NOT NULL,
  `risk_level` tinyint(4) NOT NULL DEFAULT 1,
  `details` longtext DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hour_bank_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `minutes` int(11) NOT NULL,
  `reason` varchar(150) DEFAULT NULL,
  `source` enum('auto','manual','overtime_approved') NOT NULL DEFAULT 'manual',
  `ref_attendance_id` int(11) DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hour_bank_teacher_date` (`teacher_id`,`date`),
  KEY `idx_hour_bank_ref` (`ref_attendance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kiosk_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_token_hash` varchar(255) NOT NULL,
  `name` varchar(120) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `location_label` varchar(160) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `fallback_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `last_seen_at` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kiosk_device_token` (`device_token_hash`),
  KEY `idx_kiosk_device_active` (`active`),
  KEY `idx_kiosk_device_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kiosk_face_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `event_type` enum('identify','checkin','enroll','reenroll','fallback') NOT NULL,
  `status` varchar(40) NOT NULL,
  `confidence` decimal(5,4) DEFAULT NULL,
  `action_attempted` varchar(24) DEFAULT NULL,
  `attendance_id` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `detail` longtext DEFAULT NULL CHECK (json_valid(`detail`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kioskfl_device` (`device_id`),
  KEY `idx_kioskfl_teacher` (`teacher_id`),
  KEY `idx_kioskfl_status` (`status`),
  KEY `idx_kioskfl_event` (`event_type`),
  KEY `idx_kioskfl_created` (`created_at`),
  KEY `idx_kioskfl_attendance` (`attendance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leaves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `type_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_count` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `cid_code` varchar(10) DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `attachment_uploaded_at` timestamp NULL DEFAULT NULL,
  `approved` tinyint(1) DEFAULT NULL,
  `excuses_absence` tinyint(1) DEFAULT NULL COMMENT 'Se 1, este afastamento abona a falta (zera jornada). 0/NULL = conta falta',
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leaves_teacher` (`teacher_id`),
  KEY `idx_leaves_dates` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_attachment_access_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `leave_id` int(11) NOT NULL,
  `accessed_by_admin_id` int(11) DEFAULT NULL,
  `accessed_at` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `action` varchar(50) NOT NULL DEFAULT 'view',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `paid` tinyint(1) NOT NULL DEFAULT 1,
  `affects_bank` tinyint(1) NOT NULL DEFAULT 0,
  `requires_attachment` tinyint(1) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lgpd_consent` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `consent_given` tinyint(1) NOT NULL DEFAULT 0,
  `consent_date` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `liveness_nonces` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `nonce` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_liveness_nonces_teacher` (`teacher_id`,`nonce`),
  KEY `idx_liveness_nonces_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `manual_reasons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mobile_holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `calculation_rule` text DEFAULT NULL,
  `days_offset` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `nsr_sequence` (
  `id` int(11) NOT NULL DEFAULT 1,
  `current_nsr` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `overtime_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `minutes` int(11) NOT NULL,
  `expected_minutes` int(11) NOT NULL DEFAULT 0,
  `worked_minutes` int(11) NOT NULL DEFAULT 0,
  `justification` text DEFAULT NULL COMMENT 'Justificativa fornecida pelo professor',
  `requested_by_employee` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Se 1, a solicitacao foi feita explicitamente pelo colaborador (botao Solicitar hora extra). Se 0, eh registro legado auto-criado pelo sistema.',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_overtime_teacher` (`teacher_id`),
  KEY `idx_overtime_attendance` (`attendance_id`),
  KEY `idx_overtime_status` (`status`),
  KEY `idx_requested_by_employee` (`requested_by_employee`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payslips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `reference_month` date NOT NULL,
  `base_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `worked_minutes` int(11) NOT NULL DEFAULT 0,
  `expected_minutes` int(11) NOT NULL DEFAULT 0,
  `overtime_minutes` int(11) NOT NULL DEFAULT 0,
  `overtime_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deficit_minutes` int(11) NOT NULL DEFAULT 0,
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gross_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `generated_by_admin_id` int(11) DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT current_timestamp(),
  `viewed_by_teacher_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payslip_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payslip_id` int(11) NOT NULL,
  `type` enum('earning','deduction') NOT NULL,
  `description` varchar(150) NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `permission_key` varchar(64) NOT NULL,
  `granted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_perm` (`admin_id`,`permission_key`),
  KEY `idx_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schools` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(50) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `lat` double DEFAULT NULL,
  `lng` double DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(2) DEFAULT NULL,
  `zip_code` varchar(9) DEFAULT NULL,
  `director_name` varchar(150) DEFAULT NULL,
  `school_type` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `cpf` varchar(14) NOT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `pin_changed_at` datetime DEFAULT NULL,
  `pin_self_enroll_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `email` varchar(120) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `type_id` int(11) DEFAULT NULL,
  `base_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `network_wide` tinyint(1) NOT NULL DEFAULT 0,
  `face_descriptors` longtext DEFAULT NULL CHECK (json_valid(`face_descriptors`)),
  `face_enrolled_at` datetime DEFAULT NULL,
  `face_enrollment_version` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_teachers_active` (`active`),
  KEY `idx_teachers_cpf` (`cpf`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_class_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `weekday` tinyint(1) NOT NULL,
  `period_id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `weekday` tinyint(1) NOT NULL,
  `classes_count` int(11) NOT NULL DEFAULT 0,
  `class_minutes` int(11) NOT NULL DEFAULT 60,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_teacher_schedules_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_schools` (
  `teacher_id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  PRIMARY KEY (`teacher_id`,`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_trusted_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `device_fingerprint` char(64) NOT NULL,
  `device_token` char(64) DEFAULT NULL,
  `label` varchar(100) DEFAULT NULL,
  `enrolled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `enrollment_method` enum('face_validated','repeated_use','admin') NOT NULL DEFAULT 'repeated_use',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teacher_fp` (`teacher_id`,`device_fingerprint`),
  UNIQUE KEY `uk_ttd_device_token` (`device_token`),
  KEY `idx_teacher_active` (`teacher_id`,`is_active`),
  KEY `idx_last_used` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- rotinas (1)

DELIMITER $$

CREATE PROCEDURE `generate_payslip`(IN p_teacher_id INT, IN p_month DATE, IN p_admin_id INT)
INSERT INTO payslips (teacher_id, reference_month, base_salary, worked_minutes, expected_minutes,
    overtime_minutes, overtime_value, deficit_minutes, discount_value, gross_total, net_total, generated_by_admin_id)
SELECT t.id, p_month, t.base_salary, 0, 0, 0, 0, 0, 0, t.base_salary, t.base_salary, p_admin_id
FROM teachers t
WHERE t.id = p_teacher_id
ON DUPLICATE KEY UPDATE
    base_salary = VALUES(base_salary),
    worked_minutes = 0,
    expected_minutes = 0,
    overtime_minutes = 0,
    overtime_value = 0,
    deficit_minutes = 0,
    discount_value = 0,
    gross_total = VALUES(gross_total),
    net_total = VALUES(net_total),
    generated_by_admin_id = VALUES(generated_by_admin_id),
    generated_at = CURRENT_TIMESTAMP$$

DELIMITER ;

-- ---------------------------------------------------------------- views (4)

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_attendance_receipts` AS select `a`.`id` AS `id`,`a`.`nsr` AS `nsr`,`a`.`teacher_id` AS `teacher_id`,`t`.`name` AS `teacher_name`,`t`.`cpf` AS `teacher_cpf`,`a`.`date` AS `date`,`a`.`check_in` AS `check_in`,`a`.`check_out` AS `check_out`,`a`.`record_type` AS `record_type`,case when `a`.`record_type` = 'break' and `a`.`check_out` is not null then convert('retorno do intervalo' using utf8mb4) collate utf8mb4_unicode_ci when `a`.`record_type` = 'break' and `a`.`check_in` is not null then convert('início do intervalo' using utf8mb4) collate utf8mb4_unicode_ci when `a`.`check_out` is not null and exists(select 1 from `attendance` `b` where `b`.`parent_attendance_id` = `a`.`id` and `b`.`record_type` = 'break' limit 1) then convert('saída para intervalo' using utf8mb4) collate utf8mb4_unicode_ci when `a`.`check_out` is not null then convert('saída' using utf8mb4) collate utf8mb4_unicode_ci when `a`.`check_in` is not null and exists(select 1 from `attendance` `b` where `b`.`teacher_id` = `a`.`teacher_id` and `b`.`date` = `a`.`date` and `b`.`record_type` = 'break' and `b`.`check_out` is not null and `b`.`check_out` <= `a`.`check_in` limit 1) then convert('retorno do intervalo' using utf8mb4) collate utf8mb4_unicode_ci when `a`.`check_in` is not null then convert('entrada' using utf8mb4) collate utf8mb4_unicode_ci else convert('indefinido' using utf8mb4) collate utf8mb4_unicode_ci end AS `action`,`a`.`record_mode` AS `record_mode`,`a`.`recorded_at` AS `recorded_at`,`a`.`synced_at` AS `synced_at`,`a`.`check_in_lat` AS `latitude`,`a`.`check_in_lng` AS `longitude`,`a`.`photo` AS `photo`,`a`.`approved` AS `approved`,`a`.`hlb_sync_status` AS `hlb_sync_status`,`a`.`device_identifier` AS `device_identifier`,`a`.`receipt_generated` AS `receipt_generated`,`a`.`receipt_viewed_at` AS `receipt_viewed_at`,`e`.`company_name` AS `company_name`,`e`.`cnpj` AS `cnpj`,`e`.`system_name` AS `system_name`,`e`.`system_version` AS `system_version`,`e`.`rep_category` AS `rep_category` from ((`attendance` `a` join `teachers` `t` on(`a`.`teacher_id` = `t`.`id`)) join `employer_config` `e`) order by `a`.`nsr` desc;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_calendar_full` AS select `ce`.`id` AS `id`,`ce`.`date` AS `date`,`ce`.`type` AS `type`,`ce`.`name` AS `name`,`ce`.`description` AS `description`,`ce`.`is_working_day` AS `is_working_day`,`ce`.`recurrence` AS `recurrence`,`ce`.`school_id` AS `school_id`,`s`.`name` AS `school_name`,case when `ce`.`school_id` is null then _utf8mb4'Toda a rede' collate utf8mb4_unicode_ci else `s`.`name` end AS `scope`,dayname(`ce`.`date`) AS `day_of_week`,date_format(`ce`.`date`,'%d/%m/%Y') AS `date_formatted` from (`calendar_exceptions` `ce` left join `schools` `s` on(`s`.`id` = `ce`.`school_id`)) order by `ce`.`date` desc;

CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_fraud_analysis` AS select `t`.`id` AS `teacher_id`,`t`.`name` AS `teacher_name`,count(distinct cast(`fdl`.`created_at` as date)) AS `suspicious_days`,count(`fdl`.`id`) AS `total_detections`,sum(case when `fdl`.`detection_type` = 'gps_mock' then 1 else 0 end) AS `mock_count`,sum(case when `fdl`.`risk_level` >= 2 then 1 else 0 end) AS `high_risk_count`,max(`fdl`.`created_at`) AS `last_detection` from (`teachers` `t` left join `fraud_detection_log` `fdl` on(`fdl`.`teacher_id` = `t`.`id`)) where `fdl`.`created_at` >= current_timestamp() - interval 30 day group by `t`.`id`,`t`.`name` having `total_detections` > 0;

CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_payslips_full` AS select `p`.`id` AS `id`,`p`.`reference_month` AS `reference_month`,date_format(`p`.`reference_month`,'%m/%Y') AS `month_formatted`,year(`p`.`reference_month`) AS `year`,`p`.`teacher_id` AS `teacher_id`,`t`.`name` AS `teacher_name`,`t`.`cpf` AS `teacher_cpf`,`p`.`base_salary` AS `base_salary`,`p`.`worked_minutes` AS `worked_minutes`,`p`.`expected_minutes` AS `expected_minutes`,`p`.`overtime_minutes` AS `overtime_minutes`,`p`.`overtime_value` AS `overtime_value`,`p`.`deficit_minutes` AS `deficit_minutes`,`p`.`discount_value` AS `discount_value`,`p`.`gross_total` AS `gross_total`,`p`.`net_total` AS `net_total`,`p`.`generated_at` AS `generated_at`,`p`.`viewed_by_teacher_at` AS `viewed_by_teacher_at`,case when `p`.`viewed_by_teacher_at` is not null then 1 else 0 end AS `was_viewed` from (`payslips` `p` join `teachers` `t` on(`t`.`id` = `p`.`teacher_id`));

-- ---------------------------------------------------- migrações aplicadas (62)
-- O runner compara filename + content_sha para decidir o que pular.

INSERT INTO applied_migrations (filename, applied_at, content_sha, duration_ms, statement_count, failure_reason) VALUES
  ('2026_04_27_add_min_checkout_gap.sql', '2026-04-27 23:28:49', NULL, NULL, NULL, NULL),
  ('2026_04_27_v1_to_v2_finalize.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('2026_05_13_add_end_next_day.sql', '2026-05-16 18:14:31', '375dbe3145d4d57a32cda17294e3d770d5a0edd01c8ca2694b8be4ce85268e61', '8', '1', NULL),
  ('2026_05_13_add_open_checkin_window.sql', '2026-05-16 18:14:31', '0e37831d6d4f9483573e254c86cf0f6ed4eeb98980a99d44e14e723a21b00299', '0', '1', NULL),
  ('2026_05_16_add_dedupe_indexes.sql', '2026-05-16 18:14:31', 'a877a1278ba173f23fdf6e822ed890a828f6c5fdb57cd10081d63616353cfede', '1', '2', NULL),
  ('2026_05_16_add_reflects_weekday_to_calendar_exceptions.sql', '2026-05-16 18:14:31', 'ed33e6bb16c9f82e3e8a1064f001dfa321a9c193c568690c8ad688cb32fc072c', '1', '1', NULL),
  ('2026_05_17_add_attendance_superseded_by.sql', '2026-06-10 03:11:37', '9f54e9e3dc336c659887eb5f5a34d4eadfeb3ac39145cdf8a1669245111022a4', '175', '4', NULL),
  ('2026_05_17_checkout_regularization.sql', '2026-05-25 18:12:39', '116688cb9ea6a6facd1a49fc6f65012c7c12b53d0fdc395ee22c5eaad965f19c', '0', '1', NULL),
  ('2026_05_17_overtime_optin.sql', '2026-05-25 18:12:39', '9f9ffcc62abdb8ec30a41eb989df1e161908cf871496256bcb9bb14c0667ae34', '1', '2', NULL),
  ('2026_05_25_add_attendance_break_requests.sql', '2026-05-25 17:43:09', '496ff21f396037d168a215c50fbda241b5a51a64dca427d6f42aa6e575269553', '158', '1', NULL),
  ('2026_05_25_add_attendance_break_support.sql', '2026-05-25 17:43:09', 'e82b365b5a3649b1ca03f63a1e5cf0f4ac334367935e357b283ecbf36b780cba', '298', '1', NULL),
  ('2026_05_25_fix_view_collation.sql', '2026-05-25 18:12:39', 'c7514f3eca64ea788b500875dfbcd8c72b3051f509269f444da6ed4ee96d99c5', '9', '1', NULL),
  ('2026_05_25_normalize_all_collations.sql', '2026-05-25 19:11:30', '801e76e4b264d32c7009988aaf97c9603bce33133a59c8a2b7d9aae602323858', '1302', '35', NULL),
  ('2026_05_25_update_v_attendance_receipts.sql', '2026-05-25 17:43:09', '4272db67b7916ff91f512160226625c3737b799efbf400aeb8fa444733523c03', '5', '1', NULL),
  ('2026_05_25_v_attendance_receipts_break_context.sql', '2026-05-25 17:43:09', '2ccfcb41d00d8df8b87660fc1d57ec502e1ea577cabe633c509bd72ddfeb7197', '3', '1', NULL),
  ('2026_05_25_zz_force_view_collation.sql', '2026-05-25 19:11:30', '1adcdf9daaed4f23befa69cca175a7fc88bff488466eba384726b55ab866c5a1', '2', '1', NULL),
  ('2026_05_25_zz3_simplify_v_attendance_receipts.sql', '2026-05-25 19:11:30', 'c17cdf9ef8671ceb623e2d536b34b594835c6b818762106a4e4e9f2bc6c6fdfa', '21', '1', NULL),
  ('2026_05_26_add_hours_schedule_mode.sql', '2026-05-27 03:10:59', '9c134f7454d83a16ca5a8930413f9b175ef2d7cdf1bc87119635d35213b34be2', '113', '1', NULL),
  ('2026_05_29_kiosk_azure_face.sql', '2026-06-10 03:11:37', 'eb79a26016014a3f6d03c180f3da02fcf6dc1dc6f6845e3e14061394381054d1', '62', '1', NULL),
  ('2026_05_30_kiosk_drop_azure.sql', '2026-06-10 03:11:37', '47525427d30e6d0cba6e03b22ac9098697087c6c329d5ac2fe55f238bc780b76', '120', '1', NULL),
  ('2026_05_31_kiosk_drop_azure_request_id.sql', '2026-06-10 03:11:37', '106ec9c61c80f0727718593e8d02ffe37001b19d500e1df49f463576cf2d4a28', '3', '1', NULL),
  ('2026_06_03_kiosk_collation.sql', '2026-06-10 03:11:37', '6e36a12157193ce195219efb70f8c6dc2c478b05fd1aba4a43aed5a559533a5d', '110', '1', NULL),
  ('2026_06_03_kiosk_hardening.sql', '2026-06-10 03:11:37', 'e941d263bfc731054b7c44ded6a645361d20014641c764989c74cbd4ceb2b20c', '0', '1', NULL),
  ('2026_06_09_attendance_removed.sql', '2026-06-10 03:11:37', '338cc5cede6e1dabb1353518d792937c42d09d4b012f75ced727396aa204f00c', '38', '1', NULL),
  ('2026_06_09_recreate_v_calendar_full.sql', '2026-06-10 03:11:37', '955dcacf410aa5ab59c2beba08d486882b22e3bd9755dda97c37536e9a2c3f0d', '2', '1', NULL),
  ('2026_06_16_attendance_edits_nullable_edited_by.sql', '2026-06-17 02:46:47', 'cd892742d9ec7f1ed1d5d32cb1bf7062f700f0e149018806bd542df4ce734e1b', '17', '1', NULL),
  ('2026_06_16_payslip_no_overtime_no_deficit.sql', '2026-06-17 02:46:47', '26273b568cd699ed309d477cb1207d4bc3226c1d03ab0dea34522844146a761e', '3', '1', NULL),
  ('2026_06_29_leave_excuses_absence.sql', '2026-06-29 22:52:37', '3c9d75187e616223cd681f18fade34f5eaf84dd1ada3bcbed69e9f3cc9745c40', '10', '1', NULL),
  ('add_admins_cpf.sql', '2026-03-23 02:17:52', NULL, NULL, NULL, NULL),
  ('add_attendance_checkout_client_id.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('add_attendance_client_id.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('add_attendance_edit_tracking.sql', '2026-03-23 02:20:56', NULL, NULL, NULL, NULL),
  ('add_attendance_edits_table.sql', '2026-03-23 02:20:56', NULL, NULL, NULL, NULL),
  ('add_audit_logs_table.sql', '2026-03-23 02:20:56', NULL, NULL, NULL, NULL),
  ('add_class_period_system.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_client_recorded_at.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('add_collaborator_remember_tokens.sql', '2026-05-04 19:12:59', 'cec939b030062346ea2bfc380c494a8358eb921dc0a9f57caa9882336bbc3351', '69', '1', NULL),
  ('add_device_token.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('add_face_enrollment_tracking.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_gps_fallback_settings.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('add_liveness_columns.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_liveness_nonce_dedup.sql', '2026-03-23 03:36:00', NULL, NULL, NULL, NULL),
  ('add_manual_tracking_columns.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_overtime_support.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_pending_reasons_simple.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_pending_reasons.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_photo_deleted_flag_simple.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_photo_deleted_flag.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('add_pin_and_trusted_devices.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('backfill_trusted_devices_2026_04_27.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('consolidate_schema_2026_04.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('fix_attendance_manual.sql', '2026-03-23 05:20:56', NULL, NULL, NULL, NULL),
  ('fix_audit_logs_nullable_admin.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('fix_collation_production.sql', '2026-03-23 02:22:50', NULL, NULL, NULL, NULL),
  ('fix_face_recognition_thresholds.sql', '2026-03-23 02:22:50', NULL, NULL, NULL, NULL),
  ('fix_missing_tables_and_columns.sql', '2026-03-23 02:22:50', NULL, NULL, NULL, NULL),
  ('harden_auth_and_attendance_schema.sql', '2026-03-23 02:22:50', NULL, NULL, NULL, NULL),
  ('harden_face_recognition_v3.sql', '2026-03-24 16:24:34', NULL, NULL, NULL, NULL),
  ('remove_pin_hash.sql', '2026-03-23 02:22:50', NULL, NULL, NULL, NULL),
  ('set_face_thresholds_3rd_layer.sql', '2026-04-27 23:04:08', NULL, NULL, NULL, NULL),
  ('unify_face_thresholds.sql', '2026-03-23 02:23:02', NULL, NULL, NULL, NULL),
  ('update_face_thresholds_hardened.sql', '2026-03-23 03:36:00', NULL, NULL, NULL, NULL);

SET FOREIGN_KEY_CHECKS = 1;

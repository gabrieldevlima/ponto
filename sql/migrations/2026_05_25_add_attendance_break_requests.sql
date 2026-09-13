-- Solicitações de correção de intervalo feitas pelo colaborador para dias passados.
--
-- Análoga a attendance_checkout_requests (mesmo padrão de fluxo: colaborador envia
-- proposta com justificativa → admin aprova/rejeita → UPDATE em attendance + auditoria).
--
-- A diferença: aqui ambos check_in e check_out podem ser alterados (o intervalo é um
-- par fechado, diferente do checkout esquecido que só falta o check_out).
--
-- Para edição NO MESMO DIA, o colaborador edita DIRETAMENTE via api/edit_own_break.php
-- (sem passar por esta tabela). Esta tabela cobre apenas pedidos retroativos.

CREATE TABLE IF NOT EXISTS attendance_break_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL COMMENT 'FK attendance.id (registro de intervalo a corrigir)',
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL COMMENT 'Data do intervalo (snapshot)',
  original_check_in DATETIME NULL COMMENT 'check_in atual antes da correção (auditoria)',
  original_check_out DATETIME NULL COMMENT 'check_out atual antes da correção (auditoria)',
  proposed_check_in DATETIME NOT NULL COMMENT 'check_in proposto pelo colaborador',
  proposed_check_out DATETIME NOT NULL COMMENT 'check_out proposto pelo colaborador',
  justification TEXT NOT NULL COMMENT 'Motivo da correção (obrigatório)',
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL,
  approved_at DATETIME NULL,
  admin_check_in DATETIME NULL COMMENT 'Se admin editou o check_in proposto antes de aprovar',
  admin_check_out DATETIME NULL COMMENT 'Se admin editou o check_out proposto antes de aprovar',
  admin_observation TEXT NULL COMMENT 'Observação livre do admin (visível ao colaborador)',
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  UNIQUE KEY uq_break_req_attendance (attendance_id) COMMENT 'Um pedido pendente/aprovado por intervalo (rejeitado pode ser substituído via UPDATE)',
  KEY idx_status_created (status, created_at),
  KEY idx_teacher_date (teacher_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Regularizacao de checkout esquecido (opt-in pelo colaborador).
--
-- Cenario: colaborador bate entrada em um dia, esquece de bater saida, e dias depois
-- volta a bater ponto. Hoje o sistema cria nova entrada e deixa o registro antigo
-- orfao (check_in preenchido, check_out NULL). Esta tabela armazena solicitacoes do
-- colaborador para fechar pontos orfaos com um horario estimado, sujeito a aprovacao
-- do admin (espelha o padrao de overtime_requests).
--
-- Compatibilidade: aditivo. Nenhum dado existente eh tocado. Tabela attendance_edits
-- (legado) continua disponivel para o admin editar pontos diretamente.

CREATE TABLE IF NOT EXISTS attendance_checkout_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attendance_id INT NOT NULL,
    teacher_id INT NOT NULL,
    school_id INT NULL,
    date DATE NOT NULL,
    check_in DATETIME NOT NULL COMMENT 'Snapshot do check_in original, para auditoria',
    proposed_check_out DATETIME NOT NULL COMMENT 'Horario que o colaborador estima ter saido',
    justification TEXT NOT NULL COMMENT 'Motivo do esquecimento (obrigatorio)',
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_by_admin_id INT NULL,
    approved_at DATETIME NULL,
    admin_check_out DATETIME NULL COMMENT 'Se admin editou o horario antes de aprovar, registra o ajustado aqui',
    admin_observation TEXT NULL COMMENT 'Observacao livre do admin (visivel ao colaborador)',
    rejection_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    CONSTRAINT fk_acr_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
    CONSTRAINT fk_acr_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    CONSTRAINT fk_acr_school  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    CONSTRAINT fk_acr_admin   FOREIGN KEY (approved_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    UNIQUE KEY uq_attendance_checkout_request (attendance_id),
    INDEX idx_acr_status (status),
    INDEX idx_acr_teacher_status (teacher_id, status),
    INDEX idx_acr_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

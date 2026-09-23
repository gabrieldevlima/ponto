-- ========================================================
-- SISTEMA DE AFASTAMENTOS - Upgrade para Atestados Médicos
-- ========================================================
-- Este script adiciona suporte para anexo de atestados médicos
-- e informações adicionais sobre afastamentos
-- ========================================================

USE ponto;

-- Adiciona campo para anexo de atestado médico
ALTER TABLE leaves 
  ADD COLUMN IF NOT EXISTS attachment VARCHAR(255) NULL COMMENT 'Nome do arquivo de atestado anexado' AFTER notes,
  ADD COLUMN IF NOT EXISTS attachment_uploaded_at TIMESTAMP NULL COMMENT 'Data de upload do anexo' AFTER attachment,
  ADD COLUMN IF NOT EXISTS description TEXT NULL COMMENT 'Descrição detalhada do afastamento' AFTER attachment_uploaded_at,
  ADD COLUMN IF NOT EXISTS cid_code VARCHAR(10) NULL COMMENT 'Código CID-10 (opcional, para afastamentos médicos)' AFTER description,
  ADD COLUMN IF NOT EXISTS days_count INT NOT NULL DEFAULT 0 COMMENT 'Número de dias de afastamento' AFTER end_date;

-- Atualiza contagem de dias para registros existentes
UPDATE leaves 
SET days_count = GREATEST(1, DATEDIFF(end_date, start_date) + 1)
WHERE days_count = 0;

-- Adiciona índice para buscas por anexos
CREATE INDEX IF NOT EXISTS idx_leaves_attachment ON leaves(attachment);

-- Adiciona campo para indicar se o tipo de afastamento requer atestado
ALTER TABLE leave_types
  ADD COLUMN IF NOT EXISTS requires_attachment TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se 1, requer anexo de documento' AFTER affects_bank,
  ADD COLUMN IF NOT EXISTS description TEXT NULL COMMENT 'Descrição do tipo de afastamento' AFTER requires_attachment;

-- Atualiza tipos de afastamento existentes para indicar quais requerem atestado
-- Atestados médicos, licenças médicas, etc. devem ter requires_attachment=1
UPDATE leave_types 
SET requires_attachment = 1 
WHERE code IN ('LICENCA_MEDICA', 'ATESTADO', 'AFAST_MEDICO', 'LICENCA_SAUDE')
  AND requires_attachment = 0;

-- Cria tabela para histórico de visualização de atestados (auditoria LGPD)
CREATE TABLE IF NOT EXISTS leave_attachment_access_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  leave_id INT NOT NULL COMMENT 'ID do afastamento',
  accessed_by_admin_id INT NULL COMMENT 'Admin que acessou o anexo',
  accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Data e hora do acesso',
  ip_address VARCHAR(45) NULL COMMENT 'IP de origem',
  user_agent TEXT NULL COMMENT 'User agent do navegador',
  action VARCHAR(50) NOT NULL DEFAULT 'view' COMMENT 'Ação: view, download, delete',
  CONSTRAINT fk_attachment_log_leave FOREIGN KEY (leave_id) REFERENCES leaves(id) ON DELETE CASCADE,
  CONSTRAINT fk_attachment_log_admin FOREIGN KEY (accessed_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Log de acesso aos anexos de afastamentos';

CREATE INDEX idx_attachment_log_leave ON leave_attachment_access_log(leave_id);
CREATE INDEX idx_attachment_log_admin ON leave_attachment_access_log(accessed_by_admin_id);
CREATE INDEX idx_attachment_log_date ON leave_attachment_access_log(accessed_at);

-- Insere alguns tipos de afastamento padrão se não existirem
INSERT IGNORE INTO leave_types (name, code, paid, affects_bank, requires_attachment, description) VALUES
('Atestado Médico', 'ATESTADO', 1, 0, 1, 'Afastamento por motivo de saúde com apresentação de atestado médico'),
('Licença Médica', 'LICENCA_MEDICA', 1, 0, 1, 'Licença para tratamento de saúde superior a 15 dias'),
('Férias', 'FERIAS', 1, 0, 0, 'Período de férias regulamentares'),
('Abono', 'ABONO', 1, 0, 0, 'Abono de falta por decisão da gestão'),
('Licença Maternidade', 'LICENCA_MATERNIDADE', 1, 0, 1, 'Licença maternidade de 120 dias'),
('Licença Paternidade', 'LICENCA_PATERNIDADE', 1, 0, 1, 'Licença paternidade de 5 a 20 dias'),
('Falta Justificada', 'FALTA_JUSTIFICADA', 0, 1, 1, 'Falta com justificativa documentada mas sem remuneração'),
('Suspensão', 'SUSPENSAO', 0, 1, 0, 'Suspensão disciplinar sem remuneração'),
('Licença Sem Vencimentos', 'LICENCA_SEM_VENC', 0, 1, 0, 'Licença não remunerada por solicitação do colaborador');

-- ========================================================
-- SUMÁRIO DA MIGRAÇÃO
-- ========================================================
-- 1. Campo 'attachment' - armazena nome do arquivo do atestado
-- 2. Campo 'attachment_uploaded_at' - timestamp do upload
-- 3. Campo 'description' - descrição detalhada do afastamento
-- 4. Campo 'cid_code' - código CID-10 para afastamentos médicos
-- 5. Campo 'days_count' - contagem automática de dias
-- 6. Campo 'requires_attachment' em leave_types - indica se tipo requer anexo
-- 7. Tabela 'leave_attachment_access_log' - log de acessos aos anexos (LGPD)
-- 8. Tipos de afastamento padrão inseridos
-- ========================================================

SELECT '✓ Migração concluída com sucesso!' AS status,
       'Sistema de atestados médicos ativado' AS info,
       'Diretório recomendado: public/attachments/leaves/ (criar manualmente)' AS note;


-- ============================================================================
-- ADEQUAÇÃO À PORTARIA MTP Nº 671/2021
-- Sistema de Registro Eletrônico de Ponto (REP-P)
-- ============================================================================
-- Este script adiciona os campos obrigatórios para conformidade com a
-- Portaria MTP 671/2021 que regulamenta o Registro Eletrônico de Ponto.
--
-- IMPORTANTE: Execute este script após o install.sql original
-- ============================================================================

-- Adiciona campos de conformidade à tabela attendance
-- IMPORTANTE: NSR será preenchido via trigger, não pode ser AUTO_INCREMENT (conflita com id)
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS nsr BIGINT UNSIGNED NULL UNIQUE COMMENT 'Número Sequencial de Registro (NSR) - obrigatório pela Portaria 671',
ADD COLUMN IF NOT EXISTS record_mode ENUM('online','offline') DEFAULT 'online' COMMENT 'Modo de registro: online ou offline',
ADD COLUMN IF NOT EXISTS recorded_at DATETIME(3) NULL COMMENT 'Timestamp exato da marcação (com milissegundos)',
ADD COLUMN IF NOT EXISTS synced_at DATETIME(3) NULL COMMENT 'Timestamp de sincronização com servidor',
ADD COLUMN IF NOT EXISTS hlb_sync_status VARCHAR(50) DEFAULT 'synced' COMMENT 'Status de sincronização com Hora Legal Brasileira',
ADD COLUMN IF NOT EXISTS device_identifier VARCHAR(255) NULL COMMENT 'Identificador do dispositivo usado na marcação',
ADD COLUMN IF NOT EXISTS hlb_offset_seconds INT DEFAULT 0 COMMENT 'Diferença em segundos entre hora do dispositivo e HLB',
ADD COLUMN IF NOT EXISTS receipt_generated BOOLEAN DEFAULT FALSE COMMENT 'Indica se o comprovante foi gerado',
ADD COLUMN IF NOT EXISTS receipt_viewed_at DATETIME NULL COMMENT 'Data/hora em que colaborador visualizou o comprovante';

-- ============================================================================
-- TABELA PARA CONTROLE DE NSR (Número Sequencial de Registro)
-- ============================================================================
-- Como não podemos ter dois AUTO_INCREMENT na mesma tabela, 
-- usamos uma tabela separada para controlar a sequência do NSR

CREATE TABLE IF NOT EXISTS nsr_sequence (
  id INT PRIMARY KEY DEFAULT 1,
  current_nsr BIGINT UNSIGNED DEFAULT 0,
  last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CHECK (id = 1) -- Garante apenas uma linha
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Controle de sequência do NSR (Número Sequencial de Registro)';

-- Inserir registro inicial se não existir
INSERT INTO nsr_sequence (id, current_nsr) VALUES (1, 0) ON DUPLICATE KEY UPDATE id=id;

-- Criar índices para melhor performance
CREATE INDEX IF NOT EXISTS idx_nsr ON attendance(nsr);
CREATE INDEX IF NOT EXISTS idx_record_mode ON attendance(record_mode);
CREATE INDEX IF NOT EXISTS idx_recorded_at ON attendance(recorded_at);
CREATE INDEX IF NOT EXISTS idx_device_identifier ON attendance(device_identifier);

-- Atualizar registros existentes com valores padrão
-- Para registros de entrada, usar check_in; para registros completos, usar check_out
UPDATE attendance 
SET recorded_at = COALESCE(check_in, check_out),
    synced_at = COALESCE(check_in, check_out),
    record_mode = 'online',
    hlb_sync_status = 'legacy'
WHERE recorded_at IS NULL AND (check_in IS NOT NULL OR check_out IS NOT NULL);

-- ============================================================================
-- Gerar NSR para registros existentes (executar apenas uma vez)
-- ============================================================================
-- Este procedimento atribui NSR sequencial aos registros que ainda não têm

SET @nsr_counter = 0;

UPDATE attendance 
SET nsr = (@nsr_counter := @nsr_counter + 1)
WHERE nsr IS NULL
ORDER BY id ASC;

-- Atualizar a sequência com o último valor usado
UPDATE nsr_sequence 
SET current_nsr = (SELECT COALESCE(MAX(nsr), 0) FROM attendance)
WHERE id = 1;

-- ============================================================================
-- TABELA DE AUDITORIA (LOG DE ALTERAÇÕES)
-- ============================================================================
-- Registra todas as alterações administrativas em registros de ponto
-- conforme exigência da Portaria 671/2021

CREATE TABLE IF NOT EXISTS attendance_audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL COMMENT 'ID do registro de ponto alterado',
  admin_id INT NULL COMMENT 'ID do administrador que fez a alteração',
  action VARCHAR(50) NOT NULL COMMENT 'Tipo de ação: UPDATE, DELETE, APPROVE, REJECT',
  field_changed VARCHAR(100) NULL COMMENT 'Campo que foi alterado',
  old_value TEXT NULL COMMENT 'Valor anterior',
  new_value TEXT NULL COMMENT 'Novo valor',
  reason TEXT NULL COMMENT 'Motivo/justificativa da alteração',
  ip_address VARCHAR(45) NULL COMMENT 'IP de onde foi feita a alteração',
  user_agent TEXT NULL COMMENT 'User agent do navegador',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Data/hora da alteração',
  
  INDEX idx_attendance_id (attendance_id),
  INDEX idx_admin_id (admin_id),
  INDEX idx_action (action),
  INDEX idx_created_at (created_at),
  
  FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Log de auditoria para alterações em registros de ponto - Portaria 671/2021';

-- ============================================================================
-- CONFIGURAÇÕES DO EMPREGADOR (PARA COMPROVANTES)
-- ============================================================================
-- Armazena informações da empresa para impressão em comprovantes

CREATE TABLE IF NOT EXISTS employer_config (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_name VARCHAR(255) NOT NULL COMMENT 'Razão Social da Empresa',
  company_trade_name VARCHAR(255) NULL COMMENT 'Nome Fantasia',
  cnpj VARCHAR(18) NOT NULL COMMENT 'CNPJ da Empresa',
  address TEXT NULL COMMENT 'Endereço completo',
  city VARCHAR(100) NULL COMMENT 'Cidade',
  state CHAR(2) NULL COMMENT 'UF',
  phone VARCHAR(20) NULL COMMENT 'Telefone',
  email VARCHAR(255) NULL COMMENT 'E-mail',
  
  -- Informações do Sistema (REP-P)
  system_name VARCHAR(100) DEFAULT 'DEEDO Ponto' COMMENT 'Nome do Sistema REP-P',
  system_version VARCHAR(20) DEFAULT '1.0.0' COMMENT 'Versão do Sistema',
  inpi_registration VARCHAR(100) NULL COMMENT 'Número de registro no INPI',
  rep_category VARCHAR(10) DEFAULT 'REP-P' COMMENT 'Categoria do REP',
  
  -- Conformidade
  portaria_671_compliant BOOLEAN DEFAULT TRUE COMMENT 'Sistema em conformidade com Portaria 671/2021',
  lgpd_compliant BOOLEAN DEFAULT TRUE COMMENT 'Sistema em conformidade com LGPD',
  
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Configurações do empregador para conformidade com Portaria 671/2021';

-- Inserir configuração padrão (deve ser atualizada pelo administrador)
INSERT INTO employer_config (company_name, cnpj, system_name, system_version, rep_category) 
VALUES (
  'NOME DA EMPRESA LTDA',
  '00.000.000/0000-00',
  'DEEDO Ponto',
  '1.0.0',
  'REP-P'
) ON DUPLICATE KEY UPDATE id=id;

-- ============================================================================
-- TABELA DE CONSENTIMENTO LGPD
-- ============================================================================
-- Registra consentimentos de colaboradores para processamento de dados

CREATE TABLE IF NOT EXISTS lgpd_consent (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL COMMENT 'ID do colaborador',
  consent_type VARCHAR(50) NOT NULL COMMENT 'Tipo: biometric_data, location_data, general',
  consent_given BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Consentimento foi dado',
  consent_text TEXT NOT NULL COMMENT 'Texto do termo apresentado',
  ip_address VARCHAR(45) NULL COMMENT 'IP onde consentimento foi dado',
  user_agent TEXT NULL COMMENT 'User agent',
  consented_at DATETIME NULL COMMENT 'Data/hora do consentimento',
  revoked_at DATETIME NULL COMMENT 'Data/hora de revogação (se aplicável)',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  
  INDEX idx_teacher_id (teacher_id),
  INDEX idx_consent_type (consent_type),
  INDEX idx_consented_at (consented_at),
  
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Registro de consentimentos LGPD';

-- ============================================================================
-- VISUALIZAÇÕES ÚTEIS PARA CONFORMIDADE
-- ============================================================================

-- View para comprovantes de ponto
CREATE OR REPLACE VIEW v_attendance_receipts AS
SELECT 
  a.id,
  a.nsr,
  a.teacher_id,
  t.name as teacher_name,
  t.cpf as teacher_cpf,
  a.date,
  a.check_in,
  a.check_out,
  CASE 
    WHEN a.check_out IS NOT NULL THEN 'saída'
    WHEN a.check_in IS NOT NULL THEN 'entrada'
    ELSE 'indefinido'
  END as action,
  a.record_mode,
  a.recorded_at,
  a.synced_at,
  a.check_in_lat as latitude,
  a.check_in_lng as longitude,
  a.photo,
  a.approved,
  a.hlb_sync_status,
  a.device_identifier,
  a.receipt_generated,
  a.receipt_viewed_at,
  e.company_name,
  e.cnpj,
  e.system_name,
  e.system_version,
  e.rep_category
FROM attendance a
INNER JOIN teachers t ON a.teacher_id = t.id
CROSS JOIN employer_config e
ORDER BY a.nsr DESC;

-- ============================================================================
-- TRIGGERS PARA AUDITORIA AUTOMÁTICA
-- ============================================================================

DELIMITER //

-- ============================================================================
-- Trigger de NSR REMOVIDO (auditoria de conformidade 2026-08-05).
--
-- Esta versão era a mais perigosa das três existentes no repositório: sem a
-- guarda `IF NEW.nsr IS NULL`, ela SOBRESCREVIA o NSR que o PHP já havia
-- reservado e incrementava `nsr_sequence` uma segunda vez — cada marcação
-- consumiria dois números e o NSR gravado divergiria do emitido no comprovante.
--
-- A emissão de NSR é responsabilidade exclusiva do PHP (SELECT ... FOR UPDATE
-- em nsr_sequence, dentro da transação do INSERT) e, a partir da Fase 1 de
-- docs/AUDITORIA_CONFORMIDADE_2026-08-05.md, do livro fiscal `nsr_ledger`.
--
-- Para remover de um ambiente onde tenha sido criado:
--     DROP TRIGGER IF EXISTS attendance_before_insert_nsr;
-- ============================================================================

-- Trigger para registrar alterações em attendance
CREATE TRIGGER IF NOT EXISTS attendance_update_audit
AFTER UPDATE ON attendance
FOR EACH ROW
BEGIN
  -- Registra se campos críticos foram alterados (check_in, check_out, date, approved)
  IF OLD.check_in != NEW.check_in OR OLD.check_out != NEW.check_out OR 
     OLD.date != NEW.date OR OLD.approved != NEW.approved THEN
    INSERT INTO attendance_audit_log (
      attendance_id, 
      action, 
      field_changed, 
      old_value, 
      new_value,
      reason
    ) VALUES (
      NEW.id,
      'UPDATE',
      CASE 
        WHEN OLD.check_in != NEW.check_in THEN 'check_in'
        WHEN OLD.check_out != NEW.check_out THEN 'check_out'
        WHEN OLD.date != NEW.date THEN 'date'
        WHEN OLD.approved != NEW.approved THEN 'approved'
        ELSE 'multiple_fields'
      END,
      CONCAT('date:', OLD.date, ' check_in:', OLD.check_in, ' check_out:', OLD.check_out, ' approved:', OLD.approved),
      CONCAT('date:', NEW.date, ' check_in:', NEW.check_in, ' check_out:', NEW.check_out, ' approved:', NEW.approved),
      'Alteração administrativa - requer justificativa'
    );
  END IF;
END//

-- Trigger para registrar exclusões
CREATE TRIGGER IF NOT EXISTS attendance_delete_audit
BEFORE DELETE ON attendance
FOR EACH ROW
BEGIN
  INSERT INTO attendance_audit_log (
    attendance_id, 
    action, 
    old_value,
    reason
  ) VALUES (
    OLD.id,
    'DELETE',
    CONCAT('NSR:', OLD.nsr, ' Teacher:', OLD.teacher_id, ' Date:', OLD.date, ' Check_in:', OLD.check_in, ' Check_out:', OLD.check_out),
    'Registro excluído - requer justificativa'
  );
END//

DELIMITER ;

-- ============================================================================
-- VERIFICAÇÃO DE CONFORMIDADE
-- ============================================================================

-- Mostrar status de conformidade
SELECT 
  'Portaria MTP 671/2021 - Status de Conformidade' AS info,
  (SELECT COUNT(*) FROM attendance WHERE nsr IS NOT NULL) AS registros_com_nsr,
  (SELECT COUNT(*) FROM attendance WHERE recorded_at IS NOT NULL) AS registros_com_timestamp,
  (SELECT COUNT(*) FROM attendance WHERE record_mode IN ('online','offline')) AS registros_com_modo,
  (SELECT COUNT(*) FROM attendance_audit_log) AS logs_auditoria,
  (SELECT COUNT(*) FROM employer_config) AS config_empregador,
  (SELECT COUNT(*) FROM lgpd_consent) AS consentimentos_lgpd;

-- ============================================================================
-- INSTRUÇÕES PÓS-INSTALAÇÃO
-- ============================================================================
/*
IMPORTANTE - AÇÕES NECESSÁRIAS APÓS EXECUTAR ESTE SCRIPT:

1. ATUALIZAR CONFIGURAÇÕES DO EMPREGADOR:
   - Editar a tabela employer_config com dados reais da empresa
   - Incluir CNPJ válido e Razão Social correta
   - Adicionar número de registro no INPI quando disponível

2. VERIFICAR CONFORMIDADE:
   - Todos os novos registros devem ter NSR único
   - Timestamps devem ser registrados com precisão de milissegundos
   - Modo de registro (online/offline) deve ser identificado

3. IMPLEMENTAR PROCESSOS:
   - Geração automática de comprovantes
   - Sincronização com Hora Legal Brasileira
   - Políticas de retenção de dados (5 anos mínimo)

4. DOCUMENTAÇÃO:
   - Manter Atestado Técnico atualizado
   - Documentar processos de conformidade
   - Preparar documentação para registro no INPI

5. LGPD:
   - Implementar termo de consentimento
   - Configurar políticas de privacidade
   - Treinar equipe sobre proteção de dados

Para mais informações, consulte: docs/PORTARIA_MTP_671_COMPLIANCE.md
*/


-- ============================================================================
-- SCRIPT DE VERIFICAÇÃO - PORTARIA MTP 671/2021
-- ============================================================================
-- Execute este script para verificar se todas as tabelas e campos
-- necessários foram criados corretamente
-- ============================================================================

-- Verificar se os campos foram adicionados à tabela attendance
SELECT 
  'Verificação de Campos na Tabela attendance' AS info,
  COUNT(CASE WHEN COLUMN_NAME = 'nsr' THEN 1 END) AS tem_nsr,
  COUNT(CASE WHEN COLUMN_NAME = 'record_mode' THEN 1 END) AS tem_record_mode,
  COUNT(CASE WHEN COLUMN_NAME = 'recorded_at' THEN 1 END) AS tem_recorded_at,
  COUNT(CASE WHEN COLUMN_NAME = 'synced_at' THEN 1 END) AS tem_synced_at,
  COUNT(CASE WHEN COLUMN_NAME = 'hlb_sync_status' THEN 1 END) AS tem_hlb_sync_status,
  COUNT(CASE WHEN COLUMN_NAME = 'device_identifier' THEN 1 END) AS tem_device_identifier,
  COUNT(CASE WHEN COLUMN_NAME = 'hlb_offset_seconds' THEN 1 END) AS tem_hlb_offset_seconds,
  COUNT(CASE WHEN COLUMN_NAME = 'receipt_generated' THEN 1 END) AS tem_receipt_generated,
  COUNT(CASE WHEN COLUMN_NAME = 'receipt_viewed_at' THEN 1 END) AS tem_receipt_viewed_at
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'attendance'
  AND COLUMN_NAME IN ('nsr', 'record_mode', 'recorded_at', 'synced_at', 'hlb_sync_status', 
                      'device_identifier', 'hlb_offset_seconds', 'receipt_generated', 'receipt_viewed_at');

-- Verificar se as tabelas auxiliares foram criadas
SELECT 
  'Verificação de Tabelas Criadas' AS info,
  COUNT(CASE WHEN TABLE_NAME = 'nsr_sequence' THEN 1 END) AS tem_nsr_sequence,
  COUNT(CASE WHEN TABLE_NAME = 'attendance_audit_log' THEN 1 END) AS tem_audit_log,
  COUNT(CASE WHEN TABLE_NAME = 'employer_config' THEN 1 END) AS tem_employer_config,
  COUNT(CASE WHEN TABLE_NAME = 'lgpd_consent' THEN 1 END) AS tem_lgpd_consent
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('nsr_sequence', 'attendance_audit_log', 'employer_config', 'lgpd_consent');

-- Verificar se os triggers foram criados
SELECT 
  'Verificação de Triggers' AS info,
  COUNT(CASE WHEN TRIGGER_NAME = 'attendance_before_insert_nsr' THEN 1 END) AS tem_trigger_nsr,
  COUNT(CASE WHEN TRIGGER_NAME = 'attendance_update_audit' THEN 1 END) AS tem_trigger_update,
  COUNT(CASE WHEN TRIGGER_NAME = 'attendance_delete_audit' THEN 1 END) AS tem_trigger_delete
FROM INFORMATION_SCHEMA.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME IN ('attendance_before_insert_nsr', 'attendance_update_audit', 'attendance_delete_audit');

-- Verificar se a view foi criada
SELECT 
  'Verificação de Views' AS info,
  COUNT(*) AS tem_view_receipts
FROM INFORMATION_SCHEMA.VIEWS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'v_attendance_receipts';

-- Verificar registros existentes com NSR
SELECT 
  'Status dos Registros Existentes' AS info,
  COUNT(*) AS total_registros,
  COUNT(CASE WHEN nsr IS NOT NULL THEN 1 END) AS registros_com_nsr,
  COUNT(CASE WHEN recorded_at IS NOT NULL THEN 1 END) AS registros_com_timestamp,
  COUNT(CASE WHEN record_mode IS NOT NULL THEN 1 END) AS registros_com_modo,
  MIN(nsr) AS menor_nsr,
  MAX(nsr) AS maior_nsr
FROM attendance;

-- Verificar sequência do NSR
SELECT 
  'Sequência NSR' AS info,
  current_nsr AS proximo_nsr,
  last_updated AS ultima_atualizacao
FROM nsr_sequence
WHERE id = 1;

-- Verificar configuração do empregador
SELECT 
  'Configuração do Empregador' AS info,
  company_name,
  cnpj,
  system_name,
  system_version,
  rep_category,
  CASE WHEN inpi_registration IS NOT NULL THEN 'Sim' ELSE 'Não cadastrado' END AS tem_registro_inpi,
  portaria_671_compliant AS conforme_portaria_671,
  lgpd_compliant AS conforme_lgpd
FROM employer_config
LIMIT 1;

-- ============================================================================
-- RESULTADO ESPERADO
-- ============================================================================
/*
Se tudo estiver correto, você deve ver:

1. Verificação de Campos: Todos os campos = 1
2. Verificação de Tabelas: Todas as tabelas = 1  
3. Verificação de Triggers: Todos os triggers = 1
4. Verificação de Views: view_receipts = 1
5. Status dos Registros: registros_com_nsr deve ser igual a total_registros
6. Sequência NSR: Deve mostrar o próximo NSR disponível
7. Configuração do Empregador: Deve mostrar os dados da empresa

Se algum valor for 0, significa que aquele componente não foi criado corretamente.
*/


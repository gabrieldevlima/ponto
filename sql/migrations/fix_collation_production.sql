-- =====================================================================
-- FIX COLLATION MISMATCH - Produção
-- =====================================================================
-- Problema: Algumas tabelas com utf8mb4_general_ci, outras com utf8mb4_unicode_ci
-- Solução: Padronizar TUDO para utf8mb4_unicode_ci
-- =====================================================================

-- Define o banco (ajuste se necessário)
-- USE <banco do ambiente>;

-- Altera charset e collation do BANCO
-- O nome do banco NAO entra aqui. Estava fixo em `u803039033_ponto`, que nao e
-- o banco de nenhum ambiente: producao roda em `u803039033_p_oeiras_p` e o CI
-- num banco descartavel, entao a migracao falhava em todo lugar. Sem o nome,
-- ALTER DATABASE aplica ao banco corrente da conexao, que e o certo.
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Altera TODAS as tabelas para utf8mb4_unicode_ci
ALTER TABLE admins CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE schools CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teachers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teacher_schools CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teacher_schedules CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE collaborator_time_schedules CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE collaborator_types CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE attendance CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE nsr_sequence CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE attendance_audit_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE employer_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE lgpd_consent CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leave_types CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leaves CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE leave_attachment_access_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE hour_bank_entries CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE overtime_requests CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE manual_reasons CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE app_settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE fraud_detection_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE antifraud_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE calendar_exceptions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE academic_calendar CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE mobile_holidays CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE payslips CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE payslip_items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Verifica se há outras tabelas (execute antes do script acima para ver todas as tabelas)
-- SHOW TABLES;

SELECT '✅ Collation corrigida com sucesso!' as status,
       'Todas as tabelas agora usam utf8mb4_unicode_ci' as info;


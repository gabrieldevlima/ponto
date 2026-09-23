-- =====================================================================
-- PRE-CHECK — Inspeção do estado atual da produção ANTES de aplicar
-- =====================================================================
-- Rode este SQL primeiro e GUARDE o output. Depois compare com o post-check.
-- Se algo divergir do esperado, NÃO aplique a migração.
-- =====================================================================

SELECT '=== Versão do servidor ===' AS info;
SELECT VERSION() AS mysql_version, @@character_set_database AS charset;

SELECT '=== Tabelas existentes ===' AS info;
SELECT TABLE_NAME, ENGINE, TABLE_ROWS, TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

SELECT '=== Contagens-chave (preservar) ===' AS info;
SELECT 'teachers' AS tabela, COUNT(*) AS rows_atuais FROM teachers
UNION ALL SELECT 'attendance', COUNT(*) FROM attendance
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs
UNION ALL SELECT 'auth_attempt_logs', COUNT(*) FROM auth_attempt_logs
UNION ALL SELECT 'hour_bank_entries', COUNT(*) FROM hour_bank_entries
UNION ALL SELECT 'overtime_requests', COUNT(*) FROM overtime_requests
UNION ALL SELECT 'fraud_detection_log', COUNT(*) FROM fraud_detection_log
UNION ALL SELECT 'collaborator_time_schedules', COUNT(*) FROM collaborator_time_schedules
UNION ALL SELECT 'schools', COUNT(*) FROM schools
UNION ALL SELECT 'admins', COUNT(*) FROM admins
UNION ALL SELECT 'app_settings', COUNT(*) FROM app_settings;

SELECT '=== Schema crítico: colunas v2 (esperado: 0 antes de migrar) ===' AS info;
SELECT 'attendance.client_id' AS col_v2,
       SUM(COLUMN_NAME='client_id') AS exists_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
UNION ALL
SELECT 'attendance.checkout_client_id',
       SUM(COLUMN_NAME='checkout_client_id')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
UNION ALL
SELECT 'attendance.client_recorded_at',
       SUM(COLUMN_NAME='client_recorded_at')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
UNION ALL
SELECT 'teacher_trusted_devices table',
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_trusted_devices');

SELECT '=== applied_migrations existente ===' AS info;
SELECT filename, applied_at
FROM applied_migrations
ORDER BY applied_at DESC, filename DESC
LIMIT 20;

SELECT '=== NSR atual ===' AS info;
SELECT 'nsr_sequence' AS source, COALESCE((SELECT current_nsr FROM nsr_sequence WHERE id=1), 0) AS valor
UNION ALL
SELECT 'MAX(attendance.nsr)', COALESCE((SELECT MAX(nsr) FROM attendance), 0);

SELECT '=== Foreign keys atuais ===' AS info;
SELECT COUNT(*) AS total_fks FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE();

SELECT '=== Índices secundários (não-PK) ===' AS info;
SELECT TABLE_NAME, COUNT(DISTINCT INDEX_NAME) AS qtd_indices
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME != 'PRIMARY'
GROUP BY TABLE_NAME
ORDER BY qtd_indices DESC;

-- ✅ Salve este output completo. Use como baseline para comparar pós-migração.

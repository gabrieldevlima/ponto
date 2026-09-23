-- =====================================================================
-- POST-CHECK — Valida produção DEPOIS da migração v1→v2
-- =====================================================================
-- Compare cada output com o pre_check.sql que você guardou.
-- Os valores que devem MUDAR estão marcados com 🔼 (cresceu) ou 🔄 (alterou).
-- Os valores que NÃO podem mudar (dados preservados) estão marcados com ⏸.
-- =====================================================================

SELECT '=== Contagens preservadas (devem ser iguais ou maiores que pre-check) ===' AS info;
SELECT 'teachers' AS tabela, COUNT(*) AS rows_pos FROM teachers
UNION ALL SELECT 'attendance', COUNT(*) FROM attendance
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs
UNION ALL SELECT 'auth_attempt_logs', COUNT(*) FROM auth_attempt_logs
UNION ALL SELECT 'hour_bank_entries', COUNT(*) FROM hour_bank_entries
UNION ALL SELECT 'overtime_requests', COUNT(*) FROM overtime_requests
UNION ALL SELECT 'fraud_detection_log', COUNT(*) FROM fraud_detection_log
UNION ALL SELECT 'collaborator_time_schedules', COUNT(*) FROM collaborator_time_schedules
UNION ALL SELECT 'schools', COUNT(*) FROM schools
UNION ALL SELECT 'admins', COUNT(*) FROM admins;

SELECT '=== 🔼 app_settings (deve ter ganhado 26: 2 gps + 14 face + 10 pin) ===' AS info;
SELECT COUNT(*) AS total_settings FROM app_settings;
SELECT COUNT(*) AS face_settings FROM app_settings WHERE k LIKE 'face_%';
SELECT COUNT(*) AS pin_settings FROM app_settings WHERE k LIKE 'pin_%';
SELECT COUNT(*) AS gps_settings FROM app_settings WHERE k LIKE 'gps_%';
SELECT v AS min_checkout_gap_seconds FROM app_settings WHERE k = 'min_checkout_gap_seconds';

SELECT '=== 🔼 Tabelas novas (devem existir) ===' AS info;
SELECT TABLE_NAME AS tabela_nova
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('teacher_trusted_devices', 'permissions');

SELECT '=== 🔼 Colunas novas em attendance (4 esperadas) ===' AS info;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'attendance'
  AND COLUMN_NAME IN ('client_id', 'checkout_client_id', 'client_recorded_at', 'offline_delay_seconds');

SELECT '=== 🔼 Colunas novas em teachers (2 esperadas; pin_hash deve ser NULLABLE) ===' AS info;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'teachers'
  AND COLUMN_NAME IN ('pin_hash', 'pin_changed_at', 'pin_self_enroll_allowed');

SELECT '=== 🔼 applied_migrations: deve ter 4 colunas novas + 12 linhas adicionais ===' AS info;
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applied_migrations'
ORDER BY ORDINAL_POSITION;
SELECT filename, applied_at
FROM applied_migrations
ORDER BY applied_at DESC
LIMIT 12;

SELECT '=== 🔼 audit_logs.admin_id agora NULL ===' AS info;
SELECT IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'admin_id';

SELECT '=== 🔼 nsr_sequence inicializado ===' AS info;
SELECT * FROM nsr_sequence;
SELECT '   Comparação com MAX(attendance.nsr) — devem ser iguais:' AS info,
       (SELECT current_nsr FROM nsr_sequence WHERE id=1) AS sequence_value,
       (SELECT MAX(nsr) FROM attendance) AS max_attendance_nsr;

SELECT '=== 🔄 v_payslips_full deve ser VIEW (não BASE TABLE) ===' AS info;
SELECT TABLE_TYPE
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v_payslips_full';

SELECT '=== 🔼 Índices secundários (esperado: 40+ no total) ===' AS info;
SELECT COUNT(*) AS total_indices_secundarios
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME != 'PRIMARY';

SELECT TABLE_NAME, COUNT(DISTINCT INDEX_NAME) AS qtd
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
GROUP BY TABLE_NAME
HAVING COUNT(DISTINCT INDEX_NAME) > 1
ORDER BY qtd DESC;

SELECT '=== 🔼 teacher_trusted_devices populated (backfill 30+ esperado) ===' AS info;
SELECT COUNT(*) AS trusted_devices_count FROM teacher_trusted_devices;

SELECT '=== ⏸ Integridade de dados — sem órfãos ===' AS info;
SELECT 'attendance->teachers' AS relacao,
       (SELECT COUNT(*) FROM attendance a LEFT JOIN teachers t ON a.teacher_id = t.id WHERE t.id IS NULL) AS orfaos
UNION ALL SELECT 'hour_bank_entries->teachers',
       (SELECT COUNT(*) FROM hour_bank_entries h LEFT JOIN teachers t ON h.teacher_id = t.id WHERE t.id IS NULL)
UNION ALL SELECT 'auth_attempt_logs->teachers',
       (SELECT COUNT(*) FROM auth_attempt_logs a LEFT JOIN teachers t ON a.teacher_id = t.id
        WHERE a.teacher_id IS NOT NULL AND t.id IS NULL);

-- ✅ Se todos os checks acima passarem, a migração está OK.
-- ❌ Se algo divergir, verifique a seção Rollback no README.

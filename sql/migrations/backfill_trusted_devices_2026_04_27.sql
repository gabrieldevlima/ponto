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

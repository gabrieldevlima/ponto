-- ============================================================================
-- QUIOSQUE: remover dependencia da Azure Face API
-- ============================================================================
-- Decisao de produto: o quiosque passa a usar reconhecimento facial LOCAL
-- (face-api.js no navegador + comparacao 1:1 contra teachers.face_descriptors).
-- Esta migracao remove as colunas e settings especificos da Azure criados em
-- 2026_05_29_kiosk_azure_face.sql.
--
-- MANTIDOS (continuam em uso pelo motor local):
--   - tabelas kiosk_devices e kiosk_face_logs
--   - attendance.face_match_confidence / kiosk_device_id / kiosk_face_log_id
--   - teachers.face_descriptors (cadastro facial local, ja existente)
--
-- Idempotente: o runner tolera 1091 (DROP de coluna/indice inexistente) em re-run.
-- Primeiro removemos o indice UNIQUE, depois a coluna.
-- ============================================================================

ALTER TABLE teachers DROP INDEX uq_teachers_azure_person;

ALTER TABLE teachers DROP COLUMN azure_person_id;
ALTER TABLE teachers DROP COLUMN azure_persisted_face_ids;
ALTER TABLE teachers DROP COLUMN azure_enrollment_status;
ALTER TABLE teachers DROP COLUMN azure_enrolled_at;
ALTER TABLE teachers DROP COLUMN azure_enrollment_error;
ALTER TABLE teachers DROP COLUMN azure_enrolled_by_admin_id;

DELETE FROM app_settings WHERE k IN (
  'azure_face_driver',
  'azure_face_person_group_id',
  'azure_face_detection_model',
  'azure_face_recognition_model',
  'kiosk_mock_force',
  'kiosk_mock_identify_teacher_id',
  'kiosk_mock_confidence',
  'kiosk_min_confidence'
);

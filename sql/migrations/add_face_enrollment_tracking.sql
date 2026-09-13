-- Migração: Adicionar tracking de enrollment facial na tabela teachers
-- Permite detectar descritores desatualizados e sugerir re-cadastramento

ALTER TABLE teachers
  ADD COLUMN face_enrolled_at DATETIME NULL DEFAULT NULL AFTER face_descriptors,
  ADD COLUMN face_enrollment_version INT NOT NULL DEFAULT 1 AFTER face_enrolled_at;

-- Configuração padrão: re-enrollment sugerido após 180 dias
INSERT INTO app_settings (`k`, `v`) VALUES
    ('face_reenroll_days', '180')
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);

-- Inicializar face_enrolled_at para colaboradores que já possuem descritores
UPDATE teachers SET face_enrolled_at = created_at
WHERE face_descriptors IS NOT NULL AND face_descriptors != '' AND face_enrolled_at IS NULL;

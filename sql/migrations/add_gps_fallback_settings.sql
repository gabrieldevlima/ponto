-- Configurações para o fallback inteligente de GPS no check-in.
-- Documentação completa em api/checkin.php (bloco "Validação de Geolocalização").
--
--  gps_max_accuracy_m       — Acima deste valor, GPS é considerado "não
--                              confiável" e cai pra fallback declarativo
--                              (escolha manual de escola). Default: 200m.
--
--  gps_radius_extra_max_m   — Cap da margem extra adicionada ao raio do
--                              geofence quando o GPS é impreciso. Raio
--                              efetivo = geofence_radius_m + min(accuracy,
--                              gps_radius_extra_max_m). Default: 200m.
--
-- Use INSERT IGNORE para não sobrescrever valores se admin tunou via UI.

INSERT IGNORE INTO app_settings (k, v) VALUES
  ('gps_max_accuracy_m',     '200'),
  ('gps_radius_extra_max_m', '200');

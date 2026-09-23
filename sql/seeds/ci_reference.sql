-- ============================================================================
-- Dados de REFERÊNCIA do CI — fictícios, nenhum vem de produção.
--
-- O CI parte de sql/schema/producao.sql, que traz só a estrutura. As tabelas
-- de apoio (tipos de colaborador, tipos de afastamento, motivos de lançamento
-- manual, parâmetros básicos e um admin de rede para as telas) chegam vazias,
-- e os testes precisam delas.
--
-- Estes INSERTs são os mesmos de sql/install/install.sql, extraídos dele. Se o
-- install mudar, regenere este arquivo em vez de editá-lo à mão.
-- ============================================================================

INSERT IGNORE INTO collaborator_types (id, name, slug, schedule_mode, requires_schedule) VALUES
  (1, 'Professor', 'teacher', 'classes', 1),
  (2, 'Diretor', 'director', 'time', 1),
  (3, 'Secretário', 'secretary', 'time', 1),
  (4, 'Motorista', 'driver', 'time', 1),
  (5, 'Coordenador', 'coordinator', 'time', 1),
  (6, 'Administrativo', 'administrative', 'time', 1),
  (7, 'Auxiliar', 'assistant', 'time', 1);

INSERT IGNORE INTO manual_reasons (id, name, active, sort_order) VALUES
  (1, 'Falta de internet', 1, 10),
  (2, 'Falha no sistema', 1, 20),
  (3, 'Esquecimento do colaborador', 1, 30),
  (4, 'Outro', 1, 100);

INSERT IGNORE INTO app_settings (k, v) VALUES
  ('tolerance_minutes', '5'),
  ('geofence_radius_m', '300');

INSERT IGNORE INTO leave_types (id, name, code, paid, affects_bank, active) VALUES
  (1, 'Abono', 'ABONO', 1, 0, 1),
  (2, 'Afastamento', 'AFAST', 0, 1, 1),
  (3, 'Férias', 'FERIAS', 1, 0, 1),
  (4, 'Licença', 'LICENCA', 1, 0, 1);

INSERT IGNORE INTO admins (username, cpf, password_hash, role) VALUES
('admin', '00000000000', '$2y$10$Yf3IrInDQp7patjgPNmWCuNHp7CjXNGTVYS2Y6TuMcngP3/vLQiiq', 'network_admin');

-- ============================================================================
-- Permissões por papel — NC-48 (Fase 9 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md)
--
-- O achado é pior do que "fail-open quando não há regra": `has_permission()`
-- consultava `SELECT allow FROM permissions WHERE role = ? AND perm_key = ?`,
-- mas a tabela `permissions` tem (id, admin_id, permission_key, granted_at).
-- Nenhuma dessas três colunas existe. A query lançava exceção em TODA chamada,
-- o catch devolvia `true`, e o controle granular nunca funcionou — em nenhum
-- momento, para nenhum papel.
--
-- Esta tabela dá ao mecanismo o schema que ele sempre esperou. A `permissions`
-- original é preservada (concessão individual por admin, que continua válida
-- como conceito) e não é tocada.
--
-- Papéis:
--   network_admin — administra a rede inteira
--   school_admin  — administra a própria unidade
--   hr_admin      — setor de pessoal: jornada, afastamentos, folha; NÃO mexe
--                   em cadastro de unidade nem em configuração do REP
--   manager       — gestor de equipe: consulta e aprova; não configura nada
-- ============================================================================

CREATE TABLE IF NOT EXISTS role_permissions (
  role       VARCHAR(32) NOT NULL,
  perm_key   VARCHAR(64) NOT NULL,
  allow      TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (role, perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- network_admin: tudo. Mantido explícito em vez de implícito no código, para
-- que a tela de permissões mostre a matriz completa.
INSERT INTO role_permissions (role, perm_key, allow) VALUES
  ('network_admin', 'kiosk.manage',       1),
  ('network_admin', 'leaves.manage',      1),
  ('network_admin', 'attendance.dedupe',  1),
  ('network_admin', 'calendar.manage',    1),
  ('network_admin', 'attendance.edit',    1),
  ('network_admin', 'payroll.manage',     1),
  ('network_admin', 'employer.config',    1),
  ('network_admin', 'export.fiscal',      1),
  ('network_admin', 'lgpd.manage',        1)
ON DUPLICATE KEY UPDATE allow = VALUES(allow);

-- school_admin: opera a unidade. Não configura o REP nem exporta arquivo fiscal
-- (que identifica o empregador inteiro), nem administra LGPD.
INSERT INTO role_permissions (role, perm_key, allow) VALUES
  ('school_admin', 'kiosk.manage',        1),
  ('school_admin', 'leaves.manage',       1),
  ('school_admin', 'attendance.dedupe',   1),
  ('school_admin', 'calendar.manage',     0),
  ('school_admin', 'attendance.edit',     1),
  ('school_admin', 'payroll.manage',      0),
  ('school_admin', 'employer.config',     0),
  ('school_admin', 'export.fiscal',       0),
  ('school_admin', 'lgpd.manage',         0)
ON DUPLICATE KEY UPDATE allow = VALUES(allow);

-- hr_admin (setor de pessoal): jornada, afastamentos, folha e arquivos fiscais.
-- Não mexe em quiosque nem em configuração do empregador.
INSERT INTO role_permissions (role, perm_key, allow) VALUES
  ('hr_admin', 'kiosk.manage',       0),
  ('hr_admin', 'leaves.manage',      1),
  ('hr_admin', 'attendance.dedupe',  1),
  ('hr_admin', 'calendar.manage',    1),
  ('hr_admin', 'attendance.edit',    1),
  ('hr_admin', 'payroll.manage',     1),
  ('hr_admin', 'employer.config',    0),
  ('hr_admin', 'export.fiscal',      1),
  ('hr_admin', 'lgpd.manage',        1)
ON DUPLICATE KEY UPDATE allow = VALUES(allow);

-- manager (gestor de equipe): consulta e aprova; não configura nem edita ponto.
-- Editar marcação é ato que altera prova de jornada — fica com RH e admin.
INSERT INTO role_permissions (role, perm_key, allow) VALUES
  ('manager', 'kiosk.manage',       0),
  ('manager', 'leaves.manage',      1),
  ('manager', 'attendance.dedupe',  0),
  ('manager', 'calendar.manage',    0),
  ('manager', 'attendance.edit',    0),
  ('manager', 'payroll.manage',     0),
  ('manager', 'employer.config',    0),
  ('manager', 'export.fiscal',      0),
  ('manager', 'lgpd.manage',        0)
ON DUPLICATE KEY UPDATE allow = VALUES(allow);

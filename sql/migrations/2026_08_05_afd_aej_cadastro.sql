-- ============================================================================
-- Campos de cadastro exigidos pelo AFD e pelo AEJ — Portaria MTP 671/2021
-- Fase 0b.5 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
--
-- Estas colunas NÃO existiam. Sem elas o Arquivo Fonte de Dados é inemitível:
--   - o registro tipo 3 do AFD identifica o trabalhador pelo PIS/PASEP/NIS;
--   - o cabeçalho (tipo 1) exige identificação do empregador (CNPJ ou CPF),
--     CEI/CAEPF/CNO quando aplicável e o identificador do REP;
--   - o AEJ classifica afastamentos por código de ocorrência.
--
-- Preencher os dados é trabalho administrativo (Fase 0c) e é o caminho crítico
-- do AFD — a migration só abre espaço para eles.
--
-- Portabilidade: sem `IF NOT EXISTS` em coluna (MySQL 8 não suporta e o dev
-- local é MySQL, enquanto produção é MariaDB 11.8). O runner de migrations
-- (helpers.run_auto_migrations) tolera os erros 1060 (coluna duplicada) e
-- 1061 (índice duplicado), então rodar de novo é seguro.
-- ============================================================================

ALTER TABLE teachers ADD COLUMN pis VARCHAR(11) NULL COMMENT 'PIS/PASEP/NIS - obrigatorio no AFD tipo 3 e no AEJ';
ALTER TABLE teachers ADD COLUMN admission_date DATE NULL COMMENT 'Data de admissao - AEJ';
ALTER TABLE teachers ADD COLUMN dismissal_date DATE NULL COMMENT 'Data de desligamento - AEJ';
ALTER TABLE teachers ADD COLUMN matricula VARCHAR(20) NULL COMMENT 'Matricula funcional do empregador';
ALTER TABLE teachers ADD COLUMN cbo VARCHAR(6) NULL COMMENT 'Codigo Brasileiro de Ocupacoes';
CREATE INDEX idx_teachers_pis ON teachers (pis);

ALTER TABLE employer_config ADD COLUMN employer_type TINYINT NOT NULL DEFAULT 1 COMMENT '1=CNPJ 2=CPF';
ALTER TABLE employer_config ADD COLUMN cpf VARCHAR(14) NULL COMMENT 'CPF do empregador quando pessoa fisica - art. 80';
ALTER TABLE employer_config ADD COLUMN cei_caepf_cno VARCHAR(14) NULL COMMENT 'CEI/CAEPF/CNO quando aplicavel';
ALTER TABLE employer_config ADD COLUMN rep_identifier VARCHAR(17) NULL COMMENT 'Identificador do REP-P no AFD';
ALTER TABLE employer_config ADD COLUMN service_location VARCHAR(150) NULL COMMENT 'Local da prestacao de servico - art. 80 do comprovante';
ALTER TABLE employer_config ADD COLUMN afd_layout_version VARCHAR(3) NOT NULL DEFAULT '003' COMMENT 'Versao do leiaute do AFD';

ALTER TABLE leave_types ADD COLUMN aej_code VARCHAR(4) NULL COMMENT 'Codigo de ocorrencia do AEJ';

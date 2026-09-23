-- ============================================================================
-- Dados de INSTALAÇÃO do CI — fictícios, nenhum vem de produção.
--
-- Não são dados de negócio: são os registros de controle que toda instalação
-- tem e que o código assume existirem. Em produção eles nasceram de migrações
-- antigas; partindo do snapshot (sql/schema/producao.sql), o runner não
-- reexecuta essas migrações — elas já constam como aplicadas — e as linhas
-- somem. Aqui elas voltam, com valores de teste.
-- ============================================================================

-- Contador único de NSR. O código faz `WHERE id = 1 FOR UPDATE` e, sem a linha,
-- o livro fiscal lança "nsr_sequence sem linha id=1 — instalação incompleta".
INSERT IGNORE INTO nsr_sequence (id, current_nsr) VALUES (1, 0);

-- Empregador. Tabela de linha única: o AFD e o AEJ leem o cabeçalho dela e o
-- pré-voo bloqueia a exportação se estiver vazia. Representa uma instalação
-- JÁ CONFIGURADA, como a de produção — há teste que verifica justamente que o
-- pré-voo enxerga o CNPJ gravado. Os que precisam de outro empregador fazem
-- UPDATE aqui e restauram depois; sem a linha, o UPDATE não afetava nada.
-- CNPJ 11.222.333/0001-81: fictício, de exemplo, com dígito verificador válido.
INSERT IGNORE INTO employer_config
  (id, company_name, employer_type, cnpj, rep_identifier, service_location)
VALUES (1, 'EMPREGADOR CI', 1, '11222333000181', 'REP-CI-0001', 'LOCAL DE TESTE CI');

-- Códigos de ocorrência do AEJ nos tipos de afastamento. A coluna nasce nula
-- (migração 2026_08_05_afd_aej_cadastro) e o admin a preenche pela tela; uma
-- instalação configurada os tem. Há teste que verifica justamente o estado
-- "preenchidos, mas ainda não conferidos contra o leiaute oficial" — com a
-- coluna nula esse estado nunca existia. Valores fictícios: até 4 caracteres,
-- distintos entre si, que é o que o AEJ exige do campo.
UPDATE leave_types
   SET aej_code = CASE code
       WHEN 'ABONO'   THEN 'AB01'
       WHEN 'AFAST'   THEN 'AF01'
       WHEN 'FERIAS'  THEN 'FE01'
       WHEN 'LICENCA' THEN 'LI01'
   END
 WHERE code IN ('ABONO', 'AFAST', 'FERIAS', 'LICENCA');

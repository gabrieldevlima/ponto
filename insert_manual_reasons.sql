-- =====================================================================
-- SQL: Inserir Motivos Comuns para Inserção Manual de Ponto
-- =====================================================================
-- Execute no phpMyAdmin para popular a tabela manual_reasons
-- Pode inserir todos de uma vez ou escolher apenas os que precisa
-- =====================================================================

-- Limpar motivos existentes (OPCIONAL - comente se não quiser limpar)
-- DELETE FROM manual_reasons;

-- PROBLEMAS TÉCNICOS (mais comuns)
INSERT INTO manual_reasons (name, active) VALUES
('Falha no sistema de ponto eletrônico', 1),
('Dispositivo sem bateria', 1),
('Celular sem acesso no momento', 1),
('Sem conexão com internet', 1),
('Falha no reconhecimento facial', 1),
('Erro ao capturar foto', 1),
('GPS não disponível no momento', 1),
('Sistema em manutenção', 1),
('Aplicativo apresentou erro técnico', 1);

-- ESQUECIMENTOS (muito comuns)
INSERT INTO manual_reasons (name, active) VALUES
('Esquecimento de registrar ponto no horário', 1),
('Esquecimento de registrar entrada', 1),
('Esquecimento de registrar saída', 1);

-- SAÚDE
INSERT INTO manual_reasons (name, active) VALUES
('Atestado médico apresentado', 1),
('Atendimento médico durante expediente', 1),
('Emergência médica', 1),
('Acompanhamento de familiar em consulta', 1),
('Exame médico periódico', 1),
('Doação de sangue', 1);

-- TRABALHO EXTERNO
INSERT INTO manual_reasons (name, active) VALUES
('Trabalho em campo sem acesso ao sistema', 1),
('Visita técnica externa', 1),
('Reunião fora da instituição', 1),
('Deslocamento entre unidades', 1),
('Evento externo representando a instituição', 1),
('Treinamento externo', 1),
('Trabalho em outra unidade (rede)', 1);

-- AUTORIZAÇÕES ADMINISTRATIVAS
INSERT INTO manual_reasons (name, active) VALUES
('Compensação de horas autorizada', 1),
('Banco de horas (saldo positivo)', 1),
('Folga compensatória', 1),
('Home office autorizado', 1),
('Horário especial autorizado', 1),
('Flexibilização de jornada', 1),
('Saída antecipada autorizada', 1),
('Hora extra pré-autorizada', 1);

-- LICENÇAS E AFASTAMENTOS
INSERT INTO manual_reasons (name, active) VALUES
('Licença previamente autorizada', 1),
('Comparecimento em juízo', 1),
('Alistamento militar', 1),
('Casamento (licença-gala)', 1),
('Falecimento de familiar (luto)', 1),
('Licença-paternidade/maternidade', 1);

-- EDUCAÇÃO (para escolas)
INSERT INTO manual_reasons (name, active) VALUES
('Aula remota autorizada', 1),
('Conselho de classe', 1),
('Reunião pedagógica', 1),
('Capacitação docente', 1),
('Substituição de professor ausente', 1),
('Aula extra autorizada', 1),
('Excursão pedagógica', 1),
('Feira de ciências', 1),
('Olimpíadas/Jogos escolares', 1);

-- TRANSPORTE
INSERT INTO manual_reasons (name, active) VALUES
('Problema no transporte público', 1),
('Acidente no trajeto', 1),
('Condições climáticas adversas', 1),
('Greve de transporte público', 1);

-- CORREÇÕES
INSERT INTO manual_reasons (name, active) VALUES
('Correção de horário registrado incorretamente', 1),
('Registro duplicado (correção)', 1),
('Ajuste conforme atestado médico', 1),
('Regularização após análise de gestor', 1);

-- EMERGÊNCIAS
INSERT INTO manual_reasons (name, active) VALUES
('Emergência familiar', 1),
('Emergência pessoal', 1),
('Problema doméstico urgente', 1);

-- OUTROS
INSERT INTO manual_reasons (name, active) VALUES
('Feriado municipal/estadual', 1),
('Ponto facultativo', 1),
('Recesso escolar', 1),
('Férias coletivas', 1),
('Colaborador novo (primeiro dia)', 1),
('Migração de sistema anterior', 1),
('Importação de dados legados', 1);

-- =====================================================================
-- Verificar motivos inseridos
-- =====================================================================
SELECT COUNT(*) AS total_motivos FROM manual_reasons;
SELECT * FROM manual_reasons ORDER BY name;



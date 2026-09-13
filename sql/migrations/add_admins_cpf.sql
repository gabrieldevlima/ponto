-- Adiciona coluna CPF na tabela admins para login por CPF + senha
-- CPF: 11 dígitos, único. Primeiro admin existente recebe CPF de teste válido (00000000191).

ALTER TABLE admins ADD COLUMN cpf VARCHAR(11) NULL UNIQUE AFTER username;

-- Atribui CPF válido de teste ao primeiro admin (para não quebrar acesso)
UPDATE admins SET cpf = '00000000191' WHERE cpf IS NULL ORDER BY id LIMIT 1;

-- Demais admins ficam com cpf NULL até ser definido na tela de Administradores (novo cadastro por CPF).

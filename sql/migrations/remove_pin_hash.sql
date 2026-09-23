-- Remove coluna pin_hash da tabela teachers (substituição PIN por CPF)
-- Executar apenas em bancos existentes; install.sql já não inclui pin_hash
ALTER TABLE teachers DROP COLUMN pin_hash;

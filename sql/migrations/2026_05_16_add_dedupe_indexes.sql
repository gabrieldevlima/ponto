-- Suporta o pre-check lógico de duplicatas no api/checkin_bulk.php:
-- "existe attendance para este teacher nesta data com check_in/check_out
-- dentro de uma janela de 5 minutos do que estou para inserir?"

-- Acelera busca por entrada existente em uma data
ALTER TABLE attendance
  ADD INDEX idx_dedupe_in (teacher_id, date, check_in);

-- Acelera busca por saída existente em uma data
ALTER TABLE attendance
  ADD INDEX idx_dedupe_out (teacher_id, date, check_out);

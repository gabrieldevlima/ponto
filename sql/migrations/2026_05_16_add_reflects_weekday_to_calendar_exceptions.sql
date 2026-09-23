-- Adiciona referência de dia da semana para exceções tipo "workday" (sábado letivo).
-- Quando preenchido, o sistema trata o check-in nessa data como se fosse o weekday indicado
-- (0=domingo, 1=segunda, ..., 6=sábado), permitindo aprovação automática usando a
-- jornada do colaborador para esse dia da semana.

ALTER TABLE calendar_exceptions
  ADD COLUMN reflects_weekday TINYINT NULL
    COMMENT 'Dia da semana referenciado (0=dom..6=sáb). Usado em type=workday p/ aprovar check-in'
    AFTER is_working_day;

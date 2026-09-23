-- Recria a view v_calendar_full de forma robusta.
-- MOTIVO: o serializer do MariaDB / export do phpMyAdmin corrompe a definicao
-- desta view (literal de string vira "_utf8mb4 AS Toda a rede"), o que faz o
-- import falhar e a view sumir do banco. Esta migracao recria a view com a
-- sintaxe defensiva (introducer _utf8mb4 + COLLATE explicito), igual ao fix de
-- v_attendance_receipts. View simples, sem EXISTS aninhado.
-- IMPORTANTE: comentarios NAO podem conter ';' (o runner faz split por ';').
-- DROP antes de CREATE: se a view existir corrompida, CREATE OR REPLACE pode
-- falhar; DROP IF EXISTS opera no metadata e sempre funciona.

DROP VIEW IF EXISTS v_calendar_full;

CREATE VIEW v_calendar_full AS
SELECT
  ce.id,
  ce.date,
  ce.type,
  ce.name,
  ce.description,
  ce.is_working_day,
  ce.recurrence,
  ce.school_id,
  s.name AS school_name,
  CASE
    WHEN ce.school_id IS NULL THEN _utf8mb4'Toda a rede' COLLATE utf8mb4_unicode_ci
    ELSE s.name
  END AS scope,
  DAYNAME(ce.date) AS day_of_week,
  DATE_FORMAT(ce.date, '%d/%m/%Y') AS date_formatted
FROM calendar_exceptions ce
LEFT JOIN schools s ON s.id = ce.school_id
ORDER BY ce.date DESC;

-- Adiciona suporte a turnos que cruzam a meia-noite em collaborator_time_schedules.
--
-- Motivação: colaboradores como vigilantes trabalham em jornada 18h → 06h do
-- dia seguinte. Hoje o sistema bloqueia explicitamente este cenário em três
-- pontos (teacher_edit.php JS, attendance_manual.php com mensagem
-- "Jornada que atravessa a meia-noite não é suportada nesta versão.", e
-- attendance_edit.php proíbe check_out <= check_in).
--
-- Solução: flag explícita end_next_day. Quando = 1, o end_time se refere ao
-- dia seguinte ao da entrada. Permite também turno de 24h (start = end com
-- end_next_day=1).
--
-- O schema de `attendance` já é DATETIME, então não precisa de migração ali.
-- A heurística "if (end <= start) +1 day" já estava em 6 call-sites do código
-- (helpers.php, attendances.php, reports.php, etc), portanto dados legados
-- com end_time <= start_time já eram tratados implicitamente como cruzando
-- meia-noite. A UPDATE abaixo torna esse estado explícito.

ALTER TABLE collaborator_time_schedules
  ADD COLUMN end_next_day TINYINT(1) NOT NULL DEFAULT 0
  AFTER end_time;

UPDATE collaborator_time_schedules
SET end_next_day = 1
WHERE start_time IS NOT NULL
  AND end_time IS NOT NULL
  AND end_time <= start_time;

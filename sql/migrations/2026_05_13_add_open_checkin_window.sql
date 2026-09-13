-- Janela temporal para o lookup de "registro aberto" no check-in.
--
-- Motivação: após adicionar suporte a turnos noturnos (migration
-- 2026_05_13_add_end_next_day.sql), a query em api/checkin.php passou a buscar
-- o último registro aberto SEM filtrar por `date` (necessário para fechar
-- entrada de ontem 22h com saída hoje 06h). Efeito colateral: registros órfãos
-- antigos (entradas que ninguém fechou há dias) passaram a ser detectados como
-- "abertos" e bloqueavam novas marcações com action_mismatch.
--
-- Solução: limitar a busca a registros recentes. 30h cobre confortavelmente:
--   - Turno noturno comum 18h→06h (entrada → saída = 12h)
--   - Turno 24h (08h → 08h do dia seguinte)
--   - Folga de 6h para casos extremos
--
-- Para attendance_manual.php (admin corrigindo saída de turno aberto há dias),
-- janela ampliada para 72h.

INSERT IGNORE INTO app_settings (k, v) VALUES ('open_checkin_window_hours', '30');
INSERT IGNORE INTO app_settings (k, v) VALUES ('open_checkin_window_hours_manual', '72');

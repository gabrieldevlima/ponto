-- Adiciona setting `min_checkout_gap_seconds` que controla o intervalo mínimo
-- entre check_in e check_out aceito pelo backend.
--
-- Bug que motivou: colaboradores reportavam que clicar em "registrar entrada"
-- às vezes gravava entrada e saída ao mesmo tempo. Causa raiz: race condition
-- entre 2 POSTs próximos do mesmo teacher (double-click, multi-aba, SW retry
-- com client_id distinto). O 1º POST cria entrada; o 2º vê a entrada e cai
-- na branch de saída, atualizando o mesmo registro com check_out=NOW().
-- Evidência em produção: 12 registros com gap < 60s, 3 com gap < 30s.
--
-- Mitigação: api/checkin.php agora rejeita saída quando o check_in existente
-- foi há menos de min_checkout_gap_seconds segundos. Combinado com GET_LOCK
-- por (teacher_id, date), a janela de race fecha.
--
-- Default 60s é conservador (ninguém bate ponto entrando e saindo em <1min).
-- Admin pode tunar via UI de settings se necessário.

INSERT IGNORE INTO app_settings (k, v) VALUES ('min_checkout_gap_seconds', '60');

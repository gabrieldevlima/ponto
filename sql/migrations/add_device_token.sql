-- Persistência do dispositivo via token server-issued em cookie httpOnly,
-- em vez de depender do fingerprint cacheado no localStorage do cliente.
--
-- Motivação: limpar cache do navegador não deveria fazer o sistema entender
-- que é um aparelho novo. O cookie httpOnly sobrevive à limpeza de cache
-- padrão (é preciso limpar cookies explicitamente para removê-lo), alinhando
-- o comportamento com a expectativa do usuário.
--
-- Fluxo:
--   1. Primeira autenticação bem-sucedida neste device → servidor gera token
--      aleatório de 64 hex, grava em teacher_trusted_devices.device_token,
--      e define cookie `ponto_device` (httpOnly, SameSite=Lax, 180 dias).
--   2. Próximas requests trazem o cookie automaticamente. O backend consulta
--      device_token → se match + teacher ok → device trusted (sem step-up).
--   3. Se cookie ausente (browser novo, cookies limpos, etc.), fallback para
--      device_fingerprint legacy. Se também não bater → step-up facial.
--   4. Step-up bem-sucedido em device novo → servidor emite novo token +
--      cookie → device passa a ser trusted.

ALTER TABLE teacher_trusted_devices
  ADD COLUMN IF NOT EXISTS device_token CHAR(64) NULL AFTER device_fingerprint,
  ADD UNIQUE INDEX IF NOT EXISTS uk_ttd_device_token (device_token);

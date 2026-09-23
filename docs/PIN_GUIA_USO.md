# Guia de Uso — Registro de Ponto por PIN (DEEDO Ponto)

Este documento descreve o novo fluxo de registro de ponto por **PIN** com **segurança adaptativa**. Ele coexiste com o reconhecimento facial, mas passa a ser o caminho recomendado para usuários leigos.

---

## 1. Visão geral

- **Método padrão:** PIN de 6 dígitos numéricos.
- **Reconhecimento facial:** acionado *apenas* quando o sistema percebe algo fora do padrão (dispositivo novo, fora da área da escola, falhas repetidas, etc.).
- **Entrada:** `public/ponto.php` — nova página mobile-first, minimalista.
- **Legado:** `public/index.php` continua disponível (face como método principal); não foi alterado.

---

## 2. Fluxo do colaborador

### 2.1 Primeiro acesso (gerar o PIN)
1. Abrir `ponto.php` no celular.
2. Tocar em **"Primeiro acesso • Gerar meu PIN"**.
3. Digitar o CPF.
4. Se o colaborador já tem **foto cadastrada**, o sistema pede uma captura facial para confirmar identidade.
5. Se o colaborador **não tem foto cadastrada**, só consegue prosseguir se o admin tiver liberado a flag **"auto-geração por CPF"** (ver §4).
6. PIN é exibido UMA vez na tela em fonte grande. Botão **"Copiar"** disponível. O PIN **não volta a aparecer**.

### 2.2 Bater ponto (uso normal)
1. Tocar em **"BATER PONTO"**.
2. Digitar CPF + os 6 dígitos do PIN.
3. Se tudo estiver ok (PIN correto, dispositivo conhecido, dentro da área da escola) → ponto registrado instantaneamente.
4. Caso algo pareça suspeito → o sistema pede **uma foto rápida** para confirmar. Se a foto bate, o ponto é registrado E o dispositivo é marcado como confiável daí em diante.

### 2.3 Esqueci meu PIN
1. Tocar em **"Esqueci meu PIN"** (visível em qualquer tela).
2. Digitar CPF.
3. Se o contexto for confiável (mesmo aparelho de sempre, dentro da escola, sem falhas recentes) → novo PIN gerado direto.
4. Senão → sistema pede foto. Se bate, novo PIN é gerado. Senão, bloqueia e orienta procurar o RH.

---

## 3. Segurança adaptativa — quando o sistema pede a foto

O check-in por PIN pode disparar validação facial quando **qualquer** destes sinais aparece:

| Sinal | Gatilho |
|---|---|
| Dispositivo desconhecido | Primeiro uso deste aparelho/browser para aquele colaborador |
| Fora da geofence | Localização fora do raio configurado da escola (padrão 300m) |
| GPS suspeito | Detector de mock GPS (cliente reporta fraude) |
| 3+ falhas de PIN | Em 15 minutos, pelo mesmo dispositivo/IP |
| PIN resetado recente | PIN renovado nas últimas 24h (1 validação extra) |

Um dispositivo entra na lista de "confiáveis" quando:
- O colaborador completa um step-up facial com sucesso, OU
- Acumula **3 check-ins bem-sucedidos** no mesmo aparelho em até **7 dias**.

Limite: **5 dispositivos ativos por colaborador**. O 6º novo substitui o mais antigo.

---

## 4. Painel administrativo

Acesso: **Gestão → PINs dos Colaboradores** (`teacher_pin_manage.php`).

Para cada colaborador:
- **Gerar / Resetar PIN** — o sistema exibe o novo PIN uma vez; o admin repassa presencialmente.
- **Liberar auto-geração por CPF** — permite que colaboradores sem face cadastrada gerem PIN sozinhos (use com cuidado).
- **Limpar dispositivos confiáveis** — útil em caso de troca de celular, furto, ou suspeita de comprometimento.

Indicadores na lista:
- **Status do PIN:** ativo / nunca gerou.
- **Face:** cadastrada / ausente.
- **Devices confiáveis:** quantidade.

---

## 5. Rate-limit e bloqueios

Todos os eventos de PIN ficam em `auth_attempt_logs`. Limites padrão:

| Fluxo | Janela | Limite | Comportamento |
|---|---|---|---|
| PIN no check-in | 5 min | 10 falhas | HTTP 429 "muitas tentativas" |
| PIN no check-in | 30 min | 20 falhas | Bloqueio adicional de abuso |
| Primeiro acesso | 5 min | 10 falhas | HTTP 429 |
| Recuperação de PIN | 30 min | 5 falhas | HTTP 429 |
| Face no step-up | 5 min | 5 falhas | HTTP 429 |

Após 3 falhas de PIN consecutivas, a tela facilita acesso ao "Esqueci meu PIN".

---

## 6. Auditoria

Todos os eventos críticos são gravados em `audit_logs`:

- `pin.generated_first_access` — colaborador gerou seu próprio PIN
- `pin.generated_recovery` — PIN regenerado via "Esqueci meu PIN"
- `pin.generated_admin_reset` — admin resetou o PIN
- `pin.checkin_success` — registro por PIN bem-sucedido
- `pin.checkin_stepup_required` — sistema pediu face adicional
- `pin.checkin_blocked` — não pode registrar (sem face + suspeita)
- `pin.self_enroll_allowed` / `pin.self_enroll_revoked` — admin mudou flag
- `device.cleared_trusted` — admin limpou dispositivos do colaborador

Campos em `attendance` preservados:
- `method='pin'` quando o registro veio pelo PIN.
- `device_fingerprint`, `ip`, `user_agent`, `fraud_risk_level`, `gps_mock_detected` — seguem sendo registrados.

---

## 7. Conformidade Portaria MTP 671/2021

| Requisito | Status com PIN |
|---|---|
| NSR único sequencial | ✅ Mantido (gerado na mesma transação) |
| Comprovante digital | ✅ `receipt.php` serve registros PIN normalmente |
| HLB (hora legal Brasil) | ✅ Validação idêntica ao fluxo face |
| `method` documentado | ✅ Novo valor `'pin'` aceito no campo VARCHAR existente |
| Device identification | ✅ Preservado |
| Audit trail | ✅ Ampliado com eventos PIN |

**Nada que já era exigido foi reduzido.**

---

## 8. Configurações (`app_settings`)

| Chave | Padrão | Descrição |
|---|---|---|
| `pin_length` | 6 | Tamanho do PIN em dígitos |
| `pin_max_failures_window` | 10 | Falhas permitidas por janela curta |
| `pin_rate_limit_window_sec` | 300 | Janela curta (segundos) |
| `pin_max_attempts_30min` | 20 | Limite de abuso por 30 min |
| `trusted_device_inactive_days` | 90 | Device vira inativo após X dias |
| `trusted_device_max_per_teacher` | 5 | Limite de devices ativos |
| `trusted_device_repeat_threshold` | 3 | Check-ins para auto-promoção |
| `trusted_device_repeat_window_days` | 7 | Janela da auto-promoção |
| `adaptive_time_check` | 0 | (reservado) checagem de horário fora do padrão |
| `pin_stepup_after_reset_hours` | 24 | Horas após reset em que pede face 1x |

Ajustáveis pelo admin via SQL: `UPDATE app_settings SET v = '...' WHERE k = '...'`.

---

## 9. Offline / PWA

- O fluxo PIN **exige rede** (validação do hash acontece no servidor).
- Quando sem internet, o colaborador pode usar a página legada `index.php` que cai em face/CPF e sincroniza quando voltar online.
- O Service Worker (`public/sw.js v2.4.0`) faz *precache* de `ponto.php` e seus recursos.
- Endpoints `/api/pin_enroll.php`, `/api/pin_recover.php` e `/api/checkin.php` são **network-only** (sem cache).

---

## 10. Migração

Arquivo: `sql/migrations/add_pin_and_trusted_devices.sql`

- Adiciona `pin_hash`, `pin_changed_at`, `pin_self_enroll_allowed` em `teachers`.
- Cria `teacher_trusted_devices`.
- Preenche `app_settings` com os defaults listados na §8.

Executa automaticamente no primeiro request (via `run_auto_migrations()` em `helpers.php`).

---

## 11. Riscos e mitigações (resumo)

| Risco | Mitigação implementada |
|---|---|
| Alguém que saiba o CPF tenta gerar PIN sem autorização | Face obrigatória no 1º acesso quando face já cadastrada; senão só admin libera |
| Brute-force do PIN (1M combinações) | Rate-limit 10/5min + 20/30min; PIN curto torna 70+ dias inviáveis |
| Terceiro de posse do PIN anotado | Geofence + device fingerprint + step-up face se aparelho novo |
| Device fingerprint instável em 4G | Normalização para IP /24 + 5 slots + enrollment após 3 usos |
| Step-up falha e usuário trava | Admin pode resetar PIN ou registrar ponto manual em `attendance_manual.php` |
| PIN copiado para clipboard vaza | Aviso visual + auto-clear após 30s |

---

## 12. Pontos de atenção para operação

- Orientar colaboradores: **"anote seu PIN assim que gerar; ele não volta a aparecer"**.
- Admins devem verificar periodicamente `audit_log.php` para eventos `pin.*` fora do padrão.
- Em troca de celular do colaborador, **limpar dispositivos confiáveis** pelo painel.
- Se a equipe de campo relatar muitas falhas repetidas de face no step-up, considere recadastrar a face (o cadastro facial anterior pode estar com qualidade ruim).

---

## 13. Arquivos envolvidos

| Camada | Arquivo |
|---|---|
| SQL | `sql/migrations/add_pin_and_trusted_devices.sql` |
| Helpers | `helpers.php` (seção final "PIN + Dispositivos confiáveis") |
| API | `api/pin_enroll.php`, `api/pin_recover.php`, `api/checkin.php` (branch PIN) |
| Frontend | `public/ponto.php` |
| PWA | `public/sw.js` (v2.4.0) |
| Admin | `public/admin/teacher_pin_manage.php`, `public/admin/teacher_pin_reset.php` |
| Navbar | `public/admin/_navbar.php` |

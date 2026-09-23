# Auditoria operacional em ambiente real — DEEDO Ponto (oeirasponto.deedo.com.br)

**Data:** 12/09/2026 · **Escopo:** produção (código, banco MariaDB 11.8, logs, HTTP) + árvore local
**Método:** leitura do código em produção, consultas somente leitura ao banco real, análise de
`logs/php_errors.log`, testes HTTP de exposição, e validação das correções em cópia do código de
produção com o **esquema real** do banco (sem dados pessoais), incluindo testes de concorrência
com requisições simultâneas.

> Referência anterior: `docs/AUDITORIA_CONFORMIDADE_2026-08-05.md`. As correções da "Fase 0"
> daquela auditoria **nunca foram implantadas** — produção roda o código de junho/2026 e a árvore
> local (não commitada) está ~60 arquivos à frente.

---

## 1. Situação atual (evidências de produção)

| Indicador | Valor real |
|---|---|
| Colaboradores ativos / admins | 102 / 2 (ambos `network_admin`) |
| Marcações | 12,7 mil (≈3 mil/mês; 99% por PIN/sessão) |
| Pico | 07:00–07:15, até 6 marcações/min |
| Log de erros | 16.722 avisos `Undefined variable $livenessScore`; ~500 "foto suspeita" |
| `offline_delay_seconds` | média 10.796 s (≈3h) em marcações **online** → bug de fuso |
| Quase-duplicatas (≤2 min) | ~30 pares, crescendo mês a mês (2→9) |
| Registros > 16h | 241 · sobreposições: 20 · aberto desde 29/04: 1 |
| CPF duplicado ativo | ids 65 e 97 |
| Órfãos (colaborador apagado) | 7 attendance · 7 hour_bank · 6 edits · 4 teacher_schools |
| Pendências | 740 horas extras (funcionalidade descontinuada) · 66 regularizações de saída · 3 de intervalo |
| Correções manuais | ~1.000 edições em 90 dias, praticamente todas de um admin |
| Tokens "lembrar-me" | 602 (um colaborador com 169; 182 sem uso há 90+ dias) |
| Fotos faciais | 8.696 arquivos / 211 MB, **públicos**, nenhuma expurgada (mais antiga: 23/04) |
| Consentimento LGPD no banco | 0 registros |
| Backup de banco deste site | nenhum automatizado encontrado no servidor |

### Causa raiz das "marcações duplicadas" (confirmada na trilha de auditoria)
Não é corrida de banco: o bloqueio por colaborador (`GET_LOCK`) e o NSR funcionam sob concorrência.
O padrão real é **entrada → "Iniciar intervalo" 5–60 s depois → "Retornar" → nova entrada**. O botão de
intervalo aparece ao lado do principal logo após a entrada e não havia espera mínima para ele
(ex.: ids 12652–12656 em 11/09, 5 registros em 110 s).

### Causa raiz dos registros > 16h
A janela de "turno em andamento" é de 30h para todos. A entrada esquecida de ontem continua "aberta"
e a batida da manhã seguinte vira **saída** de um registro de ~24h; o modal só mostrava HH:MM e
"trabalhados hoje".

---

## 2. Problemas encontrados

Legenda de status: **C** = corrigido no hotfix · **L** = já corrigido só na árvore local (não implantado)
· **R** = recomendação (exige decisão/ação fora do código).

### CRÍTICO

| # | Problema | Onde | Impacto | Causa provável | Correção | Afeta funcionalidade? | Status |
|---|---|---|---|---|---|---|---|
| C1 | Tomada de conta com **só o CPF**: "Esqueci meu PIN" gera PIN novo, devolve na resposta e **faz login** | `api/pin_recover.php:196,364` | Qualquer pessoa bate ponto/acessa dados de qualquer colaborador; o PIN do dono é trocado | `$trustable = true` fixo (decisão de 2026-05 para contornar falhas de face) | Contexto confiável = dispositivo conhecido + geofence + sem falhas; senão foto; sem face → admin. Sem auto-login | Colaborador em aparelho novo sem foto cadastrada precisa do admin | C (L) |
| C2 | Primeiro acesso cria PIN **só com CPF** (qualquer foto aceita) | `api/pin_enroll.php:165-198` | Tomada das contas ainda sem PIN (4 hoje) | Portão `pin_self_enroll_allowed` lido e nunca verificado | Face → match; liberado pelo admin → fluxo antigo (foto + PIN); senão → admin | Quem não tem PIN precisa ser liberado em *Gerenciar PIN* | C (L) |
| C3 | **Fotos faciais públicas** (dado biométrico) | `public/photos/` sem `.htaccess`; `/photos/<arquivo>.jpg` → 200 | Incidente LGPD (art. 11/46/48) | Proteção só por nome aleatório; `.gitignore` impedia versionar o `.htaccess` | `.htaccess` deny + `photo_view.php` autenticado com escopo; admin usa o visualizador | Miniaturas do admin passam pelo PHP (sem mudança visual) | C (L) |
| C4 | `/admin/clear_opcache.php` sem autenticação | arquivo inteiro | DoS barato (recompila todo o código) | Script avulso sem guarda | Exige admin de rede + POST + CSRF | Nenhum | C (L) |
| C5 | Credenciais do banco em texto no `config.php` versionado; dumps SQL com dados pessoais dentro de `public_html` (hoje 404 só por causa do rewrite) | `config.php:6-7`, raiz do site | Vazamento se o rewrite falhar/repositório exposto | Deploy por cópia do repositório | Rotacionar senha; mover dumps para fora do webroot; `config.local.php` | Nenhum | R |
| C6 | Sem backup automatizado do banco deste site | servidor | Perda de dados irrecuperável | Não configurado | Backup diário (Hostinger + dump próprio fora do servidor) e teste de restauração | Nenhum | R |

### ALTO

| # | Problema | Onde | Impacto | Causa | Correção | Afeta? | Status |
|---|---|---|---|---|---|---|---|
| A1 | Marcação **só com CPF** (sem PIN/sessão) aceita, revela nome e emite token de cadastro facial | `api/checkin.php:971` | Ponto em nome de terceiros; troca da biometria via `save_face_inline` | Caminho legado sem uso desde 30/04 | Recusa com `pin_required` | Nenhum uso real | C |
| A2 | Toque acidental em "Iniciar intervalo" logo após a entrada gera 3 registros | `api/checkin.php:1266` (gap só p/ saída) | Espelho poluído, correções manuais | Botão ao lado do principal, sem espera | Mesma espera mínima (`min_checkout_gap_seconds`) para iniciar intervalo; retorno livre | Intervalo legítimo < 60 s após entrada bloqueado | C |
| A3 | Fuso: hora do cliente gravada +3h; `offline_delay_seconds` sempre ≈10.800 | `index.php:11262` + `checkin.php:1081` | Auditoria offline inútil | `toISOString()` sem "Z" lido como horário de Brasília | Servidor normaliza UTC→Brasília (vale para PWAs antigos). O ramo que confiaria no relógio do cliente fica desligado (a correção o ativaria e abriria retrodatação) | Nenhum na hora gravada | C |
| A4 | Registro **anulado** pelo admin podia receber a saída do colaborador; saída sobrescrevia edição do admin; rejeição virava "pendente" | `checkin.php:1196,2009`, `checkin_bulk.php:234,507`, `helpers.php:2137` | Perda silenciosa de marcação/edição | Busca e UPDATE sem guarda de estado | Filtro `removed_at/superseded_by_id`; `UPDATE ... AND check_out IS NULL` + `rowCount`; preserva `approved=0` | Nenhum | C (parcial L) |
| A5 | Idempotência por `client_id` verificada **antes** do lock; falha do lock seguia **sem** lock | `checkin.php:364,1159` | Retry durante gravação vira nova ação | Checagem fora da seção crítica | Revalida sob o lock; falha de lock → `busy_retry` | Nenhum | C |
| A6 | Modo sessão reresolvia o colaborador pelo CPF (`LIMIT 1`) | `checkin.php:453` | Com CPF duplicado ativo, ponto no cadastro errado | Consulta por CPF | Consulta por id da sessão | Nenhum | C |
| A7 | Saída esquecida > 30h bloqueia toda batida com toast genérico "Erro", sem caminho | `index.php` (handler inexistente) | Colaborador sem conseguir bater ponto; fila offline descartava o item | Tratamento só existia em `ponto.php` | Modal "Sua batida NÃO foi registrada" com botão para regularizar no mês correto; fila não descarta | Nenhum | C |
| A8 | Entrada de outro dia confirmada como "saída de hoje" (241 registros > 16h) | `index.php` modal de confirmação | Jornadas de 24h, retrabalho do admin | Modal sem data | `last_checkin` informa `open_is_stale`; modal mostra alerta com a data e orienta cancelar e regularizar | Nenhum | C |
| A9 | Força bruta na senha do admin: limite guardado na sessão | `admin/login.php:24` | Descartar cookie zera o contador | Contador em `$_SESSION` | Limite em `auth_attempt_logs` por IP e por CPF | Nenhum | C (L) |
| A10 | `last_checkin.php` responde nome e presença de **qualquer CPF** sem login | `api/last_checkin.php` | Enumeração de vínculo e presença em tempo real | Endpoint pensado para quiosque | Com sessão usa o próprio cadastro; sem sessão não retorna nome e limita por IP | Quiosque legado perde a saudação com nome | C |
| A11 | Sessão admin nunca expirava; token "lembrar-me" de 10 anos, sem expiração no banco nem poda | `config.php:16`, `helpers.php:606-658` | Sessão/token vazado vale indefinidamente | Timeouts definidos e nunca lidos | Inatividade de 2h no admin; token 90 dias; mantém 5 por colaborador | Admin inativo 2h faz login de novo | C (L parcial) |
| A12 | `has_permission()` fail-open (colunas inexistentes → sempre `true`) | `helpers.php:1380` | Qualquer `school_admin` futuro teria acesso total | Esquema divergente | Fail-closed | Sem efeito hoje (só há admins de rede) | C (L) |
| A13 | Comprovante/holerite: admin nunca conseguia abrir (`admin_logged` inexistente) e não havia escopo por escola | `receipt.php:22`, `get_receipt.php:63`, `payslip.php:47` | Bug funcional + IDOR latente | Chave de sessão errada | `admin_id` + `admin_scope_where` | Admin volta a abrir comprovantes | C (L) |
| A14 | Migrações automáticas em **toda** requisição (2ª conexão, CREATE + 4 ALTER, lock global de até 30 s, hash de todos os `.sql`) | `config.php:166` → `helpers.php:27` | Latência em todas as telas; deploy com ALTER lento trava as batidas das 07h | Desenho do runner | Assinatura do diretório (nome+tamanho+mtime): só roda quando algum `.sql` muda | Nenhum | C |
| A15 | Retenção de fotos nunca executada (0 expurgadas) | `helpers.php:4250`, sem cron | Acúmulo de biometria além da política | Só age acima de 500 MB; sem cron; bug de caminho marcava "apagada" sem apagar | Corrigido o bug de caminho. **Agendar cron e definir prazo é decisão** (Portaria 671 × LGPD) | Expurgo é irreversível | C + R |
| A16 | CPF podia ser trocado na edição para o de outro colaborador ativo | `teachers_save.php:124` | Duplicidade (já existe 65/97) | Pre-flight só no cadastro novo | Bloqueia duplicidade entre ativos | Editar 65 ou 97 exige resolver a duplicidade antes | C |
| A17 | CPF duplicado ativo (65/97), órfãos e ausência quase total de FKs/UNIQUE (`teachers.cpf`, `attendance.nsr`, `calendar_exceptions`) | banco | Ponto/login no cadastro errado, inconsistências | Constraints nunca criadas | Resolver 65/97, arquivar órfãos, criar UNIQUE/FK por migração manual fora do pico | Nenhum após limpeza | R |

### MÉDIO

| # | Problema | Onde | Correção | Status |
|---|---|---|---|---|
| M1 | Dashboard: ~9.700 consultas por abertura (N+1 colaborador × dia) | `admin/dashboard.php:212` | Jornadas lidas 1x por colaborador + cache de 10 min do KPI. **Fórmula inalterada** (mesmo valor validado) | C |
| M2 | KPI "horas a compensar" soma dias futuros, ignora feriados/afastamentos | `dashboard.php` | Usar o cálculo canônico (`calculate_expected_minutes`) até hoje — muda o número | R |
| M3 | Cálculo de horas duplicado em ~7 telas com regras/arredondamentos diferentes | dashboard, attendances, reports, financial, monthly, my_timesheet | Serviço único de apuração por período + testes que comparem telas | R |
| M4 | Análise GD da foto (~390 mil leituras de pixel) síncrona em toda entrada, resultado só no log | `checkin.php:1441` | Desligada (mantida atrás de constante) | C |
| M5 | Service Worker cacheava GETs de `/api/*.php` e servia redirect de login do cache | `sw.js:169,212` | `/api/` sempre rede; `opaqueredirect` seguido; `CACHE_VERSION` v2.59.0 | C |
| M6 | Fila offline enviava 3 em paralelo (saída antes da entrada → descarte) e descartava `busy_retry` após 5 tentativas; banner "salvo no aparelho" não sumia | `index.php:6795,6552` | Concorrência 1 em ordem; `busy_retry`/`orphan_punch_pending` como backoff; limpa banner e recarrega estado | C |
| M7 | Códigos `checkout_too_soon`, `state_changed`, `busy_retry` etc. viravam "Erro" sem a dica | `index.php` handler | Toast com dica + recarga do estado | C |
| M8 | Mensagens de exceção (SQLSTATE) devolvidas ao colaborador | `edit_own_break.php:92`, `regularize_*.php`, `checkin_bulk.php:597` | Mensagem genérica + `error_log` | C |
| M9 | `checkin_bulk`: sem lock, lote ilimitado, CPF em claro no log | `api/checkin_bulk.php` | Mesmo lock por colaborador, limite de 50, identificador com hash | C |
| M10 | Aprovar registro anulado; redirect acumulando `&msg=` e aceitando Referer externo | `attendances_action.php` | Bloqueio + redirect interno sem acúmulo | C |
| M11 | `teacher_schools ORDER BY id` (coluna inexistente) → erro silencioso, sábado letivo por escola ignorado no intervalo previsto | `helpers.php:1945,2191` | `ORDER BY school_id` | C |
| M12 | `audit_log()` engolia falhas sem registro | `helpers.php:897` | `error_log` | C (L) |
| M13 | Aprovação 1 a 1 (1.206 APPROVE), sem filtro por motivo; `out_of_radius`/`no_gps` sem aviso claro ao colaborador | admin/attendances, PWA | Aprovação em lote + aviso persistente de "ficará pendente" | R |
| M14 | 740 horas extras pendentes de funcionalidade descontinuada; 66 regularizações sem badge | `overtime_requests`, navbar | Arquivar legado; badge de pendências; fechar pedido quando o admin corrige o dia | R |
| M15 | Crescimento sem limite: `audit_logs` (+10 mil/mês), `auth_attempt_logs` | banco | Política de retenção definida com o jurídico + índices em `created_at` | R |

### BAIXO

| # | Problema | Correção | Status |
|---|---|---|---|
| B1 | 16.722 warnings `$livenessScore` escondendo erros reais | Variáveis inicializadas | C |
| B2 | `$_GET['approved']` sem escape no PDF | `esc()` | C |
| B3 | Consentimento LGPD só em `localStorage`; política com link quebrado | Gravar em `lgpd_consent` (já feito na árvore local) | L/R |
| B4 | PHP CLI 7.4 × web 8.3 | Agendar cron com `/opt/alt/php83/usr/bin/php` | R |
| B5 | Código morto/legado (`write_leaves.php`, `tools/legacy`, `test_output.php`, overtime) | Remover em limpeza dedicada | R |
| B6 | `min_checkout_gap_seconds = 60` | Avaliar 180–300 s (configuração, sem código) | R |

---

## 3. Correções realizadas (hotfix sobre o código de produção)

Pacote: 23 arquivos, **sem alteração de banco**. Mesmas correções replicadas na árvore local onde ainda
não existiam (13 arquivos; nas partes que a árvore local já resolvia, mantida a versão local).

**Segurança:** C1, C2, C3, C4, A1, A9, A10, A11, A12, A13, M8, B2.
**Integridade/concorrência:** A2, A4, A5, A6, A16, M9, M10, M11.
**Desempenho:** A14 (sem 2ª conexão/lock por requisição), M1 (dashboard 1,98 s → 0,36 s em cache,
1,44 s sem), M4 (sem GD síncrona), pico de 20 entradas simultâneas 1,8 s → 0,58 s.
**UX/confiabilidade:** A7, A8, M5, M6, M7, B1.

## 4. Validação

| Bateria | Código atual de produção | Hotfix |
|---|---|---|
| Funcional e segurança HTTP (31 verificações: PIN, primeiro acesso, endpoints, fotos, login admin, marcações, fuso, anulação, comprovante, escopo, aprovação, dashboard, tokens) | 11 OK / 19 falhas (vulnerabilidades reproduzidas) | **31 OK / 0 falhas** |
| Concorrência real (4 processos PHP; mesmo `client_id` ×6, toques ×6, saídas ×6, 20 colaboradores simultâneos, intervalo acidental) | 8 OK / 1 falha (3 registros no intervalo acidental) | **9 OK / 0 falhas**; NSR único e sem lacunas |
| Suíte existente do código de produção (17 scripts, banco de teste) | falhas de ambiente em 12 scripts | **resultado idêntico script a script** (sem regressão) |
| Suíte da árvore local (37 scripts) | 35/37 | **35/37** (2 falhas pré-existentes: AFD e ledger) |
| KPI do dashboard | 6866h00 | **6866h00** |
| Sintaxe PHP (22 arquivos), JS inline do PWA, `sw.js` | — | OK |
| Navegador (PWA mobile) | — | login, estado, alerta de entrada antiga e toast de espera do intervalo conferidos |

Não validado em teste automatizado: modal de saída esquecida > 30h (depende de câmera no fluxo de
entrada) — lógica simples, coberta por revisão.

## 5. Pontos a monitorar após o deploy

- Pedidos de "Esqueci meu PIN" que passam a exigir foto/admin (`auth_attempt_logs.reason = blocked_contact_admin`).
- Colaboradores sem PIN que precisarão ser liberados em *Gerenciar PIN*.
- Ocorrências de `break_too_soon` e `state_changed` (devem ser raras).
- `logs/php_errors.log` sem os warnings de liveness; `offline_delay_seconds` ≈ 0 em marcações online.
- CDN da Hostinger: purgar cache de `/photos/` após o deploy (a borda pode manter cópias).
- Tempo das telas admin e das batidas às 07h.

## 6. Recomendações de médio e longo prazo

1. **Implantar a árvore local** (ledger NSR, AFD/AEJ, âncora de tempo, LGPD) em janela planejada, com
   migrações rodadas fora do horário de pico e as 2 falhas de teste resolvidas; commitar esse trabalho.
2. Resolver CPF 65/97, arquivar órfãos e criar UNIQUE/FK (`teachers.cpf`, `attendance.nsr`,
   `calendar_exceptions`, `overtime_requests.attendance_id`) e FKs para `teachers`.
3. Backup diário do banco fora do servidor + teste de restauração trimestral.
4. Rotacionar a senha do banco; remover fotos e dumps do histórico do Git (`git filter-repo`) e tratar
   como incidente LGPD; retirar dumps de `public_html`.
5. Definir com o jurídico a retenção de fotos e logs e agendar o cron de expurgo.
6. Serviço único de apuração de horas (fim das divergências entre telas).
7. Aprovação em lote, badge de pendências e fechamento automático de regularizações.
8. Janela de turno aberto baseada na jornada do colaborador (não 30h fixas).
9. Extrair o JS/CSS do `index.php` (545 KB) para arquivos estáticos versionados.

## 7. Avaliação final

A base técnica de concorrência é **sólida** (bloqueio por colaborador e NSR transacional resistiram a
requisições simultâneas). O que comprometia a plataforma em produção eram **falhas de segurança
graves e ativas** (tomada de conta por CPF, biometria pública), **armadilhas de interface** que geram
erro humano diário (intervalo acidental, saída esquecida, erros sem orientação) e **dívida de
integridade no banco** (sem constraints, duplicidades, órfãos, sem backup).

Com o hotfix implantado: **adequada para uso diário**, com os riscos críticos de segurança fechados e as
principais causas de retrabalho atacadas. Maturidade ainda **intermediária** até implantar a árvore
local (conformidade Portaria 671/LGPD), criar as constraints e o backup automatizado.

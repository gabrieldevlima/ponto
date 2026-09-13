# Auditoria de Conformidade — Ponto Eletrônico (DEEDO Ponto / ponto_oeiras)

> **Data:** 2026-08-05
> **Escopo:** Portaria MTP nº 671/2021, CLT (arts. 58, 59, 66, 71, 73, 74) e LGPD
> **Método:** análise de código-fonte, schema, dump de produção e regras de negócio — **não** análise visual de telas
> **Status:** 56 não conformidades identificadas (4 críticas de segurança, ativas em produção)
> **Fase 0 (hotfix de segurança):** aplicada em 2026-08-05 — ver seção "Estado da execução" ao final
>
> Este documento **substitui** as afirmações de `PORTARIA_MTP_671_COMPLIANCE.md`,
> `IMPLEMENTACAO_PORTARIA_671_COMPLETA.md` e `ATESTADO_TECNICO_MODELO.md`, cujas
> declarações de conformidade foram confrontadas com o código e **não se confirmaram**
> (ver NC-19). O Atestado Técnico do Anexo VII **não deve ser assinado** até a
> conclusão das fases 1 a 6 deste plano.

## Contexto

Sistema PHP/MySQL de registro eletrônico de ponto (REP-P) em **uso real em produção** — o dump `u803039033_pontooeiras (2).sql` traz ~109 colaboradores, ~2.200 registros de marcação, CPFs e templates biométricos reais. O código declara conformidade em `config.php:82` (`PORTARIA_671_COMPLIANT = true`), e `docs/IMPLEMENTACAO_PORTARIA_671_COMPLETA.md:5` afirma "Conformidade 100%".

A auditoria foi feita **no código, no schema e no dump de produção** — não nas telas. O resultado: a declaração de conformidade não se sustenta. O sistema atende cerca de 40% dos requisitos da Portaria MTP 671/2021 (a mesma estimativa que a auditoria interna anterior, `docs/AUDIT_REPORT_2026-04-26.md:121`, já havia registrado sem que a flag fosse corrigida), e há **duas falhas críticas de segurança ativas** que permitem tomada de conta de qualquer trabalhador.

Objetivo: levar o sistema a conformidade plena e defensável perante fiscalização do MTE, com dados íntegros, rastreáveis e protegidos, e adequado à LGPD.

**Decisões tomadas com o usuário:**
1. Hotfix de segurança **antes de tudo** (Fase 0).
2. Entrega desta etapa = relatório versionado em `docs/` + plano de ação; execução das correções vem em seguida, fase a fase.
3. Alvo = **conformidade plena** (ledger com NSR por marcação, cadeia de integridade, AFD, AEJ, espelho legal, HLB via NTP).

### Correções de premissa apuradas na auditoria
- Produção é **MariaDB 11.8.6**, não MySQL 8.4 (`u803039033_pontooeiras (2).sql:7`). Dev local é MySQL — migrations devem ser portáveis nos dois.
- `teachers` **não tem PIS/NIS**, data de admissão, matrícula nem CBO (`:29746`) — bloqueia o AFD tipo 3.
- `employer_config.cnpj` está **NULL em produção** — bloqueia o cabeçalho do AFD.
- Produção tem **zero triggers** e **`attendance.nsr` sem UNIQUE** (só `KEY idx_attendance_nsr`, `:316`), embora todos os instaladores declarem ambos.

---

# PARTE I — RELATÓRIO DE AUDITORIA

## 1. Funcionalidades em conformidade

| Item | Evidência |
|---|---|
| NSR sequencial, atômico, sem lacunas na inserção | `SELECT current_nsr FROM nsr_sequence WHERE id=1 FOR UPDATE` dentro da transação do INSERT — `api/checkin.php:1766`, `checkin_bulk.php:399`, `kiosk_checkin.php:302`, `attendance_manual.php:256/346`, `helpers.php:3863` |
| Nunca apaga registro legal | Zero `DELETE FROM attendance` em produção. Anulação é soft-delete reversível — `helpers.php:3565` (`admin_remove_attendance`), `:3630` (`admin_restore_attendance`), colunas `removed_at`/`removed_by_admin_id`/`removed_reason`/`superseded_by_id` |
| Motivo, responsável, data/hora e IP obrigatórios em toda alteração | Motivo validado em `helpers.php:3567` e `:3857`; `attendance_audit_log` grava campo-a-campo com `old_value`/`new_value`/`ip_address`/`user_agent` (`helpers.php:774`) |
| Estado anterior completo preservado em log | `attendance_edits.before_json`/`after_json` — `helpers.php:3958/3971/3991/4051` |
| Tela de auditoria somente-leitura | `public/admin/audit_log.php` — UNION de `attendance_audit_log` + `attendance_edits`, sem rota de edição |
| Comprovante de marcação em PDF | `public/receipt.php` — NSR, CNPJ, razão social, nome, CPF, data/hora, tipo, modo, GPS, device; entrega via `my_timesheet.php:1112` e pós-marcação em `index.php:13132` |
| Idempotência offline | `client_id`/`checkout_client_id` com UNIQUE, dedupe por `superseded_by_id`, `GET_LOCK` por colaborador |
| Marcação offline entra como pendente | `approved=null` + `fraud_risk_level>=1` + `pending_reasons` — `checkin_bulk.php:331-347` |
| Força do PIN | `pin_validate_strength()` (`helpers.php:4767-4849`): bloqueia sequências, repetições, janelas do CPF e datas plausíveis. É a parte mais sólida do sistema |
| Rate limiting do colaborador persistido | 10 falhas/5min por `sha256(ip\|ua\|cpf)` **e** 20/1h por `teacher_id` — `helpers.php:485-502` |
| Postura anti-SQLi | PDO com `ATTR_EMULATE_PREPARES=false` (`config.php:142`), prepared statements; nenhum `echo $_GET/$_POST` direto no projeto |
| Upload e path traversal | MIME real via `getimagesizefromstring()` (`kiosk_lib.php:180-204`), nome aleatório, anexos fora do webroot com `Deny from all` |
| HMAC do quiosque | `hash_hmac`+`hash_equals`, device-bound, consumo atômico de uso único (`kiosk_lib.php:295-307`) |
| Liveness multi-sinal com anti-replay | nonce UNIQUE, janela de 60s, 5 sinais, score composto — `api/checkin.php:796-957` |
| Turno que cruza meia-noite | `end_next_day` + `compute_schedule_window()` (`helpers.php:1772`); testes `test_overnight_shift`, `test_overnight_integration` |
| Calendário: feriados fixos/móveis, recesso, sábado letivo | `calendar_exceptions`, `mobile_holidays`, `calculate_easter()`, `calendar_day_is_off()` (`helpers.php:4389`) |
| Abono de falta desacoplado de "pago" | `leaves.excuses_absence` + `leave_day_is_excused()` (`helpers.php:1866`) |
| Expurgo de fotos sem apagar o registro | `cleanup_old_photos()` (`helpers.php:4242`) marca `photo_deleted=1` e preserva a linha |
| Auditoria de acesso a atestados | `leave_attachment_access_log` — `view_leave_attachment.php:78-86` |
| Isolamento por unidade nas listagens | `admin_scope_where()` (`helpers.php:750`) aplicado em ~25 pontos |

## 2. Funcionalidades parcialmente adequadas

| Item | O que está certo | O que falta |
|---|---|---|
| **NSR** | Sequencial e atômico | Não é UNIQUE no banco de produção; **a saída não recebe NSR próprio** (o par entrada+saída consome 1 NSR — `checkin.php:2081`); nenhum trigger protege |
| **Preservação do original na edição** | Estado anterior fica em `attendance_edits.before_json` | O registro legal é **sobrescrito in-place** (`helpers.php:4020-4036` faz `UPDATE ... SET check_in=?, check_out=?, date=?`). Perdido o log, some o original |
| **Comprovante** | Tem NSR, CNPJ, CPF, data/hora, GPS | Faltam CPF do empregador, **local da prestação de serviço** e **identificação do REP** (`rep_category` é lido em `receipt.php:143` e nunca renderizado) |
| **Selo de integridade do comprovante** | Existe visualmente | `substr(sha256(nsr\|id\|cpf\|recordedAt\|action),0,16)` **sem chave secreta**, recalculado na emissão a partir dos dados atuais (`receipt.php:183`). Não detecta adulteração. O texto "Qualquer alteração invalida o selo" (`:518`) é factualmente falso |
| **Log de auditoria** | Três camadas, ricas | Não é imutável (sem trigger/WORM/hash); `audit_log()` **engole exceções em silêncio** (`helpers.php:897-899`); `attendance_edits` tem `ON DELETE CASCADE` |
| **Espelho de ponto** | `teacher_monthly_report.php` gera PDF mensal | Sem NSR por marcação e sem o leiaute legal |
| **Exportação** | XLSX e CSV em `reports.php:437/472` | Formato livre — não é AFD nem AEJ |
| **Geolocalização** | Haversine + raio configurável + fallbacks | Fora do raio **não bloqueia**; raio efetivo chega a 500m; `mockDetected` vem do cliente |
| **Perfis de acesso** | 2 papéis + escopo por escola funcionando | `has_permission()` é **fail-open** (`helpers.php:1389,1392`: sem regra → `return true`; exceção → `return true`); só 3 chaves de permissão; não existem papéis de gestor/RH |
| **Retenção de fotos** | Cron + flag `photo_deleted` | Gatilho é **espaço em disco (500MB)**, não prazo — foto de 2 anos fica retida se o volume for pequeno (`helpers.php:4249-4257`) |
| **Banco de horas** | Ledger `hour_bank_entries` com fonte e referência | Sem prazo de compensação (6 meses/1 ano); compensação só intramensal; saldo não transita entre meses |
| **Tolerância de marcação** | Existe (`tolerance_minutes`=5) | Aplicada ao **agregado do mês/dia**, não por marcação, e sem o teto de 10 min/dia do art. 58 §1º |

## 3. Não conformidades identificadas

### 3.1 Segurança — CRÍTICAS (produção)

**NC-01 — Tomada de conta de qualquer colaborador com apenas o CPF.**
`api/pin_recover.php:196` — `$trustable = true;` atribuição incondicional. Todo o bloco `if (!$trustable)` (linhas 199-330: enrolment facial, step-up, `find_active_face_conflict`, consenso) é **código morto**. Consequência: `POST /api/pin_recover.php` com `{"cpf":"<CPF de qualquer ativo>"}` → gera novo PIN (`:335`), **cria sessão autenticada** e emite **cookie remember-me de 10 anos** (`:363`), e devolve o PIN em texto claro (`:370`). Sem CSRF (`:66` só exige se já logado). Uma requisição basta — rate limits não protegem. Como o CPF circula em crachás, contracheques e listas de RH, isso permite **bater ponto no lugar de outro** e ler holerite, espelho e comprovantes alheios.

**NC-02 — Primeiro acesso aceita descriptor facial forjado.**
`api/pin_enroll.php:164-198` — no cadastro inicial, `normalize_face_descriptors()` (`helpers.php:1492`) valida apenas "128 floats finitos". Não há `find_active_face_conflict` (ao contrário do que o comentário em `:150-155` afirma). CPF válido sem PIN + 128 números aleatórios via curl → conta tomada com auto-login (`:264`).

**NC-03 — Fotos biométricas servidas sem autenticação.**
`public/photos/` não tem `.htaccess` nem `index.php` (só `admin/`, `admin/tools/legacy/` e `attachments/` são protegidos). As fotos são linkadas diretamente (`attendances.php:1174`) e servidas pelo Apache. A única proteção é o nome aleatório de 32 hex.

**NC-04 — Dump com dados pessoais versionado no Git.**
`sql/backups/u803039033_pto_ribeira.sql` está em `git ls-files` (commit `a73b6f6`). Contém nomes, **CPFs**, **214 hashes bcrypt** e **templates biométricos** reais. `config.php` também é versionado, com credenciais de banco.

### 3.2 Integridade dos registros — Portaria 671

| ID | Não conformidade | Evidência |
|---|---|---|
| **NC-05** | **AFD (Arquivo Fonte de Dados) não existe** | Nenhum exportador. Busca por `AFD/AEJ/ACJEF` só acha o placeholder de formulário em `leave_types.php:98` e a auditoria anterior |
| **NC-06** | **AEJ (Arquivo Eletrônico de Jornada) não existe** | idem |
| **NC-07** | **Espelho de Ponto em leiaute legal não existe** | `teacher_monthly_report.php` é relatório interno, sem NSR por marcação |
| **NC-08** | **Sem cadeia de integridade, hash encadeado ou assinatura digital** | Zero `prev_hash`/`record_hash`/`openssl_sign`/`hash_hmac` no caminho de marcação. Nenhum certificado ICP-Brasil |
| **NC-09** | **Marcação de saída sem NSR próprio** | `checkin.php:2081-2084` reaproveita o NSR da entrada; `checkin_bulk.php:539`, `attendance_manual.php:305` idem |
| **NC-10** | **`attendance.nsr` sem UNIQUE em produção** | Dump `:316` tem só `KEY`. O retry de colisão em `checkin.php:1804-1830` é **código morto em produção** — duplicata seria gravada em silêncio |
| **NC-11** | **Zero triggers no banco de produção** | Contagem de `CREATE TRIGGER` no dump = 0. Qualquer acesso direto ao MySQL altera marcações sem rastro |
| **NC-12** | **Registro original sobrescrito na edição** | `helpers.php:4020-4036` |
| **NC-13** | **HLB (Hora Legal Brasileira) não é sincronizada** | `HLB_NTP_SERVER` (`config.php:121`) **nunca é lido**. A sincronização real é `fetch('https://worldtimeapi.org/...')` **no navegador** (`index.php:5607`) |
| **NC-14** | **Horário offline decidido por dado do próprio cliente** | `checkin.php:1083-1094` aceita `client_recorded_at` como horário legal se `$input['hlbOffsetSeconds']` ≤ 120s — e esse offset **vem do cliente** (`:1071`). Circular: quem quer retrodatar envia `hlbOffsetSeconds: 0` e injeta qualquer horário de até 30 dias atrás |
| **NC-15** | **Liveness inteiramente client-side** | Todas as métricas (`micro_movement_stddev`, `blink_count`, `texture_score`, `gyro_variance`) são calculadas no navegador e enviadas como JSON. Um POST forjado passa em 100% das checagens |
| **NC-16** | **Device fingerprint falsificável** | `sha256(user_agent + '\|' + clientFp)` (`helpers.php:4884`) — 100% controlado pelo cliente |
| **NC-17** | **Comprovante sem local de prestação, CPF do empregador e identificação do REP** | `receipt.php` — art. 80 da Portaria |
| **NC-18** | **`recorded_at` sem precisão de milissegundos** | Schema real: `datetime DEFAULT NULL`. `docs/PORTARIA_MTP_671_COMPLIANCE.md:30` afirma `DATETIME(3)` |
| **NC-19** | **Declarações falsas de conformidade** | `config.php:82` `PORTARIA_671_COMPLIANT=true`; `receipt.php:523` afirma conformidade no documento emitido ao trabalhador; `IMPLEMENTACAO_PORTARIA_671_COMPLETA.md:5` "100%"; `_tpl_receipt_pdf.php` citado como evidência **não existe**; "tabela `lgpd_consent` registra consentimentos" — **zero referências no código PHP** |

### 3.3 Regras trabalhistas (CLT)

| ID | Não conformidade | Evidência |
|---|---|---|
| **NC-20** | **Adicional noturno não implementado** (art. 73 — 22h-5h, +20%, hora reduzida de 52min30s) | Confirmado pela própria documentação: `PORTARIA_MTP_671_COMPLIANCE.md:306`, `FEATURE_HOURLY_RATE.md:302/316`. Existe apenas suporte a *turno* que cruza meia-noite |
| **NC-21** | **Intervalo intrajornada sem regra legal** (art. 71 — 1h-2h para >6h; 15min para 4-6h; indenização do §4º) | Zero ocorrências de "intrajornada". `validate_attendance_day()` (`helpers.php:3700-3762`) não valida nenhuma regra CLT. `break_minutes` é cadastro manual sem piso |
| **NC-22** | **Intervalo interjornada de 11h não validado** (art. 66) | Zero ocorrências de "interjornada". O único gap é `min_checkout_gap_seconds` (60s), criado por race condition |
| **NC-23** | **Tolerância do art. 58 §1º não conforme** | 5 min aplicados ao **agregado do mês** (`helpers.php:2045/2060`) e ao delta do dia; sem contagem por marcação nem teto de 10 min/dia |
| **NC-24** | **Limite de 2h extras/dia não existe** (art. 59) | `overtime_max_daily_hours='4'` é semeado e **nunca lido**. Controle de horas extras foi **descontinuado** (`public/admin/overtime.php` é um stub; 6 funções `@deprecated`) |
| **NC-25** | **Sem adicional de 100%** (domingo/feriado) | `calculate_overtime_payment()` (`helpers.php:4708`) tem multiplicador fixo 1.5 — e está deprecado |
| **NC-26** | **DSR não tratado** | Zero ocorrências de DSR/repouso semanal em código |
| **NC-27** | **Banco de horas sem prazo de compensação** | Nenhuma coluna de vencimento, nenhum job de fechamento; compensação só intramensal (`helpers.php:2028-2031`) |
| **NC-28** | **Folha não apura horas** | `generate_payslip` grava `worked=0, expected=0, overtime=0, deficit=0` desde `2026_06_16_payslip_no_overtime_no_deficit.sql`; `gross = net = base_salary`. Sem INSS/IRRF/FGTS/DSR |
| **NC-29** | **12x36 e trabalho intermitente não existem** | Schedules são estritamente por `weekday` com `UNIQUE(teacher_id, weekday)` — não há escala alternada |
| **NC-30** | **Sem ciência/anuência do trabalhador sobre correções** | Zero ocorrências de `ciente/reconhec/acknowledg/concord/aceite` em `my_timesheet.php`. O fluxo `approved` é o **admin aprovando o trabalhador**, não o inverso |

### 3.4 LGPD

| ID | Não conformidade | Evidência |
|---|---|---|
| **NC-31** | **Consentimento biométrico existe só em `localStorage`** | Modal em `index.php:4581-4690`; aceite gravado em `localStorage.setItem('lgpd-consent-given')` (`:5194`). **`lgpd_consent` tem zero referências no código PHP.** Limpar o navegador apaga a "prova". Sem data, sem titular, sem revogação |
| **NC-32** | **Sem consentimento para geolocalização** | Não tratado nem no código nem na política |
| **NC-33** | **Encarregado (DPO) não nomeado** | `LGPD_PRIVACY_POLICY.md:318-320` é placeholder `[Nome do DPO]`. Controlador/CNPJ/endereço idem (`:13-16`) |
| **NC-34** | **Dados biométricos em texto plano** | `teachers.face_descriptors` — JSON sem criptografia (`save_face.php:102`). Zero `openssl_encrypt`/`AES_ENCRYPT` no projeto |
| **NC-35** | **Sem rotina de anonimização/eliminação** | Zero ocorrências de `anonimiz/anonymiz`. A política promete em `:162` |
| **NC-36** | **Sem registro de operações de tratamento** (art. 37) | Não encontrado |
| **NC-37** | **Contradição de retenção: 90 dias × 5 anos** | `PHOTO_RETENTION_DAYS=90` (`config.php:132`) × "Fotos: 5 anos" (`LGPD_PRIVACY_POLICY.md:136`) |
| **NC-38** | **Transferência internacional negada, mas ocorrente** | `:310` afirma "não realizamos"; o código chama `worldtimeapi.org` (`index.php:5607`), `cdn.jsdelivr.net`, `fonts.googleapis.com`, `@mediapipe` de CDN (`:5238`) |
| **NC-39** | **"Backups diários criptografados" sem existência** | `:122-123`. Nenhum script de backup no repositório |
| **NC-40** | **Logs sem retenção** | Nada apaga `audit_logs`, `auth_attempt_logs`, `attendance_audit_log` — crescem indefinidamente, contra "5 anos" declarado |
| **NC-41** | **PIN em texto claro na fila offline** | `checkin_bulk.php:97/131` exige `$item['pin']` → o PIN fica persistido em IndexedDB até o drain. Contrasta com `index.php:6115-6122`, onde o descriptor facial *é* removido "nunca persiste em repouso (LGPD)" |

### 3.5 Segurança — média

| ID | Não conformidade | Evidência |
|---|---|---|
| **NC-42** | Rate limit do login admin é **por sessão PHP** | `admin/login.php:27` usa `$_SESSION['login_attempts']` — descartar o cookie zera o contador. Brute-force ilimitado. O login do colaborador faz certo (DB) |
| **NC-43** | Sem 2FA e sem política de senha para admin | Zero ocorrências de `2fa/totp/otp_secret` |
| **NC-44** | `ADMIN_SESSION_TIMEOUT` e `COLLABORATOR_SESSION_TIMEOUT` **nunca usados** | `config.php:90-91`. Cookie de sessão é de **30 dias**; remember-me do colaborador é de **10 anos sem expiração no banco** (`helpers.php:606-641`; a query de consumo `:652-658` não filtra data) |
| **NC-45** | `public/admin/clear_opcache.php` **sem autenticação** | 9 linhas, sem `require_once config.php` nem `require_admin()`. Único arquivo de `admin/` sem guard |
| **NC-46** | IDOR de escopo em comprovante e holerite | `get_receipt.php:61`, `payslip.php:44`, `receipt.php:55` checam `if ($isCollaborator && !$isAdmin)` — um `school_admin` lê de **toda a rede** iterando `?id=` |
| **NC-47** | Sem CSP fora do login admin; HSTS nunca emitido; HTTPS não forçado | CSP só em `admin/login.php:8`. HSTS condicionado a `$_SERVER['HTTPS']==='on'` (`config.php:62`) — atrás de proxy nunca dispara, apesar de a detecção via `X-Forwarded-Proto` existir 40 linhas acima para o cookie |
| **NC-48** | `has_permission()` fail-open | `helpers.php:1389/1392` |
| **NC-49** | Conexão MySQL sem `SET time_zone` | PHP em `America/Sao_Paulo`, mas `NOW()`/`CURRENT_TIMESTAMP` usam o TZ do servidor MySQL. Mistura de `DATETIME` naive e `TIMESTAMP` convertido na mesma tabela |
| **NC-50** | `receipt.php:22` usa `$_SESSION['admin_logged']`, chave que **nunca é definida** | Fail-closed, mas admin recebe 403 no comprovante |
| **NC-55** | **Endpoints críticos de autenticação nunca foram versionados** | `git status` mostra `api/pin_recover.php`, `api/pin_enroll.php`, `public/admin/clear_opcache.php`, `public/admin/employer_config.php`, `counting_config.php`, `kiosk_config.php` e `clear_attendances.php` como **untracked** — não estão no `.gitignore`, simplesmente nunca entraram no repositório. Os dois arquivos onde estavam NC-01 e NC-02 não têm histórico, revisão nem rastreabilidade de quem introduziu `$trustable = true`, e sumiriam num clone limpo. `employer_config.php` também é fonte de dados do futuro AFD |
| **NC-56** | **Proteções de segurança em diretórios ignorados pelo Git** | `.gitignore:2` (`public/photos/*`) faria o `.htaccess` de bloqueio das fotos **não ser versionado nem implantado** — a proteção existiria só na máquina onde foi criada. Corrigido com exceção explícita `!public/photos/.htaccess` |

### 3.6 Bugs de cálculo encontrados na auditoria

| ID | Bug | Evidência |
|---|---|---|
| **NC-51** | **Intervalo anulado continua contando como tempo trabalhado** | `helpers.php:1998` (`calculate_effective_worked_minutes`) filtra só `record_type='break'` — sem `approved`, sem `removed_at`, sem `superseded_by_id` |
| **NC-52** | **Registro aberto anulado ainda pode ser fechado pelo colaborador** | A busca do "aberto" não filtra anulação em `checkin.php:1136-1160`, `checkin_bulk.php:234`, `kiosk_checkin.php`, `attendance_manual.php:286`, `last_checkin.php` |
| **NC-53** | **Comprovante emitido para registro anulado** | `v_attendance_receipts` não filtra `removed_at`/`superseded_by_id`; `receipt.php:46` consome direto |
| **NC-54** | Trigger de auditoria (nos instaladores) usa `!=` em colunas nullable | Mudanças de/para NULL não disparariam o log. Irrelevante hoje (não há triggers em produção), relevante se instalado |

## 4. Riscos trabalhistas, jurídicos e técnicos

**Trabalhistas**
- **Invalidação do controle de jornada em juízo.** Sem AFD/AEJ, sem hash chain e com o registro original sobrescrito na edição, o ônus da prova se inverte contra o empregador (Súmula 338 do TST). O sistema não consegue provar que uma marcação não foi alterada.
- **Autuação em fiscalização do MTE.** Ausência de AFD é infração autônoma. Instituição pública sem AFD não tem como responder a uma requisição.
- **NC-14 é o risco mais grave em litígio**: um trabalhador (ou o empregador) pode injetar marcação retroativa de até 30 dias com um POST. Toda a base de marcações offline é contestável.
- **Passivo de adicional noturno** (NC-20) e de intervalo intrajornada suprimido (NC-21) não é medido — não aparece em nenhum relatório.
- **NC-30**: correções feitas unilateralmente pelo admin, sem ciência do trabalhador, são frágeis como prova.

**Jurídicos / LGPD**
- **Tratamento de dado biométrico sem base legal comprovável** (NC-31) — dado sensível (art. 11). O consentimento em `localStorage` não é prova oponível à ANPD.
- **NC-03 + NC-04**: exposição de biometria, CPFs e hashes de senha. Incidente notificável (art. 48). NC-04 é vazamento **já consumado** para quem tem acesso ao repositório.
- **NC-19**: declarar conformidade falsa no comprovante entregue ao trabalhador e em documento técnico agrava a responsabilidade. O `ATESTADO_TECNICO_MODELO.md` repete as mesmas afirmações não verificadas — assiná-lo hoje seria declaração falsa.
- Sem DPO (NC-33) e sem registro de tratamento (NC-36): descumprimento direto dos arts. 37 e 41.

**Técnicos**
- Zero proteção de banco (NC-11): acesso ao MySQL = alteração indetectável.
- `audit_log()` silencioso (`helpers.php:897-899`): a auditoria pode parar sem qualquer sinal.
- `has_permission()` fail-open (NC-48): o controle granular é decorativo.
- Cobertura de teste **zero** justamente nos pontos críticos: `pin_recover`, `pin_enroll`, login, CSRF, rate limiting, geofence, IDOR, `checkin_bulk`. Sem PHPUnit; 22 scripts manuais.
- Runner de migrations não entende `DELIMITER` (`helpers.php:159` faz `explode(';')`) — restringe como triggers podem ser criados.
- `.user.ini` fixa `max_execution_time=60` — geração de AFD anual precisa de streaming + rota CLI.

## 5. Ajustes obrigatórios (com prioridade)

### CRÍTICO — aplicar imediatamente
| # | Ajuste | NC |
|---|---|---|
| C1 | Restaurar a exigência de segundo fator em `pin_recover.php` (remover `$trustable = true`) e **nunca** criar sessão no fluxo de recuperação | NC-01 |
| C2 | Exigir prova real no primeiro acesso (`pin_enroll.php`) — reinstaurar `find_active_face_conflict` ou exigir liberação por admin | NC-02 |
| C3 | `Deny from all` em `public/photos/` + servir foto via script autenticado | NC-03 |
| C4 | Remover `sql/backups/*.sql` do histórico do Git; rotacionar todos os PINs e senhas expostos; `config.php` para `.gitignore` com `config.local.php` | NC-04 |
| C5 | Autenticar `public/admin/clear_opcache.php` | NC-45 |
| C6 | `PORTARIA_671_COMPLIANT = false` e remover a afirmação de conformidade de `receipt.php:523` até a conformidade existir | NC-19 |

### ALTO
| # | Ajuste | NC |
|---|---|---|
| A1 | Ledger append-only com **NSR por marcação** + UNIQUE + triggers de imutabilidade | NC-09, NC-10, NC-11 |
| A2 | Cadeia HMAC-SHA256 encadeada + selo diário Ed25519 ancorado externamente | NC-08 |
| A3 | Edição por **supersede** (anular original + inserir novo com NSR novo) | NC-12 |
| A4 | Gerador **AFD** | NC-05 |
| A5 | Gerador **AEJ** + espelho de ponto em leiaute legal | NC-06, NC-07 |
| A6 | HLB real via NTP server-side + âncora monotônica assinada; aposentar `hlbOffsetSeconds` do cliente como autoridade | NC-13, NC-14 |
| A7 | Consentimento LGPD gravado no servidor (`lgpd_consent`), com biometria e geolocalização separadas, versão do termo, data, IP e revogação | NC-31, NC-32 |
| A8 | Corrigir os bugs de cálculo NC-51, NC-52, NC-53 (independentes das demais fases) | NC-51..53 |
| A9 | Rate limit do login admin em banco (reusar `auth_attempt_log`) | NC-42 |
| A10 | Corrigir IDOR de escopo em comprovante/holerite | NC-46 |
| A11 | Criptografar `face_descriptors` em repouso | NC-34 |
| A12 | Comprovante com CPF do empregador, local de prestação e identificação do REP | NC-17 |

### MÉDIO
`has_permission()` fail-closed (NC-48) • CSP global + HSTS via `X-Forwarded-Proto` + HTTPS forçado (NC-47) • timeouts de sessão efetivos e expiração do remember-me (NC-44) • `SET time_zone` na conexão (NC-49) • retenção de fotos por prazo, não por volume (NC-37) • retenção de logs (NC-40) • `audit_log()` deixar de engolir exceções • adicional noturno (NC-20) • intervalo intrajornada e interjornada (NC-21, NC-22) • tolerância do art. 58 §1º (NC-23) • DPO, registro de tratamento e política sem placeholders (NC-33, NC-36) • ciência do trabalhador sobre correções (NC-30)

### BAIXO
Prazo do banco de horas (NC-27) • `recorded_at` DATETIME(3) (NC-18) • `receipt.php:22` chave de sessão inexistente (NC-50) • PIN fora do IndexedDB (NC-41) • remover CDNs externas (NC-38) • 12x36 e intermitente (NC-29) • folha com encargos (NC-28)

## 6. Melhorias recomendadas (não obrigatórias)

- PHPUnit + CI, com cobertura obrigatória em autenticação, integridade e cálculo de jornada.
- `bin/ledger_reconcile.php` — compara `attendance` × ledger e denuncia edição direta no banco. É o detector real de acesso via phpMyAdmin.
- Painel de integridade em `public/admin/ledger_integrity.php` com o resultado da verificação noturna.
- Alerta ao trabalhador (e-mail/push) a cada correção feita pelo admin no seu ponto.
- Backup automatizado, cifrado e testado, com restore documentado.
- Anonimização de colaborador desligado após o prazo legal.
- Consolidar a documentação: hoje há 80+ arquivos em `docs/` com afirmações contraditórias entre si.

## 7. Novas funcionalidades a implementar

1. **`nsr_ledger`** — livro fiscal append-only, uma linha por marcação, com NSR único, cadeia de hash e discriminador `event_type` (`mark`, `void`, `clock_adjust`, `employer_change`, `employee_change`, `chain_genesis`, `system_migration`).
2. **`nsr_ledger_seals`** — selo diário Ed25519 do head da cadeia, ancorado fora do sistema.
3. **`lib/afd.php` + `lib/aej.php`** — geradores em leiaute posicional, com spec declarativa.
4. **`lib/hlb.php` + `hlb_sync_log`** — cliente NTP em PHP puro, offset do servidor, registro de ajuste de relógio.
5. **Espelho de Ponto legal** (`public/admin/timesheet_mirror.php`).
6. **`public/verify_receipt.php`** — verificação pública do comprovante por NSR + hash.
7. **Consentimento LGPD server-side** — gravação em `lgpd_consent`, versão do termo, revogação, exportação.
8. **Cálculo de adicional noturno** e validação de intervalos (arts. 66, 71, 73).
9. **Fluxo de ciência do trabalhador** sobre correções de marcação.

---

# PARTE II — PLANO DE AÇÃO

## Fase 0 — Hotfix de segurança `[CRÍTICO]` — horas, sem migração, sem janela

> Falhas ativas em produção com CPFs e biometria reais. Sai antes de tudo.

- **0.1** `api/pin_recover.php` — remover `$trustable = true` (`:196`); restaurar a avaliação de `$deviceKnown`/`$geoKnown`/`$recentFailures`; **remover a criação de sessão e do cookie de 10 anos** do fluxo de recuperação (`:363`) — recuperar PIN não pode autenticar; exigir CSRF sempre.
- **0.2** `api/pin_enroll.php` — reinstaurar `find_active_face_conflict` no cadastro inicial (`:164-198`) ou exigir liberação por admin; nunca auto-logar (`:264`).
- **0.3** Criar `public/photos/.htaccess` com `Deny from all` + servir por script autenticado; migrar os links de `attendances.php:1174`.
- **0.4** `public/admin/clear_opcache.php` — adicionar `require_once config.php` + `require_admin()` + CSRF.
- **0.5** Purgar `sql/backups/*.sql` do histórico do Git (`git filter-repo`); mover `config.php` para `.gitignore` com `config.local.php` versionado como `.example`; **rotacionar todos os PINs e a senha de banco**; registrar o incidente LGPD.
- **0.6** `config.php:82` → `PORTARIA_671_COMPLIANT = false`; remover a afirmação de conformidade de `receipt.php:523`; marcar `docs/IMPLEMENTACAO_PORTARIA_671_COMPLETA.md` e `docs/PORTARIA_MTP_671_COMPLIANCE.md` como desatualizados.
- **0.7** Rate limit do login admin via `auth_attempt_log()` (`helpers.php:905`), como já faz o colaborador.
- **0.8** Corrigir IDOR de escopo: aplicar `admin_scope_where()` em `get_receipt.php:61`, `payslip.php:44`, `receipt.php:55`; corrigir `receipt.php:22` (`admin_logged` → `admin_id`).

## Fase 0b — Correções de baixo risco e fundação `[ALTO]` — dias

- **0b.1** Bugs de cálculo: `AND superseded_by_id IS NULL AND removed_at IS NULL` em `helpers.php:1998`, `:1882`, `:1907`, `:3536`, `_report_engine.php:82/193`; filtro de anulação na busca do "registro aberto" em `checkin.php:1136-1160`, `checkin_bulk.php:234`, `kiosk_checkin.php`, `attendance_manual.php:286`, `last_checkin.php`.
- **0b.2** Loader de `config.local.php` em `config.php` (antes da linha 150) — o arquivo está no `.gitignore` e é citado em `helpers.php:1223`, mas **nunca é incluído**. Base para todo segredo daqui pra frente.
- **0b.3** `bin/ledger_keygen.php` — gera `LEDGER_HMAC_KEY` e imprime o bloco pronto.
- **0b.4** `lib/hlb.php` + `hlb_sync_log` + `cron_hlb_sync.php` em **modo observação** (só mede e loga). **Validar em staging se Hostinger permite UDP/123** — se não, a cadeia de fallback (`HEAD https://ntp.br/` com correção de RTT) vira o caminho principal, com `hlb_source` registrado.
- **0b.5** Colunas novas para o AFD: `teachers.pis/admission_date/dismissal_date/matricula/cbo`; `employer_config.employer_type/cei_caepf_cno/rep_identifier/service_location/afd_layout_version`; `leave_types.aej_code`. Migrations portáveis MariaDB+MySQL (sem `IF NOT EXISTS` em coluna — o runner já tolera 1060 em `helpers.php:176`).
- **0b.6** Remover o trigger `attendance_before_insert_nsr` de `install_production_complete.sql:399` — vai brigar com o ledger se alguém reimportar.
- **0b.7** `audit_log()` (`helpers.php:897-899`) deixa de engolir exceções silenciosamente.

## Fase 0c — Dado administrativo `[ALTO]` — **caminho crítico do AFD**, semanas, externo

- **0c.1** Preencher **PIS/NIS de ~109 colaboradores** junto à SEMED. Sem isso o AFD tipo 3 é inemitível.
- **0c.2** Preencher `employer_config`: CNPJ (**hoje NULL em produção**), CEI/CAEPF, endereço, local de prestação, identificador do REP-P.
- **0c.3** Nomear o Encarregado (DPO) e preencher `docs/LGPD_PRIVACY_POLICY.md` (hoje todo em placeholder).

> Roda **em paralelo** às fases 1-3. É a dependência mais lenta do projeto.

## Fase 1 — Ledger append-only com NSR por marcação `[CRÍTICO p/ conformidade]` — janela ~15 min, **risco médio-alto**

Abordagem escolhida: **ledger paralelo**, não normalização de `attendance`. Normalizar exigiria reescrever 210 leituras em 65 arquivos, 4 máquinas de estado e 8 FKs (duas com UNIQUE) — migração destrutiva sem rollback. O ledger toca **os 14 sites de escrita e mais nada**. `nsr_ledger` vira o livro fiscal (fonte do AFD, AEJ e comprovante); `attendance` continua a tabela operacional.

- **1.1** Migration `sql/migrations/2026_08_05_nsr_ledger.sql` — tabela `nsr_ledger` (schema completo no desenho técnico: campos de marcação, proveniência, HLB, prova, ciclo de vida legal, cadeia). Chaves decisivas: `UNIQUE uq_ledger_nsr`, `UNIQUE uq_ledger_record_hash` e **`UNIQUE uq_ledger_prev_hash`** — esta última torna fisicamente impossível bifurcar a cadeia.
- **1.2** Triggers de imutabilidade `BEFORE UPDATE`/`BEFORE DELETE` com `SIGNAL SQLSTATE '45000'`. Cada `CREATE TRIGGER` é **um statement sem `;` interno** — passa pelo `explode(';')` do runner sem `DELIMITER`. Sem `;` e sem acento na `MESSAGE_TEXT`. Funciona porque o schema é INSERT-only de verdade: **`void` é uma linha nova, nunca um UPDATE**.
- **1.3** `nsr_sequence` ganha `last_record_hash`, `chain_key_id`, `chain_started_at`. O `FOR UPDATE` que já existe em 5 lugares passa a devolver NSR **e** `prev_hash` atomicamente — sem `ORDER BY nsr DESC LIMIT 1` (race + scan).
- **1.4** `lib/nsr_ledger.php`: `nsr_ledger_reserve/canonical/hash/append/void/verify/backfill/reconcile`. Payload canônico **versionado, delimitado por pipe** (não JSON — `json_encode` varia com ordem de chaves e versão do PHP), com floats em `sprintf('%.7f')`. Verificação dupla: recomputa o canônico das colunas **e** confere o HMAC.
- **1.5** `attendance.nsr_out` + `attendance.legacy_nsr`. `attendance.nsr` passa a espelhar o NSR da **entrada**; `nsr_out` o da **saída**. É o que mantém as 210 leituras, `v_attendance_receipts`, `receipt.php` e `get_receipt.php` funcionando **sem alteração**.
- **1.6** Instrumentar os **14 sites de escrita** (todos já dentro de `beginTransaction()` — atomicidade de graça): `checkin.php:1750/1775/2009`; `checkin_bulk.php:385/399/507`; `kiosk_checkin.php:291/302/397`; `attendance_manual.php:261/305/351`; `helpers.php:2575/2747/2838/2998` (regularizações) e `:3588/:3952/:3985` (edição/anulação).
- **1.7** **Feature flag de três estados** `app_settings('ledger_mode')` ∈ `off|shadow|enforced`. Em `shadow`, falha de append **nunca derruba o check-in** (loga e segue); em `enforced`, aborta a transação. **Rodar 2 semanas em `shadow` com verificação diária antes de virar.** Esta é a decisão que mais de-risca o plano inteiro — sem ela, um bug no append derruba o ponto de 109 pessoas na primeira manhã.
- **1.8** Pré-voo obrigatório do backfill: `SELECT nsr, COUNT(*) FROM attendance GROUP BY nsr HAVING COUNT(*)>1`; NSR nulo/zero; linhas sem `check_in` e sem `check_out`. Produção não tem UNIQUE em `nsr` e o retry de `checkin.php:1827` existe porque colisão **era possível** — se houver duplicata, resolver e documentar **antes**.
- **1.9** `bin/ledger_backfill.php` — **renumeração cronológica** (~4.300 marcações a partir de ~2.150 pares), ordenando por `COALESCE(check_in, check_out)`. NSR antigo preservado em `nsr_ledger.legacy_nsr` e `attendance.legacy_nsr`; `nsr_sequence.current_nsr = N`. Como N > 2.149, nenhum NSR novo colide com comprovante já emitido. `receipt.php` passa a resolver por `nsr` **ou** `legacy_nsr` e imprime "NSR anterior à migração: X". A migração é registrada como `event_type='system_migration'` com o digest do conjunto.
  - *Alternativa descartada*: reaproveitar o NSR original para a entrada e emitir novo para a saída — quebra a monotonia cronológica da cadeia (saída de abril com NSR 5000 antes de entrada de maio com NSR 2000). Um auditor nota.
- **1.10** `bin/ledger_verify.php` (cursor não-bufferizado, para na primeira divergência, checa buracos) + cron noturno gravando em `app_settings('ledger_last_verify')`.

## Fase 2 — Selo criptográfico e comprovante `[ALTO]` — sem janela, risco médio

- **2.1** `nsr_ledger_seals` + `bin/ledger_seal.php` (cron diário) — assina o head do dia com `sodium_crypto_sign_detached()` (**libsodium é core desde o PHP 7.2**, sem extensão exótica) e envia o `head_hash` por e-mail ao empregador. Resolve o furo do HMAC puro: com o head de ontem publicado fora do sistema, nem chave + banco comprometidos permitem retratá-lo.
- **2.2** `ledger_keys(key_id, fingerprint_sha256, created_at, retired_at)` — **fingerprint, nunca a chave**. Chave só em `config.local.php` ou env, **fail closed** (sem fallback para `app_settings`: chave no banco = quem tem o banco recalcula a cadeia).
- **2.3** `receipt.php:183` — trocar o selo falso pelo `record_hash` real (16 hex + QR) e corrigir a lista de campos do art. 80: CPF do empregador, local de prestação, identificação do REP (`rep_category` já é lido em `:143` e nunca renderizado).
- **2.4** `public/verify_receipt.php` — verificação pública por NSR + hash, com a chave pública Ed25519.
- **2.5** `v_attendance_receipts` estendida com `removed_at`/`superseded_by_id`; carimbo "REGISTRO ANULADO — substituído pelo NSR X" (corrige NC-53). *Reservar tempo para a saga de collation do MariaDB — essa view já custou 5 migrations ao projeto (`2026_05_25_*`).*

## Fase 3 — Edição por supersede `[ALTO]` — sem janela, risco médio

Converter o "finalize" de `admin_save_attendance_day` (`helpers.php:4020-4040`) de UPDATE in-place para supersede, reusando os dois padrões que já existem na mesma função: `$softDelete` (`:3949-3963`) e `$insert` (`:3981-3997`).

- **3.1** Para cada `$wEdit`/`$bEdit`: `$softDelete($cur, $kind, $novoId)` + `$insert($op, $kind, $parent, $carryFrom)`.
- **3.2** **`$wKeep` NÃO vira supersede** — aprovar ou gravar `editado_por` é metadado, não marcação, e não deve consumir NSR. UPDATE in-place restrito a `approved, method, editado_por, data_edicao, motivo_edicao` — **nunca** `check_in`/`check_out`/`date`. Corolário: se `$dateChanged` (`:3926`), todo `$wKeep` é **promovido a `$wEdit`**.
- **3.3** **`$carryFrom` — o custo escondido maior desta fase.** O `$insert` atual não copia `photo`, `check_in_lat/lng/acc`, `check_out_lat/lng/acc`, `ip`, `user_agent`, `device_identifier`, `device_fingerprint`, `record_mode`, `recorded_at`, `client_recorded_at`, `class_period_id`, `liveness_score`, `liveness_data`. Hoje tudo bem (só insere períodos novos); no supersede, o novo registro **perderia a foto e o GPS do original** — regressão de prova, quebra o comprovante e afeta a contagem de referências do `cron_photo_cleanup.php`. Decidir campo a campo o que é "prova do original" (copia) e o que é "circunstância da edição" (não copia).
- **3.4** FKs: repontar `hour_bank_entries.ref_attendance_id` e `overtime_requests.attendance_id`. **Não repontar** `attendance_break_requests.attendance_id` e `attendance_checkout_requests.attendance_id` — ambas têm **UNIQUE** e dariam 1062; além disso, o pedido de regularização ficou historicamente ligado ao registro que o originou, que é a leitura correta para auditoria. As telas `break_regularizations.php` e `checkout_regularizations.php` passam a seguir `superseded_by_id` para exibir o vigente.
- **3.5** Guarda em `admin_restore_attendance` (`helpers.php:3644-3651`, que hoje limpa `superseded_by_id` incondicionalmente): recusar restauração de registro **substituído** cujo sucessor está vivo — duplicaria o período.
- **3.6** Terceiro rótulo em `attendances.php:899`: hoje mostra "Duplicata de #X" para qualquer `superseded_by_id`; falta "Substituído por #X" para `removed_at IS NOT NULL AND superseded_by_id <> id`. Varredura dedicada nas 12 queries de `dashboard.php`.
- **3.7** Estender `tests/test_attendance_day_edit.php` e `tests/test_admin_removals.php`: o original sobrevive com `removed_at`, e o `worked_minutes` **não dobra**.

## Fase 4 — Gerador AFD `[ALTO]` — depende de 1 e 0c

> **Nenhum trecho deste plano substitui a transcrição do Anexo oficial da Portaria 671.** Errar uma coluna de posição gera arquivo que passa em teste interno e é rejeitado pela fiscalização — o pior modo de falha. Por isso a arquitetura torna os offsets **dado declarativo**.

- **4.1** `lib/afd.php` com `afd_spec()` declarativa (`['name','len','kind','source']` por tipo), `afd_pad_num/alpha`, `afd_line()` **validando a largura total e falhando alto**, `afd_generate()` como `Generator`.
- **4.2** Transcrever o Anexo oficial para a spec e **validar num validador público de AFD** antes de qualquer afirmação de conformidade.
- **4.3** Eventos que hoje não existem, a emitir no ledger: tipo 2 (`employer_change`, de `employer_config.php`), tipo 4 (`clock_adjust`, da Fase 6), tipo 5 (`employee_change`, de `teachers_save.php` e `admin_set_collaborator_active()` em `helpers.php:4097`).
- **4.4** `public/admin/export_afd.php` (streaming, `ob_end_flush()`+`flush()` por linha) **e** `bin/export_afd.php` (CLI com `set_time_limit(0)` — obrigatório porque `.user.ini` fixa `max_execution_time=60`).
- **4.5** Nota: **ACJEF é da Portaria 1510/2009, substituído pelo AEJ na 671** — não implementar salvo exigência específica.

## Fase 5 — AEJ e espelho de ponto legal `[ALTO]`

- **5.1** `lib/aej.php` — reusa `calculate_expected_minutes()`, `calculate_effective_worked_minutes()`, `leaves`+`leave_types.aej_code`, `hour_bank_entries`.
- **5.2** **Conflito de política a decidir antes**: o AEJ exige a apuração previsto × realizado × ocorrências × compensações, mas a política atual é jornada flexível **sem saldo negativo** (`recompute_day_hour_bank()` deliberadamente não grava débito — `helpers.php:3548`). O AEJ vai expor a diferença que a política optou por não registrar. Não é bug; é escolha que precisa ser consciente e documentada.
- **5.3** `public/admin/timesheet_mirror.php` — espelho legal em PDF (Dompdf, reusando `_tpl_*_pdf.php`), com NSR por marcação.

## Fase 6 — HLB estrita `[ALTO]` — risco médio-alto (impacto no usuário)

- **6.1** Ativar `hlb_now()` como fonte de `marked_at`, aplicando o offset **em software** (não dá para ajustar o relógio do SO em hospedagem compartilhada) e gravando `hlb_offset_ms` + `hlb_source` na marcação — auditável e defensável.
- **6.2** **Âncora monotônica assinada pelo servidor**, substituindo o raciocínio circular de `checkin.php:1088`: `api/get_server_time.php` passa a devolver `token = base64(payload).hmac(payload)` com `{server_time_ms, device_id, expires_at}`; o PWA guarda o token **e** o `performance.now()` da emissão (`index.php:5598` `syncWithHLB()`); ao registrar offline envia `token` + `elapsed_ms`; o servidor aceita `claimed = token.server_time + elapsed_ms` se dentro da validade e não-futuro.
  - *Ressalva a medir antes de virar a chave*: em mobile o `performance.now()` pode congelar durante suspensão do app, enviesando o `claimed` para **antes** do real — conservador para o empregado, mas precisa de teste em Android e iOS.
- **6.3** Sem token válido: grava a hora de recebimento no servidor, `hlb_status='failed'`, `approved=NULL`, `pending_reasons += "horário não comprovado"`. **A marcação existe** (art. 74 da CLT — o registro não pode ser perdido), a incerteza é explícita, e o ajuste do admin vira nova marcação com NSR próprio.
- **6.4** `hlbOffsetSeconds` continua sendo recebido e gravado, mas **rebaixado a campo de auditoria** — sai da decisão em `checkin.php:1088`, `:1640` e `checkin_bulk.php:213`.
- **6.5** Remover `worldtimeapi.org` (`index.php:5607`) — resolve NC-38 e a dependência externa.

## Fase 7 — LGPD `[ALTO/MÉDIO]`

- **7.1** Gravar consentimento em `lgpd_consent` (a tabela existe e **nunca foi usada**): titular, finalidade (biometria / geolocalização **separadas**), versão do termo, data, IP, revogação. Substituir o `localStorage` de `index.php:5194`.
- **7.2** Criptografar `face_descriptors` em repouso (chave em `config.local.php`, como o ledger).
- **7.3** Retenção **por prazo**, não por volume: corrigir o gate de 500MB em `helpers.php:4249-4257`; alinhar `PHOTO_RETENTION_DAYS` com a política escrita (hoje 90 dias × "5 anos" declarados) — decidir o número e fazer os dois baterem.
- **7.4** Retenção e rotação de `audit_logs`, `auth_attempt_logs`, `attendance_audit_log` (respeitando os 5 anos legais).
- **7.5** Rotina de anonimização de colaborador desligado; endpoint de revogação; registro de operações de tratamento (art. 37).
- **7.6** Tirar o PIN do IndexedDB (`checkin_bulk.php:97`) — usar token de dispositivo, como já se faz com o descriptor facial em `index.php:6115-6122`.

## Fase 8 — Regras CLT `[MÉDIO]`

- **8.1** Adicional noturno: minutos na janela 22h-5h, hora reduzida de 52min30s, +20%; coluna `night_bonus` (já prevista como TODO em `FEATURE_HOURLY_RATE.md:316`).
- **8.2** Validação de intervalo intrajornada (art. 71) em `validate_attendance_day()` (`helpers.php:3700`) + apuração do §4º.
- **8.3** Validação de interjornada de 11h (art. 66) no check-in.
- **8.4** Tolerância do art. 58 §1º: 5 min **por marcação**, teto de 10 min/dia, extrapolação computada integralmente — substituindo a tolerância agregada de `helpers.php:2045/2060`.
- **8.5** Reavaliar horas extras (hoje descontinuadas): limite de 2h/dia, adicional de 100% em domingo/feriado, prazo do banco de horas.
- **8.6** Fluxo de ciência do trabalhador sobre correções, em `my_timesheet.php`.

## Fase 9 — Segurança e documentação `[MÉDIO]`

- **9.1** `has_permission()` **fail-closed** (`helpers.php:1389/1392`) + papéis de gestor e RH + seed da tabela `permissions`.
- **9.2** CSP global (hoje só em `admin/login.php:8`); HSTS via `X-Forwarded-Proto` (a detecção já existe em `config.php:20-22` para o cookie — basta reusar na linha 62); redirect 301 http→https.
- **9.3** Aplicar `ADMIN_SESSION_TIMEOUT`/`COLLABORATOR_SESSION_TIMEOUT` (hoje definidos e **nunca usados**); expiração e rotação do remember-me (hoje 10 anos sem coluna de validade).
- **9.4** `SET time_zone` na conexão PDO (`config.php:145`).
- **9.5** 2FA para admin; política de senha.
- **9.6** PHPUnit + CI cobrindo autenticação, integridade e cálculo de jornada — hoje a cobertura é **zero** justamente nos pontos críticos.
- **9.7** Reescrever `docs/PORTARIA_MTP_671_COMPLIANCE.md` e `docs/ATESTADO_TECNICO_MODELO.md` com o estado real; só então assinar o Atestado Técnico do Anexo VII.

---

## Dependências e riscos

| Fase | Bloqueia | Bloqueada por | Migração | Janela | Risco |
|---|---|---|---|---|---|
| 0 | — | — | não | não | Baixo |
| 0b | 1, 4, 6 | — | colunas | não | Baixo |
| 0c | 4, 5 | — | manual | não | Baixo (**caminho crítico**) |
| 1 | 2, 3, 4, 5 | 0b | **~4.300 marcações** | **~15 min** | **Médio-alto** |
| 2 | — | 1 | não | não | Médio (view/collation) |
| 3 | — | 1 | não | não | Médio |
| 4 | — | 1, 0c | não | não | Médio |
| 5 | — | 1, 0c | não | não | Médio |
| 6 | — | 0b | não | não | **Médio-alto** (usuário) |
| 7 | — | 0b | não | não | Médio |
| 8, 9 | — | — | não | não | Baixo/Médio |

**Riscos sem mitigação limpa:**
- **Colisões históricas de NSR** — `attendance.nsr` sem UNIQUE em produção e o retry de `checkin.php:1827` existe porque colisão era possível. Se o pré-voo achar duplicata, o backfill trava, e o problema é anterior a este plano.
- **`nsr_ledger_append()` deve propagar exceção, sempre** — ao contrário de `audit_log()`. Em modo `shadow` o try/catch fica **no site de chamada**, nunca dentro da função. A distinção importa.
- **MariaDB (prod) × MySQL (dev local)** — toda migration nova roda nos dois antes de subir.
- **Autoridade do leiaute AFD/AEJ** — validador público antes de qualquer declaração de conformidade.

---

## Verificação

**Fase 0** (por item, antes de fechar):
```bash
curl -s -X POST http://localhost/ponto_oeiras/api/pin_recover.php -H "Content-Type: application/json" -d '{"cpf":"<CPF_DE_TESTE>"}' -i
```
Esperado: erro exigindo segundo fator; **nenhum** `Set-Cookie` de sessão; nenhum PIN no corpo. Idem para `pin_enroll.php` com 128 floats aleatórios. `curl -I http://localhost/ponto_oeiras/public/photos/<arquivo>.jpg` deve dar 403. `curl -I .../public/admin/clear_opcache.php` deve redirecionar para o login. `git log --all --full-history -- sql/backups/` deve voltar vazio após o purge.

**Fase 0b/1** — testes existentes primeiro, depois os novos:
```bash
php tests/test_attendance_day_edit.php && php tests/test_admin_removals.php && php tests/test_consolidate_attendance.php && php tests/test_break_no_double_deduction.php && php tests/test_overnight_integration.php
```
Depois: `php bin/ledger_verify.php --json` (cadeia íntegra, zero buracos) e `php bin/ledger_reconcile.php --from=<data> --to=<data>` (zero drift entre `attendance` e ledger). Em `shadow`, rodar os dois **diariamente por 2 semanas** antes de virar para `enforced`.

**Teste de imutabilidade** (o que prova NC-11 resolvido):
```sql
UPDATE nsr_ledger SET marked_at = '2020-01-01 00:00:00' WHERE nsr = 1;
```
Deve falhar com SQLSTATE 45000. Idem `DELETE`.

**Fase 2** — emitir comprovante, conferir que o hash exibido bate com `nsr_ledger.record_hash`; alterar um campo direto no banco e confirmar que `verify_receipt.php` **passa a acusar** (o selo atual não acusaria); emitir comprovante de registro anulado e confirmar o carimbo.

**Fase 3** — editar um dia pela UI e verificar: original com `removed_at IS NOT NULL` e `superseded_by_id` = id do novo; novo com NSR próprio; `calculate_effective_worked_minutes()` **não dobrado**; foto e GPS preservados no sucessor.

**Fase 4/5** — gerar AFD e AEJ de um mês fechado, conferir largura de linha e contadores do trailer, e **submeter a um validador público de AFD** antes de declarar conformidade.

**Fase 6** — em staging: alterar o relógio do aparelho para 3h atrás, registrar offline, sincronizar, e confirmar que `marked_at` **não** aceitou o horário forjado. Medir `performance.now()` após suspensão do app em Android e iOS.

**Regressão geral** — todos os 22 scripts de `tests/`, mais a suíte nova de autenticação/integridade da Fase 9.6.

---

# Estado da execução

## Fase 0 — aplicada em 2026-08-05

| Item | NC | Arquivo | Verificação |
|---|---|---|---|
| Segundo fator restaurado na recuperação de PIN | NC-01 | `api/pin_recover.php` | POST só com CPF → `require_face`; sem PIN e sem sessão no corpo |
| Auto-login removido da recuperação | NC-01 | `api/pin_recover.php` | resposta legítima agora traz `auto_login:false` |
| `pin_recovery_allow_face_enroll` default 1 → 0 | NC-01 | `api/pin_recover.php` | sem face + dispositivo desconhecido → direciona ao admin |
| Primeiro acesso exige prova real de identidade | NC-02 | `api/pin_enroll.php` | descriptor forjado → `blocked_contact_admin`; descriptor real → PIN criado |
| Fotos servidas sob autenticação e escopo | NC-03 | `public/photo_view.php`, `public/photos/.htaccess`, `public/admin/attendances.php` | anônimo → 403; acesso por `attendance.id`, não por nome de arquivo |
| `.htaccess` de fotos passa a ser versionado | NC-56 | `.gitignore` | `git check-ignore` deixa de casar |
| `clear_opcache.php` autenticado | NC-45 | `public/admin/clear_opcache.php` | anônimo → 302 para o login; exige network_admin + POST + CSRF |
| Rate limit do login admin persistido em banco | NC-42 | `public/admin/login.php` | 7 tentativas com sessão nova a cada vez → bloqueia na 5ª |
| Escopo de admin aplicado a comprovante e holerite | NC-46 | `public/receipt.php`, `api/get_receipt.php`, `public/payslip.php` | `school_admin` fora do escopo → 403 |
| Chave de sessão inexistente corrigida | NC-50 | `public/receipt.php` | `admin_logged` → `admin_id` |
| Flags falsas de conformidade | NC-19 | `config.php`, `public/receipt.php` | `PORTARIA_671_COMPLIANT`/`LGPD_COMPLIANT` → `false`; texto do comprovante corrigido |

Regressão: suíte `tests/` completa — **21 arquivos, zero falhas**.

## Pendente de decisão do responsável

- **NC-04 — purga de segredos do histórico do Git.** `sql/backups/u803039033_pto_ribeira.sql` está versionado com CPFs, 214 hashes bcrypt e templates biométricos reais. Exige `git filter-repo` (reescreve histórico compartilhado), rotação de PINs e da senha do banco, e registro do incidente perante a LGPD. Não executado por reescrever histórico já distribuído.
- **CSRF no fluxo anônimo de recuperação de PIN.** Hoje `api/pin_recover.php:66` só exige CSRF quando há sessão, e o front (`public/index.php:11152`) só envia token quando logado. Exigir sempre requer emitir token para visitante anônimo. Com o segundo fator restaurado o risco caiu bastante, mas o item continua aberto.
- **NC-55 — arquivos críticos fora do controle de versão.** Precisa de decisão sobre incorporá-los ao repositório (recomendado) e sobre como auditar o que mais existe apenas no servidor de produção.

## Fase 0b — aplicada em 2026-08-05

| Item | NC | Arquivo | Verificação |
|---|---|---|---|
| Fragmento único `attendance_vigente_sql()` para excluir anulados/substituídos | NC-51/52 | `helpers.php` | teste dedicado abaixo |
| Filtro aplicado nos cálculos de jornada | NC-51 | `helpers.php` (`calculate_worked_minutes`, `calculate_break_minutes`, `calculate_effective_worked_minutes`, `recompute_day_hour_bank`), `_report_engine.php` (2 queries) | intervalo anulado de 90 min deixou de somar |
| Filtro aplicado na busca do "registro aberto" | NC-52 | `api/checkin.php`, `api/checkin_bulk.php`, `api/kiosk_checkin.php`, `api/kiosk_lib.php`, `public/admin/attendance_manual.php` (3 pontos), `helpers.php` (3 pontos), `public/admin/dashboard.php` (4 pontos) | registro aberto anulado deixou de ser fechável |
| Teste de regressão dedicado | NC-51/52 | `tests/test_annulled_records_excluded.php` | 10 asserções; **validado neutralizando a correção** — o teste cai para 4/10 e acusa 300→390 min |
| `audit_log()` deixa de engolir exceções | — | `helpers.php` | falha vai para `error_log` + `app_settings('audit_log_last_failure')`; segue sem interromper o fluxo |
| `auth_attempt_is_limited()` registra fail-open | — | `helpers.php` | rate limit inoperante deixa de ser silencioso |
| Trigger de NSR removido de **3** instaladores | NC-11 | `install_production_complete.sql`, `install_portaria_671.sql`, `install_production_upgrade.sql` | o de `install_portaria_671.sql` não tinha a guarda `IF NEW.nsr IS NULL` e sobrescreveria o NSR do PHP, consumindo dois números por marcação |
| Loader de `config.local.php` | — | `config.php`, `config.local.php.example` | o arquivo já era citado por `kiosk_signing_secret()` e estava no `.gitignore`, mas **nunca era carregado** |
| Gerador da chave do ledger | — | `bin/ledger_keygen.php` | gera 256 bits + fingerprint; não grava em disco nem no banco |
| Colunas de cadastro para AFD/AEJ | NC-05/06 | `sql/migrations/2026_08_05_afd_aej_cadastro.sql` | aplicada pelo runner automático, sem falha; `teachers.pis`, `employer_config.rep_identifier`/`service_location`/`cpf`, `leave_types.aej_code` etc. |
| HLB medida pelo servidor (modo observação) | NC-13 | `lib/hlb.php`, `sql/migrations/2026_08_05_hlb_sync_log.sql`, `cron_hlb_sync.php`, `lib/.htaccess` | medição real contra `a.st1.ntp.br` (**stratum 1**): offset −686 ms, rtt 169 ms |

Regressão: suíte `tests/` completa — **22 arquivos, zero falhas**.

### Observações da Fase 0b

- **NTP funciona neste ambiente.** O cliente NTP em PHP puro (`stream_socket_client` + `unpack`, sem extensão) obteve resposta de stratum 1 do NTP.br. Isso reduz bastante o risco da Fase 6, que dependia dessa incógnita — mas **precisa ser reconfirmado no servidor de produção** (hospedagem compartilhada costuma bloquear UDP/123).
- **O fallback HTTP não respondeu** no ambiente local. Ele existe para o caso de UDP bloqueado; se em produção o NTP falhar e o fallback também, o sistema registra `hlb_status = failed` — o que é o comportamento correto, mas convém testar antes de depender dele.
- **Nada do HLB está no caminho de gravação de ponto.** `hlb_now()` existe e está correta (validada: servidor 686 ms atrasado → HLB adiantada na mesma medida), mas nenhuma marcação a utiliza ainda. Isso é deliberado e é a Fase 6.
- **`set_setting()` não invalida o cache estático de `get_setting()`** (limitação pré-existente do projeto). Por isso `hlb_sync()` devolve os valores frescos no próprio retorno, e o cron usa o retorno em vez de `hlb_status()`.

## Fase 1 — livro fiscal append-only (aplicada em 2026-08-05, modo `off`)

| Item | NC | Arquivo | Verificação |
|---|---|---|---|
| Tabela `nsr_ledger` — uma linha por marcação, cadeia HMAC-SHA256 | NC-08/09 | `sql/migrations/2026_08_05_nsr_ledger.sql` | 8.643 eventos, cadeia íntegra |
| Triggers de imutabilidade **no banco** | NC-11 | `..._nsr_ledger_triggers.sql` | UPDATE e DELETE bloqueados com SQLSTATE 45000 |
| Contador exclusivo do livro | — | `..._nsr_ledger_own_counter.sql` | ver "defeito encontrado" abaixo |
| API do livro | — | `lib/nsr_ledger.php` | `tests/test_nsr_ledger.php` — 32 asserções |
| Espelhos `attendance.nsr` / `nsr_out` / `legacy_nsr` | NC-09 | migration + endpoints | entrada e saída com NSR distintos |
| Backfill com renumeração cronológica | — | `bin/ledger_backfill.php` | 4.325 linhas → 8.639 marcações em ~10 s |
| Verificador da cadeia + reconciliação | — | `bin/ledger_verify.php` | íntegra; zero drift vs `attendance` |
| Instrumentação dos endpoints | NC-09 | `checkin.php`, `checkin_bulk.php`, `kiosk_checkin.php` | 3 pontos cada |
| Anulação vira evento `void` | NC-12 | `helpers.php` (`admin_remove_attendance`) | original permanece intacto |
| Feature flag `off / shadow / enforced` | — | `app_settings('ledger_mode')` | `tests/test_ledger_shadow_mode.php` — 18 asserções |

Regressão: suíte completa — **24 arquivos, zero falhas**.

### Defeitos encontrados pelos próprios testes (e corrigidos)

1. **Payload assinado divergia das colunas gravadas.** O canônico usava
   `?? ''` e o INSERT `?? 'online'` para `record_mode`/`origin`. Qualquer
   chamador que omitisse o campo gravava um registro com assinatura que **nunca
   mais fecharia** — e só um verificador rodando meses depois revelaria.
   Corrigido com `nsr_ledger_normalize()`: canônico e INSERT derivam do mesmo
   array normalizado.

2. **Livro compartilhava o contador de NSR com o emissor legado.** Todo caminho
   de escrita ainda não convertido (ponto manual, edição de dia, regularizações)
   consumia números da sequência sem gerar linha no livro, abrindo **buracos
   permanentes na numeração fiscal** — que para um auditor significam registro
   emitido e ausente. Corrigido dando ao livro um contador exclusivo
   (`nsr_sequence.ledger_current_nsr`).

3. **`set_setting()` não invalidava o cache de `get_setting()`.** Gravar e ler a
   mesma configuração na mesma requisição devolvia o valor antigo. Passava
   despercebido no fluxo admin (que redireciona após salvar), mas fazia
   `ledger_mode` parecer não mudar ao ser alternado. Corrigido com invalidação
   de cache — beneficia todo o sistema, não só o livro.

### Estado e próximos passos

O livro está **implantado e populado, com `ledger_mode = off`** — nada é gravado
em produção até a decisão de ligar. A sequência recomendada:

1. `ledger_mode = shadow` e rodar `bin/ledger_verify.php` diariamente por ~2 semanas;
2. `bin/ledger_verify.php --reconcile` semanal, para detectar edição direta no banco;
3. só então `ledger_mode = enforced`.

Em produção o backfill deve rodar **depois** de `--dry-run` e com backup — o
livro é imutável e um backfill duplicado não teria como ser desfeito (o script
recusa rodar sobre livro não-vazio).

### Caminhos administrativos — instrumentados em 2026-08-06

Concluída a cobertura dos fluxos em que a GESTÃO cria ou anula marcações. São os
que mais pesam numa fiscalização, porque não nasceram de registro espontâneo do
trabalhador: no livro, todos carregam `origin` identificando o fluxo, o
`admin_id` responsável e o motivo.

| Fluxo | Arquivo | Eventos no livro |
|---|---|---|
| Entrada manual | `attendance_manual.php` | 1 marcação `admin_manual` |
| Saída manual (fecha registro aberto) | `attendance_manual.php` | 1 marcação `admin_manual` + espelho `nsr_out` |
| Entrada+saída de uma vez | `attendance_manual.php` | 2 marcações com NSRs próprios |
| Período criado na edição de dia | `helpers.php` (`admin_save_attendance_day`) | 2 marcações `admin_edit` |
| Anulação na edição de dia | `helpers.php` (`$softDelete`) | 1 evento `void` por marcação |
| Anulação administrativa | `helpers.php` (`admin_remove_attendance`) | 1 evento `void` por marcação |
| Regularização de saída aprovada | `helpers.php` | 1 marcação `regularization` |
| Retorno de intervalo pelo colaborador | `helpers.php` (`close_own_open_break`) | 1 marcação `regularization`, sem `admin_id` |

Os cinco blocos legados de emissão de NSR em `attendance_manual.php` e nos
endpoints foram unificados em `nsr_legacy_reserve()`.

Cobertura: `tests/test_ledger_admin_paths.php` (14 asserções) exercita a edição
de dia e a anulação de ponta a ponta, confirmando que a marcação original
permanece intacta e que o `void` referencia o NSR alvo.

Regressão: suíte completa — **25 arquivos, zero falhas**. Cadeia com 8.653
eventos, íntegra e sem buracos.

### Ainda pendente antes de `enforced`

- Rodar em `shadow` por ~2 semanas com `bin/ledger_verify.php` diário.
- Backfill em produção (com `--dry-run` e backup antes).
- Preencher PIS/CNPJ (Fase 0c) — sem isso o AFD segue inemitível.

## Fase 2 — selo criptográfico e comprovante (aplicada em 2026-08-06)

| Item | NC | Arquivo | Verificação |
|---|---|---|---|
| Selo diário Ed25519 do head da cadeia | NC-08 | `sql/migrations/2026_08_06_ledger_seals.sql`, `lib/ledger_seal.php`, `bin/ledger_seal.php` | selo emitido e auditado |
| Keyring com fingerprint (nunca a chave) | — | `ledger_keys` | tabela não tem coluna para chave privada |
| Ancoragem externa append-only | — | `nsr_ledger_seal_anchors` | ancoragem sem referência é recusada |
| Selo real no comprovante | NC-19 | `public/receipt.php` | vem de `nsr_ledger.record_hash` |
| Campos do art. 80 | NC-17 | `public/receipt.php`, view | local da prestação, identificação do REP, CNPJ **ou** CPF do empregador, CEI/CAEPF |
| Carimbo de registro anulado | NC-53 | `public/receipt.php`, view | comprovante de anulado sai marcado em vermelho |
| Verificação pública | — | `public/verify_receipt.php` | 5 cenários testados por HTTP |
| View com estado de anulação e NSR de saída | NC-53/09 | `sql/migrations/2026_08_06_v_attendance_receipts_ledger.sql` | recriada sem corromper no MariaDB |

Regressão: suíte completa — **26 arquivos, zero falhas**. `tests/test_ledger_seal.php`
adiciona 23 asserções, incluindo a detecção de reescrita do passado.

### Por que o selo diário existe

A cadeia HMAC da Fase 1 detecta adulteração feita por quem **não tem a chave**.
Quem comprometer servidor e chave ao mesmo tempo pode reescrever um trecho do
passado e recalcular todos os elos: a cadeia voltaria a fechar e a verificação
não acusaria nada. É a limitação intrínseca de qualquer esquema simétrico.

O selo fecha essa brecha ancorando o estado do livro **fora** do sistema: o head
de cada dia é assinado com Ed25519 e publicado ao empregador. A partir daí,
reescrever o passado exige também alterar a cópia externa — que não está sob
controle de quem invadiu.

O teste `[4]` de `test_ledger_seal.php` cobre exatamente o caso decisivo: um
selo com **assinatura perfeitamente válida** cujo head já não corresponde ao
livro é reprovado. É esse o sintoma de reescrita, e verificar só a assinatura
não o pegaria.

### Defeitos encontrados durante a Fase 2

1. **O trigger de imutabilidade do selo tornava a ancoragem impossível.** O
   desenho inicial previa registrar a publicação externa por `UPDATE` nas
   colunas `anchored_at`/`anchor_ref` — mas o `BEFORE UPDATE` bloqueava tudo.
   Um selo que aceita UPDATE não é selo. Resolvido movendo a ancoragem para
   `nsr_ledger_seal_anchors`, append-only, o que ainda permite mais de uma
   ancoragem por dia (e-mail e ata, por exemplo).

2. **A verificação pública reprovava marcações íntegras.** O `SELECT` fora
   restringido a poucas colunas por privacidade, mas `nsr_ledger_verify_row()`
   recomputa o payload canônico a partir das colunas — com o SELECT parcial, o
   canônico nunca batia. A proteção de dados desta página está no que é
   **renderizado**, não no que é consultado; corrigido para `SELECT *`, com
   auditoria confirmando que nenhum dado pessoal chega ao HTML.

3. **Nenhuma view existia no banco local.** O dump restaurado trouxe
   `applied_migrations` sem trazer as views, então o runner considerava as
   migrations aplicadas e as pulava — e `receipt.php` estava quebrado
   localmente sem que nada acusasse. Vale como alerta operacional: em restauro
   parcial, `applied_migrations` pode divergir do schema real.

### Estado da verificação pública

`public/verify_receipt.php` é anônima de propósito — exigir login inutilizaria
o caso de uso, que é um fiscal ou sindicato conferindo a via impressa. Em
troca, não expõe nome, CPF, horário, GPS nem foto: responde apenas se o NSR
existe, se a assinatura fecha, se está anulado e se o dia está selado. Isso
mantém o NSR (sequencial e portanto enumerável) inútil como vetor de extração
de dados. Há rate limit de 120 consultas / 5 min por IP.

### Pendências

- Publicar o head diário fora do sistema é **ato operacional**, não automático:
  o cron imprime o head e o comando de ancoragem, mas alguém precisa enviá-lo
  ao empregador e registrar a referência. Sem isso o selo perde boa parte do
  valor.
- Em instalação madura, a chave privada de selo deveria viver em máquina
  separada, com apenas a pública no servidor de produção.
- `employer_config` é lida por `CROSS JOIN` na view: se a tabela ficar vazia,
  nenhum comprovante é emitido. Herdado da definição original, registrado como
  risco.

## Fase 3 — edição por supersede (aplicada em 2026-08-06)

Resolve **NC-12**: editar um horário fazia `UPDATE attendance SET check_in = ...`
sobre a linha original. O registro legal com aquele NSR era sobrescrito e o
valor anterior sobrevivia apenas em `attendance_edits.before_json` — perdido o
log, o original desaparecia.

Agora toda edição de horário ou data é **supersede**: insere um registro novo
(com NSR próprio) e anula o original apontando para o sucessor. Nenhuma linha de
marcação é reescrita. É o padrão que o código já usava para adições, agora
aplicado também aos ajustes.

| Item | Arquivo | Verificação |
|---|---|---|
| Supersede das edições | `helpers.php` (`admin_save_attendance_day`) | original sobrevive com horários originais |
| Preservação de prova (`$carryFrom`) | idem | foto, GPS, IP, user agent, device e carimbo do servidor herdados |
| `$wKeep` restrito a metadados | idem | UPDATE não toca `check_in`/`check_out`/`date` |
| Repontar FKs seguras | idem | `hour_bank_entries`, `overtime_requests` |
| Resolução de id substituído | idem (`$resolverSucessor`) | chamador com id antigo é remapeado ao vigente |
| Guarda na restauração | `helpers.php` (`admin_restore_attendance`) | restaurar substituído com sucessor vivo é recusado |
| Rótulo "Substituído por #X" | `public/admin/attendances.php` | distinto de "Removido" e de "Duplicata" |
| Bucket `superseded` no retorno | `helpers.php` | separado de `removed` |

Regressão: suíte completa — **27 arquivos, zero falhas**.
`tests/test_attendance_supersede.php` adiciona 26 asserções.

### O custo escondido: preservação de prova

Converter para supersede sem `$carryFrom` teria trocado um problema por outro.
O registro novo nasceria sem foto, sem GPS, sem IP e sem identificação de
dispositivo — exatamente a evidência que sustenta a marcação. O comprovante
sairia vazio e o `cron_photo_cleanup.php` deixaria de enxergar a foto como
referenciada, podendo apagá-la.

A separação foi feita campo a campo: copia-se o que é **prova do fato original**
(onde, com que aparelho, com que foto a pessoa registrou); não se copia o que
descreve a **circunstância do registro que deixou de valer** — aprovação, motivo
de inserção manual e rastro de edição pertencem ao novo ato administrativo.

### Mudanças de comportamento observáveis

1. **O id do registro muda ao editar.** A tela recarrega após salvar e já pega o
   id novo, mas qualquer chamador que guardasse o id antigo receberia "não
   pertence a este dia". Em vez de aceitar isso, `$resolverSucessor()` segue a
   corrente de `superseded_by_id` até o registro vigente — a propriedade que
   interessa (original intacto) é preservada sem quebrar contrato com quem chama.

2. **Editar consome um NSR.** Antes não consumia, porque nada era criado. Agora
   a edição gera uma marcação nova, e marcação nova tem número próprio. O teste
   `[8]` de `test_attendance_day_edit.php` foi atualizado de `+2` para `+3`.

3. **`removed` e `superseded` são buckets distintos** no retorno de
   `admin_save_attendance_day`. Remover é tirar o período do dia; substituir é
   trocá-lo por outro que continua valendo. Misturá-los faria a tela dizer
   "removido" para uma simples correção de horário.

### Referências deliberadamente NÃO repontadas

`attendance_break_requests.attendance_id` e
`attendance_checkout_requests.attendance_id` continuam apontando para o registro
original. A razão técnica é o `UNIQUE` (o UPDATE estouraria 1062), mas a de
fundo é outra: um pedido de regularização foi feito **contra o registro que
existia naquele momento** — é assim que deve ficar arquivado. As telas de
regularização seguem `superseded_by_id` para exibir o registro vigente.

### Pendência

O fluxo de **ciência do trabalhador** sobre correções (NC-30) continua aberto.
O supersede dá a base — original e substituto são registros distintos e
rastreáveis —, mas não existe notificação nem aceite do colaborador.

## Fase 4 — Arquivo Fonte de Dados (AFD), aplicada em 2026-08-06

Resolve **NC-05** — o AFD não existia. Fonte dos dados é o livro fiscal
(`nsr_ledger`), ordenado por NSR: é o encadeamento e o selo que dão lastro ao
arquivo, coisa que um `SELECT` em `attendance` não teria.

| Item | Arquivo | Verificação |
|---|---|---|
| Leiaute como DADO, não código | `lib/afd_spec.php` | `--spec` imprime posição, largura e origem de cada campo |
| Gerador em streaming | `lib/afd.php` | 4.522 linhas geradas em 0,05 s |
| `afd_line()` falha alto | idem | campo ausente e tipo inexistente são recusados |
| Pré-voo de cadastro | idem | separa bloqueios de avisos |
| Exportador CLI | `bin/export_afd.php` | `set_time_limit(0)`; `--preflight`, `--spec` |
| Exportador web | `public/admin/export_afd.php` | streaming, network_admin, CSRF |
| Eventos tipo 2 / 4 / 5 no livro | `employer_config.php`, `helpers.php`, `lib/hlb.php` | `nsr_ledger_record_cadastro()` |

Regressão: suíte completa — **28 arquivos, zero falhas**.
`tests/test_afd.php` adiciona 39 asserções.

### O leiaute NÃO está homologado — e o sistema não finge que está

`AFD_SPEC_VERIFICADA = false` em `lib/afd_spec.php`. Enquanto for `false`:

- `bin/export_afd.php` **recusa** gerar sem `--spec-nao-verificada`;
- o arquivo sai com sufixo `_RASCUNHO` e o CLI encerra com código 2;
- a tela administrativa exibe alerta e exige aceite explícito.

A razão é o modo de falha deste formato. O AFD é posicional de largura fixa:
uma coluna com um byte a menos produz um arquivo que passa em **todo** teste
interno e é rejeitado na fiscalização — e só se descobre quando não há mais como
corrigir. As posições atuais são a estrutura de trabalho do gerador, não uma
transcrição validada da Portaria.

Para homologar: conferir campo a campo contra o Anexo I da Portaria MTP
671/2021, submeter um arquivo a validador público de AFD e só então marcar a
constante como `true`. A arquitetura foi feita para que isso seja **edição de
uma tabela**, não caça a `sprintf` espalhados pelo código.

### O que o gerador já garante

- **Toda linha com a largura exata do seu tipo** — validado a cada linha, com
  exceção se divergir.
- **ASCII puro.** Um caractere acentuado ocuparia mais de um byte e deslocaria
  todas as colunas seguintes. `afd_ascii()` translitera; o teste confirma que
  "SEMED — Secretaria de Educação" não move nada.
- **NSR estritamente crescente**, reproduzindo a sequência fiscal.
- **Cabeçalho, corpo e trailer** com contadores por tipo conferindo.

### REP-P usa tipo 7 (CPF), não tipo 3 (PIS)

Distinção que muda o gargalo: a marcação de um REP-P é identificada por **CPF**.
O PIS é exigido no registro tipo 5 (empregado) e no AEJ. Por isso os 102
colaboradores sem PIS aparecem como **aviso**, não bloqueio, para o AFD — mas
bloqueiam a Fase 5.

### Bloqueios reais hoje (pré-voo em dados de produção)

- CNPJ do empregador não preenchido;
- identificador do REP não preenchido;
- local da prestação de serviço vazio (aviso);
- 102 colaboradores ativos sem PIS (aviso para o AFD, bloqueio para o AEJ).

São pendências de **cadastro** (Fase 0c), não de sistema. O gerador está pronto
e testado; sem esses dados nenhum AFD válido sai, por melhor que seja o código.

### ACJEF

Não implementado por decisão: é o formato da Portaria 1510/2009, substituído
pelo AEJ na 671. Implementar seria produzir um artefato obsoleto.

## Fase 5 — AEJ e Espelho de Ponto (aplicada em 2026-08-06)

Resolve **NC-06** (AEJ inexistente) e **NC-07** (espelho sem leiaute legal).

| Item | Arquivo | Verificação |
|---|---|---|
| Gerador do AEJ com spec declarativa | `lib/aej.php` | 30 asserções em `tests/test_aej.php` |
| Jornada contratual **líquida** | idem (`aej_jornada_contratual`) | 430 min, contra 780 do cálculo interno |
| Janela de contagem respeitada | idem | período pré-implantação não gera apuração |
| Medição prévia da apuração | idem (`aej_resumo_apuracao`) | mostra o que o arquivo vai declarar |
| Exportador CLI | `bin/export_aej.php` | `--apuracao`, `--preflight`, `--spec` |
| Espelho de ponto legal | `public/admin/timesheet_mirror.php` | NSR por marcação, HTML e PDF |

Regressão: suíte completa — **29 arquivos, zero falhas**.

### Dois defeitos que teriam produzido um documento fiscalmente danoso

Ambos só apareceram porque a apuração foi **medida antes de gerar o arquivo**.

**1. Jornada contratual inflada.** O primeiro rascunho reusava
`calculate_expected_minutes()`, que devolve a janela CHEIA sem descontar o
intervalo — é o modelo interno "cheio vs cheio", correto para o saldo do
sistema. Num colaborador real desta base (janela 06:00–19:00, intervalo de 350
min), isso declararia jornada contratual de **13 horas** onde o cadastro diz
**7h10**. O AEJ passou a ter cálculo próprio, líquido de intervalo.

**2. Falta declarada onde o sistema nem existia.** O gerador ignorava
`counting_start_date` (2026-05-01) e a data de cadastro do colaborador. Em
abril/2026 — período anterior à implantação — o AEJ declarava **1.104 dias de
jornada não cumprida, mais de 7.000 horas de ausência inexistente**.

Com as duas correções, a apuração de maio/2026 passa de "−7.267 h de déficit"
para **+29,5 h de saldo** sobre 14.500 h contratuais. O primeiro número era
puro artefato; o segundo é plausível e auditável.

### A diferença que o AEJ expõe pela primeira vez

Mesmo corrigido, o período mostra **16% dos dias previstos sem nenhuma
marcação** e 30% com déficit. Isso não é defeito do gerador: é a realidade que a
política interna do banco de horas nunca registrou, porque
`recompute_day_hour_bank()` deliberadamente não grava saldo negativo.

O AEJ é um documento fiscal e precisa declarar os fatos. Por isso
`bin/export_aej.php --apuracao` mostra o quadro **antes** de gerar, e alerta
quando mais de 10% dos dias previstos não têm marcação. Descobrir isso ao
entregar o arquivo à fiscalização seria tarde.

Antes de emitir em produção, cabe ao responsável verificar se esses dias são
ausências reais, afastamentos ainda não lançados ou falha de registro.

### Leiaute: mesma disciplina do AFD

`AEJ_SPEC_VERIFICADA = false`. O exportador recusa gerar sem
`--spec-nao-verificada`, marca o arquivo como RASCUNHO e encerra com código 2.
Conferir contra o Anexo oficial e validar antes de qualquer uso real.

### ~~PIS é BLOQUEIO no AEJ (diferente do AFD)~~ — REVISTO em 2026-08-07

> **Esta seção foi superada.** Ver "Decisão do empregador: emissão sem PIS
> (2026-08-07)", ao final deste documento. O PIS deixou de bloquear o AEJ.

Texto original: *o AFD de um REP-P identifica a marcação por CPF (tipo 7), então
o PIS ausente era apenas aviso. O AEJ identifica o trabalhador pelo PIS — sem ele
o arquivo não sai. Os 102 colaboradores sem PIS bloqueiam esta fase.*

O que estava errado no raciocínio: todos os registros do AEJ carregam **PIS e
CPF** como campos distintos — a própria spec em `lib/aej.php` mostra isso nos
tipos 2, 3, 4 e 5. O trabalhador continua identificado sem o PIS. Tratar como
bloqueio foi cautela excessiva, não exigência do leiaute.

### Espelho de ponto

`public/admin/timesheet_mirror.php` traz o que o relatório mensal existente não
tinha: **NSR de cada marcação** (entrada e saída, agora numeradas
separadamente), jornada contratual líquida, identificação do empregador e do
local de prestação conforme o art. 80, e registros anulados exibidos riscados
com o motivo — em vez de omitidos. Um relatório interno mostra o resultado; o
espelho legal precisa mostrar a evidência.

## Fase 6 — Hora Legal Brasileira estrita (aplicada em 2026-08-06)

Resolve **NC-14** (horário offline decidido por dado do próprio cliente) e
**NC-38** (transferência internacional negada, mas ocorrente).

| Item | Arquivo | Verificação |
|---|---|---|
| Âncora de tempo assinada | `lib/time_anchor.php` | 33 asserções em `tests/test_time_anchor.php` |
| Endpoint emite âncora + proveniência | `api/get_server_time.php` | token HMAC + estado da HLB |
| `checkin.php` usa a âncora | `api/checkin.php` | horário forjado deixa de ser aceito |
| `checkin_bulk.php` idem | `api/checkin_bulk.php` | fila offline carrega a prova |
| PWA guarda âncora com relógio monotônico | `public/index.php` | `buildTimeAnchorFields()` |
| APIs de tempo estrangeiras removidas | `public/index.php` | nenhuma referência ativa |

Regressão: suíte completa — **30 arquivos, zero falhas**.

### O raciocínio circular que foi eliminado

Para decidir se aceitava o horário que o **cliente** informava numa marcação
offline, o servidor consultava o `hlbOffsetSeconds` que **o mesmo cliente**
enviava no payload. Quem quisesse retrodatar um ponto mandava
`hlbOffsetSeconds: 0` junto com o horário forjado e passava. As três condições
existentes verificavam a coerência interna do payload, não sua veracidade.

Agora o horário deixa de ser afirmação do cliente:

1. Online, o app pede uma âncora — o servidor devolve o instante ATUAL dele e um
   HMAC sobre esse instante, o dispositivo e a validade.
2. O app guarda o token com o `performance.now()` daquele momento.
3. Offline, calcula o decorrido pelo relógio **monotônico**, que não muda se o
   usuário alterar a hora do aparelho.
4. Na sincronização envia token + `elapsed_ms`; o servidor confere a assinatura
   e reconstrói `instante = server_time + elapsed`.

Forjar exige a chave, que não sai do servidor. O teste `[3]` reproduz o ataque
antigo e confirma que já não funciona — e que a marcação legítima, com âncora
válida, continua aceita.

`hlbOffsetSeconds` segue sendo recebido e gravado, mas **rebaixado a campo de
auditoria**: não participa de nenhuma decisão. Continua servindo para sinalizar
drift excessivo como suspeita, que é uso legítimo.

### A marcação nunca é descartada

Sem âncora válida, grava-se a hora de **recebimento**, com
`pending_reasons += "Horário não comprovado…"` e `fraud_risk_level >= 1`.

O registro de jornada é direito do trabalhador (art. 74 da CLT) e não pode se
perder porque o horário não pôde ser comprovado. O que não se pode é apresentar
como comprovado um horário que o cliente simplesmente afirmou. A incerteza vai
explícita para revisão do admin.

Isso também garante compatibilidade: PWA antigo, que ainda não envia âncora,
continua funcionando — com o registro marcado para revisão.

### Dependências de tempo estrangeiras removidas

O PWA consultava `worldtimeapi.org` e `timeapi.io` antes do próprio servidor.
Dois problemas: cada consulta enviava o IP do trabalhador para fora do país,
contradizendo a política que declarava não haver transferência internacional
(NC-38); e hora vinda de terceiro não é oponível a ninguém — o que tem valor é
a âncora assinada pelo próprio sistema.

### Ressalva a medir em campo

Em iOS e Android, `performance.now()` pode **congelar** enquanto o app está
suspenso em segundo plano. Se congelar, `elapsed_ms` fica menor que o tempo real
e o instante reconstruído cai **antes** do real — viés conservador do ponto de
vista do trabalhador (a marcação nunca "avança"), mas que precisa ser medido nos
aparelhos efetivamente usados antes de a âncora virar obrigatória.

Enquanto isso não for medido, o comportamento correto é o atual: âncora ausente
ou inválida não bloqueia, apenas marca para revisão.

## Fase 7 — LGPD (aplicada em 2026-08-06)

Resolve **NC-31** (consentimento só em localStorage), **NC-32** (sem
consentimento para geolocalização), **NC-34** (biometria em texto plano),
**NC-35** (sem anonimização), **NC-36** (sem registro de tratamento) e
**NC-37** (retenção por volume em vez de prazo).

| Item | Arquivo | Verificação |
|---|---|---|
| Consentimento por finalidade, server-side | `lib/lgpd.php`, migration | 43 asserções em `tests/test_lgpd.php` |
| Registro de operações de tratamento (art. 37) | `lgpd_processing_log` | alimentado pelo próprio sistema |
| Biometria cifrada em repouso | `lib/lgpd.php` + 9 pontos de leitura/escrita | 97/97 migradas, round-trip íntegro |
| Retenção de fotos por PRAZO | `helpers.php` | gatilho de volume removido |
| Retenção de logs | `lgpd_purge_logs()` | prazos distintos por natureza do log |
| Anonimização de desligado | `lgpd_anonymize_teacher()` | identificadores saem, jornada permanece |

Regressão: suíte completa — **31 arquivos, zero falhas**. Reconhecimento facial
verificado após a cifragem.

### A retenção que nunca rodou

O achado mais concreto desta fase: havia **550 fotos de rosto além dos 90 dias**
de retenção, guardadas indefinidamente. A rotina existia e o cron estava certo —
mas o gatilho era **volume em disco** (500 MB), e o diretório tinha 1,1 MB. A
limpeza simplesmente nunca era acionada.

Retenção medida em espaço livre não é política de retenção; é política de
capacidade. A LGPD exige a primeira (arts. 15 e 16 — eliminação após o fim da
finalidade). O prazo passou a ser o único critério, configurável em
`app_settings.retention_photos_days`.

### Consentimento que prova alguma coisa

O aceite vivia em `localStorage.setItem('lgpd-consent-given')`: limpar o
navegador apagava a "prova". Para dado biométrico, que é sensível (art. 11),
isso não é oponível a ninguém — e a tabela `lgpd_consent` existia desde sempre
com **zero linhas**.

Três coisas que faltavam e agora existem:

- **Finalidade específica** (art. 9º): consentir com biometria não é consentir
  com geolocalização. Cada finalidade tem seu registro, e o teste confirma que
  uma não implica a outra.
- **Hash do termo**: guardar só a versão provaria a data, não o CONTEÚDO
  aceito. O `term_hash` fixa o texto exato.
- **Revogação** (art. 8º §5º): o registro anterior **não** é apagado, apenas
  marcado como revogado — apagar destruiria a prova de que o tratamento no
  período anterior era lícito.

### Biometria: envelope JSON, não string opaca

A coluna `teachers.face_descriptors` tem um `CHECK json_valid()`, e a primeira
tentativa de gravar `enc:v1:<base64>` foi rejeitada pelo banco. A saída fácil
seria remover a constraint; preferi caber nela — o cifrado agora é
`{"enc":"v1","d":"…"}`, JSON válido. A constraint protege contra gravar lixo e
continua valendo.

AES-256-GCM cifra **e autentica**: o teste confirma que adulterar um byte do
payload faz a leitura falhar, em vez de devolver um vetor plausível. O IV
aleatório garante que duas cifragens do mesmo rosto sejam diferentes — sem isso
seria possível inferir igualdade entre biometrias só comparando os registros.

A leitura aceita os dois formatos de propósito, o que permitiu migrar as 97
linhas sem janela de indisponibilidade.

### Degradação deliberada quando falta a chave

Sem `BIOMETRIC_ENCRYPTION_KEY`, o sistema **não cifra dados novos** mas continua
lendo os antigos. Travar o reconhecimento facial inteiro por falta de uma chave
impediria as pessoas de bater ponto — pior que o risco que se quer mitigar. A
ausência vai para o log.

### Anonimização preserva a jornada

Anonimizar não é apagar. Os registros de ponto sobrevivem pelo prazo legal — o
livro fiscal é imutável por construção e a Portaria exige preservar o NSR. O que
sai são nome, CPF, e-mail, PIN, biometria, PIS e dispositivos vinculados. O
`teacher_id` permanece, mas deixa de apontar para pessoa identificável (art. 12).

O CPF vira um token derivado, não NULL: a coluna participa de índices e
consultas históricas, e nulificá-la quebraria relatórios antigos.

### Pendências

- **Termo de consentimento na interface.** As funções existem e estão testadas,
  mas o modal do PWA ainda grava em `localStorage`. Ligar o fluxo à API é
  trabalho de front que ficou fora desta fase.
- **DPO não nomeado** e política com placeholders (NC-33) — decisão
  administrativa, não de código.
- **PIN em texto claro no IndexedDB** (NC-41) — exige trocar o esquema da fila
  offline por token de dispositivo.

## Fase 8 — Regras de jornada da CLT (aplicada em 2026-08-06)

Resolve **NC-20** (adicional noturno inexistente), **NC-21** (intervalo
intrajornada sem regra legal), **NC-22** (interjornada de 11h não validada) e
**NC-23** (tolerância aplicada ao agregado, não por marcação).

| Item | Arquivo | Verificação |
|---|---|---|
| Adicional noturno com hora reduzida | `lib/clt.php` | 53 asserções em `tests/test_clt.php` |
| Intervalo intrajornada + indenização §4º | idem | mínimo por faixa de jornada |
| Interjornada de 11h no check-in | `api/checkin.php` | vai para `pending_reasons` |
| Tolerância por marcação com teto diário | `lib/clt.php` | computa integralmente ao exceder |
| Avisos de conformidade na edição de dia | `helpers.php` | `res['avisos_clt']` |

Regressão: suíte completa — **32 arquivos, zero falhas**.

### Estas funções APURAM, não bloqueiam

Uma jornada que viola o art. 66 ou o art. 71 já aconteceu — o trabalhador esteve
lá. Recusar a marcação apagaria a prova justamente do fato que gera o direito.
O sistema registra a irregularidade de forma visível (em `pending_reasons` no
check-in, em `avisos_clt` na edição), para que a gestão corrija a escala e o
passivo seja conhecido antes de virar reclamação.

### O bug que o teste pegou: madrugada não contava

A primeira versão de `clt_minutos_noturnos()` varria os dias a partir do dia da
ENTRADA. Mas a janela noturna que cobre a madrugada (00h–05h) abre às **22h do
dia anterior** — então todo trabalho entre meia-noite e cinco da manhã ficava
fora da conta. É o caso mais comum de jornada noturna e o mais custoso de errar.

Corrigido recuando o laço um dia, e validado em sete cenários: madrugada pura,
00h–05h inteira, diurna, 22h–06h, cruzando meia-noite, 20h–23h e um intervalo
que toca as duas pontas da janela.

### A hora noturna é REDUZIDA, e isso aumenta o que se deve

O art. 73 §1º define a hora noturna urbana como **52 min 30 s**. Não basta
contar minutos e aplicar 20%: cada 52,5 minutos de relógio equivalem a uma hora
de jornada. 420 minutos trabalhados entre 22h e 5h são **8 horas noturnas**, não
7 — e o adicional incide sobre as 8.

### A tolerância estava errada de um jeito específico

O sistema aplicava 5 minutos ao **agregado do mês**. O art. 58 §1º diz outra
coisa, em três partes:

1. a tolerância é **por marcação** (até 5 min cada);
2. com **teto de 10 minutos no dia**;
3. e, uma vez excedido o limite, o tempo é computado **integralmente** — não
   apenas o que passou de 5 minutos.

O item 3 é o que mais se erra. Uma variação de 7 minutos gera 7 minutos de
jornada, não 2. O teste cobre esse caso explicitamente, além do de borda em que
duas marcações de 5 min esgotam o teto e a terceira passa a ser computada.

### Nada disso altera cálculo existente ainda

As funções apuram e avisam, mas **não** foram ligadas ao fechamento de folha nem
ao banco de horas. Isso é deliberado: mudar a base de cálculo de pagamento é
decisão do responsável, não efeito colateral de uma correção de conformidade.
A amostra de maio/2026 não tem trabalho noturno registrado, então o impacto
imediato é nulo — mas a regra passa a existir para quando houver.

### Pendências desta fase

- **8.5 — horas extras** (limite de 2h/dia, adicional de 100% em domingo e
  feriado, prazo do banco de horas): o controle de horas extras está
  **descontinuado** no sistema (`overtime.php` é um stub, seis funções
  `@deprecated`). Reativá-lo é decisão de produto, não correção de bug.
- **8.6 — ciência do trabalhador sobre correções** (NC-30): o supersede da
  Fase 3 dá a base rastreável, mas não há notificação nem aceite.

## Fase 9 — Segurança e documentação (aplicada em 2026-08-06)

Resolve **NC-44** (timeouts nunca aplicados, remember-me de 10 anos),
**NC-47** (CSP só no login, HSTS nunca emitido), **NC-48** (`has_permission()`
fail-open) e **NC-49** (conexão sem `time_zone`).

| Item | Arquivo | Verificação |
|---|---|---|
| `has_permission()` fail-closed + 4 papéis | `helpers.php`, `role_permissions` | 38 asserções em `tests/test_seguranca.php` |
| `SET time_zone` na conexão | `config.php` | MySQL e PHP coincidem |
| Sessão admin expira por inatividade | `helpers.php` (`require_admin`) | audita a expiração |
| Remember-me com validade real | `helpers.php`, migration | 90 dias, checado na leitura |
| CSP global + HSTS atrás de proxy | `config.php` | `connect-src 'self'`, `object-src 'none'` |
| Documentos de conformidade corrigidos | `docs/` | avisos de superação e de não-assinatura |

Regressão: suíte completa — **33 arquivos, zero falhas**.

### O controle de permissões nunca funcionou

O relatório inicial registrou `has_permission()` como fail-open — "sem regra
cadastrada, permite". O achado real é pior: a função consultava
`SELECT allow FROM permissions WHERE role = ? AND perm_key = ?`, mas a tabela
`permissions` tem `(id, admin_id, permission_key, granted_at)`. **Nenhuma das
três colunas existe.** A query lançava exceção em toda chamada, o `catch`
devolvia `true`, e o controle granular jamais funcionou — para nenhum papel, em
nenhum momento.

Criada `role_permissions` com o schema que o mecanismo sempre esperou, semeada
com quatro papéis. `school_admin` e `manager` deixam de ter acesso implícito a
tudo; surgem `hr_admin` (setor de pessoal) e `manager` (gestor), atendendo à
NC-13 do relatório original — não existiam papéis de RH nem de gestão.

Regra ausente agora **nega**, mas registra no log: uma chave nova esquecida no
seed aparece como problema em vez de virar bloqueio silencioso.

### Fuso: um risco que não tinha sintoma

A conexão nunca fixava `time_zone`. O PHP roda em `America/Sao_Paulo`, mas
`NOW()` e `CURRENT_TIMESTAMP` usavam o fuso do servidor MySQL, fora do controle
da aplicação. Como `attendance` mistura `DATETIME` (ingênuo, gravado pelo PHP)
com `TIMESTAMP` (convertido pelo MySQL), um servidor em UTC produziria registros
de ponto com **três horas de diferença entre colunas da mesma linha** — e nada
acusaria.

Usa-se o offset `-03:00` em vez do nome do fuso: bancos sem as tabelas de
timezone carregadas — comum em hospedagem compartilhada — rejeitam
`America/Sao_Paulo`. O Brasil não adota horário de verão desde 2019.

### Sessões que não terminavam

`ADMIN_SESSION_TIMEOUT` era definido em `config.php` e **nunca lido**: um posto
de trabalho deixado aberto seguia administrando o ponto de todos. Agora expira
por **inatividade** (não por tempo absoluto, que interromperia trabalho em
andamento), regenera o id da sessão e audita a expiração.

O "lembrar de mim" do colaborador emitia cookie de **dez anos**, e a tabela não
tinha coluna de expiração — a query de leitura não filtrava data nenhuma. Não
havia expiração em lugar algum. Agora são 90 dias, com `expires_at` verificado
na leitura. Os tokens existentes receberam validade contada da **criação**, não
de agora: token antigo deve expirar logo, não ganhar sobrevida pela correção.

### CSP: uma concessão explícita

A CSP existia apenas em `public/admin/login.php`. As páginas de maior superfície
— `index.php` com 13 mil linhas, `ponto.php`, `my_timesheet.php`, todo o admin —
rodavam sem nenhuma.

A política global mantém `'unsafe-inline'` em `script-src`: o projeto tem
JavaScript inline em praticamente todas as telas, e removê-lo é refatoração
ampla que não cabia aqui. Fica registrado como dívida. Mesmo assim a política já
bloqueia o que mais importa — script de origem externa e exfiltração para
domínio arbitrário, via `connect-src 'self'` — além de `object-src 'none'`,
`form-action 'self'` e `base-uri 'self'`.

O HSTS passou a usar a detecção de HTTPS atrás de proxy que **já existia** no
mesmo arquivo, 40 linhas acima, usada para o cookie de sessão.

### Documentos corrigidos

`PORTARIA_MTP_671_COMPLIANCE.md` e `IMPLEMENTACAO_PORTARIA_671_COMPLETA.md`
receberam aviso de **documento superado**, listando o que afirmavam e não se
confirmou. `ATESTADO_TECNICO_MODELO.md` recebeu aviso de **não assinar**, com as
cinco pendências que ainda impedem a assinatura — leiautes não homologados,
cadastro incompleto, livro em `off`, selo não ancorado e DPO não nomeado.

### Política de senha do administrador (NC-43)

Não havia validação alguma. `admins.php` e `bin/setup_admin.php` gravavam o
que fosse digitado — o setup checava apenas 8 caracteres, o que aceita
`12345678` — e `ensure_default_admin()` criava o usuário `admin` com a senha
`admin123`.

O contraste era desproporcional: o PIN do colaborador tinha
`pin_validate_strength()`, bloqueando sequências, repetições e janelas do CPF,
enquanto a senha de quem administra o ponto de todos aceitava qualquer coisa.

`admin_validate_password()` recusa senhas conhecidas, sequências de teclado e
de dígitos, caractere repetido, e qualquer trecho do CPF ou do nome do próprio
administrador. O critério de força é comprimento com variedade — três tipos de
caractere OU 16+ caracteres. Exigir símbolo obrigatório produz `Senha@123`,
que é pior que uma frase longa.

O admin inicial passa a receber **senha aleatória**, impressa uma única vez.
Uma senha que ninguém conhece é preferível a uma que todo mundo conhece.

### Testes em CI (9.6) — e o que o CI encontrou de imediato

O único CI do projeto era o Lighthouse de acessibilidade. Não havia verificação
de sintaxe, de testes nem de vulnerabilidade em dependência.

`composer audit` acusou **15 advisories em 2 pacotes** logo na primeira
execução. O mais grave: **Dompdf < 3.1.6 permite leitura de arquivo local via
SVG em data-URI** (CVE-2026-56722) — num sistema que usa Dompdf exatamente
para gerar o comprovante de ponto e o espelho de jornada. Atualizado para
3.1.6; `phpoffice/phpspreadsheet` foi de 1.30.0 para 1.30.6. Auditoria agora
limpa, e o CI falha se voltar a haver advisory.

**Os 34 scripts não foram reescritos como classes PHPUnit.** Eles já verificam
o que precisam e já falham quando devem; reescrevê-los seria trabalho longo com
risco de quebrar teste que hoje funciona. `tests/SuiteTest.php` executa cada um
como subprocesso e transforma o resultado em asserção — o CI ganha relatório
padronizado sem reescrita, e testes novos podem nascer como classes normais.

Cada script roda em processo próprio de propósito: vários mexem em estado
global (sessão, cache de settings, transações, triggers), e compartilhar
processo faria um contaminar o outro — o tipo de falha intermitente que corrói
a confiança na suíte.

### Pendências desta fase

- **2FA para admin** — excluído a pedido. A política de senha reduz o risco,
  mas não substitui segundo fator para contas com acesso total.
- **`'unsafe-inline'` na CSP**, pelas razões acima.

## NC-41 — PIN fora do dispositivo (aplicada em 2026-08-06)

A fila offline exigia `pin` em cada item, então a **credencial permanente do
trabalhador ficava em texto claro no IndexedDB** até o drain — que pode demorar
dias, e nunca acontece se o app for desinstalado com a fila cheia. Qualquer XSS,
extensão maliciosa ou perícia no aparelho lia o PIN.

O contraste dentro do próprio código era gritante: `savePending()` já removia
deliberadamente o descriptor facial antes de gravar, com o comentário "nunca
persiste descriptor em repouso (LGPD)". O PIN — que é credencial, não biometria
derivada — não recebia o mesmo cuidado.

| Item | Arquivo | Verificação |
|---|---|---|
| Token de autorização offline | `lib/offline_auth.php` | 28 asserções em `tests/test_offline_auth.php` |
| Emissão após autenticar por PIN | `api/checkin.php` | header `X-Offline-Auth` |
| Aceite no drain | `api/checkin_bulk.php` | token preferencial, PIN como compatibilidade |
| PIN apagado antes de gravar | `public/index.php` | `delete payloadSafe.pin` |

Suíte completa: **36 casos, 100 asserções, zero falhas** (PHPUnit).

O token é assinado com HMAC, vinculado ao CPF **e** ao dispositivo, e vale 36 h
— o suficiente para uma jornada e o turno seguinte, inclusive plantão que
atravessa a madrugada. Vazado, expira sozinho; não permite login nem troca de
PIN. O vínculo com o CPF impede reenviar um item da fila trocando o CPF para
registrar ponto no lugar de outra pessoa.

O token vai em **header**, não no corpo: `checkin.php` tem muitos pontos de
saída (duplicata, já registrado, sucesso, erro de estado) e o cliente precisa do
token em todos. Header é definido uma vez e acompanha qualquer resposta.

O PIN segue aceito no drain **apenas** para itens enfileirados antes desta
correção. Sem isso, marcações offline legítimas seriam descartadas na
atualização — e o registro de jornada é direito do trabalhador. A janela se
fecha sozinha: itens antigos são drenados e o app novo nunca mais grava PIN.

## Modo `shadow` ligado (2026-08-07) — apenas no banco de DESENVOLVIMENTO

`ledger_mode` passou de `off` para `shadow` **no banco local**. A configuração
vive em `app_settings`, então produção continua em `off` até que o comando
abaixo seja executado lá.

Pré-requisitos conferidos antes de ligar: chave de assinatura disponível,
triggers de imutabilidade ativos, cadeia íntegra e sem buracos de NSR.

### Comportamento verificado

| Cenário | Resultado |
|---|---|
| Marcação normal | ponto gravado + NSR fiscal emitido + espelho em `attendance` |
| Livro **falha** durante a marcação | ponto **sobrevive**, falha registrada em `app_settings` |
| Cadeia após ativação | íntegra, 8.822 registros |
| Reconciliação `attendance` × livro | zero divergência em 4.325 linhas |

### O que o shadow revelou de imediato

A suíte, que passava com o livro desligado, acusou **uma falha** assim que o
modo foi ligado — que é exatamente para isso que o shadow existe.

Não era defeito de produção: `test_attendance_day_edit.php` comparava
`attendance.nsr` com o contador **legado**. Com o livro ligado, `attendance.nsr`
passa a espelhar o NSR **fiscal** e o legado vai para `legacy_nsr` — duas
sequências distintas, por desenho. O teste codificava uma premissa que deixou de
valer.

Reescrito para ser consciente do modo: verifica o invariante que sempre importou
(o emissor legado avança exatamente +1 por período inserido) e, com o livro
ligado, confere adicionalmente que `legacy_nsr` foi preservado e que a marcação
consta no livro. Passa nos dois modos.

Também ficou confirmado que um período criado na edição de dia consome **dois**
NSRs fiscais — entrada e saída são marcações distintas, que é precisamente o que
a NC-09 exigia.

### Para ligar em PRODUÇÃO

```sql
UPDATE app_settings SET v = 'shadow' WHERE k = 'ledger_mode';
```

Antes disso, em produção: rodar `php bin/ledger_backfill.php --dry-run`, depois
`--commit`, e só então ligar o shadow. O backfill recusa rodar sobre livro
não-vazio.

### Rotina diária enquanto estiver em shadow

```
0  3 * * * cd /caminho && php bin/ledger_verify.php >> logs/ledger_verify.log 2>&1
30 3 * * * cd /caminho && php bin/ledger_seal.php   >> logs/ledger_seal.log   2>&1
0  4 * * 1 cd /caminho && php bin/ledger_verify.php --reconcile=$(date -d '-7 days' +\%Y-\%m-\%d):$(date +\%Y-\%m-\%d)
```

Passar para `enforced` só depois de ~2 semanas sem divergência **e** sem
`ledger_last_shadow_failure` em `app_settings`. Em `enforced`, falha do livro
aborta a marcação — o que só é aceitável quando há evidência de que ele não
falha.

---

## Fase 0c — Entrada do cadastro exigido pelo AFD/AEJ (aplicada em 2026-08-07)

### A lacuna

A Fase 0b.5 criou as colunas (`teachers.pis/admission_date/dismissal_date/matricula/cbo`,
`employer_config.employer_type/cpf/cei_caepf_cno/rep_identifier/service_location`,
`leave_types.aej_code`) e **nunca estendeu os formulários**. O resultado: os
pré-voos do AFD e do AEJ bloqueavam por CNPJ, identificador do REP e PIS, e não
havia onde digitar nenhum dos três. Era lacuna de execução, não decisão.

### O que foi feito

| Arquivo | Mudança |
|---|---|
| `helpers.php` | `validate_cnpj()` e `validate_pis()` — algoritmo oficial dos DVs, recusa sequências triviais |
| `public/admin/employer_config.php` | Campos `employer_type`, `cpf`, `cei_caepf_cno`, `rep_identifier`, `service_location`; CNPJ validado antes de gravar; pessoa física exige CPF válido |
| `public/admin/teacher_edit.php` | Bloco "Dados contratuais": `pis`, `matricula`, `cbo`, `admission_date`, `dismissal_date` |
| `public/admin/teachers_save.php` | Persiste os cinco campos; valida o PIS; recusa desligamento anterior à admissão; **emite o evento tipo 5 do AFD na edição** |
| `public/admin/leave_types.php` | Campo `aej_code` + coluna "pendente" na listagem |
| `lib/import_cadastro.php` | `importar_analisar()` e `normalizar_data_import()` — a conferência do CSV, sem gravar |
| `public/admin/import_pis.php` | Importação em massa em duas etapas (conferir → gravar) |
| `public/admin/teachers.php` | Botão "Importar PIS" com contador do que falta |
| `tests/test_cadastro_afd.php` | 74 verificações |

### Duas decisões que valem registro

**O evento tipo 5 do AFD passou a sair também na edição.** Antes só era emitido
em `admin_set_collaborator_active()` (ativação/inativação). Corrigir um PIS ou um
nome — exatamente o que o registro tipo 5 carrega — não deixava rastro no AFD.
Agora sai com operação `A`, e **só quando algum dos campos do tipo 5 realmente
muda**: salvar o formulário sem alterar nada não é alteração de empregado e não
deve consumir NSR.

**A importação é em duas etapas, por decisão.** A primeira só lê e mostra o que
aconteceria; nada é gravado. Um PIS com dígito errado passa em qualquer
verificação interna e só aparece na rejeição do AEJ pela fiscalização, quando o
mês já fechou. A conferência humana antes de gravar não é fricção supérflua.
A etapa 2 **revalida no servidor** — o payload voltou pelo formulário e não é
confiável só porque a etapa 1 o produziu. Coluna vazia no CSV preserva o valor
cadastrado em vez de apagá-lo (`COALESCE(NULLIF(?,''), coluna)`), porque
importação parcial é o caso comum.

### Estado dos bloqueios após esta fase

CNPJ gravado (`62.000.259/0001-90`, validado; evento `employer_change` no livro
sob NSR 9132). Restam **2 bloqueios**, ambos dependentes de dado externo:

| Bloqueio | Onde resolver |
|---|---|
| `rep_identifier` vazio (AFD) | `employer_config.php` |
| 102 colaboradores ativos sem PIS (AEJ) | `import_pis.php` |

Avisos remanescentes: `service_location` vazio, 9 `leave_types` sem `aej_code`,
e — o mais importante — **`AFD_SPEC_VERIFICADA` e `AEJ_SPEC_VERIFICADA`
continuam `false`**. Os leiautes não foram conferidos contra o Anexo oficial nem
submetidos a validador público. Nenhum arquivo deve ser apresentado como
homologado enquanto essas constantes forem `false`.

---

## Decisão do empregador: emissão sem PIS (2026-08-07)

**Decisão registrada:** os números de PIS dos colaboradores não existem e não
serão obtidos. Solicitado que o PIS deixe de ser requisito para emissão.

**Aplicado.** O PIS deixou de ser bloqueio no pré-voo do AEJ (em `lib/aej.php`
era o único bloqueio de PIS; no AFD já era aviso). Passou a aviso explícito, com
a contagem de colaboradores afetados.

**Por que isso é tecnicamente sustentável:** todos os registros do AEJ (tipos 2,
3, 4 e 5) e o registro tipo 5 do AFD carregam **PIS e CPF** como campos
distintos. Sem PIS, o campo sai zerado e o trabalhador continua identificado
pelo CPF. As marcações — registro tipo 7 do AFD, que é o volume do arquivo — não
são afetadas de forma alguma: identificam pelo CPF por construção do REP-P.

Verificado por geração real de junho/2026 (`tests/test_cadastro_afd.php`,
seção 13): 2.670 linhas de AEJ e 3.189 de AFD, largura única por tipo de
registro, campo PIS com 12 zeros, campo CPF preenchido e conferido contra a base.

**O risco que permanece, e que não é meu para decidir:** se a homologação do
leiaute determinar que o PIS é de preenchimento obrigatório, o arquivo será
rejeitado pela fiscalização e a decisão terá de ser revista. Como
`AEJ_SPEC_VERIFICADA` e `AFD_SPEC_VERIFICADA` continuam `false`, essa questão
será respondida na mesma etapa que valida os offsets do leiaute — não antes. O
aviso do pré-voo remete explicitamente a isso.

`public/admin/import_pis.php` e a coluna `teachers.pis` **foram mantidos**: a
tela também carrega matrícula, CBO e datas de admissão (registro tipo 5 do AFD),
e se os PIS aparecerem no futuro o caminho de carga já existe. O que mudou foi o
texto — a tela não afirma mais que o AEJ depende do PIS — e o botão em
`teachers.php`, que deixou de exibir contador de pendência.

### Estado dos bloqueios após esta decisão

| Arquivo | Pronto? | O que falta |
|---|---|---|
| **AEJ** | **sem bloqueios** | 3 avisos (PIS zerado, 9 `aej_code`, leiaute não homologado) |
| **AFD** | 1 bloqueio | `employer_config.rep_identifier` vazio |

### Adaptações decorrentes (2026-08-07)

Varredura do que ainda tratava o PIS como requisito, mais o que a decisão
destravou:

| Onde | Mudança |
|---|---|
| `lib/aej.php` | PIS deixou de ser bloqueio; virou aviso com contagem e remissão à homologação |
| `lib/afd.php` | Aviso reescrito: explicita que o tipo 7 (marcações) não é afetado |
| `bin/export_aej.php`, `bin/export_afd.php` | Textos de erro não mandam mais preencher PIS |
| `public/admin/export_afd.php` | Bloqueios não apontam mais para a tela de Colaboradores |
| `public/admin/import_pis.php` | Deixou de afirmar que o AEJ depende do PIS |
| `public/admin/teachers.php` | Botão vira "Importar cadastro", sem contador de pendência |
| `docs/ATESTADO_TECNICO_MODELO.md` | Pendência 2 reescrita |

**`public/admin/export_aej.php` — tela nova.** O AEJ só tinha exportador CLI.
Enquanto o PIS bloqueava, uma tela não teria o que entregar; destravado, passou a
precisar de caminho pela interface. Mostra o resumo da apuração (previsto ×
realizado × dias com déficit) **antes** de gerar — o AEJ expõe o déficit que a
política de jornada flexível opta por não registrar no banco de horas (Fase 5.2),
e quem assina precisa ver isso antes, não depois.

**Menu.** `export_afd.php`, `export_aej.php` e `timesheet_mirror.php` **não
estavam em lugar nenhum do menu** — só eram alcançáveis digitando a URL. Foram
agrupadas em Relatórios sob "Fiscalização — Portaria 671".

### Dois defeitos encontrados ao verificar, não ao escrever

**Telas sem `<head>`.** `export_afd.php` e `timesheet_mirror.php` definiam
`$titulo` e chamavam `_navbar.php` direto — sem doctype e sem carregar o
Bootstrap. Saíam como HTML solto e sem estilo. Ninguém tinha notado porque
nenhuma das duas estava no menu. Criado `public/admin/_head.php`; as três telas
passaram a incluí-lo.

**Constante inexistente.** `_head.php` e `import_pis.php` referenciavam
`APP_NAME`, que **não existe neste projeto**. `php -l` não pega (é erro de
execução) e a suíte não pegava porque nenhum teste carregava uma tela — a página
abriria em branco, com erro fatal.

Daí `tests/test_telas_admin.php` (60 verificações): renderiza cada tela em
processo próprio com sessão simulada e confere que monta, emite doctype, carrega
o Bootstrap, fecha o documento e não vaza warning no HTML. Inclui guarda direta
contra `APP_NAME` e confere que as três telas fiscais estão alcançáveis pelo
menu — de nada adianta funcionar se não há como chegar nelas.

---

## Bloqueios zerados — identificador do REP preenchido (2026-08-07)

`employer_config.rep_identifier = DEEDO001` (informado pelo empregador; cabe nos
17 caracteres do campo `ident_rep` do registro tipo 1). Gravado com evento
`employer_change` no livro sob NSR 9481.

**Os dois pré-voos passaram a `ok`.** Nenhum bloqueio no AFD nem no AEJ.

### Conferência do que sai

Geração real de junho/2026, decomposta pelas larguras declaradas na spec:

```
AFD  3.319 linhas  (1 cabeçalho + 3.317 tipo 7 + 1 trailer)
  cabecalho 237 col — ident_empregador '62000259000190' · ident_rep 'DEEDO001         '
  marcacao   33 col — nsr + tipo 7 + ddmmaaaa + hhmm + CPF
  trailer    64 col — qtd_tipo_7 000003317, bate com o emitido

AEJ  2.670 linhas
  cabecalho 220 col · trailer 46 col
  tipos: 2 → 302 · 3 → 2.048 · 4 → 6 · 5 → 312
```

Cada tipo consome exatamente a largura declarada, sem sobra nem falta.

`tests/test_cadastro_afd.php` subiu para **103 verificações**, agora cobrindo o
cabeçalho campo a campo (CNPJ só com dígitos, identificador do REP igual ao
cadastrado, campo alfanumérico preenchido à direita com espaço) e o fechamento
do trailer (contador declarado × linhas emitidas; total = cabeçalho +
declarados + trailer). A decomposição usa as larguras da spec, não posições
cravadas — mexer num campo anterior faz o teste acompanhar em vez de mentir.

### O que ainda separa isto de um arquivo entregável

Os avisos restantes, em ordem de importância:

1. **`AFD_SPEC_VERIFICADA` e `AEJ_SPEC_VERIFICADA` continuam `false`.** Enquanto
   estiverem, os arquivos saem com `_RASCUNHO` no nome e a tela exige marcar a
   caixa de ciência. **Este é o gate real** — a conferência acima prova
   consistência interna, não conformidade com o Anexo oficial.
2. `employer_config.service_location` vazio — o registro tipo 2 sai incompleto.
3. 9 `leave_types` sem `aej_code` — ocorrências sem classificação.
4. 102 colaboradores sem PIS — campo zerado, por decisão registrada acima.

---

## Local da prestação e códigos AEJ (2026-08-07)

Dois pedidos que pareciam iguais e não eram.

### Local da prestação — derivado, não inventado

`employer_config.service_location = 'SEMED - Secretaria Municipal de Educacao de
Oeiras - Oeiras/PI'` (62 de 100 caracteres). Não é chute: a tabela `schools` tem
**uma única unidade**, com o mesmo nome do empregador e as coordenadas já usadas
na cerca geográfica; `employer_config.city/state` traziam Oeiras/PI. A composição
apenas reúne o que já estava cadastrado. Gravado com evento `employer_change` no
livro (NSR 9540).

Conferido no arquivo: o registro tipo 2 sai com 301 colunas, `local_prestacao`
preenchido nos 100 caracteres do campo e `ident_empregador` com o CNPJ.

### Códigos AEJ — preenchidos, mas com trava

**Não existe tabela oficial de ocorrências em lugar nenhum deste repositório.**
Preenchi os 9 tipos ativos, e é preciso ser explícito sobre o que foram:

| Tipo | Código |
|---|---|
| Atestado Médico | `ATM` |
| Licença Médica | `LMED` |
| Férias | `FERI` |
| Abono | `ABON` |
| Licença Maternidade | `LMAT` |
| Licença Paternidade | `LPAT` |
| Falta Justificada | `FJUS` |
| Suspensão | `SUSP` |
| Licença Sem Vencimentos | `LSV` |

São **mnemônicos internos, não códigos oficiais**. A escolha de usar letras em
vez de números foi deliberada: um `01` pareceria código oficial e atravessaria
uma revisão sem ninguém olhar duas vezes. `FERI` denuncia sozinho que é interno.

**O risco que o preenchimento criou, e a trava que o cobre.** Antes, o pré-voo
avisava "9 tipos sem código AEJ". Preencher fez esse aviso sumir — e sem nada no
lugar, o perigo teria ficado **invisível**: o arquivo passaria a declarar
ocorrências com códigos que ninguém conferiu, o que é pior que declarar nenhuma.

Daí `aej_codigos_conferidos()`, lendo `app_settings('aej_codes_conferidos')`:

- Enquanto for `0`, o pré-voo do AEJ avisa que os códigos são provisórios e
  aponta onde conferir.
- A tela de Tipos de Afastamento mostra o estado e traz o botão "Conferi contra
  o Anexo oficial".
- **Alterar qualquer código revoga a conferência automaticamente**, com o motivo
  no log de auditoria — ela foi feita sobre a tabela como estava.
- Flag separada de `AEJ_SPEC_VERIFICADA` de propósito: aquela trata das POSIÇÕES
  dos campos, esta do CONTEÚDO de um deles. Homologar o leiaute e esquecer os
  códigos é erro plausível.

`tests/test_cadastro_afd.php` subiu para **123 verificações**, cobrindo o tipo 2
com o local de prestação, a unicidade e a largura dos códigos, o ciclo
confirmar → revogar da flag, e que os códigos cadastrados são os que saem nas
linhas tipo 4 do arquivo.

### Avisos restantes

| Aviso | Natureza |
|---|---|
| `AFD_SPEC_VERIFICADA` / `AEJ_SPEC_VERIFICADA` = `false` | **o gate real** — posições dos campos não conferidas |
| Códigos AEJ não conferidos | conteúdo do campo de ocorrência |
| 102 colaboradores sem PIS | decisão registrada do empregador |

---

## Investigação: os 1.133 dias sem marcação de junho (2026-08-07)

O AEJ de junho/2026 declarou **1.133 dias de jornada não cumprida** (56% dos dias
previstos) e um déficit de **−5.829 h**. Classificados:

| Origem | Dias | % |
|---|---:|---:|
| Após o fim dos dados | 1.004 | 88,6% |
| Afastamento já lançado | 19 | 1,7% |
| Ausência dentro do período ativo, sem justificativa | 110 | 9,7% |

**Não era ausência dos trabalhadores: era ausência de dado.** Evidências
convergentes de que esta base termina em 16/06:

- Último dia com volume normal: **2026-06-16** (86 de 102 pessoas marcaram).
- 17/06 em diante: **zero** marcações — não há declínio, há corte seco.
- `MAX(attendance.created_at)` = **2026-06-19 12:01:23**.
- `audit_logs`: 600–730 eventos/dia até 16/06, depois 12 eventos em 19/06, 1 em
  30/06 e nada até os trabalhos desta auditoria em agosto.

Encurtar o período para 01–16/06 **inverte o resultado**:

| | 01–30/06 | 01–16/06 |
|---|---:|---:|
| Dias apurados | 2.007 | 1.003 |
| Diferença | **−5.829 h** | **+780 h** |
| Dias com déficit | 1.288 (64%) | 284 (28%) |
| Dias sem marcação | 1.133 (56%) | 129 (13%) |

De déficit maciço para crédito. O primeiro número teria ido para a fiscalização.

### O defeito que isso expôs

O gerador contava como jornada não cumprida qualquer dia sem marcação entre o
início do período e **hoje** — sem perguntar se o sistema ainda estava recebendo
marcações. Qualquer AEJ emitido após uma parada (migração, recesso, queda,
snapshot) declararia falta em massa, silenciosamente.

Corrigido:

- **`aej_ultimo_dia_com_movimento()`** — último dia em que uma fração
  significativa dos ativos marcou (20%, configurável). O corte é por fração e não
  por `MAX(date)` de propósito: um registro solto após a parada moveria a
  fronteira e a esconderia.
- **`aej_preflight()`** avisa quando o período pedido passa dessa fronteira,
  dizendo quantos dias e por quê.
- **`aej_resumo_apuracao()`** devolve `dias_apos_fim_dos_dados` e
  `ultimo_dia_com_movimento`, separando "sem dado" de "ausente". O aviso de >10%
  passou a considerar só a ausência real.
- **`bin/aej_diagnostico.php`** — classifica dia a dia nas três causas
  excludentes, com quebra por data e por colaborador e exportação CSV. Somente
  leitura. É o que precisa rodar **em produção**, onde a resposta pode ser outra.

`tests/test_aej.php` subiu para **42 verificações**, cobrindo a fronteira, o
disparo e o não-disparo do aviso, a separação no resumo e a garantia de que o
diagnóstico não escreve no banco.

### O que sobra para o operador decidir

Os **110 dias** de ausência real dentro do período com dados (~11% dos 1.003
apurados) continuam sendo declarados como jornada não cumprida — corretamente,
até onde o sistema sabe. Se houver justificativa não lançada, o lugar é
Afastamentos, antes de emitir.

# Relatório de Auditoria — DEEDO Ponto

**Data:** 2026-04-26
**Escopo:** Sistema PHP de ponto eletrônico (Portaria MTP 671/2021)
**Diretório auditado:** `C:\xampp\htdocs\ponto_ribeira`
**Método:** Análise estática multi-fase (segurança, conformidade legal, sync offline, arquitetura, qualidade)
**Entregável:** relatório + correção dos bugs funcionais (rodada de fix em 2026-04-26)

---

## 0. Changelog (2026-04-26)

Duas rodadas de fix foram aplicadas:

- **Rodada 2** — 7 bugs funcionais (tabela abaixo).
- **Rodada 3** — 3 erros lógicos identificados em revisão pós-fix (ver §0.1).

Compliance/hardening/arquitetura **não foram tocados** — escopo restrito a defeitos de código por decisão do solicitante.

| ID | Status | Arquivos modificados |
|---|---|---|
| BUG-001 (Critical) — Fix CSRF do SW não aplicado + `checkin_bulk` sem `csrf_verify` | ✅ Fixed | [public/sw.js](../public/sw.js), [api/checkin_bulk.php](../api/checkin_bulk.php) |
| BUG-002 (High) — `clear_service_worker.php` apaga IDB sem auth/confirmação | ✅ Fixed | [public/admin/clear_service_worker.php](../public/admin/clear_service_worker.php) |
| BUG-005 (High) — `checkin_bulk.php` sem dedup por `client_id` | ✅ Fixed | [api/checkin_bulk.php](../api/checkin_bulk.php) |
| BUG-006 (High) — Stored XSS no modal de leaves (innerHTML sem escape) | ✅ Fixed | [public/admin/leaves.php](../public/admin/leaves.php), [public/admin/write_leaves.php](../public/admin/write_leaves.php) |
| BUG-007 (High) — `audit_discarded_offline` retornava 200 em falha | ✅ Fixed | [api/audit_discarded_offline.php](../api/audit_discarded_offline.php), [public/index.php](../public/index.php) |
| BUG-008 (High) — Precache do `index.php` congelava CSRF token | ✅ Fixed | [public/sw.js](../public/sw.js) |
| QUAL-001 (Medium) — Double-escape em templates PDF | ✅ Fixed | [public/admin/_tpl_reports_pdf.php](../public/admin/_tpl_reports_pdf.php), [public/admin/_tpl_teacher_monthly_report_pdf.php](../public/admin/_tpl_teacher_monthly_report_pdf.php) |

**Side-effect:** SEC-002 (`checkin_bulk` aceitava registro sem CSRF) foi resolvido como parte de BUG-001.

### 0.1 Rodada 3 — Correção de erros lógicos pós-revisão

A revisão crítica do que foi aplicado na rodada 2 expôs três defeitos lógicos. Todos foram corrigidos.

| # | Defeito | Status | Arquivos |
|---|---|---|---|
| Fix #1 | Dedup do BUG-005 cobria só entrada — saída-fantasma podia surgir em retry pós-falha de rede mid-flight | ✅ Fixed | [sql/migrations/add_attendance_checkout_client_id.sql](../sql/migrations/add_attendance_checkout_client_id.sql) (nova), [api/checkin_bulk.php](../api/checkin_bulk.php) |
| Fix #2 | `countPendingInDb` em `clear_service_worker.php` travava se o IDB estivesse `onblocked` | ✅ Fixed | [public/admin/clear_service_worker.php](../public/admin/clear_service_worker.php) |
| Fix #3 | Bulk usava só o CSRF do primeiro item; lote inteiro travava em sessão divergente | ✅ Fixed | [public/sw.js](../public/sw.js) |

**Decisões de design:**
- Fix #1 introduziu coluna `checkout_client_id` (UNIQUE) na tabela `attendance` via nova migration. `run_auto_migrations()` aplica em boot. Pre-check antes do UPDATE + handler de race em 1062 (mesmo padrão da entrada).
- Fix #3 trocou batch único por loop item-a-item — cada POST envia `{items:[único]}` com seu próprio `X-CSRF-Token`. Servidor não precisou mudar (já aceita batch de 1). Trade-off aceito: latência de drain aumenta linearmente com o tamanho da fila.

### 0.2 Rodada 4 — Estender dedup de saída ao `api/checkin.php` per-item

Após nova solicitação, o mesmo padrão de Fix #1 foi propagado para [api/checkin.php](../api/checkin.php) (fluxo per-item, usado pelo `session_checkin` do SW e pelo registro online direto). Antes, só `checkin_bulk.php` estava protegido; o per-item ainda podia gerar saída-fantasma em retry pós-falha de rede.

| # | Defeito | Status | Arquivos |
|---|---|---|---|
| Fix #4 | `api/checkin.php` saída sem dedup | ✅ Fixed | [api/checkin.php](../api/checkin.php) |

**Mudanças:**
- Pre-check antes do `beginTransaction()`: se `$clientId` é UUID-like (≥8 chars), busca `WHERE checkout_client_id = ? AND teacher_id = ?` e retorna `already_registered` se hit.
- UPDATE inclui `checkout_client_id = ?` no SET (NULL se sem `client_id`).
- Catch trata PDOException 1062 com `stripos(..., 'checkout_client_id')` → resolve como `already_registered`.

A migration `add_attendance_checkout_client_id.sql` (criada em rodada 3) cobre ambos os endpoints.

**Validação:** `php -l api/checkin.php` ✓.

### 0.3 Rodada 5 — Lacunas de timestamp / HLB

Após análise do comportamento "o que acontece se o usuário mudar a hora do dispositivo", três lacunas foram tratadas:

| # | Lacuna | Status | Arquivos |
|---|---|---|---|
| (a) | `check_in`/`check_out` em offline-sync usavam sempre `$now` (servidor no momento do sync), perdendo a hora real da batida | ✅ Fixed (híbrido seguro) | [api/checkin.php](../api/checkin.php), [api/checkin_bulk.php](../api/checkin_bulk.php) |
| (b) | `HLB_MAX_OFFSET_SECONDS=120` definido em [config.php:101](../config.php) era dead code — drift gigante passava sem alerta | ✅ Fixed | [api/checkin.php](../api/checkin.php), [api/checkin_bulk.php](../api/checkin_bulk.php) |
| (c) | UI silenciosa quando HLB falhava em sincronizar — só ícone wifi-off discreto | ✅ Fixed | [public/index.php](../public/index.php) |

**Decisões de design:**

- **Lacuna (a) — híbrido seguro** (escolha do solicitante via AskUserQuestion). Para `record_mode='offline'`, o servidor agora usa `client_recorded_at` como `check_in`/`check_out` SE três condições forem satisfeitas: (1) `abs(hlb_offset_seconds) ≤ HLB_MAX_OFFSET_SECONDS`, (2) timestamp do cliente não está no futuro além de 60s de grace, (3) atraso ≤ 30 dias. Caso contrário, mantém comportamento atual (`$now` do servidor). Implementado via variável `$authoritativeTime` em ambos os endpoints. Online continua sempre usando servidor — anti-fraude preservado.
- **Lacuna (b)** — drift > limite agora bumpa `fraud_risk_level` (≥2 em checkin.php, ≥3 em checkin_bulk.php) e adiciona `'hlb_drift_excessive'` em `pending_reasons`. Não bloqueia (servidor já usa $now próprio), apenas sinaliza para revisão admin. Em checkin.php também alimenta a tabela `fraud_detection_log`.
- **Lacuna (c)** — banner amarelo persistente no topo da página quando `lastHlbSync === null`. Texto explica que a hora exibida pode estar errada mas o ponto será gravado com hora do servidor. Atualizado a cada segundo via `updateHLBClock`.

**Cenários antes-vs-depois:**

| Cenário | Antes | Depois |
|---|---|---|
| Ponto offline às 8h, sync às 14h, cliente HLB-sincronizado | `check_in = 14:00` (perda temporal) | `check_in = 08:00` (fiel) |
| Ponto offline com hlb_offset > 120s (cliente dessincronizado) | `check_in = sync_time` sem alerta | `check_in = sync_time` + `pending_reasons += hlb_drift_excessive` + `fraud_risk_level += 1-2` |
| Ponto online com hora local errada e HLB ok | `check_in = $now` (correto, sem alerta) | igual + `pending_reasons += hlb_drift_excessive` se offset > limite |
| HLB sync falhou, usuário vê hora local errada | Só ícone wifi-off discreto | Banner amarelo persistente avisando |

**Validação:** `php -l` ✓ em `api/checkin.php`, `api/checkin_bulk.php`, `public/index.php`.

### 0.4 Rodada 6 — Remoção do "Limpar registros de ponto" (compliance Portaria 671)

O painel admin tinha um botão "Limpar registros de ponto" que executava `DELETE FROM attendance` + reset do NSR + opcional remoção de fotos. **Operação proibida** pela Portaria MTP 671/2021 art. 84 (registros são documento legal e não podem ser removidos em massa).

| Ação | Arquivo |
|---|---|
| Removido o link `<li>` do menu admin (era visível para network_admin) | [public/admin/_navbar.php:170-174](../public/admin/_navbar.php) |
| Removido `clear_attendances.php` da lista de páginas categorizadas em "Configurações" | [public/admin/_navbar.php:43](../public/admin/_navbar.php) |
| Endpoint `clear_attendances.php` reescrito como stub que retorna `410 Gone` com mensagem explicativa — bloqueia URL direta e bookmark antigo | [public/admin/clear_attendances.php](../public/admin/clear_attendances.php) |

**Comportamento pós-mudança:**
- Menu admin não mostra mais o link.
- `GET/POST /admin/clear_attendances.php` (URL direta) → `410 Gone` + página com instrução para usar edição individual em `attendances.php`.
- Nenhum `DELETE FROM attendance` permanece nesse caminho.

**Verificação:** `Grep clear_attendances public/` retorna apenas o próprio arquivo (sem mais referências em código de produção).

**Validação:** `php -l` ✓ em `_navbar.php` e `clear_attendances.php`.

**Compliance:** este fix endereça parte da finding **COMPLY-002** (imutabilidade de marcações violada) — o atalho mais agressivo foi fechado. A edição individual em `attendance_edit.php` permanece como vetor remanescente; tratá-lo é mudança maior (introduzir registros "superseded" + NSR novo) e segue fora do escopo até decisão de produto.

**Validação:** `php -l` passa em `api/checkin_bulk.php` e `public/admin/clear_service_worker.php`; `node --check` passa em `public/sw.js`. Cache do SW bumpado para `v2.43.0`.

**Findings ainda abertos:** 48 (segurança/hardening, compliance Portaria 671, arquitetura). Ver seções 3-9 abaixo.

---

## 1. Sumário executivo

A auditoria identificou **56 findings totais** (7 Critical, 22 High, 22 Medium, 5 Low). **7 bugs funcionais foram corrigidos** nesta rodada (ver §0). Os 49 findings restantes são de hardening, compliance Portaria 671 ou arquitetura — explicitamente fora do escopo desta correção.

A descoberta mais grave é que **a declaração `PORTARIA_671_COMPLIANT=true` em [config.php:61](../config.php) não corresponde à implementação**: faltam exportação AFD/ACJEF, sincronização NTP real com a Hora Legal Brasileira, assinatura digital de comprovante e imutabilidade de marcações. A documentação em `docs/IMPLEMENTACAO_PORTARIA_671_COMPLETA.md` afirma compliance que o código não entrega.

Em segurança, há **CSRF ausente em endpoint anônimo de bulk check-in**, **endpoint `write_leaves.php` que sobrescreve arquivo PHP sem autenticação**, **arquivo de debug `test_output.php` que vaza bytes do `config.php`**, **CORS com origem refletida em endpoints mutadores** e **fix do CSRF em sincronização offline documentado em `BUGFIX_CSRF_TOKEN.md` mas não aplicado no Service Worker**.

### Top 5 riscos imediatos (status pós-rodada)

| # | Risco | Arquivo | Severidade | Status |
|---|---|---|---|---|
| 1 | Endpoint anônimo `write_leaves.php` reescreve `leaves.php` em disco | [public/admin/write_leaves.php:1-10](../public/admin/write_leaves.php) | Critical | ❌ Aberto |
| 2 | `checkin_bulk.php` aceita registro de ponto sem CSRF e sem sessão | [api/checkin_bulk.php:1-50](../api/checkin_bulk.php) | Critical | ✅ Fixed (BUG-001/SEC-002) |
| 3 | `test_output.php` na raiz, sem auth, ecoa bytes do `config.php` | [test_output.php:1-8](../test_output.php) | Critical | ❌ Aberto |
| 4 | AFD/ACJEF (exportação obrigatória pela Portaria 671) **não existem** no código | (ausente) | Critical | ❌ Aberto |
| 5 | Fix de CSRF do Service Worker (`BUGFIX_CSRF_TOKEN.md`) nunca foi aplicado em `sw.js` | [public/sw.js:485-512](../public/sw.js) | Critical | ✅ Fixed (BUG-001) |

### Status de compliance Portaria 671

**❌ NÃO CONFORME.** Implementação cobre ~40% dos requisitos técnicos. Declarar `PORTARIA_671_COMPLIANT=true` no estado atual é afirmação falsa.

---

## 2. Matriz de severidade aplicada

| Severidade | Critério |
|---|---|
| **Critical** | RCE/escrita arbitrária, auth bypass, perda silenciosa de ponto, gap material da Portaria 671 |
| **High** | XSS autenticado, IDOR, leak de PII, CSRF em mutação, race em sync, timing attack |
| **Medium** | CORS aberto, CSP fraca, audit gaps, redundância de código com risco operacional |
| **Low** | Logs verbosos, código morto cosmético, deprecação |

---

## 3. Findings Critical

### SEC-001 — `write_leaves.php` reescreve `leaves.php` sem autenticação

- **Arquivo:** [public/admin/write_leaves.php:1-10](../public/admin/write_leaves.php) (554 linhas totais)
- **Evidência:**
  ```php
  <?php
  // Writer script - generates the new leaves.php
  $target = 'c:/xampp/htdocs/ponto_ribeira/public/admin/leaves.php';
  $content = <<<'PHPEOF'
  <?php
  require_once __DIR__ . '/../../config.php';
  require_admin();
  ...
  PHPEOF;
  file_put_contents($target, $content);
  ```
  Não há `if (PHP_SAPI !== 'cli')` (verificado via Grep), não há `require_admin()`, não há `.htaccess` específico para o arquivo.
- **Impacto:** Qualquer requisição HTTP a `/admin/write_leaves.php` reexecuta a escrita em disco. Embora o conteúdo seja hardcoded, a existência do endpoint é confissão de que código de geração ficou no webroot. Em sistema sob ataque, dá pista para path discovery.
- **Recomendação:** Mover para `bin/`, adicionar guard CLI `if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }`, ou simplesmente remover do repositório.
- **Esforço:** S (≤30 min)

### SEC-002 — `checkin_bulk.php` aceita ponto anônimo sem CSRF — ✅ Fixed (2026-04-26)

- **Arquivo:** [api/checkin_bulk.php:1-50](../api/checkin_bulk.php)
- **Evidência:** Verificado via Read das primeiras 60 linhas — não há chamada a `csrf_verify()`. O endpoint aceita `{"items":[{cpf, pin, geo}]}` via POST e roteia para `process_check_item()` que executa INSERT em `attendance`. Exige PIN, mas qualquer site externo pode chamar. Adicionalmente, verificar com `Grep csrf_verify api/checkin_bulk.php` retorna zero matches.
- **Impacto:** Falsificação de marcações em massa cruzando origem. O ponto eletrônico é documento legal (Portaria 671); inserir registros falsos é fraude trabalhista.
- **Recomendação:** Chamar `csrf_verify()` no início, restringir `Access-Control-Allow-Origin` ao domínio próprio, exigir cookie de sessão de colaborador (`is_collaborator_logged()`).
- **Esforço:** S

### SEC-003 — `test_output.php` na raiz expõe bytes de `config.php`

- **Arquivo:** [test_output.php:1-8](../test_output.php)
- **Evidência:**
  ```php
  <?php
  ob_start();
  require_once __DIR__ . '/config.php';
  $output = ob_get_clean();
  if (strlen($output) > 0) {
      echo "Config output [" . strlen($output) . " bytes]: ";
      echo bin2hex($output);
  }
  ```
- **Impacto:** Acessível via `/test_output.php` (o `.htaccess` raiz reescreve para `public/`, mas arquivos diretos na raiz são servidos pelo Apache antes do rewrite). Vaza qualquer output gerado por `config.php` (BOM, warnings, debug). Em config alterada, pode imprimir credenciais.
- **Recomendação:** Apagar do repositório. Nunca deixar scripts de debug em raiz pública.
- **Esforço:** S

### SEC-004 — CORS com origem refletida em endpoints mutadores

- **Arquivos:** [api/checkin.php:16](../api/checkin.php), [api/pin_enroll.php:25](../api/pin_enroll.php), [api/pin_recover.php:16](../api/pin_recover.php), [api/self_enroll_face.php:25](../api/self_enroll_face.php), [api/last_checkin.php:14](../api/last_checkin.php), [api/get_server_time.php:8](../api/get_server_time.php) (este último: `*` wildcard)
- **Evidência:**
  ```php
  header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
  ```
- **Impacto:** Qualquer site pode atravessar CORS sem restrição. Combinado com SEC-002, permite um atacante hospedar página que registra pontos em nome de visitantes. `pin_enroll.php` e `self_enroll_face.php` mutam dados biométricos/credenciais.
- **Recomendação:** Whitelist explícita: comparar `HTTP_ORIGIN` contra lista permitida; rejeitar se não bater. `Access-Control-Allow-Credentials` deve ser `true` apenas com origem específica.
- **Esforço:** M (≤2h)

### COMPLY-001 — AFD/ACJEF ausentes (Portaria 671 Anexo IX)

- **Cláusula:** Portaria MTP 671/2021, Anexo IX — REP-P deve gerar AFD (Arquivo Fonte de Dados) e ACJEF (Arquivo de Controle de Jornada para Efeitos Fiscais).
- **Status:** ❌ Ausente
- **Evidência:** `Glob **/afd*.php` e `Glob **/acjef*.php` retornaram zero arquivos. `Grep AFD|ACJEF` (case-insensitive) só retornou matches em `vendor/` e em comentários genéricos de docs. Nenhum endpoint, nenhuma rotina de exportação no código vivo.
- **Impacto:** Sistema não consegue atender fiscalização do MTE que exigir o arquivo. Qualquer auditoria externa derruba o compliance declarado em [config.php:61](../config.php).
- **Recomendação:** Implementar exportadores conforme layout da Portaria. Até lá, **alterar `PORTARIA_671_COMPLIANT` para `false`** para evitar afirmação falsa.
- **Esforço:** L (>2h, provavelmente vários dias)

### COMPLY-002 — Imutabilidade de marcações violada (Portaria 671 art. 84)

- **Cláusula:** art. 84 — registros de ponto devem ser imutáveis; correções exigem novo registro com novo NSR, preservando o original.
- **Status:** ❌ Falho
- **Arquivo:** [public/admin/attendance_edit.php:51, 197-207](../public/admin/attendance_edit.php)
- **Evidência:** Comentário no código declara explicitamente "Removido: restrição que bloqueava edição para aprovados/rejeitados". O `UPDATE attendance SET ... WHERE id = ?` altera os campos originais (`check_in`, `check_out`, `date`). A tabela `attendance_edits` registra o "antes/depois", mas a linha original em `attendance` é sobrescrita.
- **Impacto:** Auditor não consegue verificar estado original sem cruzar duas tabelas. Em conflito trabalhista, defesa jurídica fica comprometida. Violação material de CLT art. 74 §2º + Portaria 671.
- **Recomendação:** Travar registros após aprovação/rejeição. Edições devem inserir novo registro com flag de correção e NSR novo, preservando o original com status "superseded".
- **Esforço:** L

### BUG-001 — Fix de CSRF do Service Worker nunca foi aplicado — ✅ Fixed (2026-04-26)

- **Documentação:** [docs/BUGFIX_CSRF_TOKEN.md](BUGFIX_CSRF_TOKEN.md)
- **Arquivo afetado:** [public/sw.js:485-512](../public/sw.js)
- **Evidência:** Documento descreve fix onde SW deve extrair `csrfToken = pending[0]?.csrf || null` e enviar em `X-CSRF-Token` header em `checkin_bulk`. Verificação no `sw.js` mostra que o fetch para `/api/checkin_bulk.php` envia apenas `Content-Type: application/json` — sem header de CSRF. Resultado: servidor não rejeita (porque `checkin_bulk.php` não chama `csrf_verify()` — ver SEC-002), mas o fix documentado é fictício.
- **Impacto:** Cenários de sync offline com sessão expirada retornam 403 e o item fica preso na fila IDB sem retry visível ao usuário. Combinado com `MAX_AGE_MS=7 dias`, o ponto é descartado silenciosamente. Perda de marcação = violação Portaria 671.
- **Recomendação:** Aplicar fix conforme documentado e adicionar `csrf_verify()` no servidor para fechar o loop.
- **Esforço:** M

---

## 4. Findings High

### SEC-005 — `clear_opcache.php` sem autenticação no admin
- **Arquivo:** [public/admin/clear_opcache.php:1-10](../public/admin/clear_opcache.php)
- **Evidência:** 9 linhas, sem `require_admin()`. Qualquer um chama `opcache_reset()`.
- **Impacto:** DoS leve (cache invalidation forçado em loop), ainda no grupo `/admin/` o que sugere expectativa de auth.
- **Recomendação:** Adicionar `require_once __DIR__ . '/../../config.php'; require_admin();` no início.
- **Esforço:** S

### SEC-006 — Rate limit do login admin é session-based
- **Arquivo:** [public/admin/login.php:24-72](../public/admin/login.php)
- **Evidência:**
  ```php
  $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
  $_SESSION['login_locked_until'] = $_SESSION['login_locked_until'] ?? 0;
  ```
  Atacante limpa cookie ou abre aba anônima e reseta o contador. Login de colaborador usa `auth_attempt_logs` no DB (correto); admin não.
- **Impacto:** Brute force de 5 tentativas a cada cookie/aba é trivialmente paralelizável.
- **Recomendação:** Usar `auth_attempt_is_limited($pdo, 'admin_login', $ipUaCpf, 300, 5)` como o fluxo de colaborador.
- **Esforço:** S

### SEC-007 — Timing attack em login admin (CPF inexistente)
- **Arquivo:** [helpers.php:832-852](../helpers.php) — função `admin_login_by_cpf`
- **Evidência:** Se CPF não existe, retorna `false` sem chamar `password_verify()`. `password_verify()` com bcrypt cost=10 demora ~100-200ms; ausência permite distinguir "CPF cadastrado" de "CPF inexistente" por timing.
- **Impacto:** Enumeração de CPFs de admins. Pré-requisito para ataque direcionado.
- **Recomendação:** Sempre executar `password_verify` (com hash dummy se `$row` for null).
- **Esforço:** S

### SEC-008 — Timing attack em login colaborador (CPF/PIN inexistente)
- **Arquivo:** [helpers.php:416-456](../helpers.php) — função `collaborator_login_by_pin`
- **Evidência:** Mesmo padrão: se `$row` ou `pin_hash` vazio, retorna sem `pin_verify()`.
- **Impacto:** Enumeração de CPFs cadastrados.
- **Recomendação:** Igual a SEC-007.
- **Esforço:** S

### SEC-009 — `ensure_default_admin()` cria admin/admin123
- **Arquivos:** [config.php:135-136](../config.php), [helpers.php:887-909](../helpers.php)
- **Evidência:**
  ```php
  // config.php
  if (!defined('APP_ENV') || APP_ENV !== 'production') {
      ensure_default_admin();
  }
  // helpers.php:902-903
  $hash = password_hash('admin123', PASSWORD_DEFAULT);
  $stmt = $pdo->prepare("INSERT INTO admins (...) VALUES ('admin', '00000000191', ?, 'network_admin')");
  ```
  Atualmente `APP_ENV='production'` em [config.php:65](../config.php), então não roda. Mas qualquer mudança para dev/staging cria admin com credenciais públicas.
- **Impacto:** Credencial conhecida em qualquer ambiente não-produção.
- **Recomendação:** Remover `ensure_default_admin()` do bootstrap. Documentar processo manual de criação de admin inicial via `bin/setup_admin.php`.
- **Esforço:** S

### SEC-010 — Upload em `leaves.php` valida só extensão (sem MIME real)
- **Arquivo:** [public/admin/leaves.php:45-62](../public/admin/leaves.php) (gerado por [write_leaves.php:45-62](../public/admin/write_leaves.php))
- **Evidência:**
  ```php
  $fileExt = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
  $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
  if (!in_array($fileExt, $allowedExts)) { ... }
  ```
  Não usa `finfo_file()` para verificar MIME real. Storage em `public/attachments/leaves/` (dentro do webroot, mas com `.htaccess Deny from all` — verificado).
- **Impacto:** Atestado renomeado de `.php` para `.pdf` é aceito. Como o `.htaccess` em `public/attachments/` bloqueia execução, RCE direto é mitigado, mas defense-in-depth está fraca.
- **Recomendação:** Adicionar validação MIME com `finfo`:
  ```php
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  if (!in_array($finfo->file($_FILES['attachment']['tmp_name']), $allowedMimes, true)) { ... }
  ```
- **Esforço:** S

### SEC-011 — CSP com `'unsafe-inline'` em script-src e style-src
- **Arquivo:** [public/admin/login.php:8](../public/admin/login.php)
- **Evidência:**
  ```
  Content-Security-Policy: ... script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' ...
  ```
- **Impacto:** XSS refletido (caso exista) consegue executar JavaScript. CSP perde 80% do valor.
- **Recomendação:** Substituir por nonces gerados por request (`'nonce-<bin2hex(random_bytes(16))>'`). Mover scripts inline para arquivo externo.
- **Esforço:** M

### SEC-012 — CSP global ausente no resto do sistema
- **Arquivo:** [config.php:39-47](../config.php)
- **Evidência:** Header `Content-Security-Policy` não é definido globalmente. Só em `public/admin/login.php` (e mesmo assim com `unsafe-inline`).
- **Impacto:** Páginas admin/colaborador sem CSP ficam totalmente expostas a XSS armazenado.
- **Recomendação:** Definir CSP em `config.php` com `default-src 'self'; img-src 'self' data:; ...` aplicado a todas as respostas HTML.
- **Esforço:** M

### SEC-013 — `Permissions-Policy` ausente para câmera/geolocalização
- **Arquivo:** [config.php:39-47](../config.php) (ausência confirmada via Grep)
- **Evidência:** Sistema usa `navigator.geolocation` e `MediaStream` (face capture), mas não restringe quais frames podem usar.
- **Impacto:** Iframe malicioso embutido (se `X-Frame-Options` falhar em algum endpoint) pode acionar câmera/geo do usuário.
- **Recomendação:** `header('Permissions-Policy: camera=(self), geolocation=(self), microphone=()');`
- **Esforço:** S

### SEC-014 — `composer.json` e `composer.lock` acessíveis via HTTP
- **Arquivos:** `composer.json`, `composer.lock` na raiz; sem regra `.htaccess` que os bloqueie.
- **Evidência:** `.htaccess` raiz só faz rewrite para `public/`, mas `composer.json` na raiz é servido diretamente pelo Apache.
- **Impacto:** Enumeração de versões (dompdf 3.1, phpoffice 1.30) habilita ataque dirigido a CVEs conhecidas.
- **Recomendação:** Adicionar `<FilesMatch "^composer\.(json|lock)$"> Deny from all </FilesMatch>` no `.htaccess` raiz.
- **Esforço:** S

### SEC-015 — `logs/php_errors.log` sem `.htaccess`
- **Arquivos:** `logs/` (sem `.htaccess` — confirmado por `cat`)
- **Evidência:** [config.php](../config.php) configura `error_log` em `__DIR__ . '/../logs/php_errors.log'`. Diretório `logs/` não tem `.htaccess` próprio.
- **Impacto:** Stack traces (paths absolutos, variáveis em algumas exceções) acessíveis via `/logs/php_errors.log` se servidor expor.
- **Recomendação:** Criar `logs/.htaccess` com `Deny from all`.
- **Esforço:** S

### SEC-016 — `sql/` (migrations) sem `.htaccess`
- **Arquivos:** `sql/install/*.sql`, `sql/migrations/*.sql`
- **Evidência:** Sem `.htaccess` em `sql/`. Embora o rewrite raiz mande tudo para `public/`, arquivos `.sql` em `sql/` raiz são servidos diretamente porque o rewrite é "fallback" e Apache serve arquivos existentes antes.
- **Impacto:** Enumeração de schema (nomes de tabelas, colunas, índices) habilita SQLi mais dirigida.
- **Recomendação:** Criar `sql/.htaccess` com `Deny from all` ou mover `sql/` para fora do webroot.
- **Esforço:** S

### COMPLY-003 — HLB (Hora Legal Brasileira) não sincroniza via NTP real
- **Cláusula:** Portaria 671 Anexo — drift máximo 100ms, sincronização periódica.
- **Status:** ⚠️ Parcial
- **Arquivos:** [config.php:100-102](../config.php) (constantes), [api/get_server_time.php:1-26](../api/get_server_time.php)
- **Evidência:** `HLB_NTP_SERVER='a.st1.ntp.br'` definido mas `get_server_time.php` apenas retorna `date('Y-m-d\TH:i:s.uP')` — NTP não é consultado. Frontend usa WorldTimeAPI (HTTP, não NTP). `HLB_MAX_OFFSET_SECONDS=120` (120 s = 1200x mais permissivo que 100 ms exigidos).
- **Impacto:** Compliance teórico, sem sincronismo real. Marcações em servidor sem NTP podem derivar minutos.
- **Recomendação:** Implementar cliente NTP UDP (porta 123). Validar drift contra 100 ms. Falhar safe se exceder.
- **Esforço:** L

### COMPLY-004 — NSR usa `SELECT MAX+1` com retry, não tabela de sequência
- **Cláusula:** Anexos Portaria 671 — NSR único, sequencial, sem buracos.
- **Status:** ⚠️ Parcial
- **Arquivo:** [api/checkin.php:1408-1449](../api/checkin.php)
- **Evidência:**
  ```php
  $stmtNsr = $pdo->query("SELECT COALESCE(MAX(nsr), 0) + 1 as next_nsr FROM attendance");
  ```
  Tabela `nsr_sequence` foi criada em `sql/install/install_portaria_671.sql:30` mas é ignorada. Há retry 3x (linhas 1423-1495) para colidir com UNIQUE, mas não previne lacunas em concorrência alta.
- **Impacto:** Sob carga concorrente, dois INSERTs podem calcular o mesmo NSR; um falha e o número fica não-sequencial.
- **Recomendação:** Usar `nsr_sequence` com transação `SERIALIZABLE` ou stored procedure com `FOR UPDATE`.
- **Esforço:** M

### COMPLY-005 — Audit log captura PII em claro
- **Cláusula:** LGPD art. 6º (minimização) cruzando Portaria 671.
- **Status:** ⚠️ Parcial
- **Arquivos:** `audit_logs.payload`, [public/admin/attendance_edit.php:226-239](../public/admin/attendance_edit.php)
- **Evidência:** Campo `reason` (motivo da edição) e `payload` JSON podem conter texto livre com nome/CPF se admin preencher.
- **Impacto:** Logs retidos por 5 anos com dados sensíveis em claro.
- **Recomendação:** Codificar `reason` como enum; criptografar `payload` em repouso (AES-256 por chave KMS); mascarar CPF em relatórios.
- **Esforço:** L

### BUG-002 — `clear_service_worker.php` apaga IndexedDB sem confirmar pendências — ✅ Fixed (2026-04-26)
- **Arquivo:** [public/admin/clear_service_worker.php:112-128](../public/admin/clear_service_worker.php)
- **Evidência:** Loop `if (db.name && db.name.includes('ponto')) await indexedDB.deleteDatabase(db.name)` sem checagem prévia de itens pendentes na fila.
- **Impacto:** Admin clica "limpar SW" e perde pontos offline ainda não sincronizados, sem audit log do que foi descartado. Violação Portaria 671 (perda de marcação).
- **Recomendação:** Antes de deletar IDB, listar `pending` e exigir confirmação explícita ("Você tem N pontos não sincronizados — continuar?"). Logar em `audit_logs` a lista de `client_id` descartados.
- **Esforço:** M

### BUG-003 — Sessão expirada em background sync deixa item órfão
- **Arquivos:** [public/sw.js:451-462](../public/sw.js), [public/index.php:6440-6442](../public/index.php)
- **Evidência:** SW emite `postMessage('SESSION_EXPIRED')`, mas o item permanece em IDB sem flag `requires_relogin`. Após `MAX_AGE_MS=7 dias`, é descartado.
- **Impacto:** Perda silenciosa de ponto se colaborador não relogar dentro de 7 dias.
- **Recomendação:** Marcar como `requires_relogin`; após relogin, disparar resync; logar descarte em `audit_logs`.
- **Esforço:** M

### BUG-004 — `MAX_RETRY=5` + `MAX_AGE_MS=7d` descarta ponto sem auditoria
- **Arquivo:** [public/index.php:6221, 6274, 6345](../public/index.php)
- **Evidência:** `console.warn` descarta após exceder retries ou idade. Sem `reportDiscardedOffline()` confiável (BUG-007 abaixo).
- **Impacto:** Pontos perdidos em rede instável de 7 dias = violação Portaria 671.
- **Recomendação:** Aumentar para `MAX_RETRY=10`, `MAX_AGE_MS=30 dias`. Apenas descartar em `PERMANENT_ERRORS`. Sempre auditar via endpoint dedicado.
- **Esforço:** S

### BUG-005 — `checkin_bulk.php` não verifica `client_id` para dedup — ✅ Fixed (2026-04-26)
- **Arquivo:** [api/checkin_bulk.php:22-316](../api/checkin_bulk.php)
- **Evidência:** `process_check_item()` não recebe nem valida `client_id`. Em contraste, [api/checkin.php:361](../api/checkin.php) faz dedup. Bulk pode duplicar pontos se SW reenviar.
- **Impacto:** Marcação duplicada em alta concorrência ou retry pós-timeout.
- **Recomendação:** Receber `client_id` em cada item; consultar `WHERE client_id=? AND teacher_id=?` antes de inserir; retornar `already_registered` (200) para idempotência.
- **Esforço:** M

### BUG-006 — Stored XSS via `innerHTML` em modal de leaves — ✅ Fixed (2026-04-26)
- **Arquivo:** [public/admin/leaves.php:558-569](../public/admin/leaves.php)
- **Evidência:**
  ```js
  content.innerHTML = `... ${d.description} ... ${d.notes} ...`
  ```
  Campos `description` e `notes` vêm do banco sem `esc()`/`textContent`.
- **Impacto:** Admin que cadastra atestado pode injetar JS executado por outros admins ao abrir o modal.
- **Recomendação:** Usar `textContent` ou DOMPurify; nunca interpolar HTML do servidor sem sanitização.
- **Esforço:** S

### BUG-007 — Audit de descartes retorna 200 mesmo em falha de INSERT — ✅ Fixed (2026-04-26)

> Nota pós-correção: a análise original alegou que o `fetch` era chamado sem `await`. Verificação direta mostrou que o `await` já existia ([public/index.php:6434](../public/index.php)). O bug real é o backend retornar 200 em exceção e o frontend só fazer fallback em 401/403. Ambos foram corrigidos.
- **Arquivos:** [public/index.php:6431-6453](../public/index.php), [api/audit_discarded_offline.php:89](../api/audit_discarded_offline.php)
- **Evidência:** `reportDiscardedOffline()` não é aguardado e o backend retorna 200 mesmo em falha de INSERT (apenas `error_log`).
- **Impacto:** Auditoria de descartes perde eventos. Compliance Portaria 671 cai.
- **Recomendação:** Aguardar a chamada e implementar fallback em `localStorage` (parcialmente existe — `PONTO_AUDIT_FALLBACK_KEY`); reenviar quando online.
- **Esforço:** M

### BUG-008 — `checkin_bulk.php` precache HTML invalida CSRF — ✅ Fixed (2026-04-26)
- **Arquivo:** [public/sw.js:22-95](../public/sw.js)
- **Evidência:** SW precacheia `./index.php` no install, congelando CSRF token genérico.
- **Impacto:** Hard refresh pós-login pode usar HTML cacheado com token vazio; primeiro `checkin_bulk` falha 403.
- **Recomendação:** Não precachear `index.php`; usar Network-First para HTML dinâmico.
- **Esforço:** S

### ARCH-001 — `run_auto_migrations()` em toda requisição HTTP
- **Arquivo:** [config.php:132](../config.php), [helpers.php:24-221](../helpers.php)
- **Evidência:** Chamada incondicional no bootstrap. Há advisory lock e cache estático, mas o overhead acontece sempre. Erros 1050/1060/1062 são silenciosamente tolerados, podendo mascarar drift.
- **Impacto:** Em produção, cada login/checkin paga custo de verificar estado de migrations. Falhas silenciosas degradam schema sem alerta.
- **Recomendação:** Adicionar `if (APP_ENV !== 'production') run_auto_migrations();`. Em produção, rodar migrations via CLI no deploy.
- **Esforço:** S

---

## 5. Findings Medium

| ID | Título | Arquivo | Esforço |
|---|---|---|---|
| SEC-017 | Logout admin não chama `session_destroy()` (apenas unset parcial) | [helpers.php:877-881](../helpers.php) | S |
| SEC-018 | Cookie `Secure` falso em HTTP local — risco em proxy reverso mal configurado | [config.php:14-28](../config.php) | S |
| SEC-019 | CPF mascarado em `$_SESSION['collaborator_cpf_masked']` (PII em sessão) | [helpers.php:464](../helpers.php) | S |
| SEC-020 | Logout colaborador via GET sem CSRF | (ausente — `public/logout.php` não existe) | S |
| SEC-021 | Device fingerprint client-side com 32-bit hash (FNV-1a) | [public/login.php:519-537](../public/login.php) | S |
| SEC-022 | PIN brute-force window (10 tentativas/5min) tolera PIN de 4 dígitos | [helpers.php:429](../helpers.php) | S |
| SEC-023 | Hash de comprovante é SHA-256 truncado (16 chars), não assinatura digital | [public/receipt.php:135-138](../public/receipt.php) | M |
| SEC-024 | HSTS apenas se `$_SERVER['HTTPS'] === 'on'` (perde proxies que setam X-Forwarded-Proto) | [config.php:41-42](../config.php) | S |
| SEC-025 | `SYSTEM_VERSION` exposto em respostas públicas de `get_receipt.php` | [api/get_receipt.php:106](../api/get_receipt.php) | S |
| SEC-026 | `APP_DEBUG` hardcoded em config.php (não veio de env var) | [config.php:66](../config.php) | S |
| COMPLY-006 | Direito ao esquecimento (LGPD) não tem endpoint de soft-delete | (ausente) | M |
| BUG-009 | Colisão `client_id` rara em multi-aba — fallback de gerador não-UUID | [public/index.php:6207-6214](../public/index.php) | S |
| BUG-010 | `hlbOffsetSeconds` capturado no momento do registro (DST quebra) | [public/index.php:6216](../public/index.php) | M |
| BUG-011 | Bateria crítica durante `savePending()` pode deixar txn IDB pela metade | [public/index.php:5918-5930](../public/index.php) | S |
| BUG-012 | `BUGFIX_CONSOLE_ERRORS.md` documenta IDB v2, código usa v3 (doc desatualizada) | [docs/BUGFIX_CONSOLE_ERRORS.md:20](BUGFIX_CONSOLE_ERRORS.md), [public/index.php:5830](../public/index.php) | S |
| ARCH-002 | `add_pending_reasons.sql` + `add_pending_reasons_simple.sql` (par redundante) | sql/migrations/ | S |
| ARCH-003 | `add_photo_deleted_flag.sql` + `add_photo_deleted_flag_simple.sql` (par redundante) | sql/migrations/ | S |
| ARCH-004 | Tabela `antifraud_config` criada e nunca usada em PHP | sql/install/install_production_complete.sql | S |
| ARCH-005 | `helpers.php` god-file (2571 linhas, 86 funções) | [helpers.php](../helpers.php) | L |
| ARCH-006 | Funções `validate_cpf` e `validar_cpf` duplicadas | [helpers.php:635, 788](../helpers.php) | S |
| ARCH-007 | `dashboard_full_with_charts.php.bak` em `public/admin/` | [public/admin/dashboard_full_with_charts.php.bak](../public/admin/dashboard_full_with_charts.php.bak) | S |
| QUAL-001 | Double-escape em template PDF de relatório — ✅ Fixed (2026-04-26) | [public/admin/_tpl_reports_pdf.php:66-72](../public/admin/_tpl_reports_pdf.php), [public/admin/_tpl_teacher_monthly_report_pdf.php:80-86](../public/admin/_tpl_teacher_monthly_report_pdf.php) | S |

---

## 6. Findings Low

| ID | Título | Arquivo |
|---|---|---|
| SEC-027 | Comentário em `cron_photo_cleanup.php` revela path "/caminho/para/ponto_ribeira2" (placeholder educativo) | [cron_photo_cleanup.php:11-14](../cron_photo_cleanup.php) |
| SEC-028 | Constantes `ADMIN_SESSION_TIMEOUT`/`COLLABORATOR_SESSION_TIMEOUT` definidas mas nunca usadas (Grep retorna 0) | [config.php:69-70](../config.php) |
| QUAL-002 | CDNs externas (cdn.jsdelivr.net) sem `integrity="sha384-..."` (SRI) | [public/admin/login.php:83](../public/admin/login.php) |
| ARCH-008 | Migrations `add_liveness_columns.sql` vs `add_liveness_nonce_dedup.sql` — não redundantes mas nomes confusos | sql/migrations/ |
| ARCH-009 | `tools/legacy/` com 14 scripts antigos (já protegido por `.htaccess`, é apenas clutter) | [public/admin/tools/legacy/](../public/admin/tools/legacy/) |

---

## 7. Compliance — Portaria 671/2021

| Requisito | Status | Gap | Recomendação |
|---|---|---|---|
| NSR sequencial e imutável | ⚠️ Parcial | `SELECT MAX+1` race-prone; tabela `nsr_sequence` ignorada | Usar tabela com lock ou stored procedure |
| AFD (Arquivo Fonte de Dados) | ❌ Ausente | Nenhum exportador | Implementar conforme Anexo |
| ACJEF | ❌ Ausente | Nenhum exportador | Implementar conforme Anexo |
| HLB / NTP sync 100ms | ⚠️ Parcial | Frontend usa WorldTimeAPI (HTTP); backend só `date()` | Cliente NTP UDP real |
| Comprovante com hash/assinatura | ⚠️ Parcial | SHA-256 truncado, sem ICP-Brasil | Assinatura PAdES com cert digital |
| Imutabilidade de marcações | ❌ Falho | UPDATE em `attendance` permitido após aprovação | Travar registros aprovados; correção = novo registro |
| Audit log de operações | ✅ Implementado (cobertura) / ⚠️ PII | Eventos cobertos; `payload` tem PII em claro | Codificar `reason`; criptografar `payload` |
| Retenção de 5 anos | ✅ Implementado | `RECEIPT_RETENTION_YEARS=5` | OK |
| Direito ao esquecimento (LGPD) | ❌ Ausente | Sem endpoint de soft-delete pós-prazo | Implementar job de anonymization |

**Veredicto:** Sistema declara `PORTARIA_671_COMPLIANT=true` mas atende ~40% dos requisitos. Recomenda-se ou implementar gaps Critical (AFD, ACJEF, imutabilidade, NTP) ou alterar a flag para `false` até a implementação chegar.

---

## 8. Quick wins (≤30 min cada)

Itens com ✅ já foram aplicados na rodada de 2026-04-26 (ver §0). Os abertos abaixo continuam pendentes.

1. **Apagar `test_output.php`** (SEC-003) — `git rm test_output.php`. ❌ Aberto
2. **Apagar ou mover `public/admin/write_leaves.php`** (SEC-001) — para `bin/` ou remover. ❌ Aberto
3. **Apagar `public/admin/dashboard_full_with_charts.php.bak`** (ARCH-007). ❌ Aberto
4. **Adicionar `require_admin()` em `clear_opcache.php`** (SEC-005). ❌ Aberto
5. ~~**Adicionar `csrf_verify()` no início de `api/checkin_bulk.php`** (SEC-002).~~ ✅ Fixed
6. **Criar `logs/.htaccess` e `sql/.htaccess` com `Deny from all`** (SEC-015, SEC-016). ❌ Aberto
7. **Adicionar bloqueio de `composer.json`/`composer.lock` no `.htaccess` raiz** (SEC-014). ❌ Aberto
8. **Adicionar `Permissions-Policy` em `config.php`** (SEC-013). ❌ Aberto
9. **Mudar `ensure_default_admin()` para nunca rodar (ou deletar a função)** (SEC-009). ❌ Aberto
10. **Alterar `PORTARIA_671_COMPLIANT` para `false`** até gaps Critical serem fechados (COMPLY-001 e -002). ❌ Aberto

---

## 9. Anexos

### 9.1 Inventário de endpoints

**Endpoints anônimos (sem auth):**
- [api/get_server_time.php](../api/get_server_time.php) — leitura, OK
- [api/checkin.php](../api/checkin.php) — mutação (CORS refletido + sem CSRF)
- [api/checkin_bulk.php](../api/checkin_bulk.php) — mutação (sem CSRF, sem session)
- [api/identify_face.php](../api/identify_face.php) — leitura biométrica
- [api/pin_recover.php](../api/pin_recover.php) — mutação (PIN reset)
- [api/pin_enroll.php](../api/pin_enroll.php) — mutação (PIN inicial)
- [api/last_checkin.php](../api/last_checkin.php) — leitura (CPF via GET)
- [api/self_enroll_face.php](../api/self_enroll_face.php) — mutação (face descriptor)
- [test_output.php](../test_output.php) — DEBUG (a remover)
- [public/admin/clear_opcache.php](../public/admin/clear_opcache.php) — debug (a proteger)
- [public/admin/write_leaves.php](../public/admin/write_leaves.php) — gerador (a remover)

**Endpoints com auth de admin:**
- Todo `public/admin/*.php` que faz `require_admin()`. Sondagem confirmou que `attendance_edit.php`, `leaves.php`, `payroll.php`, `reports.php` chamam `require_admin()`.

**Endpoints com auth de colaborador:**
- [api/save_face.php](../api/save_face.php), [api/audit_stale_queue.php](../api/audit_stale_queue.php), [api/audit_discarded_offline.php](../api/audit_discarded_offline.php) — verificar `is_collaborator_logged()` é a regra.

### 9.2 Migrations redundantes ou suspeitas

| Arquivo | Observação |
|---|---|
| `add_pending_reasons.sql` | Versão 1, 13 linhas |
| `add_pending_reasons_simple.sql` | Duplicata, redundante (ARCH-002) |
| `add_photo_deleted_flag.sql` | Versão idempotente |
| `add_photo_deleted_flag_simple.sql` | Versão sem `IF NOT EXISTS` (frágil) |
| `add_liveness_columns.sql` | Adiciona colunas em `attendance` |
| `add_liveness_nonce_dedup.sql` | Cria tabela `liveness_nonces` (não redundante, só nome confuso) |
| `update_face_thresholds_hardened.sql` | Subtabela settings de face |
| `set_face_thresholds_3rd_layer.sql` | Idem (provável drift) |
| `harden_face_recognition_v3.sql` | Idem |

Total de 30 migrations; 2 pares redundantes confirmados; pelo menos 3 migrations de "thresholds de face" que provavelmente sobrescrevem-se.

### 9.3 Mapa funcional de `helpers.php` (alta-nível, 86 funções, 2571 linhas)

| Domínio | Funções aproximadas | Notas |
|---|---|---|
| Auth/Session/CSRF | ~25 | `admin_login_by_cpf`, `collaborator_login_by_pin`, `csrf_verify`, `require_admin`, `require_collaborator`, `pin_hash`, `pin_verify`, `auth_attempt_*`, `trusted_device_*`, `is_admin_logged`, `is_collaborator_logged`, `ensure_default_admin` |
| Migrations / DB | ~5 | `run_auto_migrations`, `db()`, settings KV |
| Face recognition | ~7 | matching, thresholds, deduplicação |
| Payroll/Overtime | ~18 | cálculo de horas extras, banco de horas |
| Attendance/Schedule | ~12 | jornada, períodos, calendário |
| Audit/Logs | ~6 | `audit_log`, `log_attendance_audit` |
| Util / CPF | ~13 | `validate_cpf`, `validar_cpf`, `mask_cpf`, `esc`, `is_https_request`, build URL, etc. |

Recomendação de refactor: separar em `helpers/auth.php`, `helpers/payroll.php`, `helpers/face.php`, `helpers/util.php`. Manter `helpers.php` como agregador.

### 9.4 Comandos de reprodução para findings High+

```bash
# SEC-001 — write_leaves.php
curl -i http://localhost/ponto_ribeira/admin/write_leaves.php
# Esperado: 200 com escrita real em disco. Verificar mtime de leaves.php após.

# SEC-002 — checkin_bulk sem CSRF
curl -i -X POST -H "Content-Type: application/json" \
  -d '{"items":[{"cpf":"12345678900","pin":"1234","geo":{"lat":-23,"lng":-46}}]}' \
  http://localhost/ponto_ribeira/api/checkin_bulk.php
# Esperado: 200 (deveria ser 403)

# SEC-003 — test_output exposto
curl -i http://localhost/ponto_ribeira/test_output.php
# Esperado: 200 com bytes hex de qualquer output do config.php

# SEC-004 — CORS refletido
curl -i -H "Origin: https://attacker.com" \
  -X OPTIONS http://localhost/ponto_ribeira/api/checkin.php
# Esperado: header "Access-Control-Allow-Origin: https://attacker.com"

# COMPLY-001 — AFD/ACJEF ausentes
find . -name 'afd*.php' -o -name 'acjef*.php' 2>/dev/null
# Esperado: nada
```

### 9.5 Bugfixes documentados — status

| Doc | Status |
|---|---|
| `BUGFIX_CSRF_LOGIN.md` | ✅ Implementado em `helpers.php:294` (aceita `csrf` e `csrf_token`) |
| `BUGFIX_CSRF_TOKEN.md` | ✅ Implementado em `sw.js` v2.42.0 na rodada 2026-04-26 (BUG-001) |
| `BUGFIX_CONSOLE_ERRORS.md` | ⚠️ Doc fala de IDB v2; código usa v3 (doc desatualizada — BUG-012) |

---

**Fim do relatório.**

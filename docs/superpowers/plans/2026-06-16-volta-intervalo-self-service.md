# Informar volta do intervalo (self-service) — Implementation Plan

> **For agentic workers:** implement task-by-task. Steps use `- [ ]`. Este projeto NÃO tem framework de teste (PHPUnit/pytest); a verificação é por **script PHP standalone** (padrão de `tests/`) + checagem manual. **Não commitar** salvo pedido explícito do usuário (regra do harness); o repo já tem muitos arquivos não rastreados.

**Goal:** Permitir que o colaborador informe, de forma direta e auditada, a hora real em que voltou de um intervalo aberto (esquecido), na tela de bater ponto e na Minha Folha.

**Architecture:** Uma ação dedicada que fecha um registro de intervalo aberto (`record_type='break'`, `check_out IS NULL`) numa hora informada. Backend: helper `close_own_open_break()` + endpoint `api/close_break.php`. Frontends chamam o endpoint. Reusa `recalculate_hour_bank_for_attendance()` e `attendance_edits` (auditoria), espelhando `edit_own_break_direct()`.

**Tech Stack:** PHP 8 + MariaDB (XAMPP), JS vanilla (PWA em `index.php`), Bootstrap modal (Minha Folha).

---

## File Structure

- `helpers.php` — **add** `close_own_open_break()` (perto de `edit_own_break_direct`, ~linha 2749).
- `api/close_break.php` — **create** endpoint fino (espelha `api/edit_own_break.php`).
- `public/my_timesheet.php` — **modify**: oferecer "Informar volta" em intervalos abertos (reusa o `fixBreakModal` com modo `close_open`).
- `public/index.php` — **modify**: no banner "em intervalo" (~linha 12290), link "Informar a hora que voltei" + mini-modal que chama o endpoint.
- `public/sw.js` — **modify**: bump de `CACHE_VERSION`.
- `tests/test_close_open_break.php` — **create** script de verificação.

---

## Task 1: Backend — helper `close_own_open_break()`

**Files:** Modify `helpers.php` (inserir após `edit_own_break_direct`, antes de `create_break_regularization_request` ~linha 2750).

- [ ] **Step 1: Implementar o helper**

```php
/**
 * Fecha um intervalo ABERTO (record_type='break', check_out IS NULL) informando a
 * hora real da volta. Self-service do colaborador (sem aprovação), auditado.
 * Diferente de edit_own_break_direct: atua SÓ em intervalo aberto e aceita dia
 * anterior dentro da janela (open_checkin_window_hours) — é o caso "esqueci de
 * registrar a volta". Justificativa opcional (padrão fixo) para reduzir fricção.
 *
 * @param string $returnInput 'HH:MM', 'HH:MM:SS' ou 'YYYY-MM-DD HH:MM[:SS]'
 *   (time-only é combinado com a DATA de início do intervalo no servidor).
 * @return array{success:bool,message:string}
 */
function close_own_open_break(PDO $pdo, int $attendanceId, int $teacherId, string $returnInput, string $note = ''): array {
    $note = trim($note);
    if ($note === '') $note = 'Volta de intervalo informada pelo colaborador';
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, teacher_id, date, check_in, check_out, record_type
                             FROM attendance WHERE id = ? FOR UPDATE");
        $st->execute([$attendanceId]);
        $att = $st->fetch(PDO::FETCH_ASSOC);
        if (!$att) { $pdo->rollBack(); return ['success'=>false,'message'=>'Registro de intervalo nao encontrado']; }
        if ((int)$att['teacher_id'] !== $teacherId) { $pdo->rollBack(); return ['success'=>false,'message'=>'Voce nao pode alterar registro de outro colaborador']; }
        if (($att['record_type'] ?? 'work') !== 'break') { $pdo->rollBack(); return ['success'=>false,'message'=>'Este registro nao eh um intervalo']; }
        if (!empty($att['check_out'])) { $pdo->rollBack(); return ['success'=>false,'message'=>'Este intervalo ja foi fechado']; }
        if (empty($att['check_in'])) { $pdo->rollBack(); return ['success'=>false,'message'=>'Intervalo sem inicio registrado']; }

        // Resolve a hora da volta: time-only combina com a DATA de inicio do intervalo.
        $breakDate = substr((string)$att['check_in'], 0, 10);
        if (preg_match('/^\d{2}:\d{2}$/', $returnInput))          $returnDateTime = $breakDate . ' ' . $returnInput . ':00';
        elseif (preg_match('/^\d{2}:\d{2}:\d{2}$/', $returnInput)) $returnDateTime = $breakDate . ' ' . $returnInput;
        elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $returnInput))    $returnDateTime = $returnInput . ':00';
        elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $returnInput)) $returnDateTime = $returnInput;
        else { $pdo->rollBack(); return ['success'=>false,'message'=>'Formato de horario invalido']; }

        $startTs = strtotime((string)$att['check_in']);
        $retTs   = strtotime($returnDateTime);
        if ($retTs <= $startTs) { $pdo->rollBack(); return ['success'=>false,'message'=>'A volta deve ser depois do inicio do intervalo (' . date('H:i', $startTs) . ').']; }
        if ($retTs > time())    { $pdo->rollBack(); return ['success'=>false,'message'=>'A volta nao pode estar no futuro.']; }

        // Janela sa: intervalo nao pode ter comecado ha mais que open_checkin_window_hours.
        $windowH = (int)(get_setting('open_checkin_window_hours', '30') ?? '30');
        if ($startTs < time() - $windowH * 3600) {
            $pdo->rollBack();
            return ['success'=>false,'message'=>'Intervalo muito antigo para correcao direta. Use "Solicitar correcao" (passa pelo admin).'];
        }

        $beforeJson = json_encode(['check_in'=>$att['check_in'],'check_out'=>null,'record_type'=>'break'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("UPDATE attendance SET check_out = ?, editado_por = NULL, data_edicao = NOW(), motivo_edicao = ? WHERE id = ?")
            ->execute([$returnDateTime, '[colaborador] ' . $note, $attendanceId]);
        $afterJson = json_encode(['check_in'=>$att['check_in'],'check_out'=>$returnDateTime,'record_type'=>'break'], JSON_UNESCAPED_UNICODE);

        $pdo->prepare("INSERT INTO attendance_edits (attendance_id, edited_by, edited_at, reason, type, before_json, after_json)
                       VALUES (?, NULL, NOW(), ?, 'break_return_self', ?, ?)")
            ->execute([$attendanceId, $note, $beforeJson, $afterJson]);

        recalculate_hour_bank_for_attendance($pdo, $attendanceId);
        audit_log('update','attendance',$attendanceId,['action'=>'self_close_break','teacher_id'=>$teacherId,'return'=>$returnDateTime]);

        $pdo->commit();
        return ['success'=>true,'message'=>'Volta do intervalo registrada com sucesso'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success'=>false,'message'=>'Erro ao registrar volta: ' . $e->getMessage()];
    }
}
```

- [ ] **Step 2: Lint** — `/c/xampp/php/php.exe -l helpers.php` → "No syntax errors".

---

## Task 2: Backend — endpoint `api/close_break.php`

**Files:** Create `api/close_break.php` (espelha `api/edit_own_break.php`).

- [ ] **Step 1: Criar o arquivo**

```php
<?php
/**
 * API: Informar volta do intervalo (fecha um intervalo ABERTO numa hora informada).
 * Self-service do colaborador, auditado. POST JSON/form: { attendance_id, return_time, justification?, csrf }
 * return_time: 'HH:MM' (combinado com a data do intervalo) ou 'YYYY-MM-DD HH:MM[:SS]'.
 */
ini_set('display_errors', '0'); error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function cb_error(int $http, string $code, string $message): void {
    if (ob_get_level()) ob_clean();
    http_response_code($http);
    echo json_encode(['ok'=>false,'error_code'=>$code,'message'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cb_error(405, 'method_not_allowed', 'Apenas POST eh aceito.');
if (!is_collaborator_logged()) cb_error(401, 'auth_required', 'Faca login como colaborador.');

$raw = file_get_contents('php://input') ?: ''; $payload = [];
if ($raw !== '') { $d = json_decode($raw, true); if (is_array($d)) $payload = $d; }
if (empty($payload)) $payload = $_POST;

csrf_verify(((string)($payload['csrf'] ?? $payload['csrf_token'] ?? '')) ?: null);

$attendanceId = (int)($payload['attendance_id'] ?? 0);
$returnTime   = trim((string)($payload['return_time'] ?? $payload['check_out'] ?? ''));
$note         = trim((string)($payload['justification'] ?? $payload['note'] ?? ''));

if ($attendanceId <= 0) cb_error(400, 'invalid_attendance', 'ID do intervalo invalido.');
if ($returnTime === '') cb_error(400, 'missing_time', 'Informe a hora que voce voltou.');

$teacherId = (int)($_SESSION['collaborator_id'] ?? 0);
if ($teacherId <= 0) cb_error(401, 'auth_required', 'Sessao expirada. Faca login novamente.');

$returnTime = str_replace('T', ' ', $returnTime);

$pdo = db();
try { $r = close_own_open_break($pdo, $attendanceId, $teacherId, $returnTime, $note); }
catch (Throwable $e) { cb_error(500, 'server_error', 'Erro ao registrar volta: ' . $e->getMessage()); }

if (empty($r['success'])) cb_error(409, 'not_eligible', $r['message']);
if (ob_get_level()) ob_clean();
echo json_encode(['ok'=>true, 'message'=>$r['message']], JSON_UNESCAPED_UNICODE);
```

- [ ] **Step 2: Lint** — `/c/xampp/php/php.exe -l api/close_break.php` → "No syntax errors".

---

## Task 3: Verificação backend (script standalone)

**Files:** Create `tests/test_close_open_break.php` (padrão do `tests/`, transação + rollback). Requer o banco local funcional (`u803039033_p_oeiras_p`).

- [ ] **Step 1: Escrever o teste** cobrindo: (a) fecha intervalo aberto do mesmo dia com `HH:MM` → check_out setado; (b) rejeita volta no futuro; (c) rejeita volta antes do início; (d) rejeita intervalo já fechado; (e) rejeita registro que não é break. Usa `db()`, cria type/teacher/attendance break aberto, chama `close_own_open_break`, faz asserts, dá rollback.

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
$pdo = db(); $pass=0;$fail=0;
function ck(string $l,bool $c,string $d=''){global $pass,$fail; if($c){$pass++;echo "  [OK]   $l\n";}else{$fail++;echo "  [FAIL] $l".($d?" — $d":"")."\n";}}
$pdo->beginTransaction();
try {
  $pdo->prepare("INSERT INTO collaborator_types (name,slug,schedule_mode,requires_schedule) VALUES ('__t_cb','__t_cb','time',1)")->execute();
  $typeId=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO teachers (name,cpf,type_id,active) VALUES ('__t_cb_user','00000000034',?,1)")->execute([$typeId]);
  $tid=(int)$pdo->lastInsertId();
  $today=date('Y-m-d'); $start="$today 12:00:00";
  $mk=function($checkOut) use($pdo,$tid,$today,$start){ $pdo->prepare("INSERT INTO attendance (teacher_id,date,check_in,check_out,record_type,approved,method) VALUES (?,?,?,?,'break',1,'manual')")->execute([$tid,$today,$start,$checkOut]); return (int)$pdo->lastInsertId(); };

  $id=$mk(null);
  $r=close_own_open_break($pdo,$id,$tid,'12:45'); ck('fecha intervalo aberto (12:45)',$r['success'],$r['message']);
  $co=$pdo->query("SELECT check_out FROM attendance WHERE id=$id")->fetchColumn();
  ck('check_out setado p/ 12:45',substr((string)$co,11,5)==='12:45',(string)$co);

  $id2=$mk(null);
  $r=close_own_open_break($pdo,$id2,$tid,date('H:i',time()+3600)); ck('rejeita futuro',!$r['success'],$r['message']);
  $r=close_own_open_break($pdo,$id2,$tid,'11:30'); ck('rejeita antes do inicio',!$r['success'],$r['message']);

  $id3=$mk("$today 12:30:00");
  $r=close_own_open_break($pdo,$id3,$tid,'12:50'); ck('rejeita intervalo ja fechado',!$r['success'],$r['message']);

  $pdo->prepare("INSERT INTO attendance (teacher_id,date,check_in,check_out,record_type,approved,method) VALUES (?,?,?,NULL,'work',1,'manual')")->execute([$tid,$today,$start]);
  $idW=(int)$pdo->lastInsertId();
  $r=close_own_open_break($pdo,$idW,$tid,'12:50'); ck('rejeita registro work',!$r['success'],$r['message']);

  echo "\nPassed: $pass  Failed: $fail\n";
  $pdo->rollBack(); exit($fail>0?1:0);
} catch (Throwable $e) { $pdo->rollBack(); echo "EXCEPTION: ".$e->getMessage()."\n"; exit(2); }
```

- [ ] **Step 2: Rodar** — `/c/xampp/php/php.exe tests/test_close_open_break.php` (com MySQL no ar). Esperado: todos `[OK]`, `Failed: 0`.

---

## Task 4: Minha Folha — "Informar volta" para intervalo aberto

**Files:** Modify `public/my_timesheet.php` — bloco de render dos breaks (~870-934), `fixBreakModal` (~1186-1224) e JS do modal (~1274-1347).

- [ ] **Step 1:** No render de cada break, quando o break está **aberto** (`check_out` vazio) e dentro da janela, exibir botão **"Informar volta"** com `data-mode="close_open"`, `data-attendance-id`, `data-date`, `data-check-in`. (Botões de correção de break fechado: inalterados.)
- [ ] **Step 2:** No JS do `fixBreakModal`, tratar `mode==='close_open'`: título "Informar volta do intervalo"; **esconder/disable** o campo de início (`fbCheckIn`); exigir só o retorno (`fbCheckOut`); justificativa opcional; no submit, POST para `API_BASE + '/api/close_break.php'` com `{attendance_id, return_time: <fbCheckOut>, justification, csrf}`; on success → `location.reload()`.
- [ ] **Step 3: Lint** — `/c/xampp/php/php.exe -l public/my_timesheet.php`.

---

## Task 5: Tela de bater ponto — banner "em intervalo"

**Files:** Modify `public/index.php` — banner do estado `em_intervalo` (~12290-12305) + um mini-modal/handler novo. Usa o `data.last`/estado já carregado (id do break aberto + data/hora de início) e o `CSRF_TOKEN`/`API` do app.

- [ ] **Step 1:** Confirmar de onde vem o **id do intervalo aberto** e a **data de início** no payload de estado (state/last_checkin). Se o id não estiver disponível no payload, expor `open_id` (de `detect_collaborator_state`) na resposta do endpoint de estado.
- [ ] **Step 2:** No HTML do banner `em_intervalo`, acrescentar um link discreto: `Esqueceu de registrar a volta? <button type="button" id="btnInformReturn">Informar a hora que voltei</button>`.
- [ ] **Step 3:** Mini-modal (ou `prompt`/`<input type="time">` inline) que coleta a hora; ao confirmar, `fetch(API + '/api/close_break.php', {POST, JSON {attendance_id: <open break id>, return_time: <HH:MM>, csrf: CSRF_TOKEN}})`; on success → recarrega o estado (a saída deixa de ficar bloqueada). Tratar erro exibindo `message`.
- [ ] **Step 4: Lint** — `/c/xampp/php/php.exe -l public/index.php`.

---

## Task 6: Cache-bust do PWA

**Files:** Modify `public/sw.js` (linha 1).

- [ ] **Step 1:** Bump `CACHE_VERSION` para `v2.56.0` com comentário descritivo.

---

## Verificação final (manual, com MySQL no ar)

- [ ] Iniciar intervalo no app (índex), NÃO retornar → estado "em intervalo" mostra "Informar a hora que voltei"; informar 13:00 → intervalo fecha às 13:00; saída deixa de ser bloqueada.
- [ ] Em Minha Folha, um break aberto mostra "Informar volta"; preencher → fecha; recarrega refletindo no resumo do mês.
- [ ] `tests/test_close_open_break.php` → `Failed: 0`.
- [ ] Lint limpo em todos os arquivos tocados.

## Self-review (preenchido)
- **Cobertura do spec:** backend (T1/T2), Minha Folha (T4), bater ponto (T5), auditoria+banco (T1), testes (T3/final). ✓
- **Sem placeholders de código:** helper e endpoint têm código completo; T4/T5 descrevem integração no front com âncoras exatas (código de UI finalizado na implementação por depender de ler ~30 linhas de cada arquivo). 
- **Consistência de nomes:** `close_own_open_break(pdo, attendanceId, teacherId, returnInput, note)` e endpoint `api/close_break.php` usados de forma idêntica em T2/T4/T5. ✓

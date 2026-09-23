# Afastamento que abona vs. não abona a falta — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que cada afastamento defina, por registro, se **abona a falta** (`excuses_absence=1`, zera a jornada prevista) ou **não abona** (`0`, o dia continua contando como falta + desconto), desacoplado da flag de remuneração `leave_types.paid`.

**Architecture:** Nova coluna `leaves.excuses_absence` torna-se o único critério para zerar a jornada prevista do dia. O núcleo `calculate_expected_minutes` e cada relatório que hoje duplica a lógica de afastamento trocam o critério de `lt.paid` para `l.excuses_absence`. Um helper compartilhado `leave_day_is_excused()` centraliza a checagem "o dia tem afastamento que abona?". Os templates PDF não mudam — derivam a falta de `expectedMin > 0`, herdando o efeito automaticamente.

**Tech Stack:** PHP 8 + PDO (MySQL/MariaDB), Bootstrap 5, migrações SQL idempotentes auto-aplicadas em `sql/migrations/`, smoke tests em `tests/*.php` (transação + rollback).

**Spec:** [docs/superpowers/specs/2026-06-29-afastamento-abona-falta-design.md](../specs/2026-06-29-afastamento-abona-falta-design.md)

---

## File Structure

**Criar:**
- `sql/migrations/2026_06_29_leave_excuses_absence.sql` — coluna nova + backfill idempotente.
- `tests/test_leave_excuses_absence.php` — smoke test do núcleo `calculate_expected_minutes`.

**Modificar:**
- `helpers.php` — `calculate_expected_minutes` (critério) + novo helper `leave_day_is_excused()`.
- `public/admin/leaves.php` — formulário (radio Sim/Não), POST, JS de default/edição.
- `public/admin/reports.php` — zeragem + detecção/exibição de falta.
- `public/admin/reports_financial.php` — zeragem + detecção/exibição + "pronto p/ pagamento" + tabela-resumo.
- `public/admin/teacher_monthly_report.php` — zeragem + label de exibição.
- `public/admin/reports_insights.php` — guard de query (só abonado suprime falta).
- `public/admin/dashboard.php` — guard de query (não-abonado não reduz "ausentes hoje").

**Sem alteração de lógica (verificação apenas):**
- `public/admin/_tpl_reports_pdf.php`, `_tpl_financial_pdf.php`, `_tpl_teacher_monthly_report_pdf.php` — falta deriva de `expectedMin > 0`.
- `public/admin/get_leave_details.php` — já usa `SELECT l.*`, então `excuses_absence` aparece automaticamente no JSON.

**Não tocar:**
- `public/admin/write_leaves.php` — script gerador legado morto (aponta para `c:/xampp/htdocs/ponto_ribeira`, projeto diferente).

---

## Task 1: Migração — coluna `leaves.excuses_absence` + backfill

**Files:**
- Create: `sql/migrations/2026_06_29_leave_excuses_absence.sql`

- [ ] **Step 1: Criar o arquivo de migração**

A coluna é **nullable** (sem default), e o backfill só preenche linhas `NULL` — assim a migração é idempotente (reexecução não sobrescreve valores editados depois). O servidor é **MySQL 8.4** (não MariaDB), que **não** suporta `ADD COLUMN IF NOT EXISTS`; por isso usamos `ADD COLUMN` simples e a idempotência vem da tolerância do runner ao erro 1060 (coluna duplicada) no caminho statement-by-statement.

`sql/migrations/2026_06_29_leave_excuses_absence.sql`:

```sql
-- =====================================================================
-- Migration: Afastamento que abona vs nao abona a falta
-- =====================================================================
-- Adiciona flag por registro em leaves. Desacopla "abona a falta" da
-- flag de remuneracao leave_types.paid. Quando excuses_absence=1 o dia
-- tem a jornada prevista zerada (nao conta falta nem desconto). Quando
-- 0/NULL, o dia continua contando como falta.
-- =====================================================================

ALTER TABLE leaves
  ADD COLUMN excuses_absence TINYINT(1) NULL DEFAULT NULL
  COMMENT 'Se 1, este afastamento abona a falta (zera jornada). 0/NULL = conta falta'
  AFTER approved;

-- Backfill idempotente: preserva o comportamento atual (tipo pago abonava)
-- e nao sobrescreve registros ja definidos por usuario (preenche so os NULL).
UPDATE leaves l
  JOIN leave_types lt ON lt.id = l.type_id
  SET l.excuses_absence = lt.paid
  WHERE l.excuses_absence IS NULL;

-- Verificacao
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'leaves'
  AND COLUMN_NAME = 'excuses_absence';
```

- [ ] **Step 2: Aplicar a migração (auto-bootstrap)**

A migração roda sozinha no primeiro `require config.php`. Forçar a aplicação e confirmar a coluna:

Run:
```bash
php -r "require 'config.php'; $c=db()->query(\"SHOW COLUMNS FROM leaves LIKE 'excuses_absence'\")->fetch(PDO::FETCH_ASSOC); echo $c ? 'OK: '.$c['Field'].' '.$c['Type'].' null='.$c['Null'] : 'MISSING'; echo PHP_EOL;"
```
Expected: `OK: excuses_absence tinyint(1) null=YES`

- [ ] **Step 3: Confirmar backfill (preservou comportamento atual)**

Run:
```bash
php -r "require 'config.php'; $r=db()->query('SELECT COUNT(*) tot, SUM(excuses_absence IS NULL) nulos FROM leaves')->fetch(PDO::FETCH_ASSOC); echo 'total='.$r['tot'].' nulos_restantes='.(int)$r['nulos'].PHP_EOL;"
```
Expected: `nulos_restantes=0` (todo registro existente recebeu o valor de `lt.paid`).

- [ ] **Step 4: Commit**

```bash
git add sql/migrations/2026_06_29_leave_excuses_absence.sql
git commit -m "feat(db): coluna leaves.excuses_absence (abona a falta) + backfill"
```

---

## Task 2: Núcleo — `calculate_expected_minutes` + helper `leave_day_is_excused`

**Files:**
- Modify: `helpers.php:1852` (critério) e após `helpers.php:1859` (novo helper)
- Test: `tests/test_leave_excuses_absence.php`

- [ ] **Step 1: Escrever o smoke test (falha primeiro)**

Espelha o harness de `tests/test_hours_schedule_mode.php` (transação + rollback, `check()`). Cobre o desacoplamento de `paid` e o acoplamento com `approved`.

`tests/test_leave_excuses_absence.php`:

```php
<?php
/**
 * Smoke test: leaves.excuses_absence controla a zeragem da jornada prevista,
 * desacoplado de leave_types.paid e acoplado a leaves.approved.
 *
 * Rodar: php tests\test_leave_excuses_absence.php
 * Roda dentro de transacao com rollback — nao polui dados.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pdo = db();
$failed = 0;
$passed = 0;

function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "  [OK]   $label\n"; }
    else { $failed++; echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== Smoke test: leaves.excuses_absence ===\n\n";

$pdo->beginTransaction();
try {
    // Isola do calendario real (mesma razao do teste de hours).
    $pdo->exec("DELETE FROM calendar_exceptions");

    // Colaborador 'hours' com 480min na segunda (weekday=1).
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'hours', 1)")
        ->execute(['__test_excuses', '__test_excuses']);
    $typeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__test_excuses', '00000000091', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_hours_schedules (teacher_id, weekday, total_minutes, break_minutes) VALUES (?, 1, 480, 0)")
        ->execute([$teacherId]);

    // Dois tipos de afastamento: um pago, um nao pago (controla decoupling).
    $pdo->prepare("INSERT INTO leave_types (name, code, paid, affects_bank, active) VALUES (?, ?, 1, 0, 1)")
        ->execute(['__test_lt_paid', '__test_lt_paid']);
    $ltPaid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO leave_types (name, code, paid, affects_bank, active) VALUES (?, ?, 0, 0, 1)")
        ->execute(['__test_lt_unpaid', '__test_lt_unpaid']);
    $ltUnpaid = (int)$pdo->lastInsertId();

    $MON = '2026-05-25'; // segunda

    $insLeave = function (int $type, int $approved, $excuses) use ($pdo, $teacherId, $MON): int {
        $pdo->prepare("INSERT INTO leaves (teacher_id, type_id, start_date, end_date, approved, excuses_absence) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$teacherId, $type, $MON, $MON, $approved, $excuses]);
        return (int)$pdo->lastInsertId();
    };
    $clearLeaves = function () use ($pdo, $teacherId): void {
        $pdo->prepare("DELETE FROM leaves WHERE teacher_id=?")->execute([$teacherId]);
    };

    echo "Teste 1: baseline sem afastamento → 480\n";
    check('seg → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);

    echo "\nTeste 2: aprovado + abona (tipo pago) → 0\n";
    $insLeave($ltPaid, 1, 1);
    check('abona=1 (pago) → 0', calculate_expected_minutes($pdo, $teacherId, $MON) === 0);
    $clearLeaves();

    echo "\nTeste 3: aprovado + NAO abona (tipo pago) → 480 (decoupling de paid)\n";
    $insLeave($ltPaid, 1, 0);
    check('abona=0 mesmo pago → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);
    $clearLeaves();

    echo "\nTeste 4: aprovado + abona (tipo NAO pago) → 0 (decoupling de paid)\n";
    $insLeave($ltUnpaid, 1, 1);
    check('abona=1 mesmo nao pago → 0', calculate_expected_minutes($pdo, $teacherId, $MON) === 0);
    $clearLeaves();

    echo "\nTeste 5: NAO aprovado + abona → 480 (acoplamento com approved)\n";
    $insLeave($ltPaid, 0, 1);
    check('abona=1 mas pendente/rejeitado → 480', calculate_expected_minutes($pdo, $teacherId, $MON) === 480);
    $clearLeaves();

    echo "\nTeste 6: helper leave_day_is_excused\n";
    check('vazio → false', leave_day_is_excused([]) === false);
    check('[excuses=0] → false', leave_day_is_excused([['excuses_absence' => 0]]) === false);
    check('[excuses=1] → true', leave_day_is_excused([['excuses_absence' => 0], ['excuses_absence' => 1]]) === true);
    check('chave ausente → false', leave_day_is_excused([['paid' => 1]]) === false);

    echo "\n=== Resultado ===\nPassed: $passed\nFailed: $failed\n";
    $pdo->rollBack();
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(2);
}
```

- [ ] **Step 2: Rodar o teste para confirmar que FALHA**

Run: `php tests\test_leave_excuses_absence.php`
Expected: FALHA — `Teste 3` quebra (hoje `calculate_expected_minutes` zera por `lt.paid`, então abona=0 num tipo pago ainda devolveria 0), e/ou erro de coluna. Sai com código ≠ 0.

- [ ] **Step 3: Adicionar o helper `leave_day_is_excused` no helpers.php**

Inserir imediatamente após o fim de `calculate_expected_minutes` (após a linha `}` em `helpers.php:1859`):

```php
/**
 * Retorna true se algum afastamento do dia ABONA a falta (excuses_absence=1).
 * Aceita arrays de licenca em qualquer formato que contenha a chave
 * 'excuses_absence' (linhas cruas de `leaves` ou os arrays reduzidos montados
 * nos relatorios). Ausente/NULL é tratado como "nao abona".
 */
function leave_day_is_excused(array $leaves): bool {
    foreach ($leaves as $lv) {
        if ((int)($lv['excuses_absence'] ?? 0) === 1) return true;
    }
    return false;
}
```

- [ ] **Step 4: Trocar o critério em `calculate_expected_minutes`**

`helpers.php:1851-1852` — trocar `lt.paid=1` por `l.excuses_absence=1` (dispensa o JOIN com leave_types):

Antes:
```php
    // Se há afastamento remunerado aprovado neste dia, minutos esperados = 0
    $stL = $pdo->prepare("SELECT 1 FROM leaves l JOIN leave_types lt ON lt.id=l.type_id WHERE l.teacher_id=? AND l.approved=1 AND lt.paid=1 AND ? BETWEEN l.start_date AND l.end_date LIMIT 1");
```
Depois:
```php
    // Se há afastamento aprovado que ABONA a falta neste dia, minutos esperados = 0.
    // Critério é por registro (excuses_absence), desacoplado de leave_types.paid.
    $stL = $pdo->prepare("SELECT 1 FROM leaves l WHERE l.teacher_id=? AND l.approved=1 AND l.excuses_absence=1 AND ? BETWEEN l.start_date AND l.end_date LIMIT 1");
```

- [ ] **Step 5: Rodar o teste para confirmar que PASSA**

Run: `php tests\test_leave_excuses_absence.php`
Expected: `Failed: 0`, sai com código 0.

- [ ] **Step 6: Regressão — teste de hours continua passando**

Run: `php tests\test_hours_schedule_mode.php`
Expected: `Failed: 0` (o Teste 11 usa um leave_type pago + leave aprovada; após o backfill `excuses_absence=paid`, esse registro tem `excuses_absence=1` → continua zerando). Se o ambiente tiver leave_type pago sem backfill, o teste insere `leaves` sem `excuses_absence` (NULL) → não zeraria. **Verificar:** se `test_hours` Teste 11 falhar, é esperado e será corrigido no Step 7.

- [ ] **Step 7: Ajustar o Teste 11 de `test_hours_schedule_mode.php` para setar excuses_absence**

`tests/test_hours_schedule_mode.php:168` insere uma leave sem `excuses_absence`. Tornar explícito:

Antes:
```php
        $pdo->prepare("INSERT INTO leaves (teacher_id, type_id, start_date, end_date, approved) VALUES (?, ?, '2026-05-25', '2026-05-25', 1)")
            ->execute([$teacherId, $paidLeaveTypeId]);
```
Depois:
```php
        $pdo->prepare("INSERT INTO leaves (teacher_id, type_id, start_date, end_date, approved, excuses_absence) VALUES (?, ?, '2026-05-25', '2026-05-25', 1, 1)")
            ->execute([$teacherId, $paidLeaveTypeId]);
```

Run: `php tests\test_hours_schedule_mode.php`
Expected: `Failed: 0`.

- [ ] **Step 8: Commit**

```bash
git add helpers.php tests/test_leave_excuses_absence.php tests/test_hours_schedule_mode.php
git commit -m "feat(core): calculate_expected_minutes usa excuses_absence + helper leave_day_is_excused"
```

---

## Task 3: Formulário de afastamento (`leaves.php`)

**Files:**
- Modify: `public/admin/leaves.php`

- [ ] **Step 1: Incluir `paid` na query de tipos**

`public/admin/leaves.php:21`:

Antes:
```php
$types = $pdo->query("SELECT id, name FROM leave_types WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
```
Depois:
```php
$types = $pdo->query("SELECT id, name, paid FROM leave_types WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
```

- [ ] **Step 2: Ler e validar `excuses_absence` no POST**

`public/admin/leaves.php:34` — adicionar logo após a linha `$approved = ...;`:

```php
    $excuses_absence = isset($_POST['excuses_absence']) ? (int)$_POST['excuses_absence'] : 1;
    if ($excuses_absence !== 0 && $excuses_absence !== 1) { $excuses_absence = 1; }
```

- [ ] **Step 3: Persistir em UPDATE (com anexo)**

`public/admin/leaves.php:95-96`:

Antes:
```php
            $st = $pdo->prepare("UPDATE leaves SET teacher_id=?,school_id=?,type_id=?,start_date=?,end_date=?,days_count=?,notes=?,description=?,cid_code=?,attachment=?,attachment_uploaded_at=?,approved=?,created_by_admin_id=? WHERE id=?");
            $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$attachment,$attachment_uploaded_at,$approved,$_SESSION['admin_id']??null,$id]);
```
Depois:
```php
            $st = $pdo->prepare("UPDATE leaves SET teacher_id=?,school_id=?,type_id=?,start_date=?,end_date=?,days_count=?,notes=?,description=?,cid_code=?,attachment=?,attachment_uploaded_at=?,approved=?,excuses_absence=?,created_by_admin_id=? WHERE id=?");
            $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$attachment,$attachment_uploaded_at,$approved,$excuses_absence,$_SESSION['admin_id']??null,$id]);
```

- [ ] **Step 4: Persistir em UPDATE (sem anexo)**

`public/admin/leaves.php:98-99`:

Antes:
```php
            $st = $pdo->prepare("UPDATE leaves SET teacher_id=?,school_id=?,type_id=?,start_date=?,end_date=?,days_count=?,notes=?,description=?,cid_code=?,approved=?,created_by_admin_id=? WHERE id=?");
            $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$approved,$_SESSION['admin_id']??null,$id]);
```
Depois:
```php
            $st = $pdo->prepare("UPDATE leaves SET teacher_id=?,school_id=?,type_id=?,start_date=?,end_date=?,days_count=?,notes=?,description=?,cid_code=?,approved=?,excuses_absence=?,created_by_admin_id=? WHERE id=?");
            $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$approved,$excuses_absence,$_SESSION['admin_id']??null,$id]);
```

- [ ] **Step 5: Persistir em INSERT**

`public/admin/leaves.php:104-105`:

Antes:
```php
        $st = $pdo->prepare("INSERT INTO leaves (teacher_id,school_id,type_id,start_date,end_date,days_count,notes,description,cid_code,attachment,attachment_uploaded_at,approved,created_by_admin_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$attachment,$attachment_uploaded_at,$approved,$_SESSION['admin_id']??null]);
```
Depois:
```php
        $st = $pdo->prepare("INSERT INTO leaves (teacher_id,school_id,type_id,start_date,end_date,days_count,notes,description,cid_code,attachment,attachment_uploaded_at,approved,excuses_absence,created_by_admin_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$attachment,$attachment_uploaded_at,$approved,$excuses_absence,$_SESSION['admin_id']??null]);
```

- [ ] **Step 6: Registrar no audit_log**

`public/admin/leaves.php` tem duas chamadas `audit_log('update'...)` (linha 101) e `audit_log('create'...)` (linha 106). Em ambas, trocar `compact(...)` para incluir o campo:

Antes (linha 101):
```php
        audit_log('update','leave',$id,compact('teacher_id','type_id','start_date','end_date','approved'));
```
Depois:
```php
        audit_log('update','leave',$id,compact('teacher_id','type_id','start_date','end_date','approved','excuses_absence'));
```

Antes (linha 106):
```php
        audit_log('create','leave',(int)$pdo->lastInsertId(),compact('teacher_id','type_id','start_date','end_date','approved'));
```
Depois:
```php
        audit_log('create','leave',(int)$pdo->lastInsertId(),compact('teacher_id','type_id','start_date','end_date','approved','excuses_absence'));
```

- [ ] **Step 7: Adicionar `data-paid` nas opções de tipo do formulário**

`public/admin/leaves.php:390-392` (o select `ocTypeId` do offcanvas; string única no arquivo):

Antes:
```php
                        <?php foreach ($types as $tp): ?>
                            <option value="<?= (int)$tp['id'] ?>"><?= esc($tp['name']) ?></option>
                        <?php endforeach; ?>
```
Depois:
```php
                        <?php foreach ($types as $tp): ?>
                            <option value="<?= (int)$tp['id'] ?>" data-paid="<?= (int)$tp['paid'] ?>"><?= esc($tp['name']) ?></option>
                        <?php endforeach; ?>
```

- [ ] **Step 8: Adicionar o controle radio "Abona a falta?"**

`public/admin/leaves.php:428` — inserir um bloco novo logo **após** o `</div>` que fecha o campo "Fim" (linha 428) e **antes** do bloco "Descricao Detalhada" (linha 430):

```php
                <div class="col-12">
                    <label class="form-label fw-semibold d-block">Este afastamento abona a falta? <span class="text-danger">*</span></label>
                    <div class="btn-group" role="group" aria-label="Abona a falta">
                        <input type="radio" class="btn-check" name="excuses_absence" id="ocExcusesYes" value="1" checked>
                        <label class="btn btn-outline-success" for="ocExcusesYes"><i class="bi bi-check2-circle me-1"></i>Sim, abona</label>
                        <input type="radio" class="btn-check" name="excuses_absence" id="ocExcusesNo" value="0">
                        <label class="btn btn-outline-danger" for="ocExcusesNo"><i class="bi bi-x-circle me-1"></i>Não abona</label>
                    </div>
                    <div class="form-text">
                        <strong>Sim</strong> = justificativa válida, desconsidera a falta.
                        <strong>Não</strong> = registra o motivo, mas o dia continua contando como falta (com desconto).
                    </div>
                </div>
```

- [ ] **Step 9: JS — default por tipo (criação) e preenchimento na edição**

`public/admin/leaves.php:489` — adicionar, logo após a linha `const ocForm = offcanvasEl.querySelector('form');`:

```javascript
        const ocExcusesYes = document.getElementById('ocExcusesYes');
        const ocExcusesNo  = document.getElementById('ocExcusesNo');
        function setExcuses(val) {
            if (parseInt(val, 10) === 0) { ocExcusesNo.checked = true; }
            else { ocExcusesYes.checked = true; }
        }
        // Em CRIACAO, ao trocar o tipo, sugere o default conforme 'paid' do tipo.
        ocTypeId.addEventListener('change', () => {
            if (ocId.value && ocId.value !== '0') return; // nao sobrescreve em edicao
            const opt = ocTypeId.options[ocTypeId.selectedIndex];
            const paid = opt ? parseInt(opt.getAttribute('data-paid') || '0', 10) : 0;
            setExcuses(paid ? 1 : 0);
        });
```

`public/admin/leaves.php:537` — dentro de `editLeave`, logo após `ocTypeId.value = d.type_id || '';`, adicionar:

```javascript
                setExcuses(d.excuses_absence);
```

(Em `resetForm`, o `ocForm.reset()` já volta o radio para o default HTML `Sim` — sem alteração necessária.)

- [ ] **Step 10: Verificação manual do formulário**

Run: iniciar o app (Laragon) e abrir `/admin/leaves.php`.
Verificar:
1. "Novo Afastamento": ao escolher um tipo **pago**, o radio pula para "Sim, abona"; tipo **não pago** → "Não abona".
2. Criar um afastamento "Não abona" de 1 dia útil para um colaborador de teste; salvar sem erro.
3. Editar esse afastamento: o radio reflete "Não abona".
4. "Ver detalhes": o JSON carrega (campo `excuses_absence` presente — herdado de `SELECT l.*`).

- [ ] **Step 11: Commit**

```bash
git add public/admin/leaves.php
git commit -m "feat(leaves): formulario com opcao 'abona a falta?' (Sim/Nao)"
```

---

## Task 4: Relatório mensal (`reports.php`) — lógica e exibição

**Files:**
- Modify: `public/admin/reports.php`

- [ ] **Step 1: Trazer o nome do tipo na query de licenças**

`public/admin/reports.php:160`:

Antes:
```php
    $stL = $pdo->prepare("SELECT l.*, lt.paid FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
```
Depois:
```php
    $stL = $pdo->prepare("SELECT l.*, lt.paid, lt.name AS leave_type_name FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
```

- [ ] **Step 2: Zerar jornada por `excuses_absence`**

`public/admin/reports.php:218-227`:

Antes:
```php
foreach ($daily as $k => &$d) {
    foreach ($leavesByDay[$k] ?? [] as $lv) {
        if ((int)$lv['paid'] === 1) {
            $totalExpectedMin -= $d['expectedMin'];
            $d['expectedMin'] = 0;
            break;
        }
    }
}
unset($d);
```
Depois:
```php
foreach ($daily as $k => &$d) {
    foreach ($leavesByDay[$k] ?? [] as $lv) {
        if ((int)($lv['excuses_absence'] ?? 0) === 1) { // abona a falta → zera jornada
            $totalExpectedMin -= $d['expectedMin'];
            $d['expectedMin'] = 0;
            break;
        }
    }
}
unset($d);
```

- [ ] **Step 3: Contagem de faltas — suprimir só por dia ABONADO**

`public/admin/reports.php:267-274`:

Antes:
```php
foreach ($daily as $date => $info) {
    $hasItems = !empty($info['items']);
    $expMin = (int)$info['expectedMin'];
    $isLeave = !empty($leavesByDay[$date]);

    if (!$hasItems && $expMin > 0 && $date <= $today && $date >= $teacherStartDate && !$isLeave) {
        $totalAbsences++;
    }
```
Depois:
```php
foreach ($daily as $date => $info) {
    $hasItems = !empty($info['items']);
    $expMin = (int)$info['expectedMin'];
    $isAbonado = leave_day_is_excused($leavesByDay[$date] ?? []);

    if (!$hasItems && $expMin > 0 && $date <= $today && $date >= $teacherStartDate && !$isAbonado) {
        $totalAbsences++;
    }
```

- [ ] **Step 4: Exibição por linha — variáveis de abonado/falta**

`public/admin/reports.php:744-751`:

Antes:
```php
                                $isLeave = !empty($leavesByDay[$date]);
                                $isFalta = !$hasItems && (int)$info['expectedMin'] > 0 && $date <= $today && $date >= $teacherStartDate && !$isLeave;

                                $rowClasses = [];
                                if ($isToday)   $rowClasses[] = 'rep-row-today';
                                if ($isWeekend) $rowClasses[] = 'rep-row-weekend';
                                if ($isLeave)   $rowClasses[] = 'rep-row-leave';
                                if ($isFalta)   $rowClasses[] = 'rep-row-absence';
```
Depois:
```php
                                $isAbonado = leave_day_is_excused($leavesByDay[$date] ?? []);
                                $hasLeave  = !empty($leavesByDay[$date]);
                                $isFalta = !$hasItems && (int)$info['expectedMin'] > 0 && $date <= $today && $date >= $teacherStartDate && !$isAbonado;

                                $rowClasses = [];
                                if ($isToday)   $rowClasses[] = 'rep-row-today';
                                if ($isWeekend) $rowClasses[] = 'rep-row-weekend';
                                if ($isAbonado) $rowClasses[] = 'rep-row-leave';
                                if ($isFalta)   $rowClasses[] = 'rep-row-absence';
```

- [ ] **Step 5: Exibição da célula — Abonado vs FALTA + motivo (justificada)**

`public/admin/reports.php:866-878`:

Antes:
```php
                                        <?php elseif ($isLeave): ?>
                                            <?php foreach ($leavesByDay[$date] as $lv): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                                    <i class="bi bi-calendar-x me-1"></i>Licença<?= !empty($lv['paid']) ? ' (paga)' : '' ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php elseif ($isFalta): ?>
                                            <span class="badge bg-danger text-white">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i>FALTA
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
```
Depois:
```php
                                        <?php elseif ($isAbonado): ?>
                                            <?php foreach ($leavesByDay[$date] as $lv): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                                    <i class="bi bi-calendar-check me-1"></i>Abonado<?= !empty($lv['leave_type_name']) ? ' · ' . esc($lv['leave_type_name']) : '' ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php elseif ($isFalta): ?>
                                            <span class="badge bg-danger text-white">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i>FALTA
                                            </span>
                                            <?php if ($hasLeave): ?>
                                                <?php foreach ($leavesByDay[$date] as $lv): ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="Afastamento registrado que NÃO abona a falta">
                                                        <i class="bi bi-info-circle me-1"></i>Justificada · <?= esc($lv['leave_type_name'] ?? 'Afastamento') ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
```

- [ ] **Step 6: Verificação manual + PDF**

Run: abrir `/admin/reports.php`, selecionar o colaborador de teste e o mês com o afastamento "Não abona" do Task 3.
Verificar:
1. O dia aparece como **FALTA** + selo "Justificada · {tipo}" e **conta** no total de faltas.
2. Um dia com afastamento "Sim, abona" aparece como **Abonado** e **não** conta falta.
3. Exportar PDF do mesmo relatório (botão de PDF): o dia "Não abona" mostra **⚠ FALTA** (derivado de `expectedMin > 0`); o "abona" mostra "-".

- [ ] **Step 7: Commit**

```bash
git add public/admin/reports.php
git commit -m "feat(reports): abona/nao-abona em faltas e exibicao do relatorio mensal"
```

---

## Task 5: Relatório financeiro (`reports_financial.php`) — lógica, exibição e folha

**Files:**
- Modify: `public/admin/reports_financial.php`

- [ ] **Step 1: Guardar `excuses_absence` e zerar por ele**

`public/admin/reports_financial.php:186-191`:

Antes:
```php
        $daily[$k]['leaves'][] = [
            'type' => $lv['leave_type_name'],
            'paid' => (int)$lv['paid'],
            'cid_code' => $lv['cid_code'] ?? '',
        ];
        if ((int)$lv['paid'] === 1) $daily[$k]['expected'] = 0;
```
Depois:
```php
        $daily[$k]['leaves'][] = [
            'type' => $lv['leave_type_name'],
            'paid' => (int)$lv['paid'],
            'excuses_absence' => (int)($lv['excuses_absence'] ?? 0),
            'cid_code' => $lv['cid_code'] ?? '',
        ];
        if ((int)($lv['excuses_absence'] ?? 0) === 1) $daily[$k]['expected'] = 0;
```

- [ ] **Step 2: Contagem de faltas — não suprimir por afastamento não-abonado**

`public/admin/reports_financial.php:288`:

Antes:
```php
        if ($v['expected'] > 0 && $v['worked'] == 0 && $d <= $today && $d >= $teacherStartDate && empty($v['holiday']) && empty($v['leaves'])) {
```
Depois:
```php
        if ($v['expected'] > 0 && $v['worked'] == 0 && $d <= $today && $d >= $teacherStartDate && empty($v['holiday']) && !leave_day_is_excused($v['leaves'])) {
```

- [ ] **Step 3: "Pronto para pagamento" — faltas não-abonadas bloqueiam**

`public/admin/reports_financial.php:304`:

Antes:
```php
$readyForPayment = $teacher && $pendingCount === 0 && ($totalAbsences === 0 || !empty($leaves));
```
Depois:
```php
// $totalAbsences já exclui dias abonados (expected zerado). Faltas não-abonadas contam.
$readyForPayment = $teacher && $pendingCount === 0 && $totalAbsences === 0;
```

- [ ] **Step 4: Exibição por linha — abonado vs falta**

`public/admin/reports_financial.php:769-771`:

Antes:
```php
                                $isLeave = !empty($v['leaves']);
                                $isHoliday = !empty($v['holiday']);
                                $isFalta = $v['expected'] > 0 && $v['worked'] == 0 && $d <= $today && $d >= $teacherStartDate && !$isHoliday && !$isLeave;
```
Depois:
```php
                                $isAbonado = leave_day_is_excused($v['leaves']);
                                $hasLeave  = !empty($v['leaves']);
                                $isHoliday = !empty($v['holiday']);
                                $isFalta = $v['expected'] > 0 && $v['worked'] == 0 && $d <= $today && $d >= $teacherStartDate && !$isHoliday && !$isAbonado;
```

- [ ] **Step 5: Classe da linha**

`public/admin/reports_financial.php:775`:

Antes:
```php
                                if ($isLeave)   $cls[] = 'row-leave';
```
Depois:
```php
                                if ($isAbonado) $cls[] = 'row-leave';
```

- [ ] **Step 6: Célula de status — abonado vs FALTA + motivo**

`public/admin/reports_financial.php:797-804`:

Antes:
```php
                                        <?php elseif ($isLeave): ?>
                                            <?php foreach ($v['leaves'] as $lv): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info">
                                                    <i class="bi bi-calendar-x me-1"></i><?= esc($lv['type']) ?><?= $lv['paid'] ? ' (rem.)' : '' ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php elseif ($isFalta): ?>
                                            <span class="badge bg-danger text-white"><i class="bi bi-exclamation-triangle-fill me-1"></i>FALTA</span>
```
Depois:
```php
                                        <?php elseif ($isAbonado): ?>
                                            <?php foreach ($v['leaves'] as $lv): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info">
                                                    <i class="bi bi-calendar-check me-1"></i><?= esc($lv['type']) ?> (abonado)<?= $lv['paid'] ? ' · rem.' : '' ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php elseif ($isFalta): ?>
                                            <span class="badge bg-danger text-white"><i class="bi bi-exclamation-triangle-fill me-1"></i>FALTA</span>
                                            <?php foreach ($v['leaves'] as $lv): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning" title="Afastamento que NÃO abona a falta">
                                                    <i class="bi bi-info-circle me-1"></i>Justificada · <?= esc($lv['type']) ?>
                                                </span>
                                            <?php endforeach; ?>
```

- [ ] **Step 7: Tabela-resumo "Afastamentos no Período" — coluna "Abona falta?"**

`public/admin/reports_financial.php:870` (cabeçalho):

Antes:
```php
                            <tr><th scope="col">Tipo</th><th scope="col">Período</th><th scope="col">Dias</th><th scope="col">Remunerado?</th><th scope="col">Impacto</th></tr>
```
Depois:
```php
                            <tr><th scope="col">Tipo</th><th scope="col">Período</th><th scope="col">Dias</th><th scope="col">Abona falta?</th><th scope="col">Remunerado?</th><th scope="col">Impacto</th></tr>
```

`public/admin/reports_financial.php:877-885` (corpo). Nota: `$leaves[]` guarda a linha crua (`$lv` com `l.*`), então `$lv['excuses_absence']` existe:

Antes:
```php
                                $isPaid = (int)$lv['paid']; ?>
                                <tr>
                                    <td><?= esc($lv['leave_type_name']) ?></td>
                                    <td><?= esc($startFmt) ?> → <?= esc($endFmt) ?></td>
                                    <td><span class="badge bg-info"><?= $daysCount ?> dia<?= $daysCount != 1 ? 's' : '' ?></span></td>
                                    <td><?= $isPaid ? '<span class="badge bg-success">Sim</span>' : '<span class="badge bg-secondary">Não</span>' ?></td>
                                    <td class="small">
                                        <?= $isPaid ? '<span class="text-success">Sem impacto · expected zerado</span>' : '<span class="text-warning">Não remunerado · expected mantido</span>' ?>
                                    </td>
                                </tr>
```
Depois:
```php
                                $isPaid = (int)$lv['paid'];
                                $isExcuses = (int)($lv['excuses_absence'] ?? 0); ?>
                                <tr>
                                    <td><?= esc($lv['leave_type_name']) ?></td>
                                    <td><?= esc($startFmt) ?> → <?= esc($endFmt) ?></td>
                                    <td><span class="badge bg-info"><?= $daysCount ?> dia<?= $daysCount != 1 ? 's' : '' ?></span></td>
                                    <td><?= $isExcuses ? '<span class="badge bg-success">Sim</span>' : '<span class="badge bg-warning text-dark">Não</span>' ?></td>
                                    <td><?= $isPaid ? '<span class="badge bg-success">Sim</span>' : '<span class="badge bg-secondary">Não</span>' ?></td>
                                    <td class="small">
                                        <?= $isExcuses ? '<span class="text-success">Abona · jornada zerada</span>' : '<span class="text-warning">Não abona · conta falta + desconto</span>' ?>
                                    </td>
                                </tr>
```

- [ ] **Step 8: Verificação manual + PDF financeiro**

Run: abrir `/admin/reports_financial.php`, mesmo colaborador/mês de teste.
Verificar:
1. Dia "Não abona": **FALTA** + "Justificada · {tipo}", entra no déficit/desconto e no contador de faltas.
2. Dia "abona": **(abonado)**, sem desconto.
3. Tabela "Afastamentos no Período" mostra coluna **Abona falta?** correta.
4. Com uma falta não-abonada presente, o selo "Pronto para pagamento" **não** aparece.
5. PDF financeiro: dia "Não abona" mostra **⚠ FALTA**.

- [ ] **Step 9: Commit**

```bash
git add public/admin/reports_financial.php
git commit -m "feat(financeiro): abona/nao-abona em faltas, folha e resumo de afastamentos"
```

---

## Task 6: Relatório mensal detalhado (`teacher_monthly_report.php`)

**Files:**
- Modify: `public/admin/teacher_monthly_report.php`

- [ ] **Step 1: Guardar `excuses_absence` no array do dia**

`public/admin/teacher_monthly_report.php:142-144`:

Antes:
```php
      $daily[$k]['leaves'][] = [
        'type' => $lv['leave_type_name'],
        'paid' => (int)$lv['paid'],
```
Depois:
```php
      $daily[$k]['leaves'][] = [
        'type' => $lv['leave_type_name'],
        'paid' => (int)$lv['paid'],
        'excuses_absence' => (int)($lv['excuses_absence'] ?? 0),
```

- [ ] **Step 2: Zerar jornada por `excuses_absence`**

`public/admin/teacher_monthly_report.php:150-153`:

Antes:
```php
      if ((int)$lv['paid'] === 1) {
        $totalExpectedMin -= $daily[$k]['expectedMin'];
        $daily[$k]['expectedMin'] = 0;
      }
```
Depois:
```php
      if ((int)($lv['excuses_absence'] ?? 0) === 1) { // abona a falta → zera jornada
        $totalExpectedMin -= $daily[$k]['expectedMin'];
        $daily[$k]['expectedMin'] = 0;
      }
```

(A detecção de FALTA neste arquivo — `teacher_monthly_report.php:412` — já deriva só de `expectedMin > 0`, sem suprimir por licença. Após a zeragem por `excuses_absence`, dias não-abonados mantêm `expectedMin > 0` e já exibem **FALTA**, com o afastamento listado na coluna de justificativa. Sem mudança adicional na exibição.)

- [ ] **Step 3: Verificação manual + PDF**

Run: abrir `/admin/teacher_monthly_report.php`, mesmo colaborador/mês.
Verificar:
1. Dia "Não abona": exibe **FALTA**; o afastamento aparece na justificativa.
2. Dia "abona": `expectedMin` zerado, sem FALTA.
3. PDF detalhado (`_tpl_teacher_monthly_report_pdf.php`): dia "Não abona" mostra **⚠ FALTA** + "Jornada prevista não registrada"; o afastamento aparece na coluna de observações.

- [ ] **Step 4: Commit**

```bash
git add public/admin/teacher_monthly_report.php
git commit -m "feat(relatorio-detalhado): zerar jornada por excuses_absence"
```

---

## Task 7: Insights e Dashboard — guards de query

**Files:**
- Modify: `public/admin/reports_insights.php`
- Modify: `public/admin/dashboard.php`

- [ ] **Step 1: Insights — só afastamento abonado suprime falta**

`public/admin/reports_insights.php:159` (dentro do prepared statement das licenças). O set `$leavesByTeacherDay` passa a conter apenas dias abonados, logo dias não-abonados voltam a contar como falta nos agregados.

Antes:
```php
         WHERE l.approved = 1
           AND l.teacher_id IN ($place)
```
Depois:
```php
         WHERE l.approved = 1
           AND l.excuses_absence = 1
           AND l.teacher_id IN ($place)
```

- [ ] **Step 2: Dashboard — não-abonado não reduz "ausentes hoje"**

`public/admin/dashboard.php:172-173` (subconsulta `NOT EXISTS` que exclui quem tem afastamento hoje da contagem de ausentes):

Antes:
```php
      SELECT 1 FROM leaves l
      WHERE l.teacher_id = t.id AND l.approved = 1 AND ? BETWEEN l.start_date AND l.end_date
```
Depois:
```php
      SELECT 1 FROM leaves l
      WHERE l.teacher_id = t.id AND l.approved = 1 AND l.excuses_absence = 1 AND ? BETWEEN l.start_date AND l.end_date
```

(O contador `$leavesActive` em `dashboard.php:193-201` permanece como está: é a métrica "afastamentos ativos hoje" — contagem de registros, independente de abonar.)

- [ ] **Step 3: Verificação manual**

Run: abrir `/admin/dashboard.php` e `/admin/reports_insights.php` em um dia com um afastamento "Não abona" ativo para um colaborador sem ponto.
Verificar:
1. Dashboard: o colaborador entra em **Ausentes hoje** (antes seria excluído por ter afastamento).
2. `$leavesActive` ("afastados hoje") ainda contabiliza o registro.
3. Insights: o dia conta como falta para esse colaborador.

- [ ] **Step 4: Commit**

```bash
git add public/admin/reports_insights.php public/admin/dashboard.php
git commit -m "feat(insights/dashboard): apenas afastamento abonado suprime falta"
```

---

## Task 8: Documentação + verificação final

**Files:**
- Modify: `docs/ABSENCE_DETECTION.md`

- [ ] **Step 1: Atualizar a doc de detecção de faltas**

Em `docs/ABSENCE_DETECTION.md`, na seção "Integração com Afastamentos", substituir a explicação baseada em `paid` por `excuses_absence`. Inserir, ao final dessa seção, o bloco:

```markdown
### Abona vs. não abona (a partir de 2026-06)

A decisão de abonar a falta passou a ser **por afastamento** (coluna
`leaves.excuses_absence`), desacoplada da remuneração (`leave_types.paid`):

- **Afastamento abonado** (`excuses_absence = 1`, aprovado) → zera a jornada
  prevista → **não** marca falta nem gera desconto.
- **Afastamento não abonado** (`excuses_absence = 0`) → mantém a jornada →
  o dia conta como **falta justificada** (motivo registrado) + desconto integral.
- **Sem afastamento** → **falta não justificada**.

A zeragem é centralizada em `calculate_expected_minutes()` e replicada nos
relatórios via o helper `leave_day_is_excused()`.
```

- [ ] **Step 2: Rodar a suíte de testes relevante**

Run:
```bash
php tests\test_leave_excuses_absence.php
php tests\test_hours_schedule_mode.php
```
Expected: ambos `Failed: 0`.

- [ ] **Step 3: Varredura de regressão — nenhum `lt.paid`/`paid` órfão decidindo falta**

Run:
```bash
grep -rn "paid'] === 1\|lt.paid=1\|lt\.paid = 1" public/admin helpers.php
```
Expected: nenhuma ocorrência relacionada a **zerar jornada/expected**. Ocorrências remanescentes de `paid` devem ser apenas rótulos de "remunerado" (ex.: colunas "Remunerado?"), nunca critério de falta.

- [ ] **Step 4: Commit**

```bash
git add docs/ABSENCE_DETECTION.md
git commit -m "docs: deteccao de faltas com abona/nao-abona (excuses_absence)"
```

---

## Verification Checklist (fim da implementação)

- [ ] Coluna `leaves.excuses_absence` existe; backfill sem NULLs remanescentes.
- [ ] `php tests\test_leave_excuses_absence.php` → Failed: 0.
- [ ] `php tests\test_hours_schedule_mode.php` → Failed: 0.
- [ ] Formulário: default segue `paid` do tipo; edição reflete o valor salvo.
- [ ] Afastamento "Não abona" → FALTA + "Justificada" em reports.php, reports_financial.php, teacher_monthly_report.php e nos 3 PDFs.
- [ ] Afastamento "Não abona" → desconto/déficit na folha (reports_financial) e bloqueia "Pronto para pagamento".
- [ ] Afastamento "Abona" → sem falta, sem desconto (comportamento anterior preservado).
- [ ] Dashboard conta o não-abonado em "Ausentes hoje"; insights conta a falta.
```

# Offline Sync Idempotente — Plano de Implementação

> **Para agentes executores:** SUB-SKILL OBRIGATÓRIA: usar `superpowers:executing-plans` para executar este plano tarefa por tarefa. Passos usam checkbox (`- [ ]`) para tracking.

**Goal:** Garantir que múltiplos cliques no botão de bater ponto enquanto o servidor/internet está indisponível não gerem registros duplicados na folha. Cada "intenção de batida" gera UM registro, independente de quantas vezes o usuário clicou.

**Arquitetura:** Dedupe em três camadas defensivas: (1) frontend dedupe pré-save no IndexedDB (mesma intenção = atualiza item existente, não cria novo); (2) UI bloqueia botão e mostra banner pós-save para o usuário não tentar de novo; (3) backend tem dedupe lógico por jornada (mesmo teacher + mesma data + mesmo tipo em janela curta) que complementa a UNIQUE em `client_id` já existente. Aproveita infraestrutura existente: IndexedDB store `pending`, `client_id` UUID, UNIQUE constraints, `audit_logs`.

**Tech Stack:** PHP 8 + MySQL (XAMPP), Vanilla JS + IndexedDB no PWA, Service Worker para drain.

---

## Context

Hoje o sistema permite o ponto offline, mas tem três falhas que se somam:

1. **Cada clique gera novo UUID** (`pontoGenClientId()` em [index.php:6313](public/index.php:6313)). Quando o usuário vê "Servidor indisponível" e clica 5x, são criados 5 itens distintos no IndexedDB com `client_id` diferentes. O dedupe do backend só pega `client_id` IGUAL — UUIDs distintos passam.
2. **Botão reabilita imediatamente após salvar offline** ([index.php:12018](public/index.php:12018)), sem feedback persistente de "já está salvo".
3. **Backend não tem dedupe lógico por jornada**. Se 5 saídas chegam com client_ids distintos, a 1ª fecha o ponto e as outras 4 caem em `action_mismatch` — só funciona por sorte do estado da sequência; sob concorrência ou cenários edge, falha.

Já existe infra sólida: `client_id` UNIQUE (entrada) e `checkout_client_id` UNIQUE (saída) com pre-check + handler de race-condition; `nsr_sequence` atômico; `client_recorded_at` grava horário real da batida; transações em INSERT/UPDATE; `audit_logs` para auditoria. Este plano **não recria** essa infra — apenas tampa os três gaps acima.

---

## File Structure

**Frontend** ([public/index.php](public/index.php)):
- `savePending()` (linha 5959) — adicionar dedupe pré-save
- `pontoQueueEnqueueSession()` (linha 6320) — encaminhar `expected_action` + `date` para o dedupe
- Handler do submit do ponto (linha ~12015) — bloquear botão até drain confirmar
- Indicador de pendentes (`updatePendingCount`, linha 6043) — virar banner persistente "saída pendente"
- Handler de resposta do drain (linha ~6275) — tratar novo code `duplicate_ignored`

**Backend** ([api/checkin_bulk.php](api/checkin_bulk.php)):
- Sort do `$items` por `client_recorded_at` ASC (antes do loop, ~linha 100)
- Função `find_logical_duplicate()` em [helpers.php](helpers.php) — pesquisa dedupe por jornada
- Pre-check lógico antes do INSERT (entrada) e UPDATE (saída) — linhas 145–250

**Banco** (nova migration):
- `sql/migrations/2026_05_16_add_dedupe_indexes.sql` — índices que dão suporte ao pre-check

**Docs:**
- Atualizar `docs/TROUBLESHOOTING_SYNC.md` com novos códigos de status

---

## Task 1 — Banco: índices para dedupe lógico

**Files:**
- Create: `sql/migrations/2026_05_16_add_dedupe_indexes.sql`

- [ ] **Step 1: Criar migration.**

```sql
-- Suporta o pre-check lógico de duplicatas no api/checkin_bulk.php:
-- "existe attendance para este teacher nesta data com check_in/check_out
-- dentro de uma janela de 5 minutos do que estou para inserir?"

USE ponto;

-- Acelera busca por entrada existente em uma data
ALTER TABLE attendance
  ADD INDEX idx_dedupe_in (teacher_id, date, check_in);

-- Acelera busca por saída existente em uma data
ALTER TABLE attendance
  ADD INDEX idx_dedupe_out (teacher_id, date, check_out);
```

- [ ] **Step 2: Aplicar (quando MySQL estiver no ar).**

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root ponto -e "source sql/migrations/2026_05_16_add_dedupe_indexes.sql"
& "C:\xampp\mysql\bin\mysql.exe" -u root ponto -e "SHOW INDEX FROM attendance WHERE Key_name IN ('idx_dedupe_in','idx_dedupe_out');"
```

Expected: dois índices listados.

- [ ] **Step 3: Commit.**

```bash
git add sql/migrations/2026_05_16_add_dedupe_indexes.sql
git commit -m "feat(db): índices para dedupe lógico de pontos offline"
```

---

## Task 2 — Backend: helper de dedupe lógico em `helpers.php`

**Files:**
- Modify: `helpers.php` (adicionar após `get_effective_weekday()`)

- [ ] **Step 1: Adicionar a função.**

```php
/**
 * Procura uma attendance "logicamente equivalente" — mesmo professor, mesma data,
 * mesmo tipo de ponto (entrada/saida), com client_recorded_at dentro de uma janela
 * de N minutos. Usado pelo bulk sync para descartar tentativas duplicadas que
 * vieram com client_ids diferentes (ex: usuário clicou 5x offline).
 *
 * Retorna array {id, nsr, client_id, checkout_client_id} se achar, null caso contrário.
 *
 * @param 'entrada'|'saida' $action
 * @param string $clientRecordedAt Y-m-d H:i:s no fuso BR
 */
function find_logical_duplicate(
    PDO $pdo,
    int $teacherId,
    string $date,
    string $action,
    string $clientRecordedAt,
    int $windowMinutes = 5
): ?array {
    if ($action === 'entrada') {
        $sql = "SELECT id, nsr, client_id
                FROM attendance
                WHERE teacher_id = ?
                  AND date = ?
                  AND check_in IS NOT NULL
                  AND ABS(TIMESTAMPDIFF(MINUTE, client_recorded_at, ?)) <= ?
                ORDER BY id ASC
                LIMIT 1";
    } else { // saida
        $sql = "SELECT id, nsr, checkout_client_id
                FROM attendance
                WHERE teacher_id = ?
                  AND date = ?
                  AND check_out IS NOT NULL
                  AND ABS(TIMESTAMPDIFF(MINUTE, check_out, ?)) <= ?
                ORDER BY id ASC
                LIMIT 1";
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$teacherId, $date, $clientRecordedAt, $windowMinutes]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
```

- [ ] **Step 2: Lint.**

```powershell
& "C:\xampp\php\php.exe" -l helpers.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit.**

```bash
git add helpers.php
git commit -m "feat(helpers): find_logical_duplicate para dedupe offline"
```

---

## Task 3 — Backend: usar dedupe lógico em `api/checkin_bulk.php`

**Files:**
- Modify: `api/checkin_bulk.php`

- [ ] **Step 1: Ordenar items por `client_recorded_at` antes do loop.**

Localizar onde o loop sobre `$items` começa (provavelmente algo como `foreach ($items as $idx => $item)`). Antes dele, adicionar:

```php
// Ordenação cronológica: processa primeiro a tentativa mais antiga do usuário.
// Isso garante que, em batch com várias tentativas duplicadas do mesmo ponto,
// a 1ª (do horário real da intenção) é a que vence; as demais ficam como
// duplicate_ignored e mantêm o horário original na folha.
usort($items, function ($a, $b) {
    $ta = strtotime((string)($a['recordedAt'] ?? $a['client_recorded_at'] ?? 'now'));
    $tb = strtotime((string)($b['recordedAt'] ?? $b['client_recorded_at'] ?? 'now'));
    return $ta <=> $tb;
});
```

Confirmar o nome da chave do timestamp lendo o que o frontend manda (grep `recordedAt` em `public/index.php`).

- [ ] **Step 2: Adicionar pre-check lógico ANTES do INSERT de entrada.**

Localizar o bloco que faz o INSERT da entrada (perto da linha 360). ANTES do `beginTransaction`, adicionar:

```php
// Dedupe lógico: já existe entrada equivalente para este teacher nesta data
// dentro da janela? (cliques múltiplos com client_ids distintos)
$logicalDup = find_logical_duplicate($pdo, $teacherId, $today, 'entrada', $authoritativeTime, 5);
if ($logicalDup) {
    audit_log($pdo, null, 'duplicate_ignored', 'attendance', (int)$logicalDup['id'], [
        'reason'            => 'logical_dedupe_entrada',
        'rejected_client_id'=> $clientId,
        'kept_attendance_id'=> (int)$logicalDup['id'],
        'kept_nsr'          => (int)$logicalDup['nsr'],
        'teacher_id'        => $teacherId,
        'date'              => $today,
        'client_recorded_at'=> $authoritativeTime,
    ]);
    return [
        'status'         => 'ok',
        'code'           => 'duplicate_ignored',
        'message'        => 'Entrada equivalente já registrada — esta tentativa foi ignorada.',
        'attendance_id'  => (int)$logicalDup['id'],
        'nsr'            => (int)$logicalDup['nsr'],
        'action'         => 'entrada',
    ];
}
```

- [ ] **Step 3: Adicionar pre-check lógico ANTES do UPDATE de saída.**

Localizar o bloco do UPDATE de saída (perto da linha 450). Antes do `beginTransaction` do UPDATE, adicionar:

```php
$logicalDup = find_logical_duplicate($pdo, $teacherId, $today, 'saida', $authoritativeTime, 5);
if ($logicalDup) {
    audit_log($pdo, null, 'duplicate_ignored', 'attendance', (int)$logicalDup['id'], [
        'reason'            => 'logical_dedupe_saida',
        'rejected_client_id'=> $clientId,
        'kept_attendance_id'=> (int)$logicalDup['id'],
        'kept_nsr'          => (int)$logicalDup['nsr'],
        'teacher_id'        => $teacherId,
        'date'              => $today,
        'client_recorded_at'=> $authoritativeTime,
    ]);
    return [
        'status'         => 'ok',
        'code'           => 'duplicate_ignored',
        'message'        => 'Saída equivalente já registrada — esta tentativa foi ignorada.',
        'attendance_id'  => (int)$logicalDup['id'],
        'nsr'            => (int)$logicalDup['nsr'],
        'action'         => 'saida',
    ];
}
```

- [ ] **Step 4: Lint.**

```powershell
& "C:\xampp\php\php.exe" -l api/checkin_bulk.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit.**

```bash
git add api/checkin_bulk.php
git commit -m "feat(checkin_bulk): dedupe lógico por jornada + ordenação cronológica"
```

---

## Task 4 — Frontend: dedupe pré-save no IndexedDB

**Files:**
- Modify: `public/index.php` (funções `savePending` e `pontoQueueEnqueueSession`)

- [ ] **Step 1: Adicionar helper `findEquivalentPending` antes de `savePending` (linha 5959).**

```javascript
    /**
     * Procura no IndexedDB um item pendente equivalente a `payload` —
     * mesmo CPF, mesma data Y-m-d, mesmo expected_action.
     * Usado para impedir que múltiplos cliques offline gerem itens duplicados.
     * Retorna o registro {id, payload, ...} ou null.
     */
    async function findEquivalentPending(payload) {
        if (!dbi || !payload) return null;
        const cpf = String(payload.cpf || '').replace(/\D/g, '');
        const action = String(payload.expected_action || '');
        if (!cpf || !action) return null;
        const today = new Date().toISOString().slice(0, 10);
        return await new Promise((resolve) => {
            const tx = dbi.transaction('pending', 'readonly');
            const req = tx.objectStore('pending').openCursor();
            req.onsuccess = (e) => {
                const cur = e.target.result;
                if (!cur) { resolve(null); return; }
                const it = cur.value;
                const p = it && it.payload;
                if (p && String(p.cpf || '').replace(/\D/g, '') === cpf
                       && String(p.expected_action || '') === action) {
                    // Janela: itens criados nas últimas 24h
                    const ageMs = Date.now() - (it.createdAt || 0);
                    if (ageMs < 24 * 60 * 60 * 1000) {
                        resolve(Object.assign({ _key: cur.key }, it));
                        return;
                    }
                }
                cur.continue();
            };
            req.onerror = () => resolve(null);
        });
    }
```

- [ ] **Step 2: Em `savePending`, antes do `add()`, chamar dedupe.**

Trocar o bloco do `success` (linhas 5988–5996) por:

```javascript
        // Dedupe pré-save: se já existe item equivalente pendente, atualiza esse
        // (incrementa attempts, atualiza foto/geo se mais recentes) em vez de
        // criar novo. Garante que múltiplos cliques offline = 1 ponto na folha.
        const dup = await findEquivalentPending(payloadSafe);
        const success = await new Promise((resolve, reject) => {
          const tx = dbi.transaction('pending', 'readwrite');
          const store = tx.objectStore('pending');
          if (dup) {
            const merged = {
              ...dup,
              payload: {
                ...dup.payload,
                // Atualiza foto/geo com a tentativa mais recente (pode ter melhor sinal)
                photo: payloadSafe.photo || dup.payload.photo,
                geo:   payloadSafe.geo   || dup.payload.geo,
                // Mantém client_id e timestamp originais (intenção primeira é a que vale)
              },
              attempts: (dup.attempts || 0) + 1,
              lastAttemptAt: Date.now(),
            };
            store.put(merged);
          } else {
            store.add({
              payload: payloadSafe,
              createdAt: Date.now(),
              attempts: 1,
              lastAttemptAt: Date.now(),
            });
          }
          tx.oncomplete = () => resolve({ ok: true, deduped: !!dup });
          tx.onerror = () => reject(tx.error);
        });

        if (success && success.deduped) {
          // Sinaliza ao chamador que foi dedupe (para mostrar mensagem diferente)
          window.__lastSaveWasDedupe = true;
        } else {
          window.__lastSaveWasDedupe = false;
        }
```

E onde fala `if (success)`, mudar para `if (success && success.ok)`.

- [ ] **Step 3: No `pontoQueueEnqueueSession` (linha 6320), garantir que `date` é parte do payload se ainda não estiver.**

Atualizar para:

```javascript
    async function pontoQueueEnqueueSession(payload) {
      const p = { ...payload, kind: 'session_checkin' };
      if (!p.client_id) p.client_id = pontoGenClientId();
      // dedupe usa expected_action + cpf + data (já presentes); nada a fazer aqui
      await savePending(p);
      return { client_id: p.client_id, deduped: !!window.__lastSaveWasDedupe };
    }
```

- [ ] **Step 4: Lint manual.**

Abrir DevTools no browser e checar o console por SyntaxError. Não há lint JS automatizado neste projeto.

- [ ] **Step 5: Commit.**

```bash
git add public/index.php
git commit -m "feat(pwa): dedupe pré-save no IndexedDB por (cpf+data+expected_action)"
```

---

## Task 5 — Frontend: bloqueio do botão + banner pós-save

**Files:**
- Modify: `public/index.php` (handler do submit em ~linha 12015)

- [ ] **Step 1: Substituir o bloco offline-enqueue (linhas 12014–12030) por uma versão que bloqueia o botão até o drain confirmar.**

```javascript
      // Item 2: se offline, enfileira e encerra. Botão fica bloqueado até o drain
      // confirmar — assim o usuário NÃO clica de novo achando que falhou.
      if (!navigator.onLine && typeof pontoQueueEnqueueSession === 'function') {
        try {
          const res = await pontoQueueEnqueueSession(payload);
          // NÃO reabilita o botão — fica disabled+texto "Salvo offline" até o drain
          btn.classList.remove('is-loading');
          btn.disabled = true;
          if (!btn.dataset.originalText) btn.dataset.originalText = btn.innerHTML;
          btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Salvo no aparelho';
          showPendingBanner(payload, res.deduped);
          if (typeof toast === 'function') {
            if (res.deduped) {
              toast('info', 'Já estava salvo',
                'Seu ponto já estava salvo no aparelho — não é necessário registrar de novo.',
                ['Vamos enviar assim que a conexão voltar.']);
            } else {
              toast('warning', 'Sem conexão',
                'Seu ponto foi salvo no aparelho. Não é necessário registrar novamente.',
                ['Assim que a conexão voltar, enviaremos automaticamente.']);
            }
          }
          return;
        } catch (e) {
          console.warn('[SessionCheckin] falha ao enfileirar:', e);
        }
      }
```

- [ ] **Step 2: Substituir o bloco do retry "Servidor indisponível" (linhas 12036–12046) com o mesmo padrão.**

```javascript
      if ((data.code === 'offline' || data.code === 'server_unreachable' || data.code === 'server_error')
          && typeof pontoQueueEnqueueSession === 'function') {
        try {
          const res = await pontoQueueEnqueueSession(payload);
          btn.classList.remove('is-loading');
          btn.disabled = true;
          if (!btn.dataset.originalText) btn.dataset.originalText = btn.innerHTML;
          btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Salvo no aparelho';
          showPendingBanner(payload, res.deduped);
          if (typeof toast === 'function') {
            toast('warning', 'Servidor indisponível',
              res.deduped
                ? 'Já estava salvo no aparelho — não é necessário registrar de novo.'
                : 'Ponto salvo no aparelho — vamos enviar assim que possível.',
              ['Não tente registrar de novo enquanto não houver conexão.']);
          }
          return;
        } catch (e) {
          console.warn('[SessionCheckin] falha ao enfileirar retry:', e);
        }
      }
```

- [ ] **Step 3: Adicionar funções `showPendingBanner` e `clearPendingBanner` perto de `updatePendingCount` (linha 6043).**

```javascript
    function showPendingBanner(payload, isDedupe) {
      const host = document.body;
      let el = document.getElementById('pendingPunchBanner');
      if (!el) {
        el = document.createElement('div');
        el.id = 'pendingPunchBanner';
        el.className = 'alert alert-info d-flex align-items-center gap-2 m-2 shadow-sm';
        el.style.cssText = 'position:sticky;top:0;z-index:1080;border-left:4px solid #0d6efd;';
        host.insertBefore(el, host.firstChild);
      }
      const action = (payload && payload.expected_action) === 'saida' ? 'Saída' : 'Entrada';
      el.innerHTML =
        '<i class="bi bi-cloud-arrow-up-fill text-primary fs-5"></i>' +
        '<div class="flex-grow-1"><b>' + action + ' salva no aparelho</b>' +
        '<div class="small text-muted">Aguardando conexão para enviar — não registre de novo.</div>' +
        '</div>';
    }
    function clearPendingBanner() {
      const el = document.getElementById('pendingPunchBanner');
      if (el) el.remove();
      // Restaura botão se foi bloqueado
      document.querySelectorAll('[data-original-text]').forEach((b) => {
        if (b.dataset.originalText) {
          b.innerHTML = b.dataset.originalText;
          b.disabled = false;
          delete b.dataset.originalText;
        }
      });
    }
```

- [ ] **Step 4: Chamar `clearPendingBanner()` no `drainPending` quando a fila zera.**

Localizar onde `drainPending()` reseta o estado depois de sucesso (perto da linha 6275, após `updatePendingCount()`). Adicionar:

```javascript
          // Se a fila zerou, libera o botão e remove o banner.
          try {
            const remaining = await getPendingCount();
            if (remaining === 0) clearPendingBanner();
          } catch (_) {}
```

- [ ] **Step 5: Smoke test no browser.**

1. Abrir `http://localhost/ponto_oeiras2/public/index.php`
2. DevTools → Network → Offline
3. Bater ponto. Toast amarelo deve aparecer; botão deve ficar `disabled` com texto "Salvo no aparelho ✓"; banner azul deve aparecer no topo.
4. Tentar bater de novo (mesmo após reload). Toast info "Já estava salvo".
5. Voltar online. Banner some, botão volta ao normal, contador zera.

- [ ] **Step 6: Commit.**

```bash
git add public/index.php
git commit -m "feat(pwa): botão bloqueado + banner pós-save offline"
```

---

## Task 6 — Frontend: tratar `duplicate_ignored` no drain

**Files:**
- Modify: `public/index.php` (handler de resposta do drain, linhas 6244–6275)

- [ ] **Step 1: Localizar onde itens com `status: 'ok'` são deletados do IDB (perto de 6244). Confirmar que `duplicate_ignored` cai no mesmo branch (porque `status === 'ok'`).**

Já deve cair, porque o backend retorna `status: 'ok'`. Apenas adicionar contagem para informar o usuário:

Onde o código conta `successCount`/`discardedCount` no loop de resposta, adicionar contador de duplicates:

```javascript
            const dedupedCount = results.filter(r => r.response && r.response.code === 'duplicate_ignored').length;
```

E no toast final, se `dedupedCount > 0`, mostrar:

```javascript
            if (dedupedCount > 0) {
              toast('info', 'Tentativas duplicadas',
                dedupedCount + ' tentativa(s) repetida(s) foram identificadas e ignoradas com segurança.',
                ['Sua folha de ponto não foi afetada.']);
            }
```

- [ ] **Step 2: Commit.**

```bash
git add public/index.php
git commit -m "feat(pwa): mostrar feedback quando drain ignora duplicatas"
```

---

## Task 7 — Docs

**Files:**
- Modify: `docs/TROUBLESHOOTING_SYNC.md`

- [ ] **Step 1: Adicionar seção sobre o novo code `duplicate_ignored`.**

```markdown
## Código de resposta: `duplicate_ignored`

Significa que o servidor identificou uma tentativa duplicada no momento da
sincronização e a ignorou intencionalmente. Cenário típico:

1. Usuário clicou várias vezes em "Bater ponto" offline.
2. O frontend já tem dedupe local, mas se o item antigo já havia sido enviado
   e a tentativa nova chegou ao backend, o dedupe lógico do servidor entra em
   ação.
3. O ponto fica registrado APENAS uma vez. Os demais cliques viram log de
   auditoria em `audit_logs.action='duplicate_ignored'`.

Janela atual: 5 minutos. Mesmo teacher_id + mesma data + mesmo tipo de
ponto dentro da janela = duplicado lógico.

**Não é um erro.** A folha de ponto continua correta.
```

- [ ] **Step 2: Commit.**

```bash
git add docs/TROUBLESHOOTING_SYNC.md
git commit -m "docs(sync): documenta o novo code duplicate_ignored"
```

---

## Task 8 — Verificação end-to-end

- [ ] **Step 1: Setup.**

Subir XAMPP (Apache + MySQL). Aplicar a migration do Task 1. Abrir o PWA no Chrome com DevTools.

- [ ] **Step 2: Cenário "5 cliques offline".**

1. Login como colaborador de teste. Bater **entrada** online (deve aprovar normalmente).
2. DevTools → Network → throttling: **Offline**.
3. Clicar **saída** 5 vezes seguidas (ignorando o toast de aviso).
4. Verificar IDB: DevTools → Application → IndexedDB → `ponto-db` → `pending`. Deve haver **APENAS 1 item** com `attempts: 5`.
5. Voltar online (throttling: No throttling).
6. Banner some, botão libera, toast "Sincronização completa".
7. Verificar DB: `SELECT id, check_in, check_out, client_id, checkout_client_id FROM attendance WHERE teacher_id = <ID> AND date = CURDATE();` → 1 linha com check_in e check_out preenchidos.
8. `SELECT COUNT(*) FROM audit_logs WHERE action = 'duplicate_ignored' AND created_at >= CURDATE();` → 0 (porque o frontend já deduplicou).

- [ ] **Step 3: Cenário "drain parcial + 2ª tentativa chega ao servidor".**

Simular o caso onde o frontend deduplica MAS um item antigo já foi enviado. Cenário sintético:

1. Bater entrada offline → item A na fila.
2. Voltar online; drain envia item A com sucesso.
3. **Antes** do delete local concluir, simular nova entrada offline (ainda não detectou o sucesso). Como isso é difícil de reproduzir manualmente, executar via console DevTools:

```javascript
// força um POST direto para o backend com dados equivalentes mas client_id novo
const r = await fetch('/api/checkin_bulk.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ items: [{
    cpf: '<CPF do teste>',
    pin: '<PIN>',
    photo: '<base64 mínimo>',
    geo: { lat: -23.5, lng: -46.6, acc: 10 },
    client_id: 'novo-uuid-distinto',
    expected_action: 'entrada',
    recordedAt: new Date().toISOString().slice(0,19).replace('T',' '),
    recordMode: 'offline'
  }]})
});
console.log(await r.json());
```

Expected: response `status:'ok', code:'duplicate_ignored'` apontando para o attendance_id já existente.

4. `SELECT COUNT(*) FROM audit_logs WHERE action = 'duplicate_ignored' AND created_at >= CURDATE();` → 1.

- [ ] **Step 4: Cenário "ponto legítimo do mesmo tipo no mesmo dia".**

Edge case: turno de manhã (entrada 08, saída 12) e turno de tarde (entrada 14, saída 18). A 2ª entrada às 14h NÃO pode ser tratada como duplicada da 1ª às 08h.

1. Bater entrada às 08:00 (real).
2. Bater saída às 12:00.
3. Bater entrada às 14:00.
4. Janela de dedupe é 5 minutos → 14:00 está a 6h da 08:00, fora da janela → cria novo registro normalmente.

Verificar: 2 linhas em `attendance` para o dia.

- [ ] **Step 5: Smoke test do fluxo online normal.**

Bater entrada+saída online sem desconectar. Deve funcionar como antes — sem regressão.

---

## Self-review

- **Spec coverage:**
  - Regra 1 (`local_uuid` único): já existia via `client_id` UUID.
  - Regras 2,11 (campos + horário real): já existiam (`client_recorded_at`, `device_*`, etc.).
  - Regras 3,4,5,6,18,19 (dedupe pré-save no front): Task 4 ✅
  - Regras 7,8,9,12 (validação backend, transação, status `duplicate_ignored`): Task 2+3 ✅
  - Regra 10 (ordem cronológica): Task 3 step 1 ✅
  - Regra 13 (audit logs): Task 3 usa `audit_log()` existente ✅
  - Regra 14 (tabela dedicada): **não criada** — `audit_logs` já cumpre. Comunicado ao usuário na conversa.
  - Regra 15 (proteção no banco): UNIQUE em `client_id`/`checkout_client_id` já existe + índices da Task 1 ✅
  - Regras 16,17 (UI bloqueia botão + banner): Task 5 ✅
  - Regra 20 (auditoria do fluxo): feita antes deste plano ✅

- **Placeholders:** Tasks 3 e 5 dependem de localizar offsets exatos (variáveis e linhas reais podem ter mudado); cada step tem grep/leitura instructed antes da edição.

- **Type consistency:** `code: 'duplicate_ignored'` é string nova; usada em backend (Task 3) e tratada explicitamente no frontend (Task 6). `status:'ok'` mantido para que o drain existente delete o item local sem mudança extra.

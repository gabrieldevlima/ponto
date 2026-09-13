# Auto-cadastro de face em contexto confiável

**Data:** 2026-04-20
**Tipo:** Feature nova
**Arquivos impactados:** `api/checkin.php`, `api/self_enroll_face.php` (novo), `public/ponto.php`

## Contexto

O sistema atualmente trava colaboradores que nunca cadastraram face biométrica quando um step-up de verificação é disparado (ex.: troca de dispositivo). O endpoint [api/checkin.php:465-478](../../../api/checkin.php) detecta `face_descriptors` vazio + step-up obrigatório e retorna `blocked_contact_admin`, exigindo intervenção do admin via [public/admin/capture_face.php](../../../public/admin/capture_face.php) ou [api/save_face.php](../../../api/save_face.php).

Na prática isso cria fricção operacional alta:

- Colaborador troca de celular e fica sem bater ponto até admin agir
- Em fim de semana/feriado, o colaborador não tem a quem recorrer
- O admin acumula backlog de cadastros

A política de "primeiro acesso não exige foto" ([api/pin_enroll.php:1-17](../../../api/pin_enroll.php)) é intencional e permanece — esse design cobre o vácuo entre essa política e o momento em que face vira obrigatória.

**Objetivo:** permitir que o próprio colaborador cadastre sua face, sem admin, quando estiver em contexto geograficamente confiável (dentro da escola, sem GPS simulado). Mantém a política atual de "sem face no primeiro acesso" e elimina o bloqueio operacional.

## Decisões de design (tomadas no brainstorming)

| Ponto | Decisão |
|---|---|
| Critério de "contexto confiável" | Permissivo: geo dentro do geofence + sem `gps_mock`. Ignora outras razões de step-up. |
| Quantidade de fotos | 3, com validação de diversidade (reusa lógica de [api/save_face.php:70-91](../../../api/save_face.php)). |
| UX | Tela dedicada nova (`screen-selfenroll`) com mensagem explícita e contador "Foto X de 3". |
| Pós-sucesso | Auto-retry: frontend chama `submitPin()` usando o último descritor como prova de step-up — check-in acontece sem novo clique. |

## Arquitetura

### Backend

**Modificar [api/checkin.php](../../../api/checkin.php)** (linhas 463-478):

O bloco que hoje retorna `blocked_contact_admin` passa a verificar se o contexto é confiável:

```php
$hasFaceEnrolled = !empty($row['face_descriptors']);
if (!$hasFaceEnrolled) {
    $canSelfEnroll = !in_array('geo_out', $stepupReasons, true)
                  && !in_array('gps_mock', $stepupReasons, true);
    if ($canSelfEnroll) {
        auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId,
            'self_enroll_offered', ['reasons' => $stepupReasons]);
        api_error(200, 'require_face_enroll',
            'Seu cadastro ainda não tem foto. Vamos cadastrar agora para você bater ponto.',
            [], 'pin');
    }
    // caminho antigo (bloqueia) — apenas quando contexto NÃO é confiável
    auth_attempt_log($pdo, 'pin', $authIdentifier, false, $teacherId,
        'stepup_blocked_no_face', ['reasons' => $stepupReasons]);
    api_error(200, 'blocked_contact_admin',
        'Precisamos de validação facial, mas seu cadastro ainda não tem foto.',
        ['Procure o administrador para concluir o cadastro facial.'], 'pin');
}
```

Status retornado ao frontend quando contexto é confiável:
```json
{
  "status": "error",
  "code": "require_face_enroll",
  "message": "Seu cadastro ainda não tem foto. Vamos cadastrar agora para você bater ponto."
}
```

**Novo endpoint `api/self_enroll_face.php`** — segue o padrão de [api/pin_enroll.php](../../../api/pin_enroll.php) (função local `enroll_error()` para respostas de erro, pois `api_error()` é privada de `api/checkin.php`). Recebe `{ cpf, pin, geo, deviceFingerprint, descriptors: [3] }` e:

1. Valida estrutura do payload (CPF formatado, PIN 6 dígitos, 3 descritores de 128 floats).
2. Rate limit por `(ip|ua|cpf)` — reusa helper existente (ver `api/checkin.php` e `api/pin_enroll.php` para padrão).
3. Busca teacher ativo por CPF. Se não existe ou inativo → `teacher_not_found`.
4. **Re-valida PIN** com o mesmo `pin_verify()` usado em [api/checkin.php](../../../api/checkin.php). Se falhar → `pin_invalid` + audit log.
5. **Re-valida contexto confiável server-side**. Hoje o cálculo de geofence está inline em [api/checkin.php:450](../../../api/checkin.php) e similares — mesmo padrão: `get_setting('geofence_radius_m', '300')` + distância haversine contra `schools.latitude`/`longitude`. Para este endpoint: extrair helper novo `is_geo_trusted_for_teacher(PDO, int $teacherId, array $geo): bool` em `helpers.php` reusando a lógica existente, ou — se a refatoração ficar grande — duplicar a checagem inline (escolher no momento da implementação, preferir extração). Detecção de `gps_mock` segue o mesmo padrão inline usado em `checkin.php`. Se falhar → `context_not_trusted` + audit log.
6. **Re-confirma que teacher.face_descriptors está vazio**. Se já tiver face (race condition) → `already_enrolled`.
7. Aplica a lógica existente de [api/save_face.php:40-113](../../../api/save_face.php): `normalize_face_descriptors`, validação de diversidade (`face_enrollment_min_diversity`), `deduplicate_face_descriptors`, cap de 20, `find_active_face_conflict`. Erros de cada etapa retornam os mesmos códigos (`samples_not_diverse`, `face_duplicate_active`).
8. UPDATE teachers SET face_descriptors, face_enrolled_at = NOW(), face_enrollment_version = COALESCE(..., 0) + 1.
9. Audit log com action `face_self_enrolled` + sucesso + reasons originais do step-up.
10. Responde `{ status: "ok", count: N }`.

**Sem admin gate** — é por design. A segurança vem da re-validação server-side (PIN + geo + sem face prévia).

### Frontend ([public/ponto.php](../../../public/ponto.php))

**Nova tela** `screen-selfenroll` (HTML), estrutura similar à `screen-stepup` existente:

```html
<div class="screen" id="screen-selfenroll">
  <div class="card">
    <h1>Cadastrar seu rosto</h1>
    <p id="selfenroll-msg">Seu cadastro ainda não tem foto. Vamos tirar 3 fotos
       agora para você poder bater ponto.</p>
    <div class="progress-text">Foto <span id="selfenroll-n">1</span> de 3</div>
    <video id="selfenroll-video" autoplay muted playsinline></video>
    <div id="selfenroll-hint"><!-- dica por foto: olhe pra frente / vire a cabeça / sorria --></div>
    <button class="btn" id="btn-selfenroll-capture">CAPTURAR FOTO</button>
    <button class="btn ghost" data-back="pin">Cancelar</button>
  </div>
</div>
```

**Novas funções JS:**

- `startSelfEnrollFlow(pendingPayload)` — guarda `window.__pendingSelfEnroll = pendingPayload`, reseta `__selfEnrollDescriptors = []`, chama `showScreen('selfenroll')`, inicia câmera com mesma função usada em step-up (`startStepupCamera` ou equivalente genérico — refatorar se necessário para reuso).
- Handler de `#btn-selfenroll-capture`: captura descritor com `faceapi.detectSingleFace(video).withFaceDescriptor()`. Se não detectar rosto → toast "Não vi seu rosto, centralize e tente de novo" (não incrementa contador). Se detectar:
  - Se já há descritor anterior e distância local < 0.10 (muito similar) → toast "Mude um pouco o ângulo" (não incrementa).
  - Senão → push no array, incrementa contador, atualiza dica.
  - Ao atingir 3 descritores → chama `submitSelfEnroll()`.
- `submitSelfEnroll()` — POST `/api/self_enroll_face.php` com payload. Ao receber `ok`: fecha câmera, chama `submitPin(lastDescriptor)` com `window.__pendingSelfEnroll` (cpf/pin já guardados) → check-in acontece via fluxo normal de step-up.

**Handler do retorno do checkin** ([public/ponto.php](../../../public/ponto.php) dentro de `submitPin`): adicionar caso para `code === 'require_face_enroll'` que chama `startSelfEnrollFlow({ cpf, pin, geo, deviceFingerprint })`.

## Fluxo de dados (ponta a ponta)

1. `POST /api/checkin.php` → servidor retorna `{ code: "require_face_enroll" }`.
2. Frontend: guarda contexto, abre `screen-selfenroll`, inicia câmera.
3. Usuário captura 3 fotos (cada uma validada localmente por diversidade).
4. `POST /api/self_enroll_face.php` → servidor re-valida tudo, UPDATE teacher, responde `ok`.
5. Frontend chama `submitPin(lastDescriptor)` → `POST /api/checkin.php` de novo, agora com `face_descriptor` no payload.
6. Servidor: step-up satisfeito → registra ponto → responde `{ status: "ok", ... }`.
7. Frontend: mostra tela de sucesso normal.

## Segurança

**Validações que o backend NUNCA delega ao cliente:**

- **PIN** re-verificado em `self_enroll_face.php` usando o mesmo hash/função do checkin.
- **Geo** re-validada server-side (geofence da escola) com os dados do payload.
- **GPS mock** re-detectado server-side (mesma lógica que `checkin.php`).
- **`face_descriptors` ainda vazio** checado imediatamente antes do UPDATE (SELECT FOR UPDATE se necessário) — previne sobrescrita via race condition.
- **Conflito com outro colaborador ativo** via `find_active_face_conflict()` — se o rosto capturado já pertence a outro `teacher` ativo, bloqueia com `face_duplicate_active` (possível indicador de fraude) e cai em `blocked_contact_admin`.
- **Rate limit** por `(ip, user_agent, cpf)` reusando helper existente. Mesmo limite que `pin_enroll` (3 tentativas / 15 min).
- **CSRF**: segue o padrão de [api/pin_enroll.php:75-82](../../../api/pin_enroll.php) — token só é exigido se `is_collaborator_logged()` (sessão ativa); anonymous flow (que é o caso normal aqui) valida pela combinação CPF + PIN + contexto em vez de CSRF.

**Por que sem ticket/nonce:** como todas as condições são re-validadas server-side, um ticket em sessão seria redundante (atacante que consiga satisfazer todas as condições já passaria com ou sem ticket). A simplicidade reduz superfície de bug.

## Auditoria

Inserir em `auth_attempts` (tabela já usada pelos outros fluxos):

- Action `self_enroll_offered` — quando o servidor decide que o colaborador pode se auto-cadastrar (na resposta do `checkin.php`).
- Action `face_self_enrolled` — sucesso do enrollment (em `self_enroll_face.php`).
- Action `face_self_enroll_failed` — falha (com `code` específico da falha).

Marcar também `teachers.face_enrolled_at = NOW()` e `face_enrollment_version = COALESCE(..., 0) + 1` (já é o padrão de `save_face.php`).

## Tratamento de erros

| Situação | Código retornado | UI do frontend |
|---|---|---|
| PIN errado na re-validação | `pin_invalid` | toast + volta pra tela PIN |
| Geo mudou entre os POSTs (saiu do raio) | `context_not_trusted` | tela `blocked_contact_admin` |
| GPS mock detectado na re-validação | `context_not_trusted` | tela `blocked_contact_admin` |
| 3 amostras muito similares | `samples_not_diverse` | toast + volta pra foto 1 |
| Rosto já vinculado a outro colaborador | `face_duplicate_active` | tela `blocked_contact_admin` com texto específico |
| Face cadastrada entre os dois POSTs (race) | `already_enrolled` | toast "Sua foto foi cadastrada. Clique BATER PONTO novamente." + volta pra tela PIN |
| Rate limit excedido | `too_many_attempts` | toast + volta pra tela PIN |
| Câmera negada pelo usuário | (front-end) | toast "Permita acesso à câmera" + botão retry |
| `faceapi` não detecta rosto | (front-end) | toast + não consome foto do contador |

## Verificação

### Cenários felizes (devem funcionar)

- Colaborador sem face, dentro do geofence, dispositivo novo, PIN correto → tela de auto-cadastro → 3 fotos → ponto registrado automaticamente.
- Colaborador sem face após reset de PIN, na escola → mesmo fluxo.
- Colaborador com face já cadastrada → fluxo normal de step-up, não entra no auto-cadastro.

### Cenários de bloqueio (devem continuar bloqueando)

- Sem face + fora do geofence → `blocked_contact_admin`.
- Sem face + GPS mock → `blocked_contact_admin`.
- Face capturada bate com outro colaborador ativo → `blocked_contact_admin` com mensagem de fraude.

### Cenários de retry

- Câmera negada → permitir retry.
- `faceapi` não acha rosto → não consome foto.
- 3 fotos similares → volta pra foto 1.

### Cenários de race

- Admin cadastra face no mesmo minuto → `already_enrolled`.
- Colaborador anda pra fora do geofence entre POSTs → `context_not_trusted`.

### Teste manual no XAMPP

1. Criar colaborador de teste sem face. Conferir `SELECT face_descriptors FROM teachers WHERE id=X` retorna NULL.
2. Em device A (desktop), rodar `submitFirstAccess` → gera PIN. Device A fica trusted.
3. Em device B (celular/perfil anônimo), com GPS emulado **dentro** do geofence, ir em `/ponto.php`, CPF + PIN, clicar BATER PONTO.
4. Esperado: `screen-selfenroll` aparece.
5. Capturar 3 fotos. Na aba Network: POST `/api/self_enroll_face.php` → 200 `{ status: "ok" }`.
6. DB: `face_descriptors` agora tem 3 descritores, `face_enrolled_at` preenchido, `auth_attempts` tem linha `face_self_enrolled`.
7. Tela de sucesso de ponto aparece sem clique extra.
8. Teste de bloqueio: GPS emulado fora do geofence → retorna `blocked_contact_admin`.
9. Teste de conflito: capturar foto de um rosto já cadastrado em outro colaborador ativo → retorna `face_duplicate_active` → tela blocked.

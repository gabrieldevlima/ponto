# Quiosque de Ponto com Reconhecimento Facial (motor LOCAL, gratuito)

Modo quiosque onde o colaborador bate o ponto em um terminal (totem/tablet/celular/PC).
O reconhecimento é **local**, com a biblioteca **`face-api.js`** rodando no próprio
navegador — **sem API externa, sem chaves, sem custo, sem cota e sem dependência de
rede**. Verificação **1:1**: o colaborador digita o **CPF** (identifica) e a **face
confirma** que é ele.

> **Por que 1:1?** A identificação 1:N (só rosto) foi desativada no passado por
> falsos positivos. Com 1:1, a face só compara contra o cadastro **daquela** pessoa
> → praticamente elimina troca de identidade.
>
> **Privacidade:** o template biométrico (vetor de 128 números) é gerado no
> navegador e guardado **no próprio banco** (`teachers.face_descriptors`). Nada vai
> a terceiros (sem Azure/AWS), sem transferência internacional.

## Fluxo

```
[public/kiosk.php] digita CPF → câmera → face-api.js extrai descriptor (128 floats)
   │  POST /api/kiosk_verify.php { cpf, face_descriptor, photo }   (X-Kiosk-Token, X-CSRF)
   ▼
[api/kiosk_verify.php] acha teacher por CPF → verifica 1:1 contra teachers.face_descriptors
   │  (euclidean + consenso, get_face_thresholds('identify')) → token assinado (HMAC, 90s, uso único)
   ▼
[public/kiosk.php] mostra "Confirmado: <Nome>" + ações → confirma
   │  POST /api/kiosk_checkin.php { recognition_token, action, client_id }
   ▼
ponto registrado (attendance.method='kiosk_face', face_match_confidence)
```

## Componentes

| Arquivo | Papel |
|---|---|
| `public/kiosk.php` | UI do quiosque: CPF + câmera + extração de descriptor + ações. Pareado por token. |
| `api/kiosk_verify.php` | Verificação 1:1 local (NÃO registra). Loga tentativa, emite token assinado. |
| `api/kiosk_checkin.php` | Registra o ponto honrando as regras (NSR atômico, jornada, dedup). Consome o token. |
| `api/kiosk_lib.php` | Device-auth, token HMAC uso-único, `kiosk_verify_face_1to1()`, decode de imagem, logging, rate-limit. |
| `public/admin/kiosk_config.php` | Ativar/desativar, janela anti-duplicação, fallback. |
| `public/admin/kiosk_devices.php` | Dispositivos autorizados + link de pareamento (token mostrado 1x). |
| `public/admin/kiosk_enrollment.php` | Cadastro facial (face-api.js → `api/save_face.php`); status por `face_descriptors`. |
| `public/admin/kiosk_logs.php` | Auditoria de tentativas (sucesso/recusa), com filtros. |
| `public/js/face-api.min.js` + `public/models/` | Biblioteca + modelos (já no repo). |

## Reconhecimento (reuso da base existente)

- **Extração (cliente):** `face-api.js` (`tinyFaceDetector` + `faceLandmark68TinyNet` + `faceRecognitionNet`), igual ao `public/admin/capture_face.php`.
- **Cadastro:** o EXISTENTE `api/save_face.php` grava em `teachers.face_descriptors` (com validação de diversidade + dedup, máx. 20 amostras).
- **Comparação 1:1:** `kiosk_verify_face_1to1()` em `api/kiosk_lib.php` reusa `euclidean_distance()` + `get_face_thresholds('identify')`:
  - aceita se **menor distância < `match_threshold`** E **≥ ceil(total × `min_consensus_ratio`)** amostras dentro de `consensus_threshold`;
  - exige ao menos `get_min_face_descriptors_for_auth()` (padrão 3) amostras cadastradas.
- **Rigor (limiares)** ficam em `app_settings` (`face_identify_threshold`, `face_identify_consensus_threshold`, `face_identify_consensus_ratio`) — compartilhados com o reconhecimento do app; ajuste com cautela.

## Configuração (app_settings)

| Chave | Padrão | Descrição |
|---|---|---|
| `kiosk_enabled` | `0` | Liga/desliga o modo quiosque. |
| `kiosk_dedup_window_seconds` | `120` | Janela anti duplo-toque. |
| `kiosk_fallback_enabled` | `0` | Habilita fallback CPF+PIN (também exige `fallback_allowed` no dispositivo). |
| `kiosk_max_checkins_10min` | `30` | Rate-limit por dispositivo. |

Não há chaves/credenciais. O segredo HMAC (`KIOSK_SIGNING_SECRET`) é gerado e
persistido automaticamente em `app_settings` se não for definido por constante/env.

## Segurança

- **Device-auth** por `X-Kiosk-Token` (só o hash sha256 é persistido).
- **Token de reconhecimento** HMAC-SHA256, vinculado a device+teacher, expira em 90s
  e é **uso único** (consumido atomicamente). O cliente não troca o teacher_id nem reusa.
- **CSRF** em todos os POSTs. **Atomicidade**: `GET_LOCK` por colaborador + NSR via
  `SELECT ... FOR UPDATE` → nenhum ponto parcial; idempotência por `client_id`.
- **Auditoria**: toda tentativa (recusa/baixa qualidade/sem cadastro/erro/fallback) em `kiosk_face_logs`; ações admin em `audit_logs`.

## Fallback (face não confirma)

Se a face não bater e `kiosk_fallback_enabled=1` **e** o dispositivo tem
`fallback_allowed=1`, o quiosque oferece **CPF + PIN**, que posta no
`api/checkin.php` existente (com breadcrumb `event=fallback` em `kiosk_face_logs`).

## Aprovação

A face (≥ limiar) em dispositivo autorizado é a âncora de confiança → o ponto é
**auto-aprovado**, exceto **candidato a hora extra** (dia sem jornada), que fica
pendente para o admin — igual ao fluxo padrão.

## Requisitos de produção

- **HTTPS obrigatório** (a câmera/`getUserMedia` exige contexto seguro).
- Enviar ao servidor `public/js/face-api.min.js` e a pasta `public/models/` (≈ 8 MB).
- Sem chaves a configurar.

## Robustez, segurança e operação

- **Captura inteligente (kiosk.php):** detecção contínua com guias ("aproxime-se", "centralize", "apenas uma pessoa") e gate de estabilidade (~1 s) antes de confirmar — reduz falsos negativos.
- **Cadastro com qualidade:** o cadastro rejeita quadros ruins (rosto único, próximo e centralizado) antes de salvar.
- **Anti brute-force por CPF:** após `kiosk_cpf_max_fails` falhas em `kiosk_cpf_lockout_minutes`, o CPF é bloqueado temporariamente (status `locked_out`).
- **Calibração de limiar:** em *Configuração → Calibração*, o sistema analisa os cadastros (distância genuína × impostora) e sugere/aplica o limiar (`kiosk_face_match_threshold`). Havendo sobreposição, a sugestão prioriza segurança (menos falso-aceite).
- **Painel (Quiosque → Painel):** taxa de sucesso, confiança média, pontos batidos, motivos de recusa, suspeitos (muitas falhas/24 h), saúde dos dispositivos (online/offline) e alertas de anomalia.
- **Retenção/LGPD:** o cron `cron_photo_cleanup.php` purga imagens de auditoria do quiosque após `kiosk_photo_retention_days`, **sem** apagar fotos ainda referenciadas por `attendance` (retenção legal própria).

Novas chaves em `app_settings`: `kiosk_cpf_max_fails` (5), `kiosk_cpf_lockout_minutes` (10), `kiosk_face_match_threshold` (vazio = automático), `kiosk_photo_retention_days` (90).

> **Nota honesta sobre liveness:** sem detecção de vivacidade, uma foto/vídeo pode, em tese, passar (o motor só compara o vetor). O modo **1:1 (CPF + face)** num quiosque supervisionado mitiga bastante, mas a defesa definitiva contra foto/vídeo é **liveness** (piscar/virar o rosto) — pode ser adicionada depois sobre os mesmos landmarks do face-api.js.

## Teste

1. Abrir qualquer página admin → migrações aplicam.
2. **Quiosque → Cadastro Facial**: cadastrar 3–5 fotos de um colaborador de teste.
3. **Quiosque → Dispositivos**: criar device, copiar link de pareamento.
4. **Quiosque → Configuração**: ativar.
5. Abrir o link no navegador → digitar CPF → confirmar rosto → bater entrada/saída.
6. Conferir em **Registros**, **Relatório** ("Facial (Quiosque)" + %) e **Quiosque → Logs**.

Teste automatizado (sem câmera): há um harness determinístico que injeta um
descriptor sintético em `face_descriptors` e exercita `kiosk_verify.php` +
`kiosk_checkin.php` (match, recusa, registro, dedup) via HTTP.

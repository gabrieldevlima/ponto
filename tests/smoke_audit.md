# Smoke Tests da Auditoria — 2026-04-26

Roteiro de verificação ponta-a-ponta. Estado de cada item após a consolidação.

## Banco de dados (✅ executado via mysql CLI)

| # | Verificação | Resultado |
|---|---|---|
| 1 | Total de tabelas | 38 (era 34) |
| 2 | Índices secundários | 95 (era ~40) |
| 3 | Migrations aplicadas | 32 |
| 4 | Migrations com `failure_reason` | **0** ✅ |
| 5 | Linhas em `attendance` | 2.309 (sistema em uso ao vivo) |
| 6 | Attendance com `school_id NULL` | 5 (todos do colaborador 250 e 251 — sem filiação em `teacher_schools`) |
| 7 | Attendance com data corrompida (`< 2020`) | **0** ✅ (era 1) |
| 8 | NSR duplicados | **0** ✅ |
| 9 | Órfãos de `attendance → teachers` | **0** ✅ |
| 10 | Tabelas com collation diferente de `utf8mb4_unicode_ci` | 1 (`v_payslips_full` — TIMESTAMP DEFAULT inválido bloqueia conversão; baixa prioridade) |
| 11 | `permissions` existe | ✅ |
| 12 | `teacher_trusted_devices` existe | ✅ |

## Lint PHP (✅ todos os arquivos editados e seus consumidores)

```
helpers.php                          ✅
api/checkin.php                      ✅
api/identify_face.php                ✅
api/save_face.php                    ✅
api/pin_enroll.php                   ✅
api/pin_recover.php                  ✅
api/self_enroll_face.php             ✅
api/last_checkin.php                 ✅
public/admin/_navbar.php             ✅
public/admin/login.php               ✅
public/admin/teachers_save.php       ✅
public/admin/admins.php              ✅
public/login.php                     ✅
```

## Backend (verificação manual recomendada — XAMPP)

> Estes itens precisam ser verificados manualmente abrindo o navegador em `http://localhost/ponto_ribeira/public/`. Não foi automatizado para evitar inserir dados falsos no banco de produção.

| # | Cenário | Como testar | Esperado |
|---|---|---|---|
| 13 | Login admin | `public/admin/login.php` com credenciais válidas | Redireciona para `dashboard.php` |
| 14 | Login colaborador | `public/login.php` com CPF + PIN | Redireciona para `index.php` |
| 15 | CPF de instalação ainda aceito | Login admin com `00000000000` (se admin install ainda existir) | Aceita — exceção mantida em `validate_cpf()` |
| 16 | Check-in com foto | Capturar via `ponto.php`; verificar arquivo em `public/photos/` | Filename = 32 hex chars + `.jpg` ou `.png` |
| 17 | Foto > 5MB | Enviar payload base64 grande | Rejeitada com log em `logs/php_errors.log` |
| 18 | Foto não-imagem | Enviar `data:image/jpeg;base64,SGVsbG8=` (texto disfarçado) | Rejeitada por `getimagesizefromstring` |
| 19 | Rate-limit `identify_face` | 11 POSTs em <120s do mesmo IP | 11ª retorna HTTP 429 |
| 20 | Rate-limit `pin_enroll` | já protegido via `auth_attempt_is_limited` | OK |
| 21 | Geração de comprovante PDF | `public/receipt.php?id=...` para attendance existente | DOMPDF responde com PDF válido |

## Frontend (verificação manual)

| # | Cenário | Esperado |
|---|---|---|
| 22 | Service worker registra v2.44.0 | DevTools → Application → Service Workers mostra a nova versão |
| 23 | Skip-link a11y | Tab no início de qualquer página admin → link "Pular para conteúdo" aparece |
| 24 | PWA instalável | Lighthouse audit ≥ 90 em PWA |
| 25 | Offline checkin replay | Desconectar → bater ponto → reconectar → ponto sincroniza via `checkin_bulk.php` |

## Compliance Portaria 671/2021

| # | Verificação | Resultado |
|---|---|---|
| 26 | NSR sem buracos/duplicatas | ✅ Verificado no banco |
| 27 | Tabela `nsr_sequence` | ✅ Existe |
| 28 | Comprovante automático | ✅ `RECEIPT_AUTO_GENERATE=true` em config.php |
| 29 | Retenção 5 anos | ✅ `RECEIPT_RETENTION_YEARS=5` |

## Itens com ação manual recomendada

1. **Colaboradores 250 (Santina Lima da Costa) e 251 (Ronivaldo Campelo)** — sem filiação em `teacher_schools`. 5 attendances ficaram com `school_id NULL`. Admin precisa atribuí-los a uma escola via `teacher_edit.php`.
2. **`v_payslips_full`** — collation `utf8mb4_general_ci`. Não converteu por causa de `TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00'`. Considere recriar a tabela ou ajustar o DEFAULT em uma migration futura.
3. **16 colaboradores sem PIN, 90 sem face descriptors** — investigar se é intencional ou se cadastro está incompleto.

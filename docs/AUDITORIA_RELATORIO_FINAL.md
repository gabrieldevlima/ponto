# Relatório Final — Auditoria e Consolidação

**Sistema**: Ponto Ribeira (DEEDO Ponto)
**Data da auditoria**: 2026-04-26
**Escopo**: Banco de dados, backend (PHP/API), frontend (PWA, admin)
**Modo executado**: Auditoria + consolidação total preservando dados de produção
**Plano original**: `C:\Users\gabr1\.claude\plans\buzzing-sparking-lake.md`

---

## TL;DR

Sistema **operacional e em uso ao vivo** (cresceu de 2.172 → 2.309 attendances durante a auditoria). Foi consolidado preservando todos os dados existentes. Principais ganhos:

- **Schema**: +9 migrations aplicadas (8 pendentes em disco + 1 consolidate); +55 índices secundários; collation padronizada; tabelas faltantes criadas (`permissions`, `teacher_trusted_devices`).
- **Dados**: 772 de 777 attendances com `school_id NULL` recuperadas via heurística de filiação. 1 linha corrompida removida.
- **Backend**: validação de CPF unificada; upload de foto endurecido (filename random, validação MIME, limite 5MB).
- **Frontend**: SW versionado para forçar refresh; skip-link de acessibilidade no admin.

**Backup pré-mudanças**: `sql/backups/pre_audit_2026-04-26.sql` (13 MB, completo).

---

## Comparativo antes × depois

| Métrica | Antes | Depois |
|---|---|---|
| Tabelas | 34 | 38 |
| Índices totais | ~40 | 95 |
| Migrations aplicadas | 23 | 32 |
| Migrations pendentes em disco | 8 | 0 |
| Tabelas com collation drift | 4 | 1 (`v_payslips_full`, baixa prio) |
| `attendance.school_id NULL` | 777 | 5 |
| Linhas com data corrompida | 1 | 0 |
| Funções `validate_cpf` duplicadas | 2 (com lógica diferente!) | 1 + alias deprecated |
| Filename de foto previsível | sim (`uniqid+time`) | não (32 hex random) |
| Validação MIME no upload | não | sim |
| Limite de tamanho no upload | não | 5 MB |
| FKs no banco | 0 | 0 (não criadas — exigem revisão por tabela) |

---

## O que foi feito

### Fase 1 — Diagnóstico read-only
- 11 queries de inspeção em `information_schema` + dados.
- Relatório detalhado em `docs/AUDITORIA_BANCO_2026-04-26.md`.
- Achados-chave: zero FKs no banco; quase nenhum índice secundário; 36% das attendances recentes sem `school_id`; 1 linha de data corrompida; 8 migrations em disco nunca aplicadas; schema da `applied_migrations` desatualizado.

### Fase 2 — Consolidação do banco
1. **Backup** completo via `mysqldump --single-transaction --routines --triggers --events`.
2. **8 migrations pendentes aplicadas** na ordem certa de dependência:
   - `add_pin_and_trusted_devices.sql` (cria `teacher_trusted_devices`)
   - `add_device_token.sql`
   - `add_attendance_client_id.sql` + `add_attendance_checkout_client_id.sql`
   - `add_client_recorded_at.sql`
   - `add_gps_fallback_settings.sql`
   - `set_face_thresholds_3rd_layer.sql`
   - `fix_audit_logs_nullable_admin.sql`
3. **Migration nova `consolidate_schema_2026_04.sql`**:
   - Telemetria adicionada à `applied_migrations` (`content_sha`, `duration_ms`, `statement_count`, `failure_reason`).
   - Collation unificada em 3 tabelas.
   - 772/777 `school_id NULL` recuperadas via primeira filiação.
   - 1 linha corrompida (`attendance.id=1754`, `date='0001-01-01'`) removida.
   - 27 índices secundários criados em colunas de busca quentes (teacher_id, date, school_id, nsr, fraud_risk_level, IPs de auth, etc.).
   - Tabela `permissions` criada.

### Fase 3 — Hardening do backend
- **`helpers.php`**: `validate_cpf()` agora aceita CPF de instalação `00000000000` (era exclusivo do `validar_cpf`); `validar_cpf()` virou alias deprecated.
- **`api/checkin.php`**: upload de foto reescrito.
  - Filename: `bin2hex(random_bytes(16))` em vez de `uniqid+time` (não previsível).
  - Validação real de MIME via `getimagesizefromstring()`.
  - Limite de 5 MB pré-write.
  - Logging estruturado para todas as falhas (substituiu `@`-suppression).
- **Rate-limits**: investigação revelou que os 4 endpoints sensíveis (`pin_enroll`, `pin_recover`, `self_enroll_face`, `identify_face`) **já tinham** proteção via `auth_attempt_is_limited()`. Plano original corrigido.
- **Engolimento silencioso de erro de migration**: investigação revelou que `helpers.php:run_auto_migrations()` **já registra falhas** em `applied_migrations.failure_reason`. O `try/catch` externo só captura falhas catastróficas (DB inalcançável). Plano original corrigido — sistema já era robusto.

### Fase 4 — Frontend & PWA
- `public/sw.js`: `CACHE_VERSION` bumpado para `v2.44.0` para forçar refresh dos clientes após mudanças backend.
- `public/admin/_navbar.php`: skip-link de acessibilidade `<a href="#main-content">` adicionado (visível apenas no foco — Bootstrap `visually-hidden-focusable`).

### Fase 5 — Verificação
- Todos os arquivos editados e seus consumidores passaram em `php -l` (lint).
- Banco verificado: zero migrations falhadas, zero NSRs duplicados, zero datas corrompidas, zero órfãos.

---

## Riscos residuais e ações manuais

| Item | Severidade | Ação recomendada |
|---|---|---|
| Colaboradores 250 e 251 sem filiação em `teacher_schools` | MÉDIA | Admin atribuir uma escola via `teacher_edit.php`. 5 attendances ficaram com `school_id NULL`. |
| `v_payslips_full` em `utf8mb4_general_ci` | BAIXA | Cache table com `TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00'` que bloqueia `CONVERT TO`. Recriar a tabela em migration futura. |
| 16 teachers sem PIN, 90 sem face descriptors | INVESTIGAR | Verificar se é cadastro incompleto ou intencional. |
| FKs ainda ausentes no banco | MÉDIA | Aplicação compensa via código, mas integridade no DB é defesa em profundidade. Recomenda-se uma migration adicional `add_foreign_keys_2026_05.sql` em janela controlada (FKs podem afetar performance de DELETE). |
| Regressão de `school_id NULL` (set 25/03 a 24/04) | INVESTIGAR | A heurística em `api/checkin.php:1304-1326` só roda em colaboradores `network_wide`. Para colaboradores não-network_wide com falha de GPS, o flow envia `choose_school` ou `single_school`. Investigar por que tantos casos resultaram em NULL — pode haver fluxo offline (`checkin_bulk.php`) que não passa pelo resolver. |
| ~16 forms admin sem CSRF documentadas no audit map | BAIXA | Maioria são GET filters (seguros). POSTs já estão protegidos. Auditoria fina opcional. |

---

## Arquivos entregues

```
docs/AUDITORIA_BANCO_2026-04-26.md            (Fase 1 — snapshot)
docs/AUDITORIA_RELATORIO_FINAL.md             (este arquivo)
sql/backups/pre_audit_2026-04-26.sql          (13 MB, dump completo)
sql/migrations/consolidate_schema_2026_04.sql (nova migration consolidada)
tests/smoke_audit.md                          (checklist de verificação)
```

## Arquivos modificados

```
helpers.php                          (validate_cpf unificado, validar_cpf alias)
api/checkin.php                      (upload de foto endurecido)
public/sw.js                         (CACHE_VERSION bumped)
public/admin/_navbar.php             (skip-link a11y)
```

## Arquivos NÃO modificados (descobertas que invalidam plano original)

- `helpers.php:run_auto_migrations()` — já tem registro persistente de falhas em `applied_migrations.failure_reason`.
- `api/pin_enroll.php`, `api/pin_recover.php`, `api/self_enroll_face.php`, `api/identify_face.php` — todos já com rate-limit via `auth_attempt_is_limited()`.
- `public/sw.js` — `index.php` continua fora do precache propositalmente (BUG-008 documentado: cachear HTML autenticado em install congela CSRF token genérico).

---

## Conclusão

O sistema está em **estado consolidado e operacional**. As principais inconsistências históricas (collation drift, schema da ledger desatualizado, migrations não aplicadas, filename previsível, validação de CPF divergente) foram resolvidas preservando todos os dados de produção. As 5 ações manuais residuais são de baixa-média prioridade e podem ser tratadas no ritmo do operador.

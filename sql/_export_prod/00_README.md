# Export para Produção — Migração v1 → v2

**Gerado em:** 2026-04-28
**Origem:** banco local `ponto_oeiras2` (XAMPP)
**Cenário alvo:** produção tem dados reais e precisa preservá-los

---

## Conteúdo deste pacote

| Arquivo | Função | Quando usar |
|---------|--------|-------------|
| `00_README.md` | Este arquivo | — |
| `01_pre_check.sql` | Inspeção pré-migração (read-only) | Sempre, antes de tudo |
| `02_migrate_v1_to_v2.sql` | **Pacote principal** — 12 migrations consolidadas | Sempre |
| `03_post_check.sql` | Validação pós-migração | Sempre, depois de aplicar |
| `99_full_dump_FALLBACK.sql` | Dump completo do banco local (apenas referência / instalação fresca) | **NÃO importar em produção com dados** |

---

## Passo-a-passo seguro de import

### Passo 1 — Backup obrigatório da produção

```bash
mysqldump -u SEU_USER -p \
  --single-transaction \
  --routines \
  --triggers \
  --hex-blob \
  --skip-lock-tables \
  --default-character-set=utf8mb4 \
  NOME_DO_SEU_BANCO_PROD > backup_pre_v2_$(date +%F).sql
```

Verifique:
```bash
ls -lh backup_pre_v2_*.sql        # Tamanho > 1 MB esperado
tail -1 backup_pre_v2_*.sql       # Última linha deve ser "-- Dump completed"
```

**Sem este backup, NÃO PROSSIGA.**

### Passo 2 — Pre-check (read-only, captura baseline)

```bash
mysql -u SEU_USER -p NOME_DO_BANCO_PROD < 01_pre_check.sql > pre_check_output.txt
```

Abra `pre_check_output.txt` e **anote**:
- Contagens das tabelas-chave (teachers, attendance, audit_logs, etc) → vão ser comparadas no pós-check
- Quais migrations já estão em `applied_migrations` (se algumas já foram rodadas, são ignoradas pelo pacote idempotente)
- Quais colunas v2 já existem (algumas podem ter sido pré-aplicadas)

Se a produção **já tem** todas as colunas v2 (`client_id`, `checkout_client_id`, etc) e a tabela `teacher_trusted_devices`, a migração será essencialmente um no-op com `INSERT IGNORE` — completamente seguro.

### Passo 3 — Aplicar migração principal

```bash
mysql -u SEU_USER -p \
  --halt-on-error \
  --verbose \
  NOME_DO_BANCO_PROD < 02_migrate_v1_to_v2.sql 2>&1 | tee migrate_output.log
```

**Tempo esperado:** 30-90 segundos dependendo do tamanho da `attendance`.

**Se o script parar com erro:**
1. Leia a última linha do `migrate_output.log`.
2. Não tente re-executar imediatamente — analise.
3. Erro mais comum: `Illegal mix of collations` — execute primeiro:
   ```sql
   ALTER TABLE teacher_trusted_devices CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   e depois re-rode o pacote (é idempotente).

### Passo 4 — Post-check

```bash
mysql -u SEU_USER -p NOME_DO_BANCO_PROD < 03_post_check.sql > post_check_output.txt
```

**Comparar com pre-check:**
| Item | Esperado |
|------|----------|
| `teachers`, `attendance`, `audit_logs`, `hour_bank_entries`, etc | **mesmo valor ou maior** que pre-check (zero perda) |
| `app_settings` | +26 (gps + face + pin) |
| `attendance.client_id`, `checkout_client_id`, `client_recorded_at`, `offline_delay_seconds` | colunas presentes |
| `teachers.pin_hash` | `IS_NULLABLE = YES` |
| `audit_logs.admin_id` | `IS_NULLABLE = YES` |
| `teacher_trusted_devices` | tabela existe, populada com backfill |
| `permissions` | tabela existe (vazia ou populada) |
| `nsr_sequence.current_nsr` | igual a `MAX(attendance.nsr)` |
| `v_payslips_full.TABLE_TYPE` | `VIEW` |
| `min_checkout_gap_seconds` | `60` |
| Índices secundários | 40+ |
| Órfãos em `attendance->teachers` | 0 |

**Se algo divergir → seção Rollback abaixo.**

### Passo 5 — Smoke test funcional na aplicação

Em produção, com a aplicação rodando:

1. **Login admin** → dashboard carrega.
2. **Login colaborador** → my_timesheet carrega.
3. **Bater ponto colaborador** → attendance criado com NSR sequencial (verifique `MAX(nsr)` aumenta de 1).
4. **Bater saída em <60s após entrada** → recebe mensagem `checkout_too_soon` com countdown (regressão crítica que justificou esta migração).
5. **`api/get_receipt.php?id=X`** com colaborador A pedindo recibo do colaborador B → 403.
6. **Service Worker** ativo no DevTools → Application.

---

## Rollback

Se qualquer passo falhar gravemente:

```bash
mysql -u SEU_USER -p NOME_DO_BANCO_PROD < backup_pre_v2_$(date +%F).sql
```

`mysqldump` com `--single-transaction` faz dump consistente; o restore reverte exatamente para o estado pré-migração.

**Atenção:** se a aplicação ficou online e gravou dados entre o backup e o rollback, esses dados são perdidos. Faça o rollback **com a aplicação em manutenção**.

---

## Por que NÃO usar `99_full_dump_FALLBACK.sql` em produção com dados?

Esse arquivo é um `mysqldump` completo do meu banco local (`ponto_oeiras2`) com:
- `--add-drop-table` → DROPa tabelas existentes
- `INSERT INTO ...` com TODOS os 293 attendances locais, 108 teachers locais, etc.

**Importar isso em produção sobrescreve TODOS os dados de produção** com os dados do meu ambiente de teste. **Use apenas em servidor novo/vazio.**

---

## Lista das 13 migrations aplicadas pelo pacote

| Ordem | Arquivo | O que faz |
|-------|---------|-----------|
| 00 | (inline) | **DEDUP + UNIQUE em `app_settings.k`** — schema v1 não tinha UNIQUE; sem isto, INSERTs subsequentes duplicam settings em re-runs |
| 01 | `consolidate_schema_2026_04.sql` | Master: 30+ índices secundários, tabela `permissions`, 4 colunas em `applied_migrations`, fix collation drift, recovery `attendance.school_id` NULL via `teacher_schools`, remove linha corrompida `attendance.id=1754` se existir |
| 02 | `add_pin_and_trusted_devices.sql` | Cria `teacher_trusted_devices` + colunas `pin_changed_at`, `pin_self_enroll_allowed` em `teachers`. 10 settings PIN. |
| 02b | (inline) | `ALTER TABLE teacher_trusted_devices CONVERT TO ... utf8mb4_unicode_ci` (necessário para o backfill) |
| 03 | `add_device_token.sql` | Coluna `device_token` UNIQUE em `teacher_trusted_devices` |
| 04 | `add_attendance_client_id.sql` | Coluna `client_id` UNIQUE em `attendance` (idempotência checkin offline) |
| 05 | `add_attendance_checkout_client_id.sql` | Coluna `checkout_client_id` UNIQUE (idempotência checkout offline) |
| 06 | `add_client_recorded_at.sql` | Colunas `client_recorded_at`, `offline_delay_seconds` + backfill seguro |
| 07 | `add_gps_fallback_settings.sql` | 2 settings GPS (`gps_max_accuracy_m`, `gps_radius_extra_max_m`) via `INSERT IGNORE` |
| 08 | `set_face_thresholds_3rd_layer.sql` | **⚠️ SOBRESCREVE** 14 thresholds faciais com `ON DUPLICATE KEY UPDATE`. Se prod tunou manualmente, capture valores antes |
| 09 | `fix_audit_logs_nullable_admin.sql` | `audit_logs.admin_id` `NOT NULL → NULL` (permite eventos de colaborador) |
| 10 | `backfill_trusted_devices_2026_04_27.sql` | Enrollments retroativos: teacher+device com 3+ checkins nos últimos 30 dias |
| 11 | `2026_04_27_v1_to_v2_finalize.sql` | Inicializa `nsr_sequence` com `MAX(nsr)`, recria `v_payslips_full` como VIEW, relaxa `teachers.pin_hash` para NULL |
| 12 | `2026_04_27_add_min_checkout_gap.sql` | Setting `min_checkout_gap_seconds=60` (anti race condition entrada/saída ao mesmo tempo) |
| 13 | (inline) | `INSERT IGNORE INTO applied_migrations` registrando todas |

---

## Por que isso é seguro mesmo com dados reais

- **Idempotência:** todos os `ALTER TABLE` usam `ADD COLUMN IF NOT EXISTS`; índices usam `CREATE INDEX IF NOT EXISTS` (ou são silenciados pelo `--halt-on-error` se já existem).
- **`INSERT IGNORE` / `ON DUPLICATE KEY UPDATE`:** seeds não duplicam.
- **`SET FOREIGN_KEY_CHECKS = 0`** no início e restaurado no fim — evita problemas se alguma FK foi adicionada manualmente em produção.
- **Backfills usam `LEFT JOIN ... IS NULL`** — não duplicam dados existentes.
- **Schema-only no início, dados depois:** primeiro acertamos o schema, depois fazemos backfills sobre dados reais.

Testado em local: re-aplicação 3× sem erros, sem mudança de contagens nos dados preservados.

---

## Diferenças vs. v2 do código

Esta migração leva o BANCO ao schema esperado pelo código v2 deste repo. Se a aplicação na produção ainda for v1.0:

1. Aplique este pacote no banco.
2. **Em seguida**, faça deploy do código v2.0 (`api/checkin.php` com `nsr_sequence FOR UPDATE`, etc).

Aplicar só o banco sem o código continua funcional (v1 ignora colunas novas) — banco é forward-compatible. **Mas aplicar só o código sem o banco quebra**: v2 referencia colunas que não existem.

---

## Suporte

Se precisar:
- Inspecionar uma migration específica: `sql/migrations/<arquivo>.sql`
- Ver auditoria completa do que mudou: `sql/_archive/AUDITORIA_v1_to_v2_2026-04-27_RESUMO.md`
- Contato no repo: ver `CODEOWNERS` ou histórico de commits.

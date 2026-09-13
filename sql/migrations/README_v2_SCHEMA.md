# Schema v2.0 — Sumário Executivo

**Status**: 33 migrations aplicadas. Schema v2 esperado mapeado.
**Data**: 2026-04-27
**Documentação**: 3 arquivos criados

---

## Arquivos Gerados

1. **_v2_expected_schema.md** (6.5 KB)
   - Sumário de tabelas/colunas novas
   - Definições CREATE TABLE para novas tabelas
   - Checklist de migração
   - Índices críticos

2. **_v2_schema_details.txt** (8 KB)
   - Descrição detalhada de cada coluna nova
   - Razão de cada alteração
   - App settings criadas
   - Possíveis issues e soluções

3. **README_v2_SCHEMA.md** (este arquivo)
   - Sumário executivo
   - Links para documentação
   - Passos próximos

---

## O Que Mudou de v1 para v2

### ADIÇÕES CRÍTICAS

| Cambio | Impacto | Urgencia |
|--------|---------|----------|
| **9 tabelas novas** | +integridade de dados | CRÍTICA |
| **28 colunas em attendance** | +visibilidade/rastreio | CRÍTICA |
| **nsr (número sequencial)** | +ordem global | CRÍTICA |
| **device_fingerprint** | +suporte multi-aparelho | CRÍTICA |
| **trusted_devices** | +UX (skip face step-up) | CRÍTICA |
| **fraud_detection_log** | +segurança | IMPORTANTE |
| **class_periods + assignments** | +múltiplos check-ins/dia | IMPORTANTE |
| **audit_logs** | +rastreabilidade | IMPORTANTE |
| **PIN auth** | +flexibilidade | IMPORTANTE |
| **liveness verification** | +anti-spoofing | IMPORTANTE |

---

## Números

- **Novas tabelas**: 9
- **Tabelas modificadas**: 3 (attendance, teachers, applied_migrations)
- **Novas colunas em attendance**: 28
- **Novas colunas em teachers**: 5
- **Novos índices**: 20+
- **App settings criadas**: 27
- **Migrations executadas**: 33
- **Foreign keys**: 15+

---

## Próximos Passos

### 1. Validação (imediato)

```bash
# Verificar se todas as migrations foram aplicadas
SELECT COUNT(*) FROM applied_migrations;
# Esperado: ~33

# Listar migrations falhadas
SELECT filename, failure_reason FROM applied_migrations 
WHERE failure_reason IS NOT NULL;

# Validar tabelas novas existem
SHOW TABLES LIKE 'teacher_trusted_devices';
SHOW TABLES LIKE 'audit_logs';
```

### 2. Verificação de Schema (hoje)

```bash
# Contar colunas em attendance
SELECT COUNT(*) FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance';
# Esperado: ~50

# Validar indices críticos
SHOW INDEX FROM attendance;
SHOW INDEX FROM teacher_trusted_devices;
```

### 3. Testes (hoje/amanhã)

- Testar check-in via API (client_id, nsr, device_fingerprint)
- Testar trusted device enroll/reuse
- Testar PIN auth flow
- Testar audit_logs logging
- Testar liveness nonce cleanup

### 4. Backfill de Dados (já feito)

- backfill_trusted_devices_2026_04_27.sql (140+ aparelhos)
- Atualizações de attendance.school_id (consolidate_schema)

### 5. Performance (se necessário)

- Monitorar tamanho de attendance.data
- Considerar INPLACE para índices se tabela é grande
- Verificar free space em disco

---

## Integração com Código v2

### helpers.php usa:

- `run_auto_migrations()` — executa /sql/migrations/*.sql
- `log_audit_action()` — grava em audit_logs
- `check_permission()` — consulta permissions table
- `trusted_device_*()` — gerencia teacher_trusted_devices
- `delete_stale_liveness_nonces()` — cleanup

### api/checkin.php usa:

- attendance (INSERT/UPDATE com 28 cols novas)
- liveness_nonces (INSERT/DELETE)
- fraud_detection_log (INSERT)
- hour_bank_entries (INSERT/DELETE)
- auth_attempt_logs (INSERT)
- class_periods (SELECT)
- teacher_trusted_devices (SELECT)

### admin/*.php usa:

- attendance_edits (INSERT)
- audit_logs (INSERT/SELECT)
- class_periods (CRUD)
- teacher_class_assignments (CRUD)
- teacher_trusted_devices (SELECT/UPDATE)

---

## Diferenças Principais v1 ← → v2

```
Recurso              | v1       | v2              | Impacto
=================== + ======== + =============== + ==========
Multiplos check-ins | Nao      | Sim (periods)   | UX melhor
Audit de edicoes    | Nao      | Sim (tbl)       | Compliance
Log de acoes        | Nao      | Sim (audit_logs)| Rastreio
Auth attempts log   | Nao      | Sim             | Seguranca
Aparelhos confiados | Nao      | Sim             | UX melhor
PIN auth            | Nao      | Sim             | Flexibilidade
Liveness detection  | Nao      | Sim             | Anti-spoof
Numero sequencial   | Nao      | Sim (nsr)       | Ordem global
Device fingerprint  | Nao      | Sim             | Multi-dev
GPS mock detection  | Nao      | Sim             | Fraude
Offline sync idempo | Nao      | Sim (client_id) | Confiabilidade
```

---

## Documentação Disponível

- Leia **_v2_expected_schema.md** para sumário técnico
- Leia **_v2_schema_details.txt** para detalhes de cada coluna
- Verifique migrations em **/sql/migrations/ para definições exatas
- Veja **helpers.php::run_auto_migrations()** para lógica de aplicacao

---

## Suporte

Se encontrar issues de migração:

1. Verifique applied_migrations.failure_reason
2. Veja logs do PHP em /logs/php_errors.log
3. Rode consolidate_schema_2026_04.sql manualmente se parado
4. Valide FKs com:
   ```sql
   SELECT * FROM information_schema.KEY_COLUMN_USAGE 
   WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL;
   ```

---

Gerado: 2026-04-27
Análise completa do schema v2.0

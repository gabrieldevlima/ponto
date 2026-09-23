# Deploy em Produção — DEEDO Ponto

Guia para subir o sistema com confiabilidade e preservação total de dados.

## Como funciona o auto-migration

O arquivo [config.php](../config.php) chama `run_auto_migrations()` em **todo request HTTP**. A função:

1. Adquire um **advisory lock global** via `GET_LOCK` (30s timeout) — garante que **apenas um worker** roda migrations por vez. Outros workers concorrentes **pulam silenciosamente**.
2. Lista todos os arquivos `.sql` em `sql/migrations/` em ordem alfabética.
3. Pula os já aplicados (consulta `applied_migrations.filename`).
4. Para cada migration nova, executa multi-statement; em caso de erro, faz fallback statement-by-statement, tolerando erros idempotentes (`Duplicate column`, `Table exists`, etc.).
5. Grava no ledger `applied_migrations` com:
   - `filename` (chave única)
   - `content_sha` (SHA-256 do conteúdo)
   - `applied_at`, `duration_ms`, `statement_count`
   - `failure_reason` (preenchido se falhou — visível no admin)
6. Detecta **drift**: se o conteúdo do arquivo mudar após aplicar, loga warning (não re-executa — `filename` é a fonte da verdade).
7. **Não propaga exceções** — erro em uma migration não derruba o sistema; apenas é registrado.

## Antes de fazer o deploy

### 1. Backup do banco (obrigatório)

```bash
mysqldump --single-transaction --routines --triggers --events \
  -u <user> -p <db_name> > backup_pre_deploy_$(date +%Y%m%d_%H%M%S).sql
```

### 2. Confira as migrations que vão rodar

Antes do deploy, liste os arquivos novos em `sql/migrations/` que **ainda não estão** no banco de produção:

```sql
-- No banco de PRODUÇÃO:
SELECT filename, applied_at FROM applied_migrations ORDER BY filename;
```

Compare com `git ls-files sql/migrations/*.sql` no branch sendo deployado.

### 3. (Opcional) Janela de manutenção

Se você quiser controlar quando as migrations rodam (ex: 3h da manhã, fora do pico):

**Opção A — via env var no servidor:**
```bash
# Antes do deploy: desativa auto-migration
export PONTO_DISABLE_AUTO_MIGRATIONS=1
# Faz o deploy do código novo
git pull && composer install --no-dev (se aplicável)
# Aplica migrations manualmente quando quiser
mysql -u <user> -p <db> < sql/migrations/<arquivo>.sql
# Insira no ledger:
mysql -u <user> -p <db> -e "INSERT INTO applied_migrations (filename) VALUES ('<arquivo>.sql')"
# Reativa auto-migration
unset PONTO_DISABLE_AUTO_MIGRATIONS
```

**Opção B — confiar no auto-migration:**
- Apenas faz o deploy. Primeiro request HTTP que chegar dispara as migrations pendentes.
- Recomendado porque é mais simples e tem advisory lock; adoptção em prod é o caminho default.

## Passos do deploy

```bash
# 1. Backup obrigatório
mysqldump ... > backup.sql

# 2. Pull do código
git pull origin main

# 3. (se aplicável) Atualizar dependências
# composer install --no-dev --optimize-autoloader

# 4. Touch / restart do PHP-FPM ou Apache para limpar opcache
sudo systemctl reload php8.2-fpm
# ou Apache:
# sudo systemctl reload apache2

# 5. (opcional) Pré-aquecer o auto-migrate fazendo 1 request:
curl -s -o /dev/null https://seu-dominio.com/login.php

# 6. Verificar status no admin
# Acesse: https://seu-dominio.com/admin/migrations.php
```

## Após o deploy — verificação

1. **Acesse `/admin/migrations.php`** — confira que:
   - Pendentes = 0
   - Falhadas = 0
   - Drift = 0
2. **Smoke test crítico**:
   - Login funciona
   - Bater ponto registra (NSR sequencial)
   - Comprovante PDF abre
   - Minha Folha carrega com pontos do mês

## Tabela `applied_migrations` (ledger)

```sql
CREATE TABLE applied_migrations (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  filename        VARCHAR(255) NOT NULL UNIQUE,
  applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  content_sha     CHAR(64) NULL,        -- SHA-256 do conteúdo no momento de aplicar
  duration_ms     INT NULL,             -- tempo de execução
  statement_count INT NULL,             -- estimativa de statements
  failure_reason  TEXT NULL             -- preenchido se falhou (não é re-tentado)
);
```

## Como recuperar de uma migration que falhou

Cenário: uma migration foi parcialmente executada e gravou `failure_reason`.

1. **Acesse `/admin/migrations.php`** e leia a coluna "Motivo" da falha.
2. Decida se a migration precisa ser corrigida ou se já foi parcialmente OK:
   - Se **a mudança já foi aplicada** (ex: ALTER TABLE adicionou coluna), só precisa marcar como aplicada:
     ```sql
     UPDATE applied_migrations
        SET failure_reason = NULL,
            applied_at     = NOW()
      WHERE filename = '<arquivo>.sql';
     ```
   - Se a migration **NÃO** foi aplicada, corrija o arquivo SQL no repositório, faça novo deploy. O auto-migrate vai detectar drift mas **não re-executa** (filename é chave). Você precisa **deletar o registro** e re-deploy:
     ```sql
     DELETE FROM applied_migrations WHERE filename = '<arquivo>.sql';
     ```
   - Próxima request rodará a versão corrigida.

## Como verificar drift de arquivo

"Drift" = arquivo de migration foi editado **depois** de aplicado em produção. Não causa re-execução, mas a tela `/admin/migrations.php` exibe pill amarela.

```sql
-- Listar migrations com sha calculável diferente do gravado
SELECT filename, content_sha
  FROM applied_migrations
 WHERE content_sha IS NOT NULL
 ORDER BY applied_at DESC;
```

Compare com `sha256sum sql/migrations/*.sql` localmente.

**Política recomendada**: nunca editar migrations já aplicadas. Crie uma nova migration que **revisa** ou **substitui** o efeito da anterior.

## Migrations atuais (em ordem alfabética de filename)

| Arquivo | Efeito principal |
|---|---|
| `add_admins_cpf.sql` | CPF na tabela admins |
| `add_attendance_client_id.sql` | Idempotência check-in offline |
| `add_attendance_edit_tracking.sql` | Audit de edições admin |
| `add_attendance_edits_table.sql` | Tabela de edits |
| `add_audit_logs_table.sql` | Tabela audit_logs |
| `add_class_period_system.sql` | Periods de aula |
| `add_client_recorded_at.sql` | **Anti-fraude timestamp (S1)** |
| `add_device_token.sql` | **Cookie httpOnly de device** |
| `add_face_enrollment_tracking.sql` | Histórico de enroll de face |
| `add_gps_fallback_settings.sql` | **Settings de GPS (max_acc, radius_extra)** |
| `add_liveness_columns.sql` | Liveness (piscadas, etc) |
| `add_liveness_nonce_dedup.sql` | Anti-replay liveness |
| `add_manual_tracking_columns.sql` | Tracking de inserção manual |
| `add_overtime_support.sql` | Hora extra |
| `add_pending_reasons.sql` | JSON de motivos pendentes |
| `add_pending_reasons_simple.sql` | Versão simplificada |
| `add_photo_deleted_flag.sql` | Soft delete de fotos |
| `add_photo_deleted_flag_simple.sql` | — |
| `add_pin_and_trusted_devices.sql` | PIN + trusted devices |
| `fix_attendance_manual.sql` | Correções inserção manual |
| `fix_audit_logs_nullable_admin.sql` | admin_id NULL p/ logs de colaborador |
| `fix_collation_production.sql` | Charset/collation utf8mb4_unicode_ci |
| `fix_face_recognition_thresholds.sql` | Tunings antigos |
| `fix_missing_tables_and_columns.sql` | Sanity de schema |
| `harden_auth_and_attendance_schema.sql` | Constraints de schema |
| `harden_face_recognition_v3.sql` | Tunings antigos |
| `remove_pin_hash.sql` | (legado — não aplicável após PIN voltar) |
| `set_face_thresholds_3rd_layer.sql` | **Face permissiva (3ª camada)** |
| `unify_face_thresholds.sql` | Tunings antigos |
| `update_face_thresholds_hardened.sql` | Tunings antigos |

Em **negrito** as adicionadas/modificadas nesta sessão.

## Kill switch — desativar auto-migration

Se precisar parar o auto-migrate (deploy controlado, debug, manutenção):

```bash
# Apache via .htaccess ou httpd.conf:
SetEnv PONTO_DISABLE_AUTO_MIGRATIONS 1

# PHP-FPM via pool config:
env[PONTO_DISABLE_AUTO_MIGRATIONS] = 1

# Ou shell antes de rodar PHP CLI:
export PONTO_DISABLE_AUTO_MIGRATIONS=1
```

A função detecta a env var e retorna imediatamente, logando:
```
[migrations] desativado via PONTO_DISABLE_AUTO_MIGRATIONS=1
```

## Garantias de preservação de dados

1. **Backup obrigatório antes do deploy** — política de processo, não automática.
2. **Migrations idempotentes**: usam `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`. Re-executar é seguro.
3. **Filename como chave única**: mesmo se 10 workers tentarem rodar a mesma migration, só 1 ganha o lock; os outros pulam.
4. **failure_reason persistente**: nada some sem rastro — falha fica visível no admin.
5. **Lock + retry**: se outro worker está rodando (deploy em rolling), espera até 30s pelo lock.
6. **Sem propagação de exceção**: aplicação continua respondendo mesmo se uma migration falhar.

## Riscos conhecidos

- **DDL não é transacional em MySQL** (exceto Postgres). Uma migration grande que falha no meio pode deixar schema parcial. Mitigação: migrations devem fazer **uma coisa por vez**; arquivos grandes devem ser divididos.
- **Multi-statement via PDO::exec** depende de `EMULATE_PREPARES=true`. Já está habilitado na conexão de migração.
- **Drift não é re-aplicado**: se você editar um arquivo já aplicado, a nova versão é ignorada. Sempre crie uma nova migration.

---

## Migrations: como lidar com drift

**Drift** = o arquivo SQL em `sql/migrations/` foi modificado após ter sido aplicado.
Detectado via SHA-256 comparado com `applied_migrations.content_sha`.

### Sintomas
Na tela `admin/migrations.php`, a coluna "Status" mostra pill amarelo "**Drift**".

### Causas comuns
1. Devops corrigiu um SQL para arrumar bug, mas migration já tinha rodado em produção.
2. Conflito de merge no git deixou versão diferente do arquivo no servidor.
3. Edição manual indevida.

### Procedimento de re-aplicação

**Via UI (recomendado)**:
1. Acesse `/admin/migrations.php` como admin de rede.
2. Localize a migration com pill "Drift" no histórico.
3. Clique no botão laranja `↻` (Re-aplicar) ao lado do pill.
4. Confirme — o sistema remove a entrada de `applied_migrations` e força re-execução.

**Via SQL direto** (caso UI inacessível):
```sql
-- Remove a entrada do ledger
DELETE FROM applied_migrations WHERE filename = 'NOME_DA_MIGRATION.sql';

-- O próximo request HTTP em qualquer página gatilha config.php → run_auto_migrations(),
-- que vai detectar o arquivo "não aplicado" e executar.
```

### O que acontece no boot
- `run_auto_migrations()` é chamada em todo `config.php` (com lock global).
- Migrations já em `applied_migrations` são puladas pelo `filename`.
- Se `filename` foi removido, ela RODA de novo.
- Códigos de erro esperados na re-execução (1050 table exists, 1060 duplicate column, 1062 duplicate key, etc.) são tolerados — útil porque parte da migration já foi aplicada.

### Quando NÃO re-aplicar
- Se a alteração no arquivo é **destrutiva** (`DROP TABLE`, `TRUNCATE`) — a re-aplicação vai destruir dados de novo.
- Se o ambiente tem replicação master-slave — coordenar antes para evitar split-brain.
- Sempre fazer **backup do schema + dados afetados** antes de qualquer re-aplicação.

### Auditoria
Cada re-aplicação via UI gera registro em `audit_logs` com `action='reapply'`, `entity='migration'`, `payload={filename}`, IP e admin_id.

# Validação de deploy da versão local — 13/09/2026

**Versão validada:** commit `dee0f3e` (branch `feature/afastamento-abona-falta`)
**Produção atual:** código de junho + hotfix de 12/09 (sw `v2.59.0`)
**Ambiente de validação:** cópia do banco de produção (backup de 13/09 13:26) em **MariaDB 11.8.9**
com `sql_mode`, fuso e isolamento iguais aos de produção; PHP 8.3; lint no servidor com PHP 7.4 e 8.3.

## Veredito

**Pode ir para produção** depois dos pré-requisitos da seção 3. A primeira versão commitada
(`202ffd1`) **quebraria produção**. Os bloqueantes foram corrigidos em `e8b722a` e `dee0f3e` e
revalidados.

## 1. Bloqueantes encontrados e corrigidos

| # | Problema (evidência na cópia de produção) | Correção |
|---|---|---|
| 1 | Executor de migrações: 47 arquivos diferem só por CRLF/LF → **reexecução de 24+ migrações** no 1º acesso pós-deploy (ALTERs na `attendance`, uma com FK duplicada) | Hash normalizado para LF, aceitando hashes antigos |
| 2 | `SELECT` de verificação deixava resultado pendente → erro 2014 em **todas** as migrações seguintes: livro fiscal, triggers, LGPD e `role_permissions` **não eram criados** | `query()` + `nextRowset()` |
| 3 | Selo "nada pendente" gravado mesmo com falha → falhas nunca retentadas | Só grava com varredura limpa |
| 4 | Fila offline: token emitido com UA+fingerprint e verificado com `$ua` ainda indefinido → **todo ponto offline descartado** | Mesmo identificador (UA\|fingerprint, sem IP) nos dois lados |
| 5 | Expiração de "lembrar-me" por data de criação → **56 colaboradores ativos deslogados** no deploy | Backfill por último uso + validade deslizante |
| 6 | Interjornada contava a saída da manhã → **183 entradas de agosto (9%, 57 pessoas)** virariam pendência | Considera só dias anteriores |
| 7 | Livro fiscal em `sql_mode` não estrito: valor truncado quebra a cadeia (`payload_divergente`) | INSERT estrito + SAVEPOINT no modo shadow |
| 8 | Primeiro acesso liberado pelo admin gerava PIN sem cadastro facial (regressão do hotfix) | Restaurado |
| 9 | CSP global bloqueava o mapa da geocerca (`school_edit.php`) | CSP própria da página |
| 10 | `hlb_sync_status` recebia `stale`/`drift` (fora do ENUM) | Normalizado |

## 2. Resultados da validação final (`dee0f3e`)

| Verificação | Resultado |
|---|---|
| Migrações sobre a cópia de produção | 11 aplicadas, 0 falhas, 3,7 s; 2ª execução não faz nada |
| Integridade após migrar | 12.742 marcações e NSR intactos; 4 triggers; 417 tokens válidos (185 expirados = sem uso > 90 dias) |
| Funcional/segurança HTTP (31) | 31 OK |
| Concorrência real (9 cenários, 20 simultâneos) | 9 OK, NSR único e sem lacunas |
| Fila offline com token | sincroniza no aparelho de origem; recusa em outro aparelho |
| Cálculo de horas (102 colaboradores × 43 dias) vs produção | 4.378/4.386 iguais; as 8 diferenças são intervalos **anulados** que a produção atual soma indevidamente (bug NC-51, corrigido na versão local) |
| 52 páginas admin/colaborador com dados reais | mesmos códigos HTTP da produção, nenhum erro novo; dashboard 6,3 s → 0,4 s |
| Suíte de testes (MariaDB) | 32/37 sem chaves; com chaves de teste o livro fiscal, AFD, AEJ e LGPD passam; restantes dependem de dados de cadastro/registro de chave pública (ambiente) |
| Lint no servidor | PHP 7.4 (crons) e PHP 8.3 (todos) sem erro; `sodium` ativo no site |

## 3. Pré-requisitos obrigatórios do deploy

1. **`config.local.php` no servidor antes do novo `config.php`** — o `config.php` versionado não tem
   credenciais (root/sem senha). Criar `public_html/config.local.php` (fora do git) com `$servidor`,
   `$usuario`, `$senha`, `$banco` de produção. Sem isso: HTTP 500 em tudo.
2. **Não definir chaves agora** (`LEDGER_HMAC_KEY`, `BIOMETRIC_ENCRYPTION_KEY`…): com `ledger_mode=off`
   (padrão da migração) o sistema funciona sem elas. Chaves só no plano de ativação do livro fiscal,
   com cópia em cofre — perder `BIOMETRIC_ENCRYPTION_KEY` depois de cifrar inutiliza toda a biometria.
3. **Deploy atômico**: página de manutenção → extrair pacote único (tar) → validar sintaxe → retirar
   manutenção. Nunca arquivo a arquivo (mistura de `config.php`/`helpers.php`/`lib/` = fatal).
4. **Janela fora de 05h–08h** (migrações levam segundos, mas criam índices na `attendance`).
5. **Backup do banco e dos arquivos imediatamente antes** (o script de 12/09 já faz os dois).
6. **Depois do deploy**: conferir `applied_migrations` (73, 0 falhas), `SHOW TRIGGERS` (4),
   `logs/php_errors.log` e uma marcação real de teste.

## 4. Mudanças de comportamento para comunicar

- Marcações **offline** passam a ficar pendentes com "Horário não comprovado" quando o app foi
  reaberto sem rede (âncora de tempo NC-14) — horário gravado = sincronização. Avisar o RH.
- Comprovante deixa de afirmar conformidade (Portaria/LGPD) e mostra "não informado" em CPF do
  empregador, local e identificador do REP até o cadastro do empregador ser preenchido.
- `hlb_sync_status` = `failed` nas marcações até agendar `cron_hlb_sync.php`
  (`/opt/alt/php83/usr/bin/php`, a cada hora).
- Horas trabalhadas de agosto mudam para 6 colaboradores (intervalos anulados deixam de contar).
- Tokens "lembrar-me" sem uso há mais de 90 dias expiram (185).

## 5. Riscos residuais

- Livro fiscal permanece **desligado** após o deploy; ativá-lo exige backfill em janela própria,
  registro da chave pública e decisão sobre renumeração de NSR (ver auditoria 2026-08-05).
- `payroll.php` com parâmetro `month` malformado gera erro fatal (pré-existente, igual em produção).
- Scripts `bin/*.php` usam funções do PHP 8: rodar sempre com `/opt/alt/php83/usr/bin/php`.
- Comprovante em PDF leva ~5 s (pré-existente).

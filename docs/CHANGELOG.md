# Changelog - Sistema de Horas Extras

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/lang/pt-BR/).

---

## [3.0.0] - 2026-06-09 — Intervalo conta como tempo trabalhado ("cheio vs cheio")

### Mudanca de comportamento (BREAKING)
- O **intervalo nunca subtrai** o tempo trabalhado: é um direito do colaborador
  (almoço/descanso) e passa a ser **apenas informativo**. A "trabalhada" exibida e
  usada no saldo é a presença **cheia** (pares `work` + pares `break`).
- O **esperado** passa a ser a **janela completa** da jornada: modo `time` não
  desconta mais `break_minutes` (campo virou informativo); modo `hours` usa
  `total_minutes` direto.
- Removido o auto-desconto do intervalo previsto quando o colaborador não batia
  pausa (não há mais "tempo fixo de intervalo").
- Consequência aceita: quem bate **saída** no almoço (em vez do botão de
  intervalo) tem o tempo fora não contado; o gap entre dois `work` não é presença.

### Alterado
- `helpers.php`: `calculate_effective_worked_minutes()` retorna work + break
  (breaks contam mesmo pendentes); `calculate_expected_minutes()` modo `time`
  retorna a janela completa.
- `api/checkin.php`, `api/checkin_bulk.php`, `api/kiosk_checkin.php`: cálculo
  inline triplicado de esperado substituído por `calculate_expected_minutes()`
  (de quebra corrige o modo `hours` no checkout, que o inline não tratava).
- `api/checkin_bulk.php`: alinhado aos demais — delta positivo não credita mais o
  banco direto (vira candidato a hora extra).
- Telas e PDFs (attendances, reports, teacher_monthly_report, dashboard,
  reports_financial, my_timesheet): "Trabalhada" mostra o tempo cheio com o
  intervalo como linha informativa.
- `public/my_timesheet.php`: valor financeiro estimado usa o tempo cheio.

### Ferramentas
- `bin/recompute_hour_bank_auto.php`: reconcilia lançamentos `source='auto'`;
  flag `--only-breaks` restringe a dias com intervalo registrado. Executado em
  09/06/2026 (backup em `hour_bank_entries_bkp_20260609`).
- `bin/diag_hour_bank_down.php`: diagnóstico read-only de divergências.

---

## [2.0.0] - 2026-05-17 — Hora Extra Opt-In

### Mudanca de comportamento (BREAKING)
- A detecção automática **deixou de criar solicitações** na fila administrativa. Agora a solicitação so eh criada quando o colaborador clica em **"Solicitar hora extra"** com justificativa.

### Adicionado
- Coluna `overtime_requests.requested_by_employee TINYINT(1) NOT NULL DEFAULT 0` (`sql/migrations/2026_05_17_overtime_optin.sql`).
- Funcao `compute_overtime_exceedance()` em `helpers.php` — calcula minutos excedentes sem gravar.
- Funcao `create_overtime_request()` em `helpers.php` — grava a solicitacao quando o colaborador clica no botao.
- Endpoint `api/request_overtime.php` — POST {attendance_id, justification, csrf}; valida ownership, janela do mes corrente, recalcula minutos server-side.
- Modal compartilhado `public/js/overtime_request.js` — usado em `ponto.php` e `my_timesheet.php`.
- Bloco "Solicitar hora extra" na tela de sucesso de batida (`public/ponto.php`).
- Badges de status e botao de solicitacao retroativa na Minha Folha (`public/my_timesheet.php`).
- Linha "Hora extra" no comprovante (`public/receipt.php`).
- Filtro "Origem" (employee/legacy/all) e coluna "Justificativa" na tela admin (`public/admin/overtime.php`); padrao mostra so `requested_by_employee=1`.

### Removido / Renomeado
- Removida a função `detect_and_create_overtime()` (substituída pelas duas funcoes acima).
- Removida a chamada de auto-insercao em `api/checkin.php` (linha 1938) — agora retorna apenas flags `overtime_eligible`, `overtime_exceeded_min`, `overtime_eligible_reason`, `overtime_already_requested`, `overtime_request_status`.
- Renomeada `create_overtime_request()` (versao antiga, usada pelo atalho do admin para professores com grade) para `admin_create_overtime_record()` para resolver colisao de nome. Registros criados por essa via continuam com `requested_by_employee=0`.

### Compatibilidade
- Relatórios (`reports_financial.php`, `_tpl_payslip_pdf.php`, etc.) **nao mudam** — continuam filtrando por `status='approved'`.
- Registros legados em `overtime_requests` ficam com `requested_by_employee=0` (default) e sao ocultados pelo filtro padrao.
- A funcao `auto_reject_overtime_on_attendance_rejection()` continua intacta — rejeitar um ponto continua auto-rejeitando a solicitacao.

---

## [1.0.0] - 2025-10-10

### 🎉 Adicionado

#### Sistema de Horas Extras
- **Detecção Automática**: Sistema detecta automaticamente horas extras no momento do check-out
- **Tabela `overtime_requests`**: Nova tabela para armazenar solicitações de horas extras
  - Campos: `id`, `attendance_id`, `teacher_id`, `school_id`, `date`
  - Campos de controle: `minutes`, `expected_minutes`, `worked_minutes`
  - Campos de aprovação: `status`, `approved_by_admin_id`, `approved_at`, `rejection_reason`
  - Índices otimizados para queries frequentes

#### Funções Helper (helpers.php)
- `detect_and_create_overtime()`: Detecta e cria solicitações de hora extra
- `approve_overtime_request()`: Aprova hora extra e adiciona ao banco de horas
- `reject_overtime_request()`: Rejeita hora extra com motivo obrigatório
- `auto_reject_overtime_on_attendance_rejection()`: Auto-rejeita ao rejeitar ponto
- `calculate_expected_minutes()`: Calcula minutos esperados com suporte a modo 'time'
- `calculate_worked_minutes()`: Calcula minutos trabalhados aprovados

#### Interface Administrativa
- **Página `/admin/overtime.php`**: Interface completa de gerenciamento
  - Estatísticas em tempo real (total, pendentes, aprovadas, rejeitadas)
  - Filtros: status, colaborador, instituição, período
  - Paginação (50 registros por página)
  - Ações: aprovar/rejeitar com validações
  - Modal de rejeição com motivo obrigatório
  - Exibição de quem aprovou/rejeitou e quando

#### Menu de Navegação
- Item "Horas Extras" adicionado em todas as páginas admin:
  - attendances.php
  - dashboard.php
  - teacher_monthly_report.php
  - admins.php
  - school_edit.php
  - schools.php
  - teacher_edit.php
  - teachers.php
  - attendance_manual.php
  - leaves.php
  - reports_financial.php

#### API e Integração
- Modificação em `api/checkin.php`:
  - Detecção automática de horas extras no check-out
  - Notificação ao colaborador via resposta JSON
  - Separação de banco de horas normal e hora extra (delta > 0 = 0 no banco)
  
- Modificação em `public/admin/attendances_action.php`:
  - Auto-rejeição de horas extras ao rejeitar ponto
  - Auditoria completa da ação

#### Banco de Dados
- Novo valor `'overtime_approved'` no enum `hour_bank_entries.source`
- Índices otimizados:
  - `idx_overtime_teacher_date` (teacher_id, date)
  - `idx_overtime_status` (status)
  - `idx_overtime_attendance` (attendance_id)
  - `idx_overtime_date` (date)
- Foreign Keys com `ON DELETE CASCADE` para integridade referencial

#### Dashboard Melhorias
- Card "Trabalhando Agora" expandido com:
  - Cargo do colaborador
  - Instituição vinculada
  - Contador de tempo em tempo real (JavaScript)
  - Link para localização no Google Maps
  - Status ativo/inativo visual
  
#### Validações de Negócio
- Não permite aprovação de hora extra se ponto não estiver aprovado
- Não permite aprovação de hora extra já processada
- Motivo obrigatório para rejeição
- Validação de data de afastamentos (não permite datas futuras)
- Auto-rejeição de horas extras pendentes ao rejeitar ponto

#### Coluna Instituições
- Implementação de `GROUP_CONCAT` em `teachers.php`
- Exibição de todas as escolas vinculadas ao colaborador
- Badge especial para colaboradores "Toda a Rede"

#### Auditoria
- Registro completo de todas as ações em `audit_logs`
- Rastreabilidade de quem aprovou/rejeitou
- Timestamp de todas as operações

### 🔧 Modificado

#### helpers.php
- Função `calculate_expected_minutes()` agora suporta:
  - Modo `'classes'` (aulas × duração)
  - Modo `'time'` (horário início/fim - intervalo) ✨ **NOVO**
  - Afastamentos remunerados (retorna 0)

#### api/checkin.php
- Lógica de banco de horas modificada:
  - Antes: `delta` ia direto para `hour_bank_entries`
  - Agora: Se `delta > 0`, registra `0` no banco e cria `overtime_request`
  - Mantém comportamento para `delta <= 0` (negativo)

#### public/admin/leaves.php
- Validação de data adicionada:
  - Não permite criar afastamento com data inicial futura
  - Validação de data final >= data inicial

#### public/admin/teachers.php
- Query otimizada com `GROUP_CONCAT` para listar instituições
- Exibição visual melhorada de escolas vinculadas

#### public/admin/dashboard.php
- Query expandida para buscar:
  - Cargo do colaborador (`collaborator_types.name`)
  - Escola do ponto (`schools.name`)
  - Coordenadas de check-in (`check_in_lat`, `check_in_lng`)
- JavaScript adicionado para atualização de contadores em tempo real

### 🐛 Corrigido

- **Horas previstas modo 'time'**: Agora busca corretamente de `collaborator_time_schedules`
- **Faltas em datas futuras**: Validação impede marcação de afastamentos futuros
- **Duplicação de colunas**: Removida redundância em exibição de instituições

### 🔐 Segurança

- CSRF protection em todas as ações de horas extras
- Prepared statements em todas as queries SQL
- Validação de escopo de administrador (network_admin vs school_admin)
- Sanitização de inputs em filtros e formulários
- Escape de output em HTML (`esc()`)

### 📚 Documentação

- **OVERTIME_SYSTEM.md**: Documentação completa do sistema (100+ páginas)
  - Visão geral e características
  - Fluxo de funcionamento detalhado
  - Arquitetura e componentes
  - Schema do banco de dados
  - API e detecção automática
  - Interface administrativa
  - Regras de negócio
  - Guia de instalação
  - Segurança e auditoria
  - FAQ

- **INSTALLATION_GUIDE.md**: Guia passo a passo de instalação
  - Pré-requisitos
  - Instalação rápida (5 minutos)
  - Scripts SQL
  - Testes de validação
  - Troubleshooting
  - Rollback

- **API_EXAMPLES.md**: Exemplos práticos de uso
  - Endpoints e requests/responses
  - Funções helper com exemplos
  - Queries SQL úteis
  - Scripts de integração
  - Testes automatizados

- **CHANGELOG.md**: Histórico de mudanças (este arquivo)

- **FILES_MODIFIED.md**: Lista completa de arquivos modificados/criados

### 🎯 Performance

- Índices otimizados para queries frequentes
- Paginação em listagens (50 registros por vez)
- GROUP_CONCAT para reduzir queries N+1
- JavaScript assíncrono para contadores

### ✅ Testes

- Teste de detecção automática
- Teste de aprovação
- Teste de rejeição
- Teste de auto-rejeição
- Teste de validações
- Teste de permissões

---

## [Unreleased] - Planejado

### Em Desenvolvimento

- [ ] Relatório de custo de horas extras por departamento
- [ ] Exportação de horas extras para Excel/PDF
- [ ] Dashboard de analytics de horas extras
- [ ] Notificações por email quando hora extra é detectada
- [ ] Limite de horas extras por colaborador (configurável)
- [ ] Aprovação em dois níveis (gestor + RH)

### Backlog

- [ ] App mobile para aprovação de horas extras
- [ ] Integração com folha de pagamento
- [ ] Relatório de tendências de horas extras
- [ ] Previsão de horas extras baseada em histórico
- [ ] Sistema de alertas automáticos

---

## Versionamento

### Formato de Versão: MAJOR.MINOR.PATCH

- **MAJOR**: Mudanças incompatíveis com versões anteriores
- **MINOR**: Novas funcionalidades compatíveis
- **PATCH**: Correções de bugs compatíveis

### Tags Git

```bash
git tag -a v1.0.0 -m "Release: Sistema de Horas Extras"
git push origin v1.0.0
```

---

## Migração de Versões

### De 0.x para 1.0.0

#### Requisitos
- Backup do banco de dados
- PHP 7.4+ (recomendado PHP 8.0+)
- MySQL 5.7+ ou MariaDB 10.3+

#### Passos

1. **Backup**
   ```bash
   mysqldump -u user -p database > backup_pre_v1.0.0.sql
   ```

2. **Aplicar SQL**
   ```bash
   mysql -u user -p database < install.sql
   ```

3. **Verificar**
   ```sql
   SELECT COUNT(*) FROM overtime_requests;
   SHOW COLUMNS FROM hour_bank_entries WHERE Field = 'source';
   ```

4. **Testar**
   - Acesse `/admin/overtime.php`
   - Registre ponto com hora extra
   - Verifique detecção automática

#### Rollback (se necessário)

```bash
mysql -u user -p database < backup_pre_v1.0.0.sql
```

---

## Contribuindo

### Processo de Release

1. Atualizar `CHANGELOG.md` com mudanças
2. Atualizar versão em `composer.json` (se aplicável)
3. Criar tag de versão
4. Gerar release notes
5. Publicar documentação atualizada

### Convenção de Commits

```
tipo(escopo): descrição

[corpo opcional]

[rodapé opcional]
```

**Tipos:**
- `feat`: Nova funcionalidade
- `fix`: Correção de bug
- `docs`: Documentação
- `style`: Formatação
- `refactor`: Refatoração
- `test`: Testes
- `chore`: Manutenção

**Exemplo:**
```
feat(overtime): adiciona detecção automática de horas extras

Implementa lógica de detecção automática no momento do check-out.
Cria solicitação pendente e notifica colaborador.

Closes #123
```

---

## Suporte

- 📧 Email: suporte@deedo.com.br
- 🌐 Site: https://deedo.com.br
- 📚 Docs: https://docs.deedo.com.br

---

**Última atualização:** 2025-10-10  
**Versão atual:** 1.0.0


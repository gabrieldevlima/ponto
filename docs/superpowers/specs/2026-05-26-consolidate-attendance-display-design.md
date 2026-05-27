# Consolidação visual de registros de ponto com múltiplos intervalos

**Data:** 2026-05-26
**Status:** Design aprovado pelo usuário, aguardando plano de implementação
**Escopo:** Apresentação (PHP de exibição). Sem mudanças de schema, sem migração de dados.

## 1. Problema

Hoje a tabela `attendance` grava cada par check-in/check-out como uma linha
separada (`record_type='work'` ou `record_type='break'`, com `parent_attendance_id`
ligando breaks ao work pai). Um dia com 3 intervalos gera 4 registros (1 work + 3 breaks).

Os 4 lugares que listam ponto exibem cada um desses registros como uma linha/card
independente, fazendo parecer que o colaborador "bateu o ponto 4 vezes":

- [public/admin/attendances.php](../../../public/admin/attendances.php) — tabela do painel administrativo
- [public/my_timesheet.php](../../../public/my_timesheet.php) — minha folha do colaborador
- [public/admin/reports.php](../../../public/admin/reports.php) — relatórios HTML
- Templates PDF em [public/admin/](../../../public/admin/) — incluindo
  `_tpl_attendances_pdf.php`, `_tpl_reports_pdf.php`, `_tpl_payslip_pdf.php`,
  `_tpl_teacher_monthly_report_pdf.php` e quaisquer outros que listem ponto

O cálculo das horas trabalhadas (entrada − saída − intervalos) **já está correto**
hoje. O bug é puramente visual.

## 2. Objetivo

Apresentar **uma linha (ou card) por dia/colaborador** em todos os lugares acima,
com os intervalos consolidados em uma sub-estrutura (linha expansível, sub-linhas
indentadas ou lista interna ao card, conforme o veículo).

## 3. Decisão de arquitetura

Consolidação é responsabilidade **da camada de apresentação**, não do banco.
Os dados continuam gravados como hoje.

### Abordagens consideradas

| # | Abordagem | Prós | Contras | Veredito |
|---|---|---|---|---|
| A | Helper PHP centralizado (`consolidate_attendance_by_day`) chamado pelas 4+ telas | 1 fonte de verdade; testável isoladamente; reversível; não muda SQL | Adapta múltiplos arquivos de exibição | **Escolhida** |
| B | `GROUP BY` no SQL com `GROUP_CONCAT` | Query "limpa" | Perde detalhe dos breaks; precisa 2ª query para listá-los; queries ficam complexas e divergem entre as telas | Rejeitada |
| C | Criar VIEW no MySQL | PHP fica simples | Muda schema; difícil de versionar/debugar; ainda exige query separada para breaks | Rejeitada |

## 4. Componente novo: `consolidate_attendance_by_day`

**Local:** [helpers.php](../../../helpers.php)

**Assinatura:**

```php
function consolidate_attendance_by_day(array $rows): array
```

**Entrada:** lista crua de registros de `attendance` (mesma estrutura que as queries
atuais já retornam, com colunas como `id`, `teacher_id`, `date`, `check_in`,
`check_out`, `record_type`, `parent_attendance_id`, `sequence_number`,
`teacher_name`).

**Saída:** array de dias consolidados (1 entrada por par `(date, teacher_id)`):

```php
[
  'date'                => '2026-05-26',
  'teacher_id'          => 42,
  'teacher_name'        => 'João',
  'check_in'            => '08:00:00',
  'check_out'           => '18:00:00',  // null se dia ainda em aberto
  'breaks'              => [
    ['start' => '12:00:00', 'end' => '13:00:00', 'duration_minutes' => 60,  'raw_id' => 101],
    ['start' => '15:00:00', 'end' => '15:15:00', 'duration_minutes' => 15,  'raw_id' => 102],
    ['start' => '17:00:00', 'end' => '17:30:00', 'duration_minutes' => 30,  'raw_id' => 103],
  ],
  'total_break_minutes' => 105,
  'total_worked_minutes'=> 495,
  'status'              => 'closed',   // 'closed' | 'in_progress' | 'orphan'
  'raw_rows'            => [...],      // referência aos registros originais (edição/auditoria)
]
```

### Algoritmo

1. Agrupar registros por chave `"{date}_{teacher_id}"`.
2. Dentro de cada grupo:
   - `record_type='work'` → define `check_in` (primeiro work do dia) e `check_out`
     (último work do dia). Se houver 2+ works no dia, o intervalo entre o
     `check_out` de um e o `check_in` do próximo vira um item de `breaks[]`
     com `raw_id=null` (não é registro real, é inferido).
   - `record_type='break'` → entra em `breaks[]` com o `id` real do registro
     em `raw_id`.
3. Ordenar `breaks` por `start` ascendente.
4. Calcular:
   - `total_break_minutes` = soma das durações dos breaks finalizados
     (`end` não-nulo).
   - `total_worked_minutes` = (último `check_out` − primeiro `check_in`) − `total_break_minutes`.
5. Determinar `status`:
   - `'in_progress'` se algum work ou break do dia está sem `check_out`.
   - `'orphan'` se há breaks mas nenhum work.
   - `'closed'` caso contrário.

### Edge cases

| Cenário | Comportamento |
|---|---|
| Dia com work ainda aberto | `check_out=null`, `status='in_progress'`, UI mostra "—" no campo saída |
| Break em aberto (sem `end`) | Aparece em `breaks[]` com `end=null`, duração não soma em `total_break_minutes` |
| Breaks órfãos (sem work pai) | `status='orphan'`, bucket exibido com aviso visual |
| Dia com 2+ works (lançamento manual) | Primeiro=entrada, último=saída; tempo entre `check_out` de um work e `check_in` do próximo vira break inferido (`raw_id=null`) |
| Múltiplos dias / múltiplos teachers | Cada par `(date, teacher_id)` gera um item independente |

## 5. UX por consumidor

### 5.1 `public/admin/attendances.php` — painel admin

- 1 `<tr>` por dia/colaborador.
- Coluna "Intervalos" mostra `3 (1h45m) ▸`.
- Click expande adicionando um `<tr class="break-detail">` abaixo, com a lista.
- JS puro (toggle de classe), sem framework.
- Botões de edição existentes ficam visíveis na linha expandida, ligados a `raw_id`
  de cada break e ao work pai.

### 5.2 `public/my_timesheet.php` — minha folha (colaborador)

- 1 card por dia (substitui o comportamento atual de N cards por dia).
- Cabeçalho: data + dia da semana + badge de status.
- Linha de totais: Entrada • Saída • Trabalhado • Intervalos (total + contagem).
- Sub-bloco "Intervalos" com lista `• HH:MM → HH:MM (Xh Ym)`.
- Mantém badges de "atraso" / "saiu mais cedo" se hoje exibe.

### 5.3 `public/admin/reports.php` — relatórios HTML

- Mesma tabela do admin, **sem** expand (todas linhas visíveis).
- Cada dia: 1 `<tr>` principal + N `<tr class="break-row">` indentadas.
- Totais do período recalculados a partir da estrutura consolidada.

### 5.4 PDFs

Aplica-se a todos os templates que listem registros de ponto:

- `_tpl_attendances_pdf.php`
- `_tpl_reports_pdf.php`
- `_tpl_payslip_pdf.php`
- `_tpl_teacher_monthly_report_pdf.php`
- Outros templates encontrados durante a implementação (será feito grep de
  `record_type` / `parent_attendance_id` para mapear)

Padrão:

- Linha principal + sub-linhas indentadas (caractere `·` ou `↳`).
- Larguras de coluna ajustadas para caber numa linha.
- Rodapé com totais do período.
- Não quebrar um dia entre páginas (`KeepTogether` ou equivalente no TCPDF).

## 6. Testes

Projeto é PHP procedural sem framework de testes detectado. Será criado um arquivo
de testes executável diretamente via PHP CLI:

**Local:** `tests/test_consolidate_attendance.php`
**Execução:** `php tests/test_consolidate_attendance.php`

Casos:

1. Dia sem intervalos (só 1 work) → 0 breaks, totais OK.
2. Dia com 1 intervalo → 1 break, totais OK.
3. Dia com 3 intervalos → 3 breaks ordenados, totais OK.
4. Dia com work em aberto → `status='in_progress'`.
5. Dia com break em aberto → break com `end=null`, não soma duração.
6. Dia com break órfão (sem work) → `status='orphan'`.
7. Dia com 2 works (manual) → primeiro=entrada, último=saída.
8. Múltiplos dias e múltiplos teachers → agrupamento correto.

## 7. Riscos

| Risco | Mitigação |
|---|---|
| Quebrar relatórios em uso | Função pura sem efeitos colaterais; arquivos antigos só trocam o loop de renderização — queries SQL não mudam |
| Cálculo divergir do atual | Smoke test em dados reais: comparar totais antes/depois antes de promover |
| Botões de edição perderem referência | Estrutura preserva `raw_id` em cada break e `raw_rows[]` no dia |
| PDF estourar largura ou quebrar mal | Testar com mês cheio (≥20 dias, vários com 3+ intervalos) antes de aprovar |
| Outras telas consumindo `attendance` não listadas | `grep` de `record_type` e `parent_attendance_id` na fase de implementação para mapear consumidores faltantes |

## 8. Plano de validação manual

1. Subir colaborador de teste com 1 dia conhecido: 3 intervalos com tempos exatos.
2. Conferir os 4 lugares + todos os templates PDF: cada um exibe 1 linha/card por
   dia, com os 3 breaks expansíveis/listados.
3. Conferir totais: trabalho = saída − entrada − Σbreaks.
4. Caso existente de produção (dado real): tela antiga (snapshot) e nova devem
   mostrar os **mesmos** totais.

## 9. Fora de escopo

- Migração de dados (modelo work + breaks permanece).
- Mudança no fluxo de check-in/check-out.
- Refatoração do schema da tabela `attendance`.
- Telas/APIs que não exibem listas consolidadas (ex.: tela de bater ponto, APIs
  de check-in).

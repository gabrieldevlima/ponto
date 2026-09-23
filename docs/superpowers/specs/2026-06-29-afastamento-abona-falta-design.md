# Afastamento que abona vs. não abona a falta — Design

**Data:** 2026-06-29
**Status:** Aprovado (aguardando revisão do spec)

## Problema

Hoje, ao cadastrar um afastamento, o sistema decide se aquele dia abona a falta
com base no **tipo** do afastamento: a lógica zera a jornada prevista quando
`leaves.approved = 1` **E** `leave_types.paid = 1`. Ou seja, "remunerado"
(`paid`) e "abona a falta" são tratados como o mesmo conceito.

Isso impede registrar uma ausência apenas como **registro administrativo** sem
abonar a falta. Exemplo: o colaborador faltou um dia para resolver um problema
pessoal. A instituição quer registrar o motivo no histórico, mas **não** existe
justificativa comprobatória válida — logo o dia deve **continuar contando como
falta** na frequência, folha, ponto e relatórios.

## Objetivo

Permitir que cada afastamento, individualmente, defina se **abona a falta**
(justificativa válida → desconsidera a falta) ou **não abona** (apenas registro
do motivo → o dia continua como falta). Desacoplar esse conceito da flag de
remuneração `paid`.

## Modelo conceitual

Separar dois conceitos hoje confundidos:

- `leave_types.paid` → passa a significar **apenas remuneração** (rótulos, folha).
  Não é mais critério para abonar falta.
- **Novo campo por afastamento `leaves.excuses_absence`** ("Este afastamento
  abona a falta? Sim/Não") → passa a ser o **único** critério que decide se a
  jornada prevista do dia é zerada.

### Os 3 buckets de relatório

| Situação | Como é detectada | Conta falta? | Gera desconto? |
|---|---|---|---|
| **Afastamento abonado** | afastamento aprovado com `excuses_absence = 1` cobrindo o dia | Não | Não |
| **Falta justificada** | dia sem ponto + existe afastamento com `excuses_absence = 0` cobrindo o dia | **Sim** | **Sim** |
| **Falta não justificada** | dia sem ponto + **nenhum** afastamento no dia | **Sim** | **Sim** |

Não há campo de justificativa separado do afastamento: "falta justificada" é,
por definição, uma falta que possui um afastamento não-abonado registrado no dia.

### Efeito de "não abona"

Tratamento idêntico a uma ausência sem afastamento: o dia mantém a jornada
prevista (`expectedMin` original), portanto:
- conta **falta** na frequência;
- gera **déficit/desconto integral** na folha e no banco de horas.

O afastamento serve apenas como **registro histórico do motivo**.

## Mudança no banco de dados

```sql
-- Servidor: MySQL 8.4 (não suporta ADD COLUMN IF NOT EXISTS).
ALTER TABLE leaves
  ADD COLUMN excuses_absence TINYINT(1) NULL DEFAULT NULL AFTER approved;

-- Backfill idempotente: preenche apenas linhas ainda NULL, preservando o
-- comportamento atual (tipo "pago" abonava) e sem sobrescrever edições futuras.
UPDATE leaves l JOIN leave_types lt ON lt.id = l.type_id
  SET l.excuses_absence = lt.paid
  WHERE l.excuses_absence IS NULL;
```

A migração é um arquivo idempotente em `sql/migrations/`, auto-aplicado pelo
runner do `helpers.php`. A coluna é **nullable** e o backfill só toca linhas
`NULL`, de modo que reexecuções nunca sobrescrevem valores editados depois — a
idempotência do `ALTER` vem da tolerância do runner ao erro 1060 (coluna
duplicada).

## Formulário de cadastro (`public/admin/leaves.php`)

Campos já existentes e reaproveitados: Tipo, Início, Fim, Descrição Detalhada
(motivo), Observações Internas, Anexo / Documento, Status (aprovação).

Adicionar o controle novo:

- **"Este afastamento abona a falta?"** — par de radios **Sim / Não**.
- **Default:** acompanha a flag `paid` do tipo escolhido (tipo `paid` →
  pré-seleciona "Sim"; senão "Não"), via JS no `change` do select de tipo.
  Sempre editável pelo usuário.
- Texto de ajuda: *"Sim = justificativa válida, desconsidera a falta. Não =
  registra o motivo, mas o dia continua contando como falta."*

Mudanças associadas:
- Tratar/validar `excuses_absence` no POST (INSERT e UPDATE), tanto no fluxo com
  anexo quanto sem anexo.
- Incluir o campo no `get_leave_details.php` para preencher na edição.
- Incluir o valor no `audit_log` de create/update.

## Mudança de lógica (núcleo)

Em **todos** os pontos que hoje zeram a jornada prevista com base em `paid`,
trocar o critério:

> de `approved = 1 AND lt.paid = 1`
> para `approved = 1 AND l.excuses_absence = 1`

**O acoplamento com `approved` é mantido:** um afastamento só abona se estiver
**aprovado**. Pendente/rejeitado nunca abona — o dia segue como falta até a
aprovação. É o comportamento atual e o mais seguro.

### Pontos a alterar

1. **Núcleo:** `calculate_expected_minutes` (`helpers.php`, ~linha 1852). Como é
   a função central, **folha, frequência, banco de horas e o timesheet do
   colaborador** (`my_timesheet.php`, APIs de check-in) herdam a correção
   automaticamente.
2. **Relatórios com query própria de afastamento** (cada um repete a lógica):
   - `public/admin/reports.php` (~linha 160–222)
   - `public/admin/reports_financial.php` (~linha 177)
   - `public/admin/teacher_monthly_report.php`
   - `public/admin/reports_insights.php`
   - `public/admin/dashboard.php`
3. **Templates PDF** (exibição):
   - `public/admin/_tpl_reports_pdf.php`
   - `public/admin/_tpl_financial_pdf.php`
   - `public/admin/_tpl_teacher_monthly_report_pdf.php`

Cada arquivo do grupo 2/3 deve buscar `l.excuses_absence` (ou continuar trazendo
`lt.paid` apenas para rótulos) e usar `excuses_absence` como critério de zerar a
jornada/marcar falta.

## Apresentação nos relatórios

- O dia de **afastamento não abonado** passa a aparecer como **FALTA** (hoje, se
  o tipo fosse `paid`, o dia desaparecia da contagem de faltas).
- Manter ao lado da falta um selo com o **tipo/motivo do afastamento**, deixando
  claro que é "falta justificada" (tem motivo registrado) e não falta seca.
- Bloco/legenda de resumo separando os 3 grupos: **Afastamentos abonados ·
  Faltas justificadas (com afastamento) · Faltas não justificadas (sem registro)**.

## Decisões fixadas

1. **Default do toggle** = segue a flag `paid` do tipo escolhido; editável por
   registro.
2. **Abono exige aprovação** (`approved = 1`); pendente/rejeitado não abona.
3. `leave_types.paid` **permanece** com o significado de remuneração/rótulos;
   nada é removido ou renomeado.
4. **Backfill** preserva 100% do comportamento atual para os dados existentes.

## Alternativas consideradas e descartadas

- **Reusar `paid` por tipo com override por registro:** mantém os dois conceitos
  acoplados na origem; não resolve a confusão entre remuneração e abono.
- **Enum de 3 estados em vez de boolean:** desnecessário — a distinção
  "justificada vs não justificada" deriva da existência (ou não) do afastamento,
  não de um terceiro estado no próprio afastamento (YAGNI).
- **Campo de justificativa avulsa no dia (separado do afastamento):** rejeitado
  na fase de design — o modelo de 3 buckets não exige um quarto bucket.

## Testes

- **Unit (`tests/`):** `calculate_expected_minutes` com afastamento aprovado
  `excuses_absence = 1` (deve zerar) vs `excuses_absence = 0` (deve manter),
  cruzando com `paid` 0/1 para provar o desacoplamento entre remuneração e
  abono. Incluir caso afastamento `excuses_absence = 1` porém **não aprovado**
  (não deve abonar).
- **Verificação manual:** relatório mensal e financeiro exibindo os 3 buckets
  corretamente; folha aplicando o desconto no dia "não abona".

## Fora de escopo

- Alterações na tela de tipos de afastamento (`leave_types.php`).
- Qualquer renomeação ou remoção da flag `paid`.
- Notificações/aprovação em fluxo (workflow de aprovação permanece como está).

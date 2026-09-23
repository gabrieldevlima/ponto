# Design — Informar volta do intervalo (self-service)

Data: 2026-06-16

## Contexto / Problema

Colaboradores frequentemente **esquecem de registrar a volta do intervalo**, deixando um
registro de intervalo **aberto** (`attendance.record_type='break'` com `check_out IS NULL`).
Consequências hoje:

- A ação "retornar do intervalo" (`retornar_intervalo`) grava a volta **na hora atual** —
  quem esqueceu por horas fica com um intervalo gigante e errado.
- A **saída** fica **bloqueada** enquanto há intervalo aberto (`break_open_cannot_clock_out`),
  virando um beco sem saída na tela de bater ponto.

A infraestrutura de correção de intervalo já existe (`edit_own_break_direct`,
`regularize_break`, modal em "Minha Folha"), mas **falta** deixar o colaborador **informar a
hora real da volta no momento que importa** (ao voltar / ao tentar sair), de forma direta.

## Decisões (confirmadas com o usuário)

- **Direto, sem aprovação** — o colaborador informa a volta e vale na hora; tudo auditado.
- **Aparece em dois lugares:** tela de bater ponto **e** Minha Folha.
- Abordagem **A**: ação dedicada que fecha o intervalo aberto na hora informada (reuso de
  `edit_own_break_direct`). Rejeitadas: (B) embutir hora real no `retornar_intervalo` —
  mistura com anti-fraude/quiosque; (C) auto-fechar por heurística — arriscado e não é o
  colaborador informando.

## Escopo da ação

A ação nova atua **exclusivamente em intervalos abertos** (`check_out IS NULL`) — é
"informar a volta". A edição de intervalos **já fechados** continua pelo fluxo atual
(modal de correção em Minha Folha: direto no mesmo dia, solicitação com aprovação no passado).

## Backend (reuso)

Arquivos: `helpers.php`, `api/edit_own_break.php`.

- Caminho self-service para **fechar um intervalo aberto** informando a volta, válido para o
  intervalo de **hoje ou de um dia anterior** esquecido (o `edit_own_break_direct` atual é
  limitado ao mesmo dia; generalizar **apenas** para o caso `check_out IS NULL`).
- **Validações:**
  - Registro pertence ao colaborador logado, `record_type='break'`, `check_out IS NULL`.
  - Hora da volta **> início do intervalo** (`check_in`) e **<= agora** (sem futuro).
  - Janela sã: início do intervalo dentro de `open_checkin_window_hours` (padrão ~30h) —
    evita fechar registros absurdamente antigos por essa via (esses vão para o fluxo de
    regularização com admin).
- **Justificativa opcional** (padrão: "Volta de intervalo informada pelo colaborador") — para
  manter sem fricção.
- **Auditoria:** grava em `attendance_edits` (tipo `break_return_self`, `edited_by=NULL` =
  edição do colaborador) e chama `recalculate_hour_bank_for_attendance()`.
- CSRF verificado (padrão dos endpoints).

## Surface 1 — Tela de bater ponto (`index.php` / `ponto.php`)

- Estado **"em intervalo"**: além de **"Voltei agora"** (fluxo normal `retornar_intervalo`),
  um botão **"Informar outra hora"** → seletor de horário → fecha o intervalo na hora
  informada via o endpoint de correção (sem refazer geo/face, pois é a hora passada da volta).
- **Saída com intervalo aberto:** em vez do erro seco, mostrar o mesmo diálogo
  "informe a hora que você voltou"; ao confirmar, o intervalo fecha e a saída é liberada.

## Surface 2 — Minha Folha (`my_timesheet.php`)

- Garantir que **intervalos abertos** ofereçam o "informar volta" (campo de início travado,
  apenas a volta a preencher), reusando o mesmo caminho direto.
- Edição de intervalos fechados: inalterada.

## Casos de borda

- Volta no futuro / antes do início → erro de validação claro.
- Intervalo aberto muito antigo (fora da janela) → orienta a usar a regularização (admin).
- Após fechar o intervalo, recomputar banco de horas do dia (o saldo/situação mensal reflete).
- Não confundir com edição de intervalo já fechado (essa ação só fecha `check_out IS NULL`).

## Testes

1. Fechar intervalo aberto **do mesmo dia** com hora informada → `check_out` setado, banco recomputado.
2. Fechar intervalo aberto **de dia anterior** (dentro da janela) → idem, sem aprovação.
3. Rejeitar volta **no futuro**, **antes do início**, e **fora da janela**.
4. Após informar a volta, a **saída deixa de ser bloqueada**.
5. Auditoria registrada em `attendance_edits` (tipo `break_return_self`).

## Fora de escopo

- Quiosque (dispositivo compartilhado) — a ação de informar hora passada fica no portal do
  próprio colaborador.
- Alterar o fluxo de hora extra (removido em mudança anterior) ou o cálculo "cheio vs cheio".

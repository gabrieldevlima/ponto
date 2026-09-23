<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

function hm_val(?string $dt): string
{
  if (!$dt) return '';
  $ts = strtotime($dt);
  return $ts ? date('H:i', $ts) : '';
}

function load_attendance_scoped(PDO $pdo, int $id)
{
  list($scopeSql, $scopeParams) = admin_scope_where('t');
  $sql = "
    SELECT a.*, t.name AS teacher_name, t.id AS teacher_id,
           COALESCE(NULLIF(ed.name, ''), ed.username) AS edited_by_username
    FROM attendance a
    JOIN teachers t ON t.id = a.teacher_id
    LEFT JOIN admins ed ON ed.id = a.editado_por
    WHERE a.id = ? AND $scopeSql
  ";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([$id], $scopeParams));
  return $st->fetch(PDO::FETCH_ASSOC);
}

// Linhas reais do dia (períodos de trabalho + intervalos), sem removidos/duplicatas.
function load_day_rows(PDO $pdo, int $teacherId, string $date): array
{
  $st = $pdo->prepare("SELECT * FROM attendance
                       WHERE teacher_id = ? AND date = ?
                         AND superseded_by_id IS NULL AND removed_at IS NULL
                       ORDER BY check_in ASC, id ASC");
  $st->execute([$teacherId, $date]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

$errors = [];
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  flash_redirect('error', 'ID de registro inválido.', 'attendances.php');
}

// O ?id= apenas faz o bootstrap: dele descobrimos o dia (colaborador + data) e
// editamos o DIA INTEIRO — períodos de trabalho + intervalos.
$att = load_attendance_scoped($pdo, $id);
if (!$att) {
  flash_redirect('error', 'Registro não encontrado ou sem permissão.', 'attendances.php');
}

$teacherId = (int)$att['teacher_id'];
$origDate  = (string)$att['date'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals((string)($_POST['csrf'] ?? ''), (string)csrf_token())) {
    flash_redirect('error', 'Sessão expirada. Tente novamente.', 'attendances.php');
  }

  $collect = function (string $key): array {
    $out = [];
    foreach (($_POST[$key] ?? []) as $r) {
      if (!is_array($r)) continue;
      $out[] = [
        'id'     => (int)($r['id'] ?? 0),
        'start'  => trim((string)($r['start'] ?? '')),
        'end'    => trim((string)($r['end'] ?? '')),
        'remove' => !empty($r['remove']),
      ];
    }
    return $out;
  };
  $postWorks  = $collect('works');
  $postBreaks = $collect('breaks');

  $payload = [
    'teacher_id' => (int)($_POST['teacher_id'] ?? $teacherId),
    'orig_date'  => trim((string)($_POST['orig_date'] ?? $origDate)),
    'date'       => trim((string)($_POST['date'] ?? '')),
    'method'     => trim((string)($_POST['method'] ?? '')),
    'reason'     => trim((string)($_POST['reason'] ?? '')),
    'works'      => $postWorks,
    'breaks'     => $postBreaks,
  ];

  try {
    $res = admin_save_attendance_day($pdo, (int)$admin['id'], $payload);
    $parts = [];
    $sumPt = function (array $b, string $sing, string $plu) {
      $out = [];
      if ($b['added'])   $out[] = count($b['added'])   . ' ' . ($plu) . ' adicionado(s)';
      if ($b['updated']) $out[] = count($b['updated']) . ' ' . ($plu) . ' ajustado(s)';
      if ($b['removed']) $out[] = count($b['removed']) . ' ' . ($plu) . ' removido(s)';
      return $out;
    };
    $parts = array_merge($parts, $sumPt($res['works'], 'período', 'período(s)'), $sumPt($res['breaks'], 'intervalo', 'intervalo(s)'));
    if (!empty($res['date_changed']))   $parts[] = 'data alterada';
    if (!empty($res['method_changed'])) $parts[] = 'método alterado';
    $msg = 'Ponto do dia corrigido com sucesso' . ($parts ? ' (' . implode(', ', $parts) . ')' : '') . '.';
    header('Location: attendances.php?msg=' . urlencode($msg));
    exit;
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

// ===== Valores para o formulário =====
$dayRows = load_day_rows($pdo, $teacherId, $origDate);
$works = [];
$dayBreaks = [];
foreach ($dayRows as $row) {
  if (($row['record_type'] ?? 'work') === 'break') $dayBreaks[] = $row;
  else $works[] = $row;
}

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($isPost) {
  $val_date   = (string)($_POST['date'] ?? $origDate);
  $val_method = (string)($_POST['method'] ?? '');
  $val_reason = (string)($_POST['reason'] ?? '');
} else {
  $val_date   = $origDate;
  $val_method = (string)($works[0]['method'] ?? $att['method'] ?? '');
  $val_reason = '';
}

// Monta as linhas (sticky no POST, banco no GET).
$rowsFromPost = function (string $key): array {
  $out = [];
  foreach (($_POST[$key] ?? []) as $r) {
    if (!is_array($r)) continue;
    $rid = (int)($r['id'] ?? 0);
    $s = trim((string)($r['start'] ?? ''));
    $e = trim((string)($r['end'] ?? ''));
    if ($rid <= 0 && $s === '' && $e === '') continue;
    $out[] = ['id' => $rid, 'start' => $s, 'end' => $e, 'remove' => !empty($r['remove'])];
  }
  return $out;
};
$rowsFromDb = function (array $rows): array {
  $out = [];
  foreach ($rows as $r) {
    $out[] = ['id' => (int)$r['id'], 'start' => hm_val($r['check_in'] ?? null), 'end' => hm_val($r['check_out'] ?? null), 'remove' => false];
  }
  return $out;
};
$workRows  = $isPost ? $rowsFromPost('works')  : $rowsFromDb($works);
$breakRows = $isPost ? $rowsFromPost('breaks') : $rowsFromDb($dayBreaks);

$statusLbl = ($att['approved'] === null) ? 'Pendente' : ((int)$att['approved'] === 1 ? 'Aprovado' : 'Rejeitado');
$statusClass = ($att['approved'] === null) ? 'warning' : ((int)$att['approved'] === 1 ? 'success' : 'danger');

// HTML de uma linha (período ou intervalo). $grp = 'works'|'breaks'.
function render_seg_row(string $grp, int $k, array $r, string $lblRemoverPadrao = 'Remover'): string
{
  $rm = !empty($r['remove']);
  $idv = (int)$r['id'];
  ob_start(); ?>
  <div class="seg-row row g-2 align-items-end mb-2 <?= $rm ? 'is-removed' : '' ?>">
    <input type="hidden" name="<?= $grp ?>[<?= $k ?>][id]" value="<?= $idv ?>">
    <input type="hidden" name="<?= $grp ?>[<?= $k ?>][remove]" value="<?= $rm ? '1' : '' ?>" class="seg-remove-flag">
    <div class="col-6 col-md-4">
      <label class="form-label small mb-1"><?= $grp === 'works' ? 'Entrada' : 'Início' ?></label>
      <input type="time" name="<?= $grp ?>[<?= $k ?>][start]" class="form-control seg-start" value="<?= esc($r['start']) ?>">
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label small mb-1"><?= $grp === 'works' ? 'Saída' : 'Fim' ?></label>
      <input type="time" name="<?= $grp ?>[<?= $k ?>][end]" class="form-control seg-end" value="<?= esc($r['end']) ?>">
    </div>
    <div class="col-12 col-md-4 d-flex gap-2">
      <button type="button" class="btn btn-outline-danger seg-remove" <?= $rm ? 'disabled' : '' ?>>
        <i class="bi bi-trash3"></i> <span class="seg-remove-lbl"><?= $rm ? 'Será removido' : esc($lblRemoverPadrao) ?></span>
      </button>
    </div>
  </div>
<?php return ob_get_clean();
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Editar Ponto do Dia | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .seg-row.is-removed { opacity: .55; }
    .seg-row.is-removed .form-control { text-decoration: line-through; }
    #day-preview .metric { font-variant-numeric: tabular-nums; }
    #day-preview .metric strong { font-size: 1.05rem; }
    .preview-warn { color: var(--bs-danger); }
    .seg-help { font-size: .85rem; }
  </style>
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

  <div class="container-fluid admin-content">
    <!-- Header -->
    <div class="app-page-header">
      <div class="app-page-header__main">
        <div class="app-page-icon is-warning"><i class="bi bi-calendar-week"></i></div>
        <div>
          <h1 class="app-page-title">Editar Ponto do Dia</h1>
          <p class="app-page-subtitle">
            <strong><?= esc($att['teacher_name']) ?></strong> · <?= esc(date('d/m/Y', strtotime($origDate))) ?>
          </p>
        </div>
      </div>
      <span class="app-info-badge">
        <i class="bi bi-shield-check"></i>
        Status: <span class="ms-1 fw-bold text-<?= esc($statusClass) ?>"><?= esc($statusLbl) ?></span>
      </span>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
        <div class="flex-grow-1">
          <strong>Não foi possível salvar:</strong>
          <ul class="mb-0 mt-1">
            <?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <!-- Explicação clara do modelo -->
    <div class="alert alert-info">
      <div class="d-flex gap-2 align-items-start">
        <i class="bi bi-info-circle-fill fs-5 mt-1"></i>
        <div class="seg-help">
          <strong>Como funciona esta tela.</strong> O dia é formado por:
          <ul class="mb-2 mt-1">
            <li><strong>Períodos de trabalho</strong> — cada um é um registro real de
              <em>entrada → saída</em>. A maioria dos dias tem apenas <strong>1</strong>.
              Vários períodos aparecem em grade horária (várias aulas) ou quando houve
              <strong>duplicação</strong> por sincronização. Para corrigir um registro
              indevido, <strong>remova o período errado</strong> aqui.</li>
            <li><strong>Intervalos</strong> — pausas (almoço, café) registradas dentro do dia.</li>
          </ul>
          Na listagem de pontos, o tempo <em>entre</em> dois períodos pode aparecer como
          <span class="badge text-bg-secondary">intervalo inferido</span>: isso <strong>não é
          um registro</strong> — é só o cálculo da tela e <strong>desaparece</strong> quando
          os períodos ficam corretos. Ao salvar, o sistema recalcula as horas,
          <strong>aprova o dia</strong> e grava tudo na auditoria (seu nome, motivo, data/hora,
          estado anterior e posterior). Conformidade Portaria MTP 671/2021.
        </div>
      </div>
    </div>

    <?php if (!empty($att['data_edicao'])): ?>
      <div class="alert alert-light border d-flex gap-2 align-items-start">
        <i class="bi bi-clock-history fs-5 mt-1"></i>
        <div class="small">
          <strong>Última edição:</strong>
          <?= esc(date('d/m/Y H:i', strtotime($att['data_edicao']))) ?>
          por <?= esc($att['edited_by_username'] ?? ('#' . (string)$att['editado_por'])) ?>.
          <div class="text-muted">Motivo: <?= esc($att['motivo_edicao'] ?? '—') ?></div>
        </div>
      </div>
    <?php endif; ?>

    <form action="" method="post" autocomplete="off" id="day-edit-form">
      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="teacher_id" value="<?= (int)$teacherId ?>">
      <input type="hidden" name="orig_date" value="<?= esc($origDate) ?>">

      <!-- ========== Períodos de trabalho ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-box-arrow-in-right"></i>Seção 1</span>
          <h2 class="app-section-card__title">Períodos de trabalho</h2>
          <span class="app-section-card__hint">cada período é um registro real (entrada → saída)</span>
        </header>
        <div class="app-section-card__body">
          <div id="works-list" data-next="<?= count($workRows) ?>">
            <?php foreach ($workRows as $k => $r) echo render_seg_row('works', $k, $r); ?>
          </div>
          <template id="works-row-tpl"><?= render_seg_row('works', 0, ['id' => 0, 'start' => '', 'end' => '', 'remove' => false]) ?></template>
          <button type="button" id="add-work" class="btn btn-outline-primary btn-sm mt-1" data-grp="works">
            <i class="bi bi-plus-circle me-1"></i>Adicionar período de trabalho
          </button>
          <div class="form-text mt-2">
            <i class="bi bi-lightbulb me-1"></i>
            Dica: se houver um período <strong>duplicado/incorreto</strong> (ex.: vindo de
            sincronização), clique em <strong>Remover</strong> nesse período. Ele sai da folha,
            fica auditável e pode ser restaurado.
          </div>
        </div>
      </section>

      <!-- ========== Intervalos ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-pause-circle"></i>Seção 2</span>
          <h2 class="app-section-card__title">Intervalos</h2>
          <span class="app-section-card__hint">almoço, pausas — devem ficar dentro do período trabalhado</span>
        </header>
        <div class="app-section-card__body">
          <div id="breaks-list" data-next="<?= count($breakRows) ?>">
            <?php foreach ($breakRows as $k => $r) echo render_seg_row('breaks', $k, $r); ?>
          </div>
          <template id="breaks-row-tpl"><?= render_seg_row('breaks', 0, ['id' => 0, 'start' => '', 'end' => '', 'remove' => false]) ?></template>
          <button type="button" id="add-break" class="btn btn-outline-primary btn-sm mt-1" data-grp="breaks">
            <i class="bi bi-plus-circle me-1"></i>Adicionar intervalo
          </button>

          <!-- Preview recalculado -->
          <div id="day-preview" class="mt-3 p-3 rounded border bg-body-tertiary">
            <div class="row text-center g-3">
              <div class="col metric">Trabalhado<br><strong id="pv-worked">—</strong></div>
              <div class="col metric">Total intervalos<br><strong id="pv-breaks">0h00m</strong></div>
              <div class="col metric">Períodos<br><strong id="pv-works">0</strong></div>
              <div class="col metric">Intervalos<br><strong id="pv-bcount">0</strong></div>
            </div>
            <div id="pv-warnings" class="small mt-2"></div>
          </div>
        </div>
      </section>

      <!-- ========== Dia e método ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-calendar3"></i>Seção 3</span>
          <h2 class="app-section-card__title">Dia e método</h2>
        </header>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label for="date" class="form-label">Dia do registro</label>
              <input id="date" type="date" name="date" class="form-control" value="<?= esc($val_date) ?>">
              <div class="form-text">Altere para mover o dia inteiro (períodos + intervalos).</div>
            </div>
            <div class="col-12 col-md-4">
              <label for="method" class="form-label">Método de autenticação</label>
              <select id="method" name="method" class="form-select">
                <?php
                $methods = ['cpf' => 'CPF', 'pin' => 'CPF', 'foto' => 'Foto', 'face' => 'Reconhecimento Facial', 'manual' => 'Manual'];
                $current = strtolower((string)$val_method);
                foreach ($methods as $mk => $lbl):
                ?>
                  <option value="<?= esc($mk) ?>" <?= $current === $mk ? 'selected' : '' ?>><?= esc($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-text mt-2">
            <i class="bi bi-moon-stars me-1"></i>
            <strong>Turno noturno:</strong> se a saída for menor que a entrada (ex.: 22:00 → 02:00),
            o sistema entende que terminou no <strong>dia seguinte</strong> automaticamente.
          </div>
        </div>
      </section>

      <!-- ========== Justificativa ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-chat-left-text"></i>Seção 4</span>
          <h2 class="app-section-card__title">Justificativa da correção</h2>
          <span class="app-section-card__hint"><span class="badge text-bg-danger-subtle border border-danger-subtle text-danger-emphasis">obrigatória</span></span>
        </header>
        <div class="app-section-card__body">
          <label for="reason" class="form-label">Motivo da alteração <span class="text-danger">*</span></label>
          <textarea id="reason" name="reason" class="form-control" rows="3" required placeholder="Descreva o motivo da correção (visível no log de auditoria)..."><?= esc($val_reason) ?></textarea>
          <div class="form-text">
            <i class="bi bi-info-circle me-1"></i>
            Registrado no log de auditoria com seu nome, IP, o que mudou e o estado anterior/posterior.
          </div>
        </div>
      </section>

      <!-- ========== Ações ========== -->
      <div class="app-form-actions">
        <span class="app-form-actions__hint">
          <i class="bi bi-shield-check"></i>
          Editando o dia <strong><?= esc(date('d/m/Y', strtotime($origDate))) ?></strong>
        </span>
        <div class="app-form-actions__btns">
          <a href="attendances.php" class="btn btn-outline-secondary">
            <i class="bi bi-x-lg me-1"></i>Cancelar
          </a>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-save me-1"></i>Salvar Correção
          </button>
        </div>
      </div>
    </form>
  </div>

  <script>
    (function () {
      // Adicionar linha (período ou intervalo) clonando o template do grupo.
      document.querySelectorAll('#add-work, #add-break').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var grp = btn.getAttribute('data-grp');
          var list = document.getElementById(grp + '-list');
          var tpl = document.getElementById(grp + '-row-tpl');
          var idx = parseInt(list.getAttribute('data-next') || '0', 10);
          var frag = tpl.content.cloneNode(true);
          frag.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace('[0]', '[' + idx + ']');
          });
          list.appendChild(frag);
          list.setAttribute('data-next', idx + 1);
          recalc();
        });
      });

      // Remover: linha existente (id>0) → marca remoção; nova → apaga.
      document.querySelectorAll('#works-list, #breaks-list').forEach(function (list) {
        list.addEventListener('click', function (e) {
          var btn = e.target.closest('.seg-remove');
          if (!btn) return;
          var row = btn.closest('.seg-row');
          var idIn = row.querySelector('input[name$="[id]"]');
          if (idIn && parseInt(idIn.value, 10) > 0) {
            row.querySelector('.seg-remove-flag').value = '1';
            row.classList.add('is-removed');
            btn.disabled = true;
            var lbl = row.querySelector('.seg-remove-lbl');
            if (lbl) lbl.textContent = 'Será removido';
          } else {
            row.remove();
          }
          recalc();
        });
      });

      function pad(n) { return (n < 10 ? '0' : '') + n; }
      function fmtMin(m) {
        if (m === null || isNaN(m)) return '—';
        var sg = m < 0 ? '-' : ''; m = Math.abs(m);
        return sg + Math.floor(m / 60) + 'h' + pad(m % 60) + 'm';
      }
      function hm(v) { var x = /^(\d{2}):(\d{2})$/.exec(v || ''); return x ? (+x[1]) * 60 + (+x[2]) : null; }

      // Lê linhas ativas (não removidas) de um grupo → [{s,e,n}] com s/e em minutos
      // (crus, sem ajuste de meia-noite).
      function readRows(grp) {
        var arr = [], n = 0;
        document.querySelectorAll('#' + grp + '-list .seg-row').forEach(function (row) {
          if (row.classList.contains('is-removed')) return;
          var s = hm(row.querySelector('.seg-start').value);
          var e = hm(row.querySelector('.seg-end').value);
          if (s === null && e === null) return;
          n++;
          arr.push({ s: s, e: e, n: n });
        });
        return arr;
      }

      function recalc() {
        var works = readRows('works');
        var breaks = readRows('breaks');
        var warns = [];

        // Detecta turno noturno e refStart a partir dos períodos.
        var overnight = false, refStart = null;
        works.forEach(function (w) {
          if (w.s !== null && w.e !== null && w.e < w.s) overnight = true;
          if (w.s !== null && (refStart === null || w.s < refStart)) refStart = w.s;
        });
        function eff(m) { return (overnight && refStart !== null && m !== null && m < refStart) ? m + 1440 : m; }

        // Valida + soma períodos.
        var worked = 0, dayStart = null, dayEnd = null, okWorks = [];
        works.forEach(function (w) {
          if (w.s === null || w.e === null) { warns.push('Período ' + w.n + ': informe entrada e saída.'); return; }
          var s = eff(w.s), e = eff(w.e);
          if (e <= s) { warns.push('Período ' + w.n + ': a saída deve ser posterior à entrada.'); return; }
          worked += (e - s);
          if (dayStart === null || s < dayStart) dayStart = s;
          if (dayEnd === null || e > dayEnd) dayEnd = e;
          okWorks.push({ s: s, e: e, n: w.n });
        });
        // Sobreposição de períodos.
        okWorks.sort(function (a, b) { return a.s - b.s; });
        for (var i = 1; i < okWorks.length; i++) {
          if (okWorks[i].s < okWorks[i - 1].e) warns.push('Os períodos ' + okWorks[i - 1].n + ' e ' + okWorks[i].n + ' se sobrepõem.');
        }

        // Valida + soma intervalos.
        var breakTotal = 0, okBreaks = [];
        breaks.forEach(function (b) {
          if (b.s === null || b.e === null) { warns.push('Intervalo ' + b.n + ': informe início e fim.'); return; }
          var s = eff(b.s), e = eff(b.e);
          if (e <= s) { warns.push('Intervalo ' + b.n + ': o fim deve ser posterior ao início.'); return; }
          if (dayStart !== null && s < dayStart) warns.push('Intervalo ' + b.n + ' está fora do período trabalhado (antes da entrada).');
          if (dayEnd !== null && e > dayEnd) warns.push('Intervalo ' + b.n + ' está fora do período trabalhado (depois da saída).');
          breakTotal += (e - s);
          okBreaks.push({ s: s, e: e, n: b.n });
        });
        okBreaks.sort(function (a, b) { return a.s - b.s; });
        for (var j = 1; j < okBreaks.length; j++) {
          if (okBreaks[j].s < okBreaks[j - 1].e) warns.push('Os intervalos ' + okBreaks[j - 1].n + ' e ' + okBreaks[j].n + ' se sobrepõem.');
        }

        document.getElementById('pv-worked').textContent = okWorks.length ? fmtMin(worked - breakTotal) : '—';
        document.getElementById('pv-breaks').textContent = fmtMin(breakTotal);
        document.getElementById('pv-works').textContent = works.length;
        document.getElementById('pv-bcount').textContent = breaks.length;

        var box = document.getElementById('pv-warnings');
        if (warns.length) {
          box.className = 'small mt-2 preview-warn';
          box.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>' + warns.join('<br>');
        } else {
          box.className = 'small mt-2 text-success';
          box.innerHTML = '<i class="bi bi-check-circle me-1"></i>Sem inconsistências detectadas.';
        }
      }

      document.addEventListener('input', recalc);
      document.addEventListener('change', recalc);
      recalc();
    })();
  </script>
  <?php include __DIR__ . '/../_footer.php'; ?>
</body>

</html>

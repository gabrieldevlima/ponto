<?php
/**
 * SANEAMENTO DE PONTOS OFFLINE DUPLICADOS
 *
 * Lista grupos de attendance pendentes (record_mode=offline, approved=NULL)
 * que parecem ser cliques múltiplos do mesmo ponto, e permite ao admin
 * marcar duplicatas (soft-delete via superseded_by_id).
 */

require_once __DIR__ . '/../../config.php';
require_admin();

if (!has_permission('attendance.dedupe')) {
    http_response_code(403);
    flash_redirect('error', 'Sem permissão para acessar o saneamento de pontos.', 'dashboard.php');
}

$pdo = db();
$admin = current_admin($pdo);

$from   = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to     = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : date('Y-m-d');
$schoolId = (isset($_GET['school']) && $_GET['school'] !== '' && $_GET['school'] !== '-1') ? (int)$_GET['school'] : null;
$teacherId = (isset($_GET['teacher']) && $_GET['teacher'] !== '') ? (int)$_GET['teacher'] : null;
$window = isset($_GET['window']) ? max(1, min(60, (int)$_GET['window'])) : 5;

$groups = find_duplicate_groups($pdo, $from, $to, $schoolId, $teacherId, $window);

$totalRecords = array_sum(array_map('count', $groups));
$wouldKeep    = count($groups);
$wouldSoftDel = max(0, $totalRecords - $wouldKeep);

$schools = $pdo->query("SELECT id, name FROM schools ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$isNetworkAdmin = function_exists('is_network_admin') ? is_network_admin($admin) : false;
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Saneamento de Pontos Offline | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    .dedupe-group { border-left: 4px solid #ffc107; }
    .dedupe-keeper { background: #d1e7dd; }
    .dedupe-soft   { background: #fff3cd; }
    .dedupe-mono   { font-family: 'Courier New', monospace; font-size: .8em; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

  <main class="container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h1 class="h4 mb-0">
        <i class="bi bi-collection me-2"></i>Saneamento de Pontos Offline
      </h1>
      <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Voltar
      </a>
    </div>

    <!-- Filtros -->
    <section class="card mb-3">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
          <div class="col-md-2">
            <label class="form-label small mb-1">De</label>
            <input type="date" class="form-control form-control-sm" name="from" value="<?= esc($from) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-1">Até</label>
            <input type="date" class="form-control form-control-sm" name="to" value="<?= esc($to) ?>">
          </div>
          <?php if ($isNetworkAdmin): ?>
          <div class="col-md-3">
            <label class="form-label small mb-1">Escola</label>
            <select class="form-select form-select-sm" name="school">
              <option value="-1">Todas</option>
              <?php foreach ($schools as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ($schoolId === (int)$s['id']) ? 'selected' : '' ?>>
                  <?= esc($s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-md-2">
            <label class="form-label small mb-1">Janela (min)</label>
            <input type="number" class="form-control form-control-sm" name="window" value="<?= (int)$window ?>" min="1" max="60">
          </div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="bi bi-funnel"></i> Filtrar
            </button>
            <a href="offline_dedupe.php" class="btn btn-outline-secondary btn-sm">Limpar</a>
          </div>
        </form>
      </div>
    </section>

    <!-- Resumo + ações em lote -->
    <div class="alert alert-info d-flex justify-content-between align-items-center">
      <div>
        <i class="bi bi-clipboard-data me-2"></i>
        <b><?= $totalRecords ?></b> registros em <b><?= count($groups) ?></b> grupo(s) de duplicata na janela selecionada.
        Recomendação: manter <b><?= $wouldKeep ?></b> e marcar <b><?= $wouldSoftDel ?></b> como duplicada(s).
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-primary btn-sm" id="btnPreviewAll" <?= count($groups) ? '' : 'disabled' ?>>
          <i class="bi bi-eye"></i> Pré-visualizar tudo
        </button>
        <button class="btn btn-success btn-sm" id="btnApplyAll" <?= count($groups) ? '' : 'disabled' ?>>
          <i class="bi bi-check2-all"></i> Aplicar a todos
        </button>
      </div>
    </div>

    <?php if (empty($groups)): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-check-circle display-4 d-block mb-3 text-success"></i>
        Nenhum grupo de duplicatas detectado na janela atual.
      </div>
    <?php endif; ?>

    <?php foreach ($groups as $key => $group): ?>
      <?php
        $keeperId = (int)$group[0]['id'];
        $supersededIds = array_map(static fn($r) => (int)$r['id'], array_slice($group, 1));
      ?>
      <div class="card mb-3 dedupe-group" data-keeper="<?= $keeperId ?>" data-superseded="<?= esc(implode(',', $supersededIds)) ?>">
        <div class="card-header bg-warning-subtle">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
              <b><?= esc($group[0]['teacher_name']) ?></b>
              · <?= esc($group[0]['date']) ?>
              · <?= $group[0]['_action'] === 'check_in' ? '<span class="badge bg-primary">Entrada</span>' : '<span class="badge bg-secondary">Saída</span>' ?>
              · <span class="badge bg-warning text-dark"><?= count($group) ?> registros</span>
            </div>
            <div class="btn-group btn-group-sm">
              <button class="btn btn-success btn-apply-group">
                <i class="bi bi-check2-circle"></i> Aplicar recomendação
              </button>
              <button class="btn btn-outline-primary btn-manual-group" data-group='<?= esc(json_encode($group, JSON_UNESCAPED_UNICODE)) ?>'>
                <i class="bi bi-pencil"></i> Manual
              </button>
              <button class="btn btn-outline-secondary btn-review-group" disabled title="Marca apenas visualmente (TODO)">
                <i class="bi bi-bookmark"></i> Revisar
              </button>
            </div>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr class="table-light">
                <th>#</th><th>Decisão</th><th>ID</th><th>Hora aparelho</th>
                <th>Hora servidor</th><th>Device</th><th>client_id</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($group as $i => $r): ?>
                <?php $action = $r['_action']; ?>
                <tr class="<?= $i === 0 ? 'dedupe-keeper' : 'dedupe-soft' ?>">
                  <td><?= $i + 1 ?></td>
                  <td>
                    <?php if ($i === 0): ?>
                      <span class="badge bg-success"><i class="bi bi-shield-check"></i> Manter</span>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark"><i class="bi bi-trash"></i> Soft-delete</span>
                    <?php endif; ?>
                  </td>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= esc((string)$r[$action]) ?></td>
                  <td class="text-muted small"><?= esc((string)($r['recorded_at'] ?? '')) ?></td>
                  <td class="dedupe-mono text-truncate" style="max-width:140px;" title="<?= esc((string)$r['device_identifier']) ?>">
                    <?= esc(substr((string)$r['device_identifier'], 0, 12)) ?>…
                  </td>
                  <td class="dedupe-mono text-truncate" style="max-width:240px;" title="<?= esc((string)($r['client_id'] ?: $r['checkout_client_id'])) ?>">
                    <?= esc((string)($r['client_id'] ?: $r['checkout_client_id'] ?: '—')) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </main>

  <!-- Modal: escolha manual -->
  <div class="modal fade" id="manualPickModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Escolher manualmente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Marque o registro que deve ser <b>mantido</b>. Os outros serão soft-deletados.</p>
          <table class="table table-sm">
            <thead><tr><th>Manter</th><th>ID</th><th>Hora aparelho</th><th>Hora servidor</th><th>Device</th></tr></thead>
            <tbody id="manualPickRows"></tbody>
          </table>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-success" id="manualPickApply">Aplicar</button>
        </div>
      </div>
    </div>
  </div>

  <script>
  (function() {
    const csrf = <?= json_encode(csrf_token()) ?>;
    const actionUrl = 'offline_dedupe_action.php';

    async function postAction(params) {
      const body = new URLSearchParams({ csrf, ...params });
      const r = await fetch(actionUrl, { method: 'POST', body });
      const data = await r.json().catch(() => ({ status: 'error', message: 'Resposta inválida' }));
      if (!r.ok || data.status !== 'ok') {
        throw new Error(data.message || ('HTTP ' + r.status));
      }
      return data;
    }

    document.querySelectorAll('.btn-apply-group').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const card = btn.closest('.dedupe-group');
        const keeper = card.dataset.keeper;
        const superseded = card.dataset.superseded;
        if (!confirm('Aplicar recomendação a este grupo?\n\nManter #' + keeper + ' e soft-deletar [' + superseded + '].')) return;
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try {
          await postAction({ act: 'apply', keeper_id: keeper, superseded_ids: superseded });
          card.style.opacity = '.3';
          btn.innerHTML = '<i class="bi bi-check-lg"></i> Feito';
          btn.classList.replace('btn-success', 'btn-outline-success');
        } catch (e) {
          alert('Erro: ' + e.message);
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-check2-circle"></i> Aplicar recomendação';
        }
      });
    });

    const modal = new bootstrap.Modal(document.getElementById('manualPickModal'));
    let manualCtx = null;
    document.querySelectorAll('.btn-manual-group').forEach((btn) => {
      btn.addEventListener('click', () => {
        const group = JSON.parse(btn.dataset.group);
        manualCtx = { card: btn.closest('.dedupe-group'), group };
        const tbody = document.getElementById('manualPickRows');
        tbody.innerHTML = '';
        group.forEach((r, i) => {
          const action = r._action;
          const tr = document.createElement('tr');
          tr.innerHTML =
            '<td><input type="radio" name="manualKeeper" value="' + r.id + '" ' + (i === 0 ? 'checked' : '') + '></td>' +
            '<td>' + r.id + '</td>' +
            '<td>' + (r[action] || '') + '</td>' +
            '<td class="text-muted small">' + (r.recorded_at || '') + '</td>' +
            '<td class="dedupe-mono">' + ((r.device_identifier || '').substr(0, 12)) + '…</td>';
          tbody.appendChild(tr);
        });
        modal.show();
      });
    });

    document.getElementById('manualPickApply').addEventListener('click', async () => {
      const picked = document.querySelector('input[name="manualKeeper"]:checked');
      if (!picked || !manualCtx) return;
      const keeper = picked.value;
      const superseded = manualCtx.group
        .map((r) => String(r.id))
        .filter((id) => id !== String(keeper));
      try {
        await postAction({ act: 'apply', keeper_id: keeper, superseded_ids: superseded.join(',') });
        manualCtx.card.style.opacity = '.3';
        modal.hide();
      } catch (e) {
        alert('Erro: ' + e.message);
      }
    });

    document.getElementById('btnApplyAll').addEventListener('click', async () => {
      if (!confirm('Aplicar recomendação a TODOS os grupos visíveis?\n\nO 1º registro de cada grupo é mantido; os demais ficam como duplicata.')) return;
      const btn = document.getElementById('btnApplyAll');
      btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Aplicando...';
      try {
        const params = new URLSearchParams({
          csrf, act: 'apply_all',
          from: <?= json_encode($from) ?>,
          to:   <?= json_encode($to) ?>,
          school: <?= json_encode($schoolId === null ? '' : (string)$schoolId) ?>,
        });
        const r = await fetch(actionUrl, { method: 'POST', body: params });
        const data = await r.json();
        alert('Concluído. Aplicado em ' + (data.applied_count || 0) + ' grupo(s).' +
              (data.errors && data.errors.length ? '\nErros: ' + data.errors.length : ''));
        window.location.reload();
      } catch (e) {
        alert('Erro: ' + e.message);
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-all"></i> Aplicar a todos';
      }
    });

    document.getElementById('btnPreviewAll').addEventListener('click', async () => {
      const params = new URLSearchParams({
        csrf, act: 'preview',
        from: <?= json_encode($from) ?>,
        to:   <?= json_encode($to) ?>,
        school: <?= json_encode($schoolId === null ? '' : (string)$schoolId) ?>,
      });
      try {
        const r = await fetch(actionUrl, { method: 'POST', body: params });
        const data = await r.json();
        const s = data.preview;
        alert(
          'Pré-visualização:\n\n' +
          '• Grupos: ' + s.group_count + '\n' +
          '• Total de registros: ' + s.total_records + '\n' +
          '• Manteria: ' + s.would_keep + '\n' +
          '• Soft-deletaria: ' + s.would_soft_delete
        );
      } catch (e) {
        alert('Erro: ' + e.message);
      }
    });
  })();
  </script>
  <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

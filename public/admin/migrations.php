<?php
/**
 * STATUS DE MIGRAÇÕES — visibilidade do estado do schema em produção.
 *
 * Mostra:
 *  - Migrations pendentes (arquivo existe mas ainda não rodou)
 *  - Aplicadas com timestamp + duração
 *  - Falhadas (failure_reason gravado em applied_migrations)
 *  - DRIFT: arquivo foi editado depois de aplicado (sha não bate)
 *
 * Permite re-disparar manualmente o auto-migrate clicando em botão.
 */

require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

$message = null;
$messageType = null;

// C2: Post-Redirect-Get — POST agenda re-execução via session flash, redireciona
// para GET. Request fresh tem static $ran=false → run_auto_migrations roda
// de fato no boot do config.php. Evita o no-op silencioso da chamada direta.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'rerun') {
    csrf_verify();
    $_SESSION['_mig_flash'] = [
        'type' => 'success',
        'msg'  => 'Re-execução solicitada. Pendentes (se houver) já foram processadas nesta página.',
    ];
    header('Location: migrations.php?rerun=1', true, 302);
    exit;
}

// H4: re-aplicar migration em estado de drift (arquivo SQL alterado após aplicação).
// Remove a entrada de applied_migrations, força run_auto_migrations no próximo request.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reapply') {
    csrf_verify();
    $filename = trim((string)($_POST['filename'] ?? ''));
    // Whitelist: filename precisa estar em $status['applied'] e ter drift atual
    $statusCheck = migrations_status();
    $appliedFiles = array_column($statusCheck['applied'], 'filename');
    $driftFiles   = array_column(array_filter($statusCheck['applied'], fn($a) => !empty($a['drift'])), 'filename');
    if (!in_array($filename, $appliedFiles, true)) {
        $_SESSION['_mig_flash'] = ['type' => 'danger', 'msg' => 'Migration não encontrada na lista de aplicadas.'];
    } elseif (!in_array($filename, $driftFiles, true)) {
        $_SESSION['_mig_flash'] = ['type' => 'warning', 'msg' => 'Migration ' . esc($filename) . ' não está em drift — re-aplicar é desnecessário.'];
    } else {
        try {
            $stDel = $pdo->prepare("DELETE FROM applied_migrations WHERE filename = ?");
            $stDel->execute([$filename]);
            audit_log('reapply', 'migration', null, ['filename' => $filename]);
            $_SESSION['_mig_flash'] = ['type' => 'success', 'msg' => 'Entrada de "' . esc($filename) . '" removida. Recarregando para re-executar…'];
        } catch (Throwable $e) {
            $_SESSION['_mig_flash'] = ['type' => 'danger', 'msg' => 'Falha ao remover entrada: ' . esc($e->getMessage())];
        }
    }
    header('Location: migrations.php?rerun=1', true, 302);
    exit;
}

// Lê flash da sessão se existir (vindo do POST anterior).
if (!empty($_SESSION['_mig_flash']) && is_array($_SESSION['_mig_flash'])) {
    $flash = $_SESSION['_mig_flash'];
    $message = (string)($flash['msg'] ?? '');
    $messageType = (string)($flash['type'] ?? 'info');
    unset($_SESSION['_mig_flash']);
}

$status = migrations_status();
$pendingCount = count($status['pending']);
$failedCount  = count($status['failed']);
$driftItems   = array_filter($status['applied'], fn($a) => !empty($a['drift']));
$driftCount   = count($driftItems);
$pageTitle = 'Migrações de banco';
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($pageTitle) ?> · DEEDO Ponto</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/admin.css">
  <style>
    .mig-row { font-family: ui-monospace, "SF Mono", Monaco, Consolas, monospace; font-size: 13px; }
    .mig-row.is-pending td { background: #fff8ef; }
    .mig-row.is-failed  td { background: #fef2f2; }
    .mig-row.is-drift   td { background: #fdf6e3; }
    .mig-pill { font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .mig-pill.ok      { background: #d1fae5; color: #065f46; }
    .mig-pill.pend    { background: #fef3c7; color: #92400e; }
    .mig-pill.fail    { background: #fee2e2; color: #991b1b; }
    .mig-pill.drift   { background: #fde68a; color: #92400e; }
    .mig-stat-card    { padding: 16px; border-radius: 12px; background: #fff; border: 1px solid #e5e7eb; }
    .mig-stat-num     { font-size: 28px; font-weight: 800; line-height: 1; }
    .mig-fail-msg     { white-space: pre-wrap; word-break: break-word; font-size: 12px; color: #991b1b; }
  </style>
</head>
<body>
<?php include __DIR__ . '/_navbar.php'; ?>

<main class="container py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-database-gear me-2"></i><?= esc($pageTitle) ?></h1>
    <form action="" method="post" class="d-inline">
      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
      <input type="hidden" name="action" value="rerun">
      <button class="btn btn-primary btn-sm">
        <i class="bi bi-arrow-clockwise me-1"></i> Re-executar pendentes
      </button>
    </form>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?= esc($messageType ?: 'info') ?> py-2"><?= esc($message) ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="mig-stat-card">
        <div class="text-muted small">Pendentes</div>
        <div class="mig-stat-num text-<?= $pendingCount ? 'warning' : 'success' ?>"><?= (int)$pendingCount ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="mig-stat-card">
        <div class="text-muted small">Falhadas</div>
        <div class="mig-stat-num text-<?= $failedCount ? 'danger' : 'success' ?>"><?= (int)$failedCount ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="mig-stat-card">
        <div class="text-muted small">Drift de arquivo</div>
        <div class="mig-stat-num text-<?= $driftCount ? 'warning' : 'success' ?>"><?= (int)$driftCount ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="mig-stat-card">
        <div class="text-muted small">Aplicadas com sucesso</div>
        <div class="mig-stat-num text-success"><?= (int)count($status['applied']) - $driftCount - $failedCount ?></div>
      </div>
    </div>
  </div>

  <?php if ($pendingCount): ?>
    <section class="app-section-card app-table-card">
      <header class="app-section-card__header" style="background: linear-gradient(180deg, #fffbeb, #fef3c7);">
        <span class="app-section-card__eyebrow" aria-hidden="true" style="color: #92400e;"><i class="bi bi-hourglass-split"></i>Aguardando</span>
        <h2 class="app-section-card__title" style="color: #92400e;">Pendentes</h2>
        <span class="app-section-card__hint">rodam no próximo request</span>
      </header>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th scope="col">Arquivo</th><th scope="col">Status</th></tr></thead>
          <tbody>
            <?php foreach ($status['pending'] as $f): ?>
              <tr class="mig-row is-pending">
                <td><?= esc($f) ?></td>
                <td><span class="mig-pill pend">Pendente</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($failedCount): ?>
    <section class="app-section-card app-table-card">
      <header class="app-section-card__header" style="background: linear-gradient(180deg, #fef2f2, #fee2e2);">
        <span class="app-section-card__eyebrow" aria-hidden="true" style="color: #991b1b;"><i class="bi bi-exclamation-octagon"></i>Erro</span>
        <h2 class="app-section-card__title" style="color: #991b1b;">Falhas registradas</h2>
        <span class="app-section-card__hint">inspecione antes de re-executar</span>
      </header>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th scope="col">Arquivo</th><th scope="col">Motivo</th></tr></thead>
          <tbody>
            <?php foreach ($status['failed'] as $f): ?>
              <tr class="mig-row is-failed">
                <td><strong><?= esc($f['filename']) ?></strong></td>
                <td class="mig-fail-msg"><?= esc($f['reason']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <section class="app-section-card app-table-card">
    <header class="app-section-card__header">
      <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-check-circle"></i>Histórico</span>
      <h2 class="app-section-card__title">Aplicações</h2>
    </header>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th scope="col">Arquivo</th>
            <th scope="col">Aplicada em</th>
            <th scope="col" class="text-end">Duração</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($status['applied'])): ?>
            <tr><td colspan="4" class="text-muted text-center py-3">Nenhuma migração aplicada ainda.</td></tr>
          <?php else: ?>
            <?php foreach ($status['applied'] as $a): ?>
              <tr class="mig-row <?= !empty($a['drift']) ? 'is-drift' : '' ?> <?= !empty($a['failure_reason']) ? 'is-failed' : '' ?>">
                <td><?= esc($a['filename']) ?></td>
                <td><?= esc($a['applied_at'] ?? '—') ?></td>
                <td class="text-end"><?= isset($a['duration_ms']) ? (int)$a['duration_ms'] . ' ms' : '—' ?></td>
                <td>
                  <?php if (!empty($a['failure_reason'])): ?>
                    <span class="mig-pill fail">Falha</span>
                  <?php elseif (!empty($a['drift'])): ?>
                    <span class="mig-pill drift" title="Arquivo SQL foi editado após aplicar.">Drift</span>
                    <form method="post" class="d-inline ms-1" onsubmit="return confirm('Remover entrada de applied_migrations e re-executar a versão atual de <?= esc($a['filename']) ?>?');">
                      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                      <input type="hidden" name="action" value="reapply">
                      <input type="hidden" name="filename" value="<?= esc($a['filename']) ?>">
                      <button class="btn btn-sm btn-outline-warning py-0" title="Re-aplicar versão atual do arquivo">
                        <i class="bi bi-arrow-clockwise"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="mig-pill ok">OK</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <div class="alert alert-secondary mt-4 small">
    <strong>Como funciona:</strong> auto-migration roda no <code>config.php</code> em todo request,
    com lock global (GET_LOCK) para evitar concorrência. Migrations já aplicadas são puladas pelo
    nome do arquivo. Mudanças em arquivo após aplicar são detectadas via SHA-256 e marcadas como
    <em>drift</em> (não re-executam — apenas avisam). Para desativar temporariamente, defina a env var
    <code>PONTO_DISABLE_AUTO_MIGRATIONS=1</code>.
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

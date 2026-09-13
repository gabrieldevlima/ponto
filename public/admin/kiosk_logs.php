<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('kiosk.manage')) {
    http_response_code(403);
    flash_redirect('error', 'Sem permissão para gerenciar o quiosque.', 'dashboard.php');
}
$pdo = db();

$fStatus = trim((string)($_GET['status'] ?? ''));
$fEvent  = trim((string)($_GET['event'] ?? ''));
$fName   = trim((string)($_GET['q'] ?? ''));
$fFrom   = trim((string)($_GET['from'] ?? ''));
$fTo     = trim((string)($_GET['to'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset  = ($page - 1) * $perPage;

$where = [];
$args = [];
if ($fStatus !== '') { $where[] = 'l.status = ?'; $args[] = $fStatus; }
if ($fEvent !== '')  { $where[] = 'l.event_type = ?'; $args[] = $fEvent; }
if ($fName !== '')   { $where[] = 't.name LIKE ?'; $args[] = '%' . $fName . '%'; }
if ($fFrom !== '')   { $where[] = 'l.created_at >= ?'; $args[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')     { $where[] = 'l.created_at <= ?'; $args[] = $fTo . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = 0;
try {
    $stc = $pdo->prepare("SELECT COUNT(*) FROM kiosk_face_logs l LEFT JOIN teachers t ON t.id=l.teacher_id $whereSql");
    $stc->execute($args);
    $total = (int)$stc->fetchColumn();
} catch (Throwable $e) {}

$rows = [];
try {
    $sql = "SELECT l.*, t.name AS teacher_name, d.name AS device_name
            FROM kiosk_face_logs l
            LEFT JOIN teachers t ON t.id = l.teacher_id
            LEFT JOIN kiosk_devices d ON d.id = l.device_id
            $whereSql
            ORDER BY l.id DESC
            LIMIT $perPage OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$statusOptions = $pdo->query("SELECT DISTINCT status FROM kiosk_face_logs ORDER BY status")->fetchAll(PDO::FETCH_COLUMN) ?: [];

function kiosk_status_badge(string $s): string {
    $map = [
        'registered' => 'success', 'recognized' => 'success', 'enrolled' => 'success',
        'consumed' => 'info', 'deleted' => 'dark', 'start' => 'info',
        'no_face' => 'warning', 'multiple_faces' => 'warning', 'low_quality' => 'warning',
        'low_confidence' => 'warning', 'not_recognized' => 'warning', 'enrollment_desync' => 'warning',
        'api_unavailable' => 'danger', 'api_error' => 'danger', 'insert_failed' => 'danger',
        'checkout_failed' => 'danger', 'rate_limited' => 'danger', 'image_invalid' => 'secondary',
        'orphan_punch_pending' => 'secondary',
    ];
    $cls = 'secondary';
    foreach ($map as $k => $v) { if ($s === $k || strpos($s, $k) === 0) { $cls = $v; break; } }
    return '<span class="badge bg-' . $cls . ' rounded-pill">' . esc($s) . '</span>';
}
$qs = function(array $over) use ($fStatus,$fEvent,$fName,$fFrom,$fTo) {
    $p = array_merge(['status'=>$fStatus,'event'=>$fEvent,'q'=>$fName,'from'=>$fFrom,'to'=>$fTo], $over);
    return 'kiosk_logs.php?' . http_build_query(array_filter($p, fn($v)=>$v!==''&&$v!==null));
};
$pages = max(1, (int)ceil($total / $perPage));
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Quiosque · Logs | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>
    <div class="container-fluid admin-content" id="main-content">
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-clipboard-data"></i></div><div><h1 class="app-page-title">Quiosque · Logs e Auditoria</h1><p class="app-page-subtitle">Tentativas de reconhecimento facial: sucessos, recusas e inconsistências.</p></div></div></div>

        <section class="app-section-card mb-3">
            <div class="app-section-card__body">
                <form class="row g-2 align-items-end" method="get">
                    <div class="col-md-2"><label class="form-label small mb-1">Evento</label>
                        <select name="event" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <?php foreach (['identify','checkin','enroll','reenroll','fallback'] as $ev): ?><option value="<?= $ev ?>" <?= $fEvent===$ev?'selected':'' ?>><?= $ev ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <?php foreach ($statusOptions as $so): ?><option value="<?= esc($so) ?>" <?= $fStatus===$so?'selected':'' ?>><?= esc($so) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label small mb-1">Colaborador</label><input type="text" name="q" class="form-control form-control-sm" value="<?= esc($fName) ?>" placeholder="Nome"></div>
                    <div class="col-md-2"><label class="form-label small mb-1">De</label><input type="date" name="from" class="form-control form-control-sm" value="<?= esc($fFrom) ?>"></div>
                    <div class="col-md-2"><label class="form-label small mb-1">Até</label><input type="date" name="to" class="form-control form-control-sm" value="<?= esc($fTo) ?>"></div>
                    <div class="col-md-1 d-grid"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button></div>
                </form>
            </div>
        </section>

        <section class="app-section-card app-table-card">
            <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-list-ul"></i>Registros</span><h2 class="app-section-card__title">Eventos de Reconhecimento</h2><span class="app-section-card__hint"><?= $total ?> evento(s)</span></header>
            <div class="table-responsive">
                <?php if (empty($rows)): ?>
                    <div class="p-5 text-center text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i><p class="mb-0">Nenhum evento encontrado.</p></div>
                <?php else: ?>
                    <table class="table table-hover align-middle table-sm mb-0">
                        <thead class="table-light"><tr><th>Data/Hora</th><th>Evento</th><th>Status</th><th>Colaborador</th><th>Conf.</th><th>Ação</th><th>Dispositivo</th><th>IP</th><th>Ponto</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="small text-nowrap"><?= esc($r['created_at']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= esc($r['event_type']) ?></span></td>
                                <td><?= kiosk_status_badge((string)$r['status']) ?></td>
                                <td><?= $r['teacher_name'] ? esc($r['teacher_name']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="small"><?= $r['confidence']!==null ? round((float)$r['confidence']*100).'%' : '—' ?></td>
                                <td class="small"><?= $r['action_attempted'] ? esc($r['action_attempted']) : '—' ?></td>
                                <td class="small"><?= $r['device_name'] ? esc($r['device_name']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="small text-muted"><?= esc((string)$r['ip']) ?></td>
                                <td class="small"><?= $r['attendance_id'] ? ('<a href="attendances.php?id='.(int)$r['attendance_id'].'">#'.(int)$r['attendance_id'].'</a>') : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php if ($pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center p-3">
                <span class="text-muted small">Página <?= $page ?> de <?= $pages ?></span>
                <div class="btn-group">
                    <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= esc($qs(['page'=>$page-1])) ?>">‹ Anterior</a><?php endif; ?>
                    <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= esc($qs(['page'=>$page+1])) ?>">Próxima ›</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>

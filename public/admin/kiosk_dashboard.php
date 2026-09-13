<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('kiosk.manage')) {
    http_response_code(403);
    flash_redirect('error', 'Sem permissão para gerenciar o quiosque.', 'dashboard.php');
}
$pdo = db();

$days = (int)($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30], true)) $days = 7;

$scalar = function (string $sql, array $args = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchColumn(); } catch (Throwable $e) { return null; }
};
$rows = function (string $sql, array $args = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
};

// Sucesso = identify reconhecido (recognized) ou consumido por um check-in (consumed).
$SUCCESS = "'recognized','consumed'";
$tot = (int)($scalar("SELECT COUNT(*) FROM kiosk_face_logs WHERE event_type='identify' AND created_at >= (NOW() - INTERVAL ? DAY)", [$days]) ?: 0);
$ok  = (int)($scalar("SELECT COUNT(*) FROM kiosk_face_logs WHERE event_type='identify' AND status IN ($SUCCESS) AND created_at >= (NOW() - INTERVAL ? DAY)", [$days]) ?: 0);
$registered = (int)($scalar("SELECT COUNT(*) FROM kiosk_face_logs WHERE event_type='checkin' AND status='registered' AND created_at >= (NOW() - INTERVAL ? DAY)", [$days]) ?: 0);
$ac = $scalar("SELECT AVG(confidence) FROM kiosk_face_logs WHERE event_type='identify' AND status IN ($SUCCESS) AND confidence IS NOT NULL AND created_at >= (NOW() - INTERVAL ? DAY)", [$days]);
$avgConf = ($ac !== null && $ac !== false) ? round((float)$ac * 100) : null;
$refused = max(0, $tot - $ok);
$rate = $tot > 0 ? round($ok / $tot * 100) : null;

// Recusas por motivo
$refRows = $rows("SELECT status, COUNT(*) c FROM kiosk_face_logs WHERE event_type='identify' AND status NOT IN ($SUCCESS) AND created_at >= (NOW() - INTERVAL ? DAY) GROUP BY status ORDER BY c DESC", [$days]);

// Suspeitos: muitas falhas (not_matched) por colaborador nas últimas 24h
$maxFails = (int)(get_setting('kiosk_cpf_max_fails', '5') ?? '5');
$susp = $rows("SELECT l.teacher_id, t.name, COUNT(*) c FROM kiosk_face_logs l LEFT JOIN teachers t ON t.id=l.teacher_id
            WHERE l.status='not_matched' AND l.created_at >= (NOW() - INTERVAL 24 HOUR)
            GROUP BY l.teacher_id, t.name HAVING c >= ? ORDER BY c DESC LIMIT 20", [max(2, $maxFails)]);

// Saúde dos dispositivos
$devices = $rows("SELECT * FROM kiosk_devices ORDER BY active DESC, name");
$onlineCount = 0; $offlineActive = 0;
foreach ($devices as $d) {
    $seen = $d['last_seen_at'] ? strtotime($d['last_seen_at']) : 0;
    $isOnline = $seen && (time() - $seen) <= 600; // 10 min
    if ($isOnline) $onlineCount++;
    elseif ((int)$d['active'] === 1) $offlineActive++;
}

// Alertas
$alerts = [];
if ($tot >= 20 && $rate !== null && $rate < 70) $alerts[] = "Taxa de sucesso baixa ({$rate}%) nos últimos {$days} dia(s) — verifique iluminação/cadastros ou calibre o limiar.";
if ($offlineActive > 0) $alerts[] = "{$offlineActive} dispositivo(s) ativo(s) sem comunicação recente (offline).";
if (!empty($susp)) $alerts[] = count($susp) . " colaborador(es) com muitas falhas de reconhecimento em 24h — possível tentativa de impersonação.";
if (get_setting('kiosk_enabled', '0') !== '1') $alerts[] = "O modo quiosque está DESATIVADO no momento.";

$fmtPct = fn($v) => $v === null ? '—' : $v . '%';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Quiosque · Painel | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>.kpi{background:var(--bs-body-bg,#fff);border:1px solid #e2e8f0;border-radius:14px;padding:18px;text-align:center}.kpi .v{font-size:30px;font-weight:800}.kpi .l{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.5px}</style>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>
    <div class="container-fluid admin-content" id="main-content">
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-speedometer2"></i></div><div><h1 class="app-page-title">Quiosque · Painel</h1><p class="app-page-subtitle">Confiabilidade do reconhecimento, saúde dos dispositivos e alertas.</p></div></div>
            <div class="btn-group btn-group-sm" role="group">
                <a class="btn btn-outline-secondary <?= $days===1?'active':'' ?>" href="?days=1">Hoje</a>
                <a class="btn btn-outline-secondary <?= $days===7?'active':'' ?>" href="?days=7">7 dias</a>
                <a class="btn btn-outline-secondary <?= $days===30?'active':'' ?>" href="?days=30">30 dias</a>
            </div>
        </div>

        <?php foreach ($alerts as $a): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><div><?= esc($a) ?></div></div>
        <?php endforeach; ?>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3"><div class="kpi"><div class="v text-<?= ($rate===null?'secondary':($rate>=85?'success':($rate>=70?'warning':'danger'))) ?>"><?= $fmtPct($rate) ?></div><div class="l">Taxa de sucesso</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi"><div class="v"><?= $tot ?></div><div class="l">Tentativas (<?= $days ?>d)</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi"><div class="v"><?= $registered ?></div><div class="l">Pontos batidos</div></div></div>
            <div class="col-6 col-lg-3"><div class="kpi"><div class="v"><?= $fmtPct($avgConf) ?></div><div class="l">Confiança média</div></div></div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <section class="app-section-card">
                    <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-hdd-network"></i>Dispositivos</span><h2 class="app-section-card__title">Saúde (<?= $onlineCount ?> online)</h2></header>
                    <div class="table-responsive">
                        <?php if (empty($devices)): ?><div class="p-4 text-center text-muted">Nenhum dispositivo cadastrado. <a href="kiosk_devices.php">Adicionar</a>.</div><?php else: ?>
                        <table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Nome</th><th class="text-center">Estado</th><th>Última atividade</th></tr></thead><tbody>
                        <?php foreach ($devices as $d): $seen=$d['last_seen_at']?strtotime($d['last_seen_at']):0; $online=$seen&&(time()-$seen)<=600; ?>
                            <tr>
                                <td><?= esc($d['name']) ?><?php if((int)$d['active']!==1): ?> <span class="badge bg-secondary">inativo</span><?php endif; ?></td>
                                <td class="text-center"><?= $online ? '<span class="badge bg-success rounded-pill">online</span>' : '<span class="badge bg-secondary rounded-pill">offline</span>' ?></td>
                                <td class="small text-muted"><?= $d['last_seen_at'] ? esc($d['last_seen_at']) : 'nunca' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody></table>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <div class="col-lg-6">
                <section class="app-section-card mb-4">
                    <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-x-octagon"></i>Recusas</span><h2 class="app-section-card__title">Motivos (<?= $refused ?> total)</h2></header>
                    <div class="table-responsive">
                        <?php if (empty($refRows)): ?><div class="p-4 text-center text-muted">Sem recusas no período. 🎉</div><?php else: ?>
                        <table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Motivo</th><th class="text-end">Qtd</th></tr></thead><tbody>
                        <?php foreach ($refRows as $r): ?><tr><td><span class="badge bg-light text-dark border"><?= esc($r['status']) ?></span></td><td class="text-end"><?= (int)$r['c'] ?></td></tr><?php endforeach; ?>
                        </tbody></table>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="app-section-card">
                    <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-shield-exclamation"></i>Suspeitos</span><h2 class="app-section-card__title">Muitas falhas em 24h</h2></header>
                    <div class="table-responsive">
                        <?php if (empty($susp)): ?><div class="p-4 text-center text-muted">Nada suspeito. 👍</div><?php else: ?>
                        <table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Colaborador</th><th class="text-end">Falhas</th><th></th></tr></thead><tbody>
                        <?php foreach ($susp as $s): ?><tr><td><?= esc($s['name'] ?? ('#'.$s['teacher_id'])) ?></td><td class="text-end text-danger fw-bold"><?= (int)$s['c'] ?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="kiosk_logs.php?status=not_matched">ver logs</a></td></tr><?php endforeach; ?>
                        </tbody></table>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>
</body>
</html>

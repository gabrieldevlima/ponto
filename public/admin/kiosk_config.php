<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('kiosk.manage')) {
    http_response_code(403);
    flash_redirect('error', 'Sem permissão para gerenciar o quiosque.', 'dashboard.php');
}
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['act'] ?? 'save';

    if ($act === 'calibrate') {
        $_SESSION['kiosk_calib'] = kiosk_calibrate($pdo, 200);
        audit_log('update', 'kiosk_config', null, ['action' => 'calibrate']);
        flash_redirect('success', 'Calibração concluída — veja a sugestão abaixo.', 'kiosk_config.php');
    }
    if ($act === 'apply_threshold') {
        $v = (float)($_POST['threshold'] ?? 0);
        if ($v > 0 && $v <= 1.5) {
            set_setting('kiosk_face_match_threshold', (string)$v);
            audit_log('update', 'kiosk_config', null, ['applied_threshold' => $v]);
            flash_redirect('success', 'Limiar de reconhecimento aplicado: ' . $v, 'kiosk_config.php');
        }
        flash_redirect('error', 'Limiar sugerido inválido.', 'kiosk_config.php');
    }

    // act = save
    set_setting('kiosk_enabled', isset($_POST['kiosk_enabled']) ? '1' : '0');
    set_setting('kiosk_fallback_enabled', isset($_POST['kiosk_fallback_enabled']) ? '1' : '0');
    set_setting('kiosk_dedup_window_seconds', (string)max(0, (int)($_POST['kiosk_dedup_window_seconds'] ?? 120)));
    set_setting('kiosk_cpf_max_fails', (string)max(0, (int)($_POST['kiosk_cpf_max_fails'] ?? 5)));
    set_setting('kiosk_cpf_lockout_minutes', (string)max(1, (int)($_POST['kiosk_cpf_lockout_minutes'] ?? 10)));
    set_setting('kiosk_photo_retention_days', (string)max(1, (int)($_POST['kiosk_photo_retention_days'] ?? 90)));
    $thr = trim((string)($_POST['kiosk_face_match_threshold'] ?? ''));
    if ($thr !== '' && (!is_numeric($thr) || (float)$thr <= 0 || (float)$thr > 1.5)) $thr = '';
    set_setting('kiosk_face_match_threshold', $thr);

    // Auto-confirmação (híbrido). A confiança vem em % e é guardada como 0..1 (que o kiosk.php lê).
    set_setting('kiosk_auto_confirm', isset($_POST['kiosk_auto_confirm']) ? '1' : '0');
    set_setting('kiosk_auto_confirm_seconds', (string)max(1, min(15, (int)($_POST['kiosk_auto_confirm_seconds'] ?? 3))));
    $acPct = max(30, min(100, (int)($_POST['kiosk_auto_confirm_min_pct'] ?? 80)));
    set_setting('kiosk_auto_confirm_min_confidence', number_format($acPct / 100, 2, '.', ''));
    set_setting('kiosk_sound', isset($_POST['kiosk_sound']) ? '1' : '0');

    audit_log('update', 'kiosk_config', null, ['action' => 'save']);
    flash_redirect('success', 'Configurações do quiosque salvas.', 'kiosk_config.php');
}

$msg = $_GET['msg'] ?? '';
$err = $_GET['error'] ?? '';
$enabled  = get_setting('kiosk_enabled', '0') === '1';
$fallback = get_setting('kiosk_fallback_enabled', '0') === '1';
$dedup    = (int)(get_setting('kiosk_dedup_window_seconds', '120') ?? '120');
$thrOver  = (string)(get_setting('kiosk_face_match_threshold', '') ?? '');
$maxFails = (int)(get_setting('kiosk_cpf_max_fails', '5') ?? '5');
$lockMin  = (int)(get_setting('kiosk_cpf_lockout_minutes', '10') ?? '10');
$retDays  = (int)(get_setting('kiosk_photo_retention_days', '90') ?? '90');
$autoConf = get_setting('kiosk_auto_confirm', '1') === '1';
$autoSec  = (int)(get_setting('kiosk_auto_confirm_seconds', '3') ?? '3');
$autoPct  = (int)round(((float)(get_setting('kiosk_auto_confirm_min_confidence', '0.80') ?? '0.80')) * 100);
$soundOn  = get_setting('kiosk_sound', '1') === '1';

$calib = $_SESSION['kiosk_calib'] ?? null;
unset($_SESSION['kiosk_calib']);

$minDesc = function_exists('get_min_face_descriptors_for_auth') ? get_min_face_descriptors_for_auth() : 3;
$enrolled = (int)($pdo->query("SELECT COUNT(*) FROM teachers WHERE active=1 AND face_descriptors IS NOT NULL AND face_descriptors <> '' AND face_descriptors <> '[]'")->fetchColumn() ?: 0);
$activeT  = (int)($pdo->query("SELECT COUNT(*) FROM teachers WHERE active=1")->fetchColumn() ?: 0);
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Quiosque · Configuração | DEEDO Ponto</title>
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
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-camera-video"></i></div><div><h1 class="app-page-title">Quiosque · Configuração</h1><p class="app-page-subtitle">Reconhecimento facial local (face-api.js), 1:1 por CPF — sem API externa, sem custo.</p></div></div></div>

        <?php if ($msg): ?><div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><div><?= esc($msg) ?></div><button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><div><?= esc($err) ?></div><button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="app-section-card">
                    <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-sliders"></i>Geral</span><h2 class="app-section-card__title">Parâmetros do Quiosque</h2></header>
                    <div class="app-section-card__body">
                        <form method="post" action="">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="act" value="save">

                            <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-3">
                                <div><i class="bi bi-power me-2 text-success"></i><span class="fw-semibold">Modo quiosque ativo</span></div>
                                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="kiosk_enabled" <?= $enabled ? 'checked' : '' ?>></div>
                            </div>
                            <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-3">
                                <div><i class="bi bi-shield-exclamation me-2 text-warning"></i><span class="fw-semibold">Permitir fallback CPF + PIN</span><div class="text-muted" style="font-size:.75rem;">Se a face não confirmar, em dispositivos com fallback autorizado.</div></div>
                                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="kiosk_fallback_enabled" <?= $fallback ? 'checked' : '' ?>></div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Janela anti-duplicação (s)</label>
                                    <input type="number" class="form-control" name="kiosk_dedup_window_seconds" min="0" value="<?= (int)$dedup ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Limiar de reconhecimento (override)</label>
                                    <input type="text" class="form-control" name="kiosk_face_match_threshold" value="<?= esc($thrOver) ?>" placeholder="vazio = automático (0.65)">
                                    <div class="form-text">Menor = mais rígido. Use a calibração ao lado para sugerir.</div>
                                </div>
                            </div>
                            <hr>
                            <div class="fw-semibold mb-2"><i class="bi bi-shield-lock me-1"></i>Segurança & retenção</div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4"><label class="form-label small">Máx. falhas por CPF</label><input type="number" class="form-control form-control-sm" name="kiosk_cpf_max_fails" min="0" value="<?= (int)$maxFails ?>"></div>
                                <div class="col-md-4"><label class="form-label small">Bloqueio (min)</label><input type="number" class="form-control form-control-sm" name="kiosk_cpf_lockout_minutes" min="1" value="<?= (int)$lockMin ?>"></div>
                                <div class="col-md-4"><label class="form-label small">Retenção de fotos (dias)</label><input type="number" class="form-control form-control-sm" name="kiosk_photo_retention_days" min="1" value="<?= (int)$retDays ?>"></div>
                            </div>
                            <hr>
                            <div class="fw-semibold mb-2"><i class="bi bi-lightning-charge me-1"></i>Auto-confirmação <span class="text-muted fw-normal">(reduz o toque extra)</span></div>
                            <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-3">
                                <div><i class="bi bi-magic me-2 text-primary"></i><span class="fw-semibold">Registrar automaticamente</span><div class="text-muted" style="font-size:.75rem;">Só quando a ação é única (entrada/retorno) e a confiança ≥ piso. Exibe contagem cancelável; em ação ambígua (saída × intervalo) ou confiança baixa, pede toque.</div></div>
                                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="kiosk_auto_confirm" <?= $autoConf ? 'checked' : '' ?>></div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Contagem antes de registrar (s)</label>
                                    <input type="number" class="form-control form-control-sm" name="kiosk_auto_confirm_seconds" min="1" max="15" value="<?= (int)$autoSec ?>">
                                    <div class="form-text">Tempo para cancelar ("Não sou eu") antes do registro automático.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Confiança mínima para auto (%)</label>
                                    <input type="number" class="form-control form-control-sm" name="kiosk_auto_confirm_min_pct" min="30" max="100" value="<?= (int)$autoPct ?>">
                                    <div class="form-text">Abaixo disso, pede toque de confirmação. Recomendado ≥ 80%.</div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-3">
                                <div><i class="bi bi-volume-up me-2 text-primary"></i><span class="fw-semibold">Feedback sonoro</span><div class="text-muted" style="font-size:.75rem;">Bip de sucesso/erro no totem (acessibilidade).</div></div>
                                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="kiosk_sound" <?= $soundOn ? 'checked' : '' ?>></div>
                            </div>

                            <button class="btn btn-success" type="submit"><i class="bi bi-save me-1"></i>Salvar configurações</button>
                        </form>
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="app-section-card mb-4">
                    <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-bullseye"></i>Calibração</span><h2 class="app-section-card__title">Sugerir limiar pelos cadastros</h2></header>
                    <div class="app-section-card__body">
                        <p class="text-muted small">Analisa os rostos cadastrados e compara distâncias do mesmo colaborador (genuíno) com as de pessoas diferentes (impostor) para sugerir um limiar.</p>
                        <form method="post" action=""><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="act" value="calibrate">
                            <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-graph-up me-1"></i>Analisar cadastros</button>
                        </form>
                        <?php if (is_array($calib) && empty($calib['error'])): ?>
                            <div class="mt-3 small">
                                <div>Colaboradores analisados: <b><?= (int)$calib['teachers'] ?></b></div>
                                <div>Genuíno (mesmo rosto) — mediana <b><?= esc((string)($calib['genuine']['median'] ?? '—')) ?></b>, p95 <b><?= esc((string)($calib['genuine']['p95'] ?? '—')) ?></b></div>
                                <div>Impostor (rostos diferentes) — p5 <b><?= esc((string)($calib['impostor']['p5'] ?? '—')) ?></b>, mediana <b><?= esc((string)($calib['impostor']['median'] ?? '—')) ?></b></div>
                                <div class="mt-1">Separação: <?= !empty($calib['separable']) ? '<span class="text-success">boa</span>' : '<span class="text-warning">com sobreposição</span>' ?></div>
                                <div>Limiar atual: <b><?= esc((string)($calib['current_threshold'] ?? '—')) ?></b> · Sugerido: <b class="text-primary"><?= esc((string)($calib['suggested_threshold'] ?? '—')) ?></b></div>
                                <?php if (!empty($calib['suggested_threshold'])): ?>
                                <form method="post" action="" class="mt-2"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="act" value="apply_threshold"><input type="hidden" name="threshold" value="<?= esc((string)$calib['suggested_threshold']) ?>">
                                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2 me-1"></i>Aplicar limiar sugerido (<?= esc((string)$calib['suggested_threshold']) ?>)</button>
                                </form>
                                <?php endif; ?>
                                <?php if (empty($calib['separable'])): ?><div class="text-muted mt-2" style="font-size:.75rem;">Sobreposição é normal no motor local e reforça o uso do modo 1:1 (CPF + face). O limiar sugerido prioriza segurança (menos falso-aceite).</div><?php endif; ?>
                            </div>
                        <?php elseif (is_array($calib)): ?>
                            <div class="alert alert-warning mt-3 small mb-0">Falha na calibração: <?= esc((string)($calib['error'] ?? 'erro')) ?></div>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="app-section-card">
                    <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-people"></i>Cobertura</span><h2 class="app-section-card__title">Cadastro Facial</h2></header>
                    <div class="app-section-card__body">
                        <p class="mb-1"><span class="fs-3 fw-bold"><?= $enrolled ?></span> <span class="text-muted">/ <?= $activeT ?> ativos com face cadastrada (mín. <?= (int)$minDesc ?> amostras)</span></p>
                        <a href="kiosk_dashboard.php" class="btn btn-sm btn-outline-primary mt-1"><i class="bi bi-speedometer2 me-1"></i>Painel</a>
                        <a href="kiosk_enrollment.php" class="btn btn-sm btn-outline-secondary mt-1"><i class="bi bi-person-bounding-box me-1"></i>Cadastros</a>
                        <a href="kiosk_devices.php" class="btn btn-sm btn-outline-secondary mt-1"><i class="bi bi-tablet me-1"></i>Dispositivos</a>
                    </div>
                </section>
            </div>
        </div>
    </div>
</body>
</html>

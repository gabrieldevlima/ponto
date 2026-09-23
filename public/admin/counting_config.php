<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);
if (!is_network_admin($adm)) {
    http_response_code(403);
    flash_redirect('error', 'Acesso restrito a administradores da rede.', 'dashboard.php');
}

$msg = $_GET['msg'] ?? '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $raw = trim($_POST['counting_start_date'] ?? '');

    if ($raw === '') {
        // Vazio = limpa o corte global (volta a contar a partir do cadastro de cada colaborador)
        set_setting('counting_start_date', '');
        audit_log('update', 'app_settings', 'counting_start_date', ['value' => '']);
        header('Location: counting_config.php?msg=' . urlencode('Data de início removida. O sistema conta a partir do cadastro de cada colaborador.'));
        exit;
    }

    $d = DateTime::createFromFormat('Y-m-d', $raw);
    $valid = $d && $d->format('Y-m-d') === $raw;
    if (!$valid) {
        $err = 'Data inválida. Use o formato AAAA-MM-DD.';
    } else {
        set_setting('counting_start_date', $raw);
        audit_log('update', 'app_settings', 'counting_start_date', ['value' => $raw]);
        header('Location: counting_config.php?msg=' . urlencode('Data de início da contagem salva.'));
        exit;
    }
}

$current = (string) (get_setting('counting_start_date', '') ?? '');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Início da Contagem | DEEDO Ponto</title>
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

    <div class="container-fluid admin-content">
        <div class="app-page-header">
            <div class="app-page-header__main">
                <div class="app-page-icon"><i class="bi bi-calendar-range"></i></div>
                <div>
                    <h1 class="app-page-title">Início da Contagem</h1>
                    <p class="app-page-subtitle">A partir de que dia o sistema conta presenças e faltas.</p>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i>
                <div><?= esc($msg) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div><?= esc($err) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <section class="app-section-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-calendar-check"></i>Contagem</span>
                <h2 class="app-section-card__title">Data de início</h2>
            </header>
            <div class="app-section-card__body">
                <div class="alert alert-info d-flex align-items-start gap-2">
                    <i class="bi bi-info-circle-fill mt-1"></i>
                    <div>
                        A partir desta data o sistema passa a contar presenças e faltas.
                        Dias anteriores são ignorados nos relatórios. Deixe em branco para
                        contar a partir do cadastro de cada colaborador.
                    </div>
                </div>
                <form action="" method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Data de início da contagem</label>
                            <input type="date" class="form-control" name="counting_start_date" value="<?= esc($current) ?>">
                            <div class="form-text">Formato AAAA-MM-DD. Em branco = sem corte global.</div>
                        </div>
                    </div>
                    <hr>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Salvar
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-secondary">Cancelar</a>
                </form>
            </div>
        </section>
    </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

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
    $act = $_POST['act'] ?? '';
    if ($act === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $schoolId = (int)($_POST['school_id'] ?? 0) ?: null;
        $loc = trim((string)($_POST['location_label'] ?? ''));
        $fallback = isset($_POST['fallback_allowed']) ? 1 : 0;
        if ($name === '') flash_redirect('error', 'Informe um nome para o dispositivo.', 'kiosk_devices.php');
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $st = $pdo->prepare("INSERT INTO kiosk_devices (device_token_hash, name, school_id, location_label, fallback_allowed, created_by_admin_id) VALUES (?,?,?,?,?,?)");
        $st->execute([$hash, $name, $schoolId, ($loc !== '' ? $loc : null), $fallback, (int)($_SESSION['admin_id'] ?? 0) ?: null]);
        $newId = (int)$pdo->lastInsertId();
        audit_log('create', 'kiosk_device', $newId, ['name' => $name, 'school_id' => $schoolId, 'fallback' => $fallback]);
        // Mostra o token cru UMA vez (não persiste em claro).
        $_SESSION['kiosk_flash_token'] = ['name' => $name, 'token' => $token, 'id' => $newId];
        flash_redirect('success', 'Dispositivo criado. Copie o link de pareamento agora — ele não será exibido novamente.', 'kiosk_devices.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && $act === 'toggle_active') {
        $pdo->prepare("UPDATE kiosk_devices SET active = 1 - active WHERE id = ?")->execute([$id]);
        audit_log('update', 'kiosk_device', $id, ['toggle' => 'active']);
        flash_redirect('success', 'Status do dispositivo atualizado.', 'kiosk_devices.php');
    }
    if ($id > 0 && $act === 'toggle_fallback') {
        $pdo->prepare("UPDATE kiosk_devices SET fallback_allowed = 1 - fallback_allowed WHERE id = ?")->execute([$id]);
        audit_log('update', 'kiosk_device', $id, ['toggle' => 'fallback']);
        flash_redirect('success', 'Permissão de fallback atualizada.', 'kiosk_devices.php');
    }
    if ($id > 0 && $act === 'delete') {
        $pdo->prepare("DELETE FROM kiosk_devices WHERE id = ?")->execute([$id]);
        audit_log('delete', 'kiosk_device', $id, []);
        flash_redirect('success', 'Dispositivo removido.', 'kiosk_devices.php');
    }
}

$msg = $_GET['msg'] ?? '';
$err = $_GET['error'] ?? '';

$newToken = $_SESSION['kiosk_flash_token'] ?? null;
unset($_SESSION['kiosk_flash_token']);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$publicDir = rtrim(str_replace('\\', '/', dirname(dirname((string)$_SERVER['SCRIPT_NAME']))), '/');
$pairBase = $scheme . '://' . $host . $publicDir . '/kiosk.php?pair=';

$schools = [];
try { $schools = $pdo->query("SELECT id, name FROM schools ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$schoolName = [];
foreach ($schools as $s) $schoolName[(int)$s['id']] = $s['name'];

$devices = $pdo->query("SELECT * FROM kiosk_devices ORDER BY active DESC, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Quiosque · Dispositivos | DEEDO Ponto</title>
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
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-tablet"></i></div><div><h1 class="app-page-title">Quiosque · Dispositivos</h1><p class="app-page-subtitle">Autorize totens/tablets e gere o link de pareamento de cada terminal.</p></div></div></div>

        <?php if ($msg): ?><div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><div><?= esc($msg) ?></div><button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><div><?= esc($err) ?></div><button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <?php if ($newToken): $url = $pairBase . $newToken['token']; ?>
        <div class="alert alert-info border-info">
            <div class="fw-bold mb-2"><i class="bi bi-link-45deg"></i> Link de pareamento de "<?= esc($newToken['name']) ?>"</div>
            <p class="small mb-2">Abra este link <b>uma vez</b> no dispositivo do quiosque. O token será salvo no aparelho. Por segurança, ele não será exibido novamente.</p>
            <div class="input-group">
                <input type="text" class="form-control font-monospace" id="pairUrl" value="<?= esc($url) ?>" readonly>
                <button class="btn btn-primary" onclick="navigator.clipboard.writeText(document.getElementById('pairUrl').value);this.innerHTML='<i class=\'bi bi-check\'></i> Copiado'"><i class="bi bi-clipboard"></i> Copiar</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-4">
                <section class="app-section-card">
                    <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-plus-circle"></i>Novo</span><h2 class="app-section-card__title">Autorizar Dispositivo</h2></header>
                    <div class="app-section-card__body">
                        <form method="post" action="">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="act" value="create">
                            <div class="mb-3"><label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required maxlength="120" placeholder="Ex.: Totem Recepção"></div>
                            <div class="mb-3"><label class="form-label fw-semibold">Instituição</label>
                                <select name="school_id" class="form-select">
                                    <option value="0">— Nenhuma / definir por colaborador —</option>
                                    <?php foreach ($schools as $s): ?><option value="<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></option><?php endforeach; ?>
                                </select>
                                <div class="form-text">A escola do dispositivo define a unidade do ponto registrado.</div>
                            </div>
                            <div class="mb-3"><label class="form-label fw-semibold">Localização (opcional)</label><input type="text" name="location_label" class="form-control" maxlength="160" placeholder="Ex.: Entrada principal"></div>
                            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="fb" name="fallback_allowed"><label class="form-check-label" for="fb">Permitir fallback CPF+PIN se a face não confirmar</label></div>
                            <button class="btn btn-success w-100" type="submit"><i class="bi bi-plus-lg me-1"></i>Criar e gerar link</button>
                        </form>
                    </div>
                </section>
            </div>

            <div class="col-lg-8">
                <section class="app-section-card app-table-card">
                    <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-list-ul"></i>Dispositivos</span><h2 class="app-section-card__title">Terminais Autorizados</h2><span class="app-section-card__hint"><?= count($devices) ?> dispositivo(s)</span></header>
                    <div class="table-responsive">
                        <?php if (empty($devices)): ?>
                            <div class="p-5 text-center text-muted"><i class="bi bi-tablet fs-1 d-block mb-2"></i><p class="mb-0">Nenhum dispositivo autorizado.</p></div>
                        <?php else: ?>
                            <table class="table table-hover align-middle table-sm mb-0">
                                <thead class="table-light"><tr><th>Nome</th><th>Instituição</th><th class="text-center">Fallback</th><th class="text-center">Status</th><th>Última atividade</th><th class="text-center">Ações</th></tr></thead>
                                <tbody>
                                <?php foreach ($devices as $d): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= esc($d['name']) ?></div><?php if (!empty($d['location_label'])): ?><div class="text-muted small"><?= esc($d['location_label']) ?></div><?php endif; ?></td>
                                        <td><?= esc($schoolName[(int)($d['school_id'] ?? 0)] ?? '—') ?></td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="act" value="toggle_fallback"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                                <button class="btn btn-sm btn-outline-<?= (int)$d['fallback_allowed']===1?'warning':'secondary' ?>" title="Alternar fallback"><i class="bi bi-shield-<?= (int)$d['fallback_allowed']===1?'check':'slash' ?>"></i></button>
                                            </form>
                                        </td>
                                        <td class="text-center"><?= (int)$d['active']===1 ? '<span class="badge bg-success rounded-pill">Ativo</span>' : '<span class="badge bg-secondary rounded-pill">Inativo</span>' ?></td>
                                        <td class="small text-muted"><?= $d['last_seen_at'] ? esc($d['last_seen_at']) : 'nunca' ?><?php if (!empty($d['last_ip'])): ?><br><span class="text-muted"><?= esc($d['last_ip']) ?></span><?php endif; ?></td>
                                        <td class="text-center" style="white-space:nowrap;">
                                            <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="act" value="toggle_active"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                                <button class="btn btn-sm btn-outline-primary" title="Ativar/Desativar"><i class="bi bi-power"></i></button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Remover este dispositivo? O terminal precisará ser pareado novamente.');"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>
</body>
</html>

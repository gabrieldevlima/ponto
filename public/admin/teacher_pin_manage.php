<?php
// Painel de gestão de PIN: lista colaboradores, status do PIN, ações rápidas.
// Também permite liberar "auto-enrollment por CPF" (pin_self_enroll_allowed) para casos
// em que o colaborador ainda não tem face cadastrada.

require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// Ação: alternar pin_self_enroll_allowed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_self_enroll') {
    csrf_verify();
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $newValue  = !empty($_POST['allow']) ? 1 : 0;
    if ($teacherId > 0) {
        [$scopeSql, $scopeParams] = admin_scope_where('t');
        $st = $pdo->prepare("UPDATE teachers t SET pin_self_enroll_allowed = ? WHERE t.id = ? AND $scopeSql");
        $st->execute(array_merge([$newValue, $teacherId], $scopeParams));
        audit_log($newValue ? 'pin.self_enroll_allowed' : 'pin.self_enroll_revoked', 'teacher', $teacherId, [
            'admin_id' => (int)($admin['id'] ?? 0)
        ]);
        $_SESSION['info_msg'] = $newValue ? 'Auto-geração de PIN liberada.' : 'Auto-geração de PIN desativada.';
    }
    header('Location: teacher_pin_manage.php');
    exit;
}

// Ação: limpar dispositivos confiáveis
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_devices') {
    csrf_verify();
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    if ($teacherId > 0) {
        [$scopeSql, $scopeParams] = admin_scope_where('t');
        $st = $pdo->prepare("SELECT t.id FROM teachers t WHERE t.id = ? AND $scopeSql LIMIT 1");
        $st->execute(array_merge([$teacherId], $scopeParams));
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE teacher_trusted_devices SET is_active = 0 WHERE teacher_id = ?")->execute([$teacherId]);
            audit_log('device.cleared_trusted', 'teacher', $teacherId, [
                'admin_id' => (int)($admin['id'] ?? 0)
            ]);
            $_SESSION['info_msg'] = 'Dispositivos confiáveis removidos.';
        }
    }
    header('Location: teacher_pin_manage.php');
    exit;
}

// Filtro
$q = trim($_GET['q'] ?? '');
$filter = $_GET['status'] ?? '';  // 'has_pin', 'no_pin', ''

[$scopeSql, $scopeParams] = admin_scope_where('t');
$where = ["t.active = 1", $scopeSql];
$params = $scopeParams;
if ($q !== '') {
    $where[] = "(t.name LIKE ? OR t.cpf LIKE ?)";
    $params[] = "%$q%";
    $params[] = '%' . preg_replace('/\D/', '', $q) . '%';
}
if ($filter === 'has_pin') {
    $where[] = "t.pin_hash IS NOT NULL AND t.pin_hash <> ''";
} elseif ($filter === 'no_pin') {
    $where[] = "(t.pin_hash IS NULL OR t.pin_hash = '')";
}

$sql = "
    SELECT t.id, t.name, t.cpf, t.pin_hash, t.pin_changed_at, t.pin_self_enroll_allowed,
           t.face_descriptors,
           (SELECT COUNT(*) FROM teacher_trusted_devices d WHERE d.teacher_id = t.id AND d.is_active = 1) AS trusted_devices,
           (SELECT MAX(created_at) FROM auth_attempt_logs a WHERE a.teacher_id = t.id AND a.attempt_type = 'pin' AND a.success = 0) AS last_fail
      FROM teachers t
     WHERE " . implode(' AND ', $where) . "
     ORDER BY t.name ASC
     LIMIT 500
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$msg = $_SESSION['info_msg'] ?? '';
unset($_SESSION['info_msg']);
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>PINs dos Colaboradores | DEEDO Ponto</title>
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
    <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-key"></i></div><div><h1 class="app-page-title">PINs dos Colaboradores</h1><p class="app-page-subtitle">Gere, reseta e controla a liberação de PIN por colaborador.</p></div></div></div>

    <?php if ($msg): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <?= esc($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form class="row g-2 mb-3" method="get">
        <div class="col-md-6">
            <input type="search" name="q" class="form-control" placeholder="Buscar por nome ou CPF" value="<?= esc($q) ?>">
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select">
                <option value="">Todos</option>
                <option value="has_pin" <?= $filter === 'has_pin' ? 'selected' : '' ?>>Com PIN ativo</option>
                <option value="no_pin" <?= $filter === 'no_pin' ? 'selected' : '' ?>>Ainda sem PIN</option>
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>
    </form>

    <section class="app-section-card app-table-card">
        <header class="app-section-card__header">
            <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-key"></i>Lista</span>
            <h2 class="app-section-card__title">PINs dos Colaboradores</h2>
        </header>
        <div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Colaborador</th>
                            <th scope="col">CPF</th>
                            <th scope="col">Status PIN</th>
                            <th scope="col">Face</th>
                            <th scope="col">Devices confiáveis</th>
                            <th scope="col" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-center text-muted p-4">Nenhum colaborador encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r):
                        $hasPin = !empty($r['pin_hash']);
                        $hasFace = !empty($r['face_descriptors']);
                        $allowSelf = (int)$r['pin_self_enroll_allowed'] === 1;
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= esc($r['name']) ?></td>
                            <td class="text-muted small"><?= esc(mask_cpf((string)$r['cpf'])) ?></td>
                            <td>
                                <?php if ($hasPin): ?>
                                    <span class="badge bg-success-subtle text-success-emphasis">Ativo</span>
                                    <div class="small text-muted mt-1">
                                        Alterado em
                                        <?= $r['pin_changed_at'] ? esc((new DateTime($r['pin_changed_at']))->format('d/m/Y H:i')) : '—' ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">Nunca gerado</span>
                                    <?php if ($allowSelf): ?>
                                        <div class="small text-warning mt-1"><i class="bi bi-unlock"></i> auto-geração liberada</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasFace): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis">Cadastrada</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis">Sem face</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$r['trusted_devices'] ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="teacher_pin_reset.php?teacher_id=<?= (int)$r['id'] ?>" class="btn btn-outline-warning" title="Gerar/Resetar PIN">
                                        <i class="bi bi-key"></i> <?= $hasPin ? 'Resetar' : 'Gerar' ?>
                                    </a>
                                    <?php if (!$hasFace && !$hasPin): ?>
                                        <form action="" method="post" class="d-inline">
                                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle_self_enroll">
                                            <input type="hidden" name="teacher_id" value="<?= (int)$r['id'] ?>">
                                            <input type="hidden" name="allow" value="<?= $allowSelf ? '0' : '1' ?>">
                                            <button type="submit" class="btn btn-outline-<?= $allowSelf ? 'secondary' : 'info' ?>"
                                                    title="<?= $allowSelf ? 'Revogar auto-geração' : 'Liberar auto-geração por CPF' ?>">
                                                <i class="bi <?= $allowSelf ? 'bi-lock' : 'bi-unlock' ?>"></i>
                                                <?= $allowSelf ? 'Revogar' : 'Liberar' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ((int)$r['trusted_devices'] > 0): ?>
                                        <form action="" method="post" class="d-inline" onsubmit="return confirm('Remover todos os dispositivos confiáveis deste colaborador?');">
                                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="clear_devices">
                                            <input type="hidden" name="teacher_id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Limpar dispositivos">
                                                <i class="bi bi-phone-vibrate"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <div class="mt-3 small text-muted">
        <i class="bi bi-info-circle"></i>
        <strong>Liberar auto-geração:</strong> permite que o colaborador gere o próprio PIN digitando apenas o CPF na tela de "Primeiro acesso". Use apenas quando ele ainda não tem face cadastrada.
    </div>
</div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

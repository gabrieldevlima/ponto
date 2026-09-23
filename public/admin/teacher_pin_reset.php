<?php
// Gerador de PIN pelo admin para um colaborador específico.
// Mostra o PIN UMA vez na tela; o admin repassa presencialmente ao colaborador.
// Auditado em audit_logs como pin.generated_admin_reset.

require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$admin = current_admin($pdo);

$teacherId = (int)($_GET['teacher_id'] ?? $_POST['teacher_id'] ?? 0);
if ($teacherId <= 0) {
    header('Location: teacher_pin_manage.php');
    exit;
}

// Carrega teacher com escopo
[$scopeSql, $scopeParams] = admin_scope_where('t');
$st = $pdo->prepare("SELECT t.id, t.name, t.cpf FROM teachers t WHERE t.id = ? AND $scopeSql LIMIT 1");
$st->execute(array_merge([$teacherId], $scopeParams));
$teacher = $st->fetch(PDO::FETCH_ASSOC);
if (!$teacher) {
    $_SESSION['info_msg'] = 'Colaborador não encontrado ou sem permissão.';
    header('Location: teacher_pin_manage.php');
    exit;
}

$generatedPin = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $generatedPin = pin_set_for_teacher($pdo, $teacherId);
        audit_log('pin.generated_admin_reset', 'teacher', $teacherId, [
            'cpf' => mask_cpf((string)$teacher['cpf']),
            'admin_id' => (int)($admin['id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        $_SESSION['info_msg'] = 'Erro ao gerar PIN: ' . $e->getMessage();
        header('Location: teacher_pin_manage.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Resetar PIN - <?= esc($teacher['name']) ?> | DEEDO Ponto</title>
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
            <div class="app-page-icon is-warning"><i class="bi bi-key"></i></div>
            <div>
                <h1 class="app-page-title">Resetar PIN de colaborador</h1>
                <p class="app-page-subtitle"><?= esc($teacher['name']) ?> — CPF <?= esc(mask_cpf((string)$teacher['cpf'])) ?></p>
            </div>
        </div>
    </div>

    <?php if ($generatedPin !== null): ?>
        <div class="card shadow-sm border-success mb-4">
            <div class="card-body text-center">
                <div class="text-success mb-2"><i class="bi bi-check-circle-fill fs-1"></i></div>
                <h2 class="h4 mb-1">Novo PIN gerado</h2>
                <p class="text-muted">Repasse este PIN ao colaborador presencialmente. Ele não será exibido novamente.</p>
                <div class="bg-light border rounded-3 p-4 my-3" style="font-family: 'Courier New', monospace; font-size: 42px; font-weight: 700; letter-spacing: 8px;">
                    <?= esc($generatedPin) ?>
                </div>
                <button class="btn btn-outline-primary" id="btnCopy" type="button">
                    <i class="bi bi-clipboard me-1"></i> Copiar PIN
                </button>
                <div class="mt-3">
                    <a href="teacher_pin_manage.php" class="btn btn-primary">Voltar para lista</a>
                </div>
            </div>
        </div>
        <script>
        document.getElementById('btnCopy')?.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(<?= json_encode($generatedPin) ?>);
                const b = document.getElementById('btnCopy');
                b.innerHTML = '<i class="bi bi-check2 me-1"></i> Copiado';
                b.classList.replace('btn-outline-primary', 'btn-success');
                setTimeout(() => { location.href = 'teacher_pin_manage.php'; }, 1200);
            } catch (e) {
                alert('Não foi possível copiar automaticamente. Anote o PIN manualmente.');
            }
        });
        </script>
    <?php else: ?>
        <section class="app-section-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-key"></i>Operação</span>
                <h2 class="app-section-card__title">Gerar novo PIN</h2>
            </header>
            <div class="app-section-card__body">
                <div class="alert alert-warning d-flex gap-2 align-items-start">
                    <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
                    <div>
                        <strong>Atenção:</strong> isso invalidará imediatamente o PIN atual do colaborador (se existir).
                        O novo PIN é gerado aleatoriamente e mostrado apenas uma vez.
                    </div>
                </div>
                <form action="" method="post">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="teacher_id" value="<?= (int)$teacher['id'] ?>">
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="teacher_pin_manage.php" class="btn btn-outline-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-key me-1"></i> Gerar novo PIN
                        </button>
                    </div>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('leaves.manage')) {
    http_response_code(403);
    flash_redirect('error', 'Sem permissão para acessar Tipos de Licença.', 'dashboard.php');
}
$pdo = db();

$msg = $_GET['msg'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id) {
    $st = $pdo->prepare("SELECT * FROM leave_types WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
}
require_once __DIR__ . '/../../lib/aej.php';

// Confirmação de que os códigos AEJ foram conferidos contra o Anexo oficial.
// Ato separado da edição: conferir a tabela inteira é uma decisão sobre o
// conjunto, não efeito colateral de salvar um tipo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_codigos_aej'])) {
    csrf_verify();
    $novo = $_POST['confirmar_codigos_aej'] === '1' ? '1' : '0';
    set_setting('aej_codes_conferidos', $novo);
    audit_log('update', 'app_setting', null, ['aej_codes_conferidos' => $novo]);
    header('Location: leave_types.php?msg=' . urlencode(
        $novo === '1'
            ? 'Códigos AEJ marcados como conferidos contra o Anexo oficial.'
            : 'Códigos AEJ voltaram a constar como NÃO conferidos.'
    ));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $paid = isset($_POST['paid']) ? 1 : 0;
    $affects_bank = isset($_POST['affects_bank']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;
    // Código da tabela de ocorrências do AEJ. Sem ele o afastamento sai do
    // arquivo sem classificação — a fiscalização vê o dia sem marcação e não
    // vê o motivo legal da ausência.
    $aej_code = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $_POST['aej_code'] ?? '')) ?: null;
    if ($name === '' || $code === '') $msg = 'Nome e código são obrigatórios.';
    else {
        // Mexer num código invalida a conferência: ela foi feita sobre a tabela
        // como estava. Sem isto, alterar um código depois de confirmar deixaria
        // o sistema afirmando uma conferência que não cobre o valor atual.
        $invalidarConferencia = function (?string $antes, ?string $depois): void {
            if ((string)$antes !== (string)$depois && get_setting('aej_codes_conferidos', '0') === '1') {
                set_setting('aej_codes_conferidos', '0');
                audit_log('update', 'app_setting', null, [
                    'aej_codes_conferidos' => '0',
                    'motivo' => 'codigo AEJ alterado apos a conferencia',
                ]);
            }
        };

        if ($id > 0) {
            $st0 = $pdo->prepare("SELECT aej_code FROM leave_types WHERE id=?");
            $st0->execute([$id]);
            $aejAntes = $st0->fetchColumn();
            $st = $pdo->prepare("UPDATE leave_types SET name=?, code=?, paid=?, affects_bank=?, active=?, aej_code=? WHERE id=?");
            $st->execute([$name, $code, $paid, $affects_bank, $active, $aej_code, $id]);
            audit_log('update', 'leave_type', $id, ['name' => $name, 'code' => $code, 'aej_code' => $aej_code]);
            $invalidarConferencia($aejAntes === false ? null : (string)$aejAntes, $aej_code);
            header('Location: leave_types.php?msg=' . urlencode('Tipo atualizado.'));
            exit;
        } else {
            $st = $pdo->prepare("INSERT INTO leave_types (name, code, paid, affects_bank, active, aej_code) VALUES (?, ?, ?, ?, ?, ?)");
            $st->execute([$name, $code, $paid, $affects_bank, $active, $aej_code]);
            audit_log('create', 'leave_type', $pdo->lastInsertId(), ['name' => $name, 'code' => $code, 'aej_code' => $aej_code]);
            // Tipo novo nunca esteve na tabela conferida.
            $invalidarConferencia(null, $aej_code ?? '');
            header('Location: leave_types.php?msg=' . urlencode('Tipo criado.'));
            exit;
        }
    }
}

$rows = $pdo->query("SELECT * FROM leave_types ORDER BY active DESC, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Tipos de Afastamento | DEEDO Ponto</title>
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

        <!-- Header -->
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon is-warning"><i class="bi bi-calendar-x"></i></div><div><h1 class="app-page-title">Tipos de Afastamento</h1><p class="app-page-subtitle">Gerencie os tipos de afastamento, licencas e faltas utilizados no sistema.</p></div></div></div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= str_contains(strtolower($msg), 'erro') || str_contains(strtolower($msg), 'obrigat') ? 'danger' : 'success' ?> alert-dismissible fade show d-flex align-items-center gap-2">
                <i class="bi bi-<?= str_contains(strtolower($msg), 'erro') || str_contains(strtolower($msg), 'obrigat') ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
                <div><?= esc($msg) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php
        $semCodigoAej = 0;
        foreach ($rows as $r) {
            if ((int)($r['active'] ?? 0) === 1 && ($r['aej_code'] ?? '') === '') $semCodigoAej++;
        }
        $codigosConferidos = aej_codigos_conferidos();
        ?>
        <?php if ($semCodigoAej === 0 && !$codigosConferidos): ?>
            <div class="alert alert-warning d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                <div class="flex-grow-1">
                    <strong>Codigos AEJ preenchidos provisoriamente.</strong>
                    Os codigos abaixo foram atribuidos por categoria e <strong>nao foram
                    conferidos contra a tabela de ocorrencias do Anexo oficial</strong> da
                    Portaria MTP 671/2021. Sao mnemonicos internos, nao codigos oficiais —
                    substitua-os pelos codigos do Anexo antes de entregar qualquer AEJ a
                    fiscalizacao. Enquanto isto nao for confirmado, o pre-voo do AEJ avisa.
                    <form method="post" class="mt-2">
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="confirmar_codigos_aej" value="1">
                        <button class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-check2-square me-1"></i>Conferi contra o Anexo oficial
                        </button>
                    </form>
                </div>
            </div>
        <?php elseif ($codigosConferidos): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 py-2">
                <i class="bi bi-check-circle-fill"></i>
                <div class="flex-grow-1 small">
                    Codigos AEJ conferidos contra o Anexo oficial.
                    Alterar qualquer codigo revoga esta confirmacao automaticamente.
                </div>
                <form method="post" class="mb-0">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="confirmar_codigos_aej" value="0">
                    <button class="btn btn-sm btn-outline-secondary">Revogar</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- Form Card -->
            <div class="col-lg-4">
                <section class="app-section-card">
                    <header class="app-section-card__header">
                        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-<?= $row ? 'pencil-square' : 'plus-circle' ?>"></i><?= $row ? 'Edição' : 'Novo' ?></span>
                        <h2 class="app-section-card__title"><?= $row ? 'Editar Tipo' : 'Novo Tipo' ?></h2>
                    </header>
                    <div class="app-section-card__body">
                        <form action="" method="post" autocomplete="off">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
                                <input class="form-control" name="name" required maxlength="100"
                                       value="<?= esc($row['name'] ?? '') ?>" placeholder="Ex.: Licenca Medica, Ferias">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Codigo <span class="text-danger">*</span></label>
                                <input class="form-control" name="code" required maxlength="50"
                                       value="<?= esc($row['code'] ?? '') ?>" placeholder="Ex.: LM, FER, AFD">
                                <div class="form-text">Identificador curto usado em relatorios.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Codigo AEJ
                                    <span class="badge bg-warning text-dark ms-1">Portaria 671</span>
                                </label>
                                <input class="form-control" name="aej_code" maxlength="10"
                                       value="<?= esc($row['aej_code'] ?? '') ?>" placeholder="Ex.: 01">
                                <div class="form-text">
                                    Codigo da tabela de ocorrencias do AEJ. Sem ele o afastamento sai do
                                    arquivo sem classificacao.
                                </div>
                            </div>

                            <!-- Toggles -->
                            <div class="d-flex flex-column gap-2 mb-4">
                                <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2">
                                    <div>
                                        <i class="bi bi-cash-coin me-2 text-success"></i>
                                        <span class="fw-semibold small">Remunerado</span>
                                        <div class="text-muted" style="font-size:0.75rem;">Afastamento com pagamento</div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="paid" name="paid"
                                               <?= (int)($row['paid'] ?? 1) === 1 ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2">
                                    <div>
                                        <i class="bi bi-bank me-2 text-primary"></i>
                                        <span class="fw-semibold small">Afeta Banco de Horas</span>
                                        <div class="text-muted" style="font-size:0.75rem;">Debita do banco de horas</div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="affects_bank" name="affects_bank"
                                               <?= (int)($row['affects_bank'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between border rounded px-3 py-2">
                                    <div>
                                        <i class="bi bi-toggle-on me-2 text-info"></i>
                                        <span class="fw-semibold small">Ativo</span>
                                        <div class="text-muted" style="font-size:0.75rem;">Disponivel para novos afastamentos</div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="active" name="active"
                                               <?= (int)($row['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-<?= $row ? 'warning' : 'success' ?> flex-grow-1" type="submit">
                                    <i class="bi bi-<?= $row ? 'save' : 'plus-lg' ?> me-1"></i>
                                    <?= $row ? 'Salvar Alteracoes' : 'Criar Tipo' ?>
                                </button>
                                <?php if ($row): ?>
                                <a class="btn btn-outline-secondary" href="leave_types.php">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </section>
            </div>

            <!-- Table Card -->
            <div class="col-lg-8">
                <section class="app-section-card app-table-card">
                    <header class="app-section-card__header">
                        <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-list-ul"></i>Lista</span>
                        <h2 class="app-section-card__title">Tipos Cadastrados</h2>
                        <span class="app-section-card__hint"><?= count($rows) ?> tipo(s)</span>
                    </header>
                    <div class="table-responsive">
                        <?php if (empty($rows)): ?>
                            <div class="p-5 text-center text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                <p class="mb-0">Nenhum tipo de afastamento cadastrado ainda.</p>
                            </div>
                        <?php else: ?>
                            <table class="table table-hover align-middle table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Nome</th>
                                        <th scope="col">Codigo</th>
                                        <th scope="col">Cod. AEJ</th>
                                        <th scope="col" class="text-center">Remunerado</th>
                                        <th scope="col" class="text-center">Banco Horas</th>
                                        <th scope="col" class="text-center">Status</th>
                                        <th scope="col" class="text-center" style="width:70px;">Acoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r):
                                        $isEditing = ($row && (int)$row['id'] === (int)$r['id']);
                                    ?>
                                    <tr class="<?= $isEditing ? 'table-warning' : '' ?>">
                                        <td class="fw-semibold"><?= esc($r['name']) ?></td>
                                        <td><code class="small"><?= esc($r['code']) ?></code></td>
                                        <td>
                                            <?php if (($r['aej_code'] ?? '') !== ''): ?>
                                                <code class="small"><?= esc($r['aej_code']) ?></code>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark" title="Sem codigo AEJ — o afastamento sai do arquivo sem classificacao">pendente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int)$r['paid'] === 1): ?>
                                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                                    <i class="bi bi-check-lg me-1"></i>Sim
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                    <i class="bi bi-dash me-1"></i>Nao
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int)$r['affects_bank'] === 1): ?>
                                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                                                    <i class="bi bi-check-lg me-1"></i>Sim
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                    <i class="bi bi-dash me-1"></i>Nao
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int)$r['active'] === 1): ?>
                                                <span class="badge bg-success rounded-pill">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary rounded-pill">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="leave_types.php?id=<?= (int)$r['id'] ?>"
                                               class="btn btn-sm btn-outline-primary" title="Editar">
                                                <i class="bi bi-pencil"></i>
                                            </a>
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
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

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
$row = null;

try {
    $stmt = $pdo->query("SELECT * FROM employer_config LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $msg = 'Tabela employer_config não encontrada. Execute o script sql/install/install_portaria_671.sql no banco de dados.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $company_name = trim($_POST['company_name'] ?? '');
    $company_trade_name = trim($_POST['company_trade_name'] ?? '') ?: null;
    $cnpj = trim($_POST['cnpj'] ?? '');
    $address = trim($_POST['address'] ?? '') ?: null;
    $city = trim($_POST['city'] ?? '') ?: null;
    $state = strtoupper(substr(trim($_POST['state'] ?? ''), 0, 2)) ?: null;
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $email = trim($_POST['email'] ?? '') ?: null;

    // Campos exigidos pelo AFD/AEJ (Portaria MTP 671/2021).
    $employer_type    = (int)($_POST['employer_type'] ?? 1);
    $cpf_empregador   = trim($_POST['cpf'] ?? '') ?: null;
    $cei_caepf_cno    = trim($_POST['cei_caepf_cno'] ?? '') ?: null;
    $rep_identifier   = trim($_POST['rep_identifier'] ?? '') ?: null;
    $service_location = trim($_POST['service_location'] ?? '') ?: null;

    // O tipo do empregador decide QUAL documento identifica o empregador no
    // cabeçalho do AFD: pessoa jurídica usa CNPJ, pessoa física usa CPF. Validar
    // só o que se aplica evita exigir um documento que o empregador não tem.
    if ($company_name === '') {
        $msg = 'Razão social é obrigatória.';
    } elseif ($employer_type === 1 && $cnpj === '') {
        $msg = 'CNPJ é obrigatório para empregador pessoa jurídica.';
    } elseif ($employer_type === 1 && !validate_cnpj($cnpj)) {
        $msg = 'CNPJ inválido — confira os dígitos verificadores.';
    } elseif ($employer_type === 2 && ($cpf_empregador === null || !validate_cpf($cpf_empregador))) {
        $msg = 'CPF do empregador é obrigatório e deve ser válido para pessoa física.';
    } elseif ($id <= 0) {
        $msg = 'Registro do empregador não encontrado.';
    } else {
        $st = $pdo->prepare("
            UPDATE employer_config SET
                company_name = ?, company_trade_name = ?, cnpj = ?,
                address = ?, city = ?, state = ?, phone = ?, email = ?,
                employer_type = ?, cpf = ?, cei_caepf_cno = ?,
                rep_identifier = ?, service_location = ?
            WHERE id = ?
        ");
        $st->execute([
            $company_name, $company_trade_name, $cnpj,
            $address, $city, $state, $phone, $email,
            $employer_type, $cpf_empregador, $cei_caepf_cno,
            $rep_identifier, $service_location, $id
        ]);
        audit_log('update', 'employer_config', $id, ['company_name' => $company_name, 'cnpj' => $cnpj]);

        // Livro fiscal — registro tipo 2 do AFD (alteração de empregador).
        // O AFD precisa saber QUANDO a identificação do empregador mudou, para
        // que as marcações anteriores continuem interpretáveis sob os dados
        // vigentes à época.
        if (function_exists('nsr_ledger_record_cadastro')) {
            nsr_ledger_record_cadastro($pdo, 'employer_change', [
                'origin'   => 'admin_edit',
                'admin_id' => (int)($_SESSION['admin_id'] ?? 0) ?: null,
                'reason'   => 'alteracao de cadastro do empregador: ' . $company_name,
            ]);
        }

        header('Location: employer_config.php?msg=' . urlencode('Dados do empregador atualizados.'));
        exit;
    }
    // Repopula para exibir o formulário com erros
    $row = [
        'id' => $id,
        'company_name' => $company_name,
        'company_trade_name' => $company_trade_name,
        'cnpj' => $cnpj,
        'address' => $address,
        'city' => $city,
        'state' => $state,
        'phone' => $phone,
        'email' => $email,
        'employer_type' => $employer_type,
        'cpf' => $cpf_empregador,
        'cei_caepf_cno' => $cei_caepf_cno,
        'rep_identifier' => $rep_identifier,
        'service_location' => $service_location,
    ];
}

$row = $row ?: [];
$id = (int)($row['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Dados do Empregador | DEEDO Ponto</title>
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
                <div class="app-page-icon"><i class="bi bi-building"></i></div>
                <div>
                    <h1 class="app-page-title">Dados do Empregador</h1>
                    <p class="app-page-subtitle">Razão social, CNPJ e endereço usados em comprovantes e relatórios.</p>
                </div>
            </div>
        </div>

        <?php
        $msgErro = false;
        foreach (['obrigat', 'não encontrad', 'inválid'] as $t) {
            if (str_contains(mb_strtolower($msg), $t)) { $msgErro = true; break; }
        }
        ?>
        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgErro ? 'warning' : 'success' ?> alert-dismissible fade show d-flex align-items-center gap-2">
                <i class="bi bi-<?= $msgErro ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
                <div><?= esc($msg) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($id > 0): ?>
        <section class="app-section-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-pencil-square"></i>Edição</span>
                <h2 class="app-section-card__title">Configuração do empregador</h2>
            </header>
            <div class="app-section-card__body">
                <form action="" method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <h6 class="text-muted border-bottom pb-2 mb-3">Empresa</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Razão social <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="company_name" required maxlength="255" value="<?= esc($row['company_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Nome fantasia</label>
                            <input type="text" class="form-control" name="company_trade_name" maxlength="255" value="<?= esc($row['company_trade_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tipo de empregador <span class="text-danger">*</span></label>
                            <?php $tipoEmp = (int)($row['employer_type'] ?? 1); ?>
                            <select class="form-select" name="employer_type">
                                <option value="1"<?= $tipoEmp === 1 ? ' selected' : '' ?>>1 — Pessoa jurídica (CNPJ)</option>
                                <option value="2"<?= $tipoEmp === 2 ? ' selected' : '' ?>>2 — Pessoa física (CPF)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">CNPJ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="cnpj" maxlength="18" placeholder="00.000.000/0000-00" value="<?= esc($row['cnpj'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">CPF do empregador</label>
                            <input type="text" class="form-control" name="cpf" maxlength="14" placeholder="000.000.000-00" value="<?= esc($row['cpf'] ?? '') ?>">
                            <div class="form-text">Só para empregador pessoa física.</div>
                        </div>
                    </div>

                    <h6 class="text-muted border-bottom pb-2 mb-3">
                        Portaria MTP 671/2021
                        <span class="badge bg-warning text-dark ms-2">Exigido pelo AFD/AEJ</span>
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Identificador do REP</label>
                            <input type="text" class="form-control" name="rep_identifier" maxlength="17" value="<?= esc($row['rep_identifier'] ?? '') ?>">
                            <div class="form-text">Identificação do programa de registro (REP-P) no cabeçalho do AFD.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">CEI / CAEPF / CNO</label>
                            <input type="text" class="form-control" name="cei_caepf_cno" maxlength="14" value="<?= esc($row['cei_caepf_cno'] ?? '') ?>">
                            <div class="form-text">Deixe em branco se não houver.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Local da prestação de serviço</label>
                            <input type="text" class="form-control" name="service_location" maxlength="100" value="<?= esc($row['service_location'] ?? '') ?>">
                            <div class="form-text">Vai no registro tipo 2 do AFD e no comprovante de marcação (art. 80).</div>
                        </div>
                    </div>

                    <h6 class="text-muted border-bottom pb-2 mb-3">Endereço e contato</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Endereço</label>
                            <input type="text" class="form-control" name="address" maxlength="500" value="<?= esc($row['address'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Cidade</label>
                            <input type="text" class="form-control" name="city" maxlength="100" value="<?= esc($row['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">UF</label>
                            <input type="text" class="form-control" name="state" maxlength="2" placeholder="SP" value="<?= esc($row['state'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Telefone</label>
                            <input type="text" class="form-control" name="phone" maxlength="20" value="<?= esc($row['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">E-mail</label>
                            <input type="email" class="form-control" name="email" maxlength="255" value="<?= esc($row['email'] ?? '') ?>">
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
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

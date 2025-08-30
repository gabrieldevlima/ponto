<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('leaves.manage')) {
    http_response_code(403);
    exit('Sem permissão.');
}
$pdo = db();
$admin = current_admin($pdo);

$msg = $_GET['msg'] ?? '';

list($scopeSql, $scopeParams) = admin_scope_where('t');

$teachers = (function () use ($pdo, $scopeSql, $scopeParams) {
    $st = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE t.active=1 AND $scopeSql ORDER BY t.name");
    $st->execute($scopeParams);
    return $st->fetchAll(PDO::FETCH_ASSOC);
})();
$schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$types = $pdo->query("SELECT id, name FROM leave_types WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Create/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $school_id = $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    $type_id = (int)($_POST['type_id'] ?? 0);
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $approved = $_POST['approved'] === '' ? null : (int)$_POST['approved'];

    // Escopo
    list($scopeSql, $scopeParams) = admin_scope_where('t');
    $chk = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id=? AND $scopeSql");
    $chk->execute(array_merge([$teacher_id], $scopeParams));
    if (!$chk->fetchColumn()) {
        header('Location: leaves.php?msg=' . urlencode('Sem permissão para este colaborador.'));
        exit;
    }

    if ($teacher_id <= 0 || $type_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        header('Location: leaves.php?msg=' . urlencode('Dados inválidos.'));
        exit;
    }
    if ($id > 0) {
        $st = $pdo->prepare("UPDATE leaves SET teacher_id=?, school_id=?, type_id=?, start_date=?, end_date=?, notes=?, approved=?, created_by_admin_id=? WHERE id=?");
        $st->execute([$teacher_id, $school_id, $type_id, $start_date, $end_date, $notes, $approved, $_SESSION['admin_id'] ?? null, $id]);
        audit_log('update', 'leave', $id, ['teacher_id' => $teacher_id, 'type_id' => $type_id, 'start' => $start_date, 'end' => $end_date, 'approved' => $approved]);
        header('Location: leaves.php?msg=' . urlencode('Afastamento atualizado.'));
        exit;
    } else {
        $st = $pdo->prepare("INSERT INTO leaves (teacher_id, school_id, type_id, start_date, end_date, notes, approved, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([$teacher_id, $school_id, $type_id, $start_date, $end_date, $notes, $approved, $_SESSION['admin_id'] ?? null]);
        audit_log('create', 'leave', $pdo->lastInsertId(), ['teacher_id' => $teacher_id, 'type_id' => $type_id, 'start' => $start_date, 'end' => $end_date, 'approved' => $approved]);
        header('Location: leaves.php?msg=' . urlencode('Afastamento criado.'));
        exit;
    }
}

// Filtros
$f_t = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 0;
$f_ty = isset($_GET['type']) ? (int)$_GET['type'] : 0;
$f_ap = $_GET['approved'] ?? '';
$where = ["$scopeSql"];
$params = $scopeParams;
if ($f_t > 0) {
    $where[] = "l.teacher_id=?";
    $params[] = $f_t;
}
if ($f_ty > 0) {
    $where[] = "l.type_id=?";
    $params[] = $f_ty;
}
if ($f_ap !== '') {
    if ($f_ap === 'null') $where[] = "l.approved IS NULL";
    else {
        $where[] = "l.approved=?";
        $params[] = (int)$f_ap;
    }
}

$sql = "SELECT l.*, t.name as teacher_name, lt.name as type_name, s.name as school_name
        FROM leaves l
        JOIN teachers t ON t.id=l.teacher_id
        JOIN leave_types lt ON lt.id=l.type_id
        LEFT JOIN schools s ON s.id=l.school_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.start_date DESC, l.end_date DESC, l.id DESC
        LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <title>Afastamentos/Licenças/Abonos | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
                <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="adminNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
                    <li class="nav-item"><a class="nav-link" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
                    <li class="nav-item"><a class="nav-link" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
                    <li class="nav-item"><a class="nav-link active" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
                    <?php if (is_network_admin($admin)): ?>
                        <li class="nav-item"><a class="nav-link" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
                        <li class="nav-item"><a class="nav-link" href="admins.php"><i class="bi bi-people"></i> Administradores</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
                </ul>
                <span class="navbar-text me-3 d-none d-lg-inline">
                    <i class="bi bi-person-circle"></i>
                    <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
            </div>
        </div>
    </nav>
    <div class="container-fluid">
        <div class="mb-4">
            <div class="p-3 p-md-4 rounded-3 border bg-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width:3rem;height:3rem;">
                        <i class="bi bi-person-x fs-4"></i>
                    </span>
                    <div>
                        <h3 class="mb-1 fw-semibold">Afastamentos / Licenças / Abonos</h3>
                        <p class="text-muted mb-0">
                            Nesta tela você registra, edita e consulta afastamentos de colaboradores por tipo, período e status.
                            Use o formulário à esquerda para criar/editar e, à direita, os filtros para pesquisar os registros.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success d-flex align-items-center gap-2">
                <i class="bi bi-check-circle"></i>
                <div><?= esc($msg) ?></div>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span class="fw-semibold"><i class="bi bi-pencil-square me-2"></i>Novo/Editar Afastamento</span>
                        <span class="text-muted small">Preencha os campos obrigatórios</span>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3" autocomplete="off">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)($_GET['edit'] ?? 0) ?>">

                            <div class="col-12">
                                <label class="form-label">Colaborador</label>
                                <select class="form-select" name="teacher_id" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($teachers as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>" <?= (int)($_GET['teacher'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Escola (opcional)</label>
                                <select class="form-select" name="school_id">
                                    <option value="">(todas/rede)</option>
                                    <?php foreach ($schools as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Deixe em branco para toda a rede.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" name="type_id" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($types as $tp): ?>
                                        <option value="<?= (int)$tp['id'] ?>"><?= esc($tp['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="approved">
                                    <option value="">Pendente</option>
                                    <option value="1">Aprovado</option>
                                    <option value="0">Rejeitado</option>
                                </select>
                                <div class="form-text">Pode ser definido depois.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Início</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                                    <input type="date" class="form-control" name="start_date" id="start_date" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Fim</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar2-check"></i></span>
                                    <input type="date" class="form-control" name="end_date" id="end_date" required>
                                </div>
                                <div class="form-text">A data final deve ser igual ou após a inicial.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Observações</label>
                                <textarea class="form-control" name="notes" maxlength="255" rows="2" placeholder="Detalhes adicionais (máx. 255 caracteres)"></textarea>
                            </div>

                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Salvar</button>
                                <button type="reset" class="btn btn-outline-secondary"><i class="bi bi-eraser me-1"></i>Limpar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm mb-4">
                    <div class="card-header fw-semibold"><i class="bi bi-funnel me-2"></i>Filtros de Busca</div>
                    <div class="card-body">
                        <form class="row g-3 align-items-end" method="get">
                            <div class="col-md-4">
                                <label class="form-label">Colaborador</label>
                                <select class="form-select" name="teacher">
                                    <option value="">Todos</option>
                                    <?php foreach ($teachers as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>" <?= $f_t === (int)$t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" name="type">
                                    <option value="">Todos</option>
                                    <?php foreach ($types as $tp): ?>
                                        <option value="<?= (int)$tp['id'] ?>" <?= $f_ty === (int)$tp['id'] ? 'selected' : '' ?>><?= esc($tp['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="approved">
                                    <option value="">Todos</option>
                                    <option value="null" <?= $f_ap === 'null' ? 'selected' : '' ?>>Pendente</option>
                                    <option value="1" <?= $f_ap === '1' ? 'selected' : '' ?>>Aprovado</option>
                                    <option value="0" <?= $f_ap === '0' ? 'selected' : '' ?>>Rejeitado</option>
                                </select>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Filtrar</button>
                                <a class="btn btn-outline-secondary" href="leaves.php"><i class="bi bi-x-circle me-1"></i>Limpar</a>
                            </div>
                            <div class="col-12">
                                <div class="small text-muted">
                                    Legenda:
                                    <span class="badge bg-warning text-dark">Pendente</span>
                                    <span class="badge bg-success">Aprovado</span>
                                    <span class="badge bg-danger">Rejeitado</span>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><i class="bi bi-list-check me-2"></i>Afastamentos</span>
                        <span class="text-muted small"><?= count($rows) ?> resultado(s) • exibindo no máx. 500</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Colaborador</th>
                                        <th>Escola</th>
                                        <th>Tipo</th>
                                        <th>Início</th>
                                        <th>Fim</th>
                                        <th>Dias</th>
                                        <th>Status</th>
                                        <th>Observações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <?php
                                        $dias = '';
                                        try {
                                            $d1 = new DateTime($r['start_date']);
                                            $d2 = new DateTime($r['end_date']);
                                            $dias = max(1, $d1->diff($d2)->days + 1);
                                        } catch (Throwable $e) {
                                            $dias = '-';
                                        }
                                        ?>
                                        <tr>
                                            <td><?= esc($r['teacher_name']) ?></td>
                                            <td><?= esc($r['school_name'] ?? '-') ?></td>
                                            <td><?= esc($r['type_name']) ?></td>
                                            <td><?= esc($r['start_date']) ?></td>
                                            <td><?= esc($r['end_date']) ?></td>
                                            <td><?= esc((string)$dias) ?></td>
                                            <td>
                                                <?php if ($r['approved'] === null): ?>
                                                    <span class="badge bg-warning text-dark">Pendente</span>
                                                <?php elseif ((int)$r['approved'] === 1): ?>
                                                    <span class="badge bg-success">Aprovado</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Rejeitado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td title="<?= esc($r['notes']) ?>"><?= esc(mb_strimwidth((string)$r['notes'], 0, 60, '…')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($rows)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox me-2"></i>Nenhum afastamento encontrado.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <a class="btn btn-secondary" href="dashboard.php"><i class="bi bi-arrow-left-short me-1"></i>Voltar</a>
                            <a class="btn btn-outline-primary" href="leaves.php"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function() {
                const start = document.getElementById('start_date');
                const end = document.getElementById('end_date');
                if (!start || !end) return;
                start.addEventListener('change', () => {
                    if (start.value) {
                        end.min = start.value;
                        if (end.value && end.value < start.value) end.value = start.value;
                    } else {
                        end.removeAttribute('min');
                    }
                });
            })();
        </script>
    </div>
</body>

</html>
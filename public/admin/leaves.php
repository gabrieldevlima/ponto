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
    $description = trim($_POST['description'] ?? '');
    $cid_code = trim($_POST['cid_code'] ?? '');
    $approved = $_POST['approved'] === '' ? null : (int)$_POST['approved'];
    
    // Processa upload de atestado
    $attachment = null;
    $attachment_uploaded_at = null;
    $uploadError = null;

    // Upload de arquivo (se fornecido)
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../attachments/leaves/';
        $fileName = $_FILES['attachment']['name'];
        $fileSize = $_FILES['attachment']['size'];
        $fileTmp = $_FILES['attachment']['tmp_name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Validações
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($fileExt, $allowedExts)) {
            $uploadError = 'Formato não permitido. Envie PDF, JPG, PNG, DOC ou DOCX.';
        } elseif ($fileSize > $maxSize) {
            $uploadError = 'Arquivo muito grande. Máximo: 5MB.';
        } else {
            // Gera nome único
            $safeName = 'leave_' . $teacher_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
            $targetPath = $uploadDir . $safeName;
            
            if (move_uploaded_file($fileTmp, $targetPath)) {
                $attachment = $safeName;
                $attachment_uploaded_at = date('Y-m-d H:i:s');
            } else {
                $uploadError = 'Erro ao salvar arquivo. Verifique permissões do diretório.';
            }
        }
    }
    
    if ($uploadError) {
        header('Location: leaves.php?msg=' . urlencode($uploadError));
        exit;
    }

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
    
    // Valida datas: não pode marcar afastamento em datas futuras
    $today = date('Y-m-d');
    if ($start_date > $today) {
        header('Location: leaves.php?msg=' . urlencode('Não é possível criar afastamento com data inicial futura.'));
        exit;
    }
    if ($end_date < $start_date) {
        header('Location: leaves.php?msg=' . urlencode('Data final deve ser igual ou posterior à data inicial.'));
        exit;
    }
    
    // Calcula dias de afastamento
    $days_count = (new DateTime($start_date))->diff(new DateTime($end_date))->days + 1;
    
    if ($id > 0) {
        // UPDATE - só atualiza attachment se houver novo upload
        if ($attachment) {
            $st = $pdo->prepare("UPDATE leaves SET teacher_id=?, school_id=?, type_id=?, start_date=?, end_date=?, days_count=?, notes=?, description=?, cid_code=?, attachment=?, attachment_uploaded_at=?, approved=?, created_by_admin_id=? WHERE id=?");
            $st->execute([$teacher_id, $school_id, $type_id, $start_date, $end_date, $days_count, $notes, $description, $cid_code, $attachment, $attachment_uploaded_at, $approved, $_SESSION['admin_id'] ?? null, $id]);
        } else {
            $st = $pdo->prepare("UPDATE leaves SET teacher_id=?, school_id=?, type_id=?, start_date=?, end_date=?, days_count=?, notes=?, description=?, cid_code=?, approved=?, created_by_admin_id=? WHERE id=?");
            $st->execute([$teacher_id, $school_id, $type_id, $start_date, $end_date, $days_count, $notes, $description, $cid_code, $approved, $_SESSION['admin_id'] ?? null, $id]);
        }
        audit_log('update', 'leave', $id, ['teacher_id' => $teacher_id, 'type_id' => $type_id, 'start' => $start_date, 'end' => $end_date, 'approved' => $approved, 'attachment' => $attachment]);
        header('Location: leaves.php?msg=' . urlencode('Afastamento atualizado com sucesso!'));
        exit;
    } else {
        // INSERT
        $st = $pdo->prepare("INSERT INTO leaves (teacher_id, school_id, type_id, start_date, end_date, days_count, notes, description, cid_code, attachment, attachment_uploaded_at, approved, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([$teacher_id, $school_id, $type_id, $start_date, $end_date, $days_count, $notes, $description, $cid_code, $attachment, $attachment_uploaded_at, $approved, $_SESSION['admin_id'] ?? null]);
        $newId = $pdo->lastInsertId();
        audit_log('create', 'leave', $newId, ['teacher_id' => $teacher_id, 'type_id' => $type_id, 'start' => $start_date, 'end' => $end_date, 'approved' => $approved, 'attachment' => $attachment]);
        header('Location: leaves.php?msg=' . urlencode('Afastamento criado com sucesso!'));
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
    <?php include __DIR__ . '/_navbar.php'; ?>
    <div class="container-fluid">
        <div class="mb-4">
            <div class="p-3 p-md-4 rounded-3 border">
                <div class="d-flex align-items-start gap-3">
                    <span class="bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width:3rem;height:3rem;">
                        <i class="bi bi-person-x fs-4"></i>
                    </span>
                    <div class="flex-grow-1">
                        <h3 class="mb-1 fw-semibold">Afastamentos / Licenças / Abonos</h3>
                        <p class="text-muted mb-2">
                            Cadastre e gerencie afastamentos por colaborador, tipo, período e status. Use o formulário à esquerda para criar/editar e, à direita, os filtros para pesquisar.
                        </p>
                        <div class="small text-muted">
                            Dica: defina as datas e o status depois, se preferir. Campos obrigatórios marcados com <span class="text-danger">*</span>.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i>
                <div><?= esc($msg) ?></div>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span class="fw-semibold"><i class="bi bi-pencil-square me-2"></i>Novo/Editar Afastamento</span>
                        <span class="text-muted small">Preencha os campos obrigatórios</span>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3 needs-validation" autocomplete="off" novalidate enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)($_GET['edit'] ?? 0) ?>">

                            <div class="col-12">
                                <label class="form-label">Colaborador <span class="text-danger">*</span></label>
                                <select class="form-select" name="teacher_id" required aria-label="Selecionar colaborador">
                                    <option value="" disabled selected>Selecione um colaborador</option>
                                    <?php foreach ($teachers as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>" <?= (int)($_GET['teacher'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Selecione um colaborador.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Escola (opcional)</label>
                                <select class="form-select" name="school_id" aria-label="Selecionar escola">
                                    <option value="">(todas/rede)</option>
                                    <?php foreach ($schools as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Deixe em branco para toda a rede.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Tipo <span class="text-danger">*</span></label>
                                <select class="form-select" name="type_id" required aria-label="Selecionar tipo de afastamento">
                                    <option value="" disabled selected>Selecione o tipo</option>
                                    <?php foreach ($types as $tp): ?>
                                        <option value="<?= (int)$tp['id'] ?>"><?= esc($tp['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Selecione um tipo.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="approved" aria-label="Selecionar status">
                                    <option value="">Pendente</option>
                                    <option value="1">Aprovado</option>
                                    <option value="0">Rejeitado</option>
                                </select>
                                <div class="form-text">Pode ser definido depois.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Início <span class="text-danger">*</span></label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                                    <input type="date" class="form-control" name="start_date" id="start_date" required aria-label="Data inicial">
                                    <div class="invalid-feedback">Informe a data inicial.</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Fim <span class="text-danger">*</span></label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text"><i class="bi bi-calendar2-check"></i></span>
                                    <input type="date" class="form-control" name="end_date" id="end_date" required aria-label="Data final">
                                    <div class="invalid-feedback" id="end_date_feedback">Informe uma data final válida (igual ou após a inicial).</div>
                                </div>
                                <div class="form-text">A data final deve ser igual ou após a inicial.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Descrição Detalhada</label>
                                <textarea class="form-control" name="description" id="description" rows="3" placeholder="Descrição completa do motivo do afastamento..." aria-label="Descrição"></textarea>
                                <div class="form-text">Informações detalhadas sobre o afastamento.</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">CID-10 (opcional)</label>
                                <input type="text" class="form-control" name="cid_code" id="cid_code" maxlength="10" placeholder="Ex: J00, M54.5" aria-label="Código CID-10">
                                <div class="form-text">Código CID-10 para afastamentos médicos.</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Atestado/Documento <i class="bi bi-paperclip ms-1"></i></label>
                                <input type="file" class="form-control" name="attachment" id="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" aria-label="Anexar atestado">
                                <div class="form-text">PDF, JPG, PNG, DOC ou DOCX • Máx. 5MB</div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Observações Internas</label>
                                <textarea class="form-control" name="notes" id="notes" maxlength="255" rows="2" placeholder="Notas administrativas (máx. 255 caracteres)" aria-label="Observações"></textarea>
                                <div class="d-flex justify-content-end">
                                    <small class="text-muted" id="notes_counter">0/255</small>
                                </div>
                            </div>

                            <div class="col-12">
                                <div id="edit_mode_indicator" class="alert alert-info d-none mb-3" role="alert">
                                    <i class="bi bi-info-circle me-2"></i><strong>Modo Edição:</strong> Editando afastamento #<span id="edit_id_display"></span>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-success" data-bs-toggle="tooltip" data-bs-title="Salvar afastamento"><i class="bi bi-check2-circle me-1"></i>Salvar</button>
                                    <button type="reset" class="btn btn-outline-secondary" id="btn_reset" data-bs-toggle="tooltip" data-bs-title="Limpar formulário"><i class="bi bi-eraser me-1"></i>Limpar</button>
                                    <button type="button" class="btn btn-outline-danger d-none" id="btn_cancel_edit" data-bs-toggle="tooltip" data-bs-title="Cancelar edição"><i class="bi bi-x-circle me-1"></i>Cancelar Edição</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm mb-4">
                    <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-funnel me-2"></i>Filtros de Busca</span>
                        <span class="small text-muted">Refine os resultados</span>
                    </div>
                    <div class="card-body">
                        <form class="row g-3 align-items-end" method="get">
                            <div class="col-md-4">
                                <label class="form-label">Colaborador</label>
                                <select class="form-select" name="teacher" aria-label="Filtrar por colaborador">
                                    <option value="">Todos</option>
                                    <?php foreach ($teachers as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>" <?= $f_t === (int)$t['id'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" name="type" aria-label="Filtrar por tipo">
                                    <option value="">Todos</option>
                                    <?php foreach ($types as $tp): ?>
                                        <option value="<?= (int)$tp['id'] ?>" <?= $f_ty === (int)$tp['id'] ? 'selected' : '' ?>><?= esc($tp['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="approved" aria-label="Filtrar por status">
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
                                <div class="small text-muted d-flex align-items-center gap-2">
                                    Legenda:
                                    <span class="badge bg-warning text-dark" title="Pendente"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                                    <span class="badge bg-success" title="Aprovado"><i class="bi bi-check2 me-1"></i>Aprovado</span>
                                    <span class="badge bg-danger" title="Rejeitado"><i class="bi bi-x-lg me-1"></i>Rejeitado</span>
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
                                        <th>Tipo</th>
                                        <th>Início</th>
                                        <th>Fim</th>
                                        <th>Dias</th>
                                        <th>Status</th>
                                        <th>Atestado</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <?php
                                        $dias = '';
                                        $start_fmt = '';
                                        $end_fmt = '';
                                        try {
                                            $d1 = new DateTime($r['start_date']);
                                            $d2 = new DateTime($r['end_date']);
                                            $dias = max(1, $d1->diff($d2)->days + 1);
                                            $start_fmt = $d1->format('d/m/Y');
                                            $end_fmt = $d2->format('d/m/Y');
                                        } catch (Throwable $e) {
                                            $dias = '-';
                                            $start_fmt = esc($r['start_date']);
                                            $end_fmt = esc($r['end_date']);
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <div><?= esc($r['teacher_name']) ?></div>
                                                <?php if (!empty($r['cid_code'])): ?>
                                                    <div class="small text-muted">CID: <?= esc($r['cid_code']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= esc($r['type_name']) ?></td>
                                            <td><?= esc($start_fmt) ?></td>
                                            <td><?= esc($end_fmt) ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info"><?= esc((string)$dias) ?> dia<?= $dias != 1 ? 's' : '' ?></span>
                                            </td>
                                            <td>
                                                <?php if ($r['approved'] === null): ?>
                                                    <span class="badge bg-warning text-dark" title="Pendente"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                                                <?php elseif ((int)$r['approved'] === 1): ?>
                                                    <span class="badge bg-success" title="Aprovado"><i class="bi bi-check2 me-1"></i>Aprovado</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger" title="Rejeitado"><i class="bi bi-x-lg me-1"></i>Rejeitado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!empty($r['attachment'])): ?>
                                                    <a href="view_leave_attachment.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver atestado" target="_blank">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="editLeave(<?= (int)$r['id'] ?>)" title="Editar afastamento">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="viewDetails(<?= (int)$r['id'] ?>)" title="Ver detalhes">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </div>
                                            </td>
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

        <!-- Modal de Detalhes do Afastamento -->
        <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Detalhes do Afastamento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body" id="detailsContent">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Carregando...</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const start = document.getElementById('start_date');
            const end = document.getElementById('end_date');
            const notes = document.getElementById('notes');
            const counter = document.getElementById('notes_counter');
            const btnReset = document.getElementById('btn_reset');
            const detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));

                // Tooltips
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

                // Notes counter
                if (notes && counter) {
                    const updateCount = () => counter.textContent = `${notes.value.length}/255`;
                    notes.addEventListener('input', updateCount);
                    updateCount();
                }

                // Date constraints
                if (start && end) {
                    const syncDates = () => {
                        if (start.value) {
                            end.min = start.value;
                            if (end.value && end.value < start.value) end.value = start.value;
                        } else {
                            end.removeAttribute('min');
                        }
                    };
                    start.addEventListener('change', syncDates);
                    end.addEventListener('change', syncDates);
                    syncDates();
                }

                // Confirm reset
                if (btnReset) {
                    btnReset.addEventListener('click', (e) => {
                        if (!confirm('Deseja realmente limpar o formulário?')) {
                            e.preventDefault();
                        } else {
                            // Limpar modo edição ao resetar
                            document.querySelector('input[name="id"]').value = '';
                            document.getElementById('edit_mode_indicator').classList.add('d-none');
                            const btnCancelEdit = document.getElementById('btn_cancel_edit');
                            if (btnCancelEdit) {
                                btnCancelEdit.classList.add('d-none');
                            }
                        }
                    });
                }

                // Bootstrap validation + custom end-date rule
                const form = document.querySelector('form.needs-validation');
                if (form) {
                    form.addEventListener('submit', function(event) {
                        let valid = form.checkValidity();
                        if (start && end && start.value && end.value && end.value < start.value) {
                            end.setCustomValidity('A data final deve ser igual ou após a inicial.');
                            valid = false;
                        } else if (end) {
                            end.setCustomValidity('');
                        }
                        if (!valid) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                }

                // Edit leave function
                window.editLeave = async function(leaveId) {
                    try {
                        const response = await fetch(`get_leave_details.php?id=${leaveId}`);
                        const data = await response.json();
                        
                        if (data.error) {
                            alert('Erro ao carregar dados: ' + data.error);
                            return;
                        }

                        // Preencher campos do formulário
                        document.querySelector('input[name="id"]').value = data.id || '';
                        document.querySelector('select[name="teacher_id"]').value = data.teacher_id || '';
                        document.querySelector('select[name="school_id"]').value = data.school_id || '';
                        document.querySelector('select[name="type_id"]').value = data.type_id || '';
                        document.querySelector('select[name="approved"]').value = data.approved === null ? '' : (data.approved ? '1' : '0');
                        document.getElementById('start_date').value = data.start_date || '';
                        document.getElementById('end_date').value = data.end_date || '';
                        document.getElementById('description').value = data.description || '';
                        document.getElementById('cid_code').value = data.cid_code || '';
                        document.getElementById('notes').value = data.notes || '';
                        
                        // Atualizar contador de notas
                        if (notes && counter) {
                            counter.textContent = `${notes.value.length}/255`;
                        }
                        
                        // Sincronizar datas
                        if (start && end) {
                            if (start.value) {
                                end.min = start.value;
                                if (end.value && end.value < start.value) end.value = start.value;
                            }
                        }

                        // Mostrar indicador de modo edição
                        const editIndicator = document.getElementById('edit_mode_indicator');
                        const editIdDisplay = document.getElementById('edit_id_display');
                        const btnCancelEdit = document.getElementById('btn_cancel_edit');
                        if (editIndicator && editIdDisplay) {
                            editIdDisplay.textContent = leaveId;
                            editIndicator.classList.remove('d-none');
                        }
                        if (btnCancelEdit) {
                            btnCancelEdit.classList.remove('d-none');
                        }

                        // Remover validação anterior
                        const form = document.querySelector('form.needs-validation');
                        if (form) {
                            form.classList.remove('was-validated');
                        }

                        // Rolar até o formulário
                        document.querySelector('.col-lg-5').scrollIntoView({ behavior: 'smooth', block: 'start' });
                        
                        // Focar no primeiro campo
                        setTimeout(() => {
                            document.querySelector('select[name="teacher_id"]').focus();
                        }, 300);
                    } catch (error) {
                        alert('Erro ao carregar dados do afastamento: ' + error.message);
                    }
                };

                // Cancelar edição
                const btnCancelEdit = document.getElementById('btn_cancel_edit');
                if (btnCancelEdit) {
                    btnCancelEdit.addEventListener('click', function() {
                        if (confirm('Deseja cancelar a edição e limpar o formulário?')) {
                            document.querySelector('form').reset();
                            document.querySelector('input[name="id"]').value = '';
                            document.getElementById('edit_mode_indicator').classList.add('d-none');
                            btnCancelEdit.classList.add('d-none');
                            if (notes && counter) {
                                counter.textContent = '0/255';
                            }
                            if (start && end) {
                                end.removeAttribute('min');
                            }
                        }
                    });
                }

                // Carregar edição se houver parâmetro ?edit=ID na URL
                const urlParams = new URLSearchParams(window.location.search);
                const editId = urlParams.get('edit');
                if (editId && /^\d+$/.test(editId)) {
                    editLeave(parseInt(editId));
                }

                // View details function
                window.viewDetails = async function(leaveId) {
                    const detailsContent = document.getElementById('detailsContent');
                    detailsContent.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Carregando...</span></div></div>';
                    detailsModal.show();

                    try {
                        const response = await fetch(`get_leave_details.php?id=${leaveId}`);
                        const data = await response.json();
                        
                        if (data.error) {
                            detailsContent.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                            return;
                        }

                        detailsContent.innerHTML = `
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <strong>Colaborador:</strong><br>${data.teacher_name || '-'}
                                </div>
                                <div class="col-md-6">
                                    <strong>Tipo:</strong><br>${data.type_name || '-'}
                                </div>
                                <div class="col-md-4">
                                    <strong>Data Início:</strong><br>${data.start_date_fmt || '-'}
                                </div>
                                <div class="col-md-4">
                                    <strong>Data Fim:</strong><br>${data.end_date_fmt || '-'}
                                </div>
                                <div class="col-md-4">
                                    <strong>Dias:</strong><br><span class="badge bg-info">${data.days_count || '0'} dias</span>
                                </div>
                                ${data.cid_code ? `<div class="col-md-6"><strong>CID-10:</strong><br>${data.cid_code}</div>` : ''}
                                <div class="col-md-${data.cid_code ? '6' : '12'}">
                                    <strong>Status:</strong><br>${data.approved_badge || '-'}
                                </div>
                                ${data.description ? `<div class="col-12"><strong>Descrição:</strong><br><p class="mb-0">${data.description}</p></div>` : ''}
                                ${data.notes ? `<div class="col-12"><strong>Observações:</strong><br><p class="mb-0 small text-muted">${data.notes}</p></div>` : ''}
                                ${data.attachment ? `<div class="col-12"><strong>Anexo:</strong><br><a href="view_leave_attachment.php?id=${leaveId}" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-file-earmark-text me-1"></i>Ver Atestado</a></div>` : ''}
                            </div>
                        `;
                    } catch (error) {
                        detailsContent.innerHTML = `<div class="alert alert-danger">Erro ao carregar detalhes: ${error.message}</div>`;
                    }
                };
        });
    </script>
</body>
</html>
<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('leaves.manage')) {
    http_response_code(403);
    flash_redirect('error', 'Sem permissão para acessar Afastamentos.', 'dashboard.php');
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
$types = $pdo->query("SELECT id, name, paid FROM leave_types WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $school_id = ($_POST['school_id'] ?? '') !== '' ? (int)$_POST['school_id'] : null;
    $type_id = (int)($_POST['type_id'] ?? 0);
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $cid_code = trim($_POST['cid_code'] ?? '');
    $approved = ($_POST['approved'] ?? '') === '' ? null : (int)$_POST['approved'];
    $excuses_absence = isset($_POST['excuses_absence']) ? (int)$_POST['excuses_absence'] : 1;
    if ($excuses_absence !== 0 && $excuses_absence !== 1) { $excuses_absence = 1; }

    $attachment = null;
    $attachment_uploaded_at = null;
    $uploadError = null;

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        // Diretório fora do webroot para evitar acesso HTTP direto
        $uploadDir = dirname(__DIR__, 2) . '/storage/leaves/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0750, true);
        }

        $fileExt = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

        // Validação de MIME type real (magic bytes), não apenas extensão
        $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', // docx pode ser detectado como zip
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($_FILES['attachment']['tmp_name']);

        if (!in_array($fileExt, $allowedExts, true)) {
            $uploadError = 'Formato nao permitido.';
        } elseif (!in_array($realMime, $allowedMimes, true)) {
            $uploadError = 'Tipo de arquivo invalido.';
        } elseif ($_FILES['attachment']['size'] > 5 * 1024 * 1024) {
            $uploadError = 'Arquivo muito grande. Maximo: 5MB.';
        } else {
            $safeName = 'leave_' . $teacher_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $safeName)) {
                $attachment = $safeName;
                $attachment_uploaded_at = date('Y-m-d H:i:s');
            } else {
                $uploadError = 'Erro ao salvar arquivo.';
            }
        }
    }
    if ($uploadError) { header('Location: leaves.php?msg=' . urlencode($uploadError)); exit; }

    list($scopeSql2, $scopeParams2) = admin_scope_where('t');
    $chk = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id=? AND $scopeSql2");
    $chk->execute(array_merge([$teacher_id], $scopeParams2));
    if (!$chk->fetchColumn()) { header('Location: leaves.php?msg=' . urlencode('Sem permissao para este colaborador.')); exit; }

    if ($teacher_id <= 0 || $type_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        header('Location: leaves.php?msg=' . urlencode('Dados invalidos.')); exit;
    }
    if ($start_date > date('Y-m-d')) { header('Location: leaves.php?msg=' . urlencode('Data inicial nao pode ser futura.')); exit; }
    if ($end_date < $start_date) { header('Location: leaves.php?msg=' . urlencode('Data final deve ser igual ou posterior a inicial.')); exit; }

    $days_count = (new DateTime($start_date))->diff(new DateTime($end_date))->days + 1;

    if ($id > 0) {
        if ($attachment) {
            $st = $pdo->prepare("UPDATE leaves SET teacher_id=?,school_id=?,type_id=?,start_date=?,end_date=?,days_count=?,notes=?,description=?,cid_code=?,attachment=?,attachment_uploaded_at=?,approved=?,excuses_absence=?,created_by_admin_id=? WHERE id=?");
            $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$attachment,$attachment_uploaded_at,$approved,$excuses_absence,$_SESSION['admin_id']??null,$id]);
        } else {
            $st = $pdo->prepare("UPDATE leaves SET teacher_id=?,school_id=?,type_id=?,start_date=?,end_date=?,days_count=?,notes=?,description=?,cid_code=?,approved=?,excuses_absence=?,created_by_admin_id=? WHERE id=?");
            $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$approved,$excuses_absence,$_SESSION['admin_id']??null,$id]);
        }
        audit_log('update','leave',$id,compact('teacher_id','type_id','start_date','end_date','approved','excuses_absence'));
        header('Location: leaves.php?msg=' . urlencode('Afastamento atualizado!')); exit;
    } else {
        $st = $pdo->prepare("INSERT INTO leaves (teacher_id,school_id,type_id,start_date,end_date,days_count,notes,description,cid_code,attachment,attachment_uploaded_at,approved,excuses_absence,created_by_admin_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$teacher_id,$school_id,$type_id,$start_date,$end_date,$days_count,$notes,$description,$cid_code,$attachment,$attachment_uploaded_at,$approved,$excuses_absence,$_SESSION['admin_id']??null]);
        audit_log('create','leave',(int)$pdo->lastInsertId(),compact('teacher_id','type_id','start_date','end_date','approved','excuses_absence'));
        header('Location: leaves.php?msg=' . urlencode('Afastamento criado!')); exit;
    }
}

$f_t  = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 0;
$f_ty = isset($_GET['type'])    ? (int)$_GET['type']    : 0;
$f_ap = $_GET['approved'] ?? '';
$where  = [$scopeSql];
$params = $scopeParams;
if ($f_t  > 0) { $where[] = 'l.teacher_id=?'; $params[] = $f_t; }
if ($f_ty > 0) { $where[] = 'l.type_id=?';    $params[] = $f_ty; }
if ($f_ap !== '') {
    if ($f_ap === 'null') $where[] = 'l.approved IS NULL';
    else { $where[] = 'l.approved=?'; $params[] = (int)$f_ap; }
}

$perPage = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 25;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stCount = $pdo->prepare("SELECT COUNT(*) FROM leaves l JOIN teachers t ON t.id=l.teacher_id JOIN leave_types lt ON lt.id=l.type_id LEFT JOIN schools s ON s.id=l.school_id WHERE " . implode(' AND ', $where));
$stCount->execute($params);
$totalLeaves = (int)$stCount->fetchColumn();
$totalPages  = max(1, (int)ceil($totalLeaves / $perPage));
$page   = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT l.*, t.name as teacher_name, lt.name as type_name, s.name as school_name FROM leaves l JOIN teachers t ON t.id=l.teacher_id JOIN leave_types lt ON lt.id=l.type_id LEFT JOIN schools s ON s.id=l.school_id WHERE " . implode(' AND ', $where) . " ORDER BY l.start_date DESC, l.end_date DESC, l.id DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$leavesBaseUrl = 'leaves.php?' . http_build_query(array_diff_key($_GET, ['page' => 1]));
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Afastamentos | DEEDO Ponto</title>
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

        <!-- Header (padrão .app-page-header) -->
        <div class="app-page-header">
            <div class="app-page-header__main">
                <div class="app-page-icon"><i class="bi bi-person-x"></i></div>
                <div>
                    <h1 class="app-page-title">Afastamentos / Licenças / Abonos</h1>
                    <p class="app-page-subtitle">Cadastre e gerencie afastamentos por colaborador, tipo e período.</p>
                </div>
            </div>
            <button class="btn btn-success" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasLeave" onclick="resetForm()" aria-label="Cadastrar novo afastamento">
                <i class="bi bi-plus-circle me-1"></i> Novo Afastamento
            </button>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i>
                <div><?= esc($msg) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filters (padrão .app-section-card) -->
        <section class="app-section-card">
            <header class="app-section-card__header" role="button" data-bs-toggle="collapse" data-bs-target="#filtersBody" aria-expanded="true" style="cursor:pointer;">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-funnel"></i>Filtros</span>
                <h2 class="app-section-card__title">Buscar afastamentos</h2>
                <span class="app-section-card__hint">
                    <i class="bi bi-chevron-down small text-muted"></i>
                </span>
            </header>
            <div class="collapse show" id="filtersBody">
                <div class="app-section-card__body">
                    <form class="row g-3 align-items-end" method="get">
                        <div class="col-md-3">
                            <label class="form-label">Colaborador</label>
                            <select class="form-select" name="teacher">
                                <option value="">Todos</option>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" <?= $f_t === (int)$t['id'] ? 'selected' : '' ?>><?= esc(mb_convert_case($t['name'], MB_CASE_TITLE, 'UTF-8')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tipo</label>
                            <select class="form-select" name="type">
                                <option value="">Todos</option>
                                <?php foreach ($types as $tp): ?>
                                    <option value="<?= (int)$tp['id'] ?>" <?= $f_ty === (int)$tp['id'] ? 'selected' : '' ?>><?= esc($tp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="approved">
                                <option value="">Todos</option>
                                <option value="null" <?= $f_ap === 'null' ? 'selected' : '' ?>>Pendente</option>
                                <option value="1"    <?= $f_ap === '1'    ? 'selected' : '' ?>>Aprovado</option>
                                <option value="0"    <?= $f_ap === '0'    ? 'selected' : '' ?>>Rejeitado</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Por pagina</label>
                            <select class="form-select" name="per_page">
                                <?php foreach ([25, 50, 100] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button class="btn btn-primary flex-fill"><i class="bi bi-search me-1"></i>Filtrar</button>
                            <a class="btn btn-outline-secondary" href="leaves.php" title="Limpar filtros"><i class="bi bi-x-circle"></i></a>
                        </div>
                    </form>
                    <div class="mt-3 d-flex align-items-center gap-2 small text-muted">
                        Legenda:
                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                        <span class="badge bg-success"><i class="bi bi-check2 me-1"></i>Aprovado</span>
                        <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Rejeitado</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Table (padrão .app-section-card.app-table-card) -->
        <section class="app-section-card app-table-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-list-check"></i>Lista</span>
                <h2 class="app-section-card__title">Afastamentos</h2>
                <span class="app-section-card__hint"><?= number_format($totalLeaves) ?> resultado(s)</span>
            </header>
            <div class="admin-table-wrap table-responsive">
                <table class="table table-bordered table-hover align-middle table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Colaborador</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Inicio</th>
                            <th scope="col">Fim</th>
                            <th scope="col" class="text-center">Dias</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-center" style="width:60px;">Doc.</th>
                            <th scope="col" class="text-center" style="width:90px;">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            try {
                                $d1 = new DateTime($r['start_date']);
                                $d2 = new DateTime($r['end_date']);
                                $dias = max(1, $d1->diff($d2)->days + 1);
                                $start_fmt = $d1->format('d/m/Y');
                                $end_fmt   = $d2->format('d/m/Y');
                            } catch (Throwable $e) {
                                $dias = '-'; $start_fmt = esc($r['start_date']); $end_fmt = esc($r['end_date']);
                            }
                            $rid = (int)$r['id'];
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= esc(mb_convert_case($r['teacher_name'], MB_CASE_TITLE, 'UTF-8')) ?></div>
                                    <?php if (!empty($r['cid_code'])): ?>
                                        <div class="small text-muted">CID: <?= esc($r['cid_code']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= esc($r['type_name']) ?></td>
                                <td class="small text-nowrap"><?= esc($start_fmt) ?></td>
                                <td class="small text-nowrap"><?= esc($end_fmt) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?= esc((string)$dias) ?> dia<?= $dias != 1 ? 's' : '' ?></span>
                                </td>
                                <td>
                                    <?php if ($r['approved'] === null): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                                    <?php elseif ((int)$r['approved'] === 1): ?>
                                        <span class="badge bg-success"><i class="bi bi-check2 me-1"></i>Aprovado</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Rejeitado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($r['attachment'])): ?>
                                        <a href="view_leave_attachment.php?id=<?= $rid ?>" class="btn btn-sm btn-outline-primary" title="Ver atestado" target="_blank">
                                            <i class="bi bi-file-earmark-text"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-label="Ações">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <li>
                                                <button class="dropdown-item" onclick="editLeave(<?= $rid ?>)">
                                                    <i class="bi bi-pencil me-2 text-primary"></i>Editar
                                                </button>
                                            </li>
                                            <li>
                                                <button class="dropdown-item" onclick="viewDetails(<?= $rid ?>)">
                                                    <i class="bi bi-eye me-2 text-secondary"></i>Ver detalhes
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>Nenhum afastamento encontrado.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                <?php
                $currentPage = $page;
                $baseUrl     = $leavesBaseUrl;
                $totalItems  = $totalLeaves;
                include __DIR__ . '/_pagination.php';
                ?>
                <div class="app-table-card__footer">
                    <a class="btn btn-outline-primary btn-sm" href="leaves.php" aria-label="Atualizar lista">
                        <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
                    </a>
                </div>
        </section>
    </div>

    <!-- Offcanvas: Criar / Editar Afastamento -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasLeave" style="width:min(560px,100vw)">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title"><i class="bi bi-pencil-square me-2"></i><span id="ocLeaveTitle">Novo Afastamento</span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
        </div>
        <div class="offcanvas-body">
            <div id="edit_mode_banner" class="alert alert-info d-none mb-3 py-2 small">
                <i class="bi bi-info-circle me-1"></i><strong>Modo Edicao — afastamento #</strong><span id="edit_id_display"></span>
            </div>
            <form action="" method="post" class="row g-3 needs-validation" autocomplete="off" novalidate enctype="multipart/form-data" data-no-submit-lock>
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="id" id="ocLeaveId" value="0">

                <div class="col-12">
                    <label class="form-label fw-semibold">Colaborador <span class="text-danger">*</span></label>
                    <select class="form-select" name="teacher_id" id="ocTeacherId" required>
                        <option value="" disabled selected>Selecione um colaborador</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= esc(mb_convert_case($t['name'], MB_CASE_TITLE, 'UTF-8')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Selecione um colaborador.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Escola (opcional)</label>
                    <select class="form-select" name="school_id" id="ocSchoolId">
                        <option value="">(todas/rede)</option>
                        <?php foreach ($schools as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= esc(mb_convert_case($s['name'], MB_CASE_TITLE, 'UTF-8')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Tipo <span class="text-danger">*</span></label>
                    <select class="form-select" name="type_id" id="ocTypeId" required>
                        <option value="" disabled selected>Selecione o tipo</option>
                        <?php foreach ($types as $tp): ?>
                            <option value="<?= (int)$tp['id'] ?>" data-paid="<?= (int)$tp['paid'] ?>"><?= esc($tp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Selecione um tipo.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Status</label>
                    <select class="form-select" name="approved" id="ocApproved">
                        <option value="">Pendente</option>
                        <option value="1">Aprovado</option>
                        <option value="0">Rejeitado</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">CID-10 (opcional)</label>
                    <input type="text" class="form-control" name="cid_code" id="ocCidCode" maxlength="10" placeholder="Ex: J00">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Inicio <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                        <input type="date" class="form-control" name="start_date" id="ocStartDate" required>
                        <div class="invalid-feedback">Informe a data inicial.</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Fim <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar2-check"></i></span>
                        <input type="date" class="form-control" name="end_date" id="ocEndDate" required>
                        <div class="invalid-feedback">Informe a data final valida.</div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold d-block">Este afastamento abona a falta? <span class="text-danger">*</span></label>
                    <div class="btn-group" role="group" aria-label="Abona a falta">
                        <input type="radio" class="btn-check" name="excuses_absence" id="ocExcusesYes" value="1" checked>
                        <label class="btn btn-outline-success" for="ocExcusesYes"><i class="bi bi-check2-circle me-1"></i>Sim, abona</label>
                        <input type="radio" class="btn-check" name="excuses_absence" id="ocExcusesNo" value="0">
                        <label class="btn btn-outline-danger" for="ocExcusesNo"><i class="bi bi-x-circle me-1"></i>Não abona</label>
                    </div>
                    <div class="form-text">
                        <strong>Sim</strong> = justificativa válida, desconsidera a falta.
                        <strong>Não</strong> = registra o motivo, mas o dia continua contando como falta (com desconto).
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Descricao Detalhada</label>
                    <textarea class="form-control" name="description" id="ocDescription" rows="3" placeholder="Descricao completa do motivo..."></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Observacoes Internas</label>
                    <textarea class="form-control" name="notes" id="ocNotes" maxlength="255" rows="2" placeholder="Notas administrativas (max. 255 caracteres)"></textarea>
                    <div class="d-flex justify-content-end"><small class="text-muted" id="ocNotesCounter">0/255</small></div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Atestado / Documento <i class="bi bi-paperclip ms-1 text-muted"></i></label>
                    <input type="file" class="form-control" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    <div class="form-text">PDF, JPG, PNG, DOC ou DOCX — max. 5MB</div>
                </div>

                <div class="col-12 d-flex gap-2 mt-2">
                    <button class="btn btn-success flex-fill"><i class="bi bi-check2-circle me-1"></i>Salvar</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Detalhes -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-fullscreen-md-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Detalhes do Afastamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
                    <div class="text-center py-4"><div class="spinner-border text-primary"><span class="visually-hidden">Carregando...</span></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
        const offcanvasEl  = document.getElementById('offcanvasLeave');
        const oc           = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
        const ocId          = document.getElementById('ocLeaveId');
        const ocTitle       = document.getElementById('ocLeaveTitle');
        const ocBanner      = document.getElementById('edit_mode_banner');
        const ocBannerId    = document.getElementById('edit_id_display');
        const ocTeacherId   = document.getElementById('ocTeacherId');
        const ocSchoolId    = document.getElementById('ocSchoolId');
        const ocTypeId      = document.getElementById('ocTypeId');
        const ocApproved    = document.getElementById('ocApproved');
        const ocStartDate   = document.getElementById('ocStartDate');
        const ocEndDate     = document.getElementById('ocEndDate');
        const ocDescription = document.getElementById('ocDescription');
        const ocCidCode     = document.getElementById('ocCidCode');
        const ocNotes       = document.getElementById('ocNotes');
        const ocCounter     = document.getElementById('ocNotesCounter');
        const ocForm        = offcanvasEl.querySelector('form');
        const ocExcusesYes  = document.getElementById('ocExcusesYes');
        const ocExcusesNo   = document.getElementById('ocExcusesNo');
        function setExcuses(val) {
            if (parseInt(val, 10) === 0) { ocExcusesNo.checked = true; }
            else { ocExcusesYes.checked = true; }
        }
        // Em CRIACAO, ao trocar o tipo, sugere o default conforme 'paid' do tipo.
        ocTypeId.addEventListener('change', () => {
            if (ocId.value && ocId.value !== '0') return; // nao sobrescreve em edicao
            const opt = ocTypeId.options[ocTypeId.selectedIndex];
            const paid = opt ? parseInt(opt.getAttribute('data-paid') || '0', 10) : 0;
            setExcuses(paid ? 1 : 0);
        });

        if (ocNotes && ocCounter) {
            ocNotes.addEventListener('input', () => ocCounter.textContent = `${ocNotes.value.length}/255`);
        }
        if (ocStartDate && ocEndDate) {
            ocStartDate.addEventListener('change', () => {
                if (ocStartDate.value) {
                    ocEndDate.min = ocStartDate.value;
                    if (ocEndDate.value && ocEndDate.value < ocStartDate.value) ocEndDate.value = ocStartDate.value;
                }
            });
        }
        if (ocForm) {
            ocForm.addEventListener('submit', function(e) {
                let valid = ocForm.checkValidity();
                if (ocStartDate.value && ocEndDate.value && ocEndDate.value < ocStartDate.value) {
                    ocEndDate.setCustomValidity('A data final deve ser igual ou apos a inicial.');
                    valid = false;
                } else { ocEndDate.setCustomValidity(''); }
                if (!valid) { e.preventDefault(); e.stopPropagation(); }
                ocForm.classList.add('was-validated');
            }, false);
        }

        function resetForm() {
            ocId.value = '0';
            ocTitle.textContent = 'Novo Afastamento';
            ocBanner.classList.add('d-none');
            ocForm.reset();
            ocForm.classList.remove('was-validated');
            if (ocCounter) ocCounter.textContent = '0/255';
        }

        window.editLeave = async function(leaveId) {
            try {
                const r = await fetch(`get_leave_details.php?id=${leaveId}`);
                const d = await r.json();
                if (d.error) { alert('Erro: ' + d.error); return; }
                ocForm.classList.remove('was-validated');
                ocId.value = d.id || '';
                ocTitle.textContent = 'Editar Afastamento';
                ocBannerId.textContent = leaveId;
                ocBanner.classList.remove('d-none');
                ocTeacherId.value = d.teacher_id || '';
                ocSchoolId.value = d.school_id || '';
                ocTypeId.value = d.type_id || '';
                setExcuses(d.excuses_absence);
                ocApproved.value = d.approved === null ? '' : (d.approved ? '1' : '0');
                ocStartDate.value = d.start_date || '';
                ocEndDate.value = d.end_date || '';
                ocDescription.value = d.description || '';
                ocCidCode.value = d.cid_code || '';
                ocNotes.value = d.notes || '';
                if (ocCounter) ocCounter.textContent = `${ocNotes.value.length}/255`;
                if (ocStartDate.value) ocEndDate.min = ocStartDate.value;
                oc.show();
            } catch (err) { alert('Erro ao carregar: ' + err.message); }
        };

        // BUG-006: campos do banco (description, notes, teacher_name etc.) eram
        // interpolados em innerHTML sem escape, permitindo Stored XSS por outro
        // admin/colaborador. escHtml() é aplicado a tudo que vem do servidor;
        // approved_badge é a única exceção intencional (HTML preparado server-side).
        const escHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);

        window.viewDetails = async function(leaveId) {
            const content = document.getElementById('detailsContent');
            content.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"><span class="visually-hidden">Carregando...</span></div></div>';
            detailsModal.show();
            try {
                const r = await fetch(`get_leave_details.php?id=${leaveId}`);
                const d = await r.json();
                if (d.error) { content.innerHTML = `<div class="alert alert-danger">${escHtml(d.error)}</div>`; return; }
                const safeId = parseInt(leaveId, 10) || 0;
                content.innerHTML = `
                    <div class="row g-3">
                        <div class="col-md-6"><strong>Colaborador:</strong><br>${escHtml(d.teacher_name) || '-'}</div>
                        <div class="col-md-6"><strong>Tipo:</strong><br>${escHtml(d.type_name) || '-'}</div>
                        <div class="col-md-4"><strong>Inicio:</strong><br>${escHtml(d.start_date_fmt) || '-'}</div>
                        <div class="col-md-4"><strong>Fim:</strong><br>${escHtml(d.end_date_fmt) || '-'}</div>
                        <div class="col-md-4"><strong>Dias:</strong><br><span class="badge bg-info">${escHtml(d.days_count) || '0'} dias</span></div>
                        ${d.cid_code?`<div class="col-md-6"><strong>CID-10:</strong><br>${escHtml(d.cid_code)}</div>`:''}
                        <div class="col-md-${d.cid_code?'6':'12'}"><strong>Status:</strong><br>${d.approved_badge||'-'}</div>
                        ${d.description?`<div class="col-12"><strong>Descricao:</strong><br><p class="mb-0">${escHtml(d.description)}</p></div>`:''}
                        ${d.notes?`<div class="col-12"><strong>Observacoes:</strong><br><p class="mb-0 small text-muted">${escHtml(d.notes)}</p></div>`:''}
                        ${d.attachment?`<div class="col-12"><strong>Anexo:</strong><br><a href="view_leave_attachment.php?id=${safeId}" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-file-earmark-text me-1"></i>Ver Atestado</a></div>`:''}
                    </div>`;
            } catch (err) { content.innerHTML = `<div class="alert alert-danger">Erro: ${escHtml(err && err.message)}</div>`; }
        };

        const ep = new URLSearchParams(window.location.search).get('edit');
        if (ep && /^\d+$/.test(ep)) editLeave(parseInt(ep));
    </script>
    <script>
    (function() {
      if (typeof bootstrap === 'undefined') return;
      document.querySelectorAll('.admin-table-wrap .dropdown-toggle[data-bs-toggle="dropdown"]').forEach(function(btn) {
        new bootstrap.Dropdown(btn, { popperConfig: { strategy: 'fixed' } });
      });
    })();
    </script>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

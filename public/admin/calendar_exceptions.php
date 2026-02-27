<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('calendar.manage')) {
    http_response_code(403);
    exit('Sem permissão.');
}
$pdo = db();
$admin = current_admin($pdo);

$msg = $_GET['msg'] ?? '';

// POST: criar/editar exceção
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $school_id = $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
        $date = $_POST['date'] ?? '';
        $type = $_POST['type'] ?? 'holiday';
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $recurrence = $_POST['recurrence'] ?? 'none';
        $is_working_day = isset($_POST['is_working_day']) ? 1 : 0;
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$name) {
            header('Location: calendar_exceptions.php?msg=' . urlencode('Dados inválidos'));
            exit;
        }
        
        if ($id > 0) {
            $st = $pdo->prepare("UPDATE calendar_exceptions SET school_id=?, date=?, type=?, name=?, description=?, recurrence=?, is_working_day=?, created_by_admin_id=? WHERE id=?");
            $st->execute([$school_id, $date, $type, $name, $description, $recurrence, $is_working_day, $_SESSION['admin_id'] ?? null, $id]);
            header('Location: calendar_exceptions.php?msg=' . urlencode('Exceção atualizada!'));
        } else {
            $st = $pdo->prepare("INSERT INTO calendar_exceptions (school_id, date, type, name, description, recurrence, is_working_day, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([$school_id, $date, $type, $name, $description, $recurrence, $is_working_day, $_SESSION['admin_id'] ?? null]);
            header('Location: calendar_exceptions.php?msg=' . urlencode('Exceção criada!'));
        }
        exit;
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM calendar_exceptions WHERE id=?")->execute([$id]);
            header('Location: calendar_exceptions.php?msg=' . urlencode('Exceção excluída!'));
        }
        exit;
    }
    
    if ($action === 'generate_mobile') {
        $year = (int)($_POST['year'] ?? date('Y'));
        try {
            $pdo->query("CALL generate_mobile_holidays($year)");
            header('Location: calendar_exceptions.php?msg=' . urlencode("Feriados móveis de $year gerados!"));
        } catch (Exception $e) {
            header('Location: calendar_exceptions.php?msg=' . urlencode('Erro: ' . $e->getMessage()));
        }
        exit;
    }
}

// Busca exceções
$filter_school = isset($_GET['school']) ? (int)$_GET['school'] : -1; // -1 = todas
$filter_type = $_GET['type'] ?? '';
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

$where = ["YEAR(date) = ?"];
$params = [$filter_year];

if ($filter_school >= 0) {
    if ($filter_school === 0) {
        $where[] = "school_id IS NULL";
    } else {
        $where[] = "school_id = ?";
        $params[] = $filter_school;
    }
}

if ($filter_type) {
    $where[] = "type = ?";
    $params[] = $filter_type;
}

$sql = "SELECT ce.*, s.name as school_name 
        FROM calendar_exceptions ce
        LEFT JOIN schools s ON s.id = ce.school_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ce.date ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$exceptions = $st->fetchAll(PDO::FETCH_ASSOC);

// Busca escolas para filtros
$schools = [];
if (is_network_admin($admin)) {
    $schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Formata exceções para o FullCalendar
$events = [];
foreach ($exceptions as $exc) {
    $color = '#6c757d';
    if ($exc['type'] === 'holiday') $color = '#dc3545';
    if ($exc['type'] === 'workday') $color = '#198754';
    if ($exc['is_working_day']) $color = '#0d6efd';
    
    $events[] = [
        'id' => $exc['id'],
        'title' => $exc['name'],
        'start' => $exc['date'],
        'backgroundColor' => $color,
        'borderColor' => $color,
        'extendedProps' => [
            'type' => $exc['type'],
            'school' => $exc['school_name'] ?? 'Toda a rede',
            'description' => $exc['description'] ?? '',
            'is_working_day' => $exc['is_working_day']
        ]
    ];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Calendário e Feriados | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <style>
        .fc {font-size: 0.9em;}
        .fc-event {cursor: pointer;}
    </style>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="mb-4">
            <div class="p-3 p-md-4 rounded-3 border bg-white">
                <div class="d-flex align-items-start gap-3">
                    <span class="bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width:3rem;height:3rem;">
                        <i class="bi bi-calendar-event fs-4"></i>
                    </span>
                    <div class="flex-grow-1">
                        <h3 class="mb-1 fw-semibold">Calendário e Exceções</h3>
                        <p class="text-muted mb-2">
                            Gerencie feriados, sábados letivos, eventos acadêmicos e outras exceções do calendário.
                        </p>
                        <div class="small text-muted">
                            Exceções afetam o cálculo de horas esperadas nos relatórios mensais e financeiros.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i><?= esc($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Filtros e Ações Rápidas -->
            <div class="col-lg-3">
                <div class="card shadow-sm mb-3">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-funnel me-2"></i>Filtros
                    </div>
                    <div class="card-body">
                        <form method="get">
                            <div class="mb-3">
                                <label class="form-label">Ano</label>
                                <select class="form-select" name="year" onchange="this.form.submit()">
                                    <?php for($y = date('Y')-1; $y <= date('Y')+2; $y++): ?>
                                        <option value="<?=$y?>" <?=$filter_year===$y?'selected':''?>><?=$y?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <?php if (is_network_admin($admin)): ?>
                            <div class="mb-3">
                                <label class="form-label">Escola</label>
                                <select class="form-select" name="school" onchange="this.form.submit()">
                                    <option value="-1">Todas</option>
                                    <option value="0" <?=$filter_school===0?'selected':''?>>Rede inteira</option>
                                    <?php foreach ($schools as $s): ?>
                                        <option value="<?=(int)$s['id']?>" <?=$filter_school===(int)$s['id']?'selected':''?>><?=esc($s['name'])?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label">Tipo</label>
                                <select class="form-select" name="type" onchange="this.form.submit()">
                                    <option value="">Todos</option>
                                    <option value="holiday" <?=$filter_type==='holiday'?'selected':''?>>Feriado</option>
                                    <option value="workday" <?=$filter_type==='workday'?'selected':''?>>Sábado Letivo</option>
                                    <option value="academic_event" <?=$filter_type==='academic_event'?'selected':''?>>Evento</option>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card shadow-sm mb-3">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-stars me-2"></i>Ações Rápidas
                    </div>
                    <div class="card-body">
                        <button class="btn btn-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#newExceptionModal">
                            <i class="bi bi-plus-circle me-2"></i>Nova Exceção
                        </button>
                        <button class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#generateMobileModal">
                            <i class="bi bi-calendar3 me-2"></i>Gerar Feriados Móveis
                        </button>
                    </div>
                </div>
                
                <div class="card shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-info-circle me-2"></i>Legenda
                    </div>
                    <div class="card-body small">
                        <div class="mb-2"><span class="badge bg-danger me-2">●</span>Feriado (não trabalha)</div>
                        <div class="mb-2"><span class="badge bg-primary me-2">●</span>Dia letivo especial</div>
                        <div class="mb-2"><span class="badge bg-success me-2">●</span>Sábado letivo</div>
                        <div><span class="badge bg-secondary me-2">●</span>Evento acadêmico</div>
                    </div>
                </div>
            </div>
            
            <!-- Calendário Visual -->
            <div class="col-lg-9">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><i class="bi bi-calendar4-week me-2"></i>Calendário <?= $filter_year ?></span>
                        <span class="badge bg-secondary"><?= count($exceptions) ?> exceção(ões)</span>
                    </div>
                    <div class="card-body">
                        <div id="calendar"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Nova Exceção -->
    <div class="modal fade" id="newExceptionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nova Exceção de Calendário</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="id" id="modalExceptionId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Data <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" id="modalDate" required>
                        </div>
                        
                        <?php if (is_network_admin($admin)): ?>
                        <div class="mb-3">
                            <label class="form-label">Escola</label>
                            <select class="form-select" name="school_id" id="modalSchool">
                                <option value="">Toda a rede</option>
                                <?php foreach ($schools as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= esc($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Tipo <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" id="modalType" required>
                                <option value="holiday">Feriado (não trabalha)</option>
                                <option value="workday">Sábado Letivo (trabalha)</option>
                                <option value="academic_event">Evento Acadêmico</option>
                                <option value="exam_day">Dia de Prova</option>
                                <option value="recess">Recesso</option>
                                <option value="compensation">Compensação</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nome <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="modalName" required placeholder="Ex: Natal, Sábado Letivo, Festa Junina">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea class="form-control" name="description" id="modalDescription" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Recorrência</label>
                            <select class="form-select" name="recurrence" id="modalRecurrence">
                                <option value="none">Não repete</option>
                                <option value="yearly">Anual (todo ano)</option>
                            </select>
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_working_day" id="modalIsWorkday" value="1">
                            <label class="form-check-label" for="modalIsWorkday">
                                É dia de trabalho <span class="small text-muted">(ex: sábado letivo)</span>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle me-1"></i>Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Gerar Feriados Móveis -->
    <div class="modal fade" id="generateMobileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar3 me-2"></i>Gerar Feriados Móveis</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="action" value="generate_mobile">
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Feriados Móveis:</strong> Páscoa, Carnaval, Sexta-feira Santa, Corpus Christi
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Ano <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="year" min="2020" max="2030" value="<?= date('Y') ?>" required>
                        </div>
                        
                        <div class="text-muted small">
                            Os feriados serão calculados automaticamente usando o algoritmo de Computus (cálculo da Páscoa).
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-magic me-1"></i>Gerar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/pt-br.global.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const calendarEl = document.getElementById('calendar');
        const newModal = new bootstrap.Modal(document.getElementById('newExceptionModal'));
        
        const calendar = new FullCalendar.Calendar(calendarEl, {
            locale: 'pt-br',
            initialView: 'dayGridMonth',
            initialDate: '<?= $filter_year ?>-01-01',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek'
            },
            events: <?= json_encode($events, JSON_UNESCAPED_UNICODE) ?>,
            dateClick: function(info) {
                // Preenche modal com data clicada
                document.getElementById('modalDate').value = info.dateStr;
                document.getElementById('modalExceptionId').value = '';
                document.getElementById('modalName').value = '';
                document.getElementById('modalDescription').value = '';
                document.getElementById('modalType').value = 'holiday';
                document.getElementById('modalRecurrence').value = 'none';
                document.getElementById('modalIsWorkday').checked = false;
                document.querySelector('#newExceptionModal form').action = '';
                newModal.show();
            },
            eventClick: function(info) {
                const event = info.event;
                const props = event.extendedProps;
                
                if (confirm(`${event.title}\nData: ${event.start.toLocaleDateString('pt-BR')}\nEscola: ${props.school}\n\nDeseja editar ou excluir?`)) {
                    // Editar (simplificado - recarrega página com parâmetros)
                    window.location.href = `?edit=${event.id}`;
                }
            }
        });
        
        calendar.render();
    });
    </script>
</body>
</html>


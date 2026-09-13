<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id) {
  $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
  $stmt->execute([$id]);
  $teacher = $stmt->fetch();
  if (!$teacher) flash_redirect('error', 'Colaborador não encontrado.', 'teachers.php');
} else {
  $teacher = null;
}
$faceNotice = null;
if (!empty($_SESSION['teacher_face_notice']) && is_array($_SESSION['teacher_face_notice'])) {
  $faceNotice = $_SESSION['teacher_face_notice'];
}
unset($_SESSION['teacher_face_notice']);

// Carregar tipos de colaboradores
$types = $pdo->query("SELECT id, name, schedule_mode FROM collaborator_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Escolas
$schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$teacherSchools = [];
if ($id) {
  $st = $pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id = ?");
  $st->execute([$id]);
  $teacherSchools = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$weekdays = [
  1 => 'Segunda-feira',
  2 => 'Terça-feira',
  3 => 'Quarta-feira',
  4 => 'Quinta-feira',
  5 => 'Sexta-feira',
  6 => 'Sábado',
  0 => 'Domingo',
];

// Carregar rotina semanal (modo classes)
$schedules = [];
if ($id) {
  $stmt = $pdo->prepare("SELECT * FROM teacher_schedules WHERE teacher_id = ?");
  $stmt->execute([$id]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sch) {
    $schedules[(int)$sch['weekday']] = $sch;
  }
}

// Carregar rotina semanal por horário (modo time)
$timeSchedules = [];
if ($id) {
  $stmt = $pdo->prepare("SELECT * FROM collaborator_time_schedules WHERE teacher_id = ?");
  $stmt->execute([$id]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ts) {
    $timeSchedules[(int)$ts['weekday']] = $ts;
  }
}

// Carregar rotina semanal por horas/dia (modo hours — motorista, monitor)
$hoursSchedules = [];
if ($id) {
  $stmt = $pdo->prepare("SELECT * FROM collaborator_hours_schedules WHERE teacher_id = ?");
  $stmt->execute([$id]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $hs) {
    $hoursSchedules[(int)$hs['weekday']] = $hs;
  }
}

// Carregar atribuições de períodos de aula (grade horária)
$periodAssignments = [];
if ($id) {
  $stmt = $pdo->prepare("SELECT tca.*, cp.period_number, cp.start_time, cp.end_time
                         FROM teacher_class_assignments tca
                         JOIN class_periods cp ON cp.id = tca.period_id
                         WHERE tca.teacher_id = ?
                         ORDER BY cp.period_number");
  $stmt->execute([$id]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
    $weekday = (int)$assignment['weekday'];
    $periodId = (int)$assignment['period_id'];
    if (!isset($periodAssignments[$weekday])) {
      $periodAssignments[$weekday] = [];
    }
    $periodAssignments[$weekday][$periodId] = $assignment;
  }
}

// Carregar todos os períodos disponíveis
$allPeriods = get_class_periods();
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title><?= $id ? 'Editar' : 'Cadastrar' ?> Colaborador(a) | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <div class="container-fluid admin-content mb-5">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between p-3 mb-4 rounded-3 shadow-sm">
          <div class="d-flex align-items-center gap-3">
            <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center" style="width:44px;height:44px;">
              <i class="bi bi-person-badge fs-5"></i>
            </div>
            <div>
              <h4 class="mb-0"><?= $id ? 'Editar' : 'Cadastrar' ?> Colaborador(a)</h4>
              <small class="text-muted">
                <?= $id
                  ? 'Atualize os dados' . (!empty($teacher['name']) ? ' de ' . esc($teacher['name']) : '')
                  : 'Preencha os dados para criar um novo colaborador'
                ?>
              </small>
            </div>
          </div>
        </div>
        <?php if ($faceNotice && ($faceNotice['type'] ?? '') === 'face_duplicate_active'): ?>
          <div class="alert alert-warning border border-warning-subtle shadow-sm d-flex align-items-start gap-3 mb-4 alert-dismissible fade show" role="alert">
            <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width:40px;height:40px;min-width:40px;">
              <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div class="flex-grow-1">
              <div class="fw-semibold mb-1"><?= esc((string)($faceNotice['title'] ?? 'Biometria já cadastrada')) ?></div>
              <div class="small text-muted mb-2"><?= esc((string)($faceNotice['message'] ?? 'Identificamos dados faciais já vinculados a outro cadastro ativo.')) ?></div>
              <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#faceDuplicateModal">
                Ver aviso completo
              </button>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
          </div>

          <div class="modal fade" id="faceDuplicateModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning-subtle">
                  <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="bi bi-shield-exclamation text-warning"></i>
                    <?= esc((string)($faceNotice['title'] ?? 'Biometria já cadastrada')) ?>
                  </h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                  <p class="mb-2"><?= esc((string)($faceNotice['message'] ?? 'Identificamos dados faciais já vinculados a outro cadastro ativo.')) ?></p>
                  <div class="small text-muted"><?= esc((string)($faceNotice['details'] ?? 'Revise os dados e tente novamente.')) ?></div>
                  <div class="mt-3 p-3 rounded bg-light small">
                    <strong>Importante:</strong> por privacidade, o sistema não exibe dados da pessoa já vinculada.
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar e continuar</button>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php
        if (session_status() !== PHP_SESSION_ACTIVE) {
          session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
          $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrf = $_SESSION['csrf_token'];

        // Idempotency token: regerado a cada render do form. Marcado como consumido
        // após o save bem-sucedido. Se o usuário der refresh ou voltar e re-enviar,
        // o teachers_save.php detecta e bloqueia com mensagem amigável.
        $idempotencyToken = bin2hex(random_bytes(16));

        // Lê flash do save anterior (se houve erro ou warning)
        $saveFlash = null;
        $saveEcho = [];
        if (!empty($_SESSION['teacher_save_flash']) && is_array($_SESSION['teacher_save_flash'])) {
          $saveFlash = $_SESSION['teacher_save_flash'];
          $saveEcho  = is_array($saveFlash['echo'] ?? null) ? $saveFlash['echo'] : [];
          unset($_SESSION['teacher_save_flash']);
        }
        $echoVal = function (string $key, $default = '') use ($saveEcho) {
          return $saveEcho[$key] ?? $default;
        };
        ?>

        <?php if ($saveFlash): ?>
          <?php
            $alertClass = match ($saveFlash['type']) {
              'error'   => 'alert-danger',
              'warning' => 'alert-warning',
              default   => 'alert-info',
            };
            $alertIcon = match ($saveFlash['type']) {
              'error'   => 'bi-x-circle-fill',
              'warning' => 'bi-exclamation-triangle-fill',
              default   => 'bi-info-circle-fill',
            };
          ?>
          <div class="alert <?= esc($alertClass) ?> d-flex gap-2 align-items-start mb-4" role="alert">
            <i class="bi <?= esc($alertIcon) ?> fs-5 mt-1"></i>
            <div class="flex-grow-1">
              <strong><?= esc($saveFlash['title'] ?? 'Aviso') ?></strong>
              <div><?= esc($saveFlash['message'] ?? '') ?></div>
              <?php if (!empty($saveFlash['details'])): ?>
                <div class="small mt-1 text-muted"><?= esc($saveFlash['details']) ?></div>
              <?php endif; ?>
              <?php if (($saveFlash['type'] ?? '') === 'warning'
                && stripos((string)($saveFlash['message'] ?? ''), 'inativo') !== false): ?>
                <!-- Opção de reativar cadastro inativo -->
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="reactivate_existing_chk" name="reactivate_existing" value="1" form="teacherForm">
                  <label class="form-check-label fw-semibold" for="reactivate_existing_chk">
                    Reativar o cadastro existente (em vez de criar um novo)
                  </label>
                </div>
              <?php elseif (($saveFlash['type'] ?? '') === 'warning'
                && stripos((string)($saveFlash['message'] ?? ''), 'similar') !== false): ?>
                <!-- Opção de forçar criação mesmo com nome duplicado -->
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="force_create_chk" name="force_create_duplicate_name" value="1" form="teacherForm">
                  <label class="form-check-label fw-semibold" for="force_create_chk">
                    Confirmar cadastro mesmo com nome igual (homônimo legítimo)
                  </label>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <form id="teacherForm" method="post" action="teachers_save.php" autocomplete="off" spellcheck="false">
          <input type="hidden" name="id" value="<?= esc($id) ?>">
          <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
          <input type="hidden" name="_idempotency" value="<?= esc($idempotencyToken) ?>">

          <section class="app-section-card">
            <header class="app-section-card__header">
              <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-person-badge"></i>Seção 1</span>
              <h2 class="app-section-card__title">Dados do colaborador</h2>
            </header>
            <div class="app-section-card__body admin-form-section">
              <div class="row g-3">
                <div class="col-12 col-lg-6">
                  <label class="form-label" for="name">Nome <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="name" name="name" required maxlength="120" autocomplete="name" placeholder="Nome completo" autofocus value="<?= esc($teacher['name'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-3">
                  <label class="form-label" for="cpf_display">CPF <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" id="cpf_display" name="_cpf_display" required maxlength="14" inputmode="numeric" placeholder="000.000.000-00" pattern="\d{3}\.\d{3}\.\d{3}-\d{2}" title="Formato: 000.000.000-00" value="<?= esc($teacher['cpf'] ?? '') ?>">
                  <input type="hidden" id="cpf" name="cpf" value="<?= esc($teacher['cpf'] ?? '') ?>">
                </div>
                <div class="col-12 col-lg-3 col-md-6">
                  <label class="form-label" for="email">E-mail</label>
                  <input type="email" class="form-control" id="email" name="email" maxlength="120" autocomplete="email" placeholder="email@exemplo.com" value="<?= esc($teacher['email'] ?? '') ?>">
                </div>
              </div>

              <div class="row g-3 mt-1">
                <div class="col-12 col-md-4">
                  <label class="form-label" for="type_id">Tipo <span class="text-danger">*</span></label>
                  <select class="form-select" name="type_id" id="type_id" required>
                    <option value="" data-mode="time">Selecione</option>
                    <?php foreach ($types as $t): ?>
                      <option value="<?= (int)$t['id'] ?>" data-mode="<?= esc($t['schedule_mode']) ?>" <?= isset($teacher['type_id']) && (int)$teacher['type_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= esc($t['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-12 col-md-4">
                  <label class="form-label" for="base_salary">Salário base</label>
                  <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="number" step="0.01" min="0" inputmode="decimal" class="form-control" id="base_salary" name="base_salary" value="<?= esc($teacher['base_salary'] ?? '0.00') ?>" required>
                  </div>
                </div>

                <?php
                // Campo de valor/hora (opcional) - só mostra se a coluna existir
                $hasHourlyRate = false;
                try {
                  $pdo->query("SELECT hourly_rate FROM teachers LIMIT 1");
                  $hasHourlyRate = true;
                } catch (PDOException $e) {
                  // Coluna não existe ainda
                }
                ?>
                <?php if ($hasHourlyRate): ?>
                <div class="col-12 col-md-4">
                  <label class="form-label" for="hourly_rate">
                    Valor/Hora
                    <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" title="Valor por hora para cálculo estimado na folha de ponto"></i>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="number" step="0.01" min="0" inputmode="decimal" class="form-control" id="hourly_rate" name="hourly_rate" value="<?= esc($teacher['hourly_rate'] ?? '') ?>" placeholder="0.00">
                  </div>
                  <div class="form-text">Opcional: para mostrar valor estimado na consulta</div>
                </div>
                <?php endif; ?>
              </div>

              <hr class="my-4">
              <h6 class="text-muted d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-file-earmark-ruled"></i>
                Dados contratuais
                <span class="badge bg-warning text-dark">Exigidos pelo AFD/AEJ</span>
              </h6>
              <div class="row g-3">
                <div class="col-12 col-md-4">
                  <label class="form-label" for="pis">PIS / PASEP / NIT</label>
                  <input type="text" class="form-control" id="pis" name="pis" maxlength="14" inputmode="numeric" placeholder="000.00000.00-0" value="<?= esc($echoVal('pis', $teacher['pis'] ?? '')) ?>">
                  <div class="form-text">O AEJ identifica o trabalhador pelo PIS, não pelo CPF.</div>
                </div>
                <div class="col-12 col-md-4">
                  <label class="form-label" for="matricula">Matrícula</label>
                  <input type="text" class="form-control" id="matricula" name="matricula" maxlength="20" value="<?= esc($echoVal('matricula', $teacher['matricula'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-4">
                  <label class="form-label" for="cbo">CBO</label>
                  <input type="text" class="form-control" id="cbo" name="cbo" maxlength="6" inputmode="numeric" placeholder="000000" value="<?= esc($echoVal('cbo', $teacher['cbo'] ?? '')) ?>">
                  <div class="form-text">Classificação Brasileira de Ocupações.</div>
                </div>
                <div class="col-12 col-md-4">
                  <label class="form-label" for="admission_date">Data de admissão</label>
                  <input type="date" class="form-control" id="admission_date" name="admission_date" value="<?= esc($echoVal('admission_date', $teacher['admission_date'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-4">
                  <label class="form-label" for="dismissal_date">Data de desligamento</label>
                  <input type="date" class="form-control" id="dismissal_date" name="dismissal_date" value="<?= esc($echoVal('dismissal_date', $teacher['dismissal_date'] ?? '')) ?>">
                  <div class="form-text">Deixe em branco se o vínculo está ativo.</div>
                </div>
              </div>
            </div>
          </section>

          <section class="app-section-card">
            <header class="app-section-card__header">
              <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-building"></i>Seção 2</span>
              <h2 class="app-section-card__title">Vinculação às instituições</h2>
              <span class="app-section-card__hint">Onde o(a) colaborador(a) pode registrar ponto</span>
            </header>
            <div class="app-section-card__body admin-form-section">
              <div class="row g-4 align-items-stretch">
                <div class="col-12">
                  <label class="form-label" for="schools">Instituições onde atua</label>

                  <div class="border rounded-3 p-3 mb-3 bg-body-tertiary">
                    <div class="row g-2 align-items-center">
                      <div class="col-12 col-md-8">
                        <div class="input-group">
                          <span class="input-group-text"><i class="bi bi-search"></i></span>
                          <input type="text" class="form-control" id="schoolSearch" placeholder="Filtrar por nome da instituição...">
                        </div>
                      </div>
                      <div class="col-12 col-md-4">
                        <div class="form-check form-switch d-flex align-items-center h-100 justify-content-md-end">
                          <input class="form-check-input" type="checkbox" id="only_selected_toggle">
                          <label class="form-check-label ms-2" for="only_selected_toggle">Somente selecionadas</label>
                        </div>
                      </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                      <button type="button" class="btn btn-outline-secondary btn-sm" data-schools-action="all">
                        <i class="bi bi-check2-all"></i> Selecionar todas
                      </button>
                      <button type="button" class="btn btn-outline-secondary btn-sm" data-schools-action="none">
                        <i class="bi bi-x-lg"></i> Limpar
                      </button>
                      <button type="button" class="btn btn-outline-secondary btn-sm" data-schools-action="invert">
                        <i class="bi bi-shuffle"></i> Inverter
                      </button>
                    </div>
                  </div>

                  <div class="border rounded-3 p-3 bg-info-subtle mb-3">
                    <div class="d-flex align-items-start gap-2 mb-2">
                      <i class="bi bi-info-circle-fill text-info-emphasis mt-1"></i>
                      <div class="small text-info-emphasis fw-semibold">Escopo de atuação</div>
                    </div>
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" id="network_wide" name="network_wide" value="1" <?= (int)($teacher['network_wide'] ?? 0) === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="network_wide">
                        Atua na rede de ensino completa (todas as instituições)
                      </label>
                    </div>
                    <div class="text-muted small mt-2">
                      Marcado: o(a) colaborador(a) pode atuar em qualquer instituição. A seleção ao lado fica desabilitada.
                      Admin de instituição não verá estes colaboradores.
                    </div>
                    <div id="nw_hint" class="alert alert-info py-2 px-3 mt-3 mb-0" style="display:none;">
                      Seleção de instituições desabilitada porque “Rede completa” está marcado.
                    </div>
                  </div>

                  <?php if (empty($schools)): ?>
                    <div class="alert alert-warning py-2">Nenhuma instituição ativa cadastrada.</div>
                  <?php endif; ?>

                  <select multiple size="11" class="form-select" id="schools" name="schools[]">
                    <?php foreach ($schools as $s): ?>
                      <option value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $teacherSchools, true) ? 'selected' : '' ?>>
                        <?= esc($s['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <div class="d-flex justify-content-between mt-2">
                    <div class="form-text">
                      Segure Ctrl (ou Cmd) para múltipla seleção. Use os botões para agilizar.
                    </div>
                    <div class="text-muted small">
                      <span id="schools_count">0 selecionada(s)</span>
                    </div>
                  </div>

                  <div id="schools_no_results" class="text-muted small mt-2" style="display:none;">
                    Nenhuma instituição encontrada para o filtro aplicado.
                  </div>
                </div>
              </div>
            </div>

            <script>
              (function() {
                const sel = document.getElementById('schools');
                const search = document.getElementById('schoolSearch');
                const onlySel = document.getElementById('only_selected_toggle');
                const countEl = document.getElementById('schools_count');
                const noResEl = document.getElementById('schools_no_results');
                const nwChk = document.getElementById('network_wide');
                const hint = document.getElementById('nw_hint');
                const actionBtns = document.querySelectorAll('[data-schools-action]');

                function updateCount() {
                  const opts = Array.from(sel?.options || []);
                  const selected = opts.filter(o => o.selected).length;
                  const visible = opts.filter(o => !o.hidden).length;
                  if (countEl) countEl.textContent = `${selected} selecionada(s)` + (visible !== opts.length ? ` • ${visible} visível(is)` : '');
                }

                function applyFilter() {
                  const term = (search?.value || '').trim().toLowerCase();
                  const requireSelected = !!(onlySel && onlySel.checked);
                  let visibleCount = 0;
                  (Array.from(sel?.options || [])).forEach(o => {
                    const matchesText = o.text.toLowerCase().includes(term);
                    const matchesSel = !requireSelected || o.selected;
                    const visible = matchesText && matchesSel;
                    o.hidden = !visible;
                    if (visible) visibleCount++;
                  });
                  if (noResEl) noResEl.style.display = visibleCount === 0 ? '' : 'none';
                  updateCount();
                }

                function doAction(action) {
                  const opts = Array.from(sel?.options || []);
                  const targetOpts = opts.filter(o => !o.hidden);
                  if (action === 'all') targetOpts.forEach(o => o.selected = true);
                  if (action === 'none') targetOpts.forEach(o => o.selected = false);
                  if (action === 'invert') targetOpts.forEach(o => o.selected = !o.selected);
                  updateCount();
                }

                function nwUpdate() {
                  const disabled = !!(nwChk && nwChk.checked);
                  if (sel) sel.disabled = disabled;
                  if (search) search.disabled = disabled;
                  if (onlySel) onlySel.disabled = disabled;
                  actionBtns.forEach(b => b.disabled = disabled);
                  if (hint) hint.style.display = disabled ? '' : 'none';
                }

                search?.addEventListener('input', applyFilter);
                onlySel?.addEventListener('change', applyFilter);
                sel?.addEventListener('change', updateCount);
                actionBtns.forEach(btn => {
                  btn.addEventListener('click', () => doAction(btn.getAttribute('data-schools-action')));
                });
                nwChk?.addEventListener('change', nwUpdate);

                // Init
                applyFilter();
                nwUpdate();
              })();
            </script>
          </section>

          <section class="app-section-card">
            <header class="app-section-card__header">
              <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-calendar-week"></i>Seção 3</span>
              <h2 class="app-section-card__title">Rotina de trabalho</h2>
              <span class="app-section-card__hint">Jornada semanal por aulas ou horário fixo</span>
            </header>
            <div class="app-section-card__body admin-form-section">
              <?php
              // Sugestões de padrão a partir de algum dia já preenchido
              $defCount = 0;
              $defMinutes = 60;
              foreach ($schedules as $sch) {
                if ((int)($sch['classes_count'] ?? 0) > 0) {
                  $defCount = (int)$sch['classes_count'];
                  $defMinutes = (int)($sch['class_minutes'] ?? 60);
                  break;
                }
              }
              if ($defCount <= 0) $defCount = 1;
              if ($defMinutes <= 0) $defMinutes = 60;
              ?>
              <div id="schedule_classes_block" class="mt-2" style="display:none;">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                  <h6 class="mb-0">Rotina por aulas</h6>
                  <span class="badge bg-light text-dark ms-1">defina nº de aulas e duração por dia</span>

                  <div class="ms-auto small text-muted">
                    Total semanal: <strong id="weekly_total_min">0</strong> min (<strong id="weekly_total_min_h">00:00</strong>) • <strong id="weekly_total_classes">0</strong> aulas
                  </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text" title="Preencher valores para dias visíveis">Preencher</span>
                    <input type="number" class="form-control" id="classes_apply_count" min="0" max="20" step="1" inputmode="numeric" value="<?= esc($defCount) ?>" title="Nº de aulas padrão">
                    <span class="input-group-text">aulas de</span>
                    <input type="number" class="form-control" id="classes_apply_minutes" min="0" max="480" step="5" inputmode="numeric" value="<?= esc($defMinutes) ?>" title="Duração padrão (min)">
                    <span class="input-group-text">min</span>
                    <button type="button" class="btn btn-outline-secondary" id="btnApplyAllClasses" title="Aplicar aos dias visíveis">Aplicar</button>
                  </div>

                  <label for="replicate_from_classes_day" class="form-label m-0">Copiar de</label>
                  <select id="replicate_from_classes_day" class="form-select form-select-sm" style="width:auto;">
                    <?php foreach ($weekdays as $k => $dia): ?>
                      <option value="<?= (int)$k ?>"><?= esc($dia) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="btnReplicateClasses" title="Copiar do dia escolhido para os demais">
                    Replicar para os demais
                  </button>

                  <div class="vr mx-1 d-none d-md-block"></div>

                  <div class="btn-group btn-group-sm" role="group" aria-label="Atalhos de dias">
                    <button type="button" class="btn btn-outline-secondary" id="btnWorkdays" title="Ativar Seg a Sex">Dias úteis</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnWeekendOff" title="Desativar Sáb e Dom">Fds off</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnAllDays" title="Ativar todos">Todos</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnNoneDays" title="Desativar todos">Nenhum</button>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-bordered align-middle">
                    <thead class="table-light">
                      <tr>
                        <th scope="col" style="width: 180px;">Dia</th>
                        <th scope="col" style="width: 90px;">Ativo</th>
                        <th scope="col" style="width: 160px;">Nº de Aulas</th>
                        <th scope="col" style="width: 220px;">Duração (min)</th>
                        <th scope="col" style="width: 220px;">Total do dia</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($weekdays as $k => $dia):
                        $count = (int)($schedules[$k]['classes_count'] ?? 0);
                        $mins = (int)($schedules[$k]['class_minutes'] ?? 60);
                        $active = ($count > 0 && $mins > 0);
                      ?>
                        <tr data-day="<?= (int)$k ?>">
                          <td class="fw-semibold"><?= esc($dia) ?></td>
                          <td>
                            <div class="form-check form-switch">
                              <input class="form-check-input day-active" type="checkbox" id="day_active_<?= (int)$k ?>" name="schedule[<?= (int)$k ?>][active]" value="1" <?= $active ? 'checked' : '' ?>>
                              <label class="form-check-label" for="day_active_<?= (int)$k ?>"></label>
                            </div>
                          </td>
                          <td>
                            <input
                              type="number"
                              class="form-control classes-count"
                              name="schedule[<?= (int)$k ?>][classes_count]"
                              min="0" max="20" step="1" inputmode="numeric"
                              value="<?= esc($count) ?>">
                          </td>
                          <td>
                            <div class="input-group">
                              <input
                                type="number"
                                class="form-control class-minutes"
                                name="schedule[<?= (int)$k ?>][class_minutes]"
                                min="0" max="480" step="5" inputmode="numeric"
                                value="<?= esc($mins) ?>">
                              <span class="input-group-text">min</span>
                            </div>
                          </td>
                          <td>
                            <span class="badge bg-light text-dark day-total" style="font-size:0.95rem;">0 min (00:00)</span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="alert alert-info py-2 px-3">
                  Dica: deixe “Ativo” desligado para folga. Se reativar, o sistema sugere os valores padrão.
                </div>

                <script>
                  (function() {
                    const block = document.getElementById('schedule_classes_block');
                    if (!block) return;

                    const rows = Array.from(block.querySelectorAll('tbody tr[data-day]'));
                    const countInput = block.querySelector('#classes_apply_count');
                    const minsInput = block.querySelector('#classes_apply_minutes');
                    const weeklyMinEl = block.querySelector('#weekly_total_min');
                    const weeklyMinHumanEl = block.querySelector('#weekly_total_min_h');
                    const weeklyClsEl = block.querySelector('#weekly_total_classes');

                    const btnApplyAll = block.querySelector('#btnApplyAllClasses');
                    const btnWorkdays = block.querySelector('#btnWorkdays');
                    const btnWeekendOff = block.querySelector('#btnWeekendOff');
                    const btnAllDays = block.querySelector('#btnAllDays');
                    const btnNoneDays = block.querySelector('#btnNoneDays');

                    const btnReplicate = block.querySelector('#btnReplicateClasses');
                    const selReplicateFrom = block.querySelector('#replicate_from_classes_day');

                    const toInt = (v, d = 0) => {
                      const n = parseInt(String(v ?? '').replace(/[^\d-]/g, ''), 10);
                      return Number.isFinite(n) ? n : d;
                    };
                    const minToHHMM = (m) => {
                      m = Math.max(0, toInt(m, 0));
                      const h = Math.floor(m / 60);
                      const mm = m % 60;
                      return `${String(h).padStart(2,'0')}:${String(mm).padStart(2,'0')}`;
                    };

                    function setRowState(row, active) {
                      const chk = row.querySelector('.day-active');
                      const cnt = row.querySelector('.classes-count');
                      const mins = row.querySelector('.class-minutes');
                      if (!cnt || !mins || !chk) return;

                      chk.checked = !!active;

                      // Apenas readonly (não desabilita para enviar no POST)
                      const ro = !active;
                      cnt.readOnly = ro;
                      mins.readOnly = ro;

                      if (!active) {
                        if (toInt(cnt.value) !== 0) cnt.value = 0;
                        if (toInt(mins.value) !== 0) mins.value = 0;
                        row.classList.add('table-light');
                      } else {
                        if (toInt(cnt.value) === 0 && toInt(countInput?.value, 1) > 0) {
                          cnt.value = toInt(countInput?.value, 1);
                        }
                        if (toInt(mins.value) === 0 && toInt(minsInput?.value, 60) > 0) {
                          mins.value = toInt(minsInput?.value, 60);
                        }
                        row.classList.remove('table-light');
                      }
                      updateRowTotal(row);
                      updateWeeklyTotals();
                    }

                    function updateRowTotal(row) {
                      const cnt = toInt(row.querySelector('.classes-count')?.value, 0);
                      const mins = toInt(row.querySelector('.class-minutes')?.value, 0);
                      const total = Math.max(0, cnt) * Math.max(0, mins);
                      const badge = row.querySelector('.day-total');
                      if (badge) badge.textContent = `${total} min (${minToHHMM(total)})`;
                    }

                    function updateWeeklyTotals() {
                      let sumMin = 0;
                      let sumClasses = 0;
                      rows.forEach(row => {
                        const cnt = toInt(row.querySelector('.classes-count')?.value, 0);
                        const mins = toInt(row.querySelector('.class-minutes')?.value, 0);
                        sumClasses += Math.max(0, cnt);
                        sumMin += (Math.max(0, cnt) * Math.max(0, mins));
                      });
                      if (weeklyMinEl) weeklyMinEl.textContent = String(sumMin);
                      if (weeklyMinHumanEl) weeklyMinHumanEl.textContent = minToHHMM(sumMin);
                      if (weeklyClsEl) weeklyClsEl.textContent = String(sumClasses);
                    }

                    rows.forEach(row => {
                      const chk = row.querySelector('.day-active');
                      const cnt = row.querySelector('.classes-count');
                      const mins = row.querySelector('.class-minutes');

                      chk?.addEventListener('change', () => setRowState(row, chk.checked));

                      const autoActivate = () => {
                        const hasValues = toInt(cnt.value, 0) > 0 && toInt(mins.value, 0) > 0;
                        if (hasValues && !chk.checked) {
                          chk.checked = true;
                          setRowState(row, true);
                        } else {
                          updateRowTotal(row);
                          updateWeeklyTotals();
                        }
                      };

                      cnt?.addEventListener('input', autoActivate);
                      mins?.addEventListener('input', autoActivate);

                      // Estado inicial
                      setRowState(row, row.querySelector('.day-active')?.checked);
                    });

                    // Ações em massa
                    btnApplyAll?.addEventListener('click', () => {
                      const cntVal = Math.max(0, Math.min(20, toInt(countInput?.value, 1)));
                      const minVal = Math.max(0, Math.min(480, toInt(minsInput?.value, 60)));
                      rows.forEach(row => {
                        const cnt = row.querySelector('.classes-count');
                        const mins = row.querySelector('.class-minutes');
                        const chk = row.querySelector('.day-active');
                        if (!cnt || !mins || !chk) return;
                        cnt.value = cntVal;
                        mins.value = minVal;
                        chk.checked = (cntVal > 0 && minVal > 0);
                        setRowState(row, chk.checked);
                      });
                      updateWeeklyTotals();
                    });

                    btnWorkdays?.addEventListener('click', () => {
                      rows.forEach(row => {
                        const day = parseInt(row.getAttribute('data-day') || '-1', 10);
                        const isWorkday = day >= 1 && day <= 5;
                        setRowState(row, isWorkday);
                      });
                      updateWeeklyTotals();
                    });

                    btnWeekendOff?.addEventListener('click', () => {
                      rows.forEach(row => {
                        const day = parseInt(row.getAttribute('data-day') || '-1', 10);
                        const isWeekend = (day === 6 || day === 0);
                        if (isWeekend) setRowState(row, false);
                      });
                      updateWeeklyTotals();
                    });

                    btnAllDays?.addEventListener('click', () => {
                      rows.forEach(row => setRowState(row, true));
                      updateWeeklyTotals();
                    });

                    btnNoneDays?.addEventListener('click', () => {
                      rows.forEach(row => setRowState(row, false));
                      updateWeeklyTotals();
                    });

                    // Replicar de um dia para os demais
                    btnReplicate?.addEventListener('click', () => {
                      const src = parseInt(selReplicateFrom?.value || '-1', 10);
                      const srcRow = rows.find(r => parseInt(r.getAttribute('data-day') || '-2', 10) === src);
                      if (!srcRow) return;
                      const cntVal = toInt(srcRow.querySelector('.classes-count')?.value, 0);
                      const minVal = toInt(srcRow.querySelector('.class-minutes')?.value, 0);
                      if (cntVal <= 0 || minVal <= 0) {
                        alert('Preencha nº de aulas e duração no dia de origem antes de replicar.');
                        srcRow.querySelector('.classes-count')?.focus();
                        return;
                      }
                      rows.forEach(row => {
                        const day = parseInt(row.getAttribute('data-day') || '-1', 10);
                        if (day === src) return;
                        row.querySelector('.classes-count').value = cntVal;
                        row.querySelector('.class-minutes').value = minVal;
                        row.querySelector('.day-active').checked = true;
                        setRowState(row, true);
                      });
                      updateWeeklyTotals();
                    });

                    // Inicial
                    updateWeeklyTotals();
                  })();
                </script>
              </div>

              <div id="schedule_time_block" class="mt-2" style="display:none;">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                  <h6 class="mb-0">Jornada por horário</h6>
                  <span class="badge bg-light text-dark ms-1">defina entrada, saída e intervalo diário</span>

                  <div class="ms-auto small text-muted">
                    Total semanal: <strong id="weekly_time_total_min">0</strong> min (<strong id="weekly_time_total_h">00:00</strong>)
                  </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text" title="Preencher horários para todos">Preencher</span>
                    <input type="time" class="form-control" id="apply_start_time" step="60" title="Entrada para todos">
                    <span class="input-group-text">às</span>
                    <input type="time" class="form-control" id="apply_end_time" step="60" title="Saída para todos">
                    <span class="input-group-text">int.</span>
                    <input type="number" class="form-control" id="apply_break_min" min="0" max="600" step="5" inputmode="numeric" placeholder="min" title="Intervalo (min) para todos">
                    <button type="button" id="btnApplyAllTime" class="btn btn-outline-secondary">Aplicar</button>
                  </div>

                  <label for="replicate_from_day" class="form-label m-0">Copiar de</label>
                  <select id="replicate_from_day" class="form-select form-select-sm" style="width:auto;">
                    <?php foreach ($weekdays as $k => $dia): ?>
                      <option value="<?= (int)$k ?>" <?= (int)$k === 1 ? 'selected' : '' ?>><?= esc($dia) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" id="btnReplicateTimeSchedule" class="btn btn-outline-secondary btn-sm" title="Copiar do dia escolhido para os demais">
                    Replicar para os demais
                  </button>

                  <div class="vr mx-1 d-none d-md-block"></div>

                  <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary" id="btnTimeWorkdays" title="Preencher apenas Seg a Sex com o padrão de cima">Dias úteis</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnTimeWeekendOff" title="Limpar Sáb e Dom">Fds off</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnTimeClearAll" title="Limpar todos os dias">Limpar</button>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-bordered align-middle">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">Dia</th>
                        <th scope="col">Entrada</th>
                        <th scope="col">Saída</th>
                        <th scope="col" title="Marque para turnos noturnos (ex: 18h → 06h do dia seguinte)">Próx. dia</th>
                        <th scope="col">Intervalo (min)</th>
                        <th scope="col">Duração líquida</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($weekdays as $k => $dia):
                        $ts = $timeSchedules[$k] ?? null;
                        $startRaw = $ts['start_time'] ?? '';
                        $endRaw = $ts['end_time'] ?? '';
                        $start = $startRaw ? substr($startRaw, 0, 5) : '';
                        $end = $endRaw ? substr($endRaw, 0, 5) : '';
                        $break = isset($ts['break_minutes']) ? (int)$ts['break_minutes'] : 0;
                        $endNextDay = !empty($ts['end_next_day']) ? 1 : 0;
                      ?>
                        <tr data-day="<?= (int)$k ?>">
                          <td class="fw-semibold"><?= esc($dia) ?></td>
                          <td><input type="time" class="form-control time-start" name="time_schedule[<?= $k ?>][start]" step="60" placeholder="hh:mm" value="<?= esc($start) ?>"></td>
                          <td><input type="time" class="form-control time-end" name="time_schedule[<?= $k ?>][end]" step="60" placeholder="hh:mm" value="<?= esc($end) ?>"></td>
                          <td class="text-center">
                            <div class="form-check d-inline-block">
                              <input type="checkbox" class="form-check-input time-end-next-day" name="time_schedule[<?= $k ?>][end_next_day]" value="1" <?= $endNextDay ? 'checked' : '' ?> title="Saída no dia seguinte">
                            </div>
                          </td>
                          <td><input type="number" class="form-control time-break" name="time_schedule[<?= $k ?>][break]" min="0" max="600" step="5" inputmode="numeric" value="<?= esc($break) ?>"></td>
                          <td><span class="badge bg-light text-dark day-time-total">0 min (00:00)</span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="alert alert-info py-2 px-3">
                  Deixe em branco os dias sem trabalho. Campos inválidos serão destacados em vermelho.
                </div>

                <script>
                  (function() {
                    const table = document.getElementById('schedule_time_block');
                    if (!table) return;

                    const weekdays = <?= json_encode(array_keys($weekdays)) ?>;
                    const byName = (k, field) => document.querySelector(`[name="time_schedule[${k}][${field}]"]`);
                    const weeklyMinEl = document.getElementById('weekly_time_total_min');
                    const weeklyMinHumanEl = document.getElementById('weekly_time_total_h');

                    const q = (sel, root = document) => root.querySelector(sel);
                    const qa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

                    const toMin = (hhmm) => {
                      if (!hhmm || !/^\d{2}:\d{2}$/.test(hhmm)) return null;
                      const [h, m] = hhmm.split(':').map(n => parseInt(n, 10));
                      if (isNaN(h) || isNaN(m)) return null;
                      return h * 60 + m;
                    };
                    const minToHHMM = (m) => {
                      m = Math.max(0, m | 0);
                      const h = Math.floor(m / 60);
                      const mm = m % 60;
                      return `${String(h).padStart(2,'0')}:${String(mm).padStart(2,'0')}`;
                    };

                    function validateRow(row) {
                      const startEl = row.querySelector('.time-start');
                      const endEl = row.querySelector('.time-end');
                      const breakEl = row.querySelector('.time-break');
                      const nextDayEl = row.querySelector('.time-end-next-day');
                      const badge = row.querySelector('.day-time-total');

                      const start = startEl?.value || '';
                      const end = endEl?.value || '';
                      const br = Math.max(0, parseInt(breakEl?.value ?? '0', 10) || 0);
                      const endNextDay = !!nextDayEl?.checked;

                      // Reset visual state
                      [startEl, endEl, breakEl].forEach(el => el?.classList.remove('is-invalid'));

                      if (!start && !end && !br) {
                        if (badge) badge.textContent = '0 min (00:00)';
                        return 0; // dia vazio
                      }

                      const startMin = toMin(start);
                      const endMin = toMin(end);

                      let valid = true;
                      if (startMin === null) {
                        startEl?.classList.add('is-invalid');
                        valid = false;
                      }
                      if (endMin === null) {
                        endEl?.classList.add('is-invalid');
                        valid = false;
                      }
                      if (!valid) {
                        if (badge) badge.textContent = '—';
                        return 0;
                      }

                      // Sem flag "próximo dia": saída deve ser estritamente após entrada
                      if (!endNextDay && endMin <= startMin) {
                        endEl?.classList.add('is-invalid');
                        if (badge) badge.textContent = '— (marque "Próx. dia"?)';
                        return 0;
                      }

                      // Com flag: turno cruza a meia-noite (ou turno de 24h: start == end)
                      const gross = endNextDay ? (1440 - startMin + endMin) : (endMin - startMin);
                      if (br >= gross) {
                        breakEl?.classList.add('is-invalid');
                        if (badge) badge.textContent = '—';
                        return 0;
                      }

                      const net = gross - br;
                      const suffix = endNextDay ? ' (saída no dia seguinte)' : '';
                      if (badge) badge.textContent = `${net} min (${minToHHMM(net)})${suffix}`;
                      return net;
                    }

                    function updateWeeklyTotal() {
                      let sum = 0;
                      qa('tbody tr[data-day]', table).forEach(tr => {
                        sum += validateRow(tr) || 0;
                      });
                      if (weeklyMinEl) weeklyMinEl.textContent = String(sum);
                      if (weeklyMinHumanEl) weeklyMinHumanEl.textContent = minToHHMM(sum);
                    }

                    // Eventos por linha
                    qa('tbody tr[data-day]', table).forEach(tr => {
                      ['.time-start', '.time-end', '.time-break'].forEach(sel => {
                        tr.querySelector(sel)?.addEventListener('input', updateWeeklyTotal);
                        tr.querySelector(sel)?.addEventListener('blur', updateWeeklyTotal);
                      });
                      tr.querySelector('.time-end-next-day')?.addEventListener('change', updateWeeklyTotal);
                      // Inicial
                      validateRow(tr);
                    });
                    updateWeeklyTotal();

                    // Aplicar a todos (linha de cima)
                    q('#btnApplyAllTime', table)?.addEventListener('click', () => {
                      const s = q('#apply_start_time', table)?.value || '';
                      const e = q('#apply_end_time', table)?.value || '';
                      const b = Math.max(0, parseInt(q('#apply_break_min', table)?.value ?? '0', 10) || 0);

                      if (!s || !e) {
                        alert('Informe entrada e saída para aplicar.');
                        return;
                      }

                      qa('tbody tr[data-day]', table).forEach(tr => {
                        tr.querySelector('.time-start').value = s;
                        tr.querySelector('.time-end').value = e;
                        tr.querySelector('.time-break').value = b;
                      });
                      updateWeeklyTotal();
                    });

                    // Replicar de um dia para os demais
                    document.getElementById('btnReplicateTimeSchedule')?.addEventListener('click', () => {
                      const src = parseInt(document.getElementById('replicate_from_day')?.value || '1', 10);
                      const startEl = byName(src, 'start');
                      const endEl = byName(src, 'end');
                      const breakEl = byName(src, 'break');
                      const s = startEl?.value || '';
                      const e = endEl?.value || '';
                      const b = breakEl?.value || '';

                      if (!s || !e) {
                        alert('Preencha a entrada e a saída no dia de origem antes de replicar.');
                        (startEl && !s ? startEl : endEl)?.focus();
                        return;
                      }

                      weekdays.forEach(k => {
                        if (parseInt(k, 10) === src) return;
                        const startT = byName(k, 'start');
                        const endT = byName(k, 'end');
                        const breakT = byName(k, 'break');
                        if (startT) startT.value = s;
                        if (endT) endT.value = e;
                        if (breakT) breakT.value = b;
                      });
                      updateWeeklyTotal();
                    });

                    // Atalhos de preenchimento/limpeza
                    q('#btnTimeWorkdays', table)?.addEventListener('click', () => {
                      const s = q('#apply_start_time', table)?.value || '';
                      const e = q('#apply_end_time', table)?.value || '';
                      const b = Math.max(0, parseInt(q('#apply_break_min', table)?.value ?? '0', 10) || 0);
                      if (!s || !e) {
                        alert('Defina o padrão de Entrada/Saída acima para aplicar nos dias úteis.');
                        return;
                      }
                      qa('tbody tr[data-day]', table).forEach(tr => {
                        const day = parseInt(tr.getAttribute('data-day') || '-1', 10);
                        if (day >= 1 && day <= 5) {
                          tr.querySelector('.time-start').value = s;
                          tr.querySelector('.time-end').value = e;
                          tr.querySelector('.time-break').value = b;
                        }
                      });
                      updateWeeklyTotal();
                    });

                    q('#btnTimeWeekendOff', table)?.addEventListener('click', () => {
                      qa('tbody tr[data-day]', table).forEach(tr => {
                        const day = parseInt(tr.getAttribute('data-day') || '-1', 10);
                        if (day === 6 || day === 0) {
                          tr.querySelector('.time-start').value = '';
                          tr.querySelector('.time-end').value = '';
                          tr.querySelector('.time-break').value = '';
                        }
                      });
                      updateWeeklyTotal();
                    });

                    q('#btnTimeClearAll', table)?.addEventListener('click', () => {
                      qa('tbody tr[data-day]', table).forEach(tr => {
                        tr.querySelector('.time-start').value = '';
                        tr.querySelector('.time-end').value = '';
                        tr.querySelector('.time-break').value = '';
                      });
                      updateWeeklyTotal();
                    });
                  })();
                </script>
              </div>

              <div id="schedule_hours_block" class="mt-2" style="display:none;">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                  <h6 class="mb-0">Jornada por horas/dia</h6>
                  <span class="badge bg-light text-dark ms-1">defina o total de horas trabalhadas por dia (sem horário fixo)</span>

                  <div class="ms-auto small text-muted">
                    Total semanal: <strong id="weekly_hours_total_min">0</strong> min (<strong id="weekly_hours_total_h">00:00</strong>)
                  </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text" title="Preencher horas para todos">Preencher</span>
                    <input type="number" class="form-control" id="apply_hours_total" min="0" max="1440" step="15" inputmode="numeric" placeholder="min/dia" title="Total de minutos por dia">
                    <span class="input-group-text">int.</span>
                    <input type="number" class="form-control" id="apply_hours_break" min="0" max="600" step="5" inputmode="numeric" placeholder="min" title="Intervalo previsto (min)">
                    <button type="button" id="btnApplyAllHours" class="btn btn-outline-secondary">Aplicar</button>
                  </div>

                  <div class="vr mx-1 d-none d-md-block"></div>

                  <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary" id="btnHoursWorkdays" title="Preencher apenas Seg a Sex com o padrão de cima">Dias úteis</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnHoursWeekendOff" title="Zerar Sáb e Dom">Fds off</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnHoursClearAll" title="Limpar todos os dias">Limpar</button>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-bordered align-middle">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">Dia</th>
                        <th scope="col">Horas trabalhadas (min)</th>
                        <th scope="col">Intervalo previsto (min)</th>
                        <th scope="col">Equivale a</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($weekdays as $k => $dia):
                        $hs = $hoursSchedules[$k] ?? null;
                        $totalMin = isset($hs['total_minutes']) ? (int)$hs['total_minutes'] : 0;
                        $breakMin = isset($hs['break_minutes']) ? (int)$hs['break_minutes'] : 0;
                      ?>
                        <tr data-day="<?= (int)$k ?>">
                          <td class="fw-semibold"><?= esc($dia) ?></td>
                          <td><input type="number" class="form-control hours-total" name="hours_schedule[<?= $k ?>][total_minutes]" min="0" max="1440" step="15" inputmode="numeric" value="<?= (int)$totalMin ?>"></td>
                          <td><input type="number" class="form-control hours-break" name="hours_schedule[<?= $k ?>][break_minutes]" min="0" max="600" step="5" inputmode="numeric" value="<?= (int)$breakMin ?>"></td>
                          <td><span class="badge bg-light text-dark day-hours-label">0h 00min</span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="alert alert-info py-2 px-3">
                  Para motoristas, monitores e outros colaboradores cuja jornada é definida por <strong>total de horas/dia</strong> em vez de horário fixo. O sistema confere se a quantidade de horas trabalhadas no dia atinge a meta.
                </div>

                <script>
                  (function() {
                    const block = document.getElementById('schedule_hours_block');
                    if (!block) return;
                    const qa = (sel, root = document) => Array.from(root.querySelectorAll(sel));
                    const weeklyMinEl = document.getElementById('weekly_hours_total_min');
                    const weeklyHumanEl = document.getElementById('weekly_hours_total_h');

                    function minToHHMM(m) {
                      m = Math.max(0, m | 0);
                      const h = Math.floor(m / 60);
                      const mm = m % 60;
                      return String(h).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
                    }
                    function fmtHuman(m) {
                      m = Math.max(0, m | 0);
                      const h = Math.floor(m / 60);
                      const mm = m % 60;
                      if (h === 0) return mm + 'min';
                      if (mm === 0) return h + 'h';
                      return h + 'h ' + String(mm).padStart(2, '0') + 'min';
                    }

                    function updateRow(row) {
                      const total = parseInt(row.querySelector('.hours-total').value || '0', 10);
                      const brk   = parseInt(row.querySelector('.hours-break').value || '0', 10);
                      const liquid = Math.max(0, total - brk);
                      row.querySelector('.day-hours-label').textContent = fmtHuman(total) + (brk > 0 ? ' (líq. ' + fmtHuman(liquid) + ')' : '');
                    }
                    function updateWeekly() {
                      let total = 0;
                      qa('tbody tr[data-day]', block).forEach(tr => {
                        total += parseInt(tr.querySelector('.hours-total').value || '0', 10);
                        updateRow(tr);
                      });
                      weeklyMinEl.textContent = total;
                      weeklyHumanEl.textContent = minToHHMM(total);
                    }

                    qa('tbody tr[data-day]', block).forEach(tr => {
                      ['hours-total', 'hours-break'].forEach(cls => {
                        tr.querySelector('.' + cls).addEventListener('input', updateWeekly);
                      });
                    });

                    document.getElementById('btnApplyAllHours')?.addEventListener('click', () => {
                      const t = document.getElementById('apply_hours_total').value;
                      const b = document.getElementById('apply_hours_break').value;
                      qa('tbody tr[data-day]', block).forEach(tr => {
                        if (t !== '') tr.querySelector('.hours-total').value = t;
                        if (b !== '') tr.querySelector('.hours-break').value = b;
                      });
                      updateWeekly();
                    });
                    document.getElementById('btnHoursWorkdays')?.addEventListener('click', () => {
                      const t = document.getElementById('apply_hours_total').value;
                      const b = document.getElementById('apply_hours_break').value;
                      qa('tbody tr[data-day]', block).forEach(tr => {
                        const day = parseInt(tr.dataset.day, 10);
                        if (day >= 1 && day <= 5) {
                          if (t !== '') tr.querySelector('.hours-total').value = t;
                          if (b !== '') tr.querySelector('.hours-break').value = b;
                        }
                      });
                      updateWeekly();
                    });
                    document.getElementById('btnHoursWeekendOff')?.addEventListener('click', () => {
                      qa('tbody tr[data-day]', block).forEach(tr => {
                        const day = parseInt(tr.dataset.day, 10);
                        if (day === 0 || day === 6) {
                          tr.querySelector('.hours-total').value = 0;
                          tr.querySelector('.hours-break').value = 0;
                        }
                      });
                      updateWeekly();
                    });
                    document.getElementById('btnHoursClearAll')?.addEventListener('click', () => {
                      qa('tbody tr[data-day]', block).forEach(tr => {
                        tr.querySelector('.hours-total').value = 0;
                        tr.querySelector('.hours-break').value = 0;
                      });
                      updateWeekly();
                    });

                    updateWeekly();
                  })();
                </script>
              </div>
            </div>
          </section>

          <?php if (!empty($allPeriods)): ?>
          <!-- Grade Horária - Atribuição de Períodos (colapsável para reduzir densidade) -->
          <section class="app-section-card" id="period_assignments_card">
            <header class="app-section-card__header" role="button" tabindex="0" data-bs-toggle="collapse" data-bs-target="#period_assignments_body" aria-expanded="false" aria-controls="period_assignments_body" style="cursor:pointer;">
              <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-calendar-week"></i>Seção 4</span>
              <h2 class="app-section-card__title">Grade Horária — Períodos de Aula</h2>
              <span class="app-section-card__hint">
                <i class="bi bi-chevron-down collapse-icon"></i>
                <button type="button" class="btn btn-sm btn-outline-info ms-2" data-bs-toggle="collapse" data-bs-target="#periodHelpInfo" onclick="event.stopPropagation();" aria-label="Ajuda sobre grade horária">
                  <i class="bi bi-info-circle"></i><span class="d-none d-md-inline ms-1">Ajuda</span>
                </button>
              </span>
            </header>
            <div class="app-section-card__body collapse" id="period_assignments_body">
              <div class="collapse mb-3" id="periodHelpInfo">
                <div class="alert alert-info">
                  <h6><i class="bi bi-lightbulb"></i> Como funciona:</h6>
                  <ul class="mb-0">
                    <li><strong>Múltiplos check-ins:</strong> Professor pode fazer check-in/out para cada aula separadamente</li>
                    <li><strong>Evita horas extras indevidas:</strong> Janelas entre aulas não são contabilizadas como tempo trabalhado</li>
                    <li><strong>Pagamento fixo:</strong> Pagamento baseado no número de aulas cadastradas, não no tempo total registrado</li>
                    <li><strong>Compatível:</strong> Funciona junto com o sistema tradicional de "Nº de Aulas" acima</li>
                  </ul>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table table-bordered table-sm">
                  <thead class="table-light">
                    <tr>
                      <th scope="col" style="width: 120px;">Período</th>
                      <th scope="col" style="width: 120px;">Horário</th>
                      <?php foreach ($weekdays as $k => $dia): ?>
                        <th scope="col" class="text-center" style="width: 100px;">
                          <?= esc($dia) ?>
                          <br><small class="text-muted"><?= ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][$k] ?></small>
                        </th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($allPeriods as $period): ?>
                      <tr>
                        <td><strong><?= $period['period_number'] ?>º período</strong></td>
                        <td>
                          <small>
                            <?= date('H:i', strtotime($period['start_time'])) ?> - 
                            <?= date('H:i', strtotime($period['end_time'])) ?>
                          </small>
                        </td>
                        <?php foreach ($weekdays as $k => $dia): 
                          $isAssigned = isset($periodAssignments[$k][$period['id']]);
                        ?>
                          <td class="text-center">
                            <div class="form-check form-check-inline">
                              <input 
                                class="form-check-input period-checkbox" 
                                type="checkbox" 
                                name="period_assignments[<?= $k ?>][]" 
                                value="<?= $period['id'] ?>"
                                id="period_<?= $period['id'] ?>_day_<?= $k ?>"
                                <?= $isAssigned ? 'checked' : '' ?>>
                              <label class="form-check-label" for="period_<?= $period['id'] ?>_day_<?= $k ?>"></label>
                            </div>
                          </td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="alert alert-warning mt-3">
                <i class="bi bi-exclamation-triangle"></i> <strong>Importante:</strong>
                Se você usar este sistema de grade horária, o professor precisará fazer check-in/out para cada aula.
                O pagamento será sempre baseado no "Nº de Aulas" configurado acima, independente do tempo registrado.
              </div>

              <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnSelectAllPeriods">
                  <i class="bi bi-check-square"></i> Marcar Todos
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearAllPeriods">
                  <i class="bi bi-square"></i> Desmarcar Todos
                </button>
                <button type="button" class="btn btn-sm btn-outline-info" id="btnSelectWorkdays">
                  <i class="bi bi-calendar-check"></i> Apenas Dias Úteis (Seg-Sex)
                </button>
              </div>

              <script>
                (function() {
                  const btnSelectAll = document.getElementById('btnSelectAllPeriods');
                  const btnClearAll = document.getElementById('btnClearAllPeriods');
                  const btnWorkdays = document.getElementById('btnSelectWorkdays');
                  const checkboxes = document.querySelectorAll('.period-checkbox');

                  btnSelectAll?.addEventListener('click', () => {
                    checkboxes.forEach(cb => cb.checked = true);
                  });

                  btnClearAll?.addEventListener('click', () => {
                    checkboxes.forEach(cb => cb.checked = false);
                  });

                  btnWorkdays?.addEventListener('click', () => {
                    // Dias úteis: segunda (1) a sexta (5)
                    checkboxes.forEach(cb => {
                      const match = cb.name.match(/period_assignments\[(\d+)\]/);
                      if (match) {
                        const weekday = parseInt(match[1]);
                        cb.checked = (weekday >= 1 && weekday <= 5);
                      }
                    });
                  });
                })();
              </script>
            </div>
          </section>
          <?php endif; ?>

          <!-- Cadastro Facial -->
          <section class="app-section-card">
            <header class="app-section-card__header">
              <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-person-bounding-box"></i>Seção <?= !empty($allPeriods) ? '5' : '4' ?></span>
              <h2 class="app-section-card__title">Cadastro Facial</h2>
              <span class="app-section-card__hint">
                <span id="faceEnrollBadge" class="badge <?= !empty($teacher['face_descriptors']) ? 'text-bg-success-subtle border border-success-subtle text-success-emphasis' : 'text-bg-secondary-subtle border border-secondary-subtle text-secondary-emphasis' ?>">
                  <i class="bi <?= !empty($teacher['face_descriptors']) ? 'bi-check-circle' : 'bi-dash-circle' ?> me-1"></i>
                  <?= !empty($teacher['face_descriptors']) ? 'Rosto cadastrado' : 'Sem cadastro' ?>
                </span>
              </span>
            </header>
            <div class="app-section-card__body">
              <p class="text-muted small mb-3">Opcional: execute a validação biométrica guiada para gerar um cadastro facial. O colaborador também pode cadastrar no momento do registro do ponto.</p>
              <input type="hidden" name="face_descriptors" id="faceDescriptorsInput" value="">
              <input type="hidden" name="face_descriptors_clear" id="faceDescriptorsClear" value="0">

              <div id="faceEnrollArea">
                <div id="faceVideoWrap" style="display:none; position:relative; max-width:400px; margin:0 auto 16px; border-radius:12px; overflow:hidden; background:#000; aspect-ratio:4/3;">
                  <video id="faceVideo" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover;transform:scaleX(-1)"></video>
                  <div style="position:absolute;inset:0;display:grid;place-items:center;pointer-events:none;">
                    <div style="width:55%;aspect-ratio:3/4;border:2px dashed rgba(255,255,255,.5);border-radius:50%;"></div>
                  </div>
                </div>

                <div class="progress mb-2" style="height:8px;">
                  <div id="faceProgressBar" class="progress-bar bg-success" role="progressbar" style="width:0%"></div>
                </div>
                <div id="faceStepLabel" class="small text-muted text-center mb-2">Pronto para iniciar validação facial.</div>
                <div id="faceCapturedPreviews" class="d-flex flex-wrap gap-2 mb-3 justify-content-center"></div>

                <div class="d-flex flex-wrap gap-2 justify-content-center">
                  <button type="button" class="btn btn-outline-primary btn-sm" id="btnFaceStartCam">
                    <i class="bi bi-shield-check me-1"></i> Iniciar Reconhecimento
                  </button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="btnFaceRestart" style="display:none">
                    <i class="bi bi-arrow-clockwise me-1"></i> Reiniciar Processo
                  </button>
                  <button type="button" class="btn btn-outline-danger btn-sm" id="btnFaceClear" style="<?= !empty($teacher['face_descriptors']) ? '' : 'display:none' ?>">
                    <i class="bi bi-trash me-1"></i> Remover Cadastro
                  </button>
                </div>
                <div id="faceStatus" class="text-center mt-2 small text-muted"></div>
              </div>
            </div>
          </section>

          <div class="app-form-actions">
            <span class="app-form-actions__hint">
              <i class="bi bi-info-circle"></i>
              <?= $id ? 'Editando colaborador #' . (int)$id : 'Novo colaborador' ?> · Atalho: <kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>S</kbd>
            </span>
            <div class="app-form-actions__btns">
              <a href="teachers.php" class="btn btn-outline-secondary" title="Voltar (Alt+Shift+V)" accesskey="v">
                <i class="bi bi-arrow-left me-1"></i>Voltar
              </a>
              <button id="submitBtn" class="btn btn-success" type="submit" name="action" value="save" title="Salvar (Alt+Shift+S)" accesskey="s">
                <i class="bi bi-save me-1"></i>
                <span class="btn-text">Salvar Colaborador</span>
              </button>
            </div>
          </div>
        </form>

        <script>
          // Disable do botão Salvar imediatamente após o primeiro click — protege
          // contra duplo-click, refresh do form e BACK+save (junto com o token de
          // idempotência server-side).
          (function() {
            const form = document.getElementById('teacherForm');
            const btn = document.getElementById('submitBtn');
            if (!form || !btn) return;
            form.addEventListener('submit', () => {
              if (btn.disabled) return;
              setTimeout(() => {
                btn.disabled = true;
                const span = btn.querySelector('.btn-text');
                if (span) span.textContent = 'Salvando…';
                btn.querySelector('i.bi-save')?.classList.replace('bi-save', 'bi-hourglass-split');
              }, 0);
            });
          })();
        </script>

        <script>
          (function() {
            const vis = document.getElementById('cpf_display');
            const hid = document.getElementById('cpf');
            if (!vis || !hid) return;
            const onlyDigits = s => (s || '').replace(/\D/g, '').slice(0, 11);
            const maskCPF = d => {
              const n = onlyDigits(d);
              let out = '';
              if (n.length > 0) out = n.slice(0, 3);
              if (n.length > 3) out += '.' + n.slice(3, 6);
              if (n.length > 6) out += '.' + n.slice(6, 9);
              if (n.length > 9) out += '-' + n.slice(9, 11);
              return out;
            };

            function applyMaskAndSync() {
              const masked = maskCPF(vis.value);
              vis.value = masked;
              hid.value = masked;
              try {
                const end = masked.length;
                vis.setSelectionRange(end, end);
              } catch (_) {}
            }
            applyMaskAndSync();
            if (!vis.readOnly) {
              vis.addEventListener('input', applyMaskAndSync);
              vis.addEventListener('blur', applyMaskAndSync);
            }
          })();
        </script>
      </div>
    </div>
  </div>

  <script>
    function toggleScheduleBlocks() {
      const sel = document.getElementById('type_id');
      const mode = sel?.options[sel.selectedIndex]?.getAttribute('data-mode') || 'time';
      const classesBlock = document.getElementById('schedule_classes_block');
      const timeBlock = document.getElementById('schedule_time_block');
      const hoursBlock = document.getElementById('schedule_hours_block');
      const showClasses = mode === 'classes';
      const showTime = mode === 'time';
      const showHours = mode === 'hours';
      classesBlock.style.display = showClasses ? '' : 'none';
      timeBlock.style.display = showTime ? '' : 'none';
      if (hoursBlock) hoursBlock.style.display = showHours ? '' : 'none';
      classesBlock.querySelectorAll('input').forEach(el => el.disabled = !showClasses);
      timeBlock.querySelectorAll('input').forEach(el => el.disabled = !showTime);
      if (hoursBlock) hoursBlock.querySelectorAll('input').forEach(el => el.disabled = !showHours);
    }
    document.addEventListener('DOMContentLoaded', () => {
      document.getElementById('type_id')?.addEventListener('change', toggleScheduleBlocks);
      toggleScheduleBlocks();
      const submitBtn = document.getElementById('submitBtn');
      document.getElementById('teacherForm')?.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (submitBtn) {
          submitBtn.disabled = true;
          const textEl = submitBtn.querySelector('.btn-text');
          if (textEl) textEl.textContent = 'Salvando...';
        }
      });

      // Desabilita seleção de escolas se for "rede completa"
      const chk = document.getElementById('network_wide');
      const sel = document.getElementById('schools');
      const update = () => {
        const disabled = chk.checked;
        sel.disabled = disabled;
      };
      chk?.addEventListener('change', update);
      update();
    });
  </script>
<script src="<?= esc(rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')) ?>/js/face-api.min.js"></script>
<script>
(function() {
  const video = document.getElementById('faceVideo');
  const videoWrap = document.getElementById('faceVideoWrap');
  const btnStart = document.getElementById('btnFaceStartCam');
  const btnRestart = document.getElementById('btnFaceRestart');
  const btnClear = document.getElementById('btnFaceClear');
  const previews = document.getElementById('faceCapturedPreviews');
  const hiddenInput = document.getElementById('faceDescriptorsInput');
  const clearInput = document.getElementById('faceDescriptorsClear');
  const statusEl = document.getElementById('faceStatus');
  const badge = document.getElementById('faceEnrollBadge');
  const progressBar = document.getElementById('faceProgressBar');
  const stepLabel = document.getElementById('faceStepLabel');
  const form = document.getElementById('teacherForm');

  const hasStoredEnrollment = <?= !empty($teacher['face_descriptors']) ? 'true' : 'false' ?>;
  const requiredSteps = [
    { label: 'Olhe para frente com o rosto centralizado', check: ({yaw, pitch, coverage, centered}) => centered && coverage >= 0.08 && coverage <= 0.46 && Math.abs(yaw) <= 0.12 && pitch >= 0.16 && pitch <= 0.50 },
    { label: 'Vire levemente para a esquerda', check: ({yaw, coverage, centered}) => centered && coverage >= 0.08 && coverage <= 0.48 && yaw <= -0.08 },
    { label: 'Vire levemente para a direita', check: ({yaw, coverage, centered}) => centered && coverage >= 0.08 && coverage <= 0.48 && yaw >= 0.08 },
    { label: 'Mantenha o rosto centralizado por um instante', check: ({yaw, pitch, coverage, centered}) => centered && coverage >= 0.08 && coverage <= 0.48 && Math.abs(yaw) <= 0.16 && pitch >= 0.12 && pitch <= 0.58 },
    { label: 'Retorne ao centro e mantenha-se estável', check: ({yaw, pitch, coverage, centered}) => centered && coverage >= 0.08 && coverage <= 0.46 && Math.abs(yaw) <= 0.14 && pitch >= 0.14 && pitch <= 0.52 }
  ];

  let faceStream = null;
  let descriptors = [];
  let running = false;
  let modelsLoaded = false;
  let detecting = false;
  let tickTs = 0;
  let lastCaptureMs = 0;
  let stepIndex = 0;
  let lostFaceFrames = 0;

  function avgPoint(points) {
    const total = points.reduce((acc, p) => ({ x: acc.x + p.x, y: acc.y + p.y }), { x: 0, y: 0 });
    return { x: total.x / points.length, y: total.y / points.length };
  }

  function dist(a, b) {
    let sum = 0;
    const n = Math.min(a.length, b.length);
    for (let i = 0; i < n; i++) {
      const d = Number(a[i]) - Number(b[i]);
      sum += d * d;
    }
    return Math.sqrt(sum);
  }

  function isDescriptorDiverse(nextDescriptor) {
    if (!descriptors.length) return true;
    let minDistance = Number.POSITIVE_INFINITY;
    for (const descriptor of descriptors) {
      const d = dist(descriptor, nextDescriptor);
      if (d < minDistance) minDistance = d;
    }
    return minDistance >= 0.16;
  }

  function updateProgress() {
    const done = descriptors.length;
    const total = requiredSteps.length;
    const pct = Math.max(0, Math.min(100, (done / total) * 100));
    progressBar.style.width = pct + '%';
    stepLabel.textContent = done >= total
      ? 'Validação biométrica concluída.'
      : `Etapa ${done + 1} de ${total}: ${requiredSteps[done].label}`;
  }

  function resetEnrollmentState(clearSaved = false) {
    descriptors = [];
    stepIndex = 0;
    lastCaptureMs = 0;
    lostFaceFrames = 0;
    previews.innerHTML = '';
    hiddenInput.value = '';
    if (clearSaved) clearInput.value = '1';
    updateProgress();
  }

  function capturePreview() {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0);
    const thumb = document.createElement('img');
    thumb.src = canvas.toDataURL('image/jpeg', 0.72);
    thumb.style.cssText = 'width:68px;height:68px;object-fit:cover;border-radius:8px;border:2px solid #198754;';
    previews.appendChild(thumb);
  }

  function stopCamera() {
    running = false;
    detecting = false;
    if (faceStream) {
      faceStream.getTracks().forEach(t => t.stop());
      faceStream = null;
    }
    video.srcObject = null;
    videoWrap.style.display = 'none';
  }

  async function loadModels() {
    statusEl.textContent = 'Carregando modelos de reconhecimento facial...';
    try {
      const base = '<?= esc(rtrim(dirname(dirname($_SERVER["SCRIPT_NAME"])), "/")) ?>/models';
      await faceapi.nets.tinyFaceDetector.loadFromUri(base);
      await faceapi.nets.faceLandmark68TinyNet.loadFromUri(base);
      await faceapi.nets.faceRecognitionNet.loadFromUri(base);
      modelsLoaded = true;
      statusEl.textContent = 'Modelos carregados.';
    } catch (e) {
      statusEl.textContent = 'Erro ao carregar modelos: ' + e.message;
    }
  }

  async function startCamera() {
    if (!modelsLoaded) await loadModels();
    try {
      faceStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode:'user', width:{ideal:640}, height:{ideal:480} }, audio:false });
      video.srcObject = faceStream;
      await video.play();
      videoWrap.style.display = 'block';
      btnStart.style.display = 'none';
      btnRestart.style.display = '';
      statusEl.textContent = 'Validação em andamento.';
      running = true;
      if (!detecting) {
        detecting = true;
        requestAnimationFrame(detectionTick);
      }
    } catch (e) {
      statusEl.textContent = 'Erro ao abrir câmera: ' + e.message;
    }
  }

  async function detectionTick(ts) {
    if (!running || !detecting || !faceStream || !modelsLoaded) return;
    if (ts - tickTs < 450) {
      requestAnimationFrame(detectionTick);
      return;
    }
    tickTs = ts;

    if (descriptors.length >= requiredSteps.length) {
      stopCamera();
      requestAnimationFrame(detectionTick);
      return;
    }

    try {
      const detections = await faceapi
        .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.45 }))
        .withFaceLandmarks(true)
        .withFaceDescriptors();

      if (!detections.length) {
        lostFaceFrames++;
        if (lostFaceFrames >= 6) {
          statusEl.textContent = 'Rosto não detectado com clareza. Ajuste distância e iluminação.';
        } else {
          statusEl.textContent = requiredSteps[stepIndex].label;
        }
        requestAnimationFrame(detectionTick);
        return;
      }
      lostFaceFrames = 0;
      if (detections.length > 1) {
        statusEl.textContent = 'Foi detectado mais de um rosto. Mantenha apenas uma pessoa na câmera.';
        requestAnimationFrame(detectionTick);
        return;
      }

      const det = detections[0];
      const box = det.detection.box;
      const vw = video.videoWidth || 640;
      const vh = video.videoHeight || 480;
      const coverage = (box.width * box.height) / (vw * vh);
      const centerX = box.x + box.width / 2;
      const centerY = box.y + box.height / 2;
      const centered = Math.abs(centerX / vw - 0.5) <= 0.27 && Math.abs(centerY / vh - 0.5) <= 0.27;
      const leftEye = avgPoint(det.landmarks.getLeftEye());
      const rightEye = avgPoint(det.landmarks.getRightEye());
      const eyesMid = { x: (leftEye.x + rightEye.x) / 2, y: (leftEye.y + rightEye.y) / 2 };
      const nosePoints = det.landmarks.getNose();
      const nose = nosePoints[Math.min(3, nosePoints.length - 1)];
      const yaw = (nose.x - centerX) / box.width;
      const pitch = (nose.y - eyesMid.y) / box.height;
      const currentStep = requiredSteps[stepIndex];
      const isStepAligned = currentStep.check({ yaw, pitch, coverage, centered });
      const scoreOk = Number(det.detection.score || 0) >= 0.45;

      if (!isStepAligned || !scoreOk) {
        statusEl.textContent = currentStep.label;
        requestAnimationFrame(detectionTick);
        return;
      }

      const now = Date.now();
      if (now - lastCaptureMs < 900) {
        statusEl.textContent = 'Mantenha a posição por mais um instante...';
        requestAnimationFrame(detectionTick);
        return;
      }

      const nextDescriptor = Array.from(det.descriptor || []);
      if (nextDescriptor.length !== 128) {
        statusEl.textContent = 'Não foi possível extrair o descritor facial.';
        requestAnimationFrame(detectionTick);
        return;
      }

      if (!isDescriptorDiverse(nextDescriptor)) {
        statusEl.textContent = 'Amostra muito parecida. Ajuste levemente o ângulo e tente novamente.';
        requestAnimationFrame(detectionTick);
        return;
      }

      descriptors.push(nextDescriptor);
      stepIndex = descriptors.length;
      lastCaptureMs = now;
      capturePreview();
      hiddenInput.value = JSON.stringify(descriptors);
      clearInput.value = '0';
      btnClear.style.display = '';
      badge.className = 'badge bg-warning text-dark';
      badge.textContent = `${descriptors.length}/${requiredSteps.length} validado`;
      updateProgress();
      statusEl.textContent = descriptors.length >= requiredSteps.length
        ? 'Validação biométrica concluída. Salve para gravar.'
        : `Amostra ${descriptors.length}/${requiredSteps.length} validada.`;

      if (descriptors.length >= requiredSteps.length) {
        badge.className = 'badge bg-success';
        badge.textContent = 'Validação concluída';
        stopCamera();
      }
    } catch (e) {
      statusEl.textContent = 'Erro na detecção: ' + e.message;
    }
    requestAnimationFrame(detectionTick);
  }

  btnStart.addEventListener('click', async () => {
    resetEnrollmentState(false);
    await startCamera();
  });

  btnRestart.addEventListener('click', async () => {
    resetEnrollmentState(false);
    stopCamera();
    await startCamera();
  });

  btnClear.addEventListener('click', () => {
    resetEnrollmentState(true);
    badge.className = 'badge bg-secondary';
    badge.textContent = 'Sem cadastro';
    btnClear.style.display = 'none';
    statusEl.textContent = 'Cadastro facial removido. Salve para confirmar.';
    stopCamera();
  });

  form?.addEventListener('submit', () => {
    if (running) {
      stopCamera();
    }
  });

  window.addEventListener('beforeunload', stopCamera);
  updateProgress();
})();
</script>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($faceNotice && ($faceNotice['type'] ?? '') === 'face_duplicate_active'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const modalEl = document.getElementById('faceDuplicateModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;
  const modal = new bootstrap.Modal(modalEl);
  modal.show();
});
</script>
<?php endif; ?>

</html>









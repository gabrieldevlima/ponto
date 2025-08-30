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
  if (!$teacher) die('Colaborador não encontrado.');
} else {
  $teacher = null;
}

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
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title><?= $id ? 'Editar' : 'Cadastrar' ?> Colaborador(a) | DEEDO Ponto</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
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
          <li class="nav-item"><a class="nav-link active" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
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
  <div class="container-fluid mb-5">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between p-3 mb-4 rounded-3 shadow-sm">
          <div class="d-flex align-items-center gap-3">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:44px;height:44px;">
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
          <div class="d-flex align-items-center gap-2">
            <span class="badge <?= $id ? 'bg-info' : 'bg-success' ?>">
              <?= $id ? 'Edição' : 'Novo' ?>
            </span>
            <?php if ($id): ?>
              <span class="badge bg-secondary">ID #<?= (int)$id ?></span>
            <?php endif; ?>
          </div>
        </div>

        <?php
        if (session_status() !== PHP_SESSION_ACTIVE) {
          session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
          $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrf = $_SESSION['csrf_token'];
        ?>

        <form id="teacherForm" method="post" action="teachers_save.php" autocomplete="off" spellcheck="false">
          <input type="hidden" name="id" value="<?= esc($id) ?>">
          <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">

          <div class="card mb-4">
            <div class="card-header"><strong>Dados do colaborador(a)</strong></div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-lg-6">
                  <label class="form-label" for="name">Nome</label>
                  <input type="text" class="form-control" id="name" name="name" required maxlength="120" autocomplete="name" placeholder="Nome completo" autofocus value="<?= esc($teacher['name'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                  <label class="form-label" for="cpf_display">CPF</label>
                  <input type="text" class="form-control" id="cpf_display" name="_cpf_display" required maxlength="14" inputmode="numeric" placeholder="000.000.000-00" pattern="\d{3}\.\d{3}\.\d{3}-\d{2}" title="Formato: 000.000.000-00" value="<?= esc($teacher['cpf'] ?? '') ?>" <?= $id ? 'readonly' : '' ?>>
                  <input type="hidden" id="cpf" name="cpf" value="<?= esc($teacher['cpf'] ?? '') ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                  <label class="form-label" for="email">E-mail</label>
                  <input type="email" class="form-control" id="email" name="email" maxlength="120" autocomplete="email" placeholder="email@exemplo.com" value="<?= esc($teacher['email'] ?? '') ?>">
                </div>
              </div>

              <div class="row g-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label" for="type_id">Tipo</label>
                  <select class="form-select" name="type_id" id="type_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($types as $t): ?>
                      <option value="<?= (int)$t['id'] ?>" data-mode="<?= esc($t['schedule_mode']) ?>" <?= isset($teacher['type_id']) && (int)$teacher['type_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= esc($t['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <?php if (!$id): ?>
                  <div class="col-md-4">
                    <label class="form-label" for="pin">PIN (6 dígitos, único)</label>
                    <input type="password" class="form-control" id="pin" name="pin" required inputmode="numeric" autocomplete="new-password" minlength="6" maxlength="6" pattern="^\d{6}$" title="Informe exatamente 6 dígitos numéricos" placeholder="6 dígitos" oninput="this.value=this.value.replace(/\D/g,'').slice(0,6)">
                    <div class="form-text">Deve ser único e conter exatamente 6 dígitos. A unicidade será validada ao salvar.</div>
                  </div>
                <?php endif; ?>

                <div class="col-md-4">
                  <label class="form-label" for="base_salary">Salário base</label>
                  <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="number" step="0.01" min="0" class="form-control" id="base_salary" name="base_salary" value="<?= esc($teacher['base_salary'] ?? '0.00') ?>" required>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="card mb-4">
            <div class="card-header"><strong>Vinculação às escolas</strong></div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="schools">Escolas onde atua</label>
                  <select multiple size="6" class="form-select" id="schools" name="schools[]">
                    <?php foreach ($schools as $s): ?>
                      <option value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $teacherSchools, true) ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">Segure Ctrl (ou Cmd) para múltipla seleção.</div>
                </div>
                <div class="col-md-6">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="network_wide" name="network_wide" value="1" <?= (int)($teacher['network_wide'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="network_wide">Atua na rede de ensino completa (todas as escolas)</label>
                  </div>
                  <div class="text-muted">Marcado = não precisa selecionar escolas. Admin de escola não verá estes colaboradores.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="card mb-4">
            <div class="card-header"><strong>Rotina de trabalho</strong></div>
            <div class="card-body">
              <div id="schedule_classes_block" class="mt-2" style="display:none;">
                <h6 class="mb-2">Rotina por aulas</h6>
                <p class="text-muted mt-1 mb-3" style="font-size:0.98rem;">Defina a quantidade de aulas e a duração (em minutos) por dia da semana.</p>
                <div class="table-responsive">
                  <table class="table table-bordered align-middle">
                    <thead>
                      <tr>
                        <th>Dia</th>
                        <th>Nº de Aulas</th>
                        <th>Duração de Cada Aula (min)</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($weekdays as $k => $dia): ?>
                        <tr>
                          <td><?= esc($dia) ?></td>
                          <td><input type="number" class="form-control" name="schedule[<?= $k ?>][classes_count]" min="0" max="10" step="1" inputmode="numeric" value="<?= esc($schedules[$k]['classes_count'] ?? 0) ?>"></td>
                          <td><input type="number" class="form-control" name="schedule[<?= $k ?>][class_minutes]" min="0" max="300" step="5" inputmode="numeric" value="<?= esc($schedules[$k]['class_minutes'] ?? 60) ?>"></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div id="schedule_time_block" class="mt-2" style="display:none;">
                <h6 class="mb-2">Jornada por horário</h6>
                <p class="text-muted mt-1 mb-3" style="font-size:0.98rem;">Informe o horário de entrada e saída por dia da semana e, se houver, o tempo de intervalo (minutos).</p>

                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                  <label for="replicate_from_day" class="form-label m-0">Replicar rotina de</label>
                  <select id="replicate_from_day" class="form-select form-select-sm" style="width:auto;">
                    <?php foreach ($weekdays as $k => $dia): ?>
                      <option value="<?= (int)$k ?>" <?= (int)$k === 1 ? 'selected' : '' ?>><?= esc($dia) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" id="btnReplicateTimeSchedule" class="btn btn-outline-secondary btn-sm">
                    Replicar para os demais dias
                  </button>
                </div>

                <div class="table-responsive">
                  <table class="table table-bordered align-middle">
                    <thead>
                      <tr>
                        <th>Dia</th>
                        <th>Entrada</th>
                        <th>Saída</th>
                        <th>Intervalo (min)</th>
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
                      ?>
                        <tr>
                          <td><?= esc($dia) ?></td>
                          <td><input type="time" class="form-control" name="time_schedule[<?= $k ?>][start]" step="60" placeholder="hh:mm" value="<?= esc($start) ?>"></td>
                          <td><input type="time" class="form-control" name="time_schedule[<?= $k ?>][end]" step="60" placeholder="hh:mm" value="<?= esc($end) ?>"></td>
                          <td><input type="number" class="form-control" name="time_schedule[<?= $k ?>][break]" min="0" max="600" step="5" inputmode="numeric" value="<?= esc($break) ?>"></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="alert alert-info">Deixe em branco os dias em que o colaborador não trabalha.</div>

                <script>
                  (function() {
                    const weekdays = <?= json_encode(array_keys($weekdays)) ?>;
                    const byName = (k, field) => document.querySelector(`[name="time_schedule[${k}][${field}]"]`);
                    const getValues = (k) => ({
                      start: byName(k, 'start')?.value || '',
                      end: byName(k, 'end')?.value || '',
                      breakMin: byName(k, 'break')?.value || ''
                    });

                    document.getElementById('btnReplicateTimeSchedule')?.addEventListener('click', () => {
                      const src = parseInt(document.getElementById('replicate_from_day')?.value || '1', 10);
                      const vals = getValues(src);

                      if (!vals.start || !vals.end) {
                        alert('Preencha a entrada e a saída no dia de origem antes de replicar.');
                        byName(src, !vals.start ? 'start' : 'end')?.focus();
                        return;
                      }

                      weekdays.forEach(k => {
                        if (parseInt(k, 10) === src) return;
                        const startEl = byName(k, 'start');
                        const endEl = byName(k, 'end');
                        const breakEl = byName(k, 'break');
                        if (startEl) startEl.value = vals.start;
                        if (endEl) endEl.value = vals.end;
                        if (breakEl) breakEl.value = vals.breakMin;
                      });
                    });
                  })();
                </script>
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 mt-3">
            <button id="submitBtn" class="btn btn-success" type="submit" name="action" value="save" title="Salvar (Atalho: Alt+Shift+S)" accesskey="s">
              <i class="bi bi-save me-1" aria-hidden="true"></i>
              <span class="btn-text">Salvar Colaborador(a)</span>
            </button>
            <a href="teachers.php" class="btn btn-outline-secondary" title="Voltar para a lista (Atalho: Alt+Shift+V)" accesskey="v">
              <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
              Voltar
            </a>
          </div>
        </form>

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
      const mode = sel?.options[sel.selectedIndex]?.getAttribute('data-mode') || 'classes';
      const classesBlock = document.getElementById('schedule_classes_block');
      const timeBlock = document.getElementById('schedule_time_block');
      const showClasses = mode === 'classes';
      const showTime = mode === 'time';
      classesBlock.style.display = showClasses ? '' : 'none';
      timeBlock.style.display = showTime ? '' : 'none';
      classesBlock.querySelectorAll('input').forEach(el => el.disabled = !showClasses);
      timeBlock.querySelectorAll('input').forEach(el => el.disabled = !showTime);
    }
    document.addEventListener('DOMContentLoaded', () => {
      document.getElementById('type_id')?.addEventListener('change', toggleScheduleBlocks);
      toggleScheduleBlocks();
      const submitBtn = document.getElementById('submitBtn');
      document.getElementById('teacherForm')?.addEventListener('submit', () => {
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
</body>

</html>
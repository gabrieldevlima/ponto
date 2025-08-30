<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);

// Listas (respeitar escopo)
list($scopeSql, $scopeParams) = admin_scope_where('t');
$stT = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE t.active = 1 AND $scopeSql ORDER BY t.name");
$stT->execute($scopeParams);
$teachers = $stT->fetchAll(PDO::FETCH_ASSOC);

$reasons  = $pdo->query("SELECT id, name FROM manual_reasons WHERE active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Helpers
function resolve_admin_id(PDO $pdo): ?int
{
  if (!empty($_SESSION['admin_id'])) return (int)$_SESSION['admin_id'];
  if (!empty($_SESSION['admin_username'])) {
    $st = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
    $st->execute([$_SESSION['admin_username']]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
  }
  return null;
}
$toMin = static function (string $hhmm): int {
  [$h, $m] = array_map('intval', explode(':', $hhmm . ':'));
  return $h * 60 + $m;
};
$isWithin = static function (string $time, string $start, string $end) use ($toMin): bool {
  if ($start === '' || $end === '') return false;
  $t = $toMin($time);
  $a = $toMin($start);
  $b = $toMin($end);
  if ($a >= $b) return false; // não suporta virar dia
  return ($t >= $a && $t <= $b);
};

// Mensagens
$msg = $_GET['msg'] ?? '';
$errors = [];

// Form state (sticky)
$form = [
  'teacher_id'  => '',
  'date'        => date('Y-m-d'),
  'record_type' => 'in',
  'time_in'     => '',
  'time_out'    => '',
  'reason_id'   => '',
  'reason_text' => '',
  'approved'    => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  // Coleta e normaliza
  $form['teacher_id']  = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
  $form['date']        = $_POST['date'] ?? '';
  $form['record_type'] = $_POST['record_type'] ?? 'in';
  $form['time_in']     = $_POST['time_in'] ?? '';
  $form['time_out']    = $_POST['time_out'] ?? '';
  $form['reason_id']   = isset($_POST['reason_id']) ? (int)$_POST['reason_id'] : 0;
  $form['reason_text'] = trim($_POST['reason_text'] ?? '');
  $form['approved']    = isset($_POST['approved']) ? 1 : 0;

  $teacher_id  = $form['teacher_id'];
  $date        = $form['date'];
  $record_type = $form['record_type'];
  $time_in     = $form['time_in'];
  $time_out    = $form['time_out'];
  $reason_id   = $form['reason_id'];
  $reason_text = $form['reason_text'];
  $approved    = $form['approved'];
  $adminId     = resolve_admin_id($pdo);

  // Escopo: só permitir lançar para colaborador visível
  list($scopeSql, $scopeParams) = admin_scope_where('t');
  $chk = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id = ? AND $scopeSql");
  $chk->execute(array_merge([$teacher_id], $scopeParams));
  if (!$chk->fetchColumn()) {
    $errors[] = 'Você não tem permissão para lançar ponto para este colaborador.';
  }

  // Validações
  if ($teacher_id <= 0) $errors[] = 'Colaborador inválido.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Data inválida.';
  if (!in_array($record_type, ['in', 'out', 'both'], true)) $errors[] = 'Tipo de registro inválido.';
  if ($record_type === 'in'   && !preg_match('/^\d{2}:\d{2}$/', $time_in))  $errors[] = 'Hora de entrada inválida.';
  if ($record_type === 'out'  && !preg_match('/^\d{2}:\d{2}$/', $time_out)) $errors[] = 'Hora de saída inválida.';
  if ($record_type === 'both' && (!preg_match('/^\d{2}:\d{2}$/', $time_in) || !preg_match('/^\d{2}:\d{2}$/', $time_out))) {
    $errors[] = 'Horas inválidas.';
  }
  if ($reason_id <= 0) $errors[] = 'Selecione um motivo.';

  // Carrega motivo e valida "Outro"
  if (!$errors) {
    $st = $pdo->prepare("SELECT id, name FROM manual_reasons WHERE id = ? AND active = 1");
    $st->execute([$reason_id]);
    $reasonRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$reasonRow) {
      $errors[] = 'Motivo inválido.';
    } elseif (mb_stripos($reasonRow['name'], 'outro') !== false && $reason_text === '') {
      $errors[] = 'Descreva a justificativa no campo Texto da justificativa.';
    }
  }

  // Validação contra jornada (mantido)
  if (!$errors) {
    $st = $pdo->prepare("SELECT t.id, ct.schedule_mode, ct.requires_schedule FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
    $st->execute([$teacher_id]);
    $col = $st->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
      $errors[] = 'Colaborador não encontrado.';
    } else {
      $requires = (int)($col['requires_schedule'] ?? 1) === 1;
      $mode = $col['schedule_mode'] ?? 'classes';
      $weekday = (int)date('w', strtotime($date));

      if ($requires) {
        if ($mode === 'classes') {
          $st = $pdo->prepare("SELECT classes_count FROM teacher_schedules WHERE teacher_id = ? AND weekday = ?");
          $st->execute([$teacher_id, $weekday]);
          $schedule = $st->fetch(PDO::FETCH_ASSOC);
          if (!$schedule || (int)$schedule['classes_count'] <= 0) {
            $errors[] = 'Não há rotina prevista (aulas) para este colaborador neste dia.';
          }
        } elseif ($mode === 'time') {
          $st = $pdo->prepare("SELECT start_time, end_time FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
          $st->execute([$teacher_id, $weekday]);
          $ts = $st->fetch(PDO::FETCH_ASSOC);
          if (!$ts || empty($ts['start_time']) || empty($ts['end_time'])) {
            $errors[] = 'Não há jornada cadastrada para este dia.';
          } else {
            $start = substr($ts['start_time'], 0, 5);
            $end   = substr($ts['end_time'], 0, 5);
            if ($start >= $end) {
              $errors[] = 'Jornada que atravessa a meia-noite não é suportada nesta versão.';
            } else {
              if ($record_type === 'in' && !$isWithin($time_in, $start, $end)) {
                $errors[] = 'Hora de entrada fora da janela de trabalho do dia.';
              } elseif ($record_type === 'out' && !$isWithin($time_out, $start, $end)) {
                $errors[] = 'Hora de saída fora da janela de trabalho do dia.';
              } elseif ($record_type === 'both') {
                if (strtotime($date . ' ' . $time_out . ':00') <= strtotime($date . ' ' . $time_in . ':00')) {
                  $errors[] = 'Saída deve ser após a entrada.';
                }
                if (!$isWithin($time_in, $start, $end))  $errors[] = 'Hora de entrada fora da janela de trabalho do dia.';
                if (!$isWithin($time_out, $start, $end)) $errors[] = 'Hora de saída fora da janela de trabalho do dia.';
              }
            }
          }
        }
      }
    }
  }

  // Regras de consistência com registros existentes
  if (!$errors) {
    try {
      if ($record_type === 'in') {
        $st = $pdo->prepare("SELECT id FROM attendance WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL LIMIT 1");
        $st->execute([$teacher_id, $date]);
        if ($st->fetchColumn()) {
          $errors[] = 'Já existe uma entrada aberta para este dia.';
        }
        $check_in = $date . ' ' . $time_in . ':00';
        if (!$errors) {
          $st = $pdo->prepare("SELECT id FROM attendance WHERE teacher_id = ? AND check_in = ? LIMIT 1");
          $st->execute([$teacher_id, $check_in]);
          if ($st->fetchColumn()) $errors[] = 'Já existe um registro com essa hora de entrada.';
        }
        if (!$errors) {
          $pdo->beginTransaction();
          $stmt = $pdo->prepare("INSERT INTO attendance
            (teacher_id, date, check_in, method, approved, manual_reason_id, manual_reason_text, manual_by_admin_id, manual_created_at)
            VALUES (?, ?, ?, 'manual', ?, ?, ?, ?, NOW())");
          $stmt->execute([$teacher_id, $date, $check_in, $approved, $reason_id, $reason_text, $adminId]);
          $idInserted = (int)$pdo->lastInsertId();
          $pdo->commit();
          audit_log('create', 'attendance', $idInserted, ['type' => 'in', 'date' => $date, 'time' => $time_in, 'manual_reason_id' => $reason_id]);
          header('Location: attendance_manual.php?msg=' . urlencode('Entrada inserida com sucesso.'));
          exit;
        }
      } elseif ($record_type === 'out') {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT id, check_in FROM attendance
                             WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL
                             ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->execute([$teacher_id, $date]);
        $open = $st->fetch(PDO::FETCH_ASSOC);
        if (!$open) {
          $pdo->rollBack();
          $errors[] = 'Não há entrada aberta neste dia para fechar.';
        } else {
          $check_out = $date . ' ' . $time_out . ':00';
          if (strtotime($check_out) <= strtotime($open['check_in'])) {
            $pdo->rollBack();
            $errors[] = 'Saída deve ser após a entrada aberta.';
          } else {
            $stmt = $pdo->prepare("UPDATE attendance
              SET check_out = ?, method = 'manual', approved = ?, manual_reason_id = ?, manual_reason_text = ?, manual_by_admin_id = ?, manual_created_at = NOW()
              WHERE id = ?");
            $stmt->execute([$check_out, $approved, $reason_id, $reason_text, $adminId, (int)$open['id']]);
            $pdo->commit();
            audit_log('update', 'attendance', (int)$open['id'], ['type' => 'out', 'date' => $date, 'time' => $time_out, 'manual_reason_id' => $reason_id]);
            header('Location: attendance_manual.php?msg=' . urlencode('Saída inserida com sucesso.'));
            exit;
          }
        }
      } else { // both
        $check_in  = $date . ' ' . $time_in . ':00';
        $check_out = $date . ' ' . $time_out . ':00';
        if (strtotime($check_out) <= strtotime($check_in)) $errors[] = 'Saída deve ser após a entrada.';
        if (!$errors) {
          $st = $pdo->prepare("SELECT id FROM attendance WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND (check_out IS NULL OR check_out = '') LIMIT 1");
          $st->execute([$teacher_id, $date]);
          if ($st->fetchColumn()) $errors[] = 'Há uma entrada aberta neste dia. Feche-a antes de inserir entrada e saída juntas.';
        }
        if (!$errors) {
          $pdo->beginTransaction();
          $stmt = $pdo->prepare("INSERT INTO attendance
            (teacher_id, date, check_in, check_out, method, approved, manual_reason_id, manual_reason_text, manual_by_admin_id, manual_created_at)
            VALUES (?, ?, ?, ?, 'manual', ?, ?, ?, ?, NOW())");
          $stmt->execute([$teacher_id, $date, $check_in, $check_out, $approved, $reason_id, $reason_text, $adminId]);
          $insId = (int)$pdo->lastInsertId();
          $pdo->commit();
          audit_log('create', 'attendance', $insId, ['type' => 'both', 'date' => $date, 'time_in' => $time_in, 'time_out' => $time_out, 'manual_reason_id' => $reason_id]);
          header('Location: attendance_manual.php?msg=' . urlencode('Entrada e saída inseridas com sucesso.'));
          exit;
        }
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Erro ao salvar. Tente novamente.';
    }
  }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Inserir Ponto Manual | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
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
          <li class="nav-item"><a class="nav-link" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
          <?php if (is_network_admin($adm)): ?>
            <li class="nav-item"><a class="nav-link" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
            <li class="nav-item"><a class="nav-link" href="admins.php"><i class="bi bi-people"></i> Administradores</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link active" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <span class="navbar-text me-3 d-none d-lg-inline"><i class="bi bi-person-circle"></i> <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?></span>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>
  <div class="container-fluid mb-5">
    <div class="card rounded-3 border bg-body mb-4">
      <div class="card-body py-3 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
          <div class="bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width:3rem;height:3rem;">
            <i class="bi bi-clock-history fs-4"></i>
          </div>
          <div>
            <h3 class="mb-1">Inserir Ponto Manual</h3>
            <div class="text-muted small">Cadastre entrada, saída ou ambos com justificativa</div>
          </div>
        </div>
        <div class="text-end d-none d-md-block">
          <span class="badge bg-light text-secondary border rounded-pill px-3 py-2 fs-5">
            <i class="bi bi-calendar-event me-2"></i> <?= esc(date('d/m/Y')) ?>
          </span>
        </div>
      </div>
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
      <div class="card-body">
        <form method="post" class="row g-3" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">

          <div class="col-md-6">
            <label class="form-label">Colaborador</label>
            <select name="teacher_id" class="form-select" required>
              <option value="">Selecione</option>
              <?php foreach ($teachers as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= (string)$form['teacher_id'] === (string)$t['id'] ? 'selected' : '' ?>>
                  <?= esc($t['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Data</label>
            <input type="date" name="date" class="form-control" required value="<?= esc($form['date']) ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Tipo de Registro</label>
            <select name="record_type" id="record_type" class="form-select">
              <option value="in" <?= $form['record_type'] === 'in' ? 'selected' : '' ?>>Entrada</option>
              <option value="out" <?= $form['record_type'] === 'out' ? 'selected' : '' ?>>Saída</option>
              <option value="both" <?= $form['record_type'] === 'both' ? 'selected' : '' ?>>Entrada e Saída</option>
            </select>
          </div>

          <div class="col-md-3 time-in">
            <label class="form-label">Hora de Entrada</label>
            <input type="time" name="time_in" id="time_in" class="form-control" step="60" value="<?= esc($form['time_in']) ?>">
          </div>

          <div class="col-md-3 time-out">
            <label class="form-label">Hora de Saída</label>
            <input type="time" name="time_out" id="time_out" class="form-control" step="60" value="<?= esc($form['time_out']) ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">Motivo</label>
            <select name="reason_id" id="reason_id" class="form-select" required>
              <option value="">Selecione</option>
              <?php foreach ($reasons as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= (string)$form['reason_id'] === (string)$r['id'] ? 'selected' : '' ?>>
                  <?= esc($r['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-8" id="reason_text_block" style="display:none;">
            <label class="form-label">Texto da justificativa (obrigatório para "Outro")</label>
            <input type="text" name="reason_text" id="reason_text" class="form-control" maxlength="255" placeholder="Descreva o motivo" value="<?= esc($form['reason_text']) ?>">
          </div>

          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="approved" id="approved" <?= $form['approved'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="approved">Marcar como aprovado</label>
            </div>
          </div>

          <div class="col-12 d-flex gap-2 align-items-center">
            <button class="btn btn-success" type="submit" id="btnSave">
              <i class="bi bi-check2-circle me-1"></i>
              <span>Salvar Ponto</span>
              <span class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
            </button>
            <button class="btn btn-outline-secondary" type="reset" id="btnReset"><i class="bi bi-eraser me-1"></i>Limpar</button>
            <a href="attendances.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
          </div>

          <script>
            (function() {
              const form = document.currentScript.closest('form');
              const btnSave = form.querySelector('#btnSave');
              const spinner = btnSave.querySelector('.spinner-border');
              const btnReset = form.querySelector('#btnReset');

              form.addEventListener('submit', function() {
                btnSave.disabled = true;
                spinner.classList.remove('d-none');
              });
              btnReset.addEventListener('click', function(e) {
                if (!confirm('Limpar o formulário?')) e.preventDefault();
              });
              form.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                  e.preventDefault();
                  btnSave.click();
                }
              });
            })();
          </script>
        </form>
      </div>
    </div>
  </div>

  <script>
    const recordType = document.getElementById('record_type');
    const blockIn = document.querySelector('.time-in');
    const blockOut = document.querySelector('.time-out');
    const timeIn = document.getElementById('time_in');
    const timeOut = document.getElementById('time_out');

    function toggleTimeBlocks() {
      const v = recordType.value;
      const showIn = (v === 'in' || v === 'both');
      const showOut = (v === 'out' || v === 'both');
      blockIn.style.display = showIn ? '' : 'none';
      blockOut.style.display = showOut ? '' : 'none';
      if (showIn) timeIn.setAttribute('required', 'required');
      else timeIn.removeAttribute('required');
      if (showOut) timeOut.setAttribute('required', 'required');
      else timeOut.removeAttribute('required');
    }
    recordType.addEventListener('change', toggleTimeBlocks);
    toggleTimeBlocks();

    const reasonSelect = document.getElementById('reason_id');
    const reasonTextBlock = document.getElementById('reason_text_block');
    const reasonText = document.getElementById('reason_text');

    function toggleReasonText() {
      const opt = reasonSelect.options[reasonSelect.selectedIndex]?.text || '';
      const needsText = opt.toLowerCase().includes('outro');
      reasonTextBlock.style.display = needsText ? '' : 'none';
      if (needsText) reasonText.setAttribute('required', 'required');
      else reasonText.removeAttribute('required');
    }
    reasonSelect.addEventListener('change', toggleReasonText);
    toggleReasonText();
  </script>
</body>

</html>
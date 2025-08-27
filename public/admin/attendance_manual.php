<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

// Carregar colaboradores e motivos
$teachers = $pdo->query("SELECT id, name FROM teachers WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$reasons = $pdo->query("SELECT id, name FROM manual_reasons WHERE active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Mensagem
$msg = $_GET['msg'] ?? '';

// Resolve admin logado para auditoria (pode ser NULL se não identificado)
function resolve_admin_id(PDO $pdo): ?int {
  if (!empty($_SESSION['admin_id'])) {
    return (int)$_SESSION['admin_id'];
  }
  if (!empty($_SESSION['admin_username'])) {
    $st = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
    $st->execute([$_SESSION['admin_username']]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
  }
  return null; // deixa NULL para não violar FK
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $teacher_id = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
  $date = $_POST['date'] ?? '';
  $record_type = $_POST['record_type'] ?? 'in'; // in | out | both
  $time_in = $_POST['time_in'] ?? '';
  $time_out = $_POST['time_out'] ?? '';
  $reason_id = isset($_POST['reason_id']) ? (int)$_POST['reason_id'] : 0;
  $reason_text = trim($_POST['reason_text'] ?? '');
  $approved = 1; // padrão aprovado
  $adminId = resolve_admin_id($pdo); // pode ser null

  // validações básicas
  if ($teacher_id <= 0) die('Colaborador inválido.');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) die('Data inválida.');
  if (!in_array($record_type, ['in','out','both'], true)) die('Tipo de registro inválido.');
  if ($record_type === 'in' && !preg_match('/^\d{2}:\d{2}$/', $time_in)) die('Hora de entrada inválida.');
  if ($record_type === 'out' && !preg_match('/^\d{2}:\d{2}$/', $time_out)) die('Hora de saída inválida.');
  if ($record_type === 'both' && (!preg_match('/^\d{2}:\d{2}$/', $time_in) || !preg_match('/^\d{2}:\d{2}$/', $time_out))) die('Horas inválidas.');
  if ($reason_id <= 0) die('Selecione um motivo.');

  // Se for "Outro", exigir texto
  $reasonRow = null;
  foreach ($reasons as $r) if ((int)$r['id'] === $reason_id) { $reasonRow = $r; break; }
  if (!$reasonRow) die('Motivo inválido.');
  if (mb_stripos($reasonRow['name'], 'Outro') !== false && $reason_text === '') {
    die('Descreva a justificativa no campo Texto da justificativa.');
  }

  // Execução
  if ($record_type === 'in') {
    $check_in = $date . ' ' . $time_in . ':00';
    // verifica se já há entrada aberta
    $st = $pdo->prepare("SELECT id FROM attendance WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL LIMIT 1");
    $st->execute([$teacher_id, $date]);
    if ($st->fetchColumn()) die('Já existe uma entrada aberta para este dia.');
    $stmt = $pdo->prepare("INSERT INTO attendance
      (teacher_id, date, check_in, method, approved, manual_reason_id, manual_reason_text, manual_by_admin_id, manual_created_at)
      VALUES (?, ?, ?, 'manual', ?, ?, ?, ?, NOW())");
    $stmt->execute([$teacher_id, $date, $check_in, $approved, $reason_id, $reason_text, $adminId]);
    header('Location: attendance_manual.php?msg=' . urlencode('Entrada inserida com sucesso.'));
    exit;
  } elseif ($record_type === 'out') {
    $check_out = $date . ' ' . $time_out . ':00';
    // encontra última entrada aberta do dia
    $st = $pdo->prepare("SELECT id FROM attendance WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$teacher_id, $date]);
    $openId = (int)$st->fetchColumn();
    if (!$openId) die('Não há entrada aberta neste dia para fechar.');
    $stmt = $pdo->prepare("UPDATE attendance
      SET check_out = ?, method = 'manual', approved = ?, manual_reason_id = ?, manual_reason_text = ?, manual_by_admin_id = ?, manual_created_at = NOW()
      WHERE id = ?");
    $stmt->execute([$check_out, $approved, $reason_id, $reason_text, $adminId, $openId]);
    header('Location: attendance_manual.php?msg=' . urlencode('Saída inserida com sucesso.'));
    exit;
  } else { // both
    $check_in = $date . ' ' . $time_in . ':00';
    $check_out = $date . ' ' . $time_out . ':00';
    if (strtotime($check_out) <= strtotime($check_in)) die('Saída deve ser após a entrada.');
    $stmt = $pdo->prepare("INSERT INTO attendance
      (teacher_id, date, check_in, check_out, method, approved, manual_reason_id, manual_reason_text, manual_by_admin_id, manual_created_at)
      VALUES (?, ?, ?, ?, 'manual', ?, ?, ?, ?, NOW())");
    $stmt->execute([$teacher_id, $date, $check_in, $check_out, $approved, $reason_id, $reason_text, $adminId]);
    header('Location: attendance_manual.php?msg=' . urlencode('Entrada e saída inseridas com sucesso.'));
    exit;
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Inserir Ponto Manual</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
          <li class="nav-item"><a class="nav-link active" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <span class="navbar-text me-3 d-none d-lg-inline"><i class="bi bi-person-circle"></i> <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?></span>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>
<div class="container">
  <h3 class="mb-3">Inserir Ponto Manual</h3>
  <?php if ($msg): ?>
    <div class="alert alert-success"><?= esc($msg) ?></div>
  <?php endif; ?>
  <form method="post" class="row g-3" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
    <div class="col-md-6">
      <label class="form-label">Colaborador</label>
      <select name="teacher_id" class="form-select" required>
        <option value="">Selecione</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= (int)$t['id'] ?>"><?= esc($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Data</label>
      <input type="date" name="date" class="form-control" required value="<?= esc(date('Y-m-d')) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Tipo de Registro</label>
      <select name="record_type" id="record_type" class="form-select">
        <option value="in">Entrada</option>
        <option value="out">Saída</option>
        <option value="both">Entrada e Saída</option>
      </select>
    </div>
    <div class="col-md-3 time-in">
      <label class="form-label">Hora de Entrada</label>
      <input type="time" name="time_in" class="form-control">
    </div>
    <div class="col-md-3 time-out">
      <label class="form-label">Hora de Saída</label>
      <input type="time" name="time_out" class="form-control">
    </div>
    <div class="col-md-4">
      <label class="form-label">Motivo</label>
      <select name="reason_id" id="reason_id" class="form-select" required>
        <option value="">Selecione</option>
        <?php foreach ($reasons as $r): ?>
          <option value="<?= (int)$r['id'] ?>"><?= esc($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-8" id="reason_text_block" style="display:none;">
      <label class="form-label">Texto da justificativa (obrigatório para "Outro")</label>
      <input type="text" name="reason_text" class="form-control" maxlength="255" placeholder="Descreva o motivo">
    </div>
    <div class="col-12">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="approved" id="approved" checked>
        <label class="form-check-label" for="approved">Marcar como aprovado</label>
      </div>
    </div>
    <div class="col-12">
      <button class="btn btn-success" type="submit">Salvar</button>
      <a href="attendances.php" class="btn btn-secondary">Voltar</a>
    </div>
  </form>
</div>
<script>
  const recordType = document.getElementById('record_type');
  const blockIn = document.querySelector('.time-in');
  const blockOut = document.querySelector('.time-out');
  function toggleTimeBlocks() {
    const v = recordType.value;
    blockIn.style.display = (v === 'in' || v === 'both') ? '' : 'none';
    blockOut.style.display = (v === 'out' || v === 'both') ? '' : 'none';
  }
  recordType.addEventListener('change', toggleTimeBlocks);
  toggleTimeBlocks();

  const reasonSelect = document.getElementById('reason_id');
  const reasonTextBlock = document.getElementById('reason_text_block');
  reasonSelect.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex]?.text || '';
    if (opt.toLowerCase().includes('outro')) {
      reasonTextBlock.style.display = '';
    } else {
      reasonTextBlock.style.display = 'none';
    }
  });
</script>
</body>
</html>
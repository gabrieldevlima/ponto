<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->execute([$id]);
    $teacher = $stmt->fetch();
    if (!$teacher) die('Colaborador não encontrado.');
} else {
    $teacher = null;
}

// Tipos de colaborador
$types = $pdo->query("SELECT id, slug, name, schedule_mode FROM collaborator_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$typeMap = [];
foreach ($types as $t) { $typeMap[$t['id']] = $t; }
$selectedTypeId = (int)($teacher['type_id'] ?? 1);
$scheduleMode = (string)($typeMap[$selectedTypeId]['schedule_mode'] ?? 'classes');

$weekdays = [
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado',
    0 => 'Domingo',
];

// Carregar rotina semanal (genérica)
$schedules = [];
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM collaborator_schedules WHERE teacher_id = ?");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sch) {
        $schedules[(int)$sch['weekday']] = $sch;
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title><?= $id ? 'Editar' : 'Cadastrar' ?> Colaborador</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container">
<h3 class="mt-4 mb-4"><?= $id ? 'Editar' : 'Cadastrar' ?> Colaborador</h3>
<form method="post" action="teachers_save.php" autocomplete="off">
  <input type="hidden" name="id" value="<?= esc($id) ?>">

  <div class="mb-3">
    <label class="form-label">Tipo de Colaborador</label>
    <select name="type_id" class="form-select" required id="type_id">
      <?php foreach ($types as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $selectedTypeId === (int)$t['id'] ? 'selected' : '' ?>>
          <?= esc($t['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">Nome</label>
    <input type="text" class="form-control" name="name" required maxlength="120" value="<?= esc($teacher['name'] ?? '') ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">CPF</label>
    <input type="text" class="form-control" name="cpf" required maxlength="14" value="<?= esc($teacher['cpf'] ?? '') ?>" <?= $id ? 'readonly' : '' ?>>
  </div>
  <div class="mb-3">
    <label class="form-label">E-mail</label>
    <input type="email" class="form-control" name="email" maxlength="120" value="<?= esc($teacher['email'] ?? '') ?>">
  </div>
  <?php if (!$id): ?>
  <div class="mb-3">
    <label class="form-label">PIN (senha inicial)</label>
    <input type="text" class="form-control" name="pin" required maxlength="10" pattern="\d{6}" placeholder="6 dígitos">
  </div>
  <?php endif; ?>

  <!-- Rotina por aulas (professores) -->
  <div class="mb-4" id="schedule_classes" <?= $scheduleMode === 'classes' ? '' : 'style="display:none;"' ?>>
    <h5>Carga Horária Semanal (Aulas)</h5>
    <p class="text-muted mt-2 mb-2" style="font-size:0.98rem;">Informe nº de aulas e duração (min) por dia.</p>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead>
          <tr><th>Dia</th><th>Nº de Aulas</th><th>Duração de Cada Aula (min)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($weekdays as $k => $dia): ?>
          <tr>
            <td><?= esc($dia) ?></td>
            <td><input type="number" class="form-control" name="schedule_classes[<?= $k ?>][classes_count]" min="0" max="10" value="<?= esc($schedules[$k]['classes_count']??0) ?>"></td>
            <td><input type="number" class="form-control" name="schedule_classes[<?= $k ?>][class_minutes]" min="0" max="300" step="5" value="<?= esc($schedules[$k]['class_minutes']??60) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Rotina por horário (demais colaboradores) -->
  <div class="mb-4" id="schedule_time" <?= $scheduleMode === 'time' ? '' : 'style="display:none;"' ?>>
    <h5>Horário Semanal (Entrada/Saída)</h5>
    <p class="text-muted mt-2 mb-2" style="font-size:0.98rem;">Informe o horário de entrada e saída por dia. Opcionalmente, um intervalo (min).</p>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead>
          <tr><th>Dia</th><th>Entrada</th><th>Saída</th><th>Intervalo (min)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($weekdays as $k => $dia): ?>
          <?php
          $start = $schedules[$k]['start_time'] ?? '';
          $end = $schedules[$k]['end_time'] ?? '';
          $break = $schedules[$k]['break_minutes'] ?? 0;
          ?>
          <tr>
            <td><?= esc($dia) ?></td>
            <td><input type="time" class="form-control" name="schedule_time[<?= $k ?>][start_time]" value="<?= esc($start) ?>"></td>
            <td><input type="time" class="form-control" name="schedule_time[<?= $k ?>][end_time]" value="<?= esc($end) ?>"></td>
            <td><input type="number" class="form-control" name="schedule_time[<?= $k ?>][break_minutes]" min="0" max="300" step="5" value="<?= esc($break) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <button class="btn btn-success" type="submit">Salvar</button>
  <a href="teachers.php" class="btn btn-secondary">Voltar</a>
</form>
</div>

<script>
  const typeSelect = document.getElementById('type_id');
  const blockClasses = document.getElementById('schedule_classes');
  const blockTime = document.getElementById('schedule_time');
  const typeMeta = <?= json_encode($types, JSON_UNESCAPED_UNICODE) ?>;
  function toggleBlocks() {
    const t = typeMeta.find(x => String(x.id) === String(typeSelect.value));
    const mode = t ? String(t.schedule_mode) : 'none';
    if (mode === 'classes') {
      blockClasses?.removeAttribute('style');
      blockTime?.setAttribute('style','display:none;');
    } else if (mode === 'time') {
      blockTime?.removeAttribute('style');
      blockClasses?.setAttribute('style','display:none;');
    } else {
      blockTime?.setAttribute('style','display:none;');
      blockClasses?.setAttribute('style','display:none;');
    }
  }
  typeSelect?.addEventListener('change', toggleBlocks);
</script>
</body>
</html>
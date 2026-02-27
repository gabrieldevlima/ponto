<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

function dt_local_val(?string $dt): string
{
  if (!$dt) return '';
  $ts = strtotime($dt);
  if (!$ts) return '';
  return date('Y-m-d\TH:i', $ts);
}

function load_attendance_scoped(PDO $pdo, int $id)
{
  list($scopeSql, $scopeParams) = admin_scope_where('t');
  $sql = "
    SELECT a.*, t.name AS teacher_name, t.id AS teacher_id,
           ed.username AS edited_by_username
    FROM attendance a
    JOIN teachers t ON t.id = a.teacher_id
    LEFT JOIN admins ed ON ed.id = a.editado_por
    WHERE a.id = ? AND $scopeSql
  ";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([$id], $scopeParams));
  return $st->fetch(PDO::FETCH_ASSOC);
}

function worked_minutes(?string $in, ?string $out): int
{
  if (empty($in) || empty($out)) return 0;
  $ci = strtotime($in);
  $co = strtotime($out);
  if (!$ci || !$co || $co <= $ci) return 0;
  return (int) floor(($co - $ci) / 60);
}

$errors = [];
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('ID inválido.');
}

$att = load_attendance_scoped($pdo, $id);
if (!$att) {
  http_response_code(404);
  exit('Registro não encontrado ou sem permissão.');
}

// Removido: restrição que bloqueava edição para aprovados/rejeitados

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals((string)($_POST['csrf'] ?? ''), (string)csrf_token())) {
    http_response_code(400);
    exit('CSRF inválido.');
  }

  $new_date   = trim((string)($_POST['date'] ?? ''));
  $new_ci_raw = trim((string)$_POST['check_in'] ?? '');
  $new_co_raw = trim((string)$_POST['check_out'] ?? '');
  $new_method = trim((string)($_POST['method'] ?? ($att['method'] ?? '')));
  $reason     = trim((string)($_POST['reason'] ?? ''));

  if ($reason === '') $errors[] = 'A justificativa da edição é obrigatória.';

  $date_ok = true;
  if ($new_date !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
      $errors[] = 'Data inválida.';
      $date_ok = false;
    }
  }

  $ci = null;
  $co = null;
  if ($new_ci_raw !== '') {
    $ci = DateTime::createFromFormat('Y-m-d\TH:i', $new_ci_raw) ?: DateTime::createFromFormat('Y-m-d H:i', $new_ci_raw);
    if (!$ci) $errors[] = 'Data/hora de entrada inválida.';
  }
  if ($new_co_raw !== '') {
    $co = DateTime::createFromFormat('Y-m-d\TH:i', $new_co_raw) ?: DateTime::createFromFormat('Y-m-d H:i', $new_co_raw);
    if (!$co) $errors[] = 'Data/hora de saída inválida.';
  }
  if ($ci && $co && $co <= $ci) $errors[] = 'A saída deve ser posterior à entrada.';

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // Recarrega para evitar TOCTOU
      $before = load_attendance_scoped($pdo, $id);
      if (!$before) throw new RuntimeException('Registro não encontrado ao salvar.');

      $beforeWorked = worked_minutes($before['check_in'], $before['check_out']);

      // Monta atualização
      $fields = [];
      $params = [];

      // Data (dia do registro)
      $changedFields = [];
      if ($new_date !== '' && $date_ok && $new_date !== (string)$before['date']) {
        $fields[] = "date = ?";
        $params[] = $new_date;
        $changedFields[] = 'date';
      }

      // Entrada
      if ($new_ci_raw !== '') {
        $val = $ci->format('Y-m-d H:i:s');
        if ((string)$before['check_in'] !== $val) {
          $fields[] = "check_in = ?";
          $params[] = $val;
          $changedFields[] = 'check_in';
        }
      } else {
        if (!empty($before['check_in'])) {
          $fields[] = "check_in = NULL";
          $changedFields[] = 'check_in';
        }
      }

      // Saída
      if ($new_co_raw !== '') {
        $val = $co->format('Y-m-d H:i:s');
        if ((string)$before['check_out'] !== $val) {
          $fields[] = "check_out = ?";
          $params[] = $val;
          $changedFields[] = 'check_out';
        }
      } else {
        if (!empty($before['check_out'])) {
          $fields[] = "check_out = NULL";
          $changedFields[] = 'check_out';
        }
      }

      // Método
      if ($new_method !== '' && (string)$before['method'] !== $new_method) {
        $fields[] = "method = ?";
        $params[] = $new_method;
        $changedFields[] = 'method';
      }

      // Se nada mudou além do motivo, bloqueia
      if (!$fields) {
        throw new RuntimeException('Nenhuma alteração detectada.');
      }

      // Campos de rastreabilidade
      $now = (new DateTime())->format('Y-m-d H:i:s');
      $fields[] = "editado_por = ?";
      $params[] = (int)$admin['id'];
      $fields[] = "data_edicao = ?";
      $params[] = $now;
      $fields[] = "motivo_edicao = ?";
      $params[] = $reason;

      // Monta “after” virtual
      $after = $before;
      foreach ($fields as $i => $f) {
        $col = trim(strtok($f, '=')); // "check_in", "check_out", "date", "method", etc.
        $col = trim($col);
        if ($col === 'check_in') {
          $after['check_in'] = ($new_ci_raw !== '') ? $ci->format('Y-m-d H:i:s') : null;
        } elseif ($col === 'check_out') {
          $after['check_out'] = ($new_co_raw !== '') ? $co->format('Y-m-d H:i:s') : null;
        } elseif ($col === 'date') {
          $after['date'] = $new_date;
        } elseif ($col === 'method') {
          $after['method'] = $new_method;
        }
      }

      $afterWorked = worked_minutes($after['check_in'] ?? null, $after['check_out'] ?? null);
      $deltaMin = $afterWorked - $beforeWorked;

      // Tipo da edição
      $dateChanged = in_array('date', $changedFields, true);
      $timeChanged = in_array('check_in', $changedFields, true) || in_array('check_out', $changedFields, true);

      $tipo = null;
      if ($dateChanged && $timeChanged) {
        $tipo = 'change_date_and_times';
      } elseif ($dateChanged) {
        $tipo = 'change_date';
      } elseif ($timeChanged) {
        if ($deltaMin > 0) $tipo = 'add_hours';
        elseif ($deltaMin < 0) $tipo = 'remove_hours';
        else $tipo = 'adjust_times';
      } elseif (in_array('method', $changedFields, true)) {
        $tipo = 'change_method';
      } else {
        $tipo = 'other';
      }

      // UPDATE (removida a trava approved IS NULL)
      $params_for_update = $params;
      $params_for_update[] = $id;

      $sqlUp = "UPDATE attendance SET " . implode(', ', $fields) . " WHERE id = ?";
      $stUp = $pdo->prepare($sqlUp);
      $stUp->execute($params_for_update);
      if ($stUp->rowCount() < 1) {
        throw new RuntimeException('Falha ao salvar. O registro pode ter sido alterado por outro processo.');
      }

      // Recarrega após update
      $stAfter = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
      $stAfter->execute([$id]);
      $afterRow = $stAfter->fetch(PDO::FETCH_ASSOC);

      // Histórico detalhado
      $stHist = $pdo->prepare("
        INSERT INTO attendance_edits (attendance_id, edited_by, edited_at, reason, type, diff_minutes, changed_fields, before_json, after_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $stHist->execute([
        $id,
        (int)$admin['id'],
        $now,
        $reason,
        $tipo,
        $deltaMin,
        json_encode($changedFields, JSON_UNESCAPED_UNICODE),
        json_encode([
          'date' => $before['date'],
          'check_in' => $before['check_in'],
          'check_out' => $before['check_out'],
          'method'   => $before['method'],
          'worked_min' => $beforeWorked,
        ], JSON_UNESCAPED_UNICODE),
        json_encode([
          'date' => $afterRow['date'],
          'check_in' => $afterRow['check_in'],
          'check_out' => $afterRow['check_out'],
          'method'   => $afterRow['method'],
          'worked_min' => $afterWorked,
        ], JSON_UNESCAPED_UNICODE),
      ]);

      // Auditoria geral
      $stAudit = $pdo->prepare("
        INSERT INTO audit_logs (admin_id, action, entity, entity_id, payload, ip)
        VALUES (?, 'update', 'attendance', ?, ?, ?)
      ");
      $stAudit->execute([
        (int)$admin['id'],
        (string)$id,
        json_encode([
          'reason' => $reason,
          'type' => $tipo,
          'diff_minutes' => $deltaMin,
          'changed_fields' => $changedFields,
        ], JSON_UNESCAPED_UNICODE),
        $_SERVER['REMOTE_ADDR'] ?? null,
      ]);

      $pdo->commit();
      header('Location: attendances.php?msg=' . urlencode('Registro atualizado com sucesso.'));
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Falha ao salvar: ' . $e->getMessage();
    }
  }
}

$val_date      = (string)($att['date'] ?? '');
$val_check_in  = dt_local_val($att['check_in'] ?? null);
$val_check_out = dt_local_val($att['check_out'] ?? null);
$val_method    = (string)($att['method'] ?? '');
$val_reason    = isset($_POST['reason']) ? (string)$_POST['reason'] : '';

?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Editar Registro de Ponto | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
        <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:160px;">
      </a>
      <div class="ms-auto">
        <a href="attendances.php" class="btn btn-outline-light">Registros</a>
      </div>
    </div>
  </nav>

  <div class="container" style="max-width: 820px;">
    <div class="card">
      <div class="card-body">
        <h4 class="mb-3">Editar Registro de Ponto</h4>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <ul class="mb-0">
              <?php foreach ($errors as $e): ?>
                <li><?= esc($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="mb-3">
          <div><strong>Colaborador:</strong> <?= esc($att['teacher_name']) ?></div>
          <div><strong>Data atual:</strong> <?= esc($att['date']) ?></div>
          <?php if (!empty($att['data_edicao'])): ?>
            <div class="text-muted small">
              Última edição em <?= esc(date('d/m/Y H:i', strtotime($att['data_edicao']))) ?>
              por <?= esc($att['edited_by_username'] ?? ('#' . (string)$att['editado_por'])) ?>.
              Motivo: <?= esc($att['motivo_edicao'] ?? '-') ?>.
            </div>
          <?php endif; ?>
          <?php
          $statusLbl = ($att['approved'] === null) ? 'Pendente' : ((int)$att['approved'] === 1 ? 'Aprovado' : 'Rejeitado');
          $statusClass = ($att['approved'] === null) ? 'warning' : ((int)$att['approved'] === 1 ? 'success' : 'danger');
          ?>
          <div class="mt-2">
            <span class="badge text-bg-<?= esc($statusClass) ?>">Status: <?= esc($statusLbl) ?></span>
          </div>
        </div>

        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Dia do registro</label>
              <input type="date" name="date" class="form-control" value="<?= esc($val_date) ?>">
              <div class="form-text">Altere o dia (AAAA-MM-DD) se necessário.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Entrada</label>
              <input type="datetime-local" name="check_in" class="form-control" value="<?= esc($val_check_in) ?>">
              <div class="form-text">Deixe em branco para remover.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Saída</label>
              <input type="datetime-local" name="check_out" class="form-control" value="<?= esc($val_check_out) ?>">
              <div class="form-text">Deixe em branco para remover.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Método</label>
              <select name="method" class="form-select">
                <?php
                $methods = ['pin' => 'PIN', 'foto' => 'Foto', 'manual' => 'Manual'];
                $current = strtolower((string)$val_method);
                foreach ($methods as $k => $lbl):
                ?>
                  <option value="<?= esc($k) ?>" <?= $current === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Justificativa da edição <span class="text-danger">*</span></label>
              <textarea name="reason" class="form-control" rows="3" required placeholder="Descreva o motivo da alteração..."><?= esc($val_reason) ?></textarea>
            </div>
          </div>

          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save"></i> Salvar Alterações
            </button>
            <a href="attendances.php" class="btn btn-outline-secondary">Cancelar</a>
          </div>
        </form>

      </div>
    </div>

    <div class="text-muted small mt-3">
      Todas as alterações são registradas com data, responsável, motivo, campos alterados, tipo de edição e delta de minutos. Histórico detalhado disponível em attendance_edits.
    </div>
  </div>
</body>

</html>
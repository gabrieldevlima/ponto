<?php
require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$admin = current_admin($pdo);

function redirect_with_msg(string $msg, string $fallback = 'attendances.php'): void {
  $target = isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] ? $_SERVER['HTTP_REFERER'] : $fallback;
  // Anexa msg preservando querystring
  $sep = (strpos($target, '?') === false) ? '?' : '&';
  header('Location: ' . $target . $sep . 'msg=' . urlencode($msg));
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método não permitido';
    exit;
  }

  // CSRF
  try {
    csrf_verify(); // verifica header X-CSRF-Token ou POST/GET conforme helper
  } catch (Throwable $e) {
    // fallback para tokens via campo 'csrf'
    $posted = $_POST['csrf'] ?? '';
    if (!$posted || $posted !== csrf_token()) {
      http_response_code(403);
      echo 'CSRF inválido';
      exit;
    }
  }

  $attendanceId = isset($_POST['attendance_id']) ? (int)$_POST['attendance_id'] : 0;
  $act = isset($_POST['act']) ? trim((string)$_POST['act']) : '';

  if ($attendanceId <= 0 || !in_array($act, ['approve','reject'], true)) {
    http_response_code(400);
    echo 'Parâmetros inválidos.';
    exit;
  }

  // Verifica escopo do admin sobre o registro (via teacher)
  list($scopeSql, $scopeParams) = admin_scope_where('t');
  $st = $pdo->prepare("
    SELECT a.id, a.approved, a.teacher_id, t.name AS teacher_name
    FROM attendance a
    JOIN teachers t ON t.id = a.teacher_id
    WHERE a.id = ? AND $scopeSql
    LIMIT 1
  ");
  $ok = $st->execute(array_merge([$attendanceId], $scopeParams));
  $row = $ok ? $st->fetch(PDO::FETCH_ASSOC) : null;

  if (!$row) {
    http_response_code(403);
    echo 'Sem permissão para alterar este registro ou registro inexistente.';
    exit;
  }

  $newApproved = ($act === 'approve') ? 1 : 0;

  // Atualiza status de aprovação
  $upd = $pdo->prepare("UPDATE attendance SET approved = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
  $upd->execute([$newApproved, $attendanceId]);

  // Auditoria
  audit_log('update', 'attendance', $attendanceId, [
    'approved' => $newApproved,
    'by_admin_id' => $admin['id'] ?? null,
    'by_admin_name' => $admin['name'] ?? null,
  ]);

  $msg = $act === 'approve' ? 'Registro aprovado com sucesso.' : 'Registro rejeitado com sucesso.';
  redirect_with_msg($msg);
} catch (Throwable $e) {
  // Log opcional: error_log($e->getMessage());
  http_response_code(500);
  echo 'Falha ao processar solicitação.';
  exit;
}
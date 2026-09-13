<?php
require_once __DIR__ . '/../../config.php';
require_admin();

$pdo = db();
$admin = current_admin($pdo);

function redirect_with_msg(string $msg, string $fallback = 'attendances.php'): void {
  $target = isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] ? $_SERVER['HTTP_REFERER'] : $fallback;
  // HOTFIX 2026-09: só volta para o Referer se for do próprio host (evita redirect
  // externo) e remove msg= anterior (acumulava &msg=...&msg=... a cada ação).
  $refHost = parse_url($target, PHP_URL_HOST);
  if ($refHost !== null && $refHost !== ($_SERVER['HTTP_HOST'] ?? '')) {
    $target = $fallback;
  }
  $target = preg_replace('/([?&])msg=[^&]*(&|$)/', '$1', $target);
  $target = rtrim($target, '?&');
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

  if ($attendanceId <= 0 || !in_array($act, ['approve','reject','remove','restore'], true)) {
    http_response_code(400);
    echo 'Parâmetros inválidos.';
    exit;
  }

  // Remoção / restauração (anulação auditável). Restrito a ADMIN DE REDE.
  if ($act === 'remove' || $act === 'restore') {
    if (!is_network_admin($admin)) {
      http_response_code(403);
      echo 'Apenas administradores de rede podem remover registros de ponto.';
      exit;
    }
    $reason = trim((string)($_POST['reason'] ?? ''));
    try {
      if ($act === 'remove') {
        $res = admin_remove_attendance($pdo, $attendanceId, (int)$admin['id'], $reason);
        $n = count($res['removed'] ?? []);
        redirect_with_msg($n > 0 ? ($n > 1 ? "Registro removido (incluindo $n itens do turno)." : 'Registro removido.') : 'Nada a remover (já estava removido).');
      } else {
        $res = admin_restore_attendance($pdo, $attendanceId, (int)$admin['id'], $reason);
        $n = count($res['restored'] ?? []);
        redirect_with_msg($n > 0 ? 'Registro restaurado.' : 'Nada a restaurar.');
      }
    } catch (RuntimeException $e) {
      redirect_with_msg($e->getMessage());
    } catch (Throwable $e) {
      error_log('[attendances_action remove/restore] ' . $e->getMessage());
      redirect_with_msg('Falha ao processar a remoção.');
    }
    exit;
  }

  // Verifica escopo do admin sobre o registro (via teacher)
  list($scopeSql, $scopeParams) = admin_scope_where('t');
  $st = $pdo->prepare("
    SELECT a.id, a.approved, a.teacher_id, a.removed_at, a.superseded_by_id, t.name AS teacher_name
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

  // HOTFIX 2026-09: registro anulado não pode ser aprovado/rejeitado (ficava
  // approved=1 com removed_at preenchido e voltava a pesar no banco de horas).
  if (!empty($row['removed_at']) || !empty($row['superseded_by_id'])) {
    redirect_with_msg('Este registro foi anulado e não pode ser aprovado ou rejeitado. Restaure-o antes, se necessário.');
  }

  $newApproved = ($act === 'approve') ? 1 : 0;
  $oldApproved = $row['approved']; // null|0|1

  // Atualiza status de aprovação
  $upd = $pdo->prepare("UPDATE attendance SET approved = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
  $upd->execute([$newApproved, $attendanceId]);

  // Recalcula o banco de horas do dia — o lançamento auto foi gravado no checkout
  // com o estado da época (ponto pendente → worked=0 → débito da jornada inteira).
  recalculate_hour_bank_for_attendance($pdo, $attendanceId);

  // Auditoria Portaria 671/2021 — grava em attendance_audit_log (tela admin)
  log_attendance_audit(
    $pdo, $attendanceId, (int)$admin['id'],
    $act === 'approve' ? 'APPROVE' : 'REJECT',
    'approved',
    $oldApproved,
    $newApproved,
    $_POST['reason'] ?? null
  );

  // Se rejeitando, auto-rejeita solicitações de hora extra pendentes
  if ($act === 'reject') {
    $rejected = auto_reject_overtime_on_attendance_rejection($pdo, $attendanceId, (int)$admin['id']);
    if ($rejected > 0) {
      audit_log('info', 'overtime_request', null, [
        'message' => "Auto-rejeitado $rejected solicitações de hora extra devido à rejeição do ponto",
        'attendance_id' => $attendanceId
      ]);
    }
  }

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
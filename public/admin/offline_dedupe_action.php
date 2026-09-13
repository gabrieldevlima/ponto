<?php
/**
 * SANEAMENTO DE PONTOS OFFLINE — Endpoint POST
 *
 * Atende 3 ações:
 *   - act=apply      : aplica decisão a UM grupo (keeper + superseded_ids)
 *   - act=preview    : devolve em JSON o que seria feito (não modifica)
 *   - act=apply_all  : executa "Aplicar recomendação" em todos os grupos da janela
 */

require_once __DIR__ . '/../../config.php';
require_admin();

if (!has_permission('attendance.dedupe')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Sem permissão.']);
    exit;
}

header('Content-Type: application/json');

try {
    csrf_verify();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF inválido.']);
    exit;
}

$pdo = db();
$admin = current_admin($pdo);
$adminId = (int)($admin['id'] ?? 0);
if ($adminId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sessão expirada.']);
    exit;
}

$act    = $_POST['act'] ?? '';
$reason = trim((string)($_POST['reason'] ?? 'Saneamento de pontos offline duplicados'));

try {
    if ($act === 'apply') {
        $keeperId = (int)($_POST['keeper_id'] ?? 0);
        $supersededIds = array_values(array_filter(array_map(
            'intval',
            explode(',', (string)($_POST['superseded_ids'] ?? ''))
        )));
        if ($keeperId <= 0 || empty($supersededIds)) {
            throw new RuntimeException('Parâmetros inválidos.');
        }
        $res = apply_dedupe_decision($pdo, $keeperId, $supersededIds, $adminId, $reason);
        echo json_encode(['status' => 'ok', 'result' => $res]);
        exit;
    }

    if ($act === 'preview') {
        $from = $_POST['from'] !== '' ? $_POST['from'] : null;
        $to   = $_POST['to']   !== '' ? $_POST['to']   : null;
        $schoolId = (isset($_POST['school']) && $_POST['school'] !== '') ? (int)$_POST['school'] : null;
        $groups = find_duplicate_groups($pdo, $from, $to, $schoolId);
        $totalRecords = array_sum(array_map('count', $groups));
        $summary = [
            'group_count'       => count($groups),
            'total_records'     => $totalRecords,
            'would_keep'        => count($groups),
            'would_soft_delete' => max(0, $totalRecords - count($groups)),
            'groups' => array_values(array_map(static function ($g) {
                return [
                    'teacher_id'     => (int)$g[0]['teacher_id'],
                    'teacher_name'   => (string)$g[0]['teacher_name'],
                    'date'           => (string)$g[0]['date'],
                    'action'         => (string)$g[0]['_action'],
                    'keeper_id'      => (int)$g[0]['id'],
                    'superseded_ids' => array_map('intval', array_column(array_slice($g, 1), 'id')),
                ];
            }, $groups)),
        ];
        echo json_encode(['status' => 'ok', 'preview' => $summary]);
        exit;
    }

    if ($act === 'apply_all') {
        $from = $_POST['from'] !== '' ? $_POST['from'] : null;
        $to   = $_POST['to']   !== '' ? $_POST['to']   : null;
        $schoolId = (isset($_POST['school']) && $_POST['school'] !== '') ? (int)$_POST['school'] : null;
        $groups = find_duplicate_groups($pdo, $from, $to, $schoolId);
        $applied = [];
        $errors = [];
        foreach ($groups as $g) {
            $keeperId = (int)$g[0]['id'];
            $supersededIds = array_map('intval', array_column(array_slice($g, 1), 'id'));
            if (!$supersededIds) continue;
            try {
                $res = apply_dedupe_decision($pdo, $keeperId, $supersededIds, $adminId, $reason);
                $applied[] = $res;
            } catch (Throwable $e) {
                $errors[] = ['group_keeper' => $keeperId, 'error' => $e->getMessage()];
            }
        }
        echo json_encode([
            'status'        => 'ok',
            'applied_count' => count($applied),
            'errors'        => $errors,
        ]);
        exit;
    }

    throw new RuntimeException('Ação inválida.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

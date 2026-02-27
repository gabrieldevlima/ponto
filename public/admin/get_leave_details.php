<?php
/**
 * API para retornar detalhes de um afastamento
 * Usado pelo modal de detalhes em leaves.php
 */

require_once __DIR__ . '/../../config.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$leaveId = (int)($_GET['id'] ?? 0);

if ($leaveId <= 0) {
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

// Verifica escopo do admin
list($scopeSql, $scopeParams) = admin_scope_where('t');

$sql = "SELECT l.*, t.name as teacher_name, t.cpf as teacher_cpf, 
               lt.name as type_name, lt.paid, lt.affects_bank,
               s.name as school_name,
               a.username as created_by_name
        FROM leaves l
        JOIN teachers t ON t.id = l.teacher_id
        JOIN leave_types lt ON lt.id = l.type_id
        LEFT JOIN schools s ON s.id = l.school_id
        LEFT JOIN admins a ON a.id = l.created_by_admin_id
        WHERE l.id = ? AND $scopeSql";

$st = $pdo->prepare($sql);
$st->execute(array_merge([$leaveId], $scopeParams));
$leave = $st->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    echo json_encode(['error' => 'Afastamento não encontrado ou sem permissão']);
    exit;
}

// Formata datas
try {
    $startDate = new DateTime($leave['start_date']);
    $endDate = new DateTime($leave['end_date']);
    $leave['start_date_fmt'] = $startDate->format('d/m/Y');
    $leave['end_date_fmt'] = $endDate->format('d/m/Y');
} catch (Throwable $e) {
    $leave['start_date_fmt'] = $leave['start_date'];
    $leave['end_date_fmt'] = $leave['end_date'];
}

// Badge de aprovação
if ($leave['approved'] === null) {
    $leave['approved_badge'] = '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>';
} elseif ((int)$leave['approved'] === 1) {
    $leave['approved_badge'] = '<span class="badge bg-success"><i class="bi bi-check2 me-1"></i>Aprovado</span>';
} else {
    $leave['approved_badge'] = '<span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Rejeitado</span>';
}

// Limpa dados sensíveis
unset($leave['teacher_cpf']);

echo json_encode($leave, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


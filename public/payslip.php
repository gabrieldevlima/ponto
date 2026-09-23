<?php
/**
 * Visualização de holerite em PDF
 * Acesso: colaboradores (próprios holerites) e admins (todos)
 */

require_once __DIR__ . '/../config.php';

$pdo = db();
$payslipId = (int)($_GET['id'] ?? 0);

if ($payslipId <= 0) {
    http_response_code(400);
    exit('ID inválido');
}

// Verifica autenticação
$isAdmin = isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0;
$isCollaborator = isset($_SESSION['collaborator_id']) && $_SESSION['collaborator_id'] > 0;

if (!$isAdmin && !$isCollaborator) {
    http_response_code(403);
    echo '<h3>Acesso negado</h3>';
    echo '<p>Faça login para visualizar o holerite:</p>';
    echo '<ul>';
    echo '<li><a href="my_login.php">Portal do Colaborador</a></li>';
    echo '<li><a href="admin/login.php">Portal Administrativo</a></li>';
    echo '</ul>';
    exit;
}

// Busca holerite
$sql = "SELECT p.*, t.name as teacher_name, t.cpf as teacher_cpf
        FROM payslips p
        JOIN teachers t ON t.id = p.teacher_id
        WHERE p.id = ?";
$st = $pdo->prepare($sql);
$st->execute([$payslipId]);
$payslip = $st->fetch(PDO::FETCH_ASSOC);

if (!$payslip) {
    http_response_code(404);
    exit('Holerite não encontrado');
}

// Controle de acesso
// NC-46 (2026-08-05): não havia checagem alguma para admin — um school_admin
// lia o holerite (salário) de qualquer colaborador da rede iterando ?id=.
if ($isAdmin) {
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $stScope = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id = ? AND {$scopeSql} LIMIT 1");
    $stScope->execute(array_merge([(int)$payslip['teacher_id']], $scopeParams));
    if (!$stScope->fetchColumn()) {
        http_response_code(403);
        exit('Acesso negado. Este colaborador está fora do seu escopo.');
    }
} elseif ($isCollaborator) {
    $collaboratorId = (int)$_SESSION['collaborator_id'];
    if ((int)$payslip['teacher_id'] !== $collaboratorId) {
        http_response_code(403);
        exit('Você só pode visualizar seus próprios holerites');
    }

    // Marca como visualizado
    if (!$payslip['viewed_by_teacher_at']) {
        $pdo->prepare("UPDATE payslips SET viewed_by_teacher_at = NOW() WHERE id = ?")->execute([$payslipId]);
    }
}

// Busca itens adicionais (se existirem)
$stItems = $pdo->prepare("SELECT * FROM payslip_items WHERE payslip_id = ? ORDER BY type, id");
$stItems->execute([$payslipId]);
$items = $stItems->fetchAll(PDO::FETCH_ASSOC);

$teacher = [
    'name' => $payslip['teacher_name'],
    'cpf' => $payslip['teacher_cpf']
];

// Gera PDF
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    exit('Biblioteca PDF não instalada. Execute: composer require dompdf/dompdf');
}

require_once $autoload;

ob_start();
include __DIR__ . '/admin/_tpl_payslip_pdf.php';
$html = ob_get_clean();

$dompdf = new \Dompdf\Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'holerite_' . preg_replace('/[^a-z0-9]/i', '_', $teacher['name']) . '_' . date('Y-m', strtotime($payslip['reference_month'])) . '.pdf';

$dompdf->stream($filename, ['Attachment' => false]);
exit;


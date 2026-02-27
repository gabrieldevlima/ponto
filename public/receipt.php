<?php
/**
 * COMPROVANTE DE REGISTRO DE PONTO - PORTARIA MTP 671/2021
 * 
 * Template para geração de comprovante individual de marcação de ponto
 * conforme exigências da Portaria MTP nº 671, de 8 de novembro de 2021
 * 
 * ACESSO: Colaboradores via portal my_login.php ou Administradores
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Verificar autenticação (sessão já foi iniciada em config.php)
$isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$isCollaborator = isset($_SESSION['collaborator_id']) && $_SESSION['collaborator_id'] > 0;

if (!$isAdmin && !$isCollaborator) {
    http_response_code(403);
    echo '<h3>Acesso negado</h3>';
    echo '<p>Faça login para visualizar o comprovante:</p>';
    echo '<ul>';
    echo '<li><a href="my_login.php">Portal do Colaborador</a></li>';
    echo '<li><a href="admin/login.php">Portal Administrativo</a></li>';
    echo '</ul>';
    exit;
}

// Obter ID do registro
$attendanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attendanceId <= 0) {
    http_response_code(400);
    die('ID de registro inválido.');
}

$pdo = db();

// Buscar dados do registro usando a view
$stmt = $pdo->prepare("
    SELECT * FROM v_attendance_receipts 
    WHERE id = ?
");
$stmt->execute([$attendanceId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    die('Registro não encontrado.');
}

// Verificar permissão (admin pode ver tudo, colaborador só seus próprios registros)
if ($isCollaborator && !$isAdmin) {
    $collaboratorId = $_SESSION['collaborator_id'] ?? 0;
    if ((int)$record['teacher_id'] !== $collaboratorId) {
        http_response_code(403);
        die('Acesso negado. Você só pode visualizar seus próprios comprovantes.');
    }
}

// Marcar como visualizado (apenas para colaboradores, não para admin)
if ($isCollaborator && !$isAdmin) {
    $pdo->prepare("UPDATE attendance SET receipt_viewed_at = NOW() WHERE id = ? AND receipt_viewed_at IS NULL")
        ->execute([$attendanceId]);
}

// Marcar como gerado
$pdo->prepare("UPDATE attendance SET receipt_generated = TRUE WHERE id = ?")
    ->execute([$attendanceId]);

// Formatar dados
$teacherName = $record['teacher_name'] ?? 'Nome não disponível';
$teacherCpf = $record['teacher_cpf'] ?? '';
$nsr = $record['nsr'] ?? 'N/A';
$date = $record['date'] ?? '';
$checkIn = $record['check_in'] ?? null;
$checkOut = $record['check_out'] ?? null;
$action = $record['action'] ?? 'indefinido';
$recordMode = $record['record_mode'] ?? 'online';
$recordedAt = $record['recorded_at'] ?? null;
$syncedAt = $record['synced_at'] ?? null;
$hlbStatus = $record['hlb_sync_status'] ?? 'N/A';
$latitude = $record['latitude'] ?? null;
$longitude = $record['longitude'] ?? null;
$approved = $record['approved'];
$deviceId = $record['device_identifier'] ?? 'N/A';

// Dados do empregador
$companyName = $record['company_name'] ?? 'NOME DA EMPRESA LTDA';
$cnpj = $record['cnpj'] ?? '00.000.000/0000-00';
$systemName = $record['system_name'] ?? 'DEEDO Ponto';
$systemVersion = $record['system_version'] ?? '1.0.0';
$repCategory = $record['rep_category'] ?? 'REP-P';

// Formatar datas
$dateBR = $date ? date('d/m/Y', strtotime($date)) : 'N/A';
$timeIn = $checkIn ? date('H:i:s', strtotime($checkIn)) : 'N/A';
$timeOut = $checkOut ? date('H:i:s', strtotime($checkOut)) : 'N/A';
$recordedAtBR = $recordedAt ? date('d/m/Y H:i:s', strtotime($recordedAt)) : 'N/A';
$syncedAtBR = $syncedAt ? date('d/m/Y H:i:s', strtotime($syncedAt)) : 'Não sincronizado';

// Status de aprovação
$approvalStatus = 'Pendente de aprovação';
$approvalClass = 'warning';
if ($approved === 1) {
    $approvalStatus = 'Aprovado';
    $approvalClass = 'success';
} elseif ($approved === 0) {
    $approvalStatus = 'Rejeitado';
    $approvalClass = 'danger';
}

// Localização
$locationStr = 'Não disponível';
if ($latitude && $longitude) {
    $locationStr = sprintf('Lat: %.6f, Lng: %.6f', $latitude, $longitude);
}

// Logo em base64 para Dompdf
$logoPath = __DIR__ . '/img/logo_prefeitura.png';
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

// Gerar HTML do comprovante
$html = '
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Comprovante de Registro de Ponto - NSR ' . htmlspecialchars($nsr) . '</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #0d6efd;
            font-size: 20pt;
            margin: 0 0 5px 0;
        }
        .header .subtitle {
            font-size: 10pt;
            color: #666;
            font-weight: bold;
        }
        .section {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #0d6efd;
        }
        .section-title {
            font-size: 12pt;
            font-weight: bold;
            color: #0d6efd;
            margin: 0 0 10px 0;
            text-transform: uppercase;
        }
        .field {
            margin-bottom: 8px;
            display: flex;
        }
        .field-label {
            font-weight: bold;
            width: 180px;
            flex-shrink: 0;
        }
        .field-value {
            flex: 1;
        }
        .highlight {
            background: #fff3cd;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
        }
        .nsr-box {
            text-align: center;
            background: #0d6efd;
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .nsr-box .label {
            font-size: 10pt;
            margin-bottom: 5px;
        }
        .nsr-box .value {
            font-size: 24pt;
            font-weight: bold;
            font-family: "Courier New", monospace;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            font-size: 9pt;
            color: #666;
            text-align: center;
        }
        .compliance-box {
            background: #e7f3ff;
            border: 1px solid #0d6efd;
            padding: 10px;
            margin-top: 15px;
            font-size: 9pt;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 10pt;
        }
        .status-success { background: #d4edda; color: #155724; }
        .status-warning { background: #fff3cd; color: #856404; }
        .status-danger { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="header">
        <h1>COMPROVANTE DE REGISTRO DE PONTO</h1>
        <div class="subtitle">Portaria MTP nº 671/2021 - REP-P</div>
    </div>

    <div class="nsr-box">
        <div class="label">NSR - NÚMERO SEQUENCIAL DE REGISTRO</div>
        <div class="value">' . htmlspecialchars($nsr) . '</div>
    </div>

    <div class="section">
        <div class="section-title">DADOS DO EMPREGADOR</div>
        <div class="field">
            <div class="field-label">Razão Social:</div>
            <div class="field-value">' . htmlspecialchars($companyName) . '</div>
        </div>
        <div class="field">
            <div class="field-label">CNPJ:</div>
            <div class="field-value">' . htmlspecialchars($cnpj) . '</div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">DADOS DO COLABORADOR</div>
        <div class="field">
            <div class="field-label">Nome Completo:</div>
            <div class="field-value">' . htmlspecialchars($teacherName) . '</div>
        </div>
        <div class="field">
            <div class="field-label">CPF:</div>
            <div class="field-value">' . htmlspecialchars($teacherCpf) . '</div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">DADOS DA MARCAÇÃO</div>
        <div class="field">
            <div class="field-label">Data:</div>
            <div class="field-value"><span class="highlight">' . htmlspecialchars($dateBR) . '</span></div>
        </div>
        <div class="field">
            <div class="field-label">Tipo de Marcação:</div>
            <div class="field-value"><span class="highlight">' . strtoupper(htmlspecialchars($action)) . '</span></div>
        </div>
        <div class="field">
            <div class="field-label">Horário de Entrada:</div>
            <div class="field-value">' . htmlspecialchars($timeIn) . '</div>
        </div>
        <div class="field">
            <div class="field-label">Horário de Saída:</div>
            <div class="field-value">' . htmlspecialchars($timeOut) . '</div>
        </div>
        <div class="field">
            <div class="field-label">Timestamp Preciso:</div>
            <div class="field-value">' . htmlspecialchars($recordedAtBR) . '</div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">DADOS TÉCNICOS</div>
        <div class="field">
            <div class="field-label">Modo de Registro:</div>
            <div class="field-value">' . htmlspecialchars(ucfirst($recordMode)) . '</div>
        </div>
        <div class="field">
            <div class="field-label">Sincronizado em:</div>
            <div class="field-value">' . htmlspecialchars($syncedAtBR) . '</div>
        </div>
        <div class="field">
            <div class="field-label">Status HLB:</div>
            <div class="field-value">' . htmlspecialchars($hlbStatus) . '</div>
        </div>
        <div class="field">
            <div class="field-label">Localização:</div>
            <div class="field-value">' . htmlspecialchars($locationStr) . '</div>
        </div>
        <div class="field">
            <div class="field-label">Dispositivo (ID):</div>
            <div class="field-value" style="font-size: 8pt; font-family: monospace;">' . htmlspecialchars($deviceId) . '</div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">STATUS DE APROVAÇÃO</div>
        <div class="field">
            <div class="field-label">Status:</div>
            <div class="field-value">
                <span class="status-badge status-' . $approvalClass . '">' . $approvalStatus . '</span>
            </div>
        </div>
    </div>

    <div class="compliance-box">
        <strong>Conformidade Legal:</strong><br>
        Este comprovante foi gerado em conformidade com a <strong>Portaria MTP nº 671, de 8 de novembro de 2021</strong>,
        que regulamenta o Registro Eletrônico de Ponto (REP) no Brasil.<br><br>
        <strong>Sistema:</strong> ' . htmlspecialchars($systemName) . ' v' . htmlspecialchars($systemVersion) . '<br>
        <strong>Categoria:</strong> ' . htmlspecialchars($repCategory) . ' (Registrador Eletrônico de Ponto via Programa)<br>
        <strong>Base Legal:</strong> Art. 74 da CLT, Portaria MTP 671/2021, Lei 13.709/2018 (LGPD)
    </div>

    <div class="footer">
        <div style="text-align: center; margin-bottom: 15px; padding-top: 10px; border-top: 1px solid #ddd;">
            ' . ($logoBase64 ? '<img src="' . $logoBase64 . '" alt="Prefeitura" style="height: 90px; width: auto;">' : '') . '
        </div>
        <p><strong>Autenticidade:</strong> Este documento é autêntico e foi gerado automaticamente pelo sistema.</p>
        <p><strong>NSR:</strong> ' . htmlspecialchars($nsr) . ' | <strong>Data de Emissão:</strong> ' . date('d/m/Y H:i:s') . '</p>
        <p style="margin-top: 10px; font-size: 8pt; color: #999;">
            Este comprovante deve ser mantido pelo colaborador conforme legislação trabalhista vigente.<br>
            Em caso de dúvidas, contate o RH ou departamento pessoal da empresa.
        </p>
        <p style="margin-top: 8px; font-size: 8pt; color: #666; text-align: center;">
            Prefeitura Municipal de Ribeira do Piauí - PI<br>
            DEEDO Sistemas - Sistema de Ponto Eletrônico
        </p>
    </div>
</body>
</html>
';

// Configurar Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo
$filename = sprintf('Comprovante_NSR_%s_%s.pdf', $nsr, date('Ymd_His'));

// Enviar para navegador
$dompdf->stream($filename, ['Attachment' => false]); // false = abrir no navegador, true = download


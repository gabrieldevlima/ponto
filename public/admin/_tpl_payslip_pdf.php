<?php
// Template PDF para holerite (payslip)
// Variáveis esperadas: $payslip, $teacher, $items (opcional)

if (!isset($payslip) || !isset($teacher)) {
    exit('Dados insuficientes para gerar holerite');
}

function money($val) {
    return 'R$ ' . number_format((float)$val, 2, ',', '.');
}

function mins_to_time($mins) {
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return sprintf('%02dh %02dm', $h, $m);
}

$refMonth = DateTime::createFromFormat('Y-m-d', $payslip['reference_month']);
$monthName = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
][(int)$refMonth->format('n')];
$year = $refMonth->format('Y');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Holerite - <?= esc($teacher['name']) ?> - <?= $monthName ?>/<?= $year ?></title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #222;
            margin: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 20px;
            margin: 0 0 5px 0;
            color: #0d6efd;
        }
        .header .subtitle {
            font-size: 12px;
            color: #666;
        }
        .info-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .info-box .label {
            font-weight: bold;
            color: #495057;
        }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            color: #fff;
            background: #0d6efd;
            padding: 8px 10px;
            margin: 15px 0 10px 0;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        table th, table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
        }
        table th {
            background: #e9ecef;
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .text-success {
            color: #198754;
        }
        .text-danger {
            color: #dc3545;
        }
        .total-row {
            background: #f8f9fa;
            font-weight: bold;
            font-size: 12px;
        }
        .net-total-row {
            background: #d1e7dd;
            font-weight: bold;
            font-size: 14px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 9px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>HOLERITE</h1>
        <div class="subtitle"><?= $monthName ?> de <?= $year ?></div>
    </div>

    <div class="info-box">
        <div><span class="label">Colaborador:</span> <?= esc($teacher['name']) ?></div>
        <div><span class="label">CPF:</span> <?= esc($teacher['cpf']) ?></div>
        <div><span class="label">Referência:</span> <?= $monthName ?>/<?= $year ?></div>
        <div><span class="label">Gerado em:</span> <?= date('d/m/Y H:i', strtotime($payslip['generated_at'])) ?></div>
    </div>

    <div class="section-title">PROVENTOS</div>
    <table>
        <thead>
            <tr>
                <th>Descrição</th>
                <th style="width: 120px;" class="text-right">Valor</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Salário Base</td>
                <td class="text-right"><?= money($payslip['base_salary']) ?></td>
            </tr>
            <?php if ($payslip['overtime_minutes'] > 0): ?>
            <tr>
                <td>
                    Horas Extras (<?= mins_to_time((int)$payslip['overtime_minutes']) ?>)
                    <div style="font-size: 9px; color: #6c757d;">Adicional de 50%</div>
                </td>
                <td class="text-right text-success"><?= money($payslip['overtime_value']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if (isset($items)): ?>
                <?php foreach ($items as $item): ?>
                    <?php if ($item['type'] === 'earning'): ?>
                    <tr>
                        <td><?= esc($item['description']) ?></td>
                        <td class="text-right text-success"><?= money($item['value']) ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr class="total-row">
                <td>TOTAL DE PROVENTOS</td>
                <td class="text-right"><?= money($payslip['gross_total']) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">DESCONTOS</div>
    <table>
        <thead>
            <tr>
                <th>Descrição</th>
                <th style="width: 120px;" class="text-right">Valor</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($payslip['deficit_minutes'] > 0): ?>
            <tr>
                <td>
                    Déficit de Horas (<?= mins_to_time((int)$payslip['deficit_minutes']) ?>)
                    <div style="font-size: 9px; color: #6c757d;">Desconto proporcional</div>
                </td>
                <td class="text-right text-danger"><?= money($payslip['discount_value']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if (isset($items)): ?>
                <?php foreach ($items as $item): ?>
                    <?php if ($item['type'] === 'deduction'): ?>
                    <tr>
                        <td><?= esc($item['description']) ?></td>
                        <td class="text-right text-danger"><?= money($item['value']) ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($payslip['deficit_minutes'] == 0 && (!isset($items) || empty(array_filter($items, fn($i) => $i['type'] === 'deduction')))): ?>
            <tr>
                <td colspan="2" class="text-center text-muted">Nenhum desconto</td>
            </tr>
            <?php endif; ?>
            <tr class="total-row">
                <td>TOTAL DE DESCONTOS</td>
                <td class="text-right"><?= money($payslip['discount_value']) ?></td>
            </tr>
        </tbody>
    </table>

    <table>
        <tr class="net-total-row">
            <td>LÍQUIDO A RECEBER</td>
            <td style="width: 120px;" class="text-right"><?= money($payslip['net_total']) ?></td>
        </tr>
    </table>

    <div class="info-box">
        <div class="label" style="margin-bottom: 5px;">Resumo de Horas:</div>
        <div>Horas Esperadas: <?= mins_to_time((int)$payslip['expected_minutes']) ?></div>
        <div>Horas Trabalhadas: <?= mins_to_time((int)$payslip['worked_minutes']) ?></div>
        <?php if ($payslip['overtime_minutes'] > 0): ?>
            <div class="text-success">Extras: +<?= mins_to_time((int)$payslip['overtime_minutes']) ?></div>
        <?php endif; ?>
        <?php if ($payslip['deficit_minutes'] > 0): ?>
            <div class="text-danger">Déficit: -<?= mins_to_time((int)$payslip['deficit_minutes']) ?></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($payslip['notes'])): ?>
    <div class="info-box">
        <div class="label">Observações:</div>
        <div style="margin-top: 5px;"><?= nl2br(esc($payslip['notes'])) ?></div>
    </div>
    <?php endif; ?>

    <?php
        // Logo em base64 para Dompdf
        $logoPath = __DIR__ . '/../../public/img/logo_prefeitura.png';
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
    ?>
    <div class="footer">
        <?php if ($logoBase64): ?>
        <div style="margin-bottom: 15px; text-align: center;">
            <img src="<?= $logoBase64 ?>" alt="Prefeitura" style="height: 90px; width: auto;">
        </div>
        <?php endif; ?>
        Prefeitura Municipal de Ribeira do Piauí - PI<br>
        DEEDO Ponto v1.0.0 - Sistema de Gestão de Ponto Eletrônico<br>
        Documento gerado em <?= date('d/m/Y H:i:s') ?><br>
        Este holerite é apenas informativo e não substitui documentos oficiais da folha de pagamento.
    </div>
</body>
</html>


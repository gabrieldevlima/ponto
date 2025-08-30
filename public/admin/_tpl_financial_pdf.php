<?php
// Template PDF Financeiro (usado por reports_financial.php)

// Normaliza dados de entrada
$teacherName = isset($teacher['name']) ? (string)$teacher['name'] : '';
$baseSalary  = isset($teacher['base_salary']) ? (float)$teacher['base_salary'] : 0.0;
$monthLabel  = isset($month) ? (string)$month : '';
$rows        = isset($daily) && is_array($daily) ? $daily : [];

// Cálculos principais (com salvaguardas)
$totalExpected = isset($totalExpected) ? (int)$totalExpected
  : (int)array_sum(array_map(static fn($r) => (int)($r['expected'] ?? 0), $rows));

$totalWorked = isset($totalWorked) ? (int)$totalWorked
  : (int)array_sum(array_map(static fn($r) => (int)($r['worked'] ?? 0), $rows));

$deltaMin = isset($deltaMin) ? (int)$deltaMin : ($totalWorked - $totalExpected);
$minuteValue = isset($minuteValue) ? (float)$minuteValue : ($totalExpected > 0 ? $baseSalary / $totalExpected : 0.0);

$extrasMin   = isset($extrasMin)   ? (int)$extrasMin   : max(0, $deltaMin);
$deficitMin  = isset($deficitMin)  ? (int)$deficitMin  : max(0, -$deltaMin);
$extraPay    = isset($extraPay)    ? (float)$extraPay  : ($extrasMin * $minuteValue * 0.5);
$discountPay = isset($discountPay) ? (float)$discountPay : ($deficitMin * $minuteValue);
$netSalary   = $baseSalary + $extraPay - $discountPay;

$deltaBg = $deltaMin >= 0 ? '#e8f5e9' : '#ffebee'; // verde claro / vermelho claro

// Helper de hora segura
$fmtTime = static function ($val) {
  if (empty($val)) return '-';
  $t = strtotime((string)$val);
  return $t ? date('H:i', $t) : '-';
};
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Relatório Financeiro</title>
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 12px;
      color: #222;
      margin: 24px;
    }

    h1 {
      font-size: 20px;
      margin: 0 0 8px;
    }

    .muted {
      color: #666;
    }

    .small {
      font-size: 11px;
    }

    .num {
      text-align: right;
      white-space: nowrap;
    }

    .meta {
      margin: 6px 0 18px;
    }

    .meta .item {
      display: inline-block;
      margin-right: 18px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th,
    td {
      border: 1px solid #ccc;
      padding: 6px 8px;
      vertical-align: middle;
    }

    th {
      background: #f5f5f5;
      text-align: left;
    }

    tbody tr:nth-child(even) td {
      background: #fafafa;
    }


    .section-title {
      margin: 18px 0 8px;
      font-size: 14px;
    }

    .no-border td,
    .no-border th {
      border: none;
      padding: 2px 0;
    }

    .footer {
      margin-top: 10px;
      color: #777;
      font-size: 11px;
    }
  </style>
</head>

<body>
  <h1>Relatório Financeiro</h1>
  <div class="meta">
    <span class="item"><strong>Colaborador:</strong> <?= esc($teacherName) ?></span>
    <span class="item"><strong>Mês:</strong> <?= esc($monthLabel) ?></span>
    <span class="item"><strong>Salário base:</strong> R$ <?= number_format($baseSalary, 2, ',', '.') ?></span>
  </div>

  <div class="section-title">Detalhamento diário</div>
  <table>
    <thead>
      <tr>
        <th>Data</th>
        <th class="num">Esperado (min)</th>
        <th class="num">Trabalhado (min)</th>
        <th class="num">Entrada</th>
        <th class="num">Saída</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $d => $v): ?>
        <tr>
          <td><?= esc($d) ?></td>
          <td class="num"><?= (int)($v['expected'] ?? 0) ?></td>
          <td class="num"><?= (int)($v['worked'] ?? 0) ?></td>
          <td class="num"><?= isset($v['in']) ? esc($fmtTime($v['in'])) : '-' ?></td>
          <td class="num"><?= isset($v['out']) ? esc($fmtTime($v['out'])) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="section-title">Resumo</div>
  <table class="no-border">
    <tr>
      <th class="muted">Min. Previstos</th>
      <td class="num"><?= (int)$totalExpected ?> (<?= minutes_to_hhmm((int)$totalExpected) ?>)</td>
    </tr>
    <tr>
      <th class="muted">Min. Trabalhados</th>
      <td class="num"><?= (int)$totalWorked ?> (<?= minutes_to_hhmm((int)$totalWorked) ?>)</td>
    </tr>
    <tr>
      <th class="muted">Extras / Adicional</th>
      <td class="num"><?= (int)$extrasMin ?> min / R$ <?= number_format($extraPay, 2, ',', '.') ?></td>
    </tr>
    <tr>
      <th class="muted">Déficit / Descontos</th>
      <td class="num"><?= (int)$deficitMin ?> min / R$ <?= number_format($discountPay, 2, ',', '.') ?></td>
    </tr>
    <tr>
      <th class="muted">Salário Base</th>
      <td class="num">R$ <?= number_format($baseSalary, 2, ',', '.') ?></td>
    </tr>
    <tr>
      <th class="muted">Salário Líquido (estimado)</th>
      <td class="num"><strong>R$ <?= number_format($netSalary, 2, ',', '.') ?></strong></td>
    </tr>
  </table>

  <div class="footer">Gerado em <?= date('d/m/Y H:i') ?></div>
</body>

</html>
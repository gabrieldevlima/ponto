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

// A instituição não paga hora extra nem desconta déficit automaticamente.
// Total a receber = salário base; o saldo de horas é apenas informativo.
$netSalary = $baseSalary;

$deltaBg = '#eef2f7'; // neutro (sem destacar saldo negativo)

// Conta faltas (dias com jornada prevista, sem registro, já passados, após data de criação)
$totalAbsences = 0;
$today = date('Y-m-d');
$teacherStartDate = counting_start_for($teacher['created_at'] ?? null);
foreach ($rows as $d => $v) {
    if ((($v['expected'] ?? 0) > 0) && (($v['worked'] ?? 0) == 0) && ($d <= $today) && ($d >= $teacherStartDate) && empty($v['holiday'])) {
        $totalAbsences++;
    }
}

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
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $d => $v): ?>
        <?php
        // Só marca FALTA se: tinha jornada, não trabalhou, data já passou E após data de criação
        $isFalta = (($v['expected'] ?? 0) > 0) && (($v['worked'] ?? 0) == 0) && ($d <= date('Y-m-d')) && ($d >= $teacherStartDate) && empty($v['holiday']);
        ?>
        <tr>
          <td><?= esc($d) ?></td>
          <td class="num"><?= (int)($v['expected'] ?? 0) ?></td>
          <td class="num"><?= (int)($v['worked'] ?? 0) ?></td>
          <td class="num"><?= isset($v['in']) ? esc($fmtTime($v['in'])) : '-' ?></td>
          <td class="num"><?= isset($v['out']) ? esc($fmtTime($v['out'])) : '-' ?></td>
          <td>
            <?php if ($isFalta): ?>
              <strong style="color: #dc3545;">⚠ FALTA</strong>
            <?php else: ?>
              <span class="muted">-</span>
            <?php endif; ?>
          </td>
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
      <th class="muted"><?= $deltaMin < 0 ? 'Horas a compensar' : 'Saldo de horas' ?></th>
      <td class="num"><?= $deltaMin < 0 ? minutes_to_hhmm(abs($deltaMin)) : (($deltaMin > 0 ? '+' : '') . minutes_to_hhmm($deltaMin)) ?></td>
    </tr>
    <?php if ($totalAbsences > 0): ?>
    <tr>
      <th class="muted">Faltas Detectadas</th>
      <td class="num"><strong style="color: #dc3545;"><?= (int)$totalAbsences ?> dia(s)</strong></td>
    </tr>
    <?php endif; ?>
    <tr>
      <th class="muted">Salário Base</th>
      <td class="num">R$ <?= number_format($baseSalary, 2, ',', '.') ?></td>
    </tr>
    <tr>
      <th class="muted">Salário Líquido (estimado)</th>
      <td class="num"><strong>R$ <?= number_format($netSalary, 2, ',', '.') ?></strong></td>
    </tr>
  </table>

  <?php
    // Logo em base64 para Dompdf
    $logoPath = __DIR__ . '/../../public/img/logo_prefeitura.png';
    $logoBase64 = '';
    if (file_exists($logoPath)) {
      $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    }
  ?>
  <div style="margin-top: 24px; text-align: center; border-top: 1px solid #ddd; padding-top: 16px;">
    <?php if ($logoBase64): ?>
    <div style="margin-bottom: 10px;">
      <img src="<?= $logoBase64 ?>" alt="Prefeitura" style="height: 90px; width: auto;">
    </div>
    <?php endif; ?>
    <div style="font-size: 10px; color: #666;">
      <div>Prefeitura Municipal de Oeiras - PI</div>
      <div>DEEDO Sistemas - Sistema de Ponto Eletrônico</div>
      <div style="margin-top: 4px;">Gerado em <?= date('d/m/Y H:i') ?></div>
    </div>
  </div>
</body>

</html>
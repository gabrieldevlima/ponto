<?php
// Template PDF para teacher_monthly_report.php (Relatório Mensal por Colaborador)
if (!function_exists('minutes_to_hhmm')) {
  function minutes_to_hhmm(int $minutes): string
  {
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', $h, $m);
  }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <style>
    body {
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 12px;
      color: #111;
    }

    h2,
    h3 {
      margin: 0 0 10px 0;
    }

    .muted {
      color: #555;
    }

    .metrics {
      margin: 10px 0 16px 0;
    }

    .metric {
      display: inline-block;
      margin-right: 18px;
      padding: 8px 10px;
      border: 1px solid #ddd;
      border-radius: 6px;
      background: #fafafa;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }

    th,
    td {
      border: 1px solid #ccc;
      padding: 6px 8px;
    }

    th {
      background: #f0f0f0;
    }

    .small {
      font-size: 11px;
    }

    .footer {
      margin-top: 14px;
      color: #666;
      font-size: 11px;
    }
  </style>
</head>

<body>
  <h2>Relatório Mensal do Colaborador</h2>
  <div class="muted">
    <strong>Colaborador:</strong> <?= esc($selectedTeacher['name'] ?? '-') ?> |
    <strong>Mês:</strong> <?= esc($month ?? '-') ?>
  </div>

  <div class="metrics">
    <span class="metric"><strong>Horas esperadas:</strong> <?= minutes_to_hhmm((int)($totalExpectedMin ?? 0)) ?></span>
    <span class="metric"><strong>Horas trabalhadas:</strong> <?= minutes_to_hhmm((int)($totalWorkedMin ?? 0)) ?></span>
    <span class="metric"><strong>Saldo:</strong> <?= minutes_to_hhmm((int)($saldo ?? 0)) ?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width: 90px;">Data</th>
        <th style="width: 110px;">Esperado</th>
        <th style="width: 110px;">Trabalhado</th>
        <th>Pontos</th>
        <th style="width: 280px;">Justificativa</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($daily)): ?>
        <?php foreach ($daily as $date => $info): ?>
          <tr>
            <td><?= esc((new DateTime($date))->format('d/m/Y')) ?></td>
            <td><?= minutes_to_hhmm((int)($info['expectedMin'] ?? 0)) ?></td>
            <td><?= minutes_to_hhmm((int)($info['workedMin'] ?? 0)) ?></td>
            <td>
              <?php if (!empty($info['items'])): ?>
                <?php foreach ($info['items'] as $it):
                  $entrada = !empty($it['check_in']) ? (new DateTime($it['check_in']))->format('H:i:s') : '-';
                  $saida = !empty($it['check_out']) ? (new DateTime($it['check_out']))->format('H:i:s') : '-';
                ?>
                  <div class="small">Entrada: <?= esc($entrada) ?> | Saída: <?= esc($saida) ?> | Método: <?= esc($it['method'] ?? '-') ?></div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="small muted">Sem pontos</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $just = [];
              foreach ($info['items'] ?? [] as $it) {
                if (!empty($it['manual_reason_id'])) {
                  $txt = trim(($it['manual_reason_name'] ?? 'Manual') . (!empty($it['manual_reason_text']) ? ' - ' . $it['manual_reason_text'] : ''));
                  if ($txt !== '') $just[] = $txt;
                }
              }
              echo $just ? esc(implode(' | ', $just)) : '<span class="small muted">-</span>';
              ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="5" class="small muted">Sem dados no período.</td>
        </tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <th>Total</th>
        <th><?= minutes_to_hhmm((int)($totalExpectedMin ?? 0)) ?></th>
        <th><?= minutes_to_hhmm((int)($totalWorkedMin ?? 0)) ?></th>
        <th colspan="2"></th>
      </tr>
    </tfoot>
  </table>

  <div class="footer">
    Gerado em <?= date('d/m/Y H:i') ?><br>
    <strong>Nota:</strong> Apenas registros aprovados são considerados.
  </div>
</body>

</html>
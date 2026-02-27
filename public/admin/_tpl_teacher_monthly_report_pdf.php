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
$teacherStartDate = isset($selectedTeacher['created_at']) ? date('Y-m-d', strtotime($selectedTeacher['created_at'])) : '1900-01-01';
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
    h2, h3 { margin: 0 0 10px 0; }
    .muted { color: #555; }
    .metrics { margin: 10px 0 16px 0; }
    .metric {
      display: inline-block;
      margin-right: 18px;
      padding: 8px 10px;
      border: 1px solid #ddd;
      border-radius: 6px;
      background: #fafafa;
    }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; }
    th { background: #f0f0f0; }
    .small { font-size: 11px; }
    .footer { margin-top: 14px; color: #666; font-size: 11px; }
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
                  $editTxt = '';
                  if (!empty($it['data_edicao'])) {
                    $editTxt = ' | Editado por ' . esc($it['edited_by_username'] ?? ('#' . (int)($it['editado_por'] ?? 0))) .
                               ' em ' . esc(date('d/m/Y H:i', strtotime($it['data_edicao']))) .
                               ' - Motivo: ' . esc($it['motivo_edicao'] ?? '-');
                  }
                ?>
                  <div class="small">Entrada: <?= esc($entrada) ?> | Saída: <?= esc($saida) ?> | Método: <?= esc($it['method'] ?? '-') ?><?= $editTxt ? esc($editTxt) : '' ?></div>
                <?php endforeach; ?>
              <?php else: ?>
                <?php 
                // Só marca FALTA se: tinha jornada, data já passou E após data de criação
                $isFalta = (($info['expectedMin'] ?? 0) > 0) && ($date <= date('Y-m-d')) && ($date >= $teacherStartDate);
                ?>
                <?php if ($isFalta): ?>
                  <strong style="color: #dc3545;">⚠ FALTA</strong><br>
                  <span class="small" style="color: #dc3545;">Jornada prevista não registrada</span>
                <?php else: ?>
                  <span class="small muted">-</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $just = [];
              // Afastamentos
              if (!empty($info['leaves'])) {
                foreach ($info['leaves'] as $leave) {
                  $leaveText = '[AFASTAMENTO] ' . $leave['type'];
                  if (!empty($leave['cid_code'])) $leaveText .= ' (CID: ' . $leave['cid_code'] . ')';
                  if ($leave['paid']) $leaveText .= ' - Remunerado';
                  $just[] = $leaveText;
                }
              }
              // Justificativas manuais
              foreach ($info['items'] ?? [] as $it) {
                if (!empty($it['manual_reason_id'])) {
                  $txt = trim(($it['manual_reason_name'] ?? 'Manual') . (!empty($it['manual_reason_text']) ? ' - ' . $it['manual_reason_text'] : ''));
                  if ($txt !== '') $just[] = $txt;
                }
              }
              if ($just) {
                foreach ($just as $j) {
                  echo '<div class="small">' . esc($j) . '</div>';
                }
              } else {
                echo '<span class="small muted">-</span>';
              }
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

  <?php if (!empty($leaves)): ?>
    <h3 style="margin-top: 20px;">Afastamentos no Período</h3>
    <table>
      <thead>
        <tr>
          <th>Tipo</th>
          <th>Período</th>
          <th>Dias</th>
          <th>Remunerado</th>
          <th>Detalhes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leaves as $lv): ?>
          <?php
          $startFmt = (new DateTime($lv['start_date']))->format('d/m/Y');
          $endFmt = (new DateTime($lv['end_date']))->format('d/m/Y');
          $daysCount = $lv['days_count'] ?? ((new DateTime($lv['start_date']))->diff(new DateTime($lv['end_date']))->days + 1);
          ?>
          <tr>
            <td><?= esc($lv['leave_type_name']) ?></td>
            <td><?= esc($startFmt) ?> a <?= esc($endFmt) ?></td>
            <td style="text-align: center;"><?= $daysCount ?></td>
            <td style="text-align: center;"><?= (int)$lv['paid'] ? 'Sim' : 'Não' ?></td>
            <td class="small">
              <?php if (!empty($lv['cid_code'])): ?>
                CID: <?= esc($lv['cid_code']) ?><br>
              <?php endif; ?>
              <?php if (!empty($lv['description'])): ?>
                <?= esc(mb_strimwidth($lv['description'], 0, 100, '...')) ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small muted" style="margin-top: 10px;">
      <strong>Observação:</strong> Afastamentos remunerados não afetam o cálculo de horas esperadas.
    </p>
  <?php endif; ?>

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
      <div>Prefeitura Municipal de Ribeira do Piauí - PI</div>
      <div>DEEDO Sistemas - Sistema de Ponto Eletrônico</div>
      <div style="margin-top: 4px;">Gerado em <?= date('d/m/Y H:i') ?></div>
    </div>
  </div>
</body>

</html>
<?php
// Template PDF para reports.php (Relatório Mensal por Colaborador)
if (!function_exists('minutes_to_hhmm')) {
  function minutes_to_hhmm(int $minutes): string {
    $h = intdiv($minutes, 60); $m = $minutes % 60;
    return sprintf('%02d:%02d', $h, $m);
  }
}
$teacherStartDate = counting_start_for($selectedTeacher['created_at'] ?? null);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; }
    h2, h3, h4 { margin: 0 0 10px 0; }
    .muted { color: #555; }
    .metrics { margin: 10px 0 16px 0; }
    .metric { display: inline-block; margin-right: 18px; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; }
    th { background: #f0f0f0; }
    .right { text-align: right; }
    .small { font-size: 11px; }
    .footer { margin-top: 14px; color: #666; font-size: 11px; }
  </style>
</head>
<body>
  <h2>Relatório Mensal</h2>
  <div class="muted">
    <strong>Colaborador:</strong> <?= esc($selectedTeacher['name'] ?? '-') ?> |
    <strong>Mês:</strong> <?= esc($month ?? '-') ?>
  </div>

  <div class="metrics">
    <span class="metric"><strong>Horas esperadas:</strong> <?= minutes_to_hhmm((int)($totalExpectedMin ?? 0)) ?></span>
    <span class="metric"><strong>Horas trabalhadas (líquido):</strong> <?= minutes_to_hhmm(max(0, (int)($totalWorkedMin ?? 0) - (int)($totalBreakMin ?? 0))) ?></span>
    <?php if (!empty($totalBreakMin)): ?>
      <span class="metric"><strong>Intervalo:</strong> <?= minutes_to_hhmm((int)$totalBreakMin) ?></span>
      <span class="metric"><strong>Presença:</strong> <?= minutes_to_hhmm((int)($totalEffectiveWorkedMin ?? $totalWorkedMin ?? 0)) ?></span>
    <?php endif; ?>
    <?php $saldoVal = (int)($saldo ?? 0); ?>
    <span class="metric"><strong><?= $saldoVal < 0 ? 'Horas a compensar:' : 'Saldo:' ?></strong> <?= minutes_to_hhmm($saldoVal < 0 ? abs($saldoVal) : $saldoVal) ?></span>
  </div>

  <p class="small muted" style="margin-top:4px;">
    A coluna <strong>Trabalhado</strong> mostra o tempo líquido (sem intervalo). O <strong>saldo</strong> usa a <strong>Presença</strong> (Trabalhado + Intervalo) comparada ao Esperado — o intervalo é um direito do colaborador e <strong>nunca gera déficit</strong>.
  </p>

  <?php $isHoursMode = isset($mode) && $mode === 'hours'; ?>
  <table>
    <thead>
      <tr>
        <th style="width: 90px;">Data</th>
        <th style="width: 90px;">Esperado</th>
        <th style="width: 90px;">Trabalhado</th>
        <th style="width: 80px;">Intervalo</th>
        <th style="width: 90px;">Presença</th>
        <?php if ($isHoursMode): ?>
          <th style="width: 80px;">Cumpr.</th>
        <?php endif; ?>
        <th>Pontos</th>
        <th style="width: 220px;">Justificativa</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($daily)): ?>
        <?php foreach ($daily as $date => $info): ?>
          <tr>
            <td><?= esc((new DateTime($date))->format('d/m/Y')) ?></td>
            <td><?= minutes_to_hhmm((int)($info['expectedMin'] ?? 0)) ?></td>
            <td><?= minutes_to_hhmm(max(0, (int)($info['workedMin'] ?? 0) - (int)($info['breakMin'] ?? 0))) ?></td>
            <td><?= minutes_to_hhmm((int)($info['breakMin'] ?? 0)) ?></td>
            <td><strong><?= minutes_to_hhmm((int)($info['effectiveMin'] ?? $info['workedMin'] ?? 0)) ?></strong></td>
            <?php if ($isHoursMode):
              $expDay = (int)($info['expectedMin'] ?? 0);
              $workDay = (int)($info['workedMin'] ?? 0);
              $pctDay = $expDay > 0 ? (int)round(min(100, ($workDay / $expDay) * 100)) : null;
            ?>
              <td><?= $pctDay !== null ? $pctDay . '%' : '-' ?></td>
            <?php endif; ?>
            <td>
              <?php if (!empty($info['items'])):
                // Consolida o dia inteiro: 1 linha principal (entrada → saída) + sub-listagem dos intervalos
                $itemsForConsolidation = array_map(function($it) use ($selectedTeacher) {
                    $it['teacher_id'] = (int)$selectedTeacher['id'];
                    $it['teacher_name'] = $selectedTeacher['name'];
                    return $it;
                }, $info['items']);
                $dayCons = consolidate_attendance_by_day($itemsForConsolidation);
                $dayCon = $dayCons[0] ?? null;
                if ($dayCon):
                    $dayIn  = $dayCon['check_in']  ? substr($dayCon['check_in'], 0, 5)  : '-';
                    $dayOut = $dayCon['check_out'] ? substr($dayCon['check_out'], 0, 5) : '-';
                    // Método/edição do primeiro work do dia
                    $primaryItem = null;
                    foreach ($info['items'] as $it) {
                        if (($it['record_type'] ?? 'work') === 'work') { $primaryItem = $it; break; }
                    }
                    if ($primaryItem === null) $primaryItem = $info['items'][0];
                    $methodLabels = ['cpf' => 'CPF', 'pin' => 'CPF', 'foto' => 'Foto', 'face' => 'Reconhecimento Facial', 'manual' => 'Manual'];
                    $methodLabel = $methodLabels[strtolower((string)($primaryItem['method'] ?? ''))] ?? ($primaryItem['method'] ?? '-');
                    $editTxt = '';
                    if (!empty($primaryItem['data_edicao'])) {
                        $editTxt = ' | Editado por ' . esc($primaryItem['edited_by_username'] ?? ('#' . (int)($primaryItem['editado_por'] ?? 0))) .
                                   ' em ' . esc(date('d/m/Y H:i', strtotime($primaryItem['data_edicao']))) .
                                   ' - Motivo: ' . esc($primaryItem['motivo_edicao'] ?? '-');
                    }
              ?>
                  <div class="small">Entrada: <strong><?= esc($dayIn) ?></strong> | Saída: <strong><?= esc($dayOut) ?></strong> | Método: <?= esc($methodLabel) ?><?= $editTxt ?></div>
                  <?php if (!empty($dayCon['breaks'])): ?>
                    <?php foreach ($dayCon['breaks'] as $bi => $bb):
                      $bs = $bb['start'] ? substr($bb['start'], 0, 5) : '-';
                      $be = $bb['end']   ? substr($bb['end'], 0, 5)   : 'aberto';
                      $bdMin = $bb['duration_minutes'];
                      $bd = $bdMin !== null ? minutes_to_hhmm((int)$bdMin) : '-';
                    ?>
                      <div class="small muted" style="padding-left:14px;">↳ Intervalo <?= ($bi + 1) ?>: <?= esc($bs) ?> → <?= esc($be) ?> (<?= esc($bd) ?>)</div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                <?php endif; ?>
              <?php else: ?>
                <?php
                // Só marca FALTA se: tinha jornada, data já passou E após data de criação
                $isFalta = (($info['expectedMin'] ?? 0) > 0) && ($date <= date('Y-m-d')) && ($date >= $teacherStartDate);
                ?>
                <?php if ($isFalta): ?>
                  <strong style="color: #dc3545;">⚠ FALTA</strong>
                <?php else: ?>
                  <span class="small muted">-</span>
                <?php endif; ?>
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
        <tr><td colspan="<?= $isHoursMode ? 8 : 7 ?>" class="small muted">Sem dados no período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <th>Total</th>
        <th><?= minutes_to_hhmm((int)($totalExpectedMin ?? 0)) ?></th>
        <th><?= minutes_to_hhmm((int)($totalWorkedMin ?? 0)) ?></th>
        <th colspan="<?= $isHoursMode ? 5 : 4 ?>"></th>
      </tr>
    </tfoot>
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
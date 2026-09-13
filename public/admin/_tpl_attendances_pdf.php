<?php
// Template PDF para attendances.php (Lista filtrada de Registros de Ponto)
if (!function_exists('fmt_min')) {
  function fmt_min(int $min): string {
    $sign = $min < 0 ? '-' : '';
    $min = abs($min);
    return $sign . sprintf('%dh%02d', intdiv($min, 60), $min % 60);
  }
}
$weekdays = [0=>'Dom',1=>'Seg',2=>'Ter',3=>'Qua',4=>'Qui',5=>'Sex',6=>'Sáb'];
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11.5px; color: #111; }
    h2 { margin: 0 0 10px 0; }
    .muted { color: #555; }
    .filters { margin: 8px 0 12px 0; }
    .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
    .badge-pend { background: #ffe08a; color: #6b5e00; }
    .badge-ok { background: #b8f5c0; color: #0b5f19; }
    .badge-bad { background: #f8b7b7; color: #7a0d0d; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 5px 6px; }
    th { background: #f0f0f0; }
    .right { text-align: right; }
    .small { font-size: 10.5px; }
    .mt { margin-top: 10px; }
  </style>
</head>
<body>
  <h2>Registros de Ponto</h2>

  <?php
    $parts = [];
    if (!empty($_GET['date1'])) $parts[] = 'De: ' . esc($_GET['date1']);
    if (!empty($_GET['date2'])) $parts[] = 'Até: ' . esc($_GET['date2']);
    if (!empty($_GET['teacher'])) $parts[] = 'Colaborador ID: ' . (int)$_GET['teacher'];
    if (!empty($_GET['school'])) $parts[] = 'Escola ID: ' . (int)$_GET['school'];
    if (isset($_GET['approved']) && $_GET['approved'] !== '') {
      $map = ['null'=>'Pendente','0'=>'Rejeitado','1'=>'Aprovado'];
      $parts[] = 'Status: ' . esc($map[$_GET['approved']] ?? (string)$_GET['approved']); // HOTFIX 2026-09: escapado
    }
    $flt = implode(' | ', $parts);
  ?>
  <?php if ($flt): ?>
    <div class="filters muted"><?= $flt ?></div>
  <?php endif; ?>

  <p class="small muted" style="margin-top:6px;">
    A coluna <strong>Trabalhado</strong> mostra o tempo líquido (sem intervalo); abaixo dela, <em>pres.</em> = Presença (Trabalhado + Intervalo), que é o valor comparado ao Esperado no saldo. O intervalo é um direito do colaborador e nunca gera déficit.
  </p>

  <table class="mt">
    <thead>
      <tr>
        <th>Colaborador</th>
        <th>Data</th>
        <th>Dia</th>
        <th>Entrada</th>
        <th>Saída</th>
        <th>Trabalhado</th>
        <th>Intervalos</th>
        <th>Esperado</th>
        <th>Saldo</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($consolidatedDays ?? [])): ?>
        <?php foreach ($consolidatedDays as $day):
          $rawRows = $day['raw_rows'] ?? [];
          $r = null;
          foreach ($rawRows as $rr) {
              if (($rr['record_type'] ?? 'work') === 'work') { $r = $rr; break; }
          }
          if ($r === null && !empty($rawRows)) $r = $rawRows[0];
          if ($r === null) continue;

          $weekdayIdx = isset($r['weekday_idx']) ? (int)$r['weekday_idx'] : (int)date('w', strtotime($day['date']));
          $status = $r['approved'];
          $badge = '<span class="badge badge-pend">Pendente</span>';
          if ($status === 1 || $status === '1') $badge = '<span class="badge badge-ok">Aprovado</span>';
          elseif ($status === 0 || $status === '0') $badge = '<span class="badge badge-bad">Rejeitado</span>';
          // Totais consolidados do DIA — presença CHEIA: pares work + intervalos
          // REGISTRADOS (raw_id !== null). Intervalo conta como tempo trabalhado
          // (modelo "cheio vs cheio"); breaks inferidos (saída no almoço) não.
          $realBreakM = 0;
          foreach (($day['breaks'] ?? []) as $bRow) {
              if (($bRow['raw_id'] ?? null) !== null && $bRow['duration_minutes'] !== null) {
                  $realBreakM += (int)$bRow['duration_minutes'];
              }
          }
          $worked = (int)$day['total_worked_minutes'] + $realBreakM;
          // "Trabalhado" exibido = LÍQUIDO (presença − intervalos). O Saldo segue o
          // modelo "cheio vs cheio" (presença), então $worked é usado só no saldo.
          $netWorked = (int)$day['total_worked_minutes'];
          $breakM = (int)$day['total_break_minutes'];
          $expected = (int)($r['total_esperado_min'] ?? 0);
          $saldo = $worked - $expected;
          $breakCount = count($day['breaks']);
          $entrada = $day['check_in']  ? substr($day['check_in'], 0, 5)  : '-';
          $saida   = $day['check_out'] ? substr($day['check_out'], 0, 5) : '-';
          $breakLabel = $breakCount > 0
              ? $breakCount . ' (' . fmt_min($breakM) . ')'
              : '—';
        ?>
          <tr>
            <td><?= esc($day['teacher_name'] ?? ($r['name'] ?? '-')) ?></td>
            <td><?= esc($day['date']) ?></td>
            <td><?= esc($weekdays[$weekdayIdx] ?? '') ?></td>
            <td><?= esc($entrada) ?></td>
            <td><?= esc($saida) ?></td>
            <td><?= fmt_min($netWorked) ?><?php if ($breakM > 0): ?><br><span class="small muted">pres. <?= fmt_min($worked) ?></span><?php endif; ?></td>
            <td><?= esc($breakLabel) ?></td>
            <td><?= fmt_min($expected) ?></td>
            <td><?= fmt_min($saldo) ?></td>
            <td><?= $badge ?></td>
          </tr>
          <?php if ($breakCount > 0): ?>
            <?php foreach ($day['breaks'] as $bi => $bb):
              $bs = $bb['start'] ? substr($bb['start'], 0, 5) : '-';
              $be = $bb['end']   ? substr($bb['end'], 0, 5)   : 'aberto';
              $bd = $bb['duration_minutes'] !== null ? fmt_min((int)$bb['duration_minutes']) : '-';
            ?>
              <tr>
                <td colspan="3" class="small muted" style="padding-left:18px;">↳ Intervalo <?= ($bi + 1) ?></td>
                <td class="small"><?= esc($bs) ?></td>
                <td class="small"><?= esc($be) ?></td>
                <td colspan="2" class="small muted"><?= esc($bd) ?></td>
                <td colspan="3"></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="10" class="small muted">Nenhum registro no filtro informado.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if (!empty($resumo['semana']) || !empty($resumo['mes'])): ?>
    <h4 class="mt">Resumos</h4>
    <?php if (!empty($resumo['semana'])): ?>
      <div class="small"><strong>Semanal:</strong></div>
      <table class="small" style="margin-top:6px">
        <thead><tr><th>Semana</th><th>Esperado</th><th>Realizado</th><th>Saldo</th></tr></thead>
        <tbody>
          <?php foreach ($resumo['semana'] as $semana => $tot): $sd = (int)$tot['realizado'] - (int)$tot['esperado']; ?>
            <tr>
              <td><?= esc($semana) ?></td>
              <td><?= fmt_min((int)$tot['esperado']) ?></td>
              <td><?= fmt_min((int)$tot['realizado']) ?></td>
              <td><?= fmt_min($sd) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if (!empty($resumo['mes'])): ?>
      <div class="small" style="margin-top:8px;"><strong>Mensal:</strong></div>
      <table class="small" style="margin-top:6px">
        <thead><tr><th>Mês</th><th>Esperado</th><th>Realizado</th><th>Saldo</th></tr></thead>
        <tbody>
          <?php foreach ($resumo['mes'] as $mes => $tot): $sd = (int)$tot['realizado'] - (int)$tot['esperado']; ?>
            <tr>
              <td><?= esc($mes) ?></td>
              <td><?= fmt_min((int)$tot['esperado']) ?></td>
              <td><?= fmt_min((int)$tot['realizado']) ?></td>
              <td><?= fmt_min($sd) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
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
      <div>Prefeitura Municipal de Oeiras - PI</div>
      <div>DEEDO Sistemas - Sistema de Ponto Eletrônico</div>
      <div style="margin-top: 4px;">Gerado em <?= date('d/m/Y H:i') ?></div>
    </div>
  </div>
</body>
</html>
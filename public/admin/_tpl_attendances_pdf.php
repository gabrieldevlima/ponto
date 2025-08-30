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
    // Exibição simples dos filtros aplicados, se informados (opcional)
    $parts = [];
    if (!empty($_GET['date1'])) $parts[] = 'De: ' . esc($_GET['date1']);
    if (!empty($_GET['date2'])) $parts[] = 'Até: ' . esc($_GET['date2']);
    if (!empty($_GET['teacher'])) $parts[] = 'Colaborador ID: ' . (int)$_GET['teacher'];
    if (!empty($_GET['school'])) $parts[] = 'Escola ID: ' . (int)$_GET['school'];
    if (isset($_GET['approved']) && $_GET['approved'] !== '') {
      $map = ['null'=>'Pendente','0'=>'Rejeitado','1'=>'Aprovado'];
      $parts[] = 'Status: ' . ($map[$_GET['approved']] ?? $_GET['approved']);
    }
    $flt = implode(' | ', $parts);
  ?>
  <?php if ($flt): ?>
    <div class="filters muted"><?= $flt ?></div>
  <?php endif; ?>

  <table class="mt">
    <thead>
      <tr>
        <th>Colaborador</th>
        <th>Data</th>
        <th>Dia</th>
        <th>Entrada</th>
        <th>Saída</th>
        <th>Trabalhado</th>
        <th>Previstas</th>
        <th>Min/Aula</th>
        <th>Esperado</th>
        <th>Saldo</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($relatorio)): ?>
        <?php foreach ($relatorio as $r):
          $weekdayIdx = isset($r['weekday_idx']) ? (int)$r['weekday_idx'] : (int)date('w', strtotime($r['date']));
          $status = $r['approved'];
          $badge = '<span class="badge badge-pend">Pendente</span>';
          if ($status === 1 || $status === '1') $badge = '<span class="badge badge-ok">Aprovado</span>';
          elseif ($status === 0 || $status === '0') $badge = '<span class="badge badge-bad">Rejeitado</span>';
          $worked = (int)($r['total_realizado_min'] ?? 0);
          $expected = (int)($r['total_esperado_min'] ?? 0);
          $saldo = (int)($r['saldo_min'] ?? ($worked - $expected));
        ?>
          <tr>
            <td><?= esc($r['name'] ?? '-') ?></td>
            <td><?= esc($r['date'] ?? '-') ?></td>
            <td><?= esc($weekdays[$weekdayIdx] ?? '') ?></td>
            <td><?= esc($r['check_in'] ?? '-') ?></td>
            <td><?= esc($r['check_out'] ?? '-') ?></td>
            <td><?= fmt_min($worked) ?></td>
            <td class="right"><?= (int)($r['classes_count'] ?? 0) ?></td>
            <td class="right"><?= (int)($r['class_minutes'] ?? 0) ?></td>
            <td><?= fmt_min($expected) ?></td>
            <td><?= fmt_min($saldo) ?></td>
            <td><?= $badge ?></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="11" class="small muted">Nenhum registro no filtro informado.</td></tr>
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

  <div class="small muted" style="margin-top: 10px;">
    Gerado em <?= date('d/m/Y H:i') ?><br>
    <strong>Nota:</strong> Este relatório considera apenas registros aprovados.
  </div>
</body>
</html>
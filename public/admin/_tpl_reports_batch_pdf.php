<?php
/**
 * Template PDF — Relatório MENSAL em LOTE (resumo consolidado, 1 linha/colaborador).
 * Usado por reports.php (bloco batch). Portrait.
 *
 * Colunas: Colaborador | CPF | Horas Trabalhadas | Faltas
 *
 * Recebe por escopo:
 *   $rows        array de linhas: ['name', 'cpf', worked_min, absences, ...]
 *   $periodStart, $periodEnd  DateTime
 *   $scopeLabel  string ("Rede completa" ou "Escola: X")
 *   $adminName   string
 */
$rows = isset($rows) && is_array($rows) ? $rows : [];

$sumWorked = 0; $sumAbsences = 0;
foreach ($rows as $r) {
    $sumWorked   += (int)($r['worked_min'] ?? 0);
    $sumAbsences += (int)($r['absences'] ?? 0);
}

$logoPath = __DIR__ . '/../../public/img/logo_prefeitura.png';
$logoBase64 = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Relatório Mensal — Lote</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #222; margin: 24px; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .muted { color: #666; }
    .meta { margin: 6px 0 16px; font-size: 11px; }
    .meta .item { display: inline-block; margin-right: 18px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; vertical-align: middle; }
    th { background: #f5f5f5; text-align: left; }
    tbody tr:nth-child(even) td { background: #fafafa; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .neg { color: #dc3545; }
    tfoot td { background: #eef2f7; font-weight: bold; }
    .footer { margin-top: 24px; text-align: center; border-top: 1px solid #ddd; padding-top: 16px; }
  </style>
</head>
<body>
  <h1>Relatório Mensal — Resumo Consolidado</h1>
  <div class="meta">
    <span class="item"><strong>Período:</strong> <?= esc($periodStart->format('d/m/Y')) ?> → <?= esc($periodEnd->format('d/m/Y')) ?></span>
    <span class="item"><strong>Escopo:</strong> <?= esc($scopeLabel ?? '') ?></span>
    <span class="item"><strong>Colaboradores:</strong> <?= count($rows) ?></span>
    <span class="item"><strong>Gerado por:</strong> <?= esc($adminName ?? '-') ?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:45%;">Colaborador</th>
        <th style="width:25%;">CPF</th>
        <th class="num" style="width:18%;">Horas Trabalhadas</th>
        <th class="center" style="width:12%;">Faltas</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="4" class="muted center">Nenhum colaborador no escopo/período.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= esc($r['name'] ?? '-') ?></td>
            <td><?= esc(mask_cpf((string)($r['cpf'] ?? ''))) ?></td>
            <td class="num"><?= esc(report_hours_label((int)($r['worked_min'] ?? 0))) ?></td>
            <td class="center <?= (int)($r['absences'] ?? 0) > 0 ? 'neg' : '' ?>"><?= (int)($r['absences'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td>TOTAL (<?= count($rows) ?>)</td>
        <td></td>
        <td class="num"><?= esc(report_hours_label($sumWorked)) ?></td>
        <td class="center"><?= (int)$sumAbsences ?></td>
      </tr>
    </tfoot>
  </table>

  <div class="footer">
    <?php if ($logoBase64): ?>
      <div style="margin-bottom: 10px;"><img src="<?= $logoBase64 ?>" alt="Prefeitura" style="height: 80px; width: auto;"></div>
    <?php endif; ?>
    <div style="font-size: 10px; color: #666;">
      <div>Prefeitura Municipal de Ribeira do Piauí - PI</div>
      <div>DEEDO Sistemas - Sistema de Ponto Eletrônico</div>
      <div style="margin-top: 4px;">Gerado em <?= date('d/m/Y H:i') ?></div>
    </div>
  </div>
</body>
</html>

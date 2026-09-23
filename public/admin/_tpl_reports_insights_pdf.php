<?php
/**
 * Template PDF para reports_insights.php — Análises e Rankings.
 *
 * Recebe via include:
 *   $dateFrom, $dateTo, $tolMin, $topN, $schoolFilter, $schoolName (opcional)
 *   $totals       — array com KPIs agregados
 *   $ranking      — ['absences' => [...], 'punctual' => [...]]
 *   $teacherById  — opcional (usado pra rodapé)
 */
if (!function_exists('minutes_to_hhmm_signed')) {
    function minutes_to_hhmm_signed(int $min): string {
        $sign = $min < 0 ? '-' : '';
        $min = abs($min);
        return $sign . sprintf('%dh%02d', intdiv($min, 60), $min % 60);
    }
}

// Logo prefeitura em base64 (Dompdf prefere embed)
$logoPath = __DIR__ . '/../../public/img/logo_prefeitura.png';
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

// Helper local: medalha como caractere (Dompdf não renderiza Bootstrap Icons sem font extra)
$medalChar = function (int $rank): string {
    if ($rank === 0) return '🥇';
    if ($rank === 1) return '🥈';
    if ($rank === 2) return '🥉';
    return (string)($rank + 1);
};

// Período legível
$periodStartFmt = (new DateTime($dateFrom))->format('d/m/Y');
$periodEndFmt   = (new DateTime($dateTo))->format('d/m/Y');
$schoolLabel = !empty($schoolName) ? $schoolName : 'Todas as instituições';
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 24mm 18mm 22mm 18mm; }
    body {
      font-family: DejaVu Sans, Arial, sans-serif;
      font-size: 11px;
      color: #1f2937;
      margin: 0;
    }

    /* ===== Cabeçalho ===== */
    .doc-header {
      border-bottom: 3px solid #0162cc;
      padding-bottom: 10px;
      margin-bottom: 16px;
    }
    .doc-header table { width: 100%; border-collapse: collapse; }
    .doc-header td { vertical-align: middle; padding: 0; border: none; }
    .doc-header .org {
      font-size: 13px;
      font-weight: bold;
      color: #0f172a;
      letter-spacing: 0.5px;
    }
    .doc-header .org-sub {
      font-size: 10px;
      color: #475569;
      margin-top: 2px;
    }
    .doc-header .doc-title {
      font-size: 22px;
      font-weight: bold;
      color: #0162cc;
      margin: 12px 0 4px 0;
    }
    .doc-header .doc-meta {
      font-size: 10px;
      color: #64748b;
    }
    .doc-header .doc-meta strong { color: #0f172a; }
    .doc-header .doc-meta span { display: inline-block; margin-right: 16px; }

    /* ===== KPI strip ===== */
    .kpi-strip {
      width: 100%;
      border-collapse: separate;
      border-spacing: 6px 0;
      margin: 14px 0 18px 0;
    }
    .kpi-strip td {
      width: 25%;
      border: 1px solid #e2e8f0;
      border-left: 3px solid #0162cc;
      padding: 10px 12px;
      background: #f8fafc;
      vertical-align: top;
    }
    .kpi-strip td.kpi-danger  { border-left-color: #dc2626; }
    .kpi-strip td.kpi-warning { border-left-color: #d97706; }
    .kpi-strip td.kpi-success { border-left-color: #059669; }
    .kpi-label {
      font-size: 9px;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #64748b;
    }
    .kpi-value {
      font-size: 20px;
      font-weight: bold;
      color: #0f172a;
      line-height: 1;
      margin-top: 4px;
    }
    .kpi-sub {
      font-size: 9px;
      color: #94a3b8;
      margin-top: 3px;
    }

    /* ===== Section ===== */
    .section {
      margin-top: 14px;
      page-break-inside: avoid;
    }
    .section-header {
      background: #0f172a;
      color: white;
      padding: 8px 12px;
      font-size: 12px;
      font-weight: bold;
      letter-spacing: 0.3px;
      border-radius: 4px 4px 0 0;
    }
    .section-header.bg-danger  { background: #dc2626; }
    .section-header.bg-warning { background: #d97706; }
    .section-header.bg-success { background: #059669; }
    .section-sub {
      font-weight: normal;
      font-size: 10px;
      opacity: 0.85;
      margin-left: 6px;
    }

    /* ===== Tabela de ranking ===== */
    table.ranking {
      width: 100%;
      border-collapse: collapse;
      border: 1px solid #e2e8f0;
      border-top: none;
    }
    table.ranking th, table.ranking td {
      padding: 6px 10px;
      border-bottom: 1px solid #f1f5f9;
      font-size: 11px;
    }
    table.ranking th {
      background: #f8fafc;
      color: #475569;
      font-size: 9px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      text-align: left;
    }
    table.ranking td.rank-pos {
      width: 36px;
      text-align: center;
      font-weight: bold;
      color: #475569;
      font-size: 13px;
    }
    table.ranking td.rank-pos.gold   { color: #b45309; }
    table.ranking td.rank-pos.silver { color: #64748b; }
    table.ranking td.rank-pos.bronze { color: #c2410c; }
    table.ranking td.rank-name { font-weight: bold; color: #0f172a; }
    table.ranking td.rank-sub  { font-size: 10px; color: #64748b; }
    table.ranking td.rank-metric {
      width: 100px;
      text-align: right;
      font-weight: bold;
      font-size: 13px;
    }
    table.ranking td.rank-metric.danger  { color: #dc2626; }
    table.ranking td.rank-metric.warning { color: #d97706; }
    table.ranking td.rank-metric.success { color: #059669; }
    .empty-row td {
      padding: 18px;
      text-align: center;
      color: #94a3b8;
      font-style: italic;
      background: #f8fafc;
    }

    /* ===== Footer ===== */
    .doc-footer {
      margin-top: 24px;
      border-top: 1px solid #e2e8f0;
      padding-top: 12px;
      text-align: center;
    }
    .doc-footer .footer-logo { height: 60px; width: auto; margin-bottom: 6px; }
    .doc-footer .footer-text {
      font-size: 9px;
      color: #64748b;
      line-height: 1.5;
    }
    .doc-footer .footer-text strong { color: #0f172a; }

    /* ===== Notas ===== */
    .legend {
      margin-top: 16px;
      padding: 10px 14px;
      background: #f8fafc;
      border-left: 3px solid #94a3b8;
      font-size: 10px;
      color: #475569;
      page-break-inside: avoid;
    }
    .legend strong { color: #0f172a; }

    /* Page break entre rankings em listas grandes */
    .page-break { page-break-after: always; }
  </style>
</head>
<body>

  <!-- ============ HEADER ============ -->
  <div class="doc-header">
    <table>
      <tr>
        <td style="width: 70%;">
          <div class="org">PREFEITURA MUNICIPAL DE RIBEIRA DO PIAUÍ</div>
          <div class="org-sub">Sistema de Ponto Eletrônico — REP-P / Portaria MTP 671/2021</div>
        </td>
        <td style="width: 30%; text-align: right;">
          <?php if ($logoBase64): ?>
            <img src="<?= $logoBase64 ?>" alt="Prefeitura" style="height: 50px; width: auto;">
          <?php endif; ?>
        </td>
      </tr>
    </table>
    <div class="doc-title">Análises e Rankings</div>
    <div class="doc-meta">
      <span><strong>Período:</strong> <?= esc($periodStartFmt) ?> a <?= esc($periodEndFmt) ?></span>
      <span><strong>Instituição:</strong> <?= esc($schoolLabel) ?></span>
      <span><strong>Top:</strong> <?= (int)$topN ?> colocados</span>
      <span><strong>Tolerância pontualidade:</strong> <?= (int)$tolMin ?> min</span>
    </div>
  </div>

  <!-- ============ KPI STRIP ============ -->
  <table class="kpi-strip">
    <tr>
      <td>
        <div class="kpi-label">Colaboradores</div>
        <div class="kpi-value"><?= number_format($totals['collaborators']) ?></div>
        <div class="kpi-sub">ativos no escopo</div>
      </td>
      <td class="kpi-danger">
        <div class="kpi-label">Total de Faltas</div>
        <div class="kpi-value"><?= number_format($totals['total_absences']) ?></div>
        <div class="kpi-sub"><?= (int)$totals['with_absences'] ?> colab. com faltas</div>
      </td>
      <td class="kpi-success">
        <div class="kpi-label">Janela analisada</div>
        <div class="kpi-value"><?= (new DateTime($dateFrom))->diff(new DateTime($dateTo))->days + 1 ?> dias</div>
        <div class="kpi-sub">corridos no período</div>
      </td>
    </tr>
  </table>

  <!-- ============ RANKING 1 — FALTAS ============ -->
  <div class="section">
    <div class="section-header bg-danger">
      Quem mais faltou
      <span class="section-sub">— dias úteis sem ponto aprovado nem licença</span>
    </div>
    <table class="ranking">
      <thead>
        <tr>
          <th style="width: 36px;">#</th>
          <th>Colaborador</th>
          <th style="width: 120px; text-align: right;">Faltas</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ranking['absences'])): ?>
          <tr class="empty-row"><td colspan="3">✓ Excelente! Nenhuma falta registrada no período.</td></tr>
        <?php else: ?>
          <?php foreach ($ranking['absences'] as $i => $r):
            $cls = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : '')); ?>
            <tr>
              <td class="rank-pos <?= $cls ?>"><?= $medalChar($i) ?></td>
              <td>
                <div class="rank-name"><?= esc($r['name']) ?></div>
                <div class="rank-sub"><?= esc($r['subtitle']) ?></div>
              </td>
              <td class="rank-metric danger"><?= (int)$r['metric'] ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ============ RANKING 2 — PONTUAIS ============ -->
  <div class="section">
    <div class="section-header bg-success">
      Mais pontuais
      <span class="section-sub">— % de dias com entrada no horário (tol. <?= (int)$tolMin ?>min)</span>
    </div>
    <table class="ranking">
      <thead>
        <tr>
          <th style="width: 36px;">#</th>
          <th>Colaborador</th>
          <th style="width: 120px; text-align: right;">Pontualidade</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ranking['punctual'])): ?>
          <tr class="empty-row">
            <td colspan="3">
              Sem dados suficientes — colaboradores precisam ter horário fixo (modo "tempo")<br>
              e mínimo de 3 dias trabalhados no período.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($ranking['punctual'] as $i => $r):
            $cls = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : '')); ?>
            <tr>
              <td class="rank-pos <?= $cls ?>"><?= $medalChar($i) ?></td>
              <td>
                <div class="rank-name"><?= esc($r['name']) ?></div>
                <div class="rank-sub"><?= esc($r['subtitle']) ?></div>
              </td>
              <td class="rank-metric success"><?= esc(number_format((float)$r['metric'], 1, ',', '.')) ?>%</td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ============ NOTAS METODOLÓGICAS ============ -->
  <div class="legend">
    <strong>Como interpretar este relatório:</strong>
    <strong>Faltas</strong> contam dias úteis com jornada prevista, sem batidas aprovadas e sem licença
    aprovada (ignora feriados e dias antes do cadastro do colaborador).
    <strong>Pontualidade</strong> só é calculada para colaboradores com horário fixo (modo "tempo")
    e mínimo de 3 dias trabalhados — o percentual é sobre o total de dias com batida.
  </div>

  <!-- ============ FOOTER ============ -->
  <div class="doc-footer">
    <?php if ($logoBase64): ?>
      <img src="<?= $logoBase64 ?>" alt="Prefeitura" class="footer-logo">
    <?php endif; ?>
    <div class="footer-text">
      <div><strong>Prefeitura Municipal de Oeiras - PI</strong></div>
      <div>DEEDO Sistemas — Sistema de Ponto Eletrônico</div>
      <div style="margin-top: 4px;">
        Documento gerado em <?= date('d/m/Y') ?> às <?= date('H:i:s') ?>
        <?php if (!empty($adminUsername)): ?>
          por <strong><?= esc($adminUsername) ?></strong>
        <?php endif; ?>
      </div>
    </div>
  </div>

</body>
</html>

<?php
declare(strict_types=1);
/**
 * Espelho de Ponto Eletrônico — Portaria MTP 671/2021.
 * Fase 5.3 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md — resolve NC-07.
 *
 * O que distingue este espelho do relatório mensal que já existia
 * (teacher_monthly_report.php):
 *   - traz o NSR de CADA marcação, não só os horários consolidados;
 *   - separa jornada CONTRATUAL (líquida de intervalo) do previsto interno;
 *   - identifica empregador e trabalhador conforme o art. 80;
 *   - marca registros anulados e substituídos em vez de omiti-los.
 *
 * Um relatório interno mostra o resultado; o espelho legal precisa mostrar a
 * evidência — que marcação, com que número, deu naquele resultado.
 *
 * Uso: timesheet_mirror.php?teacher_id=1&de=2026-05-01&ate=2026-05-31[&pdf=1]
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/aej.php';
require_admin();

$pdo = db();

$teacherId = (int)($_GET['teacher_id'] ?? 0);
$de  = trim((string)($_GET['de']  ?? date('Y-m-01')));
$ate = trim((string)($_GET['ate'] ?? date('Y-m-t')));
$comoPdf = !empty($_GET['pdf']);

foreach ([$de, $ate] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { http_response_code(400); exit('Período inválido.'); }
}

// Escopo: um school_admin não pode emitir espelho de colaborador de outra unidade.
[$scopeSql, $scopeParams] = admin_scope_where('t');
$stT = $pdo->prepare("SELECT t.* FROM teachers t WHERE t.id = ? AND {$scopeSql} LIMIT 1");
$stT->execute(array_merge([$teacherId], $scopeParams));
$teacher = $stT->fetch(PDO::FETCH_ASSOC);

$stLista = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE {$scopeSql} ORDER BY t.name");
$stLista->execute($scopeParams);
$colaboradores = $stLista->fetchAll(PDO::FETCH_ASSOC);

$emp = $pdo->query("SELECT * FROM employer_config LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

// ---------------------------------------------------------------------------
// Apuração dia a dia
// ---------------------------------------------------------------------------
$dias = [];
$totPrev = 0; $totReal = 0;
if ($teacher) {
    $inicioContagem = counting_start_for($teacher['created_at'] ?? null);
    $hoje = date('Y-m-d');

    $stMarcas = $pdo->prepare("
        SELECT a.id, a.nsr, a.nsr_out, a.check_in, a.check_out, a.record_type,
               a.method, a.removed_at, a.removed_reason, a.superseded_by_id, a.approved
          FROM attendance a
         WHERE a.teacher_id = ? AND a.date = ?
         ORDER BY a.check_in ASC, a.id ASC");

    $stLeave = $pdo->prepare("
        SELECT lt.name, lt.aej_code, l.excuses_absence
          FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
         WHERE l.teacher_id = ? AND l.approved = 1 AND ? BETWEEN l.start_date AND l.end_date
         LIMIT 1");

    for ($d = new DateTime($de); $d <= new DateTime($ate); $d->modify('+1 day')) {
        $ds = $d->format('Y-m-d');
        $foraContagem = ($ds < $inicioContagem || $ds > $hoje);

        $j    = $foraContagem ? ['minutos' => 0, 'intervalo' => 0] : aej_jornada_contratual($pdo, $teacherId, $ds);
        $real = $foraContagem ? 0 : calculate_effective_worked_minutes($pdo, $teacherId, $ds);

        $stMarcas->execute([$teacherId, $ds]);
        $marcas = $stMarcas->fetchAll(PDO::FETCH_ASSOC);

        $stLeave->execute([$teacherId, $ds]);
        $afast = $stLeave->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$foraContagem) { $totPrev += $j['minutos']; $totReal += $real; }

        $dias[] = ['data' => $ds, 'previsto' => $j['minutos'], 'realizado' => $real,
                   'marcas' => $marcas, 'afastamento' => $afast, 'fora_contagem' => $foraContagem];
    }
}

$fmt = function (int $min): string {
    $s = $min < 0 ? '-' : '';
    $min = abs($min);
    return sprintf('%s%02d:%02d', $s, intdiv($min, 60), $min % 60);
};
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ---------------------------------------------------------------------------
// Corpo do documento (compartilhado entre HTML e PDF)
// ---------------------------------------------------------------------------
ob_start();
if ($teacher):
    $tipoId = (int)($emp['employer_type'] ?? 1);
    $docEmp = $tipoId === 2 ? ($emp['cpf'] ?? '') : ($emp['cnpj'] ?? '');
?>
<div class="doc">
  <h1>Espelho de Ponto Eletrônico</h1>
  <p class="lei">
    Documento emitido nos termos do art. 74 da CLT e da Portaria MTP nº 671/2021.
    Cada marcação é identificada pelo seu Número Sequencial de Registro (NSR).
  </p>

  <table class="ident">
    <tr><th>Empregador</th><td><?= $h($emp['company_name'] ?? 'não informado') ?></td>
        <th><?= $tipoId === 2 ? 'CPF' : 'CNPJ' ?></th><td><?= $h($docEmp ?: 'não informado') ?></td></tr>
    <tr><th>Local da prestação</th><td colspan="3"><?= $h($emp['service_location'] ?: 'não informado') ?></td></tr>
    <tr><th>Identificação do REP</th><td><?= $h($emp['rep_identifier'] ?: 'não informado') ?></td>
        <th>Categoria</th><td><?= $h($emp['rep_category'] ?? 'REP-P') ?></td></tr>
    <tr><th>Trabalhador</th><td><?= $h($teacher['name']) ?></td>
        <th>CPF</th><td><?= $h(function_exists('mask_cpf') ? mask_cpf((string)$teacher['cpf']) : $teacher['cpf']) ?></td></tr>
    <tr><th>PIS/PASEP</th><td><?= $h($teacher['pis'] ?: 'não informado') ?></td>
        <th>Período</th><td><?= $h(date('d/m/Y', strtotime($de))) ?> a <?= $h(date('d/m/Y', strtotime($ate))) ?></td></tr>
  </table>

  <table class="grade">
    <thead>
      <tr>
        <th>Data</th><th>Jornada<br>contratual</th><th>Marcações (NSR)</th>
        <th>Trabalhado</th><th>Diferença</th><th>Observação</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($dias as $dia):
        $dif = $dia['realizado'] - $dia['previsto'];
        $semMarca = empty($dia['marcas']);
    ?>
      <tr class="<?= $dia['fora_contagem'] ? 'cinza' : '' ?>">
        <td class="nowrap"><?= $h(date('d/m', strtotime($dia['data']))) ?>
            <span class="dow"><?= ['dom','seg','ter','qua','qui','sex','sáb'][(int)date('w', strtotime($dia['data']))] ?></span></td>
        <td class="c"><?= $dia['fora_contagem'] ? '—' : $h($fmt($dia['previsto'])) ?></td>
        <td class="marcas">
          <?php if ($semMarca): ?>
            <span class="vazio">sem marcação</span>
          <?php else: foreach ($dia['marcas'] as $m):
              $anulado = !empty($m['removed_at']);
              $sup = (int)($m['superseded_by_id'] ?? 0);
              $substituido = $anulado && $sup > 0 && $sup !== (int)$m['id'];
          ?>
            <span class="marca <?= $anulado ? 'anulada' : '' ?>">
              <?= $m['check_in'] ? $h(date('H:i', strtotime((string)$m['check_in']))) : '--:--' ?>
              <sup>NSR <?= $h((string)($m['nsr'] ?? '?')) ?></sup>
              <?php if ($m['check_out']): ?>
                &rarr; <?= $h(date('H:i', strtotime((string)$m['check_out']))) ?>
                <sup>NSR <?= $h((string)($m['nsr_out'] ?? '?')) ?></sup>
              <?php endif; ?>
              <?php if ($m['record_type'] === 'break'): ?><em>(intervalo)</em><?php endif; ?>
              <?php if ($substituido): ?><b class="tag">substituída por #<?= $sup ?></b>
              <?php elseif ($anulado): ?><b class="tag">anulada</b><?php endif; ?>
            </span>
          <?php endforeach; endif; ?>
        </td>
        <td class="c"><?= $dia['fora_contagem'] ? '—' : $h($fmt($dia['realizado'])) ?></td>
        <td class="c <?= (!$dia['fora_contagem'] && $dif < 0) ? 'neg' : '' ?>">
          <?= $dia['fora_contagem'] ? '—' : $h(($dif > 0 ? '+' : '') . $fmt($dif)) ?>
        </td>
        <td class="obs">
          <?php if ($dia['fora_contagem']): ?>fora do período de contagem
          <?php elseif ($dia['afastamento']): ?>
            <?= $h($dia['afastamento']['name']) ?><?= $dia['afastamento']['aej_code'] ? ' (' . $h($dia['afastamento']['aej_code']) . ')' : '' ?>
          <?php elseif ($semMarca && $dia['previsto'] > 0): ?><b class="neg">ausência</b>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th>Totais</th>
        <th class="c"><?= $h($fmt($totPrev)) ?></th>
        <th></th>
        <th class="c"><?= $h($fmt($totReal)) ?></th>
        <th class="c <?= ($totReal - $totPrev) < 0 ? 'neg' : '' ?>">
          <?= $h((($totReal - $totPrev) > 0 ? '+' : '') . $fmt($totReal - $totPrev)) ?>
        </th>
        <th></th>
      </tr>
    </tfoot>
  </table>

  <p class="nota">
    A jornada contratual apresentada é <strong>líquida do intervalo</strong>
    previsto no cadastro. O tempo trabalhado segue o critério interno do sistema,
    no qual o intervalo registrado conta como tempo de presença — por isso o
    total trabalhado pode superar a jornada contratual em dias com intervalo longo.
  </p>
  <p class="assin">
    Emitido em <?= $h(date('d/m/Y H:i')) ?>.
    Conferência de marcações por NSR em <code>verify_receipt.php</code>.
  </p>
  <div class="campos-assinatura">
    <div><span></span>Assinatura do trabalhador</div>
    <div><span></span>Assinatura do empregador</div>
  </div>
</div>
<?php
else:
    echo '<p class="aviso">Selecione um colaborador.</p>';
endif;
$corpo = ob_get_clean();

$css = <<<CSS
.doc { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #111; }
.doc h1 { font-size: 14pt; margin: 0 0 2px; }
.doc .lei { font-size: 7.5pt; color: #444; margin: 0 0 10px; }
.doc table { width: 100%; border-collapse: collapse; }
.doc table.ident { margin-bottom: 10px; font-size: 8pt; }
.doc table.ident th { text-align: left; background: #f2f2f2; padding: 3px 5px; width: 15%; border: 1px solid #ccc; }
.doc table.ident td { padding: 3px 5px; border: 1px solid #ccc; }
.doc table.grade th, .doc table.grade td { border: 1px solid #ccc; padding: 3px 4px; vertical-align: top; }
.doc table.grade thead th { background: #e8e8e8; font-size: 7.5pt; }
.doc table.grade tfoot th { background: #f2f2f2; }
.doc .c { text-align: center; }
.doc .nowrap { white-space: nowrap; }
.doc .dow { color: #777; font-size: 7pt; }
.doc .marcas { font-size: 8pt; }
.doc .marca { display: inline-block; margin-right: 8px; white-space: nowrap; }
.doc .marca sup { color: #666; font-size: 6.5pt; }
.doc .marca.anulada { text-decoration: line-through; color: #999; }
.doc .tag { color: #b00020; font-size: 7pt; text-decoration: none; }
.doc .vazio { color: #999; font-style: italic; }
.doc .neg { color: #b00020; }
.doc tr.cinza td { background: #fafafa; color: #999; }
.doc .obs { font-size: 7.5pt; }
.doc .nota, .doc .assin { font-size: 7.5pt; color: #444; margin-top: 8px; }
.doc .campos-assinatura { margin-top: 26px; }
.doc .campos-assinatura div { display: inline-block; width: 45%; text-align: center; font-size: 8pt; margin-right: 4%; }
.doc .campos-assinatura span { display: block; border-top: 1px solid #333; margin-bottom: 3px; }
CSS;

// ---------------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------------
if ($comoPdf && $teacher) {
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
    if (class_exists('Dompdf\\Dompdf')) {
        audit_log('export', 'timesheet_mirror', $teacherId, ['de' => $de, 'ate' => $ate]);
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml('<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
                          . $corpo . '</body></html>', 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream(sprintf('Espelho_%d_%s_%s.pdf', $teacherId,
                        str_replace('-', '', $de), str_replace('-', '', $ate)), ['Attachment' => true]);
        exit;
    }
    // Dompdf ausente: cai para HTML em vez de entregar erro — o documento
    // continua utilizável (imprimível pelo navegador).
}

$titulo = 'Espelho de Ponto';
require __DIR__ . '/_head.php';
require __DIR__ . '/_navbar.php';
?>
<style><?= $css ?></style>
<div class="container py-4">
  <form method="get" class="card card-body mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label" for="teacher_id">Colaborador</label>
        <select class="form-select" id="teacher_id" name="teacher_id" required>
          <option value="">Selecione…</option>
          <?php foreach ($colaboradores as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $teacherId === (int)$c['id'] ? 'selected' : '' ?>>
              <?= $h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label" for="de">De</label>
        <input type="date" class="form-control" id="de" name="de" value="<?= $h($de) ?>"></div>
      <div class="col-md-2"><label class="form-label" for="ate">Até</label>
        <input type="date" class="form-control" id="ate" name="ate" value="<?= $h($ate) ?>"></div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-primary flex-fill" type="submit">Ver</button>
        <?php if ($teacher): ?>
          <a class="btn btn-outline-secondary flex-fill"
             href="?teacher_id=<?= $teacherId ?>&de=<?= $h($de) ?>&ate=<?= $h($ate) ?>&pdf=1">PDF</a>
        <?php endif; ?>
      </div>
    </div>
  </form>
  <?= $corpo ?>
</div>
<?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

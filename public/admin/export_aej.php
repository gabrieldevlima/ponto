<?php
declare(strict_types=1);
/**
 * Exportação do AEJ pela interface administrativa.
 * Fase 5 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md — NC-06.
 *
 * Existia só o `bin/export_aej.php`. Enquanto o PIS bloqueava a emissão, uma
 * tela não teria o que entregar; com a decisão de 2026-08-07 (os PIS não existem
 * e o campo sai zerado, com o trabalhador identificado pelo CPF) o AEJ passou a
 * ser emitível e precisava de caminho pela interface.
 *
 * Streaming linha a linha, como no AFD. Para períodos longos ainda prefira o
 * CLI: aqui vale o max_execution_time de 60s fixado no `.user.ini`.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/aej.php';
require_admin();

if (!is_network_admin(current_admin())) {
    http_response_code(403);
    exit('Acesso restrito a administradores de rede.');
}

$pdo  = db();
$hoje = date('Y-m-d');
$de   = trim((string)($_REQUEST['de']  ?? date('Y-m-01')));
$ate  = trim((string)($_REQUEST['ate'] ?? $hoje));
$erro = '';

foreach ([$de, $ate] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $erro = 'Período inválido.';
}
if (!$erro && $de > $ate) $erro = 'A data inicial é posterior à final.';

$pf      = $erro ? null : aej_preflight($pdo, $de, $ate);
$resumo  = $erro ? null : aej_resumo_apuracao($pdo, $de, $ate);
$baixar  = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$erro;

// ---------------------------------------------------------------------------
// Download
// ---------------------------------------------------------------------------
if ($baixar) {
    csrf_verify();

    if (!$pf['ok']) {
        $erro = 'Há bloqueios de cadastro que impedem a emissão.';
    } elseif (!AEJ_SPEC_VERIFICADA && empty($_POST['aceitar_rascunho'])) {
        $erro = 'O leiaute ainda não foi conferido contra o Anexo oficial. '
              . 'Marque a confirmação de rascunho para gerar mesmo assim.';
    } else {
        $nome = sprintf('AEJ_%s_%s%s.txt', str_replace('-', '', $de), str_replace('-', '', $ate),
                        AEJ_SPEC_VERIFICADA ? '' : '_RASCUNHO');
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/plain; charset=us-ascii');
        header('Content-Disposition: attachment; filename="' . $nome . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'wb');
        try {
            $r = aej_generate_to_stream($pdo, $de, $ate, $out, [
                'flush' => true,
                'aceitar_spec_nao_verificada' => !AEJ_SPEC_VERIFICADA,
            ]);
            audit_log('export', 'aej', null, ['de' => $de, 'ate' => $ate,
                'linhas' => $r['linhas'], 'spec_verificada' => AEJ_SPEC_VERIFICADA]);
        } catch (Throwable $e) {
            // O cabeçalho já foi enviado; não há como devolver página de erro.
            // Melhor um arquivo visivelmente truncado que um silenciosamente
            // incompleto.
            fwrite($out, "\r\n*** ERRO NA GERACAO: " . $e->getMessage() . " ***\r\n");
            error_log('[export_aej] ' . $e->getMessage());
        }
        fclose($out);
        exit;
    }
}

$titulo = 'Exportar AEJ';
require __DIR__ . '/_head.php';
require __DIR__ . '/_navbar.php';
?>
<div class="container py-4" style="max-width: 860px;">
  <h1 class="h4 mb-1">Arquivo Eletrônico de Jornada (AEJ)</h1>
  <p class="text-muted small mb-4">
    Arquivo exigido pela Portaria MTP nº 671/2021. Declara, por trabalhador e por
    dia, a jornada <strong>contratual</strong> ante a <strong>realizada</strong>,
    as ocorrências de afastamento e os lançamentos de banco de horas.
  </p>

  <?php if (!AEJ_SPEC_VERIFICADA): ?>
    <div class="alert alert-warning">
      <strong>Leiaute ainda não homologado.</strong>
      As posições e larguras dos campos não foram conferidas contra o Anexo
      oficial da Portaria nem validadas em validador público. Um arquivo
      posicional com uma coluna deslocada passa em qualquer teste interno e é
      rejeitado na fiscalização. Trate o que sair daqui como <strong>rascunho</strong>.
    </div>
  <?php endif; ?>

  <?php if ($erro): ?>
    <div class="alert alert-danger"><?= esc($erro) ?></div>
  <?php endif; ?>

  <form method="post" class="card card-body mb-4">
    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
    <div class="row g-3 align-items-end">
      <div class="col-sm-4">
        <label class="form-label" for="de">Data inicial</label>
        <input type="date" class="form-control" id="de" name="de" value="<?= esc($de) ?>" required>
      </div>
      <div class="col-sm-4">
        <label class="form-label" for="ate">Data final</label>
        <input type="date" class="form-control" id="ate" name="ate" value="<?= esc($ate) ?>" required>
      </div>
      <div class="col-sm-4">
        <button type="submit" class="btn btn-primary w-100"
          <?= ($pf && !$pf['ok']) ? 'disabled' : '' ?>>Gerar e baixar</button>
      </div>
    </div>
    <?php if (!AEJ_SPEC_VERIFICADA): ?>
      <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" id="aceitar_rascunho" name="aceitar_rascunho" value="1">
        <label class="form-check-label small" for="aceitar_rascunho">
          Entendo que o leiaute não foi homologado e que este arquivo é um rascunho,
          que não deve ser entregue à fiscalização sem validação prévia.
        </label>
      </div>
    <?php endif; ?>
  </form>

  <?php if ($resumo): ?>
    <h2 class="h6">O que o arquivo vai declarar</h2>
    <div class="row g-2 mb-4">
      <?php
      // Mostrado ANTES de gerar porque o AEJ expõe o déficit que a política de
      // jornada flexível opta por não registrar no banco de horas. Ver Fase 5.2
      // do relatório: não é bug, é escolha — mas quem assina precisa ver.
      $cartoes = [
          'Colaboradores'    => (int)$resumo['colaboradores'],
          'Dias apurados'    => (int)$resumo['dias'],
          'Previsto (min)'   => (int)$resumo['previsto'],
          'Realizado (min)'  => (int)$resumo['realizado'],
          'Dias com déficit' => (int)$resumo['dias_deficit'],
          'Dias sem marcação'=> (int)$resumo['dias_sem_marcacao'],
      ];
      foreach ($cartoes as $rotulo => $valor): ?>
        <div class="col-6 col-md-4">
          <div class="border rounded p-2 h-100">
            <div class="text-muted" style="font-size:.75rem;"><?= esc($rotulo) ?></div>
            <div class="fw-semibold"><?= number_format($valor, 0, ',', '.') ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($pf): ?>
    <h2 class="h6">Pré-voo do período <?= esc($de) ?> a <?= esc($ate) ?></h2>
    <?php if (!$pf['bloqueios'] && !$pf['avisos']): ?>
      <div class="alert alert-success py-2 small mb-0">Nenhuma pendência.</div>
    <?php endif; ?>
    <?php if ($pf['bloqueios']): ?>
      <div class="alert alert-danger">
        <strong>Bloqueios — impedem a emissão:</strong>
        <ul class="mb-2 mt-2"><?php foreach ($pf['bloqueios'] as $b): ?><li><?= esc($b) ?></li><?php endforeach; ?></ul>
        <div class="small">
          São pendências de <strong>cadastro</strong>, não de sistema. Preencha em
          <a href="employer_config.php">Configuração do Empregador</a>.
        </div>
      </div>
    <?php endif; ?>
    <?php if ($pf['avisos']): ?>
      <div class="alert alert-warning">
        <strong>Avisos — o arquivo sai, mas com lacuna:</strong>
        <ul class="mb-0 mt-2"><?php foreach ($pf['avisos'] as $a): ?><li><?= esc($a) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

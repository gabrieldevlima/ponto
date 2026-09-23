<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// ============================================================================
// Funcionalidade DESCONTINUADA.
// A instituição não trabalha mais com hora extra. O fluxo de solicitação e
// aprovação de hora extra foi removido. Os registros antigos permanecem na
// tabela overtime_requests apenas para auditoria (consulte via banco de dados).
// O controle de horas agora é mensal: ver Relatórios → Relatório Mensal e o
// portal do colaborador (consolidação de horas trabalhadas vs. previstas).
// Esta página é mantida apenas para não quebrar links/favoritos antigos.
// ============================================================================
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Horas Extras (descontinuado) | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <style>
    body { background-color: #f8f9fa; }
    .navbar-brand { font-weight: bold; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/_navbar.php'; ?>

  <main class="container py-4">
    <div class="bg-secondary bg-gradient rounded-3 text-white p-4 mb-4 shadow-sm">
      <h1 class="h3 mb-1"><i class="bi bi-clock-history"></i> Controle de horas extras descontinuado</h1>
      <p class="mb-0 opacity-75">A gestão de horas agora é feita de forma mensal e flexível.</p>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <p class="mb-3">
          A instituição não utiliza pagamento de hora extra. O fluxo de
          solicitação e aprovação de hora extra foi <strong>removido</strong>.
          As horas trabalhadas são <strong>consolidadas mensalmente</strong> e
          comparadas com a carga horária prevista de cada colaborador.
        </p>
        <ul class="mb-3">
          <li>Para acompanhar horas trabalhadas vs. previstas, use
            <a href="reports.php">Relatórios &rarr; Relatório Mensal</a>.</li>
          <li>O colaborador vê a situação do mês (carga prevista, total
            trabalhado e horas a compensar) no próprio portal.</li>
        </ul>
        <p class="text-muted mb-0">
          <i class="bi bi-info-circle me-1"></i>
          Os registros antigos de hora extra permanecem armazenados apenas para
          fins de auditoria.
        </p>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/../_footer.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
/**
 * Importação em massa de dados contratuais (PIS, matrícula, CBO, admissão).
 *
 * Existe porque são ~102 colaboradores sem PIS e o AEJ identifica o trabalhador
 * pelo PIS — digitar um a um na tela de cadastro é inviável e propenso a erro.
 *
 * O fluxo é deliberadamente em DUAS etapas: a primeira só lê o arquivo e mostra
 * o que ACONTECERIA; nada é gravado. A gravação só ocorre no segundo POST, sobre
 * as linhas que o admin viu. Um PIS errado só aparece na rejeição do AEJ pela
 * fiscalização, quando já não dá para refazer o mês — então a conferência
 * humana antes de gravar não é luxo.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/import_cadastro.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);
if (!is_network_admin($adm)) {
    http_response_code(403);
    flash_redirect('error', 'Importação de dados contratuais restrita a administradores da rede.', 'dashboard.php');
}

$msg      = '';
$msgTipo  = 'info';
$analise  = null;   // resultado da etapa 1
$aplicado = null;   // resultado da etapa 2

// ── Etapa 1: analisar o arquivo ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'analisar') {
    csrf_verify();
    if (empty($_FILES['arquivo']['tmp_name']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
        $msg = 'Selecione um arquivo CSV.';
        $msgTipo = 'warning';
    } elseif (($_FILES['arquivo']['size'] ?? 0) > 2 * 1024 * 1024) {
        $msg = 'Arquivo acima de 2 MB. Um CSV de cadastro não deveria chegar perto disso.';
        $msgTipo = 'warning';
    } else {
        $conteudo = (string)file_get_contents($_FILES['arquivo']['tmp_name']);
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            // Excel no Windows ainda salva em ANSI por padrão.
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $analise = importar_analisar($pdo, $conteudo);
        if (isset($analise['erro'])) {
            $msg = $analise['erro'];
            $msgTipo = 'warning';
            $analise = null;
        }
    }
}

// ── Etapa 2: gravar o que o admin conferiu ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'aplicar') {
    csrf_verify();
    $linhas = json_decode((string)($_POST['payload'] ?? ''), true);
    if (!is_array($linhas) || empty($linhas)) {
        $msg = 'Nada a aplicar — refaça a conferência.';
        $msgTipo = 'warning';
    } else {
        $gravados = 0; $falhas = [];
        $pdo->beginTransaction();
        try {
            foreach ($linhas as $l) {
                $tid = (int)($l['teacher_id'] ?? 0);
                if ($tid <= 0) continue;

                // Revalida no servidor. O payload veio do formulário e não é
                // confiável só porque a etapa 1 o produziu.
                $pis  = preg_replace('/\D/', '', (string)($l['pis'] ?? ''));
                $matr = trim((string)($l['matricula'] ?? ''));
                $cbo  = preg_replace('/\D/', '', (string)($l['cbo'] ?? ''));
                $adm  = trim((string)($l['admissao'] ?? ''));
                $desl = trim((string)($l['desligamento'] ?? ''));
                if ($pis !== '' && !validate_pis($pis)) {
                    $falhas[] = "Colaborador #{$tid}: PIS inválido.";
                    continue;
                }

                // COALESCE(NULLIF(?,''), coluna): campo vazio preserva o valor
                // atual em vez de apagá-lo.
                $st = $pdo->prepare("
                    UPDATE teachers SET
                        pis            = COALESCE(NULLIF(?, ''), pis),
                        matricula      = COALESCE(NULLIF(?, ''), matricula),
                        cbo            = COALESCE(NULLIF(?, ''), cbo),
                        admission_date = COALESCE(NULLIF(?, ''), admission_date),
                        dismissal_date = COALESCE(NULLIF(?, ''), dismissal_date)
                    WHERE id = ?
                ");
                $st->execute([$pis, $matr, $cbo, $adm, $desl, $tid]);
                if ($st->rowCount() > 0) {
                    $gravados++;
                    audit_log('update', 'teacher', $tid, [
                        'origem' => 'import_pis',
                        'pis' => $pis ?: null, 'matricula' => $matr ?: null, 'cbo' => $cbo ?: null,
                        'admission_date' => $adm ?: null, 'dismissal_date' => $desl ?: null,
                    ]);
                    // Livro fiscal — registro tipo 5 do AFD. O PIS é o campo que
                    // o tipo 5 carrega, então corrigi-lo em massa tem de deixar
                    // o mesmo rastro que corrigi-lo na tela.
                    if (function_exists('nsr_ledger_record_cadastro')) {
                        $ident = function_exists('nsr_ledger_teacher_ident')
                            ? nsr_ledger_teacher_ident($pdo, $tid) : ['cpf' => null, 'pis' => null];
                        nsr_ledger_record_cadastro($pdo, 'employee_change', [
                            'teacher_id'  => $tid,
                            'teacher_cpf' => $ident['cpf'],
                            'teacher_pis' => $ident['pis'],
                            'origin'      => 'admin_edit',
                            'admin_id'    => (int)($_SESSION['admin_id'] ?? 0) ?: null,
                            'reason'      => 'A|' . (string)($l['nome'] ?? ''),
                        ]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Nada foi gravado — a importação inteira foi desfeita: ' . $e->getMessage();
            $msgTipo = 'danger';
            $gravados = 0;
        }
        if ($msg === '') {
            $aplicado = ['gravados' => $gravados, 'falhas' => $falhas];
            $msg = "{$gravados} colaborador(es) atualizado(s).";
            $msgTipo = 'success';
        }
    }
}

// Quanto ainda falta, para o admin saber onde está.
$semPis = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE active = 1 AND (pis IS NULL OR pis = '')")->fetchColumn();
$ativos = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE active = 1")->fetchColumn();
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Importar dados contratuais | DEEDO Ponto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="css/admin.css" rel="stylesheet">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>

    <div class="container-fluid admin-content">
        <div class="app-page-header">
            <div class="app-page-header__main">
                <div class="app-page-icon"><i class="bi bi-upload"></i></div>
                <div>
                    <h1 class="app-page-title">Importar dados contratuais</h1>
                    <p class="app-page-subtitle">PIS, matrícula, CBO e datas de admissão — exigidos pelo AFD e pelo AEJ.</p>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= esc($msgTipo) ?> alert-dismissible fade show">
                <?= esc($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="bi bi-info-circle-fill mt-1"></i>
            <div>
                <?php if ($semPis > 0): ?>
                    <strong><?= $semPis ?> de <?= $ativos ?></strong> colaboradores ativos estão sem PIS.
                    Isso <strong>não impede</strong> a emissão do AFD nem do AEJ — todos os registros
                    carregam CPF, e o campo PIS sai zerado. Se você vier a obter os números,
                    esta tela é o caminho para carregá-los.
                <?php else: ?>
                    Todos os <?= $ativos ?> colaboradores ativos têm PIS cadastrado.
                <?php endif; ?>
            </div>
        </div>

        <?php if ($analise === null): ?>
        <section class="app-section-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow"><i class="bi bi-filetype-csv"></i>Etapa 1 de 2</span>
                <h2 class="app-section-card__title">Enviar arquivo</h2>
                <span class="app-section-card__hint">Nada é gravado nesta etapa</span>
            </header>
            <div class="app-section-card__body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="acao" value="analisar">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Arquivo CSV</label>
                        <input type="file" class="form-control" name="arquivo" accept=".csv,text/csv" required>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-search me-2"></i>Conferir antes de gravar</button>
                    <a href="teachers.php" class="btn btn-outline-secondary">Voltar</a>
                </form>

                <hr class="my-4">
                <h6 class="fw-semibold">Formato esperado</h6>
                <p class="text-muted small mb-2">
                    Primeira linha com os nomes das colunas. Separador <code>;</code> ou <code>,</code>.
                    Obrigatória: <code>cpf</code>. Opcionais: <code>pis</code>, <code>matricula</code>,
                    <code>cbo</code>, <code>admissao</code>, <code>desligamento</code>.
                    Datas em <code>AAAA-MM-DD</code> ou <code>DD/MM/AAAA</code>.
                </p>
<pre class="bg-body-tertiary border rounded p-3 small mb-2">cpf;pis;matricula;admissao
054.696.623-30;120.01234.56-4;M-1042;01/02/2023
111.444.777-35;;M-1043;15/03/2024</pre>
                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Coluna vazia <strong>não apaga</strong> o que já está cadastrado — dá para importar
                    uma planilha só com PIS e outra só com matrícula.
                </p>
            </div>
        </section>
        <?php else: ?>
        <?php
            $aplicaveis = array_values(array_filter(
                $analise['itens'],
                fn($i) => in_array($i['status'], ['ok', 'substitui'], true)
            ));
        ?>
        <section class="app-section-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow"><i class="bi bi-clipboard-check"></i>Etapa 2 de 2</span>
                <h2 class="app-section-card__title">Confira antes de gravar</h2>
            </header>
            <div class="app-section-card__body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-success">Atualiza: <?= (int)$analise['resumo']['ok'] ?></span>
                    <span class="badge bg-warning text-dark">Substitui PIS existente: <?= (int)$analise['resumo']['substitui'] ?></span>
                    <span class="badge bg-secondary">Sem mudança: <?= (int)$analise['resumo']['inalterado'] ?></span>
                    <span class="badge bg-danger">Com erro: <?= (int)$analise['resumo']['erro'] ?></span>
                </div>

                <?php if ((int)$analise['resumo']['erro'] > 0): ?>
                    <div class="alert alert-warning py-2 small">
                        As linhas com erro <strong>não serão gravadas</strong>. Corrija-as no arquivo e
                        importe de novo — as já aplicadas ficarão como "sem mudança".
                    </div>
                <?php endif; ?>

                <div class="table-responsive" style="max-height:60vh;">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Linha</th><th>Colaborador</th><th>CPF</th><th>PIS</th>
                                <th>Matrícula</th><th>Situação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($analise['itens'] as $it): ?>
                            <?php
                            $cor = match ($it['status']) {
                                'ok'         => 'table-success',
                                'substitui'  => 'table-warning',
                                'erro'       => 'table-danger',
                                default      => '',
                            };
                            ?>
                            <tr class="<?= $cor ?>">
                                <td class="text-muted small"><?= (int)$it['linha'] ?></td>
                                <td><?= esc($it['nome'] ?: '—') ?></td>
                                <td class="small"><code><?= esc($it['cpf'] ?: '—') ?></code></td>
                                <td class="small"><code><?= esc($it['pis'] ?: '—') ?></code></td>
                                <td class="small"><?= esc($it['matricula'] ?: '—') ?></td>
                                <td class="small"><?= esc($it['detalhe']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr>
                <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="acao" value="aplicar">
                    <input type="hidden" name="payload" value="<?= esc(json_encode($aplicaveis, JSON_UNESCAPED_UNICODE)) ?>">
                    <button class="btn btn-primary" <?= empty($aplicaveis) ? 'disabled' : '' ?>>
                        <i class="bi bi-check-lg me-2"></i>
                        Gravar <?= count($aplicaveis) ?> colaborador(es)
                    </button>
                    <a href="import_pis.php" class="btn btn-outline-secondary">Cancelar e enviar outro arquivo</a>
                </form>
            </div>
        </section>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

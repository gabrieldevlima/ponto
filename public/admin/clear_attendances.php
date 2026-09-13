<?php
/**
 * Endpoint REMOVIDO — apagamento em massa de registros de ponto não é mais
 * permitido pelo painel admin.
 *
 * Motivo: registros de ponto eletrônico são documento legal (Portaria MTP
 * 671/2021, art. 84). Qualquer alteração / remoção precisa de trilha de
 * auditoria, não de uma operação destrutiva em uma tela com checkbox.
 *
 * Mantido como stub para que bookmarks antigos retornem 410 Gone com
 * mensagem clara, em vez de 404 sem contexto.
 */
require_once __DIR__ . '/../../config.php';
require_admin();

http_response_code(410);
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Recurso removido | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>
    <div class="container-fluid admin-content py-4">
        <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-shield-exclamation fs-4 mt-1"></i>
            <div>
                <h5 class="alert-heading mb-2">Recurso removido</h5>
                <p class="mb-2">
                    O apagamento em massa de registros de ponto foi descontinuado.
                    Registros de ponto eletrônico são documento legal e não podem
                    ser removidos por uma única ação no painel.
                </p>
                <p class="mb-0">
                    Para correções pontuais, use a edição individual em
                    <a href="attendances.php" class="alert-link">Registros &rarr; Marcações</a>.
                    Toda edição fica registrada na trilha de auditoria.
                </p>
            </div>
        </div>
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Voltar ao painel
        </a>
    </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>

<?php
/**
 * Cabeçalho HTML compartilhado das telas administrativas.
 *
 * Uso: defina $titulo e inclua ANTES de _navbar.php.
 *
 * Existe porque `export_afd.php`, `export_aej.php` e `timesheet_mirror.php`
 * definiam $titulo e chamavam _navbar.php direto, sem emitir doctype nem
 * carregar o Bootstrap — saíam como HTML solto e sem estilo. Não aparecia
 * porque nenhuma das três estava no menu; passaram a estar em 2026-08-07.
 *
 * As demais telas emitem o próprio <head> inline. Não foram migradas para cá
 * nesta passagem: seria mexer em ~40 arquivos sem necessidade.
 */
if (defined('APP_ADMIN_HEAD_RENDERED')) return;
define('APP_ADMIN_HEAD_RENDERED', true);
?><!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($titulo ?? 'Administração') ?> | DEEDO Ponto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <?php /* css/admin.css, como nas demais telas. Apontava para ../assets/admin.css,
             arquivo que nunca existiu: as três telas deste include saíam com o
             Bootstrap mas sem o estilo do admin. */ ?>
    <link href="css/admin.css" rel="stylesheet">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>

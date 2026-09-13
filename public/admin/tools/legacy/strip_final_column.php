<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/teachers.php';
$content = file_get_contents($file);

$target_header = '<th><?= sort_link(\'institution\', \'Instituição\') ?></th>';
$content = str_replace($target_header, '', $content);

$target_search = 'placeholder="Buscar por nome, email ou CPF"';
$replacement_search = 'placeholder="Buscar por nome ou CPF"';
$content = str_replace($target_search, $replacement_search, $content);

file_put_contents($file, $content);

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared.\n";
}
echo "File modified via str_replace.\n";
?>

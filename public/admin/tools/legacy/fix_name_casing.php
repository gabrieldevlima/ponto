<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/teachers.php';
$content = file_get_contents($file);

$target = '<td><?= esc($t[\'name\']) ?></td>';
$replacement = '<td><?= esc(mb_convert_case($t[\'name\'], MB_CASE_TITLE, \'UTF-8\')) ?></td>';
$content = str_replace($target, $replacement, $content);

file_put_contents($file, $content);

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared.\n";
}
echo "Name casing updated via str_replace.\n";
?>

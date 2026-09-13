<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/attendances.php';
$content = file_get_contents($file);

$old = "esc(\$r['name'])";
$new = "esc(mb_convert_case(\$r['name'] ?? '', MB_CASE_TITLE, 'UTF-8'))";

if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    echo "Name casing applied.\n";
}
else {
    echo "String not found.\n";
}

file_put_contents($file, $content);
if (function_exists('opcache_reset'))
    opcache_reset();
?>

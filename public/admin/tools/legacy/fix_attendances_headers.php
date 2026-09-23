<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/attendances.php';
$content = file_get_contents($file);

// Targeted regex replacement for the headers
$pattern = '/<th>Carga Horária<\/th>\s*<th>Comprovantes<\/th>\s*<th>Status<\/th>\s*<th>Origem\/Justificativa<\/th>\s*<th>Edição<\/th>/si';
$replacement = '<th>Status</th>';

if (preg_match($pattern, $content)) {
    $content = preg_replace($pattern, $replacement, $content);
    echo "Headers replaced successfully.\n";
}
else {
    echo "Header pattern not found.\n";
}

file_put_contents($file, $content);

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared.\n";
}
?>

<?php
// Fix the dropdown button in attendances.php to match the style in teachers.php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/attendances.php';
$content = file_get_contents($file);

// The current button uses: btn btn-light btn-sm border
// The teachers button uses: btn btn-sm btn-outline-secondary dropdown-toggle
$old_button = '<button class="btn btn-light btn-sm border" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Ações">';
$new_button = '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">';

if (strpos($content, $old_button) !== false) {
    $content = str_replace($old_button, $new_button, $content);
    echo "Button class updated successfully.\n";
}
else {
    echo "Target button string not found.\n";
}

file_put_contents($file, $content);

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared.\n";
}
?>

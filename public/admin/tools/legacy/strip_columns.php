<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/teachers.php';
$content = file_get_contents($file);

// Remove Email header
$content = preg_replace('/<th[^>]*><\?= sort_link\(\'email\', \'Email\'\) \?><\/th>\s*/', '', $content);
// Remove Institution header
$content = preg_replace('/<th[^>]*><\?= sort_link\(\'institution\', \'Institui\w+\'\) \?><\/th>\s*/', '', $content);
// Update Colspan
$content = preg_replace('/colspan="7"/', 'colspan="5"', $content);
// Remove Email body
$content = preg_replace('/<td><\?= esc\(\$t\[\'email\'\]\) \?><\/td>\s*/', '', $content);

// Remove Institution body (matches the whole <td>...badge bg-primary...</td> block)
$pattern = '/<td>\s*<\?php if \(\$t\[\'network_wide\'\] == 1\): \?>\s*<span class="badge bg-primary">Toda a Rede<\/span>\s*<\?php else: \?>\s*<\?= esc\(\$t\[\'schools_list\'\] \?\? \'-\'\) \?>\s*<\?php endif; \?>\s*<\/td>\s*/s';
$content = preg_replace($pattern, '', $content);

file_put_contents($file, $content);
echo "Columns removed successfully via PHP regex.\n";
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared.\n";
}
?>

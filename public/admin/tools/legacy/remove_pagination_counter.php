<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/_pagination.php';
$content = file_get_contents($file);

// Remove the counter block using regex
$pattern = '/\s*<\?php if \(!empty\(\$totalItems\) && isset\(\$perPage\)\): \?>\s*<span class="text-muted small">.*?<\/span>\s*<\?php else: \?>\s*<span><\/span>\s*<\?php endif; \?>/s';
$new = preg_replace($pattern, '', $content);

if ($new !== null && $new !== $content) {
    file_put_contents($file, $new);
    if (function_exists('opcache_reset'))
        opcache_reset();
    echo "Counter removed OK\n";
}
else {
    // fallback si regex falha: str_replace block by block
    $blocks = [
        "  <?php if (!empty(\$totalItems) && isset(\$perPage)): ?>\r\n    <span class=\"text-muted small\">\r\n      <?php\r\n      \$from = (\$currentPage - 1) * \$perPage + 1;\r\n      \$to = min(\$currentPage * \$perPage, \$totalItems);\r\n      echo \$totalItems > 0 ? (\$from . '–' . \$to . ' de ' . \$totalItems) : '0 registros';\r\n      ?>\r\n    </span>\r\n  <?php else: ?>\r\n    <span></span>\r\n  <?php endif; ?>\r\n",
        "  <?php if (!empty(\$totalItems) && isset(\$perPage)): ?>\n    <span class=\"text-muted small\">\n      <?php\n      \$from = (\$currentPage - 1) * \$perPage + 1;\n      \$to = min(\$currentPage * \$perPage, \$totalItems);\n      echo \$totalItems > 0 ? (\$from . '–' . \$to . ' de ' . \$totalItems) : '0 registros';\n      ?>\n    </span>\n  <?php else: ?>\n    <span></span>\n  <?php endif; ?>\n",
    ];
    foreach ($blocks as $b) {
        if (strpos($content, $b) !== false) {
            $new = str_replace($b, '', $content);
            file_put_contents($file, $new);
            if (function_exists('opcache_reset'))
                opcache_reset();
            echo "Counter removed via str_replace OK\n";
            break;
        }
    }
    if ($new === $content)
        echo "Could not match pattern.\n";
}
?>

<?php
// Fix the duplicated "Ativa" checkbox in school_edit.php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/school_edit.php';
$lines = file($file);
$out = [];
$skipCount = 0;
$foundFirst = false;

foreach ($lines as $line) {
    $trim = rtrim($line);

    // Detect the original "form-check mb-3 mt-2" div that we replaced but didn't fully remove
    if ($trim === '          <div class="form-check mb-3 mt-2">') {
        // Skip this line and the next 3 (the old duplicated checkbox block)
        $skipCount = 4;
        continue;
    }

    if ($skipCount > 0) {
        $skipCount--;
        continue;
    }

    $out[] = $line;
}

file_put_contents($file, implode('', $out));
if (function_exists('opcache_reset'))
    opcache_reset();
echo "Done. Lines: " . count($out) . "\n";
?>

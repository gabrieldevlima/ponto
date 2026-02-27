<?php
// Arquivo de diagnóstico temporário
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>Teste de Helpers.php</h2>";

try {
    require_once __DIR__ . '/config.php';
    echo "✅ config.php carregado<br>";
    
    if (function_exists('get_directory_size')) {
        echo "✅ Função get_directory_size existe<br>";
    } else {
        echo "❌ Função get_directory_size NÃO EXISTE<br>";
    }
    
    if (function_exists('cleanup_old_photos')) {
        echo "✅ Função cleanup_old_photos existe<br>";
    } else {
        echo "❌ Função cleanup_old_photos NÃO EXISTE<br>";
    }
    
    if (defined('PHOTO_RETENTION_DAYS')) {
        echo "✅ PHOTO_RETENTION_DAYS = " . PHOTO_RETENTION_DAYS . "<br>";
    } else {
        echo "❌ PHOTO_RETENTION_DAYS não definida<br>";
    }
    
    if (defined('PHOTO_STORAGE_THRESHOLD_MB')) {
        echo "✅ PHOTO_STORAGE_THRESHOLD_MB = " . PHOTO_STORAGE_THRESHOLD_MB . "<br>";
    } else {
        echo "❌ PHOTO_STORAGE_THRESHOLD_MB não definida<br>";
    }
    
    if (defined('PHOTO_CLEANUP_ENABLED')) {
        echo "✅ PHOTO_CLEANUP_ENABLED = " . (PHOTO_CLEANUP_ENABLED ? 'true' : 'false') . "<br>";
    } else {
        echo "❌ PHOTO_CLEANUP_ENABLED não definida<br>";
    }
    
    echo "<br><strong>Versão do PHP:</strong> " . phpversion() . "<br>";
    echo "<strong>Caminho do helpers.php:</strong> " . __DIR__ . "/helpers.php<br>";
    
    if (file_exists(__DIR__ . "/helpers.php")) {
        echo "✅ Arquivo helpers.php existe<br>";
        echo "<strong>Tamanho:</strong> " . filesize(__DIR__ . "/helpers.php") . " bytes<br>";
        echo "<strong>Última modificação:</strong> " . date("Y-m-d H:i:s", filemtime(__DIR__ . "/helpers.php")) . "<br>";
    } else {
        echo "❌ Arquivo helpers.php NÃO EXISTE<br>";
    }
    
} catch (Throwable $e) {
    echo "<h3 style='color:red'>ERRO CAPTURADO:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<br><hr><p><strong>DELETE este arquivo após o diagnóstico!</strong></p>";
?>


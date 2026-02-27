<?php
// Script para debugar erro no checkin.php
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

echo "=== DEBUG CHECKIN.PHP ===\n\n";

// Simular ambiente do checkin
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Debug Script';

// Criar um input fake
$fakeInput = json_encode([
    'pin' => '123456',
    'photo' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==',
    'geo' => [
        'lat' => -23.5505,
        'lng' => -46.6333,
        'acc' => 10
    ]
]);

// Salvar em php://input simulado
file_put_contents('php://memory', $fakeInput);

echo "Testando inclusão do checkin.php...\n\n";

try {
    // Capturar output
    ob_start();
    
    // Incluir o arquivo
    include __DIR__ . '/checkin.php';
    
    $output = ob_get_clean();
    
    echo "✅ SUCESSO!\n\n";
    echo "Output:\n";
    echo $output;
    
} catch (Throwable $e) {
    ob_end_clean();
    
    echo "❌ ERRO CAPTURADO:\n\n";
    echo "Tipo: " . get_class($e) . "\n";
    echo "Mensagem: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
}


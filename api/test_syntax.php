<?php
// Script para testar sintaxe e erros
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

echo "=== TESTE DE SINTAXE PHP ===\n\n";

// Verificar se o arquivo existe
$arquivo = __DIR__ . '/checkin.php';
if (!file_exists($arquivo)) {
    echo "ERRO: Arquivo checkin.php não encontrado!\n";
    exit;
}

echo "✓ Arquivo encontrado\n";
echo "✓ Tamanho: " . filesize($arquivo) . " bytes\n\n";

// Testar sintaxe
$output = [];
$return_var = 0;
exec("php -l " . escapeshellarg($arquivo) . " 2>&1", $output, $return_var);

echo "=== VERIFICAÇÃO DE SINTAXE ===\n";
echo implode("\n", $output) . "\n\n";

if ($return_var !== 0) {
    echo "❌ ERRO DE SINTAXE ENCONTRADO!\n";
    exit;
}

echo "✓ Sintaxe OK\n\n";

// Tentar incluir (vai mostrar erros de runtime)
echo "=== TENTANDO CARREGAR O ARQUIVO ===\n";
try {
    ob_start();
    // Não executa, só verifica erros de parse
    $code = file_get_contents($arquivo);
    
    // Procurar por erros óbvios
    $erros = [];
    
    // Verificar variáveis indefinidas
    if (preg_match('/\$extraChecks[^=]/', $code)) {
        echo "⚠️ Variável \$extraChecks pode não estar definida em alguns caminhos\n";
    }
    
    // Verificar loops aninhados
    $whileCount = substr_count($code, 'while');
    echo "✓ Loops while encontrados: $whileCount\n";
    
    // Verificar if/else
    $ifCount = substr_count($code, 'if (');
    echo "✓ Condições if encontradas: $ifCount\n";
    
    ob_end_clean();
    echo "\n✓ Arquivo carregado sem erros óbvios\n";
    
} catch (Throwable $e) {
    ob_end_clean();
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
}

echo "\n=== LOGS DE ERRO DO PHP ===\n";
$error_log = ini_get('error_log');
if ($error_log && file_exists($error_log)) {
    echo "Últimas linhas do log:\n";
    echo shell_exec("tail -20 " . escapeshellarg($error_log));
} else {
    echo "Log de erros não configurado ou não encontrado\n";
}


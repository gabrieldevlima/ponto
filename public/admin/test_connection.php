<?php
// Arquivo de diagnóstico - pode ser removido após resolver o problema
echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Teste de Conexão</title></head><body>";
echo "<h1>✓ Servidor PHP está funcionando</h1>";
echo "<p>Se você está vendo esta mensagem, o servidor PHP está processando arquivos corretamente.</p>";

try {
    require_once __DIR__ . '/../../config.php';
    echo "<p>✓ Config.php carregado com sucesso</p>";
} catch (Throwable $e) {
    echo "<p>✗ Erro ao carregar config.php: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

try {
    $pdo = db();
    echo "<p>✓ Conexão com banco de dados estabelecida</p>";
} catch (Throwable $e) {
    echo "<p>✗ Erro ao conectar com banco: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

echo "<p>✓ Helpers carregados com sucesso</p>";
echo "<p><strong>Tudo funcionando corretamente!</strong></p>";
echo "<p><a href='teachers.php'>Voltar para colaboradores</a></p>";
echo "</body></html>";
?>


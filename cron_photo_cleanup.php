#!/usr/bin/env php
<?php
/**
 * Script CRON para Limpeza de Fotos Antigas
 * =========================================
 * 
 * Este script deve ser executado via cron para limpar fotos antigas
 * sem impactar a performance do registro de ponto.
 * 
 * CONFIGURAÇÃO DO CRON (executar diariamente às 2h da manhã):
 * 0 2 * * * cd /caminho/para/ponto_ribeira2 && php cron_photo_cleanup.php >> logs/photo_cleanup.log 2>&1
 * 
 * Ou executar semanalmente aos domingos:
 * 0 2 * * 0 cd /caminho/para/ponto_ribeira2 && php cron_photo_cleanup.php >> logs/photo_cleanup.log 2>&1
 */

// Garante que está rodando via CLI
if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando (CLI)\n");
}

// Carrega configurações
require_once __DIR__ . '/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Iniciando limpeza de fotos antigas...\n";

try {
    if (function_exists('cleanup_old_photos')) {
        $stats = cleanup_old_photos($pdo);
        
        if ($stats['status'] === 'completed') {
            echo "[" . date('Y-m-d H:i:s') . "] ✓ Limpeza concluída com sucesso!\n";
            echo "  - Fotos deletadas: {$stats['deleted_count']}\n";
            echo "  - Espaço liberado: " . number_format($stats['freed_space_mb'], 2) . " MB\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] ⚠ Limpeza não executada: {$stats['status']}\n";
        }
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ✗ Função cleanup_old_photos() não encontrada\n";
        echo "  Verifique se está definida em helpers.php\n";
    }
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ✗ ERRO: " . $e->getMessage() . "\n";
    echo "  Arquivo: " . $e->getFile() . " (linha " . $e->getLine() . ")\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Finalizado.\n";
exit(0);


<?php
// Diagnóstico rápido do backend
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

$resultado = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'diagnostico' => []
];

try {
    $pdo = db();
    
    // 0. Informações básicas
    try {
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $resultado['diagnostico']['banco'] = $dbName;
    } catch (Exception $e) {
        $resultado['diagnostico']['banco'] = 'ERRO: ' . $e->getMessage();
    }
    
    // 1. Verificar triggers
    try {
        $stmt = $pdo->query("SHOW TRIGGERS WHERE `Table` = 'attendance'");
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $resultado['diagnostico']['triggers'] = [
            'total' => count($triggers),
            'lista' => $triggers,
            'problematicos' => []
        ];
        
        foreach ($triggers as $trigger) {
            if (in_array($trigger['Trigger'], ['attendance_update_audit', 'attendance_delete_audit', 'attendance_before_insert_nsr'])) {
                $resultado['diagnostico']['triggers']['problematicos'][] = $trigger['Trigger'];
            }
        }
    } catch (Exception $e) {
        $resultado['diagnostico']['triggers'] = 'ERRO: ' . $e->getMessage();
    }
    
    // 2. Verificar coluna NSR
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'nsr'");
        $nsrColumn = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $resultado['diagnostico']['nsr'] = [
            'tipo' => $nsrColumn['Type'] ?? 'N/A',
            'null' => $nsrColumn['Null'] ?? 'N/A',
            'extra' => $nsrColumn['Extra'] ?? 'N/A',
            'default' => $nsrColumn['Default'] ?? 'N/A'
        ];
    } catch (Exception $e) {
        $resultado['diagnostico']['nsr'] = 'ERRO: ' . $e->getMessage();
    }
    
    // 3. Verificar último NSR
    try {
        $stmt = $pdo->query("SELECT MAX(nsr) as max_nsr, MIN(nsr) as min_nsr, COUNT(*) as total FROM attendance");
        $nsrInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $resultado['diagnostico']['ultimos_nsr'] = $nsrInfo;
    } catch (Exception $e) {
        $resultado['diagnostico']['ultimos_nsr'] = 'ERRO: ' . $e->getMessage();
    }
    
    // 4. Teste de performance de SELECT MAX(nsr)
    try {
        $inicio = microtime(true);
        $stmt = $pdo->query("SELECT COALESCE(MAX(nsr), 0) + 1 as next_nsr FROM attendance");
        $nextNsr = $stmt->fetchColumn();
        $tempoNSR = round((microtime(true) - $inicio) * 1000, 2);
        
        $resultado['diagnostico']['performance'] = [
            'select_max_nsr_ms' => $tempoNSR,
            'proximo_nsr' => $nextNsr,
            'velocidade' => $tempoNSR < 50 ? '✅ RAPIDO' : ($tempoNSR < 200 ? '⚠️ OK' : '❌ LENTO')
        ];
    } catch (Exception $e) {
        $resultado['diagnostico']['performance'] = 'ERRO: ' . $e->getMessage();
    }
    
    // 5. Verificar índices
    try {
        $stmt = $pdo->query("SHOW INDEX FROM attendance WHERE Column_name = 'nsr'");
        $indices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $resultado['diagnostico']['indices_nsr'] = count($indices) > 0 ? $indices : 'Nenhum índice encontrado';
    } catch (Exception $e) {
        $resultado['diagnostico']['indices_nsr'] = 'ERRO: ' . $e->getMessage();
    }
    
    // 6. Informações do servidor
    try {
        $mysqlVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
        $arquivoCheckin = __DIR__ . '/checkin.php';
        
        $resultado['diagnostico']['servidor'] = [
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysqlVersion,
            'arquivo_existe' => file_exists($arquivoCheckin),
            'arquivo_modificado' => file_exists($arquivoCheckin) ? date('Y-m-d H:i:s', filemtime($arquivoCheckin)) : 'N/A',
            'arquivo_tamanho' => file_exists($arquivoCheckin) ? filesize($arquivoCheckin) . ' bytes' : 'N/A'
        ];
    } catch (Exception $e) {
        $resultado['diagnostico']['servidor'] = 'ERRO: ' . $e->getMessage();
    }
    
    // 7. Verificar se código novo está ativo
    try {
        $arquivoCheckin = __DIR__ . '/checkin.php';
        if (file_exists($arquivoCheckin)) {
            $conteudo = file_get_contents($arquivoCheckin);
            $resultado['diagnostico']['codigo'] = [
                'tem_otimizacao_v3' => strpos($conteudo, 'OTIMIZAÇÃO V3') !== false ? '✅ SIM' : '❌ NÃO',
                'tem_for_update' => strpos($conteudo, 'FOR UPDATE') !== false ? '❌ SIM (problema!)' : '✅ NÃO',
                'tamanho_arquivo' => strlen($conteudo) . ' bytes',
                'primeiros_50_chars' => substr($conteudo, 0, 50)
            ];
        } else {
            $resultado['diagnostico']['codigo'] = '❌ Arquivo checkin.php não encontrado';
        }
    } catch (Exception $e) {
        $resultado['diagnostico']['codigo'] = 'ERRO: ' . $e->getMessage();
    }
    
    // 8. Teste rápido de INSERT simulado
    try {
        $inicio = microtime(true);
        $pdo->beginTransaction();
        
        // Simula busca de NSR
        $stmt = $pdo->query("SELECT COALESCE(MAX(nsr), 0) + 1 as next_nsr FROM attendance");
        $nextNsr = $stmt->fetchColumn();
        
        $pdo->rollBack();
        $tempoTotal = round((microtime(true) - $inicio) * 1000, 2);
        
        $resultado['diagnostico']['teste_insert_simulado'] = [
            'tempo_ms' => $tempoTotal,
            'status' => $tempoTotal < 100 ? '✅ RAPIDO' : ($tempoTotal < 500 ? '⚠️ OK' : '❌ LENTO')
        ];
    } catch (Exception $e) {
        $resultado['diagnostico']['teste_insert_simulado'] = 'ERRO: ' . $e->getMessage();
    }
    
    // DIAGNÓSTICO FINAL
    $problemas = [];
    
    if (isset($resultado['diagnostico']['triggers']['problematicos']) && count($resultado['diagnostico']['triggers']['problematicos']) > 0) {
        $problemas[] = '❌ TRIGGERS PROBLEMÁTICOS: ' . implode(', ', $resultado['diagnostico']['triggers']['problematicos']);
    }
    
    if (isset($resultado['diagnostico']['codigo']['tem_for_update']) && $resultado['diagnostico']['codigo']['tem_for_update'] === '❌ SIM (problema!)') {
        $problemas[] = '❌ CÓDIGO AINDA TEM "FOR UPDATE" (código antigo não foi substituído)';
    }
    
    if (isset($resultado['diagnostico']['codigo']['tem_otimizacao_v3']) && $resultado['diagnostico']['codigo']['tem_otimizacao_v3'] === '❌ NÃO') {
        $problemas[] = '❌ CÓDIGO NÃO TEM "OTIMIZAÇÃO V3" (arquivo não foi atualizado)';
    }
    
    if (isset($resultado['diagnostico']['performance']['select_max_nsr_ms']) && $resultado['diagnostico']['performance']['select_max_nsr_ms'] > 500) {
        $problemas[] = '⚠️ SELECT MAX(nsr) muito lento (' . $resultado['diagnostico']['performance']['select_max_nsr_ms'] . 'ms) - índices ou tabela grande';
    }
    
    if (empty($problemas)) {
        $resultado['diagnostico']['status_final'] = '✅ TUDO OK - Sistema otimizado corretamente';
        $resultado['diagnostico']['acoes'] = ['Nenhuma ação necessária'];
    } else {
        $resultado['diagnostico']['status_final'] = '❌ PROBLEMAS ENCONTRADOS';
        $resultado['diagnostico']['problemas'] = $problemas;
        $resultado['diagnostico']['acoes_recomendadas'] = [];
        
        foreach ($problemas as $problema) {
            if (strpos($problema, 'TRIGGERS') !== false) {
                $resultado['diagnostico']['acoes_recomendadas'][] = '1. Execute: DROP TRIGGER IF EXISTS attendance_update_audit; DROP TRIGGER IF EXISTS attendance_delete_audit;';
            }
            if (strpos($problema, 'FOR UPDATE') !== false || strpos($problema, 'OTIMIZAÇÃO V3') !== false) {
                $resultado['diagnostico']['acoes_recomendadas'][] = '2. Reenvie o arquivo api/checkin.php para o servidor';
                $resultado['diagnostico']['acoes_recomendadas'][] = '3. Limpe o cache do PHP (OPcache) no hPanel';
            }
        }
    }
    
} catch (Exception $e) {
    $resultado['status'] = 'error';
    $resultado['erro'] = $e->getMessage();
    $resultado['trace'] = $e->getTraceAsString();
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

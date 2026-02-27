<?php
/**
 * Script de Diagnóstico - Identifica problema de login
 * Execute via navegador e DELETE depois!
 */

echo "<h2>🔍 Diagnóstico do Sistema</h2>";
echo "<hr>";

// Teste 1: Conexão com Banco
echo "<h3>1. Teste de Conexão com Banco de Dados</h3>";
try {
    require_once __DIR__ . '/config.php';
    $pdo = db();
    echo "✅ Conexão OK!<br>";
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "<br>";
    echo "⚠️ Verifique config.php!<br>";
    exit;
}

// Teste 2: Tabela admins existe?
echo "<h3>2. Verificando Tabela 'admins'</h3>";
try {
    $result = $pdo->query("SHOW TABLES LIKE 'admins'");
    if ($result->rowCount() > 0) {
        echo "✅ Tabela 'admins' existe!<br>";
    } else {
        echo "❌ Tabela 'admins' NÃO EXISTE!<br>";
        echo "⚠️ Execute install_production_complete.sql no phpMyAdmin<br>";
        exit;
    }
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "<br>";
    exit;
}

// Teste 3: Estrutura da tabela
echo "<h3>3. Estrutura da Tabela 'admins'</h3>";
try {
    $cols = $pdo->query("DESCRIBE admins")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th></tr>";
    foreach ($cols as $col) {
        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "<br>";
}

// Teste 4: Quantos admins existem?
echo "<h3>4. Admins Cadastrados</h3>";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    echo "📊 Total de admins: <strong>$count</strong><br>";
    
    if ($count == 0) {
        echo "⚠️ Nenhum admin cadastrado!<br>";
        echo "💡 Executando criação automática...<br><br>";
        
        // Cria admin automaticamente
        $username = 'admin';
        $password = 'admin123';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $insert = $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?, ?, 'network_admin')");
        $insert->execute([$username, $hash]);
        
        echo "✅ Admin criado!<br>";
        echo "🔑 Usuário: <strong>$username</strong><br>";
        echo "🔑 Senha: <strong>$password</strong><br>";
    } else {
        // Lista admins existentes
        $admins = $pdo->query("SELECT id, username, role FROM admins")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Usuário</th><th>Role</th></tr>";
        foreach ($admins as $adm) {
            echo "<tr><td>{$adm['id']}</td><td>{$adm['username']}</td><td>{$adm['role']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "<br>";
}

// Teste 5: Teste de Senha
echo "<h3>5. Teste de Validação de Senha</h3>";
try {
    $testUser = 'admin';
    $testPass = 'admin123';
    
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM admins WHERE username = ?");
    $stmt->execute([$testUser]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        echo "✅ Usuário '$testUser' encontrado (ID: {$admin['id']})<br>";
        echo "🔐 Hash no banco: <code style='font-size:10px;'>" . substr($admin['password_hash'], 0, 50) . "...</code><br>";
        
        // Testa senha
        if (password_verify($testPass, $admin['password_hash'])) {
            echo "✅ <strong style='color:green;'>Senha '$testPass' VÁLIDA!</strong><br>";
            echo "🎉 Login deveria funcionar!<br>";
        } else {
            echo "❌ <strong style='color:red;'>Senha '$testPass' INVÁLIDA!</strong><br>";
            echo "💡 Resetando senha...<br><br>";
            
            // Reseta senha
            $newHash = password_hash($testPass, PASSWORD_BCRYPT);
            $update = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE username = ?");
            $update->execute([$newHash, $testUser]);
            
            echo "✅ Senha resetada!<br>";
            echo "🔑 Novo hash gerado e salvo<br>";
            echo "🔄 Tente fazer login novamente<br>";
        }
    } else {
        echo "❌ Usuário '$testUser' NÃO encontrado!<br>";
        echo "💡 Criando admin...<br><br>";
        
        $hash = password_hash($testPass, PASSWORD_BCRYPT);
        $insert = $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?, ?, 'network_admin')");
        $insert->execute([$testUser, $hash]);
        
        echo "✅ Admin criado!<br>";
        echo "🔑 Usuário: <strong>$testUser</strong><br>";
        echo "🔑 Senha: <strong>$testPass</strong><br>";
    }
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "<br>";
}

// Teste 6: Versão do PHP
echo "<h3>6. Informações do Servidor</h3>";
echo "🐘 Versão PHP: <strong>" . PHP_VERSION . "</strong><br>";
echo "💾 PDO disponível: " . (extension_loaded('pdo') ? '✅ Sim' : '❌ Não') . "<br>";
echo "🔐 password_hash disponível: " . (function_exists('password_hash') ? '✅ Sim' : '❌ Não') . "<br>";

echo "<hr>";
echo "<h3>🎯 Próximos Passos</h3>";
echo "1. <a href='public/admin/login.php' target='_blank'><strong>Ir para Página de Login</strong></a><br>";
echo "2. Use: <strong>admin / admin123</strong><br>";
echo "3. <strong style='color:red;'>DELETE este arquivo (diagnostic.php)</strong> após usar!<br>";


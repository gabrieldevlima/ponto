<?php
/**
 * FIX LOGIN - Cria admin funcional
 * Execute via navegador e DELETE imediatamente depois!
 */

// Carrega config
require_once __DIR__ . '/config.php';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Fix Login</title>";
echo "<style>body{font-family:Arial;padding:20px;max-width:600px;margin:0 auto;}";
echo "h2{color:#0d6efd;}code{background:#f0f0f0;padding:2px 6px;border-radius:3px;}";
echo ".success{color:green;font-weight:bold;}.error{color:red;font-weight:bold;}</style></head><body>";

echo "<h2>🔧 Fix Login - DEEDO Ponto</h2>";
echo "<hr>";

try {
    $pdo = db();
    echo "<p class='success'>✅ Conexão com banco OK</p>";
    
    // 1. Verifica/cria tabela admins
    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('network_admin','school_admin') NOT NULL DEFAULT 'network_admin',
        school_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    echo "<p class='success'>✅ Tabela 'admins' verificada</p>";
    
    // 2. Deleta admin existente (se houver)
    $pdo->exec("DELETE FROM admins WHERE username = 'admin'");
    echo "<p>🗑️ Limpou admin antigo</p>";
    
    // 3. Cria novo admin com hash GARANTIDO
    $username = 'admin';
    $password = 'admin123';
    
    // Gera hash AQUI no servidor de produção (CRITICAL!)
    $hash = password_hash($password, PASSWORD_BCRYPT);
    
    // FORÇA inserção (sem IGNORE)
    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?, ?, 'network_admin')");
    $stmt->execute([$username, $hash]);
    
    echo "<p class='success'>✅ Novo admin criado com hash gerado no servidor!</p>";
    echo "<hr>";
    echo "<h3>🔑 Credenciais:</h3>";
    echo "<p><strong>Usuário:</strong> <code>admin</code></p>";
    echo "<p><strong>Senha:</strong> <code>admin123</code></p>";
    echo "<hr>";
    
    // 4. Testa imediatamente
    $check = $pdo->prepare("SELECT id, password_hash FROM admins WHERE username = ?");
    $check->execute([$username]);
    $admin = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($admin && password_verify($password, $admin['password_hash'])) {
        echo "<p class='success'>✅ TESTE: Senha validada com sucesso!</p>";
        echo "<p class='success'>✅ Login DEVE funcionar agora!</p>";
    } else {
        echo "<p class='error'>❌ TESTE FALHOU: Senha não valida</p>";
        echo "<p>Hash no banco: " . substr($admin['password_hash'], 0, 30) . "...</p>";
    }
    
    echo "<hr>";
    echo "<h3>📍 Próximos Passos:</h3>";
    echo "<ol>";
    echo "<li><a href='public/admin/login.php' style='font-size:18px;font-weight:bold;'>IR PARA PÁGINA DE LOGIN</a></li>";
    echo "<li>Use: <code>admin</code> / <code>admin123</code></li>";
    echo "<li><strong style='color:red;'>DELETE este arquivo imediatamente!</strong></li>";
    echo "</ol>";
    
    echo "<p style='background:#fff3cd;padding:10px;border-radius:5px;'>";
    echo "⚠️ <strong>SEGURANÇA:</strong> Delete <code>fix_login.php</code> após usar!<br>";
    echo "Via FTP ou SSH: <code>rm fix_login.php</code>";
    echo "</p>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ ERRO: " . $e->getMessage() . "</p>";
    echo "<p>Verifique se:</p>";
    echo "<ul>";
    echo "<li>config.php está correto (host, banco, usuário, senha)</li>";
    echo "<li>Banco de dados foi criado na Hostinger</li>";
    echo "<li>Usuário tem permissões no banco</li>";
    echo "</ul>";
}

echo "</body></html>";
?>


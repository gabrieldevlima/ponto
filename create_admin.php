<?php
/**
 * Script para criar/resetar admin padrão
 * Execute UMA VEZ via navegador ou terminal
 * Depois DELETE este arquivo!
 */

require_once __DIR__ . '/config.php';

$pdo = db();

// Credenciais do admin
$username = 'admin';
$password = 'admin123'; // ALTERAR após primeiro login!

// Gera hash da senha
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

// Verifica se admin já existe
$check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
$check->execute([$username]);
$existing = $check->fetch();

if ($existing) {
    // Atualiza senha do admin existente
    $update = $pdo->prepare("UPDATE admins SET password_hash = ?, role = 'network_admin' WHERE username = ?");
    $update->execute([$passwordHash, $username]);
    echo "✅ Senha do admin '$username' resetada com sucesso!<br>";
    echo "🔑 Usuário: <strong>$username</strong><br>";
    echo "🔑 Senha: <strong>$password</strong><br>";
    echo "⚠️ <strong>ALTERE A SENHA</strong> após fazer login!<br><br>";
} else {
    // Cria novo admin
    $insert = $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?, ?, 'network_admin')");
    $insert->execute([$username, $passwordHash]);
    echo "✅ Admin criado com sucesso!<br>";
    echo "🔑 Usuário: <strong>$username</strong><br>";
    echo "🔑 Senha: <strong>$password</strong><br>";
    echo "⚠️ <strong>ALTERE A SENHA</strong> após fazer login!<br><br>";
}

echo "<hr>";
echo "📍 <a href='public/admin/login.php'>Ir para página de login</a><br><br>";
echo "⚠️ <strong>IMPORTANTE:</strong> DELETE este arquivo (create_admin.php) após usar!<br>";
echo "🗑️ Comando: <code>rm create_admin.php</code> ou delete via FTP<br>";


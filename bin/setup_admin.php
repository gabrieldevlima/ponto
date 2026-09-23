<?php
/**
 * Script de configuração do primeiro admin.
 * Uso: php bin/setup_admin.php
 *
 * Execute APENAS via linha de comando (CLI). Nunca via navegador.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via CLI.' . PHP_EOL);
}

require_once dirname(__DIR__) . '/config.php';

$pdo = db();

$count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
if ($count > 0) {
    echo "Já existe(m) {$count} admin(s) cadastrado(s). Nenhuma ação necessária." . PHP_EOL;
    exit(0);
}

echo "=== Setup do Admin Padrão ===" . PHP_EOL;
echo "Nenhum admin encontrado. Criando admin inicial..." . PHP_EOL;

$cpf = readline("CPF do admin (somente números, 11 dígitos): ");
$cpf = preg_replace('/\D/', '', $cpf);
if (strlen($cpf) !== 11) {
    exit("CPF inválido. Encerrando." . PHP_EOL);
}

$name = readline("Nome do admin: ");
$name = trim($name);
if ($name === '') {
    exit("Nome inválido. Encerrando." . PHP_EOL);
}

$pass = readline("Senha: ");
// NC-43: a checagem era apenas de comprimento (8 caracteres), o que aceitava
// "12345678". Usa a mesma política das telas administrativas.
[$pwdOk, , $pwdMsg] = admin_validate_password($pass, $cpf, $name);
if (!$pwdOk) {
    exit($pwdMsg . " Encerrando." . PHP_EOL);
}

$hash = password_hash($pass, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO admins (username, name, cpf, password_hash, role) VALUES (?, ?, ?, ?, 'network_admin')");
$stmt->execute([$cpf, $name, $cpf, $hash]);

echo "Admin criado com sucesso! CPF: {$cpf}, Nome: {$name}" . PHP_EOL;
echo "Guarde as credenciais em local seguro." . PHP_EOL;

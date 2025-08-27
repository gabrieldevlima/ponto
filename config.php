<?php
declare(strict_types=1);

//VARIAVEIS DO BANCO DE DADOS LOCAL
$servidor = 'localhost';
$usuario = 'root';
$senha = '';
$banco = 'ponto';

session_start();

date_default_timezone_set('America/Sao_Paulo');

define('DB_HOST', $servidor);
define('DB_NAME', $banco);
define('DB_USER', $usuario);
define('DB_PASS', $senha);
define('DB_CHARSET', 'utf8mb4');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

require_once __DIR__ . '/helpers.php';
// Opcional: inicializar admin padrão se não existir
// ensure_default_admin();
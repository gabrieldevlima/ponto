<?php
require_once __DIR__ . '/../config.php';

// Limpa dados de sessão de colaborador (audit log + unset)
collaborator_logout();

// Limpa quaisquer outras chaves de sessão remanescentes que não sejam
// necessárias para a landing de login (ex.: csrf_token, info_msg).
$_SESSION = [];

// Expira o cookie de sessão no browser (cria um novo "vencido") e destrói
// o arquivo de sessão no servidor — impede reuso por browsers com cache.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?: '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => !empty($p['secure']),
            'httponly' => !empty($p['httponly']),
            'samesite' => $p['samesite'] ?? 'Lax',
        ]
    );
}
session_destroy();

// Aceita ?next=login para indicar logout intencional (botão "Não sou eu" na home).
// Login mostra mensagem mais amigável neste caso (sem "sessão expirada").
$next = (string)($_GET['next'] ?? '');
$qs = '?out=1';
if ($next === 'login') $qs = '?out=1&via=switch';
header('Location: login.php' . $qs);
exit;

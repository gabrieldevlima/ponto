<?php
// Legado: redireciona para o novo login unificado (CPF + PIN).
require_once __DIR__ . '/../config.php';
header('Location: login.php', true, 302);
exit;

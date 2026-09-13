<?php
declare(strict_types=1);
/**
 * Limpeza do OPcache — NC-45 (auditoria 2026-08-05).
 *
 * Até esta correção o arquivo não carregava config.php nem chamava
 * require_admin(): era o único endpoint sob public/admin/ sem guarda, e
 * qualquer visitante anônimo podia disparar opcache_reset() repetidamente,
 * forçando recompilação de todo o código a cada requisição (negação de serviço
 * barata).
 *
 * Agora exige admin de rede e POST com CSRF — resetar cache compilado é ação
 * de manutenção, não uma leitura.
 */

require_once __DIR__ . '/../../config.php';
require_admin();

if (!is_network_admin(current_admin())) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acesso restrito a administradores de rede.\n";
    exit;
}

header('Content-Type: text/html; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    if (function_exists('opcache_reset')) {
        $ok = opcache_reset();
        audit_log('system.opcache_reset', 'system', null, ['result' => $ok ? 'ok' : 'failed']);
        echo '<p>' . ($ok ? 'OPCache limpo.' : 'Falha ao limpar o OPCache.') . '</p>';
    } else {
        echo '<p>OPCache não está habilitado neste servidor.</p>';
    }
    echo '<p><a href="dashboard.php">Voltar</a></p>';
    exit;
}

$enabled = function_exists('opcache_reset');
?>
<!doctype html>
<meta charset="utf-8">
<title>Limpar OPCache</title>
<h3>Limpar OPCache</h3>
<p>Status: <?= $enabled ? 'habilitado' : 'não habilitado neste servidor' ?>.</p>
<?php if ($enabled): ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
    <button type="submit">Limpar OPCache agora</button>
</form>
<?php endif; ?>
<p><a href="dashboard.php">Voltar</a></p>

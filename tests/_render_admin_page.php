<?php
/**
 * Renderiza UMA tela administrativa em processo próprio, com sessão simulada,
 * e imprime marcadores do resultado. Auxiliar de tests/test_telas_admin.php.
 *
 * Processo separado por necessidade: as telas emitem HTML, chamam `exit`,
 * declaram funções de mesmo nome entre si e definem constantes de guarda.
 * Carregar várias no mesmo processo produziria falhas que não existem em
 * produção — e, pior, esconderia as que existem.
 *
 * Uso: php tests/_render_admin_page.php <arquivo.php>
 * Saída: uma linha `chave=valor` por marcador. Exit 1 em erro fatal.
 */

$alvo = $argv[1] ?? '';
if ($alvo === '' || !preg_match('/^[a-z0-9_]+\.php$/', $alvo)) {
    fwrite(STDERR, "uso: php tests/_render_admin_page.php <arquivo.php>\n");
    exit(2);
}
$caminho = dirname(__DIR__) . '/public/admin/' . $alvo;
if (!is_file($caminho)) {
    fwrite(STDERR, "tela inexistente: {$alvo}\n");
    exit(2);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/ponto_oeiras/public/admin/' . $alvo;
$_SERVER['PHP_SELF']       = $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI']    = $_SERVER['SCRIPT_NAME'];
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'teste-render';
// Período com dados reais na base de desenvolvimento — telas de exportação
// renderizam o pré-voo a partir dele.
$_GET = ['de' => '2026-06-01', 'ate' => '2026-06-30'];
$_REQUEST = $_GET;

// Parâmetros extras no formato chave=valor, vindos do argv, sobrepõem o $_GET
// padrão. Permite exercitar uma tela em estado específico (ex.: teachers.php
// logo após cadastrar alguém) sem duplicar este runner.
foreach (array_slice($argv, 2) as $parExtra) {
    if (!str_contains($parExtra, '=')) continue;
    [$chaveExtra, $valorExtra] = explode('=', $parExtra, 2);
    $_GET[$chaveExtra] = $valorExtra;
}
$_REQUEST = $_GET;
$_POST = [];

require_once dirname(__DIR__) . '/config.php';

// Sessão de administrador de rede. Não cria nada no banco: só afirma a sessão
// que o login criaria.
$_SESSION['admin_id'] = (int)(db()->query("SELECT id FROM admins WHERE role = 'network_admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
$_SESSION['admin_last_activity'] = time();

ob_start();
try {
    require $caminho;
} catch (Throwable $e) {
    ob_end_clean();
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $e->getFile())
        . ':' . $e->getLine() . "\n");
    exit(1);
}
$html = ob_get_clean();

$marca = fn(string $k, bool $v) => printf("%s=%s\n", $k, $v ? '1' : '0');
printf("bytes=%d\n", strlen($html));
$marca('doctype',   (bool)preg_match('/<!doctype html>/i', $html));
$marca('bootstrap', str_contains($html, 'bootstrap@5'));
$marca('navbar',    str_contains($html, 'navbar'));
$marca('footer',    str_contains($html, '</html>'));
// Aviso do PHP vazado para dentro da página: não derruba, mas é defeito.
$marca('sem_warning', !preg_match('/\b(Warning|Notice|Deprecated|Fatal error)\b:/', $html));
// Com PONTO_RENDER_DUMP=1 o HTML inteiro sai depois dos marcadores, para o
// chamador afirmar sobre o conteúdo e não só sobre a saúde da página.
if (getenv('PONTO_RENDER_DUMP')) {
    echo "---HTML---\n", $html;
}
exit(0);

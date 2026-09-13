<?php
/**
 * Verifica que o HTML renderizado de teacher_edit.php contém os elementos novos:
 *   - coluna "Próx. dia" no cabeçalho da tabela de horários
 *   - inputs <input ... name="time_schedule[N][end_next_day]" ...> para cada weekday
 *
 * Como rodar: php tests\test_html_contains.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
$pdo = db();

$adm = $pdo->query("SELECT id, username, role, school_id FROM admins ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$tId = (int)$pdo->query("SELECT id FROM teachers ORDER BY id LIMIT 1")->fetchColumn();
if (!$adm || !$tId) {
    echo "[ERRO] Precisa admin e teacher cadastrados.\n";
    exit(1);
}

$configPath = str_replace('\\', '\\\\', realpath(__DIR__ . '/../config.php'));
$pagePath = str_replace('\\', '\\\\', realpath(__DIR__ . '/../public/admin/teacher_edit.php'));
$adminId = (int)$adm['id'];
$role = $adm['role'];
$name = $adm['username'];
$school = $adm['school_id'] === null ? 'null' : (int)$adm['school_id'];

$wrapper = <<<PHP
<?php
\$_SERVER['REQUEST_METHOD']='GET';
\$_SERVER['HTTP_HOST']='localhost';
\$_SERVER['SERVER_PORT']='80';
\$_SERVER['REMOTE_ADDR']='127.0.0.1';
\$_SERVER['HTTP_USER_AGENT']='cli';
\$_GET=['id'=>'{$tId}'];
@ini_set('session.use_cookies','0');
require_once '{$configPath}';
\$_SESSION['admin_id']={$adminId};
\$_SESSION['admin_role']='{$role}';
\$_SESSION['admin_name']='{$name}';
\$_SESSION['admin_school_id']={$school};
\$_SESSION['csrf_token']='test';
ob_start();
include '{$pagePath}';
echo ob_get_clean();
PHP;

$tmp = tempnam(sys_get_temp_dir(), 'html_') . '.php';
file_put_contents($tmp, $wrapper);
// Usa o mesmo binário PHP que está rodando este teste (portável entre
// XAMPP/Laragon/etc.), em vez de um caminho fixo.
$html = shell_exec(escapeshellarg(PHP_BINARY) . ' "' . $tmp . '" 2>&1');
unlink($tmp);

$failed = 0;
$passed = 0;

function check(string $label, bool $cond, string $hint = ''): void {
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  [OK]   $label\n";
    } else {
        $failed++;
        echo "  [FAIL] $label";
        if ($hint !== '') echo " — $hint";
        echo "\n";
    }
}

echo "=== HTML contains test: teacher_edit.php (id=$tId) ===\n\n";

check('renderizou (>10kb)', strlen($html) > 10000, 'tamanho: ' . strlen($html));
check('contém cabeçalho "Próx. dia"', strpos($html, 'Próx. dia') !== false);
check('contém input time_schedule[0][end_next_day]', strpos($html, 'time_schedule[0][end_next_day]') !== false);
check('contém input time_schedule[1][end_next_day]', strpos($html, 'time_schedule[1][end_next_day]') !== false);
check('contém input time_schedule[6][end_next_day]', strpos($html, 'time_schedule[6][end_next_day]') !== false);
check('contém classe CSS time-end-next-day', strpos($html, 'time-end-next-day') !== false);
check('contém title "Saída no dia seguinte"', strpos($html, 'Saída no dia seguinte') !== false);
check('NÃO contém mensagem antiga "atravessa a meia-noite"', strpos($html, 'atravessa a meia-noite') === false);
// JS atualizado deve mencionar a flag
check('JS atualizado: nextDayEl', strpos($html, 'nextDayEl') !== false);
check('JS atualizado: endNextDay var', strpos($html, 'endNextDay') !== false);

echo "\n=== Resultado: $passed passou(aram), $failed falhou(aram) ===\n";
exit($failed > 0 ? 1 : 0);

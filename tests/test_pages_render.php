<?php
/**
 * Verifica que as páginas admin afetadas pela feature de turnos noturnos
 * renderizam sem erros fatais ou warnings críticos.
 *
 * Cada página é renderizada em um SUB-PROCESSO PHP separado (escapa de problemas
 * de redeclaração de funções globais e simula melhor o ambiente HTTP real).
 *
 * Como rodar: php tests\test_pages_render.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pdo = db();

// Pega o ID do primeiro admin (não precisa de senha — só simula sessão)
$stAdm = $pdo->query("SELECT id, username, role, school_id FROM admins ORDER BY id LIMIT 1");
$adm = $stAdm->fetch(PDO::FETCH_ASSOC);
if (!$adm) {
    echo "[ERRO] Não há admin cadastrado.\n";
    exit(1);
}
$adminId = (int)$adm['id'];
$adminRole = $adm['role'];
$adminName = $adm['username'];
$adminSchoolId = $adm['school_id'] ?? null;

// Pega ID de um teacher para teacher_edit
$tId = (int)$pdo->query("SELECT id FROM teachers ORDER BY id LIMIT 1")->fetchColumn();

// Pega ID de um registro de ponto ativo para attendance_edit (editor de dia)
$attId = (int)$pdo->query("SELECT id FROM attendance WHERE removed_at IS NULL AND superseded_by_id IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();

$failed = 0;
$passed = 0;

function probe(string $label, string $relPath, array $get = []): void {
    global $failed, $passed, $adminId, $adminRole, $adminName, $adminSchoolId;
    $absPath = realpath(__DIR__ . '/../public/admin/' . $relPath);
    if (!$absPath || !file_exists($absPath)) {
        echo "  [FAIL] $label — arquivo não existe\n";
        $failed++;
        return;
    }

    // Constrói script wrapper inline que será executado por sub-processo PHP
    $getJson = json_encode($get);
    $schoolId = $adminSchoolId === null ? 'null' : (int)$adminSchoolId;
    $configPath = realpath(__DIR__ . '/../config.php');
    $absPathEsc = str_replace('\\', '\\\\', $absPath);
    $configPathEsc = str_replace('\\', '\\\\', $configPath);
    $wrapper = <<<PHP
<?php
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_SERVER['SERVER_PORT'] = '80';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
\$_SERVER['HTTP_USER_AGENT'] = 'cli-render-test';
\$_GET = json_decode('{$getJson}', true);
@ini_set('session.use_cookies', '0');
require_once '{$configPathEsc}';
\$_SESSION['admin_id']   = {$adminId};
\$_SESSION['admin_role'] = '{$adminRole}';
\$_SESSION['admin_name'] = '{$adminName}';
\$_SESSION['admin_school_id'] = {$schoolId};
\$_SESSION['csrf_token'] = 'test_csrf';
ob_start();
try {
    include '{$absPathEsc}';
} catch (Throwable \$e) {
    fwrite(STDERR, '__FATAL__:' . get_class(\$e) . ':' . \$e->getMessage() . ' in ' . basename(\$e->getFile()) . ':' . \$e->getLine() . "\\n");
    exit(2);
}
\$out = ob_get_clean();
echo "__OUTPUT_BYTES__:" . strlen(\$out) . "\\n";
PHP;

    $tmpFile = tempnam(sys_get_temp_dir(), 'render_test_') . '.php';
    file_put_contents($tmpFile, $wrapper);
    // Usa o mesmo binário PHP que está rodando este teste (portável entre
    // XAMPP/Laragon/etc.), em vez de um caminho fixo.
    $cmd = escapeshellarg(PHP_BINARY) . ' "' . $tmpFile . '" 2>&1';
    $output = shell_exec($cmd);
    unlink($tmpFile);

    $hasFatal = stripos($output, 'Fatal error') !== false || stripos($output, '__FATAL__') !== false;
    $hasParseErr = stripos($output, 'Parse error') !== false;
    $hasUndefinedFn = stripos($output, 'undefined function') !== false;

    if (!$hasFatal && !$hasParseErr && !$hasUndefinedFn && preg_match('/__OUTPUT_BYTES__:(\d+)/', $output, $m)) {
        $passed++;
        echo "  [OK]   $label (output: {$m[1]} bytes)\n";
    } else {
        $failed++;
        echo "  [FAIL] $label\n";
        // Primeiras 800 chars do output
        echo "         " . substr(preg_replace('/\s+/', ' ', $output), 0, 800) . "\n";
    }
}

echo "=== Render test: páginas admin afetadas pela feature de turnos noturnos ===\n\n";

probe('dashboard.php',                 'dashboard.php');
probe('attendances.php',               'attendances.php');
probe('attendance_manual.php',         'attendance_manual.php');
if ($attId > 0) {
    probe("attendance_edit.php?id={$attId}", 'attendance_edit.php', ['id' => (string)$attId]);
}
probe('class_periods.php',             'class_periods.php');
probe('reports.php',                   'reports.php');
probe('teacher_monthly_report.php',    'teacher_monthly_report.php');

if ($tId > 0) {
    probe("teacher_edit.php?id={$tId}", 'teacher_edit.php', ['id' => (string)$tId]);
}

echo "\n=== Resultado: $passed passou(aram), $failed falhou(aram) ===\n";
exit($failed > 0 ? 1 : 0);

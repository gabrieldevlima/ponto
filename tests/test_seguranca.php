<?php
declare(strict_types=1);
/**
 * Segurança e sessão — Fase 9 da auditoria.
 *
 * Cobre NC-44 (timeouts nunca aplicados, remember-me de 10 anos), NC-47 (CSP
 * apenas no login, HSTS nunca emitido), NC-48 (has_permission fail-open) e
 * NC-49 (conexão sem time_zone).
 *
 * Execução: php tests/test_seguranca.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();

echo "[1] Fuso horário da conexão (NC-49)\n";
$tz = (string)$pdo->query("SELECT @@session.time_zone")->fetchColumn();
check('time_zone fixado na conexão', $tz !== 'SYSTEM', $tz);
check('fuso é o de Brasília', $tz === '-03:00', $tz);
$mysqlNow = strtotime((string)$pdo->query("SELECT NOW()")->fetchColumn());
check('NOW() do MySQL coincide com o PHP (≤2s)', abs($mysqlNow - time()) <= 2,
      'diferença de ' . abs($mysqlNow - time()) . 's');

// O risco concreto: attendance mistura DATETIME (gravado pelo PHP) com
// TIMESTAMP (convertido pelo MySQL). Fusos divergentes produziriam horários
// diferentes em colunas da MESMA linha, sem nada acusar.
$r = $pdo->query("SELECT NOW() AS n, CURRENT_TIMESTAMP AS c")->fetch(PDO::FETCH_ASSOC);
check('NOW() e CURRENT_TIMESTAMP coerentes entre si',
      abs(strtotime($r['n']) - strtotime($r['c'])) <= 1);

echo "[2] Permissões por papel (NC-48)\n";
check('tabela role_permissions existe',
      (bool)$pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn());
$n = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn();
check('matriz de permissões semeada', $n > 0, "linhas={$n}");

$papeis = $pdo->query("SELECT DISTINCT role FROM role_permissions")->fetchAll(PDO::FETCH_COLUMN);
foreach (['network_admin', 'school_admin', 'hr_admin', 'manager'] as $p) {
    check("papel '{$p}' definido", in_array($p, $papeis, true));
}

// A função consulta current_admin(); simula-se um school_admin em sessão.
$adminId = (int)$pdo->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetchColumn();
$roleOrig = (string)$pdo->query("SELECT role FROM admins WHERE id = {$adminId}")->fetchColumn();
$_SESSION['admin_id'] = $adminId;
try {
    $pdo->prepare("UPDATE admins SET role = 'school_admin' WHERE id = ?")->execute([$adminId]);
    setting_cache_forget();

    // current_admin() pode cachear; força releitura chamando com o PDO.
    $adm = $pdo->query("SELECT role FROM admins WHERE id = {$adminId}")->fetch(PDO::FETCH_ASSOC);
    check('papel de teste aplicado', $adm['role'] === 'school_admin');

    // Consulta direta à matriz — é o que has_permission() passa a fazer.
    $perm = function (string $role, string $key) use ($pdo): ?int {
        $st = $pdo->prepare("SELECT allow FROM role_permissions WHERE role = ? AND perm_key = ?");
        $st->execute([$role, $key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    };
    check('school_admin PODE gerenciar quiosque', $perm('school_admin', 'kiosk.manage') === 1);
    check('school_admin NÃO configura o empregador', $perm('school_admin', 'employer.config') === 0);
    check('school_admin NÃO exporta arquivo fiscal', $perm('school_admin', 'export.fiscal') === 0);
    check('manager NÃO edita marcação', $perm('manager', 'attendance.edit') === 0);
    check('hr_admin PODE exportar arquivo fiscal', $perm('hr_admin', 'export.fiscal') === 1);
    check('hr_admin NÃO configura o empregador', $perm('hr_admin', 'employer.config') === 0);

    // FAIL-CLOSED: chave inexistente é negada, não permitida.
    check('chave desconhecida não tem regra', $perm('school_admin', 'chave.inventada') === null);
} finally {
    $pdo->prepare("UPDATE admins SET role = ? WHERE id = ?")->execute([$roleOrig, $adminId]);
    unset($_SESSION['admin_id']);
}

// A implementação em si: não pode mais devolver true por exceção.
// Normaliza as quebras de linha antes de recortar. O corpo de cada função é
// recortado até o fecha-chave sozinho numa linha; num checkout Windows com
// core.autocrlf=true o arquivo vem em CRLF, o recorte não achava o fim,
// sobravam 3 caracteres e todas as verificações abaixo falhavam sem que o
// código tivesse mudado.
$src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../helpers.php'));
$ini = strpos($src, 'function has_permission');
$corpo = substr($src, $ini, 2000);
$corpo = substr($corpo, 0, strpos($corpo, "\n}\n") + 3);
check('has_permission consulta role_permissions', str_contains($corpo, 'role_permissions'));
check('regra ausente NEGA (fail-closed)',
      str_contains($corpo, 'return false') && !preg_match('/if \(!\$row\) return true/', $corpo));
check('exceção NEGA, não permite',
      !preg_match('/catch[^}]*\{\s*return true;/s', $corpo));

echo "[3] Sessão administrativa expira por inatividade (NC-44)\n";
$srcRA = substr($src, strpos($src, 'function require_admin'), 1600);
$srcRA = substr($srcRA, 0, strpos($srcRA, "\n}\n") + 3);
check('require_admin lê ADMIN_SESSION_TIMEOUT', str_contains($srcRA, 'ADMIN_SESSION_TIMEOUT'));
check('registra a última atividade', str_contains($srcRA, 'admin_last_activity'));
check('regenera o id ao expirar', str_contains($srcRA, 'session_regenerate_id'));
check('audita a expiração', str_contains($srcRA, 'session_expired'));
check('constante definida e positiva', defined('ADMIN_SESSION_TIMEOUT') && ADMIN_SESSION_TIMEOUT > 0);

echo "[4] Remember-me deixa de valer 10 anos (NC-44)\n";
check('constante de validade definida', defined('COLLABORATOR_REMEMBER_DAYS'));
check('validade razoável (entre 7 e 180 dias)',
      COLLABORATOR_REMEMBER_DAYS >= 7 && COLLABORATOR_REMEMBER_DAYS <= 180,
      (string)COLLABORATOR_REMEMBER_DAYS);
check('cookie de 10 anos removido do código', !str_contains($src, '10 * 365 * 86400'));

$cols = array_column($pdo->query("SHOW COLUMNS FROM collaborator_remember_tokens")->fetchAll(PDO::FETCH_ASSOC), 'Field');
check('coluna expires_at existe', in_array('expires_at', $cols, true));
check('nenhum token ficou sem expiração',
      (int)$pdo->query("SELECT COUNT(*) FROM collaborator_remember_tokens WHERE expires_at IS NULL")->fetchColumn() === 0);
check('a leitura do token filtra por expiração', str_contains($src, 'expires_at IS NULL OR t.expires_at > NOW()'));

echo "[5] Cabeçalhos de segurança (NC-47)\n";
$cfg = file_get_contents(__DIR__ . '/../config.php');
check('CSP definida globalmente', str_contains($cfg, 'Content-Security-Policy'));
check('CSP bloqueia object-src', str_contains($cfg, "object-src 'none'"));
check('CSP restringe connect-src à própria origem', str_contains($cfg, "connect-src 'self'"));
check('CSP restringe form-action', str_contains($cfg, "form-action 'self'"));
check('HSTS usa a detecção de HTTPS atrás de proxy',
      preg_match('/if \(\$__sessSecure\)\s*\{\s*header\(\x27Strict-Transport-Security/s', $cfg) === 1);
check('HSTS não depende mais de $_SERVER[HTTPS] === on',
      !preg_match("/\\\$_SERVER\['HTTPS'\] === 'on'\s*\)\s*\{\s*header\('Strict/s", $cfg));

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

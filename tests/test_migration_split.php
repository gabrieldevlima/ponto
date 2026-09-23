<?php
declare(strict_types=1);
/**
 * O runner de migrações não pode partir SQL no meio de uma string.
 *
 * O caso que motivou: ao passar o CI para MariaDB (23/09/2026), duas migrações
 * falharam com erro de SINTAXE — `2026_08_05_nsr_ledger.sql` e
 * `2026_08_06_remember_token_expiry.sql`. O SQL estava certo. Quando o bloco
 * multi-statement falha por qualquer motivo, o runner cai para um split por
 * `;` que não conhece aspas, e as duas têm `;` DENTRO de um COMMENT:
 *
 *     COMMENT 'validade do token; NULL apos backfill indica ...'
 *
 * O corte deixava a string aberta, o banco acusava sintaxe, e a causa real da
 * falha original sumia do log. As duas ainda não rodaram em produção — vão
 * rodar no próximo deploy.
 *
 * Execução: php tests/test_migration_split.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

if (!function_exists('split_sql_statements')) {
    echo "  [FAIL] split_sql_statements() não existe em helpers.php\n";
    echo "\n=== Resultado ===\n0 passaram, 1 falharam\n";
    exit(1);
}

$mostra = fn(array $a) => json_encode($a, JSON_UNESCAPED_UNICODE);

echo "[1] O que o split ingênuo já fazia certo continua certo\n";
$r = split_sql_statements("CREATE TABLE a (x INT); INSERT INTO a VALUES (1);");
check('dois statements simples', $r === ['CREATE TABLE a (x INT)', 'INSERT INTO a VALUES (1)'], $mostra($r));
$r = split_sql_statements("SELECT 1;;\n\n;SELECT 2");
check('statements vazios são descartados', $r === ['SELECT 1', 'SELECT 2'], $mostra($r));
$r = split_sql_statements("   \n  ");
check('texto em branco não gera statement', $r === [], $mostra($r));

echo "[2] ';' dentro de string não parte o statement\n";
$sql = "ALTER TABLE t ADD COLUMN e DATETIME NULL\n  COMMENT 'validade do token; NULL apos backfill indica token legado';\nCREATE INDEX i ON t (e);";
$r = split_sql_statements($sql);
check('COMMENT com ; fica inteiro (caso real do remember_token_expiry)', count($r) === 2, $mostra($r));
check('a string do COMMENT chega intacta', str_contains($r[0] ?? '', "'validade do token; NULL apos backfill indica token legado'"), $r[0] ?? '');
$r = split_sql_statements("INSERT INTO t VALUES (\"a;b\"); SELECT 1");
check('aspas duplas também protegem', count($r) === 2 && str_contains($r[0], '"a;b"'), $mostra($r));
$r = split_sql_statements("SELECT `col;umn` FROM t; SELECT 2");
check('crase (identificador) também protege', count($r) === 2 && str_contains($r[0], '`col;umn`'), $mostra($r));

echo "[3] Aspas escapadas não enganam o split\n";
$r = split_sql_statements("INSERT INTO t VALUES ('it''s; ok'); SELECT 2");
check("aspa dobrada ('') não fecha a string", count($r) === 2 && str_contains($r[0], "'it''s; ok'"), $mostra($r));
$r = split_sql_statements("INSERT INTO t VALUES ('a\'; b'); SELECT 2");
check('aspa escapada com barra não fecha a string', count($r) === 2 && str_contains($r[0], "'a\'; b'"), $mostra($r));

echo "[4] ';' em comentário de bloco não parte\n";
$r = split_sql_statements("SELECT 1 /* nota; sem corte */ FROM dual; SELECT 2");
check('comentário /* ... ; ... */ protegido', count($r) === 2, $mostra($r));

echo "[5] As duas migrações reais que falharam no CI\n";
foreach (['2026_08_06_remember_token_expiry.sql', '2026_08_05_nsr_ledger.sql'] as $arq) {
    $bruto = (string)file_get_contents(__DIR__ . '/../sql/migrations/' . $arq);
    // O runner remove comentários de linha antes de partir; reproduz isso aqui.
    $limpo = trim((string)preg_replace('/^(?:--|#).*$/m', '', $bruto));
    $partes = split_sql_statements($limpo);
    $abertas = array_filter($partes, fn($s) => substr_count(preg_replace("/''/", '', $s), "'") % 2 !== 0);
    check("{$arq}: nenhum statement com string aberta", count($abertas) === 0,
          count($abertas) . ' statement(s) cortado(s) no meio de uma string');
}

echo "[6] O runner usa este split, e não o explode antigo\n";
$helpers = (string)file_get_contents(__DIR__ . '/../helpers.php');
$ini = strpos($helpers, 'function run_auto_migrations');
$corpo = $ini === false ? '' : substr($helpers, $ini, 20000);
check('o fallback do runner chama split_sql_statements()', str_contains($corpo, 'split_sql_statements($cleanSql)'));
// Montado por partes para não depender de escape de regex nem de shell.
$explodeAntigo = 'explode(' . "';'" . ', $' . 'cleanSql';
check("o runner não parte mais com explode(';')",
      !str_contains($corpo, $explodeAntigo), 'o split ingênuo voltou');

echo "\n=== Resultado ===\n{$pass} passaram, {$fail} falharam\n";
exit($fail === 0 ? 0 : 1);

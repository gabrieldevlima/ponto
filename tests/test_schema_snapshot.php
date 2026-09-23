<?php
declare(strict_types=1);
/**
 * O snapshot do schema de produção não pode carregar dado de produção.
 *
 * sql/schema/producao.sql é versionado e serve de base para o CI (ver
 * bin/schema_snapshot.php). Ele é regenerado de tempos em tempos a partir do
 * banco real — e um script alterado, ou um mysqldump rodado sem --no-data,
 * poria cadastro de colaborador dentro do repositório sem ninguém notar.
 *
 * Aqui se garante que ele traz só o que deve: estrutura e a lista de migrações
 * aplicadas; nada de linha de negócio, nada que só exista em produção.
 *
 * Execução: php tests/test_schema_snapshot.php
 */

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$arq = __DIR__ . '/../sql/schema/producao.sql';
if (!is_file($arq)) {
    echo "  [FAIL] sql/schema/producao.sql não existe\n\n=== Resultado ===\n0 passaram, 1 falharam\n";
    exit(1);
}
$sql = (string)file_get_contents($arq);

// Corpo de rotina (entre DELIMITER $$ e DELIMITER ;) é código, não dado —
// uma procedure pode ter INSERT no corpo legitimamente.
$foraDeRotina = preg_replace('/^DELIMITER \$\$.*?^DELIMITER ;/ms', '', $sql);

echo "[1] Só estrutura: nenhuma linha de negócio\n";
preg_match_all('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/mi', $foraDeRotina, $m);
$alvos = array_values(array_unique($m[1]));
check('o único INSERT é o da lista de migrações aplicadas',
      $alvos === [] || $alvos === ['applied_migrations'], 'INSERT em: ' . implode(', ', $alvos));
check('nenhum REPLACE / LOAD DATA',
      !preg_match('/^\s*(REPLACE\s+INTO|LOAD\s+DATA)/mi', $foraDeRotina));
check('nenhum CPF de 11 dígitos entre aspas',
      !preg_match("/'[0-9]{11}'/", $sql), 'achei algo com cara de CPF');

echo "[2] Nada que só exista em produção\n";
check('sem DEFINER (o usuário de produção não existe no CI)', !preg_match('/DEFINER\s*=\s*`/i', $sql));
check('sem contador AUTO_INCREMENT=N', !preg_match('/AUTO_INCREMENT=\d+/i', $sql));
check('sem o nome do banco de produção', !str_contains($sql, 'u803039033'));

echo "[3] Espelha a produção\n";
preg_match_all('/^\) ENGINE=\w+.*$/m', $sql, $opc);
$semUnicode = array_filter($opc[0], fn($l) => !str_contains($l, 'COLLATE=utf8mb4_unicode_ci'));
check('toda tabela declara COLLATE=utf8mb4_unicode_ci, como em produção',
      count($opc[0]) > 0 && count($semUnicode) === 0, count($semUnicode) . ' tabela(s) em outra collation');
check('traz a tabela de controle das migrações', preg_match('/CREATE TABLE `applied_migrations`/', $sql) === 1);
check('gerado pelo script, não à mão', str_contains($sql, 'Gerado por bin/schema_snapshot.php'));

echo "[4] Os dados de referência do CI são fictícios\n";
$ref = (string)@file_get_contents(__DIR__ . '/../sql/seeds/ci_reference.sql');
preg_match_all('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/mi', $ref, $mr);
$permitidas = ['collaborator_types', 'manual_reasons', 'app_settings', 'leave_types', 'admins'];
$fora = array_diff(array_unique($mr[1]), $permitidas);
check('ci_reference.sql só alimenta tabelas de apoio', $ref !== '' && $fora === [], 'tabelas inesperadas: ' . implode(', ', $fora));
check('o único CPF é o placeholder 00000000000',
      preg_match_all("/'([0-9]{11})'/", $ref, $cpfs) === 0 || array_unique($cpfs[1]) === ['00000000000'],
      'CPF inesperado no seed de referência');

echo "\n=== Resultado ===\n{$pass} passaram, {$fail} falharam\n";
exit($fail === 0 ? 0 : 1);

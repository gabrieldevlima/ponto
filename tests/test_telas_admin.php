<?php
declare(strict_types=1);
/**
 * As telas administrativas realmente renderizam?
 *
 * Motivo concreto: em 2026-08-07 `_head.php` e `import_pis.php` referenciaram
 * uma constante `APP_NAME` que NÃO EXISTE neste projeto. `php -l` não pega
 * (é erro de execução, não de sintaxe) e a suíte não pegava porque nenhum
 * teste carregava uma tela. A tela abriria em branco com erro fatal.
 *
 * Cobre também o defeito irmão: `export_afd.php` e `timesheet_mirror.php`
 * definiam $titulo e chamavam _navbar.php sem emitir doctype nem carregar o
 * Bootstrap — saíam sem estilo. Ninguém notou porque não estavam no menu.
 *
 * Não substitui teste de comportamento: prova que a página monta, não que
 * calcula certo. É a rede de baixo.
 *
 * Execução: php tests/test_telas_admin.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

// Telas tocadas pelas fases de conformidade, mais as que passaram a ficar
// acessíveis pelo menu em 2026-08-07.
$telas = [
    'export_afd.php',
    'export_aej.php',
    'timesheet_mirror.php',
    'import_pis.php',
    'employer_config.php',
    'leave_types.php',
    'teachers.php',
    'teacher_edit.php',
];

$php     = PHP_BINARY;
$runner  = __DIR__ . '/_render_admin_page.php';

foreach ($telas as $tela) {
    echo "[{$tela}]\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($tela) . ' 2>&1';
    $saida = []; $codigo = 0;
    exec($cmd, $saida, $codigo);
    $texto = implode("\n", $saida);

    if ($codigo !== 0) {
        check('renderiza sem erro fatal', false, $texto);
        continue;
    }
    check('renderiza sem erro fatal', true);

    $m = [];
    foreach ($saida as $linha) {
        if (preg_match('/^([a-z_]+)=(.*)$/', $linha, $mm)) $m[$mm[1]] = $mm[2];
    }

    check('produz HTML de tamanho plausível', (int)($m['bytes'] ?? 0) > 2000, ($m['bytes'] ?? '?') . ' bytes');
    check('emite doctype',            ($m['doctype'] ?? '0') === '1');
    check('carrega o Bootstrap',      ($m['bootstrap'] ?? '0') === '1');
    check('inclui o navbar',          ($m['navbar'] ?? '0') === '1');
    check('fecha o documento',        ($m['footer'] ?? '0') === '1');
    check('sem warning vazado no HTML', ($m['sem_warning'] ?? '0') === '1');
}

echo "[Constantes inexistentes]\n";
// Guarda direta contra o erro cometido: qualquer `APP_NAME` no admin volta a
// derrubar as telas, e o teste acima só pega se a tela estiver na lista.
$suspeitas = [];
foreach (glob(__DIR__ . '/../public/admin/*.php') ?: [] as $arq) {
    $src = (string)file_get_contents($arq);
    if (preg_match('/\bAPP_NAME\b/', $src)) $suspeitas[] = basename($arq);
}
check('nenhuma tela usa a constante inexistente APP_NAME',
      empty($suspeitas), implode(', ', $suspeitas));

echo "[Telas alcançáveis pelo menu]\n";
$navbar = (string)file_get_contents(__DIR__ . '/../public/admin/_navbar.php');
foreach (['export_afd.php', 'export_aej.php', 'timesheet_mirror.php'] as $t) {
    // De nada adianta a tela funcionar se não houver como chegar nela.
    check("{$t} está no menu", str_contains($navbar, 'href="' . $t . '"'));
}

echo "[Folhas de estilo e ícones locais existem]\n";
// _head.php e import_pis.php apontavam para ../assets/admin.css, arquivo que
// nunca existiu: as telas saíam com o Bootstrap mas sem o estilo do admin, e
// nada acusava — o navegador só registra um 404 e segue. O teste de renderização
// acima não pega isso, porque o HTML sai íntegro.
$quebrados = [];
foreach (glob(__DIR__ . '/../public/admin/*.php') ?: [] as $arq) {
    preg_match_all('/<link[^>]+href="([^"]+\.(?:css|ico))"/', (string)file_get_contents($arq), $m);
    foreach ($m[1] as $href) {
        if (preg_match('#^(https?:)?//#', $href) || str_contains($href, '<?')) continue;
        $caminho = dirname($arq) . '/' . explode('?', $href)[0];
        if (realpath($caminho) === false) $quebrados[] = basename($arq) . ' -> ' . $href;
    }
}
check('toda folha de estilo/ícone local referenciado existe', empty($quebrados), implode(', ', $quebrados));

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

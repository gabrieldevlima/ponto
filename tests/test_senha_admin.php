<?php
declare(strict_types=1);
/**
 * Política de senha do administrador e infraestrutura de teste.
 * Fase 9 (9.5 sem 2FA e 9.6) de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md.
 *
 * Cobre NC-43 (sem política de senha para admin) e a ausência de CI com testes
 * e verificação de vulnerabilidades.
 *
 * O contraste que motivou esta correção: o PIN do colaborador tinha
 * `pin_validate_strength()`, bloqueando sequências, repetições e janelas do
 * CPF — enquanto a senha de quem administra o ponto de todos aceitava qualquer
 * coisa, e o sistema chegava a criar o usuário `admin` com senha `admin123`.
 *
 * Execução: php tests/test_senha_admin.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "[1] Senhas recusadas\n";
$fracas = [
    ['admin123',                  'senha que o proprio sistema semeava'],
    ['12345678',                  'sequencia de digitos'],
    ['123456789012',              'sequencia longa de digitos'],
    ['aaaaaaaaaaaa',              'um unico caractere repetido'],
    ['abcdefghijkl',              'sequencia alfabetica'],
    ['qwertyuiop12',              'sequencia de teclado'],
    ['Curta1A',                   'curta demais'],
    ['minhasenha123456',          'contem sequencia conhecida'],
    ['  Trov4o-Vermelho!  ',      'espaco nas bordas'],
    ['senhasenhasenha',           'sem variedade e sem comprimento suficiente'],
];
foreach ($fracas as [$p, $rot]) {
    [$ok, $cod] = admin_validate_password($p, '', '');
    check("recusa: {$rot}", $ok === false, "aceitou \"{$p}\"");
}

echo "[2] Senhas aceitas\n";
$boas = [
    ['Trov4o-Vermelho!',            'maiuscula, minuscula, digito e simbolo'],
    ['cavalo bateria grampo azul',  'frase longa, so minusculas (>=16)'],
    ['Rx7#mQp2Lw9z',                'aleatoria com variedade'],
];
foreach ($boas as [$p, $rot]) {
    [$ok, $cod, $m] = admin_validate_password($p, '', '');
    check("aceita: {$rot}", $ok === true, $m);
}

echo "[3] Dados do próprio administrador não servem de senha\n";
[$ok] = admin_validate_password('Xy8-84567811322', '84567811322', 'Maria');
check('recusa senha contendo o CPF inteiro', $ok === false);
[$ok] = admin_validate_password('Xy8-845678#Qwz', '84567811322', 'Maria');
check('recusa senha contendo janela de 6 dígitos do CPF', $ok === false);
[$ok] = admin_validate_password('MariaSegura#99', '', 'Maria Souza');
check('recusa senha contendo o nome', $ok === false);
[$ok] = admin_validate_password('Trov4o-Vermelho!', '84567811322', 'Maria Souza');
check('aceita senha sem relação com CPF ou nome', $ok === true);

echo "[4] Contrato da função\n";
$r = admin_validate_password('x', '', '');
check('devolve [bool, codigo, mensagem]', count($r) === 3 && is_bool($r[0]) && is_string($r[1]) && is_string($r[2]));
check('mensagem de erro é informativa', mb_strlen($r[2]) > 15, $r[2]);
[$ok, $cod, $m] = admin_validate_password('Trov4o-Vermelho!', '', '');
check('sucesso vem sem mensagem', $ok === true && $m === '');

echo "[5] Pontos de gravação validam a senha\n";
$adm = file_get_contents(__DIR__ . '/../public/admin/admins.php');
check('admins.php valida em criação, edição e reset',
      substr_count($adm, 'admin_validate_password') >= 3,
      'ocorrências=' . substr_count($adm, 'admin_validate_password'));
check('admins.php sem goto remanescente', !str_contains($adm, 'goto '));

$setup = file_get_contents(__DIR__ . '/../bin/setup_admin.php');
check('bin/setup_admin.php valida a senha', str_contains($setup, 'admin_validate_password'));
check('checagem só de comprimento removida do setup',
      !str_contains($setup, 'Senha muito curta'));

$h = file_get_contents(__DIR__ . '/../helpers.php');
check('senha padrão admin123 removida', !str_contains($h, "password_hash('admin123'"));
check('admin inicial recebe senha aleatória', str_contains($h, 'random_bytes(9)'));

echo "[6] Infraestrutura de teste e CI\n";
$raiz = dirname(__DIR__);
check('phpunit.xml presente', is_file($raiz . '/phpunit.xml'));
check('phpunit instalado', is_file($raiz . '/vendor/bin/phpunit') || is_file($raiz . '/vendor/phpunit/phpunit/phpunit'));
check('wrapper da suíte presente', is_file($raiz . '/tests/SuiteTest.php'));
check('workflow de testes presente', is_file($raiz . '/.github/workflows/tests.yml'));

$ci = is_file($raiz . '/.github/workflows/tests.yml') ? file_get_contents($raiz . '/.github/workflows/tests.yml') : '';
check('CI executa php -l em todo o projeto', str_contains($ci, 'php -l'));
check('CI executa a suíte', str_contains($ci, 'phpunit'));
check('CI falha em vulnerabilidade de dependência', str_contains($ci, 'composer audit'));
check('CI verifica a cadeia do livro fiscal', str_contains($ci, 'ledger_verify'));
check('CI usa segredos próprios, não os de produção',
      str_contains($ci, 'NUNCA reutilizar as chaves de produção'));

$composer = json_decode((string)file_get_contents($raiz . '/composer.json'), true);
check('phpunit é dependência de desenvolvimento',
      isset($composer['require-dev']['phpunit/phpunit']));
check('dompdf atualizado para versão sem CVE conhecida',
      version_compare(ltrim((string)($composer['require']['dompdf/dompdf'] ?? '0'), '^~'), '3.1', '>='));

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

<?php
declare(strict_types=1);
/**
 * Aviso de primeiro acesso pendente no cadastro de colaborador.
 *
 * O caso que motivou: um colaborador cadastrado em 18/09/2026 passou três dias
 * tentando bater ponto e recebendo "Seu primeiro acesso precisa ser liberado
 * pelo administrador". `api/pin_enroll.php` (hotfix NC-02) só gera o PIN do
 * primeiro acesso para quem tem face cadastrada OU `pin_self_enroll_allowed=1`
 * — e um cadastro novo não tem nenhum dos dois, porque a coluna é DEFAULT 0 e
 * o formulário não a expõe. O bloqueio é intencional; o defeito era o admin
 * não ficar sabendo que precisava agir.
 *
 * Verifica os dois lados da correção:
 *   1. `teacher_first_access_pending()` reconhece exatamente o estado que
 *      `pin_enroll.php` bloqueia — nem mais, nem menos;
 *   2. a lista de colaboradores mostra o aviso com link para liberar o PIN.
 *
 * Execução: php tests/test_aviso_primeiro_acesso.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();

if (!function_exists('teacher_first_access_pending')) {
    echo "  [FAIL] teacher_first_access_pending() não existe em helpers.php\n";
    echo "\n=== Resultado ===\n0 passaram, 1 falharam\n";
    exit(1);
}

// CPF sintético (válido no dígito verificador, de ninguém). O teste NUNCA
// apaga por CPF: se já houver alguém com este, aborta em vez de mexer em
// cadastro alheio — rodar a suíte não pode destruir dado de colaborador.
$cpfTeste = '00000000191';
$jaExiste = $pdo->prepare("SELECT id FROM teachers WHERE cpf = ? LIMIT 1");
$jaExiste->execute([$cpfTeste]);
if ($jaExiste->fetchColumn()) {
    echo "  [FAIL] já existe colaborador com o CPF de teste {$cpfTeste} — abortando para não apagá-lo\n";
    echo "\n=== Resultado ===\n0 passaram, 1 falharam\n";
    exit(1);
}

$pdo->prepare("INSERT INTO teachers (name, cpf, active, network_wide) VALUES (?, ?, 1, 1)")
    ->execute(['ZZ TESTE PRIMEIRO ACESSO', $cpfTeste]);
$idTeste = (int)$pdo->lastInsertId();

// Some o registro de teste aconteça o que acontecer — por id, só o que este
// teste criou.
$limpar = function () use ($pdo, $idTeste): void {
    $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$idTeste]);
};
register_shutdown_function($limpar);

$setCampo = function (string $coluna, $valor) use ($pdo, $idTeste): void {
    $pdo->prepare("UPDATE teachers SET {$coluna} = ? WHERE id = ?")->execute([$valor, $idTeste]);
};

try {
    echo "[1] Estados que o primeiro acesso bloqueia\n";
    check('recém-cadastrado (sem PIN, sem face, sem liberação) fica pendente',
          teacher_first_access_pending($pdo, $idTeste) === true);

    echo "[2] Estados em que NÃO há o que avisar\n";
    $setCampo('pin_self_enroll_allowed', 1);
    check('admin já liberou a auto-geração → não pendente',
          teacher_first_access_pending($pdo, $idTeste) === false);
    $setCampo('pin_self_enroll_allowed', 0);

    $setCampo('face_descriptors', json_encode([array_fill(0, 128, 0.1)]));
    check('tem face cadastrada → prova identidade pela foto, não pendente',
          teacher_first_access_pending($pdo, $idTeste) === false);
    $setCampo('face_descriptors', null);

    $setCampo('pin_hash', pin_hash('918273'));
    check('já tem PIN → nem passa pelo primeiro acesso, não pendente',
          teacher_first_access_pending($pdo, $idTeste) === false);
    $setCampo('pin_hash', null);

    $setCampo('active', 0);
    check('colaborador inativo → não pendente',
          teacher_first_access_pending($pdo, $idTeste) === false);
    $setCampo('active', 1);

    check('id inexistente → não pendente',
          teacher_first_access_pending($pdo, 99999999) === false);
    check('id zero → não pendente',
          teacher_first_access_pending($pdo, 0) === false);

    echo "[3] Aviso na lista de colaboradores\n";
    // A tela roda em processo próprio: emite HTML, chama exit e declara funções
    // que colidiriam com as deste script.
    $render = function (array $params): string {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_render_admin_page.php')
             . ' ' . escapeshellarg('teachers.php');
        foreach ($params as $k => $v) $cmd .= ' ' . escapeshellarg($k . '=' . $v);
        $cmd .= ' 2>&1';
        putenv('PONTO_RENDER_DUMP=1'); // o subprocesso herda o ambiente deste
        $saida = []; $codigo = 0;
        exec($cmd, $saida, $codigo);
        $texto = implode("\n", $saida);
        $corte = strpos($texto, '---HTML---');
        return $corte === false ? $texto : substr($texto, $corte + 10);
    };

    $html = $render(['pin_pending' => (string)$idTeste]);
    check('a tela renderiza com o parâmetro', str_contains($html, '</html>'), substr($html, 0, 300));
    check('mostra o aviso de primeiro acesso', str_contains($html, 'primeiro acesso'), 'aviso ausente');
    check('nomeia o colaborador no aviso', str_contains($html, 'ZZ TESTE PRIMEIRO ACESSO'));
    check('oferece link para liberar o PIN', str_contains($html, 'teacher_pin_manage.php'));
    check('não vaza warning do PHP',
          !preg_match('/\b(Warning|Notice|Deprecated|Fatal error)\b:/', $html));

    check('sem o parâmetro, nenhum aviso aparece',
          !str_contains($render([]), 'primeiro acesso'));

    // Defesa contra aviso falso: com PIN já definido não há o que avisar, mesmo
    // que o parâmetro volte pela URL (refresh, botão voltar, link velho).
    $setCampo('pin_hash', pin_hash('918273'));
    check('colaborador que já tem PIN não gera aviso',
          !str_contains($render(['pin_pending' => (string)$idTeste]), 'primeiro acesso'));
    $setCampo('pin_hash', null);

    $htmlLixo = $render(['pin_pending' => 'abc; DROP']);
    check('parâmetro inválido não quebra a tela',
          str_contains($htmlLixo, '</html>') && !str_contains($htmlLixo, 'primeiro acesso'));

    echo "[4] O cadastro leva o aviso adiante\n";
    // Verificação estática: simular o POST de teachers_save.php exigiria sessão
    // de admin, CSRF e o formulário inteiro, e ainda assim `header()` não é
    // observável em CLI. O que se protege aqui é a existência do elo — sem ele,
    // a checagem e o alerta acima nunca chegam a ser acionados.
    $save = (string)file_get_contents(__DIR__ . '/../public/admin/teachers_save.php');
    check('o save guarda o id de quem foi criado agora',
          str_contains($save, '$colaboradorCriadoId = $id;'));
    check('o redirect consulta o estado do primeiro acesso',
          str_contains($save, 'teacher_first_access_pending($pdo, (int)$colaboradorCriadoId)'));
    check('o redirect leva pin_pending para a lista',
          str_contains($save, '&pin_pending='));
} finally {
    $limpar();
}

echo "\n=== Resultado ===\n{$pass} passaram, {$fail} falharam\n";
exit($fail === 0 ? 0 : 1);

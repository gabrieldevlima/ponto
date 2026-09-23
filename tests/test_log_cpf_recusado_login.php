<?php
declare(strict_types=1);
/**
 * Falha de login por CPF recusado precisa deixar rastro.
 *
 * O caso que motivou: em 23/09/2026 um colaborador passou uma manhã recebendo
 * "CPF ou PIN incorreto" e o log de tentativas ficou VAZIO — nem uma linha.
 * public/login.php barra o CPF que não passa no dígito verificador antes de
 * consultar o banco, e naquele caminho não havia registro nenhum. As duas
 * causas possíveis (CPF digitado errado × PIN errado) produzem a mesma
 * mensagem na tela, e sem registro não havia como distinguir uma da outra:
 * o diagnóstico custou uma manhã do colaborador e uma hora de investigação.
 *
 * Verifica:
 *   1. `cpf_reject_reason()` separa "não tem 11 dígitos" de "dígito verificador
 *      não fecha" — é essa distinção que diz ao admin se foi erro de digitação;
 *   2. `auth_log_cpf_rejected()` grava a tentativa com o motivo e SEM CPF em claro;
 *   3. a recusa por CPF não entra na contagem que trava o login por PIN errado —
 *      quem digitou o CPF errado dez vezes não pode ficar travado ao acertar;
 *   4. public/login.php realmente chama as duas.
 *
 * Execução: php tests/test_log_cpf_recusado_login.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

foreach (['cpf_reject_reason', 'auth_log_cpf_rejected'] as $fn) {
    if (!function_exists($fn)) {
        echo "  [FAIL] {$fn}() não existe em helpers.php\n";
        echo "\n=== Resultado ===\n0 passaram, 1 falharam\n";
        exit(1);
    }
}

echo "[1] Motivo da recusa do CPF\n";
check('CPF válido não é recusado',            cpf_reject_reason('00000000191') === null);
check('CPF com dígito trocado → check_digit', cpf_reject_reason('00000000192') === 'check_digit');
check('CPF incompleto → length',              cpf_reject_reason('0000000019') === 'length');
check('CPF vazio → length',                   cpf_reject_reason('') === 'length');
check('CPF só com letras → length',           cpf_reject_reason('abcdefghijk') === 'length');
check('dígitos repetidos → check_digit',      cpf_reject_reason('11111111111') === 'check_digit');
check('máscara é ignorada',                   cpf_reject_reason('000.000.001-91') === null);

echo "[2] A tentativa recusada vira registro\n";

$pdo = db();
$cpfInvalido = '00000000192';
$_SERVER['REMOTE_ADDR']     = '203.0.113.7';      // faixa reservada a documentação
$_SERVER['HTTP_USER_AGENT'] = 'teste-cpf-recusado';

$marco = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM auth_attempt_logs")->fetchColumn();
$limpar = function () use ($pdo, $marco): void {
    $pdo->prepare("DELETE FROM auth_attempt_logs WHERE id > ? AND reason = 'cpf_invalid_format' AND user_agent = ?")
        ->execute([$marco, 'teste-cpf-recusado']);
};
register_shutdown_function($limpar);

try {
    auth_log_cpf_rejected($pdo, $cpfInvalido, 'check_digit');

    $linhas = $pdo->query("SELECT * FROM auth_attempt_logs WHERE id > {$marco} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('gravou exatamente um registro', count($linhas) === 1, count($linhas) . ' registro(s)');

    if (count($linhas) === 1) {
        $r = $linhas[0];
        check('classificado como collaborator_login', $r['attempt_type'] === 'collaborator_login', (string)$r['attempt_type']);
        check('marcado como falha',                   (int)$r['success'] === 0);
        check('motivo cpf_invalid_format',            $r['reason'] === 'cpf_invalid_format', (string)$r['reason']);
        check('sem colaborador associado',            $r['teacher_id'] === null);
        check('detalha que foi o dígito verificador', str_contains((string)$r['details'], 'check_digit'), (string)$r['details']);
        check('registra quantos dígitos vieram',      str_contains((string)$r['details'], '"digitos":11'), (string)$r['details']);

        $tudo = json_encode($r, JSON_UNESCAPED_UNICODE);
        check('o CPF NÃO aparece em claro em lugar nenhum', !str_contains((string)$tudo, $cpfInvalido), 'CPF vazou no registro');
        check('o identifier é o hash prefixado',      str_starts_with((string)$r['identifier'], 'cpffmt:'), (string)$r['identifier']);
    }

    echo "[3] Não contamina o rate limit do login por PIN\n";
    // O identifier que trava o login é o hash puro de ip|ua|cpf. Se a recusa por
    // CPF usasse o mesmo, dez erros de digitação travariam quem já acertou.
    $identifierDoLogin = hash('sha256', $_SERVER['REMOTE_ADDR'] . '|' . substr($_SERVER['HTTP_USER_AGENT'], 0, 120) . '|' . $cpfInvalido);
    $rl = auth_attempt_is_limited($pdo, 'collaborator_login', $identifierDoLogin, 300, 10);
    check('a recusa não conta na janela de bloqueio', (int)($rl['failures'] ?? -1) === 0, 'falhas contadas: ' . ($rl['failures'] ?? '?'));

    // 12 recusas seguidas (acima do limite de 10) continuam sem travar o login.
    for ($i = 0; $i < 12; $i++) auth_log_cpf_rejected($pdo, $cpfInvalido, 'check_digit');
    $rl2 = auth_attempt_is_limited($pdo, 'collaborator_login', $identifierDoLogin, 300, 10);
    check('12 erros de digitação não bloqueiam o login', empty($rl2['limited']), 'bloqueou após ' . ($rl2['failures'] ?? '?') . ' falhas');

    echo "[4] O login usa as duas funções\n";
    $login = (string)file_get_contents(__DIR__ . '/../public/login.php');
    check('login.php classifica o CPF antes de decidir', str_contains($login, 'cpf_reject_reason($cpf)'));
    check('login.php registra a recusa',                 str_contains($login, 'auth_log_cpf_rejected(db(), $cpf, $cpfRejeitado)'));
    check('a mensagem na tela continua a mesma',
          substr_count($login, "\$error = 'CPF ou PIN incorreto.';") === 2, 'mensagem mudou — não pode revelar quais CPFs existem');
    echo "[5] Mesmo rastro no primeiro acesso e no 'Esqueci meu PIN'\n";
    // api/pin_enroll.php e api/pin_recover.php recusavam CPF pelo mesmo caminho
    // mudo. Cada porta registra com o seu proprio attempt_type, senao as tres
    // viram um amontoado so e o admin nao sabe onde a pessoa travou.
    $marco2 = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM auth_attempt_logs")->fetchColumn();
    auth_log_cpf_rejected($pdo, $cpfInvalido, 'check_digit', 'pin_enrollment');
    auth_log_cpf_rejected($pdo, $cpfInvalido, 'length', 'pin_recovery');
    $tipos = $pdo->query("SELECT attempt_type, reason FROM auth_attempt_logs WHERE id > {$marco2} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('o primeiro acesso registra com seu proprio tipo',
          ($tipos[0]['attempt_type'] ?? '') === 'pin_enrollment', json_encode($tipos));
    check('o recovery registra com seu proprio tipo',
          ($tipos[1]['attempt_type'] ?? '') === 'pin_recovery', json_encode($tipos));
    check('ambos mantem o motivo cpf_invalid_format',
          ($tipos[0]['reason'] ?? '') === 'cpf_invalid_format' && ($tipos[1]['reason'] ?? '') === 'cpf_invalid_format');
    check('o login continua sendo o tipo padrao (sem passar o 4o argumento)',
          (new ReflectionFunction('auth_log_cpf_rejected'))->getParameters()[3]->getDefaultValue() === 'collaborator_login');

    $enroll  = (string)file_get_contents(__DIR__ . '/../api/pin_enroll.php');
    $recover = (string)file_get_contents(__DIR__ . '/../api/pin_recover.php');
    check('pin_enroll.php registra a recusa',  str_contains($enroll,  'auth_log_cpf_rejected('));
    check('pin_recover.php registra a recusa', str_contains($recover, 'auth_log_cpf_rejected('));

} finally {
    $limpar();
}

echo "\n=== Resultado ===\n{$pass} passaram, {$fail} falharam\n";
exit($fail === 0 ? 0 : 1);

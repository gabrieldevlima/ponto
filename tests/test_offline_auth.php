<?php
declare(strict_types=1);
/**
 * Autorização offline sem PIN no dispositivo — NC-41.
 *
 * A fila offline exigia o PIN em cada item, então a credencial permanente do
 * trabalhador ficava em TEXTO CLARO no IndexedDB até o drain — que pode demorar
 * dias, e nunca acontece se o app for desinstalado com a fila cheia.
 *
 * O contraste dentro do próprio código era gritante: `savePending()` remove
 * deliberadamente o descriptor facial antes de gravar, comentando "nunca
 * persiste descriptor em repouso (LGPD)". O PIN não recebia o mesmo cuidado.
 *
 * Execução: php tests/test_offline_auth.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/offline_auth.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$cpf    = '00000000272';
$device = 'aparelho-de-teste-001';

echo "[1] Emissão\n";
$t = offline_auth_issue(42, $cpf, $device);
check('token emitido', !empty($t['token']));
check('token tem payload e assinatura', substr_count($t['token'], '.') === 1);
check('validade no futuro', $t['expires_at'] > time());
check('validade cobre uma jornada e a seguinte (36h)', $t['ttl'] === 129600, (string)$t['ttl']);

// O que mais importa: o PIN não está no token, em forma alguma.
$decodificado = base64_decode(explode('.', $t['token'])[0], true);
check('o token NÃO contém PIN', !preg_match('/\b\d{4,8}\b(?!\d)/', str_replace($cpf, '', (string)$decodificado)),
      (string)$decodificado);
check('o token contém o CPF vinculado', str_contains((string)$decodificado, $cpf));

echo "[2] Verificação legítima\n";
$v = offline_auth_verify($t['token'], $cpf, $device);
check('token válido é aceito', $v['ok'] === true, $v['motivo']);
check('devolve o colaborador correto', $v['teacher_id'] === 42, (string)$v['teacher_id']);

echo "[3] O token não substitui o PIN como credencial\n";
// Vale para UM CPF: sem isso, bastaria reenviar o item trocando o CPF para
// registrar ponto no lugar de outra pessoa.
$vOutro = offline_auth_verify($t['token'], '11144477735', $device);
check('não serve para outro colaborador', $vOutro['ok'] === false);
check('motivo identifica o colaborador', str_contains($vOutro['motivo'], 'colaborador'), $vOutro['motivo']);

$vDisp = offline_auth_verify($t['token'], $cpf, 'outro-aparelho-999');
check('não serve em outro dispositivo', $vDisp['ok'] === false);
check('motivo identifica o dispositivo', str_contains($vDisp['motivo'], 'dispositivo'), $vDisp['motivo']);

echo "[4] Adulteração\n";
[$b64, $sig] = explode('.', $t['token'], 2);
$campos = explode('|', (string)base64_decode($b64, true));
$campos[1] = '999'; // troca o teacher_id
$forjado = base64_encode(implode('|', $campos)) . '.' . $sig;
check('teacher_id adulterado invalida a assinatura',
      offline_auth_verify($forjado, $cpf, $device)['ok'] === false);

$campos2 = explode('|', (string)base64_decode($b64, true));
$campos2[4] = (string)(time() + 999999999); // estende a validade
check('validade esticada invalida a assinatura',
      offline_auth_verify(base64_encode(implode('|', $campos2)) . '.' . $sig, $cpf, $device)['ok'] === false);

check('assinatura trocada é rejeitada',
      offline_auth_verify($b64 . '.' . str_repeat('0', 64), $cpf, $device)['ok'] === false);
check('token vazio é rejeitado', offline_auth_verify('', $cpf, $device)['ok'] === false);
check('token malformado é rejeitado', offline_auth_verify('lixo', $cpf, $device)['ok'] === false);

echo "[5] Expiração\n";
$camposExp = explode('|', (string)base64_decode($b64, true));
$camposExp[4] = (string)(time() - 60);
$payloadExp = implode('|', $camposExp);
// Reassina corretamente — o token está bem formado, apenas venceu.
$sigExp = hash_hmac('sha256', $payloadExp, time_anchor_key());
$expirado = base64_encode($payloadExp) . '.' . $sigExp;
$vExp = offline_auth_verify($expirado, $cpf, $device);
check('token expirado é rejeitado mesmo com assinatura válida', $vExp['ok'] === false);
check('motivo indica expiração', str_contains($vExp['motivo'], 'expirado'), $vExp['motivo']);

echo "[6] Compatibilidade com cliente antigo\n";
$semDev = offline_auth_issue(42, $cpf, '');
check('token sem dispositivo funciona em qualquer aparelho',
      offline_auth_verify($semDev['token'], $cpf, 'qualquer-aparelho')['ok'] === true);

echo "[7] Integração: o PIN saiu do caminho de gravação\n";
$idx = file_get_contents(__DIR__ . '/../public/index.php');
check('savePending apaga o PIN antes de gravar', str_contains($idx, 'delete payloadSafe.pin'));
check('savePending anexa o token offline', str_contains($idx, 'payloadSafe.offlineAuth'));
check('front captura o header do servidor', str_contains($idx, "headers.get('X-Offline-Auth')"));

$chk = file_get_contents(__DIR__ . '/../api/checkin.php');
check('checkin.php emite o token após autenticar por PIN', str_contains($chk, 'offline_auth_issue'));
check('token vai em header (vale para todos os pontos de saída)',
      str_contains($chk, "header('X-Offline-Auth: '"));

$bulk = file_get_contents(__DIR__ . '/../api/checkin_bulk.php');
check('checkin_bulk aceita o token', str_contains($bulk, 'offline_auth_verify'));
check('PIN ainda aceito para drenar fila antiga', str_contains($bulk, 'password_verify($pin'));
check('compatibilidade documentada como transitória',
      str_contains($bulk, 'drenar itens'));

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

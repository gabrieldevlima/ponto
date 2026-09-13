<?php
declare(strict_types=1);
/**
 * Autorização para marcação offline, sem guardar o PIN no dispositivo.
 * ====================================================================
 * Resolve NC-41 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md.
 *
 * O PROBLEMA
 * A fila offline exigia `pin` em cada item (`api/checkin_bulk.php`), então o
 * PIN em TEXTO CLARO ficava persistido no IndexedDB do aparelho até o drain —
 * que pode demorar horas ou dias, e nunca acontece se o app for desinstalado
 * com a fila cheia. Qualquer XSS, extensão maliciosa ou perícia no aparelho
 * lia a credencial permanente do trabalhador.
 *
 * O contraste dentro do próprio código era gritante: `savePending()` remove
 * deliberadamente o descriptor facial antes de gravar, com o comentário "nunca
 * persiste descriptor em repouso (LGPD)". O PIN — que é credencial, não
 * biometria derivada — não recebia o mesmo cuidado.
 *
 * A SOLUÇÃO
 * Quando o colaborador se autentica com o PIN (online), o servidor devolve um
 * TOKEN de autorização: assinado com HMAC, vinculado ao CPF e ao dispositivo,
 * com validade curta. É esse token que vai para a fila.
 *
 * O que se ganha: o token só serve para aquele CPF, naquele aparelho, por
 * tempo limitado, e não permite login nem troca de PIN. Vazado, expira
 * sozinho. O PIN nunca toca o disco.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/time_anchor.php'; // reusa time_anchor_key()

/**
 * Validade do token offline.
 *
 * 36 horas cobre uma jornada inteira mais o turno seguinte — inclusive plantão
 * que atravessa a madrugada — sem exigir que o colaborador volte a digitar o
 * PIN só porque ficou sem rede. Mais que isso começa a se parecer com o
 * problema que se quer resolver.
 */
const OFFLINE_AUTH_TTL_SECONDS = 129600;

/** Emite o token após autenticação bem-sucedida por PIN. */
function offline_auth_issue(int $teacherId, string $cpf, string $deviceId = ''): array {
    $cpf     = preg_replace('/\D/', '', $cpf);
    $device  = substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', $deviceId), 0, 64);
    $expira  = time() + OFFLINE_AUTH_TTL_SECONDS;
    $payload = implode('|', ['offauth-v1', (string)$teacherId, $cpf, $device, (string)$expira]);
    $sig     = hash_hmac('sha256', $payload, time_anchor_key());

    return [
        'token'      => base64_encode($payload) . '.' . $sig,
        'expires_at' => $expira,
        'ttl'        => OFFLINE_AUTH_TTL_SECONDS,
    ];
}

/**
 * Verifica o token apresentado por um item da fila.
 *
 * @return array{ok:bool,teacher_id:?int,motivo:string}
 */
function offline_auth_verify(string $token, string $cpf, string $deviceId = ''): array {
    $nao = fn(string $m) => ['ok' => false, 'teacher_id' => null, 'motivo' => $m];

    if ($token === '') return $nao('sem token');
    $partes = explode('.', $token, 2);
    if (count($partes) !== 2) return $nao('token malformado');

    [$b64, $sig] = $partes;
    $payload = base64_decode($b64, true);
    if ($payload === false) return $nao('token malformado');

    if (!hash_equals(hash_hmac('sha256', $payload, time_anchor_key()), $sig)) {
        return $nao('assinatura invalida');
    }

    $c = explode('|', $payload);
    if (count($c) !== 5 || $c[0] !== 'offauth-v1') return $nao('versao de token desconhecida');
    [, $teacherId, $tokenCpf, $tokenDevice, $expira] = $c;

    if ((int)$expira < time()) return $nao('token expirado');

    // O token vale para UM CPF. Sem isso, um item da fila poderia ser reenviado
    // trocando o CPF e registrando ponto no lugar de outra pessoa.
    if (!hash_equals($tokenCpf, preg_replace('/\D/', '', $cpf))) {
        return $nao('token emitido para outro colaborador');
    }

    // Vínculo com o dispositivo. Token emitido sem device (cliente antigo)
    // segue aceito, para não quebrar quem ainda não atualizou o PWA.
    $devAtual = substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', $deviceId), 0, 64);
    if ($tokenDevice !== '' && $devAtual !== '' && !hash_equals($tokenDevice, $devAtual)) {
        return $nao('token emitido para outro dispositivo');
    }

    return ['ok' => true, 'teacher_id' => (int)$teacherId, 'motivo' => ''];
}

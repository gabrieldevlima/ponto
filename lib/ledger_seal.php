<?php
declare(strict_types=1);
/**
 * Selo diário do livro fiscal — assinatura Ed25519 e ancoragem externa.
 * =====================================================================
 * Fase 2 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * O PROBLEMA QUE ISTO RESOLVE
 * A cadeia HMAC da Fase 1 detecta adulteração feita por quem não tem a chave.
 * Quem comprometer servidor E chave pode reescrever o passado e recalcular
 * todos os elos — a cadeia voltaria a fechar. É a limitação de qualquer
 * esquema simétrico, e nenhuma quantidade de HMAC resolve isso sozinha.
 *
 * A saída é ancorar o estado do livro FORA do sistema. Diariamente:
 *   1. calcula-se o head da cadeia no fim do dia;
 *   2. assina-se com Ed25519 (chave privada, que pode ficar offline);
 *   3. publica-se o head para o empregador (e-mail, ata, protocolo).
 *
 * A partir daí o head de ontem existe fora do servidor. Reescrever o passado
 * passa a exigir também alterar a cópia externa — que não está sob controle de
 * quem invadiu. A prova deixa de depender da integridade de uma máquina só.
 *
 * SOBRE AS CHAVES
 * A privada Ed25519 assina; a pública verifica e pode ser distribuída à
 * vontade (vai no rodapé do comprovante e na página de verificação). Em
 * instalação madura, o ideal é assinar em máquina separada e manter só a
 * pública no servidor. Aqui a privada mora em config.local.php, fora do
 * versionamento — melhor que nada e coerente com o resto do sistema.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/nsr_ledger.php';

/**
 * Chave PRIVADA Ed25519 (64 bytes), em base64 na configuração.
 * FAIL CLOSED: sem chave, não sela.
 */
function ledger_seal_secret_key(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $b64 = '';
    if (defined('LEDGER_SEAL_SECRET_KEY') && LEDGER_SEAL_SECRET_KEY !== '') {
        $b64 = (string)LEDGER_SEAL_SECRET_KEY;
    } else {
        $env = getenv('PONTO_LEDGER_SEAL_SECRET_KEY');
        if ($env !== false && $env !== '') $b64 = (string)$env;
    }
    if ($b64 === '') {
        throw new RuntimeException(
            'LEDGER_SEAL_SECRET_KEY ausente — o selo diário não pode ser assinado. '
            . 'Gere com "php bin/ledger_keygen.php --seal" e defina em config.local.php.'
        );
    }
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('LEDGER_SEAL_SECRET_KEY inválida (esperados '
            . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . ' bytes após base64).');
    }
    return $cached = $raw;
}

/** Chave pública derivada da privada. Não é segredo. */
function ledger_seal_public_key(): string {
    return sodium_crypto_sign_publickey_from_secretkey(ledger_seal_secret_key());
}

function ledger_seal_public_key_b64(): string {
    return base64_encode(ledger_seal_public_key());
}

function ledger_seal_key_id(): string {
    if (defined('LEDGER_SEAL_KEY_ID') && LEDGER_SEAL_KEY_ID !== '') return (string)LEDGER_SEAL_KEY_ID;
    $env = getenv('PONTO_LEDGER_SEAL_KEY_ID');
    return ($env !== false && $env !== '') ? (string)$env : 'seal1';
}

/**
 * Mensagem canônica do selo. Como no livro, é uma string versionada delimitada
 * por pipe — reproduzir isso byte a byte daqui a cinco anos não pode depender
 * de como o PHP daquele momento serializa JSON.
 */
function ledger_seal_message(string $sealDate, int $firstNsr, int $lastNsr, int $count, string $headHash): string {
    return implode('|', ['seal-v1', $sealDate, (string)$firstNsr, (string)$lastNsr, (string)$count, $headHash]);
}

/**
 * Emite o selo de um dia.
 *
 * Idempotente por dia (UNIQUE em seal_date). Não sela dia sem eventos: selo de
 * dia vazio não prova nada e só polui a série.
 *
 * @return array{status:string,seal_date:string,head_hash?:string,signature?:string,count?:int,message?:string}
 */
function ledger_seal_day(PDO $pdo, string $sealDate): array {
    $st = $pdo->prepare("SELECT COUNT(*) FROM nsr_ledger_seals WHERE seal_date = ?");
    $st->execute([$sealDate]);
    if ((int)$st->fetchColumn() > 0) {
        return ['status' => 'ja_selado', 'seal_date' => $sealDate];
    }

    // O dia é delimitado por created_at (quando o evento ENTROU no livro), não
    // por marked_at (quando a marcação aconteceu). Um ponto de ontem registrado
    // hoje por sincronização offline pertence ao selo de HOJE — o selo atesta o
    // estado do livro num instante, não a jornada de um dia.
    $st = $pdo->prepare("
        SELECT MIN(nsr) AS first_nsr, MAX(nsr) AS last_nsr, COUNT(*) AS n
          FROM nsr_ledger
         WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $st->execute([$sealDate, $sealDate]);
    $agg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$agg || (int)$agg['n'] === 0) {
        return ['status' => 'sem_eventos', 'seal_date' => $sealDate];
    }

    $firstNsr = (int)$agg['first_nsr'];
    $lastNsr  = (int)$agg['last_nsr'];
    $count    = (int)$agg['n'];

    $st = $pdo->prepare("SELECT record_hash FROM nsr_ledger WHERE nsr = ?");
    $st->execute([$lastNsr]);
    $headHash = (string)$st->fetchColumn();
    if ($headHash === '') {
        return ['status' => 'erro', 'seal_date' => $sealDate, 'message' => 'head do dia nao encontrado'];
    }

    $msg = ledger_seal_message($sealDate, $firstNsr, $lastNsr, $count, $headHash);
    $sig = base64_encode(sodium_crypto_sign_detached($msg, ledger_seal_secret_key()));

    $pdo->prepare("INSERT INTO nsr_ledger_seals
        (seal_date, first_nsr, last_nsr, event_count, head_hash, signature, pubkey_id)
        VALUES (?,?,?,?,?,?,?)")
        ->execute([$sealDate, $firstNsr, $lastNsr, $count, $headHash, $sig, ledger_seal_key_id()]);

    ledger_seal_register_key($pdo);

    return ['status' => 'selado', 'seal_date' => $sealDate, 'head_hash' => $headHash,
            'signature' => $sig, 'count' => $count, 'first_nsr' => $firstNsr, 'last_nsr' => $lastNsr];
}

/** Verifica a assinatura de um selo com a chave pública informada (ou a atual). */
function ledger_seal_verify(array $seal, ?string $publicKey = null): bool {
    $msg = ledger_seal_message(
        (string)$seal['seal_date'], (int)$seal['first_nsr'], (int)$seal['last_nsr'],
        (int)$seal['event_count'], (string)$seal['head_hash']
    );
    $sig = base64_decode((string)$seal['signature'], true);
    if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) return false;

    $pk = $publicKey;
    if ($pk === null) {
        try { $pk = ledger_seal_public_key(); } catch (Throwable $e) { return false; }
    }
    return sodium_crypto_sign_verify_detached($sig, $msg, $pk);
}

/**
 * Confere um selo contra o livro: a assinatura fecha E o head ainda corresponde
 * ao que está gravado. As duas coisas separadas importam — assinatura válida
 * sobre um head que já não existe no livro é exatamente o sintoma de reescrita
 * do passado.
 *
 * @return array{ok:bool,assinatura:bool,head_confere:bool,detalhe:string}
 */
function ledger_seal_audit(PDO $pdo, array $seal): array {
    $assinatura = ledger_seal_verify($seal);

    $st = $pdo->prepare("SELECT record_hash FROM nsr_ledger WHERE nsr = ?");
    $st->execute([(int)$seal['last_nsr']]);
    $atual = (string)$st->fetchColumn();
    $headConfere = $atual !== '' && hash_equals((string)$seal['head_hash'], $atual);

    $detalhe = 'selo integro';
    if (!$assinatura && !$headConfere) $detalhe = 'assinatura invalida E head divergente';
    elseif (!$assinatura)              $detalhe = 'assinatura invalida — selo adulterado ou chave trocada';
    elseif (!$headConfere)             $detalhe = $atual === ''
        ? 'o NSR selado nao existe mais no livro'
        : 'head do livro mudou apos o selo — indicio de reescrita do passado';

    return ['ok' => $assinatura && $headConfere, 'assinatura' => $assinatura,
            'head_confere' => $headConfere, 'detalhe' => $detalhe];
}

/** Registra (uma vez) o fingerprint da chave de selo em uso. */
function ledger_seal_register_key(PDO $pdo): void {
    try {
        $pub = ledger_seal_public_key();
        $pdo->prepare("INSERT INTO ledger_keys (key_id, kind, fingerprint, public_key, note)
                       VALUES (?, 'ed25519', ?, ?, ?)
                       ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint)")
            ->execute([ledger_seal_key_id(), hash('sha256', $pub), base64_encode($pub),
                       'chave de selo diario do livro fiscal']);
    } catch (Throwable $e) {
        error_log('[ledger_seal] falha ao registrar fingerprint da chave: ' . $e->getMessage());
    }
}

/** Registra que o head de um dia foi publicado fora do sistema. */
function ledger_seal_anchor(PDO $pdo, string $sealDate, string $channel, string $ref, ?int $adminId = null): void {
    if (trim($ref) === '') {
        throw new InvalidArgumentException('A ancoragem exige uma referencia externa verificavel.');
    }
    $pdo->prepare("INSERT INTO nsr_ledger_seal_anchors (seal_date, anchored_at, channel, anchor_ref, recorded_by)
                   VALUES (?, NOW(), ?, ?, ?)")
        ->execute([$sealDate, $channel, trim($ref), $adminId]);
}

/** Último selo emitido, ou null. */
function ledger_seal_latest(PDO $pdo): ?array {
    $r = $pdo->query("SELECT * FROM nsr_ledger_seals ORDER BY seal_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

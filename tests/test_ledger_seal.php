<?php
declare(strict_types=1);
/**
 * Selo diário Ed25519 — Fase 2 da auditoria.
 *
 * O selo existe para cobrir a brecha que o HMAC da cadeia NÃO cobre: quem
 * comprometer servidor e chave pode reescrever o passado e recalcular todos os
 * elos, e a cadeia voltaria a fechar. O que denuncia isso é o head assinado e
 * publicado fora do sistema.
 *
 * Por isso o teste central aqui é o [3]: um head que mudou depois do selo
 * precisa ser acusado, mesmo com a assinatura perfeitamente válida.
 *
 * Execução: php tests/test_ledger_seal.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ledger_seal.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();

try {
    echo "[1] Chaves\n";
    $sk = ledger_seal_secret_key();
    $pk = ledger_seal_public_key();
    check('chave privada com tamanho correto', strlen($sk) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
    check('chave pública derivada da privada', strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    check('chave pública em base64 é estável', ledger_seal_public_key_b64() === base64_encode($pk));

    echo "[2] Mensagem canônica e assinatura\n";
    $msg1 = ledger_seal_message('2026-08-05', 1, 100, 100, str_repeat('a', 64));
    $msg2 = ledger_seal_message('2026-08-05', 1, 100, 100, str_repeat('a', 64));
    check('mensagem é determinística', $msg1 === $msg2);
    check('mensagem começa com a versão', str_starts_with($msg1, 'seal-v1|'), $msg1);
    check('mudar o head muda a mensagem',
          $msg1 !== ledger_seal_message('2026-08-05', 1, 100, 100, str_repeat('b', 64)));

    $selo = ['seal_date' => '2026-08-05', 'first_nsr' => 1, 'last_nsr' => 100,
             'event_count' => 100, 'head_hash' => str_repeat('a', 64)];
    $selo['signature'] = base64_encode(sodium_crypto_sign_detached($msg1, $sk));
    check('assinatura própria verifica', ledger_seal_verify($selo));

    echo "[3] Detecção de reescrita do passado\n";
    // Head alterado: a assinatura passa a não fechar, porque foi feita sobre o
    // head antigo. É o cenário "alguém mudou o livro depois do selo".
    $adulterado = $selo;
    $adulterado['head_hash'] = str_repeat('b', 64);
    check('head trocado invalida a assinatura', !ledger_seal_verify($adulterado));

    // Intervalo de NSR alterado — tentativa de "esconder" eventos do selo.
    $encolhido = $selo;
    $encolhido['last_nsr'] = 50;
    check('intervalo de NSR encolhido invalida a assinatura', !ledger_seal_verify($encolhido));

    $contagem = $selo;
    $contagem['event_count'] = 99;
    check('contagem de eventos alterada invalida a assinatura', !ledger_seal_verify($contagem));

    // Atacante com OUTRA chave reassina o selo adulterado. A assinatura fecha
    // sob a chave dele — mas não sob a pública publicada ao empregador.
    $outroKp = sodium_crypto_sign_keypair();
    $forjado = $adulterado;
    $forjado['signature'] = base64_encode(sodium_crypto_sign_detached(
        ledger_seal_message('2026-08-05', 1, 100, 100, str_repeat('b', 64)),
        sodium_crypto_sign_secretkey($outroKp)
    ));
    check('selo reassinado com outra chave é rejeitado', !ledger_seal_verify($forjado));
    check('e seria aceito sob a chave do atacante (prova que o teste é válido)',
          ledger_seal_verify($forjado, sodium_crypto_sign_publickey($outroKp)));

    echo "[4] Auditoria contra o livro real\n";
    $reais = $pdo->query("SELECT * FROM nsr_ledger_seals ORDER BY seal_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($reais) {
        $r = ledger_seal_audit($pdo, $reais);
        check('selo real do sistema confere', $r['ok'], $r['detalhe']);
        check('assinatura válida', $r['assinatura']);
        check('head ainda corresponde ao livro', $r['head_confere']);

        // Selo apontando para um NSR que não existe: é o sintoma de um trecho
        // do livro ter sido removido/reescrito depois do selo.
        $orfao = $reais;
        $orfao['last_nsr'] = 999999999;
        $orfao['signature'] = base64_encode(sodium_crypto_sign_detached(
            ledger_seal_message((string)$orfao['seal_date'], (int)$orfao['first_nsr'],
                                999999999, (int)$orfao['event_count'], (string)$orfao['head_hash']),
            $sk
        ));
        $r2 = ledger_seal_audit($pdo, $orfao);
        check('assinatura fecha, mas o head não existe mais no livro',
              $r2['assinatura'] === true && $r2['head_confere'] === false, json_encode($r2));
        check('auditoria reprova mesmo com assinatura válida', $r2['ok'] === false);
    } else {
        echo "  (sem selos emitidos — rode: php bin/ledger_seal.php --backfill)\n";
    }

    echo "[5] Imutabilidade do selo no banco\n";
    if ($reais) {
        try {
            $pdo->exec("UPDATE nsr_ledger_seals SET head_hash = REPEAT('0',64) WHERE id = " . (int)$reais['id']);
            check('UPDATE em selo é bloqueado', false, 'o UPDATE passou');
        } catch (Throwable $e) { check('UPDATE em selo é bloqueado', true); }
        try {
            $pdo->exec("DELETE FROM nsr_ledger_seals WHERE id = " . (int)$reais['id']);
            check('DELETE em selo é bloqueado', false, 'o DELETE passou');
        } catch (Throwable $e) { check('DELETE em selo é bloqueado', true); }
    }

    echo "[6] Ancoragem externa\n";
    check('ancoragem sem referência é recusada', (function () use ($pdo) {
        try { ledger_seal_anchor($pdo, '2026-08-05', 'email', '   '); return false; }
        catch (InvalidArgumentException $e) { return true; }
    })());

    echo "[7] Fingerprint da chave registrado (nunca a chave)\n";
    // Registra a chave em uso e confere O QUE foi gravado. Antes a seção só lia
    // a tabela e dependia de algum selo já ter sido emitido — no CI, que parte
    // do schema de produção sem selo nenhum, ela falhava sem testar nada. E
    // busca pela key_id em uso: num banco com chaves antigas, "qualquer
    // ed25519" podia conferir a chave errada.
    ledger_seal_register_key($pdo);
    $stK = $pdo->prepare("SELECT * FROM ledger_keys WHERE kind = 'ed25519' AND key_id = ? LIMIT 1");
    $stK->execute([ledger_seal_key_id()]);
    $k = $stK->fetch(PDO::FETCH_ASSOC);
    if ($k) {
        check('fingerprint corresponde à chave pública', $k['fingerprint'] === hash('sha256', $pk));
        check('coluna public_key guarda a PÚBLICA', $k['public_key'] === base64_encode($pk));
        $cols = array_column($pdo->query("SHOW COLUMNS FROM ledger_keys")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        check('não existe coluna para chave privada',
              !in_array('secret_key', $cols, true) && !in_array('private_key', $cols, true));
    } else {
        check('chave registrada em ledger_keys', false, 'nenhuma linha ed25519');
    }

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

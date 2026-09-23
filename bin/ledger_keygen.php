#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Gerador da chave HMAC do livro fiscal (nsr_ledger).
 * ===================================================
 *
 * Uso:
 *     php bin/ledger_keygen.php
 *
 * Imprime um bloco pronto para colar em `config.local.php` (que está no
 * .gitignore). NÃO grava nada em disco nem no banco — a decisão de onde a
 * chave vai morar é do operador, e o script não deve tomá-la sozinho.
 *
 * Por que a chave não pode ficar no banco: ela existe para provar que os
 * registros de ponto não foram alterados. Se ficar guardada junto dos dados que
 * assina, quem obtiver acesso ao banco pode alterar uma marcação E recalcular a
 * cadeia inteira — a prova deixa de provar qualquer coisa. Guarde uma cópia
 * offline em cofre: perdê-la não corrompe os registros, mas impede verificar a
 * integridade dos que já foram assinados.
 */

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando (CLI)\n");
}

// --seal: gera o par Ed25519 do selo diário (Fase 2), em vez da chave HMAC.
if (in_array('--seal', $argv ?? [], true)) {
    if (!function_exists('sodium_crypto_sign_keypair')) {
        die("libsodium indisponivel neste PHP — o selo Ed25519 nao pode ser gerado.\n");
    }
    $kp     = sodium_crypto_sign_keypair();
    $secret = base64_encode(sodium_crypto_sign_secretkey($kp));
    $public = base64_encode(sodium_crypto_sign_publickey($kp));
    $keyId  = 'seal' . date('Ymd');

    echo "\n";
    echo "=====================================================================\n";
    echo " Par Ed25519 do selo diario do livro fiscal\n";
    echo "=====================================================================\n\n";
    echo "Cole em config.local.php:\n\n";
    echo "define('LEDGER_SEAL_SECRET_KEY', '{$secret}');\n";
    echo "define('LEDGER_SEAL_KEY_ID', '{$keyId}');\n\n";
    echo "---------------------------------------------------------------------\n";
    echo "Chave PUBLICA (NAO e segredo — publique a vontade):\n";
    echo "  {$public}\n\n";
    echo "Ela vai no rodape do comprovante e na pagina de verificacao publica,\n";
    echo "para que terceiros possam conferir os selos sem acesso ao sistema.\n\n";
    echo "ATENCAO\n";
    echo "  - A chave PRIVADA assina o head diario. Guarde copia offline.\n";
    echo "  - O ideal, em instalacao madura, e assinar em maquina separada e\n";
    echo "    manter apenas a publica no servidor de producao.\n";
    echo "  - Trocar a chave exige novo LEDGER_SEAL_KEY_ID; os selos antigos\n";
    echo "    continuam validos sob a chave anterior, que precisa ser preservada.\n";
    echo "=====================================================================\n\n";
    exit(0);
}

$key   = bin2hex(random_bytes(32)); // 256 bits
$keyId = 'k' . date('Ymd');

echo "\n";
echo "=====================================================================\n";
echo " Chave HMAC do livro fiscal (nsr_ledger) — gerada agora\n";
echo "=====================================================================\n\n";
echo "Cole o bloco abaixo em config.local.php (copie de config.local.php.example\n";
echo "se o arquivo ainda não existir):\n\n";
echo "define('LEDGER_HMAC_KEY', '{$key}');\n";
echo "define('LEDGER_HMAC_KEY_ID', '{$keyId}');\n\n";
echo "---------------------------------------------------------------------\n";
echo "Fingerprint (pode ser registrado em ledger_keys / documentado sem risco):\n";
echo "  " . hash('sha256', $key) . "\n\n";
echo "ATENÇÃO\n";
echo "  - config.local.php NUNCA deve ser versionado (já está no .gitignore).\n";
echo "  - Guarde uma cópia offline em cofre.\n";
echo "  - Trocar a chave depois de assinar registros exige novo key_id; os\n";
echo "    registros antigos continuam válidos sob a chave anterior, que precisa\n";
echo "    ser preservada para verificação.\n";
echo "=====================================================================\n\n";

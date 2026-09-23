#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Exportação do Arquivo Fonte de Dados (AFD) — Portaria MTP 671/2021.
 * ===================================================================
 * Fase 4 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md — NC-05.
 *
 * Uso:
 *   php bin/export_afd.php --de=2026-04-01 --ate=2026-04-30 [--out=afd.txt]
 *   php bin/export_afd.php --preflight --de=... --ate=...   # só o diagnóstico
 *   php bin/export_afd.php --spec                            # inspeciona o leiaute
 *
 * Roda por CLI porque `.user.ini` fixa max_execution_time = 60: um AFD de
 * período longo não terminaria numa requisição HTTP.
 *
 * Códigos de saída: 0 ok · 1 erro/bloqueio · 2 gerado com ressalvas
 */

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando (CLI)\n");
}
set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/afd.php';

$args = $argv ?? [];
$opt = function (string $n) use ($args): ?string {
    foreach ($args as $a) if (str_starts_with($a, "--{$n}=")) return substr($a, strlen($n) + 3);
    return null;
};
$has = fn(string $n) => in_array("--{$n}", $args, true);

// --------------------------------------------------------------------------
if ($has('spec')) {
    $spec = afd_spec();
    echo "Leiaute do AFD — versao {$spec['versao']}\n";
    echo AFD_SPEC_VERIFICADA
        ? "Status: CONFERIDO contra o Anexo oficial.\n\n"
        : "Status: *** NAO CONFERIDO *** contra o Anexo oficial (ver lib/afd_spec.php).\n\n";
    foreach ($spec as $tipo => $campos) {
        if (!is_int($tipo)) continue;
        printf("Tipo %d — largura total %d bytes\n", $tipo, afd_largura($tipo, $spec));
        $pos = 1;
        foreach ($campos as [$nome, $len, $kind, $src]) {
            printf("  %4d-%-4d %-18s %-6s %s\n", $pos, $pos + $len - 1, $nome, $kind, $src);
            $pos += $len;
        }
        echo "\n";
    }
    $erros = afd_spec_selfcheck($spec);
    echo $erros
        ? "Consistencia interna: " . count($erros) . " problema(s)\n  - " . implode("\n  - ", $erros) . "\n"
        : "Consistencia interna: OK (nao substitui a conferencia com o Anexo).\n";
    exit($erros ? 1 : 0);
}

$de  = $opt('de');
$ate = $opt('ate');
if (!$de || !$ate) {
    echo "Informe o periodo: --de=AAAA-MM-DD --ate=AAAA-MM-DD\n";
    exit(1);
}
foreach ([$de, $ate] as $d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { echo "Data invalida: {$d}\n"; exit(1); }
}
if ($de > $ate) { echo "O inicio do periodo e posterior ao fim.\n"; exit(1); }

$pdo = db();

// --------------------------------------------------------------------------
echo "=====================================================================\n";
echo " AFD — periodo {$de} a {$ate}\n";
echo "=====================================================================\n\n";

$pf = afd_preflight($pdo, $de, $ate);

if ($pf['bloqueios']) {
    echo "BLOQUEIOS (impedem a emissao):\n";
    foreach ($pf['bloqueios'] as $b) echo "  - {$b}\n";
    echo "\n";
}
if ($pf['avisos']) {
    echo "AVISOS (o arquivo sai, mas com lacuna):\n";
    foreach ($pf['avisos'] as $a) echo "  - {$a}\n";
    echo "\n";
}
if (!$pf['bloqueios'] && !$pf['avisos']) {
    echo "Pre-voo: nenhuma pendencia.\n\n";
}

if ($has('preflight')) {
    exit($pf['ok'] ? 0 : 1);
}

if (!$pf['ok']) {
    echo "Emissao ABORTADA. Os bloqueios acima sao de CADASTRO, nao de sistema:\n";
    echo "preencha employer_config (CNPJ/CPF, razao social, identificador do REP)\n";
    echo "em Admin > Configuracao do Empregador. Ver Fase 0c de\n";
    echo "docs/AUDITORIA_CONFORMIDADE_2026-08-05.md.\n\n";
    echo "O PIS NAO bloqueia: as marcacoes (tipo 7) identificam pelo CPF, e o\n";
    echo "registro tipo 5 sai com o campo PIS zerado.\n";
    exit(1);
}

// --------------------------------------------------------------------------
$aceitaRascunho = $has('spec-nao-verificada');
if (!AFD_SPEC_VERIFICADA && !$aceitaRascunho) {
    echo "RECUSADO: o leiaute ainda nao foi conferido contra o Anexo oficial.\n";
    echo "Um arquivo posicional com uma coluna deslocada passa em teste interno\n";
    echo "e e rejeitado na fiscalizacao. Confira lib/afd_spec.php campo a campo\n";
    echo "contra o Anexo I da Portaria MTP 671/2021, valide num validador publico\n";
    echo "e marque AFD_SPEC_VERIFICADA = true.\n\n";
    echo "Para gerar assim mesmo, como RASCUNHO: --spec-nao-verificada\n";
    exit(1);
}

$out = $opt('out') ?: sprintf('AFD_%s_%s.txt', str_replace('-', '', $de), str_replace('-', '', $ate));
$fh  = @fopen($out, 'wb');
if (!$fh) { echo "Nao foi possivel escrever em {$out}\n"; exit(1); }

$t0 = microtime(true);
try {
    $r = afd_generate_to_stream($pdo, $de, $ate, $fh, [
        'aceitar_spec_nao_verificada' => $aceitaRascunho,
    ]);
} catch (Throwable $e) {
    fclose($fh);
    @unlink($out);
    echo "FALHOU: {$e->getMessage()}\n";
    exit(1);
}
fclose($fh);

$seg = round(microtime(true) - $t0, 2);
echo "Arquivo gerado: {$out}\n";
echo "  linhas ....: {$r['linhas']}\n";
echo "  bytes .....: {$r['bytes']}\n";
echo "  tempo .....: {$seg}s\n";
$c = $r['contadores'];
echo "  registros ..: tipo2={$c[2]} tipo4={$c[4]} tipo5={$c[5]} tipo7={$c[7]}\n";

if (!AFD_SPEC_VERIFICADA) {
    echo "\n*** RASCUNHO — leiaute nao conferido contra o Anexo oficial. ***\n";
    echo "NAO entregue este arquivo a fiscalizacao sem validar antes.\n";
    exit(2);
}
exit(0);

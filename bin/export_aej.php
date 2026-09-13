#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Exportação do Arquivo Eletrônico de Jornada (AEJ) — Portaria MTP 671/2021.
 * ==========================================================================
 * Fase 5 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md — NC-06.
 *
 * Uso:
 *   php bin/export_aej.php --de=2026-04-01 --ate=2026-04-30 [--out=aej.txt]
 *   php bin/export_aej.php --preflight --de=... --ate=...
 *   php bin/export_aej.php --apuracao --de=... --ate=...   # o que o arquivo vai declarar
 *   php bin/export_aej.php --spec
 *
 * Códigos de saída: 0 ok · 1 erro/bloqueio · 2 gerado como rascunho
 */

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando (CLI)\n");
}
set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/aej.php';

$args = $argv ?? [];
$opt = function (string $n) use ($args): ?string {
    foreach ($args as $a) if (str_starts_with($a, "--{$n}=")) return substr($a, strlen($n) + 3);
    return null;
};
$has = fn(string $n) => in_array("--{$n}", $args, true);

if ($has('spec')) {
    $spec = aej_spec();
    echo "Leiaute do AEJ — versao {$spec['versao']}\n";
    echo AEJ_SPEC_VERIFICADA ? "Status: CONFERIDO.\n\n" : "Status: *** NAO CONFERIDO *** contra o Anexo oficial.\n\n";
    foreach ($spec as $tipo => $campos) {
        if (!is_int($tipo)) continue;
        printf("Tipo %d — largura total %d bytes\n", $tipo, aej_largura($tipo, $spec));
        $pos = 1;
        foreach ($campos as [$nome, $len, $kind, $src]) {
            printf("  %4d-%-4d %-20s %-6s %s\n", $pos, $pos + $len - 1, $nome, $kind, $src);
            $pos += $len;
        }
        echo "\n";
    }
    exit(0);
}

$de = $opt('de'); $ate = $opt('ate');
if (!$de || !$ate) { echo "Informe: --de=AAAA-MM-DD --ate=AAAA-MM-DD\n"; exit(1); }
foreach ([$de, $ate] as $d) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { echo "Data invalida: {$d}\n"; exit(1); }
if ($de > $ate) { echo "Inicio posterior ao fim.\n"; exit(1); }

$pdo = db();

echo "=====================================================================\n";
echo " AEJ — periodo {$de} a {$ate}\n";
echo "=====================================================================\n\n";

// ---------------------------------------------------------------------------
// Resumo da apuração — o que o arquivo vai DECLARAR
// ---------------------------------------------------------------------------
if ($has('apuracao') || !$has('preflight')) {
    echo "--- O que o AEJ vai declarar ---\n";
    $t0 = microtime(true);
    $ap = aej_resumo_apuracao($pdo, $de, $ate);
    printf("  colaboradores ativos ......: %d\n", $ap['colaboradores']);
    printf("  dias com jornada prevista .: %d\n", $ap['dias']);
    printf("  jornada contratual total ..: %d min (%.1f h)\n", $ap['previsto'], $ap['previsto'] / 60);
    printf("  tempo trabalhado total ....: %d min (%.1f h)\n", $ap['realizado'], $ap['realizado'] / 60);
    printf("  diferenca .................: %+d min (%.1f h)\n",
           $ap['realizado'] - $ap['previsto'], ($ap['realizado'] - $ap['previsto']) / 60);
    printf("  dias com deficit ..........: %d (%.0f%%)\n",
           $ap['dias_deficit'], $ap['dias'] ? 100 * $ap['dias_deficit'] / $ap['dias'] : 0);
    printf("  dias SEM NENHUMA MARCACAO .: %d (%.0f%%)\n",
           $ap['dias_sem_marcacao'], $ap['dias'] ? 100 * $ap['dias_sem_marcacao'] / $ap['dias'] : 0);
    // Separar "ausencia" de "ausencia de dado" — sem isso o numero acima nao
    // diz nada. Ver a investigacao de 2026-08-07: 89% dos dias sem marcacao em
    // junho eram simplesmente o fim da base, nao falta de ninguem.
    $semDado = (int)($ap['dias_apos_fim_dos_dados'] ?? 0);
    if ($semDado > 0) {
        printf("    dos quais, apos o fim dos dados: %d (%.0f%%)\n",
               $semDado, $ap['dias_sem_marcacao'] ? 100 * $semDado / $ap['dias_sem_marcacao'] : 0);
        printf("    ausencia dentro do periodo ativo: %d\n", $ap['dias_sem_marcacao'] - $semDado);
        printf("    ultimo dia com movimento no sistema: %s\n", $ap['ultimo_dia_com_movimento'] ?? '-');
    }
    printf("  (apurado em %.1fs)\n\n", microtime(true) - $t0);

    if ($semDado > 0) {
        echo "  ATENCAO — o periodo pedido passa do ultimo dia em que o sistema teve\n";
        echo "  movimento. Os dias posteriores NAO tem marcacao alguma, e o AEJ os\n";
        echo "  declara como jornada NAO CUMPRIDA. Isso nao e ausencia: e ausencia de\n";
        echo "  dado. Verifique se houve recesso, parada do sistema ou fim da base, e\n";
        echo "  considere encurtar o periodo. Detalhe por colaborador e data:\n";
        echo "    php bin/aej_diagnostico.php --de={$de} --ate={$ate}\n\n";
    }

    $ausenciaReal = $ap['dias_sem_marcacao'] - $semDado;
    if ($ausenciaReal > 0 && $ap['dias'] > 0 && ($ausenciaReal / $ap['dias']) > 0.10) {
        echo "  ATENCAO — mais de 10% dos dias previstos, DENTRO do periodo com dados,\n";
        echo "  nao tem NENHUMA marcacao. O AEJ declara isso como jornada nao cumprida.\n";
        echo "  Antes de entregar, confirme se sao ausencias reais, afastamentos ainda\n";
        echo "  nao lancados, ou falha de registro. Esta e a primeira vez que o sistema\n";
        echo "  expoe essa diferenca: a politica interna do banco de horas nao grava\n";
        echo "  saldo negativo, entao ela nunca apareceu nos relatorios.\n\n";
    }
}

// ---------------------------------------------------------------------------
$pf = aej_preflight($pdo, $de, $ate);
if ($pf['bloqueios']) {
    echo "BLOQUEIOS (impedem a emissao):\n";
    foreach ($pf['bloqueios'] as $b) echo "  - {$b}\n";
    echo "\n";
}
if ($pf['avisos']) {
    echo "AVISOS:\n";
    foreach ($pf['avisos'] as $a) echo "  - {$a}\n";
    echo "\n";
}

if ($has('preflight') || $has('apuracao')) exit($pf['ok'] ? 0 : 1);

if (!$pf['ok']) {
    echo "Emissao ABORTADA. Os bloqueios acima sao de CADASTRO, nao de codigo:\n";
    echo "  - CNPJ/CPF do empregador em Admin > Configuracao do Empregador.\n";
    echo "\n";
    echo "O PIS NAO bloqueia: todo registro do AEJ carrega PIS e CPF, e sem PIS\n";
    echo "o campo sai zerado com o trabalhador identificado pelo CPF.\n";
    exit(1);
}

$rascunho = $has('spec-nao-verificada');
if (!AEJ_SPEC_VERIFICADA && !$rascunho) {
    echo "RECUSADO: leiaute nao conferido contra o Anexo oficial.\n";
    echo "Para gerar como RASCUNHO: --spec-nao-verificada\n";
    exit(1);
}

$out = $opt('out') ?: sprintf('AEJ_%s_%s.txt', str_replace('-', '', $de), str_replace('-', '', $ate));
$fh = @fopen($out, 'wb');
if (!$fh) { echo "Nao foi possivel escrever em {$out}\n"; exit(1); }

$t0 = microtime(true);
try {
    $r = aej_generate_to_stream($pdo, $de, $ate, $fh, ['aceitar_spec_nao_verificada' => $rascunho]);
} catch (Throwable $e) {
    fclose($fh); @unlink($out);
    echo "FALHOU: {$e->getMessage()}\n";
    exit(1);
}
fclose($fh);

$c = $r['contadores'];
echo "Arquivo gerado: {$out}\n";
echo "  linhas ....: {$r['linhas']}  ({$r['bytes']} bytes, " . round(microtime(true) - $t0, 2) . "s)\n";
echo "  registros ..: jornada={$c[2]} apuracao={$c[3]} ocorrencias={$c[4]} compensacoes={$c[5]}\n";

if (!AEJ_SPEC_VERIFICADA) {
    echo "\n*** RASCUNHO — leiaute nao conferido. Nao entregue a fiscalizacao. ***\n";
    exit(2);
}
exit(0);

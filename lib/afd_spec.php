<?php
declare(strict_types=1);
/**
 * LEIAUTE DO ARQUIVO FONTE DE DADOS (AFD) — Portaria MTP 671/2021.
 * ================================================================
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  ATENÇÃO — ESTE LEIAUTE AINDA NÃO FOI CONFERIDO CONTRA O ANEXO OFICIAL.  │
 * │                                                                          │
 * │  As posições e larguras abaixo são a estrutura de trabalho do gerador,   │
 * │  NÃO uma transcrição validada da Portaria. Uma única coluna com largura  │
 * │  errada produz um arquivo que passa em todo teste interno e é REJEITADO  │
 * │  na fiscalização — o pior modo de falha possível, porque só aparece no   │
 * │  momento em que não dá mais para corrigir.                               │
 * │                                                                          │
 * │  Antes de qualquer uso real:                                             │
 * │    1. conferir campo a campo contra o Anexo I da Portaria MTP 671/2021;  │
 * │    2. submeter um arquivo gerado a um validador público de AFD;          │
 * │    3. só então marcar AFD_SPEC_VERIFICADA = true (abaixo).               │
 * │                                                                          │
 * │  Enquanto a constante for `false`, o exportador recusa gerar sem a flag  │
 * │  explícita --spec-nao-verificada, e o arquivo sai com marca d'água.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * O leiaute vive aqui como DADO, não espalhado em código de formatação. Trocar
 * uma largura é editar uma linha desta tabela; `afd_line()` valida o total e
 * falha alto se não bater. É o que torna a conferência com o Anexo viável.
 *
 * TIPOS DE REGISTRO (Portaria 671):
 *   1  cabeçalho
 *   2  inclusão/alteração de empregador
 *   3  marcação de ponto identificada por PIS   → REP-C
 *   4  ajuste do relógio
 *   5  inclusão/alteração/exclusão de empregado
 *   6  eventos sensíveis do REP                 → REP-C
 *   7  marcação de ponto identificada por CPF   → REP-P / REP-A
 *   9  trailer com contadores
 *
 * Este sistema é REP-P: as marcações saem como tipo 7 (CPF). O tipo 3 fica
 * definido para completude, mas não é emitido.
 */

/**
 * A spec já foi conferida contra o Anexo oficial E validada num validador
 * público de AFD? Enquanto false, o gerador se recusa a produzir arquivo
 * "de verdade" sem que o operador assuma o risco explicitamente.
 */
const AFD_SPEC_VERIFICADA = false;

/**
 * Descrição declarativa dos registros.
 *
 * Cada campo: [nome, largura, tipo, origem]
 *   tipo  'num'   → dígitos, zeros à esquerda
 *         'alpha' → texto, espaços à direita, transliterado para ASCII
 *   origem é documentação: de onde o valor sai no schema.
 */
function afd_spec(string $versao = '003'): array {
    return [
        'versao' => $versao,

        // ---- Tipo 1: cabeçalho -------------------------------------------
        1 => [
            ['nsr',              9, 'num',   'sempre 000000000 no cabecalho'],
            ['tipo_registro',    1, 'num',   'constante 1'],
            ['tipo_ident_empr',  1, 'num',   'employer_config.employer_type (1=CNPJ 2=CPF)'],
            ['ident_empregador',14, 'num',   'employer_config.cnpj ou .cpf (so digitos)'],
            ['cei_caepf_cno',   12, 'num',   'employer_config.cei_caepf_cno'],
            ['razao_social',   150, 'alpha', 'employer_config.company_name'],
            ['ident_rep',       17, 'alpha', 'employer_config.rep_identifier'],
            ['data_inicial',     8, 'num',   'ddmmaaaa — inicio do periodo exportado'],
            ['data_final',       8, 'num',   'ddmmaaaa — fim do periodo exportado'],
            ['data_geracao',     8, 'num',   'ddmmaaaa — agora'],
            ['hora_geracao',     6, 'num',   'hhmmss — agora'],
            ['versao_leiaute',   3, 'num',   'employer_config.afd_layout_version'],
        ],

        // ---- Tipo 2: inclusão/alteração de empregador ---------------------
        2 => [
            ['nsr',              9, 'num',   'nsr_ledger.nsr'],
            ['tipo_registro',    1, 'num',   'constante 2'],
            ['data_gravacao',    8, 'num',   'ddmmaaaa'],
            ['hora_gravacao',    6, 'num',   'hhmmss'],
            ['tipo_ident_empr',  1, 'num',   'employer_type'],
            ['ident_empregador',14, 'num',   'CNPJ/CPF'],
            ['cei_caepf_cno',   12, 'num',   'cei_caepf_cno'],
            ['razao_social',   150, 'alpha', 'company_name'],
            ['local_prestacao', 100, 'alpha', 'employer_config.service_location'],
        ],

        // ---- Tipo 3: marcação por PIS (REP-C — não emitido por este REP-P) --
        3 => [
            ['nsr',              9, 'num',   'nsr_ledger.nsr'],
            ['tipo_registro',    1, 'num',   'constante 3'],
            ['data_marcacao',    8, 'num',   'ddmmaaaa de nsr_ledger.marked_at'],
            ['hora_marcacao',    4, 'num',   'hhmm de nsr_ledger.marked_at'],
            ['pis',             12, 'num',   'nsr_ledger.teacher_pis'],
        ],

        // ---- Tipo 4: ajuste do relógio ------------------------------------
        4 => [
            ['nsr',              9, 'num',   'nsr_ledger.nsr'],
            ['tipo_registro',    1, 'num',   'constante 4'],
            ['data_antes',       8, 'num',   'ddmmaaaa antes do ajuste'],
            ['hora_antes',       6, 'num',   'hhmmss antes do ajuste'],
            ['data_depois',      8, 'num',   'ddmmaaaa depois do ajuste'],
            ['hora_depois',      6, 'num',   'hhmmss depois do ajuste'],
        ],

        // ---- Tipo 5: inclusão/alteração/exclusão de empregado -------------
        5 => [
            ['nsr',              9, 'num',   'nsr_ledger.nsr'],
            ['tipo_registro',    1, 'num',   'constante 5'],
            ['data_gravacao',    8, 'num',   'ddmmaaaa'],
            ['hora_gravacao',    6, 'num',   'hhmmss'],
            ['operacao',         1, 'alpha', 'I=inclusao A=alteracao E=exclusao'],
            ['pis',             12, 'num',   'teachers.pis'],
            ['nome',            52, 'alpha', 'teachers.name'],
        ],

        // ---- Tipo 7: marcação por CPF (REP-P) ------------------------------
        7 => [
            ['nsr',              9, 'num',   'nsr_ledger.nsr'],
            ['tipo_registro',    1, 'num',   'constante 7'],
            ['data_marcacao',    8, 'num',   'ddmmaaaa de nsr_ledger.marked_at'],
            ['hora_marcacao',    4, 'num',   'hhmm de nsr_ledger.marked_at'],
            ['cpf',             11, 'num',   'nsr_ledger.teacher_cpf'],
        ],

        // ---- Tipo 9: trailer ----------------------------------------------
        9 => [
            ['nsr',              9, 'num',   'sempre 999999999'],
            ['tipo_registro',    1, 'num',   'constante 9'],
            ['qtd_tipo_2',       9, 'num',   'contador'],
            ['qtd_tipo_3',       9, 'num',   'contador'],
            ['qtd_tipo_4',       9, 'num',   'contador'],
            ['qtd_tipo_5',       9, 'num',   'contador'],
            ['qtd_tipo_6',       9, 'num',   'contador'],
            ['qtd_tipo_7',       9, 'num',   'contador'],
        ],
    ];
}

/** Largura total esperada de um tipo de registro. */
function afd_largura(int $tipo, ?array $spec = null): int {
    $spec = $spec ?? afd_spec();
    if (!isset($spec[$tipo])) {
        throw new InvalidArgumentException("Tipo de registro AFD desconhecido: {$tipo}");
    }
    $n = 0;
    foreach ($spec[$tipo] as [$nome, $len, $kind, $src]) $n += $len;
    return $n;
}

/**
 * Consistência interna da spec. Não substitui a conferência com o Anexo —
 * apenas garante que a tabela acima não tem erro grosseiro.
 *
 * @return array lista de problemas (vazia = spec internamente coerente)
 */
function afd_spec_selfcheck(?array $spec = null): array {
    $spec = $spec ?? afd_spec();
    $erros = [];
    foreach ($spec as $tipo => $campos) {
        if (!is_int($tipo)) continue;
        $vistos = [];
        foreach ($campos as $i => $c) {
            if (!is_array($c) || count($c) !== 4) {
                $erros[] = "tipo {$tipo}, campo #{$i}: definicao malformada";
                continue;
            }
            [$nome, $len, $kind, $src] = $c;
            if (!is_string($nome) || $nome === '')       $erros[] = "tipo {$tipo}, campo #{$i}: nome vazio";
            if (!is_int($len) || $len <= 0)              $erros[] = "tipo {$tipo}, campo {$nome}: largura invalida";
            if (!in_array($kind, ['num', 'alpha'], true)) $erros[] = "tipo {$tipo}, campo {$nome}: tipo '{$kind}' invalido";
            if (isset($vistos[$nome]))                    $erros[] = "tipo {$tipo}: campo '{$nome}' duplicado";
            $vistos[$nome] = true;
        }
        if (($campos[0][0] ?? '') !== 'nsr')           $erros[] = "tipo {$tipo}: primeiro campo deveria ser 'nsr'";
        if (($campos[1][0] ?? '') !== 'tipo_registro') $erros[] = "tipo {$tipo}: segundo campo deveria ser 'tipo_registro'";
    }
    return $erros;
}

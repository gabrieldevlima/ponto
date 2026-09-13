<?php
declare(strict_types=1);
/**
 * Gerador do Arquivo Fonte de Dados (AFD) — Portaria MTP 671/2021.
 * ================================================================
 * Fase 4 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md — resolve NC-05.
 *
 * O leiaute vive em lib/afd_spec.php como DADO. Este arquivo só sabe montar
 * linhas a partir dele, validar largura e percorrer o livro fiscal.
 *
 * LEIA O AVISO NO TOPO DE afd_spec.php: o leiaute ainda não foi conferido
 * contra o Anexo oficial. Enquanto `AFD_SPEC_VERIFICADA` for false, o gerador
 * marca o arquivo como não-validado e o exportador exige confirmação explícita.
 *
 * Fonte dos dados: `nsr_ledger`, ordenado por NSR. O livro é append-only,
 * encadeado e selado — é o que dá ao AFD o lastro que um SELECT em `attendance`
 * não teria.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/afd_spec.php';

/**
 * Transliteração para ASCII. O AFD é um arquivo posicional de largura fixa:
 * um caractere multibyte ocuparia mais de um byte e deslocaria todas as
 * colunas seguintes da linha — corrompendo silenciosamente o arquivo inteiro.
 */
function afd_ascii(?string $v): string {
    $v = (string)$v;
    if ($v === '') return '';
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
    if ($t === false) $t = preg_replace('/[^\x20-\x7E]/', ' ', $v);
    // iconv com //TRANSLIT pode gerar "c" para ç mas também `"a` para ä.
    $t = preg_replace('/[^\x20-\x7E]/', ' ', (string)$t);
    return (string)$t;
}

/** Campo numérico: só dígitos, zeros à esquerda, truncado pela DIREITA se exceder. */
function afd_pad_num($v, int $len): string {
    $d = preg_replace('/\D/', '', (string)($v ?? ''));
    if ($d === '') $d = '0';
    if (strlen($d) > $len) $d = substr($d, -$len); // mantém a parte menos significativa
    return str_pad($d, $len, '0', STR_PAD_LEFT);
}

/** Campo alfanumérico: ASCII, espaços à direita, truncado à direita se exceder. */
function afd_pad_alpha($v, int $len): string {
    $s = strtoupper(afd_ascii((string)($v ?? '')));
    $s = preg_replace('/\s+/', ' ', $s);
    if (strlen($s) > $len) $s = substr($s, 0, $len);
    return str_pad($s, $len, ' ', STR_PAD_RIGHT);
}

/**
 * Monta uma linha do AFD.
 *
 * FALHA ALTO por desenho: campo ausente na spec, ou largura final diferente da
 * esperada, lança exceção. Um arquivo posicional com uma coluna deslocada é
 * pior que arquivo nenhum — passa em teste interno e é rejeitado na
 * fiscalização, quando já não há como corrigir.
 */
function afd_line(int $tipo, array $valores, ?array $spec = null): string {
    $spec = $spec ?? afd_spec();
    if (!isset($spec[$tipo])) {
        throw new InvalidArgumentException("Tipo de registro AFD desconhecido: {$tipo}");
    }
    $out = '';
    foreach ($spec[$tipo] as [$nome, $len, $kind, $src]) {
        if (!array_key_exists($nome, $valores)) {
            throw new RuntimeException("AFD tipo {$tipo}: campo '{$nome}' nao informado.");
        }
        $out .= $kind === 'num'
            ? afd_pad_num($valores[$nome], $len)
            : afd_pad_alpha($valores[$nome], $len);
    }
    $esperado = afd_largura($tipo, $spec);
    if (strlen($out) !== $esperado) {
        throw new RuntimeException(
            "AFD tipo {$tipo}: linha com " . strlen($out) . " bytes, esperados {$esperado}."
        );
    }
    return $out;
}

/**
 * Pré-voo: o que impede a emissão do AFD hoje.
 *
 * Separa BLOQUEIOS (sem isso o arquivo não pode ser emitido) de AVISOS (sai,
 * mas com lacuna). Existe porque o gargalo do AFD neste sistema não é código —
 * é cadastro: CNPJ do empregador, identificador do REP e PIS dos trabalhadores.
 *
 * @return array{ok:bool,bloqueios:array,avisos:array}
 */
function afd_preflight(PDO $pdo, string $de, string $ate): array {
    $bloqueios = [];
    $avisos    = [];

    $e = $pdo->query("SELECT * FROM employer_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$e) {
        $bloqueios[] = 'employer_config esta vazia — sem identificacao do empregador nao ha cabecalho.';
        $e = [];
    } else {
        $tipoId = (int)($e['employer_type'] ?? 1);
        $doc    = $tipoId === 2 ? ($e['cpf'] ?? '') : ($e['cnpj'] ?? '');
        $rotulo = $tipoId === 2 ? 'CPF' : 'CNPJ';
        if (preg_replace('/\D/', '', (string)$doc) === '') {
            $bloqueios[] = "{$rotulo} do empregador nao preenchido (employer_config).";
        }
        if (trim((string)($e['company_name'] ?? '')) === '') {
            $bloqueios[] = 'Razao social do empregador nao preenchida.';
        }
        if (trim((string)($e['rep_identifier'] ?? '')) === '') {
            $bloqueios[] = 'Identificador do REP nao preenchido (employer_config.rep_identifier).';
        }
        if (trim((string)($e['service_location'] ?? '')) === '') {
            $avisos[] = 'Local da prestacao de servico nao preenchido — registro tipo 2 sai incompleto.';
        }
    }

    $st = $pdo->prepare("
        SELECT COUNT(*) total,
               SUM(teacher_cpf IS NULL OR teacher_cpf = '') sem_cpf
          FROM nsr_ledger
         WHERE event_type = 'mark' AND work_date BETWEEN ? AND ?
    ");
    $st->execute([$de, $ate]);
    $m = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'sem_cpf' => 0];

    if ((int)$m['total'] === 0) {
        $bloqueios[] = "Nenhuma marcacao no livro fiscal entre {$de} e {$ate}.";
    }
    if ((int)$m['sem_cpf'] > 0) {
        // Tipo 7 (REP-P) identifica o trabalhador pelo CPF; sem ele a marcação
        // não tem como ser atribuída a ninguém.
        $bloqueios[] = (int)$m['sem_cpf'] . ' marcacao(oes) sem CPF no livro — tipo 7 exige CPF.';
    }

    $semPis = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE active = 1 AND (pis IS NULL OR pis = '')")->fetchColumn();
    if ($semPis > 0) {
        // O tipo 7 (marcação, REP-P) identifica pelo CPF e não é afetado. O
        // tipo 5 (cadastro de empregado) sai com o campo PIS zerado.
        // Decisão do empregador (SEMED, 2026-08-07): os PIS não existem e não
        // serão obtidos. Confirmar na homologação se o campo é obrigatório.
        $avisos[] = "{$semPis} colaborador(es) ativo(s) sem PIS — registro tipo 5 sai com o campo "
                  . 'PIS zerado. Marcacoes (tipo 7) nao sao afetadas: identificam pelo CPF.';
    }

    if (!AFD_SPEC_VERIFICADA) {
        $avisos[] = 'O leiaute NAO foi conferido contra o Anexo oficial nem validado '
                  . 'em validador publico (ver lib/afd_spec.php).';
    }

    return ['ok' => empty($bloqueios), 'bloqueios' => $bloqueios, 'avisos' => $avisos];
}

/**
 * Gera o AFD linha a linha.
 *
 * Generator de propósito: `.user.ini` fixa memory_limit em 256M e
 * max_execution_time em 60s. Um AFD anual materializado em array estouraria a
 * memória; em streaming, o consumo não depende do tamanho do período.
 *
 * @return Generator<string> linhas SEM terminador (quem consome decide CRLF/LF).
 *                           getReturn() devolve os contadores por tipo.
 */
function afd_generate(PDO $pdo, string $de, string $ate, array $opts = []): Generator {
    $spec = afd_spec((string)($opts['versao'] ?? '003'));
    $e = $pdo->query("SELECT * FROM employer_config LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

    $tipoId = (int)($e['employer_type'] ?? 1);
    $identEmpregador = $tipoId === 2 ? ($e['cpf'] ?? '') : ($e['cnpj'] ?? '');
    $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

    // ---- Tipo 1: cabeçalho -------------------------------------------------
    yield afd_line(1, [
        'nsr'              => 0,
        'tipo_registro'    => 1,
        'tipo_ident_empr'  => $tipoId,
        'ident_empregador' => $identEmpregador,
        'cei_caepf_cno'    => $e['cei_caepf_cno'] ?? '',
        'razao_social'     => $e['company_name'] ?? '',
        'ident_rep'        => $e['rep_identifier'] ?? '',
        'data_inicial'     => date('dmY', strtotime($de)),
        'data_final'       => date('dmY', strtotime($ate)),
        'data_geracao'     => $agora->format('dmY'),
        'hora_geracao'     => $agora->format('His'),
        'versao_leiaute'   => $e['afd_layout_version'] ?? '003',
    ], $spec);

    $cont = [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];

    // ---- Corpo: um único passe pelo livro, ordenado por NSR ----------------
    //
    // A ordem por NSR é o que garante que o AFD reproduza a sequência fiscal.
    // Cursor não-bufferizado para não carregar o período inteiro na memória.
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $st = $pdo->prepare("
            SELECT nsr, event_type, marked_at, work_date, teacher_cpf, teacher_pis,
                   teacher_id, reason, target_nsr, created_at
              FROM nsr_ledger
             WHERE event_type IN ('mark','employer_change','employee_change','clock_adjust')
               AND COALESCE(work_date, DATE(created_at)) BETWEEN ? AND ?
             ORDER BY nsr ASC
        ");
        $st->execute([$de, $ate]);

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $quando = $r['marked_at'] ?: $r['created_at'];
            $ts = strtotime((string)$quando) ?: time();

            switch ($r['event_type']) {
                case 'mark':
                    // REP-P: marcação identificada por CPF → tipo 7.
                    yield afd_line(7, [
                        'nsr'            => $r['nsr'],
                        'tipo_registro'  => 7,
                        'data_marcacao'  => date('dmY', $ts),
                        'hora_marcacao'  => date('Hi', $ts),
                        'cpf'            => $r['teacher_cpf'],
                    ], $spec);
                    $cont[7]++;
                    break;

                case 'employer_change':
                    yield afd_line(2, [
                        'nsr'              => $r['nsr'],
                        'tipo_registro'    => 2,
                        'data_gravacao'    => date('dmY', $ts),
                        'hora_gravacao'    => date('His', $ts),
                        'tipo_ident_empr'  => $tipoId,
                        'ident_empregador' => $identEmpregador,
                        'cei_caepf_cno'    => $e['cei_caepf_cno'] ?? '',
                        'razao_social'     => $e['company_name'] ?? '',
                        'local_prestacao'  => $e['service_location'] ?? '',
                    ], $spec);
                    $cont[2]++;
                    break;

                case 'employee_change':
                    // A operação (I/A/E) e o nome vão em `reason`, no formato
                    // "I|Nome do trabalhador", gravado por quem emite o evento.
                    $partes = explode('|', (string)$r['reason'], 2);
                    yield afd_line(5, [
                        'nsr'           => $r['nsr'],
                        'tipo_registro' => 5,
                        'data_gravacao' => date('dmY', $ts),
                        'hora_gravacao' => date('His', $ts),
                        'operacao'      => strtoupper(substr(trim($partes[0] ?? 'A'), 0, 1)) ?: 'A',
                        'pis'           => $r['teacher_pis'],
                        'nome'          => $partes[1] ?? '',
                    ], $spec);
                    $cont[5]++;
                    break;

                case 'clock_adjust':
                    // `reason` traz "antes|depois" em Y-m-d H:i:s.
                    $p = explode('|', (string)$r['reason'], 2);
                    $tsAntes  = strtotime(trim($p[0] ?? '')) ?: $ts;
                    $tsDepois = strtotime(trim($p[1] ?? '')) ?: $ts;
                    yield afd_line(4, [
                        'nsr'           => $r['nsr'],
                        'tipo_registro' => 4,
                        'data_antes'    => date('dmY', $tsAntes),
                        'hora_antes'    => date('His', $tsAntes),
                        'data_depois'   => date('dmY', $tsDepois),
                        'hora_depois'   => date('His', $tsDepois),
                    ], $spec);
                    $cont[4]++;
                    break;
            }
        }
        $st->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    // ---- Tipo 9: trailer ---------------------------------------------------
    yield afd_line(9, [
        'nsr'           => 999999999,
        'tipo_registro' => 9,
        'qtd_tipo_2'    => $cont[2],
        'qtd_tipo_3'    => $cont[3],
        'qtd_tipo_4'    => $cont[4],
        'qtd_tipo_5'    => $cont[5],
        'qtd_tipo_6'    => $cont[6],
        'qtd_tipo_7'    => $cont[7],
    ], $spec);

    // O generator devolve os contadores como valor de retorno; quem precisar
    // deles usa ->getReturn() após consumir todas as linhas.
    return $cont;
}

/**
 * Escreve o AFD num stream e devolve os contadores.
 *
 * Terminador de linha CRLF: é o usual em arquivos posicionais de fiscalização.
 * FICA REGISTRADO COMO PONTO A CONFERIR no Anexo, junto com as larguras.
 *
 * @return array{linhas:int,bytes:int,contadores:array}
 */
function afd_generate_to_stream(PDO $pdo, string $de, string $ate, $handle, array $opts = []): array {
    $eol    = (string)($opts['eol'] ?? "\r\n");
    $linhas = 0;
    $bytes  = 0;

    if (!AFD_SPEC_VERIFICADA && empty($opts['aceitar_spec_nao_verificada'])) {
        throw new RuntimeException(
            'O leiaute do AFD ainda nao foi conferido contra o Anexo oficial '
            . '(AFD_SPEC_VERIFICADA = false em lib/afd_spec.php). '
            . 'Para gerar mesmo assim, passe --spec-nao-verificada e trate o '
            . 'arquivo como RASCUNHO.'
        );
    }

    $gen = afd_generate($pdo, $de, $ate, $opts);
    foreach ($gen as $linha) {
        $n = fwrite($handle, $linha . $eol);
        if ($n === false) throw new RuntimeException('Falha ao escrever o AFD.');
        $linhas++;
        $bytes += $n;
        // Empurra para o cliente a cada linha: em export HTTP evita acumular
        // o arquivo inteiro no buffer de saída.
        if (!empty($opts['flush'])) { @ob_flush(); @flush(); }
    }

    return ['linhas' => $linhas, 'bytes' => $bytes, 'contadores' => $gen->getReturn() ?: []];
}

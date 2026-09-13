<?php
declare(strict_types=1);
/**
 * Arquivo Eletrônico de Jornada (AEJ) — Portaria MTP 671/2021.
 * ============================================================
 * Fase 5 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md — resolve NC-06.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  O LEIAUTE AINDA NÃO FOI CONFERIDO CONTRA O ANEXO OFICIAL.               │
 * │  Mesma disciplina do AFD: enquanto AEJ_SPEC_VERIFICADA for false, o      │
 * │  exportador recusa gerar sem aceite explícito e marca o arquivo como     │
 * │  RASCUNHO. Ver o aviso completo em lib/afd_spec.php.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * A DIFERENÇA QUE MAIS IMPORTA NESTE ARQUIVO: JORNADA CONTRATUAL
 * ═══════════════════════════════════════════════════════════════════════════
 * O AEJ declara a JORNADA CONTRATUAL do trabalhador. O sistema, internamente,
 * usa `calculate_expected_minutes()`, que devolve a JANELA COMPLETA da jornada
 * SEM descontar o intervalo — é o modelo "cheio vs cheio" documentado em
 * helpers.php, no qual o intervalo conta como tempo de presença.
 *
 * Esse modelo é coerente para o saldo interno, mas NÃO pode ir para o AEJ.
 * Exemplo real desta base: um colaborador com janela 06:00–19:00 e 350 min de
 * intervalo tem `calculate_expected_minutes()` = 780 min. Declarar 780 no AEJ
 * afirmaria uma jornada contratual de 13 HORAS — o oposto do que o cadastro
 * diz. A jornada contratual dele é 780 − 350 = 430 min.
 *
 * Por isso o AEJ tem cálculo PRÓPRIO (`aej_jornada_contratual`), líquido de
 * intervalo. Reaproveitar a função interna aqui seria o tipo de erro que passa
 * despercebido no código e aparece como declaração falsa num documento fiscal.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/afd.php'; // reusa afd_pad_num/afd_pad_alpha/afd_ascii

/** Ver AFD_SPEC_VERIFICADA — mesma regra, mesmo motivo. */
const AEJ_SPEC_VERIFICADA = false;

/**
 * Leiaute do AEJ como DADO. Mesma estrutura da spec do AFD:
 * [nome, largura, tipo, origem].
 *
 * Tipos de registro:
 *   1  cabeçalho
 *   2  jornada contratual (horário contratual do trabalhador)
 *   3  apuração diária (previsto, realizado, ocorrências)
 *   4  ocorrência / afastamento
 *   5  compensação (banco de horas)
 *   9  trailer
 */
function aej_spec(string $versao = '001'): array {
    return [
        'versao' => $versao,

        1 => [
            ['nsr',              9, 'num',   'sequencial do arquivo'],
            ['tipo_registro',    1, 'num',   'constante 1'],
            ['tipo_ident_empr',  1, 'num',   'employer_config.employer_type'],
            ['ident_empregador',14, 'num',   'CNPJ ou CPF do empregador'],
            ['cei_caepf_cno',   12, 'num',   'employer_config.cei_caepf_cno'],
            ['razao_social',   150, 'alpha', 'employer_config.company_name'],
            ['data_inicial',     8, 'num',   'ddmmaaaa'],
            ['data_final',       8, 'num',   'ddmmaaaa'],
            ['data_geracao',     8, 'num',   'ddmmaaaa'],
            ['hora_geracao',     6, 'num',   'hhmmss'],
            ['versao_leiaute',   3, 'num',   'versao do leiaute'],
        ],

        2 => [
            ['nsr',              9, 'num',   'sequencial'],
            ['tipo_registro',    1, 'num',   'constante 2'],
            ['pis',             12, 'num',   'teachers.pis'],
            ['cpf',             11, 'num',   'teachers.cpf'],
            ['nome',            52, 'alpha', 'teachers.name'],
            ['data_inicio',      8, 'num',   'ddmmaaaa — vigencia do horario'],
            ['dia_semana',       1, 'num',   '1=domingo .. 7=sabado'],
            ['hora_entrada',     4, 'num',   'hhmm contratual'],
            ['hora_saida',       4, 'num',   'hhmm contratual'],
            ['minutos_intervalo',4, 'num',   'intervalo contratual em minutos'],
            ['jornada_liquida',  4, 'num',   'minutos contratuais LIQUIDOS de intervalo'],
        ],

        3 => [
            ['nsr',              9, 'num',   'sequencial'],
            ['tipo_registro',    1, 'num',   'constante 3'],
            ['pis',             12, 'num',   'teachers.pis'],
            ['cpf',             11, 'num',   'teachers.cpf'],
            ['data',             8, 'num',   'ddmmaaaa do dia apurado'],
            ['jornada_prevista', 4, 'num',   'minutos contratuais do dia'],
            ['tempo_trabalhado', 4, 'num',   'minutos efetivamente trabalhados'],
            ['diferenca_sinal',  1, 'alpha', '+ credito / - debito / 0 neutro'],
            ['diferenca',        4, 'num',   'modulo da diferenca em minutos'],
            ['cod_ocorrencia',   4, 'alpha', 'leave_types.aej_code, vazio se nenhuma'],
        ],

        4 => [
            ['nsr',              9, 'num',   'sequencial'],
            ['tipo_registro',    1, 'num',   'constante 4'],
            ['pis',             12, 'num',   'teachers.pis'],
            ['cpf',             11, 'num',   'teachers.cpf'],
            ['data_inicio',      8, 'num',   'ddmmaaaa'],
            ['data_fim',         8, 'num',   'ddmmaaaa'],
            ['cod_ocorrencia',   4, 'alpha', 'leave_types.aej_code'],
            ['descricao',       50, 'alpha', 'leave_types.name'],
        ],

        5 => [
            ['nsr',              9, 'num',   'sequencial'],
            ['tipo_registro',    1, 'num',   'constante 5'],
            ['pis',             12, 'num',   'teachers.pis'],
            ['cpf',             11, 'num',   'teachers.cpf'],
            ['data',             8, 'num',   'ddmmaaaa do lancamento'],
            ['sinal',            1, 'alpha', '+ credito / - debito'],
            ['minutos',          6, 'num',   'modulo dos minutos'],
            ['origem',          20, 'alpha', 'hour_bank_entries.source'],
        ],

        9 => [
            ['nsr',              9, 'num',   'sempre 999999999'],
            ['tipo_registro',    1, 'num',   'constante 9'],
            ['qtd_tipo_2',       9, 'num',   'contador'],
            ['qtd_tipo_3',       9, 'num',   'contador'],
            ['qtd_tipo_4',       9, 'num',   'contador'],
            ['qtd_tipo_5',       9, 'num',   'contador'],
        ],
    ];
}

function aej_largura(int $tipo, ?array $spec = null): int {
    $spec = $spec ?? aej_spec();
    if (!isset($spec[$tipo])) throw new InvalidArgumentException("Tipo AEJ desconhecido: {$tipo}");
    $n = 0;
    foreach ($spec[$tipo] as [$nome, $len, $k, $s]) $n += $len;
    return $n;
}

/** Monta uma linha do AEJ. Falha alto, pelas mesmas razões do AFD. */
function aej_line(int $tipo, array $valores, ?array $spec = null): string {
    $spec = $spec ?? aej_spec();
    if (!isset($spec[$tipo])) throw new InvalidArgumentException("Tipo AEJ desconhecido: {$tipo}");
    $out = '';
    foreach ($spec[$tipo] as [$nome, $len, $kind, $src]) {
        if (!array_key_exists($nome, $valores)) {
            throw new RuntimeException("AEJ tipo {$tipo}: campo '{$nome}' nao informado.");
        }
        $out .= $kind === 'num' ? afd_pad_num($valores[$nome], $len) : afd_pad_alpha($valores[$nome], $len);
    }
    $esperado = aej_largura($tipo, $spec);
    if (strlen($out) !== $esperado) {
        throw new RuntimeException("AEJ tipo {$tipo}: linha com " . strlen($out) . " bytes, esperados {$esperado}.");
    }
    return $out;
}

/**
 * JORNADA CONTRATUAL LÍQUIDA de um dia, em minutos.
 *
 * Deliberadamente NÃO usa calculate_expected_minutes(): aquela função devolve a
 * janela cheia (modelo "cheio vs cheio" interno). Aqui desconta-se o intervalo
 * contratual, porque é isso que o AEJ chama de jornada.
 *
 * @return array{minutos:int,entrada:?string,saida:?string,intervalo:int}
 */
function aej_jornada_contratual(PDO $pdo, int $teacherId, string $date): array {
    $vazio = ['minutos' => 0, 'entrada' => null, 'saida' => null, 'intervalo' => 0];

    // Escola do colaborador — o calendário (feriado, recesso, sábado letivo) é
    // por unidade, e as funções do helpers esperam o school_id, não o teacher.
    $schoolId = null;
    try {
        $stS = $pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id = ? LIMIT 1");
        $stS->execute([$teacherId]);
        $v = $stS->fetchColumn();
        if ($v !== false && $v !== null) $schoolId = (int)$v;
    } catch (Throwable $e) { /* sem vinculo: usa calendario global */ }

    // Dia não útil (feriado, recesso) não tem jornada contratual.
    if (function_exists('calendar_day_is_off') && calendar_day_is_off($pdo, $date, $schoolId)) {
        return $vazio;
    }

    // Respeita sábado letivo (reflects_weekday), como o cálculo interno faz.
    $weekday = function_exists('get_effective_weekday')
        ? (int)get_effective_weekday($pdo, $date, $schoolId)
        : (int)date('w', strtotime($date));

    try {
        $st = $pdo->prepare("SELECT start_time, end_time, end_next_day, break_minutes
                               FROM collaborator_time_schedules
                              WHERE teacher_id = ? AND weekday = ? LIMIT 1");
        $st->execute([$teacherId, $weekday]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $vazio;
    }

    if ($r && $r['start_time'] && $r['end_time']) {
        $ini = strtotime($date . ' ' . $r['start_time']);
        $fim = strtotime($date . ' ' . $r['end_time']);
        if (!empty($r['end_next_day']) || $fim <= $ini) $fim += 86400;
        $bruto     = (int)floor(($fim - $ini) / 60);
        $intervalo = max(0, (int)($r['break_minutes'] ?? 0));
        return [
            'minutos'   => max(0, $bruto - $intervalo), // <- o desconto que o AEJ exige
            'entrada'   => substr((string)$r['start_time'], 0, 5),
            'saida'     => substr((string)$r['end_time'], 0, 5),
            'intervalo' => $intervalo,
        ];
    }

    // Modo 'hours': total diário já é líquido por definição.
    try {
        $st = $pdo->prepare("SELECT total_minutes, break_minutes FROM collaborator_hours_schedules
                              WHERE teacher_id = ? AND weekday = ? LIMIT 1");
        $st->execute([$teacherId, $weekday]);
        if ($h = $st->fetch(PDO::FETCH_ASSOC)) {
            return ['minutos' => max(0, (int)$h['total_minutes']), 'entrada' => null,
                    'saida' => null, 'intervalo' => (int)($h['break_minutes'] ?? 0)];
        }
    } catch (Throwable $e) { /* modo nao aplicavel */ }

    return $vazio;
}

/**
 * Pré-voo do AEJ.
 *
 * Além dos bloqueios de cadastro, mede a magnitude do déficit apurado. Essa
 * medição existe porque o AEJ é o primeiro documento que EXPÕE a diferença
 * entre previsto e realizado — diferença que a política interna do banco de
 * horas optou por não registrar como saldo negativo. Descobrir isso ao entregar
 * o arquivo à fiscalização seria tarde demais.
 */
/**
 * Os códigos de ocorrência em `leave_types.aej_code` já foram conferidos contra
 * a tabela do Anexo oficial?
 *
 * Flag separada de `AEJ_SPEC_VERIFICADA` de propósito: aquela trata das
 * POSIÇÕES dos campos, esta do CONTEÚDO de um deles. Homologar o leiaute e
 * esquecer os códigos é um erro plausível — e um código de ocorrência errado
 * classifica a ausência do trabalhador como outra coisa.
 */
function aej_codigos_conferidos(): bool {
    return get_setting('aej_codes_conferidos', '0') === '1';
}

function aej_preflight(PDO $pdo, string $de, string $ate): array {
    $bloqueios = [];
    $avisos    = [];

    $e = $pdo->query("SELECT * FROM employer_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$e) {
        $bloqueios[] = 'employer_config esta vazia.';
    } else {
        $tipoId = (int)($e['employer_type'] ?? 1);
        $doc = $tipoId === 2 ? ($e['cpf'] ?? '') : ($e['cnpj'] ?? '');
        if (preg_replace('/\D/', '', (string)$doc) === '') {
            $bloqueios[] = ($tipoId === 2 ? 'CPF' : 'CNPJ') . ' do empregador nao preenchido.';
        }
    }

    // PIS ausente NÃO bloqueia: todo registro do AEJ carrega PIS *e* CPF, então
    // o trabalhador continua identificado. O campo PIS sai zerado.
    //
    // Esta é uma decisão do empregador (SEMED, 2026-08-07: os PIS não existem e
    // não serão obtidos), não uma conclusão técnica. Se a homologação do leiaute
    // determinar que o PIS é de preenchimento obrigatório, o arquivo será
    // rejeitado e a decisão terá de ser revista — por isso o aviso é explícito
    // sobre o número de registros afetados.
    $semPis = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE active = 1 AND (pis IS NULL OR pis = '')")->fetchColumn();
    if ($semPis > 0) {
        $avisos[] = "{$semPis} colaborador(es) ativo(s) sem PIS — sairao com o campo PIS zerado, "
                  . 'identificados apenas pelo CPF. Confirmar na homologacao do leiaute se o PIS '
                  . 'e de preenchimento obrigatorio.';
    }

    $semCodigo = (int)$pdo->query("SELECT COUNT(*) FROM leave_types WHERE active = 1 AND (aej_code IS NULL OR aej_code = '')")->fetchColumn();
    if ($semCodigo > 0) {
        $avisos[] = "{$semCodigo} tipo(s) de afastamento sem codigo AEJ (leave_types.aej_code) — "
                  . 'as ocorrencias saem sem classificacao.';
    } elseif (!aej_codigos_conferidos()) {
        // Preencher os códigos fez o aviso de "sem codigo" sumir. Sem esta
        // substituição, o perigo ficaria INVISÍVEL: o arquivo passaria a
        // declarar ocorrências com códigos que ninguém conferiu contra a
        // tabela oficial — pior que declarar nenhuma.
        $avisos[] = 'Os codigos AEJ dos afastamentos foram preenchidos provisoriamente e NAO '
                  . 'foram conferidos contra a tabela de ocorrencias do Anexo oficial. '
                  . 'Confira em Admin > Tipos de Afastamento e marque como conferido.';
    }

    // Período que passa do último dia com movimento: o AEJ declararia jornada
    // NAO CUMPRIDA em dias que apenas não têm dado. Descoberto em 2026-08-07 ao
    // investigar 1.133 dias sem marcação em junho — 1.004 deles (89%) eram só o
    // fim dos dados. Sem este aviso, o arquivo afirma falta em massa e ninguém
    // percebe até a fiscalização perguntar.
    $ultimoMovimento = aej_ultimo_dia_com_movimento($pdo);
    if ($ultimoMovimento !== null && $ate > $ultimoMovimento) {
        $diasDepois = (int)((strtotime(min($ate, date('Y-m-d'))) - strtotime($ultimoMovimento)) / 86400);
        if ($diasDepois > 0) {
            $avisos[] = "O periodo vai ate {$ate}, mas o ultimo dia com movimento no sistema foi "
                      . "{$ultimoMovimento} ({$diasDepois} dia(s) depois). Os dias posteriores NAO tem "
                      . 'marcacao alguma e o AEJ os declara como jornada nao cumprida. Confirme se '
                      . 'houve recesso, parada do sistema ou fim da base antes de emitir.';
        }
    }

    if (!AEJ_SPEC_VERIFICADA) {
        $avisos[] = 'O leiaute do AEJ NAO foi conferido contra o Anexo oficial nem validado.';
    }

    return ['ok' => empty($bloqueios), 'bloqueios' => $bloqueios, 'avisos' => $avisos];
}

/**
 * Último dia em que o sistema teve movimento REAL — não o último registro
 * solto, mas o último dia em que uma fração significativa dos colaboradores
 * ativos bateu ponto.
 *
 * Serve para distinguir "ninguém trabalhou" de "não há dado". Um ou dois
 * registros perdidos depois de uma parada (teste, marcação retroativa) fariam
 * `MAX(date)` mentir sobre até quando o sistema estava em uso — daí o corte por
 * fração, e não por existência.
 *
 * @param float $fracao Parcela mínima dos ativos que precisa ter marcado.
 */
function aej_ultimo_dia_com_movimento(PDO $pdo, float $fracao = 0.2): ?string {
    $ativos = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE active = 1")->fetchColumn();
    if ($ativos === 0) return null;
    $minimo = max(1, (int)floor($ativos * $fracao));
    $st = $pdo->prepare("SELECT date FROM attendance
                          WHERE removed_at IS NULL AND superseded_by_id IS NULL
                          GROUP BY date
                         HAVING COUNT(DISTINCT teacher_id) >= ?
                          ORDER BY date DESC LIMIT 1");
    $st->execute([$minimo]);
    $d = $st->fetchColumn();
    return $d === false ? null : (string)$d;
}

/**
 * Mede previsto x realizado no período, para o operador saber o que o arquivo
 * vai declarar ANTES de gerá-lo.
 *
 * @return array{dias:int,previsto:int,realizado:int,dias_deficit:int,dias_sem_marcacao:int,colaboradores:int}
 */
function aej_resumo_apuracao(PDO $pdo, string $de, string $ate): array {
    $ts = $pdo->prepare("SELECT id, created_at FROM teachers WHERE active = 1");
    $ts->execute();
    $colabs = $ts->fetchAll(PDO::FETCH_ASSOC);

    $r = ['dias' => 0, 'previsto' => 0, 'realizado' => 0, 'dias_deficit' => 0,
          'dias_sem_marcacao' => 0, 'colaboradores' => count($colabs),
          'dias_fora_contagem' => 0, 'dias_apos_fim_dos_dados' => 0,
          'ultimo_dia_com_movimento' => aej_ultimo_dia_com_movimento($pdo)];

    $stMarcas = $pdo->prepare("SELECT COUNT(*) FROM attendance
                                WHERE teacher_id = ? AND date = ?
                                  AND removed_at IS NULL AND superseded_by_id IS NULL");
    $hoje = date('Y-m-d');
    $fim  = $r['ultimo_dia_com_movimento'];

    foreach ($colabs as $c) {
        $tid = (int)$c['id'];
        // Janela de contagem: combina a data global (app_settings) com a data de
        // cadastro do colaborador. Sem isso o AEJ declararia falta em todo dia
        // anterior à implantação do sistema — este período tem 1.104 dias assim,
        // e o arquivo afirmaria mais de 7.000 horas de ausência inexistente.
        $inicio = function_exists('counting_start_for')
            ? counting_start_for($c['created_at'] ?? null)
            : '0000-01-01';

        for ($d = new DateTime($de); $d <= new DateTime($ate); $d->modify('+1 day')) {
            $ds = $d->format('Y-m-d');
            if ($ds < $inicio || $ds > $hoje) { $r['dias_fora_contagem']++; continue; }

            $prev = aej_jornada_contratual($pdo, $tid, $ds)['minutos'];
            if ($prev <= 0) continue;
            $real = calculate_effective_worked_minutes($pdo, $tid, $ds);
            $r['dias']++;
            $r['previsto']  += $prev;
            $r['realizado'] += $real;
            if ($real < $prev) $r['dias_deficit']++;
            $stMarcas->execute([$tid, $ds]);
            if ((int)$stMarcas->fetchColumn() === 0) {
                $r['dias_sem_marcacao']++;
                // Dia sem marcação DEPOIS do último dia em que o sistema teve
                // movimento não é ausência: é ausência de dado. Separar os dois
                // é o que impede o AEJ de declarar falta em massa.
                if ($fim !== null && $ds > $fim) $r['dias_apos_fim_dos_dados']++;
            }
        }
    }
    return $r;
}

/**
 * Gera o AEJ linha a linha.
 *
 * @return Generator<string> getReturn() devolve os contadores por tipo.
 */
function aej_generate(PDO $pdo, string $de, string $ate, array $opts = []): Generator {
    $spec = aej_spec((string)($opts['versao'] ?? '001'));
    $e = $pdo->query("SELECT * FROM employer_config LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $tipoId = (int)($e['employer_type'] ?? 1);
    $ident  = $tipoId === 2 ? ($e['cpf'] ?? '') : ($e['cnpj'] ?? '');
    $agora  = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

    $nsr = 0;
    $prox = function () use (&$nsr): int { return ++$nsr; };

    yield aej_line(1, [
        'nsr' => $prox(), 'tipo_registro' => 1,
        'tipo_ident_empr' => $tipoId, 'ident_empregador' => $ident,
        'cei_caepf_cno' => $e['cei_caepf_cno'] ?? '',
        'razao_social' => $e['company_name'] ?? '',
        'data_inicial' => date('dmY', strtotime($de)),
        'data_final' => date('dmY', strtotime($ate)),
        'data_geracao' => $agora->format('dmY'),
        'hora_geracao' => $agora->format('His'),
        'versao_leiaute' => $spec['versao'],
    ], $spec);

    $cont = [2 => 0, 3 => 0, 4 => 0, 5 => 0];

    $sts = $pdo->prepare("SELECT id, name, cpf, pis, created_at FROM teachers WHERE active = 1 ORDER BY id");
    $sts->execute();
    $colaboradores = $sts->fetchAll(PDO::FETCH_ASSOC);
    $hoje = date('Y-m-d');

    $stLeaves = $pdo->prepare("
        SELECT l.start_date, l.end_date, lt.name AS tipo, lt.aej_code
          FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
         WHERE l.teacher_id = ? AND l.approved = 1
           AND l.end_date >= ? AND l.start_date <= ?
         ORDER BY l.start_date");
    $stBank = $pdo->prepare("
        SELECT date, minutes, source FROM hour_bank_entries
         WHERE teacher_id = ? AND date BETWEEN ? AND ? ORDER BY date, id");

    foreach ($colaboradores as $c) {
        $tid = (int)$c['id'];

        // Janela de contagem do colaborador (global + data de cadastro). Fora
        // dela o sistema não tinha como registrar nada, e declarar jornada não
        // cumprida seria afirmar ausência que nunca existiu.
        $inicioContagem = function_exists('counting_start_for')
            ? counting_start_for($c['created_at'] ?? null)
            : '0000-01-01';

        // ---- Tipo 2: jornada contratual por dia da semana -------------------
        // Um registro por weekday com jornada definida, tomando a vigência a
        // partir do início do período exportado.
        for ($w = 0; $w <= 6; $w++) {
            $ref = new DateTime($de);
            // Avança até o primeiro dia do período que caia neste weekday.
            while ((int)$ref->format('w') !== $w) {
                $ref->modify('+1 day');
                if ($ref->format('Y-m-d') > $ate) break 1;
            }
            if ($ref->format('Y-m-d') > $ate) continue;

            $j = aej_jornada_contratual($pdo, $tid, $ref->format('Y-m-d'));
            if ($j['minutos'] <= 0) continue;

            yield aej_line(2, [
                'nsr' => $prox(), 'tipo_registro' => 2,
                'pis' => $c['pis'], 'cpf' => $c['cpf'], 'nome' => $c['name'],
                'data_inicio' => date('dmY', strtotime($de)),
                'dia_semana' => $w + 1, // 1 = domingo
                'hora_entrada' => str_replace(':', '', (string)($j['entrada'] ?? '0000')),
                'hora_saida' => str_replace(':', '', (string)($j['saida'] ?? '0000')),
                'minutos_intervalo' => $j['intervalo'],
                'jornada_liquida' => $j['minutos'],
            ], $spec);
            $cont[2]++;
        }

        // ---- Tipo 4: ocorrências / afastamentos ------------------------------
        $stLeaves->execute([$tid, $de, $ate]);
        $ocorrenciasPorDia = [];
        foreach ($stLeaves->fetchAll(PDO::FETCH_ASSOC) as $lv) {
            yield aej_line(4, [
                'nsr' => $prox(), 'tipo_registro' => 4,
                'pis' => $c['pis'], 'cpf' => $c['cpf'],
                'data_inicio' => date('dmY', strtotime((string)$lv['start_date'])),
                'data_fim' => date('dmY', strtotime((string)$lv['end_date'])),
                'cod_ocorrencia' => $lv['aej_code'] ?? '',
                'descricao' => $lv['tipo'] ?? '',
            ], $spec);
            $cont[4]++;
            for ($d = new DateTime($lv['start_date']); $d <= new DateTime($lv['end_date']); $d->modify('+1 day')) {
                $ocorrenciasPorDia[$d->format('Y-m-d')] = $lv['aej_code'] ?? '';
            }
        }

        // ---- Tipo 3: apuração diária ------------------------------------------
        for ($d = new DateTime($de); $d <= new DateTime($ate); $d->modify('+1 day')) {
            $ds   = $d->format('Y-m-d');
            if ($ds < $inicioContagem || $ds > $hoje) continue; // fora da janela de contagem
            $prev = aej_jornada_contratual($pdo, $tid, $ds)['minutos'];
            $real = calculate_effective_worked_minutes($pdo, $tid, $ds);
            if ($prev <= 0 && $real <= 0) continue; // dia sem jornada e sem marcação

            $dif   = $real - $prev;
            $sinal = $dif > 0 ? '+' : ($dif < 0 ? '-' : '0');

            yield aej_line(3, [
                'nsr' => $prox(), 'tipo_registro' => 3,
                'pis' => $c['pis'], 'cpf' => $c['cpf'],
                'data' => date('dmY', strtotime($ds)),
                'jornada_prevista' => $prev,
                'tempo_trabalhado' => $real,
                'diferenca_sinal' => $sinal,
                'diferenca' => abs($dif),
                'cod_ocorrencia' => $ocorrenciasPorDia[$ds] ?? '',
            ], $spec);
            $cont[3]++;
        }

        // ---- Tipo 5: compensações (banco de horas) ----------------------------
        $stBank->execute([$tid, $de, $ate]);
        foreach ($stBank->fetchAll(PDO::FETCH_ASSOC) as $hb) {
            $m = (int)$hb['minutes'];
            if ($m === 0) continue;
            yield aej_line(5, [
                'nsr' => $prox(), 'tipo_registro' => 5,
                'pis' => $c['pis'], 'cpf' => $c['cpf'],
                'data' => date('dmY', strtotime((string)$hb['date'])),
                'sinal' => $m > 0 ? '+' : '-',
                'minutos' => abs($m),
                'origem' => $hb['source'] ?? '',
            ], $spec);
            $cont[5]++;
        }
    }

    yield aej_line(9, [
        'nsr' => 999999999, 'tipo_registro' => 9,
        'qtd_tipo_2' => $cont[2], 'qtd_tipo_3' => $cont[3],
        'qtd_tipo_4' => $cont[4], 'qtd_tipo_5' => $cont[5],
    ], $spec);

    return $cont;
}

/** Escreve o AEJ num stream. Mesma disciplina de recusa do AFD. */
function aej_generate_to_stream(PDO $pdo, string $de, string $ate, $handle, array $opts = []): array {
    if (!AEJ_SPEC_VERIFICADA && empty($opts['aceitar_spec_nao_verificada'])) {
        throw new RuntimeException(
            'O leiaute do AEJ ainda nao foi conferido contra o Anexo oficial '
            . '(AEJ_SPEC_VERIFICADA = false em lib/aej.php). Para gerar mesmo assim, '
            . 'passe --spec-nao-verificada e trate o arquivo como RASCUNHO.'
        );
    }
    $eol = (string)($opts['eol'] ?? "\r\n");
    $linhas = 0; $bytes = 0;
    $gen = aej_generate($pdo, $de, $ate, $opts);
    foreach ($gen as $l) {
        $n = fwrite($handle, $l . $eol);
        if ($n === false) throw new RuntimeException('Falha ao escrever o AEJ.');
        $linhas++; $bytes += $n;
        if (!empty($opts['flush'])) { @ob_flush(); @flush(); }
    }
    return ['linhas' => $linhas, 'bytes' => $bytes, 'contadores' => $gen->getReturn() ?: []];
}

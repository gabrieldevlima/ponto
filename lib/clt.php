<?php
declare(strict_types=1);
/**
 * Regras de jornada da CLT.
 * =========================
 * Fase 8 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md.
 *
 * Resolve NC-20 (adicional noturno inexistente), NC-21 (intervalo intrajornada
 * sem regra legal), NC-22 (interjornada de 11h não validada) e NC-23
 * (tolerância aplicada ao agregado, não por marcação).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ESTAS FUNÇÕES APURAM, NÃO BLOQUEIAM.
 *
 * Uma jornada que viola o art. 66 ou o art. 71 já aconteceu — o trabalhador
 * esteve lá. Recusar a marcação apagaria a prova justamente do fato que gera o
 * direito. O que o sistema faz é REGISTRAR a irregularidade de forma visível,
 * para que a gestão a corrija e o passivo seja conhecido antes de virar
 * reclamação trabalhista.
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}

// ===========================================================================
// Adicional noturno — art. 73 (NC-20)
// ===========================================================================

/** Janela do trabalho noturno urbano: 22h de um dia às 5h do seguinte. */
const CLT_NOTURNO_INICIO_H = 22;
const CLT_NOTURNO_FIM_H    = 5;

/**
 * Hora noturna REDUZIDA: 52 min 30 s (art. 73 §1º).
 * Cada 52,5 minutos efetivamente trabalhados no período noturno equivalem a
 * uma hora de jornada. É por isso que não basta contar minutos.
 */
const CLT_HORA_NOTURNA_MIN = 52.5;

/** Adicional mínimo legal de 20% (art. 73 caput). Pode ser maior por acordo. */
const CLT_ADICIONAL_NOTURNO = 0.20;

/**
 * Minutos de relógio dentro da janela noturna, para um intervalo qualquer.
 *
 * Trata corretamente jornadas que cruzam a meia-noite e turnos que abrangem
 * mais de uma janela noturna (plantões longos).
 */
function clt_minutos_noturnos(string $inicio, string $fim): int {
    $ini = strtotime($inicio);
    $end = strtotime($fim);
    if (!$ini || !$end || $end <= $ini) return 0;

    $total = 0;
    // Varre cada dia tocado pelo intervalo e intersecta com [22h, 5h+1d).
    //
    // Começa UM DIA ANTES de propósito: a janela noturna que cobre a madrugada
    // (00h–05h) abre às 22h do dia ANTERIOR. Sem esse recuo, todo trabalho de
    // madrugada ficava fora da conta — exatamente o caso mais comum de jornada
    // noturna, e o mais custoso de errar.
    $dia = strtotime(date('Y-m-d', $ini)) - 86400;
    $ultimo = strtotime(date('Y-m-d', $end));
    while ($dia <= $ultimo) {
        $janIni = $dia + CLT_NOTURNO_INICIO_H * 3600;              // 22:00 do dia
        $janFim = $dia + 86400 + CLT_NOTURNO_FIM_H * 3600;         // 05:00 do dia seguinte
        $a = max($ini, $janIni);
        $b = min($end, $janFim);
        if ($b > $a) $total += (int)floor(($b - $a) / 60);
        $dia += 86400;
    }
    return $total;
}

/**
 * Apuração do adicional noturno de um dia.
 *
 * @return array{minutos_relogio:int,horas_reduzidas:float,minutos_equivalentes:int,adicional_percentual:float}
 */
function clt_adicional_noturno(PDO $pdo, int $teacherId, string $date): array {
    $vazio = ['minutos_relogio' => 0, 'horas_reduzidas' => 0.0,
              'minutos_equivalentes' => 0, 'adicional_percentual' => CLT_ADICIONAL_NOTURNO];

    // Considera os pares vigentes do dia — inclusive os que cruzam a meia-noite,
    // ancorados no dia da entrada, que é como o sistema já os trata.
    $st = $pdo->prepare("SELECT check_in, check_out FROM attendance
                          WHERE teacher_id = ? AND date = ?
                            AND check_in IS NOT NULL AND check_out IS NOT NULL
                            AND " . attendance_vigente_sql() . "
                            AND (record_type = 'work' OR record_type IS NULL)");
    $st->execute([$teacherId, $date]);

    $min = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $min += clt_minutos_noturnos((string)$r['check_in'], (string)$r['check_out']);
    }
    if ($min === 0) return $vazio;

    // Hora reduzida: 52,5 min de relógio = 1 hora de jornada noturna.
    $horas = $min / CLT_HORA_NOTURNA_MIN;

    return [
        'minutos_relogio'      => $min,
        'horas_reduzidas'      => round($horas, 4),
        // Equivalente em minutos "normais", para somar à jornada.
        'minutos_equivalentes' => (int)round($horas * 60),
        'adicional_percentual' => CLT_ADICIONAL_NOTURNO,
    ];
}

/** Valor do adicional noturno em reais, dado o valor da hora. */
function clt_valor_adicional_noturno(float $valorHora, array $apuracao): float {
    return round($valorHora * (float)$apuracao['horas_reduzidas'] * CLT_ADICIONAL_NOTURNO, 2);
}

// ===========================================================================
// Intervalo intrajornada — art. 71 (NC-21)
// ===========================================================================

/**
 * Intervalo MÍNIMO devido, em minutos, para uma jornada de N minutos.
 *   > 6h            → 60 min (mínimo; máximo 2h)
 *   > 4h e até 6h   → 15 min
 *   até 4h          → nenhum
 */
function clt_intervalo_minimo(int $minutosJornada): int {
    if ($minutosJornada > 360) return 60;
    if ($minutosJornada > 240) return 15;
    return 0;
}

/** Intervalo MÁXIMO da jornada acima de 6h, salvo acordo escrito (art. 71). */
const CLT_INTERVALO_MAXIMO = 120;

/**
 * Apura o intervalo intrajornada de um dia.
 *
 * O §4º do art. 71 é o que dá consequência: suprimir o intervalo, total ou
 * parcialmente, obriga o empregador a pagar o PERÍODO SUPRIMIDO com adicional
 * de no mínimo 50%. Não é multa fixa nem hora cheia — é o tempo que faltou.
 *
 * @return array{jornada_min:int,intervalo_gozado:int,intervalo_devido:int,suprimido:int,irregular:bool,motivo:?string}
 */
function clt_intervalo_intrajornada(PDO $pdo, int $teacherId, string $date): array {
    $jornada  = calculate_worked_minutes($pdo, $teacherId, $date);      // só pares 'work'
    $gozado   = calculate_break_minutes($pdo, $teacherId, $date);       // pares 'break' aprovados
    $devido   = clt_intervalo_minimo($jornada);
    $suprimido = max(0, $devido - $gozado);

    $motivo = null;
    if ($jornada === 0) {
        // Sem jornada não há intervalo devido.
        return ['jornada_min' => 0, 'intervalo_gozado' => $gozado, 'intervalo_devido' => 0,
                'suprimido' => 0, 'irregular' => false, 'motivo' => null];
    }
    if ($suprimido > 0) {
        $motivo = $gozado === 0
            ? "Intervalo intrajornada não registrado. Jornada de "
              . clt_fmt($jornada) . " exige no mínimo " . clt_fmt($devido) . " (art. 71 da CLT)."
            : "Intervalo intrajornada inferior ao mínimo legal: gozado "
              . clt_fmt($gozado) . ", devido " . clt_fmt($devido) . " (art. 71 da CLT).";
    } elseif ($devido === 60 && $gozado > CLT_INTERVALO_MAXIMO) {
        $motivo = "Intervalo de " . clt_fmt($gozado) . " excede o máximo de 2h sem acordo escrito (art. 71).";
    }

    return [
        'jornada_min'      => $jornada,
        'intervalo_gozado' => $gozado,
        'intervalo_devido' => $devido,
        'suprimido'        => $suprimido,
        'irregular'        => $motivo !== null,
        'motivo'           => $motivo,
    ];
}

/**
 * Indenização do art. 71 §4º: período suprimido com adicional de 50%.
 * Devolve o valor em reais, dado o valor da hora normal.
 */
function clt_indenizacao_intervalo(float $valorHora, int $minutosSuprimidos): float {
    if ($minutosSuprimidos <= 0) return 0.0;
    return round(($valorHora / 60) * $minutosSuprimidos * 1.5, 2);
}

// ===========================================================================
// Intervalo interjornada — art. 66 (NC-22)
// ===========================================================================

/** Descanso mínimo entre duas jornadas: 11 horas consecutivas. */
const CLT_INTERJORNADA_MIN = 660; // minutos

/**
 * Verifica o descanso entre a última saída registrada e a entrada informada.
 *
 * @return array{ok:bool,intervalo_min:?int,faltante:int,motivo:?string,ultima_saida:?string}
 */
function clt_interjornada(PDO $pdo, int $teacherId, string $novaEntrada): array {
    $st = $pdo->prepare("SELECT MAX(check_out) FROM attendance
                          WHERE teacher_id = ? AND check_out IS NOT NULL
                            AND check_out < ?
                            AND " . attendance_vigente_sql() . "
                            AND (record_type = 'work' OR record_type IS NULL)");
    $st->execute([$teacherId, $novaEntrada]);
    $ultima = $st->fetchColumn();

    if (!$ultima) {
        return ['ok' => true, 'intervalo_min' => null, 'faltante' => 0,
                'motivo' => null, 'ultima_saida' => null];
    }

    $delta = (int)floor((strtotime($novaEntrada) - strtotime((string)$ultima)) / 60);
    if ($delta >= CLT_INTERJORNADA_MIN) {
        return ['ok' => true, 'intervalo_min' => $delta, 'faltante' => 0,
                'motivo' => null, 'ultima_saida' => (string)$ultima];
    }

    $faltante = CLT_INTERJORNADA_MIN - $delta;
    return [
        'ok'            => false,
        'intervalo_min' => $delta,
        'faltante'      => $faltante,
        'ultima_saida'  => (string)$ultima,
        'motivo'        => "Descanso entre jornadas de " . clt_fmt($delta)
                         . ", inferior às 11h exigidas pelo art. 66 da CLT (faltaram "
                         . clt_fmt($faltante) . ").",
    ];
}

// ===========================================================================
// Tolerância de marcação — art. 58 §1º (NC-23)
// ===========================================================================

/** Tolerância por MARCAÇÃO: até 5 minutos. */
const CLT_TOLERANCIA_POR_MARCACAO = 5;
/** Teto DIÁRIO da tolerância: 10 minutos. */
const CLT_TOLERANCIA_DIARIA = 10;

/**
 * Aplica a tolerância do art. 58 §1º sobre as variações de um dia.
 *
 * A regra é frequentemente mal implementada — inclusive era aqui. O sistema
 * aplicava 5 minutos ao AGREGADO do mês. A lei diz outra coisa:
 *
 *   - a tolerância é POR MARCAÇÃO (até 5 min cada);
 *   - com TETO de 10 minutos no dia;
 *   - e, uma vez excedido o limite, o tempo é computado INTEGRALMENTE — não
 *     apenas o que passou de 5 minutos. É a diferença entre desprezar 4 min e
 *     pagar 7 min cheios quando a variação foi 7.
 *
 * @param int[] $variacoes variações em minutos por marcação (+ antecipação / − atraso)
 * @return array{computado:int,desprezado:int,por_marcacao:array,teto_excedido:bool}
 */
function clt_tolerancia_dia(array $variacoes): array {
    $acumulado  = 0;   // soma dos módulos já desprezados no dia
    $computado  = 0;
    $desprezado = 0;
    $detalhe    = [];

    foreach ($variacoes as $v) {
        $v = (int)$v;
        $mod = abs($v);

        if ($mod === 0) { $detalhe[] = ['variacao' => 0, 'computado' => 0, 'desprezado' => 0]; continue; }

        // Excedeu a tolerância por marcação: computa INTEGRALMENTE.
        if ($mod > CLT_TOLERANCIA_POR_MARCACAO) {
            $computado += $v;
            $detalhe[] = ['variacao' => $v, 'computado' => $v, 'desprezado' => 0,
                          'razao' => 'acima de ' . CLT_TOLERANCIA_POR_MARCACAO . ' min na marcacao'];
            continue;
        }

        // Dentro dos 5 min, mas o teto diário de 10 já foi atingido: computa.
        if ($acumulado + $mod > CLT_TOLERANCIA_DIARIA) {
            $computado += $v;
            $detalhe[] = ['variacao' => $v, 'computado' => $v, 'desprezado' => 0,
                          'razao' => 'teto diario de ' . CLT_TOLERANCIA_DIARIA . ' min excedido'];
            continue;
        }

        $acumulado  += $mod;
        $desprezado += $mod;
        $detalhe[]   = ['variacao' => $v, 'computado' => 0, 'desprezado' => $v];
    }

    return [
        'computado'     => $computado,
        'desprezado'    => $desprezado,
        'por_marcacao'  => $detalhe,
        'teto_excedido' => $acumulado >= CLT_TOLERANCIA_DIARIA,
    ];
}

// ===========================================================================

/** Formata minutos como "1h30" / "45min", para as mensagens. */
function clt_fmt(int $min): string {
    $min = abs($min);
    if ($min < 60) return $min . 'min';
    $h = intdiv($min, 60); $m = $min % 60;
    return $m === 0 ? $h . 'h' : $h . 'h' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

/**
 * Avisos de conformidade sobre um dia MONTADO, antes de gravar.
 *
 * Função PURA — opera sobre os segmentos que o admin acabou de compor, sem
 * tocar o banco. Devolve AVISOS, não erros: `validate_attendance_day()` bloqueia
 * o salvamento, e bloquear aqui impediria o admin de registrar uma jornada que
 * de fato ocorreu com o intervalo suprimido. O papel do sistema é deixar a
 * irregularidade visível, não negar que ela aconteceu.
 *
 * @param array $works  [['start'=>'Y-m-d H:i:s','end'=>...], ...]
 * @param array $breaks idem
 * @return string[]
 */
function clt_avisos_jornada(array $works, array $breaks): array {
    $somar = function (array $itens): int {
        $t = 0;
        foreach ($itens as $i) {
            $s = strtotime((string)($i['start'] ?? ''));
            $e = strtotime((string)($i['end'] ?? ''));
            if ($s && $e && $e > $s) $t += (int)floor(($e - $s) / 60);
        }
        return $t;
    };

    $jornada = $somar($works);
    $gozado  = $somar($breaks);
    if ($jornada === 0) return [];

    $avisos = [];
    $devido = clt_intervalo_minimo($jornada);
    if ($devido > 0 && $gozado < $devido) {
        $falta = $devido - $gozado;
        $avisos[] = 'Art. 71 da CLT: jornada de ' . clt_fmt($jornada) . ' exige intervalo de no mínimo '
                  . clt_fmt($devido) . ($gozado > 0 ? ', mas há apenas ' . clt_fmt($gozado) : ', e nenhum foi registrado')
                  . '. Faltam ' . clt_fmt($falta)
                  . ' — o período suprimido é devido com adicional de 50% (§4º).';
    }
    if ($devido === 60 && $gozado > CLT_INTERVALO_MAXIMO) {
        $avisos[] = 'Art. 71 da CLT: intervalo de ' . clt_fmt($gozado)
                  . ' excede o máximo de 2h admitido sem acordo escrito.';
    }

    // Jornada acima de 10h (8h normais + 2h extras, art. 59) é sinal de alerta.
    if ($jornada > 600) {
        $avisos[] = 'Jornada de ' . clt_fmt($jornada)
                  . ' excede 10h. O art. 59 limita a 2h extras diárias.';
    }

    // Trabalho noturno no dia composto.
    $noturno = 0;
    foreach ($works as $w) {
        $noturno += clt_minutos_noturnos((string)($w['start'] ?? ''), (string)($w['end'] ?? ''));
    }
    if ($noturno > 0) {
        $horas = $noturno / CLT_HORA_NOTURNA_MIN;
        $avisos[] = 'Art. 73 da CLT: ' . clt_fmt($noturno) . ' em horário noturno ('
                  . number_format($horas, 2, ',', '.') . ' horas noturnas reduzidas de 52min30s), '
                  . 'com adicional mínimo de 20%.';
    }

    return $avisos;
}

/**
 * Apuração consolidada das regras de um dia, para relatórios e telas.
 *
 * @return array{noturno:array,intrajornada:array,irregularidades:string[]}
 */
function clt_apurar_dia(PDO $pdo, int $teacherId, string $date): array {
    $noturno = clt_adicional_noturno($pdo, $teacherId, $date);
    $intra   = clt_intervalo_intrajornada($pdo, $teacherId, $date);

    $irregularidades = [];
    if ($intra['irregular'] && $intra['motivo']) $irregularidades[] = $intra['motivo'];

    return ['noturno' => $noturno, 'intrajornada' => $intra, 'irregularidades' => $irregularidades];
}

<?php
/**
 * Reconcilia os lançamentos automáticos de banco de horas (hour_bank_entries com
 * source='auto').
 *
 * NOTA (jornada flexível): a instituição não mantém mais saldo negativo. Este
 * script agora apenas ZERA os lançamentos automáticos antigos — o controle de
 * horas passou a ser mensal (ver get_monthly_hours_summary()). As seções abaixo
 * descrevem o comportamento histórico, mantido por referência.
 *
 * Por que existe: a correção do bug de dupla subtração do intervalo
 * (helpers.php::calculate_effective_worked_minutes) conserta todo cálculo NOVO e
 * todos os relatórios que recalculam ao vivo (ex: public/admin/reports.php). Porém
 * o saldo TOTAL somado em public/my_timesheet.php e public/admin/dashboard.php usa
 * os valores já GRAVADOS em hour_bank_entries (source='auto'), que continuam
 * desatualizados até o dia ser recalculado. Este script regrava esses valores.
 *
 * ATENÇÃO — escopo amplo: como recalcula contra os dados atuais, este script NÃO
 * isola apenas o bug do intervalo. Ele também reflete qualquer mudança ocorrida
 * APÓS o checkout que não recalculou o banco — em especial registros que ficaram
 * pendentes no checkout e foram APROVADOS depois (o saldo daquele dia sobe). Revise
 * o dry-run antes de aplicar.
 *
 * Uso:
 *   php bin/recompute_hour_bank_auto.php                 (dry-run: só mostra o que mudaria)
 *   php bin/recompute_hour_bank_auto.php --apply         (grava as correções)
 *   php bin/recompute_hour_bank_auto.php --only-breaks   (restringe a dias com intervalo registrado)
 *   php bin/recompute_hour_bank_auto.php --only-up       (aplica apenas dias cujo saldo SOBE)
 *
 * --only-up: restringe às correções que aumentam o saldo do dia — a classe dos
 * lançamentos "fossilizados" (ponto pendente no checkout, aprovado depois sem
 * recálculo). Dias cujo saldo desceria (registros editados/removidos após o
 * checkout) são apenas relatados, para revisão manual antes de um apply amplo.
 *
 * --only-breaks: recalcula somente lançamentos de dias que têm registro
 * record_type='break' — o escopo exato afetado pela regra "intervalo conta como
 * tempo trabalhado" (dias sem intervalo têm delta matematicamente idêntico ao
 * modelo antigo). Combine com --apply para gravar.
 *
 * Não toca em source='overtime_approved' nem 'manual'. Espelha a lógica de delta
 * de api/checkin.php (delta>0 → 0, tolerância via setting tolerance_minutes).
 *
 * Execute APENAS via CLI.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via CLI.' . PHP_EOL);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

$apply = in_array('--apply', $argv ?? [], true);
$onlyBreaks = in_array('--only-breaks', $argv ?? [], true);
$onlyUp = in_array('--only-up', $argv ?? [], true);
$pdo = db();

$tolerance = (int)(get_setting('tolerance_minutes', '5') ?? '5');

echo "=== Reconciliação de banco de horas (source='auto') ===" . PHP_EOL;
echo $apply ? "Modo: APLICAR alterações" . PHP_EOL : "Modo: DRY-RUN (use --apply para gravar)" . PHP_EOL;
if ($onlyBreaks) echo "Escopo: apenas dias com intervalo registrado (--only-breaks)" . PHP_EOL;
echo "Tolerância: {$tolerance} min" . PHP_EOL . PHP_EOL;

// Todos os lançamentos automáticos (um por (teacher_id, date) por construção do checkin).
$sql = "SELECT hb.id, hb.teacher_id, hb.date, hb.minutes FROM hour_bank_entries hb WHERE hb.source='auto'";
if ($onlyBreaks) {
    $sql .= " AND EXISTS (SELECT 1 FROM attendance a
                          WHERE a.teacher_id = hb.teacher_id AND a.date = hb.date
                            AND a.record_type = 'break')";
}
$sql .= " ORDER BY hb.teacher_id, hb.date";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$up = 0;          // dias cujo saldo sobe (inclui correção do intervalo e aprovações pós-checkout)
$down = 0;        // dias cujo saldo desce (registros editados/removidos após o checkout)
$unchanged = 0;
$totalDeltaMin = 0;

if ($apply) $pdo->beginTransaction();

try {
    $upd = $pdo->prepare("UPDATE hour_bank_entries SET minutes=?, reason=? WHERE id=?");

    foreach ($rows as $r) {
        $teacherId = (int)$r['teacher_id'];
        $date      = (string)$r['date'];
        $old       = (int)$r['minutes'];

        // Jornada flexível: o sistema NÃO mantém mais saldo negativo no banco de
        // horas (espelha recompute_day_hour_bank()). A reconciliação agora apenas
        // ZERA os lançamentos automáticos antigos (source='auto'). O quanto falta
        // para a carga prevista é calculado por mês (get_monthly_hours_summary()).
        $new = 0;

        if ($new === $old) {
            $unchanged++;
            continue;
        }

        $isDown = $new < $old;
        if ($onlyUp && $isDown) {
            // Queda de saldo: fora do escopo do --only-up — apenas relata.
            $down++;
            printf("  [-] teacher=%d %s : %+d -> %+d min (NÃO aplicado; revisão manual)\n",
                $teacherId, $date, $old, $new);
            continue;
        }

        if ($isDown) { $down++; } else { $up++; }
        $totalDeltaMin += ($new - $old);

        printf("  %s teacher=%d %s : %+d -> %+d min%s\n",
            $isDown ? '[-]' : '[+]', $teacherId, $date, $old, $new, $apply ? '' : ' (dry-run)');

        if ($apply) {
            $upd->execute([$new, 'Reconciliação automática do banco de horas', (int)$r['id']]);
        }
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) $pdo->rollBack();
    echo PHP_EOL . "ERRO: " . $e->getMessage() . PHP_EOL;
    exit(2);
}

echo PHP_EOL . "=== Resumo ===" . PHP_EOL;
echo "Lançamentos analisados: " . count($rows) . PHP_EOL;
echo "Saldo subiu: {$up} | Saldo desceu: {$down} | Inalterados: {$unchanged}" . PHP_EOL;
printf("Ajuste líquido total: %+d min (%.1f h)\n", $totalDeltaMin, $totalDeltaMin / 60.0);
if ($down > 0) {
    echo "Aviso: dias com [-] indicam registros alterados/removidos após o checkout." . PHP_EOL;
    echo "       Revise-os antes de aplicar." . PHP_EOL;
}
if (!$apply && ($up + $down) > 0) {
    echo PHP_EOL . "Rode novamente com --apply para gravar." . PHP_EOL;
}
exit(0);

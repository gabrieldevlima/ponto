<?php
/**
 * Diagnóstico: por que lançamentos hour_bank_entries (source='auto') divergem para
 * BAIXO ao recalcular contra o estado atual dos registros.
 *
 * Uso: php bin/diag_hour_bank_down.php [limite_exemplos]
 * Somente leitura. Execute via CLI.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only' . PHP_EOL); }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers.php';

$pdo = db();
$tolerance = (int)(get_setting('tolerance_minutes', '5') ?? '5');
$sampleLimit = isset($argv[1]) ? (int)$argv[1] : 25;

$rows = $pdo->query("SELECT id, teacher_id, date, minutes FROM hour_bank_entries WHERE source='auto' ORDER BY teacher_id, date")
            ->fetchAll(PDO::FETCH_ASSOC);

// Helpers de inspeção do dia
$countRecs = function(int $tid, string $date) use ($pdo): array {
    $st = $pdo->prepare("SELECT record_type, approved, check_in, check_out FROM attendance WHERE teacher_id=? AND date=?");
    $st->execute([$tid, $date]);
    $c = ['work_all'=>0,'work_appr'=>0,'work_open'=>0,'break_all'=>0,'break_appr'=>0,'break_open'=>0];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $rt = ($r['record_type'] ?? 'work') === 'break' ? 'break' : 'work';
        $c[$rt.'_all']++;
        if ((int)$r['approved'] === 1) $c[$rt.'_appr']++;
        if ($r['check_out'] === null) $c[$rt.'_open']++;
    }
    return $c;
};

$cats = [];
$bump = function(string $k) use (&$cats) { $cats[$k] = ($cats[$k] ?? 0) + 1; };

$capDelta = function(int $worked, int $exp) use ($tolerance): int {
    $delta = $worked - $exp; if (abs($delta) <= $tolerance) $delta = 0;
    return $delta > 0 ? 0 : $delta;
};

$downRows = [];
foreach ($rows as $r) {
    $tid = (int)$r['teacher_id']; $date = (string)$r['date']; $old = (int)$r['minutes'];
    $expMin = calculate_expected_minutes($pdo, $tid, $date);
    $eff    = calculate_effective_worked_minutes($pdo, $tid, $date);
    $new    = $capDelta($eff, $expMin);
    if ($new >= $old) continue; // só os que descem
    $gross  = calculate_worked_minutes($pdo, $tid, $date);
    // Qual seria o saldo se NÃO houvesse o auto-desconto do intervalo previsto
    // (i.e., usando worked BRUTO)? Se isso reproduz o valor antigo, a queda é
    // 100% explicada pelo auto-desconto (regra pré-existente, não drift de dados).
    $newGross = $capDelta($gross, $expMin);
    $downRows[] = ['tid'=>$tid,'date'=>$date,'old'=>$old,'new'=>$new,'exp'=>$expMin,'eff'=>$eff,'gross'=>$gross,'newGross'=>$newGross];
}

echo "=== Diagnóstico: dias com queda de saldo ===\n";
echo "Total que desce: " . count($downRows) . "\n\n";

foreach ($downRows as $d) {
    $c = $countRecs($d['tid'], $d['date']);

    // Classificação da causa provável
    if ($c['work_all'] === 0 && $c['break_all'] === 0) {
        $cat = 'A) Sem nenhum registro hoje (entrada removida/realocada)';
    } elseif ($c['work_appr'] === 0 && $c['work_all'] > 0) {
        $cat = 'B) Tem work mas NENHUM aprovado (desaprovado após checkout)';
    } elseif ($c['work_open'] > 0) {
        $cat = 'C) Há work em aberto (check_out NULL — dia inconsistente)';
    } elseif ($d['newGross'] >= $d['old']) {
        // Sem o auto-desconto do intervalo, o saldo bateria com o antigo (ou subiria):
        // a queda vem do auto-desconto do intervalo previsto, regra pré-existente.
        $cat = 'D) Auto-desconto do intervalo previsto (dia sem intervalo registrado)';
    } else {
        $cat = 'E) Drift real: worked atual menor que no checkout (registros alterados)';
    }
    $bump($cat);
}

echo "--- Categorias (todos os " . count($downRows) . " dias) ---\n";
arsort($cats);
foreach ($cats as $k => $n) printf("  %4d  %s\n", $n, $k);

echo "\n--- Amostra detalhada (até {$sampleLimit}) ---\n";
echo "tid    date         old    new   newGross  exp   effWk  grossWk  W(all/appr/open)  B(all/appr/open)\n";
$shown = 0;
foreach ($downRows as $d) {
    if ($shown++ >= $sampleLimit) break;
    $c = $countRecs($d['tid'], $d['date']);
    printf("%-5d  %s  %+5d  %+5d   %+5d   %4d   %4d    %4d     %d/%d/%d            %d/%d/%d\n",
        $d['tid'], $d['date'], $d['old'], $d['new'], $d['newGross'], $d['exp'], $d['eff'], $d['gross'],
        $c['work_all'],$c['work_appr'],$c['work_open'], $c['break_all'],$c['break_appr'],$c['break_open']);
}
exit(0);

<?php
declare(strict_types=1);
/**
 * De onde vêm os dias que o AEJ declara como jornada não cumprida?
 *
 * Nasceu da investigação de 2026-08-07: o AEJ de junho acusou 1.133 dias sem
 * marcação alguma — 56% dos dias previstos. O número sozinho não dizia nada.
 * Classificado, revelou que 89% eram apenas o fim dos dados daquela base, e não
 * falta de ninguém.
 *
 * O AEJ é o primeiro lugar do sistema onde essa diferença aparece: a política do
 * banco de horas não grava saldo negativo, então nenhum relatório a mostrava.
 * Antes de entregar um AEJ à fiscalização, rode isto e saiba o que o arquivo vai
 * afirmar sobre cada pessoa.
 *
 * Uso:
 *   php bin/aej_diagnostico.php --de=2026-06-01 --ate=2026-06-30
 *   php bin/aej_diagnostico.php --de=... --ate=... --por-colaborador
 *   php bin/aej_diagnostico.php --de=... --ate=... --csv=saida.csv
 *
 * Somente leitura: não altera nada.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/aej.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
set_time_limit(0);

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? '1';
}
$de  = $args['de']  ?? '';
$ate = $args['ate'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) || $de > $ate) {
    echo "Uso: php bin/aej_diagnostico.php --de=AAAA-MM-DD --ate=AAAA-MM-DD [--por-colaborador] [--csv=arquivo]\n";
    exit(2);
}

$pdo  = db();
$hoje = date('Y-m-d');
$fim  = aej_ultimo_dia_com_movimento($pdo);

echo "=====================================================================\n";
echo " Diagnostico do AEJ — {$de} a {$ate}\n";
echo "=====================================================================\n\n";
echo "  ultimo dia com movimento no sistema: " . ($fim ?? '(nenhum)') . "\n";
echo "  hoje ..............................: {$hoje}\n\n";

$colabs = $pdo->query("SELECT id, name, created_at FROM teachers WHERE active = 1 ORDER BY name")
              ->fetchAll(PDO::FETCH_ASSOC);
$stMarcas = $pdo->prepare("SELECT COUNT(*) FROM attendance
                            WHERE teacher_id = ? AND date = ?
                              AND removed_at IS NULL AND superseded_by_id IS NULL");
$stLeave = $pdo->prepare("SELECT lt.name FROM leaves l JOIN leave_types lt ON lt.id = l.type_id
                           WHERE l.teacher_id = ? AND l.approved = 1
                             AND l.start_date <= ? AND l.end_date >= ? LIMIT 1");

// As três causas são mutuamente excludentes e cobrem todos os casos.
const CAT_SEM_DADO   = 'apos o fim dos dados';
const CAT_AFASTADO   = 'afastamento ja lancado';
const CAT_AUSENCIA   = 'ausencia sem justificativa lancada';

$cat = [CAT_SEM_DADO => 0, CAT_AFASTADO => 0, CAT_AUSENCIA => 0];
$porColaborador = [];
$porData = [];
$linhasCsv = [];

foreach ($colabs as $c) {
    $tid = (int)$c['id'];
    $inicio = function_exists('counting_start_for')
        ? counting_start_for($c['created_at'] ?? null) : '0000-01-01';

    for ($d = new DateTime($de); $d <= new DateTime($ate); $d->modify('+1 day')) {
        $ds = $d->format('Y-m-d');
        if ($ds < $inicio || $ds > $hoje) continue;
        if (aej_jornada_contratual($pdo, $tid, $ds)['minutos'] <= 0) continue;

        $stMarcas->execute([$tid, $ds]);
        if ((int)$stMarcas->fetchColumn() > 0) continue;

        if ($fim !== null && $ds > $fim) {
            $causa = CAT_SEM_DADO; $detalhe = '';
        } else {
            $stLeave->execute([$tid, $ds, $ds]);
            $tipo = $stLeave->fetchColumn();
            if ($tipo !== false) { $causa = CAT_AFASTADO; $detalhe = (string)$tipo; }
            else                 { $causa = CAT_AUSENCIA; $detalhe = ''; }
        }

        $cat[$causa]++;
        $porColaborador[$tid]['nome'] = $c['name'];
        $porColaborador[$tid][$causa] = ($porColaborador[$tid][$causa] ?? 0) + 1;
        $porData[$ds][$causa] = ($porData[$ds][$causa] ?? 0) + 1;
        $linhasCsv[] = [$ds, $tid, $c['name'], $causa, $detalhe];
    }
}

$total = array_sum($cat);
echo "--- Origem dos {$total} dias que o AEJ declararia como nao cumpridos ---\n";
foreach ($cat as $k => $v) {
    printf("  %-36s %6d  (%5.1f%%)\n", $k, $v, $total ? 100 * $v / $total : 0);
}
echo "\n";

if ($cat[CAT_SEM_DADO] > 0) {
    echo "  ATENCAO: " . $cat[CAT_SEM_DADO] . " desses dias sao posteriores a {$fim},\n";
    echo "  quando o sistema deixou de receber marcacoes. NAO e ausencia dos\n";
    echo "  trabalhadores: e ausencia de dado. Encurtar o periodo para --ate={$fim}\n";
    echo "  os elimina.\n\n";
}
if ($cat[CAT_AUSENCIA] > 0) {
    echo "  " . $cat[CAT_AUSENCIA] . " dia(s) sao ausencia dentro do periodo com dados, sem\n";
    echo "  afastamento lancado. O AEJ os declara como jornada nao cumprida. Se\n";
    echo "  houver justificativa, lance em Afastamentos antes de emitir.\n\n";
}

echo "--- Por data ---\n";
ksort($porData);
$semanas = ['dom','seg','ter','qua','qui','sex','sab'];
foreach ($porData as $ds => $linha) {
    printf("  %s (%s)  sem dado %4d | afastado %3d | ausente %3d\n",
        $ds, $semanas[(int)date('w', strtotime($ds))],
        $linha[CAT_SEM_DADO] ?? 0, $linha[CAT_AFASTADO] ?? 0, $linha[CAT_AUSENCIA] ?? 0);
}
echo "\n";

if (!empty($args['por-colaborador'])) {
    echo "--- Por colaborador (ordenado por ausencia sem justificativa) ---\n";
    uasort($porColaborador, fn($a, $b) => ($b[CAT_AUSENCIA] ?? 0) <=> ($a[CAT_AUSENCIA] ?? 0));
    foreach ($porColaborador as $tid => $x) {
        printf("  #%-4d %-42s sem dado %3d | afastado %2d | ausente %3d\n",
            $tid, mb_substr((string)$x['nome'], 0, 42),
            $x[CAT_SEM_DADO] ?? 0, $x[CAT_AFASTADO] ?? 0, $x[CAT_AUSENCIA] ?? 0);
    }
    echo "\n";
}

if (!empty($args['csv']) && $args['csv'] !== '1') {
    $h = fopen($args['csv'], 'wb');
    fputcsv($h, ['data', 'teacher_id', 'nome', 'causa', 'detalhe'], ';');
    foreach ($linhasCsv as $l) fputcsv($h, $l, ';');
    fclose($h);
    echo "CSV com as " . count($linhasCsv) . " linhas: {$args['csv']}\n\n";
}

echo "Nada foi alterado — este comando so le.\n";
exit(0);

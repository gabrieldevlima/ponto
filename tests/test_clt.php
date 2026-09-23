<?php
declare(strict_types=1);
/**
 * Regras de jornada da CLT — Fase 8 da auditoria.
 *
 * Cobre NC-20 (adicional noturno), NC-21 (intervalo intrajornada), NC-22
 * (interjornada de 11h) e NC-23 (tolerância do art. 58 §1º).
 *
 * A tolerância é o caso mais delicado: a regra é frequentemente implementada
 * errado — inclusive era aqui, aplicando 5 minutos ao agregado do mês. A lei
 * manda tolerar até 5 min POR MARCAÇÃO, com teto de 10 min no dia, e computar
 * INTEGRALMENTE quando o limite é excedido. Ver seção [4].
 *
 * Execução: php tests/test_clt.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/clt.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();
$teacherId = 0;

echo "[1] Minutos em horário noturno (art. 73)\n";
check('jornada diurna não tem noturno',
      clt_minutos_noturnos('2026-05-04 08:00:00', '2026-05-04 17:00:00') === 0);
check('22h às 23h = 60 min noturnos',
      clt_minutos_noturnos('2026-05-04 22:00:00', '2026-05-04 23:00:00') === 60);
check('20h às 23h = só os 60 min após as 22h',
      clt_minutos_noturnos('2026-05-04 20:00:00', '2026-05-04 23:00:00') === 60,
      (string)clt_minutos_noturnos('2026-05-04 20:00:00', '2026-05-04 23:00:00'));
check('cruzando meia-noite: 23h às 01h = 120 min',
      clt_minutos_noturnos('2026-05-04 23:00:00', '2026-05-05 01:00:00') === 120);
check('22h às 06h = 420 min (janela inteira, para no fim às 5h)',
      clt_minutos_noturnos('2026-05-04 22:00:00', '2026-05-05 06:00:00') === 420,
      (string)clt_minutos_noturnos('2026-05-04 22:00:00', '2026-05-05 06:00:00'));
check('03h às 04h (madrugada) = 60 min',
      clt_minutos_noturnos('2026-05-04 03:00:00', '2026-05-04 04:00:00') === 60);
check('intervalo invertido devolve 0',
      clt_minutos_noturnos('2026-05-04 10:00:00', '2026-05-04 08:00:00') === 0);
// Plantão de 24h toca DUAS janelas noturnas.
check('plantão de 24h abrange duas janelas noturnas',
      clt_minutos_noturnos('2026-05-04 08:00:00', '2026-05-05 08:00:00') === 420,
      (string)clt_minutos_noturnos('2026-05-04 08:00:00', '2026-05-05 08:00:00'));

echo "[2] Hora noturna REDUZIDA de 52min30s (art. 73 §1º)\n";
check('constante correta', CLT_HORA_NOTURNA_MIN === 52.5);
check('adicional mínimo de 20%', CLT_ADICIONAL_NOTURNO === 0.20);
// 420 min de relógio = 8 horas noturnas (420 / 52,5), não 7.
$horas = 420 / CLT_HORA_NOTURNA_MIN;
check('420 min de relógio equivalem a 8 horas noturnas', abs($horas - 8.0) < 1e-9,
      (string)$horas);
check('a redução AUMENTA as horas devidas (52,5 < 60)', $horas > (420 / 60));

echo "[3] Intervalo intrajornada mínimo (art. 71)\n";
check('jornada de 4h não exige intervalo', clt_intervalo_minimo(240) === 0);
check('jornada de 4h01 exige 15 min', clt_intervalo_minimo(241) === 15);
check('jornada de 6h exige 15 min', clt_intervalo_minimo(360) === 15);
check('jornada de 6h01 exige 60 min', clt_intervalo_minimo(361) === 60);
check('jornada de 8h exige 60 min', clt_intervalo_minimo(480) === 60);
check('máximo de 2h sem acordo', CLT_INTERVALO_MAXIMO === 120);

echo "[4] Tolerância do art. 58 §1º — a regra que estava errada\n";
// Antes: 5 min aplicados ao AGREGADO do mês. A lei é por marcação.
$r = clt_tolerancia_dia([3, -4]);
check('duas variações de até 5 min são desprezadas', $r['computado'] === 0, json_encode($r['computado']));
check('e somam no desprezado', $r['desprezado'] === 7, (string)$r['desprezado']);

// O ponto que mais se erra: excedeu 5 min, computa TUDO — não só o excedente.
$r = clt_tolerancia_dia([7]);
check('variação de 7 min é computada INTEGRALMENTE (não 2)', $r['computado'] === 7,
      'computado=' . $r['computado']);
check('nada é desprezado nesse caso', $r['desprezado'] === 0);

// Teto diário de 10 min: a terceira marcação de 5 min já não cabe.
$r = clt_tolerancia_dia([5, 5, 5]);
check('teto diário de 10 min é respeitado', $r['desprezado'] === 10, (string)$r['desprezado']);
check('o que excede o teto é computado', $r['computado'] === 5, (string)$r['computado']);
check('teto marcado como excedido', $r['teto_excedido'] === true);

// Sinal preservado: atraso reduz, antecipação aumenta.
$r = clt_tolerancia_dia([-8, 9]);
check('sinais são preservados', $r['computado'] === 1, (string)$r['computado']);

$r = clt_tolerancia_dia([]);
check('dia sem variação não computa nada', $r['computado'] === 0 && $r['desprezado'] === 0);

$r = clt_tolerancia_dia([5, 5, 3]);
check('caso de borda: 5+5 no teto, 3 seguinte é computado',
      $r['desprezado'] === 10 && $r['computado'] === 3,
      "desprezado={$r['desprezado']} computado={$r['computado']}");

echo "[5] Avisos de conformidade sobre um dia composto\n";
$w = [['start' => '2026-05-04 08:00:00', 'end' => '2026-05-04 18:00:00']]; // 10h
check('jornada de 10h sem intervalo gera aviso do art. 71',
      (bool)array_filter(clt_avisos_jornada($w, []), fn($a) => stripos($a, 'art. 71') !== false));
check('aviso quantifica o que falta',
      (bool)array_filter(clt_avisos_jornada($w, []), fn($a) => str_contains($a, 'Faltam 1h')));
check('aviso cita o adicional de 50% do §4º',
      (bool)array_filter(clt_avisos_jornada($w, []), fn($a) => str_contains($a, '50%')));

$b = [['start' => '2026-05-04 12:00:00', 'end' => '2026-05-04 13:00:00']];
$avisos = clt_avisos_jornada($w, $b);
check('com 1h de intervalo o aviso do art. 71 desaparece',
      !array_filter($avisos, fn($a) => str_contains($a, 'exige intervalo')));

$wLongo = [['start' => '2026-05-04 08:00:00', 'end' => '2026-05-04 21:00:00']]; // 13h
check('jornada de 13h gera aviso do art. 59',
      (bool)array_filter(clt_avisos_jornada($wLongo, $b), fn($a) => stripos($a, 'art. 59') !== false));

$wNoturno = [['start' => '2026-05-04 21:00:00', 'end' => '2026-05-05 02:00:00']];
$avNot = clt_avisos_jornada($wNoturno, []);
check('trabalho noturno gera aviso do art. 73',
      (bool)array_filter($avNot, fn($a) => stripos($a, 'art. 73') !== false));
check('aviso menciona a hora reduzida',
      (bool)array_filter($avNot, fn($a) => str_contains($a, '52min30s')));

check('dia sem jornada não gera aviso', clt_avisos_jornada([], []) === []);

echo "[6] Apuração contra o banco\n";
try {
    $schoolId = (int)$pdo->query("SELECT id FROM schools ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,1,NOW())")
        ->execute(['ZZ Teste CLT', '00000000272']);
    $teacherId = (int)$pdo->lastInsertId();
    $dia = '2026-02-19';
    $nsr = (int)$pdo->query("SELECT COALESCE(MAX(nsr),0)+9000 FROM attendance")->fetchColumn();

    // Turno noturno: 21h às 02h. Noturno de relógio = 22h→02h = 240 min.
    $pdo->prepare("INSERT INTO attendance (teacher_id, school_id, date, check_in, check_out, method, approved, record_type, nsr)
                   VALUES (?,?,?,?,?,'test',1,'work',?)")
        ->execute([$teacherId, $schoolId, $dia, "$dia 21:00:00", '2026-02-20 02:00:00', $nsr]);

    $not = clt_adicional_noturno($pdo, $teacherId, $dia);
    check('240 min de relógio em horário noturno', $not['minutos_relogio'] === 240,
          (string)$not['minutos_relogio']);
    check('equivalem a ~4,57 horas noturnas reduzidas',
          abs($not['horas_reduzidas'] - (240 / 52.5)) < 1e-3, (string)$not['horas_reduzidas']);
    check('minutos equivalentes maiores que os de relógio',
          $not['minutos_equivalentes'] > $not['minutos_relogio'],
          "{$not['minutos_equivalentes']} vs {$not['minutos_relogio']}");

    $valor = clt_valor_adicional_noturno(20.0, $not);
    check('valor do adicional calculado', $valor > 0, (string)$valor);
    check('adicional é 20% sobre as horas noturnas',
          abs($valor - (20.0 * $not['horas_reduzidas'] * 0.20)) < 0.01);

    // Jornada de 5h (21h→02h) exige 60 min de intervalo? Não: 300 min → 15 min.
    $intra = clt_intervalo_intrajornada($pdo, $teacherId, $dia);
    check('jornada de 5h exige 15 min de intervalo', $intra['intervalo_devido'] === 15,
          (string)$intra['intervalo_devido']);
    check('sem intervalo registrado, marca irregular', $intra['irregular'] === true);
    check('quantifica o suprimido', $intra['suprimido'] === 15, (string)$intra['suprimido']);

    $ind = clt_indenizacao_intervalo(20.0, $intra['suprimido']);
    check('indenização do §4º é o período suprimido com +50%',
          abs($ind - ((20.0 / 60) * 15 * 1.5)) < 0.01, (string)$ind);

    echo "[7] Interjornada (art. 66)\n";
    // Saída às 02h; nova entrada às 08h do mesmo dia = 6h de descanso.
    $inter = clt_interjornada($pdo, $teacherId, '2026-02-20 08:00:00');
    check('descanso de 6h é reprovado', $inter['ok'] === false);
    check('calcula o intervalo real', $inter['intervalo_min'] === 360, (string)$inter['intervalo_min']);
    check('calcula quanto faltou', $inter['faltante'] === 300, (string)$inter['faltante']);
    check('motivo cita o art. 66', str_contains((string)$inter['motivo'], 'art. 66'));

    // Entrada 12h depois: regular.
    $inter2 = clt_interjornada($pdo, $teacherId, '2026-02-20 14:00:00');
    check('descanso de 12h é aprovado', $inter2['ok'] === true, (string)$inter2['motivo']);

    // Sem jornada anterior: não há o que comparar.
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,1,NOW())")
        ->execute(['ZZ Teste CLT2', '00000000353']);
    $novo = (int)$pdo->lastInsertId();
    check('primeiro registro do colaborador não acusa nada',
          clt_interjornada($pdo, $novo, '2026-02-20 08:00:00')['ok'] === true);
    $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$novo]);

    echo "[8] Apuração consolidada\n";
    $ap = clt_apurar_dia($pdo, $teacherId, $dia);
    check('devolve noturno e intrajornada', isset($ap['noturno'], $ap['intrajornada']));
    check('lista as irregularidades', count($ap['irregularidades']) >= 1, json_encode($ap['irregularidades']));

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($teacherId) {
        $pdo->prepare("DELETE FROM attendance WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$teacherId]);
    }
    $pdo->prepare("DELETE FROM teachers WHERE name LIKE 'ZZ Teste CLT%'")->execute();
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

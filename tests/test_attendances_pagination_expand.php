<?php
/**
 * Reproduz o bug onde attendances.php mostrava "Entrada 18:09" para um dia
 * que na verdade comecou as 05:00. Causa: LIMIT 50 cortava o work#1 da pagina.
 *
 * Cenario do Pablo (26/05/2026):
 *   05:23 -> entrada (work#1)
 *   13:41 -> iniciar intervalo (work#1 fecha @ 13:41, break abre)
 *   18:09 -> retornar intervalo (break fecha @ 18:09, work#2 abre)
 *   19:18 -> saida (work#2 fecha @ 19:18)
 *
 * Bug: se a query SQL paginada (LIMIT N) retorna apenas o work#2 e o break
 * (porque o work#1 caiu fora da pagina em outros dias),
 * consolidate_attendance_by_day so ve work#2 e mostra "Entrada 18:09".
 *
 * Fix: query expansiva apos a paginada, carrega TODOS os registros dos
 * (date, teacher_id) visiveis na pagina.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pdo = db();
$failed = 0;
$passed = 0;

// A produção tem attendance.nsr NOT NULL: gera um NSR único por inserção (padrão atômico do nsr_sequence).
$nextNsr = function () use ($pdo): int {
    $cur = (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $n = $cur + 1;
    $pdo->prepare("UPDATE nsr_sequence SET current_nsr=? WHERE id=1")->execute([$n]);
    return $n;
};

function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  [OK]   $label\n";
    } else {
        $failed++;
        echo "  [FAIL] $label";
        if ($detail !== '') echo " - $detail";
        echo "\n";
    }
}

echo "=== Regressao: paginacao parcial nao quebra display de entrada ===\n\n";

$pdo->beginTransaction();

try {
    // Cria colaborador de teste
    $pdo->prepare("INSERT INTO teachers (name, cpf, active) VALUES (?, ?, 1)")
        ->execute(['__pablo_teste', '00000000094']);
    $teacherId = (int)$pdo->lastInsertId();

    // Cenario do Pablo: 3 registros num dia (entrada matinal, break tarde, saida noite)
    $date = '2026-05-26';
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId, $date, $date . ' 05:23:00', $date . ' 13:41:00', $nextNsr()]);
    $work1Id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, parent_attendance_id, approved, method, nsr) VALUES (?, ?, ?, ?, 'break', ?, 1, 'manual', ?)")
        ->execute([$teacherId, $date, $date . ' 13:41:00', $date . ' 18:09:00', $work1Id, $nextNsr()]);
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, ?, ?, ?, 'work', 1, 'manual', ?)")
        ->execute([$teacherId, $date, $date . ' 18:09:00', $date . ' 19:18:00', $nextNsr()]);

    echo "Setup: teacher_id=$teacherId, 3 registros no dia $date\n\n";

    // ----- Cenario 1: query normal (LIMIT amplo) retorna todos -----
    echo "Cenario 1: query normal (sem paginacao parcial)\n";
    $sql = "SELECT a.*, t.name FROM attendance a JOIN teachers t ON t.id = a.teacher_id
            WHERE a.teacher_id = ? AND a.superseded_by_id IS NULL
            ORDER BY a.check_in DESC";
    $st = $pdo->prepare($sql);
    $st->execute([$teacherId]);
    $rowsAll = $st->fetchAll(PDO::FETCH_ASSOC);
    check('query traz 3 registros', count($rowsAll) === 3, "obtido: " . count($rowsAll));
    foreach ($rowsAll as &$r) { $r['teacher_name'] = $r['name']; }
    $consA = consolidate_attendance_by_day($rowsAll);
    check('consolidacao com tudo: entrada=05:23', ($consA[0]['check_in'] ?? null) === '05:23:00', "obtido: " . ($consA[0]['check_in'] ?? 'null'));
    check('consolidacao com tudo: saida=19:18', ($consA[0]['check_out'] ?? null) === '19:18:00', "obtido: " . ($consA[0]['check_out'] ?? 'null'));
    check('consolidacao com tudo: 1 break', count($consA[0]['breaks'] ?? []) === 1);

    // ----- Cenario 2: simula paginacao parcial (LIMIT 2 corta o work#1) -----
    echo "\nCenario 2: query com LIMIT 2 corta o work#1 da pagina\n";
    $sqlParcial = "SELECT a.*, t.name FROM attendance a JOIN teachers t ON t.id = a.teacher_id
                   WHERE a.teacher_id = ? AND a.superseded_by_id IS NULL
                   ORDER BY a.check_in DESC LIMIT 2";
    $st = $pdo->prepare($sqlParcial);
    $st->execute([$teacherId]);
    $rowsParcial = $st->fetchAll(PDO::FETCH_ASSOC);
    check('query paginada (LIMIT 2) traz apenas 2 registros', count($rowsParcial) === 2, "obtido: " . count($rowsParcial));
    foreach ($rowsParcial as &$rp) { $rp['teacher_name'] = $rp['name']; }
    $consB = consolidate_attendance_by_day($rowsParcial);
    // SEM expansao, o bug aparece:
    check('SEM expansao: bug -> entrada=18:09 (errado, deveria ser 05:23)',
          ($consB[0]['check_in'] ?? null) === '18:09:00',
          "obtido: " . ($consB[0]['check_in'] ?? 'null'));

    // ----- Cenario 3: simula expansao apos paginacao (o fix) -----
    echo "\nCenario 3: aplica expansao -> dia completo restaurado\n";
    $dayPairs = [];
    foreach ($rowsParcial as $rrow) {
        $key = $rrow['date'] . '_' . $rrow['teacher_id'];
        $dayPairs[$key] = ['date' => $rrow['date'], 'teacher_id' => (int)$rrow['teacher_id']];
    }
    $expandPlaceholders = [];
    $expandParams = [];
    foreach ($dayPairs as $pair) {
        $expandPlaceholders[] = '(a.date = ? AND a.teacher_id = ?)';
        $expandParams[] = $pair['date'];
        $expandParams[] = $pair['teacher_id'];
    }
    $expandWhere = '(' . implode(' OR ', $expandPlaceholders) . ')';
    $expandSql = "SELECT a.*, t.name FROM attendance a JOIN teachers t ON t.id = a.teacher_id
                  WHERE $expandWhere AND a.superseded_by_id IS NULL
                  ORDER BY a.date DESC, a.teacher_id, a.check_in ASC";
    $stExp = $pdo->prepare($expandSql);
    $stExp->execute($expandParams);
    $rowsExpand = $stExp->fetchAll(PDO::FETCH_ASSOC);
    check('apos expansao: 3 registros (dia completo)', count($rowsExpand) === 3, "obtido: " . count($rowsExpand));
    foreach ($rowsExpand as &$re) { $re['teacher_name'] = $re['name']; }
    $consC = consolidate_attendance_by_day($rowsExpand);
    check('FIX: entrada=05:23 (corretamente recuperada)', ($consC[0]['check_in'] ?? null) === '05:23:00', "obtido: " . ($consC[0]['check_in'] ?? 'null'));
    check('FIX: saida=19:18', ($consC[0]['check_out'] ?? null) === '19:18:00', "obtido: " . ($consC[0]['check_out'] ?? 'null'));
    check('FIX: 1 intervalo (nao duplicado)', count($consC[0]['breaks'] ?? []) === 1, "obtido: " . count($consC[0]['breaks'] ?? []));
    check('FIX: intervalo 13:41 -> 18:09', ($consC[0]['breaks'][0]['start'] ?? null) === '13:41:00' && ($consC[0]['breaks'][0]['end'] ?? null) === '18:09:00');
    // Worked: 05:23 -> 19:18 = 13h55 (835min) menos break 4h28 (268min) = 567min
    $expectedWorked = (19 * 60 + 18) - (5 * 60 + 23) - (18 * 60 + 9 - 13 * 60 - 41);
    check("FIX: total_worked_minutes = $expectedWorked",
          ($consC[0]['total_worked_minutes'] ?? null) === $expectedWorked,
          "obtido: " . ($consC[0]['total_worked_minutes'] ?? 'null'));

    echo "\n=== Resultado ===\n";
    echo "Passed: $passed\n";
    echo "Failed: $failed\n";

    $pdo->rollBack();
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nEXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(2);
}

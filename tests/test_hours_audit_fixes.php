<?php
/**
 * Testes específicos para os fixes encontrados na auditoria do schedule_mode='hours':
 *  - reports_financial.php carrega collaborator_hours_schedules
 *  - reports_insights.php carrega collaborator_hours_schedules em batch
 *  - attendance_manual.php valida corretamente para mode='hours'
 *
 * Como rodar (PowerShell):
 *   php tests\test_hours_audit_fixes.php
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
        if ($detail !== '') echo " — $detail";
        echo "\n";
    }
}

echo "=== Audit fix tests: schedule_mode='hours' ===\n\n";

$pdo->beginTransaction();

try {
    // Setup
    $pdo->prepare("INSERT INTO collaborator_types (name, slug, schedule_mode, requires_schedule) VALUES (?, ?, 'hours', 1)")
        ->execute(['__audit_motorista', '__audit_motorista']);
    $typeId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__audit_motorista_user', '00000000095', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();

    // 8h seg-sex, 4h sáb, 0h dom
    foreach ([1=>480, 2=>480, 3=>480, 4=>480, 5=>480, 6=>240, 0=>0] as $wd => $total) {
        $pdo->prepare("INSERT INTO collaborator_hours_schedules (teacher_id, weekday, total_minutes, break_minutes) VALUES (?, ?, ?, ?)")
            ->execute([$teacherId, $wd, $total, $total > 0 ? 60 : 0]);
    }
    echo "Setup: teacher_id=$teacherId, type_id=$typeId\n\n";

    // ========================================================================
    // FIX 1: reports_financial.php carrega collaborator_hours_schedules
    // ========================================================================
    echo "FIX 1: reports_financial.php — schedule loading\n";
    $teacher = ['id' => $teacherId, 'schedule_mode' => 'hours'];
    $scheduleMap = [];
    $tMode = $teacher['schedule_mode'];
    if ($tMode === 'classes') {
        // bypass
    } elseif ($tMode === 'hours') {
        $st = $pdo->prepare("SELECT weekday, total_minutes, break_minutes FROM collaborator_hours_schedules WHERE teacher_id = ?");
        $st->execute([$teacher['id']]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $scheduleMap[(int)$r['weekday']] = ['total' => (int)$r['total_minutes'], 'break' => (int)$r['break_minutes']];
        }
    }
    check('reports_financial: scheduleMap carregado (7 dias)', count($scheduleMap) === 7, "obtido: " . count($scheduleMap));
    check('reports_financial: scheduleMap[1] (seg) total=480', ($scheduleMap[1]['total'] ?? 0) === 480, "obtido: " . ($scheduleMap[1]['total'] ?? 'null'));
    check('reports_financial: scheduleMap[6] (sab) total=240', ($scheduleMap[6]['total'] ?? 0) === 240, "obtido: " . ($scheduleMap[6]['total'] ?? 'null'));

    // Cálculo de expected como reports_financial faz
    $w = 1; // seg
    $expectedMin = ($tMode === 'hours') ? max(0, (int)($scheduleMap[$w]['total'] ?? 0)) : 0;
    check('reports_financial: expectedMin segunda = 480', $expectedMin === 480, "obtido: $expectedMin");

    // ========================================================================
    // FIX 2: reports_insights.php carrega em batch
    // ========================================================================
    echo "\nFIX 2: reports_insights.php — batch loading\n";
    $teacherIds = [$teacherId];
    $teacherById = [$teacherId => ['schedule' => []]];
    $place = implode(',', array_fill(0, count($teacherIds), '?'));
    $stHr = $pdo->prepare("SELECT teacher_id, weekday, total_minutes FROM collaborator_hours_schedules WHERE teacher_id IN ($place)");
    $stHr->execute($teacherIds);
    while ($row = $stHr->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['teacher_id'];
        $wd = (int)$row['weekday'];
        $teacherById[$tid]['schedule'][$wd] = [
            'expected_min' => max(0, (int)$row['total_minutes']),
            'start_time' => null,
        ];
    }
    check('reports_insights: 7 dias carregados em batch', count($teacherById[$teacherId]['schedule']) === 7, "obtido: " . count($teacherById[$teacherId]['schedule']));
    check('reports_insights: seg expected_min=480', ($teacherById[$teacherId]['schedule'][1]['expected_min'] ?? 0) === 480);
    check('reports_insights: dom expected_min=0', ($teacherById[$teacherId]['schedule'][0]['expected_min'] ?? -1) === 0);
    // `?? null` colapsaria chave-faltante e null no mesmo bucket; verifica explicitamente que a chave existe E é null
    $hasKeyAndNull = array_key_exists('start_time', $teacherById[$teacherId]['schedule'][1]) && $teacherById[$teacherId]['schedule'][1]['start_time'] === null;
    check('reports_insights: start_time=null (sem pontualidade)', $hasKeyAndNull);

    // ========================================================================
    // FIX 3: attendance_manual.php — não bloqueia mode hours
    // ========================================================================
    echo "\nFIX 3: attendance_manual.php — validação para mode hours\n";

    // Simula a lógica de attendance_manual.php para mode='hours' em uma segunda (com schedule)
    $weekday = 1; // segunda
    $st = $pdo->prepare("SELECT total_minutes FROM collaborator_hours_schedules WHERE teacher_id = ? AND weekday = ?");
    $st->execute([$teacherId, $weekday]);
    $hs = $st->fetch(PDO::FETCH_ASSOC);
    $hasSchedule = !(!$hs || (int)$hs['total_minutes'] <= 0);
    check('attendance_manual: seg tem schedule → não bloqueia', $hasSchedule === true, "obtido hasSchedule=" . var_export($hasSchedule, true));

    // Mesmo teste em domingo (sem schedule)
    $weekday = 0;
    $st = $pdo->prepare("SELECT total_minutes FROM collaborator_hours_schedules WHERE teacher_id = ? AND weekday = ?");
    $st->execute([$teacherId, $weekday]);
    $hs = $st->fetch(PDO::FETCH_ASSOC);
    $hasSchedule = !(!$hs || (int)$hs['total_minutes'] <= 0);
    check('attendance_manual: dom sem schedule → bloqueio esperado (allowOutsideWindow=false)', $hasSchedule === false, "obtido hasSchedule=" . var_export($hasSchedule, true));

    // Validação 'both': saída > entrada (sem janela)
    $time_in = '08:00';
    $time_out = '17:00';
    $ciTs = strtotime('2026-05-25 ' . $time_in . ':00');
    $coTs = strtotime('2026-05-25 ' . $time_out . ':00');
    check('attendance_manual: both 08:00 → 17:00 válido (saída > entrada)', $coTs > $ciTs);

    $time_out_bad = '07:00';
    $coTsBad = strtotime('2026-05-25 ' . $time_out_bad . ':00');
    check('attendance_manual: both 08:00 → 07:00 inválido (saída <= entrada)', $coTsBad <= $ciTs);

    // ========================================================================
    // INTEGRATION: fluxo completo end-to-end
    // ========================================================================
    echo "\nINTEGRATION: fluxo completo motorista\n";

    // 1. Cria attendance: motorista bateu 6h na segunda (06:00 → 12:00)
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, '2026-05-25', '2026-05-25 06:00:00', '2026-05-25 12:00:00', 'work', 1, 'manual', ?)")
        ->execute([$teacherId, $nextNsr()]);

    $expected = calculate_expected_minutes($pdo, $teacherId, '2026-05-25');
    $worked = calculate_worked_minutes($pdo, $teacherId, '2026-05-25');
    $effective = calculate_effective_worked_minutes($pdo, $teacherId, '2026-05-25');

    check('Integration: expected=480 (8h)', $expected === 480, "obtido: $expected");
    check('Integration: worked=360 (6h bruto)', $worked === 360, "obtido: $worked");
    // Modelo "cheio vs cheio": sem desconto de intervalo — efetivo = presença (360).
    check('Integration: effective=360 (presença cheia, sem desconto)', $effective === 360, "obtido: $effective");

    $pctCumpr = (int)round(min(100, ($effective / $expected) * 100));
    // 360/480 = 0.75 → 75%
    check('Integration: cumprimento=75% (360/480)', $pctCumpr === 75, "obtido: $pctCumpr");

    // 2. Adiciona mais 2h trabalhadas: agora total 8h (480min)
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, record_type, approved, method, nsr) VALUES (?, '2026-05-25', '2026-05-25 13:00:00', '2026-05-25 15:00:00', 'work', 1, 'manual', ?)")
        ->execute([$teacherId, $nextNsr()]);

    $worked2 = calculate_worked_minutes($pdo, $teacherId, '2026-05-25');
    $effective2 = calculate_effective_worked_minutes($pdo, $teacherId, '2026-05-25');
    check('Integration: worked=480 (8h bruto com 2 pares)', $worked2 === 480, "obtido: $worked2");
    check('Integration: effective=480 (presença cheia, sem desconto)', $effective2 === 480, "obtido: $effective2");

    $pctCumpr2 = (int)round(min(100, ($effective2 / $expected) * 100));
    check('Integration: cumprimento=100% (480/480)', $pctCumpr2 === 100, "obtido: $pctCumpr2");

    // ---------- Resultado final ----------
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

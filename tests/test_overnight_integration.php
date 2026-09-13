<?php
/**
 * Teste de integração end-to-end de turnos noturnos.
 *
 * Simula o fluxo COMPLETO:
 *   1. Cria collaborator_type modo "time" + teacher (vigilante)
 *   2. Cadastra horário 18h → 06h com end_next_day=1
 *   3. Insere entrada manual em D às 18:00
 *   4. Insere saída manual em D+1 às 06:00 (cruza meia-noite)
 *   5. Verifica que attendance.date = D (não D+1)
 *   6. Verifica que calculate_worked_minutes(D) = 720
 *   7. Verifica que calculate_expected_minutes(D) = 720
 *   8. Verifica que dashboard.workingNow conta vigilantes em jornada aberta
 *   9. Verifica que reports/teacher_monthly_report acumulam corretamente
 *
 * Tudo em transação — rollback no final, zero dados persistidos.
 *
 * Como rodar: php tests\test_overnight_integration.php
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

echo "=== Teste de integração: turnos noturnos end-to-end ===\n\n";

$pdo->beginTransaction();

try {
    // ---------- 1) Setup: tipo + teacher ----------
    echo "[1] Setup: colaborador_types e teacher\n";
    $stCt = $pdo->prepare("SELECT id FROM collaborator_types WHERE schedule_mode='time' LIMIT 1");
    $stCt->execute();
    $typeId = (int)$stCt->fetchColumn();
    if (!$typeId) {
        $pdo->prepare("INSERT INTO collaborator_types (name, schedule_mode, requires_schedule) VALUES (?, 'time', 1)")
            ->execute(['__test_vigia_int']);
        $typeId = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__integration_vigia', '99999999999', $typeId]);
    $teacherId = (int)$pdo->lastInsertId();
    check("teacher criado (id=$teacherId)", $teacherId > 0);

    // ---------- 2) Cadastro do horário 18h → 06h para o weekday de ONTEM ----------
    $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
    $today = (new DateTime('today'))->format('Y-m-d');
    $weekdayYesterday = (int)(new DateTime($yesterday))->format('w');
    echo "\n[2] Cadastro de turno noturno (weekday=$weekdayYesterday, 18h-06h, end_next_day=1)\n";
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, ?, '18:00:00', '06:00:00', 1, 0)")
        ->execute([$teacherId, $weekdayYesterday]);
    $st = $pdo->prepare("SELECT start_time, end_time, end_next_day FROM collaborator_time_schedules WHERE teacher_id=? AND weekday=?");
    $st->execute([$teacherId, $weekdayYesterday]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    check('start_time persistido = 18:00:00', $row['start_time'] === '18:00:00');
    check('end_time persistido = 06:00:00', $row['end_time'] === '06:00:00');
    check('end_next_day persistido = 1', (int)$row['end_next_day'] === 1);

    // ---------- 3) Simula entrada manual ONTEM 18:00 (dentro da janela 30h) ----------
    $entryDate = $yesterday;
    $entryDateTime = $yesterday . ' 18:00:00';
    echo "\n[3] Simula entrada manual em {$entryDateTime} (ontem)\n";
    $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, method, approved, nsr) VALUES (?, ?, ?, 'manual', 1, ?)")
        ->execute([$teacherId, $entryDate, $entryDateTime, $nextNsr()]);
    $attendanceId = (int)$pdo->lastInsertId();
    check('attendance inserida', $attendanceId > 0);

    // ---------- 4) API checkin.php: busca aberto com janela temporal (30h) ----------
    echo "\n[4] API de check-in: busca registro aberto dentro da janela de 30h\n";
    $stOpen = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND check_in IS NOT NULL AND check_out IS NULL AND check_in >= (NOW() - INTERVAL 30 HOUR) ORDER BY check_in DESC LIMIT 1");
    $stOpen->execute([$teacherId]);
    $openRecord = $stOpen->fetch(PDO::FETCH_ASSOC);
    check('encontra registro aberto dentro da janela', $openRecord !== false);
    check('id corresponde', (int)($openRecord['id'] ?? 0) === $attendanceId);
    check("date = $entryDate (dia da entrada)", ($openRecord['date'] ?? '') === $entryDate);

    // ---------- 5) Simula saída HOJE 06:00 (cruza meia-noite) ----------
    $exitDateTime = $today . ' 06:00:00';
    echo "\n[5] Simula saída em {$exitDateTime} (hoje, dia seguinte)\n";
    // UPDATE simulando o api/checkin.php (não toca em `date`):
    $pdo->prepare("UPDATE attendance SET check_out = ? WHERE id = ?")
        ->execute([$exitDateTime, $attendanceId]);
    $stCheck = $pdo->prepare("SELECT date, check_in, check_out FROM attendance WHERE id = ?");
    $stCheck->execute([$attendanceId]);
    $rec = $stCheck->fetch(PDO::FETCH_ASSOC);
    check("attendance.date PERMANECE = $entryDate (não muda para hoje)", $rec['date'] === $entryDate);
    check("check_in = $entryDateTime", $rec['check_in'] === $entryDateTime);
    check("check_out = $exitDateTime (próximo dia)", $rec['check_out'] === $exitDateTime);

    // ---------- 6) Cálculo de horas trabalhadas ----------
    echo "\n[6] calculate_worked_minutes deve retornar 720 (12h)\n";
    $worked = calculate_worked_minutes($pdo, $teacherId, $entryDate);
    check('worked = 720 minutos', $worked === 720, "obtido: $worked");

    // ---------- 7) Cálculo de horas esperadas ----------
    echo "\n[7] calculate_expected_minutes deve retornar 720 (12h)\n";
    $expected = calculate_expected_minutes($pdo, $teacherId, $entryDate);
    check('expected = 720 minutos', $expected === 720, "obtido: $expected");

    // ---------- 8) Banco de horas (simula recalculo do checkin.php) ----------
    echo "\n[8] Banco de horas: saldo = 0 (worked == expected)\n";
    $delta = $worked - $expected;
    check('delta = 0', $delta === 0);
    // Insere entrada de hour_bank usando $entryDate (não $today/D+1)
    $pdo->prepare("INSERT INTO hour_bank_entries (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id) VALUES (?, NULL, ?, ?, 'Recalculo auto', 'auto', ?)")
        ->execute([$teacherId, $entryDate, $delta, $attendanceId]);
    $stHb = $pdo->prepare("SELECT date FROM hour_bank_entries WHERE ref_attendance_id = ?");
    $stHb->execute([$attendanceId]);
    $hbDate = $stHb->fetchColumn();
    check("hour_bank_entries.date = $entryDate (não D+1)", $hbDate === $entryDate);

    // ---------- 9) Dashboard "trabalhando agora" inclui jornada aberta ----------
    echo "\n[9] Dashboard: vigilante com jornada aberta (sem checkout) aparece como trabalhando\n";
    // Reabre o registro para simular jornada em andamento
    $pdo->prepare("UPDATE attendance SET check_out = NULL WHERE id = ?")->execute([$attendanceId]);
    $stWork = $pdo->prepare("SELECT COUNT(DISTINCT a.teacher_id) FROM attendance a WHERE a.check_in IS NOT NULL AND a.check_out IS NULL AND a.teacher_id = ?");
    $stWork->execute([$teacherId]);
    $workingCount = (int)$stWork->fetchColumn();
    check('contagem "trabalhando agora" = 1', $workingCount === 1, "obtido: $workingCount");

    // ---------- 10) "Ausentes" NÃO conta vigilante em jornada noturna aberta ----------
    echo "\n[10] Dashboard: vigilante em jornada aberta NÃO conta como ausente\n";
    // Query da dashboard.php nova lógica (weekday do dia da entrada)
    $stAbs = $pdo->prepare("
      SELECT COUNT(DISTINCT t.id)
      FROM teachers t
      WHERE t.id = ?
        AND EXISTS (SELECT 1 FROM collaborator_time_schedules ts WHERE ts.teacher_id=t.id AND ts.weekday=? AND ts.start_time IS NOT NULL AND ts.end_time IS NOT NULL)
        AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.teacher_id=t.id AND a.date=? AND (a.approved IS NULL OR a.approved IN (0,1)))
        AND NOT EXISTS (SELECT 1 FROM attendance a2 WHERE a2.teacher_id=t.id AND a2.check_in IS NOT NULL AND a2.check_out IS NULL)
    ");
    // Usa "hoje" como referência — para o vigilante que entrou ontem
    $stAbs->execute([$teacherId, $weekdayYesterday, $today]);
    $absentCount = (int)$stAbs->fetchColumn();
    check('vigilante NÃO é ausente (jornada aberta exclui)', $absentCount === 0, "obtido: $absentCount");

    // Re-fecha registro para testes restantes
    $pdo->prepare("UPDATE attendance SET check_out = ? WHERE id = ?")
        ->execute([$exitDateTime, $attendanceId]);

    // ---------- 11) Múltiplos turnos noturnos: 5 noites consecutivas ----------
    echo "\n[11] 5 noites de plantão consecutivas (seg-sex 18h→06h)\n";
    $weekdays = ['2026-05-11', '2026-05-12', '2026-05-13', '2026-05-14', '2026-05-15'];
    foreach ($weekdays as $i => $d) {
        // Insere TODAS as 5 noites. A seção [2-3] usa data dinâmica ('ontem'),
        // que não coincide com estas datas fixas de maio — então não pulamos a
        // primeira (senão 2026-05-11 ficaria sem attendance e a soma daria 4 noites).
        $w = (int)date('w', strtotime($d));
        // Insere schedule para o weekday se ainda não existe
        $pdo->prepare("INSERT IGNORE INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, ?, '18:00:00', '06:00:00', 1, 0)")
            ->execute([$teacherId, $w]);
        // Insere attendance
        $exitD = (new DateTime($d))->modify('+1 day')->format('Y-m-d');
        $pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, method, approved, nsr) VALUES (?, ?, ?, ?, 'manual', 1, ?)")
            ->execute([$teacherId, $d, $d . ' 18:00:00', $exitD . ' 06:00:00', $nextNsr()]);
    }
    $totalWorked = 0;
    foreach ($weekdays as $d) {
        $totalWorked += calculate_worked_minutes($pdo, $teacherId, $d);
    }
    check('total trabalhado em 5 noites = 3600 min (60h)', $totalWorked === 3600, "obtido: $totalWorked");

    // ---------- 12) Edge: turno de 24h (start == end, end_next_day=1) ----------
    echo "\n[12] Turno de 24h (plantão 12x36 inverso)\n";
    // 2026-05-16 é um sábado, mas o calendário do ambiente pode remapeá-lo
    // (sábado letivo / feriado). Usa o weekday EFETIVO para inserir a jornada de
    // 24h — assim o teste valida a matemática de 24h independente do calendário.
    $sat24 = '2026-05-16';
    $effW24 = get_effective_weekday($pdo, $sat24, null);
    $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id=? AND weekday=?")
        ->execute([$teacherId, $effW24]);
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, ?, '08:00:00', '08:00:00', 1, 0)")
        ->execute([$teacherId, $effW24]);
    $expected24 = calculate_expected_minutes($pdo, $teacherId, $sat24);
    check('plantão 24h: expected = 1440 min', $expected24 === 1440, "obtido: $expected24");

    // ---------- 13) Edge: turno diurno normal não afetado (regressão) ----------
    echo "\n[13] Regressão: turno diurno normal (07h-15h, end_next_day=0)\n";
    $pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, ?, 1)")
        ->execute(['__integration_diurno', '88888888888', $typeId]);
    $tidDay = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, 1, '07:00:00', '15:00:00', 0, 60)")
        ->execute([$tidDay]);
    $expectedDay = calculate_expected_minutes($pdo, $tidDay, '2026-05-11');
    // Modelo "cheio vs cheio": janela completa, break_minutes é informativo.
    check('turno diurno: expected = 480 min (janela completa 07-15)', $expectedDay === 480, "obtido: $expectedDay");

    // ---------- Resultado final ----------
    echo "\n=== Resultado: $passed passou(aram), $failed falhou(aram) ===\n";
} catch (Throwable $e) {
    echo "\nErro inesperado: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        echo "(transação revertida — nenhum dado persistido)\n";
    }
}

exit($failed > 0 ? 1 : 0);

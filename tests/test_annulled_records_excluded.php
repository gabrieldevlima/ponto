<?php
declare(strict_types=1);
/**
 * NC-51 / NC-52 / NC-53 — registros anulados não podem influenciar cálculo nem fluxo.
 *
 * NC-51: um INTERVALO anulado pelo admin continuava somando em
 *        calculate_effective_worked_minutes() — a função não filtra `approved`
 *        (breaks pendentes contam de propósito), então a anulação era o único
 *        discriminador e ele não era aplicado.
 * NC-52: um registro ABERTO anulado continuava sendo encontrado como "em aberto",
 *        permitindo que o colaborador o fechasse e ressuscitasse a marcação.
 *
 * Execução: php tests/test_annulled_records_excluded.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();
$pdo->beginTransaction(); // tudo é revertido no final — não suja a base

try {
    // ---------------------------------------------------------------------
    // Fixture: colaborador dedicado, para não colidir com dados reais.
    // ---------------------------------------------------------------------
    $schoolId = (int)$pdo->query("SELECT id FROM schools ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,1,NOW())")
        ->execute(['ZZ Teste NC51', '00000000272']);
    $teacherId = (int)$pdo->lastInsertId();
    $date = '2026-03-11';

    // `attendance.nsr` é NOT NULL sem default — a fixture emite NSRs próprios,
    // acima do máximo existente, para não colidir com registros reais.
    $nsrSeed = (int)$pdo->query("SELECT COALESCE(MAX(nsr),0) FROM attendance")->fetchColumn() + 1000;
    $nextNsr = function () use (&$nsrSeed): int { return $nsrSeed++; };

    $insert = function (string $kind, ?string $in, ?string $out, $approved, bool $annulled = false)
                       use ($pdo, $teacherId, $schoolId, $date, $nextNsr): int {
        $st = $pdo->prepare("INSERT INTO attendance
            (teacher_id, school_id, date, check_in, check_out, approved, record_type, method, nsr)
            VALUES (?,?,?,?,?,?,?,'test',?)");
        $st->execute([$teacherId, $schoolId, $date, $in, $out, $approved, $kind, $nextNsr()]);
        $id = (int)$pdo->lastInsertId();
        if ($annulled) {
            // Mesmo efeito de helpers.admin_remove_attendance()
            $pdo->prepare("UPDATE attendance
                              SET approved = 0, superseded_by_id = id, removed_at = NOW(),
                                  removed_reason = 'teste NC-51'
                            WHERE id = ?")->execute([$id]);
        }
        return $id;
    };

    echo "[1] NC-51 — intervalo anulado não pode contar como tempo trabalhado\n";

    // Jornada: 08:00–12:00 (240 min de work aprovado)
    $insert('work', "$date 08:00:00", "$date 12:00:00", 1);
    $baseline = calculate_effective_worked_minutes($pdo, $teacherId, $date);
    check('baseline = 240 min de work', $baseline === 240, "obtido {$baseline}");

    // Intervalo VÁLIDO de 60 min deve somar (modelo "cheio vs cheio")
    $breakOk = $insert('break', "$date 12:00:00", "$date 13:00:00", 1);
    $comBreak = calculate_effective_worked_minutes($pdo, $teacherId, $date);
    check('intervalo válido soma (240 + 60 = 300)', $comBreak === 300, "obtido {$comBreak}");

    // Intervalo ANULADO de 90 min NÃO pode somar
    $insert('break', "$date 14:00:00", "$date 15:30:00", 1, true);
    $comAnulado = calculate_effective_worked_minutes($pdo, $teacherId, $date);
    check('intervalo ANULADO não soma (segue 300)', $comAnulado === 300,
          "obtido {$comAnulado} — anulado voltou a contar");

    // E nas demais funções de cálculo
    $workedOnly = calculate_worked_minutes($pdo, $teacherId, $date);
    check('calculate_worked_minutes ignora breaks e anulados', $workedOnly === 240, "obtido {$workedOnly}");

    $breakApproved = calculate_break_minutes($pdo, $teacherId, $date);
    check('calculate_break_minutes exclui o anulado (60)', $breakApproved === 60, "obtido {$breakApproved}");

    // Um WORK anulado também não pode entrar
    $insert('work', "$date 16:00:00", "$date 18:00:00", 1, true);
    $comWorkAnulado = calculate_effective_worked_minutes($pdo, $teacherId, $date);
    check('work ANULADO não soma (segue 300)', $comWorkAnulado === 300, "obtido {$comWorkAnulado}");

    echo "[2] NC-52 — registro aberto anulado não pode ser reencontrado como 'em aberto'\n";

    $openWindow = (int)(get_setting('open_checkin_window_hours', '30') ?? '30');
    $nowIn = (new DateTime('-1 hour'))->format('Y-m-d H:i:s');
    $todayStr = (new DateTime())->format('Y-m-d');

    $stOpenLookup = $pdo->prepare(
        "SELECT id FROM attendance
          WHERE teacher_id = ? AND check_in IS NOT NULL AND check_out IS NULL
            AND " . attendance_vigente_sql() . "
            AND check_in >= (NOW() - INTERVAL ? HOUR)
          ORDER BY check_in DESC LIMIT 1"
    );

    // Registro aberto ANULADO
    $stIns = $pdo->prepare("INSERT INTO attendance
        (teacher_id, school_id, date, check_in, approved, record_type, method, nsr)
        VALUES (?,?,?,?,NULL,'work','test',?)");
    $stIns->execute([$teacherId, $schoolId, $todayStr, $nowIn, $nextNsr()]);
    $openId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE attendance SET approved=0, superseded_by_id=id, removed_at=NOW(),
                       removed_reason='teste NC-52' WHERE id=?")->execute([$openId]);

    $stOpenLookup->execute([$teacherId, $openWindow]);
    $found = $stOpenLookup->fetchColumn();
    check('aberto ANULADO não é retornado', $found === false,
          $found !== false ? "retornou id {$found} — poderia ser fechado" : '');

    // Registro aberto VÁLIDO continua sendo encontrado (não quebrei o fluxo normal)
    $stIns->execute([$teacherId, $schoolId, $todayStr, $nowIn, $nextNsr()]);
    $validOpenId = (int)$pdo->lastInsertId();
    $stOpenLookup->execute([$teacherId, $openWindow]);
    $found2 = (int)$stOpenLookup->fetchColumn();
    check('aberto VÁLIDO continua sendo encontrado', $found2 === $validOpenId,
          "esperado {$validOpenId}, obtido {$found2}");

    echo "[3] Sanidade do fragmento SQL\n";
    check('attendance_vigente_sql() sem alias', attendance_vigente_sql() === '(removed_at IS NULL AND superseded_by_id IS NULL)');
    check('attendance_vigente_sql(\'a\') com alias', attendance_vigente_sql('a') === '(a.removed_at IS NULL AND a.superseded_by_id IS NULL)');

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack(); // desfaz TODA a fixture
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

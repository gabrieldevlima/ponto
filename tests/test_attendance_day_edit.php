<?php
declare(strict_types=1);
/**
 * Testa o editor de ponto do DIA INTEIRO no modelo de SEGMENTOS
 * (admin_save_attendance_day) e a validação pura (validate_attendance_day).
 *
 * Cobre: editar período (auto-aprovação + delta); adicionar/editar/remover
 * intervalo; adicionar/remover PERÍODO DE TRABALHO (limpeza de duplicata — caso
 * do print); mudar a data; validações que bloqueiam sem gravar; atomicidade;
 * NSR; multi-work; dia órfão; turno noturno (antes/cruzando/depois da meia-noite).
 *
 * Como rodar:  php tests\test_attendance_day_edit.php
 */
require_once __DIR__ . '/../config.php';

$failed = 0; $passed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "  [OK]   $label\n"; }
    else { $failed++; echo "  [FAIL] $label" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$pdo = db();
$ADMIN = 1;
$d  = '2026-06-10';
$d2 = '2026-06-11';
$CPF    = '00000000555'; // fluxo principal
$CPF_MW = '00000000557'; // multi-work
$CPF_OR = '00000000558'; // dia órfão
$CPF_NS = '00000000560'; // turno noturno
$CPF_DP = '00000000562'; // período duplicado (caso do print)
$ALL_CPF = [$CPF, $CPF_MW, $CPF_OR, $CPF_NS, $CPF_DP];

$cleanupCpf = function (string $cpf) use ($pdo) {
    foreach ($pdo->query("SELECT id FROM teachers WHERE cpf='$cpf'")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        $ids = $pdo->query("SELECT id FROM attendance WHERE teacher_id=$tid")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) { $in = implode(',', array_map('intval', $ids));
            foreach (['attendance_audit_log', 'attendance_edits'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE attendance_id IN ($in)"); } catch (Throwable $e) {} }
            try { $pdo->exec("DELETE FROM audit_logs WHERE entity='attendance' AND entity_id IN ($in)"); } catch (Throwable $e) {}
        }
        try { $pdo->prepare("DELETE FROM hour_bank_entries WHERE teacher_id=?")->execute([$tid]); } catch (Throwable $e) {}
        $pdo->prepare("DELETE FROM attendance WHERE teacher_id=?")->execute([$tid]);
    }
    $pdo->prepare("DELETE FROM teachers WHERE cpf=?")->execute([$cpf]);
};
$cleanup = function () use ($cleanupCpf, $ALL_CPF) { foreach ($ALL_CPF as $c) $cleanupCpf($c); };
$cleanup();

echo "=== Editor de ponto do dia — modelo de segmentos ===\n\n";

$nextNsr = function () use ($pdo): int {
    $cur = (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $n = $cur + 1; $pdo->prepare("UPDATE nsr_sequence SET current_nsr=? WHERE id=1")->execute([$n]); return $n;
};
$curNsr = fn() => (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
$mkTeacher = function (string $cpf, string $name) use ($pdo): int {
    $pdo->prepare("INSERT INTO teachers(name,cpf,active) VALUES(?,?,1)")->execute([$name, $cpf]);
    return (int)$pdo->lastInsertId();
};
$insWork = function (int $tid, string $ci, string $co) use ($pdo, $nextNsr) {
    $pdo->prepare("INSERT INTO attendance(teacher_id,date,check_in,check_out,method,record_type,approved,nsr) VALUES(?,?,?,?,'manual','work',NULL,?)")
        ->execute([$tid, substr($ci, 0, 10), $ci, $co, $nextNsr()]);
    return (int)$pdo->lastInsertId();
};
$insBreak = function (int $tid, string $ci, string $co, ?int $parent) use ($pdo, $nextNsr) {
    $pdo->prepare("INSERT INTO attendance(teacher_id,date,check_in,check_out,method,record_type,parent_attendance_id,approved,nsr) VALUES(?,?,?,?,'manual','break',?,NULL,?)")
        ->execute([$tid, substr($ci, 0, 10), $ci, $co, $parent, $nextNsr()]);
    return (int)$pdo->lastInsertId();
};
// Desde a Fase 3 da auditoria, editar horário/data NÃO reescreve a linha: o
// original é anulado e um novo registro passa a valer (supersede — NC-12).
// Quem guarda um id entre etapas precisa seguir `superseded_by_id` até o
// registro vigente. É o mesmo que a tela faz ao recarregar após salvar.
$vig = function (int $id) use ($pdo): int {
    for ($i = 0; $i < 10; $i++) {
        $r = $pdo->query("SELECT id, superseded_by_id, removed_at FROM attendance WHERE id=" . (int)$id)->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $id;
        $sup = (int)($r['superseded_by_id'] ?? 0);
        if (empty($r['removed_at']) || $sup <= 0 || $sup === (int)$r['id']) return (int)$r['id'];
        $id = $sup;
    }
    return $id;
};
$col   = fn($id, $c) => $pdo->query("SELECT $c FROM attendance WHERE id=" . (int)$id)->fetchColumn();
$tm    = fn($id, $c) => (($v = $pdo->query("SELECT $c FROM attendance WHERE id=" . (int)$id)->fetchColumn()) ? date('H:i', strtotime((string)$v)) : null);
$nWorks  = fn($tid) => (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE teacher_id=$tid AND (record_type='work' OR record_type IS NULL) AND removed_at IS NULL AND superseded_by_id IS NULL")->fetchColumn();
$nBreaks = fn($tid) => (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE teacher_id=$tid AND record_type='break' AND removed_at IS NULL AND superseded_by_id IS NULL")->fetchColumn();
$nRows   = fn($tid) => (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE teacher_id=$tid")->fetchColumn();

// ---------------------------------------------------------------------------
$tid = $mkTeacher($CPF, '__day_test');
$w1 = $insWork($tid, "$d 08:00:00", "$d 17:00:00");
$b1 = $insBreak($tid, "$d 12:00:00", "$d 13:00:00", $w1);

echo "[1] editar período (entrada/saída) — auto-aprovação + delta\n";
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tid, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'corrigir turno',
    'works'  => [['id' => $w1, 'start' => '07:30', 'end' => '18:00', 'remove' => false]],
    'breaks' => [['id' => $b1, 'start' => '12:00', 'end' => '13:00', 'remove' => false]],
]);
$w1 = $vig($w1);
check('entrada 07:30 / saída 18:00', $tm($w1, 'check_in') === '07:30' && $tm($w1, 'check_out') === '18:00');
check('período aprovado', (int)$col($w1, 'approved') === 1);
check('período em works.updated', ($res['works']['updated'] ?? []) === [$w1], json_encode($res['works']));
check('delta de minutos = 90', ($res['diff_minutes'] ?? null) === 90, json_encode($res['diff_minutes'] ?? null));

echo "[2] adicionar intervalo — NSR + parent + aprovado\n";
$nsrBefore = $curNsr();
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tid, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'pausa tarde',
    'works'  => [['id' => $w1, 'start' => '07:30', 'end' => '18:00', 'remove' => false]],
    'breaks' => [['id' => $b1, 'start' => '12:00', 'end' => '13:00', 'remove' => false], ['id' => 0, 'start' => '15:00', 'end' => '15:15', 'remove' => false]],
]);
$b2 = ($res['breaks']['added'][0] ?? 0);
check('1 intervalo adicionado', count($res['breaks']['added'] ?? []) === 1, json_encode($res['breaks']));
check('intervalo é break com parent = período', (string)$col($b2, 'record_type') === 'break' && (int)$col($b2, 'parent_attendance_id') === $w1);
// O emissor LEGADO avança exatamente 1 por período inserido — isso vale nos
// dois modos e é o invariante que este passo sempre quis verificar.
check('emissor legado avança exatamente +1', $curNsr() === $nsrBefore + 1,
      'de ' . $nsrBefore . ' para ' . $curNsr());

// Já `attendance.nsr` depende de quem é a autoridade de numeração:
//   livro OFF → espelha o contador legado, como sempre foi;
//   livro ON  → passa a espelhar o NSR FISCAL, e o legado vai para legacy_nsr.
// A versão anterior deste teste comparava attendance.nsr com o contador legado,
// o que só funcionava com o livro desligado — foi o modo shadow que expôs isso.
if (nsr_ledger_enabled()) {
    $nsrB2    = (int)$col($b2, 'nsr');
    $legacyB2 = (int)$col($b2, 'legacy_nsr');
    check('attendance.nsr espelha o NSR fiscal', $nsrB2 > 0);
    check('NSR legado preservado em legacy_nsr', $legacyB2 === $nsrBefore + 1,
          "legacy={$legacyB2} esperado=" . ($nsrBefore + 1));
    check('marcação consta no livro fiscal',
          (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger WHERE nsr = {$nsrB2} AND event_type='mark'")->fetchColumn() === 1);
} else {
    check('attendance.nsr = contador legado (livro desligado)',
          (int)$col($b2, 'nsr') === $nsrBefore + 1);
}

echo "[3] remover intervalo (soft-delete auditável)\n";
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tid, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'intervalo errado',
    'works'  => [['id' => $w1, 'start' => '07:30', 'end' => '18:00', 'remove' => false]],
    'breaks' => [['id' => $b1, 'start' => '12:00', 'end' => '13:00', 'remove' => true], ['id' => $b2, 'start' => '15:00', 'end' => '15:15', 'remove' => false]],
]);
check('intervalo removido', ($res['breaks']['removed'] ?? []) === [$b1]);
check('b1.removed_at + approved=0', !empty($col($b1, 'removed_at')) && (int)$col($b1, 'approved') === 0);
check('sobra 1 intervalo ativo', $nBreaks($tid) === 1);
check('auditoria admin_remove', (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='admin_remove' AND entity_id='$b1'")->fetchColumn() === 1);

echo "[4] editar intervalo existente\n";
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tid, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'ajustar pausa',
    'works'  => [['id' => $w1, 'start' => '07:30', 'end' => '18:00', 'remove' => false]],
    'breaks' => [['id' => $b2, 'start' => '15:00', 'end' => '15:30', 'remove' => false]],
]);
$b2 = $vig($b2);
check('intervalo b2 atualizado', ($res['breaks']['updated'] ?? []) === [$b2]);
check('b2.fim = 15:30', $tm($b2, 'check_out') === '15:30');

echo "[5] mudar a data — migra período + intervalo\n";
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tid, 'orig_date' => $d, 'date' => $d2, 'method' => 'manual', 'reason' => 'dia errado',
    'works'  => [['id' => $w1, 'start' => '07:30', 'end' => '18:00', 'remove' => false]],
    'breaks' => [['id' => $b2, 'start' => '15:00', 'end' => '15:30', 'remove' => false]],
]);
$w1 = $vig($w1); $b2 = $vig($b2);
check('date_changed = true', ($res['date_changed'] ?? false) === true);
check('período migrou', (string)$col($w1, 'date') === $d2);
check('intervalo migrou', (string)$col($b2, 'date') === $d2);

echo "[6] validações bloqueiam o save sem gravar\n";
$rowsBefore = $nRows($tid); $nsrFix = $curNsr();
$base = ['teacher_id' => $tid, 'orig_date' => $d2, 'date' => $d2, 'method' => 'manual', 'reason' => 'x'];
$throws = function (string $label, array $payload) use ($pdo, $ADMIN, $base) {
    $t = false; try { admin_save_attendance_day($pdo, $ADMIN, array_merge($base, $payload)); } catch (Throwable $e) { $t = true; }
    check($label, $t);
};
$throws('período com duração zero (saída = entrada)', ['works' => [['id' => $w1, 'start' => '08:00', 'end' => '08:00']], 'breaks' => []]);
$throws('períodos sobrepostos', ['works' => [['id' => $w1, 'start' => '08:00', 'end' => '12:00'], ['id' => 0, 'start' => '11:00', 'end' => '13:00']], 'breaks' => []]);
$throws('intervalo fora do período', ['works' => [['id' => $w1, 'start' => '07:30', 'end' => '18:00']], 'breaks' => [['id' => 0, 'start' => '19:00', 'end' => '19:30']]]);
$throws('intervalos sobrepostos', ['works' => [['id' => $w1, 'start' => '07:30', 'end' => '18:00']], 'breaks' => [['id' => 0, 'start' => '12:00', 'end' => '13:00'], ['id' => 0, 'start' => '12:30', 'end' => '13:30']]]);
$throws('intervalo com fim <= início', ['works' => [['id' => $w1, 'start' => '07:30', 'end' => '18:00']], 'breaks' => [['id' => 0, 'start' => '13:00', 'end' => '12:00']]]);
$throws('justificativa vazia', ['reason' => '', 'works' => [['id' => $w1, 'start' => '07:30', 'end' => '18:00']], 'breaks' => [['id' => $b2, 'start' => '15:00', 'end' => '15:30']]]);
$throws('nenhuma alteração detectada', ['works' => [['id' => $w1, 'start' => '07:30', 'end' => '18:00']], 'breaks' => [['id' => $b2, 'start' => '15:00', 'end' => '15:30']]]);
check('nenhuma linha criada/perdida', $nRows($tid) === $rowsBefore, 'antes=' . $rowsBefore . ' depois=' . $nRows($tid));
check('NSR intacto nas validações', $curNsr() === $nsrFix);

echo "[7] atomicidade — id forjado faz rollback total\n";
$rowsBefore = $nRows($tid); $nsrFix = $curNsr();
$throws('período com id forjado', ['works' => [['id' => 999999999, 'start' => '07:30', 'end' => '18:00']], 'breaks' => []]);
check('rollback: nada alterado/criado', $nRows($tid) === $rowsBefore && $curNsr() === $nsrFix);
check('rollback: transação encerrada', !$pdo->inTransaction());

echo "[8] NSR incrementa exatamente N ao inserir 2 períodos\n";
$nsrBefore = $curNsr();
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tid, 'orig_date' => $d2, 'date' => $d2, 'method' => 'manual', 'reason' => 'dois periodos',
    'works'  => [['id' => $w1, 'start' => '07:30', 'end' => '11:00', 'remove' => false], ['id' => 0, 'start' => '13:00', 'end' => '15:00', 'remove' => false], ['id' => 0, 'start' => '16:00', 'end' => '18:00', 'remove' => false]],
    'breaks' => [],
]);
$w1 = $vig($w1);
check('2 períodos adicionados', count($res['works']['added'] ?? []) === 2, json_encode($res['works']));
// +3, não +2: além dos 2 períodos adicionados, a EDIÇÃO do primeiro agora
// consome um NSR próprio — ela cria uma marcação nova em vez de reescrever
// a existente (supersede, Fase 3 / NC-12).
check('NSR avançou +3', $curNsr() === $nsrBefore + 3, 'obtido ' . ($curNsr() - $nsrBefore));

echo "[9] validate_attendance_day (pura)\n";
check('estado válido => sem erros', validate_attendance_day(['reason' => 'ok', 'date' => $d, 'works' => [['start' => "$d 08:00:00", 'end' => "$d 17:00:00"]], 'breaks' => [['start' => "$d 12:00:00", 'end' => "$d 13:00:00"]]]) === []);
check('período fim<=início => erro', count(validate_attendance_day(['reason' => 'ok', 'date' => $d, 'works' => [['start' => "$d 17:00:00", 'end' => "$d 08:00:00"]], 'breaks' => []])) > 0);

echo "[10] multi-work — editar só o 1º, preservar os demais\n";
$tidMw = $mkTeacher($CPF_MW, '__mw');
$mw1 = $insWork($tidMw, "$d 08:00:00", "$d 10:00:00");
$mw2 = $insWork($tidMw, "$d 10:30:00", "$d 12:00:00");
$mw3 = $insWork($tidMw, "$d 13:00:00", "$d 17:00:00");
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tidMw, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'corrigir 1o periodo',
    'works'  => [['id' => $mw1, 'start' => '07:30', 'end' => '10:00'], ['id' => $mw2, 'start' => '10:30', 'end' => '12:00'], ['id' => $mw3, 'start' => '13:00', 'end' => '17:00']],
    'breaks' => [],
]);
$mw1 = $vig($mw1);
check('1º período: entrada 07:30', $tm($mw1, 'check_in') === '07:30');
check('2º período inalterado (10:30-12:00)', $tm($mw2, 'check_in') === '10:30' && $tm($mw2, 'check_out') === '12:00');
check('3º período inalterado (13:00-17:00)', $tm($mw3, 'check_in') === '13:00' && $tm($mw3, 'check_out') === '17:00');
check('todos os 3 períodos aprovados', (int)$col($mw1, 'approved') === 1 && (int)$col($mw2, 'approved') === 1 && (int)$col($mw3, 'approved') === 1);

echo "[11] CASO DO PRINT — remover período de trabalho DUPLICADO\n";
$tidDp = $mkTeacher($CPF_DP, '__dup');
$dpGood = $insWork($tidDp, "$d 05:20:00", "$d 17:35:00");        // período bom
$dpBrk  = $insBreak($tidDp, "$d 11:46:00", "$d 15:01:00", $dpGood); // intervalo real
$dpDup  = $insWork($tidDp, "$d 15:01:00", "$d 05:26:00");        // período DUPLICADO/corrompido
check('antes: 2 períodos ativos', $nWorks($tidDp) === 2);
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tidDp, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'remover periodo duplicado (sync)',
    'works'  => [['id' => $dpGood, 'start' => '05:20', 'end' => '17:35', 'remove' => false], ['id' => $dpDup, 'start' => '15:01', 'end' => '05:26', 'remove' => true]],
    'breaks' => [['id' => $dpBrk, 'start' => '11:46', 'end' => '15:01', 'remove' => false]],
]);
check('período duplicado removido', ($res['works']['removed'] ?? []) === [$dpDup], json_encode($res['works']));
check('sobra 1 período bom', $nWorks($tidDp) === 1 && (int)$col($dpGood, 'approved') === 1);
check('intervalo real preservado', $nBreaks($tidDp) === 1);
// Dia limpo: consolidação não deve mais gerar intervalo inferido absurdo.
$rowsDp = $pdo->query("SELECT * FROM attendance WHERE teacher_id=$tidDp AND removed_at IS NULL AND superseded_by_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
$cons = consolidate_attendance_by_day($rowsDp)[0] ?? null;
check('consolidação limpa: 1 intervalo, total < 12h', $cons && count($cons['breaks']) === 1 && $cons['total_break_minutes'] < 720, json_encode($cons ? ['breaks' => count($cons['breaks']), 'tot' => $cons['total_break_minutes']] : null));
check('entrada 05:20 / saída 17:35', $cons && $cons['check_in'] === '05:20:00' && $cons['check_out'] === '17:35:00', json_encode($cons ? [$cons['check_in'], $cons['check_out']] : null));

echo "[12] dia órfão (só intervalo) — adicionar período\n";
$tidOr = $mkTeacher($CPF_OR, '__orphan');
$orB = $insBreak($tidOr, "$d 12:00:00", "$d 13:00:00", null);
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tidOr, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'incluir turno ausente',
    'works'  => [['id' => 0, 'start' => '08:00', 'end' => '17:00', 'remove' => false]],
    'breaks' => [['id' => $orB, 'start' => '12:00', 'end' => '13:00', 'remove' => false]],
]);
check('período criado no dia órfão', count($res['works']['added'] ?? []) === 1 && $nWorks($tidOr) === 1);
check('intervalo re-parentado ao novo período', (int)$col($orB, 'parent_attendance_id') === ($res['works']['added'][0] ?? -1));

echo "[13] turno noturno — intervalos antes/cruzando/depois da meia-noite\n";
$tidNs = $mkTeacher($CPF_NS, '__ns');
$nw = $insWork($tidNs, "$d 22:00:00", "$d2 02:00:00");
$res = admin_save_attendance_day($pdo, $ADMIN, [
    'teacher_id' => $tidNs, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'pausas noturnas',
    'works'  => [['id' => $nw, 'start' => '22:00', 'end' => '02:00', 'remove' => false]],
    'breaks' => [['id' => 0, 'start' => '23:00', 'end' => '23:30'], ['id' => 0, 'start' => '23:45', 'end' => '00:15'], ['id' => 0, 'start' => '00:30', 'end' => '01:00']],
]);
check('período noturno mantém 22:00 → 02:00 (dia seguinte)', $col($nw, 'check_in') === "$d 22:00:00" && $col($nw, 'check_out') === "$d2 02:00:00", json_encode([$col($nw, 'check_in'), $col($nw, 'check_out')]));
check('3 intervalos aceitos', count($res['breaks']['added'] ?? []) === 3, json_encode($res['breaks']));
$bsNs = $pdo->query("SELECT check_in, check_out FROM attendance WHERE teacher_id=$tidNs AND record_type='break' AND removed_at IS NULL ORDER BY check_in")->fetchAll(PDO::FETCH_ASSOC);
$findNs = function (string $hm) use ($bsNs) { foreach ($bsNs as $b) if (substr((string)$b['check_in'], 11, 5) === $hm) return $b; return null; };
$pre = $findNs('23:00'); $cross = $findNs('23:45'); $pos = $findNs('00:30');
check('23:00 no dia da entrada', $pre && substr($pre['check_in'], 0, 10) === $d);
check('23:45 → 00:15 cruza a meia-noite', $cross && substr($cross['check_in'], 0, 10) === $d && substr($cross['check_out'], 0, 10) === $d2);
check('00:30 ancorado no dia seguinte', $pos && substr($pos['check_in'], 0, 10) === $d2);
$tNs = false;
try {
    admin_save_attendance_day($pdo, $ADMIN, [
        'teacher_id' => $tidNs, 'orig_date' => $d, 'date' => $d, 'method' => 'manual', 'reason' => 'apos saida',
        'works' => [['id' => $nw, 'start' => '22:00', 'end' => '02:00']], 'breaks' => [['id' => 0, 'start' => '04:00', 'end' => '05:00']],
    ]);
} catch (Throwable $e) { $tNs = true; }
check('intervalo depois da saída noturna é bloqueado', $tNs);

$cleanup();
echo "\n=== Resultado ===\nPassed: $passed\nFailed: $failed\n";
exit($failed > 0 ? 1 : 0);

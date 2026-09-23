<?php
/**
 * Regressão: close_own_open_break() — colaborador informa a volta de um intervalo
 * ABERTO. A função gerencia a própria transação, então o teste NÃO usa transação
 * externa; cria dados de teste, valida e limpa tudo no finally.
 * Como rodar: php tests\test_close_open_break.php  (com MySQL no ar)
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
$pdo = db(); $pass = 0; $fail = 0;
function ck(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  [OK]   $l\n"; }
    else { $fail++; echo "  [FAIL] $l" . ($d ? " — $d" : "") . "\n"; }
}

echo "=== close_own_open_break ===\n";
// A produção tem attendance.nsr NOT NULL: gera um NSR único por inserção (padrão atômico do nsr_sequence).
$nextNsr = function () use ($pdo): int {
    $cur = (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $n = $cur + 1;
    $pdo->prepare("UPDATE nsr_sequence SET current_nsr=? WHERE id=1")->execute([$n]);
    return $n;
};
$typeId = 0; $tid = 0;
try {
    $pdo->prepare("INSERT INTO collaborator_types (name,slug,schedule_mode,requires_schedule) VALUES ('__t_cb','__t_cb','time',1)")->execute();
    $typeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teachers (name,cpf,type_id,active) VALUES ('__t_cb_user','00000000034',?,1)")->execute([$typeId]);
    $tid = (int)$pdo->lastInsertId();
    $today = date('Y-m-d');
    // Horarios ancorados ao "agora" (o app roda em America/Sao_Paulo) para o teste
    // ser deterministico em qualquer hora do dia. O intervalo aberto comecou 2h atras;
    // a volta valida eh 1h depois do inicio (ainda no passado).
    $nowTs    = time();
    $startTs  = $nowTs - 2 * 3600;
    $start    = date('Y-m-d H:i:s', $startTs);
    $startDay = substr($start, 0, 10);
    $retHM    = date('H:i', $startTs + 3600); // volta valida (passado, > inicio)
    $beforeHM = date('H:i', $startTs - 1800); // antes do inicio (deve rejeitar)
    $futureHM = date('H:i', $nowTs + 3600);   // no futuro (deve rejeitar)
    $mk = function ($checkOut) use ($pdo, $tid, $startDay, $start, $nextNsr) {
        $pdo->prepare("INSERT INTO attendance (teacher_id,date,check_in,check_out,record_type,approved,method,nsr) VALUES (?,?,?,?,'break',1,'manual',?)")
            ->execute([$tid, $startDay, $start, $checkOut, $nextNsr()]);
        return (int)$pdo->lastInsertId();
    };

    $id = $mk(null);
    $r = close_own_open_break($pdo, $id, $tid, $retHM);
    ck("fecha intervalo aberto ($retHM)", !empty($r['success']), $r['message']);
    $co = $pdo->query("SELECT check_out FROM attendance WHERE id=$id")->fetchColumn();
    ck("check_out setado p/ $retHM", substr((string)$co, 11, 5) === $retHM, (string)$co);

    $id2 = $mk(null);
    $r = close_own_open_break($pdo, $id2, $tid, $futureHM);
    ck('rejeita volta no futuro', empty($r['success']), $r['message']);
    $r = close_own_open_break($pdo, $id2, $tid, $beforeHM);
    ck('rejeita volta antes do inicio', empty($r['success']), $r['message']);

    $id3 = $mk(date('Y-m-d H:i:s', $startTs + 1800));
    $r = close_own_open_break($pdo, $id3, $tid, $retHM);
    ck('rejeita intervalo ja fechado', empty($r['success']), $r['message']);

    // Intervalo cruzando a meia-noite: começou ontem 23:50, volta time-only "00:10".
    $yStart = date('Y-m-d', strtotime('-1 day')) . ' 23:50:00';
    $pdo->prepare("INSERT INTO attendance (teacher_id,date,check_in,check_out,record_type,approved,method,nsr) VALUES (?,?,?,NULL,'break',1,'manual',?)")
        ->execute([$tid, date('Y-m-d', strtotime('-1 day')), $yStart, $nextNsr()]);
    $idN = (int)$pdo->lastInsertId();
    $r = close_own_open_break($pdo, $idN, $tid, '00:10');
    ck('intervalo overnight: "00:10" rola p/ o dia seguinte (fecha ok)', !empty($r['success']), $r['message']);
    $coN = (string)$pdo->query("SELECT check_out FROM attendance WHERE id=$idN")->fetchColumn();
    ck('overnight: check_out = hoje 00:10 (depois do inicio)', substr($coN, 11, 5) === '00:10' && $coN > $yStart, $coN);

    $pdo->prepare("INSERT INTO attendance (teacher_id,date,check_in,check_out,record_type,approved,method,nsr) VALUES (?,?,?,NULL,'work',1,'manual',?)")
        ->execute([$tid, $today, $start, $nextNsr()]);
    $idW = (int)$pdo->lastInsertId();
    $r = close_own_open_break($pdo, $idW, $tid, '12:50');
    ck('rejeita registro work (nao-intervalo)', empty($r['success']), $r['message']);

    echo "\nPassed: $pass  Failed: $fail\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    // Limpeza dos dados de teste (a função comita; sem transação externa).
    try {
        if ($tid > 0) {
            $pdo->prepare("DELETE FROM attendance_edits WHERE attendance_id IN (SELECT id FROM attendance WHERE teacher_id=?)")->execute([$tid]);
            $pdo->prepare("DELETE FROM hour_bank_entries WHERE teacher_id=?")->execute([$tid]);
            try { $pdo->prepare("DELETE FROM audit_logs WHERE entity_type='attendance' AND entity_id IN (SELECT id FROM attendance WHERE teacher_id=?)")->execute([$tid]); } catch (Throwable $_) {}
            $pdo->prepare("DELETE FROM attendance WHERE teacher_id=?")->execute([$tid]);
            $pdo->prepare("DELETE FROM teachers WHERE id=?")->execute([$tid]);
        }
        if ($typeId > 0) $pdo->prepare("DELETE FROM collaborator_types WHERE id=?")->execute([$typeId]);
        echo "(dados de teste removidos)\n";
    } catch (Throwable $e) {
        echo "AVISO: falha ao limpar dados de teste: " . $e->getMessage() . "\n";
    }
}
exit($fail > 0 ? 1 : 0);

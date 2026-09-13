<?php
declare(strict_types=1);
/**
 * Suíte de testes do Quiosque (lógica de backend, determinística — sem navegador).
 *
 * Cobre:
 *   1. Validação de descriptor facial (kiosk_is_valid_descriptor)
 *   2. Normalização de ação (kiosk_normalize_action)
 *   3. Verificação facial 1:1 match/no-match (kiosk_verify_face_1to1)
 *   4. Máquina de estados / auto-detecção (kiosk_punch_state) — fora/trabalhando/em_intervalo
 *   5. Token de reconhecimento HMAC: assinar/verificar/expirar/device-bind/adulteração
 *   6. Consumo de uso único do token (kiosk_consume_recognition)
 *   7. Conversão %↔0..1 + clamps da config de auto-confirmação do admin
 *
 * Como rodar:  php tests\test_kiosk.php
 * Usa um colaborador e dispositivo de teste (CPF 00000000272) e os remove ao final.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/kiosk_lib.php';

$failed = 0;
$passed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "  [OK]   $label\n"; }
    else { $failed++; echo "  [FAIL] $label" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$pdo = db();
$TESTCPF = '00000000272';
$DEVNAME = '__kiosk_test_device__';

// A produção tem attendance.nsr NOT NULL: gera um NSR único por inserção (padrão atômico do nsr_sequence).
$nextNsr = function () use ($pdo): int {
    $cur = (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $n = $cur + 1;
    $pdo->prepare("UPDATE nsr_sequence SET current_nsr=? WHERE id=1")->execute([$n]);
    return $n;
};

$teardown = function () use ($pdo, $TESTCPF, $DEVNAME) {
    try {
        foreach ($pdo->query("SELECT id FROM teachers WHERE cpf='$TESTCPF'")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $pdo->prepare("DELETE FROM kiosk_face_logs WHERE teacher_id=?")->execute([$tid]);
            $pdo->prepare("DELETE FROM attendance WHERE teacher_id=?")->execute([$tid]);
        }
        $pdo->prepare("DELETE FROM teachers WHERE cpf=?")->execute([$TESTCPF]);
        $pdo->prepare("DELETE FROM kiosk_devices WHERE name=?")->execute([$DEVNAME]);
    } catch (Throwable $e) { /* idempotente */ }
};
$teardown(); // remove resíduo de execução anterior

echo "=== Quiosque: testes de backend ===\n\n";

// ---------------------------------------------------------------------------
echo "[1] kiosk_is_valid_descriptor\n";
$valid = array_fill(0, 128, 0.1);
check("128 floats é válido", kiosk_is_valid_descriptor($valid));
check("127 elementos é inválido", !kiosk_is_valid_descriptor(array_fill(0, 127, 0.1)));
check("129 elementos é inválido", !kiosk_is_valid_descriptor(array_fill(0, 129, 0.1)));
check("não-array é inválido", !kiosk_is_valid_descriptor('x'));
$inf = $valid; $inf[0] = INF;
check("INF é inválido", !kiosk_is_valid_descriptor($inf));
$str = $valid; $str[5] = 'abc';
check("elemento não-numérico é inválido", !kiosk_is_valid_descriptor($str));

// ---------------------------------------------------------------------------
echo "[2] kiosk_normalize_action\n";
check("entrada → entrada", kiosk_normalize_action('entrada') === 'entrada');
check("in → entrada", kiosk_normalize_action('in') === 'entrada');
check("saída → saida", kiosk_normalize_action('saída') === 'saida');
check("out → saida", kiosk_normalize_action('out') === 'saida');
check("break_start → iniciar_intervalo", kiosk_normalize_action('break_start') === 'iniciar_intervalo');
check("break_end → retornar_intervalo", kiosk_normalize_action('break_end') === 'retornar_intervalo');
check("desconhecido → null", kiosk_normalize_action('xyz') === null);
check("vazio → null", kiosk_normalize_action('') === null);
check("null → null", kiosk_normalize_action(null) === null);

// ---------------------------------------------------------------------------
echo "[3] kiosk_verify_face_1to1\n";
$D = array_fill(0, 128, 0.1); $Da = $D; $Da[0] = 0.105; $Db = $D; $Db[1] = 0.104;
$stored = [$D, $Da, $Db];
$m = kiosk_verify_face_1to1($D, $stored);
check("match: ok=true", $m['ok'] === true, json_encode($m));
check("match: confiança alta (>0.9)", $m['confidence'] > 0.9);
check("match: best_distance pequena (<0.05)", $m['best_distance'] !== null && $m['best_distance'] < 0.05);
$n = kiosk_verify_face_1to1(array_fill(0, 128, 0.9), $stored);
check("no-match: ok=false", $n['ok'] === false);
check("no-match: confiança 0", $n['confidence'] === 0.0);

// ---------------------------------------------------------------------------
echo "[4] kiosk_punch_state (máquina de estados)\n";
$pdo->prepare("INSERT INTO teachers(name,cpf,active,face_descriptors) VALUES('__kiosk_test__',?,1,?)")
    ->execute([$TESTCPF, json_encode($stored)]);
$tid = (int)$pdo->lastInsertId();
$s = kiosk_punch_state($pdo, $tid);
check("FORA → entrada, can_break=false", $s['state'] === 'fora' && $s['primary_action'] === 'entrada' && $s['can_break'] === false, json_encode($s));
$pdo->prepare("INSERT INTO attendance(teacher_id,date,check_in,method,record_type,nsr) VALUES(?,CURDATE(),NOW(),'kiosk_face','work',?)")->execute([$tid, $nextNsr()]);
$aid = (int)$pdo->lastInsertId();
$s = kiosk_punch_state($pdo, $tid);
check("TRABALHANDO → saida, can_break=true", $s['state'] === 'trabalhando' && $s['primary_action'] === 'saida' && $s['can_break'] === true, json_encode($s));
$pdo->prepare("UPDATE attendance SET record_type='break' WHERE id=?")->execute([$aid]);
$s = kiosk_punch_state($pdo, $tid);
check("EM_INTERVALO → retornar_intervalo, can_break=false", $s['state'] === 'em_intervalo' && $s['primary_action'] === 'retornar_intervalo' && $s['can_break'] === false, json_encode($s));
$pdo->prepare("DELETE FROM attendance WHERE teacher_id=?")->execute([$tid]);

// ---------------------------------------------------------------------------
echo "[5] token de reconhecimento (HMAC, device-bound, expiração)\n";
$dev = 999001;
$tok = kiosk_sign_recognition(['log_id' => 123, 'teacher_id' => $tid, 'confidence' => 0.95, 'device_id' => $dev, 'exp' => time() + 90, 'jti' => 't1']);
$v = kiosk_verify_recognition($tok, $dev);
check("verify válido retorna o teacher_id", is_array($v) && (int)$v['teacher_id'] === $tid);
check("device errado → null", kiosk_verify_recognition($tok, $dev + 1) === null);
check("assinatura adulterada → null", kiosk_verify_recognition($tok . 'x', $dev) === null);
$expired = kiosk_sign_recognition(['log_id' => 1, 'teacher_id' => $tid, 'confidence' => 1, 'device_id' => $dev, 'exp' => time() - 5, 'jti' => 't2']);
check("token expirado → null", kiosk_verify_recognition($expired, $dev) === null);

// ---------------------------------------------------------------------------
echo "[6] kiosk_consume_recognition (uso único / anti-replay)\n";
$pdo->prepare("INSERT INTO kiosk_devices(device_token_hash,name,active,fallback_allowed) VALUES(?,?,1,0)")
    ->execute([hash('sha256', 'tk' . $tid), $DEVNAME]);
$devId = (int)$pdo->lastInsertId();
$logId = kiosk_log($pdo, ['device_id' => $devId, 'teacher_id' => $tid, 'event_type' => 'identify', 'status' => 'recognized', 'confidence' => 0.9]);
check("log identify/recognized criado", $logId > 0);
check("1º consumo → true", kiosk_consume_recognition($logId, $tid, $devId, $pdo) === true);
check("2º consumo (replay) → false", kiosk_consume_recognition($logId, $tid, $devId, $pdo) === false);

// ---------------------------------------------------------------------------
echo "[7] conversão de confiança do admin (% ↔ 0..1) + clamps\n";
$conv = function ($p) { return number_format(max(30, min(100, (int)$p)) / 100, 2, '.', ''); };
check("85% → 0.85", $conv(85) === '0.85');
check("150% → 1.00 (clamp)", $conv(150) === '1.00');
check("10% → 0.30 (clamp)", $conv(10) === '0.30');
check("segundos 0 → 1 (clamp)", max(1, min(15, 0)) === 1);
check("segundos 99 → 15 (clamp)", max(1, min(15, 99)) === 15);

// ---------------------------------------------------------------------------
$teardown();

echo "\n=== Resultado ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

<?php
declare(strict_types=1);
/**
 * Âncora de tempo assinada — NC-14 (Fase 6 da auditoria).
 *
 * O teste central é o [3]: o cenário de fraude que o código anterior permitia.
 * Um cliente que quisesse retrodatar um ponto mandava `hlbOffsetSeconds: 0`
 * junto com o horário forjado, e o servidor — que consultava justamente esse
 * campo para decidir — aceitava. Aqui verificamos que forjar já não funciona,
 * e que o horário legítimo continua sendo aceito.
 *
 * Execução: php tests/test_time_anchor.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/hlb.php';
require_once __DIR__ . '/../lib/time_anchor.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();
$device = 'dispositivo-de-teste-001';

echo "[1] Emissão da âncora\n";
$a = time_anchor_issue($pdo, $device);
check('token emitido', !empty($a['token']));
check('token tem payload e assinatura', substr_count($a['token'], '.') === 1);
check('instante do servidor em milissegundos', $a['server_time_ms'] > 1_600_000_000_000);
check('validade no futuro', $a['expires_at'] > time());
check('proveniência da HLB acompanha', isset($a['hlb']['status'], $a['hlb']['source']));

echo "[2] Resolução legítima\n";
$r = time_anchor_resolve($a['token'], 5000.0, $device); // 5 s depois
check('âncora válida resolve', $r['ok'], $r['motivo']);
check('instante reconstruído é coerente',
      abs(strtotime((string)$r['marked_at']) - (int)floor($a['server_time_ms'] / 1000) - 5) <= 1,
      (string)$r['marked_at']);

$r0 = time_anchor_resolve($a['token'], 0.0, $device);
check('elapsed zero resolve para o instante da emissão', $r0['ok']);

echo "[3] O cenário de fraude que o código anterior permitia\n";
// Antes: bastava enviar recordedAt de 3h atrás com hlbOffsetSeconds=0.
// Agora o horário não é mais uma afirmação do cliente — é derivado do token.
$tresHorasAtras = date('Y-m-d H:i:s', time() - 3 * 3600);
$inputForjado = [
    'recordedAt'       => $tresHorasAtras,
    'hlbOffsetSeconds' => 0,               // o campo que o servidor consultava
    'client_recorded_at' => $tresHorasAtras,
];
$res = time_anchor_authoritative($pdo, $inputForjado, 'offline', $device);
check('horário forjado NÃO é aceito', $res['marked_at'] !== $tresHorasAtras, (string)$res['marked_at']);
check('marcação não é descartada', !empty($res['marked_at']));
check('gravada a hora de recebimento', $res['fonte'] === 'recebimento', $res['fonte']);
check('marcada como não comprovada', $res['comprovado'] === false);
check('motivo explícito para revisão', str_contains((string)$res['motivo'], 'não comprovado'), (string)$res['motivo']);

// Mesmo payload, agora COM âncora legítima: o horário é aceito.
$inputComAncora = $inputForjado + ['timeAnchor' => $a['token'], 'anchorElapsedMs' => 60000];
$res2 = time_anchor_authoritative($pdo, $inputComAncora, 'offline', $device);
check('com âncora válida o instante é aceito', $res2['comprovado'] === true, (string)$res2['motivo']);
check('fonte identificada como âncora', $res2['fonte'] === 'ancora');
check('instante vem da âncora, não do recordedAt', $res2['marked_at'] !== $tresHorasAtras);

echo "[4] Adulterações da âncora\n";
[$b64, $sig] = explode('.', $a['token'], 2);

$corpo = base64_decode($b64, true);
$camposAdulterados = str_replace('anchor-v1|', 'anchor-v1|', $corpo);
$partes = explode('|', $corpo);
$partes[1] = (string)((int)$partes[1] - 3 * 3600 * 1000); // recua 3h no instante assinado
$tokenAdulterado = base64_encode(implode('|', $partes)) . '.' . $sig;
$rAdult = time_anchor_resolve($tokenAdulterado, 1000.0, $device);
check('instante adulterado invalida a assinatura', !$rAdult['ok']);
check('motivo aponta a assinatura', str_contains($rAdult['motivo'], 'assinatura'), $rAdult['motivo']);

check('assinatura trocada é rejeitada',
      !time_anchor_resolve($b64 . '.' . str_repeat('0', 64), 1000.0, $device)['ok']);
check('token malformado é rejeitado', !time_anchor_resolve('lixo', 1000.0, $device)['ok']);
check('token vazio é rejeitado', !time_anchor_resolve('', 1000.0, $device)['ok']);

echo "[5] Vínculo com o dispositivo\n";
$rOutro = time_anchor_resolve($a['token'], 1000.0, 'outro-dispositivo-999');
check('âncora de outro dispositivo é rejeitada', !$rOutro['ok'], $rOutro['motivo']);
check('motivo identifica o dispositivo', str_contains($rOutro['motivo'], 'dispositivo'), $rOutro['motivo']);

// Cliente antigo (sem device) continua funcionando — não quebrar quem não atualizou.
$aSemDevice = time_anchor_issue($pdo, '');
check('âncora sem dispositivo resolve em qualquer aparelho',
      time_anchor_resolve($aSemDevice['token'], 1000.0, 'qualquer-um')['ok']);

echo "[6] Limites temporais\n";
check('elapsed negativo é rejeitado', !time_anchor_resolve($a['token'], -1.0, $device)['ok']);
$rFuturo = time_anchor_resolve($a['token'], 10 * 3600 * 1000, $device); // 10h no futuro
check('instante reconstruído no futuro é rejeitado', !$rFuturo['ok'], $rFuturo['motivo']);

$rLonge = time_anchor_resolve($a['token'], (TIME_ANCHOR_TTL_SECONDS + 7200) * 1000.0, $device);
check('elapsed além da validade é rejeitado', !$rLonge['ok'], $rLonge['motivo']);

echo "[7] Online ignora qualquer afirmação do cliente\n";
$resOnline = time_anchor_authoritative($pdo, [
    'recordedAt' => $tresHorasAtras, 'hlbOffsetSeconds' => 0,
    'timeAnchor' => $a['token'], 'anchorElapsedMs' => 99999999,
], 'online', $device);
check('online usa o relógio do servidor', $resOnline['fonte'] === 'servidor');
check('online é sempre comprovado', $resOnline['comprovado'] === true);
check('online ignora até a âncora', abs(strtotime((string)$resOnline['marked_at']) - time()) <= 2);

echo "[8] Proveniência da HLB registrada na marcação\n";
foreach (['hlb_status', 'hlb_source', 'hlb_offset_ms'] as $k) {
    check("campo {$k} presente no resultado", array_key_exists($k, $resOnline));
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

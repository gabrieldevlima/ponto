#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Backfill do livro fiscal a partir do histórico de `attendance`.
 * ==============================================================
 * Fase 1.9 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * Uso:
 *   php bin/ledger_backfill.php --dry-run     # relatório, não escreve nada
 *   php bin/ledger_backfill.php --commit      # executa
 *
 * O QUE FAZ
 * Cada linha de `attendance` guarda entrada E saída e consumia UM NSR (NC-09).
 * O backfill decompõe o histórico em MARCAÇÕES individuais, ordena
 * cronologicamente e emite um NSR próprio para cada uma, assinando a cadeia.
 *
 * RENUMERAÇÃO CRONOLÓGICA — e por que não reaproveitar o NSR antigo
 * A alternativa seria manter o NSR original na entrada e emitir um novo para a
 * saída. Isso quebraria a monotonia da cadeia: uma saída de abril receberia um
 * NSR alto e apareceria, na ordem do livro, depois de uma entrada de maio com
 * NSR baixo. Um auditor lendo o AFD notaria de imediato.
 * Por isso renumera-se tudo por ordem de acontecimento. O NSR anterior não se
 * perde: vai para `nsr_ledger.legacy_nsr` e `attendance.legacy_nsr`, e
 * `receipt.php` passa a resolver comprovantes antigos por qualquer um dos dois.
 *
 * ANULAÇÕES viram evento `void` no livro, posicionado pelo instante em que
 * ocorreram (`removed_at`) — o livro conta a história como ela foi, não como
 * ficou depois.
 *
 * IDEMPOTÊNCIA: recusa-se a rodar se o livro já tiver marcações. Como
 * `nsr_ledger` é imutável (não há DELETE), um backfill duplicado seria
 * irreversível.
 */

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando (CLI)\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/nsr_ledger.php';

$args   = $argv ?? [];
$commit = in_array('--commit', $args, true);
$dryRun = !$commit;

echo "=====================================================================\n";
echo " Backfill do livro fiscal — " . ($dryRun ? 'SIMULAÇÃO (--dry-run)' : 'EXECUÇÃO (--commit)') . "\n";
echo "=====================================================================\n\n";

$pdo = db();

try {
    ledger_hmac_key();
} catch (Throwable $e) {
    echo "ERRO: {$e->getMessage()}\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Guarda de idempotência
// ---------------------------------------------------------------------------
$existing = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger WHERE event_type <> 'chain_genesis'")->fetchColumn();
if ($existing > 0) {
    echo "ABORTADO: o livro fiscal já contém {$existing} evento(s).\n";
    echo "O backfill só pode rodar sobre um livro vazio — nsr_ledger é imutável\n";
    echo "e um backfill duplicado não teria como ser desfeito.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Pré-voo (as mesmas checagens da Fase 1.8, aqui como porta de entrada)
// ---------------------------------------------------------------------------
echo "--- Pré-voo ---\n";

$dups = $pdo->query("SELECT nsr, COUNT(*) c FROM attendance GROUP BY nsr HAVING c > 1")->fetchAll(PDO::FETCH_ASSOC);
if ($dups) {
    echo "ABORTADO: " . count($dups) . " NSR duplicado(s) em attendance.\n";
    foreach (array_slice($dups, 0, 10) as $d) echo "  nsr={$d['nsr']} aparece {$d['c']}x\n";
    echo "\nA coluna attendance.nsr não tem UNIQUE em produção (NC-10) e houve\n";
    echo "período em que colisão era possível. Isso precisa ser resolvido e\n";
    echo "documentado ANTES do backfill — é um defeito anterior a esta migração.\n";
    exit(1);
}
echo "  NSR duplicados em attendance : nenhum\n";

$semNsr = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE nsr IS NULL OR nsr = 0")->fetchColumn();
echo "  Linhas sem NSR               : {$semNsr}" . ($semNsr ? "  (serão importadas mesmo assim)" : '') . "\n";

$vazias = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE check_in IS NULL AND check_out IS NULL")->fetchColumn();
echo "  Linhas sem entrada e sem saída: {$vazias}" . ($vazias ? "  (ignoradas)" : '') . "\n";

$invertidas = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE check_in IS NOT NULL AND check_out IS NOT NULL AND check_out < check_in")->fetchColumn();
echo "  Saída anterior à entrada     : {$invertidas}" . ($invertidas ? "  (importadas como estão; o livro registra o que houve)" : '') . "\n";

// ---------------------------------------------------------------------------
// Montagem da lista de eventos
// ---------------------------------------------------------------------------
echo "\n--- Montando eventos ---\n";

$st = $pdo->query("
    SELECT a.id, a.nsr, a.teacher_id, a.school_id, a.date, a.check_in, a.check_out,
           a.record_type, a.method, a.record_mode, a.recorded_at, a.client_recorded_at,
           a.synced_at, a.ip, a.device_identifier, a.device_fingerprint, a.photo,
           a.check_in_lat, a.check_in_lng, a.check_in_acc,
           a.check_out_lat, a.check_out_lng, a.check_out_acc,
           a.removed_at, a.removed_reason, a.removed_by_admin_id,
           a.superseded_by_id, a.manual_by_admin_id,
           t.cpf AS teacher_cpf, t.pis AS teacher_pis
      FROM attendance a
      LEFT JOIN teachers t ON t.id = a.teacher_id
     ORDER BY a.id ASC
");

$events = [];
$linhas = 0;
$anulacoes = 0;

while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $linhas++;
    $kind = $r['record_type'] ?: 'work';

    // A origem real não é recuperável do histórico com precisão; `import_legacy`
    // é honesto sobre isso. Inventar 'device'/'kiosk' seria afirmar mais do que
    // se sabe, num livro que existe justamente para ser fiel.
    $base = [
        'teacher_id'         => $r['teacher_id'] !== null ? (int)$r['teacher_id'] : null,
        'teacher_cpf'        => $r['teacher_cpf'] ?: null,
        'teacher_pis'        => $r['teacher_pis'] ?: null,
        'school_id'          => $r['school_id'] !== null ? (int)$r['school_id'] : null,
        'attendance_id'      => (int)$r['id'],
        'record_type'        => $kind,
        'work_date'          => $r['date'],
        'origin'             => 'import_legacy',
        'record_mode'        => $r['record_mode'] ?: 'online',
        'method'             => $r['method'],
        'recorded_at'        => $r['recorded_at'],
        'client_recorded_at' => $r['client_recorded_at'],
        'synced_at'          => $r['synced_at'],
        'ip'                 => $r['ip'],
        'device_identifier'  => $r['device_identifier'],
        'device_fingerprint' => $r['device_fingerprint'],
        'hlb_status'         => 'unknown',
        'legacy_nsr'         => $r['nsr'] !== null ? (int)$r['nsr'] : null,
    ];

    if (!empty($r['check_in'])) {
        $events[] = ['_sort' => $r['check_in'], '_role' => 'in'] + $base + [
            'mark_role' => 'in', 'direction' => 'E', 'marked_at' => $r['check_in'],
            'lat' => $r['check_in_lat'], 'lng' => $r['check_in_lng'], 'acc' => $r['check_in_acc'],
            'photo' => $r['photo'],
        ];
    }
    if (!empty($r['check_out'])) {
        $events[] = ['_sort' => $r['check_out'], '_role' => 'out'] + $base + [
            'mark_role' => 'out', 'direction' => 'S', 'marked_at' => $r['check_out'],
            'lat' => $r['check_out_lat'], 'lng' => $r['check_out_lng'], 'acc' => $r['check_out_acc'],
        ];
    }

    if (!empty($r['removed_at'])) {
        $anulacoes++;
        $events[] = [
            '_sort' => $r['removed_at'], '_role' => 'void',
            'event_type'    => 'void',
            'teacher_id'    => $base['teacher_id'],
            'attendance_id' => (int)$r['id'],
            'admin_id'      => $r['removed_by_admin_id'] !== null ? (int)$r['removed_by_admin_id'] : null,
            'reason'        => $r['removed_reason'] ?: 'anulado (motivo nao registrado no historico)',
            'origin'        => 'import_legacy',
            'marked_at'     => $r['removed_at'],
            'hlb_status'    => 'unknown',
        ];
    }
}

// Ordenação cronológica estável: pelo instante e, em empate, entrada antes de
// saída (uma marcação de entrada nunca deve receber NSR maior que a saída do
// mesmo par quando ambas caem no mesmo segundo).
$rolePeso = ['in' => 0, 'out' => 1, 'void' => 2];
usort($events, function ($a, $b) use ($rolePeso) {
    $c = strcmp((string)$a['_sort'], (string)$b['_sort']);
    if ($c !== 0) return $c;
    $c = $rolePeso[$a['_role']] <=> $rolePeso[$b['_role']];
    if ($c !== 0) return $c;
    return ((int)($a['attendance_id'] ?? 0)) <=> ((int)($b['attendance_id'] ?? 0));
});

$marcacoes = count(array_filter($events, fn($e) => ($e['_role'] ?? '') !== 'void'));

echo "  Linhas de attendance lidas   : {$linhas}\n";
echo "  Marcações individuais        : {$marcacoes}\n";
echo "  Eventos de anulação (void)   : {$anulacoes}\n";
echo "  Total de eventos no livro    : " . count($events) . "\n";

if ($events) {
    echo "  Primeiro evento              : {$events[0]['_sort']}\n";
    echo "  Último evento                : {$events[count($events) - 1]['_sort']}\n";
}

$maxLegacy = (int)$pdo->query("SELECT COALESCE(MAX(nsr),0) FROM attendance")->fetchColumn();
echo "  Maior NSR legado             : {$maxLegacy}\n";
echo "  NSR final após renumeração   : " . count($events) . "\n";

if (count($events) <= $maxLegacy) {
    echo "\n  ATENÇÃO: o NSR final (" . count($events) . ") não supera o maior NSR legado ({$maxLegacy}).\n";
    echo "  Comprovantes já emitidos citam NSRs antigos que podem colidir com os\n";
    echo "  novos. A resolução por legacy_nsr em receipt.php cobre a leitura, mas\n";
    echo "  vale conferir antes de prosseguir.\n";
}

if ($dryRun) {
    echo "\n--- SIMULAÇÃO: nada foi gravado ---\n";
    echo "Para executar de verdade: php bin/ledger_backfill.php --commit\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Execução
// ---------------------------------------------------------------------------
echo "\n--- Gravando ---\n";

$pdo->beginTransaction();
try {
    // Zera a sequência EXCLUSIVA do livro para que a renumeração comece em 1
    // (a gênese ocupa o 0). `current_nsr`, do emissor legado, não é tocado.
    $pdo->prepare("UPDATE nsr_sequence SET ledger_current_nsr = 0, last_record_hash = NULL,
                       chain_key_id = NULL, chain_started_at = NULL WHERE id = 1")->execute();

    $stMirrorIn  = $pdo->prepare("UPDATE attendance SET nsr = ?, legacy_nsr = COALESCE(legacy_nsr, ?) WHERE id = ?");
    $stMirrorOut = $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?");

    $n = 0;
    foreach ($events as $e) {
        $role   = $e['_role'];
        $legacy = $e['legacy_nsr'] ?? null;
        unset($e['_sort'], $e['_role']);

        $res = nsr_ledger_append($pdo, $e);
        $n++;

        // Espelha nas colunas de attendance — é o que mantém receipt.php,
        // v_attendance_receipts e as ~210 leituras existentes funcionando.
        if ($role === 'in') {
            $stMirrorIn->execute([$res['nsr'], $legacy, (int)$e['attendance_id']]);
        } elseif ($role === 'out') {
            $stMirrorOut->execute([$res['nsr'], (int)$e['attendance_id']]);
        }

        if ($n % 500 === 0) echo "  {$n} eventos...\n";
    }

    // Registra a própria migração no livro — é o documento técnico da renumeração.
    $digest = hash('sha256', implode('|', array_map(
        fn($e) => ($e['attendance_id'] ?? '') . ':' . ($e['marked_at'] ?? ''), $events)));
    nsr_ledger_append($pdo, [
        'event_type' => 'system_migration',
        'origin'     => 'system',
        'marked_at'  => date('Y-m-d H:i:s'),
        'reason'     => "backfill: {$n} eventos importados de attendance, digest " . substr($digest, 0, 32),
    ]);

    $pdo->commit();
    echo "  {$n} eventos gravados + 1 registro de migração.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nFALHOU: {$e->getMessage()}\n";
    echo "Nada foi gravado — a transação foi revertida.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Verificação imediata
// ---------------------------------------------------------------------------
echo "\n--- Verificando a cadeia recém-criada ---\n";
$v = nsr_ledger_verify($pdo);
if ($v['ok'] && empty($v['gaps'])) {
    echo "  ÍNTEGRA — {$v['checked']} registros conferidos.\n";
} else {
    echo "  PROBLEMA: " . json_encode($v['errors'] ?: $v['gaps'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

echo "\nPróximo passo: ligar o modo shadow com\n";
echo "  UPDATE app_settings SET v = 'shadow' WHERE k = 'ledger_mode';\n";
echo "e rodar bin/ledger_verify.php diariamente antes de passar para 'enforced'.\n";
exit(0);

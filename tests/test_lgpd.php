<?php
declare(strict_types=1);
/**
 * LGPD — consentimento, biometria cifrada, retenção e anonimização.
 * Fase 7 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md.
 *
 * Cobre NC-31 (consentimento só em localStorage), NC-32 (sem consentimento
 * para geolocalização), NC-34 (biometria em texto plano), NC-35 (sem
 * anonimização), NC-36 (sem registro de tratamento) e NC-37 (retenção por
 * volume em vez de prazo).
 *
 * Execução: php tests/test_lgpd.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/lgpd.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();
$teacherId = 0;

try {
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,0,NOW())")
        ->execute(['ZZ Teste LGPD', '00000000272']);
    $teacherId = (int)$pdo->lastInsertId();

    echo "[1] Biometria cifrada em repouso (NC-34)\n";
    check('chave configurada', lgpd_biometric_key() !== null);

    $desc = [array_map(fn($i) => round(sin($i) * 0.1, 8), range(0, 127))];
    $cifrado = face_descriptors_encode($desc);
    check('resultado é JSON válido (respeita o CHECK da coluna)',
          json_decode($cifrado, true) !== null && json_last_error() === JSON_ERROR_NONE);
    check('marcado como cifrado', face_descriptors_is_encrypted($cifrado));
    check('valores não aparecem em claro',
          !str_contains($cifrado, (string)$desc[0][1]), substr($cifrado, 0, 40));

    $volta = face_descriptors_decode($cifrado);
    check('round-trip preserva as dimensões', count($volta[0] ?? []) === 128);
    check('round-trip preserva os valores', abs(($volta[0][5] ?? 0) - $desc[0][5]) < 1e-9);

    // Duas cifragens do mesmo dado devem diferir (IV aleatório) — caso
    // contrário, seria possível inferir igualdade entre biometrias.
    check('IV aleatório: cifragens iguais produzem saídas diferentes',
          face_descriptors_encode($desc) !== $cifrado);

    // Autenticação GCM: adulterar o payload invalida em vez de devolver lixo.
    $env = json_decode($cifrado, true);
    $bin = base64_decode($env['d']);
    $bin[40] = chr(ord($bin[40]) ^ 0xFF);
    $adulterado = json_encode(['enc' => 'v1', 'd' => base64_encode($bin)]);
    check('adulteração é DETECTADA (GCM autentica)', face_descriptors_decode($adulterado) === []);

    // Compatibilidade com o formato legado, para a migração conviver.
    check('formato legado em texto plano ainda é lido',
          count(face_descriptors_decode(json_encode($desc))[0] ?? []) === 128);

    echo "[2] Consentimento por finalidade (NC-31 / NC-32)\n";
    check('sem consentimento inicial', !lgpd_has_consent($pdo, $teacherId, 'biometria'));

    $termo = 'Autorizo o uso da minha imagem facial para registro de ponto.';
    check('consentimento de biometria registrado',
          lgpd_consent_give($pdo, $teacherId, 'biometria', $termo, 'portal'));
    check('consentimento de biometria vigente', lgpd_has_consent($pdo, $teacherId, 'biometria'));

    // O art. 9º exige finalidade específica: uma coisa não implica a outra.
    check('consentir biometria NÃO implica geolocalização',
          !lgpd_has_consent($pdo, $teacherId, 'geolocalizacao'));

    $row = $pdo->query("SELECT purpose, term_version, term_hash, ip_address FROM lgpd_consent
                         WHERE teacher_id = {$teacherId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('finalidade gravada', $row['purpose'] === 'biometria');
    check('versão do termo gravada', !empty($row['term_version']));
    check('hash do termo prova o TEXTO aceito, não só a versão',
          $row['term_hash'] === hash('sha256', $termo));

    check('finalidade inválida é recusada', (function () use ($pdo, $teacherId) {
        try { lgpd_consent_give($pdo, $teacherId, 'inventada'); return false; }
        catch (InvalidArgumentException $e) { return true; }
    })());

    echo "[3] Revogação (art. 8º §5º)\n";
    $n = lgpd_consent_revoke($pdo, $teacherId, 'biometria', 'teste');
    check('revogação afeta o registro', $n === 1, "linhas={$n}");
    check('consentimento deixa de valer', !lgpd_has_consent($pdo, $teacherId, 'biometria'));

    $hist = (int)$pdo->query("SELECT COUNT(*) FROM lgpd_consent WHERE teacher_id = {$teacherId}")->fetchColumn();
    check('registro anterior NÃO é apagado (preserva a licitude do período)', $hist === 1);
    $rev = $pdo->query("SELECT revoked_at, revoked_reason FROM lgpd_consent
                         WHERE teacher_id = {$teacherId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('data e motivo da revogação gravados', !empty($rev['revoked_at']) && $rev['revoked_reason'] === 'teste');

    $status = lgpd_consent_status($pdo, $teacherId);
    check('status expõe todas as finalidades', count($status) === count(LGPD_PURPOSES));
    check('status reflete a revogação', $status['biometria']['vigente'] === false);

    echo "[4] Registro de operações de tratamento (NC-36, art. 37)\n";
    $nLog = (int)$pdo->query("SELECT COUNT(*) FROM lgpd_processing_log WHERE teacher_id = {$teacherId}")->fetchColumn();
    check('consentimento e revogação foram registrados', $nLog >= 2, "linhas={$nLog}");
    $ops = $pdo->query("SELECT operation, legal_basis FROM lgpd_processing_log
                         WHERE teacher_id = {$teacherId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('operação de consentimento presente',
          (bool)array_filter($ops, fn($o) => $o['operation'] === 'consent_given'));
    check('base legal registrada',
          (bool)array_filter($ops, fn($o) => $o['legal_basis'] === 'consentimento'));

    echo "[5] Retenção por PRAZO, não por volume (NC-37)\n";
    // O gatilho antigo era 500 MB em disco. Com 1,1 MB, a limpeza nunca rodava
    // e havia 550 fotos além dos 90 dias guardadas indefinidamente.
    $ref = new ReflectionFunction('cleanup_old_photos');
    $src = file_get_contents($ref->getFileName());
    $corpo = implode("\n", array_slice(explode("\n", $src),
             $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    check('gatilho de volume removido da decisão',
          !str_contains($corpo, "'reason' => 'threshold_not_reached'"));
    check('prazo agora vem de app_settings', str_contains($corpo, 'retention_photos_days'));

    $purge = lgpd_purge_logs($pdo, true); // dry-run
    check('purga de logs devolve plano por tabela', isset($purge['auth_attempt_logs']['dias']));
    check('audit_logs tem retenção mais longa que auth_attempt_logs',
          $purge['audit_logs']['dias'] > $purge['auth_attempt_logs']['dias'],
          json_encode([$purge['audit_logs']['dias'] ?? null, $purge['auth_attempt_logs']['dias'] ?? null]));
    $antesPurge = (int)$pdo->query("SELECT COUNT(*) FROM auth_attempt_logs")->fetchColumn();
    check('dry-run não apaga nada',
          (int)$pdo->query("SELECT COUNT(*) FROM auth_attempt_logs")->fetchColumn() === $antesPurge);

    echo "[6] Anonimização (NC-35)\n";
    $pdo->prepare("UPDATE teachers SET face_descriptors = ?, pin_hash = 'x', email = 'a@b.c', pis = '123'
                    WHERE id = ?")->execute([face_descriptors_encode($desc), $teacherId]);

    check('ativo não pode ser anonimizado', (function () use ($pdo, $teacherId) {
        $pdo->prepare("UPDATE teachers SET active = 1 WHERE id = ?")->execute([$teacherId]);
        try { lgpd_anonymize_teacher($pdo, $teacherId, 1, 'teste'); return false; }
        catch (RuntimeException $e) { return str_contains($e->getMessage(), 'ativo'); }
        finally { $pdo->prepare("UPDATE teachers SET active = 0 WHERE id = ?")->execute([$teacherId]); }
    })());

    $r = lgpd_anonymize_teacher($pdo, $teacherId, 1, 'desligamento em teste');
    check('anonimização executa', $r['ok'] === true);

    $t = $pdo->query("SELECT * FROM teachers WHERE id = {$teacherId}")->fetch(PDO::FETCH_ASSOC);
    check('nome removido', str_contains((string)$t['name'], 'anonimizado'));
    check('CPF substituído por token', $t['cpf'] !== '00000000272');
    check('e-mail removido', $t['email'] === null);
    check('PIN removido', $t['pin_hash'] === null);
    check('biometria removida', $t['face_descriptors'] === null);
    check('PIS removido', $t['pis'] === null);
    check('marcado com a data da anonimização', !empty($t['anonymized_at']));
    check('a LINHA do colaborador permanece (jornada preservada)', $t !== false);

    check('anonimizar duas vezes é no-op',
          (lgpd_anonymize_teacher($pdo, $teacherId, 1, 'de novo'))['ok'] === false);

    $nAnon = (int)$pdo->query("SELECT COUNT(*) FROM lgpd_processing_log
                                WHERE teacher_id = {$teacherId} AND operation = 'anonymize'")->fetchColumn();
    check('anonimização registrada no log de tratamento', $nAnon === 1);

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($teacherId) {
        $pdo->prepare("DELETE FROM lgpd_consent WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM lgpd_processing_log WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM attendance WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$teacherId]);
    }
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

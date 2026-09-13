<?php
declare(strict_types=1);
/**
 * Modo shadow do livro fiscal — Fase 1.7 da auditoria.
 *
 * A pergunta que este teste responde é a única que importa antes de ligar o
 * ledger em produção: **se o livro fiscal falhar, o colaborador ainda consegue
 * bater ponto?**
 *
 * Em `shadow` a resposta tem que ser SIM. O registro de jornada é direito do
 * trabalhador (art. 74 da CLT) e não pode ser perdido por um defeito na
 * contabilidade de integridade. Em `enforced`, ao contrário, a falha deve
 * abortar tudo — porque aí o compromisso assumido é que nenhuma marcação
 * existe fora do livro.
 *
 * Execução: php tests/test_ledger_shadow_mode.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/nsr_ledger.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();
$modoOriginal = (string)(get_setting('ledger_mode', 'off') ?? 'off');

/** Reproduz o padrão real dos endpoints: INSERT em attendance + append no livro. */
function simular_marcacao(PDO $pdo, int $teacherId, int $schoolId, array $override = []): array {
    $now  = date('Y-m-d H:i:s');
    $hoje = date('Y-m-d');
    $pdo->beginTransaction();
    try {
        // Reproduz o padrão REAL dos endpoints. Usar nsr_legacy_reserve() aqui
        // é o que faz este teste detectar dupla contagem de NSR: se o emissor
        // legado e o livro incrementarem a mesma sequência, aparecem buracos.
        $nsrLegado = nsr_legacy_reserve($pdo);
        $pdo->prepare("INSERT INTO attendance (teacher_id, school_id, date, check_in, method, approved, record_type, nsr)
                       VALUES (?,?,?,?,'test',NULL,'work',?)")
            ->execute([$teacherId, $schoolId, $hoje, $now, $nsrLegado]);
        $attId = (int)$pdo->lastInsertId();

        $nsr = nsr_ledger_record_mark($pdo, array_merge([
            'teacher_id' => $teacherId, 'school_id' => $schoolId,
            'attendance_id' => $attId, 'mark_role' => 'in', 'direction' => 'E',
            'record_type' => 'work', 'marked_at' => $now, 'work_date' => $hoje,
            'origin' => 'device', 'method' => 'test',
        ], $override));

        if ($nsr !== null) {
            $pdo->prepare("UPDATE attendance SET legacy_nsr = COALESCE(legacy_nsr, nsr), nsr = ? WHERE id = ?")
                ->execute([$nsr, $attId]);
        }
        $pdo->commit();
        return ['ok' => true, 'attendance_id' => $attId, 'nsr' => $nsr];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
}

try {
    $schoolId = (int)$pdo->query("SELECT id FROM schools ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,1,NOW())")
        ->execute(['ZZ Teste Shadow', '00000000272']);
    $teacherId = (int)$pdo->lastInsertId();
    $criados = [];

    echo "[1] Modo OFF — nada é gravado no livro\n";
    set_setting('ledger_mode', 'off');
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn();
    $r = simular_marcacao($pdo, $teacherId, $schoolId);
    $criados[] = $r['attendance_id'] ?? null;
    $depois = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn();
    check('marcação de ponto funciona', $r['ok'] === true, $r['erro'] ?? '');
    check('livro não recebeu evento', $depois === $antes, "{$antes} -> {$depois}");
    check('nenhum NSR de livro emitido', ($r['nsr'] ?? null) === null);

    echo "[2] Modo SHADOW — grava no livro e no ponto\n";
    set_setting('ledger_mode', 'shadow');
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn();
    $r = simular_marcacao($pdo, $teacherId, $schoolId);
    $criados[] = $r['attendance_id'] ?? null;
    $depois = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn();
    check('marcação de ponto funciona', $r['ok'] === true, $r['erro'] ?? '');
    check('livro recebeu 1 evento', $depois === $antes + 1, "{$antes} -> {$depois}");
    check('NSR do livro foi emitido', is_int($r['nsr'] ?? null));

    $espelho = $pdo->query("SELECT nsr, legacy_nsr FROM attendance WHERE id = " . (int)$r['attendance_id'])->fetch(PDO::FETCH_ASSOC);
    check('attendance.nsr espelha o NSR do livro', (int)$espelho['nsr'] === (int)$r['nsr']);
    check('NSR legado foi preservado', $espelho['legacy_nsr'] !== null);

    echo "[3] Modo SHADOW com o livro QUEBRADO — o ponto tem que sobreviver\n";
    // Falha forçada de forma realista: um valor inválido para a coluna ENUM
    // `origin` faz o INSERT no livro estourar, sem afetar o INSERT em attendance.
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn();
    $r = simular_marcacao($pdo, $teacherId, $schoolId, ['origin' => 'ORIGEM_INVALIDA_PARA_O_ENUM']);
    $criados[] = $r['attendance_id'] ?? null;
    $depois = (int)$pdo->query("SELECT COUNT(*) FROM nsr_ledger")->fetchColumn();
    check('MARCAÇÃO DE PONTO NÃO FOI PERDIDA', $r['ok'] === true, $r['erro'] ?? '');
    check('livro não recebeu o evento com defeito', $depois === $antes, "{$antes} -> {$depois}");
    check('falha foi registrada em app_settings',
          !empty(get_setting('ledger_last_shadow_failure', '')));

    $existe = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE id = " . (int)$r['attendance_id'])->fetchColumn();
    check('linha de attendance está no banco', $existe === 1);

    echo "[4] Modo ENFORCED com o livro QUEBRADO — tudo é abortado\n";
    set_setting('ledger_mode', 'enforced');
    $antesAtt = (int)$pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
    $r = simular_marcacao($pdo, $teacherId, $schoolId, ['origin' => 'ORIGEM_INVALIDA_PARA_O_ENUM']);
    $depoisAtt = (int)$pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
    check('marcação foi RECUSADA', $r['ok'] === false);
    check('nenhuma linha órfã em attendance', $depoisAtt === $antesAtt, "{$antesAtt} -> {$depoisAtt}");

    echo "[5] Contadores independentes — o livro não ganha buracos\n";
    // O livro tem contador EXCLUSIVO (ledger_current_nsr). Se compartilhasse a
    // sequência com o emissor legado de attendance.nsr, qualquer caminho de
    // escrita ainda não convertido abriria buraco permanente na numeração
    // fiscal. Aqui confirmamos que cada contador anda sozinho.
    set_setting('ledger_mode', 'shadow');
    $seqLeg0 = (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $seqLiv0 = (int)$pdo->query("SELECT ledger_current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $r = simular_marcacao($pdo, $teacherId, $schoolId);
    $criados[] = $r['attendance_id'] ?? null;
    $seqLeg1 = (int)$pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    $seqLiv1 = (int)$pdo->query("SELECT ledger_current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    check('contador do livro avança exatamente 1', $seqLiv1 === $seqLiv0 + 1,
          "livro foi de {$seqLiv0} para {$seqLiv1}");
    check('contador legado avança sem afetar o livro', $seqLeg1 === $seqLeg0 + 1,
          "legado foi de {$seqLeg0} para {$seqLeg1}");

    // Simula um caminho de escrita AINDA NÃO convertido ao livro (ex.: inserção
    // manual pelo admin): consome NSR legado e não gera evento fiscal. Não pode
    // abrir buraco na numeração do livro.
    $pdo->beginTransaction();
    nsr_legacy_reserve($pdo);
    $pdo->commit();
    $seqLiv2 = (int)$pdo->query("SELECT ledger_current_nsr FROM nsr_sequence WHERE id=1")->fetchColumn();
    check('caminho não convertido NÃO consome NSR fiscal', $seqLiv2 === $seqLiv1,
          "livro foi de {$seqLiv1} para {$seqLiv2}");

    echo "[6] Cadeia continua íntegra depois de tudo\n";
    set_setting('ledger_mode', $modoOriginal);
    $v = nsr_ledger_verify($pdo);
    check('verificação da cadeia passa', $v['ok'], json_encode($v['errors']));
    check('sem buracos de NSR criados nesta execução', empty($v['gaps']), json_encode($v['gaps']));

    // Limpeza: attendance é mutável; o livro NÃO (e nem deveria ser).
    foreach (array_filter($criados) as $id) {
        $pdo->prepare("DELETE FROM attendance WHERE id = ?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$teacherId]);
    echo "  (fixtures de attendance removidas; eventos do livro permanecem, por serem imutáveis)\n";

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_setting('ledger_mode', $modoOriginal);
}

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

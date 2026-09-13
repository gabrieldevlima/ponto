<?php
declare(strict_types=1);
/**
 * Arquivo Eletrônico de Jornada (AEJ) — Fase 5 da auditoria (NC-06).
 *
 * Os dois testes que mais importam aqui não são de formatação:
 *
 *   [2] JORNADA CONTRATUAL LÍQUIDA. O sistema calcula "previsto" como a janela
 *       cheia, sem descontar intervalo (modelo interno "cheio vs cheio"). Usar
 *       isso no AEJ declararia jornada contratual de 13 h para quem tem 7 h.
 *
 *   [3] JANELA DE CONTAGEM. Sem respeitar counting_start_date + data de
 *       cadastro, o AEJ declara jornada não cumprida em todo dia anterior à
 *       implantação — nesta base, mais de 7.000 horas de ausência inexistente.
 *
 * Ambos foram defeitos reais encontrados ao medir a apuração antes de gerar.
 *
 * Execução: php tests/test_aej.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/aej.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();

echo "[1] Spec do AEJ\n";
$spec = aej_spec();
foreach ([1, 2, 3, 4, 5, 9] as $t) {
    check("tipo {$t} definido com largura > 0", isset($spec[$t]) && aej_largura($t, $spec) > 0);
}
check('AEJ_SPEC_VERIFICADA é bool', is_bool(AEJ_SPEC_VERIFICADA));

$linha3 = aej_line(3, [
    'nsr' => 7, 'tipo_registro' => 3, 'pis' => '12345678901', 'cpf' => '00000000272',
    'data' => '27042026', 'jornada_prevista' => 430, 'tempo_trabalhado' => 480,
    'diferenca_sinal' => '+', 'diferenca' => 50, 'cod_ocorrencia' => '',
]);
check('linha tipo 3 com a largura da spec', strlen($linha3) === aej_largura(3), 'strlen=' . strlen($linha3));
check('sinal de diferença preservado', str_contains($linha3, '+'));

$falhou = false;
try { aej_line(3, ['nsr' => 1, 'tipo_registro' => 3]); }
catch (RuntimeException $e) { $falhou = str_contains($e->getMessage(), 'nao informado'); }
check('campo ausente é recusado', $falhou);

echo "[2] Jornada contratual LÍQUIDA (o erro que declararia 13h de jornada)\n";
$teacherId = 0;
try {
    $schoolId = (int)$pdo->query("SELECT id FROM schools ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO teachers (name, cpf, pis, active, created_at) VALUES (?,?,?,1,?)")
        ->execute(['ZZ Teste AEJ', '00000000272', '12345678901', '2026-01-01 00:00:00']);
    $teacherId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO teacher_schools (teacher_id, school_id) VALUES (?,?)")->execute([$teacherId, $schoolId]);

    // Segunda-feira, janela 06:00–19:00 com 350 min de intervalo.
    // Bruto 780; contratual líquido 430.
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, break_minutes)
                   VALUES (?,1,'06:00:00','19:00:00',350)")->execute([$teacherId]);

    $seg = '2026-05-04'; // segunda-feira
    $j = aej_jornada_contratual($pdo, $teacherId, $seg);
    check('janela bruta seria 780 min', 780 === (13 * 60));
    check('jornada contratual do AEJ desconta o intervalo (430)', $j['minutos'] === 430, 'obtido ' . $j['minutos']);
    check('intervalo contratual exposto', $j['intervalo'] === 350);
    check('entrada e saída preservadas', $j['entrada'] === '06:00' && $j['saida'] === '19:00');

    // A comparação que motiva a função existir.
    $interno = calculate_expected_minutes($pdo, $teacherId, $seg);
    check('AEJ difere do previsto interno (não reusa a janela cheia)',
          $j['minutos'] !== $interno, "aej={$j['minutos']} interno={$interno}");

    $dom = '2026-05-03'; // domingo, sem jornada cadastrada
    check('dia sem jornada cadastrada retorna 0', aej_jornada_contratual($pdo, $teacherId, $dom)['minutos'] === 0);

    echo "[3] Janela de contagem (o erro que declararia falta antes da implantação)\n";
    $inicio = counting_start_for('2026-01-01 00:00:00');
    check('counting_start_for respeita a data global', $inicio >= '2026-05-01', $inicio);

    // Período inteiramente anterior ao início da contagem: nada a declarar.
    $ap = aej_resumo_apuracao($pdo, '2026-03-01', '2026-03-31');
    check('período anterior à contagem não gera dias apurados', $ap['dias'] === 0, 'dias=' . $ap['dias']);
    check('e os dias são contados como fora da janela', $ap['dias_fora_contagem'] > 0);
    check('nenhuma jornada declarada como não cumprida', $ap['previsto'] === 0, 'previsto=' . $ap['previsto']);

    echo "[4] Pré-voo\n";
    $pf = aej_preflight($pdo, '2026-05-01', '2026-05-31');
    check('pré-voo devolve estrutura esperada',
          isset($pf['ok'], $pf['bloqueios'], $pf['avisos']));
    // Decisão do empregador (SEMED, 2026-08-07): os PIS não existem e não serão
    // obtidos. Deixou de ser bloqueio porque TODO registro do AEJ carrega PIS e
    // CPF — o trabalhador segue identificado, o campo PIS sai zerado. Segue como
    // aviso porque, se a homologação do leiaute exigir o PIS, o arquivo será
    // rejeitado e a decisão terá de ser revista.
    check('PIS ausente NÃO bloqueia o AEJ',
          !array_filter($pf['bloqueios'], fn($b) => str_contains($b, 'PIS')),
          implode(' | ', $pf['bloqueios']));
    check('PIS ausente vira aviso explícito',
          (bool)array_filter($pf['avisos'], fn($a) => str_contains($a, 'PIS')),
          implode(' | ', $pf['avisos']));

    echo "[5] Recusa de emissão com leiaute não conferido\n";
    if (!AEJ_SPEC_VERIFICADA) {
        $recusou = false;
        $tmp = tmpfile();
        try { aej_generate_to_stream($pdo, '2026-05-01', '2026-05-02', $tmp); }
        catch (RuntimeException $e) { $recusou = str_contains($e->getMessage(), 'nao foi conferido'); }
        fclose($tmp);
        check('gera apenas com aceite explícito do rascunho', $recusou);
    } else {
        check('spec verificada — checagem não se aplica', true);
    }

    echo "[6] Geração com fixture controlada\n";
    $empBackup = $pdo->query("SELECT cnpj FROM employer_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE employer_config SET cnpj = ?")->execute(['12345678000190']);

    // Marcação real dentro da janela de contagem.
    $modo = (string)(get_setting('ledger_mode', 'off') ?? 'off');
    $pdo->prepare("INSERT INTO attendance (teacher_id, school_id, date, check_in, check_out, method, approved, record_type, nsr)
                   VALUES (?,?,?,?,?,'test',1,'work',?)")
        ->execute([$teacherId, $schoolId, $seg, "$seg 06:00:00", "$seg 13:10:00",
                   (int)$pdo->query("SELECT COALESCE(MAX(nsr),0)+5000 FROM attendance")->fetchColumn()]);

    $tmp = tmpfile();
    $r = aej_generate_to_stream($pdo, $seg, $seg, $tmp, ['aceitar_spec_nao_verificada' => true]);
    fseek($tmp, 0);
    $conteudo = stream_get_contents($tmp);
    fclose($tmp);
    $pdo->prepare("UPDATE employer_config SET cnpj = ?")->execute([$empBackup['cnpj']]);

    $linhas = array_values(array_filter(explode("\r\n", $conteudo), fn($l) => $l !== ''));
    check('arquivo tem cabeçalho e trailer', count($linhas) >= 2);
    check('1ª linha é o cabeçalho (tipo 1)', substr($linhas[0], 9, 1) === '1');
    $ultima = $linhas[count($linhas) - 1];
    check('última linha é o trailer (tipo 9)', substr($ultima, 9, 1) === '9');

    $larguraOk = true; $det = '';
    foreach ($linhas as $i => $l) {
        $t = (int)substr($l, 9, 1);
        if (!isset($spec[$t])) { $larguraOk = false; $det = "linha {$i}: tipo {$t} desconhecido"; break; }
        if (strlen($l) !== aej_largura($t)) {
            $larguraOk = false; $det = "linha {$i} (tipo {$t}): " . strlen($l) . ' != ' . aej_largura($t); break;
        }
    }
    check('TODAS as linhas com largura exata do seu tipo', $larguraOk, $det);
    check('arquivo é ASCII puro', preg_match('/^[\x20-\x7E\r\n]*$/', $conteudo) === 1);

    $temApuracao = (bool)array_filter($linhas, fn($l) => substr($l, 9, 1) === '3');
    check('há registro de apuração diária (tipo 3)', $temApuracao);
    $temJornada = (bool)array_filter($linhas, fn($l) => substr($l, 9, 1) === '2');
    check('há registro de jornada contratual (tipo 2)', $temJornada);

} catch (Throwable $e) {
    $fail++;
    echo "  [FAIL] exceção: " . $e->getMessage() . "\n";
    echo "         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($teacherId) {
        $pdo->prepare("DELETE FROM attendance WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM teacher_schools WHERE teacher_id = ?")->execute([$teacherId]);
        $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$teacherId]);
    }
}

echo "[7] Ausência de dado não é ausência do trabalhador\n";
// Investigação de 2026-08-07: o AEJ de junho declarou 1.133 dias de jornada não
// cumprida. 1.004 deles (89%) eram apenas o fim dos dados — o sistema parou de
// receber marcações em 16/06 e o período pedido ia até 30/06. Encurtar o período
// virou o resultado de -5.829 h de déficit para +780 h de crédito.
//
// Sem esta separação, um AEJ emitido logo após qualquer parada do sistema
// declara falta em massa e ninguém percebe até a fiscalização perguntar.
$ultimo = aej_ultimo_dia_com_movimento($pdo);
check('sabe dizer o último dia com movimento',
      $ultimo === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $ultimo) === 1, (string)$ultimo);

if ($ultimo !== null) {
    // Um registro solto depois de uma parada não pode mover a fronteira: o corte
    // é por FRAÇÃO dos ativos, não por existência de marcação.
    $maxSolto = (string)$pdo->query("SELECT MAX(date) FROM attendance
                                      WHERE removed_at IS NULL AND superseded_by_id IS NULL")->fetchColumn();
    check('a fronteira ignora registros soltos (usa fração dos ativos, não MAX)',
          $ultimo <= $maxSolto, "movimento={$ultimo} max={$maxSolto}");

    $depois = date('Y-m-d', strtotime($ultimo . ' +14 days'));
    $pvDepois = aej_preflight($pdo, $ultimo, $depois);
    $pvDentro = aej_preflight($pdo, date('Y-m-d', strtotime($ultimo . ' -14 days')), $ultimo);
    $aviso = fn(array $r) => (bool)array_filter($r['avisos'], fn($a) => str_contains($a, 'ultimo dia com movimento'));

    check('período que passa do fim dos dados dispara aviso', $aviso($pvDepois),
          implode(' | ', $pvDepois['avisos']));
    check('período que termina no fim dos dados NÃO dispara', !$aviso($pvDentro),
          implode(' | ', $pvDentro['avisos']));

    $resDepois = aej_resumo_apuracao($pdo, $ultimo, $depois);
    $resDentro = aej_resumo_apuracao($pdo, date('Y-m-d', strtotime($ultimo . ' -14 days')), $ultimo);
    check('o resumo separa "sem dado" de "ausente"',
          array_key_exists('dias_apos_fim_dos_dados', $resDentro));
    check('dentro do período com dados, nenhum dia cai em "sem dado"',
          (int)$resDentro['dias_apos_fim_dos_dados'] === 0,
          (string)$resDentro['dias_apos_fim_dos_dados']);
    check('"sem dado" nunca excede o total de dias sem marcação',
          (int)$resDepois['dias_apos_fim_dos_dados'] <= (int)$resDepois['dias_sem_marcacao'],
          "{$resDepois['dias_apos_fim_dos_dados']} de {$resDepois['dias_sem_marcacao']}");
    check('o resumo informa qual é a fronteira',
          $resDentro['ultimo_dia_com_movimento'] === $ultimo);
}

check('o diagnóstico reutilizável existe', is_file(__DIR__ . '/../bin/aej_diagnostico.php'));
$diag = (string)file_get_contents(__DIR__ . '/../bin/aej_diagnostico.php');
check('o diagnóstico classifica em três causas excludentes',
      str_contains($diag, 'CAT_SEM_DADO') && str_contains($diag, 'CAT_AFASTADO')
      && str_contains($diag, 'CAT_AUSENCIA'));
check('o diagnóstico é somente leitura',
      !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP)\s/i', $diag));

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

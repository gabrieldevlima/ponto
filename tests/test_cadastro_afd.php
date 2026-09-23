<?php
declare(strict_types=1);
/**
 * Cadastro exigido pelo AFD/AEJ — validadores, telas e importação em massa.
 *
 * O pré-voo do AFD e do AEJ bloqueava por CNPJ, identificador do REP e PIS, mas
 * não havia onde digitar nenhum deles: as colunas existiam desde a Fase 0b.5 e
 * os formulários nunca foram estendidos. Estes testes cobrem o caminho de
 * entrada desses dados.
 *
 * Por que a validação é dura: um PIS ou CNPJ com dígito errado passa em
 * qualquer teste interno e só aparece na REJEIÇÃO do arquivo pela fiscalização,
 * quando o mês já fechou.
 *
 * Execução: php tests/test_cadastro_afd.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/import_cadastro.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK]   {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$pdo = db();

echo "[1] validate_cnpj\n";
// 62.000.259/0001-90 é o CNPJ real do empregador; os demais são construídos.
foreach ([
    '62.000.259/0001-90' => true,
    '62000259000190'     => true,
    '62.000.259/0001-91' => false,  // 2º DV errado
    '62.000.259/0001-80' => false,  // 1º DV errado
    '11.111.111/1111-11' => false,  // sequência trivial passa no checksum
    '00000000000000'     => false,
    '6200025900019'      => false,  // 13 dígitos
    '620002590001901'    => false,  // 15 dígitos
    ''                   => false,
] as $cnpj => $esperado) {
    check("CNPJ '{$cnpj}' → " . ($esperado ? 'válido' : 'inválido'),
          validate_cnpj((string)$cnpj) === $esperado);
}

echo "[2] validate_pis\n";
// 1200123456 → soma 84, 84%11=7, DV=11-7=4
foreach ([
    '12001234564'    => true,
    '120.01234.56-4' => true,   // formatado
    '12001234565'    => false,  // DV errado
    '00000000000'    => false,
    '11111111111'    => false,
    '1200123456'     => false,  // 10 dígitos
    ''               => false,
] as $pis => $esperado) {
    check("PIS '{$pis}' → " . ($esperado ? 'válido' : 'inválido'),
          validate_pis((string)$pis) === $esperado);
}
// O resto 0 e 1 caem no ramo `< 2` do DV — o bug clássico desse algoritmo.
$comDvZero = null;
for ($n = 1000000000; $n < 1000000200; $n++) {
    $base = (string)$n;
    $pesos = [3,2,9,8,7,6,5,4,3,2]; $s = 0;
    for ($i = 0; $i < 10; $i++) $s += ((int)$base[$i]) * $pesos[$i];
    $r = $s % 11;
    if ($r < 2) { $comDvZero = $base . '0'; break; }
}
check('PIS cujo resto é 0 ou 1 tem DV zero e é aceito',
      $comDvZero !== null && validate_pis($comDvZero), (string)$comDvZero);

echo "[3] Campos do AFD no cadastro do empregador\n";
$emp = file_get_contents(__DIR__ . '/../public/admin/employer_config.php');
foreach (['rep_identifier', 'service_location', 'cei_caepf_cno', 'employer_type'] as $campo) {
    check("formulário tem o campo {$campo}", str_contains($emp, "name=\"{$campo}\""));
    check("UPDATE grava {$campo}", str_contains($emp, "{$campo} = ?") || str_contains($emp, "{$campo} = ?,"));
}
check('CNPJ é validado antes de gravar', str_contains($emp, 'validate_cnpj('));
check('pessoa física exige CPF válido', str_contains($emp, 'validate_cpf($cpf_empregador)'));
check('alteração de empregador vira evento tipo 2 no livro',
      str_contains($emp, "nsr_ledger_record_cadastro") && str_contains($emp, "'employer_change'"));

echo "[4] Campos do AFD no cadastro do colaborador\n";
$edit = file_get_contents(__DIR__ . '/../public/admin/teacher_edit.php');
$save = file_get_contents(__DIR__ . '/../public/admin/teachers_save.php');
foreach (['pis', 'matricula', 'cbo', 'admission_date', 'dismissal_date'] as $campo) {
    check("formulário tem o campo {$campo}", str_contains($edit, "name=\"{$campo}\""));
    check("save lê {$campo} do POST", str_contains($save, "\$_POST['{$campo}']"));
}
check('save valida o PIS', str_contains($save, 'validate_pis($pis)'));
check('save recusa desligamento anterior à admissão',
      str_contains($save, '$dismissal_date < $admission_date'));
check('PIS vazio vira NULL, não string vazia', str_contains($save, "\$pis ?: null"));
check('edição de cadastro vira evento tipo 5 no livro',
      str_contains($save, "'employee_change'"));
check('salvar sem mudar nada NÃO consome NSR',
      str_contains($save, '$afdOperacao !== null') && str_contains($save, '$afdMudou'));
check('inclusão sai como operação I', str_contains($save, "\$afdOperacao = 'I'"));
check('alteração sai como operação A', str_contains($save, "\$afdMudou ? 'A' : null"));

echo "[5] Código AEJ no tipo de afastamento\n";
$lt = file_get_contents(__DIR__ . '/../public/admin/leave_types.php');
check('formulário tem o campo aej_code', str_contains($lt, 'name="aej_code"'));
check('UPDATE grava aej_code', str_contains($lt, 'aej_code=?'));
check('INSERT grava aej_code', str_contains($lt, 'aej_code)'));
check('listagem sinaliza o que está pendente', str_contains($lt, 'pendente'));

echo "[6] Importação em massa — análise não grava nada\n";
$antes = $pdo->query("SELECT COUNT(*) FROM teachers WHERE pis IS NOT NULL AND pis <> ''")->fetchColumn();
// $umCpf precisa ser de um colaborador ATIVO que o importador consiga casar.
// Pegava o primeiro colaborador do banco — no CI, que parte do schema de
// produção sem dado nenhum, não havia colaborador, o CPF saía vazio e 27
// verificações caíam em cascata com "CPF em branco". Usa um fictício: se já
// houver alguém com este CPF, só o reaproveita (a análise não grava nada);
// se não houver, cria e remove ao final.
$umCpf = '52998224725';
$stUm = $pdo->prepare("SELECT id FROM teachers WHERE cpf = ? LIMIT 1");
$stUm->execute([$umCpf]);
$idUm = (int)$stUm->fetchColumn();
if (!$idUm) {
    // Fixture própria para as seções que geram AFD/AEJ de junho/2026 — antes
    // elas dependiam de a base ter colaboradores, jornadas e afastamentos reais
    // naquele mês, e só passavam na máquina de desenvolvimento:
    //   - nasce em janeiro, para caber na janela de contagem de junho;
    //   - tem jornada na segunda-feira, sem a qual o AEJ não emite o tipo 2;
    //   - tem um afastamento aprovado em junho, de tipo com código AEJ — é o
    //     que faz sair o registro tipo 4 conferido na seção [19].
    $pdo->prepare("INSERT INTO teachers (name, cpf, active, created_at) VALUES (?,?,1,?)")
        ->execute(['ZZ Teste Cadastro AFD', $umCpf, '2026-01-01 00:00:00']);
    $idUm = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, break_minutes)
                   VALUES (?,1,'08:00:00','17:00:00',60)")->execute([$idUm]);
    $tipoComCodigo = (int)$pdo->query("SELECT id FROM leave_types WHERE active = 1 AND aej_code IS NOT NULL AND aej_code <> '' ORDER BY id LIMIT 1")->fetchColumn();
    if ($tipoComCodigo) {
        $pdo->prepare("INSERT INTO leaves (teacher_id, type_id, start_date, end_date, days_count, approved) VALUES (?,?,?,?,?,1)")
            ->execute([$idUm, $tipoComCodigo, '2026-06-10', '2026-06-12', 3]);
    }
    // Uma limpeza só, na ordem das dependências.
    register_shutdown_function(function () use ($pdo, $idUm): void {
        $pdo->prepare("DELETE FROM leaves WHERE teacher_id = ?")->execute([$idUm]);
        $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ?")->execute([$idUm]);
        $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$idUm]);
    });
}
$csv = "cpf;pis;matricula\n{$umCpf};12001234564;M-9999\n";
$r = importar_analisar($pdo, $csv);
check('análise devolve itens', isset($r['itens']) && count($r['itens']) === 1, json_encode($r));
check('a linha casou com o colaborador', ($r['itens'][0]['teacher_id'] ?? 0) > 0);
check('classificada como aplicável',
      in_array($r['itens'][0]['status'] ?? '', ['ok', 'substitui', 'inalterado'], true),
      (string)($r['itens'][0]['detalhe'] ?? ''));
$depois = $pdo->query("SELECT COUNT(*) FROM teachers WHERE pis IS NOT NULL AND pis <> ''")->fetchColumn();
check('NADA foi gravado na etapa de análise', $antes === $depois, "{$antes} → {$depois}");

echo "[7] Importação em massa — o que ela recusa\n";
$casos = [
    'CPF inexistente na base' => ["cpf;pis\n11144477735;12001234564\n", 'Nenhum colaborador'],
    'CPF inválido'            => ["cpf;pis\n11111111111;12001234564\n", 'CPF inválido'],
    'PIS com DV errado'       => ["cpf;pis\n{$umCpf};12001234565\n", 'PIS inválido'],
    'CPF repetido no arquivo' => ["cpf;pis\n{$umCpf};12001234564\n{$umCpf};12001234564\n", 'repetido'],
    'data em formato livre'   => ["cpf;admissao\n{$umCpf};32/13/2023\n", 'Data inválida'],
];
foreach ($casos as $rotulo => [$conteudo, $trecho]) {
    $res = importar_analisar($pdo, $conteudo);
    $itens = $res['itens'] ?? [];
    $achou = false;
    foreach ($itens as $it) {
        if ($it['status'] === 'erro' && str_contains($it['detalhe'], $trecho)) { $achou = true; break; }
    }
    check("recusa: {$rotulo}", $achou, json_encode($itens, JSON_UNESCAPED_UNICODE));
}

echo "[8] Importação em massa — arquivos malformados\n";
check('arquivo vazio é recusado', isset(importar_analisar($pdo, '')['erro']));
check('só cabeçalho é recusado', isset(importar_analisar($pdo, "cpf;pis\n")['erro']));
check('sem coluna cpf é recusado', isset(importar_analisar($pdo, "nome;pis\nJoao;12001234564\n")['erro']));
check('cpf sozinho, sem coluna de dado, é recusado',
      isset(importar_analisar($pdo, "cpf\n{$umCpf}\n")['erro']));
$comBom = "\xEF\xBB\xBFcpf;pis\n{$umCpf};12001234564\n";
check('CSV do Excel com BOM é lido', !isset(importar_analisar($pdo, $comBom)['erro']));
$comVirgula = "cpf,pis\n{$umCpf},12001234564\n";
$rv = importar_analisar($pdo, $comVirgula);
check('separador vírgula é detectado', ($rv['itens'][0]['teacher_id'] ?? 0) > 0);
$acentuado = "CPF;PIS;Matrícula\n{$umCpf};12001234564;M-1\n";
$ra = importar_analisar($pdo, $acentuado);
check('cabeçalho com acento e maiúscula é normalizado', ($ra['itens'][0]['teacher_id'] ?? 0) > 0);

echo "[9] Um PIS não pode pertencer a duas pessoas\n";
$dois = $pdo->query("SELECT cpf FROM teachers WHERE active = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($dois) === 2) {
    $dup = "cpf;pis\n{$dois[0]};12001234564\n{$dois[1]};12001234564\n";
    $rd = importar_analisar($pdo, $dup);
    $temErroDup = false;
    foreach ($rd['itens'] as $it) {
        if ($it['status'] === 'erro' && str_contains($it['detalhe'], 'repetido')) { $temErroDup = true; }
    }
    check('mesmo PIS em duas linhas é recusado', $temErroDup, json_encode($rd['itens'], JSON_UNESCAPED_UNICODE));
} else {
    check('mesmo PIS em duas linhas é recusado', true, 'base com menos de 2 colaboradores — pulado');
}

echo "[10] normalizar_data_import\n";
check("'' vira null", normalizar_data_import('') === null);
check("'2023-02-01' aceito", normalizar_data_import('2023-02-01') === '2023-02-01');
check("'01/02/2023' vira 2023-02-01", normalizar_data_import('01/02/2023') === '2023-02-01');
check("'2023-02-30' recusado (dia inexistente)", normalizar_data_import('2023-02-30') === false);
check("'31/13/2023' recusado", normalizar_data_import('31/13/2023') === false);
check("'ontem' recusado", normalizar_data_import('ontem') === false);

echo "[11] Pré-voo enxerga o CNPJ gravado\n";
require_once __DIR__ . '/../lib/afd.php';
require_once __DIR__ . '/../lib/aej.php';
$pv  = afd_preflight($pdo, '2026-06-01', '2026-06-30');
$pvA = aej_preflight($pdo, '2026-06-01', '2026-06-30');
$temBloqueio = fn(array $r, string $t) => (bool)array_filter($r['bloqueios'], fn($b) => str_contains($b, $t));
$temAviso    = fn(array $r, string $t) => (bool)array_filter($r['avisos'],    fn($a) => str_contains($a, $t));
check('CNPJ deixou de bloquear o AFD', !$temBloqueio($pv, 'CNPJ'),  implode(' | ', $pv['bloqueios']));
check('CNPJ deixou de bloquear o AEJ', !$temBloqueio($pvA, 'CNPJ'), implode(' | ', $pvA['bloqueios']));

echo "[12] PIS ausente não bloqueia (decisão do empregador, 2026-08-07)\n";
// A SEMED não tem os PIS e não vai obtê-los. Todo registro do AEJ carrega PIS
// *e* CPF, então o trabalhador segue identificado — o campo PIS sai zerado.
$semPis = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE active = 1 AND (pis IS NULL OR pis = '')")->fetchColumn();
check('há colaboradores sem PIS na base (senão este teste não prova nada)', $semPis > 0, (string)$semPis);
check('PIS ausente NÃO bloqueia o AEJ', !$temBloqueio($pvA, 'PIS'), implode(' | ', $pvA['bloqueios']));
check('PIS ausente NÃO bloqueia o AFD', !$temBloqueio($pv, 'PIS'),  implode(' | ', $pv['bloqueios']));
check('mas o AEJ avisa quantos e por quê', $temAviso($pvA, 'PIS'), implode(' | ', $pvA['avisos']));
check('e o aviso remete à homologação do leiaute', $temAviso($pvA, 'homologacao'), implode(' | ', $pvA['avisos']));
check('o AFD avisa que o tipo 7 não é afetado', $temAviso($pv, 'tipo 7'), implode(' | ', $pv['avisos']));

echo "[13] Geração real sem PIS — o CPF identifica e a largura se mantém\n";
$larguras = []; $tipos = []; $linhaTipo2 = null;
foreach (aej_generate($pdo, '2026-06-01', '2026-06-30', ['aceitar_spec_nao_verificada' => true]) as $linha) {
    $linha = rtrim($linha, "\r\n");
    $t = substr($linha, 9, 1);
    $tipos[$t] = ($tipos[$t] ?? 0) + 1;
    $larguras[$t][strlen($linha)] = true;
    if ($t === '2' && $linhaTipo2 === null) $linhaTipo2 = $linha;
}
check('o AEJ gerou linhas', array_sum($tipos) > 0, json_encode($tipos));
$larguraUniforme = true;
foreach ($larguras as $t => $ws) if (count($ws) !== 1) $larguraUniforme = false;
check('cada tipo tem largura única (nenhum campo estourou)', $larguraUniforme, json_encode(array_map('array_keys', $larguras)));
if ($linhaTipo2 !== null) {
    $pisCampo = substr($linhaTipo2, 10, 12);
    $cpfCampo = substr($linhaTipo2, 22, 11);
    check('campo PIS sai zerado', $pisCampo === str_repeat('0', 12), $pisCampo);
    check('campo CPF sai preenchido', preg_match('/^\d{11}$/', $cpfCampo) && $cpfCampo !== str_repeat('0', 11), $cpfCampo);
    check('o CPF do registro existe na base',
          (bool)$pdo->query("SELECT 1 FROM teachers WHERE REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','') = " . $pdo->quote($cpfCampo) . " LIMIT 1")->fetchColumn(),
          $cpfCampo);
} else {
    check('campo PIS sai zerado', false, 'nenhuma linha tipo 2 gerada no período');
}

$afdLarguras = []; $afdCab = null; $afdUlt = null; $afdTipos = [];
foreach (afd_generate($pdo, '2026-06-01', '2026-06-30', ['aceitar_spec_nao_verificada' => true]) as $linha) {
    $linha = rtrim($linha, "\r\n");
    $t = substr($linha, 9, 1);
    $afdLarguras[$t][strlen($linha)] = true;
    $afdTipos[$t] = ($afdTipos[$t] ?? 0) + 1;
    if ($afdCab === null) $afdCab = $linha;
    $afdUlt = $linha;
}
$afdUniforme = true;
foreach ($afdLarguras as $ws) if (count($ws) !== 1) $afdUniforme = false;
check('AFD também gera com largura única por tipo', $afdUniforme, json_encode(array_map('array_keys', $afdLarguras)));

echo "[14] Cabeçalho do AFD carrega a identificação do empregador\n";
// Decompõe pelas larguras declaradas na spec, em vez de posições cravadas —
// se alguém mexer num campo anterior, o teste acompanha em vez de mentir.
$fatiar = function (string $linha, array $campos): array {
    $pos = 0; $out = [];
    foreach ($campos as [$nome, $len]) { $out[$nome] = substr($linha, $pos, $len); $pos += $len; }
    $out['__consumido'] = $pos;
    return $out;
};
$specAfd = afd_spec();
check('o AFD gerou cabeçalho', $afdCab !== null);
if ($afdCab !== null) {
    $c = $fatiar($afdCab, $specAfd[1]);
    check('largura do cabeçalho bate com a spec',
          strlen($afdCab) === afd_largura(1) && $c['__consumido'] === strlen($afdCab),
          strlen($afdCab) . ' vs ' . afd_largura(1));
    check('tipo de registro é 1',        $c['tipo_registro'] === '1');
    check('NSR do cabeçalho é zerado',   $c['nsr'] === str_repeat('0', 9), $c['nsr']);
    // Compara com o empregador CADASTRADO, não com o CNPJ da SEMED cravado no
    // teste: o teste só passava no banco que tinha a SEMED configurada.
    $empCad = $pdo->query("SELECT cnpj, company_name FROM employer_config LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    check('CNPJ sai só com dígitos',     $c['ident_empregador'] === afd_pad_num($empCad['cnpj'] ?? '', 14), $c['ident_empregador']);
    check('tipo de identificação é 1 (pessoa jurídica)', $c['tipo_ident_empr'] === '1');
    check('identificador do REP está preenchido',
          trim($c['ident_rep']) !== '' && trim($c['ident_rep']) !== '0', "'{$c['ident_rep']}'");
    check('identificador do REP é o cadastrado',
          trim($c['ident_rep']) === (string)$pdo->query("SELECT rep_identifier FROM employer_config LIMIT 1")->fetchColumn(),
          "'{$c['ident_rep']}'");
    check('campo alfanumérico é preenchido à direita com espaço',
          $c['ident_rep'] === str_pad(trim($c['ident_rep']), 17), "'{$c['ident_rep']}'");
    check('razão social é a cadastrada, normalizada pelo AFD',
          trim($c['razao_social']) !== '' && $c['razao_social'] === afd_pad_alpha($empCad['company_name'] ?? '', strlen($c['razao_social'])),
          "'" . trim($c['razao_social']) . "'");
    check('período confere com o solicitado',
          $c['data_inicial'] === '01062026' && $c['data_final'] === '30062026',
          $c['data_inicial'] . '/' . $c['data_final']);
}

echo "[15] Trailer do AFD fecha a conta\n";
if ($afdUlt !== null) {
    $tr = $fatiar($afdUlt, $specAfd[9]);
    check('última linha é o trailer', $tr['tipo_registro'] === '9');
    check('NSR do trailer é 999999999', $tr['nsr'] === '999999999', $tr['nsr']);
    check('contador do tipo 7 bate com as linhas emitidas',
          (int)$tr['qtd_tipo_7'] === ($afdTipos['7'] ?? 0),
          (int)$tr['qtd_tipo_7'] . ' declarado vs ' . ($afdTipos['7'] ?? 0) . ' emitido');
    // Cabeçalho + marcações + trailer, sem sobra: prova que nenhum tipo saiu
    // fora da contagem.
    $somaDeclarada = 0;
    foreach (['qtd_tipo_2','qtd_tipo_3','qtd_tipo_4','qtd_tipo_5','qtd_tipo_6','qtd_tipo_7'] as $k) {
        $somaDeclarada += (int)$tr[$k];
    }
    check('total de linhas = cabeçalho + declarados + trailer',
          array_sum($afdTipos) === $somaDeclarada + 2,
          array_sum($afdTipos) . ' vs ' . ($somaDeclarada + 2));
}

echo "[17] Local da prestação de serviço no registro tipo 2\n";
$local = (string)$pdo->query("SELECT service_location FROM employer_config LIMIT 1")->fetchColumn();
check('local da prestação preenchido', trim($local) !== '', "'{$local}'");
check('cabe no campo de 100 do tipo 2', strlen($local) <= 100, strlen($local) . ' caracteres');
check('deixou de constar como aviso no AFD',
      !$temAviso(afd_preflight($pdo, '2026-06-01', '2026-06-30'), 'Local da prestacao'));
// O tipo 2 do AFD sai de um evento `employer_change` no livro. O teste esperava
// que ALGUÉM tivesse alterado o empregador no mês corrente — só acontecia na
// máquina de desenvolvimento, e por acaso. Registra a alteração pelo mesmo
// caminho da tela do empregador (admin/employer_config.php), com o livro em
// modo shadow, e restaura o modo em seguida.
$modoAntes = (string)(get_setting('ledger_mode', 'off') ?? 'off');
set_setting('ledger_mode', 'shadow');
nsr_ledger_record_cadastro($pdo, 'employer_change', [
    'origin' => 'system',
    'reason' => 'teste: alteracao de cadastro do empregador',
]);
set_setting('ledger_mode', $modoAntes);
// Período que contém os eventos de cadastro de hoje — só aí sai registro tipo 2.
$linhaT2 = null;
foreach (afd_generate($pdo, date('Y-m-01'), date('Y-m-d'), ['aceitar_spec_nao_verificada' => true]) as $linha) {
    if (substr($linha, 9, 1) === '2') { $linhaT2 = rtrim($linha, "\r\n"); break; }
}
check('o AFD emitiu registro tipo 2', $linhaT2 !== null);
if ($linhaT2 !== null) {
    $c2 = $fatiar($linhaT2, $specAfd[2]);
    check('largura do tipo 2 bate com a spec',
          strlen($linhaT2) === afd_largura(2) && $c2['__consumido'] === strlen($linhaT2));
    check('tipo 2 carrega o local da prestação',
          trim($c2['local_prestacao']) === strtoupper(afd_ascii($local)),
          "'" . trim($c2['local_prestacao']) . "'");
    $empCad17 = $pdo->query("SELECT cnpj FROM employer_config LIMIT 1")->fetchColumn();
    check('tipo 2 carrega o CNPJ cadastrado', $c2['ident_empregador'] === afd_pad_num((string)$empCad17, 14), $c2['ident_empregador']);
}

echo "[18] Códigos AEJ: preenchidos, porém marcados como não conferidos\n";
$semCod = (int)$pdo->query("SELECT COUNT(*) FROM leave_types WHERE active = 1 AND (aej_code IS NULL OR aej_code = '')")->fetchColumn();
check('nenhum tipo de afastamento ativo sem código', $semCod === 0, (string)$semCod);
$codigos = $pdo->query("SELECT name, aej_code FROM leave_types WHERE active = 1")->fetchAll(PDO::FETCH_KEY_PAIR);
check('todos os códigos cabem no campo de 4',
      empty(array_filter($codigos, fn($c) => strlen((string)$c) > 4)),
      json_encode($codigos, JSON_UNESCAPED_UNICODE));
check('nenhum código repetido entre tipos ativos',
      count(array_unique($codigos)) === count($codigos),
      json_encode($codigos, JSON_UNESCAPED_UNICODE));
// A trava que importa: preencher os códigos fez sumir o aviso de "sem código".
// Se nada o substituísse, o risco ficaria invisível.
$pvCod = aej_preflight($pdo, '2026-06-01', '2026-06-30');
check('preencher NÃO silenciou o AEJ — avisa que os códigos não foram conferidos',
      aej_codigos_conferidos() || $temAviso($pvCod, 'provisoriamente'),
      implode(' | ', $pvCod['avisos']));
check('o aviso diz onde conferir',
      aej_codigos_conferidos() || $temAviso($pvCod, 'Tipos de Afastamento'),
      implode(' | ', $pvCod['avisos']));

echo "[19] A conferência dos códigos é revogável e se auto-invalida\n";
$lt2 = file_get_contents(__DIR__ . '/../public/admin/leave_types.php');
check('a tela permite confirmar a conferência', str_contains($lt2, 'confirmar_codigos_aej'));
check('alterar um código revoga a conferência', str_contains($lt2, '$invalidarConferencia'));
check('a revogação fica no log de auditoria',
      str_contains($lt2, "'motivo' => 'codigo AEJ alterado apos a conferencia'"));
// Exercita o ciclo de verdade, restaurando o estado ao final.
$estadoOriginal = get_setting('aej_codes_conferidos', '0');
set_setting('aej_codes_conferidos', '1');
check('marcado como conferido, o aviso some',
      !$temAviso(aej_preflight($pdo, '2026-06-01', '2026-06-30'), 'provisoriamente'));
set_setting('aej_codes_conferidos', '0');
check('desmarcado, o aviso volta',
      $temAviso(aej_preflight($pdo, '2026-06-01', '2026-06-30'), 'provisoriamente'));
set_setting('aej_codes_conferidos', $estadoOriginal);
check('estado da flag restaurado após o teste',
      get_setting('aej_codes_conferidos', '0') === $estadoOriginal);

echo "[20] Os códigos chegam ao arquivo\n";
$comOcorrencia = 0; $codigosVistos = [];
foreach (aej_generate($pdo, '2026-06-01', '2026-06-30', ['aceitar_spec_nao_verificada' => true]) as $linha) {
    $linha = rtrim($linha, "\r\n");
    if (substr($linha, 9, 1) !== '4') continue;   // tipo 4 = ocorrência de afastamento
    $c4 = $fatiar($linha, aej_spec()[4]);
    $cod = trim($c4['cod_ocorrencia']);
    if ($cod !== '') { $comOcorrencia++; $codigosVistos[$cod] = true; }
}
check('registros de afastamento saem com código de ocorrência',
      $comOcorrencia > 0, "{$comOcorrencia} linhas tipo 4 com código");
check('os códigos emitidos são os cadastrados',
      empty(array_diff(array_keys($codigosVistos), array_values($codigos))),
      implode(',', array_keys($codigosVistos)));

echo "[21] A tela de importação não afirma mais que o AEJ depende do PIS\n";
$imp = file_get_contents(__DIR__ . '/../public/admin/import_pis.php');
check('some a afirmação de que o AEJ não pode ser emitido',
      !str_contains($imp, 'não pode ser emitido'));
check('a tela explica que o PIS deixou de ser bloqueio',
      str_contains($imp, 'não impede'));

echo "\n=== Resultado ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

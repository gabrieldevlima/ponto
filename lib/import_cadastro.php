<?php
declare(strict_types=1);
/**
 * Leitura e conferência de planilhas de cadastro contratual (PIS, matrícula,
 * CBO, admissão) — a parte da importação em massa que NÃO grava nada.
 *
 * Separada de public/admin/import_pis.php por dois motivos: a tela carrega
 * sessão de admin e emite HTML (intestável), e a conferência é justamente a
 * parte que precisa de teste — um PIS com dígito errado passa em qualquer
 * verificação interna e só aparece na rejeição do AEJ pela fiscalização.
 */

require_once __DIR__ . '/../helpers.php';

/**
 * Aceita AAAA-MM-DD, DD/MM/AAAA e DD-MM-AAAA.
 *
 * Distingue três resultados de propósito: '' → null (campo não informado, que
 * na importação significa "preserve o que está lá"), data válida → 'Y-m-d',
 * data impossível → false (erro que barra a linha). Um `null` silencioso no
 * lugar do `false` faria a linha inválida ser gravada como "sem alteração".
 */
function normalizar_data_import(string $v) {
    $v = trim($v);
    if ($v === '') return null;
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $fmt) {
        $d = DateTimeImmutable::createFromFormat($fmt, $v);
        // O round-trip pega 2023-02-30, que o createFromFormat aceita e rola
        // para 2023-03-02.
        if ($d && $d->format($fmt) === $v) return $d->format('Y-m-d');
    }
    return false;
}

/**
 * Lê o CSV, casa cada linha com um colaborador pelo CPF e classifica o que
 * ACONTECERIA. Não escreve no banco.
 *
 * Colunas aceitas (cabeçalho obrigatório, ordem livre):
 *   cpf (obrigatória) | pis | matricula | cbo | admissao | desligamento
 *
 * Status por linha: 'ok' (atualiza), 'substitui' (troca um PIS já cadastrado —
 * destacado porque é o caso perigoso), 'inalterado', 'erro'.
 */
function importar_analisar(PDO $pdo, string $conteudo): array {
    // Excel salva UTF-8 com BOM por padrão.
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);

    $linhas = preg_split('/\r\n|\r|\n/', $conteudo);
    $linhas = array_values(array_filter($linhas, fn($l) => trim($l) !== ''));
    if (count($linhas) < 2) {
        return ['erro' => 'Arquivo vazio ou sem linhas de dados além do cabeçalho.'];
    }

    // Excel em português salva CSV com ";".
    $sep = substr_count($linhas[0], ';') >= substr_count($linhas[0], ',') ? ';' : ',';

    $cabecalho = array_map(
        fn($c) => strtolower(trim(str_replace(
            ['á','ã','â','é','ê','í','ó','ô','õ','ú','ç','Á','Ã','Â','É','Ê','Í','Ó','Ô','Õ','Ú','Ç'],
            ['a','a','a','e','e','i','o','o','o','u','c','a','a','a','e','e','i','o','o','o','u','c'],
            $c
        ), " \t\"'")),
        str_getcsv($linhas[0], $sep, '"', '\\')
    );
    $idx = array_flip($cabecalho);
    if (!isset($idx['cpf'])) {
        return ['erro' => 'O arquivo precisa de uma coluna "cpf" no cabeçalho. Colunas lidas: ' . implode(', ', $cabecalho)];
    }
    $temAlgumDado = false;
    foreach (['pis', 'matricula', 'cbo', 'admissao', 'desligamento'] as $c) {
        if (isset($idx[$c])) { $temAlgumDado = true; break; }
    }
    if (!$temAlgumDado) {
        return ['erro' => 'Além de "cpf", o arquivo precisa de ao menos uma coluna entre: pis, matricula, cbo, admissao, desligamento.'];
    }

    $porCpf = [];
    $st = $pdo->query("SELECT id, name, cpf, pis, matricula, cbo, admission_date, dismissal_date, active FROM teachers");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $porCpf[preg_replace('/\D/', '', (string)$t['cpf'])] = $t;
    }
    // Quem já detém cada PIS — para acusar o mesmo PIS em duas pessoas.
    $donoDoPis = [];
    foreach ($porCpf as $t) {
        $pisT = preg_replace('/\D/', '', (string)($t['pis'] ?? ''));
        if ($pisT !== '') $donoDoPis[$pisT] = $t;
    }

    $itens = [];
    $cpfsNoArquivo = [];
    $pisNoArquivo  = [];

    for ($i = 1; $i < count($linhas); $i++) {
        $cols = str_getcsv($linhas[$i], $sep, '"', '\\');
        $get  = function (string $nome) use ($cols, $idx): string {
            return isset($idx[$nome]) ? trim((string)($cols[$idx[$nome]] ?? ''), " \t\"'") : '';
        };

        $cpf  = preg_replace('/\D/', '', $get('cpf'));
        $pis  = preg_replace('/\D/', '', $get('pis'));
        $matr = $get('matricula');
        $cbo  = preg_replace('/\D/', '', $get('cbo'));
        $adm  = normalizar_data_import($get('admissao'));
        $desl = normalizar_data_import($get('desligamento'));

        $item = [
            'linha'        => $i + 1,
            'cpf'          => $cpf,
            'pis'          => $pis,
            'matricula'    => $matr,
            'cbo'          => $cbo,
            'admissao'     => $adm === false ? '' : ($adm ?? ''),
            'desligamento' => $desl === false ? '' : ($desl ?? ''),
            'nome'         => '',
            'teacher_id'   => 0,
            'pis_atual'    => '',
            'status'       => '',
            'detalhe'      => '',
        ];

        if ($cpf === '') {
            $item['status'] = 'erro';  $item['detalhe'] = 'CPF em branco.';
        } elseif (!validate_cpf($cpf)) {
            $item['status'] = 'erro';  $item['detalhe'] = 'CPF inválido.';
        } elseif (isset($cpfsNoArquivo[$cpf])) {
            $item['status'] = 'erro';  $item['detalhe'] = 'CPF repetido no arquivo (linha ' . $cpfsNoArquivo[$cpf] . ').';
        } elseif (!isset($porCpf[$cpf])) {
            $item['status'] = 'erro';  $item['detalhe'] = 'Nenhum colaborador cadastrado com esse CPF.';
        } else {
            $t = $porCpf[$cpf];
            $item['nome']      = (string)$t['name'];
            $item['pis_atual'] = preg_replace('/\D/', '', (string)($t['pis'] ?? ''));

            if ($pis !== '' && !validate_pis($pis)) {
                $item['status'] = 'erro';  $item['detalhe'] = 'PIS inválido (dígito verificador não confere).';
            } elseif ($pis !== '' && isset($pisNoArquivo[$pis])) {
                $item['status'] = 'erro';  $item['detalhe'] = 'PIS repetido no arquivo (linha ' . $pisNoArquivo[$pis] . ').';
            } elseif ($pis !== '' && isset($donoDoPis[$pis]) && (int)$donoDoPis[$pis]['id'] !== (int)$t['id']) {
                $item['status'] = 'erro';  $item['detalhe'] = 'PIS já cadastrado para ' . $donoDoPis[$pis]['name'] . '.';
            } elseif ($adm === false || $desl === false) {
                $item['status'] = 'erro';  $item['detalhe'] = 'Data inválida — use AAAA-MM-DD ou DD/MM/AAAA.';
            } elseif ($adm !== null && $desl !== null && $desl < $adm) {
                $item['status'] = 'erro';  $item['detalhe'] = 'Desligamento anterior à admissão.';
            } else {
                $item['teacher_id'] = (int)$t['id'];

                // Campo vazio no CSV NÃO apaga o cadastrado — importação
                // parcial é o caso comum (uma planilha só com PIS, outra só
                // com matrícula).
                $mudancas = [];
                if ($pis  !== ''   && $pis  !== $item['pis_atual'])                    $mudancas[] = 'PIS';
                if ($matr !== ''   && $matr !== (string)($t['matricula'] ?? ''))       $mudancas[] = 'matrícula';
                if ($cbo  !== ''   && $cbo  !== (string)($t['cbo'] ?? ''))             $mudancas[] = 'CBO';
                if ($adm  !== null && $adm  !== (string)($t['admission_date'] ?? ''))  $mudancas[] = 'admissão';
                if ($desl !== null && $desl !== (string)($t['dismissal_date'] ?? ''))  $mudancas[] = 'desligamento';

                if (empty($mudancas)) {
                    $item['status']  = 'inalterado';
                    $item['detalhe'] = 'Os dados do arquivo já são os cadastrados.';
                } else {
                    // Trocar um PIS que já existe é o caso perigoso: ou é
                    // correção de erro, ou é o PIS da pessoa errada.
                    $trocaPis = $item['pis_atual'] !== '' && $pis !== '' && $pis !== $item['pis_atual'];
                    $item['status']  = $trocaPis ? 'substitui' : 'ok';
                    $item['detalhe'] = 'Atualiza: ' . implode(', ', $mudancas)
                        . ($trocaPis ? ' — SUBSTITUI o PIS ' . $item['pis_atual'] : '');
                }
                if ($pis !== '') $pisNoArquivo[$pis] = $i + 1;
            }
        }

        if ($cpf !== '') $cpfsNoArquivo[$cpf] = $i + 1;
        $itens[] = $item;
    }

    $resumo = ['ok' => 0, 'substitui' => 0, 'inalterado' => 0, 'erro' => 0];
    foreach ($itens as $it) $resumo[$it['status']]++;

    return ['itens' => $itens, 'resumo' => $resumo];
}

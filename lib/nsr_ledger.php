<?php
declare(strict_types=1);
/**
 * Livro fiscal append-only com NSR por marcação e cadeia de integridade.
 * ======================================================================
 * Fase 1 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * Resolve NC-08 (sem cadeia de integridade), NC-09 (saída sem NSR próprio),
 * NC-10 (NSR sem UNIQUE) e NC-11 (banco sem proteção).
 *
 * MODELO
 *   `nsr_ledger` é o livro fiscal: uma linha por MARCAÇÃO individual, imutável
 *   (triggers BEFORE UPDATE/DELETE), encadeada por HMAC-SHA256. É a fonte do
 *   AFD, do AEJ e do comprovante.
 *   `attendance` segue sendo a tabela operacional (entrada+saída na mesma
 *   linha), agora espelhando `nsr` (entrada) e `nsr_out` (saída).
 *
 * CADEIA
 *   record_hash = HMAC-SHA256(chave, prev_hash + "\n" + payload_canon)
 *   O prev_hash de cada linha é o record_hash da anterior. `uq_ledger_prev_hash`
 *   impede que duas linhas apontem para o mesmo predecessor, o que tornaria
 *   possível reescrever um ramo mantendo a cadeia "válida".
 *
 * PAYLOAD CANÔNICO
 *   String versionada delimitada por pipe — deliberadamente NÃO JSON.
 *   `json_encode` varia com ordem de chaves, flags de escape e versão do PHP;
 *   reproduzir o mesmo byte a byte daqui a cinco anos, num servidor diferente,
 *   seria uma aposta. Aqui a serialização é explícita e estável.
 *   Floats com precisão fixa (%.7f) para não depender de `precision` do php.ini.
 *
 * A VERIFICAÇÃO É DUPLA (ver nsr_ledger_verify):
 *   1. recomputa o canônico a partir das COLUNAS e compara com payload_canon
 *      — pega adulteração das colunas;
 *   2. confere o HMAC sobre prev_hash + payload_canon
 *      — pega adulteração do payload e da cadeia.
 *   Só (2) não bastaria: alguém poderia editar `marked_at` deixando
 *   `payload_canon` intacto, e a cadeia continuaria fechando.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}

const NSR_LEDGER_PAYLOAD_VERSION = 'v1';
const NSR_LEDGER_GENESIS_NSR     = 0;

// ---------------------------------------------------------------------------
// Chave e modo
// ---------------------------------------------------------------------------

/**
 * Chave HMAC do ledger. FAIL CLOSED: sem chave, não assina.
 *
 * Deliberadamente NÃO há fallback para app_settings (ao contrário de
 * kiosk_signing_secret). Se a chave morar no banco, quem obtiver acesso ao
 * banco altera uma marcação E recalcula a cadeia inteira — a prova de
 * integridade vira teatro. Gere com `php bin/ledger_keygen.php` e guarde em
 * config.local.php (fora do versionamento) ou em variável de ambiente.
 */
function ledger_hmac_key(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (defined('LEDGER_HMAC_KEY') && LEDGER_HMAC_KEY !== '') {
        return $cached = (string)LEDGER_HMAC_KEY;
    }
    $env = getenv('PONTO_LEDGER_HMAC_KEY');
    if ($env !== false && $env !== '') {
        return $cached = (string)$env;
    }
    throw new RuntimeException(
        'LEDGER_HMAC_KEY ausente — o livro fiscal não pode assinar registros. '
        . 'Gere com "php bin/ledger_keygen.php" e defina em config.local.php.'
    );
}

function ledger_hmac_key_id(): string {
    if (defined('LEDGER_HMAC_KEY_ID') && LEDGER_HMAC_KEY_ID !== '') {
        return (string)LEDGER_HMAC_KEY_ID;
    }
    $env = getenv('PONTO_LEDGER_HMAC_KEY_ID');
    return ($env !== false && $env !== '') ? (string)$env : 'k1';
}

/**
 * Modo de operação: 'off' | 'shadow' | 'enforced'.
 *
 *   off      — não grava nada (estado inicial).
 *   shadow   — grava, mas falha NUNCA derruba o registro de ponto. O try/catch
 *              fica no SITE DE CHAMADA, não aqui dentro: nsr_ledger_append()
 *              sempre propaga a exceção, ao contrário de audit_log(). A
 *              distinção importa — engolir erro dentro da função tornaria
 *              impossível o modo enforced funcionar.
 *   enforced — falha aborta a transação inteira.
 */
function nsr_ledger_mode(): string {
    $m = (string)(get_setting('ledger_mode', 'off') ?? 'off');
    return in_array($m, ['off', 'shadow', 'enforced'], true) ? $m : 'off';
}

function nsr_ledger_enabled(): bool {
    return nsr_ledger_mode() !== 'off';
}

// ---------------------------------------------------------------------------
// Serialização canônica e hash
// ---------------------------------------------------------------------------

/** Normaliza um valor para o payload canônico: NULL/'' viram string vazia. */
function nsr_ledger_field($v): string {
    if ($v === null || $v === false) return '';
    if (is_float($v)) return sprintf('%.7f', $v);
    return trim(str_replace(['|', "\n", "\r"], ' ', (string)$v));
}

/**
 * Aplica os valores padrão do evento UMA ÚNICA VEZ.
 *
 * Isto existe por causa de um bug real, encontrado pelo teste da cadeia: o
 * payload canônico usava `$e['record_mode'] ?? ''` enquanto o INSERT usava
 * `$e['record_mode'] ?? 'online'`. Um chamador que omitisse o campo assinava
 * um payload com string vazia e gravava 'online' na coluna — a verificação
 * recomputa o canônico A PARTIR DAS COLUNAS e nunca mais fechava. O registro
 * nascia com assinatura permanentemente inválida, e só um verificador rodando
 * revelaria isso, possivelmente meses depois.
 *
 * Normalizar antes, e derivar tanto o canônico quanto o INSERT do MESMO array,
 * elimina a classe inteira de erro. Qualquer default novo entra aqui, nunca nos
 * dois lugares.
 */
function nsr_ledger_normalize(array $e): array {
    $e['event_type']  = $e['event_type']  ?? 'mark';
    $e['origin']      = $e['origin']      ?? 'system';
    $e['record_mode'] = $e['record_mode'] ?? 'online';
    $e['hlb_status']  = $e['hlb_status']  ?? 'unknown';
    return $e;
}

/**
 * String canônica de um evento. A ORDEM DOS CAMPOS É PARTE DO CONTRATO:
 * alterá-la invalida todas as assinaturas existentes. Se um dia for preciso
 * mudar, incremente NSR_LEDGER_PAYLOAD_VERSION e mantenha o verificador capaz
 * de recomputar as versões antigas.
 */
function nsr_ledger_canonical(array $e): string {
    $lat = isset($e['lat']) && $e['lat'] !== null && $e['lat'] !== '' ? (float)$e['lat'] : null;
    $lng = isset($e['lng']) && $e['lng'] !== null && $e['lng'] !== '' ? (float)$e['lng'] : null;

    return implode('|', [
        NSR_LEDGER_PAYLOAD_VERSION,
        nsr_ledger_field($e['nsr'] ?? ''),
        nsr_ledger_field($e['event_type'] ?? 'mark'),
        nsr_ledger_field($e['teacher_cpf'] ?? ''),
        nsr_ledger_field($e['teacher_pis'] ?? ''),
        nsr_ledger_field($e['direction'] ?? ''),
        nsr_ledger_field($e['marked_at'] ?? ''),
        nsr_ledger_field($e['work_date'] ?? ''),
        nsr_ledger_field($e['record_type'] ?? ''),
        nsr_ledger_field($e['origin'] ?? ''),
        nsr_ledger_field($e['record_mode'] ?? ''),
        nsr_ledger_field($e['method'] ?? ''),
        $lat === null ? '' : sprintf('%.7f', $lat),
        $lng === null ? '' : sprintf('%.7f', $lng),
        nsr_ledger_field($e['device_identifier'] ?? ''),
        nsr_ledger_field($e['attendance_id'] ?? ''),
        nsr_ledger_field($e['target_nsr'] ?? ''),
        nsr_ledger_field($e['admin_id'] ?? ''),
        nsr_ledger_field($e['hlb_source'] ?? ''),
        nsr_ledger_field($e['hlb_offset_ms'] ?? ''),
    ]);
}

function nsr_ledger_hash(string $prevHash, string $payloadCanon, ?string $key = null): string {
    $key = $key ?? ledger_hmac_key();
    return hash_hmac('sha256', $prevHash . "\n" . $payloadCanon, $key);
}

/** Semente da cadeia — determinística por instalação, não secreta. */
function nsr_ledger_genesis_seed(): string {
    $cfg  = function_exists('get_employer_config') ? (get_employer_config() ?? []) : [];
    $cnpj = preg_replace('/\D/', '', (string)($cfg['cnpj'] ?? '')) ?: 'SEM-CNPJ';
    $name = (string)($cfg['company_name'] ?? 'SEM-NOME');
    return hash('sha256', 'PONTO-OEIRAS-CHAIN-V1|' . $cnpj . '|' . $name);
}

// ---------------------------------------------------------------------------
// Reserva de NSR + cabeça da cadeia (atômicos)
// ---------------------------------------------------------------------------

/**
 * Reserva o próximo NSR e devolve a cabeça atual da cadeia, na MESMA linha
 * travada de nsr_sequence. Exige transação aberta.
 *
 * O `SELECT ... FOR UPDATE` já existia em 5 pontos do código para serializar a
 * emissão de NSR; aproveitá-lo para carregar `last_record_hash` dá o prev_hash
 * de graça, sem `ORDER BY nsr DESC LIMIT 1` (que seria corrida + varredura).
 *
 * @return array{nsr:int,prev_hash:string,key_id:string}
 */
function nsr_ledger_reserve(PDO $pdo): array {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('nsr_ledger_reserve exige transação aberta.');
    }

    // `ledger_current_nsr` é EXCLUSIVO do livro. Antes usava-se `current_nsr`,
    // compartilhado com o emissor legado de attendance.nsr — e qualquer caminho
    // de escrita ainda não convertido consumia números sem gerar linha no livro,
    // abrindo buracos permanentes na numeração fiscal.
    $st = $pdo->query("SELECT ledger_current_nsr, last_record_hash FROM nsr_sequence WHERE id = 1 FOR UPDATE");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('nsr_sequence sem linha id=1 — instalação incompleta.');
    }

    $prev = (string)($row['last_record_hash'] ?? '');
    if ($prev === '') {
        // Cadeia ainda não iniciada: cria o registro de gênese aqui dentro,
        // na mesma transação e sob o mesmo lock.
        $prev = nsr_ledger_write_genesis($pdo);
    }

    return [
        'nsr'       => (int)$row['ledger_current_nsr'] + 1,
        'prev_hash' => $prev,
        'key_id'    => ledger_hmac_key_id(),
    ];
}

/**
 * NSR a usar no INSERT em `attendance`, respeitando quem é a autoridade.
 *
 * Corrige um defeito introduzido ao ligar o ledger: o emissor LEGADO de NSR
 * (`SELECT current_nsr ... FOR UPDATE` + `UPDATE nsr_sequence`) continuava
 * ativo nos endpoints, e o ledger incrementava a MESMA sequência. Cada marcação
 * consumia dois números — um para `attendance.nsr`, outro para o livro — e o
 * livro ficava com buracos permanentes de NSR. Buraco, para um auditor, é
 * registro emitido e ausente: exatamente o que não pode acontecer.
 *
 * A separação foi resolvida dando ao livro um contador EXCLUSIVO
 * (`nsr_sequence.ledger_current_nsr`, migration 2026_08_05_nsr_ledger_own_counter).
 * Com isso os dois emissores convivem sem interferência: este continua numerando
 * `attendance.nsr` a partir de `current_nsr`, e o livro numera a partir do seu.
 *
 * Manter este emissor sempre ativo (em vez de devolver um marcador quando o
 * livro está ligado) garante que `attendance.nsr` nunca fique com valor
 * inválido caso o espelho não chegue a rodar — o que acontece em modo `shadow`
 * quando o livro falha. Quando o espelho roda, ele sobrescreve com o NSR fiscal.
 *
 * Exige transação aberta (herdada do caminho de escrita que a chama).
 */
function nsr_legacy_reserve(PDO $pdo): int {
    $st  = $pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id = 1 FOR UPDATE");
    $cur = (int)$st->fetchColumn();
    $st->closeCursor();
    $next = $cur + 1;
    $pdo->prepare("UPDATE nsr_sequence SET current_nsr = ? WHERE id = 1")->execute([$next]);
    return $next;
}

/**
 * Grava o registro de gênese (NSR 0) e devolve seu record_hash.
 * Chamado por nsr_ledger_reserve() quando a cadeia ainda não existe.
 * Espera lock já obtido sobre nsr_sequence.
 */
function nsr_ledger_write_genesis(PDO $pdo): string {
    $keyId = ledger_hmac_key_id();
    $now   = date('Y-m-d H:i:s');
    $seed  = nsr_ledger_genesis_seed();

    // Normalizado pelo mesmo caminho dos demais eventos: os defaults do array
    // precisam bater com os defaults das COLUNAS, senão a gênese nasce com
    // assinatura que nunca fecha na verificação.
    $event = nsr_ledger_normalize([
        'nsr'        => NSR_LEDGER_GENESIS_NSR,
        'event_type' => 'chain_genesis',
        'origin'     => 'system',
        'marked_at'  => $now,
        'reason'     => 'inicio da cadeia de integridade',
    ]);
    $canon = nsr_ledger_canonical($event);
    $hash  = nsr_ledger_hash($seed, $canon);

    $pdo->prepare("INSERT INTO nsr_ledger
        (nsr, event_type, origin, record_mode, hlb_status, marked_at, reason,
         payload_canon, prev_hash, record_hash, hmac_key_id, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            NSR_LEDGER_GENESIS_NSR, $event['event_type'], $event['origin'],
            $event['record_mode'], $event['hlb_status'], $now,
            $event['reason'], $canon, $seed, $hash, $keyId, $now,
        ]);

    $pdo->prepare("UPDATE nsr_sequence
                      SET last_record_hash = ?, chain_key_id = ?, chain_started_at = ?
                    WHERE id = 1")
        ->execute([$hash, $keyId, $now]);

    return $hash;
}

// ---------------------------------------------------------------------------
// Escrita
// ---------------------------------------------------------------------------

/**
 * Acrescenta um evento ao livro fiscal. EXIGE transação aberta — a intenção é
 * que o append participe da mesma transação do INSERT/UPDATE em `attendance`,
 * de modo que ou os dois acontecem ou nenhum acontece.
 *
 * SEMPRE propaga exceção. Em modo `shadow`, quem trata é o site de chamada.
 *
 * @param array $e Campos do evento (ver colunas de nsr_ledger).
 * @return array{nsr:int,id:int,record_hash:string}
 */
function nsr_ledger_append(PDO $pdo, array $e): array {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('nsr_ledger_append exige transação aberta.');
    }

    $res = nsr_ledger_reserve($pdo);
    $e = nsr_ledger_normalize($e);
    $e['nsr'] = $res['nsr'];

    // Canônico e INSERT derivam do MESMO array normalizado — ver nsr_ledger_normalize().
    $canon = nsr_ledger_canonical($e);
    $hash  = nsr_ledger_hash($res['prev_hash'], $canon);
    $now   = date('Y-m-d H:i:s');

    // sql_mode ESTRITO só para esta gravação. Produção (MariaDB) roda sem
    // STRICT: um valor fora do ENUM ou maior que a coluna seria gravado truncado
    // em silêncio, enquanto `payload_canon`/`record_hash` foram calculados sobre
    // o valor original — a cadeia passaria a acusar "payload_divergente" para
    // sempre. Com STRICT o INSERT falha e o chamador decide (shadow/enforced).
    $modoAnterior = (string)$pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn();
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    try {
    $sql = "INSERT INTO nsr_ledger (
                nsr, event_type, teacher_id, teacher_cpf, teacher_pis, school_id,
                attendance_id, mark_role, record_type, direction, marked_at, work_date,
                origin, record_mode, method, recorded_at, client_recorded_at, synced_at,
                hlb_source, hlb_offset_ms, hlb_synced_at, hlb_status,
                lat, lng, acc, ip, device_identifier, device_fingerprint, photo,
                target_nsr, reason, admin_id, legacy_nsr,
                payload_canon, prev_hash, record_hash, hmac_key_id, created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $pdo->prepare($sql)->execute([
        $res['nsr'],
        $e['event_type'],
        $e['teacher_id'] ?? null,
        $e['teacher_cpf'] ?? null,
        $e['teacher_pis'] ?? null,
        $e['school_id'] ?? null,
        $e['attendance_id'] ?? null,
        $e['mark_role'] ?? null,
        $e['record_type'] ?? null,
        $e['direction'] ?? null,
        $e['marked_at'] ?? null,
        $e['work_date'] ?? null,
        $e['origin'],
        $e['record_mode'],
        $e['method'] ?? null,
        $e['recorded_at'] ?? null,
        $e['client_recorded_at'] ?? null,
        $e['synced_at'] ?? null,
        $e['hlb_source'] ?? null,
        $e['hlb_offset_ms'] ?? null,
        $e['hlb_synced_at'] ?? null,
        $e['hlb_status'],
        $e['lat'] ?? null,
        $e['lng'] ?? null,
        $e['acc'] ?? null,
        $e['ip'] ?? null,
        $e['device_identifier'] ?? null,
        $e['device_fingerprint'] ?? null,
        $e['photo'] ?? null,
        $e['target_nsr'] ?? null,
        $e['reason'] ?? null,
        $e['admin_id'] ?? null,
        $e['legacy_nsr'] ?? null,
        $canon, $res['prev_hash'], $hash, $res['key_id'], $now,
    ]);
    } finally {
        $pdo->prepare("SET SESSION sql_mode = ?")->execute([$modoAnterior]);
    }

    $id = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE nsr_sequence SET ledger_current_nsr = ?, last_record_hash = ? WHERE id = 1")
        ->execute([$res['nsr'], $hash]);

    return ['nsr' => $res['nsr'], 'id' => $id, 'record_hash' => $hash];
}

/**
 * CPF e PIS do colaborador, para congelar no evento do livro.
 *
 * Os endpoints de marcação já têm esses dados em mão; os caminhos
 * administrativos (ponto manual, edição de dia, regularizações) só têm o
 * teacher_id. Uma consulta a mais é irrelevante nesses fluxos — são ações
 * pontuais de admin, não o caminho quente do registro de ponto.
 *
 * Os valores são congelados no evento de propósito: se o cadastro mudar depois,
 * o livro tem que continuar mostrando quem era o trabalhador NAQUELE momento.
 *
 * @return array{cpf:?string,pis:?string}
 */
function nsr_ledger_teacher_ident(PDO $pdo, int $teacherId): array {
    static $cache = [];
    if (isset($cache[$teacherId])) return $cache[$teacherId];
    try {
        $st = $pdo->prepare("SELECT cpf, pis FROM teachers WHERE id = ? LIMIT 1");
        $st->execute([$teacherId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $r = [];
    }
    return $cache[$teacherId] = [
        'cpf' => $r['cpf'] ?? null,
        'pis' => $r['pis'] ?? null,
    ];
}

/**
 * Registra uma marcação aplicando a POLÍTICA do modo de operação.
 *
 * É este wrapper — e não nsr_ledger_append() — que os pontos de escrita devem
 * chamar. A separação é deliberada:
 *   - nsr_ledger_append() SEMPRE propaga a exceção. Se ele engolisse erro, o
 *     modo `enforced` seria impossível de implementar.
 *   - este wrapper concentra a decisão "derruba ou não derruba o ponto",
 *     evitando repetir o mesmo try/catch em 14 lugares (onde um esquecimento
 *     silencioso passaria despercebido).
 *
 * Em `shadow`, uma falha aqui NUNCA pode impedir o colaborador de bater ponto:
 * o registro de jornada é direito do trabalhador (art. 74 da CLT) e não pode
 * ser perdido por causa de um defeito no livro fiscal. A falha vai para o log.
 *
 * @return int|null NSR emitido, ou null se desligado / falhou em modo shadow
 */
function nsr_ledger_record_mark(PDO $pdo, array $e): ?int {
    $mode = nsr_ledger_mode();
    if ($mode === 'off') return null;

    // SAVEPOINT: em shadow, a falha do livro não pode deixar meio evento na
    // transação do ponto (contador de NSR do livro avançado sem a linha).
    $comSavepoint = ($mode !== 'enforced') && $pdo->inTransaction();
    if ($comSavepoint) {
        $pdo->exec('SAVEPOINT nsr_ledger_mark');
    }
    try {
        $nsrLivro = nsr_ledger_append($pdo, $e)['nsr'];
        if ($comSavepoint) {
            $pdo->exec('RELEASE SAVEPOINT nsr_ledger_mark');
        }
        return $nsrLivro;
    } catch (Throwable $ex) {
        if ($mode === 'enforced') {
            throw $ex; // aborta a transação junto com o INSERT em attendance
        }
        if ($comSavepoint) {
            try { $pdo->exec('ROLLBACK TO SAVEPOINT nsr_ledger_mark'); } catch (Throwable $ignored) {}
        }
        error_log('[nsr_ledger][shadow] falha ao registrar marcacao'
                  . ' att=' . (string)($e['attendance_id'] ?? '?')
                  . ' papel=' . (string)($e['mark_role'] ?? '?')
                  . ' erro=' . $ex->getMessage());
        try {
            set_setting('ledger_last_shadow_failure',
                        date('Y-m-d H:i:s') . '|' . substr($ex->getMessage(), 0, 180));
        } catch (Throwable $ignored) {}
        return null;
    }
}

/**
 * Registra um evento de CADASTRO no livro (tipos 2, 4 e 5 do AFD).
 *
 * Diferente de nsr_ledger_record_mark(), abre a própria transação quando não
 * há uma em curso: alterações de cadastro do empregador ou de colaborador
 * costumam acontecer fora de transação, e exigir que cada chamador abrisse uma
 * só para o livro seria convite a esquecimento.
 *
 * O AFD precisa desses eventos para reconstituir o contexto das marcações: quem
 * era o empregador, quem eram os trabalhadores e quando o relógio foi ajustado.
 * Sem eles o arquivo tem as marcações mas não a história em volta.
 *
 * @param string $tipo 'employer_change' | 'employee_change' | 'clock_adjust'
 */
function nsr_ledger_record_cadastro(PDO $pdo, string $tipo, array $e): ?int {
    if (nsr_ledger_mode() === 'off') return null;
    if (!in_array($tipo, ['employer_change', 'employee_change', 'clock_adjust'], true)) {
        throw new InvalidArgumentException("Tipo de evento de cadastro invalido: {$tipo}");
    }

    $e['event_type'] = $tipo;
    $e['origin']     = $e['origin'] ?? 'system';
    $e['marked_at']  = $e['marked_at'] ?? date('Y-m-d H:i:s');

    $propria = !$pdo->inTransaction();
    if ($propria) $pdo->beginTransaction();
    try {
        $nsr = nsr_ledger_append($pdo, $e)['nsr'];
        if ($propria) $pdo->commit();
        return $nsr;
    } catch (Throwable $ex) {
        if ($propria && $pdo->inTransaction()) $pdo->rollBack();
        if (nsr_ledger_mode() === 'enforced') throw $ex;
        error_log("[nsr_ledger][shadow] falha ao registrar evento {$tipo}: " . $ex->getMessage());
        return null;
    }
}

/**
 * Anula uma marcação. NÃO altera a linha original — insere um evento `void`
 * apontando para o NSR alvo. É o que mantém o livro append-only e permite os
 * triggers de imutabilidade.
 */
function nsr_ledger_void(PDO $pdo, int $targetNsr, ?int $adminId, string $reason): array {
    if (trim($reason) === '') {
        throw new InvalidArgumentException('Anulação no livro fiscal exige motivo.');
    }
    return nsr_ledger_append($pdo, [
        'event_type' => 'void',
        'target_nsr' => $targetNsr,
        'admin_id'   => $adminId,
        'reason'     => $reason,
        'origin'     => 'admin_edit',
        'marked_at'  => date('Y-m-d H:i:s'),
    ]);
}

/** Marcações registradas para uma linha de `attendance`. */
function nsr_ledger_marks_for(PDO $pdo, int $attendanceId): array {
    $st = $pdo->prepare("SELECT nsr, mark_role, direction, marked_at, record_type, record_hash
                           FROM nsr_ledger
                          WHERE attendance_id = ? AND event_type = 'mark'
                          ORDER BY nsr ASC");
    $st->execute([$attendanceId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
// Verificação
// ---------------------------------------------------------------------------

/**
 * Valida UMA linha do livro: payload, assinatura e elo com a anterior.
 *
 * Extraída de nsr_ledger_verify() para poder ser testada com uma linha
 * adulterada em memória. A alternativa seria derrubar o trigger de
 * imutabilidade para adulterar de verdade no banco — mas DROP TRIGGER é DDL e
 * provoca COMMIT implícito no MySQL, o que faria o teste vazar dados adulterados
 * para dentro de um livro que, por construção, não permite apagá-los.
 *
 * @param array       $row          linha de nsr_ledger
 * @param string|null $expectedPrev record_hash esperado do predecessor
 * @return array{tipo:string,detalhe:string}|null null se a linha está íntegra
 */
function nsr_ledger_verify_row(array $row, ?string $expectedPrev = null, ?string $key = null): ?array {
    $key = $key ?? ledger_hmac_key();

    // (1) O canônico recomputado das COLUNAS bate com o que foi assinado?
    $recomputed = nsr_ledger_canonical($row);
    if (!hash_equals((string)$row['payload_canon'], $recomputed)) {
        return ['tipo' => 'payload_divergente',
                'detalhe' => 'colunas nao correspondem ao payload assinado'];
    }

    // (2) O HMAC fecha sobre prev_hash + payload?
    $expectedHash = nsr_ledger_hash((string)$row['prev_hash'], (string)$row['payload_canon'], $key);
    if (!hash_equals((string)$row['record_hash'], $expectedHash)) {
        return ['tipo' => 'hash_invalido',
                'detalhe' => 'esperado ' . substr($expectedHash, 0, 16)
                             . ', encontrado ' . substr((string)$row['record_hash'], 0, 16)];
    }

    // (3) O elo aponta para o predecessor correto?
    if ($expectedPrev !== null && !hash_equals((string)$row['prev_hash'], $expectedPrev)) {
        return ['tipo' => 'cadeia_rompida',
                'detalhe' => 'prev_hash nao corresponde ao record_hash anterior'];
    }

    return null;
}

/**
 * Percorre a cadeia e valida cada elo.
 *
 * Usa cursor não-bufferizado: a verificação precisa funcionar com milhões de
 * linhas dentro do memory_limit de 256 MB fixado em .user.ini.
 *
 * @return array{ok:bool,checked:int,first_bad_nsr:?int,errors:array,gaps:array}
 */
function nsr_ledger_verify(PDO $pdo, ?int $from = null, ?int $to = null): array {
    $errors = [];
    $gaps   = [];
    $checked = 0;
    $firstBad = null;

    $key = ledger_hmac_key();

    $where = []; $params = [];
    if ($from !== null) { $where[] = 'nsr >= ?'; $params[] = $from; }
    if ($to !== null)   { $where[] = 'nsr <= ?'; $params[] = $to; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Cursor não-bufferizado: não carrega o resultado inteiro na memória.
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $st = $pdo->prepare("SELECT * FROM nsr_ledger {$whereSql} ORDER BY nsr ASC");
        $st->execute($params);

        $expectedPrev = null;
        $lastNsr = null;

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $checked++;
            $nsr = (int)$row['nsr'];

            // Buracos na sequência.
            //
            // A transição gênese (NSR 0) → primeiro evento é ignorada de
            // propósito: a sequência de marcações não começa necessariamente em
            // 1. Quando o ledger é ligado sobre uma base que já usava
            // `nsr_sequence` para `attendance`, o primeiro NSR do livro continua
            // de onde o contador legado parou. Contar isso como buraco produziria
            // um alarme falso permanente, e alarme que sempre dispara deixa de
            // ser lido. Depois do backfill (que renumera cronologicamente a
            // partir de 1) a sequência fica contígua de qualquer forma.
            $afterGenesis = ($lastNsr === NSR_LEDGER_GENESIS_NSR
                             && $row['event_type'] !== 'chain_genesis');
            if ($lastNsr !== null && !$afterGenesis && $nsr !== $lastNsr + 1) {
                $gaps[] = ['after' => $lastNsr, 'next' => $nsr, 'missing' => $nsr - $lastNsr - 1];
            }
            $lastNsr = $nsr;

            $problem = nsr_ledger_verify_row($row, $expectedPrev, $key);
            if ($problem !== null) {
                $errors[] = ['nsr' => $nsr] + $problem;
                $firstBad = $firstBad ?? $nsr;
                break; // para na primeira divergência: o resto da cadeia perde o sentido
            }

            $expectedPrev = (string)$row['record_hash'];
        }
        $st->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    return [
        'ok'            => empty($errors),
        'checked'       => $checked,
        'first_bad_nsr' => $firstBad,
        'errors'        => $errors,
        'gaps'          => $gaps,
    ];
}

/**
 * Compara `attendance` com o livro fiscal e denuncia divergências.
 *
 * Este é o detector prático de "alguém editou pelo phpMyAdmin": o ledger
 * continuaria íntegro (ninguém o tocou), mas divergente da tabela operacional.
 * A verificação da cadeia sozinha não pegaria isso.
 */
function nsr_ledger_reconcile(PDO $pdo, string $from, string $to): array {
    $st = $pdo->prepare("
        SELECT a.id, a.nsr, a.nsr_out, a.check_in, a.check_out, a.date,
               li.marked_at AS ledger_in, lo.marked_at AS ledger_out
          FROM attendance a
          LEFT JOIN nsr_ledger li ON li.nsr = a.nsr     AND li.event_type = 'mark'
          LEFT JOIN nsr_ledger lo ON lo.nsr = a.nsr_out AND lo.event_type = 'mark'
         WHERE a.date BETWEEN ? AND ?
         ORDER BY a.date ASC, a.id ASC
    ");
    $st->execute([$from, $to]);

    $drift = [];
    $rows = 0;
    foreach ($st as $r) {
        $rows++;
        if ($r['check_in'] !== null && $r['ledger_in'] !== null && $r['check_in'] !== $r['ledger_in']) {
            $drift[] = ['attendance_id' => (int)$r['id'], 'campo' => 'check_in',
                        'attendance' => $r['check_in'], 'ledger' => $r['ledger_in']];
        }
        if ($r['check_out'] !== null && $r['ledger_out'] !== null && $r['check_out'] !== $r['ledger_out']) {
            $drift[] = ['attendance_id' => (int)$r['id'], 'campo' => 'check_out',
                        'attendance' => $r['check_out'], 'ledger' => $r['ledger_out']];
        }
        if ($r['check_in'] !== null && $r['nsr'] !== null && $r['ledger_in'] === null) {
            $drift[] = ['attendance_id' => (int)$r['id'], 'campo' => 'check_in',
                        'attendance' => $r['check_in'], 'ledger' => '(sem marcacao no livro)'];
        }
    }

    return ['ok' => empty($drift), 'rows' => $rows, 'drift' => $drift];
}

<?php
declare(strict_types=1);
/**
 * LGPD — consentimento, criptografia de biometria, retenção e anonimização.
 * =========================================================================
 * Fase 7 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md.
 *
 * Resolve NC-31 (consentimento só em localStorage), NC-32 (sem consentimento
 * para geolocalização), NC-34 (biometria em texto plano), NC-35 (sem
 * anonimização), NC-36 (sem registro de tratamento) e NC-37 (retenção por
 * volume, não por prazo).
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/../config.php';
}

// ===========================================================================
// Registro de operações de tratamento (art. 37)
// ===========================================================================

/**
 * Registra uma operação sobre dados pessoais.
 *
 * O art. 37 exige que o controlador mantenha registro das operações. Aqui ele é
 * alimentado pelo próprio sistema, a cada operação — em vez de ser uma planilha
 * mantida à mão, que é como esse registro costuma deixar de refletir a
 * realidade.
 */
function lgpd_log(PDO $pdo, string $operation, string $category, string $legalBasis,
                  ?int $teacherId = null, int $affected = 0, ?string $detail = null): void {
    try {
        $actor = !empty($_SESSION['admin_id']) ? 'admin:' . (int)$_SESSION['admin_id']
               : (php_sapi_name() === 'cli' ? 'cron' : 'sistema');
        $pdo->prepare("INSERT INTO lgpd_processing_log
            (operation, data_category, legal_basis, teacher_id, affected, actor, detail)
            VALUES (?,?,?,?,?,?,?)")
            ->execute([$operation, $category, $legalBasis, $teacherId, $affected, $actor,
                       $detail !== null ? mb_substr($detail, 0, 255) : null]);
    } catch (Throwable $e) {
        // Não interrompe a operação, mas não silencia: um registro de tratamento
        // que para de gravar sem aviso é exatamente o que o art. 37 não admite.
        error_log('[lgpd_log] falha ao registrar tratamento (' . $operation . '): ' . $e->getMessage());
    }
}

// ===========================================================================
// Consentimento
// ===========================================================================

const LGPD_PURPOSES = ['biometria', 'geolocalizacao', 'foto'];

function lgpd_term_version(): string {
    return (string)(get_setting('lgpd_term_version', '1.0') ?? '1.0');
}

/**
 * Registra o consentimento do titular para uma finalidade específica.
 *
 * O art. 9º exige finalidade ESPECÍFICA: consentir com o uso de biometria não
 * é consentir com rastreamento de localização. Por isso cada finalidade tem seu
 * próprio registro, e o `term_hash` guarda o texto exato aceito — sem ele o
 * registro provaria a data, mas não o conteúdo do que a pessoa concordou.
 */
function lgpd_consent_give(PDO $pdo, int $teacherId, string $purpose,
                           string $termText = '', string $source = 'portal'): bool {
    if (!in_array($purpose, LGPD_PURPOSES, true)) {
        throw new InvalidArgumentException("Finalidade LGPD desconhecida: {$purpose}");
    }
    try {
        $pdo->prepare("INSERT INTO lgpd_consent
            (teacher_id, consent_given, consent_date, ip_address, user_agent,
             purpose, term_version, term_hash, source)
            VALUES (?, 1, NOW(), ?, ?, ?, ?, ?, ?)")
            ->execute([
                $teacherId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                $purpose,
                lgpd_term_version(),
                $termText !== '' ? hash('sha256', $termText) : null,
                $source,
            ]);
        lgpd_log($pdo, 'consent_given', $purpose === 'biometria' ? 'biometrico' : 'geolocalizacao',
                 'consentimento', $teacherId, 1, "finalidade={$purpose} termo=" . lgpd_term_version());
        return true;
    } catch (Throwable $e) {
        error_log('[lgpd_consent_give] ' . $e->getMessage());
        return false;
    }
}

/**
 * Revoga o consentimento (art. 8º §5º).
 *
 * Revogar não apaga o registro anterior: marca-o como revogado, preservando o
 * histórico de que houve consentimento naquele período. Apagar destruiria a
 * prova de que o tratamento anterior era lícito.
 */
function lgpd_consent_revoke(PDO $pdo, int $teacherId, string $purpose, string $reason = ''): int {
    try {
        $st = $pdo->prepare("UPDATE lgpd_consent
                                SET revoked_at = NOW(), revoked_reason = ?
                              WHERE teacher_id = ? AND purpose = ?
                                AND consent_given = 1 AND revoked_at IS NULL");
        $st->execute([mb_substr($reason, 0, 255) ?: null, $teacherId, $purpose]);
        $n = $st->rowCount();
        if ($n > 0) {
            lgpd_log($pdo, 'consent_revoked', $purpose === 'biometria' ? 'biometrico' : 'geolocalizacao',
                     'consentimento', $teacherId, $n, "finalidade={$purpose}");
        }
        return $n;
    } catch (Throwable $e) {
        error_log('[lgpd_consent_revoke] ' . $e->getMessage());
        return 0;
    }
}

/** Há consentimento vigente para esta finalidade? */
function lgpd_has_consent(PDO $pdo, int $teacherId, string $purpose): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM lgpd_consent
                              WHERE teacher_id = ? AND purpose = ?
                                AND consent_given = 1 AND revoked_at IS NULL
                              LIMIT 1");
        $st->execute([$teacherId, $purpose]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Situação de todas as finalidades, para exibir ao titular. */
function lgpd_consent_status(PDO $pdo, int $teacherId): array {
    $out = [];
    foreach (LGPD_PURPOSES as $p) {
        $st = $pdo->prepare("SELECT consent_date, term_version, revoked_at
                               FROM lgpd_consent
                              WHERE teacher_id = ? AND purpose = ? AND consent_given = 1
                              ORDER BY id DESC LIMIT 1");
        $st->execute([$teacherId, $p]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $out[$p] = [
            'vigente'    => $r && empty($r['revoked_at']),
            'data'       => $r['consent_date'] ?? null,
            'versao'     => $r['term_version'] ?? null,
            'revogado_em'=> $r['revoked_at'] ?? null,
        ];
    }
    return $out;
}

// ===========================================================================
// Biometria criptografada em repouso (NC-34)
// ===========================================================================

/**
 * Chave de criptografia da biometria.
 *
 * Sem chave, o sistema NÃO cifra dados novos — mas continua lendo os antigos em
 * texto plano. É degradação deliberada: travar o reconhecimento facial inteiro
 * porque falta uma chave impediria as pessoas de bater ponto, o que é pior que
 * o risco que se quer mitigar. A ausência é registrada no log.
 */
function lgpd_biometric_key(): ?string {
    static $cached = false;
    if ($cached !== false) return $cached;

    $raw = '';
    if (defined('BIOMETRIC_ENCRYPTION_KEY') && BIOMETRIC_ENCRYPTION_KEY !== '') {
        $raw = (string)BIOMETRIC_ENCRYPTION_KEY;
    } else {
        $env = getenv('PONTO_BIOMETRIC_KEY');
        if ($env !== false && $env !== '') $raw = (string)$env;
    }
    if ($raw === '') return $cached = null;

    $bin = base64_decode($raw, true);
    if ($bin === false || strlen($bin) !== 32) {
        $bin = hash('sha256', $raw, true); // aceita passphrase, deriva 32 bytes
    }
    return $cached = $bin;
}

/**
 * Serializa descritores faciais para gravação, cifrando quando há chave.
 *
 * AES-256-GCM: além de cifrar, autentica — um descritor adulterado no banco
 * falha ao decifrar em vez de virar um vetor válido qualquer.
 *
 * O resultado é um ENVELOPE JSON, não uma string opaca. A coluna
 * `teachers.face_descriptors` tem um CHECK `json_valid()`, e essa constraint
 * protege contra gravar lixo — preferível manter e caber nela a removê-la.
 * Formato: {"enc":"v1","d":"<base64 de iv|tag|ciphertext>"}
 */
function face_descriptors_encode(array $descriptors): string {
    $json = (string)json_encode($descriptors, JSON_UNESCAPED_UNICODE);
    $key  = lgpd_biometric_key();
    if ($key === null) return $json; // sem chave: texto plano, como antes

    $iv  = random_bytes(12); // GCM
    $tag = '';
    $ct  = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        error_log('[lgpd] falha ao cifrar biometria — gravando em texto plano');
        return $json;
    }
    return (string)json_encode(['enc' => 'v1', 'd' => base64_encode($iv . $tag . $ct)]);
}

/**
 * Lê descritores faciais, decifrando quando necessário.
 *
 * Aceita os dois formatos de propósito: o legado em texto plano e o envelope
 * cifrado. É o que permite migrar as linhas existentes sem janela de
 * indisponibilidade e sem quebrar o reconhecimento no meio do caminho —
 * enquanto a migração roda, as duas formas convivem.
 */
function face_descriptors_decode(?string $stored): array {
    $stored = trim((string)$stored);
    if ($stored === '') return [];

    $a = json_decode($stored, true);
    if (!is_array($a)) return [];

    // Formato legado: lista de vetores.
    if (!isset($a['enc'])) return $a;

    $key = lgpd_biometric_key();
    if ($key === null) {
        error_log('[lgpd] biometria cifrada mas BIOMETRIC_ENCRYPTION_KEY nao esta configurada');
        return [];
    }
    $bin = base64_decode((string)($a['d'] ?? ''), true);
    if ($bin === false || strlen($bin) < 29) return [];

    $iv   = substr($bin, 0, 12);
    $tag  = substr($bin, 12, 16);
    $ct   = substr($bin, 28);
    $json = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($json === false) {
        error_log('[lgpd] falha ao decifrar biometria — dado adulterado ou chave trocada');
        return [];
    }
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

/** O valor armazenado já está cifrado? */
function face_descriptors_is_encrypted(?string $stored): bool {
    $a = json_decode(trim((string)$stored), true);
    return is_array($a) && isset($a['enc']);
}

/** Cifra as linhas ainda em texto plano. Idempotente. */
function lgpd_encrypt_existing_biometrics(PDO $pdo, bool $dryRun = true): array {
    if (lgpd_biometric_key() === null) {
        return ['ok' => false, 'motivo' => 'BIOMETRIC_ENCRYPTION_KEY nao configurada', 'total' => 0, 'cifradas' => 0];
    }
    $rows = $pdo->query("SELECT id, face_descriptors FROM teachers
                          WHERE face_descriptors IS NOT NULL AND face_descriptors <> ''")
                ->fetchAll(PDO::FETCH_ASSOC);

    $total = count($rows); $cifradas = 0;
    $upd = $pdo->prepare("UPDATE teachers SET face_descriptors = ? WHERE id = ?");
    foreach ($rows as $r) {
        if (face_descriptors_is_encrypted($r['face_descriptors'])) continue;
        $desc = face_descriptors_decode($r['face_descriptors']);
        if (!$desc) continue;
        if (!$dryRun) $upd->execute([face_descriptors_encode($desc), (int)$r['id']]);
        $cifradas++;
    }
    if (!$dryRun && $cifradas > 0) {
        lgpd_log($pdo, 'biometric_encrypt', 'biometrico', 'obrigacao_legal', null, $cifradas,
                 'cifragem em repouso AES-256-GCM');
    }
    return ['ok' => true, 'motivo' => '', 'total' => $total, 'cifradas' => $cifradas];
}

// ===========================================================================
// Retenção (NC-37 / NC-40)
// ===========================================================================

/**
 * Expurga logs além do prazo de retenção.
 *
 * `audit_logs` e `attendance_audit_log` guardam a trilha exigida pela Portaria —
 * prazo longo (5 anos por padrão). `auth_attempt_logs` é operacional, serve ao
 * rate limit e à investigação de incidentes: 1 ano basta e mantê-lo além disso
 * é acumular dado pessoal sem finalidade.
 */
function lgpd_purge_logs(PDO $pdo, bool $dryRun = true): array {
    $conf = [
        'auth_attempt_logs'    => (int)(get_setting('retention_auth_logs_days', '365') ?? 365),
        'audit_logs'           => (int)(get_setting('retention_audit_logs_days', '1825') ?? 1825),
        'attendance_audit_log' => (int)(get_setting('retention_audit_logs_days', '1825') ?? 1825),
        'lgpd_processing_log'  => (int)(get_setting('retention_audit_logs_days', '1825') ?? 1825),
    ];
    $res = [];
    foreach ($conf as $tabela => $dias) {
        if ($dias <= 0) { $res[$tabela] = ['dias' => $dias, 'alvo' => 0, 'apagados' => 0, 'nota' => 'retencao desativada']; continue; }
        $coluna = $tabela === 'lgpd_processing_log' ? 'occurred_at' : 'created_at';
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `{$tabela}` WHERE `{$coluna}` < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $st->execute([$dias]);
            $alvo = (int)$st->fetchColumn();
            $apagados = 0;
            if (!$dryRun && $alvo > 0) {
                // Em lotes: DELETE de dezenas de milhares de linhas de uma vez
                // trava a tabela e derruba o registro de ponto junto.
                do {
                    $d = $pdo->prepare("DELETE FROM `{$tabela}` WHERE `{$coluna}` < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1000");
                    $d->execute([$dias]);
                    $n = $d->rowCount();
                    $apagados += $n;
                } while ($n > 0);
            }
            $res[$tabela] = ['dias' => $dias, 'alvo' => $alvo, 'apagados' => $apagados];
        } catch (Throwable $e) {
            $res[$tabela] = ['erro' => $e->getMessage()];
        }
    }
    if (!$dryRun) {
        $tot = array_sum(array_column($res, 'apagados'));
        if ($tot > 0) lgpd_log($pdo, 'purge_logs', 'identificacao', 'obrigacao_legal', null, $tot, 'expurgo por prazo');
    }
    return $res;
}

// ===========================================================================
// Anonimização (NC-35)
// ===========================================================================

/**
 * Anonimiza um colaborador desligado, preservando os registros de jornada.
 *
 * Anonimizar NÃO é apagar. Os registros de ponto precisam sobreviver pelo prazo
 * legal — a Portaria exige a preservação do NSR e o livro fiscal é imutável por
 * construção. O que sai são os dados que IDENTIFICAM a pessoa e não têm mais
 * finalidade: nome, CPF, e-mail, PIN, biometria, dispositivos.
 *
 * O vínculo estatístico permanece (as marcações continuam ligadas ao mesmo
 * teacher_id), mas deixa de apontar para uma pessoa identificável — que é o que
 * o art. 12 chama de dado anonimizado.
 *
 * O CPF é substituído por um valor derivado determinístico, não por NULL: a
 * coluna participa de índices e de unicidade, e nulificá-la quebraria consultas
 * históricas. Guardar o hash permite ainda responder "esta pessoa consta?" sem
 * armazenar o CPF.
 */
function lgpd_anonymize_teacher(PDO $pdo, int $teacherId, int $adminId, string $reason = ''): array {
    $st = $pdo->prepare("SELECT id, name, cpf, active, anonymized_at FROM teachers WHERE id = ? LIMIT 1");
    $st->execute([$teacherId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new RuntimeException('Colaborador inexistente.');
    if (!empty($t['anonymized_at'])) return ['ok' => false, 'motivo' => 'ja anonimizado'];
    if ((int)$t['active'] === 1) {
        throw new RuntimeException('Colaborador ativo nao pode ser anonimizado. Inative primeiro.');
    }

    $pdo->beginTransaction();
    try {
        $token = substr(hash('sha256', 'anon|' . $teacherId . '|' . (string)$t['cpf']), 0, 11);

        $pdo->prepare("UPDATE teachers SET
                          name = ?, cpf = ?, email = NULL, pin_hash = NULL,
                          face_descriptors = NULL, face_enrolled_at = NULL,
                          pis = NULL, matricula = NULL,
                          anonymized_at = NOW()
                        WHERE id = ?")
            ->execute(['Colaborador anonimizado #' . $teacherId, $token, $teacherId]);

        // Vínculos que permitiriam reidentificar pelo dispositivo.
        foreach (['teacher_trusted_devices', 'collaborator_remember_tokens'] as $tab) {
            try { $pdo->prepare("DELETE FROM `{$tab}` WHERE teacher_id = ?")->execute([$teacherId]); }
            catch (Throwable $e) { /* tabela pode nao existir */ }
        }

        // Fotos: imagem de rosto é dado biométrico e não tem finalidade após o
        // desligamento. A LINHA de attendance permanece — o registro legal não
        // pode ser apagado; só a imagem sai.
        $fotos = 0;
        $stF = $pdo->prepare("SELECT id, photo FROM attendance
                               WHERE teacher_id = ? AND photo IS NOT NULL AND photo <> '' AND photo_deleted = 0");
        $stF->execute([$teacherId]);
        $updF = $pdo->prepare("UPDATE attendance SET photo_deleted = 1, photo_deleted_at = NOW() WHERE id = ?");
        $base = __DIR__ . '/../public/';
        foreach ($stF->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $nome = basename(str_replace('\\', '/', (string)$f['photo']));
            $abs  = $base . 'photos/' . $nome;
            if (is_file($abs)) @unlink($abs);
            $updF->execute([(int)$f['id']]);
            $fotos++;
        }

        audit_log('anonymize', 'teacher', $teacherId, ['reason' => $reason, 'admin_id' => $adminId, 'fotos' => $fotos]);
        lgpd_log($pdo, 'anonymize', 'identificacao', 'obrigacao_legal', $teacherId, 1,
                 'fotos removidas: ' . $fotos . ($reason !== '' ? ' | ' . $reason : ''));

        $pdo->commit();
        return ['ok' => true, 'fotos_removidas' => $fotos];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

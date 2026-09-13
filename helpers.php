<?php
declare(strict_types=1);

/**
 * Sistema de auto-migração — executa arquivos SQL de sql/migrations/ que
 * ainda não foram aplicados, com garantias para produção:
 *
 *   1. ADVISORY LOCK (GET_LOCK) — múltiplos workers concorrentes (Apache
 *      mpm_prefork, FPM com vários processos) não rodam a mesma migration
 *      em paralelo. Quem perdeu o lock pula silenciosamente.
 *   2. HASH SHA-256 do conteúdo gravado em applied_migrations — detecta
 *      drift (alguém editou um migration JÁ aplicado). Em drift, reexecuta
 *      para reconciliar e REGRAVA o content_sha — loga uma vez, não a cada
 *      requisição. Não bloqueia o restante do boot.
 *   3. TELEMETRIA — cada migration aplicada loga em error_log com tempo,
 *      número de statements, status. Admin pode auditar.
 *   4. KILL SWITCH — env var PONTO_DISABLE_AUTO_MIGRATIONS=1 desativa
 *      tudo (caso admin precise aplicar manualmente em janela controlada).
 *   5. Reconciliação por content_sha — a chave é o filename; se o conteúdo
 *      for idêntico, a migration NÃO roda de novo. Se o content_sha divergir
 *      (drift), reexecuta UMA vez para sincronizar banco e arquivo e grava o
 *      novo hash, encerrando a re-detecção.
 *   6. Tolerância de erros "esperados" (duplicate column/key/entry).
 *   7. Rastreio de FALHAS persistentes em applied_migrations.failure_reason
 *      para visibilidade do admin.
 */
function run_auto_migrations(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    // Kill switch: deploy controlado pelo admin
    if (getenv('PONTO_DISABLE_AUTO_MIGRATIONS') === '1') {
        error_log('[migrations] desativado via PONTO_DISABLE_AUTO_MIGRATIONS=1');
        return;
    }

    // HOTFIX 2026-09 (desempenho): antes, TODA requisição abria uma 2ª conexão,
    // rodava CREATE TABLE + 4 ALTER TABLE (que falham com 1060), pegava um lock
    // GLOBAL de até 30 s e fazia sha256 de todos os .sql. Agora uma assinatura
    // barata do diretório (nome+tamanho+mtime) é comparada com a da última
    // execução completa; só roda o runner quando algum arquivo mudou (deploy).
    // Semântica preservada: o runner já pulava arquivos inalterados.
    $__migDir = __DIR__ . '/sql/migrations';
    $__migSig = null;
    $__migStamp = __DIR__ . '/logs/.migrations_ok_' . md5((string)DB_NAME);
    if (is_dir($__migDir)) {
        $__parts = [];
        foreach ((glob($__migDir . '/*.sql') ?: []) as $__f) {
            $__parts[] = basename($__f) . ':' . @filesize($__f) . ':' . @filemtime($__f);
        }
        sort($__parts);
        $__migSig = sha1(implode('|', $__parts));
        if (is_readable($__migStamp) && trim((string)@file_get_contents($__migStamp)) === $__migSig) {
            return;
        }
    }

    $migPdo = null;
    $haveLock = false;
    $lockName = null; // H2: definido antes do try para o finally sempre ter valor válido

    try {
        // Conexão SEPARADA para migrações — evita "unbuffered queries" no PDO compartilhado
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $migPdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,  // Emulação ativada para multi-statement
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);

        // Criar tabela de controle se não existir (versão hardenizada)
        $migPdo->exec("CREATE TABLE IF NOT EXISTS applied_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            content_sha CHAR(64) NULL,
            duration_ms INT NULL,
            statement_count INT NULL,
            failure_reason TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Migration legada não tinha as colunas extras — adiciona se faltarem.
        // H3: filtra APENAS code 1060 (Duplicate column) — outros erros (ex:
        // 1142 access denied, 1213 deadlock) são propagados em vez de
        // silenciados, evitando schema parcialmente migrado sem aviso.
        foreach ([
            "ALTER TABLE applied_migrations ADD COLUMN content_sha CHAR(64) NULL",
            "ALTER TABLE applied_migrations ADD COLUMN duration_ms INT NULL",
            "ALTER TABLE applied_migrations ADD COLUMN statement_count INT NULL",
            "ALTER TABLE applied_migrations ADD COLUMN failure_reason TEXT NULL",
        ] as $alter) {
            try {
                $migPdo->exec($alter);
            } catch (PDOException $e) {
                if ((int)$e->errorInfo[1] !== 1060) throw $e;
            }
        }

        // Advisory lock global — máx 30s de wait. Se outro worker estiver
        // rodando migrations, perdemos o lock e voltamos depois.
        $lockName = 'ponto_auto_migrations_' . md5(DB_NAME); // sobrescreve o null inicial
        $lockStmt = $migPdo->prepare("SELECT GET_LOCK(?, 30) AS got");
        $lockStmt->execute([$lockName]);
        $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
        $haveLock = ($lockRow && (int)$lockRow['got'] === 1);
        if (!$haveLock) {
            error_log('[migrations] outro worker está rodando — saindo');
            return;
        }

        // Buscar migrações já aplicadas (filename + content_sha)
        $applied = [];
        $stmt = $migPdo->query("SELECT filename, content_sha, failure_reason FROM applied_migrations");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $applied[$row['filename']] = [
                'sha'    => $row['content_sha'] ?? null,
                'failed' => ($row['failure_reason'] !== null && $row['failure_reason'] !== ''),
            ];
        }
        $stmt->closeCursor();

        // Listar arquivos SQL na pasta de migrações
        $migrationsDir = __DIR__ . '/sql/migrations';
        if (!is_dir($migrationsDir)) return;

        $files = glob($migrationsDir . '/*.sql');
        if (empty($files)) return;
        sort($files); // Ordem alfabética garante sequência

        $appliedCount = 0;
        $skippedCount = 0;
        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $sql = file_get_contents($filePath);
            if (empty(trim($sql))) { $skippedCount++; continue; }
            // Hash NORMALIZADO para LF. Os arquivos em produção têm CRLF (vieram de
            // checkout Windows) e os do git têm LF: o hash cru divergia em 47
            // migrations sem nenhuma mudança real de conteúdo, e o runner as
            // reexecutava todas no primeiro request pós-deploy (ALTERs na
            // attendance dentro de uma requisição web). Hashes antigos (crus, CRLF
            // ou LF) continuam sendo reconhecidos como "inalterado".
            $sqlLf       = str_replace("\r\n", "\n", $sql);
            $contentSha  = hash('sha256', $sqlLf);
            $knownShas   = [$contentSha, hash('sha256', $sql), hash('sha256', str_replace("\n", "\r\n", $sqlLf))];

            // Já aplicada? Decide entre PULAR e REEXECUTAR:
            //   - content_sha bate (ou é legado/null) → conteúdo inalterado, pula.
            //   - content_sha diverge (drift) → o arquivo mudou após ter sido
            //     aplicado. Reexecuta UMA vez para reconciliar o banco com o
            //     arquivo; o caminho de sucesso abaixo (INSERT ... ON DUPLICATE
            //     KEY UPDATE) regrava o content_sha, encerrando o drift. Sem essa
            //     reexecução o hash gravado nunca era atualizado e o DRIFT era
            //     relogado em TODA requisição (re-detecção perpétua). Migrations
            //     deste projeto são idempotentes (CREATE OR REPLACE / IF NOT
            //     EXISTS) e o runner tolera erros de idempotência — reexecutar é
            //     seguro. Migrations legadas sem content_sha (null) nunca são
            //     forçadas a rodar de novo.
            if (array_key_exists($filename, $applied)) {
                $prev = $applied[$filename];
                $contentChanged = ($prev['sha'] !== null && !in_array($prev['sha'], $knownShas, true));
                if (!$contentChanged) {
                    $skippedCount++;
                    continue;
                }
                $driftKind = $prev['failed'] ? 'falha anterior + conteúdo alterado' : 'drift — reconciliando content_sha';
                error_log("[migrations] reexecutando {$filename} ({$driftKind}; esperado={$prev['sha']}, atual={$contentSha})");
                // cai para a reexecução abaixo
            }

            // M1: Remover comentários de linha (-- ... ou # ...) para evitar problemas ao executar.
            // O `#` é também comentário válido em MySQL e é comum em dumps; sem ele, migrations
            // futuras com `# comentário` poderiam estourar o parser ingênuo de split por `;`.
            $cleanSql = preg_replace('/^(?:--|#).*$/m', '', $sql);
            $cleanSql = trim($cleanSql);
            if (empty($cleanSql)) { $skippedCount++; continue; }

            $startedAt = microtime(true);
            $stmtCount = 0;
            $errMsg = null;

            try {
                // Multi-statement primeiro. query() + nextRowset() em vez de exec():
                // migrations com SELECT de verificação deixavam um result set não
                // lido na conexão, e TODOS os comandos seguintes (inclusive das
                // próximas migrations) falhavam com 2014 "unbuffered queries".
                $stMulti = $migPdo->query($cleanSql);
                if ($stMulti !== false) {
                    do {
                        if ($stMulti->columnCount() > 0) { $stMulti->fetchAll(); }
                    } while ($stMulti->nextRowset());
                    $stMulti->closeCursor();
                }
                $stmtCount = 1; // contagem aproximada
            } catch (PDOException $e) {
                // Fallback: statement por statement (split simples por ;).
                $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
                $stmtCount = count($statements);
                $hadFatal = false;
                foreach ($statements as $statement) {
                    if (empty($statement)) continue;
                    try {
                        $stOne = $migPdo->query($statement);
                        if ($stOne !== false) {
                            if ($stOne->columnCount() > 0) { $stOne->fetchAll(); }
                            $stOne->closeCursor();
                        }
                    } catch (PDOException $e2) {
                        $code = (int)$e2->errorInfo[1];
                        // Códigos esperados quando re-rodando (idempotência defensiva):
                        // 1050 = Table exists, 1060 = Duplicate column, 1061 = Duplicate key,
                        // 1062 = Duplicate entry (UNIQUE), 1068 = Multiple primary key,
                        // 1091 = Can't DROP (não existe), 1146 = Base table doesn't exist
                        // (tabelas opcionais que podem não existir em todos os ambientes —
                        // ex: migration de normalização de collation rodando em local que
                        // não tem todas as tabelas de produção).
                        // 1826 = Duplicate FK constraint name (re-add de FK já existente).
                        if (in_array($code, [1050, 1060, 1061, 1062, 1068, 1091, 1146, 1826], true)) {
                            continue;
                        }
                        // MariaDB: re-add de FK com nome existente vem como 1005 errno 121.
                        if ($code === 1005 && strpos($e2->getMessage(), 'errno: 121') !== false) {
                            continue;
                        }
                        $errMsg = "stmt[{$code}]: " . $e2->getMessage();
                        error_log("[migrations] erro em {$filename}: " . $errMsg);
                        $hadFatal = true;
                        break;
                    }
                }
                if ($hadFatal) {
                    $__migHadFailure = true;
                    // Grava como falha persistente para o admin investigar.
                    // Reconcilia também content_sha (+duração/contagem): se um
                    // migration COM DRIFT falhar ao reexecutar, manter o hash
                    // antigo o faria reexecutar (e relogar) a cada requisição.
                    // Gravando o hash atual, só roda de novo quando o arquivo
                    // mudar outra vez (ex.: admin corrige a migration).
                    try {
                        $stI = $migPdo->prepare("
                            INSERT INTO applied_migrations (filename, content_sha, duration_ms, statement_count, failure_reason, applied_at)
                            VALUES (?, ?, ?, ?, ?, NULL)
                            ON DUPLICATE KEY UPDATE
                                content_sha     = VALUES(content_sha),
                                duration_ms     = VALUES(duration_ms),
                                statement_count = VALUES(statement_count),
                                failure_reason  = VALUES(failure_reason)
                        ");
                        $durMs = (int)round((microtime(true) - $startedAt) * 1000);
                        // M4: usa mb_substr para não cortar no meio de char UTF-8 multibyte.
                        $reason = (string)($errMsg ?? 'unknown');
                        if (function_exists('mb_strlen') && mb_strlen($reason, 'UTF-8') > 4000) {
                            error_log("[migrations] failure_reason truncado em {$filename}: " . mb_strlen($reason, 'UTF-8') . ' chars');
                            $reason = mb_substr($reason, 0, 4000, 'UTF-8');
                        } else {
                            $reason = substr($reason, 0, 5000);
                        }
                        $stI->execute([$filename, $contentSha, $durMs, $stmtCount, $reason]);
                    } catch (Throwable $_) {}
                    continue; // pula esta, segue as próximas
                }
            }

            // Sucesso — grava no ledger
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            try {
                $stI = $migPdo->prepare("
                    INSERT INTO applied_migrations (filename, content_sha, duration_ms, statement_count, applied_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        content_sha     = VALUES(content_sha),
                        duration_ms     = VALUES(duration_ms),
                        statement_count = VALUES(statement_count),
                        failure_reason  = NULL
                ");
                $stI->execute([$filename, $contentSha, $durationMs, $stmtCount]);
                $appliedCount++;
                error_log("[migrations] aplicada {$filename} ({$durationMs}ms, {$stmtCount} stmts)");
            } catch (Throwable $e) {
                $__migHadFailure = true;
                error_log("[migrations] falha ao registrar {$filename}: " . $e->getMessage());
            }
        }

        if ($appliedCount > 0) {
            error_log("[migrations] sumário: {$appliedCount} aplicada(s), {$skippedCount} já estavam OK");
        }
        // Varredura completa terminou: grava a assinatura para as próximas
        // requisições pularem o runner até algum .sql mudar.
        // Só com a varredura LIMPA: com falha, o próximo request tenta de novo.
        if ($__migSig !== null && empty($__migHadFailure)) {
            @file_put_contents($__migStamp, $__migSig, LOCK_EX);
        }
    } catch (Throwable $e) {
        error_log("[migrations] erro fatal: " . $e->getMessage());
        // Não propaga — o sistema deve funcionar mesmo se uma migração falhar
    } finally {
        // Solta o lock para outros workers se a função encerrar.
        // H2: só executa se $lockName foi de fato definido (após GET_LOCK ok).
        if ($haveLock && $migPdo && $lockName !== null) {
            try {
                $rel = $migPdo->prepare("SELECT RELEASE_LOCK(?)");
                $rel->execute([$lockName]);
            } catch (Throwable $_) {}
        }
        $migPdo = null;
    }
}

/**
 * Retorna status atual das migrations para visualização admin.
 * @return array{pending: array<string>, applied: array<array{filename:string,applied_at:string,duration_ms:?int,failure_reason:?string,drift:bool}>, failed: array<string>}
 */
function migrations_status(): array {
    $out = ['pending' => [], 'applied' => [], 'failed' => []];
    try {
        $pdo = db();
        // Garante schema mínimo
        $pdo->exec("CREATE TABLE IF NOT EXISTS applied_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NULL,
            content_sha CHAR(64) NULL,
            duration_ms INT NULL,
            statement_count INT NULL,
            failure_reason TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $rows = $pdo->query("SELECT filename, applied_at, content_sha, duration_ms, failure_reason FROM applied_migrations ORDER BY applied_at IS NULL DESC, applied_at DESC, filename ASC")->fetchAll(PDO::FETCH_ASSOC);
        $applied = [];
        foreach ($rows as $r) {
            $applied[$r['filename']] = $r;
            if (!empty($r['failure_reason'])) {
                $out['failed'][] = [
                    'filename' => $r['filename'],
                    'reason'   => $r['failure_reason'],
                ];
            }
        }

        $files = glob(__DIR__ . '/sql/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $sql = @file_get_contents($filePath);
            $sha = $sql !== false ? hash('sha256', str_replace("\r\n", "\n", $sql)) : null;
            $shaVariants = $sql !== false ? [$sha, hash('sha256', $sql), hash('sha256', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $sql)))] : [];
            if (!array_key_exists($filename, $applied)) {
                $out['pending'][] = $filename;
                continue;
            }
            $row = $applied[$filename];
            $drift = $sha && !empty($row['content_sha']) && !in_array($row['content_sha'], $shaVariants, true);
            $out['applied'][] = [
                'filename'      => $filename,
                'applied_at'    => $row['applied_at'],
                'duration_ms'   => isset($row['duration_ms']) ? (int)$row['duration_ms'] : null,
                'failure_reason'=> $row['failure_reason'] ?? null,
                'drift'         => $drift,
            ];
        }
    } catch (Throwable $e) {
        error_log('[migrations_status] ' . $e->getMessage());
    }
    return $out;
}

function esc($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $tokenFromBody = null) {
    $token = $tokenFromBody !== null && $tokenFromBody !== ''
        ? $tokenFromBody
        : ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X-CSRF-TOKEN'] ?? $_POST['csrf'] ?? $_POST['csrf_token'] ?? $_GET['csrf'] ?? $_GET['csrf_token'] ?? '');
    if (!$token || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        if (ob_get_level()) ob_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','message'=>'CSRF token inválido']);
        exit;
    }
}

function require_admin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }

    // NC-44 (auditoria 2026-08-05): ADMIN_SESSION_TIMEOUT era definido em
    // config.php e NUNCA lido. A sessão administrativa não expirava por
    // inatividade e o cookie durava 30 dias — um posto de trabalho deixado
    // aberto continuava administrando o ponto de todos indefinidamente.
    $ttl = defined('ADMIN_SESSION_TIMEOUT') ? (int)ADMIN_SESSION_TIMEOUT : 7200;
    if ($ttl > 0) {
        $ultimo = (int)($_SESSION['admin_last_activity'] ?? 0);
        if ($ultimo > 0 && (time() - $ultimo) > $ttl) {
            $adminId = (int)$_SESSION['admin_id'];
            unset($_SESSION['admin_id'], $_SESSION['admin_last_activity']);
            session_regenerate_id(true);
            audit_log('session_expired', 'admin', $adminId, ['inatividade_s' => time() - $ultimo]);
            header('Location: login.php?msg=' . urlencode('Sessão expirada por inatividade.'));
            exit;
        }
        // Renova a janela a cada ação — expira por INATIVIDADE, não por tempo
        // absoluto, que interromperia trabalho em andamento sem necessidade.
        $_SESSION['admin_last_activity'] = time();
    }
}

function is_admin_logged() {
    return !empty($_SESSION['admin_id']);
}

/**
 * Retorna os dados do admin logado (usa a função db() definida em config.php).
 */
function current_admin(PDO $pdo = null): ?array {
    if (empty($_SESSION['admin_id'])) return null;
    if (isset($_SESSION['_admin_cache']) && is_array($_SESSION['_admin_cache'])) {
        return $_SESSION['_admin_cache'];
    }
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("SELECT a.*, s.name AS school_name
                         FROM admins a
                         LEFT JOIN schools s ON s.id = a.school_id
                         WHERE a.id = ?");
    $st->execute([(int)$_SESSION['admin_id']]);
    $adm = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $_SESSION['_admin_cache'] = $adm;
    return $adm;
}

function is_network_admin(array $admin = null): bool {
    $admin = $admin ?? current_admin();
    return $admin && ($admin['role'] ?? '') === 'network_admin';
}

// ============================================================================
// Sessão de colaborador (espelha padrão admin: require / is / current / login / logout)
// ============================================================================

/**
 * Redireciona para login.php se o colaborador não estiver autenticado.
 * Respeita TTL configurável via app_settings.collaborator_session_ttl_days (default 30).
 */
function require_collaborator(): void {
    if (!is_collaborator_logged()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Checagem PURA da sessão de colaborador: retorna apenas se é válida, SEM
 * efeito colateral (não desloga, não renova). Ideal para decisões de UI/API
 * onde não queremos alterar estado.
 */
function collaborator_session_is_valid(): bool {
    if (empty($_SESSION['collaborator_id'])) return false;
    $ttlDays = max(1, (int)(get_setting('collaborator_session_ttl_days', '30') ?? '30'));
    $loggedAt = (int)($_SESSION['collaborator_logged_at'] ?? 0);
    if ($loggedAt <= 0) return false;
    return (time() - $loggedAt) <= $ttlDays * 86400;
}

/**
 * Checagem + efeitos: se expirada, desloga; se ativa há mais de 1h, renova
 * o timestamp (rolling TTL). Mantido com esse nome por compatibilidade com
 * callers existentes (require_collaborator, api/checkin.php, etc.).
 *
 * Se a sessão não existir ou estiver expirada, tenta reautenticar via cookie
 * persistente "ponto_remember" (token gerado a cada login).
 */
function is_collaborator_logged(): bool {
    if (!empty($_SESSION['collaborator_id'])) {
        $ttlDays = max(1, (int)(get_setting('collaborator_session_ttl_days', '30') ?? '30'));
        $loggedAt = (int)($_SESSION['collaborator_logged_at'] ?? 0);
        if ($loggedAt > 0 && (time() - $loggedAt) <= $ttlDays * 86400) {
            // A1: rolling TTL — renova o timestamp para evitar deslogar usuário ativo.
            // Só grava se passou ao menos 1 hora (evita write thrash em cada request).
            if ((time() - $loggedAt) > 3600) {
                $_SESSION['collaborator_logged_at'] = time();
            }
            return true;
        }
        // Sessão expirada ou inválida — limpa variáveis de sessão sem destruir o
        // cookie de remember-me, para que o consumo abaixo possa reautenticar.
        unset(
            $_SESSION['collaborator_id'],
            $_SESSION['collaborator_name'],
            $_SESSION['collaborator_cpf_masked'],
            $_SESSION['collaborator_logged_at'],
            $_SESSION['_collaborator_cache']
        );
    }
    // S1 (code review 2026-05): se há sessão admin ativa, NÃO reautenticar
    // como colaborador via cookie persistente. consume_remember_cookie() mata
    // a sessão admin pelo mutex "B4", o que faz sentido em login explícito mas
    // não em fallback automático — caso contrário, um admin que também tem
    // cadastro de professor com cookie ponto_remember seria demovido sem clicar
    // em nada ao acessar uma rota que checa as duas sessões (ex: get_receipt.php).
    if (!empty($_SESSION['admin_id'])) {
        return false;
    }
    if (!empty($_COOKIE[collaborator_remember_cookie_name()])) {
        return collaborator_consume_remember_cookie();
    }
    return false;
}

/**
 * Retorna dados completos do colaborador logado (null se não logado).
 * Usa cache em sessão; releitura só quando explicitamente invalidado.
 */
function current_collaborator(PDO $pdo = null): ?array {
    if (!is_collaborator_logged()) return null;
    if (isset($_SESSION['_collaborator_cache']) && is_array($_SESSION['_collaborator_cache'])) {
        return $_SESSION['_collaborator_cache'];
    }
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("SELECT id, name, cpf, email, active, type_id, base_salary, network_wide, face_descriptors, pin_changed_at
                         FROM teachers
                         WHERE id = ? AND active = 1");
    $st->execute([(int)$_SESSION['collaborator_id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        collaborator_logout();
        return null;
    }
    $_SESSION['_collaborator_cache'] = $row;
    return $row;
}

/**
 * Valida CPF + PIN. Se OK, cria sessão de colaborador e retorna true.
 * Rate-limit: 10 falhas / 5 min por identifier (IP+UA+CPF).
 */
function collaborator_login_by_pin(string $cpf, string $pin, string $clientFp = ''): bool {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11 || !validar_cpf($cpf)) return false;
    if ($pin === '' || strlen($pin) < 4 || strlen($pin) > 8) return false;

    $pdo = db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $identifier = hash('sha256', $ip . '|' . substr($ua, 0, 120) . '|' . $cpf);
    // Normaliza/sanitiza fingerprint do cliente antes de usar
    $clientFp = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $clientFp), 0, 128);

    // Rate-limit por identifier (IP+UA+CPF): 10 falhas/5min
    [$limited] = array_values(auth_attempt_is_limited($pdo, 'collaborator_login', $identifier, 300, 10));
    if ($limited) return false;

    $stmt = $pdo->prepare("SELECT id, name, cpf, pin_hash, face_descriptors
                           FROM teachers WHERE cpf = ? AND active = 1 LIMIT 1");
    $stmt->execute([$cpf]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // C3: Rate-limit adicional por teacher_id — fecha bypass por IP-hopping.
    // 20 falhas/1h globais para o mesmo teacher bloqueiam novas tentativas.
    if ($row) {
        $teacherKey = 'teacher:' . (int)$row['id'];
        [$teacherLimited] = array_values(auth_attempt_is_limited($pdo, 'collaborator_login', $teacherKey, 3600, 20));
        if ($teacherLimited) {
            auth_attempt_log($pdo, 'collaborator_login', $teacherKey, false, (int)$row['id'], 'teacher_rate_limited');
            return false;
        }
    }

    if (!$row || empty($row['pin_hash'])) {
        auth_attempt_log($pdo, 'collaborator_login', $identifier, false, $row['id'] ?? null, 'cpf_or_pin_missing');
        return false;
    }
    if (!pin_verify($pin, (string)$row['pin_hash'])) {
        auth_attempt_log($pdo, 'collaborator_login', $identifier, false, (int)$row['id'], 'pin_invalid');
        // Contabiliza também na janela por teacher_id
        auth_attempt_log($pdo, 'collaborator_login', 'teacher:' . (int)$row['id'], false, (int)$row['id'], 'pin_invalid');
        return false;
    }

    // B4: mutual exclusion — ao logar como colaborador, limpa sessão de admin.
    if (!empty($_SESSION['admin_id']) && function_exists('admin_logout')) admin_logout();
    session_regenerate_id(true);
    $_SESSION['collaborator_id']         = (int)$row['id'];
    $_SESSION['collaborator_name']       = (string)$row['name'];
    $_SESSION['collaborator_cpf_masked'] = mask_cpf((string)$row['cpf']);
    $_SESSION['collaborator_logged_at']  = time();
    $_SESSION['_collaborator_cache']     = $row;

    auth_attempt_log($pdo, 'collaborator_login', $identifier, true, (int)$row['id'], 'login_ok');
    audit_log('login', 'collaborator', (int)$row['id'], [
        'cpf' => mask_cpf((string)$row['cpf']),
        'via' => 'pin',
    ]);

    // Emite token persistente para manter o login até logout explícito.
    collaborator_issue_remember_token((int)$row['id']);

    // Enroll device como trusted — usa o mesmo clientFp que o frontend envia
    // em cada check-in, garantindo hash idêntico entre login e check-in.
    try {
        $fp = trusted_device_fingerprint($ua, $ip, $clientFp);
        if ($fp !== '') {
            trusted_device_enroll($pdo, (int)$row['id'], $fp, !empty($row['face_descriptors']) ? 'face_validated' : 'repeated_use');
        }
    } catch (Throwable $e) { /* não bloqueia login */ }

    return true;
}

/**
 * Cria sessão de colaborador diretamente (usado por pin_enroll/pin_recover após gerar PIN).
 * Assume que o caller já validou a identidade (face ou CPF admin-liberado).
 */
function collaborator_login_establish(int $teacherId, string $reason = 'auto'): bool {
    if ($teacherId <= 0) return false;
    $pdo = db();
    $st = $pdo->prepare("SELECT id, name, cpf, face_descriptors FROM teachers WHERE id = ? AND active = 1");
    $st->execute([$teacherId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    // B4: mutual exclusion
    if (!empty($_SESSION['admin_id']) && function_exists('admin_logout')) admin_logout();
    session_regenerate_id(true);
    $_SESSION['collaborator_id']         = (int)$row['id'];
    $_SESSION['collaborator_name']       = (string)$row['name'];
    $_SESSION['collaborator_cpf_masked'] = mask_cpf((string)$row['cpf']);
    $_SESSION['collaborator_logged_at']  = time();
    $_SESSION['_collaborator_cache']     = $row;

    audit_log('login', 'collaborator', (int)$row['id'], [
        'cpf' => mask_cpf((string)$row['cpf']),
        'via' => $reason,
    ]);

    // Emite token persistente para manter o login até logout explícito.
    collaborator_issue_remember_token((int)$row['id']);

    return true;
}

function collaborator_logout(): void {
    if (!empty($_SESSION['collaborator_id'])) {
        audit_log('logout', 'collaborator', (int)$_SESSION['collaborator_id']);
    }
    // Invalida o token persistente deste device (remove do banco e apaga o cookie).
    collaborator_clear_remember_cookie();
    unset(
        $_SESSION['collaborator_id'],
        $_SESSION['collaborator_name'],
        $_SESSION['collaborator_cpf_masked'],
        $_SESSION['collaborator_logged_at'],
        $_SESSION['_collaborator_cache']
    );
}

// ============================================================================
// Login persistente (remember-me) — token ficar válido até o usuário sair.
// Token bruto vai num cookie HttpOnly; banco guarda apenas o hash SHA-256.
// ============================================================================

function collaborator_remember_cookie_name(): string {
    return 'ponto_remember';
}

/**
 * Gera token persistente, salva o hash em `collaborator_remember_tokens` e
 * grava o token bruto num cookie HttpOnly (10 anos de lifetime).
 * Falhas são logadas mas não bloqueiam o login.
 */
function collaborator_issue_remember_token(int $teacherId): void {
    if ($teacherId <= 0) return;
    try {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $ua    = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ip    = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        $pdo = db();
        $st  = $pdo->prepare("INSERT INTO collaborator_remember_tokens
                              (teacher_id, token_hash, user_agent, ip_address, expires_at)
                              VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
        $st->execute([$teacherId, $hash, $ua !== '' ? $ua : null, $ip !== '' ? $ip : null,
                      COLLABORATOR_REMEMBER_DAYS]);

        // HOTFIX 2026-09: tokens nunca eram podados (produção: 602 tokens, um
        // colaborador com 169). Remove expirados e mantém os 5 mais recentes.
        try {
            $pdo->prepare("DELETE FROM collaborator_remember_tokens
                            WHERE teacher_id = ? AND expires_at IS NOT NULL AND expires_at < NOW()")
                ->execute([$teacherId]);
            $pdo->prepare("DELETE t FROM collaborator_remember_tokens t
                            JOIN (SELECT id FROM collaborator_remember_tokens
                                   WHERE teacher_id = ?
                                   ORDER BY COALESCE(last_used_at, created_at) DESC, id DESC
                                   LIMIT 18446744073709551615 OFFSET 5) old ON old.id = t.id")
                ->execute([$teacherId]);
        } catch (Throwable $pruneErr) {
            error_log('[remember] poda falhou: ' . $pruneErr->getMessage());
        }

        if (headers_sent()) {
            error_log('[remember] não foi possível setar cookie: headers já enviados');
            return;
        }
        // NC-44: o cookie durava DEZ ANOS, e a query que o consome não filtrava
        // por data — não havia expiração em lugar nenhum. Um token vazado (de um
        // aparelho perdido, de um backup, de um log de proxy) valia uma década.
        //
        // 90 dias é longo o bastante para o colaborador não reautenticar toda
        // semana e curto o bastante para que um vazamento tenha fim. O valor
        // acompanha a coluna `expires_at`, checada na leitura.
        setcookie(collaborator_remember_cookie_name(), $token, [
            'expires'  => time() + COLLABORATOR_REMEMBER_DAYS * 86400,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[collaborator_remember_cookie_name()] = $token;
    } catch (Throwable $e) {
        error_log('[remember] issue falhou: ' . $e->getMessage());
    }
}

/**
 * Lê o cookie persistente, valida o hash no banco e recria a sessão.
 * Atualiza `last_used_at`. Se o cookie for inválido, é apagado do browser.
 * Retorna true se reautenticou com sucesso.
 */
function collaborator_consume_remember_cookie(): bool {
    $name  = collaborator_remember_cookie_name();
    $token = (string)($_COOKIE[$name] ?? '');
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return false;
    }
    try {
        $hash = hash('sha256', $token);
        $pdo  = db();
        $st   = $pdo->prepare("SELECT t.id AS token_row_id, c.id AS teacher_id, c.name, c.cpf,
                                      c.pin_hash, c.face_descriptors, c.active
                               FROM collaborator_remember_tokens t
                               INNER JOIN teachers c ON c.id = t.teacher_id
                               WHERE t.token_hash = ?
                                 AND (t.expires_at IS NULL OR t.expires_at > NOW())
                               LIMIT 1");
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['active'] !== 1) {
            collaborator_clear_remember_cookie_only();
            return false;
        }

        // Mutual exclusion com sessão de admin (mesma regra de collaborator_login_by_pin).
        if (!empty($_SESSION['admin_id']) && function_exists('admin_logout')) {
            admin_logout();
        }
        session_regenerate_id(true);
        $tid = (int)$row['teacher_id'];
        $_SESSION['collaborator_id']         = $tid;
        $_SESSION['collaborator_name']       = (string)$row['name'];
        $_SESSION['collaborator_cpf_masked'] = mask_cpf((string)$row['cpf']);
        $_SESSION['collaborator_logged_at']  = time();
        $_SESSION['_collaborator_cache']     = [
            'id'               => $tid,
            'name'             => $row['name'],
            'cpf'              => $row['cpf'],
            'pin_hash'         => $row['pin_hash'],
            'face_descriptors' => $row['face_descriptors'],
        ];

        try {
            $up = $pdo->prepare("UPDATE collaborator_remember_tokens
                                 SET last_used_at = NOW()
                                 WHERE id = ?");
            $up->execute([(int)$row['token_row_id']]);
        } catch (Throwable $_) { /* não bloqueia */ }

        audit_log('login', 'collaborator', $tid, [
            'cpf' => mask_cpf((string)$row['cpf']),
            'via' => 'remember_cookie',
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[remember] consume falhou: ' . $e->getMessage());
        return false;
    }
}

/**
 * Logout explícito: apaga a linha do banco (se existir) E o cookie do browser.
 */
function collaborator_clear_remember_cookie(): void {
    $name  = collaborator_remember_cookie_name();
    $token = (string)($_COOKIE[$name] ?? '');
    if ($token !== '' && strlen($token) === 64 && ctype_xdigit($token)) {
        try {
            $pdo = db();
            $st  = $pdo->prepare("DELETE FROM collaborator_remember_tokens WHERE token_hash = ?");
            $st->execute([hash('sha256', $token)]);
        } catch (Throwable $e) {
            error_log('[remember] clear falhou: ' . $e->getMessage());
        }
    }
    collaborator_clear_remember_cookie_only();
}

/**
 * Limpa apenas o cookie do browser (sem mexer no banco). Usado quando o token
 * recebido é inválido — não há linha correspondente para deletar.
 */
function collaborator_clear_remember_cookie_only(): void {
    $name = collaborator_remember_cookie_name();
    if (!isset($_COOKIE[$name])) return;
    if (!headers_sent()) {
        setcookie($name, '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE[$name]);
}

function is_school_admin(array $admin = null): bool {
    $admin = $admin ?? current_admin();
    return $admin && ($admin['role'] ?? '') === 'school_admin';
}

/**
 * Cláusula de escopo por escola.
 * Para admins de rede: sem restrição (1=1).
 * Para admins de escola: apenas professores vinculados à sua school_id.
 */
function admin_scope_where(string $teacherAlias = 't'): array {
    $adm = current_admin();
    if (!$adm || is_network_admin($adm)) {
        return ['1=1', []];
    }
    $schoolId = (int)($adm['school_id'] ?? 0);
    if ($schoolId <= 0) return ['0=1', []];
    $sql = "EXISTS (SELECT 1 FROM teacher_schools ts WHERE ts.teacher_id = {$teacherAlias}.id AND ts.school_id = ?)";
    return [$sql, [$schoolId]];
}

/**
 * Log de auditoria por campo alterado em registros de attendance — Portaria MTP 671/2021.
 * Grava na tabela `attendance_audit_log` que é consumida pela tela admin/audit_log.php.
 *
 * @param PDO         $pdo
 * @param int         $attendanceId  ID do registro de ponto afetado
 * @param int|null    $adminId       Admin que executou (null = sistema)
 * @param string      $action        UPDATE | DELETE | APPROVE | REJECT | CREATE
 * @param string|null $field         Campo alterado (null para ações sem campo, ex APPROVE)
 * @param mixed       $oldValue      Valor anterior (será cast para string/JSON)
 * @param mixed       $newValue      Novo valor
 * @param string|null $reason        Justificativa fornecida pelo admin
 */
function log_attendance_audit(
    PDO $pdo,
    int $attendanceId,
    ?int $adminId,
    string $action,
    ?string $field,
    $oldValue,
    $newValue,
    ?string $reason = null
): void {
    try {
        $serialize = function ($v): ?string {
            if ($v === null) return null;
            if (is_scalar($v)) return (string)$v;
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        };
        $st = $pdo->prepare("
            INSERT INTO attendance_audit_log
                (attendance_id, admin_id, action, field_changed, old_value, new_value, reason, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $st->execute([
            $attendanceId,
            $adminId,
            $action,
            $field,
            $serialize($oldValue),
            $serialize($newValue),
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        // Não interrompe fluxo principal — auditoria é best-effort.
        error_log('[log_attendance_audit] ' . $e->getMessage());
    }
}

/**
 * Log de auditoria.
 */
/**
 * Redirect com flash message tipado. Substitui `die('texto')` em handlers admin.
 * O flash é renderizado automaticamente em `_navbar.php`.
 *
 * Segurança: URL precisa ser relativa interna (sem `://` ou `//host`). URLs
 * suspeitas caem no fallback `dashboard.php` para evitar open-redirect via
 * HTTP_REFERER manipulado por phishing.
 *
 * @param string      $type  'error' | 'warning' | 'success' | 'info'
 * @param string      $msg   Mensagem visível ao admin
 * @param string|null $url   URL de destino relativa (default: PHP_SELF)
 */
function flash_redirect(string $type, string $msg, ?string $url = null): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'msg'  => $msg,
        'time' => time(),
    ];
    // Default: redireciona pra mesma página (PHP_SELF) — sempre relativo, sempre interno.
    $target = $url ?? basename((string)($_SERVER['PHP_SELF'] ?? 'dashboard.php'));
    // Anti open-redirect: rejeita absolute URLs, protocol-relative e javascript:
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $target)) {
        $target = 'dashboard.php';
    }
    header('Location: ' . $target);
    exit;
}

/**
 * Valida CPF brasileiro: deve ter 11 dígitos (após limpeza), não ser sequência
 * trivial (000…, 111…, etc.) e bater com o algoritmo de verificação oficial.
 */
function validate_cpf(string $cpf): bool {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    // Exceção legacy: CPF padrão do admin de instalação (000.000.000-00)
    if ($cpf === '00000000000') return true;
    // Sequências triviais inválidas (mas matematicamente passam no checksum)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    // Algoritmo oficial dos 2 dígitos verificadores
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) {
            $sum += ((int)$cpf[$i]) * (($t + 1) - $i);
        }
        $d = (10 * $sum) % 11;
        if ($d === 10) $d = 0;
        if ($d !== (int)$cpf[$t]) return false;
    }
    return true;
}

/**
 * Valida CNPJ: 14 dígitos e os dois verificadores pelo algoritmo oficial.
 *
 * Vai para o cabeçalho do AFD e para o AEJ — um CNPJ errado só é descoberto na
 * rejeição do arquivo pela fiscalização, quando já não dá para refazer o mês.
 */
function validate_cnpj(string $cnpj): bool {
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if (strlen($cnpj) !== 14) return false;
    if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
    foreach ([12, 13] as $t) {
        $sum = 0;
        $peso = $t - 7;
        for ($i = 0; $i < $t; $i++) {
            $sum += ((int)$cnpj[$i]) * $peso;
            $peso = ($peso === 2) ? 9 : $peso - 1;
        }
        $resto = $sum % 11;
        $d = ($resto < 2) ? 0 : 11 - $resto;
        if ($d !== (int)$cnpj[$t]) return false;
    }
    return true;
}

/**
 * Valida PIS/PASEP/NIT: 11 dígitos e o verificador (pesos 3..2).
 *
 * O AEJ identifica o trabalhador pelo PIS, não pelo CPF — daí a validação ser
 * tão dura quanto a do CPF, e não um simples strlen.
 */
function validate_pis(string $pis): bool {
    $pis = preg_replace('/\D/', '', $pis);
    if (strlen($pis) !== 11) return false;
    if (preg_match('/^(\d)\1{10}$/', $pis)) return false;
    $pesos = [3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += ((int)$pis[$i]) * $pesos[$i];
    }
    $resto = $sum % 11;
    $d = ($resto < 2) ? 0 : 11 - $resto;
    return $d === (int)$pis[10];
}

/**
 * Normaliza nome para comparação fuzzy: remove acentos, baixa caixa, colapsa espaços.
 * Usado para detectar "soft duplicates" entre cadastros (mesma pessoa com CPFs diferentes).
 */
function normalize_name_for_dedup(string $name): string {
    $name = trim($name);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii !== false) $name = $ascii;
    }
    $name = mb_strtolower($name, 'UTF-8');
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function audit_log(string $action, string $entity, $entity_id = null, array $payload = []): void {
    try {
        $pdo = db();
        $st = $pdo->prepare("INSERT INTO audit_logs (admin_id, action, entity, entity_id, payload, ip)
                             VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([
            $_SESSION['admin_id'] ?? null,
            $action,
            $entity,
            (string)($entity_id ?? ''),
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Throwable $e) {
        // NC (auditoria 2026-08-05): a falha continua NÃO interrompendo o fluxo
        // — derrubar uma operação administrativa porque o log falhou seria pior.
        // Mas antes ela era engolida em silêncio absoluto: a trilha de auditoria
        // podia parar de gravar (tabela ausente, disco cheio, permissão) sem que
        // ninguém percebesse, e é justamente a trilha que a Portaria MTP 671/2021
        // exige. Agora a falha fica registrada no log de erros e marcada em
        // app_settings, para o painel de integridade poder alertar.
        error_log('[audit_log] FALHA ao gravar auditoria — acao=' . $action
                  . ' entidade=' . $entity . ' id=' . (string)($entity_id ?? '')
                  . ' erro=' . $e->getMessage());
        try {
            db()->prepare("INSERT INTO app_settings (k, v) VALUES ('audit_log_last_failure', ?)
                           ON DUPLICATE KEY UPDATE v = VALUES(v)")
                ->execute([date('Y-m-d H:i:s') . '|' . $action . '|' . substr($e->getMessage(), 0, 180)]);
        } catch (Throwable $ignored) {
            // Banco indisponível: o error_log acima é a última linha de defesa.
        }
    }
}

/**
 * Registro de tentativas de autenticação (PIN/face) e suporte a rate limit.
 */
function ensure_auth_attempt_logs_table(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS auth_attempt_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                attempt_type VARCHAR(32) NOT NULL,
                identifier VARCHAR(191) NOT NULL,
                teacher_id INT NULL,
                ip_address VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                reason VARCHAR(100) NULL,
                details TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_auth_attempt_lookup (attempt_type, identifier, success, created_at),
                INDEX idx_auth_attempt_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // Não interrompe fluxo principal caso criação falhe
    }
    $ready = true;
}

function auth_attempt_log(
    PDO $pdo,
    string $attemptType,
    string $identifier,
    bool $success,
    ?int $teacherId = null,
    ?string $reason = null,
    array $details = []
): void {
    try {
        ensure_auth_attempt_logs_table($pdo);
        $st = $pdo->prepare("
            INSERT INTO auth_attempt_logs
            (attempt_type, identifier, teacher_id, ip_address, user_agent, success, reason, details)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $attemptType,
            $identifier,
            $teacherId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $success ? 1 : 0,
            $reason,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        // Não interrompe fluxo principal
    }
}

function auth_attempt_is_limited(
    PDO $pdo,
    string $attemptType,
    string $identifier,
    int $windowSeconds = 300,
    int $maxFailures = 10
): array {
    try {
        ensure_auth_attempt_logs_table($pdo);
        $st = $pdo->prepare("
            SELECT COUNT(*) AS failures, MAX(created_at) AS last_failure
            FROM auth_attempt_logs
            WHERE attempt_type = ?
              AND identifier = ?
              AND success = 0
              AND created_at >= (NOW() - INTERVAL ? SECOND)
        ");
        $st->execute([$attemptType, $identifier, $windowSeconds]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['failures' => 0, 'last_failure' => null];
        $failures = (int)($row['failures'] ?? 0);
        $limited = $failures >= $maxFailures;
        $retryAfter = 0;
        if ($limited && !empty($row['last_failure'])) {
            $last = strtotime((string)$row['last_failure']);
            if ($last > 0) {
                $retryAfter = max(1, ($last + $windowSeconds) - time());
            }
        }
        return [
            'limited' => $limited,
            'failures' => $failures,
            'retry_after' => $retryAfter
        ];
    } catch (Throwable $e) {
        // Fail-open deliberado: se a leitura do contador falhar, NÃO trancamos
        // todo mundo para fora (seria negação de serviço a partir de um erro de
        // banco). Mas a falha deixa de ser silenciosa — enquanto durar, o rate
        // limit está efetivamente desligado e isso precisa ser visível.
        error_log('[auth_attempt_is_limited] rate limit INOPERANTE (fail-open) — tipo='
                  . $attemptType . ' erro=' . $e->getMessage());
        return ['limited' => false, 'failures' => 0, 'retry_after' => 0];
    }
}

/**
 * @deprecated Use validate_cpf() — alias mantido por compatibilidade.
 */
function validar_cpf(string $cpf): bool {
    return validate_cpf($cpf);
}

/**
 * Retorna CPF mascarado para exibição (ex.: ***.***.***-12 ou 123.456.789-00).
 */
function mask_cpf(string $cpf): string {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) {
        return '***.***.***-**';
    }
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

/**
 * Login de admin por CPF + senha.
 */
function admin_login_by_cpf(string $cpf, string $password): bool {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11 || !validar_cpf($cpf)) {
        return false;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, username, cpf, password_hash, role, school_id FROM admins WHERE cpf = ?");
    $stmt->execute([$cpf]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($password, $row['password_hash'])) {
        // B4: mutual exclusion — ao logar como admin, limpa sessão de colaborador.
        if (function_exists('collaborator_logout')) collaborator_logout();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_username'] = $row['username'] ?? $row['cpf'];
        $_SESSION['admin_name'] = mask_cpf($row['cpf']);
        $_SESSION['_admin_cache'] = $row;
        audit_log('login', 'admin', $row['id'], ['cpf' => mask_cpf($row['cpf'])]);
        return true;
    }
    return false;
}

/**
 * Login de admin (legado por username; mantido para compatibilidade).
 */
function admin_login(string $username, string $password): bool {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, username, cpf, password_hash, role, school_id FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($password, $row['password_hash'])) {
        // B4: mutual exclusion — ao logar como admin, limpa sessão de colaborador.
        if (function_exists('collaborator_logout')) collaborator_logout();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_username'] = $row['username'];
        $_SESSION['admin_name'] = !empty($row['cpf']) ? mask_cpf($row['cpf']) : $row['username'];
        $_SESSION['_admin_cache'] = $row;
        audit_log('login', 'admin', $row['id'], ['username'=>$row['username']]);
        return true;
    }
    return false;
}

function admin_logout(): void {
    if (!empty($_SESSION['admin_id'])) {
        audit_log('logout', 'admin', $_SESSION['admin_id']);
    }
    unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_name'], $_SESSION['_admin_cache']);
}

/**
 * Cria admin padrão (apenas ambiente de dev). Em produção, remova após criar seu admin.
 */
function ensure_default_admin(): void {
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
          id INT AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(100) UNIQUE NOT NULL,
          cpf VARCHAR(11) UNIQUE NULL,
          password_hash VARCHAR(255) NOT NULL,
          role ENUM('network_admin','school_admin') NOT NULL DEFAULT 'network_admin',
          school_id INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        if ($count === 0) {
            // NC-43: aqui se criava o usuário `admin` com a senha `admin123`.
            // A função só roda fora de produção (config.php:APP_ENV), mas
            // APP_ENV é uma constante num arquivo versionado — bastava alguém
            // copiar o arquivo para o admin padrão nascer com senha conhecida.
            //
            // Agora a senha é ALEATÓRIA e impressa uma única vez. Quem instala
            // precisa anotá-la ou usar `php bin/setup_admin.php`. Uma senha que
            // ninguém conhece é preferível a uma que todo mundo conhece.
            $senhaInicial = bin2hex(random_bytes(9)); // 18 hex
            $hash = password_hash($senhaInicial, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (username, cpf, password_hash, role) VALUES ('admin', '00000000191', ?, 'network_admin')");
            $stmt->execute([$hash]);
            $aviso = "[setup] Admin inicial criado — usuario 'admin', senha: {$senhaInicial}"
                   . " (exibida apenas nesta vez; troque no primeiro acesso)";
            error_log($aviso);
            if (php_sapi_name() === 'cli') echo $aviso . PHP_EOL;
        }
    } catch (Throwable $e) {
        // Ignora em ambientes onde não pode criar tabela automaticamente
    }
}

/**
 * Key-Value settings.
 */
/**
 * Cache em memória das configurações, por requisição.
 *
 * Vive numa função própria (devolvida por referência) para que set_setting()
 * consiga invalidá-lo. Antes o cache era um `static` privado de get_setting():
 * gravar uma configuração e lê-la em seguida, na MESMA requisição, devolvia o
 * valor antigo. Isso passava despercebido no fluxo admin (que redireciona após
 * salvar, iniciando uma requisição nova), mas quebrava qualquer código que
 * dependesse do valor recém-gravado — foi assim que o modo do livro fiscal
 * (`ledger_mode`) parecia não mudar ao ser alternado.
 */
function &setting_cache(): array {
    static $cache = [];
    return $cache;
}

/** Esquece uma chave (ou todas) do cache de configurações. */
function setting_cache_forget(?string $key = null): void {
    $cache = &setting_cache();
    if ($key === null) { $cache = []; return; }
    unset($cache[$key]);
}

function get_setting(string $key, $default = null): ?string {
    // OTIMIZAÇÃO: Cache em memória para evitar queries repetidas
    $cache = &setting_cache();

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT v FROM app_settings WHERE k = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $result = $v !== false ? (string)$v : ($default === null ? null : (string)$default);
        $cache[$key] = $result;
        return $result;
    } catch (Throwable $e) {
        $result = $default === null ? null : (string)$default;
        $cache[$key] = $result;
        return $result;
    }
}

/**
 * Grava (upsert) uma configuração em app_settings e invalida o cache da chave,
 * para que uma leitura seguinte na MESMA requisição enxergue o valor novo.
 */
function set_setting(string $key, string $value): void {
    try {
        $pdo = db();
        $st = $pdo->prepare("INSERT INTO app_settings (k, v) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE v = VALUES(v)");
        $st->execute([$key, $value]);
        setting_cache_forget($key);
    } catch (Throwable $e) {
        error_log('set_setting falhou (' . $key . '): ' . $e->getMessage());
        // Invalida mesmo em falha: o cache pode ter um valor que já não
        // corresponde ao banco, e servir dado velho é pior que reconsultar.
        setting_cache_forget($key);
    }
}

/**
 * Data global a partir da qual o sistema conta presenças e faltas.
 * Lida de app_settings (chave 'counting_start_date'). Retorna 'YYYY-MM-DD'
 * ou null quando não configurada (vazia).
 */
function counting_start_date(): ?string {
    $v = trim((string) get_setting('counting_start_date', ''));
    return $v !== '' ? $v : null;
}

/**
 * Regra pura: data efetiva de início de contagem para um colaborador.
 * = max(data global, created_at do colaborador, '1900-01-01').
 * Quando a data global é nula/vazia, devolve apenas o piso do created_at.
 */
function resolve_counting_start(?string $globalStart, ?string $createdAt): string {
    $hire = ($createdAt !== null && $createdAt !== '')
        ? date('Y-m-d', strtotime($createdAt))
        : '1900-01-01';
    if ($globalStart === null || $globalStart === '') {
        return $hire;
    }
    return max($globalStart, $hire);
}

/**
 * Conveniência usada pelos relatórios: resolve a data de início combinando
 * a configuração global com o created_at do colaborador.
 */
function counting_start_for(?string $createdAt): string {
    return resolve_counting_start(counting_start_date(), $createdAt);
}

/**
 * Compara dois datetimes ao nível do MINUTO (ignora segundos).
 *
 * Usado na edição de ponto do admin: o formulário só permite precisão de minuto
 * (input datetime-local), mas o banco guarda segundos (HH:MM:SS) das batidas reais.
 * Comparar string crua marcaria um campo NÃO editado como "alterado" (07:14:25 vs
 * 07:14:00) e reescreveria os segundos. Comparar no minuto evita esse efeito.
 *
 * Retorna false se qualquer um for vazio/nulo ou não-parseável (o chamador trata
 * o caso "campo esvaziado = remover" separadamente).
 */
function dt_same_minute(?string $a, ?string $b): bool {
    if (empty($a) || empty($b)) return false;
    $ta = strtotime($a);
    $tb = strtotime($b);
    if ($ta === false || $tb === false) return false;
    return date('Y-m-d H:i', $ta) === date('Y-m-d H:i', $tb);
}

/**
 * Aplica a janela de contagem ao mapa diário de um relatório financeiro/mensal:
 * dias antes do início da contagem (startDate) ou no futuro (depois de today) NÃO
 * são contados — zera os campos de minutos desses dias para que não gerem saldo,
 * déficit, falta nem desconto. Datas no formato 'Y-m-d'. No-op quando todos os
 * dias já estão dentro da janela (ex.: mês fechado), preservando o comportamento.
 *
 * Os nomes dos campos zerados são configuráveis porque os relatórios usam
 * convenções diferentes (ex.: 'expected'/'worked'/'effective' no financeiro,
 * 'expectedMin'/'workedMin'/'effectiveMin' no relatório mensal).
 *
 * @param array<string,array> $daily  Mapa data => ['expected'=>int, ...]
 * @param string[]            $fields Campos de minutos a zerar fora da janela.
 * @return array<string,array> O mesmo mapa com os dias fora da janela zerados.
 */
function apply_counting_window(array $daily, string $startDate, string $today, array $fields = ['expected', 'worked', 'effective']): array {
    foreach ($daily as $d => $v) {
        $d = (string)$d;
        if ($d < $startDate || $d > $today) {
            foreach ($fields as $f) {
                $daily[$d][$f] = 0;
            }
        }
    }
    return $daily;
}

/**
 * Segredo HMAC para assinar o token de reconhecimento do quiosque.
 * Preferência: constante KIOSK_SIGNING_SECRET (config.local.php) ou env.
 * Fallback: gera um segredo aleatório por instalação e persiste em app_settings
 * (server-side; mesmo nível de confiança dos demais dados) — garante que o
 * quiosque funcione sem arquivo de config (modo mock) mantendo HMAC forte.
 */
function kiosk_signing_secret(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (defined('KIOSK_SIGNING_SECRET') && KIOSK_SIGNING_SECRET !== '') {
        return $cached = (string)KIOSK_SIGNING_SECRET;
    }
    $env = getenv('KIOSK_SIGNING_SECRET');
    if ($env !== false && $env !== '') {
        return $cached = (string)$env;
    }
    $stored = get_setting('kiosk_signing_secret', '');
    if ($stored !== null && $stored !== '') {
        return $cached = $stored;
    }
    $secret = bin2hex(random_bytes(32));
    set_setting('kiosk_signing_secret', $secret);
    return $cached = $secret;
}

/**
 * Purga (LGPD) as imagens de auditoria do quiosque (kiosk_face_logs.photo) mais
 * antigas que a retenção. NÃO apaga arquivos ainda referenciados por
 * attendance.photo — esses seguem a retenção legal própria (Portaria 671).
 * Remove o arquivo em public/photos e zera a coluna do log.
 * @return array ['status','deleted','cleared','freed_mb','errors','retention_days']
 */
function kiosk_purge_audit_photos(PDO $pdo, ?int $retentionDays = null): array {
    $days = $retentionDays ?? (int)(get_setting('kiosk_photo_retention_days', '90') ?? '90');
    if ($days <= 0) $days = 90;
    $deleted = 0; $freed = 0; $errors = 0; $cleared = 0;
    try {
        $st = $pdo->prepare("SELECT id, photo FROM kiosk_face_logs
            WHERE photo IS NOT NULL AND photo <> ''
              AND created_at < (NOW() - INTERVAL ? DAY)
            LIMIT 5000");
        $st->execute([$days]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage(), 'deleted' => 0, 'cleared' => 0, 'freed_mb' => 0, 'errors' => 1, 'retention_days' => $days];
    }
    if (empty($rows)) {
        return ['status' => 'completed', 'deleted' => 0, 'cleared' => 0, 'freed_mb' => 0, 'errors' => 0, 'retention_days' => $days];
    }
    // Descobre quais desses arquivos AINDA são referenciados por attendance
    // (esses seguem a retenção legal própria e NÃO podem ser apagados aqui).
    // Comparação coluna-vs-parâmetro evita conflito de collation entre tabelas.
    $photos = array_values(array_unique(array_map(fn($r) => (string)$r['photo'], $rows)));
    $referenced = [];
    foreach (array_chunk($photos, 500) as $chunk) {
        try {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT DISTINCT photo FROM attendance WHERE photo IN ($ph)");
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) { $referenced[(string)$p] = true; }
        } catch (Throwable $e) { /* na dúvida, preserva tudo deste chunk */ foreach ($chunk as $c) $referenced[$c] = true; }
    }
    $upd = $pdo->prepare("UPDATE kiosk_face_logs SET photo = NULL WHERE id = ?");
    $baseDir = __DIR__ . '/public/';
    foreach ($rows as $r) {
        $rel = (string)$r['photo'];
        if (isset($referenced[$rel])) continue; // preserva (referenciado por attendance)
        if (preg_match('#^photos/[a-f0-9]{32}\.(jpg|png)$#', $rel)) {
            $abs = $baseDir . $rel;
            if (is_file($abs)) {
                $sz = @filesize($abs) ?: 0;
                if (@unlink($abs)) { $deleted++; $freed += $sz; } else { $errors++; }
            }
        }
        try { $upd->execute([(int)$r['id']]); $cleared++; } catch (Throwable $e) { $errors++; }
    }
    return ['status' => 'completed', 'deleted' => $deleted, 'cleared' => $cleared,
            'freed_mb' => round($freed / 1048576, 2), 'errors' => $errors, 'retention_days' => $days];
}

/**
 * Calibração do limiar de reconhecimento facial do quiosque.
 * A partir dos descritores cadastrados, calcula a distribuição GENUÍNA
 * (distâncias entre amostras do MESMO colaborador — devem ser pequenas) e a
 * IMPOSTORA (distâncias entre colaboradores DIFERENTES — devem ser maiores) e
 * sugere um limiar que as separe. Custo limitado por $maxTeachers.
 * @return array stats + suggested_threshold + current_threshold
 */
function kiosk_calibrate(PDO $pdo, int $maxTeachers = 200): array {
    try {
        $st = $pdo->query("SELECT face_descriptors FROM teachers
            WHERE active=1 AND face_descriptors IS NOT NULL AND face_descriptors <> '' AND face_descriptors <> '[]'
            LIMIT " . max(1, (int)$maxTeachers));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    $persons = [];
    foreach ($rows as $r) {
        $d = face_descriptors_decode($r['face_descriptors']);
        if (!is_array($d)) continue;
        $valid = [];
        foreach ($d as $v) { if (is_array($v) && count($v) === 128) $valid[] = $v; }
        if (!empty($valid)) $persons[] = $valid;
    }
    $T = count($persons);
    $genuine = []; $impostor = [];
    foreach ($persons as $samples) {
        $n = count($samples);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) $genuine[] = euclidean_distance($samples[$i], $samples[$j]);
        }
    }
    $reps = [];
    foreach ($persons as $s) $reps[] = $s[0];
    for ($i = 0; $i < $T; $i++) {
        for ($j = $i + 1; $j < $T; $j++) $impostor[] = euclidean_distance($reps[$i], $reps[$j]);
    }
    sort($genuine); sort($impostor);
    $pct = function (array $a, float $p) {
        if (empty($a)) return null;
        $idx = (int)floor(($p / 100) * (count($a) - 1));
        return round($a[$idx], 4);
    };
    $gp95 = $pct($genuine, 95); $ip05 = $pct($impostor, 5);
    $suggested = null; $separable = false;
    if ($gp95 !== null && $ip05 !== null) {
        if ($gp95 < $ip05) {
            // Separação limpa: ponto médio entre as distribuições.
            $separable = true;
            $suggested = round(($gp95 + $ip05) / 2, 3);
        } else {
            // Sobreposição: favorece SEGURANÇA (menos falso-aceite) ancorando no
            // piso da distribuição impostora. Falsos-negativos são mitigados por
            // nova tentativa + fallback CPF+PIN.
            $suggested = round($ip05, 3);
        }
        $suggested = max(0.4, min(0.8, $suggested));
    }
    $ovr = get_setting('kiosk_face_match_threshold', '');
    if ($ovr !== null && $ovr !== '' && is_numeric($ovr)) { $current = (float)$ovr; }
    else { $tt = get_face_thresholds('identify'); $current = (float)$tt['match_threshold']; }

    return [
        'teachers' => $T,
        'genuine'  => ['count' => count($genuine), 'min' => $pct($genuine, 0), 'median' => $pct($genuine, 50), 'p95' => $gp95, 'max' => $pct($genuine, 100)],
        'impostor' => ['count' => count($impostor), 'min' => $pct($impostor, 0), 'p5' => $ip05, 'median' => $pct($impostor, 50)],
        'separable' => $separable,
        'suggested_threshold' => $suggested,
        'current_threshold' => round($current, 4),
    ];
}

/**
 * Permissões finas por role (baseline).
 * network_admin: permitido por padrão.
 * school_admin: verifica tabela permissions; se não houver regra, permite.
 */
function has_permission(string $permKey): bool {
    $adm = current_admin();
    if (!$adm) return false;
    if (is_network_admin($adm)) return true;

    // NC-48 (auditoria 2026-08-05): esta função era FAIL-OPEN de dois jeitos.
    //
    // O declarado — "sem regra cadastrada, permite" — já era discutível. O real
    // era pior: a query consultava `SELECT allow FROM permissions WHERE role = ?
    // AND perm_key = ?`, mas a tabela `permissions` tem (id, admin_id,
    // permission_key, granted_at). Nenhuma das três colunas existe. A query
    // lançava exceção em TODA chamada, o catch devolvia `true`, e o controle
    // granular nunca funcionou — para nenhum papel, em momento algum.
    //
    // Agora é FAIL-CLOSED contra `role_permissions`, que tem o schema que o
    // mecanismo sempre esperou: sem regra explícita de permissão, nega.
    // Autorização que erra para o lado permissivo não é autorização.
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT allow FROM role_permissions WHERE role = ? AND perm_key = ? LIMIT 1");
        $st->execute([(string)($adm['role'] ?? ''), $permKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Regra ausente é negação — mas registrada, para que uma chave nova
            // esquecida no seed apareça como problema em vez de virar bloqueio
            // silencioso que ninguém entende.
            error_log("[has_permission] sem regra para role='" . (string)($adm['role'] ?? '')
                      . "' perm='" . $permKey . "' — negado por padrao");
            return false;
        }
        return (int)$row['allow'] === 1;
    } catch (Throwable $e) {
        error_log('[has_permission] falha ao consultar permissoes — negando: ' . $e->getMessage());
        return false;
    }
}

/**
 * Distância Euclidiana entre dois vetores (utilitário).
 */
function euclidean_distance(array $a, array $b): float {
    $sum = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $d = ((float)$a[$i]) - ((float)$b[$i]);
        $sum += $d * $d;
    }
    return sqrt($sum);
}

/**
 * Retorna thresholds de reconhecimento facial centralizados por contexto.
 * @param string $context 'checkin' (mais restritivo) ou 'identify' (mais permissivo)
 */
function get_face_thresholds(string $context = 'checkin'): array {
    // Política: face é a TERCEIRA camada de identificação, depois de CPF +
    // PIN. A identidade do colaborador JÁ FOI PROVADA antes da face entrar
    // em cena — o reconhecimento facial é um sinal suplementar, não a
    // barreira principal. Por isso, thresholds permissivos: quase nunca
    // rejeitamos a pessoa certa, mesmo em luz ruim, ângulo estranho, etc.
    //
    // O que ainda impede fraude:
    //   - CPF + PIN são "algo que você sabe" (um atacante precisaria saber
    //     ambos antes mesmo de chegar na face).
    //   - Liveness (piscadas, yaw, giroscópio) impede foto/vídeo impresso.
    //   - Step-up nonce de 300s impede replay.
    //   - second_best_margin permanece como guardrail leve.
    $defaults = [
        'checkin' => [
            // CPF + PIN já validados — só precisa "parecer com" o cadastrado
            'match_threshold'    => 0.70,  // bem permissivo (default lib = 0.60)
            'second_best_margin' => 0.05,  // guardrail leve
            'consensus_threshold'=> 0.70,
            'min_consensus_ratio'=> 0.20,  // 1 a cada 5 samples basta
        ],
        'identify' => [
            // Recovery: CPF informado mas PIN não — face é mais relevante.
            // Ainda permissivo, mas com cuidado contra confusão entre rostos.
            'match_threshold'    => 0.65,
            'second_best_margin' => 0.08,
            'consensus_threshold'=> 0.65,
            'min_consensus_ratio'=> 0.30,
        ],
    ];
    $ctx = $defaults[$context] ?? $defaults['checkin'];

    $prefix = "face_{$context}_";

    $matchThreshold = (float)(get_setting($prefix . 'threshold', (string)$ctx['match_threshold'])
                        ?? get_setting('face_match_threshold', (string)$ctx['match_threshold'])
                        ?? (string)$ctx['match_threshold']);
    if ($matchThreshold <= 0 || $matchThreshold > 1.5) {
        $matchThreshold = $ctx['match_threshold'];
    }

    $margin = (float)(get_setting($prefix . 'margin', (string)$ctx['second_best_margin'])
                ?? get_setting('face_second_best_margin', (string)$ctx['second_best_margin'])
                ?? (string)$ctx['second_best_margin']);
    if ($margin < 0 || $margin > 1) {
        $margin = $ctx['second_best_margin'];
    }

    $consensusThreshold = (float)(get_setting($prefix . 'consensus_threshold', (string)$ctx['consensus_threshold'])
                            ?? get_setting('face_consensus_threshold', (string)$ctx['consensus_threshold'])
                            ?? (string)$ctx['consensus_threshold']);
    if ($consensusThreshold <= 0 || $consensusThreshold > 1.5) {
        $consensusThreshold = $ctx['consensus_threshold'];
    }

    $consensusRatio = (float)(get_setting($prefix . 'consensus_ratio', (string)$ctx['min_consensus_ratio'])
                        ?? get_setting('face_min_consensus_ratio', (string)$ctx['min_consensus_ratio'])
                        ?? (string)$ctx['min_consensus_ratio']);
    if ($consensusRatio <= 0 || $consensusRatio > 1) {
        $consensusRatio = $ctx['min_consensus_ratio'];
    }

    return [
        'match_threshold'     => $matchThreshold,
        'second_best_margin'  => $margin,
        'consensus_threshold' => $consensusThreshold,
        'min_consensus_ratio' => $consensusRatio,
    ];
}

/**
 * Retorna o numero minimo de descriptors cadastrados para permitir autenticacao facial.
 * Com poucos descriptors, a camada de consenso se torna inutil (minHits=1 sempre passa).
 */
function get_min_face_descriptors_for_auth(): int {
    $v = (int)(get_setting('face_min_descriptors_for_auth', '3') ?? '3');
    return max(2, min(10, $v));
}

function normalize_face_descriptors(array $descriptors, int $maxSamples = 20): array {
    $normalized = [];
    foreach ($descriptors as $descriptor) {
        if (!is_array($descriptor) || count($descriptor) !== 128) {
            throw new InvalidArgumentException('Formato de descritor inválido (esperado: array de 128 floats).');
        }
        $vector = [];
        foreach ($descriptor as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) {
                throw new InvalidArgumentException('Descritor facial contém valores inválidos.');
            }
            $vector[] = (float)$value;
        }
        $normalized[] = $vector;
    }
    if (empty($normalized)) {
        throw new InvalidArgumentException('Descritores faciais ausentes ou inválidos.');
    }
    if ($maxSamples > 0 && count($normalized) > $maxSamples) {
        $normalized = array_slice($normalized, -$maxSamples);
    }
    return $normalized;
}

/**
 * Remove descritores faciais quase-duplicados (distância < threshold).
 * Mantém o primeiro descritor de cada grupo similar.
 */
function deduplicate_face_descriptors(array $descriptors, ?float $threshold = null): array {
    if ($threshold === null) {
        $threshold = (float)(get_setting('face_dedup_threshold', '0.25') ?? '0.25');
    }
    $deduped = [];
    foreach ($descriptors as $desc) {
        $isDuplicate = false;
        foreach ($deduped as $kept) {
            if (euclidean_distance($desc, $kept) < $threshold) {
                $isDuplicate = true;
                break;
            }
        }
        if (!$isDuplicate) {
            $deduped[] = $desc;
        }
    }
    return $deduped;
}

function find_active_face_conflict(PDO $pdo, array $incomingDescriptors, int $ignoreTeacherId = 0): ?array {
    $threshold = (float)(get_setting('face_uniqueness_threshold', '0.38') ?? '0.38');
    if ($threshold <= 0 || $threshold > 1.5) {
        $threshold = 0.46;
    }
    $strongThreshold = (float)(get_setting('face_uniqueness_strong_threshold', (string)max(0.20, $threshold - 0.06)) ?? (string)max(0.20, $threshold - 0.06));
    if ($strongThreshold <= 0 || $strongThreshold > $threshold) {
        $strongThreshold = max(0.20, $threshold - 0.06);
    }
    $minHits = (int)(get_setting('face_uniqueness_min_hits', '1') ?? '1');
    if ($minHits < 1) {
        $minHits = 1;
    } elseif ($minHits > 10) {
        $minHits = 10;
    }

    if ($ignoreTeacherId > 0) {
        $stmt = $pdo->prepare("SELECT id, name, cpf, face_descriptors FROM teachers WHERE active = 1 AND id <> ? AND face_descriptors IS NOT NULL AND face_descriptors <> ''");
        $stmt->execute([$ignoreTeacherId]);
    } else {
        $stmt = $pdo->query("SELECT id, name, cpf, face_descriptors FROM teachers WHERE active = 1 AND face_descriptors IS NOT NULL AND face_descriptors <> ''");
    }

    $bestConflict = null;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $storedRaw = face_descriptors_decode($row['face_descriptors']);
        if (!is_array($storedRaw) || empty($storedRaw)) {
            continue;
        }

        $stored = [];
        foreach ($storedRaw as $descriptor) {
            if (!is_array($descriptor) || count($descriptor) !== 128) {
                continue;
            }
            $vector = [];
            $valid = true;
            foreach ($descriptor as $value) {
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    $valid = false;
                    break;
                }
                $vector[] = (float)$value;
            }
            if ($valid) {
                $stored[] = $vector;
            }
        }
        if (empty($stored)) {
            continue;
        }

        $hitCount = 0;
        $bestDistance = INF;
        $sumBestDistances = 0.0;
        $matchedCount = 0;

        foreach ($incomingDescriptors as $incoming) {
            $probeBest = INF;
            foreach ($stored as $reference) {
                $distance = euclidean_distance($incoming, $reference);
                if ($distance < $probeBest) {
                    $probeBest = $distance;
                }
            }
            if (is_finite($probeBest)) {
                $matchedCount++;
                $sumBestDistances += $probeBest;
                if ($probeBest < $bestDistance) {
                    $bestDistance = $probeBest;
                }
                if ($probeBest <= $threshold) {
                    $hitCount++;
                }
            }
        }

        if ($matchedCount === 0 || !is_finite($bestDistance)) {
            continue;
        }

        $accepted = ($hitCount >= $minHits) || ($hitCount >= 1 && $bestDistance <= $strongThreshold);
        if (!$accepted) {
            continue;
        }

        $avgBestDistance = $sumBestDistances / $matchedCount;
        $candidate = [
            'teacher_id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'cpf' => (string)($row['cpf'] ?? ''),
            'best_distance' => $bestDistance,
            'avg_best_distance' => $avgBestDistance,
            'hit_count' => $hitCount,
            'min_hits' => $minHits,
            'threshold' => $threshold
        ];

        if ($bestConflict === null
            || $candidate['best_distance'] < $bestConflict['best_distance']
            || ($candidate['best_distance'] === $bestConflict['best_distance'] && $candidate['hit_count'] > $bestConflict['hit_count'])) {
            $bestConflict = $candidate;
        }
    }

    return $bestConflict;
}

/**
 * Distância Haversine (metros) entre dois pontos geográficos.
 */
function haversine_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0; // raio da Terra em metros
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

/**
 * Retorna lista de escolas permitidas para um colaborador com suas geocoordenadas.
 * - Se network_wide = 1: todas as escolas ativas com lat/lng definidos.
 * - Senão: apenas as escolas vinculadas em teacher_schools com lat/lng definidos.
 */
function get_teacher_allowed_schools(PDO $pdo, int $teacherId): array {
    // OTIMIZAÇÃO: Cache em memória para evitar queries repetidas
    static $cache = [];
    
    if (isset($cache[$teacherId])) {
        return $cache[$teacherId];
    }
    
    $st = $pdo->prepare("SELECT network_wide FROM teachers WHERE id = ?");
    $st->execute([$teacherId]);
    $nw = (int)($st->fetchColumn() ?: 0);
    if ($nw === 1) {
        $q = $pdo->query("SELECT id, name, lat, lng FROM schools WHERE active=1 AND lat IS NOT NULL AND lng IS NOT NULL");
        $result = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $cache[$teacherId] = $result;
        return $result;
    }
    $st2 = $pdo->prepare("SELECT s.id, s.name, s.lat, s.lng
                          FROM teacher_schools ts
                          JOIN schools s ON s.id = ts.school_id
                          WHERE ts.teacher_id = ? AND s.active=1 AND s.lat IS NOT NULL AND s.lng IS NOT NULL");
    $st2->execute([$teacherId]);
    $result = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cache[$teacherId] = $result;
    return $result;
}

/**
 * Encontra a escola mais próxima dentro do raio informado (em metros).
 * Retorna [bool dentro, ?int school_id, ?float distancia_m]
 */
function match_school_by_geo(array $schools, float $lat, float $lng, float $radiusM): array {
    $bestId = null;
    $bestDist = null;
    foreach ($schools as $s) {
        if (!isset($s['lat'], $s['lng'])) continue;
        $d = haversine_distance_m((float)$s['lat'], (float)$s['lng'], $lat, $lng);
        if ($bestDist === null || $d < $bestDist) {
            $bestDist = $d;
            $bestId = (int)$s['id'];
        }
    }
    if ($bestDist !== null && $bestDist <= $radiusM) {
        return [true, $bestId, $bestDist];
    }
    return [false, null, $bestDist];
}

/**
 * Retorna colaboradores para matching facial, pré-filtrados por escola via geolocalização.
 * Se lat/lng disponíveis, filtra pela escola mais próxima + network_wide.
 * Caso contrário, retorna todos os ativos com face_descriptors.
 */
function get_teachers_for_face_matching(PDO $pdo, ?float $lat, ?float $lng): array {
    if ($lat !== null && $lng !== null) {
        $radiusM = (float)(get_setting('geofence_radius_m', '300') ?? '300');
        $searchRadius = $radiusM * 2; // Raio expandido para não perder matches legítimos

        $stSchools = $pdo->query("SELECT id, lat, lng FROM schools WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL");
        $schools = $stSchools->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($schools)) {
            [$matched, $schoolId, $dist] = match_school_by_geo($schools, $lat, $lng, $searchRadius);
            if ($matched && $schoolId) {
                $stmt = $pdo->prepare("
                    SELECT t.id, t.name, t.active, t.network_wide, t.cpf, t.face_descriptors, t.face_enrolled_at
                    FROM teachers t
                    JOIN teacher_schools ts ON ts.teacher_id = t.id
                    WHERE t.active = 1 AND t.face_descriptors IS NOT NULL AND t.face_descriptors != ''
                      AND ts.school_id = ?
                    UNION
                    SELECT t.id, t.name, t.active, t.network_wide, t.cpf, t.face_descriptors, t.face_enrolled_at
                    FROM teachers t
                    WHERE t.active = 1 AND t.face_descriptors IS NOT NULL AND t.face_descriptors != ''
                      AND t.network_wide = 1
                ");
                $stmt->execute([$schoolId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }

    // Fallback: retorna todos os colaboradores ativos com face registrada
    $stmt = $pdo->query("
        SELECT id, name, active, network_wide, cpf, face_descriptors, face_enrolled_at
        FROM teachers
        WHERE active = 1 AND face_descriptors IS NOT NULL AND face_descriptors != ''
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resolve o intervalo [start, end] de um schedule de tempo para uma data específica,
 * tratando turnos que cruzam a meia-noite (vigilantes 18h → 06h, etc).
 *
 * Aceita explicitamente a flag end_next_day. Quando ausente ou falsa, faz fallback
 * heurístico para o comportamento legado (`end <= start` ⇒ cruzou meia-noite), o
 * que mantém compatibilidade com call-sites antigos antes da migration rodar.
 *
 * @param array $ts Row de collaborator_time_schedules (start_time, end_time,
 *                  end_next_day opcional, break_minutes opcional).
 * @param string $date Data-base no formato Y-m-d (dia da entrada).
 * @return array{start: DateTime, end: DateTime, breakMin: int, crossesMidnight: bool}|null
 *         null se start_time ou end_time forem NULL/vazios.
 */
function compute_schedule_window(array $ts, string $date): ?array {
    if (empty($ts['start_time']) || empty($ts['end_time'])) return null;
    try {
        $start = new DateTime($date . ' ' . $ts['start_time']);
        $end   = new DateTime($date . ' ' . $ts['end_time']);
    } catch (Throwable $e) {
        return null;
    }
    $flag = !empty($ts['end_next_day']);
    $crosses = $flag || $end <= $start;
    if ($crosses) $end->modify('+1 day');
    return [
        'start' => $start,
        'end' => $end,
        'breakMin' => (int)($ts['break_minutes'] ?? 0),
        'crossesMidnight' => $crosses,
    ];
}

/**
 * Calcula minutos esperados de trabalho para um colaborador em uma data específica.
 *
 * Modelo "cheio vs cheio": retorna a janela COMPLETA da jornada (mode='time':
 * fim − início, SEM descontar break_minutes — o intervalo conta como tempo
 * trabalhado e break_minutes é meramente informativo). Comparar sempre com
 * calculate_effective_worked_minutes(), que retorna a presença cheia (work+break).
 */
function calculate_expected_minutes(PDO $pdo, int $teacherId, string $date): int {
    // Escola primária do colaborador (para resolver sábado letivo e feriados
    // específicos por escola). Sem lotação → NULL, captura só exceções da rede.
    $schoolId = primary_school_id_for_teacher($pdo, $teacherId);

    // Weekday "efetivo": se a data tem exceção 'workday' com reflects_weekday (sábado letivo),
    // usa esse weekday para buscar a jornada. Senão, usa o weekday real.
    $weekday = get_effective_weekday($pdo, $date, $schoolId);

    // Detecta o modo de agenda do colaborador
    $stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
    $stMode->execute([$teacherId]);
    $mode = $stMode->fetchColumn() ?: 'classes';

    $expMin = 0;

    if ($mode === 'classes') {
        $st = $pdo->prepare("SELECT classes_count, class_minutes FROM teacher_schedules WHERE teacher_id=? AND weekday=?");
        $st->execute([$teacherId, $weekday]);
        if ($sc = $st->fetch(PDO::FETCH_ASSOC)) {
            $expMin = (int)$sc['classes_count'] * (int)$sc['class_minutes'];
        }
    } elseif ($mode === 'time') {
        $st = $pdo->prepare("SELECT start_time, end_time, end_next_day, break_minutes FROM collaborator_time_schedules WHERE teacher_id=? AND weekday=?");
        $st->execute([$teacherId, $weekday]);
        if ($ts = $st->fetch(PDO::FETCH_ASSOC)) {
            $win = compute_schedule_window($ts, $date);
            if ($win) {
                // Janela completa — break_minutes NÃO é descontado (intervalo conta
                // como tempo trabalhado; ver docblock).
                $expMin = max(0, (int)(($win['end']->getTimestamp() - $win['start']->getTimestamp()) / 60));
            }
        }
    } elseif ($mode === 'hours') {
        // Jornada "X horas/dia" — sem horário fixo (motorista, monitor, etc).
        // Guarda total de minutos por weekday em collaborator_hours_schedules.
        $st = $pdo->prepare("SELECT total_minutes FROM collaborator_hours_schedules WHERE teacher_id=? AND weekday=?");
        $st->execute([$teacherId, $weekday]);
        $val = $st->fetchColumn();
        if ($val !== false && $val !== null) {
            $expMin = max(0, (int)$val);
        }
    }

    // Feriado / ponto facultativo / recesso (exceção de calendário não-útil) zera
    // a jornada prevista — respeitando a escola do colaborador OU a rede inteira.
    if ($expMin > 0 && calendar_day_is_off($pdo, $date, $schoolId)) {
        $expMin = 0;
    }

    // Se há afastamento aprovado que ABONA a falta neste dia, minutos esperados = 0.
    // Critério é por registro (excuses_absence), desacoplado de leave_types.paid.
    $stL = $pdo->prepare("SELECT 1 FROM leaves l WHERE l.teacher_id=? AND l.approved=1 AND l.excuses_absence=1 AND ? BETWEEN l.start_date AND l.end_date LIMIT 1");
    $stL->execute([$teacherId, $date]);
    if ($stL->fetchColumn()) {
        $expMin = 0;
    }

    return $expMin;
}

/**
 * Retorna true se algum afastamento do dia ABONA a falta (excuses_absence=1).
 * Aceita arrays de licenca em qualquer formato que contenha a chave
 * 'excuses_absence' (linhas cruas de `leaves` ou os arrays reduzidos montados
 * nos relatorios). Ausente/NULL é tratado como "nao abona".
 */
function leave_day_is_excused(array $leaves): bool {
    foreach ($leaves as $lv) {
        if ((int)($lv['excuses_absence'] ?? 0) === 1) return true;
    }
    return false;
}

/**
 * Fragmento SQL que restringe uma consulta aos registros de ponto VIGENTES.
 *
 * O sistema nunca apaga marcação (Portaria MTP 671/2021): registro anulado ou
 * substituído continua na tabela. Duas situações precisam ficar de fora de
 * qualquer cálculo de jornada:
 *   - `removed_at IS NOT NULL`      → anulado pelo admin (admin_remove_attendance)
 *   - `superseded_by_id IS NOT NULL`→ duplicata de dedupe offline, ou registro
 *                                     substituído por uma edição
 *
 * NC-51/52/53 (auditoria 2026-08-05): esse filtro estava ausente. Vários pontos
 * escapavam por acidente, porque anular também seta `approved = 0` e a query
 * filtrava `approved = 1` — mas calculate_effective_worked_minutes() não filtra
 * aprovação nenhuma (breaks pendentes contam de propósito), então um intervalo
 * anulado seguia somando como tempo trabalhado. Concentrar a regra aqui evita
 * que o próximo cálculo criado esqueça de novo.
 *
 * @param string $alias Alias da tabela attendance na query ('' se não houver).
 */
function attendance_vigente_sql(string $alias = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "({$p}removed_at IS NULL AND {$p}superseded_by_id IS NULL)";
}

/**
 * Calcula minutos trabalhados APROVADOS (record_type='work') em uma data específica.
 * Soma TODOS os pares check_in/check_out aprovados — múltiplos pontos no mesmo dia são suportados.
 * Tempo de intervalo (record_type='break') NÃO é contabilizado aqui.
 * Para tempo de intervalo, ver calculate_break_minutes().
 */
function calculate_worked_minutes(PDO $pdo, int $teacherId, string $date): int {
    // record_type='work' OR record_type IS NULL preserva compatibilidade caso a migration
    // ainda não tenha rodado em algum ambiente (defensivo).
    $stW = $pdo->prepare("SELECT check_in, check_out FROM attendance
                          WHERE teacher_id=? AND date=?
                            AND check_in IS NOT NULL AND check_out IS NOT NULL
                            AND approved = 1
                            AND " . attendance_vigente_sql() . "
                            AND (record_type = 'work' OR record_type IS NULL)");
    $stW->execute([$teacherId, $date]);
    $worked = 0;
    while ($r = $stW->fetch(PDO::FETCH_ASSOC)) {
        if ($r['check_in'] && $r['check_out']) {
            $ci = new DateTime($r['check_in']);
            $co = new DateTime($r['check_out']);
            if ($co > $ci) {
                $worked += (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
            }
        }
    }
    return $worked;
}

/**
 * Calcula minutos APROVADOS em INTERVALO (record_type='break') em uma data específica.
 * Soma todos os pares de intervalo aprovados do dia.
 */
function calculate_break_minutes(PDO $pdo, int $teacherId, string $date): int {
    try {
        $stB = $pdo->prepare("SELECT check_in, check_out FROM attendance
                              WHERE teacher_id=? AND date=?
                                AND check_in IS NOT NULL AND check_out IS NOT NULL
                                AND approved = 1
                                AND " . attendance_vigente_sql() . "
                                AND record_type = 'break'");
        $stB->execute([$teacherId, $date]);
    } catch (Throwable $_) {
        // Coluna record_type pode não existir ainda (migration pendente).
        return 0;
    }
    $breakMin = 0;
    while ($r = $stB->fetch(PDO::FETCH_ASSOC)) {
        if ($r['check_in'] && $r['check_out']) {
            $ci = new DateTime($r['check_in']);
            $co = new DateTime($r['check_out']);
            if ($co > $ci) {
                $breakMin += (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
            }
        }
    }
    return $breakMin;
}

/**
 * Retorna os minutos de intervalo PREVISTOS na rotina do colaborador para uma data,
 * lendo collaborator_time_schedules.break_minutes do weekday efetivo.
 *
 * Retorna 0 quando:
 *  - Colaborador é tipo 'classes' (não usa time schedule)
 *  - Não há jornada cadastrada para o weekday
 *  - break_minutes da jornada é 0
 *
 * Usa get_effective_weekday() para respeitar sábado letivo.
 */
function get_expected_break_minutes(PDO $pdo, int $teacherId, string $date): int {
    try {
        // Escola primária para resolver sábado letivo específico de escola.
        try {
            $stSchool = $pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id = ? ORDER BY school_id LIMIT 1");
            $stSchool->execute([$teacherId]);
            $schoolIdRaw = $stSchool->fetchColumn();
            $schoolId = ($schoolIdRaw !== false && $schoolIdRaw !== null) ? (int)$schoolIdRaw : null;
        } catch (Throwable $_) {
            $schoolId = null;
        }
        $weekday = get_effective_weekday($pdo, $date, $schoolId);

        // Modo de jornada determina de qual tabela ler o break previsto.
        $stMode = $pdo->prepare("SELECT ct.schedule_mode FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
        $stMode->execute([$teacherId]);
        $mode = $stMode->fetchColumn() ?: 'classes';

        if ($mode === 'hours') {
            $st = $pdo->prepare("SELECT break_minutes FROM collaborator_hours_schedules
                                 WHERE teacher_id = ? AND weekday = ?");
        } else {
            $st = $pdo->prepare("SELECT break_minutes FROM collaborator_time_schedules
                                 WHERE teacher_id = ? AND weekday = ?");
        }
        $st->execute([$teacherId, $weekday]);
        $val = $st->fetchColumn();
        return ($val !== false && $val !== null) ? max(0, (int)$val) : 0;
    } catch (Throwable $_) {
        return 0;
    }
}

/**
 * Calcula minutos TRABALHADOS EFETIVOS — valor usado para saldo, banco de horas e
 * comparação com `calculate_expected_minutes()`.
 *
 * Modelo "cheio vs cheio": o intervalo é um direito do colaborador (almoço/descanso)
 * e NUNCA subtrai o tempo trabalhado — é apenas informativo. O efetivo é o tempo de
 * presença CHEIO: pares 'work' + pares 'break'. A contrapartida é que
 * `calculate_expected_minutes()` retorna a janela COMPLETA da jornada (sem descontar
 * break_minutes, que virou campo informativo).
 *
 * Breaks contam independentemente de aprovação (approved=1 OU pendente): os pares
 * 'work' aprovados ao redor já comprovam a presença; exigir aprovação faria um
 * intervalo pendente derrubar o saldo do dia.
 *
 * Não altera `calculate_worked_minutes` (soma bruta dos pares work, útil em
 * relatórios de transparência) nem `calculate_break_minutes` (breaks aprovados,
 * usada em exibições). Esta função é a CANÔNICA para qualquer delta vs esperado.
 */
function calculate_effective_worked_minutes(PDO $pdo, int $teacherId, string $date): int {
    $worked = calculate_worked_minutes($pdo, $teacherId, $date);

    // Soma de TODOS os breaks fechados do dia (aprovados ou pendentes).
    $breakMin = 0;
    try {
        // NC-51: sem este filtro, um intervalo ANULADO pelo admin continuava
        // somando como tempo trabalhado. Aqui não há filtro de `approved` (breaks
        // pendentes contam de propósito, ver docblock), então a anulação era a
        // única coisa que separava um intervalo válido de um descartado.
        $stB = $pdo->prepare("SELECT check_in, check_out FROM attendance
                              WHERE teacher_id=? AND date=?
                                AND check_in IS NOT NULL AND check_out IS NOT NULL
                                AND " . attendance_vigente_sql() . "
                                AND record_type = 'break'");
        $stB->execute([$teacherId, $date]);
        while ($r = $stB->fetch(PDO::FETCH_ASSOC)) {
            $ci = new DateTime($r['check_in']);
            $co = new DateTime($r['check_out']);
            if ($co > $ci) {
                $breakMin += (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
            }
        }
    } catch (Throwable $_) {
        // Coluna record_type pode não existir (migration pendente) — sem breaks.
    }

    return $worked + $breakMin;
}

/**
 * Consolida as horas do MÊS para um colaborador: soma dia a dia os minutos
 * PREVISTOS (calculate_expected_minutes) e os EFETIVAMENTE trabalhados
 * (calculate_effective_worked_minutes — modelo cheio vs cheio), respeitando a
 * janela de contagem (counting_start_for) e ignorando dias no futuro.
 *
 * É a base da nova visão de jornada flexível: em vez de "banco de horas" com
 * saldo negativo dia a dia, apresenta a foto mensal — previsto, trabalhado,
 * "horas além do previsto" e uma situação geral em linguagem neutra. NUNCA usa
 * "hora extra", "déficit", "saldo negativo" ou "débito".
 *
 * "Horas compensadas" (compensated_minutes) é o excedente do mês (worked −
 * expected, quando positivo): naturalmente, dias com mais horas cobrem dias com
 * menos dentro do mesmo mês. "A compensar" (remaining_to_compensate_minutes) é o
 * que ainda falta para a carga prevista — exibido ao admin como informativo.
 * Aplica a mesma tolerância (tolerance_minutes) usada no recálculo diário.
 *
 * @return array{
 *   expected_minutes:int, worked_minutes:int,
 *   compensated_minutes:int, remaining_to_compensate_minutes:int,
 *   situation:string, situation_label:string, is_current_month:bool
 * }  situation ∈ {'em_dia','em_andamento','a_compensar'}
 */
function get_monthly_hours_summary(PDO $pdo, int $teacherId, int $year, int $month, ?string $createdAt = null): array {
    $first        = sprintf('%04d-%02d-01', $year, $month);
    $daysInMonth  = (int)date('t', strtotime($first));
    $today        = date('Y-m-d');
    $countingStart = counting_start_for($createdAt); // 'Y-m-d'
    $tolerance    = (int)(get_setting('tolerance_minutes', '5') ?? '5');

    $expected = 0;
    $worked   = 0;
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        // Fora da janela de contagem (antes da admissão/início) ou no futuro: não conta.
        if ($date < $countingStart || $date > $today) {
            continue;
        }
        $expected += (int)calculate_expected_minutes($pdo, $teacherId, $date);
        $worked   += (int)calculate_effective_worked_minutes($pdo, $teacherId, $date);
    }

    $delta = $worked - $expected;
    if (abs($delta) <= $tolerance) {
        $delta = 0;
    }
    $compensated = max(0, $delta);
    $remaining   = max(0, -$delta);

    $isCurrentMonth = (sprintf('%04d-%02d', $year, $month) === date('Y-m'));

    if ($remaining <= 0) {
        $situation = 'em_dia';
        $label     = 'Em dia com a carga horária';
    } elseif ($isCurrentMonth) {
        $situation = 'em_andamento';
        $label     = 'Mês em andamento — horas ainda sendo contabilizadas';
    } else {
        $situation = 'a_compensar';
        $label     = 'Há horas a compensar';
    }

    return [
        'expected_minutes'                => $expected,
        'worked_minutes'                  => $worked,
        'compensated_minutes'             => $compensated,
        'remaining_to_compensate_minutes' => $remaining,
        'situation'                       => $situation,
        'situation_label'                 => $label,
        'is_current_month'                => $isCurrentMonth,
    ];
}

/**
 * Recalcula o banco de horas do dia a partir de um attendance_id, delegando a
 * recompute_day_hour_bank() (a função canônica de ressincronização do ledger).
 *
 * Por que existe: o lançamento auto é gravado no momento do CHECKOUT, com o
 * estado daquele instante. Se o ponto estava pendente (approved=NULL), worked=0
 * e o banco recebe −jornada inteira; quando o admin aprova depois, nada
 * atualizava o lançamento — o débito ficava fossilizado. Chame em QUALQUER
 * mutação pós-checkout: aprovação/rejeição, edição de horários, fechamento
 * manual, regularização de saída ou intervalo.
 *
 * NUNCA lança exceção — o recálculo é efeito colateral; a ação principal
 * (aprovar, editar, remover) não pode falhar por causa dele.
 */
function recalculate_hour_bank_for_attendance(PDO $pdo, int $attendanceId): void {
    try {
        $st = $pdo->prepare("SELECT teacher_id, date FROM attendance WHERE id = ? LIMIT 1");
        $st->execute([$attendanceId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            recompute_day_hour_bank($pdo, (int)$row['teacher_id'], (string)$row['date']);
        }
    } catch (Throwable $e) {
        error_log('[recalculate_hour_bank_for_attendance] att=' . $attendanceId . ': ' . $e->getMessage());
    }
}

/**
 * Detecta o estado atual de batida do colaborador (FORA / TRABALHANDO / EM_INTERVALO)
 * com base em qual registro está aberto (check_out IS NULL).
 *
 * Retorna:
 *   - state: 'fora' | 'trabalhando' | 'em_intervalo'
 *   - open_id: id do registro aberto (ou null)
 *   - open_check_in: datetime de entrada aberta (ou null)
 *   - open_record_type: 'work' | 'break' | null
 *   - parent_work_id: id do work pai (quando em intervalo)
 *
 * @param int|null $openWindow Janela em horas para considerar um check_in como ainda válido.
 *                              Default: lê app_settings ou 30h.
 */
function detect_collaborator_state(PDO $pdo, int $teacherId, ?int $openWindow = null): array {
    if ($openWindow === null) {
        $openWindow = (int)(function_exists('get_setting') ? get_setting('open_checkin_window_hours', '30') : 30);
        if ($openWindow <= 0) $openWindow = 30;
    }

    try {
        // NC-52: exclui anulados/substituídos.
        $sql = "SELECT id, check_in, record_type, parent_attendance_id
                FROM attendance
                WHERE teacher_id = ?
                  AND check_in IS NOT NULL AND check_out IS NULL
                  AND " . attendance_vigente_sql() . "
                  AND check_in >= (NOW() - INTERVAL ? HOUR)
                ORDER BY check_in DESC
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$teacherId, $openWindow]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $_) {
        // Fallback se colunas novas não existirem (migration pendente).
        $sql = "SELECT id, check_in FROM attendance
                WHERE teacher_id = ?
                  AND check_in IS NOT NULL AND check_out IS NULL
                  AND " . attendance_vigente_sql() . "
                  AND check_in >= (NOW() - INTERVAL ? HOUR)
                ORDER BY check_in DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$teacherId, $openWindow]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) $row['record_type'] = 'work';
        if ($row) $row['parent_attendance_id'] = null;
    }

    if (!$row) {
        return ['state' => 'fora', 'open_id' => null, 'open_check_in' => null, 'open_record_type' => null, 'parent_work_id' => null];
    }

    $recordType = $row['record_type'] ?? 'work';
    $state = ($recordType === 'break') ? 'em_intervalo' : 'trabalhando';
    return [
        'state' => $state,
        'open_id' => (int)$row['id'],
        'open_check_in' => $row['check_in'],
        'open_record_type' => $recordType,
        'parent_work_id' => isset($row['parent_attendance_id']) ? (int)$row['parent_attendance_id'] : null,
    ];
}

/**
 * Calcula o excesso de intervalo do dia em relação ao break_minutes configurado na jornada.
 *
 * Retorna:
 *   - used_min: total de minutos de intervalo já gozados no dia
 *   - allowed_min: minutos esperados pelo collaborator_time_schedules.break_minutes (ou 0)
 *   - overage_min: max(0, used_min - allowed_min) — só vale quando há jornada do tipo 'time'
 *
 * Para colaboradores tipo 'classes' (professores), allowed_min é 0 e overage_min reflete o uso real.
 */
function get_break_overage(PDO $pdo, int $teacherId, string $date): array {
    $usedMin = calculate_break_minutes($pdo, $teacherId, $date);

    // Escola primária para sábado letivo
    try {
        $stSchool = $pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id = ? ORDER BY school_id LIMIT 1");
        $stSchool->execute([$teacherId]);
        $schoolIdRaw = $stSchool->fetchColumn();
        $schoolId = ($schoolIdRaw !== false && $schoolIdRaw !== null) ? (int)$schoolIdRaw : null;
    } catch (Throwable $_) {
        $schoolId = null;
    }
    $weekday = get_effective_weekday($pdo, $date, $schoolId);

    $allowedMin = 0;
    try {
        $st = $pdo->prepare("SELECT break_minutes FROM collaborator_time_schedules WHERE teacher_id=? AND weekday=?");
        $st->execute([$teacherId, $weekday]);
        $val = $st->fetchColumn();
        if ($val !== false && $val !== null) $allowedMin = (int)$val;
    } catch (Throwable $_) {
        $allowedMin = 0;
    }

    $overage = max(0, $usedMin - $allowedMin);
    return ['used_min' => $usedMin, 'allowed_min' => $allowedMin, 'overage_min' => $overage];
}

/**
 * Calcula a extrapolacao de jornada para um attendance recem-fechado, SEM gravar nada.
 *
 * Retorna se o ponto eh elegivel para solicitar hora extra. Eh chamado pela API de
 * checkout para informar o cliente que pode (opcionalmente) solicitar hora extra,
 * e pelo endpoint api/request_overtime.php para validar o pedido server-side.
 *
 * @return array {
 *   eligible: bool,           // true se ha minutos excedentes
 *   minutes: int,             // minutos a creditar como hora extra (sempre >= 0)
 *   expected_minutes: int,    // jornada esperada do dia
 *   worked_minutes: int,      // total trabalhado no dia
 *   reason: 'workday_exceeded'|'unscheduled_day'|null
 * }
 */
/** @deprecated Hora extra descontinuada — função sem call sites ativos; mantida só para compatibilidade/auditoria. */
function compute_overtime_exceedance(PDO $pdo, int $attendanceId, int $teacherId, string $date): array {
    $blank = ['eligible' => false, 'minutes' => 0, 'expected_minutes' => 0, 'worked_minutes' => 0, 'reason' => null];

    // Linha de intervalo (record_type='break') jamais gera hora extra.
    try {
        $stAtt = $pdo->prepare("SELECT check_in, check_out, is_overtime_candidate, record_type FROM attendance WHERE id = ?");
        $stAtt->execute([$attendanceId]);
        $att = $stAtt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $_) {
        // Fallback sem record_type
        $stAtt = $pdo->prepare("SELECT check_in, check_out, is_overtime_candidate FROM attendance WHERE id = ?");
        $stAtt->execute([$attendanceId]);
        $att = $stAtt->fetch(PDO::FETCH_ASSOC);
        if ($att) $att['record_type'] = 'work';
    }

    if (!$att || !$att['check_in'] || !$att['check_out']) {
        return $blank;
    }
    if (($att['record_type'] ?? 'work') === 'break') {
        return $blank;
    }

    $ci = new DateTime($att['check_in']);
    $co = new DateTime($att['check_out']);
    if ($co <= $ci) return $blank;
    $workedThisPunch = (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);

    $expectedMinutes = calculate_expected_minutes($pdo, $teacherId, $date);
    // Usa EFETIVO (já desconta intervalo previsto quando não há manual) — evita falsas
    // horas extras quando colaborador bate apenas entrada/saída numa rotina com break.
    $workedMinutes   = calculate_effective_worked_minutes($pdo, $teacherId, $date);

    // Caso 1: dia com jornada definida e ultrapassada.
    if ($expectedMinutes > 0) {
        $exceeded = $workedMinutes - $expectedMinutes;
        if ($exceeded > 0) {
            return [
                'eligible' => true,
                'minutes' => $exceeded,
                'expected_minutes' => $expectedMinutes,
                'worked_minutes' => $workedMinutes,
                'reason' => 'workday_exceeded',
            ];
        }
        return array_merge($blank, ['expected_minutes' => $expectedMinutes, 'worked_minutes' => $workedMinutes]);
    }

    // Caso 2: dia sem jornada (ex. dia sem aulas) mas com trabalho registrado.
    // O sistema ja marca is_overtime_candidate=1 nesses pontos via api/checkin.
    if (!empty($att['is_overtime_candidate']) && $workedThisPunch > 0) {
        return [
            'eligible' => true,
            'minutes' => $workedThisPunch,
            'expected_minutes' => 0,
            'worked_minutes' => $workedMinutes ?: $workedThisPunch,
            'reason' => 'unscheduled_day',
        ];
    }

    return $blank;
}

/**
 * Cria uma solicitacao de hora extra a partir de um clique do colaborador no botao
 * "Solicitar hora extra". A justificativa eh obrigatoria. Os minutos sao recalculados
 * server-side via compute_overtime_exceedance — valores enviados pelo cliente sao ignorados.
 *
 * @return array {
 *   created: bool,
 *   overtime_id: int|null,
 *   minutes: int,
 *   message: string
 * }
 */
/** @deprecated Hora extra descontinuada — função sem call sites ativos; mantida só para compatibilidade/auditoria. */
function create_overtime_request(PDO $pdo, int $attendanceId, int $teacherId, ?int $schoolId, string $date, string $justification): array {
    $justification = trim($justification);
    if ($justification === '') {
        return ['created' => false, 'overtime_id' => null, 'minutes' => 0, 'message' => 'Justificativa obrigatoria'];
    }
    if (mb_strlen($justification) > 500) {
        $justification = mb_substr($justification, 0, 500);
    }

    $stCheck = $pdo->prepare("SELECT id FROM overtime_requests WHERE attendance_id = ?");
    $stCheck->execute([$attendanceId]);
    if ($stCheck->fetch()) {
        return ['created' => false, 'overtime_id' => null, 'minutes' => 0, 'message' => 'Solicitacao de hora extra ja existe para este ponto'];
    }

    $calc = compute_overtime_exceedance($pdo, $attendanceId, $teacherId, $date);
    if (!$calc['eligible'] || $calc['minutes'] <= 0) {
        return ['created' => false, 'overtime_id' => null, 'minutes' => 0, 'message' => 'Este ponto nao apresenta extrapolacao de jornada'];
    }

    $stInsert = $pdo->prepare("INSERT INTO overtime_requests
        (attendance_id, teacher_id, school_id, date, minutes, expected_minutes, worked_minutes, status, justification, requested_by_employee)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, 1)");
    $stInsert->execute([
        $attendanceId, $teacherId, $schoolId, $date,
        $calc['minutes'], $calc['expected_minutes'], $calc['worked_minutes'],
        $justification
    ]);
    $overtimeId = (int)$pdo->lastInsertId();

    audit_log('create', 'overtime_request', $overtimeId, [
        'attendance_id'    => $attendanceId,
        'teacher_id'       => $teacherId,
        'overtime_minutes' => $calc['minutes'],
        'reason'           => $calc['reason'],
        'source'           => 'employee_request',
    ]);

    return [
        'created'     => true,
        'overtime_id' => $overtimeId,
        'minutes'     => $calc['minutes'],
        'message'     => sprintf('Solicitacao de hora extra de %d minutos enviada para analise', $calc['minutes']),
    ];
}

/**
 * Auto-rejeita solicitações de hora extra quando um ponto é rejeitado.
 * Deve ser chamado quando um attendance.approved é alterado para 0 (rejeitado).
 * 
 * @param PDO $pdo Conexão PDO
 * @param int $attendanceId ID do attendance rejeitado
 * @param int $adminId ID do admin que está rejeitando
 * @return int Número de solicitações auto-rejeitadas
 */
/**
 * Detecta pontos orfaos do colaborador (entrada sem saida) FORA da janela de
 * "turno em andamento" (30h por padrao) e dentro de uma janela de regularizacao
 * (14 dias por padrao). Usada no checkin para bloquear nova entrada ate o
 * colaborador regularizar — ou ignorar conscientemente.
 *
 * Retorna array de attendance rows com chave extra 'pending_regularization' indicando
 * se ja existe solicitacao pendente (true) ou nao (false). Linhas com status='approved'
 * sao excluidas (significam que admin ja aplicou o checkout).
 */
function detect_checkout_orphans(PDO $pdo, int $teacherId, int $daysBack = 14, int $openWindowHours = 30): array {
    if ($daysBack <= 0) $daysBack = 14;
    if ($openWindowHours <= 0) $openWindowHours = 30;

    // Bloqueia apenas se NAO ha solicitacao OU se a anterior foi REJEITADA.
    // Solicitacoes 'pending' nao bloqueiam — o colaborador ja agiu, aguarda o admin.
    // 'approved' implicitamente nao bloqueia (o check_out ja foi preenchido).
    $sql = "SELECT a.id, a.teacher_id, a.school_id, a.date, a.check_in, s.name AS school_name,
                   acr.id AS request_id, acr.status AS request_status, acr.proposed_check_out, acr.justification
            FROM attendance a
            LEFT JOIN schools s ON s.id = a.school_id
            LEFT JOIN attendance_checkout_requests acr ON acr.attendance_id = a.id
            WHERE a.teacher_id = ?
              AND a.check_in IS NOT NULL
              AND a.check_out IS NULL
              AND a.superseded_by_id IS NULL
              AND a.removed_at IS NULL
              AND a.check_in <  (NOW() - INTERVAL ? HOUR)
              AND a.check_in >= (NOW() - INTERVAL ? DAY)
              AND (acr.status IS NULL OR acr.status = 'rejected')
            ORDER BY a.check_in ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$teacherId, $openWindowHours, $daysBack]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['pending_regularization'] = ($row['request_status'] === 'pending');
    }
    return $rows;
}

/**
 * Cria uma solicitacao de regularizacao de checkout (esqueceu de bater saida).
 * O horario eh validado server-side: deve estar APOS o check_in, no MESMO DIA ou
 * ate as 06h do dia seguinte (cobre turnos noturnos), e nunca no futuro.
 *
 * Justificativa obrigatoria. Janela retroativa: ate 60 dias. Se ja existe
 * solicitacao pendente para este attendance, eh rejeitada como duplicata.
 */
function create_checkout_regularization_request(PDO $pdo, int $attendanceId, int $teacherId, string $proposedCheckOut, string $justification, int $maxDaysBack = 60): array {
    $justification = trim($justification);
    if ($justification === '') {
        return ['created' => false, 'request_id' => null, 'message' => 'Justificativa obrigatoria'];
    }
    if (mb_strlen($justification) > 500) {
        $justification = mb_substr($justification, 0, 500);
    }

    // SELECT inclui record_type para validar caso patológico: ponto de intervalo aberto.
    try {
        $stAtt = $pdo->prepare("SELECT id, teacher_id, school_id, date, check_in, check_out, approved, record_type
                                FROM attendance WHERE id = ?");
        $stAtt->execute([$attendanceId]);
        $att = $stAtt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $_) {
        $stAtt = $pdo->prepare("SELECT id, teacher_id, school_id, date, check_in, check_out, approved
                                FROM attendance WHERE id = ?");
        $stAtt->execute([$attendanceId]);
        $att = $stAtt->fetch(PDO::FETCH_ASSOC);
        if ($att) $att['record_type'] = 'work';
    }
    if (!$att) {
        return ['created' => false, 'request_id' => null, 'message' => 'Registro de ponto nao encontrado'];
    }
    if ((int)$att['teacher_id'] !== $teacherId) {
        return ['created' => false, 'request_id' => null, 'message' => 'Voce nao pode regularizar ponto de outro colaborador'];
    }
    if (!empty($att['check_out'])) {
        return ['created' => false, 'request_id' => null, 'message' => 'Este ponto ja tem saida registrada'];
    }
    if (empty($att['check_in'])) {
        return ['created' => false, 'request_id' => null, 'message' => 'Ponto sem entrada — nao ha o que regularizar'];
    }
    // Regularização de checkout é apenas para pares 'work' (jornada). Intervalo aberto
    // deve ser tratado via "Retornar do intervalo" ou ajuste manual pelo admin.
    if (($att['record_type'] ?? 'work') === 'break') {
        return ['created' => false, 'request_id' => null, 'message' => 'Este registro e um intervalo. Solicite ao administrador para fechar manualmente.'];
    }

    // Janela retroativa: ate $maxDaysBack dias.
    $checkInTs = strtotime((string)$att['check_in']);
    if ($checkInTs === false) {
        return ['created' => false, 'request_id' => null, 'message' => 'Horario de entrada invalido'];
    }
    if ((time() - $checkInTs) > $maxDaysBack * 86400) {
        return ['created' => false, 'request_id' => null, 'message' => 'Regularizacao expirada. Procure o administrador para ajuste manual.'];
    }

    // Valida formato ESTRITO antes de strtotime() — strtotime aceita strings ambiguas
    // tipo "next Friday" que poderiam burlar checagens. Endpoint ja valida, mas
    // helper defende em profundidade caso seja chamado de outro lugar.
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $proposedCheckOut)) {
        return ['created' => false, 'request_id' => null, 'message' => 'Formato de horario invalido (esperado AAAA-MM-DD HH:MM:SS)'];
    }
    $proposedTs = strtotime($proposedCheckOut);
    if ($proposedTs === false) {
        return ['created' => false, 'request_id' => null, 'message' => 'Horario de saida invalido'];
    }
    if ($proposedTs <= $checkInTs) {
        return ['created' => false, 'request_id' => null, 'message' => 'O horario de saida deve ser posterior a entrada'];
    }
    if ($proposedTs > time()) {
        return ['created' => false, 'request_id' => null, 'message' => 'O horario de saida nao pode estar no futuro'];
    }
    // Limite superior: 24h apos o check_in (cobre turno noturno + folga). Acima disso,
    // claramente nao foi esquecimento de saida — admin precisa intervir.
    if (($proposedTs - $checkInTs) > 24 * 3600) {
        return ['created' => false, 'request_id' => null, 'message' => 'O horario de saida nao pode ser mais de 24h apos a entrada'];
    }

    // Ja existe solicitacao para este attendance?
    $stCheck = $pdo->prepare("SELECT id, status FROM attendance_checkout_requests WHERE attendance_id = ?");
    $stCheck->execute([$attendanceId]);
    $existing = $stCheck->fetch(PDO::FETCH_ASSOC);
    if ($existing && $existing['status'] === 'pending') {
        return ['created' => false, 'request_id' => (int)$existing['id'], 'message' => 'Ja existe solicitacao pendente para este ponto'];
    }
    if ($existing && $existing['status'] === 'approved') {
        return ['created' => false, 'request_id' => (int)$existing['id'], 'message' => 'Este ponto ja foi regularizado'];
    }

    // Snapshot do check_in para auditoria, mesmo se admin posteriormente editar.
    $checkInStored = date('Y-m-d H:i:s', $checkInTs);
    $proposedStored = date('Y-m-d H:i:s', $proposedTs);

    // Se ha solicitacao rejeitada anterior, sobrescreve em vez de violar UNIQUE.
    if ($existing && $existing['status'] === 'rejected') {
        $stUpd = $pdo->prepare("UPDATE attendance_checkout_requests
            SET status='pending', proposed_check_out=?, justification=?,
                approved_by_admin_id=NULL, approved_at=NULL, admin_check_out=NULL,
                admin_observation=NULL, rejection_reason=NULL, ip=?, user_agent=?
            WHERE id=?");
        $stUpd->execute([
            $proposedStored, $justification,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            (int)$existing['id'],
        ]);
        $requestId = (int)$existing['id'];
    } else {
        $stIns = $pdo->prepare("INSERT INTO attendance_checkout_requests
            (attendance_id, teacher_id, school_id, date, check_in, proposed_check_out, justification, status, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
        $stIns->execute([
            $attendanceId, $teacherId, $att['school_id'],
            $att['date'], $checkInStored, $proposedStored, $justification,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
        $requestId = (int)$pdo->lastInsertId();
    }

    audit_log('create', 'attendance_checkout_request', $requestId, [
        'attendance_id' => $attendanceId,
        'teacher_id'    => $teacherId,
        'proposed_check_out' => $proposedStored,
        'source' => 'employee_request',
    ]);

    return [
        'created'    => true,
        'request_id' => $requestId,
        'message'    => 'Solicitacao de regularizacao enviada para analise',
    ];
}

/**
 * Aprova uma solicitacao de regularizacao: preenche check_out no attendance correspondente
 * e marca a solicitacao como approved. O admin pode opcionalmente sobrescrever o horario
 * proposto pelo colaborador via $adminCheckOut.
 */
function approve_checkout_regularization(PDO $pdo, int $requestId, int $adminId, ?string $adminCheckOut = null, ?string $observation = null): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT acr.*, a.check_in AS att_check_in, a.check_out AS att_check_out
                             FROM attendance_checkout_requests acr
                             JOIN attendance a ON a.id = acr.attendance_id
                             WHERE acr.id = ? FOR UPDATE");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitacao nao encontrada'];
        }
        if ($req['status'] !== 'pending') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitacao ja foi processada'];
        }
        if (!empty($req['att_check_out'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'O ponto ja tem saida registrada — solicitacao obsoleta'];
        }

        $effectiveCheckOut = $adminCheckOut !== null && $adminCheckOut !== ''
            ? $adminCheckOut : $req['proposed_check_out'];
        $effTs = strtotime($effectiveCheckOut);
        if ($effTs === false || $effTs <= strtotime((string)$req['att_check_in'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Horario de saida invalido (deve ser apos a entrada)'];
        }
        $effectiveCheckOutFmt = date('Y-m-d H:i:s', $effTs);

        // Preenche check_out no attendance. Marca approved=NULL para reentrar no
        // fluxo de aprovacao normal de pontos.
        $pdo->prepare("UPDATE attendance SET check_out = ?, approved = NULL WHERE id = ?")
            ->execute([$effectiveCheckOutFmt, (int)$req['attendance_id']]);

        // Livro fiscal — SAIDA preenchida por regularizacao aprovada pelo admin.
        // O horario foi decidido depois do fato, entao a marcacao carrega origem
        // 'regularization' e o motivo: quem ler o livro precisa saber que este
        // horario nao veio de um registro espontaneo do trabalhador.
        if (function_exists('nsr_ledger_record_mark')) {
            $identReg = nsr_ledger_teacher_ident($pdo, (int)$req['teacher_id']);
            $nsrReg = nsr_ledger_record_mark($pdo, [
                'teacher_id' => (int)$req['teacher_id'],
                'teacher_cpf' => $identReg['cpf'], 'teacher_pis' => $identReg['pis'],
                'school_id' => isset($req['school_id']) ? (int)$req['school_id'] : null,
                'attendance_id' => (int)$req['attendance_id'],
                'mark_role' => 'out', 'direction' => 'S', 'record_type' => 'work',
                'marked_at' => $effectiveCheckOutFmt,
                'work_date' => (string)($req['date'] ?? substr($effectiveCheckOutFmt, 0, 10)),
                'origin' => 'regularization', 'method' => 'manual',
                'admin_id' => $adminId,
                'reason' => 'regularizacao de saida aprovada' . ($observation ? ': ' . $observation : ''),
            ]);
            if ($nsrReg !== null) {
                $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
                    ->execute([$nsrReg, (int)$req['attendance_id']]);
            }
        }

        // Ressincroniza o banco de horas do dia (saida preenchida pos-checkout).
        recalculate_hour_bank_for_attendance($pdo, (int)$req['attendance_id']);

        $pdo->prepare("UPDATE attendance_checkout_requests
            SET status='approved', approved_by_admin_id=?, approved_at=NOW(),
                admin_check_out=?, admin_observation=?
            WHERE id=?")
            ->execute([$adminId, $effectiveCheckOutFmt, $observation, $requestId]);

        audit_log('update', 'attendance_checkout_request', $requestId, [
            'action' => 'approve',
            'attendance_id' => (int)$req['attendance_id'],
            'effective_check_out' => $effectiveCheckOutFmt,
            'admin_edited' => $adminCheckOut !== null && $adminCheckOut !== '',
        ]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Regularizacao aprovada e ponto fechado'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao aprovar: ' . $e->getMessage()];
    }
}

/**
 * Rejeita uma solicitacao de regularizacao com motivo obrigatorio. Nao altera o
 * attendance — o ponto continua orfao (cabe ao admin tratar via attendance_edit).
 */
function reject_checkout_regularization(PDO $pdo, int $requestId, int $adminId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return ['success' => false, 'message' => 'Motivo da rejeicao eh obrigatorio'];
    }
    if (mb_strlen($reason) > 255) {
        $reason = mb_substr($reason, 0, 255);
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT status FROM attendance_checkout_requests WHERE id = ? FOR UPDATE");
        $st->execute([$requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitacao nao encontrada'];
        }
        if ($row['status'] !== 'pending') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitacao ja foi processada'];
        }
        $pdo->prepare("UPDATE attendance_checkout_requests
            SET status='rejected', rejection_reason=?, approved_by_admin_id=?, approved_at=NOW()
            WHERE id=?")
            ->execute([$reason, $adminId, $requestId]);

        audit_log('update', 'attendance_checkout_request', $requestId, [
            'action' => 'reject',
            'reason' => $reason,
        ]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Solicitacao rejeitada'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao rejeitar: ' . $e->getMessage()];
    }
}

/**
 * =====================================================================
 * REGULARIZAÇÃO E EDIÇÃO DE INTERVALO (record_type='break')
 * =====================================================================
 *
 * Fluxo híbrido:
 * - Edição DIRETA: colaborador edita seu próprio break NO DIA ATUAL via edit_own_break()
 *   (sem aprovação, mas com auditoria em attendance_edits).
 * - SOLICITAÇÃO: para datas anteriores (até 60 dias), envia pedido via
 *   create_break_regularization_request() que admin aprova/rejeita.
 *
 * Modelo análogo a checkout regularization (acima), com diferença chave: aqui ambos
 * check_in e check_out podem ser alterados (par fechado), enquanto checkout só altera
 * check_out (par com entrada existente, faltando saída).
 */

/**
 * Edita um intervalo (record_type='break') diretamente pelo PRÓPRIO colaborador.
 * Permitido apenas se a data do registro for HOJE. Em datas anteriores, usar
 * create_break_regularization_request() (fluxo com aprovação).
 *
 * Auditoria: grava em attendance_edits com edited_by=NULL (sinaliza "colaborador")
 * e em audit_logs.
 */
function edit_own_break_direct(PDO $pdo, int $attendanceId, int $teacherId, ?string $newCheckIn, ?string $newCheckOut, string $justification): array {
    $justification = trim($justification);
    if ($justification === '') {
        return ['success' => false, 'message' => 'Justificativa eh obrigatoria'];
    }
    if (mb_strlen($justification) > 500) {
        $justification = mb_substr($justification, 0, 500);
    }

    $pdo->beginTransaction();
    try {
        $stAtt = $pdo->prepare("SELECT id, teacher_id, date, check_in, check_out, record_type, approved
                                FROM attendance WHERE id = ? FOR UPDATE");
        $stAtt->execute([$attendanceId]);
        $att = $stAtt->fetch(PDO::FETCH_ASSOC);
        if (!$att) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Registro de ponto nao encontrado'];
        }
        if ((int)$att['teacher_id'] !== $teacherId) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Voce nao pode editar registro de outro colaborador'];
        }
        if (($att['record_type'] ?? 'work') !== 'break') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este registro nao eh um intervalo'];
        }

        // Edição direta apenas no dia atual. Data anterior exige solicitação com aprovação.
        // Usa timezone do Brasil explicitamente — evita off-by-one quando servidor está em UTC
        // mas a coluna `date` é gravada em horário local (a vira-noite ficaria fora da janela).
        try {
            $today = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        } catch (Throwable $_) {
            $today = date('Y-m-d');
        }
        if ($att['date'] !== $today) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Edicao direta permitida apenas no dia atual. Para dias anteriores, use Solicitar correcao.'];
        }

        // Valida formato dos horários propostos (estrito).
        $datetimeRegex = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        if ($newCheckIn !== null && $newCheckIn !== '' && !preg_match($datetimeRegex, $newCheckIn)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Formato de check_in invalido (esperado AAAA-MM-DD HH:MM:SS)'];
        }
        if ($newCheckOut !== null && $newCheckOut !== '' && !preg_match($datetimeRegex, $newCheckOut)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Formato de check_out invalido (esperado AAAA-MM-DD HH:MM:SS)'];
        }

        $finalCheckIn  = $newCheckIn ?: $att['check_in'];
        $finalCheckOut = $newCheckOut ?: $att['check_out'];

        // Consistência: se ambos preenchidos, check_out > check_in.
        if (!empty($finalCheckIn) && !empty($finalCheckOut) && strtotime($finalCheckOut) <= strtotime($finalCheckIn)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Horario de retorno deve ser apos o inicio do intervalo'];
        }
        // Não pode estar no futuro.
        if (!empty($finalCheckIn) && strtotime($finalCheckIn) > time()) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Horario de inicio nao pode estar no futuro'];
        }
        if (!empty($finalCheckOut) && strtotime($finalCheckOut) > time()) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Horario de retorno nao pode estar no futuro'];
        }

        $beforeJson = json_encode([
            'check_in' => $att['check_in'],
            'check_out' => $att['check_out'],
            'record_type' => $att['record_type'],
        ], JSON_UNESCAPED_UNICODE);

        // Aplica UPDATE. editado_por=NULL sinaliza "edição feita pelo colaborador" (semântica
        // consistente — admin tem ID, colaborador é NULL). motivo_edicao recebe a justificativa.
        $pdo->prepare("UPDATE attendance
            SET check_in = ?, check_out = ?, editado_por = NULL,
                data_edicao = NOW(), motivo_edicao = ?
            WHERE id = ?")
            ->execute([$finalCheckIn, $finalCheckOut, '[colaborador] ' . $justification, $attendanceId]);

        $afterJson = json_encode([
            'check_in' => $finalCheckIn,
            'check_out' => $finalCheckOut,
            'record_type' => 'break',
        ], JSON_UNESCAPED_UNICODE);

        // Histórico em attendance_edits com edited_by=NULL (= colaborador).
        $pdo->prepare("INSERT INTO attendance_edits
            (attendance_id, edited_by, edited_at, reason, type, before_json, after_json)
            VALUES (?, NULL, NOW(), ?, 'break_self_edit', ?, ?)")
            ->execute([$attendanceId, $justification, $beforeJson, $afterJson]);

        // Ressincroniza o banco de horas do dia (intervalo editado pos-checkout).
        recalculate_hour_bank_for_attendance($pdo, $attendanceId);

        audit_log('update', 'attendance', $attendanceId, [
            'action' => 'self_edit_break',
            'teacher_id' => $teacherId,
            'reason' => $justification,
        ]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Intervalo corrigido com sucesso'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao corrigir: ' . $e->getMessage()];
    }
}

/**
 * Fecha um intervalo ABERTO (record_type='break', check_out IS NULL) informando a
 * hora real da volta. Self-service do colaborador (sem aprovação), auditado.
 *
 * Diferente de edit_own_break_direct(): atua SOMENTE em intervalo aberto e aceita
 * dia anterior dentro da janela (open_checkin_window_hours) — é o caso "esqueci de
 * registrar a volta". Justificativa opcional (padrão fixo) para reduzir fricção.
 *
 * @param string $returnInput 'HH:MM', 'HH:MM:SS' ou 'YYYY-MM-DD HH:MM[:SS]'
 *   (time-only é combinado com a DATA de início do intervalo no servidor).
 * @return array{success:bool,message:string}
 */
function close_own_open_break(PDO $pdo, int $attendanceId, int $teacherId, string $returnInput, string $note = ''): array {
    $note = trim($note);
    if ($note === '') $note = 'Volta de intervalo informada pelo colaborador';
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, teacher_id, date, check_in, check_out, record_type
                             FROM attendance WHERE id = ? FOR UPDATE");
        $st->execute([$attendanceId]);
        $att = $st->fetch(PDO::FETCH_ASSOC);
        if (!$att) { $pdo->rollBack(); return ['success'=>false,'message'=>'Registro de intervalo nao encontrado']; }
        if ((int)$att['teacher_id'] !== $teacherId) { $pdo->rollBack(); return ['success'=>false,'message'=>'Voce nao pode alterar registro de outro colaborador']; }
        if (($att['record_type'] ?? 'work') !== 'break') { $pdo->rollBack(); return ['success'=>false,'message'=>'Este registro nao eh um intervalo']; }
        if (!empty($att['check_out'])) { $pdo->rollBack(); return ['success'=>false,'message'=>'Este intervalo ja foi fechado']; }
        if (empty($att['check_in'])) { $pdo->rollBack(); return ['success'=>false,'message'=>'Intervalo sem inicio registrado']; }

        // Resolve a hora da volta: time-only combina com a DATA de inicio do intervalo.
        $breakDate = substr((string)$att['check_in'], 0, 10);
        $wasTimeOnly = false;
        if (preg_match('/^\d{2}:\d{2}$/', $returnInput))                            { $returnDateTime = $breakDate . ' ' . $returnInput . ':00'; $wasTimeOnly = true; }
        elseif (preg_match('/^\d{2}:\d{2}:\d{2}$/', $returnInput))                  { $returnDateTime = $breakDate . ' ' . $returnInput; $wasTimeOnly = true; }
        elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $returnInput))       $returnDateTime = $returnInput . ':00';
        elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $returnInput)) $returnDateTime = $returnInput;
        else { $pdo->rollBack(); return ['success'=>false,'message'=>'Formato de horario invalido']; }

        $startTs = strtotime((string)$att['check_in']);
        $retTs   = strtotime($returnDateTime);
        // Intervalo que cruza a meia-noite: a hora-só caiu antes do início → rola +1 dia.
        if ($wasTimeOnly && $retTs !== false && $retTs <= $startTs) {
            $retTs += 86400;
            $returnDateTime = date('Y-m-d H:i:s', $retTs);
        }
        if ($retTs === false || $retTs <= $startTs) { $pdo->rollBack(); return ['success'=>false,'message'=>'A volta deve ser depois do inicio do intervalo (' . date('H:i', $startTs) . ').']; }
        if ($retTs > time() + 60) { $pdo->rollBack(); return ['success'=>false,'message'=>'A volta nao pode estar no futuro.']; }

        // Janela sa: intervalo nao pode ter comecado ha mais que open_checkin_window_hours.
        $windowH = (int)(get_setting('open_checkin_window_hours', '30') ?? '30');
        if ($startTs < time() - $windowH * 3600) {
            $pdo->rollBack();
            return ['success'=>false,'message'=>'Intervalo muito antigo para correcao direta. Use "Solicitar correcao" (passa pelo admin).'];
        }

        $beforeJson = json_encode(['check_in'=>$att['check_in'],'check_out'=>null,'record_type'=>'break'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("UPDATE attendance SET check_out = ?, editado_por = NULL, data_edicao = NOW(), motivo_edicao = ? WHERE id = ?")
            ->execute([$returnDateTime, '[colaborador] ' . $note, $attendanceId]);
        $afterJson = json_encode(['check_in'=>$att['check_in'],'check_out'=>$returnDateTime,'record_type'=>'break'], JSON_UNESCAPED_UNICODE);

        $pdo->prepare("INSERT INTO attendance_edits (attendance_id, edited_by, edited_at, reason, type, before_json, after_json)
                       VALUES (?, NULL, NOW(), ?, 'break_return_self', ?, ?)")
            ->execute([$attendanceId, $note, $beforeJson, $afterJson]);

        // Livro fiscal — RETORNO DE INTERVALO informado pelo proprio colaborador.
        // Sem admin_id: a regularizacao foi feita pelo trabalhador, nao pela
        // gestao, e o livro deve refletir essa diferenca.
        if (function_exists('nsr_ledger_record_mark')) {
            $identBrk = nsr_ledger_teacher_ident($pdo, (int)$teacherId);
            $nsrBrk = nsr_ledger_record_mark($pdo, [
                'teacher_id' => (int)$teacherId,
                'teacher_cpf' => $identBrk['cpf'], 'teacher_pis' => $identBrk['pis'],
                'attendance_id' => (int)$attendanceId,
                'mark_role' => 'out', 'direction' => 'S', 'record_type' => 'break',
                'marked_at' => $returnDateTime,
                'work_date' => (string)($att['date'] ?? substr((string)$returnDateTime, 0, 10)),
                'origin' => 'regularization', 'method' => 'self',
                'reason' => 'retorno de intervalo informado pelo colaborador: ' . $note,
            ]);
            if ($nsrBrk !== null) {
                $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
                    ->execute([$nsrBrk, (int)$attendanceId]);
            }
        }

        recalculate_hour_bank_for_attendance($pdo, $attendanceId);
        audit_log('update','attendance',$attendanceId,['action'=>'self_close_break','teacher_id'=>$teacherId,'return'=>$returnDateTime]);

        $pdo->commit();
        return ['success'=>true,'message'=>'Volta do intervalo registrada com sucesso'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[close_own_open_break] ' . $e->getMessage());
        return ['success'=>false,'message'=>'Erro ao registrar a volta. Tente novamente.'];
    }
}

/**
 * Cria uma solicitação de correção de intervalo (para datas anteriores ao dia atual).
 * Admin precisa aprovar via approve_break_regularization().
 */
function create_break_regularization_request(PDO $pdo, int $attendanceId, int $teacherId, string $proposedCheckIn, string $proposedCheckOut, string $justification, int $maxDaysBack = 60): array {
    $justification = trim($justification);
    if ($justification === '') {
        return ['created' => false, 'request_id' => null, 'message' => 'Justificativa obrigatoria'];
    }
    if (mb_strlen($justification) > 500) {
        $justification = mb_substr($justification, 0, 500);
    }

    $stAtt = $pdo->prepare("SELECT id, teacher_id, school_id, date, check_in, check_out, record_type
                            FROM attendance WHERE id = ?");
    $stAtt->execute([$attendanceId]);
    $att = $stAtt->fetch(PDO::FETCH_ASSOC);
    if (!$att) {
        return ['created' => false, 'request_id' => null, 'message' => 'Registro de ponto nao encontrado'];
    }
    if ((int)$att['teacher_id'] !== $teacherId) {
        return ['created' => false, 'request_id' => null, 'message' => 'Voce nao pode solicitar correcao de outro colaborador'];
    }
    if (($att['record_type'] ?? 'work') !== 'break') {
        return ['created' => false, 'request_id' => null, 'message' => 'Este registro nao eh um intervalo'];
    }

    // Janela retroativa: ate $maxDaysBack dias contados a partir da data do registro.
    $dateTs = strtotime($att['date']);
    if ($dateTs === false) {
        return ['created' => false, 'request_id' => null, 'message' => 'Data do registro invalida'];
    }
    if ((time() - $dateTs) > $maxDaysBack * 86400) {
        return ['created' => false, 'request_id' => null, 'message' => 'Janela de regularizacao expirada (>'.$maxDaysBack.' dias). Procure o administrador.'];
    }

    // Valida formato dos horários propostos.
    $rx = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
    if (!preg_match($rx, $proposedCheckIn) || !preg_match($rx, $proposedCheckOut)) {
        return ['created' => false, 'request_id' => null, 'message' => 'Formato de horario invalido (esperado AAAA-MM-DD HH:MM:SS)'];
    }
    $tsIn  = strtotime($proposedCheckIn);
    $tsOut = strtotime($proposedCheckOut);
    if ($tsIn === false || $tsOut === false || $tsOut <= $tsIn) {
        return ['created' => false, 'request_id' => null, 'message' => 'Retorno deve ser posterior ao inicio'];
    }
    if ($tsIn > time() || $tsOut > time()) {
        return ['created' => false, 'request_id' => null, 'message' => 'Horario proposto nao pode estar no futuro'];
    }
    if (($tsOut - $tsIn) > 6 * 3600) {
        return ['created' => false, 'request_id' => null, 'message' => 'Duracao do intervalo nao pode ser maior que 6h'];
    }

    // Ja existe solicitacao para este attendance?
    $stCheck = $pdo->prepare("SELECT id, status FROM attendance_break_requests WHERE attendance_id = ?");
    $stCheck->execute([$attendanceId]);
    $existing = $stCheck->fetch(PDO::FETCH_ASSOC);
    if ($existing && $existing['status'] === 'pending') {
        return ['created' => false, 'request_id' => (int)$existing['id'], 'message' => 'Ja existe solicitacao pendente para este intervalo'];
    }
    if ($existing && $existing['status'] === 'approved') {
        return ['created' => false, 'request_id' => (int)$existing['id'], 'message' => 'Este intervalo ja foi regularizado'];
    }

    $proposedInFmt  = date('Y-m-d H:i:s', $tsIn);
    $proposedOutFmt = date('Y-m-d H:i:s', $tsOut);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    if ($existing && $existing['status'] === 'rejected') {
        $pdo->prepare("UPDATE attendance_break_requests
            SET status='pending', proposed_check_in=?, proposed_check_out=?, justification=?,
                approved_by_admin_id=NULL, approved_at=NULL, admin_check_in=NULL, admin_check_out=NULL,
                admin_observation=NULL, rejection_reason=NULL, ip=?, user_agent=?
            WHERE id=?")
            ->execute([$proposedInFmt, $proposedOutFmt, $justification, $ip, $ua, (int)$existing['id']]);
        $requestId = (int)$existing['id'];
    } else {
        $pdo->prepare("INSERT INTO attendance_break_requests
            (attendance_id, teacher_id, school_id, date, original_check_in, original_check_out,
             proposed_check_in, proposed_check_out, justification, status, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)")
            ->execute([
                $attendanceId, $teacherId, $att['school_id'], $att['date'],
                $att['check_in'], $att['check_out'],
                $proposedInFmt, $proposedOutFmt, $justification, $ip, $ua,
            ]);
        $requestId = (int)$pdo->lastInsertId();
    }

    audit_log('create', 'attendance_break_request', $requestId, [
        'attendance_id' => $attendanceId,
        'teacher_id'    => $teacherId,
        'proposed_check_in'  => $proposedInFmt,
        'proposed_check_out' => $proposedOutFmt,
        'source' => 'employee_request',
    ]);

    return ['created' => true, 'request_id' => $requestId, 'message' => 'Solicitacao de correcao enviada para analise do administrador'];
}

/**
 * Aprova uma solicitação de correção de intervalo. Aplica UPDATE em attendance e
 * grava trilha em attendance_edits + audit_logs.
 */
function approve_break_regularization(PDO $pdo, int $requestId, int $adminId, ?string $adminCheckIn = null, ?string $adminCheckOut = null, ?string $observation = null): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT abr.*, a.check_in AS att_check_in, a.check_out AS att_check_out, a.record_type AS att_record_type
                             FROM attendance_break_requests abr
                             JOIN attendance a ON a.id = abr.attendance_id
                             WHERE abr.id = ? FOR UPDATE");
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitacao nao encontrada'];
        }
        if ($req['status'] !== 'pending') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitacao ja foi processada'];
        }
        if (($req['att_record_type'] ?? 'work') !== 'break') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'O registro nao eh mais um intervalo (foi alterado)'];
        }

        $effIn  = $adminCheckIn  !== null && $adminCheckIn  !== '' ? $adminCheckIn  : $req['proposed_check_in'];
        $effOut = $adminCheckOut !== null && $adminCheckOut !== '' ? $adminCheckOut : $req['proposed_check_out'];
        if (strtotime($effOut) <= strtotime($effIn)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Retorno deve ser posterior ao inicio'];
        }

        $beforeJson = json_encode([
            'check_in' => $req['att_check_in'],
            'check_out' => $req['att_check_out'],
            'record_type' => 'break',
        ], JSON_UNESCAPED_UNICODE);

        $pdo->prepare("UPDATE attendance
            SET check_in = ?, check_out = ?, editado_por = ?, data_edicao = NOW(),
                motivo_edicao = ?
            WHERE id = ?")
            ->execute([
                $effIn, $effOut, $adminId,
                'Regularizacao de intervalo solicitada pelo colaborador',
                (int)$req['attendance_id'],
            ]);

        $afterJson = json_encode([
            'check_in' => $effIn,
            'check_out' => $effOut,
            'record_type' => 'break',
        ], JSON_UNESCAPED_UNICODE);

        $pdo->prepare("INSERT INTO attendance_edits
            (attendance_id, edited_by, edited_at, reason, type, before_json, after_json)
            VALUES (?, ?, NOW(), ?, 'break_regularization', ?, ?)")
            ->execute([(int)$req['attendance_id'], $adminId,
                       'Aprovou solicitacao de correcao de intervalo: ' . ($req['justification'] ?? ''),
                       $beforeJson, $afterJson]);

        $pdo->prepare("UPDATE attendance_break_requests
            SET status='approved', approved_by_admin_id=?, approved_at=NOW(),
                admin_check_in=?, admin_check_out=?, admin_observation=?
            WHERE id=?")
            ->execute([$adminId, $effIn, $effOut, $observation, $requestId]);

        // Ressincroniza o banco de horas do dia (intervalo ajustado pos-checkout).
        recalculate_hour_bank_for_attendance($pdo, (int)$req['attendance_id']);

        audit_log('update', 'attendance_break_request', $requestId, [
            'action' => 'approve',
            'attendance_id' => (int)$req['attendance_id'],
            'effective_check_in'  => $effIn,
            'effective_check_out' => $effOut,
            'admin_edited' => ($adminCheckIn !== null && $adminCheckIn !== '') || ($adminCheckOut !== null && $adminCheckOut !== ''),
        ]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Correcao aprovada e intervalo ajustado'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao aprovar: ' . $e->getMessage()];
    }
}

/**
 * Rejeita uma solicitação de correção de intervalo. Não altera o attendance.
 */
function reject_break_regularization(PDO $pdo, int $requestId, int $adminId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        return ['success' => false, 'message' => 'Motivo da rejeicao eh obrigatorio'];
    }
    if (mb_strlen($reason) > 255) {
        $reason = mb_substr($reason, 0, 255);
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT status FROM attendance_break_requests WHERE id = ? FOR UPDATE");
        $st->execute([$requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitacao nao encontrada'];
        }
        if ($row['status'] !== 'pending') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitacao ja foi processada'];
        }
        $pdo->prepare("UPDATE attendance_break_requests
            SET status='rejected', rejection_reason=?, approved_by_admin_id=?, approved_at=NOW()
            WHERE id=?")
            ->execute([$reason, $adminId, $requestId]);
        audit_log('update', 'attendance_break_request', $requestId, [
            'action' => 'reject',
            'reason' => $reason,
        ]);
        $pdo->commit();
        return ['success' => true, 'message' => 'Solicitacao rejeitada'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao rejeitar: ' . $e->getMessage()];
    }
}

function auto_reject_overtime_on_attendance_rejection(PDO $pdo, int $attendanceId, int $adminId): int {
    $stUpdate = $pdo->prepare("UPDATE overtime_requests SET status = 'rejected', rejection_reason = 'Ponto rejeitado pelo administrador', approved_by_admin_id = ?, approved_at = NOW() WHERE attendance_id = ? AND status = 'pending'");
    $stUpdate->execute([$adminId, $attendanceId]);
    
    $affected = $stUpdate->rowCount();
    
    if ($affected > 0) {
        audit_log('update', 'overtime_request', null, [
            'action' => 'auto_reject',
            'attendance_id' => $attendanceId,
            'count' => $affected,
            'reason' => 'Ponto rejeitado'
        ]);
    }
    
    return $affected;
}

/**
 * Aprova uma solicitação de hora extra.
 * Adiciona os minutos ao banco de horas com source='overtime_approved'.
 * 
 * @param PDO $pdo Conexão PDO
 * @param int $overtimeId ID da solicitação de hora extra
 * @param int $adminId ID do admin aprovando
 * @return array ['success' => bool, 'message' => string]
 */
/** @deprecated Hora extra descontinuada — função sem call sites ativos; mantida só para compatibilidade/auditoria. */
function approve_overtime_request(PDO $pdo, int $overtimeId, int $adminId): array {
    $pdo->beginTransaction();
    try {
        // Busca a solicitação
        $st = $pdo->prepare("SELECT ot.*, a.approved as attendance_approved FROM overtime_requests ot JOIN attendance a ON a.id = ot.attendance_id WHERE ot.id = ? FOR UPDATE");
        $st->execute([$overtimeId]);
        $ot = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$ot) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitação não encontrada'];
        }
        
        if ($ot['status'] !== 'pending') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitação já foi processada'];
        }
        
        // Valida: só pode aprovar hora extra se o ponto estiver aprovado
        if ($ot['attendance_approved'] != 1) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Não é possível aprovar hora extra de um ponto pendente ou rejeitado'];
        }
        
        // Aprova a solicitação
        $stUpdate = $pdo->prepare("UPDATE overtime_requests SET status = 'approved', approved_by_admin_id = ?, approved_at = NOW() WHERE id = ?");
        $stUpdate->execute([$adminId, $overtimeId]);
        
        // Adiciona ao banco de horas
        $stBank = $pdo->prepare("INSERT INTO hour_bank_entries (teacher_id, school_id, date, minutes, reason, source, ref_attendance_id, created_by_admin_id) VALUES (?, ?, ?, ?, ?, 'overtime_approved', ?, ?)");
        $stBank->execute([
            $ot['teacher_id'],
            $ot['school_id'],
            $ot['date'],
            $ot['minutes'],
            'Hora extra aprovada',
            $ot['attendance_id'],
            $adminId
        ]);
        
        audit_log('update', 'overtime_request', $overtimeId, [
            'action' => 'approve',
            'minutes' => $ot['minutes'],
            'teacher_id' => $ot['teacher_id']
        ]);
        
        $pdo->commit();
        return ['success' => true, 'message' => sprintf('Hora extra de %d minutos aprovada e adicionada ao banco de horas', $ot['minutes'])];
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao aprovar: ' . $e->getMessage()];
    }
}

/**
 * Rejeita uma solicitação de hora extra.
 * 
 * @param PDO $pdo Conexão PDO
 * @param int $overtimeId ID da solicitação de hora extra
 * @param int $adminId ID do admin rejeitando
 * @param string $reason Motivo da rejeição (obrigatório)
 * @return array ['success' => bool, 'message' => string]
 */
/** @deprecated Hora extra descontinuada — função sem call sites ativos; mantida só para compatibilidade/auditoria. */
function reject_overtime_request(PDO $pdo, int $overtimeId, int $adminId, string $reason): array {
    if (empty(trim($reason))) {
        return ['success' => false, 'message' => 'Motivo da rejeição é obrigatório'];
    }
    
    $pdo->beginTransaction();
    try {
        // Busca a solicitação
        $st = $pdo->prepare("SELECT * FROM overtime_requests WHERE id = ? FOR UPDATE");
        $st->execute([$overtimeId]);
        $ot = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$ot) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitação não encontrada'];
        }
        
        if ($ot['status'] !== 'pending') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Solicitação já foi processada'];
        }
        
        // Rejeita a solicitação
        $stUpdate = $pdo->prepare("UPDATE overtime_requests SET status = 'rejected', rejection_reason = ?, approved_by_admin_id = ?, approved_at = NOW() WHERE id = ?");
        $stUpdate->execute([trim($reason), $adminId, $overtimeId]);
        
        audit_log('update', 'overtime_request', $overtimeId, [
            'action' => 'reject',
            'reason' => $reason,
            'teacher_id' => $ot['teacher_id']
        ]);
        
        $pdo->commit();
        return ['success' => true, 'message' => 'Hora extra rejeitada'];
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Erro ao rejeitar: ' . $e->getMessage()];
    }
}

/**
 * Verifica se uma data é dia útil considerando calendário de exceções
 * @param PDO $pdo
 * @param string $date Data no formato Y-m-d
 * @param int|null $schoolId ID da escola (null = toda a rede)
 * @return bool
 */
function is_working_day(PDO $pdo, string $date, ?int $schoolId = null): bool {
    // Verifica dia da semana (0=domingo, 6=sábado)
    $dayOfWeek = (int)date('w', strtotime($date));
    
    // Verifica se há exceção cadastrada
    $sql = "SELECT is_working_day FROM calendar_exceptions 
            WHERE date = ? AND (school_id IS NULL OR school_id = ?) 
            ORDER BY school_id DESC LIMIT 1"; // Prioriza escola específica
    $st = $pdo->prepare($sql);
    $st->execute([$date, $schoolId]);
    $exception = $st->fetchColumn();
    
    if ($exception !== false) {
        // Há exceção: retorna o valor de is_working_day
        return (bool)$exception;
    }
    
    // Sem exceção: seg-sex são úteis (1-5), sab-dom não são (0,6)
    return ($dayOfWeek >= 1 && $dayOfWeek <= 5);
}

/**
 * Retorna o dia da semana "efetivo" para fins de jornada de trabalho.
 *
 * Se a data tem uma exceção do tipo 'workday' (sábado letivo) com reflects_weekday
 * preenchido, devolve esse valor (0=domingo .. 6=sábado). Caso contrário, devolve
 * o weekday real da data.
 */
function get_effective_weekday(PDO $pdo, string $date, ?int $schoolId = null): int {
    $realWeekday = (int)date('w', strtotime($date));

    $sql = "SELECT reflects_weekday FROM calendar_exceptions
            WHERE date = ?
              AND type = 'workday'
              AND is_working_day = 1
              AND reflects_weekday IS NOT NULL
              AND (school_id IS NULL OR school_id = ?)
            ORDER BY school_id DESC
            LIMIT 1";
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$date, $schoolId]);
        $val = $st->fetchColumn();
    } catch (Throwable $e) {
        // Coluna pode não existir ainda (migração pendente) — fallback seguro.
        return $realWeekday;
    }

    if ($val === false || $val === null || $val === '') {
        return $realWeekday;
    }
    $w = (int)$val;
    if ($w < 0 || $w > 6) {
        return $realWeekday;
    }
    return $w;
}

/**
 * Procura uma attendance "logicamente equivalente" — mesmo professor, mesma data,
 * mesmo tipo de ponto (entrada/saida), com client_recorded_at dentro de uma janela
 * de N minutos. Usado pelo bulk sync para descartar tentativas duplicadas que
 * vieram com client_ids diferentes (ex.: usuário clicou várias vezes offline).
 *
 * @param 'entrada'|'saida' $action
 * @param string $clientRecordedAt Y-m-d H:i:s no fuso BR
 */
function find_logical_duplicate(
    PDO $pdo,
    int $teacherId,
    string $date,
    string $action,
    string $clientRecordedAt,
    int $windowMinutes = 5
): ?array {
    // Comparação por check_in/check_out (campo autoritativo do servidor que
    // recebe o $authoritativeTime). Consistente entre entrada e saída.
    if ($action === 'entrada') {
        $sql = "SELECT id, nsr, client_id
                FROM attendance
                WHERE teacher_id = ?
                  AND date = ?
                  AND check_in IS NOT NULL
                  AND ABS(TIMESTAMPDIFF(MINUTE, check_in, ?)) <= ?
                ORDER BY id ASC
                LIMIT 1";
    } else {
        $sql = "SELECT id, nsr, checkout_client_id
                FROM attendance
                WHERE teacher_id = ?
                  AND date = ?
                  AND check_out IS NOT NULL
                  AND ABS(TIMESTAMPDIFF(MINUTE, check_out, ?)) <= ?
                ORDER BY id ASC
                LIMIT 1";
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$teacherId, $date, $clientRecordedAt, $windowMinutes]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Detecta GRUPOS de attendance que parecem ser duplicatas entre si.
 * Critério: mesmo teacher_id + mesma data + mesma "ação" (check_in OU check_out)
 * + |delta| ≤ $windowMinutes + approved IS NULL + record_mode='offline'
 * + ainda não marcado como superseded.
 *
 * Retorna array de grupos: cada item contém '_action' indicando se é grupo de
 * entrada ou saída, ordenado por client_recorded_at ASC (1º = recomendado manter).
 */
function find_duplicate_groups(
    PDO $pdo,
    ?string $fromDate = null,
    ?string $toDate = null,
    ?int $schoolId = null,
    ?int $teacherId = null,
    int $windowMinutes = 5
): array {
    $where = ["a.approved IS NULL",
              "a.record_mode = 'offline'",
              "a.superseded_by_id IS NULL"];
    $params = [];
    if ($fromDate) { $where[] = "a.date >= ?"; $params[] = $fromDate; }
    if ($toDate)   { $where[] = "a.date <= ?"; $params[] = $toDate; }
    if ($teacherId){ $where[] = "a.teacher_id = ?"; $params[] = $teacherId; }
    if ($schoolId !== null) {
        $where[] = "a.school_id = ?";
        $params[] = $schoolId;
    }

    // Escopo do admin atual: admin de escola só vê suas escolas.
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $where[] = $scopeSql;
    $params = array_merge($params, $scopeParams);

    $sql = "SELECT a.id, a.teacher_id, a.date, a.check_in, a.check_out,
                   a.client_recorded_at, a.recorded_at, a.client_id, a.checkout_client_id,
                   a.device_identifier, a.record_mode, t.name as teacher_name
            FROM attendance a
            JOIN teachers t ON t.id = a.teacher_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.teacher_id, a.date, a.client_recorded_at ASC";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $groups = [];
    foreach ($rows as $r) {
        foreach (['check_in', 'check_out'] as $action) {
            if (empty($r[$action])) continue;
            $key = $r['teacher_id'] . '|' . $r['date'] . '|' . $action;
            if (!isset($groups[$key])) $groups[$key] = [];
            $groups[$key][] = $r + ['_action' => $action];
        }
    }

    $result = [];
    foreach ($groups as $key => $items) {
        if (count($items) < 2) continue;
        usort($items, function ($a, $b) {
            $ta = strtotime((string)($a[$a['_action']] ?: $a['client_recorded_at']));
            $tb = strtotime((string)($b[$b['_action']] ?: $b['client_recorded_at']));
            return $ta <=> $tb;
        });
        $first = strtotime((string)$items[0][$items[0]['_action']]);
        $within = true;
        foreach ($items as $it) {
            $t = strtotime((string)$it[$it['_action']]);
            if (abs($t - $first) > $windowMinutes * 60) { $within = false; break; }
        }
        if ($within) $result[$key] = $items;
    }
    return $result;
}

/**
 * Aplica uma decisão de dedupe a um grupo: mantém o keeper e marca os demais
 * com approved=0 + superseded_by_id=<keeperId>, em uma transação. Cada
 * soft-delete gera entrada em attendance_edits e audit_logs.
 *
 * Segurança: TODOS os IDs (keeper + superseded) são validados contra o escopo
 * do admin via `admin_scope_where()`. IDs fora do escopo lançam exceção e
 * abortam toda a operação. Previne IDOR via POST direto.
 */
function apply_dedupe_decision(
    PDO $pdo,
    int $keeperId,
    array $supersededIds,
    int $adminId,
    string $reason
): array {
    if (!$supersededIds) {
        return ['kept' => $keeperId, 'superseded' => []];
    }

    // Valida escopo: todos os IDs precisam estar acessíveis ao admin atual.
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $idsToCheck = array_unique(array_merge([$keeperId], array_map('intval', $supersededIds)));
    $idsToCheck = array_values(array_filter($idsToCheck, static fn($i) => $i > 0));
    $placeholders = implode(',', array_fill(0, count($idsToCheck), '?'));
    $st = $pdo->prepare(
        "SELECT a.id FROM attendance a
         JOIN teachers t ON t.id = a.teacher_id
         WHERE a.id IN ($placeholders) AND $scopeSql"
    );
    $st->execute(array_merge($idsToCheck, $scopeParams));
    $allowedIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $missing = array_diff($idsToCheck, $allowedIds);
    if (!empty($missing)) {
        throw new RuntimeException('Sem permissão para um ou mais registros: #' . implode(', #', $missing));
    }

    $pdo->beginTransaction();
    try {
        $stUpd = $pdo->prepare(
            "UPDATE attendance
                SET approved = 0,
                    superseded_by_id = ?,
                    manual_reason_text = COALESCE(manual_reason_text, ?),
                    manual_by_admin_id = COALESCE(manual_by_admin_id, ?),
                    manual_created_at  = COALESCE(manual_created_at, NOW())
              WHERE id = ?
                AND superseded_by_id IS NULL"
        );
        $stEdit = $pdo->prepare(
            "INSERT INTO attendance_edits
                (attendance_id, edited_by, edited_at, reason, type, before_json, after_json)
             VALUES (?, ?, NOW(), ?, 'admin_soft_delete_duplicate', ?, ?)"
        );
        $stBefore = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");

        $supersededOk = [];
        foreach ($supersededIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0 || $sid === $keeperId) continue;

            $stBefore->execute([$sid]);
            $before = $stBefore->fetch(PDO::FETCH_ASSOC) ?: [];

            $stUpd->execute([$keeperId, "Duplicata de #$keeperId — $reason", $adminId, $sid]);
            // Só registra auditoria se o UPDATE de fato modificou a linha
            // (evita marcar como dedupe um registro já superseded por outro keeper).
            if ($stUpd->rowCount() === 0) continue;

            $after = $before;
            $after['approved'] = 0;
            $after['superseded_by_id'] = $keeperId;
            $stEdit->execute([
                $sid, $adminId, $reason,
                json_encode($before, JSON_UNESCAPED_UNICODE),
                json_encode($after, JSON_UNESCAPED_UNICODE)
            ]);

            audit_log('admin_soft_delete_duplicate', 'attendance', $sid, [
                'keeper_id' => $keeperId,
                'reason'    => $reason,
                'admin_id'  => $adminId,
            ]);
            $supersededOk[] = $sid;
        }
        if (!empty($supersededOk)) {
            audit_log('admin_dedupe_keep', 'attendance', $keeperId, [
                'superseded_ids' => $supersededOk,
                'reason'         => $reason,
                'admin_id'       => $adminId,
            ]);
        }
        $pdo->commit();
        return ['kept' => $keeperId, 'superseded' => $supersededOk];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ============================================================================
// Remoção/anulação AUDITÁVEL de registros de ponto (Portaria 671: NSR imutável —
// nunca DELETE). Marca como removido reusando a exclusão de `superseded_by_id`
// que folha/relatórios/recibos já respeitam. Reversível ("Restaurar").
// ============================================================================

/**
 * Ressincroniza a entrada automática do banco de horas (source='auto') de um dia.
 * É a função CANÔNICA do ledger — usada pelo checkout (checkin/bulk/kiosk) e por
 * toda mutação pós-checkout (aprovação, edição, remoção, regularização).
 *
 * IMPORTANTE (jornada flexível): a instituição não trabalha com "saldo negativo".
 * Esta função NÃO grava mais lançamentos de DÉBITO automáticos — apenas LIMPA a
 * entrada 'auto' antiga do dia, mantendo o ledger sem acumular déficit. O quanto
 * ainda falta para a carga prevista é calculado on-the-fly (mensal) por
 * get_monthly_hours_summary() e exibido como "horas a compensar" (informativo).
 * Lançamentos históricos e os de origem 'manual'/'overtime_approved' permanecem
 * intactos para auditoria.
 */
function recompute_day_hour_bank(PDO $pdo, int $teacherId, string $date): void {
    try {
        $pdo->prepare("DELETE FROM hour_bank_entries WHERE teacher_id=? AND date=? AND source='auto'")->execute([$teacherId, $date]);

        // Dia com work fechado PENDENTE → neutro até a decisão do admin.
        try {
            $stP = $pdo->prepare("SELECT 1 FROM attendance
                                  WHERE teacher_id=? AND date=?
                                    AND check_in IS NOT NULL AND check_out IS NOT NULL
                                    AND approved IS NULL
                                    AND " . attendance_vigente_sql() . "
                                    AND (record_type = 'work' OR record_type IS NULL)
                                  LIMIT 1");
            $stP->execute([$teacherId, $date]);
            if ($stP->fetchColumn()) {
                return;
            }
        } catch (Throwable $_) { /* coluna record_type ausente — segue fluxo normal */ }

        // Jornada flexível: não gravamos mais débito automático (saldo negativo).
        // O DELETE acima já zera qualquer lançamento 'auto' antigo do dia; a falta
        // de horas é apenas informativa e calculada no mês (get_monthly_hours_summary).
    } catch (Throwable $e) {
        error_log('[recompute_day_hour_bank] ' . $e->getMessage());
    }
}

/**
 * Anula (soft-delete) um registro de ponto/intervalo pelo admin. Auditável e
 * reversível. NÃO apaga — marca approved=0 + superseded_by_id=self + removed_*.
 * Se for 'work', anula junto os intervalos filhos do mesmo turno. Recalcula o
 * banco de horas do dia. Valida escopo do admin (anti-IDOR).
 *
 * @return array{removed:int[],teacher_id:int,date:string}
 * @throws RuntimeException fora do escopo / inexistente / motivo vazio
 */
function admin_remove_attendance(PDO $pdo, int $attendanceId, int $adminId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('Informe o motivo da remoção.');
    if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 255);

    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $st = $pdo->prepare("SELECT a.* FROM attendance a JOIN teachers t ON t.id = a.teacher_id WHERE a.id = ? AND $scopeSql LIMIT 1");
    $st->execute(array_merge([$attendanceId], $scopeParams));
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) throw new RuntimeException('Registro inexistente ou fora do seu escopo.');
    if (!empty($rec['removed_at'])) {
        return ['removed' => [], 'teacher_id' => (int)$rec['teacher_id'], 'date' => (string)$rec['date'], 'already' => true];
    }
    $teacherId  = (int)$rec['teacher_id'];
    $date       = (string)$rec['date'];
    $recordType = (isset($rec['record_type']) && $rec['record_type'] !== null) ? (string)$rec['record_type'] : 'work';

    $pdo->beginTransaction();
    try {
        $removed = [];
        $mark = function (int $id, array $before) use ($pdo, $adminId, $reason, &$removed) {
            $upd = $pdo->prepare(
                "UPDATE attendance
                    SET approved = 0, superseded_by_id = id, removed_at = NOW(),
                        removed_by_admin_id = ?, removed_reason = ?,
                        manual_reason_text = COALESCE(manual_reason_text, ?),
                        manual_by_admin_id = COALESCE(manual_by_admin_id, ?),
                        manual_created_at  = COALESCE(manual_created_at, NOW()),
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND removed_at IS NULL AND superseded_by_id IS NULL"
            );
            $upd->execute([$adminId, $reason, 'Removido pelo admin: ' . $reason, $adminId, $id]);
            if ($upd->rowCount() === 0) return; // já removido OU já superseded por dedupe — não toca
            $after = $before; $after['approved'] = 0; $after['superseded_by_id'] = $id; $after['removed_at'] = date('Y-m-d H:i:s');
            try {
                $pdo->prepare("INSERT INTO attendance_edits (attendance_id, edited_by, edited_at, reason, type, before_json, after_json) VALUES (?, ?, NOW(), ?, 'admin_remove', ?, ?)")
                    ->execute([$id, $adminId, $reason, json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable $e) { /* best-effort */ }
            log_attendance_audit($pdo, $id, $adminId, 'admin_remove', 'removed_at', null, date('Y-m-d H:i:s'), $reason);
            audit_log('admin_remove', 'attendance', $id, ['reason' => $reason, 'admin_id' => $adminId, 'nsr' => $before['nsr'] ?? null, 'record_type' => $before['record_type'] ?? null]);

            // Livro fiscal: a anulação vira evento `void` com NSR próprio,
            // apontando para as marcações originais — que permanecem intactas.
            // Um void por marcação (entrada e saída são registros distintos).
            if (function_exists('nsr_ledger_record_mark')) {
                foreach (['nsr', 'nsr_out'] as $col) {
                    $targetNsr = isset($before[$col]) ? (int)$before[$col] : 0;
                    if ($targetNsr <= 0) continue;
                    nsr_ledger_record_mark($pdo, [
                        'event_type'    => 'void',
                        'target_nsr'    => $targetNsr,
                        'teacher_id'    => (int)($before['teacher_id'] ?? 0) ?: null,
                        'attendance_id' => $id,
                        'admin_id'      => $adminId,
                        'reason'        => $reason,
                        'origin'        => 'admin_edit',
                        'marked_at'     => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $removed[] = $id;
        };

        $mark($attendanceId, $rec);
        if ($recordType === 'work') {
            $kids = $pdo->prepare("SELECT * FROM attendance WHERE parent_attendance_id = ? AND removed_at IS NULL");
            $kids->execute([$attendanceId]);
            foreach ($kids->fetchAll(PDO::FETCH_ASSOC) as $kid) { $mark((int)$kid['id'], $kid); }
        }
        recompute_day_hour_bank($pdo, $teacherId, $date);
        $pdo->commit();
        return ['removed' => $removed, 'teacher_id' => $teacherId, 'date' => $date];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Restaura um registro anulado pelo admin (e os filhos anulados junto). Só age
 * sobre registros com removed_at != NULL (não toca duplicatas de dedupe). O
 * approved volta a NULL (pendente) — o admin reavalia, mais seguro que assumir 1.
 *
 * @return array{restored:int[],teacher_id?:int,date?:string}
 */
function admin_restore_attendance(PDO $pdo, int $attendanceId, int $adminId, string $reason = ''): array {
    $reason = trim($reason);
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $st = $pdo->prepare("SELECT a.* FROM attendance a JOIN teachers t ON t.id = a.teacher_id WHERE a.id = ? AND $scopeSql LIMIT 1");
    $st->execute(array_merge([$attendanceId], $scopeParams));
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) throw new RuntimeException('Registro inexistente ou fora do seu escopo.');
    if (empty($rec['removed_at'])) return ['restored' => [], 'already' => true];

    // Guarda contra duplicar o período (Fase 3 da auditoria).
    //
    // Há dois motivos distintos para um registro estar anulado:
    //   superseded_by_id = id      → o admin o REMOVEU. Restaurar é legítimo.
    //   superseded_by_id = <outro> → ele foi SUBSTITUÍDO por uma edição.
    //
    // No segundo caso, o sucessor está vivo e cobre o mesmo intervalo.
    // Restaurar o antigo faria o dia contar o período DUAS VEZES. Esta função
    // limpava `superseded_by_id` incondicionalmente, então antes do supersede
    // isso não aparecia — só passou a ser possível quando a edição deixou de
    // sobrescrever a linha original.
    $supersededBy = isset($rec['superseded_by_id']) ? (int)$rec['superseded_by_id'] : 0;
    if ($supersededBy > 0 && $supersededBy !== (int)$rec['id']) {
        $stSuc = $pdo->prepare("SELECT id, check_in, check_out FROM attendance
                                 WHERE id = ? AND removed_at IS NULL LIMIT 1");
        $stSuc->execute([$supersededBy]);
        if ($suc = $stSuc->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException(
                'Este registro foi substituído pelo #' . (int)$suc['id'] . ', que está ativo. '
                . 'Restaurá-lo duplicaria o período. Anule o registro #' . (int)$suc['id']
                . ' antes, se a intenção é voltar ao horário anterior.'
            );
        }
    }

    $teacherId = (int)$rec['teacher_id'];
    $date = (string)$rec['date'];

    $pdo->beginTransaction();
    try {
        $restored = [];
        $unmark = function (int $id) use ($pdo, $adminId, $reason, &$restored) {
            $upd = $pdo->prepare(
                "UPDATE attendance
                    SET superseded_by_id = NULL, removed_at = NULL, removed_by_admin_id = NULL,
                        removed_reason = NULL, approved = NULL, updated_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND removed_at IS NOT NULL"
            );
            $upd->execute([$id]);
            if ($upd->rowCount() === 0) return;
            log_attendance_audit($pdo, $id, $adminId, 'admin_restore', 'removed_at', 'removed', null, $reason);
            audit_log('admin_restore', 'attendance', $id, ['reason' => $reason, 'admin_id' => $adminId]);
            $restored[] = $id;
        };
        $unmark($attendanceId);
        foreach ($pdo->query("SELECT id FROM attendance WHERE parent_attendance_id = " . (int)$attendanceId . " AND removed_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $kidId) {
            $unmark((int)$kidId);
        }
        recompute_day_hour_bank($pdo, $teacherId, $date);
        $pdo->commit();
        return ['restored' => $restored, 'teacher_id' => $teacherId, 'date' => $date];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Minutos trabalhados entre dois datetimes (out - in). Retorna 0 se incompleto
 * ou se a saída não for posterior à entrada. Promovido a helper global (era
 * local em attendance_edit.php) para reuso no editor de dia e nos testes.
 */
if (!function_exists('worked_minutes')) {
    function worked_minutes(?string $in, ?string $out): int {
        if (empty($in) || empty($out)) return 0;
        $ci = strtotime($in);
        $co = strtotime($out);
        if (!$ci || !$co || $co <= $ci) return 0;
        return (int) floor(($co - $ci) / 60);
    }
}

/**
 * Validação PURA (sem efeitos colaterais / sem DB) da consistência de um dia de
 * ponto no modelo de SEGMENTOS: o dia é uma lista de PERÍODOS DE TRABALHO (works)
 * + uma lista de INTERVALOS (breaks). Recebe o ESTADO FINAL pretendido (só itens
 * não removidos), com datetimes já reconstruídos, e devolve mensagens de erro
 * (pt-BR); array vazio = válido. Testável isoladamente.
 *
 * @param array $p [
 *   'reason' => string,                                    // justificativa (obrigatória)
 *   'date'   => 'Y-m-d',                                   // dia do registro
 *   'works'  => [ ['start'=>dt|null,'end'=>dt|null], ... ],// períodos de trabalho
 *   'breaks' => [ ['start'=>dt|null,'end'=>dt|null], ... ],// intervalos
 * ]
 * @return string[]
 */
function validate_attendance_day(array $p): array {
    $errors = [];

    if (trim((string)($p['reason'] ?? '')) === '') {
        $errors[] = 'A justificativa da edição é obrigatória.';
    }

    $date = (string)($p['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'Data do registro inválida.';
    }

    // Normaliza uma lista [{start,end}] em [{s,e,n}], validando cada par.
    $parse = function (array $items, string $rotulo) use (&$errors): array {
        $out = [];
        foreach ($items as $i => $it) {
            $n = $i + 1;
            $s = $it['start'] ?? null;
            $e = $it['end'] ?? null;
            if (!$s || !$e) { $errors[] = "{$rotulo} {$n}: informe início e fim."; continue; }
            $ts = strtotime((string)$s);
            $te = strtotime((string)$e);
            if ($ts === false || $te === false) { $errors[] = "{$rotulo} {$n}: horário inválido."; continue; }
            if ($te <= $ts) { $errors[] = "{$rotulo} {$n}: o fim deve ser posterior ao início."; continue; }
            $out[] = ['s' => $ts, 'e' => $te, 'n' => $n];
        }
        usort($out, fn($a, $b) => $a['s'] <=> $b['s']);
        return $out;
    };

    $works  = $parse(is_array($p['works'] ?? null) ? $p['works'] : [], 'Período de trabalho');
    $breaks = $parse(is_array($p['breaks'] ?? null) ? $p['breaks'] : [], 'Intervalo');

    // Períodos de trabalho não podem se sobrepor.
    for ($i = 1; $i < count($works); $i++) {
        if ($works[$i]['s'] < $works[$i - 1]['e']) {
            $errors[] = "Os períodos de trabalho {$works[$i - 1]['n']} e {$works[$i]['n']} se sobrepõem.";
        }
    }

    // Intervalos não podem se sobrepor.
    for ($i = 1; $i < count($breaks); $i++) {
        if ($breaks[$i]['s'] < $breaks[$i - 1]['e']) {
            $errors[] = "Os intervalos {$breaks[$i - 1]['n']} e {$breaks[$i]['n']} se sobrepõem.";
        }
    }

    // Cada intervalo deve estar dentro do período trabalhado do dia
    // (entre o início do 1º período e o fim do último).
    if ($works) {
        $dayStart = $works[0]['s'];
        $dayEnd = 0;
        foreach ($works as $w) { if ($w['e'] > $dayEnd) $dayEnd = $w['e']; }
        foreach ($breaks as $b) {
            if ($b['s'] < $dayStart) $errors[] = "Intervalo {$b['n']} está fora do período trabalhado (antes da entrada).";
            if ($b['e'] > $dayEnd)   $errors[] = "Intervalo {$b['n']} está fora do período trabalhado (depois da saída).";
        }
    } elseif ($breaks) {
        $errors[] = 'Há intervalos no dia, mas nenhum período de trabalho. Adicione um período ou remova os intervalos.';
    }

    return $errors;
}

/**
 * Salva uma correção do DIA INTEIRO de ponto no modelo de SEGMENTOS, numa única
 * transação, com auditoria completa (attendance_edits + attendance_audit_log +
 * audit_logs) e recálculo do banco de horas.
 *
 * O dia é uma lista de PERÍODOS DE TRABALHO (record_type='work') + uma lista de
 * INTERVALOS (record_type='break'). Cada um pode ser adicionado, editado ou
 * removido (soft-delete auditável). Ao salvar, o dia inteiro é aprovado
 * (approved=1). Turno noturno: períodos/intervalos cujo horário é menor que a
 * entrada do dia são ancorados no dia seguinte. Valida escopo (anti-IDOR),
 * consistência (validate_attendance_day) e a posse de cada registro ao dia.
 * Nunca sobrescreve sem histórico.
 *
 * @param array $payload [
 *   'teacher_id'=>int, 'orig_date'=>'Y-m-d', 'date'=>'Y-m-d', 'method'=>string,
 *   'reason'=>string,
 *   'works'  => [ ['id'=>int,'start'=>'HH:MM'|'','end'=>'HH:MM'|'','remove'=>bool], ... ],
 *   'breaks' => [ ['id'=>int,'start'=>'HH:MM'|'','end'=>'HH:MM'|'','remove'=>bool], ... ],
 * ]
 * @return array{ok:bool,work_id:int,works:array,breaks:array,date_changed:bool,method_changed:bool,diff_minutes:int}
 * @throws RuntimeException
 */
function admin_save_attendance_day(PDO $pdo, int $adminId, array $payload): array {
    $teacherId = (int)($payload['teacher_id'] ?? 0);
    $origDate  = trim((string)($payload['orig_date'] ?? ''));
    $newDate   = trim((string)($payload['date'] ?? ''));
    $method    = trim((string)($payload['method'] ?? ''));
    $reason    = trim((string)($payload['reason'] ?? ''));
    $worksIn   = is_array($payload['works'] ?? null) ? $payload['works'] : [];
    $breaksIn  = is_array($payload['breaks'] ?? null) ? $payload['breaks'] : [];

    if ($teacherId <= 0) throw new RuntimeException('Colaborador inválido.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $origDate)) throw new RuntimeException('Data original inválida.');
    if ($newDate === '') $newDate = $origDate;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) throw new RuntimeException('Data inválida.');
    if ($reason === '') throw new RuntimeException('A justificativa da edição é obrigatória.');
    if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 255);

    // Escopo (anti-IDOR): admin só edita colaboradores do seu escopo.
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $stT = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id = ? AND $scopeSql LIMIT 1");
    $stT->execute(array_merge([$teacherId], $scopeParams));
    if (!$stT->fetchColumn()) throw new RuntimeException('Colaborador fora do seu escopo de acesso.');

    // Normaliza ops (períodos e intervalos): {id, s(HH:MM), e(HH:MM), remove}.
    $normOps = function (array $items): array {
        $ops = [];
        foreach ($items as $it) {
            $id = (int)($it['id'] ?? 0);
            $s  = trim((string)($it['start'] ?? ''));
            $e  = trim((string)($it['end'] ?? ''));
            $rm = !empty($it['remove']);
            if ($id <= 0 && $s === '' && $e === '') continue; // linha vazia nova
            foreach ([$s, $e] as $hm) {
                if ($hm !== '' && !preg_match('/^\d{2}:\d{2}$/', $hm)) {
                    throw new RuntimeException('Horário inválido: ' . $hm);
                }
            }
            $ops[] = ['id' => $id, 's' => $s, 'e' => $e, 'remove' => $rm];
        }
        return $ops;
    };
    $workOps  = $normOps($worksIn);
    $breakOps = $normOps($breaksIn);

    // Ancoragem: dia base + detecção de turno noturno a partir dos PERÍODOS finais.
    // overnight = algum período não removido tem fim < início (HH:MM, cruza meia-noite).
    // refStart = menor início (HH:MM) entre os períodos não removidos. Em turno
    // noturno, horários menores que refStart pertencem ao dia seguinte.
    $overnight = false; $refStart = null;
    foreach ($workOps as $op) {
        if ($op['remove'] || $op['s'] === '' || $op['e'] === '') continue;
        if ($op['e'] < $op['s']) $overnight = true;
        if ($refStart === null || $op['s'] < $refStart) $refStart = $op['s'];
    }
    $nextDay = (new DateTime($newDate))->modify('+1 day')->format('Y-m-d');
    $mk = function (string $hm) use ($newDate, $nextDay, $overnight, $refStart): ?string {
        if ($hm === '') return null;
        $anchor = ($overnight && $refStart !== null && $hm < $refStart) ? $nextDay : $newDate;
        return $anchor . ' ' . $hm . ':00';
    };
    // Anexa datetimes reconstruídos a cada op (startDt/endDt).
    $attach = function (array &$ops) use ($mk) {
        foreach ($ops as &$op) { $op['startDt'] = $mk($op['s']); $op['endDt'] = $mk($op['e']); }
        unset($op);
    };
    $attach($workOps);
    $attach($breakOps);

    // Validação de consistência sobre o estado final (itens não removidos).
    $finalWorks = []; $finalBreaks = [];
    foreach ($workOps as $op)  { if (!$op['remove']) $finalWorks[]  = ['start' => $op['startDt'], 'end' => $op['endDt']]; }
    foreach ($breakOps as $op) { if (!$op['remove']) $finalBreaks[] = ['start' => $op['startDt'], 'end' => $op['endDt']]; }
    $vErrors = validate_attendance_day([
        'reason' => $reason, 'date' => $newDate, 'works' => $finalWorks, 'breaks' => $finalBreaks,
    ]);
    if ($vErrors) throw new RuntimeException(implode(' ', $vErrors));

    $now = (new DateTime())->format('Y-m-d H:i:s');
    // Emissor legado unificado (lib/nsr_ledger.php). O livro fiscal tem contador
    // próprio; este numera apenas `attendance.nsr` e o espelho o sobrescreve com
    // o NSR fiscal quando o livro está ligado.
    $nextNsr = function () use ($pdo): int {
        if (function_exists('nsr_legacy_reserve')) return nsr_legacy_reserve($pdo);
        $stCur = $pdo->query("SELECT current_nsr FROM nsr_sequence WHERE id = 1 FOR UPDATE");
        $cur = (int)$stCur->fetchColumn();
        $stCur->closeCursor();
        $n = $cur + 1;
        $pdo->prepare("UPDATE nsr_sequence SET current_nsr = ? WHERE id = 1")->execute([$n]);
        return $n;
    };
    $dayWorked = function (array $rws): int {
        $c = consolidate_attendance_by_day($rws);
        $m = 0;
        foreach ($c as $d) $m += (int)($d['total_worked_minutes'] ?? 0);
        return $m;
    };

    $pdo->beginTransaction();
    try {
        // Linhas reais do dia ORIGINAL (trava p/ evitar TOCTOU).
        $st = $pdo->prepare("SELECT * FROM attendance
                             WHERE teacher_id = ? AND date = ?
                               AND superseded_by_id IS NULL AND removed_at IS NULL
                             ORDER BY check_in ASC, id ASC
                             FOR UPDATE");
        $st->execute([$teacherId, $origDate]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) throw new RuntimeException('Nenhum registro encontrado para este dia.');

        $existingWorks = [];  // id => row
        $existingBreaks = []; // id => row
        $schoolId = null;
        foreach ($rows as $row) {
            if (($row['record_type'] ?? 'work') === 'break') {
                $existingBreaks[(int)$row['id']] = $row;
            } else {
                $existingWorks[(int)$row['id']] = $row;
                if ($schoolId === null) $schoolId = $row['school_id'] ?? null;
            }
        }

        // Classifica ops em add / edit / keep / remove (com checagem de posse).
        // Resolve um id que já foi SUBSTITUÍDO por uma edição anterior.
        //
        // Desde a Fase 3, editar um horário não reescreve a linha: anula a
        // original e cria uma nova. Quem tiver em mãos o id antigo — uma tela
        // aberta antes da edição, uma segunda submissão do mesmo formulário,
        // um script que encadeia edições — passaria a receber "não pertence a
        // este dia", o que é tecnicamente verdade e praticamente inútil.
        //
        // Seguir a corrente de `superseded_by_id` até o registro vigente
        // preserva a propriedade que interessa (o original continua intacto)
        // sem transformar cada edição numa quebra de contrato para quem chama.
        // O limite de saltos evita laço caso os dados fiquem inconsistentes.
        $resolverSucessor = function (int $id, array $existingById) use ($pdo): ?int {
            $atual = $id;
            for ($i = 0; $i < 10; $i++) {
                $st = $pdo->prepare("SELECT superseded_by_id, removed_at FROM attendance WHERE id = ? LIMIT 1");
                $st->execute([$atual]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                $sup = isset($r['superseded_by_id']) ? (int)$r['superseded_by_id'] : 0;
                // Sem sucessor, ou apontando para si mesmo (remoção comum).
                if ($sup <= 0 || $sup === $atual) return null;
                if (isset($existingById[$sup])) return $sup;
                $atual = $sup;
            }
            return null;
        };

        $classify = function (array $ops, array $existingById, string $rotulo)
                use ($newDate, $resolverSucessor): array {
            $add = []; $edit = []; $keep = []; $remove = [];
            foreach ($ops as $op) {
                if ($op['id'] > 0) {
                    if (!isset($existingById[$op['id']])) {
                        $sucessor = $resolverSucessor((int)$op['id'], $existingById);
                        if ($sucessor === null) {
                            throw new RuntimeException("{$rotulo} #{$op['id']} não pertence a este dia.");
                        }
                        $op['id'] = $sucessor;
                    }
                    $cur = $existingById[$op['id']];
                    if ($op['remove']) { $remove[] = ['op' => $op, 'cur' => $cur]; continue; }
                    $changed = !dt_same_minute($cur['check_in'], $op['startDt'])
                            || !dt_same_minute($cur['check_out'], $op['endDt'])
                            || ($newDate !== (string)$cur['date']);
                    if ($changed) $edit[] = ['op' => $op, 'cur' => $cur];
                    else $keep[] = ['op' => $op, 'cur' => $cur];
                } elseif (!$op['remove']) {
                    $add[] = $op;
                }
            }
            return [$add, $edit, $keep, $remove];
        };
        [$wAdd, $wEdit, $wKeep, $wRemove] = $classify($workOps, $existingWorks, 'Período de trabalho');
        [$bAdd, $bEdit, $bKeep, $bRemove] = $classify($breakOps, $existingBreaks, 'Intervalo');

        $dateChanged = ($newDate !== $origDate);
        $methodChanged = false;
        if ($method !== '') {
            foreach ($existingWorks as $w) { if (strtolower((string)$w['method']) !== strtolower($method)) { $methodChanged = true; break; } }
        }
        $allApproved = true;
        foreach ($rows as $r) { if ((int)$r['approved'] !== 1) { $allApproved = false; break; } }

        $anyChange = $wAdd || $wEdit || $wRemove || $bAdd || $bEdit || $bRemove
                  || $dateChanged || $methodChanged || !$allApproved;
        if (!$anyChange) {
            $pdo->rollBack();
            throw new RuntimeException('Nenhuma alteração detectada.');
        }

        $beforeWorked = $dayWorked($rows);
        // `superseded` é separado de `removed` de propósito: são atos diferentes.
        // Remover é tirar o período do dia; substituir é trocá-lo por outro que
        // continua valendo. Misturá-los faria a tela dizer "removido" para uma
        // simples correção de horário.
        $res = [
            'works'  => ['added' => [], 'removed' => [], 'updated' => [], 'superseded' => []],
            'breaks' => ['added' => [], 'removed' => [], 'updated' => [], 'superseded' => []],
            // Avisos de conformidade com a CLT (art. 59, 71 e 73). NÃO bloqueiam
            // o salvamento: a jornada pode ter ocorrido irregularmente, e negar
            // o registro apagaria a prova do fato que gera o direito.
            'avisos_clt' => function_exists('clt_avisos_jornada')
                ? clt_avisos_jornada($finalWorks, $finalBreaks) : [],
        ];
        $bucket = fn(string $kind) => $kind === 'work' ? 'works' : 'breaks';

        // --- Anulação (soft-delete inline, espelha admin_remove_attendance) ---
        //
        // Dois usos, distinguidos por $replacedById:
        //   null  → o admin removeu o período. `superseded_by_id = id` (aponta
        //           para si mesmo), convenção já usada em admin_remove_attendance.
        //   <id>  → o período foi SUBSTITUÍDO por um novo na edição.
        //           `superseded_by_id` aponta para o sucessor, o que permite às
        //           telas mostrarem "substituído por #X" e ao histórico ligar
        //           original e substituto.
        $softDelete = function (array $cur, string $kind, ?int $replacedById = null)
                use ($pdo, $adminId, $reason, $now, &$res, $bucket) {
            $id = (int)$cur['id'];
            $supersededBy = $replacedById ?? $id;
            $upd = $pdo->prepare("UPDATE attendance
                SET approved = 0, superseded_by_id = ?, removed_at = NOW(),
                    removed_by_admin_id = ?, removed_reason = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND removed_at IS NULL AND superseded_by_id IS NULL");
            $upd->execute([$supersededBy, $adminId, $reason, $id]);
            if ($upd->rowCount() === 0) return;
            $editType = $replacedById !== null ? 'superseded' : 'admin_remove';
            $after = $cur; $after['approved'] = 0; $after['superseded_by_id'] = $supersededBy; $after['removed_at'] = $now;
            $pdo->prepare("INSERT INTO attendance_edits (attendance_id, edited_by, edited_at, reason, type, before_json, after_json) VALUES (?,?,?,?,?,?,?)")
                ->execute([$id, $adminId, $now, $reason, $editType,
                           json_encode($cur, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE)]);
            log_attendance_audit($pdo, $id, $adminId, $editType, 'removed_at', null, $now, $reason);
            audit_log($editType, 'attendance', $id, ['reason' => $reason, 'admin_id' => $adminId,
                'kind' => $kind, 'context' => 'day_edit', 'superseded_by' => $supersededBy]);

            // Livro fiscal: um evento `void` por marcacao anulada (entrada e
            // saida sao registros distintos). A linha original permanece.
            if (function_exists('nsr_ledger_record_mark')) {
                foreach (['nsr', 'nsr_out'] as $col) {
                    $tgt = isset($cur[$col]) ? (int)$cur[$col] : 0;
                    if ($tgt <= 0) continue;
                    nsr_ledger_record_mark($pdo, [
                        'event_type' => 'void', 'target_nsr' => $tgt,
                        'teacher_id' => (int)($cur['teacher_id'] ?? 0) ?: null,
                        'attendance_id' => $id, 'admin_id' => $adminId,
                        'reason' => $reason, 'origin' => 'admin_edit', 'marked_at' => $now,
                    ]);
                }
            }

            $res[$bucket($kind)][$replacedById !== null ? 'superseded' : 'removed'][] = $id;
        };
        foreach ($wRemove as $r)  $softDelete($r['cur'], 'work');
        foreach ($bRemove as $r)  $softDelete($r['cur'], 'break');

        // --- Auditoria campo-a-campo das edições de horário ---
        // O registro em si NÃO é mais alterado: cada edição vira supersede
        // (anula o original + insere um novo com NSR próprio) mais abaixo.
        $auditAdjust = function (array $op, array $cur, string $kind) use ($pdo, $adminId, $reason, $newDate) {
            $id = (int)$op['id'];
            if (!dt_same_minute($cur['check_in'], $op['startDt']))  log_attendance_audit($pdo, $id, $adminId, 'UPDATE', 'check_in', $cur['check_in'], $op['startDt'], $reason);
            if (!dt_same_minute($cur['check_out'], $op['endDt']))   log_attendance_audit($pdo, $id, $adminId, 'UPDATE', 'check_out', $cur['check_out'], $op['endDt'], $reason);
            if ($newDate !== (string)$cur['date'])                  log_attendance_audit($pdo, $id, $adminId, 'UPDATE', 'date', $cur['date'], $newDate, $reason);
        };
        foreach ($wEdit as $e) $auditAdjust($e['op'], $e['cur'], 'work');
        foreach ($bEdit as $e) $auditAdjust($e['op'], $e['cur'], 'break');

        // --- Inserções (NSR atômico). Períodos primeiro; intervalos após saber o repWork. ---
        // $carryFrom: registro ORIGINAL, quando esta inserção o substitui.
        //
        // Sem isto, o supersede seria uma REGRESSÃO DE PROVA. O registro novo
        // nasceria sem foto, sem GPS, sem IP e sem identificação de dispositivo
        // — justamente a evidência que sustenta a marcação. O comprovante
        // ficaria vazio e o cron de limpeza de fotos deixaria de ver a foto
        // como referenciada, podendo apagá-la.
        //
        // A separação é deliberada: copia-se o que é PROVA DO FATO ORIGINAL
        // (onde, com que aparelho, com que foto a pessoa registrou) e NÃO se
        // copia o que descreve a circunstância do registro original que deixou
        // de valer — aprovação, motivo de inserção manual e o rastro de edição,
        // que pertencem ao novo ato administrativo.
        $insert = function (array $op, string $kind, ?int $parentId, ?array $carryFrom = null)
                use ($pdo, $teacherId, $schoolId, $newDate, $method, $adminId, $reason, $now, $nextNsr, &$res, $bucket) {
            $n = $nextNsr();
            $rt = $kind === 'work' ? 'work' : 'break';
            $met = ($kind === 'work' && $method !== '') ? $method : 'manual';
            $c = $carryFrom ?? [];
            $g = fn(string $k) => ($c[$k] ?? null);

            $pdo->prepare("INSERT INTO attendance
                (teacher_id, school_id, date, check_in, check_out, method, approved,
                 manual_reason_text, editado_por, data_edicao, motivo_edicao, nsr, record_type, parent_attendance_id,
                 photo, check_in_lat, check_in_lng, check_in_acc, check_out_lat, check_out_lng, check_out_acc,
                 ip, user_agent, device_identifier, device_fingerprint,
                 record_mode, recorded_at, client_recorded_at, class_period_id)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?)")
                ->execute([
                    $teacherId, $schoolId, $newDate, $op['startDt'], $op['endDt'], $met, $reason, $adminId, $now, $reason, $n, $rt, $parentId,
                    $g('photo'), $g('check_in_lat'), $g('check_in_lng'), $g('check_in_acc'),
                    $g('check_out_lat'), $g('check_out_lng'), $g('check_out_acc'),
                    $g('ip'), $g('user_agent'), $g('device_identifier'), $g('device_fingerprint'),
                    $g('record_mode') ?? 'online', $g('recorded_at'), $g('client_recorded_at'), $g('class_period_id'),
                ]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO attendance_edits (attendance_id, edited_by, edited_at, reason, type, before_json, after_json) VALUES (?,?,?,?,?, NULL, ?)")
                ->execute([$id, $adminId, $now, $reason, $kind === 'work' ? 'add_work' : 'add_break', json_encode(['date' => $newDate, 'check_in' => $op['startDt'], 'check_out' => $op['endDt'], 'parent_attendance_id' => $parentId], JSON_UNESCAPED_UNICODE)]);
            log_attendance_audit($pdo, $id, $adminId, 'CREATE', 'check_in', null, $op['startDt'], $reason);
            audit_log('create', 'attendance', $id, ['kind' => $kind, 'date' => $newDate, 'context' => 'day_edit']);

            // Livro fiscal: periodo criado na edicao de dia gera DUAS marcacoes
            // com NSR proprio. Origem 'admin_edit' + motivo obrigatorio deixam
            // explicito no livro que nao nasceram de registro do trabalhador.
            if (function_exists('nsr_ledger_record_mark')) {
                $identEd = nsr_ledger_teacher_ident($pdo, $teacherId);
                $baseEd = [
                    'teacher_id' => $teacherId,
                    'teacher_cpf' => $identEd['cpf'], 'teacher_pis' => $identEd['pis'],
                    'school_id' => $schoolId, 'attendance_id' => $id,
                    'record_type' => $rt, 'work_date' => $newDate,
                    'origin' => 'admin_edit', 'method' => $met,
                    'admin_id' => $adminId, 'reason' => $reason,
                ];
                $nIn = nsr_ledger_record_mark($pdo, $baseEd + [
                    'mark_role' => 'in', 'direction' => 'E', 'marked_at' => $op['startDt'],
                ]);
                $nOut = !empty($op['endDt'])
                    ? nsr_ledger_record_mark($pdo, $baseEd + [
                        'mark_role' => 'out', 'direction' => 'S', 'marked_at' => $op['endDt'],
                      ])
                    : null;
                if ($nIn !== null) {
                    $pdo->prepare("UPDATE attendance SET legacy_nsr = COALESCE(legacy_nsr, nsr), nsr = ? WHERE id = ?")
                        ->execute([$nIn, $id]);
                }
                if ($nOut !== null) {
                    $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")->execute([$nOut, $id]);
                }
            }

            $res[$bucket($kind)]['added'][] = $id;
            return $id;
        };

        // --- SUPERSEDE das edições (Fase 3 da auditoria — NC-12) ---
        //
        // Antes, editar um horário fazia `UPDATE attendance SET check_in=...`
        // sobre a linha original: o registro legal com aquele NSR era
        // sobrescrito, e o valor anterior sobrevivia apenas em
        // `attendance_edits.before_json`. Perdido esse log, o original sumia —
        // e a Portaria exige que a marcação original seja preservada.
        //
        // Agora cada edição é: INSERE o novo (com NSR próprio e a prova do
        // original) e ANULA o antigo apontando para o sucessor. Nenhuma linha
        // de marcação é reescrita. É o mesmo padrão que o código já usava para
        // adições, aplicado também aos ajustes.
        //
        // A ordem importa: insere primeiro para ter o id do sucessor, e só
        // então anula o original referenciando-o.
        $supersede = function (array $row, string $kind, ?int $parentId)
                use ($pdo, $insert, $softDelete, &$res, $bucket) {
            $velhoId = (int)$row['cur']['id'];
            $novoId  = $insert($row['op'], $kind, $parentId, $row['cur']);
            $softDelete($row['cur'], $kind, $novoId);

            // Referências que devem seguir o registro VIGENTE.
            // Ambas têm índice comum (não UNIQUE), então repontar é seguro.
            foreach ([['hour_bank_entries', 'ref_attendance_id'],
                      ['overtime_requests', 'attendance_id']] as [$tab, $col]) {
                try {
                    $pdo->prepare("UPDATE `{$tab}` SET `{$col}` = ? WHERE `{$col}` = ?")
                        ->execute([$novoId, $velhoId]);
                } catch (Throwable $e) {
                    error_log("[supersede] falha ao repontar {$tab}.{$col}: " . $e->getMessage());
                }
            }
            //
            // DELIBERADAMENTE NÃO repontadas:
            //   attendance_break_requests.attendance_id     (UNIQUE)
            //   attendance_checkout_requests.attendance_id  (UNIQUE)
            // Duas razões. A técnica: o UNIQUE faria o UPDATE estourar 1062 se
            // já houvesse pedido para o id novo. A de fundo, que é a que decide:
            // um pedido de regularização foi feito CONTRA o registro que existia
            // naquele momento — é assim que ele deve continuar arquivado. As
            // telas de regularização seguem `superseded_by_id` para mostrar qual
            // registro está vigente hoje.


            // Sai de 'added' e entra em 'updated': para quem chama, isto é uma
            // edição, não a criação de um período novo.
            $b = $bucket($kind);
            if (($k = array_search($novoId, $res[$b]['added'], true)) !== false) {
                unset($res[$b]['added'][$k]);
                $res[$b]['added'] = array_values($res[$b]['added']);
            }
            $res[$b]['updated'][] = $novoId;
            return $novoId;
        };

        // Períodos sobreviventes (keep + supersede + add) → para achar o representativo.
        $survWorks = []; // [ ['id'=>, 'startDt'=>] ]
        foreach ($wKeep as $k) $survWorks[] = ['id' => (int)$k['op']['id'], 'startDt' => $k['op']['startDt']];
        foreach ($wEdit as $e) $survWorks[] = ['id' => $supersede($e, 'work', null), 'startDt' => $e['op']['startDt']];
        foreach ($wAdd as $op) $survWorks[] = ['id' => $insert($op, 'work', null), 'startDt' => $op['startDt']];

        usort($survWorks, fn($a, $b) => strcmp((string)$a['startDt'], (string)$b['startDt']));
        $repWorkId = $survWorks ? (int)$survWorks[0]['id'] : null;

        // Intervalos sobreviventes (keep + supersede + add); parent reancorado
        // ao repWork no bloco final, então aqui basta passar $repWorkId.
        $survBreakIds = [];
        foreach ($bKeep as $k) $survBreakIds[] = (int)$k['op']['id'];
        foreach ($bEdit as $e) $survBreakIds[] = $supersede($e, 'break', $repWorkId);
        foreach ($bAdd as $op) $survBreakIds[] = $insert($op, 'break', $repWorkId);

        $survWorkIds = array_map(fn($w) => (int)$w['id'], $survWorks);
        $intList = fn(array $ids) => implode(',', array_map('intval', array_values(array_unique($ids))));

        // --- Finalize: apenas METADADOS dos períodos mantidos ---
        //
        // Só $wKeep/$bKeep entram aqui, e o UPDATE NÃO toca check_in, check_out
        // nem date. Períodos com horário ou data alterados já passaram pelo
        // supersede acima — reescrever a linha deles aqui anularia a correção.
        //
        // Aprovar o dia, registrar quem editou e trocar o método são metadados
        // administrativos: descrevem o ato de gestão, não a marcação. Não
        // consomem NSR nem exigem novo registro no livro fiscal.
        //
        // ($classify já coloca em $edit qualquer item cuja data mudou, então
        // não existe item em $keep precisando de reancoragem de data.)
        foreach ($wKeep as $row) {
            $sets = ['approved = 1', 'editado_por = ?', 'data_edicao = ?', 'motivo_edicao = ?'];
            $p = [$adminId, $now, $reason];
            if ($method !== '') { $sets[] = 'method = ?'; $p[] = $method; }
            $p[] = (int)$row['op']['id'];
            $pdo->prepare("UPDATE attendance SET " . implode(', ', $sets) . " WHERE id = ?")->execute($p);
        }
        foreach ($bKeep as $row) {
            $pdo->prepare("UPDATE attendance
                SET approved = 1, parent_attendance_id = ?,
                    editado_por = ?, data_edicao = ?, motivo_edicao = ?
                WHERE id = ?")
                ->execute([$repWorkId, $adminId, $now, $reason, (int)$row['op']['id']]);
        }
        // Intervalos adicionados: garantir parent = repWork (inseridos antes de saber? não — já com repWork).
        if ($repWorkId && $survBreakIds) {
            $pdo->prepare("UPDATE attendance SET parent_attendance_id = ? WHERE id IN (" . $intList($survBreakIds) . ")")->execute([$repWorkId]);
        }

        // --- Auditoria-resumo do dia + diff de minutos ---
        $stAfter = $pdo->prepare("SELECT * FROM attendance WHERE teacher_id = ? AND date = ? AND superseded_by_id IS NULL AND removed_at IS NULL");
        $stAfter->execute([$teacherId, $newDate]);
        $afterRows = $stAfter->fetchAll(PDO::FETCH_ASSOC);
        $afterWorked = $dayWorked($afterRows);
        $diff = $afterWorked - $beforeWorked;

        $summaryId = $repWorkId ?: (int)($rows[0]['id'] ?? 0);
        if ($summaryId > 0) {
            $pdo->prepare("INSERT INTO attendance_edits
                (attendance_id, edited_by, edited_at, reason, type, diff_minutes, changed_fields, before_json, after_json)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $summaryId, $adminId, $now, $reason, 'day_edit', $diff,
                    json_encode([
                        'date_changed' => $dateChanged, 'method_changed' => $methodChanged,
                        'works_added' => count($res['works']['added']), 'works_removed' => count($res['works']['removed']), 'works_updated' => count($res['works']['updated']),
                        'breaks_added' => count($res['breaks']['added']), 'breaks_removed' => count($res['breaks']['removed']), 'breaks_updated' => count($res['breaks']['updated']),
                    ], JSON_UNESCAPED_UNICODE),
                    json_encode(['date' => $origDate, 'worked_min' => $beforeWorked], JSON_UNESCAPED_UNICODE),
                    json_encode(['date' => $newDate, 'worked_min' => $afterWorked], JSON_UNESCAPED_UNICODE),
                ]);
            if ($dateChanged)   log_attendance_audit($pdo, $summaryId, $adminId, 'UPDATE', 'date', $origDate, $newDate, $reason);
            if ($methodChanged) log_attendance_audit($pdo, $summaryId, $adminId, 'UPDATE', 'method', null, $method, $reason);
            audit_log('update', 'attendance', $summaryId, [
                'reason' => $reason, 'date_changed' => $dateChanged, 'method_changed' => $methodChanged,
                'works' => $res['works'], 'breaks' => $res['breaks'], 'diff_minutes' => $diff, 'context' => 'day_edit',
            ]);
        }

        // Banco de horas (dia novo e, se a data mudou, o dia de origem).
        recompute_day_hour_bank($pdo, $teacherId, $newDate);
        if ($dateChanged) recompute_day_hour_bank($pdo, $teacherId, $origDate);

        $pdo->commit();
        return [
            'ok' => true,
            'work_id' => $repWorkId ?: 0,
            'works' => $res['works'], 'breaks' => $res['breaks'],
            'date_changed' => $dateChanged, 'method_changed' => $methodChanged,
            'diff_minutes' => $diff,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Inativa (active=0) ou reativa (active=1) um colaborador — auditável, reversível,
 * NÃO apaga histórico/folha. Valida escopo do admin. Inativar exige motivo e
 * invalida tokens de sessão persistente.
 *
 * @return array{changed:bool,active:int,name?:string}
 */
function admin_set_collaborator_active(PDO $pdo, int $teacherId, int $adminId, bool $active, string $reason = ''): array {
    $reason = trim($reason);
    if (!$active && $reason === '') throw new RuntimeException('Informe o motivo da inativação.');
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $st = $pdo->prepare("SELECT t.id, t.active, t.name FROM teachers t WHERE t.id = ? AND $scopeSql LIMIT 1");
    $st->execute(array_merge([$teacherId], $scopeParams));
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new RuntimeException('Colaborador inexistente ou fora do seu escopo.');
    $old = (int)$t['active'];
    $new = $active ? 1 : 0;
    if ($old === $new) return ['changed' => false, 'active' => $new, 'name' => $t['name']];
    $pdo->prepare("UPDATE teachers SET active = ? WHERE id = ?")->execute([$new, $teacherId]);
    if (!$active) {
        try { $pdo->prepare("DELETE FROM collaborator_remember_tokens WHERE teacher_id = ?")->execute([$teacherId]); } catch (Throwable $e) {}
    }
    audit_log($active ? 'reactivate' : 'deactivate', 'teacher', $teacherId, ['reason' => $reason, 'admin_id' => $adminId, 'old_active' => $old, 'new_active' => $new]);

    // Livro fiscal — registro tipo 5 do AFD (inclusão/alteração/exclusão de
    // empregado). `reason` carrega "OPERACAO|Nome" porque é assim que
    // lib/afd.php monta o registro: 'I' inclusão, 'A' alteração, 'E' exclusão.
    if (function_exists('nsr_ledger_record_cadastro')) {
        $ident = function_exists('nsr_ledger_teacher_ident')
            ? nsr_ledger_teacher_ident($pdo, $teacherId) : ['cpf' => null, 'pis' => null];
        nsr_ledger_record_cadastro($pdo, 'employee_change', [
            'teacher_id'  => $teacherId,
            'teacher_cpf' => $ident['cpf'],
            'teacher_pis' => $ident['pis'],
            'origin'      => 'admin_edit',
            'admin_id'    => $adminId,
            'reason'      => ($active ? 'I' : 'E') . '|' . (string)$t['name'],
        ]);
    }

    return ['changed' => true, 'active' => $new, 'name' => $t['name']];
}

/**
 * Converte um código técnico de pendência (ex: "no_gps") em texto amigável
 * para exibição ao usuário/admin. Aceita também os textos legacy em pt-BR
 * que já existem no banco. Fallback: retorna o próprio código capitalizado.
 */
function humanize_pending_reason(string $code): string {
    $key = strtolower(trim($code));
    if ($key === '') return '';

    static $map = [
        'no_gps'                 => 'Localização não habilitada no dispositivo.',
        'no_location'            => 'Localização não habilitada no dispositivo.',
        'gps_accuracy_low'       => 'GPS com baixa precisão no momento da batida.',
        'gps_low_accuracy'       => 'GPS com baixa precisão no momento da batida.',
        'out_of_radius'          => 'Fora da área permitida da instituição.',
        'out_of_perimeter'       => 'Fora da área permitida da instituição.',
        'no_school_linked'       => 'Colaborador não está vinculado a uma instituição.',
        'school_not_configured'  => 'Instituição sem localização configurada.',
        'gps_mock'               => 'Possível GPS falso detectado no aparelho.',
        'gps_mock_detected'      => 'Possível GPS falso detectado no aparelho.',
        'no_face'                => 'Colaborador sem foto facial cadastrada.',
        'no_face_enrolled'       => 'Colaborador sem foto facial cadastrada.',
        'cadastro facial pendente' => 'Colaborador sem foto facial cadastrada.',
        'face_low_score'         => 'Reconhecimento facial com baixa confiança.',
        'face_mismatch'          => 'Rosto não conferiu com o cadastro.',
        'liveness_failed'        => 'Falha na verificação de presença real (anti-foto).',
        'hlb_drift_excessive'    => 'Horário do aparelho diferente do horário oficial.',
        'fraud_suspected'        => 'Comportamento incomum detectado — requer revisão.',
        // Registros históricos podem conter estes códigos; exibidos em linguagem
        // neutra (hora extra foi descontinuada).
        'overtime_candidate'     => 'Registro fora da jornada regular — requer revisão.',
        'is_overtime_candidate'  => 'Registro fora da jornada regular — requer revisão.',
        'overtime_review'        => 'Registro fora da jornada regular — requer revisão.',
        'registro em lote (offline sync)' => 'Registro feito offline e sincronizado depois.',
        'fora da área de geofence'        => 'Fora da área permitida da instituição.',
    ];

    if (isset($map[$key])) return $map[$key];

    // Fuzzy match para frases legacy.
    static $needles = [
        'fora do raio'      => 'out_of_radius',
        'fora do per'       => 'out_of_perimeter',
        'fora da área'      => 'out_of_radius',
        'fora da area'      => 'out_of_radius',
        'sem gps'           => 'no_gps',
        'sem localiza'      => 'no_location',
        'localiza'          => 'no_location',
        'cadastro facial'   => 'no_face_enrolled',
        'sem foto'          => 'no_face_enrolled',
        'precis'            => 'gps_accuracy_low',
        'institui'          => 'school_not_configured',
        'offline'           => 'registro em lote (offline sync)',
        'lote'              => 'registro em lote (offline sync)',
        'hlb'               => 'hlb_drift_excessive',
        'rel'               => 'hlb_drift_excessive',
    ];
    foreach ($needles as $n => $alias) {
        if (strpos($key, $n) !== false && isset($map[$alias])) {
            return $map[$alias];
        }
    }

    // Fallback: devolve o código original com primeira letra maiúscula.
    return ucfirst($code);
}

/**
 * Recebe o valor bruto da coluna `pending_reasons` (JSON ou array) e devolve
 * uma lista de mensagens humanizadas únicas, preservando ordem.
 *
 * @param string|array|null $raw
 * @return string[]
 */
function humanize_pending_reasons($raw): array {
    if (empty($raw)) return [];
    $reasons = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($reasons)) return [];

    $out = [];
    $seen = [];
    foreach ($reasons as $r) {
        $msg = humanize_pending_reason((string)$r);
        if ($msg === '') continue;
        $k = mb_strtolower($msg);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $msg;
    }
    return $out;
}

/**
 * Calcula o tamanho total de um diretório em bytes
 */
function get_directory_size(string $path): int {
    $totalSize = 0;
    if (!is_dir($path)) {
        return 0;
    }
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao calcular tamanho do diretório {$path}: " . $e->getMessage());
    }
    
    return $totalSize;
}

/**
 * Limpa fotos antigas do sistema de ponto automaticamente
 * 
 * @param PDO $pdo Conexão com banco de dados
 * @param bool $force Forçar limpeza mesmo se threshold não foi atingido
 * @return array Estatísticas da limpeza
 */
function cleanup_old_photos(PDO $pdo, bool $force = false): array {
    $photosDir = __DIR__ . '/public/photos/';
    // Prazo vem de app_settings (retention_photos_days), com a constante como
    // fallback — assim o responsável ajusta a política sem editar código.
    $retentionDays = (int)(function_exists('get_setting')
        ? (get_setting('retention_photos_days', (string)(defined('PHOTO_RETENTION_DAYS') ? PHOTO_RETENTION_DAYS : 90)) ?? 90)
        : (defined('PHOTO_RETENTION_DAYS') ? PHOTO_RETENTION_DAYS : 90));
    if ($retentionDays <= 0) $retentionDays = 90;

    $thresholdMB   = defined('PHOTO_STORAGE_THRESHOLD_MB') ? PHOTO_STORAGE_THRESHOLD_MB : 500;
    $currentSize   = get_directory_size($photosDir);
    $currentSizeMB = round($currentSize / 1024 / 1024, 2);

    // ------------------------------------------------------------------------
    // NC-37 (auditoria 2026-08-05): o gatilho da limpeza era o VOLUME em disco
    // (500 MB). Como o diretório tinha 1,1 MB, a rotina nunca rodava — e havia
    // 550 fotos de rosto além dos 90 dias de retenção, guardadas
    // indefinidamente. Retenção medida em espaço livre não é política de
    // retenção: é política de capacidade, e a LGPD exige a primeira
    // (art. 15/16 — eliminação após o fim da finalidade).
    //
    // O prazo passou a ser o único critério. O limiar de volume sobrevive
    // apenas como informação no relatório.
    // ------------------------------------------------------------------------

    // Calcula data de corte
    $cutoffDate = date('Y-m-d', strtotime("-{$retentionDays} days"));
    
    // Busca fotos antigas que ainda não foram deletadas
    $stmt = $pdo->prepare("
        SELECT id, photo, date
        FROM attendance 
        WHERE photo IS NOT NULL 
          AND photo != ''
          AND photo_deleted = 0 
          AND date < ?
        ORDER BY date ASC
    ");
    $stmt->execute([$cutoffDate]);
    $oldPhotos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $deleted = 0;
    $freed = 0;
    $errors = [];
    
    foreach ($oldPhotos as $row) {
        // HOTFIX 2026-09: a coluna tem dois formatos ('arquivo.jpg' e
        // 'photos/arquivo.jpg'). Com o prefixo, o caminho montado não existia e o
        // ramo "arquivo não existe" marcava photo_deleted=1 SEM apagar o arquivo —
        // o banco dizia "expurgada" e a foto biométrica continuava no disco.
        $photoName = basename(str_replace('\\', '/', (string)$row['photo']));
        if ($photoName === '' || !preg_match('/^[A-Za-z0-9_.-]+\.(jpg|jpeg|png)$/i', $photoName)) {
            $errors[] = "Referência de foto inválida no registro {$row['id']}";
            continue;
        }
        $filepath = $photosDir . $photoName;

        if (file_exists($filepath)) {
            try {
                $size = filesize($filepath);
                
                if (@unlink($filepath)) {
                    // Atualiza registro no banco
                    $updateStmt = $pdo->prepare("
                        UPDATE attendance 
                        SET photo_deleted = 1, photo_deleted_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$row['id']]);
                    
                    $deleted++;
                    $freed += $size;
                } else {
                    $errors[] = "Falha ao deletar arquivo: {$row['photo']}";
                }
            } catch (Exception $e) {
                $errors[] = "Erro ao processar {$row['photo']}: " . $e->getMessage();
                error_log("Photo cleanup error: " . $e->getMessage());
            }
        } else {
            // Arquivo não existe mais, apenas marca como deletado no banco
            try {
                $updateStmt = $pdo->prepare("
                    UPDATE attendance 
                    SET photo_deleted = 1, photo_deleted_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$row['id']]);
            } catch (Exception $e) {
                $errors[] = "Erro ao atualizar registro {$row['id']}: " . $e->getMessage();
            }
        }
    }
    
    $freedMB = round($freed / 1024 / 1024, 2);
    $newSize = get_directory_size($photosDir);
    $newSizeMB = round($newSize / 1024 / 1024, 2);
    
    // Log da operação
    $logMsg = sprintf(
        "Photo cleanup: %d fotos deletadas, %.2f MB liberados (de %.2f MB para %.2f MB)",
        $deleted,
        $freedMB,
        $currentSizeMB,
        $newSizeMB
    );
    error_log($logMsg);
    
    return [
        'status' => 'completed',
        'deleted_count' => $deleted,
        'freed_space_mb' => $freedMB,
        'current_size_mb' => $newSizeMB,
        'previous_size_mb' => $currentSizeMB,
        'cutoff_date' => $cutoffDate,
        'retention_days' => $retentionDays,
        'total_candidates' => count($oldPhotos),
        'errors' => $errors,
        'error_count' => count($errors)
    ];
}

/**
 * Retorna array de datas que são feriados em um período
 * @param PDO $pdo
 * @param string $startDate
 * @param string $endDate
 * @param int|null $schoolId
 * @return array [date => ['name' => ..., 'type' => ...]]
 */
function get_holidays_in_period(PDO $pdo, string $startDate, string $endDate, ?int $schoolId = null): array {
    $sql = "SELECT date, name, type, is_working_day FROM calendar_exceptions 
            WHERE date BETWEEN ? AND ? 
              AND (school_id IS NULL OR school_id = ?)
              AND is_working_day = 0
            ORDER BY date";
    $st = $pdo->prepare($sql);
    $st->execute([$startDate, $endDate, $schoolId]);
    
    $holidays = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $holidays[$row['date']] = [
            'name' => $row['name'],
            'type' => $row['type']
        ];
    }

    return $holidays;
}

/**
 * Retorna true se a data é um dia NÃO-útil por exceção de calendário —
 * feriado, ponto facultativo, recesso, etc. (qualquer linha em
 * calendar_exceptions com is_working_day=0) válida para a escola informada
 * OU para toda a rede (school_id IS NULL).
 *
 * Diferente de is_working_day(): considera APENAS exceções explícitas e NÃO
 * trata sábado/domingo "default" como folga. É seguro, portanto, para zerar a
 * jornada prevista sem afetar quem trabalha em fins de semana (sábado letivo).
 */
function calendar_day_is_off(PDO $pdo, string $date, ?int $schoolId = null): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM calendar_exceptions
                             WHERE date = ?
                               AND is_working_day = 0
                               AND (school_id IS NULL OR school_id = ?)
                             LIMIT 1");
        $st->execute([$date, $schoolId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $_) {
        return false;
    }
}

/**
 * Resolve a escola "primária" de um colaborador (primeira lotação em
 * teacher_schools). Usada por relatórios para consultar exceções de calendário
 * específicas da escola. Retorna null se o colaborador não tem lotação — nesse
 * caso só exceções da rede inteira (school_id IS NULL) se aplicam.
 */
function primary_school_id_for_teacher(PDO $pdo, int $teacherId): ?int {
    try {
        // teacher_schools não tem coluna `id` neste schema — ordena por school_id
        // (determinístico) em vez de `id` (que dispararia 1054 e cairia no catch).
        $st = $pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id = ? ORDER BY school_id LIMIT 1");
        $st->execute([$teacherId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (int)$v : null;
    } catch (Throwable $_) {
        return null;
    }
}

/**
 * =====================================================================
 * FUNÇÕES PARA GRADE HORÁRIA E PERÍODOS DE AULA
 * =====================================================================
 */

/**
 * Retorna os períodos de aula disponíveis para uma escola (ou globais se school_id = null)
 */
function get_class_periods(?int $schoolId = null): array {
    $pdo = db();
    if ($schoolId === null) {
        // Retorna períodos globais
        $st = $pdo->prepare("SELECT * FROM class_periods WHERE school_id IS NULL AND active = 1 ORDER BY period_number");
        $st->execute();
    } else {
        // Retorna períodos da escola ou globais
        $st = $pdo->prepare("SELECT * FROM class_periods WHERE (school_id = ? OR school_id IS NULL) AND active = 1 ORDER BY period_number");
        $st->execute([$schoolId]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca os horários/períodos atribuídos a um professor em um determinado dia da semana
 */
function buscar_horarios_professor(int $teacherId, int $weekday, ?int $schoolId = null): array {
    $pdo = db();
    
    $sql = "SELECT tca.*, cp.period_number, cp.start_time, cp.end_time 
            FROM teacher_class_assignments tca
            JOIN class_periods cp ON cp.id = tca.period_id
            WHERE tca.teacher_id = ? AND tca.weekday = ? AND cp.active = 1";
    
    $params = [$teacherId, $weekday];
    
    if ($schoolId !== null) {
        $sql .= " AND (tca.school_id = ? OR tca.school_id IS NULL)";
        $params[] = $schoolId;
    }
    
    $sql .= " ORDER BY cp.period_number";
    
    $st = $pdo->prepare($sql);
    $st->execute($params);
    
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Identifica qual período está ativo no momento atual (com tolerância)
 * Retorna o period_id ou null se não houver período ativo
 */
function identificar_periodo_atual(array $assignments, string $nowTime = null, int $toleranceMinutes = 15): ?array {
    if (empty($assignments)) {
        return null;
    }
    
    if ($nowTime === null) {
        $nowTime = date('H:i:s');
    }
    
    $nowTimestamp = strtotime($nowTime);
    
    foreach ($assignments as $assignment) {
        $startTime = strtotime($assignment['start_time']);
        $endTime = strtotime($assignment['end_time']);
        
        // Adiciona tolerância (antes e depois)
        $startWithTolerance = $startTime - ($toleranceMinutes * 60);
        $endWithTolerance = $endTime + ($toleranceMinutes * 60);
        
        if ($nowTimestamp >= $startWithTolerance && $nowTimestamp <= $endWithTolerance) {
            return $assignment;
        }
    }
    
    return null;
}

/**
 * Verifica se um professor já registrou ponto para um determinado período em uma data
 */
function verificar_periodo_registrado(int $teacherId, string $date, int $periodId): bool {
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) FROM attendance 
                         WHERE teacher_id = ? AND date = ? AND class_period_id = ?");
    $st->execute([$teacherId, $date, $periodId]);
    return ((int)$st->fetchColumn()) > 0;
}

/**
 * Retorna o próximo número de sequência para um registro de attendance em uma data
 */
function get_next_attendance_sequence(int $teacherId, string $date): int {
    $pdo = db();
    $st = $pdo->prepare("SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM attendance 
                         WHERE teacher_id = ? AND date = ?");
    $st->execute([$teacherId, $date]);
    return (int)$st->fetchColumn();
}

/**
 * Conta quantos períodos um professor tem em um dia da semana
 */
function count_teacher_periods(int $teacherId, int $weekday, ?int $schoolId = null): int {
    $assignments = buscar_horarios_professor($teacherId, $weekday, $schoolId);
    return count($assignments);
}

/**
 * Retorna todos os registros de attendance de um professor em uma data (múltiplos check-ins)
 */
function get_teacher_attendance_records(int $teacherId, string $date): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT a.*, cp.period_number, cp.start_time, cp.end_time
                         FROM attendance a
                         LEFT JOIN class_periods cp ON cp.id = a.class_period_id
                         WHERE a.teacher_id = ? AND a.date = ?
                         ORDER BY a.sequence_number, a.check_in");
    $st->execute([$teacherId, $date]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calcula o total de minutos trabalhados baseado em múltiplos registros do dia
 * (soma apenas os períodos com check-in e check-out completos).
 *
 * Ignora registros marcados como record_type='break' — eles representam intervalo,
 * não tempo de trabalho.
 */
function calculate_worked_minutes_from_periods(array $records): int {
    $totalMinutes = 0;

    foreach ($records as $record) {
        if (!empty($record['check_in']) && !empty($record['check_out'])) {
            // Pula intervalos. Registros legados sem record_type são tratados como work.
            if (($record['record_type'] ?? 'work') === 'break') continue;

            $in = new DateTime($record['check_in']);
            $out = new DateTime($record['check_out']);

            if ($out > $in) {
                $minutes = (int)(($out->getTimestamp() - $in->getTimestamp()) / 60);
                $totalMinutes += $minutes;
            }
        }
    }

    return $totalMinutes;
}

/**
 * Verifica se um professor usa o sistema de grade horária (múltiplos check-ins)
 * ou o sistema tradicional (um check-in por dia)
 */
function teacher_uses_period_system(int $teacherId): bool {
    // OTIMIZAÇÃO: Cache em memória para evitar queries repetidas durante mesma requisição
    static $cache = [];
    
    if (isset($cache[$teacherId])) {
        return $cache[$teacherId];
    }
    
    $pdo = db();
    // Verifica se há atribuições de períodos para este professor
    $st = $pdo->prepare("SELECT COUNT(*) FROM teacher_class_assignments WHERE teacher_id = ? LIMIT 1");
    $st->execute([$teacherId]);
    $result = ((int)$st->fetchColumn()) > 0;
    $cache[$teacherId] = $result;
    return $result;
}

/**
 * =====================================================================
 * FUNÇÕES PARA HORAS EXTRAS (OVERTIME)
 * =====================================================================
 */

/**
 * Verifica se um horário está dentro da grade atribuída ao professor
 * Retorna true se está dentro, false se está fora (candidato a hora extra)
 */
function is_time_within_assigned_periods(int $teacherId, int $weekday, string $time, int $toleranceMinutes = 15): bool {
    $assignments = buscar_horarios_professor($teacherId, $weekday);
    
    if (empty($assignments)) {
        return false; // Sem atribuições = fora da grade
    }
    
    $period = identificar_periodo_atual($assignments, $time, $toleranceMinutes);
    return $period !== null;
}

/**
 * Cria um registro de hora extra direto pelo admin (atalho da tela admin/overtime.php
 * para professores com grade horaria, em dias sem aulas). NAO marca requested_by_employee=1
 * — esses registros aparecem como "criados pelo sistema" no filtro Origem da tela admin.
 *
 * Para solicitacao pelo colaborador, use create_overtime_request() — fluxo opt-in.
 */
/** @deprecated Hora extra descontinuada — função sem call sites ativos; mantida só para compatibilidade/auditoria. */
function admin_create_overtime_record(PDO $pdo, int $attendanceId, int $teacherId, ?int $schoolId, string $date, int $minutes, int $expectedMinutes, int $workedMinutes, ?string $justification = null): int {
    $stmt = $pdo->prepare("INSERT INTO overtime_requests
        (attendance_id, teacher_id, school_id, date, minutes, expected_minutes, worked_minutes, justification, status, requested_by_employee, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW())");

    $stmt->execute([
        $attendanceId,
        $teacherId,
        $schoolId,
        $date,
        $minutes,
        $expectedMinutes,
        $workedMinutes,
        $justification
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Retorna candidatos a hora extra pendentes de aprovação
 */
/** @deprecated Hora extra descontinuada — função sem call sites ativos; mantida só para compatibilidade/auditoria. */
function get_pending_overtime_candidates(?int $schoolId = null, ?int $teacherId = null, int $limit = 100): array {
    $pdo = db();
    
    $sql = "SELECT a.*, t.name as teacher_name, s.name as school_name,
                   ct.name as type_name
            FROM attendance a
            JOIN teachers t ON t.id = a.teacher_id
            LEFT JOIN schools s ON s.id = a.school_id
            LEFT JOIN collaborator_types ct ON ct.id = t.type_id
            WHERE a.is_overtime_candidate = 1 AND a.approved IS NULL";
    
    $params = [];
    
    if ($schoolId !== null) {
        $sql .= " AND a.school_id = ?";
        $params[] = $schoolId;
    }
    
    if ($teacherId !== null) {
        $sql .= " AND a.teacher_id = ?";
        $params[] = $teacherId;
    }
    
    $sql .= " ORDER BY a.date DESC, a.check_in DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calcula total de horas extras aprovadas para um professor em um período
 */
function calculate_approved_overtime(int $teacherId, string $startDate, string $endDate): array {
    $pdo = db();
    
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total_requests,
        SUM(minutes) as total_minutes,
        SUM(minutes) / 60.0 as total_hours
        FROM overtime_requests 
        WHERE teacher_id = ? 
        AND date BETWEEN ? AND ? 
        AND status = 'approved'");
    
    $stmt->execute([$teacherId, $startDate, $endDate]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'total_requests' => (int)($result['total_requests'] ?? 0),
        'total_minutes' => (int)($result['total_minutes'] ?? 0),
        'total_hours' => (float)($result['total_hours'] ?? 0.0)
    ];
}

/**
 * Calcula valor monetário de horas extras
 */
/** @deprecated Hora extra descontinuada — função sem call sites ativos; mantida só para compatibilidade/auditoria. */
function calculate_overtime_payment(int $totalMinutes, float $baseSalary, int $expectedMinutes, float $multiplier = 1.5): float {
    if ($expectedMinutes <= 0) {
        return 0.0;
    }
    
    $minuteValue = $baseSalary / $expectedMinutes;
    return $totalMinutes * $minuteValue * $multiplier;
}

/**
 * Retorna configuração de overtime
 */
function get_overtime_setting(string $key, $default = null) {
    $fullKey = 'overtime_' . $key;
    return get_setting($fullKey, $default);
}

// ============================================================================
// PIN (registro de ponto com segurança adaptativa) + Dispositivos confiáveis
// ============================================================================

/**
 * Gera um PIN numérico aleatório, evitando sequências triviais.
 */
function pin_generate(int $length = 6): string {
    $length = max(4, min(8, $length));
    $blacklist = ['123456','654321','111111','222222','333333','444444',
                  '555555','666666','777777','888888','999999','000000',
                  '1234','4321','1111','0000','123123','112233'];
    $min = (int)str_pad('1', $length, '0');
    $max = (int)str_pad('9', $length, '9');
    // Laço amplo: a probabilidade de 1000 rejeições consecutivas é desprezível
    // (~0,1% do espaço é trivial), mas o limite garante que a função sempre termina.
    for ($i = 0; $i < 1000; $i++) {
        $candidate = str_pad((string)random_int($min, $max), $length, '0', STR_PAD_LEFT);
        if (in_array($candidate, $blacklist, true)) continue;
        $isSeq = true; $isRep = true;
        for ($k = 1; $k < strlen($candidate); $k++) {
            if ((int)$candidate[$k] - (int)$candidate[$k-1] !== 1) $isSeq = false;
            if ($candidate[$k] !== $candidate[0]) $isRep = false;
        }
        if ($isSeq || $isRep) continue;
        return $candidate;
    }
    // Se 1000 tentativas falharem (estatisticamente impossível), lança erro
    // em vez de devolver um PIN potencialmente trivial.
    throw new RuntimeException('Não foi possível gerar um PIN não-trivial.');
}

/**
 * Valida força de um PIN escolhido pelo usuário.
 * Retorna [bool $ok, string $errorCode, string $errorMessage].
 *
 * Regras:
 *  - Comprimento exato igual a app_setting `pin_length` (default 6)
 *  - Apenas dígitos
 *  - Bloqueia padrões fracos (sequências, repetições, datas óbvias)
 *  - Bloqueia coincidência com últimos/primeiros 6 dígitos do CPF
 */
/**
 * Força da senha de ADMINISTRADOR — NC-43 (Fase 9 da auditoria).
 *
 * Não havia validação nenhuma: `admins.php` e `bin/setup_admin.php` gravavam o
 * que fosse digitado, e `ensure_default_admin()` chegava a criar o usuário
 * `admin` com a senha `admin123`. O contraste com o PIN do colaborador — que
 * tem `pin_validate_strength()`, bloqueando sequências, repetições e janelas do
 * CPF — era desproporcional: quem tem menos poder no sistema estava mais
 * protegido do que quem administra o ponto de todos.
 *
 * O critério aqui é comprimento com variedade, não um teatro de complexidade:
 * exigir símbolo obrigatório produz `Senha@123`, que é pior que uma frase longa.
 *
 * @return array{0:bool,1:string,2:string} [ok, código, mensagem]
 */
function admin_validate_password(string $senha, string $cpf = '', string $nome = ''): array {
    $min = (int)(get_setting('admin_password_min_length', '10') ?? 10);
    $min = max(8, min(64, $min));

    if (mb_strlen($senha) < $min) {
        return [false, 'admin_pwd_curta', "A senha deve ter ao menos {$min} caracteres."];
    }
    if (mb_strlen($senha) > 200) {
        return [false, 'admin_pwd_longa', 'A senha é longa demais (máximo 200 caracteres).'];
    }
    if (trim($senha) !== $senha) {
        return [false, 'admin_pwd_espaco', 'A senha não pode começar nem terminar com espaço.'];
    }

    // Senhas notoriamente usadas, incluindo a que o próprio sistema semeava.
    $proibidas = ['admin123', 'admin1234', '12345678', '123456789', '1234567890',
                  'senha123', 'password', 'password1', 'qwerty123', 'abc12345',
                  'admin@123', 'mudar123', 'trocar123', 'ponto123', '00000000'];
    $lower = mb_strtolower($senha);
    if (in_array($lower, $proibidas, true)) {
        return [false, 'admin_pwd_comum', 'Essa senha é conhecida e não pode ser usada.'];
    }
    foreach ($proibidas as $p) {
        if (mb_strlen($p) >= 8 && str_contains($lower, $p)) {
            return [false, 'admin_pwd_comum', 'A senha contém uma sequência conhecida (' . $p . ').'];
        }
    }

    // Um único caractere repetido, por mais longo que seja, não é senha.
    if (preg_match('/^(.)\1+$/u', $senha)) {
        return [false, 'admin_pwd_repetida', 'A senha não pode ser um único caractere repetido.'];
    }
    // Sequências óbvias de teclado ou de dígitos.
    foreach (['0123456789', 'abcdefghijklmnopqrstuvwxyz', 'qwertyuiop', 'asdfghjkl'] as $seq) {
        for ($i = 0; $i + 6 <= mb_strlen($seq); $i++) {
            $trecho = mb_substr($seq, $i, 6);
            if (str_contains($lower, $trecho) || str_contains($lower, strrev($trecho))) {
                return [false, 'admin_pwd_sequencia', 'A senha contém uma sequência previsível ("' . $trecho . '").'];
            }
        }
    }

    // Dados do próprio administrador não servem de senha.
    $cpfDigitos = preg_replace('/\D/', '', $cpf);
    if ($cpfDigitos !== '' && str_contains(preg_replace('/\D/', '', $senha), $cpfDigitos)) {
        return [false, 'admin_pwd_cpf', 'A senha não pode conter o CPF.'];
    }
    if ($cpfDigitos !== '' && mb_strlen($cpfDigitos) === 11) {
        // Qualquer janela de 6 dígitos do CPF — mesma regra do PIN.
        $sd = preg_replace('/\D/', '', $senha);
        for ($i = 0; $i + 6 <= 11; $i++) {
            if ($sd !== '' && str_contains($sd, substr($cpfDigitos, $i, 6))) {
                return [false, 'admin_pwd_cpf', 'A senha não pode conter trechos do CPF.'];
            }
        }
    }
    $primeiroNome = trim(explode(' ', trim($nome))[0] ?? '');
    if (mb_strlen($primeiroNome) >= 4 && str_contains($lower, mb_strtolower($primeiroNome))) {
        return [false, 'admin_pwd_nome', 'A senha não pode conter o seu nome.'];
    }

    // Variedade: ao menos três tipos de caractere, OU uma senha longa (16+),
    // que é forte por comprimento mesmo sendo uma frase em letras minúsculas.
    $tipos = (int)(bool)preg_match('/[a-z]/u', $senha)
           + (int)(bool)preg_match('/[A-Z]/u', $senha)
           + (int)(bool)preg_match('/\d/', $senha)
           + (int)(bool)preg_match('/[^\p{L}\p{N}]/u', $senha);
    if ($tipos < 3 && mb_strlen($senha) < 16) {
        return [false, 'admin_pwd_variedade',
                'Use ao menos três tipos de caractere (maiúscula, minúscula, número, símbolo) '
                . 'ou uma senha com 16 caracteres ou mais.'];
    }

    return [true, '', ''];
}

function pin_validate_strength(string $pin, string $cpf = '', ?int $length = null): array {
    $length = $length ?? (int)(get_setting('pin_length', '6') ?? '6');
    $length = max(4, min(8, $length));

    if (!preg_match('/^\d+$/', $pin)) {
        return [false, 'pin_format_invalid', 'O PIN deve conter apenas números.'];
    }
    if (strlen($pin) !== $length) {
        return [false, 'pin_length_invalid', "O PIN deve ter exatamente {$length} dígitos."];
    }

    // Repetição: 000000, 111111, ..., 999999
    if (count(array_unique(str_split($pin))) === 1) {
        return [false, 'pin_too_weak', 'Esse PIN é fácil demais. Evite 6 dígitos iguais.'];
    }

    // Sequências crescentes/decrescentes (123456, 654321, 234567, 765432, etc)
    $isAsc = true; $isDesc = true;
    for ($k = 1; $k < strlen($pin); $k++) {
        if ((int)$pin[$k] - (int)$pin[$k-1] !== 1)  $isAsc = false;
        if ((int)$pin[$k-1] - (int)$pin[$k] !== 1) $isDesc = false;
    }
    if ($isAsc || $isDesc) {
        return [false, 'pin_too_weak', 'Esse PIN é fácil demais. Evite sequências (1-2-3-4-5-6).'];
    }

    // Padrões repetidos óbvios: 121212, 123123, 112233, 010101
    $patterns = ['121212','123123','111222','112233','010101','101010','212121','202020','131313','141414','151515','161616','171717','181818','191919'];
    if (in_array($pin, $patterns, true)) {
        return [false, 'pin_too_weak', 'Esse PIN é fácil demais. Tente uma combinação menos previsível.'];
    }

    // Pares repetidos (AABBCC) — ex: 112233, 224466
    if ($length >= 6) {
        $pairsRepeated = true;
        for ($k = 0; $k < $length; $k += 2) {
            if (!isset($pin[$k+1]) || $pin[$k] !== $pin[$k+1]) { $pairsRepeated = false; break; }
        }
        if ($pairsRepeated) {
            return [false, 'pin_too_weak', 'Esse PIN é fácil demais. Evite pares repetidos.'];
        }
    }

    // M1: bloqueia 2+ pares consecutivos (AAB...CC, ABCDDEE etc) — ex: 123344
    $consecutivePairs = 0;
    for ($k = 0; $k < strlen($pin) - 1; $k++) {
        if ($pin[$k] === $pin[$k+1]) $consecutivePairs++;
    }
    if ($consecutivePairs >= 2) {
        return [false, 'pin_too_weak', 'Esse PIN é fácil demais. Evite repetir dígitos lado a lado.'];
    }

    // H3: coincidência com QUALQUER janela de N dígitos do CPF (não só
    // primeiros/últimos). Ex: CPF 12345678901, janela do meio "345678".
    $cpfDigits = preg_replace('/\D/', '', $cpf);
    if (strlen($cpfDigits) === 11 && $length <= 11) {
        for ($start = 0; $start <= 11 - $length; $start++) {
            if (substr($cpfDigits, $start, $length) === $pin) {
                return [false, 'pin_too_weak', 'Não use parte do seu CPF como PIN.'];
            }
        }
    }

    // H4: padrões de data (ddmmyy ou ddmmyyyy quando length=8). Ataques
    // óbvios: aniversários (010199), casamentos (121225 = 12/12/25), etc.
    if ($length === 6 && preg_match('/^(\d{2})(\d{2})(\d{2})$/', $pin, $m)) {
        $dd = (int)$m[1]; $mm = (int)$m[2]; $yy = (int)$m[3];
        // yy < 60 → 2000+yy; yy >= 60 → 1900+yy (heurística para data plausível)
        $yyyy = ($yy < 60) ? 2000 + $yy : 1900 + $yy;
        if (checkdate($mm, $dd, $yyyy)) {
            return [false, 'pin_too_weak', 'Não use sua data de nascimento ou outras datas óbvias.'];
        }
    }
    if ($length === 8 && preg_match('/^(\d{2})(\d{2})(\d{4})$/', $pin, $m)) {
        $dd = (int)$m[1]; $mm = (int)$m[2]; $yyyy = (int)$m[3];
        if ($yyyy >= 1900 && $yyyy <= 2100 && checkdate($mm, $dd, $yyyy)) {
            return [false, 'pin_too_weak', 'Não use sua data de nascimento ou outras datas óbvias.'];
        }
    }

    return [true, '', ''];
}

function pin_hash(string $pin): string {
    return password_hash($pin, PASSWORD_BCRYPT, ['cost' => 10]);
}

function pin_verify(string $pin, string $hash): bool {
    if ($hash === '' || $pin === '') return false;
    return password_verify($pin, $hash);
}

/**
 * Persiste novo PIN para um colaborador e invalida qualquer PIN antigo.
 * Retorna o PIN em texto claro (exibir UMA vez ao usuário).
 *
 * Se $customPin for fornecido, usa-o (já assumindo que passou por
 * pin_validate_strength); caso contrário, gera um aleatório.
 */
function pin_set_for_teacher(PDO $pdo, int $teacherId, ?int $length = null, ?string $customPin = null): string {
    $length = $length ?? (int)(get_setting('pin_length', '6') ?? '6');
    $pin = ($customPin !== null && $customPin !== '') ? $customPin : pin_generate($length);
    $hash = pin_hash($pin);
    $st = $pdo->prepare("UPDATE teachers SET pin_hash = ?, pin_changed_at = NOW() WHERE id = ?");
    $st->execute([$hash, $teacherId]);
    return $pin;
}

/**
 * Calcula hash estável do dispositivo combinando user-agent + fingerprint do cliente.
 * IP é deliberadamente EXCLUÍDO da chave porque em redes móveis (4G) o IP público
 * do carrier muda entre sessões — incluí-lo derrubaria a confiabilidade do device
 * toda vez que o usuário alterna wifi/4G. O IP continua registrado em attendance.ip
 * e auth_attempt_logs.ip_address para auditoria e rate-limit.
 *
 * Parâmetro $ip mantido na assinatura por compatibilidade; ignorado de propósito.
 */
function trusted_device_fingerprint(string $ua, string $ip, string $clientFp = ''): string {
    unset($ip);
    return substr(hash('sha256', $ua . '|' . $clientFp), 0, 64);
}

// ============================================================================
// DEVICE TOKEN (COOKIE HTTPONLY)
//
// Identidade primária do dispositivo: token aleatório emitido pelo servidor,
// salvo em teacher_trusted_devices.device_token e entregue ao cliente como
// cookie httpOnly `ponto_device`. Sobrevive à limpeza de cache do navegador
// (cookies só são removidos quando o usuário explicitamente limpa cookies).
//
// O fingerprint calculado client-side continua como fallback para quando o
// cookie ainda não foi estabelecido (primeira vez que o backend vê o device).
// ============================================================================
const DEVICE_COOKIE_NAME = 'ponto_device';
const DEVICE_COOKIE_TTL  = 180 * 86400; // 180 dias

function generate_device_token(): string {
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return bin2hex(openssl_random_pseudo_bytes(32));
    }
}

/**
 * Detecta se a request veio por HTTPS, considerando proxy reverso.
 * Trust X-Forwarded-Proto APENAS se IP do proxy for confiável (assumimos rede
 * privada do hosting; ajustar se houver caso público).
 */
function is_https_request(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($fwd === 'https') return true;
    // Hosting alguns mandam isso (Cloudflare, AWS ELB, Heroku):
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return false;
}

function trusted_device_read_cookie(): ?string {
    $v = $_COOKIE[DEVICE_COOKIE_NAME] ?? '';
    if (!is_string($v) || $v === '') return null;
    $v = preg_replace('/[^0-9a-f]/', '', strtolower($v));
    return (strlen($v) === 64) ? $v : null;
}

/**
 * Define o cookie do device. Retorna true se conseguiu chamar setcookie,
 * false se headers já foram enviados (browser não vai persistir, mas a
 * gravação no DB já aconteceu — fallback fingerprint na próxima request
 * mantém o usuário trustado).
 */
function trusted_device_set_cookie(string $token): bool {
    if (headers_sent()) return false;
    $ok = setcookie(DEVICE_COOKIE_NAME, $token, [
        'expires'  => time() + DEVICE_COOKIE_TTL,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($ok) $_COOKIE[DEVICE_COOKIE_NAME] = $token;
    return (bool)$ok;
}

function trusted_device_clear_cookie(): bool {
    if (headers_sent()) return false;
    $ok = setcookie(DEVICE_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[DEVICE_COOKIE_NAME]);
    return (bool)$ok;
}

/**
 * Verifica se o dispositivo é conhecido para este colaborador.
 * Prioriza o cookie server-issued (sobrevive à limpeza de cache do browser);
 * cai para o fingerprint legacy quando o cookie ainda não foi estabelecido.
 */
function trusted_device_is_known(PDO $pdo, int $teacherId, string $fingerprint): bool {
    if ($teacherId <= 0) return false;
    $inactiveDays = (int)(get_setting('trusted_device_inactive_days', '90') ?? '90');

    // H5: ambos os caminhos (cookie e fingerprint) verificam que o teacher
    // ainda está ATIVO. Cobre demissão / desativação — cookie roubado de
    // funcionário desativado deixa de ser trusted imediatamente.

    // 1) Match por cookie (identidade primária, não depende do client-side state).
    $token = trusted_device_read_cookie();
    if ($token !== null) {
        $st = $pdo->prepare("
            SELECT ttd.id FROM teacher_trusted_devices ttd
              JOIN teachers t ON t.id = ttd.teacher_id
             WHERE ttd.teacher_id = ?
               AND ttd.device_token = ?
               AND ttd.is_active = 1
               AND t.active = 1
               AND (ttd.last_used_at IS NULL OR ttd.last_used_at >= DATE_SUB(NOW(), INTERVAL ? DAY))
             LIMIT 1
        ");
        $st->execute([$teacherId, $token, $inactiveDays]);
        if ($st->fetchColumn()) return true;
    }

    // 2) Fallback: match por fingerprint client-side (cookie não estabelecido
    //    ainda — primeira entrada pós-step-up) ou perda do cookie.
    if ($fingerprint === '') return false;
    $st = $pdo->prepare("
        SELECT ttd.id FROM teacher_trusted_devices ttd
          JOIN teachers t ON t.id = ttd.teacher_id
         WHERE ttd.teacher_id = ?
           AND ttd.device_fingerprint = ?
           AND ttd.is_active = 1
           AND t.active = 1
           AND (ttd.last_used_at IS NULL OR ttd.last_used_at >= DATE_SUB(NOW(), INTERVAL ? DAY))
         LIMIT 1
    ");
    $st->execute([$teacherId, $fingerprint, $inactiveDays]);
    return (bool)$st->fetchColumn();
}

/**
 * Atualiza last_used_at do registro do dispositivo. Se encontrar match pelo
 * cookie, renova o TTL do cookie para sliding window de 180 dias.
 */
function trusted_device_touch(PDO $pdo, int $teacherId, string $fingerprint): void {
    try {
        $token = trusted_device_read_cookie();
        if ($token !== null && $teacherId > 0) {
            $st = $pdo->prepare("
                UPDATE teacher_trusted_devices
                   SET last_used_at = NOW(), is_active = 1
                 WHERE teacher_id = ? AND device_token = ?
            ");
            $st->execute([$teacherId, $token]);
            if ($st->rowCount() > 0) {
                // Renova TTL do cookie (sliding session).
                trusted_device_set_cookie($token);
                return;
            }
        }
        // Fallback: toca pelo fingerprint (backward compat).
        if ($fingerprint !== '' && $teacherId > 0) {
            $st = $pdo->prepare("
                UPDATE teacher_trusted_devices
                   SET last_used_at = NOW(), is_active = 1
                 WHERE teacher_id = ? AND device_fingerprint = ?
            ");
            $st->execute([$teacherId, $fingerprint]);
        }
    } catch (Throwable $e) { /* não interrompe fluxo */ }
}

/**
 * Cadastra/reativa o dispositivo como confiável. Emite token server-issued
 * (se ainda não houver) e entrega ao cliente via cookie httpOnly.
 *
 * Fluxos:
 *  - Cliente tem cookie → reativa o registro correspondente, atualiza
 *    fingerprint e renova cookie.
 *  - Sem cookie mas existe registro por fingerprint → reativa, emite novo
 *    token e define cookie.
 *  - Nenhum match → INSERT novo (com poda de limite por teacher).
 */
function trusted_device_enroll(PDO $pdo, int $teacherId, string $fingerprint, string $method = 'repeated_use', ?string $label = null): bool {
    if ($teacherId <= 0) return false;
    if (!in_array($method, ['face_validated', 'repeated_use', 'admin'], true)) {
        $method = 'repeated_use';
    }

    try {
        $existingToken = trusted_device_read_cookie();

        // 1) Atualiza registro pelo cookie existente (device já conhecido).
        if ($existingToken !== null) {
            $st = $pdo->prepare("SELECT id FROM teacher_trusted_devices
                                  WHERE teacher_id = ? AND device_token = ? LIMIT 1");
            $st->execute([$teacherId, $existingToken]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $up = $pdo->prepare("
                    UPDATE teacher_trusted_devices
                       SET is_active = 1,
                           last_used_at = NOW(),
                           enrollment_method = ?,
                           device_fingerprint = ?
                     WHERE id = ?
                ");
                $up->execute([$method, $fingerprint, (int)$row['id']]);
                // Renova cookie (sliding TTL).
                trusted_device_set_cookie($existingToken);
                return true;
            }
            // Cookie não bate com nenhum registro deste teacher — trata como
            // device novo: emitiremos token fresh abaixo.
        }

        // 2) Migration path: registro existe por fingerprint mas sem token.
        if ($fingerprint !== '') {
            $st = $pdo->prepare("SELECT id, device_token FROM teacher_trusted_devices
                                  WHERE teacher_id = ? AND device_fingerprint = ? LIMIT 1");
            $st->execute([$teacherId, $fingerprint]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $token = !empty($row['device_token']) ? (string)$row['device_token'] : generate_device_token();
                $up = $pdo->prepare("
                    UPDATE teacher_trusted_devices
                       SET is_active = 1,
                           last_used_at = NOW(),
                           enrollment_method = ?,
                           device_token = ?
                     WHERE id = ?
                ");
                $up->execute([$method, $token, (int)$row['id']]);
                trusted_device_set_cookie($token);
                return true;
            }
        }

        // 3) Poda: mantém no máximo N ativos; desativa o mais antigo.
        $max = max(1, (int)(get_setting('trusted_device_max_per_teacher', '5') ?? '5'));
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM teacher_trusted_devices WHERE teacher_id = ? AND is_active = 1");
        $cnt->execute([$teacherId]);
        $active = (int)$cnt->fetchColumn();
        while ($active >= $max) {
            $del = $pdo->prepare("
                UPDATE teacher_trusted_devices
                   SET is_active = 0
                 WHERE teacher_id = ? AND is_active = 1
                 ORDER BY COALESCE(last_used_at, enrolled_at) ASC
                 LIMIT 1
            ");
            $del->execute([$teacherId]);
            $active--;
        }

        // 4) Emite token novo e insere registro.
        $token = generate_device_token();
        $ins = $pdo->prepare("
            INSERT INTO teacher_trusted_devices
                (teacher_id, device_fingerprint, device_token, label, enrollment_method, last_used_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([$teacherId, (string)$fingerprint, $token, $label, $method]);
        trusted_device_set_cookie($token);
        return true;
    } catch (Throwable $e) {
        error_log("trusted_device_enroll failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Quando não há face cadastrada, contabiliza 3 check-ins bem-sucedidos no mesmo fingerprint
 * em até 7 dias e promove a "trusted device" automaticamente.
 *
 * IMPORTANTE: `attendance.device_fingerprint` armazena o FP CRU vindo do cliente
 * (ex: "ponto_81edebd3") enquanto `teacher_trusted_devices.device_fingerprint`
 * armazena o HASH SHA-256 de "UA|clientFp" (ex: 64 hex chars). Por isso esta
 * função recebe AMBOS os formatos:
 *   - $hashedFingerprint: usado para checar/inserir em teacher_trusted_devices
 *   - $rawFingerprint: usado para buscar uso repetido em attendance
 * Sem essa distinção, a query em attendance nunca encontra match e o
 * auto-enroll por repetição nunca dispara.
 */
function trusted_device_consider_repeat_enroll(PDO $pdo, int $teacherId, string $hashedFingerprint, string $rawFingerprint = ''): void {
    if ($teacherId <= 0 || $hashedFingerprint === '') return;
    if (trusted_device_is_known($pdo, $teacherId, $hashedFingerprint)) return;
    $threshold = max(2, (int)(get_setting('trusted_device_repeat_threshold', '3') ?? '3'));
    $windowDays = max(1, (int)(get_setting('trusted_device_repeat_window_days', '7') ?? '7'));
    // Compat: se chamador legado não passar o raw, cai no comportamento anterior
    // (que provavelmente vai retornar 0 matches, mas pelo menos não quebra).
    $lookupFp = $rawFingerprint !== '' ? $rawFingerprint : $hashedFingerprint;
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM attendance
             WHERE teacher_id = ? AND device_fingerprint = ?
               AND check_in >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $st->execute([$teacherId, $lookupFp, $windowDays]);
        $count = (int)$st->fetchColumn();
        if ($count >= $threshold) {
            trusted_device_enroll($pdo, $teacherId, $hashedFingerprint, 'repeated_use');
        }
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Avalia gatilhos de segurança adaptativa. Retorna array de motivos ("new_device", "geo_out", etc.)
 * ou vazio se o registro pode ser aprovado direto.
 */
function pin_stepup_reasons(PDO $pdo, int $teacherId, string $fingerprint, array $ctx): array {
    // Política simplificada: face só é exigida em DOIS cenários reais de risco.
    //   1. Aparelho novo (cookie/fingerprint não bate com trusted_devices).
    //   2. 3+ falhas de PIN em 15 minutos (suspeita de brute force / roubo).
    // Outros sinais (geofence, GPS mock, recovery recente) são registrados
    // como flags em pending_reasons no attendance, mas NÃO disparam step-up
    // facial — reduzem fricção do usuário leigo no fluxo feliz.
    //
    // $ctx pode trazer geo_out / gps_mock — ignorados aqui mas mantidos na
    // assinatura para que o caller continue passando dados de auditoria.
    $reasons = [];
    if (!trusted_device_is_known($pdo, $teacherId, $fingerprint)) {
        $reasons[] = 'new_device';
    }
    try {
        $ident = $ctx['identifier'] ?? '';
        if ($ident !== '') {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM auth_attempt_logs
                 WHERE attempt_type = 'pin'
                   AND identifier = ?
                   AND success = 0
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $st->execute([$ident]);
            if ((int)$st->fetchColumn() >= 3) {
                $reasons[] = 'recent_failures';
            }
        }
    } catch (Throwable $e) { /* ignore */ }
    return array_values(array_unique($reasons));
}

/**
 * Mensagem amigável para o colaborador explicando por que o sistema pediu face.
 */
function pin_stepup_reason_message(array $reasons): string {
    // Apenas dois motivos disparam step-up agora (ver pin_stepup_reasons).
    if (in_array('new_device', $reasons, true)) {
        return 'Percebemos que você está usando um aparelho novo. Vamos confirmar com sua foto — é rápido.';
    }
    if (in_array('recent_failures', $reasons, true)) {
        return 'Por segurança, vamos confirmar sua identidade com uma foto.';
    }
    return 'Vamos confirmar sua identidade com uma foto.';
}

/**
 * Consolida registros de attendance em uma estrutura agrupada por dia e
 * colaborador, para exibição. Hoje o banco grava cada par check-in/check-out
 * como linha separada (work + N breaks), o que faz a UI mostrar várias
 * "linhas de ponto" quando o colaborador teve múltiplos intervalos no dia.
 * Esta função consolida tudo num único item por (date, teacher_id).
 *
 * Entrada: array de rows da query SELECT * FROM attendance (com os campos
 * id, teacher_id, date, check_in, check_out, record_type, parent_attendance_id,
 * sequence_number, teacher_name, ...). Aceita campos extras — preserva no raw_rows.
 *
 * Saída: array de dias consolidados, cada um com:
 *   - date, teacher_id, teacher_name
 *   - check_in (HH:MM:SS, primeiro work), check_out (HH:MM:SS, último work, ou null)
 *   - breaks: lista [{start, end, duration_minutes, raw_id}] ordenada por start
 *   - total_break_minutes, total_worked_minutes
 *   - status: 'closed' | 'in_progress' | 'orphan'
 *   - raw_rows: registros originais (preserva referências p/ edição/auditoria)
 *
 * Edge cases tratados:
 *   - Work em aberto (check_out=null) → status='in_progress'
 *   - Break em aberto → end=null, não soma duração
 *   - Break órfão (sem work) → status='orphan'
 *   - 2+ works no dia → gap entre eles vira break inferido com raw_id=null
 */
function consolidate_attendance_by_day(array $rows): array {
    // Agrupar por (date, teacher_id)
    $groups = [];
    foreach ($rows as $r) {
        $date = $r['date'] ?? null;
        $tid  = $r['teacher_id'] ?? null;
        if ($date === null || $tid === null) continue;
        $key = $date . '_' . $tid;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'date' => $date,
                'teacher_id' => (int)$tid,
                'teacher_name' => $r['teacher_name'] ?? null,
                'rows' => [],
            ];
        }
        $groups[$key]['rows'][] = $r;
    }

    $extractTime = function(?string $dt): ?string {
        if ($dt === null || $dt === '') return null;
        // Aceita 'YYYY-MM-DD HH:MM:SS' ou só 'HH:MM:SS'
        if (strlen($dt) >= 19 && strpos($dt, ' ') !== false) {
            return substr($dt, 11, 8);
        }
        if (strlen($dt) === 8 && substr_count($dt, ':') === 2) {
            return $dt;
        }
        // fallback: tenta parse
        $ts = strtotime($dt);
        return $ts ? date('H:i:s', $ts) : null;
    };

    $diffMinutes = function(string $startDt, string $endDt): int {
        // Aceita datetime completo ou só hora.
        // Se vier só hora, normaliza para uma data base comum para suportar
        // intervalos que cruzam meia-noite (raro neste contexto, mas defensivo).
        $normalize = function(string $s): string {
            if (strlen($s) >= 19 && strpos($s, ' ') !== false) return $s;
            return '2000-01-01 ' . $s;
        };
        $a = strtotime($normalize($startDt));
        $b = strtotime($normalize($endDt));
        if ($a === false || $b === false) return 0;
        $diff = ($b - $a) / 60;
        // Se diff negativo (cruzou meia-noite c/ só hora), adiciona 1 dia
        if ($diff < 0) $diff += 24 * 60;
        return (int)round($diff);
    };

    $result = [];
    foreach ($groups as $g) {
        $works = [];
        $breaks = [];
        foreach ($g['rows'] as $r) {
            $type = $r['record_type'] ?? 'work';
            if ($type === 'break') {
                $breaks[] = $r;
            } else {
                $works[] = $r;
            }
        }

        // Ordenar works por check_in
        usort($works, function($a, $b) {
            return strcmp((string)($a['check_in'] ?? ''), (string)($b['check_in'] ?? ''));
        });

        $status = 'closed';
        $checkInTime = null;
        $checkOutTime = null;
        $checkInRaw = null;
        $checkOutRaw = null;

        if (count($works) > 0) {
            $firstWork = $works[0];
            $lastWork = $works[count($works) - 1];
            $checkInTime = $extractTime($firstWork['check_in'] ?? null);
            $checkOutTime = $extractTime($lastWork['check_out'] ?? null);
            $checkInRaw = $firstWork['check_in'] ?? null;
            $checkOutRaw = $lastWork['check_out'] ?? null;

            // Algum work sem check_out → in_progress
            foreach ($works as $w) {
                if (empty($w['check_out'])) { $status = 'in_progress'; break; }
            }

            // Gap entre 2+ works vira break inferido APENAS se nenhum break real
            // ja cobre essa janela. Sem essa verificacao, o fluxo padrao do
            // sistema (work#1 08-11, break 11-14, work#2 14-16) cria um break
            // duplicado (real + inferido), inflando total_break_minutes.
            for ($i = 0; $i < count($works) - 1; $i++) {
                $gapStartRaw = $works[$i]['check_out'] ?? null;
                $gapEndRaw   = $works[$i+1]['check_in'] ?? null;
                if (!$gapStartRaw || !$gapEndRaw) continue;
                $covered = false;
                foreach ($breaks as $existingBreak) {
                    $bIn  = $existingBreak['check_in']  ?? null;
                    $bOut = $existingBreak['check_out'] ?? null;
                    if ($bIn === null || $bOut === null) continue;
                    // Overlap: existingBreak.start <= gap.end AND existingBreak.end >= gap.start
                    if ($bIn <= $gapEndRaw && $bOut >= $gapStartRaw) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    $breaks[] = [
                        'id' => null,
                        'check_in' => $gapStartRaw,
                        'check_out' => $gapEndRaw,
                        'record_type' => 'break',
                        '_inferred' => true,
                    ];
                }
            }
        } else {
            // Só breaks, sem work → orphan
            $status = 'orphan';
        }

        // Algum break em aberto também marca o dia como in_progress (só
        // se ainda estava closed — orphan tem prioridade menor que isto?
        // Mantemos orphan se não há work; in_progress só faz sentido com work).
        if ($status === 'closed') {
            foreach ($breaks as $b) {
                if (empty($b['check_out'])) { $status = 'in_progress'; break; }
            }
        }

        // Montar breaks consolidados (ordenados, com duração)
        $consolidatedBreaks = [];
        foreach ($breaks as $b) {
            $startRaw = $b['check_in'] ?? null;
            $endRaw   = $b['check_out'] ?? null;
            $duration = null;
            if ($startRaw && $endRaw) {
                $duration = $diffMinutes($startRaw, $endRaw);
            }
            $consolidatedBreaks[] = [
                'start' => $extractTime($startRaw),
                'end'   => $extractTime($endRaw),
                'duration_minutes' => $duration,
                'raw_id' => $b['id'] ?? null,
            ];
        }
        usort($consolidatedBreaks, function($a, $b) {
            return strcmp((string)$a['start'], (string)$b['start']);
        });

        $totalBreakMin = 0;
        foreach ($consolidatedBreaks as $b) {
            if ($b['duration_minutes'] !== null) $totalBreakMin += $b['duration_minutes'];
        }

        $totalWorkedMin = 0;
        if ($checkInRaw && $checkOutRaw) {
            $span = $diffMinutes($checkInRaw, $checkOutRaw);
            $totalWorkedMin = max(0, $span - $totalBreakMin);
        }

        $result[] = [
            'date' => $g['date'],
            'teacher_id' => $g['teacher_id'],
            'teacher_name' => $g['teacher_name'],
            'check_in' => $checkInTime,
            'check_out' => $checkOutTime,
            'breaks' => $consolidatedBreaks,
            'total_break_minutes' => $totalBreakMin,
            'total_worked_minutes' => $totalWorkedMin,
            'status' => $status,
            'raw_rows' => $g['rows'],
        ];
    }

    return $result;
}

/**
 * Formata duração em minutos como "Xh YYm" (ou "YYm" se < 1h, "Xh" se exato).
 */
function format_duration_minutes(?int $minutes): string {
    if ($minutes === null) return '—';
    if ($minutes < 0) $minutes = 0;
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) return $m . 'm';
    if ($m === 0) return $h . 'h';
    return sprintf('%dh %02dm', $h, $m);
}

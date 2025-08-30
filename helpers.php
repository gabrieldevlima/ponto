<?php
declare(strict_types=1);

function esc($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X-CSRF-TOKEN'] ?? $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!$token || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
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
 * Log de auditoria.
 */
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
        // Não interromper fluxo em caso de falha no log
    }
}

/**
 * Login de admin.
 */
function admin_login(string $username, string $password): bool {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, username, password_hash, role, school_id FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($password, $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_username'] = $row['username'];
        $_SESSION['admin_name'] = $row['username'];
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
          password_hash VARCHAR(255) NOT NULL,
          role ENUM('network_admin','school_admin') NOT NULL DEFAULT 'network_admin',
          school_id INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        if ($count === 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?, ?, 'network_admin')");
            $stmt->execute(['admin', $hash]);
        }
    } catch (Throwable $e) {
        // Ignora em ambientes onde não pode criar tabela automaticamente
    }
}

/**
 * Key-Value settings.
 */
function get_setting(string $key, $default = null): ?string {
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT v FROM app_settings WHERE k = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v !== false ? (string)$v : ($default === null ? null : (string)$default);
    } catch (Throwable $e) {
        return $default === null ? null : (string)$default;
    }
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
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT allow FROM permissions WHERE role = ? AND perm_key = ?");
        $st->execute([$adm['role'], $permKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return true;
        return (int)$row['allow'] === 1;
    } catch (Throwable $e) {
        return true;
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
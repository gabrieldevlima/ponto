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
        echo json_encode(['status'=>'error','message'=>'CSRF token inválido']);
        exit;
    }
}
function require_admin() {
    if (empty($_SESSION['admin'])) {
        header('Location: login.php');
        exit;
    }
}
function is_admin_logged() {
    return !empty($_SESSION['admin']);
}

function admin_login(string $username, string $password): bool {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, password_hash FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['admin'] = true; // garante compatibilidade com require_admin/is_admin_logged
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_name'] = $username;
        return true;
    }
    return false;
}

function admin_logout(): void {
    unset($_SESSION['admin'], $_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_name']);
}

function ensure_default_admin(): void {
    $pdo = db();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    if ($count === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES (?, ?)");
        $stmt->execute(['admin', $hash]);
    }
}

/**
 * Distância Euclidiana entre dois vetores.
 * @param float[] $a
 * @param float[] $b
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
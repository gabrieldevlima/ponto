<?php
// Versão de debug - remove após identificar o problema
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Debug Reset PIN</title></head><body>";
echo "<h1>Debug: Reset de PIN</h1>";

echo "<p>1. ✓ Iniciando script...</p>";

try {
    require_once __DIR__ . '/../../config.php';
    echo "<p>2. ✓ Config.php carregado</p>";
} catch (Throwable $e) {
    echo "<p>2. ✗ Erro ao carregar config: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

try {
    require_admin();
    echo "<p>3. ✓ Autenticação verificada (require_admin)</p>";
} catch (Throwable $e) {
    echo "<p>3. ✗ Erro na autenticação: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Redirecionando para login...</p>";
    exit;
}

try {
    $pdo = db();
    echo "<p>4. ✓ Conexão com banco obtida</p>";
} catch (Throwable $e) {
    echo "<p>4. ✗ Erro ao conectar banco: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

try {
    $admin = current_admin($pdo);
    echo "<p>5. ✓ Admin atual: " . htmlspecialchars($admin['username'] ?? 'N/A') . "</p>";
} catch (Throwable $e) {
    echo "<p>5. ✗ Erro ao buscar admin: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
echo "<p>6. ID do colaborador: " . $id . "</p>";

if (!$id) {
    echo "<p>6. ✗ ID não informado</p>";
    exit;
}

try {
    list($scopeSql, $scopeParams) = admin_scope_where('t');
    echo "<p>7. ✓ Escopo SQL: " . htmlspecialchars($scopeSql) . "</p>";
    echo "<p>7. ✓ Params: " . implode(', ', $scopeParams) . "</p>";
} catch (Throwable $e) {
    echo "<p>7. ✗ Erro ao obter escopo: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT t.* FROM teachers t WHERE t.id = ? AND $scopeSql");
    $stmt->execute(array_merge([$id], $scopeParams));
    $teacher = $stmt->fetch();
    
    if ($teacher) {
        echo "<p>8. ✓ Colaborador encontrado: " . htmlspecialchars($teacher['name']) . "</p>";
    } else {
        echo "<p>8. ✗ Colaborador não encontrado ou sem permissão</p>";
        exit;
    }
} catch (Throwable $e) {
    echo "<p>8. ✗ Erro ao buscar colaborador: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT pin_hash FROM teachers WHERE pin_hash IS NOT NULL AND id <> ?");
    $stmt->execute([$id]);
    $existingHashes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>9. ✓ Hashes existentes carregados: " . count($existingHashes) . " PINs</p>";
} catch (Throwable $e) {
    echo "<p>9. ✗ Erro ao carregar hashes: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

try {
    // Testa geração de PIN
    $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
    echo "<p>10. ✓ PIN gerado: " . htmlspecialchars($pin) . "</p>";
} catch (Throwable $e) {
    echo "<p>10. ✗ Erro ao gerar PIN: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

try {
    // Testa update (comentado para não alterar de verdade)
    // $pdo->prepare("UPDATE teachers SET pin_hash = ? WHERE id = ?")->execute([$pin_hash, $id]);
    echo "<p>11. ✓ Update SQL (não executado, apenas teste)</p>";
} catch (Throwable $e) {
    echo "<p>11. ✗ Erro no update: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

echo "<hr>";
echo "<h2 style='color: green;'>✓ TUDO OK! O arquivo deveria funcionar.</h2>";
echo "<p><a href='teacher_pin_reset.php?id=" . $id . "'>Testar versão real</a></p>";
echo "<p><a href='teachers.php'>Voltar para colaboradores</a></p>";
echo "</body></html>";
?>





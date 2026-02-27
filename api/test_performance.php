<?php
/**
 * SCRIPT DE TESTE DE PERFORMANCE
 * Executa cada etapa do registro de ponto e mede o tempo
 */

header('Content-Type: application/json; charset=utf-8');

$inicio_total = microtime(true);
$tempos = [];

// 1. Carregar config
$t1 = microtime(true);
require_once __DIR__ . '/../config.php';
$tempos['1_config'] = round((microtime(true) - $t1) * 1000, 2) . 'ms';

// 2. Testar conexão com banco
$t2 = microtime(true);
try {
    $pdo = db();
    $pdo->query("SELECT 1")->fetch();
    $tempos['2_conexao_banco'] = round((microtime(true) - $t2) * 1000, 2) . 'ms';
} catch (Exception $e) {
    $tempos['2_conexao_banco'] = 'ERRO: ' . $e->getMessage();
}

// 3. Testar query de validação de PIN (simulado)
$t3 = microtime(true);
try {
    $stmt = $pdo->query("SELECT id, name, pin_hash, active, network_wide FROM teachers WHERE active = 1 LIMIT 10");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tempos['3_query_teachers'] = round((microtime(true) - $t3) * 1000, 2) . 'ms';
    $tempos['3_teachers_encontrados'] = count($teachers);
} catch (Exception $e) {
    $tempos['3_query_teachers'] = 'ERRO: ' . $e->getMessage();
}

// 4. Testar validação de PIN (bcrypt)
if (!empty($teachers)) {
    $t4 = microtime(true);
    $pin_teste = '123456';
    $verificacoes = 0;
    foreach ($teachers as $teacher) {
        password_verify($pin_teste, $teacher['pin_hash']);
        $verificacoes++;
        if ($verificacoes >= 5) break; // Testar apenas 5
    }
    $tempos['4_password_verify_5x'] = round((microtime(true) - $t4) * 1000, 2) . 'ms';
}

// 5. Testar get_setting
$t5 = microtime(true);
$setting1 = get_setting('geofence_radius_m', '300');
$setting2 = get_setting('tolerance_minutes', '5');
$tempos['5_get_setting_2x'] = round((microtime(true) - $t5) * 1000, 2) . 'ms';

// 6. Testar teacher_uses_period_system
if (!empty($teachers)) {
    $t6 = microtime(true);
    $uses_period = teacher_uses_period_system($teachers[0]['id']);
    $tempos['6_teacher_uses_period'] = round((microtime(true) - $t6) * 1000, 2) . 'ms';
}

// 7. Testar get_teacher_allowed_schools
if (!empty($teachers)) {
    $t7 = microtime(true);
    $schools = get_teacher_allowed_schools($pdo, $teachers[0]['id']);
    $tempos['7_get_allowed_schools'] = round((microtime(true) - $t7) * 1000, 2) . 'ms';
    $tempos['7_schools_encontradas'] = count($schools);
}

// 8. Testar INSERT simulado (com NSR)
$t8 = microtime(true);
try {
    $pdo->beginTransaction();
    $stmtNsr = $pdo->query("SELECT COALESCE(MAX(nsr), 0) + 1 as next_nsr FROM attendance FOR UPDATE");
    $nextNsr = (int)$stmtNsr->fetchColumn();
    $pdo->rollBack(); // Não vamos realmente inserir
    $tempos['8_gerar_nsr'] = round((microtime(true) - $t8) * 1000, 2) . 'ms';
    $tempos['8_proximo_nsr'] = $nextNsr;
} catch (Exception $e) {
    $tempos['8_gerar_nsr'] = 'ERRO: ' . $e->getMessage();
}

// 9. Testar audit_log
$t9 = microtime(true);
try {
    // Não vamos realmente gravar, só preparar
    $stmt = $pdo->prepare("SELECT 1");
    $stmt->execute();
    $tempos['9_audit_log_prepare'] = round((microtime(true) - $t9) * 1000, 2) . 'ms';
} catch (Exception $e) {
    $tempos['9_audit_log'] = 'ERRO: ' . $e->getMessage();
}

// 10. Verificar se código está atualizado
$t10 = microtime(true);
$checkin_code = file_get_contents(__DIR__ . '/checkin.php');
$has_transaction = strpos($checkin_code, 'beginTransaction') !== false;
$has_for_update = strpos($checkin_code, 'FOR UPDATE') !== false;
$tempos['10_codigo_atualizado'] = $has_transaction && $has_for_update ? 'SIM' : 'NAO';
$tempos['10_verificacao'] = round((microtime(true) - $t10) * 1000, 2) . 'ms';

$tempo_total = round((microtime(true) - $inicio_total) * 1000, 2);

echo json_encode([
    'status' => 'ok',
    'tempo_total_ms' => $tempo_total,
    'tempo_total_s' => round($tempo_total / 1000, 2),
    'detalhes' => $tempos,
    'diagnostico' => [
        'codigo_php_atualizado' => $tempos['10_codigo_atualizado'],
        'banco_conectado' => isset($tempos['2_conexao_banco']) && !str_contains($tempos['2_conexao_banco'], 'ERRO'),
        'queries_funcionando' => isset($tempos['3_query_teachers']) && !str_contains($tempos['3_query_teachers'], 'ERRO')
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);


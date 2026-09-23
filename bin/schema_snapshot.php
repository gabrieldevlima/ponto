<?php
/**
 * Gera o snapshot do SCHEMA de produção que serve de base para o CI.
 *
 * Por que existe: montar o banco de teste do zero, rodando as 73 migrações em
 * ordem alfabética, não reproduz a produção. As migrações legadas (sem data no
 * nome) rodaram lá ANTES das datadas, mas o alfabeto as põe depois; uma coluna
 * era removida e recriada em momentos que o nome do arquivo não conta; e tabela
 * nova no MariaDB 11.8 nasce em outra collation. O CI passava a acusar dezenas
 * de falhas que não existem em produção — e com isso escondia as que existem.
 *
 * Com o snapshot, o CI parte do schema REAL de produção e roda só as migrações
 * que ainda não foram aplicadas lá: testa exatamente o próximo deploy.
 *
 * O que SAI daqui: DDL de tabelas, views e rotinas, e as linhas de
 * `applied_migrations` (nome do arquivo, hash, datas) — para o runner saber o
 * que pular. NENHUMA linha de tabela de negócio. Os dados de referência que o
 * CI precisa vêm de sql/seeds/ci_reference.sql, que é fictício.
 *
 * Também sai limpo do que só faz sentido em produção:
 *   - DEFINER de views e rotinas (o usuário de produção não existe no CI);
 *   - AUTO_INCREMENT=N (contador de produção, irrelevante e ruidoso no diff).
 *
 * Uso, no servidor de produção:
 *   /opt/alt/php83/usr/bin/php bin/schema_snapshot.php > /tmp/producao.sql
 * e copie o arquivo para sql/schema/producao.sql no repositório.
 *
 * Regenere sempre que uma migração for aplicada em produção — senão o CI volta
 * a rodar como "pendente" algo que já está lá.
 */

require __DIR__ . '/../config.php';

$pdo = db();
$out = fopen('php://stdout', 'w');
$w = function (string $s) use ($out): void { fwrite($out, $s); };

$semDefiner = function (string $sql): string {
    return preg_replace('/\s*DEFINER\s*=\s*`[^`]*`@`[^`]*`/i', '', $sql);
};

$versao = (string)$pdo->query('SELECT VERSION()')->fetchColumn();

$w("-- ============================================================================\n");
$w("-- Snapshot do schema de PRODUÇÃO — base do CI. NÃO EDITE À MÃO.\n");
$w("-- Gerado por bin/schema_snapshot.php em " . gmdate('Y-m-d\TH:i:s\Z') . "\n");
$w("-- Servidor: {$versao}\n");
$w("--\n");
$w("-- Só estrutura e a lista de migrações aplicadas. Nenhum dado de negócio.\n");
$w("-- Regenere quando uma migração for aplicada em produção.\n");
$w("-- ============================================================================\n\n");
$w("SET NAMES utf8mb4;\n");
$w("SET FOREIGN_KEY_CHECKS = 0;\n");
$w("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

// ---------------------------------------------------------------- tabelas
$tabelas = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                        ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$w("-- ---------------------------------------------------------------- tabelas (" . count($tabelas) . ")\n\n");
foreach ($tabelas as $t) {
    $ddl = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM)[1];
    $ddl = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $ddl);
    $w($ddl . ";\n\n");
}

// ---------------------------------------------------------------- rotinas
$rotinas = $pdo->query("SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES
                        WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME")->fetchAll(PDO::FETCH_ASSOC);
if ($rotinas) {
    $w("-- ---------------------------------------------------------------- rotinas (" . count($rotinas) . ")\n\n");
    $w("DELIMITER $$\n\n");
    foreach ($rotinas as $r) {
        $tipo = strtoupper($r['ROUTINE_TYPE']) === 'PROCEDURE' ? 'PROCEDURE' : 'FUNCTION';
        $row = $pdo->query("SHOW CREATE {$tipo} `{$r['ROUTINE_NAME']}`")->fetch(PDO::FETCH_ASSOC);
        $ddl = $row['Create Function'] ?? $row['Create Procedure'] ?? '';
        $w($semDefiner($ddl) . "$$\n\n");
    }
    $w("DELIMITER ;\n\n");
}

// ---------------------------------------------------------------- views
// Ordem por dependência: uma view que lê outra precisa vir depois dela.
$views = $pdo->query("SELECT TABLE_NAME FROM information_schema.VIEWS
                      WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$ddlView = [];
foreach ($views as $v) {
    $ddlView[$v] = $semDefiner($pdo->query("SHOW CREATE VIEW `{$v}`")->fetch(PDO::FETCH_NUM)[1]);
}
$ordem = []; $visitando = [];
$visita = function (string $v) use (&$visita, &$ordem, &$visitando, $ddlView): void {
    if (in_array($v, $ordem, true) || isset($visitando[$v])) return;
    $visitando[$v] = true;
    foreach (array_keys($ddlView) as $outra) {
        if ($outra !== $v && preg_match('/`' . preg_quote($outra, '/') . '`/', $ddlView[$v])) $visita($outra);
    }
    $ordem[] = $v;
};
foreach (array_keys($ddlView) as $v) $visita($v);
if ($ordem) {
    $w("-- ---------------------------------------------------------------- views (" . count($ordem) . ")\n\n");
    foreach ($ordem as $v) $w($ddlView[$v] . ";\n\n");
}

// ----------------------------------------------------- migrações aplicadas
$cols = array_column($pdo->query("SHOW COLUMNS FROM applied_migrations")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$campos = array_values(array_intersect(
    ['filename', 'applied_at', 'content_sha', 'duration_ms', 'statement_count', 'failure_reason'], $cols));
$linhas = $pdo->query('SELECT ' . implode(', ', $campos) . ' FROM applied_migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
$w("-- ---------------------------------------------------- migrações aplicadas (" . count($linhas) . ")\n");
$w("-- O runner compara filename + content_sha para decidir o que pular.\n\n");
if ($linhas) {
    $w('INSERT INTO applied_migrations (' . implode(', ', $campos) . ") VALUES\n");
    $vals = [];
    foreach ($linhas as $l) {
        $vals[] = '  (' . implode(', ', array_map(
            fn($c) => $l[$c] === null ? 'NULL' : $pdo->quote((string)$l[$c]), $campos)) . ')';
    }
    $w(implode(",\n", $vals) . ";\n\n");
}

$w("SET FOREIGN_KEY_CHECKS = 1;\n");

<?php
declare(strict_types=1);

//VARIAVEIS DO BANCO DE DADOS LOCAL
$servidor = 'localhost';
$usuario = 'root';
$senha = '';
$banco = 'u803039033_pto_ribeira';

session_start();

date_default_timezone_set('America/Sao_Paulo');

define('DB_HOST', $servidor);
define('DB_NAME', $banco);
define('DB_USER', $usuario);
define('DB_PASS', $senha);
define('DB_CHARSET', 'utf8mb4');

// ============================================================================
// CONFIGURAÇÕES DE CONFORMIDADE - PORTARIA MTP 671/2021
// ============================================================================
define('SYSTEM_NAME', 'DEEDO Ponto');
define('SYSTEM_VERSION', '1.0.0');
define('REP_CATEGORY', 'REP-P'); // Registrador Eletrônico de Ponto via Programa
define('PORTARIA_671_COMPLIANT', true);
define('LGPD_COMPLIANT', true);

// Ambiente e debug
define('APP_ENV', 'production');  // 'production' ou 'development'
define('APP_DEBUG', false);       // true para incluir dados de debug nas respostas API

// Verificacao de qualidade de foto habilitada por padrao
if (!defined('PHOTO_QUALITY_CHECK_ENABLED')) {
    define('PHOTO_QUALITY_CHECK_ENABLED', true);
}

// Função para obter configurações do empregador do banco
function get_employer_config(): ?array {
    static $config = null;
    if ($config === null) {
        try {
            $pdo = db();
            $stmt = $pdo->query("SELECT * FROM employer_config LIMIT 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Tabela ainda não existe (migration não executada)
            $config = [
                'company_name' => 'NOME DA EMPRESA LTDA',
                'cnpj' => '00.000.000/0000-00',
                'system_name' => SYSTEM_NAME,
                'system_version' => SYSTEM_VERSION,
                'rep_category' => REP_CATEGORY
            ];
        }
    }
    return $config ?: null;
}

// Sincronização com Hora Legal Brasileira (HLB)
define('HLB_NTP_SERVER', 'a.st1.ntp.br'); // Servidor NTP brasileiro
define('HLB_MAX_OFFSET_SECONDS', 120); // Tolerância máxima de 2 minutos
define('HLB_SYNC_INTERVAL_HOURS', 1); // Sincronizar a cada 1 hora

// Comprovante de Ponto Digital
define('RECEIPT_RETENTION_YEARS', 5); // Manter comprovantes por 5 anos
define('RECEIPT_AUTO_GENERATE', true); // Gerar comprovante automaticamente

// ============================================================================
// CONFIGURAÇÕES DE LIMPEZA AUTOMÁTICA DE FOTOS
// ============================================================================
define('PHOTO_RETENTION_DAYS', 90); // Manter fotos por 90 dias (padrão)
define('PHOTO_STORAGE_THRESHOLD_MB', 500); // Executar limpeza se armazenamento > 500MB
define('PHOTO_CLEANUP_ENABLED', true); // Ativar limpeza automática

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

require_once __DIR__ . '/helpers.php';
// Inicializa admin padrao apenas em ambiente de desenvolvimento
if (!defined('APP_ENV') || APP_ENV !== 'production') {
    ensure_default_admin();
}
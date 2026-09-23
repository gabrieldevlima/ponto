<?php
declare(strict_types=1);

//VARIAVEIS DO BANCO DE DADOS LOCAL
$servidor = 'localhost';
$usuario = 'root';
$senha = '';
// Banco importado do dump de produção u803039033_p_oeiras_p.sql (2026-06-09).
// Backup do banco local anterior: 'oeiras_ponto_pd' (preservado no MySQL local).
$banco = 'u803039033_p_oeiras_p';

// ============================================================================
// SEGREDOS E SOBRESCRITAS LOCAIS — config.local.php
//
// Este arquivo (config.php) É VERSIONADO. Nenhum segredo pode viver aqui.
// `config.local.php` está no .gitignore desde sempre e já era citado por
// helpers.kiosk_signing_secret(), mas NUNCA era carregado por ninguém — o
// mecanismo existia só no comentário. Este require é o que o torna real.
//
// Carregado ANTES dos define() de banco para poder sobrescrever $servidor,
// $usuario, $senha e $banco, e ANTES de helpers.php para definir constantes
// de segredo (KIOSK_SIGNING_SECRET, LEDGER_HMAC_KEY, ...).
//
// Modelo em config.local.php.example. Gere a chave do ledger com:
//     php bin/ledger_keygen.php
// ============================================================================
$__localConfig = __DIR__ . '/config.local.php';
if (is_readable($__localConfig)) {
    require_once $__localConfig;
}
unset($__localConfig);

// B1: cookie de sessão persistente (30 dias) alinhado ao TTL do colaborador
// em helpers.is_collaborator_logged. Antes, cookie_lifetime padrão (0) fazia o
// cookie expirar ao fechar o navegador — discrepância com o TTL do DB.
// Precisa vir ANTES de session_start().
if (session_status() !== PHP_SESSION_ACTIVE) {
    $__sessTtl = 30 * 86400; // 30 dias
    // C2: detecta HTTPS atrás de proxy reverso (Cloudflare, Nginx, ELB).
    // Inline aqui porque helpers.php (is_https_request) ainda não foi carregado.
    $__sessSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => $__sessTtl,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $__sessSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Prolonga também o GC para não coletar antes do TTL
    ini_set('session.gc_maxlifetime', (string)$__sessTtl);
}
session_start();

date_default_timezone_set('America/Sao_Paulo');

// ============================================================================
// APP BUILD ID — fonte única da versão do PWA. Lê CACHE_VERSION do sw.js.
// Cliente compara via header X-App-Version e meta tag <meta name="app-build">,
// e força reload (com guards) se detectar mismatch. Garante que PWAs instalados
// não fiquem rodando versão velha indefinidamente.
// ============================================================================
$__buildId = 'unknown';
$__swPath  = __DIR__ . '/public/sw.js';
if (is_readable($__swPath)) {
    $__sw = @file_get_contents($__swPath, false, null, 0, 256); // só os primeiros 256 bytes (CACHE_VERSION está na linha 1)
    if ($__sw && preg_match("/CACHE_VERSION\\s*=\\s*'([^']+)'/", $__sw, $__m)) {
        $__buildId = $__m[1];
    }
}
define('APP_BUILD_ID', $__buildId);
unset($__buildId, $__swPath, $__sw, $__m);

// ============================================================================
// HEADERS DE SEGURANÇA
// ============================================================================
if (!headers_sent()) {
    // X-App-Version: cliente usa pra detectar PWA desatualizado e forçar reload.
    header('X-App-Version: ' . APP_BUILD_ID);
    // NC-47: o HSTS era condicionado a $_SERVER['HTTPS'] === 'on'. Atrás de
    // proxy reverso (Cloudflare, Nginx, ELB) essa variável costuma vir vazia,
    // então o cabeçalho NUNCA era emitido em produção — apesar de a detecção
    // correta já existir 40 linhas acima, usada para o cookie de sessão.
    if ($__sessSecure) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // NC-47: CSP existia APENAS em public/admin/login.php. As páginas com maior
    // superfície — index.php (13 mil linhas), ponto.php, my_timesheet.php e
    // todo o admin — rodavam sem nenhuma. Sem CSP, qualquer XSS que escape do
    // esc() é integralmente explorável.
    //
    // 'unsafe-inline' em script-src é uma concessão consciente: o projeto tem
    // JavaScript inline em praticamente todas as telas, e removê-lo é refatoração
    // ampla. Mesmo assim a política já bloqueia o que mais importa —
    // carregamento de script de origem externa e exfiltração para domínio
    // arbitrário via connect-src.
    if (!defined('SKIP_GLOBAL_CSP')) {
        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "img-src 'self' data: blob:; "
            . "media-src 'self' blob:; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://use.typekit.net; "
            . "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com https://use.typekit.net; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; "
            . "worker-src 'self' blob:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "object-src 'none'"
        );
    }
}

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

// ----------------------------------------------------------------------------
// NC-19 (auditoria de conformidade 2026-08-05): estas flags estavam como `true`
// sem que os requisitos correspondentes existissem no sistema. Declarar
// conformidade que não se sustenta agrava a responsabilidade — o valor era
// impresso no comprovante entregue ao trabalhador e embasaria o Atestado
// Técnico do Anexo VII da Portaria.
//
// Faltam, entre outros: AFD, AEJ, espelho de ponto em leiaute legal, cadeia de
// integridade dos registros, NSR por marcação, sincronização real com a Hora
// Legal Brasileira, e registro de consentimento LGPD no servidor.
//
// Voltar para `true` SOMENTE após concluir as fases 1 a 7 de
// docs/AUDITORIA_CONFORMIDADE_2026-08-05.md e validar o AFD/AEJ em validador
// oficial. Ver docs/AUDITORIA_CONFORMIDADE_2026-08-05.md.
// ----------------------------------------------------------------------------
define('PORTARIA_671_COMPLIANT', false);
define('LGPD_COMPLIANT', false);

// Ambiente e debug
define('APP_ENV', 'production');  // 'production' ou 'development'
define('APP_DEBUG', false);       // true para incluir dados de debug nas respostas API

// Session timeout (segundos)
define('ADMIN_SESSION_TIMEOUT', 7200);       // 2 horas de INATIVIDADE para admins
define('COLLABORATOR_SESSION_TIMEOUT', 3600); // 1 hora para colaboradores

// Validade do "lembrar de mim" do colaborador, em dias (NC-44).
// Era um cookie de 10 anos, sem expiração no banco. 90 dias evita
// reautenticação semanal e garante que um vazamento tenha fim.
define('COLLABORATOR_REMEMBER_DAYS', 90);

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

        // NC-49 (auditoria 2026-08-05): a conexão nunca fixava o fuso.
        // O PHP roda em America/Sao_Paulo, mas NOW() e CURRENT_TIMESTAMP usavam
        // o fuso do SERVIDOR MySQL, que a aplicação não controlava. Como a
        // tabela `attendance` mistura DATETIME (ingênuo, gravado pelo PHP) com
        // TIMESTAMP (convertido pelo MySQL), servidor em UTC produziria
        // registros de ponto com 3 horas de diferença entre colunas da MESMA
        // linha — e nada acusaria.
        //
        // Offset numérico em vez de nome: bancos sem as tabelas de fuso
        // carregadas (comum em hospedagem compartilhada) rejeitam
        // 'America/Sao_Paulo'. -03:00 é o horário de Brasília; o Brasil não
        // adota horário de verão desde 2019.
        try {
            $pdo->exec("SET time_zone = '-03:00'");
        } catch (Throwable $e) {
            error_log('[db] nao foi possivel fixar o time_zone da conexao: ' . $e->getMessage());
        }
    }
    return $pdo;
}

require_once __DIR__ . '/helpers.php';

// Livro fiscal (Portaria MTP 671/2021). Carregado DEPOIS de helpers.php porque
// depende de get_setting/set_setting; helpers.php, por sua vez, o consulta via
// function_exists() para evitar dependência circular.
require_once __DIR__ . '/lib/nsr_ledger.php';

// LGPD: consentimento, criptografia de biometria em repouso, retenção e
// anonimização. Carregado globalmente porque face_descriptors_decode() é usada
// em todo caminho que lê biometria — espalhar requires por sete arquivos seria
// convite a esquecer um e voltar a ler texto plano sem perceber.
require_once __DIR__ . '/lib/lgpd.php';

// Regras de jornada da CLT (adicional noturno, intervalos, tolerância legal).
require_once __DIR__ . '/lib/clt.php';

// Executa migrações pendentes automaticamente ao iniciar
run_auto_migrations();

// Inicializa admin padrao apenas em ambiente de desenvolvimento
if (!defined('APP_ENV') || APP_ENV !== 'production') {
    ensure_default_admin();
}
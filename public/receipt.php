<?php
/**
 * COMPROVANTE DE REGISTRO DE PONTO - PORTARIA MTP 671/2021
 *
 * Documento oficial individual de marcação de ponto, em formato documental
 * tradicional (serifa, hierarquia tipográfica, sem ornamentos de UI).
 * Compatível com Dompdf 3.x e impressão real (A4 portrait, 1 página).
 *
 * ACESSO: Colaboradores via portal my_login.php ou Administradores
 */

require_once __DIR__ . '/../config.php';

$dompdfAvailable = false;
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    $dompdfAvailable = class_exists('Dompdf\\Dompdf');
}

// Verificar autenticação (sessão já foi iniciada em config.php)
// NC-50 (2026-08-05): antes era $_SESSION['admin_logged'], chave que NUNCA é
// definida em lugar nenhum do sistema — a sessão de admin usa 'admin_id'.
// Efeito: $isAdmin era sempre false e nenhum admin conseguia abrir comprovante.
$isAdmin = !empty($_SESSION['admin_id']);
$isCollaborator = isset($_SESSION['collaborator_id']) && $_SESSION['collaborator_id'] > 0;

if (!$isAdmin && !$isCollaborator) {
    http_response_code(403);
    echo '<h3>Acesso negado</h3>';
    echo '<p>Faça login para visualizar o comprovante:</p>';
    echo '<ul>';
    echo '<li><a href="my_login.php">Portal do Colaborador</a></li>';
    echo '<li><a href="admin/login.php">Portal Administrativo</a></li>';
    echo '</ul>';
    exit;
}

// Obter ID do registro
$attendanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attendanceId <= 0) {
    http_response_code(400);
    die('ID de registro inválido.');
}

$pdo = db();

// Buscar dados do registro usando a view
$stmt = $pdo->prepare("SELECT * FROM v_attendance_receipts WHERE id = ?");
$stmt->execute([$attendanceId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    die('Registro não encontrado.');
}

// Verificar permissão.
// NC-46 (2026-08-05): o escopo do admin não era aplicado — a checagem só existia
// para colaborador, então um school_admin lia o comprovante de QUALQUER pessoa
// da rede iterando ?id=. Agora o admin também é limitado por admin_scope_where().
if ($isAdmin) {
    [$scopeSql, $scopeParams] = admin_scope_where('t');
    $stScope = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id = ? AND {$scopeSql} LIMIT 1");
    $stScope->execute(array_merge([(int)$record['teacher_id']], $scopeParams));
    if (!$stScope->fetchColumn()) {
        http_response_code(403);
        die('Acesso negado. Este colaborador está fora do seu escopo.');
    }
} elseif ($isCollaborator) {
    $collaboratorId = (int)($_SESSION['collaborator_id'] ?? 0);
    if ((int)$record['teacher_id'] !== $collaboratorId) {
        http_response_code(403);
        die('Acesso negado. Você só pode visualizar seus próprios comprovantes.');
    }
}

// Marcar como visualizado (apenas para colaboradores, não para admin)
if ($isCollaborator && !$isAdmin) {
    $pdo->prepare("UPDATE attendance SET receipt_viewed_at = NOW() WHERE id = ? AND receipt_viewed_at IS NULL")
        ->execute([$attendanceId]);
}

// Marcar como gerado
$pdo->prepare("UPDATE attendance SET receipt_generated = TRUE WHERE id = ?")
    ->execute([$attendanceId]);

// Formatar dados
$teacherName = $record['teacher_name'] ?? 'Nome não disponível';
$teacherCpf = $record['teacher_cpf'] ?? '';
$nsr = $record['nsr'] ?? 'N/A';
$date = $record['date'] ?? '';
$checkIn = $record['check_in'] ?? null;
$checkOut = $record['check_out'] ?? null;
$action = $record['action'] ?? 'indefinido';

// Detecção contextual de "saída para intervalo" / "retorno do intervalo" para
// registros work. A view v_attendance_receipts retorna apenas os 5 labels
// básicos (entrada, saída, início/retorno do intervalo, indefinido) — fizemos
// isso para evitar o bug 1064 do MariaDB com EXISTS aninhado em CASE WHEN.
// Aqui refinamos quando aplicável.
$recordType = $record['record_type'] ?? 'work';
if ($recordType !== 'break') {
    $teacherIdForCtx = (int)($record['teacher_id'] ?? 0);
    if ($action === 'saída' && $teacherIdForCtx > 0) {
        $stCtx = $pdo->prepare("SELECT 1 FROM attendance WHERE parent_attendance_id = ? AND record_type = 'break' LIMIT 1");
        $stCtx->execute([$attendanceId]);
        if ($stCtx->fetchColumn()) {
            $action = 'saída para intervalo';
        }
    } elseif ($action === 'entrada' && $teacherIdForCtx > 0 && !empty($checkIn) && !empty($date)) {
        $stCtx = $pdo->prepare(
            "SELECT 1 FROM attendance
              WHERE teacher_id = ?
                AND date = ?
                AND record_type = 'break'
                AND check_out IS NOT NULL
                AND check_out <= ?
              LIMIT 1"
        );
        $stCtx->execute([$teacherIdForCtx, $date, $checkIn]);
        if ($stCtx->fetchColumn()) {
            $action = 'retorno do intervalo';
        }
    }
}

// Label legível para exibição. A view v_attendance_receipts retorna o valor cru
// ('entrada', 'saída', 'início do intervalo', 'retorno do intervalo'). Mapeamos
// para uma forma capitalizada uniforme antes de renderizar no comprovante.
$actionDisplayMap = [
    'entrada'                => 'Entrada',
    'saída'                  => 'Saída',
    'saida'                  => 'Saída',
    'saída para intervalo'   => 'Saída para intervalo',
    'saida para intervalo'   => 'Saída para intervalo',
    'início do intervalo'    => 'Início do intervalo',
    'inicio do intervalo'    => 'Início do intervalo',
    'retorno do intervalo'   => 'Retorno do intervalo',
    'indefinido'             => 'Indefinido',
];
$actionDisplay = $actionDisplayMap[mb_strtolower($action, 'UTF-8')] ?? ucfirst($action);
$recordMode = $record['record_mode'] ?? 'online';
$recordedAt = $record['recorded_at'] ?? null;
$syncedAt = $record['synced_at'] ?? null;
$hlbStatus = $record['hlb_sync_status'] ?? 'N/A';
$latitude = $record['latitude'] ?? null;
$longitude = $record['longitude'] ?? null;
$approved = isset($record['approved']) ? (int)$record['approved'] : null;
$deviceId = $record['device_identifier'] ?? 'N/A';

// Dados do empregador
$companyName = $record['company_name'] ?? 'NOME DA EMPRESA LTDA';
$cnpj = $record['cnpj'] ?? '00.000.000/0000-00';
$systemName = $record['system_name'] ?? 'DEEDO Ponto';
$systemVersion = $record['system_version'] ?? '1.0.0';
$repCategory = $record['rep_category'] ?? 'REP-P';

// NC-17 — campos do art. 80 que faltavam no comprovante.
// `rep_category` já era lido antes desta correção e nunca chegava a ser
// renderizado; os demais nem existiam no schema (criados na Fase 0b.5).
// Quando o cadastro ainda não foi preenchido, o comprovante diz "não informado"
// em vez de omitir o campo: a ausência precisa ficar visível, não escondida.
$naoInformado    = 'não informado';
$employerCpf     = $record['employer_cpf'] ?? null;
$employerType    = (int)($record['employer_type'] ?? 1);
$repIdentifier   = $record['rep_identifier'] ?: $naoInformado;
$serviceLocation = $record['service_location'] ?: null;
if (!$serviceLocation) {
    // Fallback: endereço do empregador, se houver.
    $partes = array_filter([$record['employer_address'] ?? null,
                            $record['employer_city'] ?? null,
                            $record['employer_state'] ?? null]);
    $serviceLocation = $partes ? implode(' - ', $partes) : $naoInformado;
}
// Identificação fiscal conforme o tipo de empregador (1=CNPJ, 2=CPF).
$employerDocLabel = $employerType === 2 ? 'CPF do Empregador' : 'CNPJ';
$employerDocValue = $employerType === 2 ? ($employerCpf ?: $naoInformado) : ($cnpj ?: $naoInformado);

// NC-53 — estado de anulação. Antes, a view não expunha estas colunas e o
// sistema emitia comprovante normal de registro ANULADO, como se valesse.
$removedAt     = $record['removed_at'] ?? null;
$removedReason = $record['removed_reason'] ?? null;
$isAnulado     = !empty($removedAt);

// NSR da SAÍDA — marcação distinta, com número próprio desde a Fase 1 (NC-09).
$nsrOut    = $record['nsr_out'] ?? null;
$legacyNsr = $record['legacy_nsr'] ?? null;

// Formatar datas
$dateBR = $date ? date('d/m/Y', strtotime($date)) : 'N/A';
$timeIn = $checkIn ? date('H:i:s', strtotime($checkIn)) : 'N/A';
$timeOut = $checkOut ? date('H:i:s', strtotime($checkOut)) : 'N/A';
$recordedAtBR = $recordedAt ? date('d/m/Y H:i:s', strtotime($recordedAt)) : 'N/A';
$syncedAtBR = $syncedAt ? date('d/m/Y H:i:s', strtotime($syncedAt)) : 'Não sincronizado';
$weekdayBR = '';
if ($date) {
    $weekdays = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    $weekdayBR = $weekdays[(int)date('w', strtotime($date))] ?? '';
}

// Data por extenso (formato documental: "25 de abril de 2026")
$months = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
           'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$nowTs = time();
$dateLongBR = sprintf('%d de %s de %d',
    (int)date('d', $nowTs),
    $months[(int)date('n', $nowTs)],
    (int)date('Y', $nowTs)
);
$issuedAtBR = date('d/m/Y H:i:s', $nowTs);

// Status de aprovação
$approvalStatus = 'Pendente de aprovação';
if ($approved === 1) {
    $approvalStatus = 'Aprovado';
} elseif ($approved === 0) {
    $approvalStatus = 'Rejeitado';
}

// Localização
$locationStr = 'Não disponível';
if ($latitude && $longitude) {
    $locationStr = sprintf('%.6f, %.6f', $latitude, $longitude);
}

// ---------------------------------------------------------------------------
// Selo de integridade da marcação.
//
// Até a Fase 2 isto era um SHA-256 SEM chave, recomputado na emissão a partir
// dos dados ATUAIS do banco: alterada a marcação, o código era recalculado
// sobre os novos valores e seguia "conferindo". Não detectava nada, e o PDF
// ainda afirmava que qualquer alteração o invalidaria (NC-19).
//
// Agora vem de `nsr_ledger.record_hash`: HMAC-SHA256 com chave secreta, sobre
// o payload assinado no momento do registro e encadeado ao evento anterior.
// Alterar a marcação quebra a verificação — e o head do dia está assinado com
// Ed25519 e publicado fora do sistema.
//
// Quando o livro está desligado (`ledger_mode = off`) ou a marcação é anterior
// ao backfill, não há hash fiscal. Neste caso o comprovante é honesto: informa
// que a marcação não está coberta pelo livro, em vez de exibir um código
// decorativo que aparenta uma garantia inexistente.
// ---------------------------------------------------------------------------
$integrityHash   = null;   // 16 hex do record_hash, para exibição
$integrityFull   = null;   // hash completo, para a verificação pública
$integritySource = 'none';

try {
    $stLedger = $pdo->prepare(
        "SELECT record_hash FROM nsr_ledger
          WHERE event_type = 'mark' AND nsr = ? LIMIT 1"
    );
    $stLedger->execute([(int)$nsr]);
    $rh = $stLedger->fetchColumn();
    if ($rh) {
        $integrityFull   = (string)$rh;
        $integrityHash   = strtoupper(substr($integrityFull, 0, 16));
        $integritySource = 'ledger';
    }
} catch (Throwable $e) {
    // Livro ainda não instalado neste ambiente — segue sem selo.
    error_log('[receipt] leitura do livro fiscal falhou: ' . $e->getMessage());
}

// Selo do dia que cobre esta marcação (se já emitido).
$seloDia = null;
if ($integritySource === 'ledger') {
    try {
        $stSeal = $pdo->prepare(
            "SELECT seal_date, head_hash, pubkey_id FROM nsr_ledger_seals
              WHERE ? BETWEEN first_nsr AND last_nsr LIMIT 1"
        );
        $stSeal->execute([(int)$nsr]);
        $seloDia = $stSeal->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $seloDia = null;
    }
}

// Logo em base64 para Dompdf
$logoPath = __DIR__ . '/img/logo_prefeitura.png';
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

// Helper local para escape
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

// Gerar HTML do comprovante (estilo documental — serifa, sem cores de UI)
$html = '
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Comprovante NSR ' . $e($nsr) . '</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 18mm 20mm 16mm 20mm;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #000000;
            background: #ffffff;
            padding: 24px 32px;
            max-width: 210mm;
            margin: 0 auto;
        }
        @media print {
            body { padding: 0; max-width: none; }
        }

        /* ----------------------------------------------------------
           CABEÇALHO INSTITUCIONAL
           ---------------------------------------------------------- */
        .doc-header {
            text-align: center;
            border-bottom: 1.5pt solid #000000;
            padding-bottom: 6pt;
            margin-bottom: 14pt;
        }
        .doc-header .logo { margin-bottom: 4pt; }
        .doc-header .logo img { height: 52px; width: auto; }
        .doc-header .org {
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            line-height: 1.3;
        }
        .doc-header .org-sub {
            font-size: 9.5pt;
            line-height: 1.3;
        }
        .doc-header .system {
            font-size: 9pt;
            font-style: italic;
            margin-top: 2pt;
        }

        /* ----------------------------------------------------------
           TÍTULO DO DOCUMENTO
           ---------------------------------------------------------- */
        .doc-title {
            text-align: center;
            margin: 10pt 0 4pt;
        }
        .doc-title h1 {
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 0;
        }
        .doc-title .legal {
            font-size: 9.5pt;
            font-style: italic;
            margin-top: 2pt;
        }

        /* ----------------------------------------------------------
           NSR (caixa central)
           ---------------------------------------------------------- */
        .nsr-box {
            border: 1pt solid #000000;
            padding: 6pt 10pt;
            margin: 10pt auto 14pt;
            text-align: center;
            width: 70%;
        }
        .nsr-box .label {
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .nsr-box .value {
            font-family: "Courier New", Courier, monospace;
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 0.08em;
            margin-top: 2pt;
        }

        /* ----------------------------------------------------------
           PARÁGRAFO INTRODUTÓRIO
           ---------------------------------------------------------- */
        .doc-intro {
            text-align: justify;
            text-indent: 1.5em;
            margin-bottom: 12pt;
        }

        /* ----------------------------------------------------------
           SEÇÕES NUMERADAS
           ---------------------------------------------------------- */
        .section { margin-bottom: 8pt; page-break-inside: avoid; }
        .section h2 {
            font-size: 10.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 0.5pt solid #000000;
            padding-bottom: 1pt;
            margin-bottom: 4pt;
        }

        /* Tabela de campos no estilo documental: rótulos à esquerda,
           valores em sequência. Bordas finas pretas. */
        table.fields {
            width: 100%;
            border-collapse: collapse;
        }
        table.fields td {
            padding: 2.5pt 6pt;
            font-size: 10pt;
            vertical-align: top;
            border: 0.4pt solid #000000;
        }
        table.fields td.lbl {
            width: 28%;
            font-weight: bold;
            background: #f2f2f2;
            text-transform: uppercase;
            font-size: 8.5pt;
            letter-spacing: 0.03em;
        }
        table.fields td.val {
            width: 22%;
        }
        table.fields td.val.mono {
            font-family: "Courier New", Courier, monospace;
            font-size: 9.5pt;
        }
        table.fields td.val.wide { width: 72%; }

        /* ----------------------------------------------------------
           SELO DE INTEGRIDADE
           ---------------------------------------------------------- */
        .integrity {
            border: 0.6pt solid #000000;
            padding: 5pt 8pt;
            margin: 10pt 0 8pt;
            text-align: center;
        }
        .integrity .lbl {
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: bold;
        }
        .integrity .hash {
            font-family: "Courier New", Courier, monospace;
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.1em;
            margin-top: 2pt;
        }
        .integrity .hint {
            font-size: 8pt;
            font-style: italic;
            margin-top: 2pt;
        }

        /* ----------------------------------------------------------
           FECHAMENTO / ASSINATURA DOCUMENTAL
           ---------------------------------------------------------- */
        .closing {
            margin-top: 10pt;
            text-align: justify;
            text-indent: 1.5em;
            font-size: 10pt;
        }
        .place-date {
            text-align: right;
            margin-top: 12pt;
            font-size: 10pt;
        }

        /* ----------------------------------------------------------
           RODAPÉ
           ---------------------------------------------------------- */
        .doc-footer {
            border-top: 0.5pt solid #000000;
            padding-top: 4pt;
            margin-top: 14pt;
            font-size: 8pt;
            text-align: center;
            line-height: 1.4;
        }
        .doc-footer .meta { font-style: italic; }
    </style>
</head>
<body>

    <!-- ========== CABEÇALHO ========== -->
    <div class="doc-header">
        ' . ($logoBase64 ? '<div class="logo"><img src="' . $logoBase64 . '" alt="Brasão"></div>' : '') . '
        <div class="org">Prefeitura Municipal de Oeiras - PI</div>
        <div class="org-sub">Secretaria Municipal de Educação — SEMED</div>
        <div class="system">Sistema de Ponto Eletrônico — ' . $e($systemName) . ' v' . $e($systemVersion) . '</div>
    </div>

    <!-- ========== TÍTULO ========== -->
    <div class="doc-title">
        <h1>Comprovante de Registro de Ponto</h1>
        <div class="legal">Emitido em conformidade com a Portaria MTP nº 671, de 8 de novembro de 2021 — Categoria ' . $e($repCategory) . '</div>
    </div>

    <!-- ========== NSR ========== -->
    <div class="nsr-box">
        <div class="label">Número Sequencial de Registro (NSR)</div>
        <div class="value">' . $e($nsr) . '</div>
    </div>

    <!-- ========== PARÁGRAFO INTRODUTÓRIO ========== -->
    <p class="doc-intro">
        Atesto, por meio do presente documento, que o registro eletrônico de ponto identificado pelo NSR acima foi efetuado no sistema de Registro Eletrônico de Ponto via Programa (REP-P) do empregador a seguir qualificado, conforme dados consignados nas seções subsequentes.
    </p>

    <!-- ========== 1. EMPREGADOR ========== -->
    <div class="section">
        <h2>1. Identificação do Empregador</h2>
        <table class="fields">
            <tr>
                <td class="lbl">Razão Social</td>
                <td class="val wide" colspan="3">' . $e($companyName) . '</td>
            </tr>
            <tr>
                <td class="lbl">' . $e($employerDocLabel) . '</td>
                <td class="val mono wide" colspan="3">' . $e($employerDocValue)
                    . ($record['cei_caepf_cno'] ? ' &nbsp;·&nbsp; CEI/CAEPF/CNO ' . $e((string)$record['cei_caepf_cno']) : '') . '</td>
            </tr>
        </table>
    </div>

    <!-- ========== 2. COLABORADOR ========== -->
    <div class="section">
        <h2>2. Identificação do Colaborador</h2>
        <table class="fields">
            <tr>
                <td class="lbl">Nome Completo</td>
                <td class="val wide" colspan="3">' . $e($teacherName) . '</td>
            </tr>
            <tr>
                <td class="lbl">CPF</td>
                <td class="val mono wide" colspan="3">' . $e($teacherCpf) . '</td>
            </tr>
        </table>
    </div>

    <!-- ========== 3. REGISTRO ========== -->
    <div class="section">
        <h2>3. Dados da Marcação</h2>
        <table class="fields">
            <tr>
                <td class="lbl">Data</td>
                <td class="val">' . $e($dateBR) . '</td>
                <td class="lbl">Dia da Semana</td>
                <td class="val">' . $e($weekdayBR ?: '—') . '</td>
            </tr>
            <tr>
                <td class="lbl">Tipo</td>
                <td class="val">' . $e($actionDisplay) . '</td>
                <td class="lbl">Modo</td>
                <td class="val">' . $e(ucfirst($recordMode)) . '</td>
            </tr>
            <tr>
                <td class="lbl">Hora de Entrada</td>
                <td class="val mono">' . $e($timeIn) . '</td>
                <td class="lbl">Hora de Saída</td>
                <td class="val mono">' . $e($timeOut) . '</td>
            </tr>
            <tr>
                <td class="lbl">Timestamp do Servidor</td>
                <td class="val mono wide" colspan="3">' . $e($recordedAtBR) . '</td>
            </tr>
        </table>
    </div>

    <!-- ========== 4. AUDITORIA ========== -->
    <div class="section">
        <h2>4. Auditoria Técnica</h2>
        <table class="fields">
            <tr>
                <td class="lbl">Sincronização</td>
                <td class="val mono">' . $e($syncedAtBR) . '</td>
                <td class="lbl">Status HLB</td>
                <td class="val">' . $e($hlbStatus) . '</td>
            </tr>
            <tr>
                <td class="lbl">Geolocalização</td>
                <td class="val mono">' . $e($locationStr) . '</td>
                <td class="lbl">Aprovação</td>
                <td class="val">' . $e($approvalStatus) . '</td>
            </tr>
            <tr>
                <td class="lbl">Identificador do Dispositivo</td>
                <td class="val mono wide" colspan="3" style="font-size: 8.5pt;">' . $e($deviceId) . '</td>
            </tr>
            <tr>
                <td class="lbl">Local da prestação de serviço</td>
                <td class="val wide" colspan="3">' . $e($serviceLocation) . '</td>
            </tr>
            <tr>
                <td class="lbl">Identificação do REP</td>
                <td class="val mono">' . $e($repIdentifier) . '</td>
                <td class="lbl">Categoria</td>
                <td class="val">' . $e($repCategory) . '</td>
            </tr>
        </table>
    </div>
    ' . ($isAnulado ? '
    <!-- ========== CARIMBO DE ANULAÇÃO (NC-53) ========== -->
    <div style="border:2px solid #b00020;background:#fdecef;padding:10px 12px;margin:12px 0;">
        <div style="font-weight:bold;color:#b00020;font-size:11pt;">REGISTRO ANULADO</div>
        <div style="font-size:9pt;margin-top:4px;">
            Esta marcação foi anulada em ' . $e(date('d/m/Y H:i', strtotime((string)$removedAt))) . '
            e NÃO é válida como prova de jornada.
            ' . ($removedReason ? 'Motivo: ' . $e($removedReason) . '.' : '') . '
            O registro permanece arquivado por exigência legal — a anulação está
            lançada no livro fiscal com número sequencial próprio.
        </div>
    </div>' : '') . '

    <!-- ========== SELO DE INTEGRIDADE ========== -->
    ' . ($integritySource === 'ledger' ? '
    <div class="integrity">
        <div class="lbl">Selo de integridade — HMAC-SHA256 (livro fiscal)</div>
        <div class="hash">' . $e($integrityHash) . '</div>
        <div class="hint">
            Derivado da assinatura desta marcação no livro fiscal, encadeada ao registro
            anterior. Qualquer alteração nos dados quebra a verificação.
            ' . ($seloDia
                ? 'O dia ' . $e(date('d/m/Y', strtotime((string)$seloDia['seal_date'])))
                  . ' está selado com assinatura Ed25519 (chave ' . $e((string)$seloDia['pubkey_id']) . ').'
                : 'O selo diário deste período ainda não foi emitido.') . '
            Confira em: verify_receipt.php?nsr=' . $e((string)$nsr) . '
        </div>
    </div>' : '
    <div class="integrity">
        <div class="lbl">Selo de integridade</div>
        <div class="hash" style="font-size:9pt;">NÃO DISPONÍVEL</div>
        <div class="hint">
            Esta marcação não está coberta pelo livro fiscal com cadeia de integridade.
            O comprovante segue válido como registro de jornada, mas sem prova
            criptográfica de que os dados não foram alterados após o registro.
        </div>
    </div>') . '

    <!-- ========== FECHAMENTO ========== -->
    <p class="closing">
        O presente comprovante é emitido em atendimento ao art. 74 da Consolidação das Leis do Trabalho (CLT), para fins de prova de jornada de trabalho.
    </p>

    <div class="place-date">Oeiras - PI, ' . $e($dateLongBR) . '.</div>

    <!-- ========== RODAPÉ ========== -->
    <div class="doc-footer">
        <div><strong>NSR ' . $e($nsr) . '</strong>'
            . ($nsrOut ? ' &nbsp;·&nbsp; <strong>NSR saída ' . $e((string)$nsrOut) . '</strong>' : '')
            . ' &nbsp;·&nbsp; CPF ' . $e($teacherCpf) . ' &nbsp;·&nbsp; Emitido em ' . $e($issuedAtBR) . '</div>'
            // Comprovantes emitidos antes da renumeração do livro citam o NSR
            // antigo; imprimi-lo aqui permite casar a via impressa do
            // trabalhador com o registro atual.
            . ($legacyNsr && (int)$legacyNsr !== (int)$nsr
                ? '<div class="meta">NSR anterior à migração do livro fiscal: ' . $e((string)$legacyNsr) . '</div>'
                : '') . '
        <div class="meta">Documento gerado eletronicamente. Mantenha-o conforme legislação trabalhista vigente.</div>
    </div>

</body>
</html>
';

// Tentar gerar PDF com Dompdf; fallback para HTML se não disponível
if ($dompdfAvailable) {
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Times-Roman');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = sprintf('Comprovante_NSR_%s_%s.pdf', preg_replace('/[^a-zA-Z0-9_-]/', '', $nsr), date('Ymd_His'));
    $dompdf->stream($filename, ['Attachment' => false]);
} else {
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
}

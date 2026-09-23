<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

// Acesso protegido: usa helper centralizado (redireciona se não logado/expirado)
require_collaborator();

$collaborator = current_collaborator();
$collaborator_id = (int)$collaborator['id'];
$collaborator_name = (string)($collaborator['name'] ?? 'Colaborador');

// Logout
if (isset($_GET['logout'])) {
    collaborator_logout();
    header('Location: login.php?out=1');
    exit;
}

$pdo = db();
$tzBR = new DateTimeZone('America/Sao_Paulo');

// Mês/Ano selecionado
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$selectedMonth = max(1, min(12, $selectedMonth));
$selectedYear = max(2020, min((int)date('Y') + 1, $selectedYear));

// Busca informações do colaborador
$stmt = $pdo->prepare("SELECT t.*, ct.name as type_name, ct.schedule_mode 
                       FROM teachers t 
                       LEFT JOIN collaborator_types ct ON ct.id = t.type_id 
                       WHERE t.id = ?");
$stmt->execute([$collaborator_id]);
$collaborator = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$collaborator) {
    die('Colaborador não encontrado.');
}

$schedule_mode = $collaborator['schedule_mode'] ?? 'classes';

// Valor por hora (opcional) - pode ser adicionado futuramente na tabela teachers ou collaborator_types
// Para habilitar: ALTER TABLE teachers ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL;
$hourly_rate = (float)($collaborator['hourly_rate'] ?? 0);

// Período do mês
$startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$endDate = date('Y-m-t', strtotime($startDate));

// Busca pontos do mês. Esconde registros marcados como duplicata pelo admin —
// o colaborador nunca vê duplicatas resolvidas.
$stmt = $pdo->prepare("
    SELECT a.*, s.name as school_name
    FROM attendance a
    LEFT JOIN schools s ON s.id = a.school_id
    WHERE a.teacher_id = ? AND a.date BETWEEN ? AND ?
      AND a.superseded_by_id IS NULL
    ORDER BY a.date ASC, a.check_in ASC
");
$stmt->execute([$collaborator_id, $startDate, $endDate]);
$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Solicitacoes de regularizacao de checkout do periodo, indexadas por attendance_id.
$checkoutReqByAttendance = [];
$stCR = $pdo->prepare("SELECT attendance_id, id, status, proposed_check_out, admin_check_out, justification, rejection_reason, admin_observation, approved_at
                       FROM attendance_checkout_requests
                       WHERE teacher_id = ? AND date BETWEEN ? AND ?");
$stCR->execute([$collaborator_id, $startDate, $endDate]);
while ($row = $stCR->fetch(PDO::FETCH_ASSOC)) {
    $checkoutReqByAttendance[(int)$row['attendance_id']] = $row;
}

// Solicitacoes de correcao de INTERVALO do periodo (analogo a checkout, mas para break).
// Indexadas por attendance_id para fácil lookup ao renderizar cada linha de break.
$breakReqByAttendance = [];
try {
    $stBR = $pdo->prepare("SELECT attendance_id, id, status, proposed_check_in, proposed_check_out,
                                   admin_check_in, admin_check_out, justification, rejection_reason,
                                   admin_observation, approved_at
                           FROM attendance_break_requests
                           WHERE teacher_id = ? AND date BETWEEN ? AND ?");
    $stBR->execute([$collaborator_id, $startDate, $endDate]);
    while ($row = $stBR->fetch(PDO::FETCH_ASSOC)) {
        $breakReqByAttendance[(int)$row['attendance_id']] = $row;
    }
} catch (Throwable $_) {
    // Tabela pode não existir ainda (migration pendente) — não bloqueia a tela.
}

// Janela de regularizacao: ate 60 dias apos o check_in.
$checkoutRegularizationMaxDays = 60;

$csrfTokenTS = csrf_token();

// API base path: a pasta /api/ esta na raiz do projeto, nao dentro de /public/.
// Quando esta pagina eh acessada via /<projeto>/public/my_timesheet.php, URLs
// relativas (ex: 'api/...') resolvem para /<projeto>/public/api/... — 404.
// Removemos o sufixo /public se presente, garantindo URL absoluta correta.
$apiBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBasePath = preg_replace('#/public$#', '', $apiBasePath);

// Consolidação mensal de horas (jornada flexível): carga prevista vs. trabalhado
// no mês, "horas além do previsto" e situação geral em linguagem neutra. Substitui
// o antigo "banco de horas" com saldo negativo dia a dia (helpers.php).
$monthlySummary = get_monthly_hours_summary($pdo, $collaborator_id, (int)$selectedYear, (int)$selectedMonth, $collaborator['created_at'] ?? null);

// Agrupa por dia
$dayGroups = [];
foreach ($attendances as $att) {
    $date = $att['date'];
    if (!isset($dayGroups[$date])) {
        $dayGroups[$date] = [];
    }
    $dayGroups[$date][] = $att;
}

// Calcula totais — separa work (jornada) de break (intervalo).
// - totalWorkedMinutes  = soma bruta de pares 'work'.
// - totalBreakMinutes   = soma de pares 'break' (informativo).
// - totalEffectiveMinutes = trabalhado efetivo CHEIO (work + break — o intervalo
//   conta como tempo trabalhado; modelo "cheio vs cheio", helpers.php).
// - dayBruto/dayBreakReal/dayExpectedBreak/dayEffective: agrupados por dia para exibição.
$totalWorkedMinutes    = 0;
$totalBreakMinutes     = 0;
$totalEffectiveMinutes = 0;
$totalApproved = 0;
$totalPending = 0;
$dayMetrics = []; // [date => ['bruto'=>X, 'breakReal'=>X, 'expectedBreak'=>X, 'effective'=>X]]

// Janela de contagem: não soma dias antes do início da contagem (config/cadastro)
// nem dias futuros — coerente com os relatórios. Sem isso, pontos anteriores ao
// go-live inflariam o "trabalhado" exibido ao colaborador.
$tsStartDate = counting_start_for($collaborator['created_at'] ?? null);
$tsToday = date('Y-m-d');

foreach ($dayGroups as $date => $atts) {
    if ($date < $tsStartDate || $date > $tsToday) {
        continue; // fora da janela de contagem
    }
    $dayBruto = 0;
    $dayBreakReal = 0;
    foreach ($atts as $att) {
        if ($att['check_in'] && $att['check_out']) {
            $ci = new DateTime($att['check_in']);
            $co = new DateTime($att['check_out']);
            if ($co > $ci) {
                $minutes = (int)floor(($co->getTimestamp() - $ci->getTimestamp()) / 60);
                if (($att['record_type'] ?? 'work') === 'break') {
                    $totalBreakMinutes += $minutes;
                    $dayBreakReal += $minutes;
                } else {
                    $totalWorkedMinutes += $minutes;
                    $dayBruto += $minutes;
                }

                if ($att['approved'] == 1) {
                    $totalApproved++;
                } else {
                    $totalPending++;
                }
            }
        }
    }
    // Para cada dia: calcula efetivo (com auto-desconto) e intervalo previsto.
    $eff = calculate_effective_worked_minutes($pdo, $collaborator_id, $date);
    $expBreak = get_expected_break_minutes($pdo, $collaborator_id, $date);
    $dayMetrics[$date] = [
        'bruto'         => $dayBruto + $dayBreakReal,  // tempo total na instituição
        'workReal'      => $dayBruto,                  // pares work somados
        'breakReal'     => $dayBreakReal,              // pares break somados
        'expectedBreak' => $expBreak,                  // previsto na rotina
        'effective'     => $eff,                       // saldo usa este
    ];
    $totalEffectiveMinutes += $eff;
}

// Estrutura consolidada por dia — usa o helper centralizado para garantir que
// um dia com múltiplos intervalos seja apresentado como 1 card (não N cards),
// com a lista de intervalos como sub-bloco interno.
$attendancesForConsolidation = array_map(function($a) use ($collaborator_id, $collaborator_name) {
    $a['teacher_id'] = $collaborator_id;
    $a['teacher_name'] = $collaborator_name;
    return $a;
}, $attendances);
$consolidatedDays = consolidate_attendance_by_day($attendancesForConsolidation);
$consolidatedDaysByDate = [];
foreach ($consolidatedDays as $cd) {
    $consolidatedDaysByDate[$cd['date']] = $cd;
}

// Valor financeiro (se configurado)
$totalValue = 0;
if ($hourly_rate > 0) {
    // Tempo cheio (work + break) — o intervalo é remunerado.
    $totalValue = ($totalEffectiveMinutes / 60) * $hourly_rate;
}

$appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

function formatMinutes($minutes) {
    if ($minutes == 0) return '0h 00min';
    $h = floor(abs($minutes) / 60);
    $m = abs($minutes) % 60;
    $sign = $minutes < 0 ? '-' : '';
    return sprintf('%s%dh %02dmin', $sign, $h, $m);
}

function statusBadge($approved, $pending_reasons = null) {
    if ($approved == 1) {
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Aprovado</span>';
    }
    if ($approved === 0 || $approved === '0') {
        return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejeitado</span>';
    }
    return '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history"></i> Pendente de aprovação</span>';
}

/**
 * Converte os códigos técnicos de pending_reasons em frases amigáveis
 * para o colaborador. Adiciona contexto extra (GPS mock, hora extra,
 * fraude detectada) quando aplicável.
 */
function explainPendingReasons(array $att): array {
    // 1) Códigos vindos da coluna pending_reasons — usa o helper central
    //    para garantir consistência com o admin.
    $out = humanize_pending_reasons($att['pending_reasons'] ?? null);

    // 2) Sinalizações adicionais derivadas de outras colunas do registro.
    if (!empty($att['gps_mock_detected']) && (int)$att['gps_mock_detected'] === 1) {
        $out[] = humanize_pending_reason('gps_mock');
    }
    if (!empty($att['fraud_risk_level']) && (int)$att['fraud_risk_level'] >= 2) {
        $out[] = humanize_pending_reason('fraud_suspected');
    }
    if (!empty($att['manual_reason_text'])) {
        $out[] = 'Registro inserido manualmente pelo administrador. Motivo: "'
               . htmlspecialchars((string)$att['manual_reason_text'], ENT_QUOTES) . '"';
    }

    // Remove duplicatas preservando ordem
    $seen = [];
    $unique = [];
    foreach ($out as $m) {
        $k = mb_strtolower($m);
        if (!isset($seen[$k])) { $seen[$k] = true; $unique[] = $m; }
    }
    return $unique;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Minha Folha de Ponto - <?= htmlspecialchars($collaborator_name) ?></title>
    <meta name="theme-color" content="#0162cc">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin/css/admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="img/icone-2.ico" type="image/x-icon">
    <style>
      /* A4: alinha Minha Folha à camada de refinamento do app */
      :root {
        --font-ui: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
        --r-brand: #0d6efd;
        --r-brand-hover: #0958d9;
        --r-brand-soft: #e7f1ff;
        --r-ink: #0f172a;
        --r-ink-2: #475569;
        --r-ink-3: #6b7280;
        --r-page: #f7f8fb;
        --r-surface: #ffffff;
        --r-edge: #e5e7eb;
        /* Hierarquia de alturas de botão (WCAG 2.1 touch target >= 44px):
           --btn-h-sm  = 44px (filtros/ações secundárias)
           --btn-h     = 48px (CTAs padrão de página)
           --btn-h-lg  = 54px (CTA primária de formulário)
           --btn-h-xl  = 68px (hero action na home) */
        --btn-h-sm: 44px;
        --btn-h: 48px;
        --btn-h-lg: 54px;
        --btn-h-xl: 68px;
      }
      body.my-timesheet {
        background: var(--r-page) !important;
        font-family: var(--font-ui) !important;
        color: var(--r-ink);
        letter-spacing: -0.003em;
        -webkit-font-smoothing: antialiased;
      }
      body.my-timesheet .container { background: transparent !important; }
      body.my-timesheet h1, body.my-timesheet h2, body.my-timesheet h3 { font-family: var(--font-ui); letter-spacing: -0.01em; }
      body.my-timesheet .text-muted { color: var(--r-ink-3) !important; }
      body.my-timesheet .card {
        border: 1px solid var(--r-edge) !important;
        border-radius: 14px !important;
        box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 2px 6px rgba(15,23,42,.04) !important;
      }
      .ts-topbar {
        max-width: 900px; margin: 0 auto 14px;
        display: flex; justify-content: space-between; align-items: center; gap: 12px;
        padding: 6px 2px;
      }
      .ts-greet { font-weight: 700; font-size: 15px; color: var(--r-ink); }
      .ts-greet small { font-weight: 500; color: var(--r-ink-3); font-size: 12.5px; display: block; margin-top: 2px; }
      .ts-topbar .btn {
        font-family: var(--font-ui); font-weight: 600;
        border-radius: 10px;
      }
      /* Alerta dentro de card pendente — motivo + contexto amigável */
      .pending-alert {
        background: linear-gradient(135deg, #fff7e5 0%, #fff1d1 100%);
        border: 1px solid #f6c552;
        border-left: 4px solid #e8b33f;
        border-radius: 12px;
        padding: 12px 14px;
        margin: 0 0 14px;
        color: #4a2900;
        font-size: 14px;
        line-height: 1.5;
      }
      .pending-alert-header {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
        margin-bottom: 6px;
        color: #7c4d00;
      }
      .pending-alert-header i { font-size: 16px; color: #e8b33f; }
      .pending-alert-list {
        margin: 4px 0 8px 22px;
        padding: 0;
      }
      .pending-alert-list li {
        margin-bottom: 3px;
      }
      .pending-alert-foot {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed rgba(232,179,63,.5);
        font-size: 12.5px;
        color: #5c3700;
      }
      .pending-alert-foot i { flex-shrink: 0; margin-top: 2px; font-size: 13px; }

      /* Filtro de período (Mês/Ano) */
      .period-filter-card { border-radius: 14px; }
      .period-filter {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 14px;
      }
      .period-field { display: flex; flex-direction: column; gap: 6px; }
      .period-field-action { margin-left: auto; }
      .period-label {
        margin: 0;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: var(--r-ink-3, #6c757d);
      }
      .period-select {
        min-width: 150px;
        padding-right: 2.4rem;
        font-weight: 600;
        color: var(--r-ink-1, #1a1f2e);
        border-color: var(--r-line, #e3e7ef);
        border-radius: 10px;
      }
      .period-select-year { min-width: 110px; }
      .period-select:focus {
        border-color: var(--r-brand, #2f6df6);
        box-shadow: 0 0 0 .2rem rgba(47,109,246,.15);
      }
      .period-btn {
        min-width: 130px;
        min-height: var(--btn-h);
        padding: 0 1.1rem;
        border-radius: 10px;
        background: var(--r-brand, #2f6df6);
        color: #fff;
        font-weight: 600;
        border: 1px solid var(--r-brand, #2f6df6);
        transition: background .15s ease, transform .08s ease;
      }
      .period-btn:hover { background: var(--r-brand-strong, #2558d0); color: #fff; }
      .period-btn:active { transform: translateY(1px); }
      .period-btn i { margin-right: 4px; }
      @media (max-width: 520px) {
        .period-filter { gap: 10px; }
        .period-field, .period-field-action { flex: 1 1 100%; }
        .period-field-action { margin-left: 0; }
        .period-btn { width: 100%; }
      }

      /* Hamburger menu + Drawer */
      .ts-menu-btn {
        width: 42px; height: 42px;
        border-radius: 11px;
        background: var(--r-surface);
        border: 1px solid var(--r-edge);
        color: var(--r-ink);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 20px; cursor: pointer;
        transition: border-color .15s ease, background .15s ease, transform .12s ease;
      }
      .ts-menu-btn:hover { background: var(--r-brand-soft); border-color: var(--r-brand); color: var(--r-brand); }
      .ts-menu-btn:active { transform: scale(.96); }

      .app-drawer-backdrop {
        position: fixed; inset: 0;
        background: rgba(15,23,42,.55);
        -webkit-backdrop-filter: blur(2px);
        backdrop-filter: blur(2px);
        z-index: 1040;
        opacity: 0; pointer-events: none;
        transition: opacity .22s ease;
      }
      .app-drawer-backdrop.is-open { opacity: 1; pointer-events: auto; }
      .app-drawer {
        position: fixed; top: 0; right: 0; bottom: 0;
        width: min(86vw, 320px);
        background: var(--r-surface);
        box-shadow: -8px 0 24px rgba(15,23,42,.14);
        border-left: 1px solid var(--r-edge);
        z-index: 1050;
        transform: translateX(100%);
        transition: transform .28s cubic-bezier(.22,.61,.36,1);
        display: flex; flex-direction: column;
        overflow-y: auto;
      }
      .app-drawer.is-open { transform: translateX(0); }
      .app-drawer-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: max(18px, env(safe-area-inset-top, 18px)) 18px 14px;
        border-bottom: 1px solid var(--r-edge);
      }
      .app-drawer-title {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
        font-size: 16px;
        color: var(--r-ink);
      }
      .app-drawer-title img { height: 34px; width: auto; display: block; }
      .app-drawer-close {
        width: 36px; height: 36px;
        border-radius: 10px;
        background: transparent; border: 0;
        color: var(--r-ink-2); font-size: 18px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        transition: background .12s ease, color .12s ease;
      }
      .app-drawer-close:hover { background: #f1f5f9; color: var(--r-ink); }
      .app-drawer-profile {
        display: flex; align-items: center; gap: 12px;
        padding: 18px;
        border-bottom: 1px solid var(--r-edge);
      }
      .app-drawer-avatar {
        width: 44px; height: 44px;
        border-radius: 50%;
        background: var(--r-brand-soft);
        color: var(--r-brand);
        display: inline-flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 18px; flex-shrink: 0;
      }
      .app-drawer-profile-body { min-width: 0; }
      .app-drawer-profile-name {
        font-weight: 700; color: var(--r-ink);
        font-size: 14.5px; line-height: 1.2;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      }
      .app-drawer-profile-cpf { color: var(--r-ink-3); font-size: 12.5px; margin-top: 2px; }
      .app-drawer-list { list-style: none; margin: 0; padding: 8px 0; }
      .app-drawer-item {
        display: flex; align-items: center; gap: 14px;
        padding: 14px 18px;
        color: var(--r-ink); text-decoration: none;
        font-size: 15px; font-weight: 500;
        background: transparent; border: 0; width: 100%;
        cursor: pointer; text-align: left;
        transition: background .12s ease, color .12s ease, padding-left .12s ease;
      }
      .app-drawer-item i { font-size: 19px; color: var(--r-brand); flex-shrink: 0; }
      .app-drawer-item .chev { color: var(--r-ink-3); font-size: 14px; margin-left: auto; }
      .app-drawer-item:hover { background: var(--r-brand-soft); padding-left: 22px; }
      .app-drawer-item-danger { color: #dc3545; }
      .app-drawer-item-danger i { color: #dc3545; }
      .app-drawer-item-danger:hover { background: rgba(220,53,69,.08); }
      .app-drawer-item.is-active { background: var(--r-brand-soft); color: var(--r-brand); }
      .app-drawer-item.is-active i { color: var(--r-brand); }
      .app-drawer-divider { height: 1px; background: var(--r-edge); margin: 4px 18px; }
      .app-drawer-footer {
        margin-top: auto;
        padding: 16px 18px max(16px, env(safe-area-inset-bottom, 16px));
        border-top: 1px solid var(--r-edge);
        color: var(--r-ink-3);
        font-size: 11.5px; font-weight: 500;
      }
      .app-drawer-footer strong { color: var(--r-ink-2); font-weight: 600; }
      body.drawer-open {
        overflow: hidden;
        /* iOS Safari: overflow:hidden no body não trava o bounce scroll.
           position:fixed + top é aplicado via JS. */
      }

      /* iOS Safari: inputs e selects com font-size < 16px disparam zoom
         automático ao focar. Garante mínimo de 16px em telas pequenas. */
      @media (max-width: 767px) {
        input, select, textarea, .form-control, .form-select { font-size: 16px; }
      }

      /* Landscape em telefones: reduz topbar e filtros. */
      @media (orientation: landscape) and (max-height: 500px) {
        .ts-topbar { padding: 4px 0 8px !important; }
        .period-filter-card .card-body { padding: 10px 14px !important; }
        .period-filter { gap: 8px !important; }
        .period-select, .period-btn { min-height: 40px !important; }
      }
    </style>
</head>
<body class="my-timesheet">
    <div class="container py-3">
        <!-- Topbar pessoal -->
        <div class="ts-topbar">
            <div class="ts-greet">
                <i class="bi bi-calendar-check me-1"></i>
                Minha Folha
                <small><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($collaborator_name) ?></small>
            </div>
            <button type="button" class="ts-menu-btn" id="btnMenuToggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="appDrawer">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <!-- Seletor de Mês/Ano -->
        <div class="card border-0 shadow-sm mb-4 period-filter-card">
            <div class="card-body">
                <form method="get" class="period-filter">
                    <div class="period-field">
                        <label for="month" class="period-label">Mês</label>
                        <select name="month" id="month" class="form-select period-select">
                            <?php
                            $months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                                      'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
                            foreach ($months as $i => $monthName) {
                                $m = $i + 1;
                                $selected = ($m == $selectedMonth) ? 'selected' : '';
                                echo "<option value='$m' $selected>$monthName</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="period-field">
                        <label for="year" class="period-label">Ano</label>
                        <select name="year" id="year" class="form-select period-select period-select-year">
                            <?php
                            $currentYear = (int)date('Y');
                            for ($y = $currentYear; $y >= 2020; $y--) {
                                $selected = ($y == $selectedYear) ? 'selected' : '';
                                echo "<option value='$y' $selected>$y</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="period-field period-field-action">
                        <button type="submit" class="btn period-btn">
                            <i class="bi bi-search"></i> Consultar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Seção de Holerites -->
        <?php
        $stPayslips = $pdo->prepare("SELECT * FROM payslips WHERE teacher_id = ? ORDER BY reference_month DESC LIMIT 6");
        $stPayslips->execute([$collaborator_id]);
        $myPayslips = $stPayslips->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($myPayslips)):
        ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Meus Holerites</h5>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($myPayslips as $ps): 
                        $refDate = DateTime::createFromFormat('Y-m-d', $ps['reference_month']);
                        $monthName = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][(int)$refDate->format('n')];
                    ?>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="payslip.php?id=<?= (int)$ps['id'] ?>" class="btn btn-outline-success w-100" target="_blank">
                            <i class="bi bi-file-earmark-pdf-fill d-block fs-3 mb-1"></i>
                            <small><?= $monthName ?>/<?= $refDate->format('y') ?></small>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-muted small mt-3">
                    <i class="bi bi-info-circle me-1"></i>Clique para visualizar/baixar seu holerite em PDF
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card primary">
                    <div class="stat-label">
                        <i class="bi bi-clock me-1"></i>
                        Horas Trabalhadas
                    </div>
                    <div class="stat-value"><?= formatMinutes($totalEffectiveMinutes) ?></div>
                    <small class="stat-sub">Tempo total (intervalo incluído)</small>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card secondary">
                    <div class="stat-label">
                        <i class="bi bi-pause-circle me-1"></i>
                        Tempo em Intervalo
                    </div>
                    <div class="stat-value"><?= formatMinutes($totalBreakMinutes) ?></div>
                    <small class="stat-sub">Já incluído nas horas trabalhadas</small>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card warning">
                    <div class="stat-label">
                        <i class="bi bi-clock-history me-1"></i>
                        Pontos Pendentes
                    </div>
                    <div class="stat-value"><?= $totalPending ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card info">
                    <div class="stat-label">
                        <i class="bi bi-calendar-check me-1"></i>
                        Carga Prevista
                    </div>
                    <div class="stat-value"><?= formatMinutes((int)$monthlySummary['expected_minutes']) ?></div>
                    <small class="stat-sub">No mês</small>
                </div>
            </div>
        </div>

        <?php
        // Situação do mês (jornada flexível): consolida o tempo realmente trabalhado
        // vs. a carga prevista, sem "banco de horas" nem saldo negativo. Linguagem
        // neutra e positiva — variações de entrada/saída são compensadas no mês.
        $ms            = $monthlySummary;
        $msWorked      = (int)$ms['worked_minutes'];
        $msCompensated = (int)$ms['compensated_minutes'];
        $msRemaining   = (int)$ms['remaining_to_compensate_minutes'];
        switch ($ms['situation']) {
            case 'em_dia':       $msBadge = 'bg-success';        $msIcon = 'bi-check-circle';     break;
            case 'em_andamento': $msBadge = 'bg-info text-dark';  $msIcon = 'bi-hourglass-split';  break;
            default:             $msBadge = 'bg-secondary';       $msIcon = 'bi-arrow-repeat';     break; // a_compensar
        }
        ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h6 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Situação do mês</h6>
                    <span class="badge <?= $msBadge ?>"><i class="bi <?= $msIcon ?> me-1"></i><?= htmlspecialchars($ms['situation_label']) ?></span>
                </div>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">Carga prevista</div>
                        <div class="fs-5 fw-semibold"><?= formatMinutes((int)$ms['expected_minutes']) ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">Horas trabalhadas</div>
                        <div class="fs-5 fw-semibold"><?= formatMinutes($msWorked) ?></div>
                    </div>
                    <?php if ($msCompensated > 0): ?>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">Horas além do previsto</div>
                        <div class="fs-5 fw-semibold text-success">+<?= formatMinutes($msCompensated) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($ms['situation'] === 'a_compensar' && $msRemaining > 0): ?>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">Horas a compensar</div>
                        <div class="fs-5 fw-semibold"><?= formatMinutes($msRemaining) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mt-3">
                    <i class="bi bi-info-circle me-1"></i>As horas são consolidadas ao longo do mês. Pequenas variações no horário de entrada e saída podem ser compensadas dentro do período.
                </div>
            </div>
        </div>

        <?php if ($hourly_rate > 0): ?>
        <!-- Valor Financeiro -->
        <div class="alert alert-info d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-cash-coin fs-4"></i>
            <div>
                <strong>Valor Estimado:</strong>
                R$ <?= number_format($totalValue, 2, ',', '.') ?>
                <small class="ms-2 opacity-75">(<?= number_format($hourly_rate, 2, ',', '.') ?>/hora)</small>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cards de Pontos -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">
                <i class="bi bi-list-ul me-2"></i>
                Registros de Ponto
            </h5>
            <span class="badge bg-primary"><?= count($attendances) ?> registro(s)</span>
        </div>

        <?php if (empty($dayGroups)): ?>
        <div class="card border-0 shadow-sm text-center py-5">
            <div class="card-body">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-3">Nenhum registro encontrado neste período.</p>
                <a href="<?= htmlspecialchars($appBase) ?>/index.php" class="btn btn-primary">
                    <i class="bi bi-clock-history me-1"></i> Bater Ponto
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info py-2 px-3 small mb-3" role="note">
            <i class="bi bi-info-circle me-1"></i>
            O sistema considera o <strong>intervalo previsto na sua rotina</strong> mesmo quando você não registra manualmente.
            Tempo líquido trabalhado é calculado com base na rotina cadastrada.
        </div>

        <div class="row g-3">
            <?php foreach ($dayGroups as $date => $atts):
                // Cada dia vira UM card (não N). Antes, múltiplos intervalos = N cards;
                // agora os intervalos viram sub-lista interna ao card do dia.
                $day = $consolidatedDaysByDate[$date] ?? null;
                if (!$day) continue;

                // Escolhe registro representativo: primeiro work; se não houver
                // (dia orphan, só breaks), pega o primeiro registro qualquer.
                $att = null;
                foreach ($atts as $candidate) {
                    if (($candidate['record_type'] ?? 'work') === 'work') { $att = $candidate; break; }
                }
                if ($att === null && !empty($atts)) $att = $atts[0];
                if ($att === null) continue;

                $isOrphanDay = ($day['status'] === 'orphan');
                // $isBreak só fica true em dia orphan (sem work) — usado para preservar
                // comportamento legado de blocos visuais marcados como "break".
                $isBreak = $isOrphanDay;
                $cardClass = $isBreak ? 'card point-card point-card--break' : 'card point-card';

                // Labels de entrada/saída são sempre os do dia (turno completo).
                $inLabel  = 'Entrada';
                $outLabel = 'Saída';
                $inIcon   = 'bi-box-arrow-in-right';
                $outIcon  = 'bi-box-arrow-right';
                $inIconCls  = 'text-success';
                $outIconCls = 'text-danger';
            ?>
                <div class="col-12">
                    <div class="<?= $cardClass ?>">
                        <div class="card-body">
                            <!-- Header: Data e Status -->
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="mb-1 fw-bold">
                                        <?= date('d/m/Y', strtotime($date)) ?>
                                        <?php if ($isOrphanDay): ?>
                                            <span class="badge bg-warning text-dark ms-2" title="Não há registro de jornada para este dia, apenas intervalos."><i class="bi bi-exclamation-triangle me-1"></i>Sem jornada</span>
                                        <?php elseif ($day['status'] === 'in_progress'): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis border border-info ms-2"><i class="bi bi-hourglass-split me-1"></i>Em andamento</span>
                                        <?php endif; ?>
                                    </h6>
                                    <small class="text-muted">
                                        <?php
                                        $days = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
                                        echo $days[date('w', strtotime($date))];
                                        ?>
                                    </small>
                                </div>
                                <div><?= statusBadge($att['approved'], $att['pending_reasons'] ?? null) ?></div>
                            </div>

                            <?php if ((int)($att['approved'] ?? 0) !== 1):
                                $pendingMessages = explainPendingReasons($att);
                            ?>
                            <div class="pending-alert">
                                <div class="pending-alert-header">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <span>Por que este ponto está pendente</span>
                                </div>
                                <?php if (!empty($pendingMessages)): ?>
                                <ul class="pending-alert-list">
                                    <?php foreach ($pendingMessages as $msg): ?>
                                        <li><?= $msg /* já escapado em explainPendingReasons */ ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php else: ?>
                                <p class="mb-0 small">
                                    Este ponto ainda não foi revisado pelo administrador. Nenhum motivo específico foi registrado.
                                </p>
                                <?php endif; ?>
                                <div class="pending-alert-foot">
                                    <i class="bi bi-info-circle"></i>
                                    Enquanto não for aprovado, este registro não entra na sua folha. Procure o administrador se precisar de ajuste.
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Horários do DIA (entrada inicial e saída final, consolidados) -->
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <div class="time-box">
                                        <div class="time-label">
                                            <i class="bi <?= $inIcon ?> <?= $inIconCls ?> me-1"></i>
                                            <?= htmlspecialchars($inLabel) ?>
                                        </div>
                                        <div class="time-value">
                                            <?php if ($day['check_in']): ?>
                                                <?= htmlspecialchars(substr($day['check_in'], 0, 5)) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="time-box">
                                        <div class="time-label">
                                            <i class="bi <?= $outIcon ?> <?= $outIconCls ?> me-1"></i>
                                            <?= htmlspecialchars($outLabel) ?>
                                        </div>
                                        <div class="time-value">
                                            <?php if ($day['check_out']): ?>
                                                <?= htmlspecialchars(substr($day['check_out'], 0, 5)) ?>
                                            <?php else:
                                                $isPastDay = strtotime($date) < strtotime(date('Y-m-d'));
                                            ?>
                                                <?php if ($isPastDay): ?>
                                                    <small class="text-danger fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>Sem saída</small>
                                                <?php else: ?>
                                                    <small class="text-muted">Em andamento</small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($day['breaks'])): ?>
                            <!-- Sub-bloco: intervalos do dia (consolidado) -->
                            <div class="mb-3 p-3 border-start border-4 border-secondary bg-light rounded">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <strong class="text-secondary"><i class="bi bi-pause-circle me-1"></i>Intervalos do dia</strong>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border">
                                        <?= count($day['breaks']) ?> · <?= formatMinutes((int)$day['total_break_minutes']) ?>
                                    </span>
                                </div>
                                <ul class="list-unstyled mb-0 small">
                                    <?php foreach ($day['breaks'] as $bIdx => $b):
                                        $bStart = $b['start'] ? substr($b['start'], 0, 5) : null;
                                        $bEnd   = $b['end']   ? substr($b['end'], 0, 5)   : null;
                                        $bMin   = $b['duration_minutes'];
                                        $bRawId = $b['raw_id'];
                                        $bRow   = null;
                                        if ($bRawId) {
                                            foreach ($atts as $cand) {
                                                if ((int)($cand['id'] ?? 0) === (int)$bRawId) { $bRow = $cand; break; }
                                            }
                                        }
                                        $bReq = $bRawId && isset($breakReqByAttendance[$bRawId]) ? $breakReqByAttendance[$bRawId] : null;
                                        $isToday = ($date === date('Y-m-d'));
                                        $dayDiff = (int)floor((time() - strtotime($date)) / 86400);
                                        $withinWindow = ($dayDiff <= $checkoutRegularizationMaxDays && $dayDiff >= 0);
                                    ?>
                                    <li class="d-flex flex-wrap align-items-center gap-2 py-1">
                                        <span class="text-muted">Intervalo <?= ($bIdx + 1) ?>:</span>
                                        <strong><?= htmlspecialchars($bStart ?? '—') ?></strong>
                                        <span class="text-muted">→</span>
                                        <?php if ($bEnd): ?>
                                            <strong><?= htmlspecialchars($bEnd) ?></strong>
                                        <?php else: ?>
                                            <em class="text-warning">em aberto</em>
                                        <?php endif; ?>
                                        <?php if ($bMin !== null): ?>
                                            <span class="text-muted">(<?= formatMinutes((int)$bMin) ?>)</span>
                                        <?php endif; ?>
                                        <?php if (!$bRawId): ?>
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis border" title="Intervalo entre dois works (inferido)">inferido</span>
                                        <?php elseif ($bReq): ?>
                                            <?php
                                                $bReqStatus = (string)$bReq['status'];
                                                $bReqClass = $bReqStatus === 'approved' ? 'success'
                                                           : ($bReqStatus === 'rejected' ? 'danger' : 'info');
                                                $bReqLabel = $bReqStatus === 'approved' ? 'correção aprovada'
                                                           : ($bReqStatus === 'rejected' ? 'correção rejeitada' : 'correção pendente');
                                            ?>
                                            <span class="badge bg-<?= $bReqClass ?>-subtle text-<?= $bReqClass ?>-emphasis border border-<?= $bReqClass ?>"><?= htmlspecialchars($bReqLabel) ?></span>
                                        <?php elseif ($withinWindow && $bRow && empty($bRow['check_out'])): ?>
                                            <!-- Intervalo aberto (esqueceu a volta): informar a hora real, direto. -->
                                            <button type="button"
                                                    class="btn btn-sm btn-warning py-0 px-2 ms-1 btn-fix-break"
                                                    data-attendance-id="<?= (int)$bRawId ?>"
                                                    data-date="<?= htmlspecialchars($date) ?>"
                                                    data-check-in="<?= htmlspecialchars((string)($bRow['check_in'] ?? '')) ?>"
                                                    data-check-out=""
                                                    data-mode="close_open"
                                                    title="Informar a hora que você voltou do intervalo">
                                                <i class="bi bi-box-arrow-in-left me-1"></i>Informar volta
                                            </button>
                                        <?php elseif ($withinWindow && $bRow): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-link p-0 ms-1 btn-fix-break"
                                                    data-attendance-id="<?= (int)$bRawId ?>"
                                                    data-date="<?= htmlspecialchars($date) ?>"
                                                    data-check-in="<?= htmlspecialchars((string)($bRow['check_in'] ?? '')) ?>"
                                                    data-check-out="<?= htmlspecialchars((string)($bRow['check_out'] ?? '')) ?>"
                                                    data-mode="<?= $isToday ? 'direct' : 'request' ?>"
                                                    title="<?= $isToday ? 'Corrigir intervalo' : 'Solicitar correção' ?>">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php
                            // Correção de intervalo: cada item da sub-lista de intervalos (acima)
                            // já tem seu próprio botão inline. Bloco antigo (1 por card de break)
                            // removido com a consolidação por dia.

                            // Bloco de regularizacao de checkout esquecido. Aparece quando ha entrada
                            // sem saida E o ponto eh de um dia anterior (entrada de hoje ainda eh
                            // "em andamento", nao orfao).
                            $aidCR = (int)$att['id'];
                            $isOrphan = !empty($att['check_in']) && empty($att['check_out']) && strtotime($date) < strtotime(date('Y-m-d'));
                            $crReq = $checkoutReqByAttendance[$aidCR] ?? null;
                            $checkInTsForCR = $att['check_in'] ? strtotime($att['check_in']) : 0;
                            $daysSinceCheckIn = $checkInTsForCR ? (int)floor((time() - $checkInTsForCR) / 86400) : 999;
                            $withinRegularizationWindow = ($daysSinceCheckIn <= $checkoutRegularizationMaxDays);
                            if ($isOrphan || $crReq):
                            ?>
                            <div class="mt-2 mb-3 p-3 border-start border-4 border-warning bg-light rounded">
                                <?php if ($crReq):
                                    $crStatus = (string)$crReq['status'];
                                    $crProposed = $crReq['admin_check_out'] ?: $crReq['proposed_check_out'];
                                    $crProposedFmt = $crProposed ? date('d/m/Y H:i', strtotime($crProposed)) : '—';
                                    if ($crStatus === 'pending'):
                                ?>
                                    <div class="d-flex align-items-start gap-2 mb-2">
                                        <i class="bi bi-hourglass-split text-info"></i>
                                        <div>
                                            <strong>Regularizacao pendente de analise</strong>
                                            <div class="small text-muted">Saida estimada: <?= htmlspecialchars($crProposedFmt) ?></div>
                                            <?php if (!empty($crReq['justification'])): ?>
                                                <div class="small text-muted"><strong>Sua justificativa:</strong> <?= htmlspecialchars((string)$crReq['justification']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php elseif ($crStatus === 'approved'): ?>
                                    <div class="d-flex align-items-start gap-2">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                        <div>
                                            <strong>Regularizacao aprovada</strong>
                                            <div class="small text-muted">Saida registrada: <?= htmlspecialchars($crProposedFmt) ?></div>
                                            <?php if (!empty($crReq['admin_observation'])): ?>
                                                <div class="small text-muted"><strong>Observacao do admin:</strong> <?= htmlspecialchars((string)$crReq['admin_observation']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php elseif ($crStatus === 'rejected'): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <div class="d-flex align-items-start gap-2">
                                            <i class="bi bi-x-circle-fill text-danger"></i>
                                            <div>
                                                <strong>Regularizacao rejeitada</strong>
                                                <?php if (!empty($crReq['rejection_reason'])): ?>
                                                    <div class="small text-danger"><strong>Motivo:</strong> <?= htmlspecialchars((string)$crReq['rejection_reason']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($isOrphan && $withinRegularizationWindow): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-warning btn-fix-checkout align-self-start"
                                                    data-attendance-id="<?= $aidCR ?>"
                                                    data-date="<?= htmlspecialchars($date) ?>"
                                                    data-check-in="<?= htmlspecialchars($att['check_in']) ?>"
                                                    data-school="<?= htmlspecialchars($att['school_name'] ?? '') ?>">
                                                <i class="bi bi-arrow-clockwise me-1"></i> Solicitar nova regularizacao
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php else: /* sem solicitacao */ ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-exclamation-triangle me-1"></i> Sem saida registrada
                                        </span>
                                        <?php if ($withinRegularizationWindow): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-warning btn-fix-checkout"
                                                    data-attendance-id="<?= $aidCR ?>"
                                                    data-date="<?= htmlspecialchars($date) ?>"
                                                    data-check-in="<?= htmlspecialchars($att['check_in']) ?>"
                                                    data-school="<?= htmlspecialchars($att['school_name'] ?? '') ?>">
                                                <i class="bi bi-pencil-square me-1"></i> Regularizar saida
                                            </button>
                                            <small class="text-muted">Voce informa o horario estimado de saida e o administrador analisa.</small>
                                        <?php else: ?>
                                            <small class="text-muted">Fora da janela de regularizacao (>= <?= (int)$checkoutRegularizationMaxDays ?> dias). Procure o RH/Admin.</small>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Informações Adicionais -->
                            <div class="point-info">
                                <div class="info-item">
                                    <i class="bi bi-clock text-primary"></i>
                                    <span class="info-label">Duração:</span>
                                    <span class="info-value fw-semibold">
                                        <?php
                                        // Duração = tempo LÍQUIDO trabalhado no dia = presença (entrada→saída)
                                        // − intervalos. Usa o valor já consolidado por helpers.php
                                        // (consolidate_attendance_by_day → span − total_break_minutes), em vez
                                        // de um único par check_in/check_out. O intervalo NÃO conta como
                                        // trabalhado aqui (transparência); o saldo/banco de horas continua no
                                        // modelo "cheio vs cheio" e não é afetado por esta exibição.
                                        $durMin = (int)($day['total_worked_minutes'] ?? 0);
                                        echo ($durMin > 0 || ($day['check_in'] && $day['check_out'])) ? formatMinutes($durMin) : '—';
                                        ?>
                                    </span>
                                </div>

                                <?php
                                // Linha-resumo do DIA (mostra apenas no primeiro card de cada dia).
                                // Modelo "cheio vs cheio": o intervalo CONTA como tempo trabalhado —
                                // aparece apenas como informação ("do total, X foi de intervalo").
                                if (isset($dayMetrics[$date])):
                                    $m = $dayMetrics[$date];
                                ?>
                                <div class="info-item" style="flex-basis:100%;background:#f8f9fb;padding:8px 10px;border-radius:6px;margin-top:6px;">
                                    <i class="bi bi-calculator text-secondary"></i>
                                    <span class="info-label">Resumo do dia:</span>
                                    <span class="info-value">
                                        Trabalhado <strong class="text-success"><?= formatMinutes((int)$day['total_worked_minutes']) ?></strong>
                                        <?php if ((int)$day['total_break_minutes'] > 0): ?>
                                            · <small class="text-muted">intervalo</small>
                                            <strong><?= formatMinutes((int)$day['total_break_minutes']) ?></strong>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php
                                    // Modo 'hours' (motorista, monitor): mostra cumprimento da meta diária
                                    // em vez de comparação com horário esperado.
                                    if ($schedule_mode === 'hours'):
                                        $expDay = calculate_expected_minutes($pdo, $collaborator_id, $date);
                                        if ($expDay > 0):
                                            $pctDay = (int)round(min(100, ($m['effective'] / $expDay) * 100));
                                            $cumprColor = $m['effective'] >= $expDay ? 'success' : 'warning';
                                ?>
                                <div class="info-item" style="flex-basis:100%;background:#f0f9ff;padding:8px 10px;border-radius:6px;margin-top:6px;">
                                    <i class="bi bi-stopwatch text-primary"></i>
                                    <span class="info-label">Meta do dia:</span>
                                    <span class="info-value">
                                        Esperado <strong><?= formatMinutes($expDay) ?></strong>
                                        · Cumprimento <strong class="text-<?= $cumprColor ?>"><?= $pctDay ?>%</strong>
                                    </span>
                                </div>
                                <?php endif; endif; ?>
                                <?php endif; ?>

                                <?php if ($att['school_name']): ?>
                                <div class="info-item">
                                    <i class="bi bi-geo-alt text-primary"></i>
                                    <span class="info-label">Local:</span>
                                    <span class="info-value"><?= htmlspecialchars($att['school_name']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Portaria 671/2021: Link para Comprovante Digital -->
                            <?php if (isset($att['nsr']) && $att['nsr']): ?>
                            <div class="mt-3 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="bi bi-file-earmark-text me-1"></i>
                                        NSR: <strong><?= htmlspecialchars($att['nsr']) ?></strong>
                                    </small>
                                    <a href="receipt.php?id=<?= $att['id'] ?>"
                                       target="_blank"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-file-pdf me-1"></i>
                                        Ver Comprovante
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Rodapé -->
        <div class="text-center mt-4">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Dúvidas sobre seus pontos? Contate o RH/Admin.
            </small>
        </div>
    </div>

    <?php $footerAppBase = $appBase; include __DIR__ . '/_footer.php'; ?>

    <!-- ===================== DRAWER MENU (lateral direita) ===================== -->
    <div class="app-drawer-backdrop" id="appDrawerBackdrop" aria-hidden="true"></div>
    <nav class="app-drawer" id="appDrawer" role="navigation" aria-label="Menu principal" aria-hidden="true">
        <div class="app-drawer-header">
            <div class="app-drawer-title">
                <img src="<?= htmlspecialchars($appBase) ?>/img/logo_login.png" alt="DEEDO Ponto">
            </div>
            <button type="button" class="app-drawer-close" id="btnMenuClose" aria-label="Fechar menu">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="app-drawer-profile">
            <div class="app-drawer-avatar">
                <?= htmlspecialchars(mb_strtoupper(mb_substr($collaborator_name, 0, 1))) ?>
            </div>
            <div class="app-drawer-profile-body">
                <div class="app-drawer-profile-name"><?= htmlspecialchars($collaborator_name) ?></div>
                <div class="app-drawer-profile-cpf">CPF <?= htmlspecialchars(mask_cpf((string)($collaborator['cpf'] ?? ''))) ?></div>
            </div>
        </div>
        <ul class="app-drawer-list" role="menu">
            <li>
                <a class="app-drawer-item" href="<?= htmlspecialchars($appBase) ?>/index.php">
                    <i class="bi bi-clock-history"></i>
                    <span>Bater Ponto</span>
                    <i class="bi bi-chevron-right chev"></i>
                </a>
            </li>
            <li>
                <a class="app-drawer-item is-active" href="<?= htmlspecialchars($appBase) ?>/my_timesheet.php" aria-current="page">
                    <i class="bi bi-calendar-check"></i>
                    <span>Minha Folha</span>
                    <i class="bi bi-chevron-right chev"></i>
                </a>
            </li>
        </ul>
        <div class="app-drawer-divider"></div>
        <ul class="app-drawer-list" role="menu">
            <li>
                <a class="app-drawer-item app-drawer-item-danger" href="?logout=1">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Sair</span>
                    <i class="bi bi-chevron-right chev"></i>
                </a>
            </li>
        </ul>
        <div class="app-drawer-footer">
            <strong><?= htmlspecialchars(SYSTEM_NAME) ?></strong> · v<?= htmlspecialchars(SYSTEM_VERSION) ?>
        </div>
    </nav>

    <!-- Modal de correção de intervalo (work=break) — atende DOIS modos:
         - direct: edição direta (apenas dia atual) via api/edit_own_break.php
         - request: solicitação que admin aprova via api/regularize_break.php -->
    <div class="modal fade" id="fixBreakModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="fixBreakModalTitle">Corrigir intervalo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="fixBreakModalIntro">Ajuste os horários do intervalo. As alterações ficam registradas para auditoria.</p>
                    <form id="fixBreakForm">
                        <input type="hidden" id="fbAttendanceId">
                        <input type="hidden" id="fbDate">
                        <input type="hidden" id="fbMode">
                        <div class="mb-3">
                            <label class="form-label">Início do intervalo</label>
                            <input type="datetime-local" id="fbCheckIn" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Retorno do intervalo</label>
                            <input type="datetime-local" id="fbCheckOut" class="form-control" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Motivo / Justificativa <span class="text-danger">*</span></label>
                            <textarea id="fbJustification" class="form-control" rows="3" maxlength="500" required placeholder="Ex: esqueci de bater o retorno do intervalo"></textarea>
                            <div class="form-text" id="fbJustificationHelp">Obrigatório. Será registrado no histórico de auditoria.</div>
                        </div>
                        <div id="fbError" class="alert alert-danger d-none mt-3" role="alert"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="fbSubmit">
                        <span id="fbSubmitLabel">Salvar correção</span>
                        <span class="spinner-border spinner-border-sm ms-2 d-none" id="fbSpinner" role="status"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/checkout_regularize.js"></script>
    <script>
    // Inicializa modais compartilhados e liga os botoes dos cards.
    (function() {
        const CSRF = <?= json_encode($csrfTokenTS) ?>;
        const API_BASE = <?= json_encode($apiBasePath) ?>;
        if (window.CheckoutRegularize) {
            window.CheckoutRegularize.init({ csrfToken: CSRF, endpoint: API_BASE + '/api/regularize_checkout.php' });
        }
        document.querySelectorAll('.btn-fix-checkout').forEach(btn => {
            btn.addEventListener('click', (ev) => {
                ev.preventDefault();
                const attendanceId = parseInt(btn.dataset.attendanceId, 10);
                if (!attendanceId) return;
                window.CheckoutRegularize.open({
                    attendanceId,
                    date: btn.dataset.date,
                    checkIn: btn.dataset.checkIn,
                    schoolName: btn.dataset.school || null,
                    onSuccess: () => { window.location.reload(); },
                });
            });
        });

        // ===== Correção de INTERVALO (break) =====
        const fbModalEl = document.getElementById('fixBreakModal');
        const fbModal = fbModalEl ? new bootstrap.Modal(fbModalEl) : null;
        const fbTitle = document.getElementById('fixBreakModalTitle');
        const fbIntro = document.getElementById('fixBreakModalIntro');
        const fbAttendanceId = document.getElementById('fbAttendanceId');
        const fbDateEl = document.getElementById('fbDate');
        const fbModeEl = document.getElementById('fbMode');
        const fbCheckIn = document.getElementById('fbCheckIn');
        const fbCheckOut = document.getElementById('fbCheckOut');
        const fbJust = document.getElementById('fbJustification');
        const fbError = document.getElementById('fbError');
        const fbSubmit = document.getElementById('fbSubmit');
        const fbSubmitLabel = document.getElementById('fbSubmitLabel');
        const fbSpinner = document.getElementById('fbSpinner');

        // datetime-local exige formato YYYY-MM-DDTHH:MM (sem segundos).
        function toLocalInput(ts) {
            if (!ts) return '';
            const t = String(ts).replace(' ', 'T');
            return t.substring(0, 16); // corta segundos se vierem
        }

        document.querySelectorAll('.btn-fix-break').forEach(btn => {
            btn.addEventListener('click', (ev) => {
                ev.preventDefault();
                if (!fbModal) return;
                const attendanceId = parseInt(btn.dataset.attendanceId, 10);
                if (!attendanceId) return;
                const mode = btn.dataset.mode || 'direct'; // 'direct' (hoje) | 'request' (passado)
                fbAttendanceId.value = attendanceId;
                fbDateEl.value = btn.dataset.date || '';
                fbModeEl.value = mode;
                fbCheckIn.value = toLocalInput(btn.dataset.checkIn);
                fbCheckOut.value = toLocalInput(btn.dataset.checkOut);
                fbJust.value = '';
                fbError.classList.add('d-none');
                fbError.textContent = '';
                // Reset de estado entre aberturas do modal
                fbCheckIn.readOnly = false;
                fbJust.required = true;
                const fbJustHelp = document.getElementById('fbJustificationHelp');
                if (mode === 'close_open') {
                    fbTitle.textContent = 'Informar volta do intervalo';
                    fbIntro.textContent = 'Você esqueceu de registrar a volta. Informe a hora em que retornou do intervalo — vale na hora, sem aprovação.';
                    fbSubmitLabel.textContent = 'Registrar volta';
                    fbCheckIn.readOnly = true;  // início travado: só a volta é informada
                    fbJust.required = false;    // justificativa opcional
                    if (fbJustHelp) fbJustHelp.textContent = 'Opcional.';
                    if (!fbCheckOut.value) {
                        const n = new Date(); const p = x => String(x).padStart(2, '0');
                        fbCheckOut.value = `${n.getFullYear()}-${p(n.getMonth()+1)}-${p(n.getDate())}T${p(n.getHours())}:${p(n.getMinutes())}`;
                    }
                } else if (mode === 'direct') {
                    fbTitle.textContent = 'Corrigir intervalo (hoje)';
                    fbIntro.textContent = 'Ajuste os horários do intervalo de hoje. A alteração fica registrada no histórico de auditoria.';
                    fbSubmitLabel.textContent = 'Salvar correção';
                    if (fbJustHelp) fbJustHelp.textContent = 'Obrigatório. Será registrado no histórico de auditoria.';
                } else {
                    fbTitle.textContent = 'Solicitar correção de intervalo';
                    fbIntro.textContent = 'Sua proposta será analisada pelo administrador. Você será notificado da aprovação/rejeição.';
                    fbSubmitLabel.textContent = 'Enviar solicitação';
                    if (fbJustHelp) fbJustHelp.textContent = 'Obrigatório. Será registrado no histórico de auditoria.';
                }
                fbModal.show();
            });
        });

        if (fbSubmit) {
            fbSubmit.addEventListener('click', async () => {
                const mode = fbModeEl.value;
                const attendanceId = parseInt(fbAttendanceId.value, 10);
                const checkIn = fbCheckIn.value;
                const checkOut = fbCheckOut.value;
                const justification = (fbJust.value || '').trim();
                if (!attendanceId) return;
                if (mode === 'close_open') {
                    if (!checkOut) {
                        fbError.textContent = 'Informe a hora que você voltou do intervalo.';
                        fbError.classList.remove('d-none');
                        return;
                    }
                } else {
                    if (!checkIn || !checkOut) {
                        fbError.textContent = 'Informe os horários de início e retorno.';
                        fbError.classList.remove('d-none');
                        return;
                    }
                    if (!justification) {
                        fbError.textContent = 'A justificativa é obrigatória.';
                        fbError.classList.remove('d-none');
                        return;
                    }
                }
                fbError.classList.add('d-none');
                fbSubmit.disabled = true;
                fbSpinner.classList.remove('d-none');

                let endpoint, payload;
                if (mode === 'close_open') {
                    endpoint = API_BASE + '/api/close_break.php';
                    payload = { attendance_id: attendanceId, return_time: checkOut, justification, csrf: CSRF };
                } else if (mode === 'direct') {
                    endpoint = API_BASE + '/api/edit_own_break.php';
                    payload = { attendance_id: attendanceId, check_in: checkIn, check_out: checkOut, justification, csrf: CSRF };
                } else {
                    endpoint = API_BASE + '/api/regularize_break.php';
                    payload = { attendance_id: attendanceId, proposed_check_in: checkIn, proposed_check_out: checkOut, justification, csrf: CSRF };
                }

                try {
                    const res = await fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                        body: JSON.stringify(payload),
                    });
                    const data = await res.json();
                    if (!res.ok || !data.ok) {
                        fbError.textContent = data.message || 'Falha ao processar.';
                        fbError.classList.remove('d-none');
                        fbSubmit.disabled = false;
                        fbSpinner.classList.add('d-none');
                        return;
                    }
                    fbModal.hide();
                    window.location.reload();
                } catch (err) {
                    fbError.textContent = 'Erro de rede. Tente novamente.';
                    fbError.classList.remove('d-none');
                    fbSubmit.disabled = false;
                    fbSpinner.classList.add('d-none');
                }
            });
        }
    })();
    </script>
    <script>
    (function() {
        const appDrawer = document.getElementById('appDrawer');
        const appDrawerBackdrop = document.getElementById('appDrawerBackdrop');
        const btnMenuToggle = document.getElementById('btnMenuToggle');
        const btnMenuClose = document.getElementById('btnMenuClose');
        let _prevFocus = null;
        let _scrollLockY = 0;
        let _drawerHistoryPushed = false;

        function drawerOpen() {
            if (!appDrawer) return;
            _prevFocus = document.activeElement;
            // iOS Safari: trava scroll do body via position:fixed (overflow:hidden
            // sozinho não impede momentum/bounce no WebKit).
            _scrollLockY = window.scrollY || window.pageYOffset || 0;
            document.body.style.position = 'fixed';
            document.body.style.top = `-${_scrollLockY}px`;
            document.body.style.left = '0';
            document.body.style.right = '0';
            document.body.style.width = '100%';
            appDrawer.classList.add('is-open');
            appDrawerBackdrop.classList.add('is-open');
            appDrawer.setAttribute('aria-hidden', 'false');
            btnMenuToggle && btnMenuToggle.setAttribute('aria-expanded', 'true');
            document.body.classList.add('drawer-open');
            // Back-button do Android fecha drawer em vez de sair da página.
            try {
                history.pushState({ drawer: true }, '');
                _drawerHistoryPushed = true;
            } catch (_) { _drawerHistoryPushed = false; }
            const firstItem = appDrawer.querySelector('.app-drawer-item');
            if (firstItem) setTimeout(() => firstItem.focus(), 220);
        }
        function drawerClose(fromPopstate) {
            if (!appDrawer) return;
            appDrawer.classList.remove('is-open');
            appDrawerBackdrop.classList.remove('is-open');
            appDrawer.setAttribute('aria-hidden', 'true');
            btnMenuToggle && btnMenuToggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('drawer-open');
            // Restaura scroll preservando posição original.
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.left = '';
            document.body.style.right = '';
            document.body.style.width = '';
            window.scrollTo(0, _scrollLockY);
            if (_drawerHistoryPushed && !fromPopstate) {
                try { history.back(); } catch (_) {}
            }
            _drawerHistoryPushed = false;
            if (_prevFocus && typeof _prevFocus.focus === 'function') {
                try { _prevFocus.focus(); } catch (_) {}
                _prevFocus = null;
            }
        }
        window.addEventListener('popstate', () => {
            if (appDrawer && appDrawer.classList.contains('is-open')) {
                drawerClose(true);
            }
        });
        // H4: bfcache restore limpa o flag — entry de history não volta junto.
        window.addEventListener('pageshow', (e) => {
            if (e.persisted) _drawerHistoryPushed = false;
        });
        if (btnMenuToggle) btnMenuToggle.addEventListener('click', drawerOpen);
        if (btnMenuClose) btnMenuClose.addEventListener('click', drawerClose);
        if (appDrawerBackdrop) appDrawerBackdrop.addEventListener('click', drawerClose);
        document.addEventListener('keydown', (e) => {
            if (!appDrawer || !appDrawer.classList.contains('is-open')) return;
            if (e.key === 'Escape') { e.preventDefault(); drawerClose(); return; }
            if (e.key === 'Tab') {
                const items = Array.from(appDrawer.querySelectorAll('.app-drawer-item, .app-drawer-close'));
                if (items.length === 0) return;
                const first = items[0], last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
    })();
    </script>
</body>
</html>


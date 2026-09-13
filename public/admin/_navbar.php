<?php
/**
 * Navbar responsivo com dropdowns categorizados
 * Incluir em todas as páginas admin após require_admin()
 * 
 * Uso: include __DIR__ . '/_navbar.php';
 */

if (!isset($admin)) {
    $admin = current_admin($pdo ?? db());
}

$curr = basename($_SERVER['PHP_SELF']);
$admName = trim((string)($admin['name'] ?? ''));
if ($admName === '' && !empty($_SESSION['admin_id'])) {
    try {
        $stAdmName = ($pdo ?? db())->prepare("SELECT COALESCE(NULLIF(name, ''), NULLIF(username, '')) AS display_name FROM admins WHERE id = ? LIMIT 1");
        $stAdmName->execute([(int)$_SESSION['admin_id']]);
        $freshName = trim((string)($stAdmName->fetchColumn() ?: ''));
        if ($freshName !== '') {
            $admName = $freshName;
            $_SESSION['admin_name'] = $freshName;
        }
    } catch (Throwable $e) {
    }
}
if ($admName === '') {
    $admName = trim((string)($admin['username'] ?? ''));
}
if ($admName === '') {
    $admName = trim((string)($_SESSION['admin_name'] ?? 'Administrador'));
}
$initial = mb_strtoupper(mb_substr($admName, 0, 1));

// A11y: skip-link permite que usuários de teclado/leitor pulem o navbar.
?>
<a class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary" href="#main-content">Pular para conteúdo</a>
<?php

// Determina categoria ativa para highlighting
$activeCategory = '';
if (in_array($curr, ['teachers.php', 'schools.php', 'admins.php', 'teacher_pin_manage.php'])) {
    $activeCategory = 'gestao';
} elseif (in_array($curr, ['attendances.php', 'leaves.php', 'attendance_manual.php', 'offline_dedupe.php', 'checkout_regularizations.php', 'break_regularizations.php'])) {
    $activeCategory = 'registros';
} elseif (in_array($curr, ['reports_financial.php', 'teacher_monthly_report.php', 'payroll.php', 'audit_log.php', 'migrations.php', 'reports_insights.php', 'reports.php', 'export_afd.php', 'export_aej.php', 'timesheet_mirror.php'])) {
    $activeCategory = 'relatorios';
} elseif (in_array($curr, ['kiosk_dashboard.php', 'kiosk_config.php', 'kiosk_devices.php', 'kiosk_enrollment.php', 'kiosk_logs.php'])) {
    $activeCategory = 'kiosk';
} elseif (in_array($curr, ['collaborator_types.php', 'leave_types.php', 'manual_reasons.php', 'calendar_exceptions.php', 'holidays.php', 'class_periods.php', 'employer_config.php', 'counting_config.php', 'photo_cleanup.php'])) {
    $activeCategory = 'configuracoes';
}
?>

<nav class="navbar navbar-expand-lg navbar-dark mb-4 admin-navbar" style="background-color: #0f172a !important; border-bottom: 4px solid #0162cc !important;">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
            <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:160px;">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <!-- Início -->
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-2 fw-semibold rounded-2 px-3 <?= $curr === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                        <i class="bi bi-house"></i><span>Início</span>
                    </a>
                </li>
                
                <!-- Gestão -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 fw-semibold rounded-2 px-3 <?= $activeCategory === 'gestao' ? 'active' : '' ?>" 
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-people"></i><span>Gestão</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?= $curr === 'teachers.php' ? 'active' : '' ?>" href="teachers.php">
                            <i class="bi bi-person-badge me-2"></i>Colaboradores
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'teacher_pin_manage.php' ? 'active' : '' ?>" href="teacher_pin_manage.php">
                            <i class="bi bi-key me-2"></i>PINs dos Colaboradores
                        </a></li>
                        <?php if (is_network_admin($admin)): ?>
                        <li><a class="dropdown-item <?= $curr === 'schools.php' ? 'active' : '' ?>" href="schools.php">
                            <i class="bi bi-building me-2"></i>Instituições
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'admins.php' ? 'active' : '' ?>" href="admins.php">
                            <i class="bi bi-shield-check me-2"></i>Administradores
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                
                <!-- Registros -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 fw-semibold rounded-2 px-3 <?= $activeCategory === 'registros' ? 'active' : '' ?>" 
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-calendar-check"></i><span>Registros</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?= $curr === 'attendances.php' ? 'active' : '' ?>" href="attendances.php">
                            <i class="bi bi-clock-history me-2"></i>Registros de Ponto
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'leaves.php' ? 'active' : '' ?>" href="leaves.php">
                            <i class="bi bi-person-x me-2"></i>Afastamentos
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'checkout_regularizations.php' ? 'active' : '' ?>" href="checkout_regularizations.php">
                            <i class="bi bi-clock-history me-2"></i>Regularizacoes de Saida
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'break_regularizations.php' ? 'active' : '' ?>" href="break_regularizations.php">
                            <i class="bi bi-pause-circle me-2"></i>Correcoes de Intervalo
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $curr === 'attendance_manual.php' ? 'active' : '' ?>" href="attendance_manual.php">
                            <i class="bi bi-plus-circle me-2"></i>Inserir Ponto Manual
                        </a></li>
                        <?php if (has_permission('attendance.dedupe')): ?>
                        <li><a class="dropdown-item <?= $curr === 'offline_dedupe.php' ? 'active' : '' ?>" href="offline_dedupe.php">
                            <i class="bi bi-collection me-2"></i>Saneamento de Pontos Offline
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- Quiosque -->
                <?php if (has_permission('kiosk.manage')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 fw-semibold rounded-2 px-3 <?= $activeCategory === 'kiosk' ? 'active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-camera-video"></i><span>Quiosque</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?= $curr === 'kiosk_dashboard.php' ? 'active' : '' ?>" href="kiosk_dashboard.php">
                            <i class="bi bi-speedometer2 me-2"></i>Painel
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'kiosk_config.php' ? 'active' : '' ?>" href="kiosk_config.php">
                            <i class="bi bi-gear me-2"></i>Configuração
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'kiosk_devices.php' ? 'active' : '' ?>" href="kiosk_devices.php">
                            <i class="bi bi-tablet me-2"></i>Dispositivos
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'kiosk_enrollment.php' ? 'active' : '' ?>" href="kiosk_enrollment.php">
                            <i class="bi bi-person-bounding-box me-2"></i>Cadastro Facial
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $curr === 'kiosk_logs.php' ? 'active' : '' ?>" href="kiosk_logs.php">
                            <i class="bi bi-clipboard-data me-2"></i>Logs e Auditoria
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Relatórios -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 fw-semibold rounded-2 px-3 <?= $activeCategory === 'relatorios' ? 'active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-graph-up"></i><span>Relatórios</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?= $curr === 'reports_insights.php' ? 'active' : '' ?>" href="reports_insights.php">
                            <i class="bi bi-bar-chart-line me-2"></i>Análises e Rankings
                        </a></li>
                        <li><a class="dropdown-item <?= ($curr === 'reports.php' || $curr === 'teacher_monthly_report.php') ? 'active' : '' ?>" href="reports.php">
                            <i class="bi bi-calendar-month me-2"></i>Relatório Mensal
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $curr === 'reports_financial.php' ? 'active' : '' ?>" href="reports_financial.php">
                            <i class="bi bi-currency-dollar me-2"></i>Financeiro
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'payroll.php' ? 'active' : '' ?>" href="payroll.php">
                            <i class="bi bi-receipt me-2"></i>Holerites
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Fiscalização — Portaria 671</h6></li>
                        <li><a class="dropdown-item <?= $curr === 'export_afd.php' ? 'active' : '' ?>" href="export_afd.php">
                            <i class="bi bi-file-earmark-text me-2"></i>Exportar AFD
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'export_aej.php' ? 'active' : '' ?>" href="export_aej.php">
                            <i class="bi bi-file-earmark-ruled me-2"></i>Exportar AEJ
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'timesheet_mirror.php' ? 'active' : '' ?>" href="timesheet_mirror.php">
                            <i class="bi bi-file-earmark-person me-2"></i>Espelho de Ponto
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $curr === 'audit_log.php' ? 'active' : '' ?>" href="audit_log.php">
                            <i class="bi bi-shield-lock me-2"></i>Log de Auditoria
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'migrations.php' ? 'active' : '' ?>" href="migrations.php">
                            <i class="bi bi-database-gear me-2"></i>Migrações de banco
                        </a></li>
                    </ul>
                </li>
                
                <!-- Configurações -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 fw-semibold rounded-2 px-3 <?= $activeCategory === 'configuracoes' ? 'active' : '' ?>" 
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear"></i><span>Configurações</span>
                    </a>
                    <ul class="dropdown-menu">
                        <?php if (is_network_admin($admin)): ?>
                        <li><a class="dropdown-item <?= $curr === 'employer_config.php' ? 'active' : '' ?>" href="employer_config.php">
                            <i class="bi bi-building me-2"></i>Dados do Empregador
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'counting_config.php' ? 'active' : '' ?>" href="counting_config.php">
                            <i class="bi bi-calendar-range me-2"></i>Início da Contagem
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item <?= $curr === 'collaborator_types.php' ? 'active' : '' ?>" href="collaborator_types.php">
                            <i class="bi bi-person-lines-fill me-2"></i>Tipos de Colaborador
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'class_periods.php' ? 'active' : '' ?>" href="class_periods.php">
                            <i class="bi bi-clock-history me-2"></i>Grade Horária
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'leave_types.php' ? 'active' : '' ?>" href="leave_types.php">
                            <i class="bi bi-list-check me-2"></i>Tipos de Licença
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'manual_reasons.php' ? 'active' : '' ?>" href="manual_reasons.php">
                            <i class="bi bi-pencil-square me-2"></i>Motivos Manuais
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'photo_cleanup.php' ? 'active' : '' ?>" href="photo_cleanup.php">
                            <i class="bi bi-images me-2"></i>Gerenciar Fotos
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $curr === 'calendar_exceptions.php' ? 'active' : '' ?>" href="calendar_exceptions.php">
                            <i class="bi bi-calendar-event me-2"></i>Calendário e Feriados
                        </a></li>
                    </ul>
                </li>
            </ul>
            
            <!-- Desktop: profile dropdown on the right -->
            <ul class="navbar-nav ms-auto align-items-lg-center d-none d-lg-flex">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="profileMenu" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="rounded-circle bg-white text-primary fw-semibold d-inline-flex align-items-center justify-content-center" 
                              style="width:32px;height:32px;">
                            <?= esc($initial) ?>
                        </span>
                        <span><?= esc($admName) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="profileMenu">
                        <li class="dropdown-header">
                            <div class="small text-muted">Sessão</div>
                            <div class="fw-semibold"><?= esc($admName) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i>Sair
                        </a></li>
                    </ul>
                </li>
            </ul>
            
            <!-- Mobile: compact logout button -->
            <div class="d-lg-none w-100 mt-2 pt-3">
                <a href="logout.php" class="btn btn-light w-100">
                    <i class="bi bi-box-arrow-right me-2"></i>Sair
                </a>
            </div>
        </div>
    </div>
</nav>

<?php
// Flash message global (consumido pelo helper flash_redirect()).
if (!empty($_SESSION['admin_flash']) && is_array($_SESSION['admin_flash'])) {
    $__f = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
    $__cls = ['error' => 'danger', 'warning' => 'warning', 'success' => 'success', 'info' => 'info'][$__f['type'] ?? 'info'] ?? 'info';
    $__icon = ['error' => 'bi-x-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'success' => 'bi-check-circle-fill', 'info' => 'bi-info-circle-fill'][$__f['type'] ?? 'info'] ?? 'bi-info-circle-fill';
    echo '<div class="container-fluid mt-3"><div class="alert alert-' . $__cls
       . ' alert-dismissible fade show d-flex align-items-center gap-2" role="alert">'
       . '<i class="bi ' . $__icon . ' fs-5"></i><div class="flex-grow-1">' . esc((string)($__f['msg'] ?? '')) . '</div>'
       . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button></div></div>';
}
?>

<script>
// Double-submit protection global: desabilita botão de submit por 4s no submit do form.
// Forms que precisam de comportamento próprio devem adicionar `data-no-submit-lock` no <form>.
(function(){
  function lock(form){
    if (form.hasAttribute('data-no-submit-lock')) return;
    var btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (!btn || btn.disabled) return;
    btn.dataset.origText = btn.innerHTML;
    setTimeout(function(){
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processando…';
    }, 0);
    setTimeout(function(){
      // Reabilita após 4s caso a página não tenha recarregado (ex: erro de rede)
      btn.disabled = false;
      if (btn.dataset.origText) btn.innerHTML = btn.dataset.origText;
    }, 4000);
  }
  document.addEventListener('submit', function(e){
    var f = e.target;
    if (f && f.tagName === 'FORM' && /post/i.test(f.method || '')) lock(f);
  }, true);
})();
</script>

<style>
/* Custom Admin Navbar Branding */
.admin-navbar {
    background-color: #0f172a !important; /* Fallback direct color for dark slate */
    border-bottom: 4px solid #0162cc !important;
}

.admin-navbar .nav-link {
    font-weight: 500;
    letter-spacing: 0.02em;
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
    transition: all 0.2s ease;
}

.admin-navbar .navbar-brand img {
    filter: brightness(0) invert(1);
}

/* Mobile dropdown styling */
@media (max-width: 991.98px) {
    .navbar-nav .dropdown-menu {
        border: none;
        padding-left: 1rem;
        background: rgba(255,255,255,.05);
        box-shadow: none;
    }
    .dropdown-item {
        color: rgba(255,255,255,.85);
        padding: 0.5rem 1rem;
    }
    .dropdown-item:hover,
    .dropdown-item:focus {
        background: rgba(255,255,255,.1);
        color: #fff;
    }
    .dropdown-item.active {
        background: rgba(255,255,255,.15);
        color: #fff;
        font-weight: 600;
        border-left: 2px solid #0162cc;
    }
    .dropdown-divider {
        border-color: rgba(255,255,255,.1);
    }
}

/* Desktop dropdown styling */
@media (min-width: 992px) {
    .dropdown-menu {
        border-radius: 1rem;
        border: 1px solid #e2e8f0;
        box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.1);
        margin-top: 0.5rem;
        padding: 0.5rem;
    }
    .dropdown-item {
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        transition: all 0.2s;
        color: #64748b;
    }
    .dropdown-item:hover {
        background-color: #f1f5f9;
        color: #0f172a;
        padding-left: 1.25rem;
    }
    .dropdown-item.active {
        background-color: #ddeeff;
        color: #003d7a;
        font-weight: 600;
    }
}
</style>
<?php
if (!function_exists('esc')) {
    function esc($str) {
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>

<?php
$content = <<<'EOD'
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
$admName = trim((string)($_SESSION['admin_name'] ?? 'Administrador'));
$initial = mb_strtoupper(mb_substr($admName, 0, 1));

// Determina categoria ativa para highlighting
$activeCategory = '';
if (in_array($curr, ['teachers.php', 'schools.php', 'admins.php'])) {
    $activeCategory = 'gestao';
} elseif (in_array($curr, ['attendances.php', 'leaves.php', 'overtime.php', 'attendance_manual.php'])) {
    $activeCategory = 'registros';
} elseif (in_array($curr, ['reports_financial.php', 'teacher_monthly_report.php', 'payroll.php', 'audit_log.php'])) {
    $activeCategory = 'relatorios';
} elseif (in_array($curr, ['collaborator_types.php', 'leave_types.php', 'manual_reasons.php', 'calendar_exceptions.php', 'holidays.php', 'class_periods.php'])) {
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
                        <li><a class="dropdown-item <?= $curr === 'overtime.php' ? 'active' : '' ?>" href="overtime.php">
                            <i class="bi bi-clock me-2"></i>Horas Extras
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $curr === 'attendance_manual.php' ? 'active' : '' ?>" href="attendance_manual.php">
                            <i class="bi bi-plus-circle me-2"></i>Inserir Ponto Manual
                        </a></li>
                    </ul>
                </li>
                
                <!-- Relatórios -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 fw-semibold rounded-2 px-3 <?= $activeCategory === 'relatorios' ? 'active' : '' ?>" 
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-graph-up"></i><span>Relatórios</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?= $curr === 'reports_financial.php' ? 'active' : '' ?>" href="reports_financial.php">
                            <i class="bi bi-currency-dollar me-2"></i>Financeiro
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'teacher_monthly_report.php' ? 'active' : '' ?>" href="teacher_monthly_report.php">
                            <i class="bi bi-calendar-month me-2"></i>Mensal
                        </a></li>
                        <li><a class="dropdown-item <?= $curr === 'payroll.php' ? 'active' : '' ?>" href="payroll.php">
                            <i class="bi bi-receipt me-2"></i>Holerites
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $curr === 'audit_log.php' ? 'active' : '' ?>" href="audit_log.php">
                            <i class="bi bi-shield-lock me-2"></i>Log de Auditoria
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
EOD;

file_put_contents('c:/xampp/htdocs/ponto_ribeira/public/admin/_navbar.php', $content);
echo "File rewritten successfully!\n";
?>

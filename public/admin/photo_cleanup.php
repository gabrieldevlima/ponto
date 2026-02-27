<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);

$msg = '';
$error = '';
$cleanupStats = null;

// Executar limpeza manual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    
    if ($_POST['action'] === 'cleanup_now') {
        try {
            $force = isset($_POST['force']) && $_POST['force'] === '1';
            $cleanupStats = cleanup_old_photos($pdo, $force);
            
            if ($cleanupStats['status'] === 'skipped') {
                $msg = 'Limpeza não executada: limite de armazenamento ainda não atingido.';
            } else {
                $msg = sprintf(
                    'Limpeza concluída! %d fotos deletadas, %.2f MB liberados.',
                    $cleanupStats['deleted_count'],
                    $cleanupStats['freed_space_mb']
                );
            }
        } catch (Throwable $e) {
            $error = 'Erro ao executar limpeza: ' . $e->getMessage();
            error_log($error);
        }
    }
}

// Estatísticas atuais
$photosDir = __DIR__ . '/../../public/photos/';
$currentSize = get_directory_size($photosDir);
$currentSizeMB = round($currentSize / 1024 / 1024, 2);
$thresholdMB = defined('PHOTO_STORAGE_THRESHOLD_MB') ? PHOTO_STORAGE_THRESHOLD_MB : 500;
$retentionDays = defined('PHOTO_RETENTION_DAYS') ? PHOTO_RETENTION_DAYS : 90;
$cleanupEnabled = defined('PHOTO_CLEANUP_ENABLED') ? PHOTO_CLEANUP_ENABLED : false;
$percentUsed = $thresholdMB > 0 ? round(($currentSizeMB / $thresholdMB) * 100, 1) : 0;

// Total de fotos no banco
$stmt = $pdo->query("SELECT COUNT(*) as total FROM attendance WHERE photo IS NOT NULL AND photo != ''");
$totalPhotos = $stmt->fetchColumn();

// Fotos não deletadas
$stmt = $pdo->query("SELECT COUNT(*) as active FROM attendance WHERE photo IS NOT NULL AND photo != '' AND photo_deleted = 0");
$activePhotos = $stmt->fetchColumn();

// Fotos deletadas
$stmt = $pdo->query("SELECT COUNT(*) as deleted FROM attendance WHERE photo_deleted = 1");
$deletedPhotos = $stmt->fetchColumn();

// Fotos deletadas nos últimos 30 dias
$stmt = $pdo->query("SELECT COUNT(*) as recent FROM attendance WHERE photo_deleted = 1 AND photo_deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$recentDeleted = $stmt->fetchColumn();

// Data de corte para limpeza
$cutoffDate = date('Y-m-d', strtotime("-{$retentionDays} days"));

// Fotos elegíveis para limpeza
$stmt = $pdo->prepare("
    SELECT COUNT(*) as eligible 
    FROM attendance 
    WHERE photo IS NOT NULL 
      AND photo != ''
      AND photo_deleted = 0 
      AND date < ?
");
$stmt->execute([$cutoffDate]);
$eligiblePhotos = $stmt->fetchColumn();

// Histórico recente de exclusões
$recentDeletions = $pdo->query("
    SELECT 
        t.name as teacher_name,
        a.date,
        a.photo,
        a.photo_deleted_at
    FROM attendance a
    JOIN teachers t ON t.id = a.teacher_id
    WHERE a.photo_deleted = 1
    ORDER BY a.photo_deleted_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Gerenciar Fotos | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>
<body>

<?php include '_navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col">
            <h2><i class="bi bi-images"></i> Gerenciamento de Fotos</h2>
            <p class="text-muted">Controle de armazenamento e limpeza automática de fotos</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= esc($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i> <?= esc($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-6 text-white-50">Armazenamento Usado</div>
                            <div class="fs-3 fw-bold"><?= $currentSizeMB ?> MB</div>
                            <div class="small">Limite: <?= $thresholdMB ?> MB (<?= $percentUsed ?>%)</div>
                        </div>
                        <i class="bi bi-hdd-stack fs-1 opacity-50"></i>
                    </div>
                    <div class="progress mt-2" style="height: 6px;">
                        <div class="progress-bar bg-white" role="progressbar" style="width: <?= min(100, $percentUsed) ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-6 text-white-50">Fotos Ativas</div>
                            <div class="fs-3 fw-bold"><?= number_format($activePhotos) ?></div>
                            <div class="small">Total: <?= number_format($totalPhotos) ?></div>
                        </div>
                        <i class="bi bi-camera fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-6 text-white-50">Fotos Deletadas</div>
                            <div class="fs-3 fw-bold"><?= number_format($deletedPhotos) ?></div>
                            <div class="small">Últimos 30 dias: <?= number_format($recentDeleted) ?></div>
                        </div>
                        <i class="bi bi-trash fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white <?= $eligiblePhotos > 0 ? 'bg-danger' : 'bg-secondary' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-6 text-white-50">Elegíveis p/ Limpeza</div>
                            <div class="fs-3 fw-bold"><?= number_format($eligiblePhotos) ?></div>
                            <div class="small">Anteriores a <?= date('d/m/Y', strtotime($cutoffDate)) ?></div>
                        </div>
                        <i class="bi bi-clock-history fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Configurações e Ações -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-gear"></i> Configurações Atuais</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr>
                                <td class="fw-semibold">Limpeza Automática:</td>
                                <td>
                                    <?php if ($cleanupEnabled): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Ativada</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-x-circle"></i> Desativada</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-semibold">Período de Retenção:</td>
                                <td><?= $retentionDays ?> dias</td>
                            </tr>
                            <tr>
                                <td class="fw-semibold">Limite de Armazenamento:</td>
                                <td><?= $thresholdMB ?> MB</td>
                            </tr>
                            <tr>
                                <td class="fw-semibold">Data de Corte Atual:</td>
                                <td><?= date('d/m/Y', strtotime($cutoffDate)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-info-circle"></i> 
                        <small>
                            <strong>Como funciona:</strong> A limpeza automática é executada durante o upload de fotos. 
                            Fotos com mais de <?= $retentionDays ?> dias são deletadas quando o armazenamento ultrapassa <?= $thresholdMB ?> MB.
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-play-circle"></i> Executar Limpeza Manual</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">Execute a limpeza manualmente para liberar espaço imediatamente.</p>
                    
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="action" value="cleanup_now">
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="force" value="1" id="forceCleanup">
                                <label class="form-check-label" for="forceCleanup">
                                    <strong>Forçar limpeza</strong>
                                    <small class="text-muted d-block">Executar mesmo que o limite de armazenamento não tenha sido atingido</small>
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Tem certeza que deseja executar a limpeza agora? Fotos com mais de <?= $retentionDays ?> dias serão deletadas permanentemente.')">
                            <i class="bi bi-trash"></i> Executar Limpeza Agora
                        </button>
                    </form>
                    
                    <?php if ($cleanupStats): ?>
                        <div class="alert alert-secondary mt-3 mb-0">
                            <strong>Último Resultado:</strong><br>
                            <ul class="mb-0 mt-2">
                                <li>Fotos deletadas: <?= $cleanupStats['deleted_count'] ?? 0 ?></li>
                                <li>Espaço liberado: <?= $cleanupStats['freed_space_mb'] ?? 0 ?> MB</li>
                                <li>Tamanho anterior: <?= $cleanupStats['previous_size_mb'] ?? 0 ?> MB</li>
                                <li>Tamanho atual: <?= $cleanupStats['current_size_mb'] ?? 0 ?> MB</li>
                                <?php if (!empty($cleanupStats['errors'])): ?>
                                    <li class="text-danger">Erros: <?= count($cleanupStats['errors']) ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Histórico de Exclusões -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Histórico de Exclusões (Últimas 20)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($recentDeletions)): ?>
                <p class="text-muted mb-0">Nenhuma foto foi deletada automaticamente ainda.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Colaborador</th>
                                <th>Data do Registro</th>
                                <th>Arquivo</th>
                                <th>Deletada Em</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentDeletions as $row): ?>
                                <tr>
                                    <td><?= esc($row['teacher_name']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                                    <td><code class="small"><?= esc($row['photo']) ?></code></td>
                                    <td><?= $row['photo_deleted_at'] ? date('d/m/Y H:i', strtotime($row['photo_deleted_at'])) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Dicas -->
    <div class="card mt-4 mb-4 border-info">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-lightbulb"></i> Dicas de Gerenciamento</h5>
        </div>
        <div class="card-body">
            <ul class="mb-0">
                <li><strong>Conformidade Legal:</strong> Para atender a Portaria MTP 671/2021, configure retenção de <strong>1825 dias (5 anos)</strong>.</li>
                <li><strong>Economia Agressiva:</strong> Para ambientes com pouco armazenamento, use <strong>30 dias</strong> de retenção.</li>
                <li><strong>Padrão Recomendado:</strong> <strong>90 dias</strong> equilibra conformidade com economia de espaço.</li>
                <li><strong>Alterar Configurações:</strong> Edite as constantes em <code>config.php</code>:
                    <ul>
                        <li><code>PHOTO_RETENTION_DAYS</code> - Dias de retenção</li>
                        <li><code>PHOTO_STORAGE_THRESHOLD_MB</code> - Limite de armazenamento</li>
                        <li><code>PHOTO_CLEANUP_ENABLED</code> - Ativar/desativar limpeza</li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


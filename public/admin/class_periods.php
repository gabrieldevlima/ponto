<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers.php';

require_admin();
$pdo = db();
$admin = current_admin($pdo);

// Tratamento de ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $schoolId = !empty($_POST['school_id']) ? (int)$_POST['school_id'] : null;
        $periodNumber = (int)$_POST['period_number'];
        $startTime = $_POST['start_time'];
        $endTime = $_POST['end_time'];
        $active = isset($_POST['active']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("INSERT INTO class_periods (school_id, period_number, start_time, end_time, active) 
                                   VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$schoolId, $periodNumber, $startTime, $endTime, $active]);
            
            audit_log('create', 'class_period', $pdo->lastInsertId(), [
                'school_id' => $schoolId,
                'period_number' => $periodNumber,
                'start_time' => $startTime,
                'end_time' => $endTime
            ]);
            
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Período criado com sucesso!'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Erro ao criar período: ' . $e->getMessage()];
        }
        
        header('Location: class_periods.php');
        exit;
    }
    
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $schoolId = !empty($_POST['school_id']) ? (int)$_POST['school_id'] : null;
        $periodNumber = (int)$_POST['period_number'];
        $startTime = $_POST['start_time'];
        $endTime = $_POST['end_time'];
        $active = isset($_POST['active']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("UPDATE class_periods 
                                   SET school_id = ?, period_number = ?, start_time = ?, end_time = ?, active = ?
                                   WHERE id = ?");
            $stmt->execute([$schoolId, $periodNumber, $startTime, $endTime, $active, $id]);
            
            audit_log('update', 'class_period', $id, [
                'school_id' => $schoolId,
                'period_number' => $periodNumber,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'active' => $active
            ]);
            
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Período atualizado com sucesso!'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Erro ao atualizar período: ' . $e->getMessage()];
        }
        
        header('Location: class_periods.php');
        exit;
    }
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            // Verifica se há atribuições usando este período
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM teacher_class_assignments WHERE period_id = ?");
            $stmt->execute([$id]);
            $count = (int)$stmt->fetchColumn();
            
            if ($count > 0) {
                $_SESSION['flash'] = ['type' => 'warning', 'msg' => "Não é possível excluir. Existem $count atribuições usando este período."];
            } else {
                $stmt = $pdo->prepare("DELETE FROM class_periods WHERE id = ?");
                $stmt->execute([$id]);
                
                audit_log('delete', 'class_period', $id, []);
                
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Período excluído com sucesso!'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Erro ao excluir período: ' . $e->getMessage()];
        }
        
        header('Location: class_periods.php');
        exit;
    }
}

// Busca escolas para filtro/seleção
$schools = [];
$stSchools = $pdo->query("SELECT id, name FROM schools WHERE active = 1 ORDER BY name");
while ($row = $stSchools->fetch(PDO::FETCH_ASSOC)) {
    $schools[$row['id']] = $row['name'];
}

// Filtro por escola
$filterSchoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : null;

// Busca períodos
$sql = "SELECT cp.*, s.name as school_name
        FROM class_periods cp
        LEFT JOIN schools s ON s.id = cp.school_id
        WHERE 1=1";

$params = [];

if ($filterSchoolId > 0) {
    $sql .= " AND cp.school_id = ?";
    $params[] = $filterSchoolId;
} elseif ($filterSchoolId === 0) {
    $sql .= " AND cp.school_id IS NULL";
}

$sql .= " ORDER BY cp.school_id IS NULL DESC, cp.period_number";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Período sendo editado
$editingPeriod = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM class_periods WHERE id = ?");
    $stmt->execute([$editId]);
    $editingPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
}

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Grade Horária | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>
<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <div class="container-fluid admin-content">

<div class="app-page-header">
    <div class="app-page-header__main">
        <div class="app-page-icon"><i class="bi bi-clock-history"></i></div>
        <div>
            <h1 class="app-page-title">Grade Horária</h1>
            <p class="app-page-subtitle">Gerencie os períodos de aula da instituição.</p>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['flash'])): ?>
    <div class="alert alert-<?= esc($_SESSION['flash']['type']) ?> alert-dismissible fade show">
        <?= esc($_SESSION['flash']['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-md-4">
        <section class="app-section-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-<?= $editingPeriod ? 'pencil-square' : 'plus-circle' ?>"></i><?= $editingPeriod ? 'Edição' : 'Novo' ?></span>
                <h2 class="app-section-card__title"><?= $editingPeriod ? 'Editar Período' : 'Novo Período' ?></h2>
            </header>
            <div class="app-section-card__body">
                <form action="" method="post">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="<?= $editingPeriod ? 'update' : 'create' ?>">
                    <?php if ($editingPeriod): ?>
                        <input type="hidden" name="id" value="<?= $editingPeriod['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Escola</label>
                        <select name="school_id" class="form-select">
                            <option value="">Global (todas as escolas)</option>
                            <?php foreach ($schools as $sid => $sname): ?>
                                <option value="<?= $sid ?>" <?= (isset($editingPeriod) && $editingPeriod['school_id'] == $sid) ? 'selected' : '' ?>>
                                    <?= esc($sname) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Deixe em branco para aplicar a todas as escolas</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Número do Período *</label>
                        <input type="number" name="period_number" class="form-control" min="1" max="20" 
                               value="<?= $editingPeriod['period_number'] ?? '' ?>" required>
                        <small class="text-muted">Ex: 1 (primeira aula), 2 (segunda aula), etc.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Horário de Início *</label>
                        <input type="time" name="start_time" class="form-control" 
                               value="<?= $editingPeriod['start_time'] ?? '' ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Horário de Fim *</label>
                        <input type="time" name="end_time" class="form-control" 
                               value="<?= $editingPeriod['end_time'] ?? '' ?>" required>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="active" class="form-check-input" id="active" 
                               <?= (!isset($editingPeriod) || $editingPeriod['active']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Ativo</label>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> <?= $editingPeriod ? 'Atualizar' : 'Criar' ?>
                        </button>
                        <?php if ($editingPeriod): ?>
                            <a href="class_periods.php" class="btn btn-secondary">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <div class="col-md-8">
        <section class="app-section-card app-table-card">
            <header class="app-section-card__header">
                <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-list-ul"></i>Lista</span>
                <h2 class="app-section-card__title">Períodos Cadastrados</h2>
                <span class="app-section-card__hint">
                    <select class="form-select form-select-sm" onchange="location.href='?school_id='+this.value" aria-label="Filtrar por escola">
                        <option value="">Todos</option>
                        <option value="0" <?= $filterSchoolId === 0 ? 'selected' : '' ?>>Apenas Globais</option>
                        <?php foreach ($schools as $sid => $sname): ?>
                            <option value="<?= $sid ?>" <?= $filterSchoolId === $sid ? 'selected' : '' ?>>
                                <?= esc($sname) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </header>
            <div>
                <?php if (empty($periods)): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                        <p class="mb-0">Nenhum período cadastrado</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Período</th>
                                    <th scope="col">Horário</th>
                                    <th scope="col">Escola</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($periods as $period): ?>
                                    <tr>
                                        <td><strong><?= $period['period_number'] ?>º</strong></td>
                                        <td>
                                            <?= date('H:i', strtotime($period['start_time'])) ?> -
                                            <?= date('H:i', strtotime($period['end_time'])) ?>
                                            <?php
                                                // Duração tolerante a período que cruza meia-noite (raro mas seguro).
                                                $sTs = strtotime($period['start_time']);
                                                $eTs = strtotime($period['end_time']);
                                                if ($eTs <= $sTs) $eTs += 86400;
                                            ?>
                                            <small class="text-muted">
                                                (<?= round(($eTs - $sTs) / 60) ?> min)
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($period['school_id']): ?>
                                                <span class="badge bg-info"><?= esc($period['school_name']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Global</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($period['active']): ?>
                                                <span class="badge bg-success">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?edit=<?= $period['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form action="" method="post" style="display: inline;" 
                                                  onsubmit="return confirm('Tem certeza que deseja excluir este período?')">
                                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $period['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="alert alert-info mt-3">
            <strong><i class="bi bi-info-circle"></i> Dica:</strong>
            Períodos globais são aplicados a todas as escolas. Você pode criar períodos específicos por escola se necessário.
            Após criar os períodos, vá até a edição do professor para atribuir em quais períodos ele tem aula.
        </div>
    </div>
</div>

  </div> <!-- container-fluid -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
</html>


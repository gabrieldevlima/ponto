<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$admin = current_admin($pdo);

// Only network admins can manage teacher-school associations
if (!is_network_admin($admin)) {
    http_response_code(403);
    exit('Acesso negado. Apenas administradores de rede podem gerenciar vínculos de escola.');
}

$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$messages = [];

if ($teacher_id <= 0) {
    http_response_code(400);
    exit('ID do professor é obrigatório.');
}

// Get teacher info
$stmt = $pdo->prepare("SELECT id, name, email FROM teachers WHERE id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();
if (!$teacher) {
    http_response_code(404);
    exit('Professor não encontrado.');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_schools') {
        $school_ids = $_POST['school_ids'] ?? [];
        $school_ids = array_map('intval', array_filter($school_ids));
        
        try {
            $pdo->beginTransaction();
            
            // Remove existing associations
            $pdo->prepare("DELETE FROM teacher_schools WHERE teacher_id = ?")->execute([$teacher_id]);
            
            // Add new associations
            if (!empty($school_ids)) {
                $stmt = $pdo->prepare("INSERT INTO teacher_schools (teacher_id, school_id) VALUES (?, ?)");
                foreach ($school_ids as $school_id) {
                    $stmt->execute([$teacher_id, $school_id]);
                }
            }
            
            $pdo->commit();
            $messages[] = 'Vínculos de escola atualizados com sucesso.';
            
        } catch (Exception $e) {
            $pdo->rollback();
            $messages[] = 'Erro ao atualizar vínculos: ' . $e->getMessage();
        }
    }
}

// Get all schools
$schools = $pdo->query("SELECT id, name FROM schools WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get current teacher's schools
$stmt = $pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id = ?");
$stmt->execute([$teacher_id]);
$teacherSchools = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$csrf = csrf_token();
?>

<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Vincular Escolas - <?= esc($teacher['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php">
        <img src="../img/logo.png" alt="Logo da Empresa" style="height:auto;max-width:130px;">
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-house"></i> Início</a></li>
          <li class="nav-item"><a class="nav-link" href="attendances.php"><i class="bi bi-calendar-check"></i> Registros de Ponto</a></li>
          <li class="nav-item"><a class="nav-link" href="teachers.php"><i class="bi bi-person-badge"></i> Colaboradores</a></li>
          <li class="nav-item"><a class="nav-link" href="leaves.php"><i class="bi bi-person-x"></i> Afastamentos</a></li>
          <li class="nav-item"><a class="nav-link" href="schools.php"><i class="bi bi-building"></i> Instituições</a></li>
          <li class="nav-item"><a class="nav-link" href="admins.php"><i class="bi bi-people"></i> Administradores</a></li>
          <li class="nav-item"><a class="nav-link" href="attendance_manual.php"><i class="bi bi-plus-circle"></i> Inserir Ponto Manual</a></li>
        </ul>
        <span class="navbar-text me-3 d-none d-lg-inline">
          <i class="bi bi-person-circle"></i>
          <?= esc($_SESSION['admin_name'] ?? 'Administrador') ?>
        </span>
        <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="mb-0"><i class="bi bi-building me-2"></i> Vincular Escolas - <?= esc($teacher['name']) ?></h4>
        <a href="teachers.php" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left"></i> Voltar
        </a>
      </div>
      <div class="card-body">
        <?php foreach ($messages as $msg): ?>
          <div class="alert alert-info"><?= esc($msg) ?></div>
        <?php endforeach; ?>

        <div class="row">
          <div class="col-md-6">
            <h6>Informações do Professor</h6>
            <p><strong>Nome:</strong> <?= esc($teacher['name']) ?></p>
            <p><strong>Email:</strong> <?= esc($teacher['email']) ?></p>
          </div>
          <div class="col-md-6">
            <h6>Escolas Vinculadas</h6>
            <p class="text-muted">Selecione as escolas às quais este professor está vinculado.</p>
          </div>
        </div>

        <hr>

        <form method="post" action="">
          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
          <input type="hidden" name="action" value="update_schools">
          
          <div class="row">
            <?php if (empty($schools)): ?>
              <div class="col-12">
                <div class="alert alert-warning">
                  <i class="bi bi-exclamation-triangle"></i>
                  Nenhuma escola ativa encontrada. 
                  <a href="schools.php" class="alert-link">Cadastre escolas primeiro</a>.
                </div>
              </div>
            <?php else: ?>
              <div class="col-md-6">
                <h6>Escolas Disponíveis</h6>
                <div class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                  <?php foreach ($schools as $school): ?>
                    <div class="form-check">
                      <input class="form-check-input" 
                             type="checkbox" 
                             name="school_ids[]" 
                             value="<?= (int)$school['id'] ?>"
                             id="school_<?= (int)$school['id'] ?>"
                             <?= in_array((int)$school['id'], $teacherSchools) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="school_<?= (int)$school['id'] ?>">
                        <?= esc($school['name']) ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                
                <div class="mt-3">
                  <button type="button" class="btn btn-sm btn-outline-primary me-2" onclick="selectAll()">
                    <i class="bi bi-check-all"></i> Selecionar Todas
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectNone()">
                    <i class="bi bi-x-circle"></i> Desmarcar Todas
                  </button>
                </div>
              </div>
              
              <div class="col-md-6">
                <h6>Resumo</h6>
                <div class="alert alert-light">
                  <p class="mb-2"><strong>Professor:</strong> <?= esc($teacher['name']) ?></p>
                  <p class="mb-2">
                    <strong>Escolas selecionadas:</strong> 
                    <span id="selected-count"><?= count($teacherSchools) ?></span>
                  </p>
                  <p class="mb-0 text-muted small">
                    Marque as escolas onde este professor pode trabalhar. 
                    Isso afeta os filtros e relatórios no sistema.
                  </p>
                </div>
                
                <div class="d-grid gap-2">
                  <button type="submit" class="btn btn-primary">
                    <i class="bi bi-floppy"></i> Salvar Vínculos
                  </button>
                  <a href="teacher_edit.php?id=<?= (int)$teacher_id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-pencil"></i> Editar Professor
                  </a>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function selectAll() {
      const checkboxes = document.querySelectorAll('input[name="school_ids[]"]');
      checkboxes.forEach(cb => cb.checked = true);
      updateCount();
    }
    
    function selectNone() {
      const checkboxes = document.querySelectorAll('input[name="school_ids[]"]');
      checkboxes.forEach(cb => cb.checked = false);
      updateCount();
    }
    
    function updateCount() {
      const checked = document.querySelectorAll('input[name="school_ids[]"]:checked').length;
      document.getElementById('selected-count').textContent = checked;
    }
    
    // Update count when checkboxes change
    document.addEventListener('change', function(e) {
      if (e.target.name === 'school_ids[]') {
        updateCount();
      }
    });
  </script>
</body>
</html>
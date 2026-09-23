<?php
require_once __DIR__ . '/../../config.php';
require_admin();
if (!has_permission('kiosk.manage')) {
    http_response_code(403);
    flash_redirect('error', 'Sem permissão para gerenciar o quiosque.', 'dashboard.php');
}
$pdo = db();

// Remover cadastro facial (limpa os descritores locais).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['act'] ?? '') === 'remove') {
        $tid = (int)($_POST['id'] ?? 0);
        if ($tid > 0) {
            $pdo->prepare("UPDATE teachers SET face_descriptors = NULL, face_enrolled_at = NULL WHERE id = ?")->execute([$tid]);
            audit_log('update', 'teacher', $tid, ['action' => 'kiosk_face_removed']);
            flash_redirect('success', 'Cadastro facial removido.', 'kiosk_enrollment.php');
        }
    }
}

$minDesc = function_exists('get_min_face_descriptors_for_auth') ? get_min_face_descriptors_for_auth() : 3;
$fName = trim((string)($_GET['q'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));

$where = ['active = 1'];
$args = [];
if ($fName !== '') { $where[] = 'name LIKE ?'; $args[] = '%' . $fName . '%'; }
$whereSql = 'WHERE ' . implode(' AND ', $where);
$st = $pdo->prepare("SELECT id, name, cpf, face_descriptors, face_enrolled_at FROM teachers $whereSql ORDER BY name LIMIT 500");
$st->execute($args);
$all = $st->fetchAll(PDO::FETCH_ASSOC);

// Calcula status por colaborador a partir dos descritores armazenados.
$descCount = function ($json): int {
    $d = json_decode((string)$json, true);
    if (!is_array($d)) return 0;
    $n = 0;
    foreach ($d as $r) { if (is_array($r) && count($r) === 128) $n++; }
    return $n;
};
$rows = [];
$counts = ['enrolled' => 0, 'partial' => 0, 'none' => 0];
foreach ($all as $t) {
    $n = $descCount($t['face_descriptors'] ?? null);
    $status = $n >= $minDesc ? 'enrolled' : ($n > 0 ? 'partial' : 'none');
    $counts[$status]++;
    if ($fStatus === '' || $fStatus === $status) {
        $t['_count'] = $n; $t['_status'] = $status; $rows[] = $t;
    }
}

function enroll_badge2(string $s, int $n, int $min): string {
    if ($s === 'enrolled') return '<span class="badge bg-success rounded-pill"><i class="bi bi-check-lg"></i> Cadastrado (' . $n . ')</span>';
    if ($s === 'partial')  return '<span class="badge bg-warning text-dark rounded-pill">Parcial (' . $n . '/' . $min . ')</span>';
    return '<span class="badge bg-secondary rounded-pill">Sem cadastro</span>';
}
$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Quiosque · Cadastro Facial | DEEDO Ponto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
    <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../js/face-api.min.js"></script>
    <style>
      #enrollVideo{width:100%;max-width:320px;border-radius:14px;transform:scaleX(-1);background:#000;aspect-ratio:1;object-fit:cover;}
      .frame-thumbs{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;justify-content:center;}
      .frame-thumbs img{width:56px;height:56px;border-radius:8px;object-fit:cover;border:2px solid #cbd5e1;transform:scaleX(-1);}
    </style>
</head>
<body>
    <?php include __DIR__ . '/_navbar.php'; ?>
    <div class="container-fluid admin-content" id="main-content">
        <div class="app-page-header"><div class="app-page-header__main"><div class="app-page-icon"><i class="bi bi-person-bounding-box"></i></div><div><h1 class="app-page-title">Quiosque · Cadastro Facial</h1><p class="app-page-subtitle">Cadastro local (face-api.js): capture <?= (int)$minDesc ?>–5 fotos por colaborador. Nada é enviado a serviços externos.</p></div></div></div>

        <?php if ($msg): ?><div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><div><?= esc($msg) ?></div><button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <section class="app-section-card mb-3">
            <div class="app-section-card__body">
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <span class="badge bg-success rounded-pill px-3 py-2">Cadastrados: <?= $counts['enrolled'] ?></span>
                    <span class="badge bg-warning text-dark rounded-pill px-3 py-2">Parciais: <?= $counts['partial'] ?></span>
                    <span class="badge bg-secondary rounded-pill px-3 py-2">Sem cadastro: <?= $counts['none'] ?></span>
                </div>
                <form class="row g-2 align-items-end" method="get">
                    <div class="col-md-3"><label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <?php foreach (['none'=>'Sem cadastro','partial'=>'Parcial','enrolled'=>'Cadastrado'] as $k=>$v): ?><option value="<?= $k ?>" <?= $fStatus===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label small mb-1">Colaborador</label><input type="text" name="q" class="form-control form-control-sm" value="<?= esc($fName) ?>" placeholder="Nome"></div>
                    <div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrar</button></div>
                </form>
            </div>
        </section>

        <section class="app-section-card app-table-card">
            <header class="app-section-card__header"><span class="app-section-card__eyebrow"><i class="bi bi-people"></i>Colaboradores</span><h2 class="app-section-card__title">Cadastro Facial</h2><span class="app-section-card__hint"><?= count($rows) ?> colaborador(es)</span></header>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm mb-0">
                    <thead class="table-light"><tr><th>Colaborador</th><th>CPF</th><th class="text-center">Status</th><th>Atualizado</th><th class="text-center">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $t): ?>
                        <tr>
                            <td class="fw-semibold"><?= esc($t['name']) ?></td>
                            <td class="small text-muted"><?= esc(function_exists('mask_cpf') ? mask_cpf((string)$t['cpf']) : $t['cpf']) ?></td>
                            <td class="text-center"><?= enroll_badge2($t['_status'], (int)$t['_count'], (int)$minDesc) ?></td>
                            <td class="small text-muted"><?= $t['face_enrolled_at'] ? esc($t['face_enrolled_at']) : '—' ?></td>
                            <td class="text-center" style="white-space:nowrap;">
                                <button class="btn btn-sm btn-outline-primary btn-enroll" data-id="<?= (int)$t['id'] ?>" data-name="<?= esc($t['name']) ?>"><i class="bi bi-camera"></i> <?= $t['_status']==='enrolled' ? 'Recadastrar' : 'Cadastrar' ?></button>
                                <?php if ($t['_status'] !== 'none'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remover o cadastro facial de <?= esc($t['name']) ?>?');">
                                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="act" value="remove"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Remover cadastro"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- Modal de captura -->
    <div class="modal fade" id="enrollModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Cadastro facial · <span id="emName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body text-center">
            <p class="text-muted small">Capture <?= (int)$minDesc ?> a 5 fotos do rosto, em ângulos levemente diferentes e boa iluminação.</p>
            <video id="enrollVideo" autoplay muted playsinline></video>
            <canvas id="enrollCanvas" class="d-none"></canvas>
            <div class="frame-thumbs" id="frameThumbs"></div>
            <div id="emStatus" class="small mt-2 text-muted">Carregando câmera…</div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline-secondary" id="btnCapture" disabled><i class="bi bi-camera"></i> Capturar (<span id="frameCount">0</span>/5)</button>
            <button class="btn btn-success" id="btnSaveEnroll" disabled><i class="bi bi-save"></i> Salvar cadastro</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const MIN_DESC = <?= (int)$minDesc ?>;
    let emStream=null, emDescs=[], emTeacherId=null, modelsLoaded=false;
    const emModalEl=document.getElementById('enrollModal');
    const emModal=new bootstrap.Modal(emModalEl);
    const video=document.getElementById('enrollVideo'), canvas=document.getElementById('enrollCanvas');
    const thumbs=document.getElementById('frameThumbs'), emStatus=document.getElementById('emStatus');

    async function loadModels(){
      if(modelsLoaded) return true;
      if(typeof faceapi==='undefined'){ emStatus.innerHTML='<span class="text-danger">face-api.js não carregou.</span>'; return false; }
      try{
        await faceapi.nets.tinyFaceDetector.loadFromUri('../models');
        await faceapi.nets.faceLandmark68TinyNet.loadFromUri('../models');
        await faceapi.nets.faceRecognitionNet.loadFromUri('../models');
        modelsLoaded=true; return true;
      }catch(e){ emStatus.innerHTML='<span class="text-danger">Falha ao carregar modelos.</span>'; return false; }
    }
    async function openEnroll(id,name){
      emTeacherId=id; emDescs=[]; thumbs.innerHTML=''; emStatus.textContent='Carregando…';
      document.getElementById('emName').textContent=name;
      document.getElementById('frameCount').textContent='0';
      document.getElementById('btnSaveEnroll').disabled=true; document.getElementById('btnCapture').disabled=true;
      emModal.show();
      const ok=await loadModels(); if(!ok) return;
      try{ emStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:1280},height:{ideal:960}}}); video.srcObject=emStream; await video.play().catch(()=>{}); }
      catch(e){ emStatus.innerHTML='<span class="text-danger">Câmera indisponível.</span>'; return; }
      emStatus.textContent='Pronto. Capture as fotos.'; document.getElementById('btnCapture').disabled=false;
    }
    function stopCam(){ if(emStream){ emStream.getTracks().forEach(t=>t.stop()); emStream=null; } }
    emModalEl.addEventListener('hidden.bs.modal', stopCam);

    document.getElementById('btnCapture').onclick=async ()=>{
      if(emDescs.length>=5) return;
      emStatus.textContent='Lendo rosto…';
      const opts=new faceapi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:0.5});
      // Gate de qualidade: um único rosto, próximo e centralizado.
      let all; try{ all=await faceapi.detectAllFaces(video,opts); }catch(e){ all=[]; }
      if(!all||all.length===0){ emStatus.innerHTML='<span class="text-warning">Rosto não detectado. Centralize e tente de novo.</span>'; return; }
      if(all.length>1){ emStatus.innerHTML='<span class="text-warning">Mais de um rosto no quadro. Deixe apenas o colaborador.</span>'; return; }
      const b=all[0].box, vw=video.videoWidth||640, vh=video.videoHeight||480;
      const coverage=(b.width*b.height)/(vw*vh), cx=(b.x+b.width/2)/vw, cy=(b.y+b.height/2)/vh;
      if(coverage<0.08){ emStatus.innerHTML='<span class="text-warning">Aproxime o rosto da câmera.</span>'; return; }
      if(cx<0.28||cx>0.72||cy<0.20||cy>0.80){ emStatus.innerHTML='<span class="text-warning">Centralize o rosto.</span>'; return; }
      let det; try{ det=await faceapi.detectSingleFace(video,opts).withFaceLandmarks(true).withFaceDescriptor(); }catch(e){ det=null; }
      if(!det){ emStatus.innerHTML='<span class="text-warning">Não consegui ler o rosto. Tente de novo.</span>'; return; }
      emDescs.push(Array.from(det.descriptor));
      const w=video.videoWidth||640,h=video.videoHeight||480,side=Math.min(w,h);
      canvas.width=96;canvas.height=96;canvas.getContext('2d').drawImage(video,(w-side)/2,(h-side)/2,side,side,0,0,96,96);
      const img=document.createElement('img'); img.src=canvas.toDataURL('image/jpeg',0.7); thumbs.appendChild(img);
      document.getElementById('frameCount').textContent=emDescs.length;
      emStatus.textContent=emDescs.length+' foto(s) capturada(s).';
      document.getElementById('btnSaveEnroll').disabled = emDescs.length < MIN_DESC;
    };

    document.getElementById('btnSaveEnroll').onclick=async ()=>{
      if(emDescs.length<MIN_DESC||!emTeacherId) return;
      emStatus.innerHTML='<i class="bi bi-hourglass-split"></i> Salvando…';
      document.getElementById('btnSaveEnroll').disabled=true;
      try{
        const res=await fetch('../../api/save_face.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({teacher_id:emTeacherId,descriptors:emDescs})});
        const d=await res.json();
        if(d.status==='ok'){ emStatus.innerHTML='<span class="text-success">Cadastrado! ('+d.count+' amostras)</span>'; setTimeout(()=>location.reload(),900); }
        else { emStatus.innerHTML='<span class="text-danger">'+(d.message||'Falha no cadastro.')+'</span>'; document.getElementById('btnSaveEnroll').disabled=false; }
      }catch(e){ emStatus.innerHTML='<span class="text-danger">Erro de rede.</span>'; document.getElementById('btnSaveEnroll').disabled=false; }
    };

    document.querySelectorAll('.btn-enroll').forEach(b=> b.onclick=()=>openEnroll(+b.dataset.id,b.dataset.name));
    </script>
</body>
</html>

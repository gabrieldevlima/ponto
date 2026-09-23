<?php
require_once __DIR__ . '/../config.php';

if (function_exists('run_auto_migrations')) {
    run_auto_migrations();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$csrfToken = csrf_token();
$apiBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBasePath = preg_replace('#/public$#', '', $apiBasePath);
$modelsUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/models';

// Auto-confirmação (híbrido): registra sozinho, com contagem cancelável, APENAS
// quando a ação é única (entrada/retorno) E a confiança >= piso. Em confiança
// baixa ou ação ambígua (saída vs. intervalo) exige toque. Tudo configurável.
$kAutoConfirm = ((string)(get_setting('kiosk_auto_confirm', '1') ?? '1') === '1');
$kAutoSeconds = (int)(get_setting('kiosk_auto_confirm_seconds', '3') ?? '3');
if ($kAutoSeconds < 1) $kAutoSeconds = 1;
if ($kAutoSeconds > 15) $kAutoSeconds = 15;
$kAutoMinConf = (float)(get_setting('kiosk_auto_confirm_min_confidence', '0.80') ?? '0.80');
if ($kAutoMinConf < 0.3 || $kAutoMinConf > 1) $kAutoMinConf = 0.80;
$kSound = ((string)(get_setting('kiosk_sound', '1') ?? '1') === '1'); // feedback sonoro (acessibilidade)
$kFallback = ((string)(get_setting('kiosk_fallback_enabled', '0') ?? '0') === '1'); // fallback CPF+PIN p/ dispositivos sem câmera
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0066cc">
<meta name="app-build" content="<?= htmlspecialchars(APP_BUILD_ID, ENT_QUOTES, 'UTF-8') ?>">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<title>Quiosque de Ponto · DEEDO</title>
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" sizes="180x180" href="img/icon-180x180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<style>
  /* ===== Mesma identidade visual do registro de ponto (public/ponto.php) ===== */
  :root{
    --primary:#0066cc;--primary-dark:#0052a3;--success:#15803d;--danger:#dc2626;
    --warning:#ea9a16;--ink:#0f172a;--muted:#64748b;--surface:#ffffff;
    --bg:#f1f5f9;--border:#e2e8f0;--radius:14px;--shadow:0 2px 14px rgba(15,23,42,.08);
  }
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
  html,body{margin:0;padding:0;}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    background:var(--bg);color:var(--ink);font-size:17px;line-height:1.4;min-height:100vh;min-height:100dvh;-webkit-font-smoothing:antialiased;}
  .container{max-width:480px;margin:0 auto;padding:24px 20px 40px;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;}
  .brand{text-align:center;padding:8px 0 18px;}
  .brand img{max-width:170px;height:auto;}
  .brand .tag{color:var(--muted);font-size:14px;margin-top:4px;}
  .greeting{font-size:22px;font-weight:600;margin:8px 0 18px;text-align:center;}
  .card{background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow);padding:24px 20px;margin-bottom:14px;}
  .card h1{font-size:22px;margin:0 0 6px;}
  .card p{color:var(--muted);margin:0 0 18px;}
  .card.center{text-align:center;}
  .btn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:64px;padding:14px 20px;border:none;border-radius:var(--radius);
    background:var(--primary);color:#fff;font-size:18px;font-weight:600;line-height:1.2;text-align:center;cursor:pointer;transition:transform .08s ease,background .15s ease,opacity .15s ease;}
  .btn:active{transform:scale(.98);} .btn:hover{background:var(--primary-dark);} .btn:disabled{opacity:.45;cursor:not-allowed;}
  .btn.secondary{background:#fff;color:var(--primary);border:2px solid var(--primary);} .btn.secondary:hover{background:#eef4fb;}
  .btn.ghost{background:transparent;color:var(--primary);min-height:48px;text-decoration:underline;font-weight:500;}
  .btn.ghost:hover{background:transparent;color:var(--primary-dark);}
  .btn.success{background:var(--success);} .btn.success:hover{background:#126b33;}
  .btn.danger{background:var(--danger);} .btn.danger:hover{background:#b91c1c;}
  .btn+.btn{margin-top:10px;}
  .input-label{display:block;font-size:14px;font-weight:600;color:var(--ink);margin-bottom:8px;}
  .input{width:100%;height:60px;font-size:24px;font-weight:600;text-align:center;letter-spacing:1px;padding:10px 14px;border:2px solid var(--border);border-radius:var(--radius);background:#fff;color:var(--ink);}
  .input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(0,102,204,.15);}
  .note{font-size:14px;color:var(--muted);margin-top:10px;}
  .back-link{color:var(--muted);background:transparent;border:none;font-size:15px;padding:0 0 12px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
  .back-link:hover{color:var(--ink);}
  .eyebrow{font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--success);text-align:center;margin:0 0 4px;}
  .name{font-size:26px;font-weight:700;text-align:center;margin:0 0 4px;color:var(--ink);}
  .conf{display:flex;align-items:center;justify-content:center;gap:8px;color:var(--muted);font-size:13px;}
  .conf .bars{display:flex;gap:3px;} .conf .bars i{width:18px;height:6px;border-radius:3px;background:var(--border);} .conf .bars i.on{background:var(--success);}
  .actions-label{margin:0 0 14px;color:var(--ink);font-weight:600;text-align:center;}
  .camera-wrap{width:100%;max-width:300px;margin:0 auto 14px;aspect-ratio:1;border-radius:var(--radius);overflow:hidden;background:#0b1220;position:relative;box-shadow:0 0 0 3px var(--border);transition:box-shadow .2s;}
  @supports not (aspect-ratio: 1 / 1){ .camera-wrap{height:300px;} } /* fallback: navegadores sem aspect-ratio (Safari<15, Chrome<88) */
  .camera-wrap.ok{box-shadow:0 0 0 3px var(--success);}
  .camera-wrap.bad{box-shadow:0 0 0 3px var(--danger);}
  .camera-wrap video{width:100%;height:100%;object-fit:cover;display:block;transform:scaleX(-1);}
  canvas{display:none;}
  .guide{text-align:center;font-size:16px;color:var(--ink);font-weight:600;margin:0;min-height:22px;}
  .result-icon{width:100px;height:100px;margin:6px auto 16px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:60px;line-height:1;}
  .result-icon.ok{background:var(--success);} .result-icon.err{background:var(--danger);} .result-icon.warn{background:var(--warning);}
  .result-icon svg{width:60px;height:60px;}
  .ck-c{fill:none;stroke:#fff;stroke-width:4;stroke-dasharray:166;stroke-dashoffset:166;animation:dash .6s ease forwards;}
  .ck-p{fill:none;stroke:#fff;stroke-width:5;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;animation:dash .4s .5s ease forwards;}
  @keyframes dash{to{stroke-dashoffset:0}}
  .meta{text-align:center;color:var(--muted);font-size:14px;font-weight:600;}
  .card .result-msg{font-size:17px;font-weight:700;color:var(--ink);margin:6px 0 2px;line-height:1.3;}
  .card .status-line{font-size:14px;font-weight:600;margin:0 0 4px;}
  .card .status-line.ok{color:#15803d;}   /* Aprovado — verde AA (5:1) */
  .card .status-line.wait{color:#b45309;} /* Aguardando — âmbar escuro AA (4.6:1) */
  .loader{width:46px;height:46px;border:4px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto;}
  @keyframes spin{to{transform:rotate(360deg)}}
  .alert{padding:12px 14px;border-radius:10px;font-size:15px;margin:0 0 14px;}
  .alert-warning{background:#fef7e5;color:#8a5a06;border-left:4px solid var(--warning);}
  .screen-fade{animation:fadeIn .2s ease;} @keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
  /* ===== Acessibilidade (WCAG 2.1 AA) ===== */
  .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
  :focus-visible{outline:3px solid #1d4ed8;outline-offset:3px;border-radius:6px;}
  .input:focus-visible{outline:none;} /* o input já tem realce próprio */
  @media (prefers-reduced-motion: reduce){
    *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important;}
    .ck-c,.ck-p{stroke-dashoffset:0!important;}
  }
</style>
</head>
<body>
<div class="container">
  <div class="brand">
    <img src="img/logo_login.png" alt="DEEDO Ponto" onerror="this.style.display='none'">
    <div class="tag">Sistema de Ponto Eletrônico · Quiosque</div>
  </div>
  <div id="stage" role="main"><div style="text-align:center;padding:40px 0"><div class="loader"></div><p class="note">Carregando…</p></div></div>
  <div id="sr" class="sr-only" role="status" aria-live="assertive" aria-atomic="true"></div>
</div>

<video id="video" autoplay muted playsinline aria-hidden="true" tabindex="-1" style="display:none"></video>
<canvas id="canvas"></canvas>

<script src="js/face-api.min.js"></script>
<script>
const API_BASE = <?= json_encode($apiBasePath, JSON_UNESCAPED_SLASHES) ?>;
const MODELS_URL = <?= json_encode($modelsUrl, JSON_UNESCAPED_SLASHES) ?>;
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const TOKEN_KEY = 'kiosk_device_token';
const RESET_MS = 6000;           // tela de resultado → volta ao início
const RECOG_MS = 30000;          // tela de ação (já reconhecido)
const CAPTURE_MS = 45000;        // câmera aberta sem concluir
const RETRY_MS = 20000;          // "tentar de novo" / oferta de PIN
const FALLBACK_MS = 40000;       // tela de PIN
const IDLE_MS = 60000;           // CPF parcialmente digitado e abandonado
const BUSY_MS = 20000;           // telas de processamento (lendo/confirmando/registrando)
const FETCH_MS = 15000;          // tempo máximo de cada chamada ao servidor
const AUTO_CONFIRM = <?= $kAutoConfirm ? 'true' : 'false' ?>;   // auto-registro (ação única + confiança alta)
const AUTO_SECONDS = <?= (int)$kAutoSeconds ?>;                 // duração da contagem cancelável
const AUTO_MIN_CONF = <?= json_encode($kAutoMinConf) ?>;        // piso de confiança (0..1) p/ auto-registro
const SOUND_ON = <?= $kSound ? 'true' : 'false' ?>;            // feedback sonoro (acessibilidade)
const FALLBACK_ENABLED = <?= $kFallback ? 'true' : 'false' ?>; // CPF+PIN quando a câmera não funciona (compat)

const stage = document.getElementById('stage');
const video = document.getElementById('video');
const canvas = document.getElementById('canvas');

let deviceToken = null, stream = null, modelsLoaded = false;
let currentCpf = '', currentName = '';
let recog = null, resetTimer = null, countdownTimer = null;
let detectTimer = null, stableCount = 0;
let flowGen = 0;   // geração do fluxo; muda ao voltar ao início → cancela ações assíncronas em voo
let lastFacePhoto = '';   // último frame da tentativa facial; reusado como foto de auditoria no fallback CPF+PIN

const ACTLBL = {entrada:'Entrada',saida:'Saída',iniciar_intervalo:'Iniciar intervalo',retornar_intervalo:'Retornar intervalo'};
const AUTO_NOUN = {entrada:'entrada', retornar_intervalo:'volta do intervalo'}; // ações elegíveis a auto-registro

function uuid(){ return (crypto.randomUUID ? crypto.randomUUID() : 'k-'+Date.now()+'-'+Math.random().toString(16).slice(2)); }
function esc(s){ const d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }
function setStage(html){ stage.innerHTML='<div class="screen-fade">'+html+'</div>'; }
// A11y: anuncia a tela/estado para leitores de tela (região aria-live persistente).
const srEl = document.getElementById('sr');
function announce(msg){ if(!srEl) return; srEl.textContent=''; try{ requestAnimationFrame(()=>{ srEl.textContent=String(msg||''); }); }catch(e){ srEl.textContent=String(msg||''); } }
// A11y: foca o elemento principal da nova tela (foco se perde no innerHTML).
function focusEl(id){ try{ const e=document.getElementById(id); if(e) e.focus(); }catch(e){} }
// Feedback sonoro via Web Audio (sem arquivos). Desbloqueado no 1º gesto do usuário.
let _ac=null;
function audioUnlock(){ try{ _ac=_ac||new (window.AudioContext||window.webkitAudioContext)(); if(_ac.state==='suspended') _ac.resume(); }catch(e){} }
['pointerdown','keydown','touchstart'].forEach(ev=>window.addEventListener(ev, audioUnlock, {passive:true}));
function beep(kind){
  if(!SOUND_ON) return;
  try{ audioUnlock(); const ctx=_ac; if(!ctx) return;
    const seq=(kind==='ok')?[[660,0,.12],[990,.11,.18]]:[[330,0,.16],[196,.16,.26]];
    seq.forEach(p=>{ const t0=ctx.currentTime+p[1], o=ctx.createOscillator(), g=ctx.createGain(); o.type='sine'; o.frequency.value=p[0];
      g.gain.setValueAtTime(0.0001,t0); g.gain.exponentialRampToValueAtTime(0.22,t0+0.012); g.gain.exponentialRampToValueAtTime(0.0001,t0+p[2]);
      o.connect(g); g.connect(ctx.destination); o.start(t0); o.stop(t0+p[2]+0.03); });
  }catch(e){}
}
function stopDetect(){ if(detectTimer){clearInterval(detectTimer);detectTimer=null;} stableCount=0; }
function clearTimers(){ if(resetTimer){clearTimeout(resetTimer);resetTimer=null;} if(countdownTimer){clearInterval(countdownTimer);countdownTimer=null;} stopDetect(); }
// Totem: agenda o retorno automático à tela inicial após inatividade.
function armIdleReturn(ms){ if(resetTimer){clearTimeout(resetTimer);} resetTimer=setTimeout(()=>{ try{ screenIdle(); }catch(e){} }, ms); }
function maskCpf(v){ v=(v||'').replace(/\D/g,'').slice(0,11);
  if(v.length>9) return v.slice(0,3)+'.'+v.slice(3,6)+'.'+v.slice(6,9)+'-'+v.slice(9);
  if(v.length>6) return v.slice(0,3)+'.'+v.slice(3,6)+'.'+v.slice(6);
  if(v.length>3) return v.slice(0,3)+'.'+v.slice(3); return v; }

async function api(path, body){
  const ctrl = (typeof AbortController!=='undefined') ? new AbortController() : null;
  const to = ctrl ? setTimeout(()=>{ try{ctrl.abort();}catch(e){} }, FETCH_MS) : null;
  try{
    const res = await fetch(API_BASE + path, {method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF,'X-Kiosk-Token':deviceToken||''},
      body: JSON.stringify(body||{}), signal: ctrl?ctrl.signal:undefined});
    let data={}; try{ data=await res.json(); }catch(e){ data={status:'error',message:'Resposta inválida do servidor.'}; }
    data.__http=res.status; return data;
  }catch(e){
    return {status:'error', code:'network', message:'Falha de conexão. Verifique a internet e tente novamente.', __http:0};
  }finally{ if(to) clearTimeout(to); }
}

// Compat: a câmera exige getUserMedia + contexto seguro (HTTPS/localhost).
function cameraSupported(){ return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.isSecureContext); }
// Sem câmera/reconhecimento? Cai para CPF+PIN (se habilitado) — funciona em qualquer dispositivo.
function faceUnavailableFallback(msg){
  stopCamera();
  if(FALLBACK_ENABLED && currentCpf){ announce('Reconhecimento facial indisponível neste dispositivo. Use o seu PIN.'); screenFallbackPin(); }
  else { screenResult('err','Reconhecimento indisponível', msg); }
}
async function startCamera(){
  if(stream) return true;
  try{ stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:1280},height:{ideal:960}},audio:false});
    video.srcObject=stream; await video.play().catch(()=>{}); return true;
  }catch(e){ return false; }
}
function stopCamera(){ if(stream){ try{ stream.getTracks().forEach(t=>t.stop()); }catch(e){} stream=null; } try{ video.srcObject=null; }catch(e){} }
async function loadModels(){
  if(modelsLoaded) return true;
  if(typeof faceapi==='undefined') return false;
  try{
    await faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URL);
    await faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODELS_URL);
    await faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URL);
    modelsLoaded=true; return true;
  }catch(e){ return false; }
}
function captureJpeg(){
  const w=video.videoWidth||640,h=video.videoHeight||480,side=Math.min(w,h);
  canvas.width=480;canvas.height=480;
  canvas.getContext('2d').drawImage(video,(w-side)/2,(h-side)/2,side,side,0,0,480,480);
  return canvas.toDataURL('image/jpeg',0.85);
}
async function extractDescriptor(){
  const ok=await loadModels(); if(!ok) return {error:'models'};
  const opts=new faceapi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:0.5});
  let det;
  try{ det=await faceapi.detectSingleFace(video,opts).withFaceLandmarks(true).withFaceDescriptor(); }
  catch(e){ return {error:'detect'}; }
  if(!det) return {error:'no_face'};
  return {descriptor:Array.from(det.descriptor)};
}

// ---------- telas ----------
function screenUnpaired(){ clearTimers(); setStage(`
  <div class="card center">
    <div class="result-icon warn">🔒</div>
    <h1>Dispositivo não pareado</h1>
    <p>Peça ao administrador o link de pareamento do quiosque para ativar este terminal.</p>
  </div>`);
  announce('Dispositivo não pareado. Peça ao administrador o link de pareamento.'); }

function screenIdle(){
  flowGen++; clearTimers(); stopCamera(); recog=null; currentCpf=''; currentName='';
  setStage(`
    <div class="greeting">Olá! 👋</div>
    <div class="card">
      <h1>Bater Ponto</h1>
      <p>Digite seu CPF e olhe para a câmera para confirmar.</p>
      <label class="input-label" for="cpf">CPF</label>
      <input class="input" id="cpf" type="tel" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
      <button class="btn" id="btnGo" style="margin-top:18px;">Continuar</button>
    </div>`);
  const cpf=document.getElementById('cpf'); cpf.focus();
  announce('Bater ponto. Digite o seu CPF e toque em continuar.');
  cpf.oninput=()=>{ cpf.value=maskCpf(cpf.value); armIdleReturn(IDLE_MS); };
  document.getElementById('btnGo').onclick=()=>{
    const v=(cpf.value||'').replace(/\D/g,'');
    if(v.length<11){ cpf.style.borderColor='var(--danger)'; cpf.focus(); return; }
    currentCpf=v;
    if(!cameraSupported() && FALLBACK_ENABLED){ screenFallbackPin(); return; } // sem câmera → direto p/ PIN
    screenCapture();
  };
}

async function screenCapture(){
  clearTimers();
  const gen=flowGen;
  setStage(`<button class="back-link" id="btnCancel">&larr; Cancelar</button>
    <div class="card center">
      <h1>Olhe para a câmera</h1>
      <div class="camera-wrap" id="camWrap"></div>
      <p class="guide" id="guide">Iniciando câmera…</p>
    </div>`);
  document.getElementById('camWrap').appendChild(video); video.style.display='block';
  document.getElementById('btnCancel').onclick=()=>{ stopDetect(); screenIdle(); };
  announce('Olhe para a câmera para confirmar a sua identidade. Para cancelar, use o botão cancelar.');
  armIdleReturn(CAPTURE_MS); // totem: volta sozinho mesmo se a câmera/modelos travarem
  const ok=await startCamera();
  if(gen!==flowGen){ stopCamera(); return; }
  if(!ok){ faceUnavailableFallback('Verifique as permissões da câmera ou use outro dispositivo.'); return; }
  const ml=await loadModels();
  if(gen!==flowGen){ stopCamera(); return; }
  if(!ml){ faceUnavailableFallback('Não foi possível carregar o reconhecimento facial.'); return; }
  startDetectLoop();
}

function startDetectLoop(){
  stopDetect();
  const NEED=3; let detBusy=false;
  const camWrap=document.getElementById('camWrap');
  detectTimer=setInterval(async ()=>{
    if(detBusy) return;
    const g=document.getElementById('guide');
    if(!g||!camWrap||!g.isConnected){ return; }
    if(typeof faceapi==='undefined'){ return; }
    detBusy=true;
    try{
      let dets;
      try{ dets=await faceapi.detectAllFaces(video,new faceapi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:0.5})); }
      catch(e){ return; }
      if(!g.isConnected){ return; }
      const setBad=(msg)=>{ stableCount=0; camWrap.classList.remove('ok'); camWrap.classList.add('bad'); g.textContent=msg; };
      if(!dets||dets.length===0){ setBad('Posicione o rosto na câmera'); return; }
      if(dets.length>1){ setBad('Apenas uma pessoa por vez'); return; }
      const b=dets[0].box, vw=video.videoWidth||640, vh=video.videoHeight||480;
      const coverage=(b.width*b.height)/(vw*vh), cx=(b.x+b.width/2)/vw, cy=(b.y+b.height/2)/vh;
      if(coverage<0.08){ setBad('Aproxime-se da câmera'); return; }
      if(cx<0.30||cx>0.70||cy<0.22||cy>0.78){ setBad('Centralize o rosto'); return; }
      stableCount++; camWrap.classList.remove('bad'); camWrap.classList.add('ok');
      g.textContent='Segure firme… '+Math.min(NEED,stableCount)+'/'+NEED;
      if(stableCount>=NEED){ stopDetect(); doVerify(); }
    } finally { detBusy=false; }
  },350);
}

function screenBusy(t){ setStage(`<div style="text-align:center;padding:48px 0"><div class="loader"></div><p class="guide" style="margin-top:18px">${esc(t)}</p></div>`); announce(t); armIdleReturn(BUSY_MS); }

function screenRecognized(note){
  clearTimers();
  // Ação AUTO-DETECTADA pelo servidor (entrada/saída/retorno). Só o estado
  // "trabalhando" é ambíguo → oferece também "Iniciar intervalo".
  const next = (recog && recog.next) ? recog.next
    : {state:'fora',primary_action:'entrada',primary_label:'Registrar entrada',can_break:false,since:null};
  const pct = (recog && recog.confidence!=null) ? Math.round(recog.confidence*100) : null;
  const on = pct!=null ? Math.max(1,Math.round(pct/20)) : 0;
  const bars = [0,1,2,3,4].map(i=>`<i class="${i<on?'on':''}"></i>`).join('');
  let line;
  if(next.state==='trabalhando') line='Você está trabalhando'+(next.since?` desde ${next.since}`:'')+'. Confirme:';
  else if(next.state==='em_intervalo') line='Você está em intervalo'+(next.since?` desde ${next.since}`:'')+'. Confirme:';
  else line='Tudo certo — confirme o seu ponto:';
  const primCls = next.primary_action==='entrada'?'success':(next.primary_action==='saida'?'danger':'');
  const ambiguous = !!next.can_break;                                          // trabalhando: saída vs intervalo
  const confOk = (recog && recog.confidence!=null) ? (recog.confidence >= AUTO_MIN_CONF) : false;
  const auto = AUTO_CONFIRM && !note && !ambiguous && confOk;                   // auto só em ação única + confiança alta + sem aviso

  const idCard = `
    ${note?`<div class="alert alert-warning">${esc(note)}</div>`:''}
    <div class="card center">
      <p class="eyebrow">✓ Identificado</p>
      <p class="name">${esc(currentName)}</p>
      ${pct!=null?`<div class="conf"><span class="bars">${bars}</span> confiança ${pct}%</div>`:''}
    </div>`;

  if(auto){
    // CONFIANÇA ALTA + AÇÃO ÚNICA → registra sozinho com contagem cancelável.
    const noun = AUTO_NOUN[next.primary_action] || 'ponto';
    setStage(idCard + `
      <div class="card center">
        <p class="actions-label">Registrando sua <b>${esc(noun)}</b> em <span id="cd">${AUTO_SECONDS}</span>s…</p>
        <button class="btn ${primCls}" id="btnPrimary">Confirmar agora</button>
        <button class="btn ghost" id="btnNotMe">Não sou eu / Cancelar</button>
      </div>`);
    document.getElementById('btnPrimary').onclick=()=>doCheckin(next.primary_action); // toque = registra já
    document.getElementById('btnNotMe').onclick=screenIdle;
    let left=AUTO_SECONDS; const cd=document.getElementById('cd');
    countdownTimer=setInterval(()=>{
      left--;
      if(cd) cd.textContent=String(Math.max(0,left));
      if(left<=0){ if(countdownTimer){clearInterval(countdownTimer);countdownTimer=null;} doCheckin(next.primary_action); }
    },1000);
    announce(`${currentName} identificado. Registrando ${noun} automaticamente em ${AUTO_SECONDS} segundos. Toque em confirmar agora, ou em não sou eu para cancelar.`);
    focusEl('btnPrimary');
    armIdleReturn(RECOG_MS); // backstop
    return;
  }

  // CONFIRMAÇÃO EXPLÍCITA → confiança baixa, ação ambígua, ou após um aviso.
  setStage(idCard + `
    <div class="card">
      <p class="actions-label">${esc(line)}</p>
      <div class="actions">
        <button class="btn ${primCls}" id="btnPrimary">${esc(next.primary_label||'Registrar ponto')}</button>
        ${next.can_break?`<button class="btn secondary" id="btnBreak">Iniciar intervalo</button>`:''}
      </div>
      <button class="btn ghost" id="btnNotMe">Não sou eu / Cancelar</button>
    </div>`);
  document.getElementById('btnPrimary').onclick=()=>doCheckin(next.primary_action);
  const bk=document.getElementById('btnBreak'); if(bk) bk.onclick=()=>doCheckin('iniciar_intervalo');
  document.getElementById('btnNotMe').onclick=screenIdle;
  announce(`${currentName} identificado. ${line}`);
  focusEl('btnPrimary');
  armIdleReturn(RECOG_MS);
}

function screenResult(kind,title,message,extra,approval){
  clearTimers(); stopCamera();
  let ico;
  if(kind==='ok') ico='<svg viewBox="0 0 52 52"><circle class="ck-c" cx="26" cy="26" r="24"/><path class="ck-p" d="M15 27l8 8 15-16"/></svg>';
  else ico=(kind==='warn'?'!':'✕');
  // Status de aprovação em LINHA PRÓPRIA abaixo do nome (não inline).
  const apprTxt = approval===true ? 'Aprovado' : (approval===false ? 'Aguardando aprovação' : '');
  const statusHtml = approval===true ? `<p class="status-line ok">✓ ${esc(apprTxt)}</p>`
    : (approval===false ? `<p class="status-line wait">⏳ ${esc(apprTxt)}</p>` : '');
  setStage(`<div class="card center">
      <div class="result-icon ${kind==='ok'?'ok':(kind==='warn'?'warn':'err')}">${ico}</div>
      <h1>${esc(title)}</h1>
      <p class="result-msg">${esc(message)}</p>
      ${statusHtml}
      ${extra?`<p class="meta">${esc(extra)}</p>`:''}
      <button class="btn" id="btnContinue" style="margin-top:18px">Continuar</button>
    </div>`);
  document.getElementById('btnContinue').onclick=screenIdle;
  beep(kind==='ok'?'ok':'err');
  announce(`${title}. ${message}${apprTxt?'. '+apprTxt:''}`);
  focusEl('btnContinue');
  armIdleReturn(RESET_MS);
}

function screenRetry(reason){
  clearTimers();
  setStage(`<div class="card center">
      <div class="result-icon warn">!</div>
      <h1>Não confirmado</h1>
      <p class="guide" style="margin:6px 0 16px">${esc(reason)}</p>
      <button class="btn" id="btnRetry">Tentar de novo</button>
      <button class="btn ghost" id="btnCancel">Cancelar</button>
    </div>`);
  document.getElementById('btnRetry').onclick=screenCapture;
  document.getElementById('btnCancel').onclick=screenIdle;
  beep('err');
  announce(`Não confirmado. ${reason}`);
  focusEl('btnRetry');
  armIdleReturn(RETRY_MS);
}

// ---------- ações ----------
async function doVerify(){
  clearTimers();
  const gen=flowGen;
  screenBusy('Lendo seu rosto…');
  const photo=captureJpeg();
  lastFacePhoto=photo; // guarda o frame para o fallback CPF+PIN (checkin.php exige foto na entrada)
  const ex=await extractDescriptor();
  if(gen!==flowGen) return; // fluxo reiniciou (timeout/cancelar) — descarta resultado tardio
  if(ex.error==='models'){ screenResult('err','Falha ao carregar','Não foi possível carregar o reconhecimento. Recarregue a página.'); return; }
  if(ex.error==='no_face'){ screenResult('warn','Rosto não detectado','Centralize o rosto na câmera e tente de novo.'); return; }
  if(ex.error){ screenResult('err','Erro na câmera','Tente novamente.'); return; }
  screenBusy('Confirmando…');
  const r=await api('/api/kiosk_verify.php',{cpf:currentCpf,face_descriptor:ex.descriptor,photo});
  if(gen!==flowGen) return; // idem
  if(r.status==='recognized'){
    recog={token:r.recognition_token,confidence:r.confidence,next:r.next||null}; currentName=(r.teacher&&r.teacher.name)||'';
    screenRecognized();
  } else if(r.code==='kiosk_unpaired'){ screenUnpaired(); }
  else if(r.code==='kiosk_disabled'){ screenResult('warn','Quiosque desativado', r.message||'Procure o administrador.'); }
  else {
    if(r.fallback_allowed){ screenFallbackOffer(r.message||'Não confirmamos seu rosto.'); }
    else if(r.code==='not_matched'){ screenRetry(r.message||'Não confirmamos seu rosto.'); }
    else { screenResult('err','Não confirmado', r.message||'Tente novamente.'); }
  }
}

async function doCheckin(action){
  if(!recog||!recog.token){ screenIdle(); return; }
  clearTimers();
  const gen=flowGen;
  screenBusy('Registrando…');
  const r=await api('/api/kiosk_checkin.php',{recognition_token:recog.token,action,client_id:uuid()});
  if(gen!==flowGen) return; // fluxo reiniciou — descarta resultado tardio
  if(r.status==='ok'){
    const when=r.time?r.time.substring(11,16):'';
    const extra=(r.nsr?`NSR ${r.nsr}`:'')+(when?` · ${when}`:'');
    screenResult('ok',(ACTLBL[r.action]||'Ponto')+' registrada!', currentName, extra, (r.approved===true));
  } else if(['recognition_invalid','recognition_reused','recognition_expired'].includes(r.code)){
    screenResult('warn','Sessão expirada','Confirme seu rosto novamente.');
  } else if(r.status==='blocked'){ screenResult('err','Ação bloqueada', r.message||'Procure o administrador.'); }
  else {
    if(recog&&recog.token){ screenRecognized(r.message||'Ação não permitida agora.'); }
    else { screenResult('err','Não foi possível', r.message||'Tente novamente.'); }
  }
}

// ---------- fallback CPF + PIN ----------
function screenFallbackOffer(reason){
  clearTimers();
  setStage(`<div class="card center">
      <div class="result-icon warn">!</div>
      <h1>Não confirmamos seu rosto</h1>
      <p class="guide" style="margin:6px 0 16px">${esc(reason)}</p>
      <button class="btn" id="btnRetry">Tentar rosto de novo</button>
      <button class="btn secondary" id="btnPin">Usar CPF + PIN</button>
    </div>`);
  document.getElementById('btnRetry').onclick=screenCapture;
  document.getElementById('btnPin').onclick=screenFallbackPin;
  announce(`${reason} Você pode tentar o rosto de novo ou usar CPF e PIN.`);
  focusEl('btnRetry');
  armIdleReturn(RETRY_MS);
}
function screenFallbackPin(){
  clearTimers();
  setStage(`<div class="card">
      <h1>Bater ponto com PIN</h1>
      <p>CPF ${esc(maskCpf(currentCpf))} — informe seu PIN. O sistema identifica a ação automaticamente.</p>
      <label class="input-label" for="pin">PIN</label>
      <input class="input" id="pin" type="password" inputmode="numeric" maxlength="10" placeholder="••••" autocomplete="off">
      <div class="actions" style="margin-top:18px">
        <button class="btn" id="btnPunch">Bater ponto</button>
        <button class="btn secondary" id="btnBreak">Iniciar intervalo</button>
      </div>
      <button class="btn ghost" id="btnFbCancel">Cancelar</button>
    </div>`);
  const pin=document.getElementById('pin');
  document.getElementById('btnPunch').onclick=()=>doFallback(null);
  document.getElementById('btnBreak').onclick=()=>doFallback('iniciar_intervalo');
  document.getElementById('btnFbCancel').onclick=screenIdle;
  pin.oninput=()=>armIdleReturn(FALLBACK_MS);
  pin.focus();
  announce('Bater ponto com PIN. Informe o seu PIN e toque em bater ponto.');
  armIdleReturn(FALLBACK_MS);
}
async function doFallback(action){
  const pinEl=document.getElementById('pin');
  const pin=((pinEl&&pinEl.value)||'').trim();
  if(pin.length<4){ if(pinEl){ pinEl.style.borderColor='var(--danger)'; pinEl.focus(); } return; }
  clearTimers();
  const gen=flowGen;
  screenBusy('Registrando…');
  try{ await api('/api/kiosk_fallback_log.php',{stage:'start'}); }catch(e){}
  const payload={cpf:currentCpf,pin,client_id:uuid(),photo:(lastFacePhoto||(typeof captureJpeg==='function'?captureJpeg():''))};
  if(action) payload.expected_action=action; // sem ação → servidor auto-detecta
  const r=await api('/api/checkin.php',payload);
  if(gen!==flowGen) return; // fluxo reiniciou — descarta resultado tardio
  try{ await api('/api/kiosk_fallback_log.php',{stage:'done',result:(r.status||'?')}); }catch(e){}
  if(r.status==='ok'){
    screenResult('ok',(ACTLBL[r.action]||'Ponto')+' registrada!', (r.teacher&&r.teacher.name)||'', r.nsr?`NSR ${r.nsr}`:'', (r.approved===true));
  } else {
    screenResult('err','Não foi possível', r.message||'Verifique o CPF e o PIN.');
  }
}

// ---------- init ----------
function init(){
  const params=new URLSearchParams(location.search);
  const pair=params.get('pair');
  if(pair){ try{ localStorage.setItem(TOKEN_KEY,pair); }catch(e){} history.replaceState(null,'',location.pathname); }
  try{ deviceToken=localStorage.getItem(TOKEN_KEY); }catch(e){ deviceToken=null; }
  if(!deviceToken){ screenUnpaired(); return; }
  if('wakeLock' in navigator){ navigator.wakeLock.request('screen').catch(()=>{}); }
  loadModels();
  screenIdle();
}
init();
</script>
</body>
</html>

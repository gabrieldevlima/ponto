<?php
// O mapa da geocerca usa Leaflet (unpkg), tiles OSM/ArcGIS e Nominatim — todos
// bloqueados pela CSP global. Página restrita a admin; política própria abaixo.
define('SKIP_GLOBAL_CSP', true);
require_once __DIR__ . '/../../config.php';
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://*.tile.openstreetmap.org https://server.arcgisonline.com https://unpkg.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com; font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; connect-src 'self' https://nominatim.openstreetmap.org; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
}
require_admin();
$pdo = db();
$adm = current_admin($pdo);
if (!is_network_admin($adm)) {
  flash_redirect('error', 'Acesso restrito a administradores da rede.', 'dashboard.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = $_GET['msg'] ?? '';

// Garante colunas (para instalações antigas)
$extraCols = [
  "ALTER TABLE schools ADD COLUMN lat DOUBLE NULL",
  "ALTER TABLE schools ADD COLUMN lng DOUBLE NULL",
  "ALTER TABLE schools ADD COLUMN phone VARCHAR(20) NULL",
  "ALTER TABLE schools ADD COLUMN email VARCHAR(100) NULL",
  "ALTER TABLE schools ADD COLUMN address VARCHAR(255) NULL",
  "ALTER TABLE schools ADD COLUMN city VARCHAR(100) NULL",
  "ALTER TABLE schools ADD COLUMN state VARCHAR(2) NULL",
  "ALTER TABLE schools ADD COLUMN zip_code VARCHAR(9) NULL",
  "ALTER TABLE schools ADD COLUMN director_name VARCHAR(150) NULL",
  "ALTER TABLE schools ADD COLUMN school_type VARCHAR(50) NULL",
  "ALTER TABLE schools ADD COLUMN notes TEXT NULL",
];
foreach ($extraCols as $col) { try { $pdo->exec($col); } catch (Throwable $e) {} }
try {
  $pdo->exec("ALTER TABLE schools ADD COLUMN lat DOUBLE NULL");
} catch (Throwable $e) {
}
try {
  $pdo->exec("ALTER TABLE schools ADD COLUMN lng DOUBLE NULL");
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $name = trim($_POST['name'] ?? '');
  $code = trim($_POST['code'] ?? '');
  $active = isset($_POST['active']) ? 1 : 0;
  $lat          = ($_POST['lat'] ?? '') !== '' ? (float)$_POST['lat'] : null;
  $lng          = ($_POST['lng'] ?? '') !== '' ? (float)$_POST['lng'] : null;
  $phone        = trim($_POST['phone'] ?? '') ?: null;
  $pEmail       = trim($_POST['email'] ?? '') ?: null;
  $address      = trim($_POST['address'] ?? '') ?: null;
  $city         = trim($_POST['city'] ?? '') ?: null;
  $state        = strtoupper(trim($_POST['state'] ?? '')) ?: null;
  $zip_code     = trim($_POST['zip_code'] ?? '') ?: null;
  $director_name= trim($_POST['director_name'] ?? '') ?: null;
  $school_type  = trim($_POST['school_type'] ?? '') ?: null;
  $notes        = trim($_POST['notes'] ?? '') ?: null;
  $lng = $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;

  if ($name === '' || $code === '') {
    $msg = 'Nome e código são obrigatórios.';
  } else {
    $fields = [$name, $code, $active, $lat, $lng, $phone, $pEmail, $address, $city, $state, $zip_code, $director_name, $school_type, $notes];
    if ($id > 0) {
      $st = $pdo->prepare("UPDATE schools SET name=?, code=?, active=?, lat=?, lng=?, phone=?, email=?, address=?, city=?, state=?, zip_code=?, director_name=?, school_type=?, notes=? WHERE id=?");
      $st->execute([...$fields, $id]);
      audit_log('update', 'school', $id, ['name' => $name, 'code' => $code, 'active' => $active, 'lat' => $lat, 'lng' => $lng]);
      header('Location: school_edit.php?id=' . (int)$id . '&msg=' . urlencode('Instituição atualizada.'));
      exit;
    } else {
      $st = $pdo->prepare("INSERT INTO schools (name, code, active, lat, lng, phone, email, address, city, state, zip_code, director_name, school_type, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $st->execute($fields);
      $newId = (int)$pdo->lastInsertId();
      audit_log('create', 'school', $newId, ['name' => $name, 'code' => $code, 'active' => $active, 'lat' => $lat, 'lng' => $lng]);
      header('Location: schools.php?msg=' . urlencode('Instituição criada.'));
      exit;
    }
  }
}

$row = null;
if ($id) {
  $st = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    flash_redirect('error', 'Instituição não encontrada.', 'schools.php');
  }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title><?= $id ? 'Editar' : 'Nova' ?> Instituição | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <div class="container-fluid admin-content">
    <!-- Header (padrão .app-page-header) -->
    <div class="app-page-header">
      <div class="app-page-header__main">
        <div class="app-page-icon"><i class="bi bi-building"></i></div>
        <div>
          <h1 class="app-page-title"><?= $id ? 'Editar Instituição' : 'Nova Instituição' ?></h1>
          <p class="app-page-subtitle"><?= $id ? esc($row['name'] ?? '') : 'Cadastre uma nova instituição na rede.' ?></p>
        </div>
      </div>
      <a class="btn btn-outline-secondary btn-sm" href="schools.php">
        <i class="bi bi-arrow-left me-1"></i>Voltar
      </a>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <div class="flex-grow-1"><?= esc($msg) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <form action="" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <!-- ========== Identificação ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-card-text"></i>Seção 1</span>
          <h2 class="app-section-card__title">Identificação</h2>
        </header>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-12 col-md-8">
              <label for="schoolName" class="form-label">Nome <span class="text-danger">*</span></label>
              <input id="schoolName" class="form-control" name="name" required maxlength="150" value="<?= esc($row['name'] ?? '') ?>" placeholder="Ex: EMEF Centro">
            </div>
            <div class="col-12 col-md-4">
              <label for="schoolCode" class="form-label">Código <span class="text-danger">*</span></label>
              <input id="schoolCode" class="form-control" name="code" required maxlength="50" value="<?= esc($row['code'] ?? '') ?>" placeholder="EMEF-CENTRO">
              <div class="form-text">Identificador único da instituição.</div>
            </div>
          </div>
        </div>
      </section>

      <!-- ========== Localização e mapa ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-geo-alt"></i>Seção 2</span>
          <h2 class="app-section-card__title">Localização</h2>
          <span class="app-section-card__hint">
            <span class="badge text-bg-secondary-subtle border border-secondary-subtle text-secondary-emphasis">opcional</span>
          </span>
        </header>
        <div class="app-section-card__body">
          <div class="position-relative mb-3">
            <div class="input-group mb-2">
              <input type="text" id="addressQuery" class="form-control" placeholder="Digite endereço ou CEP (ex: Rua, número, bairro, cidade)" autocomplete="off" aria-label="Endereço de busca">
              <button class="btn btn-outline-primary" type="button" id="btnSearch" aria-label="Buscar endereço"><i class="bi bi-search"></i><span class="d-none d-md-inline ms-1">Buscar</span></button>
              <button class="btn btn-outline-secondary" type="button" id="btnLocate" aria-label="Usar minha localização"><i class="bi bi-geo-alt"></i><span class="d-none d-md-inline ms-1">Minha localização</span></button>
              <button class="btn btn-outline-danger" type="button" id="btnClear" aria-label="Limpar localização"><i class="bi bi-x-circle"></i></button>
            </div>
            <div id="addressResults" class="list-group shadow-sm" style="position:absolute;top:100%;left:0;right:0;z-index:1050;display:none;max-height:260px;overflow:auto;border-radius:8px;"></div>
          </div>
          <div id="geocodeStatus" class="form-text mb-2"></div>
          <div id="schoolMap" style="height: 380px; border-radius: 10px; overflow: hidden; border: 1px solid #cbd5e1;"></div>
          <div class="form-text mt-2">
            <i class="bi bi-info-circle me-1"></i>
            Digite o endereço e escolha na lista, ou clique/arraste o marcador no mapa. Círculo de 300m exibido apenas como referência visual.
          </div>

          <div class="row g-3 mt-2">
            <div class="col-12 col-md-6">
              <label for="latInput" class="form-label">Latitude</label>
              <input type="number" step="0.000001" min="-90" max="90" inputmode="decimal" class="form-control" name="lat" id="latInput" value="<?= esc((string)($row['lat'] ?? '')) ?>" placeholder="-23.550520">
            </div>
            <div class="col-12 col-md-6">
              <label for="lngInput" class="form-label">Longitude</label>
              <input type="number" step="0.000001" min="-180" max="180" inputmode="decimal" class="form-control" name="lng" id="lngInput" value="<?= esc((string)($row['lng'] ?? '')) ?>" placeholder="-46.633308">
            </div>
          </div>
        </div>
      </section>

          <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
          <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
          <script>
            (function() {
              const latInput = document.getElementById('latInput');
              const lngInput = document.getElementById('lngInput');
              const statusEl = document.getElementById('geocodeStatus');
              const searchBtn = document.getElementById('btnSearch');
              const locateBtn = document.getElementById('btnLocate');
              const clearBtn = document.getElementById('btnClear');
              const addressInput = document.getElementById('addressQuery');
              const resultsEl = document.getElementById('addressResults');

              const initialLatStr = <?= json_encode((string)($row['lat'] ?? '')) ?>;
              const initialLngStr = <?= json_encode((string)($row['lng'] ?? '')) ?>;
              const initialLat = initialLatStr ? parseFloat(initialLatStr) : null;
              const initialLng = initialLngStr ? parseFloat(initialLngStr) : null;

              const defaultCenter = [-14.235004, -51.92528]; // Brasil
              const defaultZoom = initialLat != null && initialLng != null ? 17 : 4;

              // Base maps
              const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
              });
              const esri = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: 'Tiles &copy; Esri'
              });

              const map = L.map('schoolMap', {
                  scrollWheelZoom: true,
                  layers: [osm]
                })
                .setView(initialLat != null && initialLng != null ? [initialLat, initialLng] : defaultCenter, defaultZoom);

              L.control.layers({
                'Mapa (OSM)': osm,
                'Satélite (Esri)': esri
              }, {}, {
                position: 'topleft'
              }).addTo(map);

              let marker = null;
              let circle = null;
              const radiusMeters = 300;

              function setPoint(lat, lng, pan = true) {
                if (Number.isNaN(lat) || Number.isNaN(lng)) return;
                if (!marker) {
                  marker = L.marker([lat, lng], {
                    draggable: true
                  }).addTo(map);
                  marker.on('dragend', () => {
                    const p = marker.getLatLng();
                    updateInputs(p.lat, p.lng);
                    drawCircle(p.lat, p.lng);
                    reverseGeocode(p.lat, p.lng);
                  });
                } else {
                  marker.setLatLng([lat, lng]);
                }
                drawCircle(lat, lng);
                updateInputs(lat, lng);
                if (pan) map.setView([lat, lng], Math.max(map.getZoom(), 17));
              }

              function clearPoint() {
                if (marker) {
                  map.removeLayer(marker);
                  marker = null;
                }
                if (circle) {
                  map.removeLayer(circle);
                  circle = null;
                }
                latInput.value = '';
                lngInput.value = '';
                addressInput.value = '';
                setStatus('');
              }

              function drawCircle(lat, lng) {
                if (circle) {
                  circle.setLatLng([lat, lng]);
                } else {
                  circle = L.circle([lat, lng], {
                    radius: radiusMeters,
                    color: '#0d6efd',
                    fillColor: '#0d6efd',
                    fillOpacity: 0.08,
                    weight: 2
                  }).addTo(map);
                }
              }

              function updateInputs(lat, lng) {
                latInput.value = lat.toFixed(6);
                lngInput.value = lng.toFixed(6);
              }

              function setStatus(msg) {
                statusEl.textContent = msg || '';
              }

              // Initialize marker if existing coords
              if (initialLat != null && initialLng != null) {
                setPoint(initialLat, initialLng, false);
                reverseGeocode(initialLat, initialLng, true);
              }

              // Map click -> set marker + reverse geocode
              map.on('click', (e) => {
                setPoint(e.latlng.lat, e.latlng.lng);
                reverseGeocode(e.latlng.lat, e.latlng.lng);
              });

              // Manual input change -> update marker
              function onInputChange() {
                const lat = parseFloat(latInput.value);
                const lng = parseFloat(lngInput.value);
                if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
                  setPoint(lat, lng);
                  reverseGeocode(lat, lng);
                }
              }
              latInput.addEventListener('change', onInputChange);
              lngInput.addEventListener('change', onInputChange);

              // Nominatim helpers
              let searchAbort = null;

              function abortSearch() {
                if (searchAbort) {
                  searchAbort.abort();
                  searchAbort = null;
                }
              }

              function renderResults(items) {
                resultsEl.innerHTML = '';
                if (!items || !items.length) {
                  resultsEl.style.display = 'none';
                  return;
                }
                items.forEach((it, idx) => {
                  const a = document.createElement('button');
                  a.type = 'button';
                  a.className = 'list-group-item list-group-item-action';
                  a.innerHTML = `<div class="fw-semibold">${escapeHtml(it.display_name_short || it.display_name)}</div>
                       <div class="small text-muted">${escapeHtml(it.display_name)}</div>`;
                  a.addEventListener('click', () => {
                    selectResult(it);
                  });
                  resultsEl.appendChild(a);
                });
                resultsEl.style.display = 'block';
                activeIndex = -1;
              }

              function selectResult(item) {
                const lat = parseFloat(item.lat);
                const lon = parseFloat(item.lon);
                setPoint(lat, lon);
                addressInput.value = item.display_name || '';
                setStatus('Endereço localizado.');
                hideResults();
              }

              function hideResults() {
                resultsEl.style.display = 'none';
              }

              function escapeHtml(s) {
                return String(s || '').replace(/[&<>"']/g, c => ({
                  '&': '&amp;',
                  '<': '&lt;',
                  '>': '&gt;',
                  '"': '&quot;',
                  "'": '&#39;'
                } [c]));
              }

              async function geocode(q, pickBest = false) {
                if (!q || q.trim().length < 3) {
                  setStatus('Informe pelo menos 3 caracteres para buscar.');
                  hideResults();
                  return;
                }
                setStatus('Buscando endereço...');
                abortSearch();
                searchAbort = new AbortController();
                try {
                  const url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=8&addressdetails=1&countrycodes=br&dedupe=1&q=' + encodeURIComponent(q);
                  const res = await fetch(url, {
                    signal: searchAbort.signal,
                    headers: {
                      'Accept-Language': 'pt-BR,pt;q=0.9'
                    }
                  });
                  if (!res.ok) throw new Error('Falha na consulta');
                  const data = await res.json();
                  if (!data.length) {
                    setStatus('Endereço não encontrado. Tente ser mais específico.');
                    hideResults();
                    return;
                  }
                  // Add short display
                  data.forEach(d => {
                    if (d.address) {
                      const a = d.address;
                      const parts = [a.road, a.house_number, a.neighbourhood || a.suburb, a.city || a.town || a.village, a.state];
                      d.display_name_short = parts.filter(Boolean).join(', ');
                    }
                  });
                  if (pickBest) {
                    selectResult(data[0]);
                  } else {
                    renderResults(data);
                    setStatus('Selecione um endereço na lista.');
                  }
                } catch (err) {
                  if (err.name !== 'AbortError') {
                    console.error(err);
                    setStatus('Erro ao buscar endereço. Verifique sua conexão e tente novamente.');
                    hideResults();
                  }
                }
              }

              let reverseAbort = null;
              async function reverseGeocode(lat, lon, quiet = false) {
                if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
                if (!quiet) setStatus('Obtendo endereço do local selecionado...');
                if (reverseAbort) {
                  reverseAbort.abort();
                  reverseAbort = null;
                }
                reverseAbort = new AbortController();
                try {
                  const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}`;
                  const res = await fetch(url, {
                    signal: reverseAbort.signal,
                    headers: {
                      'Accept-Language': 'pt-BR,pt;q=0.9'
                    }
                  });
                  if (!res.ok) throw new Error('Falha no reverse');
                  const data = await res.json();
                  if (data && data.display_name) {
                    addressInput.value = data.display_name;
                    setStatus('Endereço detectado no mapa.');
                  } else if (!quiet) {
                    setStatus('Local selecionado, mas não foi possível obter o endereço.');
                  }
                } catch (e) {
                  if (e.name !== 'AbortError' && !quiet) {
                    console.warn(e);
                    setStatus('Não foi possível obter o endereço do local.');
                  }
                }
              }

              // Live suggestions (debounced)
              let typeTimer = null;
              let activeIndex = -1;
              addressInput.addEventListener('input', () => {
                const q = addressInput.value.trim();
                setStatus('');
                if (typeTimer) clearTimeout(typeTimer);
                if (q.length < 3) {
                  hideResults();
                  return;
                }
                typeTimer = setTimeout(() => geocode(q, false), 300);
              });

              // Keyboard navigation on results
              addressInput.addEventListener('keydown', (e) => {
                if (resultsEl.style.display !== 'block') return;
                const items = Array.from(resultsEl.querySelectorAll('.list-group-item'));
                if (!items.length) return;
                if (e.key === 'ArrowDown') {
                  e.preventDefault();
                  activeIndex = (activeIndex + 1) % items.length;
                  items.forEach((it, i) => it.classList.toggle('active', i === activeIndex));
                } else if (e.key === 'ArrowUp') {
                  e.preventDefault();
                  activeIndex = (activeIndex - 1 + items.length) % items.length;
                  items.forEach((it, i) => it.classList.toggle('active', i === activeIndex));
                } else if (e.key === 'Enter') {
                  if (activeIndex >= 0) {
                    e.preventDefault();
                    items[activeIndex].click();
                  }
                } else if (e.key === 'Escape') {
                  hideResults();
                }
              });

              document.addEventListener('click', (e) => {
                if (!resultsEl.contains(e.target) && e.target !== addressInput) {
                  hideResults();
                }
              });

              // Buttons
              searchBtn.addEventListener('click', () => geocode(addressInput.value, true));
              addressInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && resultsEl.style.display !== 'block') {
                  e.preventDefault();
                  geocode(addressInput.value, true);
                }
              });

              locateBtn.addEventListener('click', () => {
                if (!navigator.geolocation) {
                  setStatus('Geolocalização não suportada neste navegador.');
                  return;
                }
                setStatus('Obtendo sua localização...');
                navigator.geolocation.getCurrentPosition(
                  (pos) => {
                    const {
                      latitude,
                      longitude,
                      accuracy
                    } = pos.coords;
                    setPoint(latitude, longitude);
                    reverseGeocode(latitude, longitude);
                    setStatus('Localização detectada pelo navegador' + (accuracy ? ` (precisão ~${Math.round(accuracy)}m)` : '') + '.');
                  },
                  (err) => {
                    console.warn(err);
                    setStatus('Não foi possível obter sua localização.');
                  }, {
                    enableHighAccuracy: true,
                    timeout: 12000
                  }
                );
              });

              clearBtn.addEventListener('click', () => {
                clearPoint();
                hideResults();
              });
            })();
          </script>

      <!-- ========== Contato ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-telephone"></i>Seção 3</span>
          <h2 class="app-section-card__title">Contato</h2>
          <span class="app-section-card__hint"><span class="badge text-bg-secondary-subtle border border-secondary-subtle text-secondary-emphasis">opcional</span></span>
        </header>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label for="schoolPhone" class="form-label">Telefone</label>
              <input id="schoolPhone" type="tel" inputmode="tel" class="form-control" name="phone" maxlength="20" placeholder="(00) 0000-0000" value="<?= esc($row['phone'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label for="schoolEmail" class="form-label">E-mail</label>
              <input id="schoolEmail" type="email" inputmode="email" class="form-control" name="email" maxlength="100" placeholder="contato@escola.edu.br" value="<?= esc($row['email'] ?? '') ?>">
            </div>
          </div>
        </div>
      </section>

      <!-- ========== Endereço ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-house"></i>Seção 4</span>
          <h2 class="app-section-card__title">Endereço</h2>
          <span class="app-section-card__hint"><span class="badge text-bg-secondary-subtle border border-secondary-subtle text-secondary-emphasis">opcional</span></span>
        </header>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-12">
              <label for="schoolAddress" class="form-label">Logradouro</label>
              <input id="schoolAddress" type="text" class="form-control" name="address" maxlength="255" placeholder="Rua, número, bairro" value="<?= esc($row['address'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label for="schoolCity" class="form-label">Cidade</label>
              <input id="schoolCity" type="text" class="form-control" name="city" maxlength="100" placeholder="São Paulo" value="<?= esc($row['city'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
              <label for="schoolState" class="form-label">UF</label>
              <input id="schoolState" type="text" class="form-control text-uppercase" name="state" maxlength="2" placeholder="SP" value="<?= esc($row['state'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
              <label for="schoolZip" class="form-label">CEP</label>
              <input id="schoolZip" type="text" inputmode="numeric" class="form-control" name="zip_code" maxlength="9" placeholder="00000-000" value="<?= esc($row['zip_code'] ?? '') ?>">
            </div>
          </div>
        </div>
      </section>

      <!-- ========== Informações Administrativas ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-person-badge"></i>Seção 5</span>
          <h2 class="app-section-card__title">Informações Administrativas</h2>
          <span class="app-section-card__hint"><span class="badge text-bg-secondary-subtle border border-secondary-subtle text-secondary-emphasis">opcional</span></span>
        </header>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-12 col-md-7">
              <label for="schoolDirector" class="form-label">Diretor(a) / Responsável</label>
              <input id="schoolDirector" type="text" class="form-control" name="director_name" maxlength="150" placeholder="Nome completo do responsável" value="<?= esc($row['director_name'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-5">
              <label for="schoolType" class="form-label">Tipo de Instituição</label>
              <select id="schoolType" class="form-select" name="school_type">
                <option value="">— Selecione —</option>
                <?php
                $types = ['Municipal','Estadual','Federal','Particular','Filantrópica','Técnica','Outra'];
                foreach ($types as $t) {
                  $sel = ($row['school_type'] ?? '') === $t ? 'selected' : '';
                  echo "<option value=\"".esc($t)."\" {$sel}>".esc($t)."</option>";
                }
                ?>
              </select>
            </div>
            <div class="col-12">
              <label for="schoolNotes" class="form-label">Observações</label>
              <textarea id="schoolNotes" class="form-control" name="notes" rows="3" maxlength="1000" placeholder="Anotações gerais sobre a instituição..."><?= esc($row['notes'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </section>

      <!-- ========== Status ========== -->
      <section class="app-section-card">
        <div class="app-section-card__body">
          <div class="form-check form-switch">
            <input type="checkbox" class="form-check-input" id="active" name="active" <?= !isset($row['active']) || (int)$row['active'] === 1 ? 'checked' : '' ?>>
            <label for="active" class="form-check-label fw-semibold">Instituição ativa</label>
            <div class="form-text">Quando inativa, a instituição não aparece nos seletores e não recebe novos cadastros.</div>
          </div>
        </div>
      </section>

      <!-- ========== Form actions (sticky) ========== -->
      <div class="app-form-actions">
        <span class="app-form-actions__hint">
          <i class="bi bi-info-circle"></i>
          <?= $id ? 'Editando instituição #' . (int)$id : 'Nova instituição' ?>
        </span>
        <div class="app-form-actions__btns">
          <a class="btn btn-outline-secondary" href="schools.php">
            <i class="bi bi-x-lg me-1"></i>Cancelar
          </a>
          <button class="btn btn-success" type="submit">
            <i class="bi bi-check2-circle me-1"></i>Salvar Instituição
          </button>
        </div>
      </div>
    </form>
  </div>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>

</html>

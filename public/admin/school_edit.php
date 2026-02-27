<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);
if (!is_network_admin($adm)) {
  http_response_code(403);
  exit('Acesso restrito a administradores da rede.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = $_GET['msg'] ?? '';

// Garante colunas lat/lng (para instalações antigas)
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
  $lat = $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
  $lng = $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;

  if ($name === '' || $code === '') {
    $msg = 'Nome e código são obrigatórios.';
  } else {
    if ($id > 0) {
      $st = $pdo->prepare("UPDATE schools SET name=?, code=?, active=?, lat=?, lng=? WHERE id=?");
      $st->execute([$name, $code, $active, $lat, $lng, $id]);
      audit_log('update', 'school', $id, ['name' => $name, 'code' => $code, 'active' => $active, 'lat' => $lat, 'lng' => $lng]);
      header('Location: school_edit.php?id=' . (int)$id . '&msg=' . urlencode('Instituição atualizada.'));
      exit;
    } else {
      $st = $pdo->prepare("INSERT INTO schools (name, code, active, lat, lng) VALUES (?, ?, ?, ?, ?)");
      $st->execute([$name, $code, $active, $lat, $lng]);
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
    http_response_code(404);
    exit('Instituição não encontrada.');
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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <div class="container">
    <?php if ($msg): ?><div class="alert alert-info"><?= esc($msg) ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header"><?= $id ? 'Editar Instituição' : 'Nova Instituição' ?></div>
      <div class="card-body">
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <div class="mb-3">
            <label class="form-label">Nome</label>
            <input class="form-control" name="name" required maxlength="150" value="<?= esc($row['name'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Código</label>
            <input class="form-control" name="code" required maxlength="50" value="<?= esc($row['code'] ?? '') ?>">
            <div class="form-text">Identificador único (ex: EMEF-CENTRO, ESC-A-01)</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Localização (opcional)</label>

            <div class="position-relative">
              <div class="input-group mb-1">
                <input type="text" id="addressQuery" class="form-control" placeholder="Digite endereço ou CEP (ex: Rua, número, bairro, cidade)" autocomplete="off">
                <button class="btn btn-outline-primary" type="button" id="btnSearch"><i class="bi bi-search"></i> Buscar</button>
                <button class="btn btn-outline-secondary" type="button" id="btnLocate"><i class="bi bi-geo-alt"></i> Minha localização</button>
                <button class="btn btn-outline-danger" type="button" id="btnClear"><i class="bi bi-x-circle"></i> Limpar</button>
              </div>
              <div id="addressResults" class="list-group shadow-sm" style="position:absolute;top:100%;left:0;right:0;z-index:1050;display:none;max-height:260px;overflow:auto;"></div>
            </div>

            <div id="geocodeStatus" class="form-text"></div>
            <div id="schoolMap" style="height: 420px; border-radius: .375rem; overflow: hidden; border: 1px solid #dee2e6;"></div>
            <div class="form-text mt-2">
              Dica: digite o endereço e escolha na lista, ou clique/arraste o marcador no mapa. O endereço será preenchido automaticamente.
              O círculo de 300m é exibido apenas como referência para validação de geolocalização.
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Latitude</label>
              <input type="number" step="0.000001" min="-90" max="90" class="form-control" name="lat" id="latInput" value="<?= esc((string)($row['lat'] ?? '')) ?>" placeholder="-23.550520">
            </div>
            <div class="col-md-6">
              <label class="form-label">Longitude</label>
              <input type="number" step="0.000001" min="-180" max="180" class="form-control" name="lng" id="lngInput" value="<?= esc((string)($row['lng'] ?? '')) ?>" placeholder="-46.633308">
            </div>
          </div>

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

          <div class="form-check mb-3 mt-2">
            <input type="checkbox" class="form-check-input" id="active" name="active" <?= !isset($row['active']) || (int)$row['active'] === 1 ? 'checked' : '' ?>>
            <label for="active" class="form-check-label">Ativa</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-success" type="submit">Salvar</button>
            <a class="btn btn-secondary" href="schools.php">Voltar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>

</html>
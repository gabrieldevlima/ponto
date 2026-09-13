<?php
// Patch school_edit.php by working with individual lines
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/school_edit.php';
$lines = file($file); // preserves line endings

$out = [];
$changed = [];

foreach ($lines as $i => $line) {
    $trim = rtrim($line);

    // ---- PATCH 1: After "Garante colunas lat/lng", replace the try/catch blocks ----
    // We detect line "// Garante colunas lat/lng" and replace lines 14-22 with the new loop
    if ($trim === '// Garante colunas lat/lng (para instalações antigas)') {
        $out[] = '// Garante colunas (para instalações antigas)' . "\n";
        $out[] = '$extraCols = [' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN lat DOUBLE NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN lng DOUBLE NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN phone VARCHAR(20) NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN email VARCHAR(100) NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN address VARCHAR(255) NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN city VARCHAR(100) NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN state VARCHAR(2) NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN zip_code VARCHAR(9) NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN director_name VARCHAR(150) NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN school_type VARCHAR(50) NULL",' . "\n";
        $out[] = '  "ALTER TABLE schools ADD COLUMN notes TEXT NULL",' . "\n";
        $out[] = '];' . "\n";
        $out[] = 'foreach ($extraCols as $col) { try { $pdo->exec($col); } catch (Throwable $e) {} }' . "\n";
        $changed[] = 'DB loop';
        // Skip the next 8 lines (try/catch blocks for lat and lng)
        for ($skip = $i + 1; $skip <= $i + 8; $skip++) {
            $lines[$skip] = null; // mark for skipping
        }
        continue;
    }

    // ---- PATCH 2: Replace simple POST variables for lat/lng with full new set ----
    if ($trim === '  $lat = $_POST[\'lat\'] !== \'\' ? (float)$_POST[\'lat\'] : null;') {
        // Replace this line and the next (lng)
        $out[] = '  $lat          = ($_POST[\'lat\'] ?? \'\') !== \'\' ? (float)$_POST[\'lat\'] : null;' . "\n";
        $out[] = '  $lng          = ($_POST[\'lng\'] ?? \'\') !== \'\' ? (float)$_POST[\'lng\'] : null;' . "\n";
        $out[] = '  $phone        = trim($_POST[\'phone\'] ?? \'\') ?: null;' . "\n";
        $out[] = '  $pEmail       = trim($_POST[\'email\'] ?? \'\') ?: null;' . "\n";
        $out[] = '  $address      = trim($_POST[\'address\'] ?? \'\') ?: null;' . "\n";
        $out[] = '  $city         = trim($_POST[\'city\'] ?? \'\') ?: null;' . "\n";
        $out[] = '  $state        = strtoupper(trim($_POST[\'state\'] ?? \'\')) ?: null;' . "\n";
        $out[] = '  $zip_code     = trim($_POST[\'zip_code\'] ?? \'\') ?: null;' . "\n";
        $out[] = '  $director_name= trim($_POST[\'director_name\'] ?? \'\') ?: null;' . "\n";
        $out[] = '  $school_type  = trim($_POST[\'school_type\'] ?? \'\') ?: null;' . "\n";
        $out[] = '  $notes        = trim($_POST[\'notes\'] ?? \'\') ?: null;' . "\n";
        $changed[] = 'POST fields';
        // skip next line (lng assignment)
        $lines[$i + 1] = null;
        continue;
    }

    // ---- PATCH 3: Replace the UPDATE and INSERT SQL with new versions ----
    if (strpos($trim, 'UPDATE schools SET name=?, code=?, active=?, lat=?, lng=? WHERE id=?') !== false) {
        $out[] = '      $fields = [$name, $code, $active, $lat, $lng, $phone, $pEmail, $address, $city, $state, $zip_code, $director_name, $school_type, $notes];' . "\n";
        $out[] = '      $st = $pdo->prepare("UPDATE schools SET name=?, code=?, active=?, lat=?, lng=?, phone=?, email=?, address=?, city=?, state=?, zip_code=?, director_name=?, school_type=?, notes=? WHERE id=?");' . "\n";
        $changed[] = 'UPDATE SQL';
        continue;
    }
    if (strpos($trim, '$st->execute([$name, $code, $active, $lat, $lng, $id])') !== false) {
        $out[] = '      $st->execute([...$fields, $id]);' . "\n";
        continue;
    }
    if (strpos($trim, 'INSERT INTO schools (name, code, active, lat, lng) VALUES') !== false) {
        $out[] = '      $st = $pdo->prepare("INSERT INTO schools (name, code, active, lat, lng, phone, email, address, city, state, zip_code, director_name, school_type, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");' . "\n";
        $changed[] = 'INSERT SQL';
        continue;
    }
    if (strpos($trim, '$st->execute([$name, $code, $active, $lat, $lng])') !== false) {
        $out[] = '      $st->execute($fields);' . "\n";
        continue;
    }

    // ---- PATCH 4: Inject new form sections before the "Ativa" checkbox ----
    if ($trim === '          <div class="form-check mb-3 mt-2">') {
        // Inject all the new optional fields first
        $inject = <<<'HTML'
          <!-- ===== Contato ===== -->
          <hr class="my-4">
          <h6 class="text-muted fw-semibold mb-3"><i class="bi bi-telephone me-2"></i>Contato <span class="badge bg-secondary fw-normal ms-1" style="font-size:.7rem">opcional</span></h6>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Telefone</label>
              <input type="tel" class="form-control" name="phone" maxlength="20" placeholder="(00) 0000-0000" value="<?= esc($row['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">E-mail</label>
              <input type="email" class="form-control" name="email" maxlength="100" placeholder="contato@escola.edu.br" value="<?= esc($row['email'] ?? '') ?>">
            </div>
          </div>

          <!-- ===== Endereço ===== -->
          <h6 class="text-muted fw-semibold mb-3 mt-4"><i class="bi bi-geo me-2"></i>Endereço <span class="badge bg-secondary fw-normal ms-1" style="font-size:.7rem">opcional</span></h6>
          <div class="mb-3">
            <label class="form-label">Logradouro</label>
            <input type="text" class="form-control" name="address" maxlength="255" placeholder="Rua, número, bairro" value="<?= esc($row['address'] ?? '') ?>">
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Cidade</label>
              <input type="text" class="form-control" name="city" maxlength="100" placeholder="São Paulo" value="<?= esc($row['city'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">UF</label>
              <input type="text" class="form-control" name="state" maxlength="2" placeholder="SP" value="<?= esc($row['state'] ?? '') ?>" style="text-transform:uppercase">
            </div>
            <div class="col-md-3">
              <label class="form-label">CEP</label>
              <input type="text" class="form-control" name="zip_code" maxlength="9" placeholder="00000-000" value="<?= esc($row['zip_code'] ?? '') ?>">
            </div>
          </div>

          <!-- ===== Informações Administrativas ===== -->
          <h6 class="text-muted fw-semibold mb-3 mt-4"><i class="bi bi-person-badge me-2"></i>Informações Administrativas <span class="badge bg-secondary fw-normal ms-1" style="font-size:.7rem">opcional</span></h6>
          <div class="row g-3 mb-3">
            <div class="col-md-7">
              <label class="form-label">Diretor(a) / Responsável</label>
              <input type="text" class="form-control" name="director_name" maxlength="150" placeholder="Nome completo do responsável" value="<?= esc($row['director_name'] ?? '') ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label">Tipo de Instituição</label>
              <select class="form-select" name="school_type">
                <option value="">-- Selecione --</option>
                <?php
                $types = ['Municipal','Estadual','Federal','Particular','Filantrópica','Técnica','Outra'];
                foreach ($types as $t) {
                  $sel = ($row['school_type'] ?? '') === $t ? 'selected' : '';
                  echo "<option value=\"".esc($t)."\" {$sel}>".esc($t)."</option>";
                }
                ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Observações</label>
            <textarea class="form-control" name="notes" rows="3" maxlength="1000" placeholder="Anotações gerais sobre a instituição..."><?= esc($row['notes'] ?? '') ?></textarea>
          </div>

          <!-- ===== Status ===== -->
          <hr class="my-4">
          <div class="form-check mb-3">
HTML;
        foreach (explode("\n", $inject) as $injLine) {
            $out[] = $injLine . "\n";
        }
        $changed[] = 'Form fields injected';
        // Continue without adding the original div (the form-check gets replaced properly)
        // But we need to still output the original checkbox line properly:
        $out[] = '            <input type="checkbox" class="form-check-input" id="active" name="active" <?= !isset($row[\'active\']) || (int)$row[\'active\'] === 1 ? \'checked\' : \'\' ?>>' . "\n";
        $out[] = '            <label for="active" class="form-check-label">Ativa</label>' . "\n";
        $out[] = '          </div>' . "\n";
        // skip the next 3 lines (original form-check innards)
        for ($skip = $i + 1; $skip <= $i + 3; $skip++) {
            $lines[$skip] = null;
        }
        continue;
    }

    if ($line === null)
        continue; // skip marked lines
    $out[] = $line;
}

// Remove nulled lines
$final = implode('', array_filter($out, fn($l) => $l !== null));
file_put_contents($file, $final);
if (function_exists('opcache_reset'))
    opcache_reset();
echo 'Changes applied: ' . implode(', ', $changed) . "\n";
echo 'Total output lines: ' . count($out) . "\n";
?>

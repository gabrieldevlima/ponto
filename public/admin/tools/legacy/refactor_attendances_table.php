<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/attendances.php';
$content = file_get_contents($file);

// Replace Headers
$headers_target = <<<HTML
            <th>Carga Horária</th>
            <th>Comprovantes</th>
            <th>Status</th>
            <th>Origem/Justificativa</th>
            <th>Edição</th>
HTML;
$headers_replacement = <<<HTML
            <th>Status</th>
HTML;
$content = str_replace($headers_target, $headers_replacement, $content);

// Regex matching the 5 columns to extract and the Start of the 6th Ações column
$pattern = '/(<td class="text-nowrap">\s*<div>Trabalhada:.*?<\/td>)\s*(<td class="text-nowrap">\s*<\?php\s*\$hasIn.*?<\/td>)\s*(<td class="text-nowrap">\s*<\?php if \(\$approved === null\):.*?<\/td>)\s*(<td class="text-center">\s*<span class="badge.*?<\/td>)\s*(<td class="text-start">\s*<\?php if \(\$wasEdited\):.*?<\/td>)\s*(<td>\s*<div class="d-flex flex-wrap gap-2 justify-content-center">)/s';

if (preg_match($pattern, $content, $matches)) {
    $cargaHorariaTd = $matches[1];
    $comprovantesTd = $matches[2];
    $statusTd = $matches[3];
    $origemTd = $matches[4];
    $edicaoTd = $matches[5];
    $acoesTdStart = $matches[6]; // "<td> ... <div class...>"

    // Strip the outer <td> tags for the modal content using a simpler non-greedy approach
    $cargaHorariaContent = preg_replace('/^<td[^>]*>(.*)<\/td>$/s', '$1', $cargaHorariaTd);
    $comprovantesContent = preg_replace('/^<td[^>]*>(.*)<\/td>$/s', '$1', $comprovantesTd);
    $origemContent = preg_replace('/^<td[^>]*>(.*)<\/td>$/s', '$1', $origemTd);
    $edicaoContent = preg_replace('/^<td[^>]*>(.*)<\/td>$/s', '$1', $edicaoTd);

    $modalHtml = <<<HTML
$statusTd
$acoesTdStart
                  <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#modalAttendance<?= \$r['id'] ?>">
                    <i class="bi bi-eye"></i> Detalhes
                  </button>

                  <div class="modal fade text-start" id="modalAttendance<?= \$r['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title">Detalhes do Registro</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <div class="row g-4">
                            <div class="col-md-6">
                              <h6 class="fw-bold border-bottom pb-2">Carga Horária</h6>
                              $cargaHorariaContent
                            </div>
                            <div class="col-md-6">
                              <h6 class="fw-bold border-bottom pb-2">Comprovantes</h6>
                              $comprovantesContent
                            </div>
                            <div class="col-md-6">
                              <h6 class="fw-bold border-bottom pb-2">Origem/Justificativa</h6>
                              $origemContent
                            </div>
                            <div class="col-md-6">
                              <h6 class="fw-bold border-bottom pb-2">Edição</h6>
                              $edicaoContent
                            </div>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        </div>
                      </div>
                    </div>
                  </div>
HTML;

    $content = preg_replace($pattern, $modalHtml, $content);
    echo "Pattern matched and replaced.\n";
}
else {
    echo "Pattern did not match!\n";
}

file_put_contents($file, $content);

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared.\n";
}

?>

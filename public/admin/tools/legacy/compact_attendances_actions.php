<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/attendances.php';
$content = file_get_contents($file);

// We want to match from the start of the Ações <td> to the end of the </td>, capturing the modal inside.
// The structure is roughly:
// <td>
//   <div class="d-flex flex-wrap gap-2 justify-content-center">
//     <button ...>Detalhes</button>
//     <div class="modal ...">...</div>
//     <a ...>Editar</a>
//     <?php if... form ... Approve </form>
//     <?php if... form ... Reject </form>
//   </div>
// </td>

$pattern = '/(<td>\s*<div class="d-flex flex-wrap gap-2 justify-content-center">\s*)<button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#modalAttendance<\?= \$r\[\'id\'\] \?>">\s*<i class="bi bi-eye"><\/i> Detalhes\s*<\/button>\s*(<div class="modal fade text-start" id="modalAttendance<\?= \$r\[\'id\'\] \?>".*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>)\s*<a href="attendance_edit\.php\?id=<\?= \(int\)\$r\[\'id\'\] \?>" class="btn btn-sm btn-outline-primary">\s*<i class="bi bi-pencil-square"><\/i> Editar\s*<\/a>\s*(<\?php if \(\$approved === null \|\| \(int\)\$approved === 0\): \?>.*?<\/form>\s*<\?php endif; \?>)\s*(<\?php if \(\$approved === null \|\| \(int\)\$approved === 1\): \?>.*?<\/form>\s*<\?php endif; \?>)\s*<\/div>\s*<\/td>/s';

if (preg_match($pattern, $content, $matches)) {
    $modalHtmlOuter = $matches[2];

    // Original Approve Form
    $approveFormRaw = $matches[3];
    // Original Reject Form
    $rejectFormRaw = $matches[4];

    // Restyle the forms for dropdown items:
    // Change <button type="submit" class="btn btn-sm btn-outline-success"> to <button type="submit" class="dropdown-item text-success">
    $approveFormRestyled = str_replace('btn btn-sm btn-outline-success', 'dropdown-item text-success', $approveFormRaw);
    $rejectFormRestyled = str_replace('btn btn-sm btn-outline-danger', 'dropdown-item text-danger', $rejectFormRaw);

    $replacement = <<<HTML
              <td class="text-center align-middle">
                <!-- Ações Dropdown -->
                <div class="dropdown">
                  <button class="btn btn-light btn-sm border" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Ações">
                    <i class="bi bi-three-dots-vertical"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li>
                      <button type="button" class="dropdown-item text-info" data-bs-toggle="modal" data-bs-target="#modalAttendance<?= \$r['id'] ?>">
                        <i class="bi bi-eye"></i> Detalhes
                      </button>
                    </li>
                    <li>
                      <a class="dropdown-item text-primary" href="attendance_edit.php?id=<?= (int)\$r['id'] ?>">
                        <i class="bi bi-pencil-square"></i> Editar
                      </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      $approveFormRestyled
                    </li>
                    <li>
                      $rejectFormRestyled
                    </li>
                  </ul>
                </div>

                <!-- Detalhes Modal (Placed outside dropdown to prevent clipping) -->
                $modalHtmlOuter
              </td>
HTML;

    $content = preg_replace($pattern, $replacement, $content);

    // Also: Shrink the table header <th>Ações</th> width
    $content = str_replace('<th>Ações</th>', '<th style="min-width: 80px;">Ações</th>', $content);

    file_put_contents($file, $content);
    echo "Actions grouped successfully.\n";
}
else {
    echo "Pattern failed to match the Ações cell. Please refine the regex.\n";
}

if (function_exists('opcache_reset')) {
    opcache_reset();
}
?>

<?php
$file = 'c:/xampp/htdocs/ponto_ribeira/public/admin/teachers.php';
$content = file_get_contents($file);

// 1. Shrink header width
$content = preg_replace('/<th class="text-center" style="min-width: 420px;">A\x{00e7}\x{00f5}es<\/th>/u', '<th class="text-center" style="width: 80px;">Ações</th>', $content);
$content = str_replace('<th class="text-center" style="min-width: 420px;">Ações</th>', '<th class="text-center" style="width: 80px;">Ações</th>', $content);

// 2. Replace the entire td block with the dropdown
$pattern = '/<td class="text-center table-actions">.*?<\/td>/s';

$replacement = <<<'HTML'
<td class="text-center table-actions">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                      <li>
                        <a href="teacher_edit.php?id=<?= (int)$t['id'] ?>" class="dropdown-item text-primary">
                          <i class="bi bi-pencil me-2"></i> Editar
                        </a>
                      </li>
                      <li>
                        <a href="teacher_monthly_report.php?teacher_id=<?= (int)$t['id'] ?>&month=<?= date('Y-m') ?>" class="dropdown-item text-info">
                          <i class="bi bi-bar-chart-line me-2"></i> Relatório Mensal
                        </a>
                      </li>
                      <li>
                        <a href="reports_financial.php?teacher_id=<?= (int)$t['id'] ?>&month=<?= date('Y-m') ?>" class="dropdown-item text-success">
                          <i class="bi bi-cash-coin me-2"></i> Financeiro
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form action="<?= htmlspecialchars(keep_params(), ENT_QUOTES, 'UTF-8') ?>" method="post" class="m-0 p-0">
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                          <button type="submit" class="dropdown-item text-warning fw-semibold" onclick="return confirm('Alterar status deste colaborador?')">
                            <i class="bi bi-power me-2"></i> <?= ((int)$t['active'] === 1) ? 'Desativar' : 'Ativar' ?>
                          </button>
                        </form>
                      </li>
                    </ul>
                  </div>
                </td>
HTML;

$content = preg_replace($pattern, $replacement, $content);

file_put_contents($file, $content);

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared.\n";
}
echo "Buttons grouped via PHP script.\n";
?>

<?php
/**
 * Include de paginação reutilizável.
 * Espera no escopo: $currentPage, $totalPages, $baseUrl (URL com query string, sem parâmetro page).
 * Opcional: $perPage (ex.: 20), $totalItems (total de registros, para "X–Y de Z").
 */
if (!isset($currentPage) || !isset($totalPages) || !isset($baseUrl)) {
    return;
}
$currentPage = max(1, (int)$currentPage);
$totalPages = max(1, (int)$totalPages);
$sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';
$pageParam = 'page';
$urlForPage = function ($p) use ($baseUrl, $sep, $pageParam) {
    return $baseUrl . $sep . $pageParam . '=' . (int)$p;
};
$prevDisabled = $currentPage <= 1;
$nextDisabled = $currentPage >= $totalPages;
?>
<div class="admin-pagination-wrap">
  <nav aria-label="Paginação">
    <ul class="pagination justify-content-center flex-wrap mb-0">
      <li class="page-item<?= $prevDisabled ? ' disabled' : '' ?>">
        <?php if ($prevDisabled): ?>
          <span class="page-link">« Anterior</span>
        <?php else: ?>
          <a class="page-link" href="<?= esc($urlForPage($currentPage - 1)) ?>">« Anterior</a>
        <?php endif; ?>
      </li>
      <?php
      $range = 2;
      $from = max(1, $currentPage - $range);
      $to = min($totalPages, $currentPage + $range);
      if ($from > 1): ?>
        <li class="page-item">
          <a class="page-link" href="<?= esc($urlForPage(1)) ?>">1</a>
        </li>
        <?php if ($from > 2): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif;
      endif;
      for ($p = $from; $p <= $to; $p++): ?>
        <li class="page-item<?= $p === $currentPage ? ' active' : '' ?>">
          <a class="page-link" href="<?= esc($urlForPage($p)) ?>"><?= $p ?></a>
        </li>
      <?php endfor;
      if ($to < $totalPages): ?>
        <?php if ($to < $totalPages - 1): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <li class="page-item">
          <a class="page-link" href="<?= esc($urlForPage($totalPages)) ?>"><?= $totalPages ?></a>
        </li>
      <?php endif; ?>
      <li class="page-item<?= $nextDisabled ? ' disabled' : '' ?>">
        <?php if ($nextDisabled): ?>
          <span class="page-link">Próximo »</span>
        <?php else: ?>
          <a class="page-link" href="<?= esc($urlForPage($currentPage + 1)) ?>">Próximo »</a>
        <?php endif; ?>
      </li>
    </ul>
  </nav>
</div>

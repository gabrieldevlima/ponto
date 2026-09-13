<?php
/**
 * Rodapé compartilhado (B3).
 * Uso: <?php include __DIR__ . '/_footer.php'; ?>
 *
 * Variáveis opcionais:
 *   $footerAppBase (string) — base do app para resolver caminhos de imagens.
 *                             Default: detecta automaticamente.
 */
// F4: guard contra inclusão dupla no mesmo request — evita duplicar HTML/CSS.
if (defined('APP_FOOTER_RENDERED')) return;
define('APP_FOOTER_RENDERED', true);

if (!isset($footerAppBase)) {
    $footerAppBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($footerAppBase === '') $footerAppBase = '/';
}
$__fYear = date('Y');
$__fVer = 'v2.12.0';
?>
<footer class="app-footer" role="contentinfo">
  <div class="app-footer-divider" aria-hidden="true"></div>
  <div class="app-footer-name">DEEDO Ponto</div>
  <div class="app-footer-tag">Sistema de Ponto Eletrônico</div>
  <div class="app-footer-badge">
    <i class="bi bi-shield-check" aria-hidden="true"></i>
    <span>Portaria MTP 671/2021</span>
  </div>
  <div class="app-footer-copy">
    &copy; <?= $__fYear ?> DEEDO Sistemas <span class="app-footer-sep">·</span> <?= $__fVer ?>
  </div>
</footer>

<style>
  .app-footer {
    max-width: 640px;
    margin: 32px auto 0;
    padding: 0 16px max(20px, env(safe-area-inset-bottom, 20px));
    text-align: center;
    font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, sans-serif;
  }
  .app-footer-divider {
    height: 1px;
    background: var(--r-edge, #e5e7eb);
    margin: 0 auto 18px;
    max-width: 120px;
    opacity: .7;
  }
  .app-footer-name {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: var(--r-ink-2, #475569);
    margin-bottom: 4px;
  }
  .app-footer-tag {
    font-size: 12.5px;
    color: var(--r-ink-3, #6b7280);
    font-weight: 500;
    margin-bottom: 12px;
  }
  .app-footer-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    background: var(--r-brand-soft, #e7f1ff);
    color: var(--r-brand, #0d6efd);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .02em;
    margin-bottom: 12px;
  }
  .app-footer-badge i { font-size: 13px; opacity: .9; }
  .app-footer-copy {
    font-size: 11px;
    color: var(--r-ink-3, #6b7280);
    opacity: .85;
    font-weight: 500;
  }
  .app-footer-sep {
    color: var(--r-edge, #e5e7eb);
    margin: 0 3px;
  }
</style>

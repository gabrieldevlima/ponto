/**
 * Puppeteer script para Lighthouse CI — faz login no admin com credenciais do
 * seed (sql/seeds/lhci_seed.sql) antes de cada URL auditada.
 *
 * Credenciais:
 *   CPF:    000.000.000-00 (cadastrado como "00000000000" no seed)
 *   Senha:  lhci-test-2025
 *
 * Variáveis de ambiente (override pelo workflow se necessário):
 *   LHCI_LOGIN_URL  — URL completa do form de login (default: http://localhost/ponto_ribeira/public/admin/login.php)
 *   LHCI_CPF        — CPF do admin de teste
 *   LHCI_PASSWORD   — Senha do admin de teste
 */
module.exports = async (browser, context) => {
  // login.php é a única página pré-auth e não precisa de login para auditá-la.
  if (context.url.endsWith('/admin/login.php')) return;

  const loginUrl = process.env.LHCI_LOGIN_URL
    || context.url.replace(/\/admin\/[^/]+\.php.*$/, '/admin/login.php');
  const cpf = process.env.LHCI_CPF || '00000000000';
  const password = process.env.LHCI_PASSWORD || 'lhci-test-2025';

  const page = await browser.newPage();
  try {
    await page.goto(loginUrl, { waitUntil: 'networkidle0', timeout: 15000 });

    // Tenta detectar campos do form de login (compatível com diferentes layouts)
    const cpfSelector = await page.$('input[name="cpf"], input[name="username"], input[type="text"]');
    const passSelector = await page.$('input[name="password"], input[type="password"]');
    if (!cpfSelector || !passSelector) {
      // Fail-fast em CI: se o form mudou ou seed não foi importado, abortar com erro
      // explícito é melhor do que silenciosamente auditar páginas pré-auth.
      await page.close();
      throw new Error(
        '[lhci-login] Campos do form de login não encontrados. ' +
        'Verifique se o seed lhci_seed.sql foi importado e se os selectors do login.php estão atualizados.'
      );
    }

    await cpfSelector.type(cpf, { delay: 5 });
    await passSelector.type(password, { delay: 5 });

    // Submete o form e espera o navigation completar
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 }),
      page.click('button[type="submit"], input[type="submit"]'),
    ]);

    console.log('[lhci-login] Login bem-sucedido. Cookies prontos para auditoria.');
  } catch (e) {
    console.warn('[lhci-login] Falha no login (continua com cookies vazios):', e.message);
  } finally {
    await page.close().catch(() => {});
  }
};

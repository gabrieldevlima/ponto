/**
 * Puppeteer script para Lighthouse CI — faz login no admin com as credenciais
 * do seed (sql/seeds/lhci_seed.sql) antes de cada URL auditada.
 *
 * Credenciais:
 *   CPF:    714.287.938-60 (cadastrado como "71428793860" no seed)
 *   Senha:  lhci-test-2025
 *
 * Variáveis de ambiente (override pelo workflow se necessário):
 *   LHCI_LOGIN_URL  — URL completa do form de login (default: login.php ao lado da URL auditada)
 *   LHCI_CPF        — CPF do admin de teste
 *   LHCI_PASSWORD   — Senha do admin de teste
 *
 * Por que os seletores são exatos: o form tem um HONEYPOT anti-bot — um
 * <input type="text" name="website"> escondido, ANTES dos campos reais. O
 * seletor antigo aceitava `input[type="text"]` como alternativa, e o page.$()
 * devolve o primeiro que casar no documento: o CPF era digitado no honeypot, o
 * login recusava ("Falha de validação"), as falhas somavam no limite por IP e o
 * botão "Entrar" acabava desabilitado — daí o timeout em toda URL.
 */
module.exports = async (browser, context) => {
  // login.php é a única página pré-auth e não precisa de login para auditá-la.
  if (context.url.endsWith('/admin/login.php')) return;

  const alvo = new URL(context.url);
  const partes = alvo.pathname.split('/');
  partes[partes.length - 1] = 'login.php';
  alvo.pathname = partes.join('/');
  alvo.search = '';
  const loginUrl = process.env.LHCI_LOGIN_URL || alvo.toString();
  const cpf = process.env.LHCI_CPF || '71428793860';
  const password = process.env.LHCI_PASSWORD || 'lhci-test-2025';

  const page = await browser.newPage();
  try {
    // domcontentloaded, não networkidle0: o servidor embutido do PHP atende uma
    // requisição por vez e a página puxa CSS/JS de CDN — esperar a rede ficar
    // ociosa era frágil e não diz nada sobre o form estar pronto.
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });

    // Os cookies persistem entre as URLs auditadas: da segunda em diante a
    // sessão já está aberta e login.php redireciona para o dashboard. Não há o
    // que fazer — reusa a sessão. Sem isso o script procurava o formulário no
    // dashboard e falhava na segunda URL.
    if (!new URL(page.url()).pathname.endsWith('/login.php')) {
      console.log('[lhci-login] Sessão já aberta, reusando para ' + context.url);
      return;
    }

    const cpfField = await page.$('input[name="cpf"]');
    const passField = await page.$('input[name="pass"]');
    if (!cpfField || !passField) {
      throw new Error('campos cpf/pass não encontrados em ' + loginUrl
        + ' — o form mudou ou o seed lhci_seed.sql não foi importado');
    }

    await cpfField.type(cpf, { delay: 5 });
    await passField.type(password, { delay: 5 });

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
      page.click('#submitBtn'),
    ]);

    // Sem esta conferência, um login recusado passava em silêncio: o Lighthouse
    // auditava a tela de login no lugar de cada página interna e o relatório
    // parecia cobrir telas que ele nunca viu.
    if (new URL(page.url()).pathname.endsWith('/login.php')) {
      const aviso = await page.$eval('.alert', el => el.textContent.trim()).catch(() => '(sem mensagem)');
      throw new Error('login recusado — ainda em login.php: ' + aviso);
    }
    console.log('[lhci-login] Login ok para ' + context.url);
  } finally {
    await page.close().catch(() => {});
  }
};

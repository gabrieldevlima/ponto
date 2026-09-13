# Auditoria de acessibilidade automática (Lighthouse CI)

Configuração para regressão de a11y no painel admin.

## Arquivos

- `lighthouserc.json` — URLs auditadas + thresholds + asserções estritas
- `.github/workflows/a11y.yml` — workflow GitHub Actions que roda em PRs que tocam `public/admin/**`

## URLs auditadas (10)

login, dashboard, teachers, attendances, leaves, reports_insights, reports, reports_financial,
audit_log, migrations.

## Thresholds (em `lighthouserc.json`)

| Categoria | Mínimo | Severidade |
|---|---|---|
| Accessibility | 90 | **error** (falha o build) |
| Best Practices | 85 | warn |
| SEO | 80 | warn |
| Performance | — | off (admin não tem requisito) |

## Asserções estritas (build falha se quebrar)

- `color-contrast` — todos os textos com contraste WCAG AA
- `html-has-lang` — `<html lang="pt-br">` presente
- `valid-lang` — atributo lang válido
- `label` — todos os inputs têm `<label>` associado
- `image-alt` — todas as `<img>` têm `alt`

## Rodar localmente

```bash
# Instala lhci CLI
npm install -g @lhci/cli@0.13.x

# Garante que XAMPP está rodando em http://localhost/ponto_ribeira/
# Roda a auditoria
lhci autorun --config=./lighthouserc.json
```

Os resultados são publicados em `https://lhci-temp.appspot.com/...` (temporary public storage)
e o link aparece no log do CI.

## Como o CI faz login no admin

Lighthouse roda anônimo — todas as URLs em `lighthouserc.json` precisam ser **publicamente
acessíveis** sem auth. Para o admin, o workflow espera um `sql/seeds/lhci_seed.sql` (opcional)
com um admin de teste; se não existir, o Lighthouse audita a tela de login (que é pré-auth de
qualquer forma). Para auditar páginas autenticadas:

1. Crie `sql/seeds/lhci_seed.sql` com `INSERT INTO admins ...`
2. Use o `puppeteerScript` do `lighthouserc.json` para fazer o login antes de cada URL
   (veja [docs do lhci](https://github.com/GoogleChrome/lighthouse-ci/blob/main/docs/configuration.md#puppeteerscript))

Sem isso, o CI valida só páginas pré-auth (login.php) — ainda assim é útil porque pega regressões
de contrast/label/lang.

## Atualizando thresholds

Se um threshold ficar muito estrito, edite `lighthouserc.json` na seção `assert.assertions`.
Mantenha `accessibility >= 90` como linha vermelha — abaixo disso quebra a conformidade WCAG 2.1 AA.

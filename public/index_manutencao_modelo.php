<?php
/**
 * Manutenção: na produção, substitua o conteúdo de index.php por ESTE arquivo inteiro
 * (ou copie tudo abaixo da linha em branco após os headers para dentro do seu index.php).
 */
http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Em manutenção</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f1f5f9; color: #334155; }
    main { text-align: center; padding: 1.5rem; max-width: 28rem; }
    h1 { font-size: 1.25rem; color: #0f172a; margin: 0 0 0.5rem; }
    p { margin: 0; line-height: 1.5; font-size: 0.95rem; }
  </style>
</head>
<body>
  <main>
    <h1>Sistema em manutenção</h1>
    <p>Voltamos em breve. Tente novamente mais tarde.</p>
  </main>
</body>
</html>

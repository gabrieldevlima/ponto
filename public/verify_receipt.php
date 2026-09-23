<?php
declare(strict_types=1);
/**
 * Verificação PÚBLICA de comprovante de marcação.
 * ===============================================
 * Fase 2.4 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md
 *
 * Uso: verify_receipt.php?nsr=1234[&hash=ABCD...]
 *
 * PARA QUE SERVE
 * Um comprovante em PDF, sozinho, não prova nada — qualquer um edita um PDF.
 * O que dá valor a ele é poder conferir, contra o sistema, que o NSR impresso
 * corresponde a uma marcação cuja assinatura fecha e cuja cadeia está íntegra.
 * Serve ao trabalhador, ao sindicato, ao contador e ao auditor fiscal.
 *
 * POR QUE É PÚBLICA E MESMO ASSIM NÃO VAZA DADOS
 * A página é deliberadamente ANÔNIMA — exigir login inutilizaria o caso de uso
 * (um fiscal conferindo a via impressa não tem conta no sistema). Em troca,
 * NADA de dado pessoal é exposto: sem nome, sem CPF, sem GPS, sem foto, sem
 * horário. A resposta diz apenas se aquele NSR existe, se a assinatura fecha,
 * se está anulado e se o dia está selado.
 *
 * Isso é o suficiente para o que a verificação precisa responder e mantém o
 * NSR — que é sequencial e portanto trivialmente enumerável — inútil como
 * vetor de extração de dados (LGPD art. 6º, princípio da necessidade).
 *
 * Se o parâmetro `hash` for informado, ele é comparado contra o selo real: é
 * assim que se detecta um PDF com o hash adulterado.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/nsr_ledger.php';
require_once __DIR__ . '/../lib/ledger_seal.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$nsrParam  = isset($_GET['nsr']) ? (int)$_GET['nsr'] : 0;
$hashParam = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)($_GET['hash'] ?? '')));

$pdo = db();

// Rate limit simples: a página é anônima e o NSR é enumerável. Não há dado
// pessoal a extrair, mas varredura em massa também não tem razão de existir.
$ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
$rl = auth_attempt_is_limited($pdo, 'verify_receipt', $ipHash, 300, 120);
if (!empty($rl['limited'])) {
    http_response_code(429);
    echo '<!doctype html><meta charset="utf-8"><p>Muitas consultas. Aguarde alguns minutos.</p>';
    exit;
}

$resultado = null;
$evento    = null;
$selo      = null;
$seloAudit = null;
$anulado   = null;

if ($nsrParam > 0) {
    auth_attempt_log($pdo, 'verify_receipt', $ipHash, true, null, 'consulta');

    // Resolve por NSR do livro ou, para vias antigas, pelo NSR legado.
    //
    // Carrega a linha COMPLETA de propósito: nsr_ledger_verify_row() recomputa
    // o payload canônico a partir das colunas, e um SELECT parcial faria a
    // verificação falhar sempre — acusando adulteração em marcação íntegra.
    // A proteção de dados desta página está no que é RENDERIZADO (nome, CPF,
    // GPS e horário nunca chegam ao HTML), não no que é consultado.
    $st = $pdo->prepare("SELECT * FROM nsr_ledger WHERE nsr = ? AND event_type = 'mark' LIMIT 1");
    $st->execute([$nsrParam]);
    $evento = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$evento) {
        $st = $pdo->prepare("SELECT * FROM nsr_ledger WHERE legacy_nsr = ? AND event_type = 'mark' LIMIT 1");
        $st->execute([$nsrParam]);
        $evento = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$evento) {
        $resultado = ['nivel' => 'erro', 'titulo' => 'Marcação não encontrada',
                      'texto' => 'Nenhuma marcação com este NSR consta no livro fiscal. '
                               . 'Confira o número impresso no comprovante.'];
    } else {
        // Assinatura da linha (payload + HMAC). Não verifica o elo com o
        // predecessor aqui: isso é papel de bin/ledger_verify.php, que percorre
        // a cadeia inteira. Aqui interessa a marcação específica consultada.
        $problema = null;
        try {
            $problema = nsr_ledger_verify_row($evento);
        } catch (Throwable $e) {
            $problema = ['tipo' => 'indisponivel', 'detalhe' => 'chave de verificacao indisponivel'];
        }

        // Anulação: evento `void` apontando para este NSR.
        $stV = $pdo->prepare("SELECT nsr, marked_at, reason FROM nsr_ledger
                               WHERE event_type = 'void' AND target_nsr = ? ORDER BY nsr ASC LIMIT 1");
        $stV->execute([(int)$evento['nsr']]);
        $anulado = $stV->fetch(PDO::FETCH_ASSOC) ?: null;

        // Selo do dia que cobre este NSR.
        $stS = $pdo->prepare("SELECT * FROM nsr_ledger_seals WHERE ? BETWEEN first_nsr AND last_nsr LIMIT 1");
        $stS->execute([(int)$evento['nsr']]);
        $selo = $stS->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selo) $seloAudit = ledger_seal_audit($pdo, $selo);

        if ($problema !== null) {
            $resultado = ['nivel' => 'erro', 'titulo' => 'Marcação NÃO confere',
                          'texto' => 'A assinatura desta marcação não fecha (' . $problema['tipo'] . '). '
                                   . 'Isso indica que os dados foram alterados após o registro. '
                                   . 'Procure o setor responsável pelo ponto.'];
        } elseif ($hashParam !== '' && !str_starts_with(strtoupper((string)$evento['record_hash']), $hashParam)) {
            $resultado = ['nivel' => 'erro', 'titulo' => 'Selo do documento não confere',
                          'texto' => 'A marcação existe e está íntegra no sistema, mas o selo impresso '
                                   . 'no documento apresentado NÃO corresponde a ela. O documento pode '
                                   . 'ter sido adulterado.'];
        } elseif ($anulado) {
            $resultado = ['nivel' => 'alerta', 'titulo' => 'Marcação ANULADA',
                          'texto' => 'Esta marcação existe e está íntegra, mas foi anulada e não vale '
                                   . 'como prova de jornada.'];
        } else {
            $resultado = ['nivel' => 'ok', 'titulo' => 'Marcação autêntica',
                          'texto' => 'A marcação consta no livro fiscal e a assinatura confere.'];
        }
    }
}

$pubkey = '';
try { $pubkey = ledger_seal_public_key_b64(); } catch (Throwable $e) { $pubkey = ''; }

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$cores = ['ok' => '#0b6b3a', 'alerta' => '#8a6100', 'erro' => '#b00020'];
$fundos = ['ok' => '#e9f7ef', 'alerta' => '#fff6e5', 'erro' => '#fdecef'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificação de comprovante de ponto</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
         max-width: 680px; margin: 0 auto; padding: 24px 16px; color: #1a1a1a; line-height: 1.5; }
  h1 { font-size: 1.35rem; margin: 0 0 4px; }
  .sub { color: #555; font-size: .9rem; margin-bottom: 20px; }
  form { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
  input { padding: 9px 11px; border: 1px solid #bbb; border-radius: 6px; font-size: 1rem; }
  input[name=nsr] { width: 150px; }
  input[name=hash] { flex: 1; min-width: 190px; font-family: ui-monospace, monospace; }
  button { padding: 9px 18px; border: 0; border-radius: 6px; background: #14532d; color: #fff;
           font-size: 1rem; cursor: pointer; }
  .box { border-left: 5px solid; border-radius: 6px; padding: 14px 16px; margin-bottom: 18px; }
  .box h2 { margin: 0 0 6px; font-size: 1.05rem; }
  table { border-collapse: collapse; width: 100%; font-size: .9rem; margin-bottom: 18px; }
  th, td { text-align: left; padding: 7px 9px; border-bottom: 1px solid #eaeaea; vertical-align: top; }
  th { width: 42%; color: #555; font-weight: 600; }
  .mono { font-family: ui-monospace, monospace; word-break: break-all; font-size: .82rem; }
  footer { margin-top: 28px; padding-top: 14px; border-top: 1px solid #eee; color: #666; font-size: .8rem; }
</style>
</head>
<body>

<h1>Verificação de comprovante de ponto</h1>
<div class="sub">
  Confere se a marcação impressa num comprovante consta no livro fiscal e se
  continua íntegra. Informe o NSR do documento.
</div>

<form method="get">
  <input type="number" name="nsr" placeholder="NSR" value="<?= $h($nsrParam ?: '') ?>" required min="1">
  <input type="text" name="hash" placeholder="Selo (opcional)" value="<?= $h($hashParam) ?>" maxlength="64">
  <button type="submit">Verificar</button>
</form>

<?php if ($resultado): ?>
  <div class="box" style="border-color: <?= $cores[$resultado['nivel']] ?>; background: <?= $fundos[$resultado['nivel']] ?>;">
    <h2 style="color: <?= $cores[$resultado['nivel']] ?>;"><?= $h($resultado['titulo']) ?></h2>
    <div><?= $h($resultado['texto']) ?></div>
  </div>
<?php endif; ?>

<?php if ($evento): ?>
  <table>
    <tr><th>NSR</th><td class="mono"><?= $h($evento['nsr']) ?></td></tr>
    <tr><th>Tipo de marcação</th>
        <td><?= $evento['direction'] === 'E' ? 'Entrada' : 'Saída' ?>
            <?= $evento['record_type'] === 'break' ? ' (intervalo)' : '' ?></td></tr>
    <tr><th>Data de competência</th><td><?= $h($evento['work_date']) ?></td></tr>
    <tr><th>Registrada no livro em</th><td><?= $h($evento['created_at']) ?></td></tr>
    <tr><th>Origem</th><td><?= $h($evento['origin']) ?> / <?= $h($evento['record_mode']) ?></td></tr>
    <tr><th>Selo da marcação</th><td class="mono"><?= $h(strtoupper(substr((string)$evento['record_hash'], 0, 32))) ?>…</td></tr>
    <?php if ($anulado): ?>
      <tr><th>Anulada pelo NSR</th><td class="mono"><?= $h($anulado['nsr']) ?> em <?= $h($anulado['marked_at']) ?></td></tr>
      <tr><th>Motivo da anulação</th><td><?= $h($anulado['reason']) ?></td></tr>
    <?php endif; ?>
    <?php if ($selo): ?>
      <tr><th>Dia selado em</th><td><?= $h($selo['seal_date']) ?> (chave <?= $h($selo['pubkey_id']) ?>)</td></tr>
      <tr><th>Selo do dia</th>
          <td><?= $seloAudit && $seloAudit['ok']
                  ? 'assinatura Ed25519 válida e head inalterado'
                  : 'PROBLEMA: ' . $h($seloAudit['detalhe'] ?? 'não verificado') ?></td></tr>
    <?php else: ?>
      <tr><th>Selo do dia</th><td>ainda não emitido para este período</td></tr>
    <?php endif; ?>
  </table>
  <p style="font-size:.82rem;color:#555;">
    Por proteção de dados, esta página não exibe nome, CPF, horário exato,
    localização ou foto. Para o conteúdo completo do comprovante, acesse o
    portal do colaborador ou procure o setor responsável pelo ponto.
  </p>
<?php endif; ?>

<footer>
  <?php if ($pubkey !== ''): ?>
    <div><strong>Chave pública Ed25519 dos selos diários:</strong></div>
    <div class="mono"><?= $h($pubkey) ?></div>
    <div style="margin-top:6px;">
      Permite conferir de forma independente as assinaturas dos selos, sem
      depender deste sistema.
    </div>
  <?php endif; ?>
  <div style="margin-top:10px;">
    <?= $h(defined('SYSTEM_NAME') ? SYSTEM_NAME : 'Sistema de ponto') ?>
    <?= $h(defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '') ?> ·
    Registro Eletrônico de Ponto via Programa (REP-P)
  </div>
</footer>

</body>
</html>

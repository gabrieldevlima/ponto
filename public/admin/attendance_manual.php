<?php
require_once __DIR__ . '/../../config.php';
require_admin();
$pdo = db();
$adm = current_admin($pdo);

// Listas (respeitar escopo)
list($scopeSql, $scopeParams) = admin_scope_where('t');
$stT = $pdo->prepare("SELECT t.id, t.name FROM teachers t WHERE t.active = 1 AND $scopeSql ORDER BY t.name");
$stT->execute($scopeParams);
$teachers = $stT->fetchAll(PDO::FETCH_ASSOC);

$reasons  = $pdo->query("SELECT id, name FROM manual_reasons WHERE active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Helpers
function resolve_admin_id(PDO $pdo): ?int
{
  if (!empty($_SESSION['admin_id'])) return (int)$_SESSION['admin_id'];
  if (!empty($_SESSION['admin_username'])) {
    $st = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
    $st->execute([$_SESSION['admin_username']]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
  }
  return null;
}
$toMin = static function (string $hhmm): int {
  [$h, $m] = array_map('intval', explode(':', $hhmm . ':'));
  return $h * 60 + $m;
};
$isWithin = static function (string $time, string $start, string $end, bool $crossesMidnight = false) use ($toMin): bool {
  if ($start === '' || $end === '') return false;
  $t = $toMin($time);
  $a = $toMin($start);
  $b = $toMin($end);
  if ($crossesMidnight) {
    // Janela 22:00 → 04:00 (ex): aceita 22..23:59 OU 00..04
    return ($t >= $a) || ($t <= $b);
  }
  if ($a >= $b) return false;
  return ($t >= $a && $t <= $b);
};

// Mensagens
$msg = $_GET['msg'] ?? '';
$errors = [];

// Form state (sticky)
// $form['record_type'] = forma do lançamento (in/out/both)
// $form['attendance_kind'] = categoria do registro (work=trabalho, break=intervalo)
$form = [
  'teacher_id'           => '',
  'date'                 => date('Y-m-d'),
  'record_type'          => 'in',
  'attendance_kind'      => 'work',
  'time_in'              => '',
  'time_out'             => '',
  'reason_id'            => '',
  'reason_text'          => '',
  'approved'             => 1,
  'allow_outside_window' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  // Coleta e normaliza
  $form['teacher_id']      = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
  $form['date']            = $_POST['date'] ?? '';
  $form['record_type']     = $_POST['record_type'] ?? 'in';
  $form['attendance_kind'] = in_array($_POST['attendance_kind'] ?? '', ['work','break'], true) ? $_POST['attendance_kind'] : 'work';
  $form['time_in']         = $_POST['time_in'] ?? '';
  $form['time_out']        = $_POST['time_out'] ?? '';
  $form['reason_id']       = isset($_POST['reason_id']) ? (int)$_POST['reason_id'] : 0;
  $form['reason_text']     = trim($_POST['reason_text'] ?? '');
  $form['approved']        = isset($_POST['approved']) ? 1 : 0;
  $form['allow_outside_window'] = (isset($_POST['allow_outside_window']) && $_POST['allow_outside_window'] === '1') ? 1 : 0;

  $teacher_id      = $form['teacher_id'];
  $date            = $form['date'];
  $record_type     = $form['record_type'];
  $attendance_kind = $form['attendance_kind']; // 'work' | 'break'
  $time_in         = $form['time_in'];
  $time_out        = $form['time_out'];
  $reason_id       = $form['reason_id'];
  $reason_text     = $form['reason_text'];
  $approved        = $form['approved'];
  $adminId         = resolve_admin_id($pdo);

  // Escopo: só permitir lançar para colaborador visível
  list($scopeSql, $scopeParams) = admin_scope_where('t');
  $chk = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id = ? AND $scopeSql");
  $chk->execute(array_merge([$teacher_id], $scopeParams));
  if (!$chk->fetchColumn()) {
    $errors[] = 'Você não tem permissão para lançar ponto para este colaborador.';
  }

  // Validações
  if ($teacher_id <= 0) $errors[] = 'Colaborador inválido.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Data inválida.';
  if (!in_array($record_type, ['in', 'out', 'both'], true)) $errors[] = 'Tipo de registro inválido.';
  if ($record_type === 'in'   && !preg_match('/^\d{2}:\d{2}$/', $time_in))  $errors[] = 'Hora de entrada inválida.';
  if ($record_type === 'out'  && !preg_match('/^\d{2}:\d{2}$/', $time_out)) $errors[] = 'Hora de saída inválida.';
  if ($record_type === 'both' && (!preg_match('/^\d{2}:\d{2}$/', $time_in) || !preg_match('/^\d{2}:\d{2}$/', $time_out))) {
    $errors[] = 'Horas inválidas.';
  }
  if ($reason_id <= 0) $errors[] = 'Selecione um motivo.';

  // Carrega motivo e valida "Outro"
  if (!$errors) {
    $st = $pdo->prepare("SELECT id, name FROM manual_reasons WHERE id = ? AND active = 1");
    $st->execute([$reason_id]);
    $reasonRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$reasonRow) {
      $errors[] = 'Motivo inválido.';
    } elseif (mb_stripos($reasonRow['name'], 'outro') !== false && $reason_text === '') {
      $errors[] = 'Descreva a justificativa no campo Texto da justificativa.';
    }
  }

  // Validação contra jornada (mantido)
  if (!$errors) {
    $st = $pdo->prepare("SELECT t.id, ct.schedule_mode, ct.requires_schedule FROM teachers t LEFT JOIN collaborator_types ct ON ct.id = t.type_id WHERE t.id = ?");
    $st->execute([$teacher_id]);
    $col = $st->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
      $errors[] = 'Colaborador não encontrado.';
    } else {
      $requires = (int)($col['requires_schedule'] ?? 1) === 1;
      $mode = $col['schedule_mode'] ?? 'classes';

      // Escola primária do colaborador para resolver sábado letivo específico de escola.
      try {
        $stSch = $pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id = ? ORDER BY id LIMIT 1");
        $stSch->execute([$teacher_id]);
        $schIdRaw = $stSch->fetchColumn();
        $schoolIdForWeekday = ($schIdRaw !== false && $schIdRaw !== null) ? (int)$schIdRaw : null;
      } catch (Throwable $_) {
        $schoolIdForWeekday = null;
      }
      // Weekday "efetivo": em sábado letivo (calendar_exceptions.type='workday' com
      // reflects_weekday), o sistema trata o dia como o weekday referenciado para
      // localizar a jornada. Sem isso, sábado letivo cairia em weekday=6 e bloquearia.
      $weekday = get_effective_weekday($pdo, $date, $schoolIdForWeekday);

      // Detecta se a data é sábado letivo (informativo nas mensagens de erro).
      $realWeekday = (int)date('w', strtotime($date));
      $isLetivoSat = ($weekday !== $realWeekday); // get_effective_weekday substituiu sábado pelo dia referenciado
      $weekdayNames = [0=>'domingo',1=>'segunda',2=>'terça',3=>'quarta',4=>'quinta',5=>'sexta',6=>'sábado'];
      $letivoSuffix = $isLetivoSat
        ? " (sábado letivo — usando jornada de {$weekdayNames[$weekday]})"
        : '';

      // Permitir override quando admin marca explicitamente o checkbox "Fora da janela (admin)"
      $allowOutsideWindow = isset($_POST['allow_outside_window']) && $_POST['allow_outside_window'] === '1';

      if ($requires) {
        if ($mode === 'classes') {
          $st = $pdo->prepare("SELECT classes_count FROM teacher_schedules WHERE teacher_id = ? AND weekday = ?");
          $st->execute([$teacher_id, $weekday]);
          $schedule = $st->fetch(PDO::FETCH_ASSOC);
          if (!$schedule || (int)$schedule['classes_count'] <= 0) {
            $errors[] = "Não há rotina prevista (aulas) para este colaborador neste dia{$letivoSuffix}.";
          }
        } elseif ($mode === 'hours') {
          // Modo "horas/dia" (motorista, monitor): sem horário fixo, só valida se há
          // schedule definido para este weekday (total_minutes > 0).
          $st = $pdo->prepare("SELECT total_minutes FROM collaborator_hours_schedules WHERE teacher_id = ? AND weekday = ?");
          $st->execute([$teacher_id, $weekday]);
          $hs = $st->fetch(PDO::FETCH_ASSOC);
          if (!$hs || (int)$hs['total_minutes'] <= 0) {
            if (!$allowOutsideWindow) {
              $errors[] = "Não há jornada de horas/dia cadastrada para este dia{$letivoSuffix}. Marque \"Permitir fora da janela\" para forçar.";
            }
          }
          // Para 'both', ainda garante saída > entrada (sem janela horária pra validar)
          if ($record_type === 'both') {
            $ciTs = strtotime($date . ' ' . $time_in . ':00');
            $coTs = strtotime($date . ' ' . $time_out . ':00');
            if ($coTs <= $ciTs) {
              $errors[] = 'Saída deve ser após a entrada.';
            }
          }
        } elseif ($mode === 'time') {
          $st = $pdo->prepare("SELECT start_time, end_time, end_next_day FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
          $st->execute([$teacher_id, $weekday]);
          $ts = $st->fetch(PDO::FETCH_ASSOC);
          if (!$ts || empty($ts['start_time']) || empty($ts['end_time'])) {
            $errors[] = "Não há jornada cadastrada para este dia{$letivoSuffix}.";
          } else {
            $start = substr($ts['start_time'], 0, 5);
            $end   = substr($ts['end_time'], 0, 5);
            $crosses = !empty($ts['end_next_day']) || $start >= $end;
            $windowMsg = "Janela esperada: {$start}–{$end}{$letivoSuffix}.";
            if ($record_type === 'in' && !$isWithin($time_in, $start, $end, $crosses)) {
              if (!$allowOutsideWindow) {
                $errors[] = "Hora de entrada ({$time_in}) fora da janela de trabalho. {$windowMsg} Marque \"Permitir fora da janela\" para forçar.";
              }
            } elseif ($record_type === 'out' && !$isWithin($time_out, $start, $end, $crosses)) {
              if (!$allowOutsideWindow) {
                $errors[] = "Hora de saída ({$time_out}) fora da janela de trabalho. {$windowMsg} Marque \"Permitir fora da janela\" para forçar.";
              }
            } elseif ($record_type === 'both') {
              // Para turno noturno, a saída pode ser no dia seguinte (time_out < time_in).
              $ciTs = strtotime($date . ' ' . $time_in . ':00');
              $coTs = strtotime($date . ' ' . $time_out . ':00');
              if ($crosses && $coTs <= $ciTs) $coTs += 86400; // saída no dia seguinte
              if ($coTs <= $ciTs) {
                $errors[] = 'Saída deve ser após a entrada (marque turno como "termina no dia seguinte" no cadastro se aplicável).';
              }
              if (!$allowOutsideWindow) {
                if (!$isWithin($time_in, $start, $end, $crosses))
                  $errors[] = "Hora de entrada ({$time_in}) fora da janela. {$windowMsg} Marque \"Permitir fora da janela\" para forçar.";
                if (!$isWithin($time_out, $start, $end, $crosses))
                  $errors[] = "Hora de saída ({$time_out}) fora da janela. {$windowMsg} Marque \"Permitir fora da janela\" para forçar.";
              }
            }
          }
        }
      }
    }
  }

  // Regras de consistência com registros existentes
  if (!$errors) {
    try {
      if ($record_type === 'in') {
        // Verificação de "entrada aberta" considera o MESMO tipo (work/break).
        // Permite, por ex., abrir um intervalo enquanto existe um work aberto se admin desejar.
        //
        // Filtro por record_type é montado em PHP (não em SQL) — evita comparação de dois
        // literais (? = 'work') que dispara erro 1267 (Illegal mix of collations) em
        // ambientes com collation_connection ≠ collation das colunas.
        if ($attendance_kind === 'work') {
          $kindFilterSql = " AND (record_type = 'work' OR record_type IS NULL)";
        } else {
          $kindFilterSql = " AND record_type = 'break'";
        }
        // NC-52: exclui anulados/substituídos (ver attendance_vigente_sql em helpers.php)
        $st = $pdo->prepare("SELECT id FROM attendance
                             WHERE teacher_id = ? AND date = ? AND check_in IS NOT NULL AND check_out IS NULL
                               AND " . attendance_vigente_sql()
                             . $kindFilterSql . " LIMIT 1");
        $st->execute([$teacher_id, $date]);
        if ($st->fetchColumn()) {
          $kindLabel = $attendance_kind === 'break' ? 'intervalo' : 'entrada';
          $errors[] = "Já existe um(a) {$kindLabel} aberto(a) para este dia.";
        }
        $check_in = $date . ' ' . $time_in . ':00';
        if (!$errors) {
          $st = $pdo->prepare("SELECT id FROM attendance WHERE teacher_id = ? AND check_in = ? LIMIT 1");
          $st->execute([$teacher_id, $check_in]);
          if ($st->fetchColumn()) $errors[] = 'Já existe um registro com essa hora de entrada.';
        }
        if (!$errors) {
          $pdo->beginTransaction();
          $nextNsr = nsr_legacy_reserve($pdo);
          $stmt = $pdo->prepare("INSERT INTO attendance
            (teacher_id, date, check_in, method, approved, manual_reason_id, manual_reason_text, editado_por, data_edicao, nsr, record_type)
            VALUES (?, ?, ?, 'manual', ?, ?, ?, ?, NOW(), ?, ?)");
          $stmt->execute([$teacher_id, $date, $check_in, $approved, $reason_id, $reason_text, $adminId, $nextNsr, $attendance_kind]);
          $idInserted = (int)$pdo->lastInsertId();

          // Livro fiscal — ENTRADA inserida manualmente pelo admin. O motivo é
          // obrigatório neste fluxo e vai junto: uma marcação que não nasceu de
          // um registro do trabalhador precisa dizer por que existe.
          $identManIn = nsr_ledger_teacher_ident($pdo, (int)$teacher_id);
          $nsrManIn = nsr_ledger_record_mark($pdo, [
            'teacher_id' => (int)$teacher_id,
            'teacher_cpf' => $identManIn['cpf'], 'teacher_pis' => $identManIn['pis'],
            'attendance_id' => $idInserted, 'mark_role' => 'in', 'direction' => 'E',
            'record_type' => $attendance_kind, 'marked_at' => $check_in, 'work_date' => $date,
            'origin' => 'admin_manual', 'method' => 'manual',
            'admin_id' => $adminId, 'reason' => $reason_text ?: 'insercao manual',
          ]);
          if ($nsrManIn !== null) {
            $pdo->prepare("UPDATE attendance SET legacy_nsr = COALESCE(legacy_nsr, nsr), nsr = ? WHERE id = ?")
                ->execute([$nsrManIn, $idInserted]);
          }

          $pdo->commit();
          audit_log('create', 'attendance', $idInserted, ['type' => 'in', 'kind' => $attendance_kind, 'date' => $date, 'time' => $time_in, 'manual_reason_id' => $reason_id]);
          $okMsg = $attendance_kind === 'break' ? 'Início de intervalo inserido com sucesso.' : 'Entrada inserida com sucesso.';
          header('Location: attendance_manual.php?msg=' . urlencode($okMsg));
          exit;
        }
      } elseif ($record_type === 'out') {
        $pdo->beginTransaction();
        // Admin manualmente fechando uma saída: aceita janela mais ampla (72h)
        // porque o admin pode estar corrigindo um turno noturno que ficou aberto
        // por dias. Não tão amplo a ponto de pegar órfãos antigos esquecidos.
        // Filtra pelo tipo escolhido (work/break) para não fechar o registro errado.
        $openWindow = (int)(function_exists('get_setting') ? get_setting('open_checkin_window_hours_manual', '72') : 72);
        if ($openWindow <= 0) $openWindow = 72;
        // Filtro por record_type montado em PHP (evita comparação de literais ? = 'work')
        if ($attendance_kind === 'work') {
          $kindFilterSql = " AND (record_type = 'work' OR record_type IS NULL)";
        } else {
          $kindFilterSql = " AND record_type = 'break'";
        }
        // NC-52: exclui anulados/substituídos (ver attendance_vigente_sql em helpers.php)
        $st = $pdo->prepare("SELECT id, date, check_in FROM attendance
                             WHERE teacher_id = ? AND check_in IS NOT NULL AND check_out IS NULL
                               AND " . attendance_vigente_sql() . "
                               AND check_in >= (NOW() - INTERVAL ? HOUR)"
                             . $kindFilterSql .
                             " ORDER BY check_in DESC LIMIT 1 FOR UPDATE");
        $st->execute([$teacher_id, $openWindow]);
        $open = $st->fetch(PDO::FETCH_ASSOC);
        if (!$open) {
          $pdo->rollBack();
          $errors[] = 'Não há entrada aberta para fechar.';
        } else {
          // Constrói check_out completo. Se o time_out cair antes do horário do
          // check_in original e em data ANTERIOR, considera turno noturno: usa
          // $date escolhido pelo admin para a saída.
          $check_out = $date . ' ' . $time_out . ':00';
          if (strtotime($check_out) <= strtotime($open['check_in'])) {
            $pdo->rollBack();
            $errors[] = 'Saída deve ser após a entrada aberta (verifique a data escolhida).';
          } else {
            $stmt = $pdo->prepare("UPDATE attendance
              SET check_out = ?, method = 'manual', approved = ?, manual_reason_id = ?, manual_reason_text = ?, editado_por = ?, data_edicao = NOW()
              WHERE id = ?");
            $stmt->execute([$check_out, $approved, $reason_id, $reason_text, $adminId, (int)$open['id']]);

            // Livro fiscal — SAÍDA fechada manualmente pelo admin (NSR próprio).
            $identManOut = nsr_ledger_teacher_ident($pdo, (int)$teacher_id);
            $nsrManOut = nsr_ledger_record_mark($pdo, [
              'teacher_id' => (int)$teacher_id,
              'teacher_cpf' => $identManOut['cpf'], 'teacher_pis' => $identManOut['pis'],
              'attendance_id' => (int)$open['id'], 'mark_role' => 'out', 'direction' => 'S',
              'record_type' => ($open['record_type'] ?? 'work'),
              'marked_at' => $check_out, 'work_date' => (string)($open['date'] ?? $date),
              'origin' => 'admin_manual', 'method' => 'manual',
              'admin_id' => $adminId, 'reason' => $reason_text ?: 'fechamento manual',
            ]);
            if ($nsrManOut !== null) {
              $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")
                  ->execute([$nsrManOut, (int)$open['id']]);
            }

            // Recalcula o banco de horas do dia (fechamento manual pós-checkout).
            recalculate_hour_bank_for_attendance($pdo, (int)$open['id']);
            $pdo->commit();
            audit_log('update', 'attendance', (int)$open['id'], ['type' => 'out', 'date' => $date, 'time' => $time_out, 'manual_reason_id' => $reason_id]);
            header('Location: attendance_manual.php?msg=' . urlencode('Saída inserida com sucesso.'));
            exit;
          }
        }
      } else { // both
        // Para turno noturno (saída no dia seguinte), o check_out vai para $date+1.
        // Detecção: se time_out <= time_in OU schedule do dia tem end_next_day, soma 1 dia.
        $check_in  = $date . ' ' . $time_in . ':00';
        $checkOutDate = $date;
        if ($time_out <= $time_in) {
          $checkOutDate = (new DateTime($date))->modify('+1 day')->format('Y-m-d');
        }
        $check_out = $checkOutDate . ' ' . $time_out . ':00';
        if (strtotime($check_out) <= strtotime($check_in)) $errors[] = 'Saída deve ser após a entrada.';
        if (!$errors) {
          // Verifica entrada aberta do MESMO tipo: bloqueia apenas se tipo conflita.
          // Filtro montado em PHP (evita comparação ? = 'work' que causa erro 1267).
          if ($attendance_kind === 'work') {
            $kindFilterSql = " AND (record_type = 'work' OR record_type IS NULL)";
          } else {
            $kindFilterSql = " AND record_type = 'break'";
          }
          // NC-52: exclui anulados/substituídos (ver attendance_vigente_sql em helpers.php)
          $st = $pdo->prepare("SELECT id FROM attendance
                               WHERE teacher_id = ? AND date = ?
                                 AND check_in IS NOT NULL AND (check_out IS NULL OR check_out = '')
                                 AND " . attendance_vigente_sql()
                               . $kindFilterSql .
                               " LIMIT 1");
          $st->execute([$teacher_id, $date]);
          if ($st->fetchColumn()) $errors[] = 'Há um registro aberto deste tipo neste dia. Feche-o antes de inserir entrada e saída juntas.';
        }
        if (!$errors) {
          $pdo->beginTransaction();
          $nextNsr = nsr_legacy_reserve($pdo);
          $stmt = $pdo->prepare("INSERT INTO attendance
            (teacher_id, date, check_in, check_out, method, approved, manual_reason_id, manual_reason_text, editado_por, data_edicao, nsr, record_type)
            VALUES (?, ?, ?, ?, 'manual', ?, ?, ?, ?, NOW(), ?, ?)");
          $stmt->execute([$teacher_id, $date, $check_in, $check_out, $approved, $reason_id, $reason_text, $adminId, $nextNsr, $attendance_kind]);
          $insId = (int)$pdo->lastInsertId();

          // Livro fiscal — par completo inserido de uma vez gera DUAS marcações,
          // com NSRs próprios e na ordem cronológica correta (NC-09).
          $identPar = nsr_ledger_teacher_ident($pdo, (int)$teacher_id);
          $basePar = [
            'teacher_id' => (int)$teacher_id,
            'teacher_cpf' => $identPar['cpf'], 'teacher_pis' => $identPar['pis'],
            'attendance_id' => $insId, 'record_type' => $attendance_kind,
            'work_date' => $date, 'origin' => 'admin_manual', 'method' => 'manual',
            'admin_id' => $adminId, 'reason' => $reason_text ?: 'insercao manual',
          ];
          $nsrParIn  = nsr_ledger_record_mark($pdo, $basePar + [
            'mark_role' => 'in',  'direction' => 'E', 'marked_at' => $check_in,
          ]);
          $nsrParOut = nsr_ledger_record_mark($pdo, $basePar + [
            'mark_role' => 'out', 'direction' => 'S', 'marked_at' => $check_out,
          ]);
          if ($nsrParIn !== null) {
            $pdo->prepare("UPDATE attendance SET legacy_nsr = COALESCE(legacy_nsr, nsr), nsr = ? WHERE id = ?")
                ->execute([$nsrParIn, $insId]);
          }
          if ($nsrParOut !== null) {
            $pdo->prepare("UPDATE attendance SET nsr_out = ? WHERE id = ?")->execute([$nsrParOut, $insId]);
          }

          $pdo->commit();
          audit_log('create', 'attendance', $insId, ['type' => 'both', 'date' => $date, 'time_in' => $time_in, 'time_out' => $time_out, 'manual_reason_id' => $reason_id]);
          header('Location: attendance_manual.php?msg=' . urlencode('Entrada e saída inseridas com sucesso.'));
          exit;
        }
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log("Erro ao salvar ponto manual: " . $e->getMessage());
      $errors[] = 'Erro ao salvar: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <title>Inserir Ponto Manual | DEEDO Ponto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="shortcut icon" href="../img/icone-2.ico" type="image/x-icon">
  <link rel="icon" href="../img/icone-2.ico" type="image/x-icon">
</head>

<body>
  <?php include __DIR__ . '/_navbar.php'; ?>
  <div class="container-fluid mb-5">
    <div class="app-page-header">
      <div class="app-page-header__main">
        <div class="app-page-icon"><i class="bi bi-clock-history"></i></div>
        <div>
          <h1 class="app-page-title">Inserir Ponto Manual</h1>
          <p class="app-page-subtitle">Cadastre entrada, saída ou ambos com justificativa.</p>
        </div>
      </div>
      <span class="app-info-badge d-none d-md-inline-flex">
        <i class="bi bi-calendar-event"></i><?= esc(date('d/m/Y')) ?>
      </span>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2">
        <i class="bi bi-check-circle-fill"></i>
        <div class="flex-grow-1"><?= esc($msg) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
        <div class="flex-grow-1">
          <strong>Não foi possível salvar:</strong>
          <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    <?php endif; ?>

    <form action="" method="post" autocomplete="off" id="manualPointForm">
      <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">

      <!-- ========== Quem e quando ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-person"></i>Seção 1</span>
          <h2 class="app-section-card__title">Quem e quando</h2>
        </header>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-12 col-md-7">
              <label for="teacher_id" class="form-label">Colaborador <span class="text-danger">*</span></label>
              <select id="teacher_id" name="teacher_id" class="form-select" required>
                <option value="">Selecione um colaborador</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= (string)$form['teacher_id'] === (string)$t['id'] ? 'selected' : '' ?>>
                    <?= esc($t['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-5">
              <label for="dateField" class="form-label">Data <span class="text-danger">*</span></label>
              <input id="dateField" type="date" name="date" class="form-control" required value="<?= esc($form['date']) ?>" max="<?= date('Y-m-d') ?>">
              <div class="form-text">Não é permitido lançar pontos no futuro.</div>
            </div>
          </div>
        </div>
      </section>

      <!-- ========== Tipo + horários ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-clock"></i>Seção 2</span>
          <h2 class="app-section-card__title">Tipo de registro e horários</h2>
        </header>
        <div class="app-section-card__body">
          <label class="form-label d-block">Categoria</label>
          <div class="app-segmented mb-3" role="radiogroup" aria-label="Categoria do registro">
            <input type="radio" name="attendance_kind" id="ak_work" value="work" <?= ($form['attendance_kind'] ?? 'work') === 'work' ? 'checked' : '' ?>>
            <label for="ak_work"><i class="bi bi-briefcase"></i>Trabalho</label>
            <input type="radio" name="attendance_kind" id="ak_break" value="break" <?= ($form['attendance_kind'] ?? 'work') === 'break' ? 'checked' : '' ?>>
            <label for="ak_break"><i class="bi bi-pause-circle"></i>Intervalo</label>
          </div>
          <div class="form-text mb-3">Trabalho conta no tempo trabalhado; Intervalo não conta como hora trabalhada.</div>

          <label class="form-label d-block">Tipo de registro</label>
          <div class="app-segmented mb-3" role="radiogroup" aria-label="Tipo de registro">
            <input type="radio" name="record_type" id="rt_in" value="in" <?= $form['record_type'] === 'in' ? 'checked' : '' ?>>
            <label for="rt_in"><i class="bi bi-box-arrow-in-right"></i>Entrada</label>
            <input type="radio" name="record_type" id="rt_out" value="out" <?= $form['record_type'] === 'out' ? 'checked' : '' ?>>
            <label for="rt_out"><i class="bi bi-box-arrow-left"></i>Saída</label>
            <input type="radio" name="record_type" id="rt_both" value="both" <?= $form['record_type'] === 'both' ? 'checked' : '' ?>>
            <label for="rt_both"><i class="bi bi-arrow-down-up"></i>Entrada e Saída</label>
          </div>

          <div class="row g-3">
            <div class="col-6 col-md-4 time-in">
              <label for="time_in" class="form-label">Hora de Entrada</label>
              <input type="time" name="time_in" id="time_in" class="form-control" step="60" value="<?= esc($form['time_in']) ?>">
            </div>
            <div class="col-6 col-md-4 time-out">
              <label for="time_out" class="form-label">Hora de Saída</label>
              <input type="time" name="time_out" id="time_out" class="form-control" step="60" value="<?= esc($form['time_out']) ?>">
            </div>
          </div>

          <div class="form-check form-switch mt-3">
            <input class="form-check-input" type="checkbox" name="allow_outside_window" id="allow_outside_window" value="1" <?= !empty($form['allow_outside_window']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="allow_outside_window">Permitir horário fora da janela de trabalho</label>
            <div class="form-text">Útil em sábado letivo (jornada referenciada pode ter horário diferente) ou em horas extras autorizadas. O ponto fica gravado com o horário informado.</div>
          </div>
        </div>
      </section>

      <!-- ========== Justificativa ========== -->
      <section class="app-section-card">
        <header class="app-section-card__header">
          <span class="app-section-card__eyebrow" aria-hidden="true"><i class="bi bi-chat-left-text"></i>Seção 3</span>
          <h2 class="app-section-card__title">Justificativa</h2>
        </header>
        <div class="app-section-card__body">
          <div class="row g-3">
            <div class="col-12 col-md-5">
              <label for="reason_id" class="form-label">Motivo <span class="text-danger">*</span></label>
              <select name="reason_id" id="reason_id" class="form-select" required>
                <option value="">Selecione um motivo</option>
                <?php foreach ($reasons as $r): ?>
                  <option value="<?= (int)$r['id'] ?>" <?= (string)$form['reason_id'] === (string)$r['id'] ? 'selected' : '' ?>>
                    <?= esc($r['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-7" id="reason_text_block" style="display:none;">
              <label for="reason_text" class="form-label">Descrição <span class="text-danger">*</span></label>
              <input type="text" name="reason_text" id="reason_text" class="form-control" maxlength="255" placeholder="Descreva o motivo" value="<?= esc($form['reason_text']) ?>">
              <div class="form-text">Obrigatório quando o motivo selecionado é "Outro".</div>
            </div>
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="approved" id="approved" <?= $form['approved'] ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="approved">Marcar como aprovado</label>
                <div class="form-text">Pontos aprovados contam imediatamente em relatórios e folha. Sem essa marcação, fica como pendente.</div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ========== Form actions (sticky) ========== -->
      <div class="app-form-actions">
        <span class="app-form-actions__hint">
          <i class="bi bi-info-circle"></i>
          Atalho: <kbd>Ctrl</kbd>+<kbd>Enter</kbd> para salvar
        </span>
        <div class="app-form-actions__btns">
          <a href="attendances.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar
          </a>
          <button class="btn btn-outline-danger" type="reset" id="btnReset">
            <i class="bi bi-eraser me-1"></i>Limpar
          </button>
          <button class="btn btn-success" type="submit" id="btnSave">
            <i class="bi bi-check2-circle me-1"></i>
            <span>Salvar Ponto</span>
            <span class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
          </button>
        </div>
      </div>
    </form>
  </div>

  <script>
    // record_type agora é segmented (radio buttons), não <select>
    const recordRadios = document.querySelectorAll('input[name="record_type"]');
    const blockIn = document.querySelector('.time-in');
    const blockOut = document.querySelector('.time-out');
    const timeIn = document.getElementById('time_in');
    const timeOut = document.getElementById('time_out');

    function toggleTimeBlocks() {
      const checked = document.querySelector('input[name="record_type"]:checked');
      const v = checked ? checked.value : 'in';
      const showIn = (v === 'in' || v === 'both');
      const showOut = (v === 'out' || v === 'both');
      blockIn.style.display = showIn ? '' : 'none';
      blockOut.style.display = showOut ? '' : 'none';
      if (showIn) timeIn.setAttribute('required', 'required');
      else timeIn.removeAttribute('required');
      if (showOut) timeOut.setAttribute('required', 'required');
      else timeOut.removeAttribute('required');
    }
    recordRadios.forEach(r => r.addEventListener('change', toggleTimeBlocks));
    toggleTimeBlocks();

    // Botões e atalhos do form
    (function() {
      const form = document.getElementById('manualPointForm');
      const btnSave = document.getElementById('btnSave');
      const spinner = btnSave.querySelector('.spinner-border');
      const btnReset = document.getElementById('btnReset');

      form.addEventListener('submit', function() {
        btnSave.disabled = true;
        spinner.classList.remove('d-none');
      });
      btnReset.addEventListener('click', function(e) {
        if (!confirm('Limpar todos os campos do formulário?')) e.preventDefault();
      });
      form.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
          e.preventDefault();
          btnSave.click();
        }
      });
    })();

    const reasonSelect = document.getElementById('reason_id');
    const reasonTextBlock = document.getElementById('reason_text_block');
    const reasonText = document.getElementById('reason_text');

    function toggleReasonText() {
      const opt = reasonSelect.options[reasonSelect.selectedIndex]?.text || '';
      const needsText = opt.toLowerCase().includes('outro');
      reasonTextBlock.style.display = needsText ? '' : 'none';
      if (needsText) reasonText.setAttribute('required', 'required');
      else reasonText.removeAttribute('required');
    }
    reasonSelect.addEventListener('change', toggleReasonText);
    toggleReasonText();
  </script>
    <?php include __DIR__ . '/../_footer.php'; ?>
</body>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</html>
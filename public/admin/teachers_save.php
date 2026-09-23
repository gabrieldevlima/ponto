<?php
require_once __DIR__ . '/../../config.php';
require_admin();
csrf_verify($_POST['_csrf'] ?? '');
$pdo = db();
$adm = current_admin($pdo);

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$colaboradorCriadoId = 0; // preenchido só quando este save cria um cadastro
$name = trim($_POST['name'] ?? '');
$cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
$email = trim($_POST['email'] ?? '');
$type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
$base_salary = isset($_POST['base_salary']) ? (float)$_POST['base_salary'] : 0.0;
$hourly_rate = isset($_POST['hourly_rate']) && $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
$network_wide = isset($_POST['network_wide']) ? 1 : 0;
// Dados contratuais exigidos pelo AFD (registro tipo 5) e pelo AEJ, que
// identifica o trabalhador pelo PIS — não pelo CPF.
$pis            = preg_replace('/\D/', '', $_POST['pis'] ?? '');
$matricula      = trim($_POST['matricula'] ?? '') ?: null;
$cbo            = preg_replace('/\D/', '', $_POST['cbo'] ?? '') ?: null;
$admission_date = trim($_POST['admission_date'] ?? '') ?: null;
$dismissal_date = trim($_POST['dismissal_date'] ?? '') ?: null;
$schools = isset($_POST['schools']) && is_array($_POST['schools']) ? array_values(array_unique(array_map('intval', $_POST['schools']))) : [];
$face_descriptors_raw = $_POST['face_descriptors'] ?? '';
$face_descriptors_clear = isset($_POST['face_descriptors_clear']) && $_POST['face_descriptors_clear'] === '1';
$face_descriptors = null;
$face_descriptors_array = null;
$face_descriptors_provided = false;
if ($face_descriptors_clear) {
    $face_descriptors = null;
    $face_descriptors_provided = true;
} elseif ($face_descriptors_raw !== '') {
    $decoded = json_decode($face_descriptors_raw, true);
    if (is_array($decoded) && !empty($decoded)) {
        try {
            $face_descriptors_array = normalize_face_descriptors($decoded, 20);
            $face_descriptors = json_encode($face_descriptors_array, JSON_UNESCAPED_UNICODE);
            $face_descriptors_provided = true;
        } catch (Throwable $e) {
            flash_redirect('error', 'Descritores faciais inválidos.', 'teacher_edit.php' . ($id > 0 ? '?id=' . $id : ''));
        }
    }
}

// Verifica se a coluna hourly_rate existe
$hasHourlyRate = false;
try {
  $pdo->query("SELECT hourly_rate FROM teachers LIMIT 1");
  $hasHourlyRate = true;
} catch (PDOException $e) {
  // Coluna não existe
}

$schedule = $_POST['schedule'] ?? [];          // classes
$timeSchedule = $_POST['time_schedule'] ?? []; // time
$hoursSchedule = $_POST['hours_schedule'] ?? []; // hours (motorista, monitor)

// ============================================================================
// HELPER: redireciona com flash e termina o request
// ============================================================================
$flashAndRedirect = function (string $type, string $title, string $message, ?string $details = null) use ($id) {
    $_SESSION['teacher_save_flash'] = [
        'type'    => $type, // 'error' | 'warning' | 'info'
        'title'   => $title,
        'message' => $message,
        'details' => $details,
        'echo'    => $_POST,   // pra repreencher o form
    ];
    $redirect = 'teacher_edit.php' . ($id > 0 ? ('?id=' . $id) : '');
    header('Location: ' . $redirect);
    exit;
};

// ============================================================================
// VALIDAÇÕES BÁSICAS
// ============================================================================
if (!$name) {
    $flashAndRedirect('error', 'Nome obrigatório', 'O campo Nome não pode ficar em branco.');
}
if (!$cpf) {
    $flashAndRedirect('error', 'CPF obrigatório', 'O campo CPF não pode ficar em branco.');
}
if (!validate_cpf($cpf)) {
    $flashAndRedirect(
        'error',
        'CPF inválido',
        'O CPF informado não é válido. Verifique se digitou os 11 dígitos corretamente.',
        'O sistema aplica o algoritmo oficial de verificação dos 2 últimos dígitos. Sequências como 111.111.111-11 não são aceitas.'
    );
}
if ($type_id <= 0) {
    $flashAndRedirect('error', 'Tipo obrigatório', 'Selecione o Tipo de colaborador.');
}
if ($base_salary < 0) {
    $flashAndRedirect('error', 'Salário inválido', 'O Salário base não pode ser negativo.');
}
// PIS é opcional no cadastro (a base legada não tem), mas se vier tem de ser
// válido: um PIS errado só aparece na rejeição do AEJ pela fiscalização.
if ($pis !== '' && !validate_pis($pis)) {
    $flashAndRedirect(
        'error',
        'PIS inválido',
        'O PIS/PASEP/NIT informado não é válido. Verifique se digitou os 11 dígitos corretamente.',
        'O sistema aplica o algoritmo oficial do dígito verificador. O AEJ identifica o trabalhador pelo PIS, então um valor errado invalida o arquivo inteiro.'
    );
}
foreach (['admission_date' => $admission_date, 'dismissal_date' => $dismissal_date] as $rotulo => $valor) {
    if ($valor !== null && !DateTimeImmutable::createFromFormat('Y-m-d', $valor)) {
        $flashAndRedirect('error', 'Data inválida', "O campo {$rotulo} não é uma data válida.");
    }
}
if ($admission_date !== null && $dismissal_date !== null && $dismissal_date < $admission_date) {
    $flashAndRedirect('error', 'Datas incoerentes', 'O desligamento não pode ser anterior à admissão.');
}

// ============================================================================
// IDEMPOTÊNCIA — previne double-submit (clique duplo, refresh, BACK+save)
// ============================================================================
$idempotencyToken = trim((string)($_POST['_idempotency'] ?? ''));
if ($idempotencyToken !== '') {
    $_SESSION['teacher_idempotency_seen'] = $_SESSION['teacher_idempotency_seen'] ?? [];
    // limpa entradas com >5min (TTL)
    foreach ($_SESSION['teacher_idempotency_seen'] as $tk => $ts) {
        if ($ts < time() - 300) unset($_SESSION['teacher_idempotency_seen'][$tk]);
    }
    // Cap defensivo: previne memory leak em sessões long-lived (admin com várias abas
    // abertas por dias). Mantém apenas as 50 entradas mais recentes.
    if (count($_SESSION['teacher_idempotency_seen']) > 50) {
        $_SESSION['teacher_idempotency_seen'] = array_slice(
            $_SESSION['teacher_idempotency_seen'], -50, null, true
        );
    }
    if (isset($_SESSION['teacher_idempotency_seen'][$idempotencyToken])) {
        $flashAndRedirect(
            'warning',
            'Envio duplicado detectado',
            'Esse formulário já foi processado há instantes — o cadastro NÃO foi duplicado.',
            'Se você estava tentando fazer outra alteração, abra novamente a tela de cadastro.'
        );
    }
    // Marca DEPOIS dos checks (só consome o token se chegamos a salvar de fato).
    // Será setado abaixo, após os pre-flights passarem.
}

// ============================================================================
// HOTFIX 2026-09: na EDIÇÃO, o CPF podia ser trocado para o de outro colaborador
// ativo sem aviso (o pre-flight abaixo só roda em cadastro novo). Produção já tem
// dois cadastros ativos com o mesmo CPF — login/ponto por CPF passam a cair no
// cadastro errado. Bloqueia a duplicidade entre ATIVOS; não mexe nos existentes.
// ============================================================================
if ($id > 0 && $cpf !== '') {
    $stDupEdit = $pdo->prepare("SELECT id, name FROM teachers WHERE cpf = ? AND id <> ? AND active = 1 LIMIT 1");
    $stDupEdit->execute([$cpf, $id]);
    if ($dupEdit = $stDupEdit->fetch(PDO::FETCH_ASSOC)) {
        $flashAndRedirect(
            'error',
            'CPF já cadastrado',
            'Este CPF já pertence a outro colaborador ativo: ' . esc($dupEdit['name']) . ' (#' . (int)$dupEdit['id'] . ').',
            'Corrija o CPF ou inative o cadastro duplicado antes de salvar.'
        );
    }
}

// ============================================================================
// PRE-FLIGHT POR CPF
// Detecta CPF já cadastrado ANTES da INSERT pra dar mensagem amigável
// ============================================================================
if ($id <= 0) { // só valida pre-flight em CADASTRO novo
    $stExisting = $pdo->prepare("SELECT id, name, active FROM teachers WHERE cpf = ? LIMIT 1");
    $stExisting->execute([$cpf]);
    $existingByCpf = $stExisting->fetch(PDO::FETCH_ASSOC);

    if ($existingByCpf) {
        if ((int)$existingByCpf['active'] === 1) {
            $flashAndRedirect(
                'error',
                'CPF já cadastrado',
                'Já existe um colaborador ativo com esse CPF: ' . esc($existingByCpf['name']) . ' (#' . (int)$existingByCpf['id'] . ').',
                'Para alterar os dados desse colaborador, abra o cadastro existente em vez de criar um novo.'
            );
        } else {
            // Inativo — oferece reativação se o admin marcou explicitamente
            $reactivate = isset($_POST['reactivate_existing']) && $_POST['reactivate_existing'] === '1';
            if (!$reactivate) {
                $flashAndRedirect(
                    'warning',
                    'CPF pertence a um cadastro inativo',
                    'Existe um cadastro INATIVO com esse CPF: ' . esc($existingByCpf['name']) . ' (#' . (int)$existingByCpf['id'] . ').',
                    'Edite o cadastro existente e marque como ativo, ou reenvie este formulário marcando a opção "Reativar cadastro existente" para reaproveitar o registro.'
                );
            }
            // Reaproveita o ID e força UPDATE (transição para ativo + dados novos)
            $id = (int)$existingByCpf['id'];
        }
    }

    // Pre-flight POR NOME (soft duplicate — mesma pessoa com CPFs diferentes?)
    $forceCreate = isset($_POST['force_create_duplicate_name']) && $_POST['force_create_duplicate_name'] === '1';
    if (!$forceCreate && $id <= 0) {
        $normalized = normalize_name_for_dedup($name);
        if ($normalized !== '') {
            $stN = $pdo->prepare("SELECT id, name, cpf, active FROM teachers WHERE active = 1");
            $stN->execute();
            $matches = [];
            while ($row = $stN->fetch(PDO::FETCH_ASSOC)) {
                if (normalize_name_for_dedup((string)$row['name']) === $normalized) {
                    $matches[] = $row;
                }
            }
            if (!empty($matches)) {
                $namesList = array_map(fn($m) => $m['name'] . ' (#' . (int)$m['id'] . ')', $matches);
                $flashAndRedirect(
                    'warning',
                    'Possível duplicata',
                    'Já existe colaborador ativo com nome similar: ' . implode(', ', $namesList) . '.',
                    'Verifique se não é a mesma pessoa. Se for um homônimo legítimo, marque a opção "Confirmar cadastro mesmo com nome igual" e envie novamente.'
                );
            }
        }
    }
}

// Descobre o modo do tipo selecionado
$stMode = $pdo->prepare("SELECT schedule_mode FROM collaborator_types WHERE id = ?");
$stMode->execute([$type_id]);
$mode = $stMode->fetchColumn() ?: 'classes';

// ── Verificação de escopo ──────────────────────────────────────────────────
// school_admin só pode criar/editar professores dentro da sua própria escola.
if (!is_network_admin($adm)) {
    $adminSchoolId = (int)($adm['school_id'] ?? 0);

    // UPDATE: verificar se o professor existente está no escopo do admin
    if ($id > 0) {
        list($scopeSql, $scopeParams) = admin_scope_where('t');
        $chkScope = $pdo->prepare("SELECT 1 FROM teachers t WHERE t.id = ? AND $scopeSql LIMIT 1");
        $chkScope->execute(array_merge([$id], $scopeParams));
        if (!$chkScope->fetchColumn()) {
            flash_redirect('error', 'Sem permissão para editar este colaborador.', 'teachers.php');
        }
    }

    // Impedir criação de professores de rede por school_admin
    $network_wide = 0;

    // Restringir escolas à própria escola do admin
    $schools = $adminSchoolId > 0 ? [$adminSchoolId] : [];
}
// ──────────────────────────────────────────────────────────────────────────

try {
  $pdo->beginTransaction();

  if ($face_descriptors_provided && is_array($face_descriptors_array) && !empty($face_descriptors_array)) {
      $conflict = find_active_face_conflict($pdo, $face_descriptors_array, $id > 0 ? $id : 0);
      if ($conflict) {
          if ($pdo->inTransaction()) {
              $pdo->rollBack();
          }
          $_SESSION['teacher_face_notice'] = [
              'type' => 'face_duplicate_active',
              'title' => 'Biometria já cadastrada',
              'message' => 'Identificamos que os dados faciais enviados já estão vinculados a outro cadastro ativo.',
              'details' => 'Por segurança e consistência da base, o salvamento foi interrompido. Refaça a captura facial com outro colaborador ou remova o vínculo antigo antes de continuar.'
          ];
          $redirect = 'teacher_edit.php' . ($id > 0 ? ('?id=' . $id) : '');
          header('Location: ' . $redirect);
          exit;
      }
  }

  // Colunas do AFD/AEJ, comuns ao INSERT e ao UPDATE. `''` vira NULL para que
  // o pré-voo do AEJ (que testa NULL e string vazia) leia um estado só.
  $afdCols   = ['pis', 'matricula', 'cbo', 'admission_date', 'dismissal_date'];
  $afdValues = [$pis ?: null, $matricula, $cbo, $admission_date, $dismissal_date];

  // Estado anterior dos campos que o registro tipo 5 do AFD carrega. Só emite o
  // evento se algum deles realmente mudou — salvar o formulário sem alterar
  // nada não é "alteração de empregado" e não deve consumir NSR.
  $afdAntes = null;
  if ($id) {
      $stAntes = $pdo->prepare("SELECT name, cpf, pis, matricula FROM teachers WHERE id = ?");
      $stAntes->execute([$id]);
      $afdAntes = $stAntes->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  if ($id) {
      $faceExpr = $face_descriptors_provided ? '?' : 'face_descriptors';
      $afdSet   = implode(', ', array_map(fn($c) => "{$c}=?", $afdCols));
      if ($hasHourlyRate) {
          $sql = "UPDATE teachers SET name=?, cpf=?, email=?, type_id=?, base_salary=?, hourly_rate=?, network_wide=?, {$afdSet}, face_descriptors={$faceExpr} WHERE id=?";
          $params = array_merge([$name, $cpf, $email, $type_id, $base_salary, $hourly_rate, $network_wide], $afdValues);
          if ($face_descriptors_provided) $params[] = $face_descriptors;
          $params[] = $id;
      } else {
          $sql = "UPDATE teachers SET name=?, cpf=?, email=?, type_id=?, base_salary=?, network_wide=?, {$afdSet}, face_descriptors={$faceExpr} WHERE id=?";
          $params = array_merge([$name, $cpf, $email, $type_id, $base_salary, $network_wide], $afdValues);
          if ($face_descriptors_provided) $params[] = $face_descriptors;
          $params[] = $id;
      }
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      // Se veio de "reativar inativo" via pre-flight, marca active=1 explicitamente
      if (!empty($_POST['reactivate_existing']) && $_POST['reactivate_existing'] === '1') {
          $pdo->prepare("UPDATE teachers SET active = 1 WHERE id = ?")->execute([$id]);
      }
      audit_log('update','teacher',$id,['name'=>$name,'cpf'=>$cpf,'pis'=>$pis ?: null,'matricula'=>$matricula,'cbo'=>$cbo,'admission_date'=>$admission_date,'dismissal_date'=>$dismissal_date,'type_id'=>$type_id,'base_salary'=>$base_salary,'network_wide'=>$network_wide]);
      $afdMudou = $afdAntes === null
          || (string)$afdAntes['name']      !== $name
          || (string)$afdAntes['cpf']       !== $cpf
          || (string)($afdAntes['pis'] ?? '')       !== (string)($pis ?: '')
          || (string)($afdAntes['matricula'] ?? '') !== (string)($matricula ?? '');
      $afdOperacao = $afdMudou ? 'A' : null;
  } else {
      $afdInsCols = implode(', ', $afdCols);
      $afdInsPh   = implode(', ', array_fill(0, count($afdCols), '?'));
      if ($hasHourlyRate) {
          $stmt = $pdo->prepare("INSERT INTO teachers (name, cpf, email, active, type_id, base_salary, hourly_rate, network_wide, {$afdInsCols}, face_descriptors) VALUES (?, ?, ?, 1, ?, ?, ?, ?, {$afdInsPh}, ?)");
          $stmt->execute(array_merge([$name, $cpf, $email, $type_id, $base_salary, $hourly_rate, $network_wide], $afdValues, [$face_descriptors]));
      } else {
          $stmt = $pdo->prepare("INSERT INTO teachers (name, cpf, email, active, type_id, base_salary, network_wide, {$afdInsCols}, face_descriptors) VALUES (?, ?, ?, 1, ?, ?, ?, {$afdInsPh}, ?)");
          $stmt->execute(array_merge([$name, $cpf, $email, $type_id, $base_salary, $network_wide], $afdValues, [$face_descriptors]));
      }
      $id = (int)$pdo->lastInsertId();
      audit_log('create','teacher',$id,['name'=>$name,'pis'=>$pis ?: null,'matricula'=>$matricula,'type_id'=>$type_id,'base_salary'=>$base_salary,'network_wide'=>$network_wide]);
      $afdOperacao = 'I';
      $colaboradorCriadoId = $id;
  }

  // Livro fiscal — registro tipo 5 do AFD (inclusão/alteração de empregado).
  // Antes deste ponto o evento só era emitido na ativação/inativação
  // (helpers.php: admin_set_collaborator_active), então uma correção de PIS ou
  // de nome — exatamente o que o tipo 5 carrega — não deixava rastro no AFD.
  if ($afdOperacao !== null && function_exists('nsr_ledger_record_cadastro')) {
      nsr_ledger_record_cadastro($pdo, 'employee_change', [
          'teacher_id'  => $id,
          'teacher_cpf' => $cpf,
          'teacher_pis' => $pis ?: null,
          'origin'      => 'admin_edit',
          'admin_id'    => (int)($_SESSION['admin_id'] ?? 0) ?: null,
          'reason'      => $afdOperacao . '|' . $name,
      ]);
  }

  // Vínculos com escolas (somente se NÃO for rede completa)
  $pdo->prepare("DELETE FROM teacher_schools WHERE teacher_id = ?")->execute([$id]);
  if ($network_wide !== 1 && !empty($schools)) {
      $ins = $pdo->prepare("INSERT INTO teacher_schools (teacher_id, school_id) VALUES (?, ?)");
      foreach ($schools as $sid) {
        $ins->execute([$id, (int)$sid]);
      }
  }

  if ($mode === 'classes') {
      // Salvar rotina semanal por aulas
      if (is_array($schedule)) {
          foreach ($schedule as $weekday => $data) {
              $classes_count = max(0, (int)($data['classes_count'] ?? 0));
              $class_minutes = max(0, (int)($data['class_minutes'] ?? 0));
              $stmt = $pdo->prepare("SELECT id FROM teacher_schedules WHERE teacher_id = ? AND weekday = ?");
              $stmt->execute([$id, $weekday]);
              $exists = $stmt->fetchColumn();
              if ($exists) {
                  $stmt = $pdo->prepare("UPDATE teacher_schedules SET classes_count = ?, class_minutes = ? WHERE teacher_id = ? AND weekday = ?");
                  $stmt->execute([$classes_count, $class_minutes, $id, $weekday]);
              } else {
                  $stmt = $pdo->prepare("INSERT INTO teacher_schedules (teacher_id, weekday, classes_count, class_minutes) VALUES (?, ?, ?, ?)");
                  $stmt->execute([$id, $weekday, $classes_count, $class_minutes]);
              }
          }
      }
      // limpar rotinas dos outros modos
      $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM collaborator_hours_schedules WHERE teacher_id = ?")->execute([$id]);
  } elseif ($mode === 'time') {
      // Salvar rotina semanal por horário
      if (is_array($timeSchedule)) {
          foreach ($timeSchedule as $weekday => $ts) {
              $start = trim($ts['start'] ?? '');
              $end = trim($ts['end'] ?? '');
              $break = max(0, (int)($ts['break'] ?? 0));
              $endNextDay = !empty($ts['end_next_day']) ? 1 : 0;
              if ($start === '' && $end === '') {
                  $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?")->execute([$id, $weekday]);
                  continue;
              }
              if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
                  throw new RuntimeException('Horários inválidos em ' . $weekday . '. Use HH:MM.');
              }
              // Turno que cruza a meia-noite (end_next_day=1) OU mesmo dia (end > start)
              if ($endNextDay === 0 && $start >= $end) {
                  throw new RuntimeException('Em ' . $weekday . ': saída deve ser depois da entrada, ou marque "Termina no dia seguinte".');
              }
              $stmt = $pdo->prepare("SELECT id FROM collaborator_time_schedules WHERE teacher_id = ? AND weekday = ?");
              $stmt->execute([$id, $weekday]);
              $exists = $stmt->fetchColumn();
              if ($exists) {
                  $stmt = $pdo->prepare("UPDATE collaborator_time_schedules SET start_time = ?, end_time = ?, end_next_day = ?, break_minutes = ? WHERE teacher_id = ? AND weekday = ?");
                  $stmt->execute([$start, $end, $endNextDay, $break, $id, $weekday]);
              } else {
                  $stmt = $pdo->prepare("INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, end_next_day, break_minutes) VALUES (?, ?, ?, ?, ?, ?)");
                  $stmt->execute([$id, $weekday, $start, $end, $endNextDay, $break]);
              }
          }
      }
      // limpar rotinas dos outros modos
      $pdo->prepare("DELETE FROM teacher_schedules WHERE teacher_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM collaborator_hours_schedules WHERE teacher_id = ?")->execute([$id]);
  } elseif ($mode === 'hours') {
      // Salvar rotina semanal por horas/dia (motorista, monitor — sem horário fixo)
      if (is_array($hoursSchedule)) {
          foreach ($hoursSchedule as $weekday => $hs) {
              $totalMin = max(0, (int)($hs['total_minutes'] ?? 0));
              $breakMin = max(0, (int)($hs['break_minutes'] ?? 0));
              if ($totalMin === 0 && $breakMin === 0) {
                  $pdo->prepare("DELETE FROM collaborator_hours_schedules WHERE teacher_id = ? AND weekday = ?")->execute([$id, (int)$weekday]);
                  continue;
              }
              $stmt = $pdo->prepare("SELECT id FROM collaborator_hours_schedules WHERE teacher_id = ? AND weekday = ?");
              $stmt->execute([$id, (int)$weekday]);
              $exists = $stmt->fetchColumn();
              if ($exists) {
                  $stmt = $pdo->prepare("UPDATE collaborator_hours_schedules SET total_minutes = ?, break_minutes = ? WHERE teacher_id = ? AND weekday = ?");
                  $stmt->execute([$totalMin, $breakMin, $id, (int)$weekday]);
              } else {
                  $stmt = $pdo->prepare("INSERT INTO collaborator_hours_schedules (teacher_id, weekday, total_minutes, break_minutes) VALUES (?, ?, ?, ?)");
                  $stmt->execute([$id, (int)$weekday, $totalMin, $breakMin]);
              }
          }
      }
      // limpar rotinas dos outros modos
      $pdo->prepare("DELETE FROM teacher_schedules WHERE teacher_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ?")->execute([$id]);
  } else {
      // mode 'none': limpar todas as rotinas
      $pdo->prepare("DELETE FROM teacher_schedules WHERE teacher_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM collaborator_time_schedules WHERE teacher_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM collaborator_hours_schedules WHERE teacher_id = ?")->execute([$id]);
  }

  // Salvar atribuições de períodos de aula (grade horária)
  $periodAssignments = $_POST['period_assignments'] ?? [];
  
  // Primeiro, remove todas as atribuições existentes
  $pdo->prepare("DELETE FROM teacher_class_assignments WHERE teacher_id = ?")->execute([$id]);
  
  // Depois, insere as novas atribuições
  if (is_array($periodAssignments) && !empty($periodAssignments)) {
      $ins = $pdo->prepare("INSERT INTO teacher_class_assignments (teacher_id, weekday, period_id) VALUES (?, ?, ?)");
      foreach ($periodAssignments as $weekday => $periods) {
          if (is_array($periods)) {
              foreach ($periods as $periodId) {
                  $ins->execute([$id, (int)$weekday, (int)$periodId]);
              }
          }
      }
      audit_log('update', 'teacher_class_assignments', $id, ['count' => count($periodAssignments, COUNT_RECURSIVE) - count($periodAssignments)]);
  }

  $pdo->commit();

  // Marca o token como consumido (idempotência) — só após commit bem-sucedido.
  if ($idempotencyToken !== '') {
      $_SESSION['teacher_idempotency_seen'] = $_SESSION['teacher_idempotency_seen'] ?? [];
      $_SESSION['teacher_idempotency_seen'][$idempotencyToken] = time();
  }

  // Cadastro novo nasce sem PIN e sem face, e por isso api/pin_enroll.php vai
  // barrar o primeiro acesso dele até alguém liberar. Leva o id adiante para a
  // lista avisar quem acabou de cadastrar, em vez de o colaborador descobrir
  // sozinho na hora de bater ponto.
  $destino = 'teachers.php?msg=' . urlencode('Colaborador salvo com sucesso.') . '&msg_type=success';
  if (!empty($colaboradorCriadoId) && teacher_first_access_pending($pdo, (int)$colaboradorCriadoId)) {
      $destino .= '&pin_pending=' . (int)$colaboradorCriadoId;
  }
  header('Location: ' . $destino);
  exit;
} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  // Trata erro de UNIQUE (CPF) com mensagem amigável
  $sqlState = $e->errorInfo[0] ?? '';
  $code = (int)($e->errorInfo[1] ?? 0);
  if ($sqlState === '23000' && $code === 1062) {
      $flashAndRedirect(
          'error',
          'Cadastro duplicado',
          'O CPF informado já existe em outro registro do sistema.',
          'Detalhe técnico: ' . $e->getMessage()
      );
  }
  $flashAndRedirect(
      'error',
      'Falha ao salvar',
      'Ocorreu um erro de banco de dados ao salvar o colaborador.',
      esc($e->getMessage())
  );
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $flashAndRedirect(
      'error',
      'Falha ao salvar',
      'Ocorreu um erro inesperado ao salvar o colaborador.',
      esc($e->getMessage())
  );
}

# Exemplos de API - Sistema de Horas Extras

## 📡 Endpoints e Exemplos

Este documento contém exemplos práticos de uso da API de horas extras.

---

## 1. Check-out com Detecção de Hora Extra

### Endpoint
```
POST /api/checkin.php
```

### Request Headers
```
Content-Type: application/json
X-CSRF-Token: abc123def456...
```

### Request Body (Saída com Hora Extra)

```json
{
  "cpf": "12345678900",
  "photo": "data:image/jpeg;base64,/9j/4AAQSkZJRg...",
  "geo": {
    "lat": -23.5505,
    "lng": -46.6333,
    "acc": 15.5
  }
}
```

### Response (Sucesso com Hora Extra Detectada)

```json
{
  "status": "ok",
  "collaborator": {
    "id": 42,
    "name": "João Silva"
  },
  "teacher": {
    "id": 42,
    "name": "João Silva"
  },
  "action": "saída",
  "time": "2025-10-10 18:30:00",
  "photo": "/public/photos/foto_42_20251010_183000_a1b2c3.jpg",
  "message": "Saída registrada com foto e localização!",
  "overtime": "Detectado 1h30m de hora extra (aguarda aprovação)"
}
```

### Response (Saída sem Hora Extra)

```json
{
  "status": "ok",
  "collaborator": {
    "id": 42,
    "name": "João Silva"
  },
  "action": "saída",
  "time": "2025-10-10 17:00:00",
  "message": "Saída registrada com foto e localização!",
  "overtime": null
}
```

### Códigos de Erro

| Código HTTP | Code | Descrição |
|-------------|------|-----------|
| 400 | `cpf_required` | CPF não foi informado |
| 400 | `no_open_checkin` | Não há entrada aberta |
| 401 | `cpf_invalid` | CPF não encontrado ou colaborador inativo |
| 401 | `collaborator_inactive` | Colaborador inativo |
| 500 | `server_error` | Erro no servidor |

---

## 2. Funções Helper em PHP

### 2.1 Detectar e Criar Hora Extra

```php
<?php
require_once __DIR__ . '/config.php';

$pdo = db();

// Dados do ponto que acabou de ser fechado
$attendanceId = 123;
$teacherId = 42;
$schoolId = 5;
$date = '2025-10-10';

// Detecta e cria solicitação de hora extra
$result = detect_and_create_overtime(
    $pdo, 
    $attendanceId, 
    $teacherId, 
    $schoolId, 
    $date
);

if ($result['created']) {
    echo "✅ Hora extra detectada!\n";
    echo "Minutos extras: " . $result['overtime_minutes'] . "\n";
    echo "Mensagem: " . $result['message'] . "\n";
} else {
    echo "ℹ️ Nenhuma hora extra detectada\n";
    echo "Motivo: " . $result['message'] . "\n";
}

/*
Saída de exemplo:
✅ Hora extra detectada!
Minutos extras: 90
Mensagem: Hora extra de 90 minutos detectada e aguarda aprovação
*/
?>
```

### 2.2 Aprovar Hora Extra

```php
<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$overtimeId = 15;  // ID da solicitação
$adminId = 1;      // ID do admin aprovando

$result = approve_overtime_request($pdo, $overtimeId, $adminId);

if ($result['success']) {
    echo "✅ " . $result['message'] . "\n";
} else {
    echo "❌ Erro: " . $result['message'] . "\n";
}

/*
Saída de exemplo (sucesso):
✅ Hora extra de 90 minutos aprovada e adicionada ao banco de horas

Saída de exemplo (erro):
❌ Erro: Não é possível aprovar hora extra de um ponto pendente ou rejeitado
*/
?>
```

### 2.3 Rejeitar Hora Extra

```php
<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$overtimeId = 16;
$adminId = 1;
$reason = "Colaborador não estava autorizado a fazer hora extra neste dia";

$result = reject_overtime_request($pdo, $overtimeId, $adminId, $reason);

if ($result['success']) {
    echo "✅ " . $result['message'] . "\n";
} else {
    echo "❌ Erro: " . $result['message'] . "\n";
}

/*
Saída de exemplo:
✅ Hora extra rejeitada
*/
?>
```

### 2.4 Auto-Rejeitar ao Rejeitar Ponto

```php
<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$attendanceId = 125;
$adminId = 1;

// Primeiro rejeita o ponto
$pdo->prepare("UPDATE attendance SET approved = 0 WHERE id = ?")
    ->execute([$attendanceId]);

// Depois auto-rejeita horas extras pendentes
$rejectedCount = auto_reject_overtime_on_attendance_rejection(
    $pdo, 
    $attendanceId, 
    $adminId
);

echo "Auto-rejeitadas: $rejectedCount solicitações de hora extra\n";

/*
Saída de exemplo:
Auto-rejeitadas: 1 solicitações de hora extra
*/
?>
```

### 2.5 Calcular Minutos Esperados

```php
<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$teacherId = 42;
$date = '2025-10-10';  // Quinta-feira

$expectedMinutes = calculate_expected_minutes($pdo, $teacherId, $date);

echo "Minutos esperados: $expectedMinutes\n";
echo "Horas esperadas: " . floor($expectedMinutes / 60) . "h " . ($expectedMinutes % 60) . "m\n";

/*
Saída de exemplo (modo 'time' com 8h - 1h almoço):
Minutos esperados: 420
Horas esperadas: 7h 0m

Saída de exemplo (modo 'classes' com 6 aulas de 60min):
Minutos esperados: 360
Horas esperadas: 6h 0m

Saída de exemplo (afastamento remunerado):
Minutos esperados: 0
Horas esperadas: 0h 0m
*/
?>
```

### 2.6 Calcular Minutos Trabalhados

```php
<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$teacherId = 42;
$date = '2025-10-10';

$workedMinutes = calculate_worked_minutes($pdo, $teacherId, $date);

echo "Minutos trabalhados: $workedMinutes\n";
echo "Horas trabalhadas: " . floor($workedMinutes / 60) . "h " . ($workedMinutes % 60) . "m\n";

/*
Saída de exemplo:
Minutos trabalhados: 510
Horas trabalhadas: 8h 30m
*/
?>
```

---

## 3. Consultas SQL Úteis

### 3.1 Listar Horas Extras Pendentes

```sql
SELECT 
    ot.id,
    ot.date,
    t.name AS colaborador,
    s.name AS escola,
    ot.minutes,
    ot.expected_minutes,
    ot.worked_minutes,
    ot.created_at
FROM overtime_requests ot
JOIN teachers t ON t.id = ot.teacher_id
LEFT JOIN schools s ON s.id = ot.school_id
WHERE ot.status = 'pending'
ORDER BY ot.created_at DESC;
```

### 3.2 Estatísticas do Mês

```sql
SELECT 
    t.name AS colaborador,
    COUNT(*) AS total_solicitacoes,
    SUM(CASE WHEN ot.status = 'pending' THEN 1 ELSE 0 END) AS pendentes,
    SUM(CASE WHEN ot.status = 'approved' THEN 1 ELSE 0 END) AS aprovadas,
    SUM(CASE WHEN ot.status = 'rejected' THEN 1 ELSE 0 END) AS rejeitadas,
    SUM(CASE WHEN ot.status = 'approved' THEN ot.minutes ELSE 0 END) AS minutos_aprovados
FROM overtime_requests ot
JOIN teachers t ON t.id = ot.teacher_id
WHERE DATE_FORMAT(ot.date, '%Y-%m') = '2025-10'
GROUP BY t.id, t.name
ORDER BY minutos_aprovados DESC;
```

### 3.3 Histórico de um Colaborador

```sql
SELECT 
    ot.date,
    ot.minutes,
    ot.status,
    ot.rejection_reason,
    a.username AS processado_por,
    ot.approved_at
FROM overtime_requests ot
LEFT JOIN admins a ON a.id = ot.approved_by_admin_id
WHERE ot.teacher_id = 42
ORDER BY ot.date DESC
LIMIT 20;
```

### 3.4 Custo Estimado de Horas Extras

```sql
SELECT 
    t.name AS colaborador,
    t.base_salary,
    SUM(ot.minutes) AS minutos_aprovados,
    ROUND(SUM(ot.minutes) / 60, 2) AS horas_aprovadas,
    ROUND((t.base_salary / 220 / 60) * SUM(ot.minutes) * 1.5, 2) AS custo_estimado
FROM overtime_requests ot
JOIN teachers t ON t.id = ot.teacher_id
WHERE ot.status = 'approved'
  AND DATE_FORMAT(ot.date, '%Y-%m') = '2025-10'
GROUP BY t.id, t.name, t.base_salary
ORDER BY custo_estimado DESC;
```

**Observação:** O cálculo considera:
- Salário base / 220 horas mensais / 60 minutos = valor por minuto
- Multiplicado por 1.5 (adicional de 50% de hora extra)

---

## 4. Exemplos de Integração

### 4.1 Script de Aprovação em Lote

```php
<?php
/**
 * Aprova automaticamente horas extras de até 30 minutos
 * (para casos específicos de política da empresa)
 */
require_once __DIR__ . '/config.php';

$pdo = db();
$adminId = 1;  // Admin do sistema automático

// Busca solicitações pendentes de até 30 minutos
$stmt = $pdo->prepare("
    SELECT ot.id, ot.minutes, t.name
    FROM overtime_requests ot
    JOIN attendance a ON a.id = ot.attendance_id
    JOIN teachers t ON t.id = ot.teacher_id
    WHERE ot.status = 'pending'
      AND a.approved = 1
      AND ot.minutes <= 30
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$approved = 0;
$errors = 0;

foreach ($requests as $req) {
    $result = approve_overtime_request($pdo, $req['id'], $adminId);
    
    if ($result['success']) {
        echo "✅ Aprovado: {$req['name']} - {$req['minutes']} minutos\n";
        $approved++;
    } else {
        echo "❌ Erro: {$req['name']} - {$result['message']}\n";
        $errors++;
    }
}

echo "\n=== Resumo ===\n";
echo "Aprovadas: $approved\n";
echo "Erros: $errors\n";
?>
```

### 4.2 Relatório Diário por Email

```php
<?php
/**
 * Envia email diário com horas extras pendentes
 */
require_once __DIR__ . '/config.php';

$pdo = db();

// Busca pendentes de hoje
$stmt = $pdo->query("
    SELECT 
        t.name AS colaborador,
        ot.minutes,
        ot.created_at,
        s.name AS escola
    FROM overtime_requests ot
    JOIN teachers t ON t.id = ot.teacher_id
    LEFT JOIN schools s ON s.id = ot.school_id
    WHERE ot.status = 'pending'
      AND DATE(ot.created_at) = CURDATE()
    ORDER BY ot.created_at DESC
");

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($requests)) {
    echo "Nenhuma hora extra pendente hoje.\n";
    exit;
}

// Monta email
$subject = "Horas Extras Pendentes - " . date('d/m/Y');
$body = "<h2>Horas Extras Detectadas Hoje</h2>";
$body .= "<table border='1' cellpadding='5'>";
$body .= "<tr><th>Colaborador</th><th>Escola</th><th>Minutos</th><th>Horário</th></tr>";

foreach ($requests as $req) {
    $body .= "<tr>";
    $body .= "<td>{$req['colaborador']}</td>";
    $body .= "<td>" . ($req['escola'] ?? '-') . "</td>";
    $body .= "<td>{$req['minutes']}</td>";
    $body .= "<td>" . date('H:i', strtotime($req['created_at'])) . "</td>";
    $body .= "</tr>";
}

$body .= "</table>";
$body .= "<p><a href='https://seu-dominio.com/admin/overtime.php'>Acessar Painel</a></p>";

// Envia email (configure seu SMTP)
mail(
    'admin@empresa.com',
    $subject,
    $body,
    "Content-Type: text/html; charset=UTF-8\r\n"
);

echo "Email enviado com " . count($requests) . " solicitações pendentes.\n";
?>
```

### 4.3 Webhook de Notificação

```php
<?php
/**
 * Exemplo de webhook para notificar sistema externo
 * quando hora extra é aprovada
 */

// Em helpers.php, na função approve_overtime_request(), após commit:

function notify_overtime_approved($overtimeData) {
    $webhookUrl = 'https://sistema-externo.com/webhook/overtime';
    
    $payload = [
        'event' => 'overtime_approved',
        'overtime_id' => $overtimeData['id'],
        'teacher_id' => $overtimeData['teacher_id'],
        'teacher_name' => $overtimeData['teacher_name'],
        'date' => $overtimeData['date'],
        'minutes' => $overtimeData['minutes'],
        'approved_at' => date('Y-m-d H:i:s'),
        'approved_by' => $overtimeData['admin_username']
    ];
    
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Webhook falhou: HTTP $httpCode - $response");
    }
}
?>
```

---

## 5. Testes Automatizados

### 5.1 PHPUnit Test Example

```php
<?php
use PHPUnit\Framework\TestCase;

class OvertimeSystemTest extends TestCase
{
    private $pdo;
    private $teacherId;
    
    protected function setUp(): void
    {
        $this->pdo = db();
        
        // Cria colaborador de teste
        $stmt = $this->pdo->prepare("INSERT INTO teachers (name, cpf, type_id, active) VALUES (?, ?, 1, 1)");
        $stmt->execute(['Test User', '12345678900']);
        $this->teacherId = $this->pdo->lastInsertId();
    }
    
    public function testDetectOvertimeWhenWorkedMoreThanExpected()
    {
        // Cria ponto com hora extra
        $stmt = $this->pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, approved) VALUES (?, CURDATE(), ?, ?, 1)");
        $stmt->execute([
            $this->teacherId,
            date('Y-m-d') . ' 08:00:00',
            date('Y-m-d') . ' 19:00:00'  // 11 horas trabalhadas
        ]);
        $attendanceId = $this->pdo->lastInsertId();
        
        // Detecta hora extra
        $result = detect_and_create_overtime($this->pdo, $attendanceId, $this->teacherId, null, date('Y-m-d'));
        
        $this->assertTrue($result['created']);
        $this->assertGreaterThan(0, $result['overtime_minutes']);
    }
    
    public function testCannotApproveOvertimeWithPendingAttendance()
    {
        // Cria hora extra com ponto pendente
        $stmt = $this->pdo->prepare("INSERT INTO attendance (teacher_id, date, check_in, check_out, approved) VALUES (?, CURDATE(), ?, ?, NULL)");
        $stmt->execute([$this->teacherId, date('Y-m-d') . ' 08:00:00', date('Y-m-d') . ' 19:00:00']);
        $attendanceId = $this->pdo->lastInsertId();
        
        $stmt = $this->pdo->prepare("INSERT INTO overtime_requests (attendance_id, teacher_id, date, minutes, expected_minutes, worked_minutes) VALUES (?, ?, CURDATE(), 120, 480, 600)");
        $stmt->execute([$attendanceId, $this->teacherId]);
        $overtimeId = $this->pdo->lastInsertId();
        
        // Tenta aprovar
        $result = approve_overtime_request($this->pdo, $overtimeId, 1);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('pendente ou rejeitado', $result['message']);
    }
    
    protected function tearDown(): void
    {
        // Limpa dados de teste
        $this->pdo->prepare("DELETE FROM overtime_requests WHERE teacher_id = ?")->execute([$this->teacherId]);
        $this->pdo->prepare("DELETE FROM attendance WHERE teacher_id = ?")->execute([$this->teacherId]);
        $this->pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$this->teacherId]);
    }
}
?>
```

---

## 📞 Suporte

Para dúvidas sobre a API:
- 📧 Email: dev@deedo.com.br
- 📚 Documentação: [OVERTIME_SYSTEM.md](OVERTIME_SYSTEM.md)


# Guia de Instalação - Sistema de Horas Extras

## 📦 Pré-requisitos

- PHP 7.4 ou superior
- MySQL 5.7+ ou MariaDB 10.3+
- Sistema DEEDO Ponto já instalado e funcionando
- Acesso administrativo ao banco de dados
- Acesso SSH ou FTP ao servidor

---

## 🚀 Instalação Rápida (5 minutos)

### Passo 1: Backup do Banco de Dados

**IMPORTANTE:** Sempre faça backup antes de aplicar alterações!

```bash
mysqldump -u usuario -p nome_do_banco > backup_antes_overtime_$(date +%Y%m%d_%H%M%S).sql
```

### Passo 2: Aplicar SQL Updates

Execute o script `install.sql` atualizado:

```bash
mysql -u usuario -p nome_do_banco < install.sql
```

**OU** aplique manualmente os updates:

```sql
-- 1. Atualiza enum de hour_bank_entries
ALTER TABLE hour_bank_entries 
MODIFY source ENUM('auto','manual','overtime_approved') NOT NULL DEFAULT 'manual';

-- 2. Cria tabela overtime_requests
CREATE TABLE IF NOT EXISTS overtime_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  teacher_id INT NOT NULL,
  school_id INT NULL,
  date DATE NOT NULL,
  minutes INT NOT NULL,
  expected_minutes INT NOT NULL DEFAULT 0,
  worked_minutes INT NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL,
  approved_at DATETIME NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ot_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
  CONSTRAINT fk_ot_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id),
  CONSTRAINT fk_ot_school FOREIGN KEY (school_id) REFERENCES schools(id),
  CONSTRAINT fk_ot_admin FOREIGN KEY (approved_by_admin_id) REFERENCES admins(id),
  UNIQUE KEY uq_attendance_overtime (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Cria índices
CREATE INDEX idx_overtime_teacher_date ON overtime_requests(teacher_id, date);
CREATE INDEX idx_overtime_status ON overtime_requests(status);
CREATE INDEX idx_overtime_attendance ON overtime_requests(attendance_id);
CREATE INDEX idx_overtime_date ON overtime_requests(date);
```

### Passo 3: Verificar Criação das Tabelas

```sql
-- Verificar se a tabela foi criada
SHOW TABLES LIKE 'overtime%';

-- Ver estrutura
DESCRIBE overtime_requests;

-- Testar enum atualizado
SHOW COLUMNS FROM hour_bank_entries WHERE Field = 'source';
```

**Saída esperada:**
```
+--------+----------------------------------------------+------+-----+---------+-------+
| Field  | Type                                         | Null | Key | Default | Extra |
+--------+----------------------------------------------+------+-----+---------+-------+
| source | enum('auto','manual','overtime_approved')    | NO   |     | manual  |       |
+--------+----------------------------------------------+------+-----+---------+-------+
```

### Passo 4: Verificar Arquivos

Confirme que todos os arquivos foram atualizados:

```bash
# Arquivos principais que devem existir
ls -la helpers.php
ls -la api/checkin.php
ls -la public/admin/overtime.php
ls -la public/admin/attendances_action.php
```

### Passo 5: Limpar Cache (se aplicável)

Se você usa cache de opcode (OPcache):

```bash
# Método 1: Reiniciar PHP-FPM
sudo systemctl restart php-fpm

# Método 2: Reiniciar Apache
sudo systemctl restart apache2

# Método 3: Via código PHP (criar arquivo clear_cache.php)
<?php
opcache_reset();
echo "Cache limpo!";
?>
```

### Passo 6: Teste Básico

Acesse a interface administrativa:

```
https://seu-dominio.com/admin/overtime.php
```

Você deve ver:
- Página de Horas Extras carregando
- Estatísticas (Total, Pendentes, Aprovadas, Rejeitadas)
- Filtros funcionando
- Mensagem "Nenhuma solicitação encontrada" (se não houver dados)

---

## 🧪 Teste Completo do Sistema

### Teste 1: Detecção Automática

#### 1.1 Configure um Colaborador

```sql
-- Verificar configuração de jornada
SELECT t.id, t.name, ct.schedule_mode
FROM teachers t
LEFT JOIN collaborator_types ct ON ct.id = t.type_id
WHERE t.id = 1;
```

#### 1.2 Configure Jornada (modo 'time')

```sql
-- Exemplo: Segunda a Sexta, 8h às 17h, 1h de almoço
INSERT INTO collaborator_time_schedules (teacher_id, weekday, start_time, end_time, break_minutes)
VALUES
  (1, 1, '08:00:00', '17:00:00', 60),  -- Segunda
  (1, 2, '08:00:00', '17:00:00', 60),  -- Terça
  (1, 3, '08:00:00', '17:00:00', 60),  -- Quarta
  (1, 4, '08:00:00', '17:00:00', 60),  -- Quinta
  (1, 5, '08:00:00', '17:00:00', 60);  -- Sexta
```

#### 1.3 Simule Registro de Ponto com Hora Extra

```sql
-- Inserir entrada
INSERT INTO attendance (teacher_id, school_id, date, check_in, method, approved)
VALUES (1, 1, CURDATE(), CONCAT(CURDATE(), ' 08:00:00'), 'pin', 1);

-- Pegar ID do ponto
SET @att_id = LAST_INSERT_ID();

-- Simular saída com 2 horas extras (17h esperado, saiu 19h)
UPDATE attendance 
SET check_out = CONCAT(CURDATE(), ' 19:00:00')
WHERE id = @att_id;
```

#### 1.4 Executar Detecção Manual (simular API)

```php
<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$attendanceId = 123; // ID do ponto criado
$teacherId = 1;
$schoolId = 1;
$date = date('Y-m-d');

$result = detect_and_create_overtime($pdo, $attendanceId, $teacherId, $schoolId, $date);

print_r($result);
/*
Saída esperada:
Array (
    [created] => 1
    [overtime_minutes] => 120
    [message] => Hora extra de 120 minutos detectada e aguarda aprovação
)
*/
?>
```

#### 1.5 Verificar Criação

```sql
SELECT * FROM overtime_requests WHERE attendance_id = @att_id;
```

**Resultado esperado:**
| id | attendance_id | teacher_id | minutes | expected_minutes | worked_minutes | status |
|----|---------------|------------|---------|------------------|----------------|--------|
| 1  | 123          | 1          | 120     | 480              | 600            | pending |

### Teste 2: Aprovação de Hora Extra

#### 2.1 Via Interface

1. Acesse `/admin/overtime.php`
2. Localize a solicitação pendente
3. Clique em **[Aprovar]**
4. Confirme

#### 2.2 Verificar Aprovação

```sql
-- Verificar status
SELECT id, status, approved_by_admin_id, approved_at 
FROM overtime_requests 
WHERE id = 1;

-- Verificar entrada no banco de horas
SELECT * 
FROM hour_bank_entries 
WHERE source = 'overtime_approved' 
  AND ref_attendance_id = 123;
```

**Resultado esperado em hour_bank_entries:**
| id | teacher_id | date | minutes | reason | source | ref_attendance_id |
|----|------------|------|---------|--------|--------|-------------------|
| X  | 1          | 2025-10-10 | 120 | Hora extra aprovada | overtime_approved | 123 |

### Teste 3: Rejeição de Hora Extra

#### 3.1 Criar Nova Solicitação

Repita os passos do Teste 1 com outro ponto.

#### 3.2 Rejeitar via Interface

1. Acesse `/admin/overtime.php`
2. Clique em **[Rejeitar]**
3. Informe motivo: "Não autorizado previamente"
4. Confirme

#### 3.3 Verificar Rejeição

```sql
SELECT status, rejection_reason, approved_by_admin_id 
FROM overtime_requests 
WHERE id = 2;
```

**Resultado esperado:**
```
status: rejected
rejection_reason: Não autorizado previamente
approved_by_admin_id: 1 (ID do admin)
```

### Teste 4: Auto-Rejeição

#### 4.1 Criar Ponto com Hora Extra

```sql
-- Criar ponto
INSERT INTO attendance (teacher_id, school_id, date, check_in, check_out, method, approved)
VALUES (1, 1, CURDATE(), CONCAT(CURDATE(), ' 08:00:00'), CONCAT(CURDATE(), ' 19:00:00'), 'pin', NULL);

SET @att_id = LAST_INSERT_ID();

-- Criar hora extra manualmente
INSERT INTO overtime_requests (attendance_id, teacher_id, school_id, date, minutes, expected_minutes, worked_minutes, status)
VALUES (@att_id, 1, 1, CURDATE(), 120, 480, 600, 'pending');
```

#### 4.2 Rejeitar o Ponto

Via interface `/admin/attendances.php`, rejeite o ponto.

**OU via SQL:**
```php
<?php
require_once __DIR__ . '/config.php';
$pdo = db();
$attendanceId = 125; // ID do ponto

// Rejeita ponto
$pdo->prepare("UPDATE attendance SET approved = 0 WHERE id = ?")->execute([$attendanceId]);

// Auto-rejeita hora extra
$rejected = auto_reject_overtime_on_attendance_rejection($pdo, $attendanceId, 1);
echo "Auto-rejeitadas: $rejected solicitações\n";
?>
```

#### 4.3 Verificar Auto-Rejeição

```sql
SELECT status, rejection_reason 
FROM overtime_requests 
WHERE attendance_id = @att_id;
```

**Resultado esperado:**
```
status: rejected
rejection_reason: Ponto rejeitado pelo administrador
```

---

## 🔧 Troubleshooting

### Problema: Tabela não foi criada

**Erro:**
```
Table 'ponto.overtime_requests' doesn't exist
```

**Solução:**
```sql
-- Verificar se você está no banco correto
SELECT DATABASE();

-- Executar CREATE TABLE manualmente
SOURCE install.sql;
```

### Problema: Erro de Foreign Key

**Erro:**
```
Cannot add foreign key constraint
```

**Possíveis causas:**
1. Tabela `attendance` não existe
2. Tipos de dados incompatíveis

**Solução:**
```sql
-- Verificar estrutura da tabela attendance
DESCRIBE attendance;

-- Criar tabela sem FKs primeiro, depois adicionar
CREATE TABLE overtime_requests (...) ENGINE=InnoDB;

-- Adicionar FKs uma por uma
ALTER TABLE overtime_requests 
ADD CONSTRAINT fk_ot_attendance 
FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE;
```

### Problema: Enum não atualizado

**Erro ao inserir:**
```
Data truncated for column 'source'
```

**Solução:**
```sql
-- Verificar enum atual
SHOW COLUMNS FROM hour_bank_entries WHERE Field = 'source';

-- Forçar atualização
ALTER TABLE hour_bank_entries 
MODIFY source ENUM('auto','manual','overtime_approved') NOT NULL DEFAULT 'manual';
```

### Problema: Página 404 - overtime.php não encontrada

**Solução:**
1. Verificar se o arquivo existe:
   ```bash
   ls -la public/admin/overtime.php
   ```

2. Verificar permissões:
   ```bash
   chmod 644 public/admin/overtime.php
   chown www-data:www-data public/admin/overtime.php
   ```

3. Verificar .htaccess ou nginx config

### Problema: Função não encontrada

**Erro:**
```
Call to undefined function detect_and_create_overtime()
```

**Solução:**
1. Verificar se `helpers.php` foi atualizado
2. Verificar se está sendo incluído:
   ```php
   require_once __DIR__ . '/../../config.php';
   ```

3. Limpar cache de opcode

---

## 📊 Validação Pós-Instalação

### Checklist de Validação

```sql
-- ✅ 1. Tabela criada
SELECT COUNT(*) FROM overtime_requests;

-- ✅ 2. Índices criados
SHOW INDEX FROM overtime_requests;

-- ✅ 3. Foreign Keys criadas
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'overtime_requests'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- ✅ 4. Enum atualizado
SHOW COLUMNS FROM hour_bank_entries WHERE Field = 'source';

-- ✅ 5. Permissões de admin
SELECT * FROM admins LIMIT 1;
```

### Teste de Integração

Execute este script PHP:

```php
<?php
require_once __DIR__ . '/config.php';

echo "=== VALIDAÇÃO DO SISTEMA DE HORAS EXTRAS ===\n\n";

$pdo = db();

// 1. Verificar tabela
try {
    $count = $pdo->query("SELECT COUNT(*) FROM overtime_requests")->fetchColumn();
    echo "✅ Tabela overtime_requests OK ($count registros)\n";
} catch (Exception $e) {
    echo "❌ Tabela overtime_requests ERRO: " . $e->getMessage() . "\n";
}

// 2. Verificar funções
$functions = [
    'detect_and_create_overtime',
    'approve_overtime_request',
    'reject_overtime_request',
    'auto_reject_overtime_on_attendance_rejection',
    'calculate_expected_minutes',
    'calculate_worked_minutes'
];

foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "✅ Função $func OK\n";
    } else {
        echo "❌ Função $func NÃO ENCONTRADA\n";
    }
}

// 3. Verificar arquivos
$files = [
    'public/admin/overtime.php',
    'api/checkin.php',
    'helpers.php'
];

foreach ($files as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "✅ Arquivo $file OK\n";
    } else {
        echo "❌ Arquivo $file NÃO ENCONTRADO\n";
    }
}

echo "\n=== VALIDAÇÃO COMPLETA ===\n";
?>
```

---

## 🔄 Rollback (Reverter Instalação)

Se precisar reverter as alterações:

```sql
-- 1. Remover tabela
DROP TABLE IF EXISTS overtime_requests;

-- 2. Reverter enum (CUIDADO: pode perder dados)
ALTER TABLE hour_bank_entries 
MODIFY source ENUM('auto','manual') NOT NULL DEFAULT 'manual';

-- 3. Restaurar backup
mysql -u usuario -p nome_do_banco < backup_antes_overtime_YYYYMMDD_HHMMSS.sql
```

---

## 📞 Suporte

Se encontrar problemas:

1. Verifique o arquivo de log do PHP: `tail -f /var/log/php_errors.log`
2. Verifique o log do MySQL: `tail -f /var/log/mysql/error.log`
3. Entre em contato: suporte@deedo.com.br

---

**Próximos Passos:**
- Leia a [Documentação Completa](OVERTIME_SYSTEM.md)
- Veja os [Exemplos de API](API_EXAMPLES.md)
- Configure as [Permissões de Usuários](USER_PERMISSIONS.md)


# Detecção e Marcação de Faltas nos Relatórios

## 📋 Visão Geral

O sistema agora detecta e marca automaticamente **FALTAS** nos relatórios quando um colaborador tinha jornada prevista mas não registrou ponto.

---

## ✨ Como Funciona

### Critério de Detecção

Uma **FALTA** é marcada quando:

1. ✅ O colaborador tem **jornada prevista** naquele dia (`expectedMin > 0`)
2. ❌ **NÃO há registro de ponto** naquele dia (`items` vazio)
3. ✅ O dia **NÃO é afastamento** (afastamentos aprovados zeram a jornada prevista)
4. ✅ A data **já passou** - `date <= hoje` (não marca falta em dias futuros) ⚠️ **IMPORTANTE**

### Exemplo de Cálculo

```php
// Segunda-feira - Jornada: 8h (480 minutos)
$expectedMin = 480;  // 8 horas previstas
$items = [];         // Sem registro de ponto
$date = '2025-10-10'; // Data do registro
$today = date('Y-m-d'); // Data de hoje

// Resultado: FALTA detectada e marcada SOMENTE se data <= hoje
$isFalta = ($expectedMin > 0) && empty($items) && ($date <= $today);

if ($isFalta) {
    // Exibe: ⚠ FALTA
} else {
    // Exibe: - (traço, sem informação)
}
```

---

## 📊 Visualização nos Relatórios

### Relatórios HTML

**Localização:**
- `/admin/reports.php` (Relatório Mensal)
- `/admin/teacher_monthly_report.php` (Relatório Mensal Detalhado)
- `/admin/reports_financial.php` (Relatório Financeiro) ✨ **NOVO**

**Aparência:**

```
┌─────────────────────────────────────────────────┐
│ Data       | Esperado | Trabalhado | Pontos     │
├─────────────────────────────────────────────────┤
│ 10/10/2025 | 08:00    | 00:00      | ⚠ FALTA   │
│                                     | Jornada    │
│                                     | prevista   │
│                                     | não        │
│                                     | registrada │
└─────────────────────────────────────────────────┘
```

**Estilo:**
- Badge vermelho com ícone de alerta
- Texto em vermelho
- Mensagem explicativa: "Jornada prevista não registrada"

### Relatórios PDF

**Localização:**
- `_tpl_reports_pdf.php` (Relatório Mensal)
- `_tpl_teacher_monthly_report_pdf.php` (Relatório Mensal Detalhado)
- `_tpl_financial_pdf.php` (Relatório Financeiro) ✨ **NOVO**

**Aparência:**

```
⚠ FALTA
Jornada prevista não registrada
```

**Estilo:**
- Texto em vermelho (`#dc3545`)
- Negrito para destaque
- Fonte menor para a mensagem explicativa

---

## 🔍 Casos de Uso

### Caso 1: Colaborador Faltou

**Cenário:**
- Segunda-feira, jornada configurada: 8h
- Colaborador não registrou ponto
- Não há afastamento

**Resultado:**
```
Data: 10/10/2025
Esperado: 08:00
Trabalhado: 00:00
Pontos: ⚠ FALTA - Jornada prevista não registrada
```

---

### Caso 2: Dia Sem Jornada (Final de Semana)

**Cenário:**
- Domingo, sem jornada configurada
- Colaborador não registrou ponto

**Resultado:**
```
Data: 14/10/2025
Esperado: 00:00
Trabalhado: 00:00
Pontos: -
```

**Observação:** Não marca falta porque `expectedMin = 0`

---

### Caso 2.1: Dia Futuro (Não Marca Falta)

**Cenário:**
- Segunda-feira (15/10/2025), jornada configurada: 8h
- Hoje é 10/10/2025 (dia ainda não chegou)
- Colaborador não registrou ponto (obviamente, dia futuro)

**Resultado:**
```
Data: 15/10/2025
Esperado: 08:00
Trabalhado: 00:00
Pontos: -
```

**Observação:** ⚠️ **NÃO marca falta** porque a data ainda não chegou (`date > hoje`)

---

### Caso 3: Afastamento Remunerado

**Cenário:**
- Quarta-feira, jornada configurada: 8h
- Colaborador em afastamento remunerado aprovado
- Não há registro de ponto

**Resultado:**
```
Data: 12/10/2025
Esperado: 00:00  (zerado por afastamento)
Trabalhado: 00:00
Pontos: Sem pontos
```

**Observação:** Não marca falta porque afastamento remunerado zera a jornada esperada

---

### Caso 4: Falta Parcial (Só Entrada ou Só Saída)

**Cenário:**
- Terça-feira, jornada: 8h
- Colaborador registrou apenas entrada (sem saída)

**Resultado:**
```
Data: 11/10/2025
Esperado: 08:00
Trabalhado: 00:00  (não conta ponto incompleto)
Pontos: Entrada: 08:00 | Saída: - | Método: cpf
```

**Observação:** O ponto aparece, mas horas trabalhadas = 0 (ponto incompleto não é contabilizado)

---

## 💻 Código Implementado

### HTML (reports.php e teacher_monthly_report.php)

```php
<td>
  <?php if (!empty($info['items'])): ?>
    <!-- Exibe registros de ponto -->
    <?php foreach ($info['items'] as $it): ?>
      <div class="mb-1">
        <span class="badge bg-primary-subtle text-dark">Entrada: <?= $entrada ?></span>
        <span class="badge bg-secondary-subtle text-dark">Saída: <?= $saida ?></span>
        <!-- ... -->
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <!-- SEM REGISTROS DE PONTO -->
    <?php if ($info['expectedMin'] > 0): ?>
      <!-- FALTA DETECTADA -->
      <span class="badge bg-danger text-white fs-6">
        <i class="bi bi-exclamation-triangle-fill"></i> FALTA
      </span>
      <div class="small text-danger mt-1">Jornada prevista não registrada</div>
    <?php else: ?>
      <!-- DIA SEM JORNADA -->
      <span class="text-muted">Sem pontos</span>
    <?php endif; ?>
  <?php endif; ?>
</td>
```

### PDF (_tpl_reports_pdf.php e _tpl_teacher_monthly_report_pdf.php)

```php
<td>
  <?php if (!empty($info['items'])): ?>
    <!-- Exibe registros de ponto -->
    <?php foreach ($info['items'] as $it): ?>
      <div class="small">
        Entrada: <?= $entrada ?> | Saída: <?= $saida ?> | Método: <?= $method ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <?php if (($info['expectedMin'] ?? 0) > 0): ?>
      <!-- FALTA DETECTADA -->
      <strong style="color: #dc3545;">⚠ FALTA</strong><br>
      <span class="small" style="color: #dc3545;">Jornada prevista não registrada</span>
    <?php else: ?>
      <span class="small muted">Sem pontos</span>
    <?php endif; ?>
  <?php endif; ?>
</td>
```

---

## 🎯 Arquivos Modificados

| Arquivo | Modificação |
|---------|-------------|
| `public/admin/reports.php` | Adicionada detecção de faltas na exibição HTML |
| `public/admin/teacher_monthly_report.php` | Adicionada detecção de faltas na exibição HTML |
| `public/admin/reports_financial.php` | Adicionada detecção de faltas com coluna "Status" |
| `public/admin/_tpl_reports_pdf.php` | Adicionada detecção de faltas no PDF |
| `public/admin/_tpl_teacher_monthly_report_pdf.php` | Adicionada detecção de faltas no PDF |
| `public/admin/_tpl_financial_pdf.php` | Adicionada detecção de faltas no PDF financeiro |

---

## 📈 Benefícios

### 1. Visibilidade Imediata
✅ Gestores identificam faltas rapidamente nos relatórios mensais

### 2. Redução de Erros
✅ Não precisa comparar manualmente "esperado vs trabalhado"

### 3. Auditoria Facilitada
✅ Faltas ficam registradas visualmente nos PDFs

### 4. Conformidade
✅ Facilita conformidade com políticas de presença

---

## 🔄 Integração com Afastamentos

O sistema já integra automaticamente com afastamentos:

```php
// Em reports.php (linhas ~100-110)
// Afastamentos aprovados e pagos (paid) → zeram jornada esperada
$stL = $pdo->prepare("SELECT l.*, lt.paid
                      FROM leaves l
                      JOIN leave_types lt ON lt.id = l.type_id
                      WHERE l.teacher_id = ? 
                        AND l.approved = 1 
                        AND ? BETWEEN l.start_date AND l.end_date");
$stL->execute([$selectedTeacher['id'], $dateStr]);
if ($leave = $stL->fetch(PDO::FETCH_ASSOC)) {
  if ((int)$leave['paid'] === 1) {
    $daily[$dateStr]['expectedMin'] = 0;  // Zera jornada
  }
}
```

**Resultado:**
- Afastamento remunerado → `expectedMin = 0` → **Não marca falta**
- Afastamento não remunerado → Mantém `expectedMin` original

### Abona vs. não abona (a partir de 2026-06)

A decisão de abonar a falta passou a ser **por afastamento** (coluna
`leaves.excuses_absence`), desacoplada da remuneração (`leave_types.paid`):

- **Afastamento abonado** (`excuses_absence = 1`, aprovado) → zera a jornada
  prevista → **não** marca falta nem gera desconto.
- **Afastamento não abonado** (`excuses_absence = 0`) → mantém a jornada →
  o dia conta como **falta justificada** (motivo registrado) + desconto integral.
- **Sem afastamento** → **falta não justificada**.

A zeragem é centralizada em `calculate_expected_minutes()` e replicada nos
relatórios via o helper `leave_day_is_excused()`.

---

## 🗓️ Integração com Calendário (Feriado / Ponto Facultativo)

Exceções de calendário cadastradas em **Calendário e Exceções**
(`calendar_exceptions`) com **`is_working_day = 0`** — feriado, ponto
facultativo, recesso, etc. — **zeram a jornada prevista** do dia, então **não**
marcam falta (mesma lógica do afastamento abonado).

```php
// helpers.php
if ($expMin > 0 && calendar_day_is_off($pdo, $date, $schoolId)) {
    $expMin = 0; // feriado / ponto facultativo → não é falta
}
```

**Pontos de atenção (corrigidos em 2026-06):**

- A exceção pode ser **da rede inteira** (`school_id IS NULL`) **ou de uma
  escola específica** (`school_id = X`). Os relatórios precisam consultar o
  calendário com a **escola do colaborador** (`primary_school_id_for_teacher()`);
  passar `null` ignora silenciosamente feriados de escola específica.
- `is_working_day()` retorna `false` para sábado/domingo *por padrão*; por isso
  a zeragem usa `calendar_day_is_off()` (apenas exceções **explícitas**
  `is_working_day=0`), preservando quem trabalha em sábado letivo.
- A fonte de feriados é **`calendar_exceptions`** (não existe tabela `holidays`
  neste schema).

Cobertura: `reports.php`, `teacher_monthly_report.php`, `reports_financial.php`,
`reports_insights.php`, `my_timesheet.php` e os PDFs derivados. Teste:
`tests/test_calendar_holiday_expected.php`.

---

## 📊 Exemplo Visual Completo

### Relatório Mensal - João Silva - Outubro/2025

| Data | Esperado | Trabalhado | Pontos | Justificativa |
|------|----------|------------|--------|---------------|
| 01/10 | 08:00 | 08:30 | Entrada: 08:00, Saída: 17:00 | - |
| 02/10 | 08:00 | 00:00 | **⚠ FALTA** - Jornada prevista não registrada | - |
| 03/10 | 08:00 | 08:00 | Entrada: 08:00, Saída: 16:30 | - |
| 04/10 | 08:00 | 00:00 | **⚠ FALTA** - Jornada prevista não registrada | - |
| 05/10 | 00:00 | 00:00 | Sem pontos | Afastamento - Férias |
| 06/10 | 00:00 | 00:00 | - | (Sábado - sem jornada) |
| 07/10 | 00:00 | 00:00 | - | (Domingo - sem jornada) |
| 08/10 | 08:00 | 09:00 | Entrada: 08:00, Saída: 18:00 | - |

**Resumo:**
- Dias úteis: 5
- Faltas: 2 (02/10 e 04/10)
- Afastamentos: 1 (05/10)
- Taxa de presença: 60% (3 presentes / 5 dias úteis)

---

### Relatório Financeiro - João Silva - Outubro/2025

| Data | Esperado (min) | Trabalhado (min) | Entrada | Saída | Status |
|------|----------------|------------------|---------|-------|--------|
| 01/10/2025 | 480 | 510 | 08:00 | 17:30 | - |
| 02/10/2025 | 480 | 0 | - | - | **⚠ FALTA** |
| 03/10/2025 | 480 | 480 | 08:00 | 17:00 | - |
| 04/10/2025 | 480 | 0 | - | - | **⚠ FALTA** |
| 05/10/2025 | 0 | 0 | - | - | - |
| 06/10/2025 | 0 | 0 | - | - | - |
| 07/10/2025 | 0 | 0 | - | - | - |
| 08/10/2025 | 480 | 540 | 08:00 | 18:00 | - |
| 15/10/2025 | 480 | 0 | - | - | - (futuro) |

**Resumo Financeiro:**
- Min. Previstos: 1920 (32:00)
- Min. Trabalhados: 1530 (25:30)
- Déficit: 390 min / R$ 195,00 (desconto)
- Faltas Detectadas: 2

---

## 🧪 Testando a Funcionalidade

### Teste 1: Criar Falta

1. Configure jornada de um colaborador (ex: Segunda a Sexta, 8h/dia)
2. Não registre ponto em um dia útil
3. Gere relatório mensal
4. Verifique se aparece "⚠ FALTA" no dia sem registro

### Teste 2: Afastamento Não Marca Falta

1. Crie afastamento remunerado para um dia
2. Não registre ponto nesse dia
3. Gere relatório
4. Verifique que NÃO aparece falta (aparece "Sem pontos")

### Teste 3: PDF

1. Gere relatório HTML com faltas
2. Exporte para PDF
3. Verifique se as faltas aparecem em vermelho no PDF

### Teste 4: Relatório Financeiro

1. Acesse `/admin/reports_financial.php`
2. Selecione colaborador e mês
3. Verifique:
   - Coluna "Status" mostra **FALTA** nos dias sem registro
   - Alerta vermelho mostra total de faltas detectadas
   - PDF exportado também mostra as faltas
   - Faltas contam no cálculo de déficit de horas

---

## 📞 Suporte

Para dúvidas sobre a detecção de faltas:
- 📧 Email: suporte@deedo.com.br
- 📚 Documentação: [OVERTIME_SYSTEM.md](OVERTIME_SYSTEM.md)

---

**Versão:** 1.1.0  
**Data:** 2025-10-10  
**Feature:** Detecção automática de faltas nos relatórios


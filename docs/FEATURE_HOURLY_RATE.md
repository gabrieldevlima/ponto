# 💰 Feature Opcional: Valor por Hora

## 📋 Visão Geral

Esta funcionalidade permite configurar um **valor por hora** para cada colaborador, exibindo uma **estimativa financeira** na consulta da folha de ponto.

**Status:** ✅ Implementada (Opcional)

---

## 🔧 Como Ativar

### Passo 1: Executar SQL

Execute o arquivo `install_hourly_rate.sql` no seu banco de dados:

```bash
# Via linha de comando
mysql -u root -p ponto < install_hourly_rate.sql

# Ou copie e cole no phpMyAdmin/MySQL Workbench
```

**O que ele faz:**
```sql
ALTER TABLE teachers 
ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL;
```

---

### Passo 2: Configurar Valores

Após executar o SQL:

1. Vá em **Admin → Colaboradores**
2. Clique em **Editar** no colaborador
3. Encontre o campo **"Valor/Hora"** (novo campo)
4. Digite o valor (ex: 50.00)
5. Clique em **Salvar**

---

### Passo 3: Visualizar

O colaborador verá na **"Minha Folha de Ponto"**:

```
💰 Valor Estimado: R$ 8.400,00 (R$ 50,00/hora)
```

**Cálculo:**
```
Valor = Horas trabalhadas ÷ 60 × Valor/hora
Exemplo: 168 horas × R$ 50,00 = R$ 8.400,00
```

---

## 🎯 Funcionalidades

### 1. Campo no Admin

**Localização:** Admin → Colaboradores → Editar

**Aparência:**
```
┌─────────────────────────────────────┐
│ Valor/Hora (i)                      │
│ ┌─────┬──────────────────────────┐ │
│ │ R$  │ 50.00                    │ │
│ └─────┴──────────────────────────┘ │
│ Opcional: para mostrar valor       │
│ estimado na consulta               │
└─────────────────────────────────────┘
```

**Características:**
- Aparece apenas se a coluna existir no banco
- Opcional (pode deixar em branco)
- Aceita valores decimais (ex: 50.50)
- Tooltip explicativo

---

### 2. Exibição na Folha de Ponto

**Localização:** Minha Folha de Ponto (acesso do colaborador)

**Aparência:**
```
╔════════════════════════════════════════════╗
║ 💰 Valor Estimado: R$ 8.400,00            ║
║    (R$ 50,00/hora)                        ║
╚════════════════════════════════════════════╝
```

**Aparece apenas se:**
- Coluna `hourly_rate` existir
- Colaborador tiver valor configurado (> 0)

---

## 📁 Arquivos Modificados

### 1. `install_hourly_rate.sql` (NOVO)
Script SQL para adicionar a coluna no banco.

### 2. `public/my_timesheet.php`
- Remove dependência obrigatória da coluna
- Calcula valor estimado se configurado
- Exibe alerta informativo

**Código:**
```php
// Valor por hora (opcional)
$hourly_rate = (float)($collaborator['hourly_rate'] ?? 0);

// Calcula valor estimado
if ($hourly_rate > 0) {
    $totalValue = ($totalWorkedMinutes / 60) * $hourly_rate;
}
```

### 3. `public/admin/teacher_edit.php`
- Adiciona campo "Valor/Hora" no formulário
- Detecta automaticamente se coluna existe
- Só mostra campo se coluna estiver criada

**Código:**
```php
// Verifica se coluna existe
$hasHourlyRate = false;
try {
  $pdo->query("SELECT hourly_rate FROM teachers LIMIT 1");
  $hasHourlyRate = true;
} catch (PDOException $e) {
  // Coluna não existe ainda
}
```

### 4. `public/admin/teachers_save.php`
- Salva valor/hora se campo estiver presente
- Compatível com e sem a coluna
- Validação automática

**Código:**
```php
$hourly_rate = isset($_POST['hourly_rate']) && $_POST['hourly_rate'] !== '' 
    ? (float)$_POST['hourly_rate'] 
    : null;
```

---

## 🔒 Segurança

### Validações Implementadas

✅ Aceita apenas números decimais  
✅ Valor mínimo: 0  
✅ Valor máximo: 999999.99 (limite do DECIMAL(10,2))  
✅ NULL para valores não configurados  
✅ Não afeta colaboradores sem valor configurado

### Auditoria

Mudanças são registradas em `audit_log`:
```php
audit_log('update','teacher',$id,[
    'hourly_rate'=>$hourly_rate
]);
```

---

## 📊 Exemplos de Uso

### Exemplo 1: Configurar Valor

```
1. Admin acessa: Colaboradores → Editar João Silva
2. Campo "Valor/Hora": 50.00
3. Salvar

Resultado: João Silva verá valores estimados em sua folha
```

### Exemplo 2: Sem Valor Configurado

```
Campo "Valor/Hora": (vazio)

Resultado: João Silva NÃO verá alerta de valor estimado
```

### Exemplo 3: Múltiplos Valores

```
Professor A: R$ 50,00/hora
Professor B: R$ 75,00/hora
Zelador:     R$ 25,00/hora
Diretor:     (sem valor configurado)

Resultado: Cada um vê seu próprio valor estimado
```

---

## 🧪 Como Testar

### Teste 1: Sem a Coluna (Padrão)

```bash
1. NÃO execute o SQL
2. Acesse: my_timesheet.php
3. ✅ Deve funcionar normalmente
4. ✅ NÃO mostra valor estimado
5. Admin → Editar Colaborador
6. ✅ NÃO mostra campo "Valor/Hora"
```

### Teste 2: Com a Coluna

```bash
1. Execute: install_hourly_rate.sql
2. Admin → Colaboradores → Editar
3. ✅ Campo "Valor/Hora" aparece
4. Digite: 50.00
5. Salvar
6. Colaborador → Minha Folha
7. ✅ Mostra: "Valor Estimado: R$ X.XXX,XX"
```

### Teste 3: Cálculo Correto

```bash
Horas trabalhadas: 10h 30min = 10.5 horas
Valor/hora: R$ 50,00
Resultado esperado: 10.5 × 50 = R$ 525,00

✅ Verificar se o valor está correto
```

---

## ⚠️ Avisos Importantes

### 1. É Apenas uma Estimativa

```
⚠️ O valor exibido é ESTIMADO
- Não inclui descontos (INSS, IR, etc)
- Não inclui bonificações
- Não inclui adicionais (noturno, periculosidade)
- Para valores oficiais, consulte RH/Contracheque
```

### 2. Não é Obrigatório

```
✅ Sistema funciona perfeitamente SEM esta feature
✅ Execute o SQL apenas se quiser a funcionalidade
✅ Pode adicionar depois, a qualquer momento
```

### 3. Compatibilidade

```
✅ Funciona com versão antiga (sem coluna)
✅ Funciona com versão nova (com coluna)
✅ Migração transparente e segura
```

---

## 🔄 Como Desativar

### Opção 1: Remover a Coluna

```sql
ALTER TABLE teachers DROP COLUMN hourly_rate;
```

**Resultado:** Campo desaparece do admin e não calcula mais valores.

### Opção 2: Zerar Valores

```sql
UPDATE teachers SET hourly_rate = NULL;
```

**Resultado:** Campo continua existindo, mas ninguém vê valores.

---

## 📈 Melhorias Futuras (Opcional)

### Funcionalidades Adicionais

- [ ] Valor/hora diferente por dia da semana
- [ ] **Adicional noturno (Art. 73 CLT): 22h–05h com +20%** — a estrutura para jornada noturna
      (cadastro com `end_next_day`, marcação de ponto cruzando meia-noite, relatórios) já está
      implementada. Falta apenas calcular o adicional sobre as horas trabalhadas dentro da
      janela 22h–05h e somar ao contracheque. Pontos a tocar: `helpers.php`
      (`calculate_worked_minutes` pode ganhar variante `calculate_night_minutes`), procedure
      `generate_payslip` (SQL), `_tpl_payslip_pdf.php`, `reports_financial.php`.
- [ ] Adicional de insalubridade/periculosidade
- [ ] Cálculo de INSS e IR estimados
- [ ] Exportar relatório financeiro
- [ ] Gráfico de evolução de ganhos

### Campos Adicionais

- [ ] `overtime_rate` - Valor da hora extra
- [ ] `night_bonus` - Adicional noturno (% sobre horas em 22h–05h)
- [ ] `weekend_bonus` - Adicional fim de semana
- [ ] `hazard_pay` - Periculosidade/Insalubridade

---

## 🐛 Troubleshooting

### Erro: "Column not found: hourly_rate"

**Causa:** Coluna não existe no banco.

**Solução:**
```bash
1. Execute: install_hourly_rate.sql
2. OU aceite trabalhar sem a feature
```

---

### Campo não aparece no admin

**Verificar:**
```sql
-- Ver se coluna existe
SHOW COLUMNS FROM teachers LIKE 'hourly_rate';
```

**Se não existir:**
```sql
-- Adicionar manualmente
ALTER TABLE teachers ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL;
```

---

### Valor não aparece na folha do colaborador

**Verificar:**

1. **Coluna existe?**
```sql
SELECT hourly_rate FROM teachers WHERE id = X;
```

2. **Valor está configurado?**
- Deve ser > 0
- NULL = não mostra

3. **Código está correto?**
- Ver `my_timesheet.php` linha ~48
- Deve ter: `$hourly_rate = (float)($collaborator['hourly_rate'] ?? 0);`

---

## ✅ Checklist de Implantação

- [x] SQL script criado (`install_hourly_rate.sql`)
- [x] Campo adicionado no formulário de edição
- [x] Salvamento implementado
- [x] Cálculo implementado na folha de ponto
- [x] Compatibilidade com versão sem coluna
- [x] Documentação completa
- [ ] **SQL executado no banco** (você decide)
- [ ] **Valores configurados** (você decide)
- [ ] **Testado com colaboradores** (você decide)

---

## 📞 Suporte

### Para Colaboradores

**"Meu valor está errado!"**
→ Contate o RH/Admin para verificar configuração

**"Não vejo valor estimado"**
→ Pode não estar configurado ou feature desabilitada

### Para Administradores

**"Coluna não existe"**
→ Execute `install_hourly_rate.sql`

**"Como configurar?"**
→ Admin → Colaboradores → Editar → Campo "Valor/Hora"

---

**💡 Feature opcional pronta para uso!**

*Execute o SQL apenas se desejar esta funcionalidade.*  
*O sistema funciona perfeitamente sem ela.*

*Data: Outubro 2025*
*Versão: 1.0*


# 📋 Portal do Colaborador - Consulta de Folha de Ponto

## 🎯 Funcionalidade

Sistema de autoatendimento que permite aos colaboradores consultarem sua própria folha de ponto mensal, incluindo:

- ✅ Pontos registrados (entrada/saída)
- ✅ Status de aprovação (aprovado/pendente)
- ✅ Horas trabalhadas
- ✅ Banco de horas
- ✅ Valores financeiros (se configurado)
- ✅ Filtro por mês/ano
- ✅ Detalhamento diário

---

## 📁 Arquivos Criados

### 1. `public/my_login.php`
**Login do Colaborador**

- Autenticação por CPF (11 dígitos)
- Mesmo CPF usado para registrar ponto
- Validação de duplicidade
- Interface responsiva e moderna
- Links para registro de ponto e área admin

**Recursos:**
- CSRF protection
- Sessão segura
- Validação de colaborador ativo
- Mensagens de erro amigáveis

---

### 2. `public/my_timesheet.php`
**Folha de Ponto Mensal**

**Cabeçalho:**
- Nome do colaborador logado
- Botões: Registrar Ponto | Sair

**Seletor:**
- Mês (dropdown)
- Ano (dropdown)
- Botão "Consultar"

**Cards de Estatísticas (4 cards coloridos):**
1. **Horas Trabalhadas** (roxo)
   - Total de horas do mês
   
2. **Pontos Aprovados** (verde)
   - Quantidade de pontos aprovados
   
3. **Pontos Pendentes** (rosa)
   - Quantidade aguardando aprovação
   
4. **Banco de Horas** (azul)
   - Saldo total acumulado

**Valor Financeiro (se configurado):**
- Alerta informativo com valor estimado
- Baseado em horas × valor/hora configurado

**Tabela de Registros:**
- Data (com dia da semana)
- Horário de entrada
- Horário de saída
- Duração
- Local (escola)
- Status (aprovado/pendente)
- Banco de horas do dia

---

### 3. Modificação em `public/index.php`

Adicionado botão **"Minha Folha"** no header:
```html
<a href="my_login.php" class="btn btn-outline-info btn-sm">
  <i class="bi bi-calendar-check"></i>
  <span class="d-none d-sm-inline">Minha Folha</span>
</a>
```

---

## 🔐 Segurança

### Autenticação
- Login por CPF (mesmo usado para registro)
- Sessão PHP segura
- Validação de colaborador ativo
- Logout seguro

### Proteção CSRF
- Token CSRF em formulário de login
- Proteção contra ataques CSRF

### Controle de Acesso
- Apenas colaborador autenticado acessa `my_timesheet.php`
- Redirecionamento automático se não logado
- Sessão isolada (não interfere com admin)

### Isolamento de Dados
- Colaborador vê apenas seus próprios pontos
- Filtro por `teacher_id` em todas as queries
- Sem acesso a dados de outros colaboradores

---

## 🎨 Interface

### Design
- Responsivo (mobile-first)
- Cards coloridos para estatísticas
- Tabela limpa e organizada
- Badges visuais para status
- Ícones Bootstrap Icons

### Cores dos Cards
- **Roxo** (Gradient): Horas trabalhadas
- **Verde** (Gradient): Pontos aprovados
- **Rosa** (Gradient): Pontos pendentes
- **Azul** (Gradient): Banco de horas

### Badges de Status
- 🟢 **Verde**: Aprovado
- 🟡 **Amarelo**: Pendente

### Banco de Horas na Tabela
- 🟢 **Verde**: Horas extras (+)
- 🔴 **Vermelho**: Horas negativas (-)
- ⚪ **Cinza**: Neutro (0)

---

## 📊 Cálculos

### Horas Trabalhadas
```
Total = Soma de todas as durações (check_out - check_in)
```

### Banco de Horas
```
Por Dia: SELECT SUM(minutes) FROM hour_bank_entries WHERE date = ?
Acumulado: SELECT SUM(minutes) FROM hour_bank_entries
```

### Valor Financeiro
```
Valor = (Total de minutos ÷ 60) × Valor por hora
```
*(Aparece apenas se `hourly_rate > 0` no tipo do colaborador)*

---

## 🔄 Fluxo de Uso

### 1. Acesso Inicial
```
1. Colaborador acessa index.php
2. Clica em "Minha Folha"
3. Redireciona para my_login.php
```

### 2. Login
```
1. Digite CPF (11 dígitos)
2. Clique em "Acessar"
3. Sistema valida CPF
4. Redireciona para my_timesheet.php
```

### 3. Consulta
```
1. Visualiza estatísticas do mês atual
2. Pode alterar mês/ano no seletor
3. Clica em "Consultar"
4. Tabela atualiza com dados do período
```

### 4. Logout
```
1. Clica em "Sair" no topo
2. Sessão é destruída
3. Redireciona para my_login.php
```

---

## 🧪 Como Testar

### Teste 1: Login
```
1. Acesse: http://localhost/ponto/public/my_login.php
2. Digite CPF de um colaborador (ex: 111.222.333-44)
3. Clique em "Acessar"
4. ✅ Deve entrar e mostrar folha de ponto
```

### Teste 2: Consulta
```
1. Após login, visualize estatísticas
2. ✅ Cards devem mostrar:
   - Horas trabalhadas do mês
   - Quantidade de pontos aprovados/pendentes
   - Saldo do banco de horas
3. ✅ Tabela deve listar todos os pontos do mês
```

### Teste 3: Filtro de Período
```
1. Altere mês (ex: Janeiro)
2. Altere ano (ex: 2024)
3. Clique em "Consultar"
4. ✅ Tabela deve atualizar com dados do período
```

### Teste 4: Valor Financeiro
```
1. Configure hourly_rate no tipo do colaborador (admin)
2. Volte para my_timesheet.php
3. ✅ Deve aparecer alerta com "Valor Estimado"
```

### Teste 5: Segurança
```
1. Faça logout
2. Tente acessar direto: my_timesheet.php
3. ✅ Deve redirecionar para my_login.php
```

### Teste 6: CPF Inválido
```
1. Tente fazer login com CPF não cadastrado ou incorreto
2. ✅ Deve mostrar erro: "CPF não encontrado ou colaborador inativo"
```

---

## 📱 Responsividade

### Mobile (< 576px)
- Botões com apenas ícones (sem texto)
- Cards em coluna única
- Tabela com scroll horizontal
- Logo menor

### Tablet (576px - 991px)
- Cards em 2 colunas
- Botões com ícone + texto reduzido
- Tabela responsiva

### Desktop (> 992px)
- Cards em 4 colunas
- Todos os textos visíveis
- Layout otimizado

---

## ⚙️ Configurações

### Valor por Hora
Para exibir valores financeiros:

1. Vá em Admin → Tipos de Colaborador
2. Edite o tipo
3. Configure "Valor/Hora" (ex: 50.00)
4. Salve

**Resultado:**
- Aparecerá alerta com valor estimado
- Cálculo: horas × valor/hora

### Modo de Agenda
O sistema respeita o modo configurado:
- **Classes**: Por aulas
- **Time**: Por horário

---

## 🔧 Manutenção

### Adicionar Novos Campos na Tabela

Edite `public/my_timesheet.php`:

```php
// Na tabela, adicione coluna:
<th>Novo Campo</th>

// No loop de dados:
<td><?= htmlspecialchars($att['novo_campo']) ?></td>
```

### Personalizar Cores dos Cards

Edite o CSS em `my_timesheet.php`:

```css
.stat-card {
    background: linear-gradient(135deg, #cor1, #cor2);
}
```

### Adicionar Novos Filtros

Adicione no formulário:

```html
<select name="filtro" class="form-select">
  <option>Opção</option>
</select>
```

E processe em PHP:
```php
$filtro = $_GET['filtro'] ?? 'todos';
```

---

## 📈 Melhorias Futuras (Opcional)

### Funcionalidades
- [ ] Exportar folha em PDF
- [ ] Gráfico de horas trabalhadas
- [ ] Histórico de vários meses
- [ ] Notificações de ponto pendente
- [ ] Solicitar correção de ponto
- [ ] Ver foto dos pontos registrados

### UX
- [ ] Loading durante carregamento
- [ ] Animações nos cards
- [ ] Tooltip com mais informações
- [ ] Busca/filtro na tabela
- [ ] Paginação se muitos registros

### Mobile
- [ ] App PWA instalável
- [ ] Push notifications
- [ ] Modo offline para consulta

---

## 🐛 Troubleshooting

### "CPF não encontrado" mas CPF está certo

**Verificar:**
1. CPF no cadastro está correto? (Gestão → Colaboradores)
2. Colaborador está ativo?
3. CPF digitado sem espaços ou caracteres extras?

**Solução:**
- Admin deve conferir o CPF em Gestão → Colaboradores → Editar

### Nenhum ponto aparece

**Verificar:**
1. Colaborador tem pontos registrados?
2. Mês/ano selecionado está correto?
3. Pontos estão na tabela `attendance`?

**Debug:**
```sql
SELECT * FROM attendance 
WHERE teacher_id = ? 
AND date BETWEEN '2025-01-01' AND '2025-01-31';
```

### Banco de horas errado

**Verificar:**
```sql
SELECT * FROM hour_bank_entries WHERE teacher_id = ?;
```

**Recalcular (admin):**
- Vá em Admin → Pontos Registrados
- Edite e salve novamente (recalcula)

---

## 📞 Suporte ao Colaborador

### Perguntas Frequentes

**Q: CPF não encontrado ou colaborador inativo. O que fazer?**
A: Contate o RH/Admin para conferir seu cadastro e CPF.

**Q: Por que meu ponto está "Pendente"?**
A: Aguardando aprovação do gestor. Motivos comuns:
- Foto com baixa qualidade
- Localização imprecisa
- Fora do horário esperado

**Q: Posso alterar meu ponto?**
A: Não diretamente. Contate o RH/Admin para correções.

**Q: Como funciona o banco de horas?**
A: 
- **Positivo**: Você trabalhou mais que o esperado
- **Negativo**: Você trabalhou menos que o esperado
- Calculado diariamente e acumulado

---

## ✅ Checklist de Implantação

- [x] Arquivos criados (my_login.php, my_timesheet.php)
- [x] Link adicionado no index.php
- [x] Sistema de sessão implementado
- [x] Segurança aplicada (CSRF, sessão)
- [x] Interface responsiva
- [x] Cálculos implementados
- [x] Documentação criada
- [ ] **Testar com colaboradores reais**
- [ ] **Treinar colaboradores para usar**
- [ ] **Configurar valores/hora (se aplicável)**

---

**🎉 Portal do Colaborador está completo e pronto para uso!**

*Data: Outubro 2025*
*Versão: 1.0*


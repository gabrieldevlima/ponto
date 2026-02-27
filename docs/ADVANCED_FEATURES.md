# Funcionalidades Avançadas - DEEDO Ponto

## 🛡️ Sistema Anti-Fraude de Localização

### Visão Geral
Sistema de detecção básica de tentativas de fraude via GPS falso (mock apps) e localização manipulada.

### Detecções Implementadas

#### 1. GPS Mock Detection (JavaScript)
- Verifica flag `navigator.geolocation.mocked` (Android)
- Detecta precisão GPS suspeitosamente perfeita (< 5m constantemente)
- Compara timestamp do GPS vs dispositivo (diferença > 5 segundos)
- Calcula velocidade de deslocamento entre check-ins

#### 2. Validação Backend (PHP)
- Cálculo de distância Haversine entre check-in e check-out
- Detecta velocidades impossíveis (> 90 km/h)
- Armazena device fingerprint único por dispositivo
- Registra todas as detecções no log

### Níveis de Risco

| Nível | Descrição | Ação do Sistema |
|-------|-----------|-----------------|
| 0 | Baixo - Nenhum indicador | Registro normal, sem flag |
| 1 | Médio - Indicadores leves | Registra mas marca para revisão |
| 2 | Alto - GPS mock ou velocidade impossível | Registra e alerta admin |

### Campos no Banco de Dados

**Tabela `attendance`:**
- `fraud_risk_level`: Nível de risco (0-2)
- `gps_mock_detected`: Flag booleana
- `device_fingerprint`: Hash único do dispositivo

**Tabela `fraud_detection_log`:**
- Registro completo de cada detecção
- Detalhes em JSON
- IP e User Agent
- Link para o registro de ponto

### Como Monitorar

1. **Dashboard**: Alertas aparecem se houver detecções nos últimos 7 dias
2. **Registros de Ponto**: Coluna de risco exibe badges coloridos
3. **View SQL**: `v_fraud_analysis` - agregação por colaborador

### Configurações

Edite valores na tabela `antifraud_config`:
- `max_distance_km_per_minute`: Velocidade máxima permitida (padrão: 1 km/min)
- `min_suspicious_accuracy`: Precisão suspeita (padrão: 5m)
- `fraud_alert_threshold`: Detecções para alertar (padrão: 3 em 24h)

**Importante**: O sistema NÃO bloqueia registros, apenas marca e alerta.

---

## 🎥 Espelhamento de Câmera

### Implementação
Aplicado CSS `transform: scaleX(-1)` no elemento `<video>`.

### Benefícios
- UX tipo "selfie" facilita posicionamento
- Usuários veem própria imagem espelhada (como em espelho)
- Foto salva continua não-espelhada (correta)

### Arquivos
- `public/index.php` (linha ~213)

---

## 📅 Sistema de Calendário e Exceções

### Visão Geral
Gerenciamento completo de feriados, sábados letivos, eventos acadêmicos e calendário escolar.

### Funcionalidades

#### 1. Exceções de Calendário
- **Feriados**: Dias não úteis (nacional, estadual, municipal)
- **Sábados Letivos**: Exceções de trabalho em fins de semana
- **Eventos Acadêmicos**: Formaturas, reuniões, etc.
- **Recesso**: Períodos sem aula
- **Compensações**: Pontes e emendas

#### 2. Feriados Móveis Automáticos
- **Páscoa**: Calculada via algoritmo de Computus de Gauss
- **Carnaval**: 47 dias antes da Páscoa
- **Sexta-feira Santa**: 2 dias antes da Páscoa
- **Corpus Christi**: 60 dias após a Páscoa

Geração automática via procedure: `CALL generate_mobile_holidays(2025)`

#### 3. Calendário Acadêmico
- Definição de semestres letivos
- Data de início e fim
- Por escola

#### 4. Integração nos Relatórios
- **Feriados**: Horas esperadas = 0 automaticamente
- **Sábados Letivos**: Horas esperadas somadas normalmente
- **Destacados visualmente**: Linhas vermelhas nas tabelas

### Interface

**Calendário Visual (FullCalendar.js)**
- Visualização mensal/semanal
- Clique para criar exceção
- Cores por tipo de evento
- Filtros por escola e tipo

### Arquivos
- `public/admin/calendar_exceptions.php`
- `install_calendar_system.sql`

### Funções Helper
- `is_working_day($pdo, $date, $schoolId)`: Verifica se é dia útil
- `get_holidays_in_period($pdo, $start, $end, $schoolId)`: Lista feriados

### Exemplo de Uso

```php
// Verificar se dia 25/12 é útil
if (!is_working_day($pdo, '2025-12-25', null)) {
    echo "É feriado!";
}

// Buscar feriados de janeiro
$holidays = get_holidays_in_period($pdo, '2025-01-01', '2025-01-31', null);
foreach ($holidays as $date => $info) {
    echo "$date: {$info['name']}\n";
}
```

---

## 💰 Sistema de Holerites

### Visão Geral
Geração automatizada de holerites (contracheques) em PDF com base nas horas trabalhadas.

### Funcionalidades

#### 1. Geração em Lote
- Seleciona múltiplos colaboradores
- Gera todos de uma vez para um mês
- Cálculo automático via procedure SQL

#### 2. Cálculo Automático
**Proventos:**
- Salário base
- Horas extras (+50%)

**Descontos:**
- Déficit de horas (proporcional)

**Líquido:**
- Total Proventos - Total Descontos

#### 3. Visualização
- Admin: lista todos, regenera, exclui
- Colaborador: acessa via "Minha Folha"
- PDF profissional via DomPDF

#### 4. Controle de Visualização
- Registra quando colaborador visualiza
- Badge "Visto" / "Não visto"
- Timestamp de visualização

### Estrutura do Banco

**Tabela `payslips`:**
- Dados calculados (horas, valores)
- Geração e visualização
- Unique por colaborador+mês

**Tabela `payslip_items`:**
- Itens adicionais customizados
- Proventos ou descontos extras

### Como Gerar

1. Acesse **Relatórios** → **Holerites**
2. Selecione mês/ano
3. Marque colaboradores
4. Clique em **Gerar Holerites**

### Como Colaborador Acessa

1. Login no portal do colaborador
2. Menu **Minha Folha**
3. Seção **Holerites**
4. Visualizar ou baixar PDF

### Arquivos
- `public/admin/payroll.php` - Gerenciamento
- `public/admin/_tpl_payslip_pdf.php` - Template PDF
- `public/payslip.php` - Visualização pública
- `install_payroll.sql` - Migração

---

## 📊 Dashboard Avançado com Gráficos

### Visão Geral
Dashboard administrativo com 7 gráficos interativos usando Chart.js.

### Gráficos Implementados

#### 1. Evolução de Presença (30 dias)
- **Tipo**: Linha com área
- **Dados**: Contagem de presentes por dia
- **Período**: Últimos 30 dias
- **Uso**: Identificar tendências e padrões

#### 2. Distribuição de Status (Hoje)
- **Tipo**: Pizza (Doughnut)
- **Dados**: Presentes, Ausentes, Pendentes, Afastados
- **Período**: Dia atual
- **Uso**: Visão rápida do status atual

#### 3. Horas Extras por Mês (12 meses)
- **Tipo**: Barras
- **Dados**: Total de horas extras aprovadas
- **Período**: Últimos 12 meses
- **Uso**: Monitorar custos e padrões de hora extra

#### 4. Comparativo por Escola (Hoje)
- **Tipo**: Barras empilhadas
- **Dados**: Presentes vs Ausentes por escola
- **Período**: Dia atual
- **Uso**: Comparação entre unidades (network admin)

#### 5. Top 5 Tipos de Afastamento (Mês)
- **Tipo**: Doughnut
- **Dados**: Dias de afastamento por tipo
- **Período**: Mês atual
- **Uso**: Identificar motivos mais comuns de ausência

#### 6. Tendência de Presença (Média Móvel)
- **Tipo**: Linha com área
- **Dados**: Média móvel de 7 dias
- **Período**: Últimos 14 dias
- **Uso**: Previsão e detecção de anomalias

#### 7. Alertas de Fraude
- **Tipo**: Card de alerta
- **Dados**: Detecções de alto risco em 7 dias
- **Uso**: Segurança e auditoria

### Tecnologias

- **Chart.js 4.4.1**: Biblioteca principal
- **ChartDataLabels Plugin**: Labels nos gráficos
- **Queries otimizadas**: Agregações eficientes
- **Responsivo**: Adapta mobile e desktop

### Performance

- Queries com índices apropriados
- Limitação de período (30 dias máximo)
- Agregações no banco (não em PHP)
- Cache de gráficos no navegador

---

## 🎨 Navbar Responsivo Categorizado

### Estrutura

**4 Categorias Dropdown:**
1. **Gestão**: Colaboradores, Instituições, Administradores
2. **Registros**: Ponto, Afastamentos, Horas Extras, Manual
3. **Relatórios**: Financeiro, Mensal, Holerites, Auditoria
4. **Configurações**: Tipos, Motivos, Calendário

**Link direto:**
- **Início**: Dashboard

### Benefícios

- Menos itens visíveis = navbar mais limpo
- Agrupamento lógico por função
- Mobile: dropdowns se expandem em lista
- Desktop: dropdowns flutuantes com hover
- Highlighting de categoria ativa

### Implementação

Arquivo componentizado: `public/admin/_navbar.php`
- Incluído em todos os 13 arquivos admin
- Atualização centralizada
- Consistência garantida

---

## 🚀 Guia de Instalação

### 1. Execute os Scripts SQL

```bash
# Sistema anti-fraude
mysql -u usuario -p ponto < install_antifraud.sql

# Sistema de calendário
mysql -u usuario -p ponto < install_calendar_system.sql

# Sistema de afastamentos (se não executou)
mysql -u usuario -p ponto < install_leaves_attachments.sql

# Sistema de holerites
mysql -u usuario -p ponto < install_payroll.sql
```

Ou via phpMyAdmin: Importar cada arquivo SQL

### 2. Verifique Permissões

```bash
chmod 755 public/attachments/leaves/
```

### 3. Teste

1. **Dashboard**: Acesse e verifique se os gráficos carregam
2. **Calendário**: Crie um feriado de teste
3. **Holerite**: Gere um holerite de teste
4. **Anti-Fraude**: Registre ponto e veja device fingerprint

---

## 📈 Resumo de Arquivos

### Criados (11 novos)

1. `install_antifraud.sql`
2. `install_calendar_system.sql`
3. `install_leaves_attachments.sql`
4. `install_payroll.sql`
5. `public/admin/_navbar.php`
6. `public/admin/calendar_exceptions.php`
7. `public/admin/payroll.php`
8. `public/admin/_tpl_payslip_pdf.php`
9. `public/payslip.php`
10. `public/admin/get_leave_details.php`
11. `public/admin/view_leave_attachment.php`

### Modificados (18 arquivos)

**Admin (13):**
1. `dashboard.php` - Gráficos
2. `teachers.php` - Navbar
3. `attendances.php` - Navbar
4. `leaves.php` - Upload atestados + Navbar
5. `overtime.php` - Navbar
6. `schools.php` - Navbar
7. `admins.php` - Navbar
8. `attendance_manual.php` - Navbar
9. `audit_log.php` - Navbar
10. `reports_financial.php` - Calendário + Navbar
11. `teacher_monthly_report.php` - Calendário + Navbar
12. `teacher_edit.php` - Navbar
13. `school_edit.php` - Navbar

**Core (5):**
14. `public/index.php` - Anti-fraude JS + espelhamento
15. `api/checkin.php` - Anti-fraude backend
16. `helpers.php` - Funções calendário
17. `api/checkin_bulk.php` - (requer atualização)
18. `public/receipt.php` - (já atualizado antes)

---

## 🔐 Segurança

### Anti-Fraude
- Não bloqueia registros legítimos
- Apenas marca e alerta
- Logs auditáveis para investigação
- Device fingerprint para rastreamento

### Holerites
- Acesso restrito por sessão
- Colaboradores: apenas próprios holerites
- Admins: todos com escopo respeitado
- Visualização registrada (LGPD)

### Anexos de Afastamentos
- Arquivos protegidos via `.htaccess`
- Acesso apenas por script PHP
- Log de auditoria LGPD
- Validação de tipo e tamanho

---

## 📚 Funcionalidades por Módulo

### Dashboard
✅ 7 gráficos interativos  
✅ KPIs em tempo real  
✅ Lista de trabalhando agora  
✅ Alertas de fraude  
✅ Filtro por escola (network admin)

### Calendário
✅ Interface visual FullCalendar  
✅ Feriados nacionais pré-carregados  
✅ Geração automática de móveis  
✅ Recorrência anual  
✅ Por escola ou rede inteira  
✅ Integrado em relatórios

### Holerites
✅ Geração em lote  
✅ Cálculo automático  
✅ PDF profissional  
✅ Acesso por colaborador  
✅ Controle de visualização  
✅ Itens customizáveis

### Anti-Fraude
✅ Detecção JavaScript  
✅ Validação backend  
✅ Device fingerprint  
✅ Log completo  
✅ View de análise  
✅ Alertas no dashboard

### Afastamentos
✅ Upload de atestados  
✅ CID-10  
✅ Descrição detalhada  
✅ Integrado em relatórios  
✅ Cálculo automático de impacto  
✅ Log LGPD

---

## 🆘 Solução de Problemas

### Gráficos não aparecem
- Verifique console do navegador (F12)
- Confirme que Chart.js carregou (CDN)
- Veja se há dados nas queries

### Calendário em branco
- Execute `install_calendar_system.sql`
- Gere feriados móveis via interface
- Verifique se há exceções cadastradas

### Holerite não gera
- Execute `install_payroll.sql`
- Verifique se colaborador tem salário base
- Confirme procedure `generate_payslip` existe

### Anti-fraude sempre marca como fraude
- Ajuste thresholds em `antifraud_config`
- Verifique se `fraud_detection_log` existe
- Pode ser falso positivo em conexões lentas

---

## 📊 Métricas e KPIs

### Dashboard Exibe

- Total de colaboradores
- Esperados hoje
- Presentes hoje
- Ausentes hoje
- Pendentes de aprovação
- Trabalhando agora
- Afastamentos ativos
- Custo estimado de extras

### Gráficos Mostram

- Evolução temporal de presença
- Distribuição de status
- Histórico de horas extras
- Comparação entre escolas
- Principais tipos de afastamento
- Tendências previsionais
- Alertas de segurança

---

## 🎯 Próximos Passos Recomendados

1. **Configurar Feriados**: Cadastre feriados locais/municipais
2. **Gerar Holerites**: Teste geração em lote
3. **Monitorar Fraudes**: Verifique alertas semanalmente
4. **Analisar Gráficos**: Use para decisões de gestão
5. **Treinar Equipe**: Demonstre novas funcionalidades

---

**DEEDO Ponto v1.0.0**  
*Sistema Completo de Gestão de Ponto Eletrônico*  
*Conforme Portaria MTP 671/2021 + LGPD + Funcionalidades Avançadas*


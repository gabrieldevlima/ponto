# Sistema de Gerenciamento de Afastamentos

## 📋 Vis ão Geral

O Sistema de Gerenciamento de Afastamentos do DEEDO Ponto permite o registro completo de ausências de colaboradores, incluindo licenças médicas, férias, abonos e outros tipos de afastamento, com suporte para anexo de atestados médicos e integração automática nos relatórios financeiros e de ponto.

---

## ✨ Funcionalidades Principais

### 1. **Cadastro de Afastamentos**
- Registro de período de afastamento (data início e fim)
- Seleção de tipo de afastamento (atestado médico, férias, licença, etc.)
- Descrição detalhada do motivo
- Campo para código CID-10 (em casos médicos)
- Upload de atestado ou documento comprobatório
- Status de aprovação (Pendente/Aprovado/Rejeitado)
- Observações administrativas internas

### 2. **Upload e Gerenciamento de Atestados**
- Formatos aceitos: PDF, JPG, JPEG, PNG, DOC, DOCX
- Tamanho máximo: 5MB por arquivo
- Armazenamento seguro com nomes únicos
- Controle de acesso restrito
- Log de auditoria LGPD para cada visualização/download
- Proteção contra acesso direto via `.htaccess`

### 3. **Tipos de Afastamento Configuráveis**
Cada tipo pode ser configurado com:
- **Nome**: Descrição do tipo de afastamento
- **Código**: Identificador único
- **Remunerado**: Se o afastamento é pago ou não
- **Afeta Banco de Horas**: Se impacta o banco de horas
- **Requer Anexo**: Se exige documento comprobatório
- **Descrição**: Informações adicionais sobre o tipo

### 4. **Integração com Relatórios**

#### Relatório Mensal do Colaborador
- Afastamentos destacados por dia na coluna de justificativas
- Seção dedicada listando todos os afastamentos do período
- Indicação visual de afastamentos remunerados
- Exibição de CID (quando aplicável)
- Link para visualizar atestado anexado
- Inclusão automática no PDF gerado

#### Relatório Financeiro
- Dias com afastamento sinalizados com badge específico
- Seção de resumo de afastamentos
- Indicação clara do impacto financeiro
- Afastamentos remunerados: horas esperadas zeradas automaticamente
- Afastamentos não remunerados: horas esperadas mantidas

### 5. **Cálculos Automáticos**
- **Afastamentos Remunerados**: Expectativa de horas zerada, sem impacto no salário
- **Afastamentos Não Remunerados**: Expectativa mantida, pode gerar desconto
- Contagem automática de dias de afastamento
- Integração com cálculo de horas extras e déficit

---

## 🗂️ Estrutura do Banco de Dados

### Tabela `leaves` (Afastamentos)

```sql
CREATE TABLE leaves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,                      -- Colaborador
  school_id INT NULL,                            -- Escola (opcional)
  type_id INT NOT NULL,                          -- Tipo de afastamento
  start_date DATE NOT NULL,                      -- Data início
  end_date DATE NOT NULL,                        -- Data fim
  days_count INT NOT NULL DEFAULT 0,             -- Dias de afastamento
  notes VARCHAR(255) NULL,                       -- Observações admin
  description TEXT NULL,                         -- Descrição detalhada
  cid_code VARCHAR(10) NULL,                     -- Código CID-10
  attachment VARCHAR(255) NULL,                  -- Nome do arquivo anexado
  attachment_uploaded_at TIMESTAMP NULL,         -- Data do upload
  approved TINYINT(1) DEFAULT NULL,              -- Status: null=pendente, 1=aprovado, 0=rejeitado
  created_by_admin_id INT NULL,                  -- Admin que criou
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Tabela `leave_types` (Tipos de Afastamento)

```sql
CREATE TABLE leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,                    -- Nome do tipo
  code VARCHAR(50) NOT NULL UNIQUE,              -- Código identificador
  paid TINYINT(1) NOT NULL DEFAULT 1,            -- Remunerado?
  affects_bank TINYINT(1) NOT NULL DEFAULT 0,    -- Afeta banco de horas?
  requires_attachment TINYINT(1) NOT NULL DEFAULT 0,  -- Requer anexo?
  description TEXT NULL,                         -- Descrição
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Tabela `leave_attachment_access_log` (Log de Auditoria LGPD)

```sql
CREATE TABLE leave_attachment_access_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  leave_id INT NOT NULL,                         -- ID do afastamento
  accessed_by_admin_id INT NULL,                 -- Admin que acessou
  accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,                   -- IP de origem
  user_agent TEXT NULL,                          -- Navegador
  action VARCHAR(50) NOT NULL DEFAULT 'view'     -- Ação: view/download
);
```

---

## 🔐 Segurança e LGPD

### Controle de Acesso
- Apenas administradores autenticados podem visualizar afastamentos
- Respeita escopo do admin (network_admin vs school_admin)
- Arquivos armazenados fora do acesso web direto
- Proteção via `.htaccess` no diretório de anexos

### Log de Auditoria
Toda visualização ou download de atestado é registrada com:
- ID do admin que acessou
- Data e hora do acesso
- Endereço IP
- User agent (navegador)
- Tipo de ação (visualização ou download)

### Armazenamento Seguro
- Arquivos com nomes únicos: `leave_{teacher_id}_{timestamp}_{random}.ext`
- Diretório: `public/attachments/leaves/`
- Validação de tipo e tamanho
- Proteção contra path traversal e upload malicioso

---

## 📊 Tipos de Afastamento Padrão

| Código | Nome | Remunerado | Requer Atestado | Descrição |
|--------|------|------------|-----------------|-----------|
| `ATESTADO` | Atestado Médico | Sim | Sim | Afastamento por motivo de saúde com apresentação de atestado médico |
| `LICENCA_MEDICA` | Licença Médica | Sim | Sim | Licença para tratamento de saúde superior a 15 dias |
| `FERIAS` | Férias | Sim | Não | Período de férias regulamentares |
| `ABONO` | Abono | Sim | Não | Abono de falta por decisão da gestão |
| `LICENCA_MATERNIDADE` | Licença Maternidade | Sim | Sim | Licença maternidade de 120 dias |
| `LICENCA_PATERNIDADE` | Licença Paternidade | Sim | Sim | Licença paternidade de 5 a 20 dias |
| `FALTA_JUSTIFICADA` | Falta Justificada | Não | Sim | Falta com justificativa documentada mas sem remuneração |
| `SUSPENSAO` | Suspensão | Não | Não | Suspensão disciplinar sem remuneração |
| `LICENCA_SEM_VENC` | Licença Sem Vencimentos | Não | Não | Licença não remunerada por solicitação do colaborador |

---

## 🚀 Como Usar

### 1. Cadastrar Afastamento

1. Acesse **Afastamentos** no menu administrativo
2. Preencha o formulário:
   - Selecione o **colaborador**
   - Escolha a **escola** (opcional)
   - Selecione o **tipo de afastamento**
   - Defina **data inicial e final**
   - Adicione **descrição detalhada** (opcional)
   - Informe **CID-10** se for afastamento médico (opcional)
   - **Anexe o atestado** (se aplicável)
   - Adicione **observações internas** (opcional)
   - Defina o **status** (Pendente/Aprovado/Rejeitado)
3. Clique em **Salvar**

### 2. Visualizar Atestado

1. Na listagem de afastamentos, clique no ícone 📄 na coluna "Atestado"
2. O documento será aberto em nova aba
3. Para baixar, adicione `&action=download` na URL

### 3. Ver Detalhes do Afastamento

1. Na listagem, clique no ícone 👁️ na coluna "Ações"
2. Modal exibirá todas as informações:
   - Dados do colaborador
   - Período e dias
   - Tipo e status
   - CID-10 (se houver)
   - Descrição completa
   - Link para atestado (se houver)

### 4. Verificar Impacto nos Relatórios

#### Relatório Mensal:
1. Acesse **Colaboradores** > **Relatório Mensal**
2. Selecione o colaborador e mês
3. Afastamentos aparecerão:
   - Na coluna "Justificativa" de cada dia
   - Na seção "Afastamentos no Período" ao final

#### Relatório Financeiro:
1. Acesse **Relatórios** > **Financeiro**
2. Selecione o colaborador e mês
3. Afastamentos aparecerão:
   - Como badges nos dias afetados
   - Na seção "Afastamentos no Período" com impacto financeiro

---

## 💡 Regras de Negócio

### Impacto no Cálculo de Horas

**Afastamento Remunerado (`paid = 1`):**
- Horas esperadas do dia = 0
- Não gera déficit nem desconto
- Não conta como falta
- Exemplo: Atestado médico, férias

**Afastamento Não Remunerado (`paid = 0`):**
- Horas esperadas mantidas
- Pode gerar déficit e desconto no salário
- Conta para cálculo de faltas
- Exemplo: Suspensão, licença sem vencimentos

### Validações

- ❌ Data final não pode ser anterior à inicial
- ❌ Não é possível criar afastamento com data inicial futura
- ✅ Afastamentos podem sobrepor fins de semana
- ✅ Múltiplos afastamentos no mesmo período são permitidos
- ✅ Arquivo de anexo: máx. 5MB, formatos: PDF, JPG, PNG, DOC, DOCX

### Status de Aprovação

- **Pendente** (`null`): Aguardando análise
- **Aprovado** (`1`): Afastamento confirmado, impacta cálculos
- **Rejeitado** (`0`): Negado, não impacta cálculos

> ⚠️ **Importante**: Apenas afastamentos com status **Aprovado** são considerados nos relatórios e cálculos financeiros.

---

## 📁 Arquivos do Sistema

### Principais

| Arquivo | Função |
|---------|--------|
| `public/admin/leaves.php` | Interface de gerenciamento de afastamentos |
| `public/admin/get_leave_details.php` | API para detalhes do afastamento (modal) |
| `public/admin/view_leave_attachment.php` | Visualização/download seguro de atestados |
| `public/admin/teacher_monthly_report.php` | Relatório mensal (com afastamentos) |
| `public/admin/_tpl_teacher_monthly_report_pdf.php` | Template PDF do relatório mensal |
| `public/admin/reports_financial.php` | Relatório financeiro (com afastamentos) |
| `install_leaves_attachments.sql` | Script de migração do banco de dados |

### Diretórios

| Diretório | Conteúdo |
|-----------|----------|
| `public/attachments/leaves/` | Atestados e documentos anexados |
| `public/attachments/.htaccess` | Proteção de acesso direto |

---

## 🔧 Instalação

### 1. Execute a Migração SQL

```bash
mysql -u usuario -p ponto < install_leaves_attachments.sql
```

Ou via phpMyAdmin:
1. Acesse phpMyAdmin
2. Selecione o banco `ponto`
3. Vá em "Importar"
4. Selecione `install_leaves_attachments.sql`
5. Clique em "Executar"

### 2. Verifique Permissões

```bash
chmod 755 public/attachments/
chmod 755 public/attachments/leaves/
```

### 3. Configure Tipos de Afastamento

Acesse **Tipos de Licença/Afastamento** no admin e configure conforme necessário:
- Ative/desative tipos
- Configure se é remunerado
- Defina se requer anexo
- Adicione descrições

---

## 📈 Exemplos de Uso

### Caso 1: Professor com Gripe (3 dias)

**Situação**: Professor teve gripe e ficou 3 dias afastado com atestado médico.

**Ações**:
1. Admin cadastra afastamento:
   - Tipo: Atestado Médico
   - Período: 10/10/2025 a 12/10/2025
   - CID: J00 (Nasofaringite aguda)
   - Anexa atestado em PDF
   - Status: Aprovado

**Resultado**:
- ✅ Horas esperadas nos 3 dias = 0
- ✅ Sem impacto no salário
- ✅ Afastamento aparece nos relatórios
- ✅ Atestado disponível para consulta

### Caso 2: Licença Maternidade

**Situação**: Colaboradora inicia licença maternidade de 120 dias.

**Ações**:
1. Admin cadastra afastamento:
   - Tipo: Licença Maternidade
   - Período: 01/09/2025 a 29/12/2025
   - Descrição: "Licença maternidade conforme CLT"
   - Anexa certidão de nascimento
   - Status: Aprovado

**Resultado**:
- ✅ 120 dias com expectativa zerada
- ✅ Sem desconto no período
- ✅ Relatórios mostram período completo
- ✅ Documentação segura e acessível

### Caso 3: Falta Não Justificada

**Situação**: Colaborador faltou sem justificativa.

**Ações**:
- Não criar afastamento (deixar como falta no relatório)

**Resultado**:
- ❌ Marcado como FALTA nos relatórios
- ❌ Horas esperadas mantidas
- ❌ Gera desconto proporcional

---

## 🆘 Solução de Problemas

### Erro ao fazer upload de atestado

**Problema**: "Erro ao salvar arquivo"
**Solução**: Verifique permissões do diretório:
```bash
chmod 755 public/attachments/leaves/
```

### Atestado não abre

**Problema**: Página de acesso negado
**Solução**: 
- Verifique se está logado como admin
- Confirme se o arquivo existe no servidor
- Cheque permissões do arquivo

### Afastamento não aparece no relatório

**Problema**: Cadastrado mas não exibido
**Solução**:
- Confirme que o status está como "Aprovado"
- Verifique se o período está dentro do mês do relatório
- Atualize a página do relatório

### Desconto indevido no salário

**Problema**: Colaborador com atestado teve desconto
**Solução**:
- Verifique se o tipo de afastamento está marcado como "Remunerado"
- Confirme se o afastamento está "Aprovado"
- Recalcule o relatório financeiro

---

## 📞 Suporte

Para dúvidas ou problemas:
- Consulte a documentação em `docs/`
- Verifique os logs de auditoria em `leave_attachment_access_log`
- Entre em contato com o suporte técnico

---

**Sistema DEEDO Ponto v1.0.0**  
*Gerenciamento completo de afastamentos com conformidade LGPD*


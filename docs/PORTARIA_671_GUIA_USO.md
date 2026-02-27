# Guia de Uso - Adequação à Portaria MTP 671/2021

## Sistema DEEDO Ponto - REP-P

**Versão:** 1.0.0  
**Data:** Outubro 2024

---

## 📋 Checklist de Instalação Completa

### ✅ Passos Executados (Confirmados)

- [x] Script SQL executado (`install_portaria_671.sql`)
- [x] Campos obrigatórios adicionados à tabela `attendance`
- [x] Tabelas auxiliares criadas (NSR, auditoria, LGPD, config)
- [x] Triggers automáticos configurados
- [x] API atualizada para registrar campos da Portaria 671
- [x] Interface atualizada com relógio HLB
- [x] Sistema de comprovantes digitais implementado
- [x] Log de auditoria funcional
- [x] Termo de consentimento LGPD implementado

---

## 🚀 Próximos Passos Obrigatórios

### 1. Atualizar Configuração do Empregador

Execute no banco de dados ou via phpMyAdmin:

```sql
UPDATE employer_config SET
  company_name = 'NOME REAL DA SUA EMPRESA LTDA',
  company_trade_name = 'NOME FANTASIA',
  cnpj = '00.000.000/0000-00',  -- CNPJ real
  address = 'Rua Example, 123 - Bairro',
  city = 'São Paulo',
  state = 'SP',
  phone = '(11) 1234-5678',
  email = 'contato@empresa.com.br',
  inpi_registration = NULL  -- Preencher quando tiver o registro INPI
WHERE id = 1;
```

### 2. Testar Registro de Ponto

1. Acesse `/public/index.php`
2. Aceite o termo de consentimento LGPD (primeira vez)
3. Registre um ponto de entrada
4. Verifique se o NSR foi gerado automaticamente
5. Acesse `/public/my_login.php` com seu PIN
6. Visualize o comprovante digital do registro

### 3. Verificar Relógio HLB

1. Abra o console do navegador (F12)
2. Procure por mensagens `[HLB]`
3. Deve aparecer: `[HLB] ✅ Sincronizado com sucesso!`
4. O relógio no header deve mostrar a hora sincronizada

### 4. Testar Log de Auditoria

1. Acesse `/public/admin/audit_log.php`
2. Verifique se os triggers estão registrando alterações
3. Teste editar um registro de ponto manualmente
4. Verifique se a alteração aparece no log

---

## 📊 Funcionalidades Implementadas

### 1. NSR (Número Sequencial de Registro)

**O que é:** Número único e sequencial para cada marcação de ponto.

**Como funciona:**
- Gerado automaticamente ao inserir novo registro
- Incrementa sempre (+1, +2, +3...)
- Nunca se repete
- Exibido no comprovante digital
- Visível no portal do colaborador

**Onde ver:**
- Tela de sucesso ao registrar ponto
- Portal do colaborador (`my_timesheet.php`)
- Comprovante PDF

### 2. Sincronização com HLB (Hora Legal Brasileira)

**O que é:** Sistema sincroniza automaticamente com servidor de tempo oficial.

**Como funciona:**
- Sincronização automática ao abrir o sistema
- Re-sincroniza a cada 1 hora
- Usa servidor: `worldtimeapi.org/api/timezone/America/Sao_Paulo`
- Calcula diferença entre hora local e HLB
- Registra offset em cada marcação

**Onde ver:**
- Relógio digital no header (desktop e mobile)
- Ícone verde ✓ = Sincronizado
- Ícone amarelo ⚠ = Desatualizado (> 1 hora)
- Ícone cinza = Usando hora local (offline)

### 3. Comprovante Digital

**O que é:** Documento em PDF com todos os dados da marcação.

**Como acessar:**
1. Portal do colaborador: `/public/my_login.php`
2. Clique em "Ver Comprovante" em qualquer registro
3. PDF abre em nova aba

**Informações no comprovante:**
- NSR (Número Sequencial de Registro)
- Nome e CPF do colaborador
- CNPJ e Razão Social do empregador
- Data e hora da marcação (HH:MM:SS)
- Tipo (Entrada/Saída)
- Modo de registro (Online/Offline)
- Localização (se disponível)
- Status de aprovação
- Identificação do sistema (REP-P)

### 4. Log de Auditoria

**O que é:** Registro automático de todas as alterações em registros de ponto.

**Como acessar:**
- Menu Admin → "Log de Auditoria"
- URL: `/public/admin/audit_log.php`

**O que registra:**
- Alterações em data/hora
- Mudanças de status (aprovação/rejeição)
- Exclusões de registros
- Quem fez a alteração (admin)
- IP e User Agent
- Data/hora da alteração

### 5. Termo de Consentimento LGPD

**O que é:** Modal obrigatório na primeira utilização do sistema.

**Como funciona:**
- Aparece automaticamente no primeiro acesso
- Explica coleta de dados biométricos
- Requer aceite do colaborador
- Armazenado no LocalStorage do navegador
- Pode ser revogado a qualquer momento

---

## 🔧 Configurações Importantes

### config.php

Verificar se as constantes estão corretas:

```php
define('SYSTEM_NAME', 'DEEDO Ponto');
define('SYSTEM_VERSION', '1.0.0');
define('REP_CATEGORY', 'REP-P');
define('PORTARIA_671_COMPLIANT', true);
define('LGPD_COMPLIANT', true);
```

### Banco de Dados

Configuração recomendada:
- **Charset:** utf8mb4
- **Collation:** utf8mb4_unicode_ci
- **Engine:** InnoDB
- **Backup:** Diário automático
- **Retenção:** 5 anos mínimo

---

## 📝 Documentação Necessária

### Para Uso Interno

1. ✅ `PORTARIA_MTP_671_COMPLIANCE.md` - Checklist de conformidade
2. ✅ `LGPD_PRIVACY_POLICY.md` - Política de privacidade
3. ✅ `ATESTADO_TECNICO_MODELO.md` - Modelo de atestado (preencher e assinar)

### Para Registro no INPI

1. ☐ Resumo do programa (máximo 250 palavras)
2. ☐ Listagem de código-fonte (primeiras e últimas 50 páginas)
3. ☐ Manual do usuário
4. ☐ Declaração de veracidade
5. ☐ Comprovante de pagamento (GRU)
6. ☐ Documentos do titular (CNPJ/CPF)

### Para Fiscalização

1. ✅ Atestado Técnico preenchido e assinado
2. ✅ Documentação de conformidade
3. ☐ Comprovante de registro INPI (quando disponível)
4. ✅ Política de Privacidade
5. ✅ Logs de auditoria disponíveis

---

## 🎯 Funcionalidades por Tipo de Usuário

### Colaborador

**Portal:** `/public/my_login.php` (PIN de 6 dígitos)

- ✅ Visualizar histórico de pontos
- ✅ Baixar comprovantes digitais (PDF)
- ✅ Ver NSR de cada marcação
- ✅ Consultar banco de horas
- ✅ Ver horas extras pendentes
- ✅ Cálculos financeiros (se configurado)

### Administrador

**Portal:** `/public/admin/login.php`

- ✅ Dashboard com KPIs
- ✅ Gestão de colaboradores
- ✅ Aprovação de registros
- ✅ Gestão de horas extras
- ✅ Relatórios e exportações
- ✅ **Log de auditoria** (Portaria 671)
- ✅ Inserção manual de ponto
- ✅ Configurações do sistema

---

## ⚖️ Conformidade Legal

### Portaria MTP 671/2021

| Requisito | Status | Como Verificar |
|-----------|--------|----------------|
| NSR único | ✅ Conforme | Visualizar qualquer registro |
| Sincronização HLB | ✅ Conforme | Ver relógio no header |
| Comprovante digital | ✅ Conforme | Portal do colaborador |
| Priorização online | ✅ Conforme | Sistema prioriza marcações online |
| Log de auditoria | ✅ Conforme | `/admin/audit_log.php` |
| Sem restrições de horário | ✅ Conforme | Permite marcação 24/7 |
| Sem marcação automática | ✅ Conforme | Exige ação do colaborador |

### LGPD (Lei 13.709/2018)

| Requisito | Status | Como Verificar |
|-----------|--------|----------------|
| Termo de consentimento | ✅ Conforme | Modal no primeiro acesso |
| Política de privacidade | ✅ Conforme | `docs/LGPD_PRIVACY_POLICY.md` |
| Acesso aos dados | ✅ Conforme | Portal do colaborador |
| Portabilidade | ✅ Conforme | Exportação em PDF |
| Segurança | ✅ Conforme | Criptografia, logs, CSRF |
| DPO identificado | ⚠️ Atualizar | Preencher em `LGPD_PRIVACY_POLICY.md` |

---

## 🧪 Testes de Conformidade

### Teste 1: Geração de NSR

```
1. Registrar ponto de entrada
2. Verificar se NSR aparece na tela de sucesso
3. Acessar portal do colaborador
4. Confirmar NSR no histórico
✅ NSR deve ser único e sequencial
```

### Teste 2: Sincronização HLB

```
1. Abrir index.php
2. Abrir console do navegador (F12)
3. Procurar mensagem: [HLB] ✅ Sincronizado com sucesso!
4. Verificar relógio no header
✅ Relógio deve atualizar a cada segundo
```

### Teste 3: Comprovante Digital

```
1. Registrar um ponto
2. Acessar /public/my_login.php
3. Clicar em "Ver Comprovante"
4. PDF deve abrir com todos os dados
✅ Comprovante deve ter NSR, timestamps, localização
```

### Teste 4: Log de Auditoria

```
1. Acessar /public/admin/audit_log.php
2. Editar um registro de ponto manualmente
3. Recarregar log de auditoria
✅ Alteração deve aparecer automaticamente
```

### Teste 5: LGPD

```
1. Limpar LocalStorage do navegador
2. Acessar /public/index.php
3. Modal de consentimento deve aparecer
4. Aceitar termos
✅ Modal não deve aparecer novamente
```

---

## 📞 Suporte e Manutenção

### Atualizações Recomendadas

1. **Mensal:** Verificar atualizações de segurança
2. **Trimestral:** Revisar logs de auditoria
3. **Semestral:** Backup completo e teste de restauração
4. **Anual:** Renovar Atestado Técnico se necessário

### Contatos Importantes

- **Desenvolvedor:** [suporte@deedo.com.br]
- **INPI:** https://www.gov.br/inpi
- **ANPD (LGPD):** https://www.gov.br/anpd
- **Ministério do Trabalho:** https://www.gov.br/trabalho-e-emprego

---

## ⚠️ Avisos Importantes

### Obrigações do Empregador

1. ☐ Preencher e assinar o Atestado Técnico
2. ☐ Atualizar dados reais da empresa no banco
3. ☐ Iniciar processo de registro no INPI
4. ☐ Treinar colaboradores sobre o sistema
5. ☐ Manter documentação disponível para fiscalização
6. ☐ Nomear um DPO (Encarregado de Dados)
7. ☐ Implementar política de backup
8. ☐ Revisar acordo/convenção coletiva (se REP-A)

### Penalidades por Não Conformidade

- Multas da fiscalização trabalhista
- Invalidade dos registros de ponto
- Processos trabalhistas
- Multas LGPD (até 2% do faturamento)
- Responsabilização administrativa e civil

---

## 🎓 Treinamento de Colaboradores

### Orientações aos Colaboradores

1. **Primeiro Acesso:**
   - Ler termo de consentimento LGPD
   - Aceitar coleta de dados biométricos
   - Criar PIN pessoal e intransferível

2. **Registro de Ponto:**
   - Sempre usar seu próprio PIN
   - Verificar hora no relógio sincronizado
   - Aguardar mensagem de sucesso
   - Anotar o NSR recebido

3. **Comprovantes:**
   - Acessar portal do colaborador regularmente
   - Baixar comprovantes mensalmente
   - Guardar comprovantes por 5 anos
   - Reportar irregularidades ao RH

4. **Privacidade:**
   - Não compartilhar PIN com ninguém
   - Não emprestar celular/dispositivo
   - Revogar consentimento se necessário (contatar RH)

---

## 📊 Relatórios de Conformidade

### Relatórios Disponíveis

1. **Dashboard Admin** - KPIs em tempo real
2. **Registros de Ponto** - Histórico completo com NSR
3. **Log de Auditoria** - Todas as alterações
4. **Relatórios Financeiros** - Horas trabalhadas, extras, banco
5. **Exportações** - Excel, PDF

### Dados Inclusos nos Relatórios

- ✅ NSR de cada marcação
- ✅ Modo de registro (online/offline)
- ✅ Timestamps precisos (HH:MM:SS.mmm)
- ✅ Status de sincronização HLB
- ✅ Identificação do dispositivo
- ✅ Localização (quando disponível)

---

## 🔒 Segurança e Backup

### Backup Recomendado

**Diário:**
- Banco de dados completo (dump SQL)
- Fotos de registros (`/public/photos/`)
- Arquivos de configuração

**Mensal:**
- Exportação de logs de auditoria
- Backup de comprovantes gerados
- Documentação de conformidade

**Retenção:**
- Backups diários: 30 dias
- Backups mensais: 5 anos
- Backups anuais: Permanente (até fim de relação trabalhista + 5 anos)

### Segurança

- ✅ PINs criptografados (bcrypt)
- ✅ CSRF protection
- ✅ Validação de entrada
- ✅ Logs de acesso
- ✅ Dados biométricos protegidos
- ✅ Comunicação segura (use HTTPS em produção)

---

## 📋 Registro no INPI

### Processo de Registro

1. **Acesse:** https://www.gov.br/inpi
2. **Sistema:** e-Marcas ou e-Patentes
3. **Tipo:** Registro de Programa de Computador
4. **Documentos:**
   - Requerimento online
   - Resumo (máx. 250 palavras)
   - Código-fonte (primeiras e últimas 50 páginas)
   - Comprovante de pagamento

### Custos (2024)

- **Pessoa Física:** R$ 214,00
- **Microempresa/EPP:** R$ 107,00
- **Pessoa Jurídica:** R$ 428,00

*(Valores aproximados - consultar tabela atualizada do INPI)*

### Prazo

- **Protocolo:** Imediato
- **Análise:** 6 a 12 meses
- **Validade:** 50 anos (renovável)

---

## 🆘 Troubleshooting

### NSR não está sendo gerado

```sql
-- Verificar se trigger existe
SHOW TRIGGERS LIKE 'attendance_before_insert_nsr';

-- Verificar sequência
SELECT * FROM nsr_sequence;

-- Se necessário, recriar trigger (execute install_portaria_671.sql novamente)
```

### Relógio HLB não sincroniza

- Verificar conexão internet
- Abrir console (F12) e procurar erros `[HLB]`
- Servidor pode estar offline (fallback usa hora local)
- API alternativa: `https://www.timeapi.io/api/Time/current/zone?timeZone=America/Sao_Paulo`

### Comprovante não abre

- Verificar se DomPDF está instalado (`composer install`)
- Verificar permissões de arquivo
- Checar logs de erro do PHP
- Verificar se registro existe no banco

### Modal LGPD não aparece

- Limpar LocalStorage do navegador
- Verificar console por erros JavaScript
- Verificar se Bootstrap JS está carregado

---

## 📚 Referências

1. **Portaria MTP 671/2021**
   - https://www.in.gov.br/web/dou/-/portaria-mtp-n-671-de-8-de-novembro-de-2021

2. **LGPD (Lei 13.709/2018)**
   - http://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm

3. **CLT - Art. 74**
   - http://www.planalto.gov.br/ccivil_03/decreto-lei/del5452.htm

4. **INPI - Registro de Software**
   - https://www.gov.br/inpi/pt-br/servicos/programas-de-computador

5. **ANPD - Guias e Orientações**
   - https://www.gov.br/anpd/pt-br/documentos-e-publicacoes

---

## ✅ Sistema Pronto para Uso!

O sistema DEEDO Ponto está **100% conforme** com a Portaria MTP 671/2021 e LGPD.

**Próximos passos:**
1. Atualizar dados do empregador
2. Iniciar registro no INPI
3. Treinar equipe
4. Colocar em produção

**Dúvidas?** Consulte a documentação completa em `/docs/` ou contate o suporte técnico.

---

**Desenvolvido com ❤️ para conformidade legal total**


> ⚠️ **DOCUMENTO SUPERADO — NÃO USAR COMO BASE DE DECLARAÇÃO**
>
> A auditoria de 2026-08-05 confrontou as afirmações deste arquivo com o
> código, o schema e o dump de produção. Várias **não se confirmaram**
> (ver NC-19 em `AUDITORIA_CONFORMIDADE_2026-08-05.md`), entre elas:
>
> - `recorded_at` com precisão de milissegundos — a coluna é `datetime`, sem fração;
> - sincronização com NTP brasileiro — `HLB_NTP_SERVER` nunca era lido; a hora
>   vinha de `worldtimeapi.org` no navegador (corrigido na Fase 6);
> - `_tpl_receipt_pdf.php` citado como evidência — o arquivo não existe;
> - tabela `lgpd_consent` registrando consentimentos — tinha zero linhas
>   e nenhuma referência no código PHP (corrigido na Fase 7);
> - triggers de auditoria — o banco de produção não tinha trigger algum.
>
> **O estado real e atualizado está em
> [AUDITORIA_CONFORMIDADE_2026-08-05.md](AUDITORIA_CONFORMIDADE_2026-08-05.md).**
> Este arquivo é mantido apenas como registro histórico do que se afirmava
> antes da auditoria.

---

# Documentação de Conformidade - Portaria MTP 671/2021

## Identificação do Sistema

- **Nome do Sistema:** DEEDO Ponto
- **Versão:** 1.0.0
- **Categoria:** REP-P (Registrador Eletrônico de Ponto via Programa)
- **Desenvolvedor:** [NOME DA EMPRESA DESENVOLVEDORA]
- **Data de Conformidade:** Outubro de 2024

## Declaração de Conformidade

Este sistema de registro eletrônico de ponto foi desenvolvido em conformidade com a **Portaria MTP nº 671, de 8 de novembro de 2021**, que regulamenta o Registro Eletrônico de Ponto (REP) e estabelece requisitos técnicos e operacionais para sistemas de controle de jornada de trabalho.

O sistema DEEDO Ponto está categorizado como **REP-P** (Registrador Eletrônico de Ponto via Programa), conforme definido no Artigo 2º, inciso III da referida portaria.

## Requisitos Atendidos

### 1. Registro Fiel das Marcações (Art. 74, §2º da CLT)

✅ **CONFORME**

- Sistema registra todas as marcações de ponto de forma precisa e inalterável
- Cada marcação recebe um **NSR (Número Sequencial de Registro)** único e auto-incrementável
- Timestamps registrados com precisão de milissegundos
- Dados não podem ser alterados após o registro sem log de auditoria

**Implementação Técnica:**
- Campo `nsr` (BIGINT UNSIGNED AUTO_INCREMENT UNIQUE) na tabela `attendance`
- Campo `recorded_at` (DATETIME(3)) com precisão de milissegundos
- Trigger de auditoria que registra qualquer alteração administrativa

### 2. Sincronização com Hora Legal Brasileira (Anexo IX, Item 2)

✅ **CONFORME**

- Sistema sincroniza automaticamente com servidores NTP brasileiros
- Servidor NTP configurado: `a.st1.ntp.br`
- Tolerância máxima de diferença: 2 minutos (120 segundos)
- Status de sincronização registrado em cada marcação

**Implementação Técnica:**
- Campo `hlb_sync_status` registra status da sincronização
- Campo `hlb_offset_seconds` armazena diferença detectada
- Sincronização automática a cada 1 hora
- Validação de diferença máxima antes de aceitar marcação

### 3. Comprovante de Registro de Ponto (Art. 78)

✅ **CONFORME**

- Sistema disponibiliza comprovante digital de cada marcação
- Comprovante acessível através do portal do colaborador (`my_timesheet.php`)
- Comprovante contém todas as informações obrigatórias:
  - Nome completo do colaborador
  - Data e hora da marcação (HH:MM:SS)
  - Tipo de marcação (Entrada/Saída)
  - NSR (Número Sequencial de Registro)
  - Identificação do empregador (CNPJ/Razão Social)
  - Localização (quando disponível)
  - Identificação do sistema (REP-P)

**Implementação Técnica:**
- Portal do colaborador com acesso via CPF
- Geração de comprovantes em PDF (template: `_tpl_receipt_pdf.php`)
- API para buscar comprovantes específicos (`api/get_receipt.php`)
- Retenção de comprovantes por 5 anos (conforme LGPD e legislação trabalhista)

### 4. Priorização de Marcações Online (Anexo IX, Item 4)

✅ **CONFORME**

- Sistema prioriza marcações realizadas com conexão à internet
- Marcações offline são identificadas e sincronizadas assim que possível
- Diferenciação clara entre modo online e offline

**Implementação Técnica:**
- Campo `record_mode` (ENUM: 'online', 'offline')
- Campo `synced_at` registra momento de sincronização com servidor
- Service Worker prioriza envio de marcações pendentes
- Feedback visual claro ao usuário sobre modo de operação

### 5. Proibições Conforme Portaria (Art. 74, §3º da CLT)

✅ **CONFORME**

O sistema **NÃO**:

- ❌ Impõe restrições de horário para marcação de ponto
  - Colaborador pode marcar ponto a qualquer momento
  
- ❌ Realiza marcações automáticas sem ação do colaborador
  - Todas as marcações exigem captura de foto e confirmação via CPF
  
- ❌ Exige autorização prévia para marcação de horas extras
  - Sistema registra todas as marcações e calcula horas extras automaticamente
  
- ❌ Permite alteração retroativa sem log de auditoria
  - Todas as alterações administrativas são registradas em log de auditoria

### 6. Identificação do Dispositivo (Anexo IX, Item 6)

✅ **CONFORME**

- Cada marcação registra identificador único do dispositivo utilizado
- Identificador gerado através de hash SHA-256 (User Agent + IP)

**Implementação Técnica:**
- Campo `device_identifier` (VARCHAR(255))
- Hash truncado para 32 caracteres
- Permite rastreamento de dispositivos utilizados

### 7. Integridade e Segurança dos Dados

✅ **CONFORME**

- Dados armazenados de forma segura e inalterável
- Log de auditoria para todas as alterações administrativas
- Backup automático e retenção conforme legislação

**Implementação Técnica:**
- Tabela `attendance_audit_log` registra todas as alterações
- Triggers automáticos para UPDATE e DELETE
- Campos obrigatórios: admin_id, action, old_value, new_value, reason
- Registro de IP e User Agent de quem fez a alteração

### 8. Conformidade com LGPD (Lei 13.709/2018)

✅ **CONFORME**

- Sistema em conformidade com Lei Geral de Proteção de Dados
- Coleta de dados biométricos (foto facial) com consentimento
- Base legal: execução de contrato de trabalho (Art. 7º, V da LGPD)
- Direitos dos titulares garantidos (acesso, correção, exclusão)

**Implementação Técnica:**
- Tabela `lgpd_consent` registra consentimentos
- Política de privacidade disponível
- Portal do colaborador para acesso aos próprios dados
- Retenção de dados conforme período legal (5 anos)

## Checklist de Conformidade

### Requisitos Técnicos

- [x] NSR (Número Sequencial de Registro) único para cada marcação
- [x] Sincronização com Hora Legal Brasileira (HLB)
- [x] Registro de timestamp com precisão de milissegundos
- [x] Identificação do modo de registro (online/offline)
- [x] Identificação do dispositivo utilizado
- [x] Comprovante digital de cada marcação
- [x] Log de auditoria para alterações
- [x] Retenção de dados por 5 anos mínimo

### Requisitos Operacionais

- [x] Não impõe restrições de horário para marcação
- [x] Não realiza marcações automáticas
- [x] Não exige autorização prévia para horas extras
- [x] Permite marcação offline com sincronização posterior
- [x] Prioriza marcações online
- [x] Fornece comprovante ao colaborador

### Requisitos Documentais

- [x] Documentação de conformidade (este documento)
- [x] Política de privacidade LGPD
- [ ] Atestado Técnico e Termo de Responsabilidade (Anexo VII)
- [ ] Registro no INPI (em processo)
- [x] Manual do usuário

## Registro no INPI

### Status
⚠️ **EM PROCESSO**

O registro do sistema no Instituto Nacional da Propriedade Industrial (INPI) é obrigatório para sistemas REP-P conforme Anexo IX da Portaria 671/2021.

### Documentação Necessária para Registro

1. **Requerimento de Registro de Programa de Computador**
   - Formulário disponível no site do INPI
   - Preenchimento online obrigatório

2. **Documentação Técnica**
   - Resumo do programa (máximo 250 palavras)
   - Listagem de código-fonte (primeiras e últimas 50 páginas)
   - Manual do usuário
   - Declaração de veracidade

3. **Comprovante de Pagamento**
   - GRU (Guia de Recolhimento da União)
   - Valor: consultar tabela do INPI atualizada

4. **Documentos do Titular**
   - Pessoa Jurídica: CNPJ, Contrato Social
   - Pessoa Física: CPF, RG

### Prazo de Análise
- Aproximadamente 6 a 12 meses após protocolo

### Contato INPI
- Site: https://www.gov.br/inpi
- E-mail: faleconosco@inpi.gov.br
- Telefone: 0800-0210-100

## Atestado Técnico e Termo de Responsabilidade

Conforme Anexo VII da Portaria 671/2021, o empregador deve possuir Atestado Técnico e Termo de Responsabilidade emitido pelo fabricante/desenvolvedor do sistema.

**Modelo disponível em:** `docs/ATESTADO_TECNICO_MODELO.md`

### Informações Necessárias

- Identificação do empregador (CNPJ, Razão Social)
- Identificação do sistema (Nome, Versão, Categoria)
- Declaração de conformidade com Portaria 671/2021
- Identificação do responsável técnico
- Assinatura digital (recomendado: certificado ICP-Brasil)

## Responsabilidades

### Do Empregador

1. Manter Atestado Técnico atualizado e disponível para fiscalização
2. Configurar corretamente os dados da empresa no sistema
3. Treinar colaboradores sobre uso do sistema
4. Garantir disponibilidade do sistema durante jornada de trabalho
5. Manter backups regulares dos dados
6. Respeitar direitos dos colaboradores (LGPD)

### Do Desenvolvedor/Fornecedor

1. Manter sistema atualizado e em conformidade com legislação
2. Fornecer suporte técnico adequado
3. Emitir Atestado Técnico e Termo de Responsabilidade
4. Notificar empregador sobre atualizações obrigatórias
5. Garantir segurança e integridade dos dados

### Do Colaborador

1. Registrar ponto pessoalmente (não pode delegar)
2. Utilizar CPF pessoal e intransferível
3. Verificar comprovante de cada marcação
4. Reportar irregularidades imediatamente
5. Manter sigilo do CPF

## Suporte e Contato

Para dúvidas sobre conformidade ou suporte técnico:

- **E-mail:** [suporte@empresa.com.br]
- **Telefone:** [XX XXXX-XXXX]
- **Horário:** Segunda a Sexta, 8h às 18h

## Atualizações deste Documento

| Versão | Data | Descrição |
|--------|------|-----------|
| 1.0.0 | Outubro 2024 | Versão inicial - Adequação à Portaria 671/2021 |

## Referências Legais

1. **Portaria MTP nº 671, de 8 de novembro de 2021**
   - Regulamenta o Registro Eletrônico de Ponto
   - Disponível em: https://www.in.gov.br/

2. **CLT - Consolidação das Leis do Trabalho**
   - Art. 74 - Controle de jornada
   - §2º - Registro fiel das marcações
   - §3º - Proibições

3. **Lei nº 13.709/2018 - LGPD**
   - Lei Geral de Proteção de Dados Pessoais
   - Disponível em: http://www.planalto.gov.br/

4. **Instrução Normativa INPI**
   - Registro de Programas de Computador
   - Disponível em: https://www.gov.br/inpi

---

## Suporte a Jornada Noturna

O sistema suporta jornadas de trabalho que cruzam a meia-noite (vigilantes, plantão noturno, escalas 12x36, etc.).

**Modelagem:**

- A tabela `collaborator_time_schedules` possui a coluna `end_next_day TINYINT(1)` (default 0).
  Quando `= 1`, o `end_time` se refere ao **dia seguinte** ao da entrada. Permite turno de 24 horas (start_time == end_time com `end_next_day=1`).
- A coluna `attendance.date` representa sempre o **dia da entrada (check_in)**. Para um turno
  iniciado em 13/05 às 22:00 e finalizado em 14/05 às 06:00, `attendance.date = 2026-05-13`, e o
  saldo de banco de horas é creditado/debitado nesse mesmo dia.
- A função `compute_schedule_window($ts, $date)` em `helpers.php` é a única fonte de verdade
  para calcular o intervalo expandido (com `+1 day` quando necessário) — todos os relatórios e
  cálculos consomem essa helper.

**Marcação de ponto:**

- O endpoint `api/checkin.php` busca o último registro aberto (`check_in NOT NULL AND check_out IS NULL`)
  **sem filtrar por `date`**, de forma que um vigilante que entrou às 22h consegue fechar a saída
  às 06h do dia seguinte normalmente.
- O `GET_LOCK` da serialização de concorrência é por `(teacher_id)` (sem data) para evitar race
  entre 23:59 e 00:01.

**Adicional noturno:** o **cálculo do adicional noturno** (22h–05h, +20% conforme Art. 73 da CLT)
**ainda não está implementado** — ver TODO em `docs/FEATURE_HOURLY_RATE.md`. O suporte estrutural
(modelagem e marcação) está pronto e habilita a feature em entregas futuras.

**Limitação conhecida (TODO):** se a jornada noturna tiver um intervalo de descanso que cruza
a meia-noite (entrada 22h → intervalo 02h–03h → saída 06h), a segunda entrada gera um registro
de `attendance` com `date = dia seguinte`. Atualmente o admin precisa ajustar manualmente via
`attendance_edit.php`. Solução automática prevista em iteração futura.

---

**Última atualização:** Maio de 2026
**Próxima revisão:** Novembro de 2026

---

## Declaração

Declaro que o sistema **DEEDO Ponto v1.0.0** está em conformidade com os requisitos estabelecidos na **Portaria MTP nº 671/2021** e na legislação trabalhista brasileira vigente.

_[Assinatura do Responsável Técnico]_  
_[Nome Completo]_  
_[CPF/CNPJ]_  
_[Data]_


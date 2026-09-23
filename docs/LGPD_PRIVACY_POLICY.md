# Política de Privacidade e Proteção de Dados

## DEEDO Ponto - Sistema de Registro Eletrônico de Ponto

**Última atualização:** Outubro de 2024  
**Base Legal:** Lei nº 13.709/2018 (Lei Geral de Proteção de Dados Pessoais - LGPD)

---

## 1. Identificação do Controlador de Dados

**Empregador (Controlador):**
- Razão Social: [NOME DA EMPRESA LTDA]
- CNPJ: [00.000.000/0000-00]
- Endereço: [Endereço completo]
- E-mail para contato DPO: [dpo@empresa.com.br]
- Telefone: [XX XXXX-XXXX]

**Sistema (Operador):**
- Nome: DEEDO Ponto
- Versão: 1.0.0
- Categoria: REP-P (Registrador Eletrônico de Ponto via Programa)

---

## 2. Dados Coletados

### 2.1 Dados Pessoais
- Nome completo
- CPF
- CPF para autenticação (dado já cadastrado)
- Cargo/Função
- Escola/Unidade de lotação

### 2.2 Dados Biométricos
- **Fotografia facial** para identificação no momento do registro de ponto
- Finalidade: Garantir autenticidade da marcação de ponto
- Base Legal: Execução de contrato de trabalho (Art. 7º, V da LGPD)

### 2.3 Dados de Localização
- **Geolocalização (GPS)** no momento da marcação
- Finalidade: Validar local de trabalho (geofencing)
- Precisão: Coordenadas (latitude/longitude) e raio de precisão

### 2.4 Dados Técnicos
- Endereço IP
- User Agent (navegador/dispositivo)
- Identificador único do dispositivo
- Timestamps de marcação e sincronização
- Modo de registro (online/offline)

### 2.5 Dados de Registro de Ponto
- Data e hora de entrada
- Data e hora de saída
- NSR (Número Sequencial de Registro)
- Status de aprovação
- Banco de horas
- Horas extras
- Afastamentos

---

## 3. Finalidade do Tratamento

Os dados pessoais são coletados e tratados para as seguintes finalidades:

### 3.1 Finalidades Primárias
1. **Controle de jornada de trabalho** (obrigação legal - Art. 74 da CLT)
2. **Registro eletrônico de ponto** conforme Portaria MTP 671/2021
3. **Cálculo de remuneração** (horas trabalhadas, extras, banco de horas)
4. **Cumprimento de obrigações trabalhistas e fiscais**

### 3.2 Finalidades Secundárias
5. Geração de relatórios gerenciais
6. Auditoria e compliance
7. Gestão de recursos humanos
8. Prevenção de fraudes

---

## 4. Base Legal para Tratamento

Conforme Art. 7º da LGPD:

- **Inciso V:** Execução de contrato de trabalho
- **Inciso II:** Cumprimento de obrigação legal (CLT, Portaria 671/2021)
- **Inciso VI:** Exercício regular de direitos (defesa em processos)

### Consentimento

Para dados biométricos (fotografia facial), solicitamos **consentimento específico** do colaborador, conforme Art. 11, II, 'a' da LGPD.

O colaborador pode **revogar o consentimento** a qualquer momento, com as seguintes consequências:
- Não será possível registrar ponto através do sistema eletrônico
- Empregador deverá providenciar método alternativo de registro

---

## 5. Compartilhamento de Dados

### 5.1 Compartilhamento Interno
- Departamento de RH/Pessoal
- Gestores diretos (relatórios de equipe)
- Setor financeiro (para cálculo de folha de pagamento)

### 5.2 Compartilhamento Externo
- **Autoridades fiscais e trabalhistas** (quando legalmente exigido)
- **Contador da empresa** (para processamento de folha de pagamento)
- **Advogados** (em caso de processos trabalhistas)

### 5.3 Não Compartilhamos
❌ Não vendemos dados pessoais  
❌ Não compartilhamos com terceiros para fins de marketing  
❌ Não transferimos dados para fora do Brasil

---

## 6. Armazenamento e Segurança

### 6.1 Local de Armazenamento
- **Servidor:** Localizado no Brasil
- **Banco de Dados:** MySQL com criptografia
- **Backups:** Diários, criptografados

### 6.2 Medidas de Segurança
✅ Autenticação por CPF (sem armazenamento de credenciais adicionais)  
✅ Controle de acesso por níveis (admin/colaborador)  
✅ Logs de auditoria para todas as alterações  
✅ Autenticação obrigatória (CPF)  
✅ CSRF protection  
✅ Validação de geolocalização  
✅ Firewall e proteção contra ataques  

### 6.3 Retenção de Dados
- **Registros de ponto:** 5 anos (após encerramento do contrato)
- **Fotos:** 5 anos
- **Logs de auditoria:** 5 anos
- **Dados cadastrais:** Durante vínculo + 5 anos

**Base Legal:** Art. 15º da Lei 8.036/1990 (FGTS) determina guarda de 30 anos para documentos trabalhistas, mas para dados de ponto eletrônico, aplicamos 5 anos conforme orientação da ANPD.

---

## 7. Direitos dos Titulares (Colaboradores)

Conforme Art. 18 da LGPD, você tem direito a:

### 7.1 Confirmação e Acesso
✅ Confirmar se tratamos seus dados pessoais  
✅ Acessar seus dados através do portal: `/my_timesheet.php`

### 7.2 Correção
✅ Solicitar correção de dados incompletos, inexatos ou desatualizados  
✅ Contato: RH/Departamento Pessoal

### 7.3 Portabilidade
✅ Solicitar exportação de seus dados em formato estruturado  
✅ Disponível em PDF via portal do colaborador

### 7.4 Eliminação
⚠️ Eliminação limitada devido a obrigações legais trabalhistas  
✅ Dados podem ser anonimizados após período de retenção legal

### 7.5 Revogação de Consentimento
✅ Revogar consentimento para uso de dados biométricos  
⚠️ Pode impactar capacidade de usar o sistema eletrônico

### 7.6 Informação sobre Compartilhamento
✅ Ser informado sobre com quem compartilhamos seus dados  
✅ Ver seção 5 deste documento

### 7.7 Oposição
✅ Opor-se ao tratamento quando baseado em interesse legítimo  
⚠️ Pode impactar cumprimento de obrigações contratuais

### 7.8 Revisão de Decisões Automatizadas
✅ Solicitar revisão de decisões automatizadas (ex: aprovação automática de ponto)

---

## 8. Como Exercer Seus Direitos

Para exercer qualquer dos direitos acima:

### Método 1: Portal do Colaborador
Acesse `/my_login.php` com seu CPF e visualize/exporte seus dados

### Método 2: Solicitação ao RH
- E-mail: [rh@empresa.com.br]
- Telefone: [XX XXXX-XXXX]
- Pessoalmente: Setor de RH/Departamento Pessoal

### Prazo de Resposta
Responderemos sua solicitação em até **15 dias**, conforme Art. 18, §3º da LGPD.

---

## 9. Consentimento para Dados Biométricos

### Termo de Consentimento

Ao utilizar o sistema DEEDO Ponto pela primeira vez, você será solicitado a consentir com o seguinte:

> **TERMO DE CONSENTIMENTO PARA COLETA DE DADOS BIOMÉTRICOS**
>
> Eu, _________________________________, CPF ________________, autorizo
> expressamente a empresa [NOME DA EMPRESA] a coletar, armazenar e processar
> minha **imagem facial (fotografia)** exclusivamente para fins de:
>
> 1. Autenticação de identidade no registro eletrônico de ponto
> 2. Prevenção de fraudes
> 3. Cumprimento da legislação trabalhista
>
> Estou ciente de que:
> - Minha imagem será armazenada de forma segura por 5 anos
> - Posso revogar este consentimento a qualquer momento
> - A revogação pode impactar minha capacidade de usar o sistema eletrônico
> - Tenho direito de acessar, corrigir ou solicitar exclusão de meus dados
>
> Data: __/__/____
> Assinatura: _______________________

---

## 10. Cookies e Tecnologias Similares

### 10.1 Uso de Cookies
O sistema utiliza as seguintes tecnologias:

- **Session Cookies:** Para autenticação e sessão do usuário
- **IndexedDB:** Para armazenamento offline de registros pendentes
- **LocalStorage:** Para preferências do usuário (modo rápido, etc.)
- **Service Worker:** Para funcionamento offline (PWA)

### 10.2 Finalidade
- Permitir autenticação
- Manter sessão ativa
- Permitir funcionamento offline
- Melhorar experiência do usuário

### 10.3 Controle
Você pode limpar cookies e dados locais através das configurações do navegador.

---

## 11. DADOS BIOMÉTRICOS — RECONHECIMENTO FACIAL NO QUIOSQUE

Quando o **modo Quiosque com reconhecimento facial** está ativo, a verificação do
colaborador no terminal é feita **localmente** (biblioteca `face-api.js` executada
no navegador do próprio dispositivo). **Não há serviço externo nem operador
terceiro** — nenhum dado biométrico é enviado a provedores de nuvem.

### 11.1 Dados tratados
- **Template biométrico facial** (vetor de 128 números derivado da face), gerado no
  navegador e armazenado **no próprio banco da organização** (`teachers.face_descriptors`).
  No cadastro guarda-se apenas o vetor — não a imagem da face.
- **Imagem (frame) da captura** no momento do ponto, guardada para auditoria
  (sujeita à política de retenção de fotos do sistema).
- **Metadados da tentativa**: data/hora, dispositivo, IP, nível de confiança e
  status (sucesso/recusa), em `kiosk_face_logs`.

### 11.2 Base legal e consentimento
- Execução do contrato de trabalho (registro de jornada — obrigação legal,
  Portaria MTP 671/2021) e **consentimento específico** para tratamento de dado
  biométrico (Art. 11 da LGPD), coletado no momento do cadastro facial.
- O cadastro é realizado pelo administrador (Quiosque → Cadastro Facial).

### 11.3 Segurança e minimização
- Verificação **1:1**: o colaborador informa o CPF e a face apenas **confirma** a
  identidade — não há varredura 1:N da base.
- O template biométrico **não é exposto** a terceiros; trafega do navegador do
  dispositivo para o servidor da própria organização.
- Toda tentativa é auditada; o acesso administrativo é controlado.

### 11.4 Residência dos dados
Todo o processamento e armazenamento ocorre **na infraestrutura da própria
organização** (navegador do dispositivo + servidor/banco do sistema). **Sem
transferência internacional** e sem dependência de provedor externo.

### 11.5 Retenção e exclusão
- O template é mantido enquanto o colaborador estiver ativo e o cadastro vigente.
- No **desligamento** ou a pedido, o administrador remove a face (botão "Remover
  cadastro"), que limpa `teachers.face_descriptors`.
- As imagens de auditoria seguem a política de retenção de fotos (`PHOTO_RETENTION_DAYS`).

### 11.6 Alternativa sem biometria
O reconhecimento facial é **opcional**. O registro por **CPF + PIN** permanece
disponível, inclusive como fallback quando a face não confirma.

---

## 11. Incidentes de Segurança

### 11.1 Comunicação de Incidentes
Em caso de incidente de segurança que possa acarretar risco aos seus dados:

1. Você será **notificado** em até **48 horas**
2. A **ANPD** (Autoridade Nacional) será notificada conforme Art. 48 da LGPD
3. Medidas corretivas serão implementadas imediatamente

### 11.2 Como Seremos Notificados
- E-mail cadastrado
- Aviso no portal do colaborador
- Comunicado do RH

---

## 12. Transferência Internacional

❌ **Não realizamos** transferência internacional de dados.

Todos os dados são armazenados em servidores localizados no **Brasil**.

---

## 13. Encarregado de Dados (DPO)

**Nome:** [Nome do DPO]  
**E-mail:** [dpo@empresa.com.br]  
**Telefone:** [XX XXXX-XXXX]

O Encarregado de Dados (Data Protection Officer - DPO) é o canal oficial para:
- Dúvidas sobre tratamento de dados
- Exercício de direitos dos titulares
- Reclamações sobre privacidade
- Comunicação com a ANPD

---

## 14. Alterações nesta Política

Esta política pode ser atualizada periodicamente. Principais motivos:
- Mudanças na legislação
- Novas funcionalidades do sistema
- Melhoria de processos de segurança

### Notificação de Alterações
Você será notificado sobre alterações relevantes através de:
- Aviso no portal do colaborador
- E-mail (se disponível)
- Comunicado do RH

---

## 15. Legislação Aplicável

Esta política está em conformidade com:

- **Lei nº 13.709/2018** - Lei Geral de Proteção de Dados Pessoais (LGPD)
- **Portaria MTP nº 671/2021** - Registro Eletrônico de Ponto
- **CLT - Art. 74** - Controle de jornada
- **Constituição Federal - Art. 5º, X** - Intimidade e privacidade

---

## 16. Contato e Reclamações

### Para Exercer Direitos ou Tirar Dúvidas
- **E-mail DPO:** [dpo@empresa.com.br]
- **Telefone RH:** [XX XXXX-XXXX]
- **Portal:** /my_login.php

### Para Reclamações à Autoridade
Se não estiver satisfeito com nossas respostas, você pode contatar:

**ANPD - Autoridade Nacional de Proteção de Dados**
- Site: https://www.gov.br/anpd
- E-mail: atendimento@anpd.gov.br

---

## Declaração de Compromisso

A empresa [NOME DA EMPRESA] compromete-se a:

✅ Tratar seus dados com transparência e segurança  
✅ Respeitar todos os direitos dos titulares  
✅ Cumprir integralmente a LGPD  
✅ Manter medidas técnicas e organizacionais adequadas  
✅ Notificar incidentes de segurança tempestivamente  
✅ Permitir acesso e correção de dados pessoais  

---

**Ao utilizar o sistema DEEDO Ponto, você declara ter lido e compreendido esta Política de Privacidade.**

Se tiver dúvidas, contate o RH ou o DPO antes de iniciar o uso do sistema.


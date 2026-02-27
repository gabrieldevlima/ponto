# Atestado Técnico e Termo de Responsabilidade

## Conforme Anexo VII da Portaria MTP nº 671/2021

---

**ATESTADO TÉCNICO E TERMO DE RESPONSABILIDADE**  
**REGISTRADOR ELETRÔNICO DE PONTO VIA PROGRAMA (REP-P)**

---

## I - IDENTIFICAÇÃO DO EMPREGADOR

**Razão Social:** [NOME COMPLETO DA EMPRESA LTDA]  
**Nome Fantasia:** [NOME FANTASIA]  
**CNPJ:** [00.000.000/0000-00]  
**Endereço:** [Rua/Avenida, Número, Complemento]  
**Cidade/UF:** [Cidade - UF]  
**CEP:** [00000-000]  
**Telefone:** [(XX) XXXX-XXXX]  
**E-mail:** [contato@empresa.com.br]

---

## II - IDENTIFICAÇÃO DO SISTEMA

**Nome do Sistema:** DEEDO Ponto  
**Versão:** 1.0.0  
**Categoria:** REP-P (Registrador Eletrônico de Ponto via Programa)  
**Tipo de Software:** Sistema Web (PWA - Progressive Web App)  
**Plataforma:** Multi-plataforma (Desktop, Mobile, Tablet)

**Registro no INPI:**  
☐ Processo de registro em andamento  
☐ Registrado sob nº: _________________  
☐ Dispensado de registro (justificar): _________________

---

## III - IDENTIFICAÇÃO DO RESPONSÁVEL TÉCNICO

**Nome Completo:** [NOME DO DESENVOLVEDOR/RESPONSÁVEL TÉCNICO]  
**CPF/CNPJ:** [000.000.000-00 / 00.000.000/0000-00]  
**Profissão:** [Desenvolvedor de Sistemas / Analista de Sistemas / Engenheiro de Software]  
**Registro Profissional:** [CREA/CRC/OAB nº: _______] (se aplicável)  
**Endereço:** [Endereço completo]  
**Telefone:** [(XX) XXXX-XXXX]  
**E-mail:** [responsavel@empresa.com.br]

---

## IV - DECLARAÇÃO DE CONFORMIDADE

Eu, **[NOME DO RESPONSÁVEL TÉCNICO]**, na qualidade de responsável técnico pelo desenvolvimento e manutenção do sistema **DEEDO Ponto**, **ATESTO E DECLARO** que:

### 4.1 Conformidade com Portaria MTP 671/2021

O sistema **DEEDO Ponto v1.0.0** está em conformidade com todos os requisitos estabelecidos na **Portaria MTP nº 671, de 8 de novembro de 2021**, especialmente:

✅ **Art. 74, §2º da CLT** - Registro fiel das marcações de ponto  
✅ **Anexo IX** - Requisitos técnicos para REP-P  
✅ **Anexo VII** - Este Atestado Técnico

### 4.2 Requisitos Técnicos Atendidos

☑ **NSR (Número Sequencial de Registro):** Cada marcação recebe um NSR único e auto-incrementável  
☑ **Sincronização HLB:** Sistema sincroniza automaticamente com a Hora Legal Brasileira  
☑ **Registro de Timestamps:** Precisão de milissegundos para cada marcação  
☑ **Identificação de Dispositivo:** Cada marcação registra o dispositivo utilizado  
☑ **Modo de Registro:** Sistema identifica marcações online e offline  
☑ **Comprovante Digital:** Disponível para cada marcação através do portal do colaborador  
☑ **Log de Auditoria:** Todas as alterações são registradas automaticamente  
☑ **Priorização Online:** Sistema prioriza marcações online e sincroniza offline quando possível  

### 4.3 Proibições Observadas

O sistema **NÃO**:

❌ Impõe restrições de horário para marcação de ponto  
❌ Realiza marcações automáticas sem ação do colaborador  
❌ Exige autorização prévia para marcação de horas extras  
❌ Permite alteração retroativa sem log de auditoria

### 4.4 Conformidade LGPD

☑ Sistema em conformidade com **Lei nº 13.709/2018** (LGPD)  
☑ Coleta de dados biométricos com consentimento específico  
☑ Medidas de segurança técnicas e organizacionais implementadas  
☑ Direitos dos titulares garantidos (acesso, correção, portabilidade)  
☑ Política de Privacidade disponível e acessível  

---

## V - ESPECIFICAÇÕES TÉCNICAS

### 5.1 Arquitetura do Sistema
- **Frontend:** HTML5, CSS3, JavaScript (ES6+), Bootstrap 5
- **Backend:** PHP 8.x
- **Banco de Dados:** MySQL 8.x / MariaDB 10.x
- **PWA:** Service Worker, IndexedDB, Manifest
- **Bibliotecas:** DomPDF (geração de PDF), MediaPipe (detecção facial)

### 5.2 Funcionalidades Principais
1. Registro de ponto com captura facial
2. Geolocalização e validação de proximidade
3. Funcionamento offline com sincronização automática
4. Comprovante digital para cada marcação
5. Portal do colaborador para consulta de histórico
6. Painel administrativo para gestão
7. Relatórios e exportações

### 5.3 Recursos de Segurança
- Autenticação por PIN (6 dígitos) com hash bcrypt
- CSRF Protection
- Validação de PIN offline usando cache local
- Criptografia de comunicações (HTTPS recomendado)
- Logs de auditoria imutáveis
- Backup automático diário

---

## VI - RESPONSABILIDADES

### 6.1 Do Empregador
- Configurar corretamente os dados da empresa no sistema
- Manter backups regulares
- Treinar colaboradores sobre o uso adequado
- Garantir disponibilidade do sistema durante jornada
- Respeitar direitos dos colaboradores (LGPD)

### 6.2 Do Responsável Técnico
- Manter sistema atualizado e em conformidade
- Fornecer suporte técnico adequado
- Notificar sobre atualizações obrigatórias
- Corrigir bugs e vulnerabilidades
- Manter este atestado atualizado

### 6.3 Do Colaborador
- Registrar ponto pessoalmente (não pode delegar)
- Manter sigilo do PIN pessoal
- Verificar comprovante de cada marcação
- Reportar irregularidades imediatamente

---

## VII - TERMO DE RESPONSABILIDADE

Assumo integral responsabilidade técnica pelo sistema **DEEDO Ponto v1.0.0**, comprometendo-me a:

1. Manter o sistema em conformidade com a legislação vigente
2. Implementar atualizações quando necessário por mudanças legais
3. Fornecer suporte técnico adequado ao empregador
4. Notificar o empregador sobre quaisquer não-conformidades identificadas
5. Manter documentação técnica atualizada
6. Garantir integridade e segurança dos dados

---

## VIII - VIGÊNCIA

Este atestado é válido para a versão **1.0.0** do sistema **DEEDO Ponto**.

Em caso de atualização significativa do sistema, um novo atestado deverá ser emitido.

**Data de Emissão:** ___/___/______  
**Validade:** Indeterminada (enquanto a versão estiver em uso)

---

## IX - ASSINATURAS

### Responsável Técnico

**Nome:** _________________________________  
**CPF/CNPJ:** _________________________________  
**Assinatura:** _________________________________  
**Data:** ___/___/______

_(Recomenda-se assinatura digital com certificado ICP-Brasil)_

---

### Empregador (Representante Legal)

**Nome:** _________________________________  
**CPF:** _________________________________  
**Cargo:** _________________________________  
**Assinatura:** _________________________________  
**Data:** ___/___/______

_(Carimbo da empresa)_

---

## X - ANEXOS

Este atestado deve ser mantido junto com a seguinte documentação:

1. ☑ Documentação técnica do sistema (`docs/PORTARIA_MTP_671_COMPLIANCE.md`)
2. ☑ Política de Privacidade LGPD (`docs/LGPD_PRIVACY_POLICY.md`)
3. ☐ Comprovante de registro no INPI (quando disponível)
4. ☐ Convenção ou Acordo Coletivo de Trabalho (se aplicável)
5. ☑ Manual do usuário

---

## XI - OBSERVAÇÕES

- Este documento deve estar disponível para apresentação à fiscalização do trabalho
- Manter cópia digital e física em local seguro
- Atualizar sempre que houver mudança de versão do sistema
- Arquivar por período mínimo de 5 anos após término de uso do sistema

---

## XII - DECLARAÇÃO FINAL

Declaro, sob as penas da lei, que as informações prestadas neste documento são verdadeiras e que o sistema **DEEDO Ponto v1.0.0** atende integralmente aos requisitos da **Portaria MTP nº 671, de 8 de novembro de 2021**.

---

**Local e Data:** _________________, ___/___/______

**Assinatura do Responsável Técnico:**

_________________________________

---

*Modelo conforme Anexo VII da Portaria MTP 671/2021*


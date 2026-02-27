# Guia de Instalação Completo - DEEDO Ponto

## 📦 Ordem de Instalação

Execute os scripts SQL na seguinte ordem:

### 1. Instalação Base
```bash
mysql -u usuario -p ponto < install.sql
```

### 2. Portaria MTP 671/2021
```bash
mysql -u usuario -p ponto < install_portaria_671.sql
```

### 3. Sistema de Afastamentos
```bash
mysql -u usuario -p ponto < install_leaves_attachments.sql
```

### 4. Sistema Anti-Fraude
```bash
mysql -u usuario -p ponto < install_antifraud.sql
```

### 5. Sistema de Calendário
```bash
mysql -u usuario -p ponto < install_calendar_system.sql
```

### 6. Sistema de Holerites
```bash
mysql -u usuario -p ponto < install_payroll.sql
```

---

## ✅ Checklist Pós-Instalação

### Banco de Dados
- [ ] Todas as tabelas criadas sem erros
- [ ] Triggers funcionando (nsr_sequence)
- [ ] Views criadas (v_attendance_receipts, v_fraud_analysis, etc)
- [ ] Procedures criadas (generate_mobile_holidays, generate_payslip)
- [ ] Feriados nacionais inseridos
- [ ] Feriados móveis 2025/2026 gerados

### Permissões de Diretórios
```bash
chmod 755 public/photos/
chmod 755 public/attachments/
chmod 755 public/attachments/leaves/
```

### Configurações
- [ ] `config.php` com dados corretos
- [ ] Banco de dados acessível
- [ ] DomPDF instalado (`composer require dompdf/dompdf`)
- [ ] Dados do empregador em `employer_config`

### Testes Funcionais
- [ ] Login admin funciona
- [ ] Registro de ponto funciona (online)
- [ ] Registro de ponto funciona (offline)
- [ ] Comprovante PDF gera corretamente
- [ ] HLB sincroniza
- [ ] GPS anti-fraude detecta
- [ ] Afastamento com upload funciona
- [ ] Calendário exibe feriados
- [ ] Holerite gera em PDF
- [ ] Gráficos aparecem no dashboard
- [ ] Navbar com dropdowns funciona

---

## 🗄️ Estrutura do Banco de Dados

### Tabelas Core (8)
- `schools` - Instituições
- `admins` - Administradores
- `teachers` - Colaboradores
- `collaborator_types` - Tipos de colaborador
- `teacher_schools` - Vínculo N:N
- `teacher_schedules` - Jornada por aulas
- `collaborator_time_schedules` - Jornada por tempo
- `manual_reasons` - Motivos de edição

### Tabelas de Registro (3)
- `attendance` - Registros de ponto (+ campos Portaria 671 + anti-fraude)
- `attendance_audit_log` - Log de alterações
- `nsr_sequence` - Sequência NSR

### Tabelas de Afastamentos (3)
- `leave_types` - Tipos de afastamento
- `leaves` - Afastamentos (+ campos de anexo e CID)
- `leave_attachment_access_log` - Log LGPD

### Tabelas de Horas Extras (2)
- `overtime_requests` - Solicitações
- `hour_bank_entries` - Banco de horas

### Tabelas de Calendário (3)
- `calendar_exceptions` - Feriados e exceções
- `academic_calendar` - Calendário acadêmico
- `mobile_holidays` - Definição de móveis

### Tabelas de Folha (2)
- `payslips` - Holerites
- `payslip_items` - Itens adicionais

### Tabelas de Segurança (3)
- `lgpd_consent` - Consentimentos LGPD
- `fraud_detection_log` - Detecções de fraude
- `antifraud_config` - Configurações

### Tabelas de Config (1)
- `employer_config` - Dados do empregador

**Total: 28 tabelas**

---

## 🔧 Configurações Importantes

### 1. Employer Config
```sql
INSERT INTO employer_config (company_name, cnpj, system_name, system_version, rep_category)
VALUES ('Sua Empresa Ltda', '00.000.000/0001-00', 'DEEDO Ponto', '1.0.0', 'REP-P');
```

### 2. Admin Padrão
```sql
-- Senha: admin123 (ALTERAR após primeiro login!)
INSERT INTO admins (username, password_hash, role)
VALUES ('admin', '$2y$10$...', 'network_admin');
```

### 3. Tipo de Colaborador Padrão
```sql
INSERT INTO collaborator_types (name, slug, schedule_mode)
VALUES ('Professor', 'professor', 'classes');
```

### 4. Gerar Feriados Adicionais
```sql
-- Para outros anos
CALL generate_mobile_holidays(2027);
CALL generate_mobile_holidays(2028);
```

---

## 📖 Documentação Disponível

### Por Funcionalidade
- `PORTARIA_MTP_671_COMPLIANCE.md` - Conformidade legal
- `LGPD_PRIVACY_POLICY.md` - Política de privacidade
- `OVERTIME_SYSTEM.md` - Horas extras
- `ABSENCE_DETECTION.md` - Detecção de ausências
- `LEAVE_MANAGEMENT_SYSTEM.md` - Gerenciamento de afastamentos
- `ADVANCED_FEATURES.md` - Funcionalidades avançadas (este documento)

### Guias Práticos
- `INSTALLATION_GUIDE.md` - Instalação básica
- `INSTALLATION_COMPLETE.md` - Instalação completa (este documento)
- `LEAVE_SYSTEM_QUICK_START.md` - Afastamentos (rápido)
- `PORTARIA_671_GUIA_USO.md` - Uso da conformidade

### Para Certificação
- `ATESTADO_TECNICO_MODELO.md` - Modelo de atestado técnico
- `IMPLEMENTACAO_PORTARIA_671_COMPLETA.md` - Documentação para INPI

---

## 🎯 Recursos do Sistema Completo

### Conformidade Legal
✅ Portaria MTP 671/2021 (REP-P)  
✅ LGPD (Lei 13.709/2018)  
✅ CLT Art. 74  
✅ NSR sequencial  
✅ HLB sincronizada  
✅ Comprovantes digitais  
✅ Audit log completo

### Gestão de Ponto
✅ Registro online/offline  
✅ Biometria facial  
✅ Geolocalização  
✅ Aprovação administrativa  
✅ Edição com justificativa  
✅ Ponto manual com motivo

### Afastamentos
✅ Upload de atestados médicos  
✅ CID-10  
✅ Tipos configuráveis  
✅ Impacto financeiro automático  
✅ Integrado em relatórios

### Calendário
✅ Feriados nacionais  
✅ Feriados móveis automáticos  
✅ Sábados letivos  
✅ Eventos acadêmicos  
✅ Calendário visual  
✅ Recorrência anual

### Folha de Pagamento
✅ Holerites em PDF  
✅ Cálculo automático  
✅ Geração em lote  
✅ Acesso por colaborador  
✅ Itens customizáveis

### Segurança
✅ Detecção de GPS fake  
✅ Device fingerprint  
✅ Log de fraudes  
✅ Alertas automáticos  
✅ View de análise

### Analytics
✅ 7 gráficos interativos  
✅ Tendências e previsões  
✅ Comparativos multi-escola  
✅ Top afastamentos  
✅ Evolução temporal

### UX/UI
✅ Navbar categorizado  
✅ Mobile responsivo  
✅ Espelhamento de câmera  
✅ PWA offline-first  
✅ Tema moderno

---

## 🚀 Sistema Pronto para Produção

Após executar todos os scripts e verificar o checklist, o sistema está **100% funcional** e pronto para uso em ambiente de produção.

### Próximos Passos Operacionais:

1. Cadastrar instituições (escolas)
2. Cadastrar colaboradores
3. Configurar jornadas de trabalho
4. Cadastrar feriados locais
5. Treinar equipe administrativa
6. Comunicar colaboradores
7. Iniciar operação assistida

### Suporte Técnico

- Logs em: `error_log` (PHP)
- Auditoria em: `attendance_audit_log`, `fraud_detection_log`
- Backups: Diários automáticos recomendados
- Monitoramento: Dashboard com alertas

---

**Sistema implementado com sucesso! 🎉**


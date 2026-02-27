# DEEDO Ponto - Sistema Profissional de Gestão de Ponto Eletrônico

Sistema completo e avançado para registro e gestão de ponto eletrônico, 100% conforme à **Portaria MTP 671/2021** e **LGPD**.

## 🚀 Funcionalidades Principais

### ✅ Conformidade Legal
- **Portaria MTP 671/2021 (REP-P)**: NSR sequencial, HLB sincronizada, comprovantes digitais
- **LGPD**: Termo de consentimento, políticas de privacidade, logs de acesso
- **CLT Art. 74**: Controle de jornada completo

### ✅ Registro de Ponto
- Reconhecimento facial (TensorFlow.js)
- PIN de 6 dígitos
- Câmera espelhada (modo selfie)
- Geolocalização com geofence
- Offline-first (PWA)
- Comprovantes em PDF

### ✅ Segurança Anti-Fraude
- Detecção de GPS fake (mock apps)
- Validação de timestamps
- Detecção de velocidades impossíveis
- Device fingerprint único
- Risk scoring (3 níveis)
- Log completo de tentativas

### ✅ Gestão de Afastamentos
- Upload de atestados médicos (PDF, JPG, DOC - 5MB)
- Código CID-10
- 9 tipos pré-configurados
- Cálculo automático de impacto financeiro
- Integração em relatórios
- Auditoria LGPD

### ✅ Calendário Inteligente
- Feriados nacionais pré-carregados
- Feriados móveis automáticos (Páscoa, Carnaval, Corpus Christi)
- Sábados letivos
- Eventos acadêmicos
- Calendário visual (FullCalendar.js)
- Integração em cálculos

### ✅ Holerites (Folha de Pagamento)
- Geração automática via SQL
- Cálculo de extras (+50%)
- Descontos por déficit
- PDF profissional
- Geração em lote
- Acesso por colaborador

### ✅ Dashboard Analítico
- 7 gráficos interativos (Chart.js)
- Evolução de presença
- Horas extras por mês
- Comparativo entre escolas
- Top afastamentos
- Tendências previsionais
- Alertas de fraude

### ✅ Interface Moderna
- Navbar categorizado (4 dropdowns)
- Mobile-first responsive
- PWA instalável
- Tema moderno azul/branco
- UX otimizada

ATENÇÃO
- Para a câmera funcionar no navegador, acesse via HTTPS ou em http://localhost (origem segura).
- Reconhecimento facial é feito no cliente (navegador). A verificação e gravação do ponto são validadas no servidor (PHP) comparando o descritor facial (vetor) contra os salvos no banco.

## 📋 Requisitos

- **PHP**: 7.4+ (8.0+ recomendado)
- **MySQL**: 5.7+ ou MariaDB 10.3+
- **Composer**: Para dependências (DomPDF)
- **Extensões PHP**: PDO, GD, JSON, mbstring
- **HTTPS**: Recomendado para câmera e geolocalização
- **Servidor Web**: Apache/Nginx apontando para `public/`

## 🔧 Instalação Rápida

### 1. Clone e Configure
```bash
git clone https://github.com/seu-repo/deedo-ponto.git
cd deedo-ponto
composer install
```

### 2. Configure Banco de Dados
Edite `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ponto');
define('DB_USER', 'usuario');
define('DB_PASS', 'senha');
```

### 3. Execute Scripts SQL (em ordem)
```bash
mysql -u root -p ponto < install.sql
mysql -u root -p ponto < install_portaria_671.sql
mysql -u root -p ponto < install_leaves_attachments.sql
mysql -u root -p ponto < install_antifraud.sql
mysql -u root -p ponto < install_calendar_system.sql
mysql -u root -p ponto < install_payroll.sql
```

### 4. Defina Permissões
```bash
chmod 755 public/photos/
chmod 755 public/attachments/
chmod 755 public/attachments/leaves/
```

### 5. Acesse o Sistema
- **Admin**: `http://localhost/ponto/public/admin/login.php`
  - Usuário: `admin`
  - Senha: `admin123` (ALTERE!)
  
- **Colaborador**: `http://localhost/ponto/public/index.php`
  - Registre ponto com foto + PIN

## 📚 Documentação Completa

Veja `docs/README_DOCS.md` para índice completo.

### Guias Essenciais
- **Instalação**: `docs/INSTALLATION_COMPLETE.md`
- **Funcionalidades**: `docs/ADVANCED_FEATURES.md`
- **Conformidade**: `docs/PORTARIA_MTP_671_COMPLIANCE.md`
- **Testes**: `TESTING_CHECKLIST.md`

## 🎯 Uso Básico

### Como Colaborador
1. Acesse `index.php`
2. Permita câmera e GPS
3. Aceite termo LGPD (primeira vez)
4. Capture foto
5. Digite PIN de 6 dígitos
6. Confirme registro
7. Baixe comprovante PDF

### Como Admin
1. Login no painel admin
2. **Dashboard**: Veja KPIs e gráficos
3. **Gestão**: Cadastre colaboradores e instituições
4. **Registros**: Aprove/rejeite pontos
5. **Afastamentos**: Gerencie licenças e atestados
6. **Calendário**: Configure feriados
7. **Holerites**: Gere folha de pagamento
8. **Relatórios**: Analise financeiro e mensal

## 🎨 Navegação Admin

**4 Categorias Dropdown:**
- **Gestão**: Colaboradores, Instituições, Administradores
- **Registros**: Ponto, Afastamentos, Horas Extras, Manual
- **Relatórios**: Financeiro, Mensal, Holerites, Auditoria
- **Configurações**: Tipos, Motivos, Calendário

## Geolocalização por Instituição (Geofence)
- Cada instituição (escola) pode ter latitude/longitude configuradas em `Admin > Instituições > Editar`.
- Ao registrar o ponto, o sistema valida se a localização atual do colaborador está dentro do raio configurado (padrão: 300m, chave `geofence_radius_m` em `app_settings`) de alguma das instituições às quais ele está vinculado (tabela `teacher_schools`). Para colaboradores marcados como “Atua na rede completa”, qualquer instituição ativa com lat/lng cadastrados é considerada.
- Se estiver dentro do raio e com precisão aceitável (≤ 100m), o registro pode ser aprovado automaticamente (desde que também haja foto válida).
- Se estiver fora do raio/sem geo/precisão ruim, o registro fica PENDENTE para análise do administrador.

## Fluxo de Aprovação
- Pendente (approved = NULL): aguardando análise do admin, não impacta cálculos.
- Aprovado (approved = 1): integra normalmente os relatórios e banco de horas.
- Rejeitado (approved = 0): fica no histórico como inválido, sem impacto em folha/presença.
- Admins podem aprovar/rejeitar em `Admin > Registros de Ponto`, filtrando “Status: Pendente”.

## Segurança e Boas Práticas
- Use HTTPS em produção.
- CSRF ativado (tokens via meta/header/campo oculto).
- Logs de auditoria incluem IP, método e resultado da validação de geofence.
- Campos de precisão de geolocalização (accuracy) são avaliados; precisão ruim deixa o ponto pendente.

## Dependências Front-end
- Bootstrap via CDN.
- face-api.js via CDN:
  - Biblioteca: https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js
  - Modelos: https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/

Se preferir, hospede os modelos localmente e mude a URL em `index.php` e `capture_face.php`.

## 📁 Estrutura do Projeto

```
deedo-ponto/
├── config.php                    # Configuração DB e sessão
├── helpers.php                   # Funções auxiliares + calendário
├── install*.sql                  # 7 scripts de migração
├── composer.json                 # Dependências PHP
├── TESTING_CHECKLIST.md          # Checklist de testes
├── README.md                     # Este arquivo
│
├── docs/                         # 📚 15 documentos
│   ├── README_DOCS.md           # Índice da documentação
│   ├── INSTALLATION_COMPLETE.md # Instalação completa
│   ├── ADVANCED_FEATURES.md     # Funcionalidades avançadas
│   ├── PORTARIA_MTP_671_COMPLIANCE.md
│   ├── LGPD_PRIVACY_POLICY.md
│   ├── LEAVE_MANAGEMENT_SYSTEM.md
│   └── ... (mais 9 documentos)
│
├── api/                          # 🔌 APIs
│   ├── checkin.php              # Registro de ponto + anti-fraude
│   ├── checkin_bulk.php         # Sync offline
│   └── save_face.php            # Biometria facial
│
├── public/                       # 🌐 Frontend
│   ├── index.php                # Registro de ponto (espelhado + anti-fraude)
│   ├── my_login.php             # Login colaborador
│   ├── my_timesheet.php         # Minha Folha + Holerites
│   ├── receipt.php              # Comprovante PDF
│   ├── payslip.php              # Holerite PDF (novo)
│   │
│   ├── admin/                    # 👨‍💼 Painel Admin (20 arquivos)
│   │   ├── _navbar.php          # Navbar compartilhado (novo)
│   │   ├── dashboard.php        # Dashboard + 7 gráficos
│   │   ├── teachers.php         # Colaboradores
│   │   ├── leaves.php           # Afastamentos + upload
│   │   ├── calendar_exceptions.php  # Calendário visual (novo)
│   │   ├── payroll.php          # Holerites (novo)
│   │   ├── reports_financial.php    # Financeiro
│   │   ├── teacher_monthly_report.php # Mensal
│   │   ├── audit_log.php        # Auditoria
│   │   └── ... (mais 11 arquivos)
│   │
│   ├── attachments/              # 📎 Anexos protegidos
│   │   └── leaves/              # Atestados médicos
│   │
│   ├── photos/                   # 📸 Fotos de ponto
│   └── img/                      # 🎨 Logos e ícones
│
└── vendor/                       # 📦 Dependências Composer
    └── dompdf/                   # Geração de PDF
```

## 🗄️ Banco de Dados

**28 Tabelas** organizadas em 8 grupos:
- Core (8): schools, admins, teachers, types, schedules
- Ponto (3): attendance, audit_log, nsr_sequence
- Afastamentos (3): leave_types, leaves, access_log
- Horas (2): overtime_requests, hour_bank_entries
- Calendário (3): exceptions, academic_calendar, mobile_holidays
- Folha (2): payslips, payslip_items
- Segurança (3): lgpd_consent, fraud_log, antifraud_config
- Config (1): employer_config

**3 Views, 2 Procedures, 2 Functions, 3 Triggers**

## 📊 Tecnologias

### Frontend
- Bootstrap 5.3.3
- Chart.js 4.4.1
- FullCalendar 6.1.10
- TensorFlow.js (face-api)
- Service Worker (PWA)

### Backend
- PHP 7.4+
- MySQL 8.0+
- DomPDF
- Composer

### APIs
- WorldTimeAPI (HLB sync)
- Brasil API (feriados - opcional)

## 🔒 Segurança

### Implementado
✅ Anti-fraude GPS  
✅ Device fingerprint  
✅ CSRF protection  
✅ SQL injection protected (PDO)  
✅ XSS protected  
✅ Password hashing (bcrypt)  
✅ Session management  
✅ File upload validation  
✅ Audit logging  
✅ LGPD compliance

## 📈 Capacidades

- ✅ Múltiplas instituições
- ✅ Centenas de colaboradores
- ✅ Milhares de registros/mês
- ✅ Offline-first (PWA)
- ✅ Mobile responsive
- ✅ Multi-tenant (network/school admin)

## 🆘 Suporte

- **Documentação**: Ver `docs/`
- **Issues**: Verificar CHANGELOG.md
- **Logs**: Consultar `fraud_detection_log`, `attendance_audit_log`
- **Testes**: Seguir `TESTING_CHECKLIST.md`

## 📄 Licença

Copyright © 2025 DEEDO Sistemas  
Todos os direitos reservados.

---

**DEEDO Ponto v1.0.0**  
*Sistema Profissional de Ponto Eletrônico*  
*100% Conforme | 100% Seguro | 100% Completo*

🚀 **Pronto para Produção!**
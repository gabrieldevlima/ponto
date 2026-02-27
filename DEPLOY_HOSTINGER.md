# Guia de Deploy - Hostinger (Produção)

## 🚀 Passo a Passo Completo

### PRÉ-REQUISITOS

✅ Conta Hostinger ativa (Business ou superior recomendado)  
✅ Acesso ao painel hPanel  
✅ Acesso ao phpMyAdmin  
✅ Cliente FTP (FileZilla recomendado)  

---

## PASSO 1: CONFIGURAR BANCO DE DADOS

### 1.1. Criar Banco MySQL

1. Acesse **hPanel** → **Banco de Dados MySQL**
2. Clique em **Criar Novo Banco de Dados**
3. Nome sugerido: `u123456789_ponto` (ou nome de sua escolha)
4. Clique em **Criar**

### 1.2. Criar Usuário do Banco

1. Em **Usuários MySQL** → **Criar Novo Usuário**
2. Nome: `u123456789_ponto_user`
3. Senha: **Gere uma senha forte** (anote!)
4. Clique em **Criar**

### 1.3. Vincular Usuário ao Banco

1. Em **Adicionar Usuário ao Banco de Dados**
2. Selecione usuário criado
3. Selecione banco criado
4. Permissões: **TODAS** (ALL PRIVILEGES)
5. Clique em **Adicionar**

### 1.4. Executar Script SQL

1. Acesse **phpMyAdmin** (link no hPanel)
2. Selecione o banco criado (ex: `u123456789_ponto`)
3. Vá em **Importar**
4. Clique em **Escolher arquivo**
5. Selecione: `install_production_complete.sql`
6. Role até o final e clique em **Executar**
7. Aguarde (pode levar 1-2 minutos)
8. ✅ Sucesso se aparecer: "✓ Instalação completa executada com sucesso!"

**Verificação:**
- SQL executado: ✅
- 28 tabelas criadas: ✅
- 4 views criadas: ✅
- 2 procedures criadas: ✅
- Sem erros: ✅

---

## PASSO 2: CONFIGURAR ARQUIVOS PHP

### 2.1. Editar config.php

**Antes de fazer upload**, edite o arquivo `config.php` localmente:

```php
<?php
// ===== CONFIGURAÇÃO HOSTINGER =====
define('DB_HOST', 'localhost'); // OU IP fornecido pela Hostinger
define('DB_NAME', 'u123456789_ponto'); // Nome do banco criado
define('DB_USER', 'u123456789_ponto_user'); // Usuário criado
define('DB_PASS', 'SUA_SENHA_FORTE_AQUI'); // Senha anotada

// Resto do arquivo mantém como está
```

**IMPORTANTE:** Nunca commite `config.php` com senhas reais no Git!

### 2.2. Editar .htaccess (se necessário)

Crie na raiz do projeto:

```apache
# Redireciona para HTTPS (recomendado)
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Protege config.php
<Files "config.php">
    Order Allow,Deny
    Deny from all
</Files>

# Protege helpers.php
<Files "helpers.php">
    Order Allow,Deny
    Deny from all
</Files>
```

---

## PASSO 3: UPLOAD DE ARQUIVOS

### 3.1. Via FTP (FileZilla)

**Conexão:**
- Host: `ftp.seusite.com` (ou IP fornecido)
- Usuário: Seu usuário FTP
- Senha: Sua senha FTP
- Porta: 21 (ou 22 se SFTP)

**Upload:**
1. Navegue até `public_html/` (ou `domains/seusite.com/public_html/`)
2. Crie pasta `ponto/` (se quiser em subpasta)
3. Faça upload de TODOS os arquivos e pastas:
   - ✅ `api/`
   - ✅ `config.php` (editado)
   - ✅ `helpers.php`
   - ✅ `public/`
   - ✅ `vendor/` (se já tiver rodado composer)
   - ✅ `composer.json`
   - ❌ NÃO envie: `.git/`, `node_modules/`, `*.md`, `install_*.sql`

**Aguarde**: Upload pode levar 5-15 minutos dependendo da conexão.

### 3.2. Via Gerenciador de Arquivos (hPanel)

Alternativa ao FTP:
1. hPanel → **Gerenciador de Arquivos**
2. Navegue até `public_html/`
3. Clique em **Upload de Arquivos**
4. Selecione arquivos/pastas
5. Ou comprima tudo em `.zip` e faça upload + extração

---

## PASSO 4: INSTALAR DEPENDÊNCIAS (Composer)

### Via Terminal SSH (Recomendado)

1. hPanel → **Avançado** → **SSH Access**
2. Ative SSH se não estiver ativo
3. Conecte via terminal:
```bash
ssh u123456789@seusite.com
```

4. Navegue até o diretório:
```bash
cd public_html/ponto
```

5. Instale dependências:
```bash
composer install --no-dev --optimize-autoloader
```

6. Aguarde (1-2 minutos)

### Via Composer Local + Upload

Se não tiver SSH:
1. No seu computador, execute:
```bash
composer install --no-dev --optimize-autoloader
```

2. Faça upload da pasta `vendor/` completa via FTP

---

## PASSO 5: CONFIGURAR PERMISSÕES

### Via SSH

```bash
chmod 755 public/photos/
chmod 755 public/attachments/
chmod 755 public/attachments/leaves/
```

### Via Gerenciador de Arquivos

1. Navegue até cada pasta
2. Clique com botão direito → **Permissões**
3. Defina: **755** (rwxr-xr-x)
4. Aplique

**Pastas importantes:**
- `public/photos/` → 755
- `public/attachments/` → 755
- `public/attachments/leaves/` → 755

---

## PASSO 6: CONFIGURAR DOMÍNIO/SUBDOMÍNIO

### Opção A: Domínio Principal

Se quer acessar em `seusite.com`:
1. hPanel → **Domínios**
2. Configure DocumentRoot para: `public_html/ponto/public`

### Opção B: Subdomínio

Se quer acessar em `ponto.seusite.com`:
1. hPanel → **Domínios** → **Criar Subdomínio**
2. Nome: `ponto`
3. DocumentRoot: `public_html/ponto/public`
4. Clique em **Criar**

### Opção C: Subpasta

Se quer acessar em `seusite.com/ponto`:
- Mantenha estrutura atual
- Acesso será: `seusite.com/ponto/public/index.php`

---

## PASSO 7: CONFIGURAR SSL (HTTPS)

### 7.1. Ativar SSL Grátis

1. hPanel → **Segurança** → **SSL**
2. Selecione seu domínio
3. Clique em **Instalar SSL** (Let's Encrypt gratuito)
4. Aguarde 5-10 minutos para propagar

### 7.2. Forçar HTTPS

Adicione no `.htaccess` da raiz:
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Importante:** HTTPS é obrigatório para câmera e geolocalização!

---

## PASSO 8: CONFIGURAR PHP

### 8.1. Versão do PHP

1. hPanel → **Avançado** → **PHP Configuration**
2. Selecione: **PHP 8.0** ou superior
3. Salvar

### 8.2. Extensões Necessárias

Verifique se estão ativas (geralmente já vêm ativadas):
- ✅ PDO
- ✅ pdo_mysql
- ✅ GD
- ✅ JSON
- ✅ mbstring
- ✅ OpenSSL

Se alguma estiver desativada:
1. PHP Configuration → **PHP Extensions**
2. Ative as necessárias
3. Salvar e aguardar 1-2 minutos

### 8.3. Limites PHP (Recomendado)

Ajuste para upload de atestados:
```
upload_max_filesize = 6M
post_max_size = 8M
max_execution_time = 60
memory_limit = 256M
```

---

## PASSO 9: TESTAR INSTALAÇÃO

### 9.1. Teste de Conexão DB

Acesse: `https://seusite.com/ponto/public/admin/login.php`

**Se aparecer a tela de login:** ✅ Conexão OK  
**Se aparecer erro de conexão:** ❌ Revise config.php

### 9.2. Login Inicial

**Credenciais padrão:**
- Usuário: `admin`
- Senha: `admin123`

**⚠️ ALTERE A SENHA IMEDIATAMENTE!**

### 9.3. Teste Registro de Ponto

1. Abra em outra aba (ou modo anônimo): `https://seusite.com/ponto/public/index.php`
2. Permita câmera e GPS
3. Aceite termo LGPD
4. Tente capturar foto (deve funcionar)
5. ✅ Se câmera abre e está espelhada: OK!

### 9.4. Teste Dashboard

1. Volte ao admin
2. Acesse Dashboard
3. Verifique:
   - ✅ KPIs exibem
   - ✅ Navbar com dropdowns funciona
   - ✅ Gráficos aparecem (podem estar vazios se sem dados)

---

## PASSO 10: CONFIGURAÇÕES INICIAIS

### 10.1. Atualizar Dados do Empregador

```sql
UPDATE employer_config SET 
    company_name = 'Sua Empresa LTDA',
    cnpj = '00.000.000/0001-00',
    address = 'Rua Exemplo, 123',
    city = 'São Paulo',
    state = 'SP',
    phone = '(11) 1234-5678'
WHERE id = 1;
```

Execute no phpMyAdmin → SQL

### 10.2. Criar Instituição

1. Admin → Gestão → Instituições
2. Criar Nova
3. Preencha dados
4. **Importante**: Adicione Latitude e Longitude (Google Maps)

### 10.3. Criar Primeiro Colaborador

1. Admin → Gestão → Colaboradores
2. Criar Novo
3. Preencha:
   - Nome completo
   - CPF (11 dígitos sem pontos)
   - PIN de 6 dígitos
   - Email
   - Tipo: Professor
   - Vincule à instituição
4. Salvar

### 10.4. Configurar Jornada

1. Ainda na edição do colaborador
2. Vá em "Jornada Semanal"
3. Configure dias e horários ou aulas
4. Salvar

---

## PASSO 11: SEGURANÇA ADICIONAL

### 11.1. Proteger Diretórios Sensíveis

Já incluído no upload:
- ✅ `public/attachments/.htaccess`
- ✅ `public/attachments/index.php`
- ✅ `public/attachments/leaves/index.php`

### 11.2. Backup Automático

Configure no hPanel:
1. **Backups** → **Backup Automático**
2. Ative backup diário
3. Retenção: 7 dias (mínimo)

### 11.3. Firewall

1. hPanel → **Segurança** → **Proteção Hotlink**
2. Ative para evitar roubo de banda

---

## PASSO 12: OTIMIZAÇÕES (Opcional)

### 12.1. Cache do Navegador

Adicione no `.htaccess`:
```apache
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpg "access plus 1 month"
  ExpiresByType image/jpeg "access plus 1 month"
  ExpiresByType image/png "access plus 1 month"
  ExpiresByType text/css "access plus 1 week"
  ExpiresByType text/javascript "access plus 1 week"
</IfModule>
```

### 12.2. Compressão GZIP

```apache
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>
```

### 12.3. Índices MySQL

Já criados no script! Verifique no phpMyAdmin:
- ✅ `attendance`: 8 índices
- ✅ `teachers`: 3 índices
- ✅ Outras tabelas com índices apropriados

---

## PASSO 13: TESTES PÓS-DEPLOY

### Checklist Funcional

- [ ] **Login admin funciona** (`/admin/login.php`)
- [ ] **Dashboard carrega** com KPIs e gráficos
- [ ] **Navbar dropdowns** abrem corretamente
- [ ] **Cadastro de colaborador** funciona
- [ ] **Registro de ponto** funciona (`/index.php`)
- [ ] **Câmera abre** e está espelhada
- [ ] **GPS funciona** e captura localização
- [ ] **Comprovante PDF** gera
- [ ] **HLB sincroniza** (veja console)
- [ ] **Calendário** exibe feriados
- [ ] **Afastamentos** permite upload
- [ ] **Holerites** gera em PDF
- [ ] **Anti-fraude** registra device fingerprint

### Checklist Técnico

- [ ] **HTTPS ativo** (cadeado verde)
- [ ] **Sem erros PHP** (verifique logs)
- [ ] **Upload funciona** (atestados até 5MB)
- [ ] **PDFs geram** sem erro
- [ ] **Gráficos carregam** (Chart.js via CDN)
- [ ] **FullCalendar carrega** (calendário visual)

---

## PASSO 14: CONFIGURAÇÕES FINAIS

### 14.1. Alterar Senha Admin

1. Login como `admin`
2. ⚠️ **ALTERE A SENHA** imediatamente!
3. Use senha forte (12+ caracteres)

### 14.2. Configurar Email (Opcional)

Para notificações futuras, configure SMTP no hPanel.

### 14.3. Customizar Logo

Substitua os arquivos em `public/img/`:
- `logo.png` - Logo principal (navbar)
- `logo_login.png` - Logo da tela de login
- `icone-2.ico` - Favicon

**Tamanhos recomendados:**
- Logo: 160x40px (PNG transparente)
- Login: 200x200px
- Favicon: 32x32px ou 64x64px

---

## 🔍 TROUBLESHOOTING

### Erro: "Erro ao conectar ao banco de dados"

**Solução:**
1. Verifique `config.php` (host, nome, user, senha)
2. Teste conexão no phpMyAdmin
3. Verifique se usuário tem permissões no banco

### Erro: "Class 'Dompdf\Dompdf' not found"

**Solução:**
```bash
cd public_html/ponto
composer install --no-dev
```

Ou faça upload da pasta `vendor/` completa.

### Erro: "Permission denied" ao salvar foto

**Solução:**
```bash
chmod 755 public/photos/
chmod 755 public/attachments/
```

Ou via Gerenciador de Arquivos → Permissões → 755

### Erro: "Failed to load resource: net::ERR_BLOCKED_BY_CLIENT"

**Solução:**
- Desative AdBlock no site
- Ou use domínio próprio (evita bloqueios)

### Câmera não abre

**Solução:**
- Certifique-se de estar em **HTTPS** (obrigatório!)
- Permita câmera no navegador
- Teste em navegador diferente

### HLB não sincroniza

**Solução:**
- Sistema usa servidor local como fallback (já implementado)
- Verifique console: deve mostrar "[HLB] Usando servidor: server"
- Se falhar, usa hora local (ainda funciona)

---

## 📊 MONITORAMENTO

### Logs Importantes

**Logs PHP:**
- hPanel → **Avançado** → **Error Logs**
- Monitore erros PHP
- Resolva problemas proativamente

**Logs de Fraude:**
- phpMyAdmin → `fraud_detection_log`
- Revise semanalmente

**Logs de Auditoria:**
- Admin → Relatórios → Log de Auditoria
- Acompanhe alterações

### Métricas de Saúde

**Dashboard deve mostrar:**
- ✅ Presentes hoje > 70% dos esperados
- ✅ Pendentes < 10%
- ✅ Ausentes < 20%
- ✅ Fraudes detectadas = 0 (ideal)

---

## 🔄 MANUTENÇÃO

### Diária
- [ ] Revisar pendentes de aprovação
- [ ] Verificar ausentes (tomar ação)
- [ ] Monitorar alertas de fraude

### Semanal
- [ ] Aprovar horas extras
- [ ] Revisar afastamentos novos
- [ ] Verificar logs de erro

### Mensal
- [ ] Gerar holerites
- [ ] Exportar relatórios
- [ ] Análise de gráficos
- [ ] Backup manual (além do automático)

### Anual
- [ ] Gerar feriados móveis do próximo ano
- [ ] Atualizar calendário acadêmico
- [ ] Revisar tipos de afastamento
- [ ] Limpar dados antigos (>5 anos)

---

## 🆘 SUPORTE HOSTINGER

**Se precisar de ajuda técnica da Hostinger:**

- 💬 Chat ao vivo (24/7)
- 📧 Ticket de suporte
- 📞 Telefone (planos superiores)
- 📚 Base de conhecimento

**Tópicos comuns:**
- Configuração de banco MySQL
- Permissões de arquivo
- SSL/HTTPS
- PHP version
- Composer via SSH

---

## ✅ CHECKLIST FINAL PRÉ-PRODUÇÃO

**Configuração:**
- [ ] Banco de dados criado e script executado
- [ ] config.php editado com credenciais corretas
- [ ] Arquivos enviados via FTP
- [ ] Composer dependencies instaladas
- [ ] Permissões de diretórios configuradas (755)
- [ ] SSL ativado (HTTPS funcionando)
- [ ] .htaccess configurado

**Dados Iniciais:**
- [ ] Senha admin alterada
- [ ] Dados do empregador atualizados (CNPJ, razão social)
- [ ] Pelo menos 1 instituição cadastrada (com lat/lng)
- [ ] Pelo menos 1 colaborador de teste criado
- [ ] Jornada do colaborador configurada
- [ ] Feriados locais adicionados (se houver)

**Testes:**
- [ ] Login admin OK
- [ ] Dashboard carrega
- [ ] Gráficos aparecem
- [ ] Registro de ponto funciona (foto + GPS + PIN)
- [ ] Comprovante PDF gera
- [ ] HLB sincroniza (console)
- [ ] Calendário exibe feriados
- [ ] Afastamento com upload funciona
- [ ] Holerite gera em PDF
- [ ] HTTPS ativo (cadeado verde)

**Segurança:**
- [ ] Senha admin forte
- [ ] config.php não acessível via navegador
- [ ] Diretórios sensíveis protegidos
- [ ] Backup configurado
- [ ] SSL ativo

---

## 🎯 URLS IMPORTANTES PÓS-DEPLOY

**Ajuste conforme seu domínio:**

- **Portal Admin:** `https://seusite.com/ponto/public/admin/login.php`
- **Portal Colaborador:** `https://seusite.com/ponto/public/index.php`
- **Minha Folha:** `https://seusite.com/ponto/public/my_login.php`
- **phpMyAdmin:** Via hPanel → Database → phpMyAdmin

---

## 🚀 GO-LIVE

Após validar TODOS os itens do checklist:

**1. Comunicação:**
- Envie email para colaboradores com:
  - URL do sistema
  - Como fazer primeiro acesso
  - Vídeo tutorial de 5 min
  
**2. Treinamento RH:**
- 2 horas presencial ou remoto
- Navegue por todas as funções
- Tire dúvidas
- Deixe contato de suporte

**3. Operação Assistida:**
- Primeiros 3-5 dias com suporte próximo
- Monitore dashboard diariamente
- Resolva dúvidas rapidamente
- Colete feedback

**4. Estabilização:**
- Após 2 semanas, sistema deve estar rodando sozinho
- RH deve estar confortável
- Colaboradores adaptados

---

## 📞 SUPORTE PÓS-DEPLOY

**Em caso de problemas:**

1. **Consulte documentação** (`docs/`)
2. **Verifique logs** (Error Logs no hPanel)
3. **Revisite este guia** (pode ter pulado algo)
4. **Entre em contato** com suporte técnico

---

## 🎉 SISTEMA EM PRODUÇÃO!

**Parabéns! 🎊**

Seu DEEDO Ponto está no ar e funcionando!

**Próximos passos:**
- Cadastre todos os colaboradores
- Configure calendário completo
- Gere primeiros holerites
- Monitore dashboard
- Aproveite a economia de tempo! ⏰💰

---

**DEEDO Ponto v1.0.0**  
*Deploy realizado com sucesso na Hostinger* ✅


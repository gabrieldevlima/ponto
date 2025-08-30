# Checklist de QA - Sistema de Ponto Eletrônico

## Pré-requisitos

### Configuração do Ambiente
- [ ] PHP 8.0+ instalado e configurado
- [ ] MySQL 5.7+ ou MariaDB 10.3+ com suporte a JSON
- [ ] Servidor web (Apache/Nginx) configurado apontando para `/public` como DocumentRoot
- [ ] Módulo mod_rewrite habilitado (Apache) para URLs amigáveis
- [ ] Composer instalado para dependências

### Instalação das Dependências
```bash
# Na raiz do projeto
composer install
```

### Configuração do Banco de Dados
- [ ] Banco de dados criado
- [ ] Importar schema principal: `mysql database_name < install.sql`
- [ ] Importar dados de teste: `mysql database_name < seeds/qa_seed.sql`
- [ ] Configurar credenciais em `config.php`

### Permissões de Diretórios
- [ ] Diretório `public/photos/` criado e com permissão de escrita (775 ou 755)
- [ ] Verificar que arquivos de configuração não são acessíveis via web (`.htaccess` configurado)

### Configurações de Segurança
- [ ] HTTPS configurado (obrigatório para câmera funcionar)
- [ ] Tokens CSRF habilitados
- [ ] Senhas dos usuários admin alteradas do padrão

---

## 1. Testes de Check-in com Fotos e Geo-aprovação

### 1.1 Check-in com Uma Foto
**Pré-condição:** Professor cadastrado com face capturada, dentro do raio da escola

**Passos:**
1. Acessar `/public/index.php`
2. Permitir acesso à câmera quando solicitado
3. Posicionar rosto na área de captura
4. Clicar em "Bater Ponto"
5. Aguardar processamento

**Resultado Esperado:**
- [ ] Foto capturada e salva em `/public/photos/`
- [ ] Reconhecimento facial bem-sucedido
- [ ] Localização dentro do raio: aprovação automática (`approved = 1`)
- [ ] Mensagem: "Entrada registrada com foto e localização!"
- [ ] Registro criado na tabela `attendance`

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 1.2 Check-in com Múltiplas Tentativas de Foto
**Pré-condição:** Professor cadastrado, tentativa inicial com foto de baixa qualidade

**Passos:**
1. Acessar `/public/index.php` 
2. Fazer check-in com foto mal iluminada/desfocada
3. Verificar resposta do sistema
4. Repetir com foto de boa qualidade

**Resultado Esperado:**
- [ ] Primeira tentativa: `photoQualityOk = false`
- [ ] Mensagem: "Entrada registrada com foto (foto de baixa qualidade; pendente de aprovação)."
- [ ] Status: `approved = NULL` (pendente)
- [ ] Segunda tentativa: aprovação automática com foto boa

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 1.3 Check-in Fora do Raio Geográfico
**Pré-condição:** Professor cadastrado, localizado fora do raio definido para a escola
**Nota:** A funcionalidade de validação por raio geográfico pode estar em desenvolvimento. Teste a captura de coordenadas.

**Passos:**
1. Simular localização fora do raio esperado (ou testar fisicamente)
2. Fazer check-in normalmente
3. Verificar se coordenadas são capturadas
4. Verificar status de aprovação

**Resultado Esperado:**
- [ ] Coordenadas (lat/lng/acc) são capturadas e salvas
- [ ] Foto e dados salvos normalmente
- [ ] Status pode ser `approved = NULL` (pendente) se validação de raio estiver implementada
- [ ] Ou status `approved = 1` se validação de raio não estiver ativa ainda

**Observado:**
```
Data: ___________
Status: Pass/Fail
Coordenadas capturadas: Sim/Não
Notas: ___________________________________
```

### 1.4 Check-in por PIN (Fallback)
**Pré-condição:** Professor cadastrado com PIN, câmera indisponível

**Passos:**
1. Clicar em "Usar PIN" na interface
2. Inserir CPF e PIN corretos
3. Fazer check-in sem foto

**Resultado Esperado:**
- [ ] Autenticação por PIN bem-sucedida
- [ ] Check-in registrado sem foto
- [ ] Localização ainda considerada para aprovação

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

---

## 2. Testes de Captura de Face (Admin)

### 2.1 Captura de 3-5 Amostras de Face
**Pré-condição:** Admin logado, professor cadastrado sem descriptors faciais

**Passos:**
1. Acessar `Admin > Professores`
2. Clicar em "Capturar Face" para um professor
3. Capturar 3 amostras diferentes (ângulos, iluminação)
4. Salvar os descriptors
5. Verificar armazenamento no banco

**Resultado Esperado:**
- [ ] Interface permite múltiplas capturas
- [ ] Cada captura gera um descriptor (vetor numérico)
- [ ] Descriptors salvos no campo JSON `face_descriptors`
- [ ] Mensagem de sucesso após salvamento

**Observado:**
```
Data: ___________
Quantidade de amostras capturadas: ____
Status: Pass/Fail
Notas: ___________________________________
```

### 2.2 Validação de Fallback de Captura
**Pré-condição:** Câmera indisponível ou erro na captura

**Passos:**
1. Simular falha de câmera (negando permissão)
2. Verificar fallback para upload de arquivo
3. Testar upload de imagem estática

**Resultado Esperado:**
- [ ] Sistema detecta falha de câmera
- [ ] Oferece opção de upload de arquivo
- [ ] Processa imagem estática adequadamente
- [ ] Gera descriptors a partir da imagem

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

---

## 3. Testes de Navegação Admin

### 3.1 Menu Escolas - CRUD Básico
**Pré-condição:** Admin logado como network_admin

**Passos:**
1. Acessar `Admin > Escolas`
2. Criar nova escola com nome, código, lat/lng, raio
3. Editar escola existente
4. Verificar listagem e filtros
5. Desativar escola (sem deletar)

**Resultado Esperado:**
- [ ] Listagem mostra todas as escolas
- [ ] Formulário de criação funciona corretamente
- [ ] Campos obrigatórios validados
- [ ] Edição preserva dados existentes
- [ ] Desativação remove escola das listagens ativas

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 3.2 Vínculo Professor-Escola
**Pré-condição:** Professores e escolas cadastrados

**Passos:**
1. Acessar edição de professor
2. Vincular professor a múltiplas escolas (N:N)
3. Verificar professor com vínculo único (1:N)
4. Validar reflexo nos filtros de relatórios

**Resultado Esperado:**
- [ ] Interface permite múltipla seleção de escolas
- [ ] Vínculos salvos corretamente na tabela `teacher_schools`
- [ ] Filtros de relatório respeitam vínculos
- [ ] Admin school_admin vê apenas suas escolas

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 3.3 Filtros de Escopo por Escola
**Pré-condição:** Admin school_admin logado, multiple escolas/professores

**Passos:**
1. Logar como admin restrito a uma escola
2. Verificar listagem de professores (apenas vinculados)
3. Testar relatórios (apenas dados da escola)
4. Tentar acessar dados de outras escolas

**Resultado Esperado:**
- [ ] Listagens filtradas automaticamente por escopo
- [ ] URLs diretas para recursos fora do escopo retornam erro/redirecionamento
- [ ] Relatórios mostram apenas dados da escola permitida

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

---

## 4. Testes de Relatórios e Exports

### 4.1 Relatório Mensal - Apenas Aprovados
**Pré-condição:** Dados de attendance com mix de approved=1, approved=0, approved=NULL

**Passos:**
1. Acessar `Admin > Relatórios`
2. Selecionar professor e mês
3. Verificar dados mostrados na tela
4. Confirmar que apenas registros `approved = 1` aparecem

**Resultado Esperado:**
- [ ] Tela mostra apenas pontos aprovados
- [ ] Cálculos de horas baseados em approved=1
- [ ] Pendências (approved=NULL) não aparecem no cálculo

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 4.2 Toggle de Auditoria (include_pendings=1)
**Pré-condição:** Relatório mensal carregado

**Passos:**
1. Na tela de relatório, ativar toggle "Incluir Pendências" 
2. Verificar se registros pending aparecem (diferenciados)
3. Confirmar que toggle afeta apenas visualização
4. Testar export com toggle ativo

**Resultado Esperado:**
- [ ] Toggle adiciona parâmetro `include_pendings=1` na URL
- [ ] Registros pending aparecem marcados/destacados
- [ ] Export continua apenas com approved=1 (não afetado pelo toggle)

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 4.3 Export PDF
**Pré-condição:** Relatório carregado, Dompdf instalado

**Passos:**
1. Clicar em "Exportar PDF"
2. Verificar conteúdo do PDF gerado
3. Confirmar que apenas registros approved=1 estão incluídos

**Resultado Esperado:**
- [ ] PDF baixado automaticamente
- [ ] Conteúdo formatado corretamente
- [ ] Apenas dados aprovados no export
- [ ] Headers e footers adequados

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 4.4 Export CSV/XLSX
**Pré-condição:** Relatório carregado

**Passos:**
1. Testar export CSV
2. Testar export XLSX (se PhpSpreadsheet disponível)
3. Verificar encoding (UTF-8)
4. Validar estrutura das colunas

**Resultado Esperado:**
- [ ] CSV com separador correto (;)
- [ ] XLSX com formatação adequada
- [ ] Encoding preserva acentos
- [ ] Apenas registros approved=1 exportados

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

---

## 5. Testes de Relatório Financeiro

### 5.1 Consolidação Approved-Only
**Pré-condição:** Dados de attendance e salário base configurado

**Passos:**
1. Acessar `Admin > Relatório Financeiro`
2. Selecionar professor e mês
3. Verificar cálculos de horas trabalhadas
4. Confirmar que apenas approved=1 são considerados

**Resultado Esperado:**
- [ ] Cálculo de horas baseado apenas em registros aprovados
- [ ] Valores de extras/déficit corretos
- [ ] Base salarial aplicada adequadamente

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 5.2 Export XLSX Financeiro
**Pré-condição:** Relatório financeiro carregado

**Passos:**
1. Clicar em "Export XLSX"
2. Verificar se arquivo é gerado corretamente
3. Em caso de erro, verificar fallback para CSV

**Resultado Esperado:**
- [ ] XLSX gerado com sucesso (se PhpSpreadsheet disponível)
- [ ] Fallback automático para CSV em caso de erro
- [ ] Dados financeiros corretos no export

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 5.3 Fallback CSV Financeiro
**Pré-condição:** PhpSpreadsheet indisponível ou erro no XLSX

**Passos:**
1. Simular erro no XLSX (renomear vendor/phpoffice temporariamente)
2. Tentar export XLSX
3. Verificar redirecionamento para CSV

**Resultado Esperado:**
- [ ] Sistema detecta erro/indisponibilidade
- [ ] Redirecionamento automático para CSV
- [ ] CSV gerado com mesmos dados do XLSX

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

---

## 6. Cenários de Edge Cases

### 6.1 Limite de 5 Fotos/Frames
**Pré-condição:** Sistema configurado para best-of-N

**Passos:**
1. Fazer múltiplas tentativas de captura rápida
2. Verificar se sistema limita a 5 frames
3. Confirmar que melhor frame é selecionado

**Resultado Esperado:**
- [ ] Sistema captura máximo 5 frames
- [ ] Algoritmo seleciona frame com melhor qualidade
- [ ] Performance mantida mesmo com múltiplas tentativas

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

### 6.2 Comportamento Best-of-N no Servidor
**Pré-condição:** Múltiplos descriptors capturados para um professor

**Passos:**
1. Professor com 3+ amostras faciais cadastradas
2. Fazer check-in por reconhecimento facial
3. Verificar qual descriptor foi usado para match

**Resultado Esperado:**
- [ ] Sistema testa contra todos os descriptors salvos
- [ ] Seleciona o melhor match (menor distância)
- [ ] Threshold de similaridade respeitado

**Observado:**
```
Data: ___________
Status: Pass/Fail
Notas: ___________________________________
```

---

## 7. Lista de Verificação Final

### Funcionalidades Core
- [ ] Check-in/out funcionando com foto e geolocalização
- [ ] Aprovação automática baseada em qualidade da foto + geo
- [ ] Sistema de fallback (PIN) operacional
- [ ] Captura e armazenamento de múltiplos descriptors faciais

### Admin e Navegação
- [ ] CRUD de escolas funcionando
- [ ] Vínculos Professor-Escola (1:N e N:N) operacionais
- [ ] Filtros de escopo por escola (school_admin vs network_admin)
- [ ] Inserção manual de ponto com justificativas

### Relatórios e Exports
- [ ] Relatórios mensais com filtro approved-only
- [ ] Toggle de auditoria (include_pendings) funcional
- [ ] Exports PDF/CSV/XLSX operacionais
- [ ] Relatório financeiro com cálculos corretos

### Segurança e Performance
- [ ] CSRF tokens ativos e validados
- [ ] Permissões de arquivo adequadas
- [ ] .htaccess bloqueando arquivos sensíveis
- [ ] HTTPS obrigatório para camera (produção)

---

## Comandos Úteis para QA

### Importar Seeds de Teste
```bash
# Importar dados de teste (execute na raiz do projeto)
mysql -u username -p database_name < seeds/qa_seed.sql

# Ou usando docker (se aplicável)
docker exec -i mysql_container mysql -u username -p database_name < seeds/qa_seed.sql

# Verificar se dados foram importados
mysql -u username -p -e "SELECT COUNT(*) as schools FROM database_name.schools; SELECT COUNT(*) as teachers FROM database_name.teachers;"
```

### Verificar Logs de Erro
```bash
# Verificar logs do Apache/Nginx
tail -f /var/log/apache2/error.log
tail -f /var/log/nginx/error.log

# Verificar logs do PHP
tail -f /var/log/php/error.log
```

### Reset de Dados para Teste
```bash
# Limpar dados de attendance para novo teste
mysql -u username -p -e "DELETE FROM database_name.attendance WHERE date >= CURDATE();"

# Reset de fotos de teste
rm -f /path/to/public/photos/foto_*
```

### Verificar Dependências
```bash
# Verificar se todas as dependências estão instaladas
composer install --no-dev
composer show dompdf/dompdf
composer show phpoffice/phpspreadsheet
```

---

## Notas de Configuração

### Limites Importantes
- **Máximo 5 frames** por sessão de captura facial
- **Threshold de similaridade facial**: 0.5-0.6 (configurável em `api/checkin.php`)
- **Qualidade mínima de foto**: brightness 70-200, contrast >20, laplacian variance >80
- **Geo-aprovação**: Sistema captura coordenadas, mas validação por raio pode estar em desenvolvimento

### Troubleshooting Comum
- **Câmera não funciona**: Verificar HTTPS e permissões do navegador
- **Reconhecimento facial falha**: Verificar qualidade dos descriptors e threshold
- **Exports falham**: Verificar instalação do Composer e dependências
- **Geolocalização imprecisa**: Verificar configuração de raio das escolas

### Performance Tips
- Manter fotos em resolução adequada (não muito altas)
- Limpar periodicamente logs de auditoria antigos
- Otimizar queries com índices adequados
- Usar cache para relatórios frequentes

---

*Última atualização: Dezembro 2024*
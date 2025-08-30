# Sistema de Ponto Eletrônico para Professores (PHP + Bootstrap + MySQL PDO + Reconhecimento Facial)

Sistema simples, porém completo, para registro de ponto (entrada/saída) de professores. Inclui:
- Reconhecimento facial via face-api.js (TensorFlow.js) no navegador.
- Captura e cadastro de face para cada professor.
- Alternativa por PIN.
- Painel administrativo para gerenciar professores e visualizar batidas.
- MySQL (PDO), PHP, Bootstrap.

ATENÇÃO
- Para a câmera funcionar no navegador, acesse via HTTPS ou em http://localhost (origem segura).
- Reconhecimento facial é feito no cliente (navegador). A verificação e gravação do ponto são validadas no servidor (PHP) comparando o descritor facial (vetor) contra os salvos no banco.

## Requisitos
- PHP 8.0+
- MySQL 5.7+ (ou MariaDB com suporte a JSON; se não tiver JSON, o campo será TEXT e funciona da mesma forma)
- Servidor web apontando para a pasta `public/` como DocumentRoot (recomendado)

## Instalação
1. Crie o banco e tabelas:
   - Importe o arquivo `install.sql` no seu MySQL.

2. Configure o banco em `config.php`:
   - Ajuste as constantes DB_HOST, DB_NAME, DB_USER, DB_PASS.

3. Acesse o painel admin:
   - Vá para `/admin/login.php`.
   - Na primeira execução, um usuário admin padrão será criado automaticamente:
     - Usuário: `admin`
     - Senha: `admin123`
   - Altere a senha imediatamente no painel (há um link/ação para alterar senha na dashboard).

4. Cadastre professores:
   - Acesse `Admin > Professores`.
   - Crie um professor (nome, e-mail e opcionalmente um PIN).
   - Em seguida, capture a face do professor via `Capturar Face`.

5. Registro de ponto:
   - Professores acessam a página inicial (`/index.php`).
   - Podem registrar entrada/saída via reconhecimento facial ou por PIN (e-mail + PIN).

## Observações de Segurança
- Use HTTPS em produção (requerido para câmera).
- Ative e verifique tokens CSRF (já implementados).
- Limite IP/ACLs do painel admin.
- Ajuste a sensibilidade do reconhecimento alterando a constante FACE_MATCH_THRESHOLD em `api/checkin.php` (padrão 0.5 ~ 0.6 é razoável).

## Testes e QA
📋 **[Checklist de QA Completo](docs/QA_CHECKLIST.md)** - Roteiro detalhado para validação de todas as funcionalidades do sistema, incluindo dados de teste e cenários de uso.

## Dependências Front-end
- Bootstrap via CDN.
- face-api.js via CDN:
  - Biblioteca: https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js
  - Modelos: https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/

Se preferir, hospede os modelos localmente e mude a URL em `index.php` e `capture_face.php`.

## Estrutura
- config.php: conexão PDO e sessão
- helpers.php: autenticação admin, CSRF, etc.
- install.sql: schema do banco
- public/
  - index.php: página de ponto (face/PIN)
  - admin/
    - login.php, logout.php, dashboard.php
    - teachers.php, teacher_form.php, capture_face.php, attendance.php
- api/
  - checkin.php: bater ponto (face ou PIN)
  - save_face.php: salvar descritor facial do professor (admin)

Bom uso!
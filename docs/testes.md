# Sistema de Ponto – Roteiro de Testes Completo

Objetivo: validar todas as funcionalidades após a ampliação para colaboradores, novos tipos, rotina por aulas/horário, inserção manual com justificativa e relatórios.

Pré-requisitos
- Banco criado e selecionado (ex.: `USE ponto;`).
- Rodar o script completo:
  - install.sql (última versão que te enviei).
  - Se for ambiente existente, rodar as migrações prod/dev conforme o caso.
- PHP 8.x com PDO MySQL habilitado.
- Apache com:
  - mod_rewrite (para .htaccess).
  - mod_headers e mod_expires (opcional, para headers/caching).
- Permissões de escrita em public/photos (www-data/daemon).
- Configurar fuso horário: America/Sao_Paulo (já no config.php).

Dados de teste sugeridos
- Colaboradores:
  - Professor: Nome “Ana Prof”, CPF 11122233344, PIN 123456, Tipo: Professor (classes).
  - Diretor: Nome “Bruno Diretor”, CPF 22233344455, PIN 234567, Tipo: Diretor (time).
  - Motorista: Nome “Carlos Motorista”, CPF 33344455566, PIN 345678, Tipo: Motorista (time).
- Horários (exemplo):
  - Professor (Seg–Sex): classes_count=4, class_minutes=50. Sábado/Domingo: 0.
  - Diretor (Seg–Sex): 08:00–17:00, intervalo 60. Sábado/Domingo: vazio.
- Motivos manuais (padrão já inserido): Falta de internet, Falha no sistema, Esquecimento do colaborador, Outro.

1) Verificações iniciais (Infra e Segurança)
- [ ] Acessar / (raiz) e garantir que redireciona/reescreve para /public/index.php.
- [ ] Tentar acessar /config.php: deve retornar 403 (bloqueado pelo .htaccess).
- [ ] Tentar acessar /helpers.php: deve retornar 403.
- [ ] Verificar que /api/ está acessível diretamente (p.ex. 405 em GET de /api/checkin.php).
- [ ] Garantir que public/photos existe e tem permissão de escrita.

2) Login Admin
- [ ] Acessar /public/admin/login.php.
- [ ] Logar com admin/admin123 (do script) e trocar a senha depois do primeiro acesso (boa prática).
- [ ] Verificar se $_SESSION['admin'], $_SESSION['admin_id'] e $_SESSION['admin_username'] são populadas (helpers admin_login).

3) Tipos de Colaboradores (Admin > Tipos)
- [ ] Conferir tipos padrão: Professor, Diretor, Secretário, Motorista, Coordenador, Administrativo, Auxiliar.
- [ ] Criar um tipo extra “Estagiário”, slug “intern”, schedule_mode “time”.
- [ ] Editar um tipo (ex.: Motorista) e salvar; checar se requires_schedule acompanha schedule_mode (≠ none).

4) Motivos de Ponto Manual (Admin > Motivos)
- [ ] Verificar motivos padrão listados.
- [ ] Criar novo motivo: “Falta de energia”, ativo, ordem 15.
- [ ] Editar “Outro” para outro nome; salvar; voltar ao padrão (opcional).
- [ ] Inativar um motivo e checar se some do select na tela de inserção manual.

5) Cadastro de Colaboradores
- [ ] Admin > Colaboradores > Novo Colaborador: criar “Ana Prof” (Professor) com PIN 123456.
- [ ] Definir rotina por aulas (Seg–Sex: 4 aulas, 50 min).
- [ ] Criar “Bruno Diretor” (Diretor) com PIN 234567 e rotina time (Seg–Sex: 08:00–17:00, intervalo 60).
- [ ] Criar “Carlos Motorista” (Motorista) com PIN 345678 e rotina time (ex.: 07:00–11:00, intervalo 15).
- [ ] Tentar cadastrar PIN repetido: deve bloquear (mensagem de PIN já em uso).

6) Captura de Face (opcional, se usar face descriptors)
- [ ] Admin > Colaboradores > Ações > “Capturar Face” para “Ana Prof”.
- [ ] Carregar face-api, capturar 3 amostras e salvar.
- [ ] Verificar retorno ok (count > 0) e no banco (teachers.face_descriptors preenchido).

7) Check-in público (PIN + Foto + Geo)
Pré: abrir /public/index.php
- [ ] Verificar campos PIN e CPF (CPF opcional), webcam OK, CSRF token presente no HTML.
- [ ] Professor (dia com rotina):
  - [ ] Informar CPF 11122233344 e PIN 123456, permitir geolocalização, registrar “entrada”.
  - [ ] Deve retornar status ok, action “entrada”, grava foto em /public/photos, row em attendance com method=pin, approved=1.
  - [ ] Repetir “entrada” no mesmo dia: deve rejeitar (entrada aberta).
  - [ ] Registrar “saída” (realizar novo envio): deve fechar o mesmo registro (check_out preenchido).
- [ ] Professor (domingo, sem rotina):
  - [ ] Tentar “entrada” no domingo: deve rejeitar por falta de rotina prevista.
- [ ] Diretor (dia com rotina time):
  - [ ] Registrar “entrada” e “saída” no mesmo dia: OK.
- [ ] Colaborador inativo:
  - [ ] Desativar “Carlos Motorista” e tentar check-in com PIN: deve retornar erro (inativo).
- [ ] PIN inválido:
  - [ ] Usar PIN errado: deve rejeitar (401).

8) Inserção Manual de Pontos (Admin)
Acessar Admin > Inserir Ponto Manual
- [ ] Entrada manual:
  - [ ] Selecionar “Ana Prof”, hoje, “Entrada”, 10:00, motivo “Falta de internet”.
  - [ ] Salvar: deve criar attendance com method=manual, manual_reason_id e manual_by_admin_id (não nulo se login correto).
- [ ] Saída manual:
  - [ ] Selecionar “Ana Prof”, hoje, “Saída”, 12:00, motivo “Esquecimento do colaborador”.
  - [ ] Deve fechar o último registro aberto do dia; method vira ‘manual’ na batida de saída e justificativa preenchida.
- [ ] Entrada e saída (both):
  - [ ] Selecionar “Bruno Diretor”, data de ontem, “Entrada e Saída”, 08:05–16:58, motivo “Falta de energia”.
  - [ ] Deve criar um registro completo (check_in e check_out).
- [ ] Motivo “Outro”:
  - [ ] Selecionar “Outro” sem texto de justificativa: deve exigir texto.
- [ ] Auditoria Admin:
  - [ ] Confirmar que manual_by_admin_id é o ID do admin logado (se não, checar sessão e ajuste em attendance_manual.php).

9) Registros de Ponto (Admin > Registros)
- [ ] Filtro por Colaborador, Tipo, Data inicial/final: resultados coerentes.
- [ ] Colunas:
  - [ ] “Tipo”: exibir Professor/Diretor, etc.
  - [ ] “Rotina Prevista”: professor (X aulas x Y min), time (HH:MM–HH:MM (-break)).
  - [ ] “Justificativa”: preenchida apenas nos manuais (Motivo - Texto (por admin)).
  - [ ] “Foto”: imagem abre em nova aba quando houver.
- [ ] Aprovação/Rejeição:
  - [ ] Rejeitar um registro, badge muda para rejeitado.
  - [ ] Aprovar de volta, badge muda para aprovado.
- [ ] Resumos Semanal/Mensal: total esperado vs realizado e saldo coerentes.

10) Relatórios Mensais
- Admin > Relatórios (ou Relatório Mensal do Colaborador)
  - [ ] Selecionar “Ana Prof” em mês com rotinas e pontos:
    - [ ] Conferir colunas: Rotina Prevista (aulas), Esperado (cc*cm), Trabalhado, Saldo, Justificativa (quando manual).
    - [ ] Domingos ocultados (ou rotina zero).
    - [ ] Totais do mês batem com a soma.
  - [ ] Selecionar “Bruno Diretor”:
    - [ ] Rotina Prevista exibe faixa horária e intervalo.
    - [ ] Esperado calculado por diferença (end-start-break).
    - [ ] Justificativa aparece nos dias com ponto manual.
  - [ ] Botão de imprimir/Gerar PDF funciona (renderização ok).

11) Edição e Reset de PIN
- [ ] Editar colaborador e trocar tipo (classes <-> time), formulário alterna bloco de rotina corretamente.
- [ ] Resetar PIN em “Ações”:
  - [ ] Novo PIN gerado, checar unicidade (não coincide com outro).
  - [ ] Testar login de check-in com novo PIN (sucesso).

12) Menus e Navegação
- [ ] Navbar/Admin em todas as páginas traz:
  - [ ] Colaboradores
  - [ ] Registros de Ponto
  - [ ] Inserir Ponto Manual
  - [ ] Motivos
  - [ ] Tipos
  - [ ] Relatórios (se aplicável)
- [ ] Links funcionam e breadcrumbs condizem.

13) API – Sanidade (opcional por Postman/cURL)
Notas: precisa de CSRF token (capturar via sessão/logado no browser ou desabilitar temporariamente só no ambiente de teste).
- checkin.php
  - [ ] POST JSON { pin, cpf, photo(dataURL), geo } → retorna { status: ok, action, time, photo, collaborator/teacher }
  - [ ] Sem CPF: deve funcionar via varredura de hash (lento com muitos usuários, mas ok em teste).
- save_face.php
  - [ ] POST JSON { teacher_id, descriptors: [[..128floats..], ...] } logado como admin → { status: ok, count }.

Exemplo cURL (com CSRF ilustrativo; usar valor real do meta csrf-token):
```bash
curl -i -X POST http://localhost/ponto/api/checkin.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: SEU_TOKEN" \
  -d '{"pin":"123456","cpf":"11122233344","photo":"data:image/jpeg;base64,/9j/4AAQSk...","geo":{"lat":-23.5,"lng":-46.6,"acc":30}}'
```

14) Integridade de Dados (Banco)
- [ ] attendance.teacher_id referencia teachers.id (FK ok).
- [ ] attendance.manual_reason_id referencia manual_reasons.id (FK ok ou NULL).
- [ ] attendance.manual_by_admin_id referencia admins.id (FK ok ou NULL).
- [ ] teachers.type_id referencia collaborator_types.id (FK ok).
- [ ] collaborator_schedules.teacher_id referencia teachers.id (FK com ON DELETE CASCADE).
- [ ] Índices presentes: idx_teachers_type_active, idx_attendance_teacher_date, idx_attendance_manual.

15) Casos de Borda
- [ ] Ponto atravessando meia-noite: check-in 23:00 e check-out 01:00 (manual “both” em dois dias) – validar cálculo por dia (cada dia isolado; cenário real exige política própria).
- [ ] Colaborador sem rotina (tipo schedule_mode=none): Esperado=0, check-ins permitidos (dependendo de política; hoje só bloqueia quando tipo exige rotina).
- [ ] Mudar tipo do colaborador (de classes para time): rotina “antiga” permanece na tabela; valida que relatórios passam a usar o novo modo (classes x time).
- [ ] Apagar motivo “em uso”: FK permite manter ID, mas evite deletar motivos usados (sugestão: desativar ao invés de excluir).
- [ ] Lentidão sem CPF: com base grande, varrer PIN pode ser lento (recomendação: tornar CPF obrigatório na UI pública para produção).

Checklist de Aceite (resumo)
- [ ] Check-in/out via PIN com foto e geo funcionando para Professor e Diretor.
- [ ] Bloqueio de “entrada” em dias sem rotina (para tipos que exigem).
- [ ] Inserção Manual (entrada/saída/both) com motivo obrigatório, “Outro” exige texto.
- [ ] Registros mostram Justificativa para manuais.
- [ ] Relatórios mostram Rotina Prevista e Justificativa e somatórios corretos.
- [ ] Filtros por tipo/colaborador funcionando.
- [ ] Menus com Tipos/Motivos/Inserção Manual em todas as páginas Admin.
- [ ] Segurança: .htaccess protege arquivos, CSRF ativo nas rotas, API admin exige sessão.
- [ ] FKs e índices criados, sem erros de integridade.

Dicas adicionais
- Para ambiente de produção, exija CPF no check-in para lookup O(1).
- Mantenha backups do diretório de fotos e do banco.
- Logar IP/user_agent ajuda auditoria (já gravado).
- Configure HTTPS em produção e SameSite/secure para cookies.
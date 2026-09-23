# Legacy — scripts one-off

Scripts de migração/correção que rodaram **uma vez** no passado para corrigir dados
ou estrutura, e foram movidos para fora da pasta servida pelo webserver
(via `.htaccess` com `Require all denied`).

Mantidos no repositório apenas como histórico/documentação. **Não executar de novo.**

| Arquivo | Propósito |
|---|---|
| `fix_attendances_btn_style.php` | Reestilizou o botão dropdown da tabela de registros |
| `fix_attendances_headers.php` | Ajustou cabeçalhos de colunas |
| `fix_attendances_name_casing.php` | Normalizou case de nomes em registros |
| `fix_name_casing.php` | Normalizou nomes em `teachers.php` |
| `fix_school_dup_checkbox.php` | Removeu checkbox duplicado |
| `fix_schools_name_casing.php` | Normalizou nomes de escolas |
| `group_buttons.php` | Agrupou botões de ação em dropdown |
| `refactor_attendances_table.php` | Refatorou estrutura de tabela |
| `remove_pagination_counter.php` | Removeu contador antigo de paginação |
| `strip_columns.php` | Removeu colunas obsoletas |
| `strip_final_column.php` | Removeu coluna final |
| `compact_attendances_actions.php` | Compactou ações em dropdown |
| `patch_school_edit2.php` | Aplicou colunas novas em `school_edit.php` |
| `rewrite_navbar.php` | Gerador one-off do `_navbar.php` |

Se precisar recriar algum efeito desses, copie e adapte — não execute o original.

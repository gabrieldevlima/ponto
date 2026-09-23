# Spec — Remoções auditáveis no painel admin (DEEDO Ponto)

**Data:** 2026-06-09 · **Status:** aprovado (brainstorming)

## Objetivo
Três ações no admin, auditáveis e seguras:
1. **Remover intervalo registrado** (attendance record_type='break')
2. **Remover ponto batido** (attendance record_type='work')
3. **Remover colaborador** (teachers)

## Decisões
- **Ponto e Intervalo → anular (soft-delete).** Nunca apaga registro legal: o NSR é imutável (Portaria 671). O registro permanece no banco mas é excluído de folha/relatórios/recibos/listagens. Reversível ("Restaurar"). **Motivo obrigatório.**
- **Colaborador → inativar (`active=0`).** Preserva histórico/folha. Reversível ("Reativar"). Motivo obrigatório.
- **Permissão:** remoção de ponto/intervalo = **somente admin de rede** (`is_network_admin()`); inativar colaborador = admin dentro do escopo (`admin_scope_where`). Fácil relaxar depois.

## Mecanismo de dados
- Migration `2026_06_09_attendance_removed.sql`: adiciona em `attendance`:
  - `removed_at DATETIME NULL`, `removed_by_admin_id INT NULL`, `removed_reason VARCHAR(255) NULL`.
- **Remover** (work/break): numa transação →
  - `UPDATE attendance SET approved=0, superseded_by_id=id, removed_at=NOW(), removed_by_admin_id=?, removed_reason=?, manual_reason_text=CONCAT('Removido pelo admin: ',?), manual_by_admin_id=?, manual_created_at=NOW() WHERE id=? AND superseded_by_id IS NULL`.
  - Reusa a exclusão existente (`superseded_by_id IS NULL` + `approved=1`) que folha/timesheet/listagens **já** respeitam → registro removido some de todos os consumidores sem alterar dezenas de queries. (Validar no implement: payroll, my_timesheet, reports, receipts, consolidate.)
  - Se for **work** com filhos `parent_attendance_id` → remove (soft) os intervalos filhos junto (auditando a cascata).
  - **Recalcula horas do dia** (`calculate_effective_worked_minutes`) e atualiza `hour_bank_entries` (source='auto') — mesmo padrão do checkin.
- **Restaurar:** zera `removed_at/by/reason`, `superseded_by_id=NULL`, devolve `approved` ao estado anterior (recalcula dia). Só restaura registros com `removed_at IS NOT NULL` (não toca em duplicatas de dedupe).
- **Inativar colaborador:** `UPDATE teachers SET active=0`. Opcional: invalida tokens/sessões (collaborator_remember_tokens). Não apaga ponto.

## Segurança
- Endpoint POST: `require_admin` + `csrf_verify` + gate de permissão (network-admin p/ ponto; `admin_scope_where` p/ colaborador) + **confirmação na UI com motivo obrigatório**.
- Validação de escopo: o registro/colaborador precisa estar no escopo do admin.

## Auditoria (dupla, sempre)
- `log_attendance_audit($pdo,$id,$adminId,'admin_remove'|'admin_restore','removed',$old,$new,$reason)` → `attendance_audit_log` (Portaria 671).
- `audit_log('remove'|'restore'|'deactivate', 'attendance'|'teacher', $id, [...])` → `audit_logs`.

## Arquivos
- `sql/migrations/2026_06_09_attendance_removed.sql` (novo)
- `helpers.php`: `admin_remove_attendance()`, `admin_restore_attendance()`, `recompute_day_hour_bank()`, `admin_deactivate_collaborator()`/`reactivate`.
- `public/admin/attendances_action.php`: `act=remove` / `act=restore`.
- `public/admin/attendances.php`: botões "Remover"/"Restaurar" + modal de confirmação + motivo.
- `public/admin/teachers.php` (ou `teacher_edit.php`): ação "Inativar"/"Reativar" com confirmação + motivo.

## Testes/verificação
- `tests/test_admin_removals.php`: remover work/break → excluído da folha (calculate_effective_worked_minutes) + reversível; cascata de intervalos; inativar/reativar colaborador; auditoria gravada. Tudo em transação que reverte.
- Driver real (curl/Apache) dos endpoints com sessão admin + CSRF + escopo.
- Lint + suíte completa (12 arquivos) verde.

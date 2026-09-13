-- Suporte a registros de intervalo (break) na tabela attendance.
--
-- Permite distinguir pares check_in/check_out de trabalho efetivo (record_type='work')
-- de pares correspondentes a intervalos (record_type='break', ex. almoço/café).
--
-- Compatibilidade: DEFAULT 'work' garante que todos os registros existentes ficam
-- automaticamente classificados como trabalho. Nenhum backfill é necessário.
--
-- parent_attendance_id é opcional e aponta o registro work que originou o intervalo,
-- útil para auditoria e agrupamento visual em relatórios. ON DELETE SET NULL preserva
-- breaks mesmo se o work parent for removido.

ALTER TABLE attendance
  ADD COLUMN record_type ENUM('work','break') NOT NULL DEFAULT 'work'
    COMMENT 'work=jornada produtiva (par check_in/check_out de trabalho), break=intervalo'
    AFTER class_period_id;

ALTER TABLE attendance
  ADD COLUMN parent_attendance_id INT NULL
    COMMENT 'Para record_type=break: id do work do mesmo turno (auditoria/agrupamento)'
    AFTER record_type;

ALTER TABLE attendance
  ADD INDEX idx_att_teacher_date_type (teacher_id, date, record_type);

ALTER TABLE attendance
  ADD CONSTRAINT fk_att_parent FOREIGN KEY (parent_attendance_id)
    REFERENCES attendance(id) ON DELETE SET NULL;

-- =====================================================================
-- SCRIPT SIMPLES: Adicionar coluna pending_reasons
-- =====================================================================
-- Execute este script completo no phpMyAdmin
-- Não vai dar erro se a coluna já existir
-- =====================================================================

-- Adiciona coluna pending_reasons (seguro - não dá erro se já existir)
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS pending_reasons TEXT NULL 
COMMENT 'Motivos JSON quando ponto fica pendente (ex: sem foto, sem geo)';

-- Confirma que funcionou
SELECT 'Coluna adicionada ou ja existe!' AS resultado;

-- Mostra estrutura da tabela attendance (para confirmar)
SHOW COLUMNS FROM attendance LIKE '%pending%';

-- Mostra últimos 5 registros pendentes com seus motivos
SELECT 
    a.id,
    t.name AS colaborador,
    DATE_FORMAT(a.date, '%d/%m/%Y') AS data,
    CASE 
        WHEN a.approved IS NULL THEN 'Pendente'
        WHEN a.approved = 1 THEN 'Aprovado'
        ELSE 'Rejeitado'
    END AS status,
    a.pending_reasons AS motivos,
    CASE WHEN a.photo IS NULL THEN 'Nao' ELSE 'Sim' END AS tem_foto,
    CASE WHEN a.check_in_lat IS NULL THEN 'Nao' ELSE 'Sim' END AS tem_gps
FROM attendance a
JOIN teachers t ON t.id = a.teacher_id
WHERE a.approved IS NULL
ORDER BY a.id DESC
LIMIT 5;


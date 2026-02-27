-- =====================================================================
-- VERIFICAÇÃO: Coluna pending_reasons na tabela attendance
-- =====================================================================
-- Execute este script no phpMyAdmin da produção para verificar
-- se a coluna pending_reasons existe e está configurada corretamente
-- =====================================================================

-- 1. Verificar se a coluna existe
-- Este comando vai falhar se a coluna NÃO existir (é normal!)
-- Se falhar, execute a migration logo abaixo
SELECT pending_reasons FROM attendance LIMIT 1;

-- Se o comando acima retornou erro "Unknown column", execute a migration abaixo:
-- Se funcionou, a coluna JÁ EXISTE e você pode pular para o passo 2

-- =====================================================================
-- MIGRATION: Adicionar coluna pending_reasons (se não existir)
-- =====================================================================

ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS pending_reasons TEXT NULL 
COMMENT 'Motivos JSON quando ponto fica pendente (ex: sem foto, sem geo)';

-- 2. Verificar registros pendentes existentes
SELECT 
    a.id,
    t.name AS colaborador,
    a.date,
    a.approved,
    a.pending_reasons,
    a.photo,
    a.check_in_lat,
    a.check_in_lng,
    t.network_wide
FROM attendance a
JOIN teachers t ON t.id = a.teacher_id
WHERE a.approved IS NULL
ORDER BY a.id DESC
LIMIT 10;

-- 3. Estatísticas de registros pendentes
SELECT 
    COUNT(*) as total_pendentes,
    SUM(CASE WHEN pending_reasons IS NOT NULL THEN 1 ELSE 0 END) as com_motivos,
    SUM(CASE WHEN pending_reasons IS NULL THEN 1 ELSE 0 END) as sem_motivos,
    SUM(CASE WHEN photo IS NULL THEN 1 ELSE 0 END) as sem_foto,
    SUM(CASE WHEN check_in_lat IS NULL THEN 1 ELSE 0 END) as sem_gps
FROM attendance
WHERE approved IS NULL;

SELECT '✓ Verificação concluída!' AS status;


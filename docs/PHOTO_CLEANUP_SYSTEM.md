# Sistema de Limpeza Automática de Fotos

## Visão Geral

O DEEDO Ponto implementa um sistema inteligente de gerenciamento de armazenamento que automaticamente deleta fotos antigas para evitar esgotamento do espaço em disco. As fotos são deletadas após um período configurável, mas os registros de ponto permanecem intactos no banco de dados.

## Como Funciona

### Processo Automático

1. **Trigger de Limpeza**: A cada upload de foto (durante registro de ponto), o sistema verifica se deve executar a limpeza
2. **Verificação de Threshold**: Compara o tamanho atual da pasta `public/photos/` com o limite configurado
3. **Execução**: Se o limite for ultrapassado, fotos mais antigas que o período de retenção são deletadas
4. **Marcação no Banco**: Registros têm a flag `photo_deleted = 1` e timestamp `photo_deleted_at` atualizados
5. **Preservação de Dados**: Todos os outros dados do registro (horários, localização, aprovação) permanecem intactos

### Quando a Limpeza é Executada

A limpeza automática ocorre apenas quando **TODAS** estas condições são atendidas:

- `PHOTO_CLEANUP_ENABLED = true` (em `config.php`)
- Uma nova foto é enviada (registro de ponto)
- Tamanho da pasta `public/photos/` > `PHOTO_STORAGE_THRESHOLD_MB`
- Existem fotos com data anterior ao período de retenção

## Configurações

### Arquivo: `config.php`

```php
// Dias de retenção (padrão: 90 dias)
define('PHOTO_RETENTION_DAYS', 90);

// Limite de armazenamento em MB (padrão: 500 MB)
define('PHOTO_STORAGE_THRESHOLD_MB', 500);

// Ativar/desativar limpeza automática (padrão: true)
define('PHOTO_CLEANUP_ENABLED', true);
```

### Recomendações por Cenário

| Cenário | Retenção | Threshold | Justificativa |
|---------|----------|-----------|---------------|
| **Conformidade Legal** | 1825 dias (5 anos) | 2000 MB | Portaria MTP 671/2021 exige 5 anos |
| **Padrão Balanceado** | 90 dias (3 meses) | 500 MB | Equilibra conformidade e economia |
| **Economia Agressiva** | 30 dias (1 mês) | 200 MB | Ambientes com pouco armazenamento |
| **Desenvolvimento/Teste** | 7 dias | 100 MB | Limpeza frequente para testes |

## Interface Administrativa

### Acesso

**Menu:** Configurações → Gerenciar Fotos

**URL:** `/public/admin/photo_cleanup.php`

### Funcionalidades

#### 1. Painel de Estatísticas

Exibe em tempo real:

- Armazenamento usado (MB)
- Total de fotos ativas
- Total de fotos deletadas
- Fotos elegíveis para limpeza
- Fotos deletadas nos últimos 30 dias

#### 2. Execução Manual

Permite executar limpeza manualmente com duas opções:

- **Normal**: Respeita o threshold configurado
- **Forçada**: Executa mesmo abaixo do threshold (útil para manutenção)

#### 3. Histórico de Exclusões

Lista as últimas 20 fotos deletadas com:

- Nome do colaborador
- Data do registro original
- Nome do arquivo
- Timestamp de quando foi deletada

## Banco de Dados

### Novas Colunas na Tabela `attendance`

```sql
photo_deleted TINYINT(1) DEFAULT 0 
  COMMENT 'Flag indicando se a foto foi deletada automaticamente'

photo_deleted_at DATETIME NULL 
  COMMENT 'Data/hora em que a foto foi deletada'
```

### Consultas Úteis

#### Listar fotos deletadas recentemente

```sql
SELECT 
    t.name AS colaborador,
    a.date AS data_registro,
    a.photo AS arquivo,
    a.photo_deleted_at AS deletada_em
FROM attendance a
JOIN teachers t ON t.id = a.teacher_id
WHERE a.photo_deleted = 1
ORDER BY a.photo_deleted_at DESC
LIMIT 50;
```

#### Quantidade de fotos por status

```sql
SELECT 
    CASE 
        WHEN photo_deleted = 1 THEN 'Deletadas'
        WHEN photo IS NOT NULL THEN 'Ativas'
        ELSE 'Sem Foto'
    END AS status,
    COUNT(*) AS quantidade
FROM attendance
GROUP BY status;
```

#### Espaço potencial a liberar

```sql
SELECT 
    COUNT(*) AS fotos_antigas,
    DATE_SUB(CURDATE(), INTERVAL 90 DAY) AS data_corte
FROM attendance
WHERE photo IS NOT NULL 
  AND photo_deleted = 0 
  AND date < DATE_SUB(CURDATE(), INTERVAL 90 DAY);
```

## Exibição de Fotos Deletadas

### Na Interface Admin (`attendances.php`)

Fotos deletadas aparecem como:

```
[Badge Cinza] 🖼️ Foto deletada
```

Com tooltip mostrando data de exclusão.

### Na Interface do Colaborador

Se a página exibir fotos (futuro), mostrará placeholder similar.

## Integração com o Sistema

### APIs Afetadas

1. **`api/checkin.php`**: Executa cleanup após salvar foto (entrada/saída)
2. **`api/checkin_bulk.php`**: Executa cleanup em registros em lote

### Funções Auxiliares (`helpers.php`)

#### `cleanup_old_photos(PDO $pdo, bool $force = false): array`

Executa a limpeza e retorna estatísticas:

```php
[
    'status' => 'completed',
    'deleted_count' => 15,
    'freed_space_mb' => 23.45,
    'current_size_mb' => 476.55,
    'previous_size_mb' => 500.00,
    'cutoff_date' => '2024-10-24',
    'retention_days' => 90,
    'errors' => []
]
```

#### `get_directory_size(string $path): int`

Calcula tamanho total de um diretório em bytes.

## Monitoramento e Dashboard

### KPIs no Dashboard Principal

O dashboard (`dashboard.php`) exibe 3 novos cards:

1. **Armazenamento Usado**
   - Tamanho em MB
   - Contagem de fotos ativas
   - Barra de progresso vs threshold

2. **Fotos Deletadas**
   - Total deletado
   - Últimos 30 dias

3. **Gerenciar Armazenamento**
   - Link rápido para a página de gerenciamento

## Troubleshooting

### Problema: Limpeza não está executando

**Possíveis causas:**

1. `PHOTO_CLEANUP_ENABLED = false` em `config.php`
2. Threshold não foi atingido
3. Não há fotos antigas (anteriores ao período de retenção)

**Solução:**

- Verifique configurações em `config.php`
- Execute limpeza manual com opção "Forçar"
- Verifique logs do servidor (`error_log`)

### Problema: Erro ao deletar fotos

**Possíveis causas:**

1. Permissões insuficientes na pasta `public/photos/`
2. Arquivo em uso (raro)
3. Caminho incorreto

**Solução:**

```bash
# Verificar permissões (via SSH)
ls -la public/photos/

# Corrigir permissões se necessário
chmod 755 public/photos/
chown www-data:www-data public/photos/ -R
```

### Problema: Muitas fotos sendo deletadas

**Solução:**

1. Aumente `PHOTO_RETENTION_DAYS` em `config.php`
2. Aumente `PHOTO_STORAGE_THRESHOLD_MB` para retardar limpezas
3. Considere upgrade de armazenamento no servidor

## Migração e Instalação

### Instalação Completa (Novo Sistema)

O arquivo `install_production_complete.sql` já inclui as colunas necessárias.

### Migração (Sistema Existente)

Execute o script de migração:

```sql
-- Arquivo: add_photo_deleted_flag.sql
ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS photo_deleted TINYINT(1) DEFAULT 0 
COMMENT 'Flag indicando se a foto foi deletada automaticamente';

ALTER TABLE attendance 
ADD COLUMN IF NOT EXISTS photo_deleted_at DATETIME NULL 
COMMENT 'Data/hora em que a foto foi deletada';

CREATE INDEX IF NOT EXISTS idx_att_photo_cleanup 
ON attendance(photo_deleted, date);
```

## Conformidade Legal

### Portaria MTP 671/2021

A Portaria exige **5 anos de retenção** de registros de ponto. Para conformidade total:

```php
define('PHOTO_RETENTION_DAYS', 1825); // 5 anos
```

**Importante:** Os **dados do registro** (horários, localização, aprovação) nunca são deletados, apenas as **fotos**. Isso atende parcialmente à Portaria.

### LGPD

O sistema de limpeza automática auxilia na conformidade com o princípio de **minimização de dados** da LGPD:

- Dados pessoais (fotos) são mantidos apenas pelo necessário
- Exclusão automática reduz exposição a vazamentos
- Logs de exclusão permitem auditoria

## Boas Práticas

### Recomendações

1. **Backup**: Faça backup periódico da pasta `public/photos/` antes de limpezas manuais
2. **Monitoramento**: Acompanhe os KPIs no dashboard semanalmente
3. **Ajuste Gradual**: Comece com 90 dias e ajuste conforme necessário
4. **Teste**: Em ambientes de produção, execute limpeza manual primeiro
5. **Documentação**: Mantenha registro das configurações em documentação interna

### Alertas Sugeridos

Configure monitoramento externo para:

- Armazenamento > 80% do threshold (alerta amarelo)
- Armazenamento > 95% do threshold (alerta vermelho)
- Falhas repetidas na limpeza (checar logs)

## FAQ

**P: As fotos deletadas podem ser recuperadas?**

R: Não. A exclusão é permanente. Faça backups regulares se necessário.

**P: O registro de ponto é afetado pela exclusão da foto?**

R: Não. Apenas o arquivo de imagem é deletado. Horários, localização e status permanecem intactos.

**P: Posso desativar a limpeza automática?**

R: Sim. Defina `PHOTO_CLEANUP_ENABLED = false` em `config.php`.

**P: Quanto espaço cada foto ocupa?**

R: Em média, 100-300 KB por foto. Com 10.000 registros/mês, são ~2-3 GB/mês.

**P: A limpeza afeta a performance do sistema?**

R: Não significativamente. A limpeza executa apenas quando necessário e em background.

## Suporte

Para problemas relacionados ao sistema de limpeza de fotos:

1. Verifique logs do servidor
2. Consulte a seção Troubleshooting deste documento
3. Execute diagnóstico manual via painel administrativo
4. Em caso de dúvidas, contate o suporte técnico

---

**Sistema de Limpeza de Fotos - DEEDO Ponto v1.0**

*Última atualização: 22/10/2024*


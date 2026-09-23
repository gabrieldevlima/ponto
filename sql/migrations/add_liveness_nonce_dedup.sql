-- Migração: Tabela de deduplicação de nonces de liveness (anti-replay)
CREATE TABLE IF NOT EXISTS liveness_nonces (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    nonce VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_teacher_nonce (teacher_id, nonce),
    INDEX idx_nonce_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Limpar nonces antigos (> 5 minutos) — rodar periodicamente ou via trigger
-- A limpeza é feita no PHP antes de cada inserção

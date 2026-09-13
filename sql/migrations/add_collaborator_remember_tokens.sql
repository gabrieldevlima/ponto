-- Login persistente para colaboradores: tabela de tokens "lembrar-me".
-- Cada device autenticado tem uma linha; logout explícito remove a linha.
-- Token bruto vai num cookie HttpOnly; aqui guardamos somente o hash SHA-256.

CREATE TABLE IF NOT EXISTS collaborator_remember_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  user_agent VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_token_hash (token_hash),
  KEY idx_teacher (teacher_id),
  CONSTRAINT fk_remember_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

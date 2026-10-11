-- Datas em UTC no formato 'AAAA-MM-DD HH:MM:SS'.
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('solicitante', 'tecnico', 'admin') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  category VARCHAR(20) NOT NULL,
  impact VARCHAR(10) NOT NULL,
  urgency VARCHAR(10) NOT NULL,
  priority VARCHAR(10) NOT NULL,
  status VARCHAR(20) NOT NULL,
  requester_id INT UNSIGNED NOT NULL,
  assignee_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  first_response_at DATETIME NULL,
  resolved_at DATETIME NULL,
  closed_at DATETIME NULL,
  paused_at DATETIME NULL,
  paused_minutes INT NOT NULL DEFAULT 0,
  response_due_at DATETIME NOT NULL,
  resolution_due_at DATETIME NOT NULL,
  INDEX idx_tickets_status (status),
  INDEX idx_tickets_assignee (assignee_id),
  INDEX idx_tickets_requester (requester_id),
  INDEX idx_tickets_due (resolution_due_at),
  CONSTRAINT fk_tickets_requester FOREIGN KEY (requester_id) REFERENCES users(id),
  CONSTRAINT fk_tickets_assignee FOREIGN KEY (assignee_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_comments_ticket (ticket_id),
  CONSTRAINT fk_comments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL,
  from_value VARCHAR(60) NULL,
  to_value VARCHAR(60) NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_events_ticket (ticket_id),
  CONSTRAINT fk_events_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_login_attempts (email, created_at),
  INDEX idx_login_attempts_ip (ip, created_at) -- erros de login por IP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2FA (TOTP) e rate limit — tabelas novas no padrão; as antigas ficam como estão =====
-- Credencial do segundo fator, separada do perfil (users). Uma por usuário: user_id é a própria chave.
-- enabled_at NULL = QR gerado, mas ainda não confirmado com um código.
CREATE TABLE IF NOT EXISTS user_totp (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  secret_encrypted VARCHAR(255) NOT NULL, -- SENSÍVEL: AES-256-GCM com TOTP_ENCRYPTION_KEY
  enabled_at DATETIME NULL,
  last_used_step BIGINT NULL,             -- impede reusar o mesmo código dentro dos 30 s
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL ON UPDATE CURRENT_TIMESTAMP,
  -- CASCADE: a credencial não tem sentido sem o usuário
  CONSTRAINT fk_totp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recovery_codes (
  id CHAR(36) NOT NULL PRIMARY KEY,       -- UUID gerado pela aplicação (o SQLite não tem UUID nativo)
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,            -- SENSÍVEL: SHA-256 do código, nunca o código
  used_at DATETIME NULL,                  -- uso único
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL ON UPDATE CURRENT_TIMESTAMP,
  -- índice único com user_id primeiro: código único por conta e índice da chave estrangeira
  UNIQUE KEY uq_recovery_user_code (user_id, code_hash),
  -- CASCADE: códigos só valem para a conta dona
  CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contador do rate limit em janela fixa. No banco (e não em memória) porque cada requisição PHP é um processo novo.
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket VARCHAR(190) NOT NULL,           -- categoria + quem (ex.: "write:user:7", "login:ip:1.2.3.4")
  window_start BIGINT NOT NULL,           -- início da janela em segundos Unix
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

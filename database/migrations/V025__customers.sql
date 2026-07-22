CREATE TABLE `customers` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `email`                VARCHAR(255) NOT NULL UNIQUE,
  `password_hash`        VARCHAR(255) NOT NULL,
  `reset_token`          VARCHAR(64) NULL,
  `reset_token_expires`  DATETIME NULL,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `notifications` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `recipient_id` INT NOT NULL,
  `actor_id`     INT NULL,
  `actor_label`  VARCHAR(255) NOT NULL,
  `entity_type`  ENUM('category','product','service') NOT NULL,
  `entity_id`    INT NOT NULL,
  `entity_label` VARCHAR(255) NOT NULL,
  `action`       ENUM('created','updated','deleted') NOT NULL,
  `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_notifications_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_actor` FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_notifications_recipient_unread` (`recipient_id`, `is_read`),
  INDEX `idx_notifications_recipient_created` (`recipient_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

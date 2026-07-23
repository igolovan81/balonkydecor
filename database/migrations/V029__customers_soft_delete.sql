ALTER TABLE `customers`
  ADD COLUMN `deleted_at` DATETIME NULL AFTER `reset_token_expires`;

ALTER TABLE `notifications`
  MODIFY COLUMN `action` ENUM('created','updated','deleted','restored') NOT NULL;

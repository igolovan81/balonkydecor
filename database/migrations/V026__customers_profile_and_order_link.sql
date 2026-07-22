ALTER TABLE `customers`
  ADD COLUMN `name`  VARCHAR(255) NULL AFTER `email`,
  ADD COLUMN `phone` VARCHAR(50)  NULL AFTER `name`;

ALTER TABLE `orders`
  ADD COLUMN `customer_id` INT NULL AFTER `id`;

ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  ADD INDEX `idx_orders_customer` (`customer_id`);

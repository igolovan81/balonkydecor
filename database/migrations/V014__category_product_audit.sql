ALTER TABLE `categories`
  ADD COLUMN `created_by` int NULL AFTER `sort_order`,
  ADD COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`,
  ADD COLUMN `updated_by` int NULL AFTER `created_at`,
  ADD COLUMN `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `updated_by`;

ALTER TABLE `categories`
  ADD CONSTRAINT `fk_categories_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_categories_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `products`
  ADD COLUMN `created_by` int NULL AFTER `created_at`,
  ADD COLUMN `updated_by` int NULL AFTER `created_by`,
  ADD COLUMN `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `updated_by`;

ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_products_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

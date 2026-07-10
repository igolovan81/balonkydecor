ALTER TABLE `services`
  ADD COLUMN `created_by` int NULL AFTER `created_at`,
  ADD COLUMN `updated_by` int NULL AFTER `created_by`,
  ADD COLUMN `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `updated_by`;

ALTER TABLE `services`
  ADD CONSTRAINT `fk_services_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_services_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `gallery_albums`
  ADD COLUMN `created_by` int NULL AFTER `created_at`,
  ADD COLUMN `updated_by` int NULL AFTER `created_by`,
  ADD COLUMN `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `updated_by`;

ALTER TABLE `gallery_albums`
  ADD CONSTRAINT `fk_gallery_albums_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_gallery_albums_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `customers`
  ADD COLUMN `notification_lang` VARCHAR(5) NOT NULL DEFAULT 'cs' AFTER `phone`;

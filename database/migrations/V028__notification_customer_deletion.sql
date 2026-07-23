ALTER TABLE `notifications`
  MODIFY COLUMN `entity_type` ENUM('category','product','service','customer') NOT NULL;

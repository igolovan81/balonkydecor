CREATE TABLE `product_subtypes` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `price`      decimal(10,2) NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_subtype_t` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `subtype_id`  int NOT NULL,
  `lang_code`   varchar(5) NOT NULL,
  `name`        varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subtype_lang` (`subtype_id`,`lang_code`),
  FOREIGN KEY (`subtype_id`) REFERENCES `product_subtypes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `order_items`
  ADD COLUMN `subtype_id` int NULL AFTER `product_id`,
  ADD COLUMN `subtype_name_snapshot` varchar(255) NULL AFTER `product_name_snapshot`,
  ADD FOREIGN KEY (`subtype_id`) REFERENCES `product_subtypes`(`id`) ON DELETE SET NULL;

CREATE TABLE `product_specs` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_spec_t` (
  `id`               int NOT NULL AUTO_INCREMENT,
  `spec_id`          int NOT NULL,
  `lang_code`        varchar(5) NOT NULL,
  `attribute_name`   varchar(255) NOT NULL,
  `attribute_value`  text NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spec_lang` (`spec_id`,`lang_code`),
  FOREIGN KEY (`spec_id`) REFERENCES `product_specs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

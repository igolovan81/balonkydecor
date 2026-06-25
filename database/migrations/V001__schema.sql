SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE `languages` (
  `id`        int NOT NULL AUTO_INCREMENT,
  `code`      varchar(5) NOT NULL,
  `name`      varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `languages` (`code`, `name`) VALUES
  ('cs','Čeština'), ('ru','Русский'), ('en','English'),
  ('uk','Українська'), ('sk','Slovenčina');

CREATE TABLE `categories` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `slug`       varchar(100) NOT NULL,
  `image`      varchar(255) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `category_t` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `lang_code`   varchar(5) NOT NULL,
  `name`        varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cat_lang` (`category_id`,`lang_code`),
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `products` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `sku`         varchar(100) NOT NULL,
  `price`       decimal(10,2) NOT NULL,
  `stock_type`  enum('unlimited','limited') NOT NULL DEFAULT 'unlimited',
  `stock_qty`   int NOT NULL DEFAULT 0,
  `is_active`   tinyint(1) NOT NULL DEFAULT 1,
  `sort_order`  int NOT NULL DEFAULT 0,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_t` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `product_id`  int NOT NULL,
  `lang_code`   varchar(5) NOT NULL,
  `name`        varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `meta_title`  varchar(255) DEFAULT NULL,
  `meta_desc`   varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_lang` (`product_id`,`lang_code`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_images` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `filename`   varchar(255) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `orders` (
  `id`               int NOT NULL AUTO_INCREMENT,
  `order_number`     varchar(20) NOT NULL,
  `status`           enum('pending','paid','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  `customer_name`    varchar(255) NOT NULL,
  `customer_email`   varchar(255) NOT NULL,
  `customer_phone`   varchar(50) NOT NULL,
  `pickup_date`      date DEFAULT NULL,
  `total_amount`     decimal(10,2) NOT NULL,
  `gopay_payment_id` varchar(100) DEFAULT NULL,
  `notes`            text DEFAULT NULL,
  `created_at`       datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `order_items` (
  `id`                    int NOT NULL AUTO_INCREMENT,
  `order_id`              int NOT NULL,
  `product_id`            int DEFAULT NULL,
  `quantity`              int NOT NULL,
  `unit_price`            decimal(10,2) NOT NULL,
  `product_name_snapshot` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `gallery_albums` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `slug`        varchar(100) NOT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `sort_order`  int NOT NULL DEFAULT 0,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `gallery_album_t` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `album_id`    int NOT NULL,
  `lang_code`   varchar(5) NOT NULL,
  `name`        varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `album_lang` (`album_id`,`lang_code`),
  FOREIGN KEY (`album_id`) REFERENCES `gallery_albums`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `gallery_images` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `album_id`   int NOT NULL,
  `filename`   varchar(255) NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`album_id`) REFERENCES `gallery_albums`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `blog_posts` (
  `id`           int NOT NULL AUTO_INCREMENT,
  `slug`         varchar(255) NOT NULL,
  `author_id`    int DEFAULT NULL,
  `status`       enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `blog_post_t` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `post_id`    int NOT NULL,
  `lang_code`  varchar(5) NOT NULL,
  `title`      varchar(255) NOT NULL,
  `body`       longtext DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_desc`  varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `post_lang` (`post_id`,`lang_code`),
  FOREIGN KEY (`post_id`) REFERENCES `blog_posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pages` (
  `id`   int NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `pages` (`slug`) VALUES ('home'), ('services'), ('contact');

CREATE TABLE `page_t` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `page_id`    int NOT NULL,
  `lang_code`  varchar(5) NOT NULL,
  `title`      varchar(255) NOT NULL DEFAULT '',
  `body`       longtext DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_desc`  varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page_lang` (`page_id`,`lang_code`),
  FOREIGN KEY (`page_id`) REFERENCES `pages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `users` (
  `id`            int NOT NULL AUTO_INCREMENT,
  `email`         varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role`          enum('admin','editor') NOT NULL DEFAULT 'editor',
  `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `settings` (
  `key`   varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('site_name',           'BalonkyDecor'),
  ('contact_email',       ''),
  ('contact_phone',       ''),
  ('contact_address',     ''),
  ('gopay_go_id',         ''),
  ('gopay_client_id',     ''),
  ('gopay_client_secret', ''),
  ('gopay_test_mode',     '1'),
  ('smtp_host',           ''),
  ('smtp_port',           '587'),
  ('smtp_user',           ''),
  ('smtp_pass',           ''),
  ('smtp_from',           '');

CREATE TABLE `schema_migrations` (
  `version`    varchar(255) NOT NULL,
  `applied_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

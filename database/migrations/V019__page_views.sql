CREATE TABLE `page_views` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `path`        varchar(255) NOT NULL,
  `lang`        varchar(5) NOT NULL,
  `referrer`    varchar(500) DEFAULT NULL,
  `ip_anon`     varchar(45) DEFAULT NULL,
  `user_agent`  varchar(255) DEFAULT NULL,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_page_views_created_at` (`created_at`),
  KEY `idx_page_views_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

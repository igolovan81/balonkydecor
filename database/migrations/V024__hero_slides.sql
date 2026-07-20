CREATE TABLE `hero_slides` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `image`      varchar(255) DEFAULT NULL,
  `cta_url`    varchar(255) NOT NULL DEFAULT '/shop',
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_hero_slides_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hero_slides_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `hero_slide_t` (
  `id`        int NOT NULL AUTO_INCREMENT,
  `slide_id`  int NOT NULL,
  `lang_code` varchar(5) NOT NULL,
  `title`     varchar(255) NOT NULL,
  `subtitle`  varchar(500) DEFAULT NULL,
  `cta_label` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slide_lang` (`slide_id`,`lang_code`),
  CONSTRAINT `fk_hero_slide_t_slide` FOREIGN KEY (`slide_id`) REFERENCES `hero_slides`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: one default slide reusing the current plain-text hero copy, so the
-- homepage carousel is never empty out of the box. image stays NULL —
-- www/assets/uploads/ is gitignored, so no real file ships with this
-- migration; the public template renders a placeholder for a NULL image.
INSERT IGNORE INTO hero_slides (id, image, cta_url, is_active, sort_order) VALUES (1, NULL, '/shop', 1, 10);
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'cs', 'Krásné balónky pro každou příležitost', 'Hélium, balónky a dekorace na míru', 'Prohlédnout nabídku');
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'en', 'Beautiful balloons for every occasion', 'Helium, balloons and custom decorations', 'Browse our range');
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'ru', 'Красивые шары для любого праздника', 'Гелий, шары и украшения на заказ', 'Смотреть каталог');
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'uk', 'Красиві кульки для кожного свята', 'Гелій, кульки та прикраси на замовлення', 'Переглянути каталог');
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'sk', 'Krásne balóny pre každú príležitosť', 'Hélium, balóny a dekorácie na mieru', 'Prezrieť ponuku');

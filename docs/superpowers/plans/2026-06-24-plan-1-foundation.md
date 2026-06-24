# BalonkyDecor — Plan 1: Foundation & Scaffolding

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A working Slim 4 application with language routing, i18n, Twig templating, MySQL connection, and a rendered home page — the skeleton every subsequent plan builds on.

**Architecture:** Slim 4 as front controller; PHP-DI 7 for the DI container; Twig 3 for templates. All source code lives outside `www/` (WEDOS web root); only `www/index.php`, `.htaccess`, and `www/assets/` are publicly accessible. `LangMiddleware` extracts `/{lang}/` from the URL and attaches an `I18n` instance to every request before any controller runs.

**Tech Stack:** PHP 8.1+, Slim 4.12, slim/psr7, PHP-DI 7, Twig 3, slim/twig-view 3, PHPUnit 11, PDO/MySQL (MariaDB on WEDOS)

## Global Constraints

- PHP minimum: 8.1
- All source files (`src/`, `vendor/`, `templates/`, `lang/`) outside `www/` (web root)
- Public URL structure: `/{lang}/{path}` — lang codes: `cs`, `ru`, `en`, `uk`, `sk`; default: `cs`
- Admin URL structure: `/admin/{path}` (no lang prefix; implemented in Plan 4)
- GoPay IPN webhook: `/payment/notify` (no lang prefix — server-to-server call)
- Design: elegant & clean — white/pastel palette (`#fafaf8` bg, `#b8967a` accent), refined serif body font
- No WordPress, no Laravel; Slim 4 + PDO only

---

### Task 1: Composer Setup & Project Scaffolding

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `config/settings.php`
- Create: `tests/bootstrap.php`
- Create: `.gitignore` (repo root)
- Create: `www/assets/uploads/.gitkeep`, `www/assets/css/.gitkeep`, `www/assets/js/.gitkeep`

**Interfaces:**
- Produces: `App\` PSR-4 namespace autoloaded from `src/`; `Tests\` from `tests/`

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "balonkydecor/website",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "slim/slim": "^4.12",
        "slim/psr7": "^1.6",
        "php-di/php-di": "^7.0",
        "slim/twig-view": "^3.4",
        "twig/twig": "^3.8"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "config": {
        "optimize-autoloader": true
    }
}
```

- [ ] **Step 2: Run Composer install**

```bash
composer install
```

Expected: `vendor/` created, no errors.

- [ ] **Step 3: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="BalonkyDecor">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 4: Create `config/settings.php`**

```php
<?php
return [
    'displayErrorDetails' => true,   // set false in production
    'db' => [
        'host'    => 'localhost',
        'name'    => 'balonkydecor',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'languages'       => ['cs', 'ru', 'en', 'uk', 'sk'],
    'default_lang'    => 'cs',
    'upload_dir'      => __DIR__ . '/../www/assets/uploads/',
    'upload_url'      => '/assets/uploads/',
    'thumb_width'     => 400,
    'image_max_width' => 1600,
];
```

- [ ] **Step 5: Create `tests/bootstrap.php`**

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 6: Create `.gitignore` at repo root**

```
/vendor/
/composer.lock
/config/settings.local.php
/tmp/twig_cache/
www/assets/uploads/*
!www/assets/uploads/.gitkeep
```

- [ ] **Step 7: Create asset placeholder files**

```bash
mkdir -p www/assets/uploads www/assets/css www/assets/js tmp/twig_cache
touch www/assets/uploads/.gitkeep www/assets/css/.gitkeep www/assets/js/.gitkeep
```

- [ ] **Step 8: Verify PHPUnit is available**

```bash
./vendor/bin/phpunit --version
```

Expected: `PHPUnit 11.x.x by Sebastian Bergmann and contributors.`

- [ ] **Step 9: Commit**

```bash
git add composer.json phpunit.xml config/settings.php tests/bootstrap.php .gitignore www/assets/
git commit -m "feat: composer setup and project scaffolding"
```

---

### Task 2: Database Schema & PDO Connection

**Files:**
- Create: `database/schema.sql`
- Create: `src/Models/Database.php`
- Create: `tests/Unit/Models/DatabaseTest.php`

**Interfaces:**
- Produces: `Database::getConnection(): PDO` — returns a singleton PDO configured from `config/settings.php`

- [ ] **Step 1: Create `database/schema.sql`**

```sql
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
  `id`         int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `lang_code`  varchar(5) NOT NULL,
  `name`       varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_desc`  varchar(500) DEFAULT NULL,
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
  `id`                int NOT NULL AUTO_INCREMENT,
  `order_number`      varchar(20) NOT NULL,
  `status`            enum('pending','paid','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  `customer_name`     varchar(255) NOT NULL,
  `customer_email`    varchar(255) NOT NULL,
  `customer_phone`    varchar(50) NOT NULL,
  `pickup_date`       date DEFAULT NULL,
  `total_amount`      decimal(10,2) NOT NULL,
  `gopay_payment_id`  varchar(100) DEFAULT NULL,
  `notes`             text DEFAULT NULL,
  `created_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
```

- [ ] **Step 2: Import schema into MySQL**

Open phpMyAdmin → select your database → Import tab → upload `database/schema.sql` → Go.

Expected: 14 tables created, no errors.

- [ ] **Step 3: Create `src/Models/Database.php`**

```php
<?php
namespace App\Models;

use PDO;

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $settings = require __DIR__ . '/../../config/settings.php';
            $db  = $settings['db'];
            $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
            self::$connection = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$connection;
    }
}
```

- [ ] **Step 4: Create `tests/Unit/Models/DatabaseTest.php`**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function test_connection_returns_pdo(): void
    {
        $pdo = Database::getConnection();
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function test_connection_is_singleton(): void
    {
        $this->assertSame(Database::getConnection(), Database::getConnection());
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Models/DatabaseTest.php --testdox
```

Expected: 2 tests, 2 passed. (Requires local MySQL with credentials matching `config/settings.php`.)

- [ ] **Step 6: Commit**

```bash
git add database/schema.sql src/Models/Database.php tests/Unit/Models/DatabaseTest.php
git commit -m "feat: database schema and PDO connection singleton"
```

---

### Task 3: I18n Service & Language Files

**Files:**
- Create: `src/Services/I18n.php`
- Create: `lang/cs.json`
- Create: `lang/ru.json`
- Create: `lang/en.json`
- Create: `lang/uk.json`
- Create: `lang/sk.json`
- Create: `tests/Unit/Services/I18nTest.php`

**Interfaces:**
- Produces:
  - `I18n::__construct(string $lang, string $langDir)`
  - `I18n::t(string $key, array $params = []): string` — returns translated string; interpolates `{param}` placeholders; falls back to `$key` if missing
  - `I18n::getLang(): string`

- [ ] **Step 1: Create `tests/Unit/Services/I18nTest.php`**

```php
<?php
namespace Tests\Unit\Services;

use App\Services\I18n;
use PHPUnit\Framework\TestCase;

class I18nTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/balonky_i18n_' . uniqid();
        mkdir($this->langDir);
        file_put_contents($this->langDir . '/en.json', json_encode([
            'nav.home'  => 'Home',
            'greeting'  => 'Hello, {name}!',
        ]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->langDir . '/*.json'));
        rmdir($this->langDir);
    }

    public function test_translates_known_key(): void
    {
        $i18n = new I18n('en', $this->langDir);
        $this->assertSame('Home', $i18n->t('nav.home'));
    }

    public function test_returns_key_when_missing(): void
    {
        $i18n = new I18n('en', $this->langDir);
        $this->assertSame('missing.key', $i18n->t('missing.key'));
    }

    public function test_interpolates_params(): void
    {
        $i18n = new I18n('en', $this->langDir);
        $this->assertSame('Hello, Igor!', $i18n->t('greeting', ['name' => 'Igor']));
    }

    public function test_get_lang(): void
    {
        $i18n = new I18n('en', $this->langDir);
        $this->assertSame('en', $i18n->getLang());
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Services/I18nTest.php --testdox
```

Expected: FAIL — `App\Services\I18n` not found.

- [ ] **Step 3: Create `src/Services/I18n.php`**

```php
<?php
namespace App\Services;

class I18n
{
    private array $strings = [];

    public function __construct(private string $lang, string $langDir)
    {
        $file = $langDir . '/' . $lang . '.json';
        if (file_exists($file)) {
            $this->strings = json_decode(file_get_contents($file), true) ?? [];
        }
    }

    public function t(string $key, array $params = []): string
    {
        $str = $this->strings[$key] ?? $key;
        foreach ($params as $k => $v) {
            $str = str_replace('{' . $k . '}', (string) $v, $str);
        }
        return $str;
    }

    public function getLang(): string
    {
        return $this->lang;
    }
}
```

- [ ] **Step 4: Run tests to confirm all pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/I18nTest.php --testdox
```

Expected: 4 tests, 4 passed.

- [ ] **Step 5: Create `lang/cs.json`**

```json
{
  "nav.home": "Domů",
  "nav.shop": "Obchod",
  "nav.services": "Služby",
  "nav.gallery": "Galerie",
  "nav.blog": "Blog",
  "nav.contact": "Kontakt",
  "nav.cart": "Košík",
  "site.name": "BalonkyDecor",
  "home.hero_title": "Krásné balónky pro každou příležitost",
  "home.hero_subtitle": "Hélium, balónky a dekorace na míru",
  "home.cta": "Prohlédnout nabídku",
  "cart.empty": "Košík je prázdný",
  "cart.total": "Celkem",
  "checkout.pickup_date": "Datum vyzvednutí",
  "order.status.pending": "Čeká na platbu",
  "order.status.paid": "Zaplaceno",
  "order.status.ready": "Připraveno k vyzvednutí",
  "order.status.completed": "Dokončeno",
  "order.status.cancelled": "Zrušeno"
}
```

- [ ] **Step 6: Create `lang/en.json`**

```json
{
  "nav.home": "Home",
  "nav.shop": "Shop",
  "nav.services": "Services",
  "nav.gallery": "Gallery",
  "nav.blog": "Blog",
  "nav.contact": "Contact",
  "nav.cart": "Cart",
  "site.name": "BalonkyDecor",
  "home.hero_title": "Beautiful balloons for every occasion",
  "home.hero_subtitle": "Helium, balloons and custom decorations",
  "home.cta": "Browse our range",
  "cart.empty": "Your cart is empty",
  "cart.total": "Total",
  "checkout.pickup_date": "Pickup date",
  "order.status.pending": "Awaiting payment",
  "order.status.paid": "Paid",
  "order.status.ready": "Ready for pickup",
  "order.status.completed": "Completed",
  "order.status.cancelled": "Cancelled"
}
```

- [ ] **Step 7: Create `lang/ru.json`**

```json
{
  "nav.home": "Главная",
  "nav.shop": "Магазин",
  "nav.services": "Услуги",
  "nav.gallery": "Галерея",
  "nav.blog": "Блог",
  "nav.contact": "Контакты",
  "nav.cart": "Корзина",
  "site.name": "BalonkyDecor",
  "home.hero_title": "Красивые шары для любого праздника",
  "home.hero_subtitle": "Гелий, шары и украшения на заказ",
  "home.cta": "Смотреть каталог",
  "cart.empty": "Корзина пуста",
  "cart.total": "Итого",
  "checkout.pickup_date": "Дата получения",
  "order.status.pending": "Ожидает оплаты",
  "order.status.paid": "Оплачено",
  "order.status.ready": "Готово к получению",
  "order.status.completed": "Завершено",
  "order.status.cancelled": "Отменено"
}
```

- [ ] **Step 8: Create `lang/uk.json`**

```json
{
  "nav.home": "Головна",
  "nav.shop": "Магазин",
  "nav.services": "Послуги",
  "nav.gallery": "Галерея",
  "nav.blog": "Блог",
  "nav.contact": "Контакти",
  "nav.cart": "Кошик",
  "site.name": "BalonkyDecor",
  "home.hero_title": "Красиві кульки для кожного свята",
  "home.hero_subtitle": "Гелій, кульки та прикраси на замовлення",
  "home.cta": "Переглянути каталог",
  "cart.empty": "Кошик порожній",
  "cart.total": "Разом",
  "checkout.pickup_date": "Дата отримання",
  "order.status.pending": "Очікує оплати",
  "order.status.paid": "Оплачено",
  "order.status.ready": "Готово до отримання",
  "order.status.completed": "Завершено",
  "order.status.cancelled": "Скасовано"
}
```

- [ ] **Step 9: Create `lang/sk.json`**

```json
{
  "nav.home": "Domov",
  "nav.shop": "Obchod",
  "nav.services": "Služby",
  "nav.gallery": "Galéria",
  "nav.blog": "Blog",
  "nav.contact": "Kontakt",
  "nav.cart": "Košík",
  "site.name": "BalonkyDecor",
  "home.hero_title": "Krásne balóny pre každú príležitosť",
  "home.hero_subtitle": "Hélium, balóny a dekorácie na mieru",
  "home.cta": "Prezrieť ponuku",
  "cart.empty": "Košík je prázdny",
  "cart.total": "Spolu",
  "checkout.pickup_date": "Dátum vyzdvihnutia",
  "order.status.pending": "Čaká na platbu",
  "order.status.paid": "Zaplatené",
  "order.status.ready": "Pripravené na vyzdvihnutie",
  "order.status.completed": "Dokončené",
  "order.status.cancelled": "Zrušené"
}
```

- [ ] **Step 10: Commit**

```bash
git add src/Services/I18n.php lang/ tests/Unit/Services/I18nTest.php
git commit -m "feat: I18n service and 5-language JSON files"
```

---

### Task 4: LangMiddleware & App Bootstrap

**Files:**
- Create: `src/Middleware/LangMiddleware.php`
- Create: `src/Twig/I18nExtension.php`
- Create: `src/app.php`
- Create: `src/routes.php`
- Create: `www/index.php`
- Modify: `www/.htaccess`
- Create: `tests/Unit/Middleware/LangMiddlewareTest.php`

**Interfaces:**
- Consumes: `I18n::__construct(string $lang, string $langDir)` (Task 3)
- Produces:
  - `LangMiddleware::process()` — attaches `i18n` (I18n) and `lang` (string) to request attributes
  - `I18nExtension` — registers Twig function `t(key, params=[])` from the request-scoped I18n instance
  - `$app` from `src/app.php` — configured Slim App ready to run

- [ ] **Step 1: Create `tests/Unit/Middleware/LangMiddlewareTest.php`**

```php
<?php
namespace Tests\Unit\Middleware;

use App\Middleware\LangMiddleware;
use App\Services\I18n;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LangMiddlewareTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/balonky_mw_' . uniqid();
        mkdir($this->langDir);
        foreach (['cs','ru','en','uk','sk'] as $code) {
            file_put_contents($this->langDir . "/{$code}.json", json_encode(['k' => $code]));
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->langDir . '/*.json'));
        rmdir($this->langDir);
    }

    private function captureHandler(string &$lang, string &$i18nLang): RequestHandlerInterface
    {
        return new class($lang, $i18nLang) implements RequestHandlerInterface {
            public function __construct(private string &$lang, private string &$i18nLang) {}
            public function handle(ServerRequestInterface $req): ResponseInterface {
                $this->lang     = $req->getAttribute('lang', '');
                $i18n = $req->getAttribute('i18n');
                $this->i18nLang = $i18n instanceof I18n ? $i18n->getLang() : '';
                return (new ResponseFactory())->createResponse();
            }
        };
    }

    public function test_extracts_lang_from_url(): void
    {
        $lang = ''; $i18nLang = '';
        $mw  = new LangMiddleware(['cs','ru','en','uk','sk'], 'cs', $this->langDir);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/ru/shop');
        $mw->process($req, $this->captureHandler($lang, $i18nLang));
        $this->assertSame('ru', $lang);
        $this->assertSame('ru', $i18nLang);
    }

    public function test_defaults_when_no_lang_prefix(): void
    {
        $lang = ''; $i18nLang = '';
        $mw  = new LangMiddleware(['cs','ru','en','uk','sk'], 'cs', $this->langDir);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/login');
        $mw->process($req, $this->captureHandler($lang, $i18nLang));
        $this->assertSame('cs', $lang);
    }

    public function test_unsupported_segment_falls_back_to_default(): void
    {
        $lang = ''; $i18nLang = '';
        $mw  = new LangMiddleware(['cs','ru','en','uk','sk'], 'cs', $this->langDir);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/fr/whatever');
        $mw->process($req, $this->captureHandler($lang, $i18nLang));
        $this->assertSame('cs', $lang);
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Middleware/LangMiddlewareTest.php --testdox
```

Expected: FAIL — `App\Middleware\LangMiddleware` not found.

- [ ] **Step 3: Create `src/Middleware/LangMiddleware.php`**

```php
<?php
namespace App\Middleware;

use App\Services\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LangMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array  $supported,
        private string $default,
        private string $langDir
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $segment = explode('/', ltrim($request->getUri()->getPath(), '/'))[0];
        $lang    = in_array($segment, $this->supported, true) ? $segment : $this->default;

        return $handler->handle(
            $request
                ->withAttribute('lang', $lang)
                ->withAttribute('i18n', new I18n($lang, $this->langDir))
        );
    }
}
```

- [ ] **Step 4: Run tests to confirm all pass**

```bash
./vendor/bin/phpunit tests/Unit/Middleware/LangMiddlewareTest.php --testdox
```

Expected: 3 tests, 3 passed.

- [ ] **Step 5: Create `src/Twig/I18nExtension.php`**

```php
<?php
namespace App\Twig;

use App\Services\I18n;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class I18nExtension extends AbstractExtension
{
    public function __construct(private I18n $i18n) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', fn(string $key, array $p = []) => $this->i18n->t($key, $p)),
        ];
    }
}
```

- [ ] **Step 6: Create `src/app.php`**

```php
<?php
use App\Middleware\LangMiddleware;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';

$builder = new ContainerBuilder();
$builder->addDefinitions([
    'settings' => $settings,
    Twig::class => fn() => Twig::create(
        __DIR__ . '/../templates',
        ['cache' => false]   // production: __DIR__ . '/../tmp/twig_cache'
    ),
]);
$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addErrorMiddleware($settings['displayErrorDetails'], true, true);
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app->add(new LangMiddleware(
    $settings['languages'],
    $settings['default_lang'],
    __DIR__ . '/../lang'
));
$app->addRoutingMiddleware();

require __DIR__ . '/routes.php';

return $app;
```

- [ ] **Step 7: Create `src/routes.php`**

```php
<?php
use App\Controllers\BlogController;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\ContactController;
use App\Controllers\GalleryController;
use App\Controllers\HomeController;
use App\Controllers\OrderController;
use App\Controllers\PageController;
use App\Controllers\PaymentController;
use App\Controllers\ShopController;
use Slim\App;

/** @var App $app */

// Redirect bare root to default language
$app->get('/', function ($req, $res) {
    return $res->withHeader('Location', '/cs/')->withStatus(302);
});

// Public
$app->get('/{lang}/',                   HomeController::class    . ':index');
$app->get('/{lang}/shop',               ShopController::class    . ':index');
$app->get('/{lang}/shop/{slug}',        ShopController::class    . ':product');
$app->get('/{lang}/services',           PageController::class    . ':services');
$app->get('/{lang}/gallery',            GalleryController::class . ':index');
$app->get('/{lang}/gallery/{slug}',     GalleryController::class . ':album');
$app->get('/{lang}/blog',               BlogController::class    . ':index');
$app->get('/{lang}/blog/{slug}',        BlogController::class    . ':post');
$app->get('/{lang}/contact',            ContactController::class . ':index');
$app->post('/{lang}/contact',           ContactController::class . ':send');
$app->get('/{lang}/cart',               CartController::class    . ':index');
$app->post('/{lang}/cart/add',          CartController::class    . ':add');
$app->post('/{lang}/cart/remove',       CartController::class    . ':remove');
$app->get('/{lang}/checkout',           CheckoutController::class . ':index');
$app->post('/{lang}/checkout',          CheckoutController::class . ':submit');
$app->get('/{lang}/checkout/confirm',   CheckoutController::class . ':confirm');
$app->post('/{lang}/payment/gopay',     PaymentController::class . ':initiate');
$app->get('/{lang}/payment/return',     PaymentController::class . ':paymentReturn');
$app->post('/payment/notify',           PaymentController::class . ':notify');
$app->get('/{lang}/order/{number}',     OrderController::class   . ':status');

// Admin routes — added in Plan 4
```

- [ ] **Step 8: Create `www/index.php`**

```php
<?php
declare(strict_types=1);

$app = require __DIR__ . '/../src/app.php';
$app->run();
```

- [ ] **Step 9: Update `www/.htaccess`**

Replace entire file contents with:

```apache
# htaccess rules for subdomains and aliases
# to create new subdomain, create a folder www/subdom/(subdomain name)
# to create web for alias, create a folder www/domains/(whole domain name)

# htaccess pravidla pro subdomeny a samostatne weby aliasu
# pro vytvoreni subdomeny vytvorte adresar www/subdom/(nazev subdomeny)
# pro vytvoreni webu pro alias vytvorte adresar www/domains/(cely domenovy nazev)
# dalsi info a priklady: https://kb.wedos.com/cs/webhosting/htaccess/htaccess-na-webhostingu

RewriteEngine On

# cele domeny (aliasy)
RewriteCond %{REQUEST_URI} !^domains/
RewriteCond %{REQUEST_URI} !^/domains/
RewriteCond %{HTTP_HOST} ^(www\.)?(.*)$
RewriteCond %{DOCUMENT_ROOT}/domains/%2 -d
RewriteRule (.*) domains/%2/$1 [DPI]

# subdomeny (s nebo bez www na zacatku)
RewriteCond %{REQUEST_URI} !^subdom/
RewriteCond %{REQUEST_URI} !^/subdom/
RewriteCond %{HTTP_HOST} ^(www\.)?(.*)\.([^\.]*)\.([^\.]*)$
RewriteCond %{DOCUMENT_ROOT}/subdom/%2 -d
RewriteRule (.*) subdom/%2/$1 [DPI]

# aliasy - spravne presmerovani pri chybejicim /
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^domains/[^/]+/(.+[^/])$ /$1/ [R]

# subdomeny - spravne presmerovani pri chybejicim /
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^subdom/[^/]+/(.+[^/])$ /$1/ [R]

# Slim — route all non-file, non-directory requests to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

- [ ] **Step 10: Commit**

```bash
git add src/Middleware/ src/Twig/ src/app.php src/routes.php www/index.php www/.htaccess tests/Unit/Middleware/
git commit -m "feat: Slim bootstrap, LangMiddleware, Twig I18n extension, routing"
```

---

### Task 5: Base Templates, CSS & Stub Controllers

**Files:**
- Create: `templates/layout/base.twig`
- Create: `templates/layout/admin-base.twig`
- Create: `templates/public/home.twig`
- Create: `www/assets/css/style.css`
- Create: `src/Controllers/HomeController.php`
- Create stubs: `src/Controllers/ShopController.php`, `PageController.php`, `GalleryController.php`, `BlogController.php`, `ContactController.php`, `CartController.php`, `CheckoutController.php`, `PaymentController.php`, `OrderController.php`

**Interfaces:**
- Consumes: `lang` and `i18n` request attributes (Task 4); `I18nExtension` (Task 4)
- Produces: `HomeController::index()` renders `public/home.twig` via Twig; all other stub controllers return plain text "coming soon" so no route 500s

- [ ] **Step 1: Create `templates/layout/base.twig`**

```twig
<!DOCTYPE html>
<html lang="{{ lang }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}{{ t('site.name') }}{% endblock %}</title>
    <meta name="description" content="{% block meta_desc %}{% endblock %}">
    <link rel="stylesheet" href="/assets/css/style.css">
    {% block head %}{% endblock %}
</head>
<body>
    <header class="site-header">
        <div class="container header-inner">
            <a href="/{{ lang }}/" class="logo">{{ t('site.name') }}</a>
            <nav class="main-nav">
                <a href="/{{ lang }}/">{{ t('nav.home') }}</a>
                <a href="/{{ lang }}/shop">{{ t('nav.shop') }}</a>
                <a href="/{{ lang }}/services">{{ t('nav.services') }}</a>
                <a href="/{{ lang }}/gallery">{{ t('nav.gallery') }}</a>
                <a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>
                <a href="/{{ lang }}/contact">{{ t('nav.contact') }}</a>
                <a href="/{{ lang }}/cart" class="cart-link">{{ t('nav.cart') }}</a>
            </nav>
            <div class="lang-switcher">
                {% for code, label in {'cs': 'CZ', 'ru': 'RU', 'en': 'EN', 'uk': 'UA', 'sk': 'SK'} %}
                    <a href="/{{ code }}{{ current_path }}"
                       class="{{ code == lang ? 'active' : '' }}">{{ label }}</a>
                {% endfor %}
            </div>
        </div>
    </header>

    <main>{% block content %}{% endblock %}</main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }}</p>
        </div>
    </footer>

    {% block scripts %}{% endblock %}
</body>
</html>
```

- [ ] **Step 2: Create `templates/layout/admin-base.twig`**

```twig
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}Admin{% endblock %} — BalonkyDecor</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    {% block head %}{% endblock %}
</head>
<body class="admin-body">
    <aside class="admin-sidebar">
        <div class="admin-logo">BalonkyDecor</div>
        <nav class="admin-nav">
            <a href="/admin">Dashboard</a>
            <a href="/admin/products">Produkty</a>
            <a href="/admin/categories">Kategorie</a>
            <a href="/admin/orders">Objednávky</a>
            <a href="/admin/gallery">Galerie</a>
            <a href="/admin/blog">Blog</a>
            <a href="/admin/pages">Stránky</a>
            <a href="/admin/translations">Překlady</a>
            <a href="/admin/settings">Nastavení</a>
            <a href="/admin/users">Uživatelé</a>
        </nav>
    </aside>
    <main class="admin-main">
        {% block content %}{% endblock %}
    </main>
    {% block scripts %}{% endblock %}
</body>
</html>
```

- [ ] **Step 3: Create `templates/public/home.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('home.hero_title') }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="hero">
    <div class="container">
        <h1>{{ t('home.hero_title') }}</h1>
        <p class="hero-subtitle">{{ t('home.hero_subtitle') }}</p>
        <a href="/{{ lang }}/shop" class="btn btn-primary">{{ t('home.cta') }}</a>
    </div>
</section>
{% endblock %}
```

- [ ] **Step 4: Create `www/assets/css/style.css`**

```css
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #fafaf8;
    --text:     #2c2c2c;
    --muted:    #888;
    --accent:   #b8967a;
    --border:   #e8e3dc;
    --font:     Georgia, serif;
    --ui-font:  system-ui, sans-serif;
    --width:    1100px;
}

body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 1rem; line-height: 1.7; }
.container { max-width: var(--width); margin: 0 auto; padding: 0 1.5rem; }

/* Header */
.site-header { background: #fff; border-bottom: 1px solid var(--border); padding: 1rem 0; }
.header-inner { display: flex; align-items: center; gap: 2rem; }
.logo { font-size: 1.4rem; color: var(--accent); text-decoration: none; letter-spacing: .04em; font-weight: bold; }
.main-nav { display: flex; gap: 1.5rem; flex: 1; }
.main-nav a { color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; }
.main-nav a:hover { color: var(--accent); }
.lang-switcher { display: flex; gap: .5rem; font-family: var(--ui-font); font-size: .8rem; }
.lang-switcher a { color: var(--muted); text-decoration: none; }
.lang-switcher a.active { color: var(--accent); font-weight: bold; }

/* Hero */
.hero { padding: 6rem 0; text-align: center; background: linear-gradient(to bottom,#fff,var(--bg)); }
.hero h1 { font-size: 2.8rem; font-weight: normal; margin-bottom: 1rem; }
.hero-subtitle { color: var(--muted); font-size: 1.2rem; margin-bottom: 2rem; }

/* Buttons */
.btn { display: inline-block; padding: .75rem 2rem; border-radius: 2px; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .08em; text-decoration: none; border: none; cursor: pointer; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #a0806a; }

/* Footer */
.site-footer { border-top: 1px solid var(--border); padding: 2rem 0; text-align: center; color: var(--muted); font-family: var(--ui-font); font-size: .85rem; margin-top: 4rem; }
```

- [ ] **Step 5: Create `src/Controllers/HomeController.php`**

```php
<?php
namespace App\Controllers;

use App\Services\I18n;
use App\Twig\I18nExtension;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HomeController
{
    public function __construct(private Twig $twig) {}

    public function index(Request $request, Response $response, array $args): Response
    {
        /** @var I18n $i18n */
        $i18n = $request->getAttribute('i18n');
        $lang = $request->getAttribute('lang');

        $env = $this->twig->getEnvironment();
        if (!$env->hasExtension(I18nExtension::class)) {
            $env->addExtension(new I18nExtension($i18n));
        }

        return $this->twig->render($response, 'public/home.twig', [
            'lang'         => $lang,
            'current_path' => '/',
        ]);
    }
}
```

- [ ] **Step 6: Create stub controllers**

Create each file exactly as shown (all stubs follow the same pattern):

`src/Controllers/ShopController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class ShopController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Shop — coming soon'); return $res;
    }
    public function product(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Product — coming soon'); return $res;
    }
}
```

`src/Controllers/PageController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class PageController {
    public function __construct(private Twig $twig) {}
    public function services(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Services — coming soon'); return $res;
    }
}
```

`src/Controllers/GalleryController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class GalleryController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Gallery — coming soon'); return $res;
    }
    public function album(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Album — coming soon'); return $res;
    }
}
```

`src/Controllers/BlogController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class BlogController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Blog — coming soon'); return $res;
    }
    public function post(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Post — coming soon'); return $res;
    }
}
```

`src/Controllers/ContactController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class ContactController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Contact — coming soon'); return $res;
    }
    public function send(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Sent — coming soon'); return $res;
    }
}
```

`src/Controllers/CartController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class CartController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Cart — coming soon'); return $res;
    }
    public function add(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Added'); return $res;
    }
    public function remove(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Removed'); return $res;
    }
}
```

`src/Controllers/CheckoutController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class CheckoutController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Checkout — coming soon'); return $res;
    }
    public function submit(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Submitted'); return $res;
    }
    public function confirm(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Confirm — coming soon'); return $res;
    }
}
```

`src/Controllers/PaymentController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class PaymentController {
    public function __construct(private Twig $twig) {}
    public function initiate(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Payment — coming soon'); return $res;
    }
    public function paymentReturn(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Payment return — coming soon'); return $res;
    }
    public function notify(Request $req, Response $res, array $args): Response {
        return $res->withStatus(200);
    }
}
```

`src/Controllers/OrderController.php`:
```php
<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class OrderController {
    public function __construct(private Twig $twig) {}
    public function status(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Order status — coming soon'); return $res;
    }
}
```

- [ ] **Step 7: Run full test suite**

```bash
./vendor/bin/phpunit --testdox
```

Expected: All tests pass. (DatabaseTest requires local MySQL; skip with `--exclude-group db` if unavailable.)

- [ ] **Step 8: Smoke-test locally**

```bash
php -S localhost:8080 -t www
```

Visit `http://localhost:8080/` — expected: redirect to `http://localhost:8080/cs/`.
Visit `http://localhost:8080/cs/` — expected: home page with Czech text, nav, and pastel styling.
Visit `http://localhost:8080/en/` — expected: same page with English text.
Visit `http://localhost:8080/ru/` — expected: Russian text.

- [ ] **Step 9: Commit**

```bash
git add templates/ www/assets/css/style.css src/Controllers/ src/Twig/
git commit -m "feat: base Twig templates, CSS, home page, and stub controllers"
```

---

## Self-Review

**Spec coverage:**
- ✅ Languages (cs/ru/en/uk/sk with URL prefix routing)
- ✅ Directory structure (source outside www/)
- ✅ Database schema (all 14 tables from spec)
- ✅ GoPay IPN route without lang prefix (`/payment/notify`)
- ✅ Admin URL structure reserved (`/admin/*`) — implemented in Plan 4
- ✅ Elegant & clean design direction (style.css)
- ✅ All public routes defined (stubs for unimplemented pages)

**What this plan does NOT cover (separate plans):**
- Plan 2: Shop, gallery, blog, contact, services pages
- Plan 3: Cart, checkout, GoPay payment
- Plan 4: Admin panel (auth, products, orders, gallery, blog, pages, settings)

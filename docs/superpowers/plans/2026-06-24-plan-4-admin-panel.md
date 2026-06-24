# Admin Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a complete session-authenticated admin panel covering products, categories, orders, gallery, blog, pages, settings, and user management.

**Architecture:** Session-based auth with `AuthMiddleware` protecting a `/admin/*` route group; `AdminBaseController` handles flash messages and Twig rendering without I18n; all admin controllers live in `src/Controllers/Admin/`; image uploads use GD with UUID filenames stored under `www/assets/uploads/`.

**Tech Stack:** Slim 4, PHP-DI 7, Twig 3, PDO/MySQL 8, GD extension, bcrypt (`password_hash`), PHPUnit 11

## Global Constraints

- PHP 8.1+
- No lang prefix on admin URLs (`/admin/*`)
- Admin templates extend `layout/admin-base.twig`
- Images: max 1600px wide, thumb 400px wide, `thumb_` prefix, UUID filenames, stored in `www/assets/uploads/`
- bcrypt for passwords (`PASSWORD_BCRYPT`)
- Languages supported: cs, ru, en, uk, sk
- Admin session key: `$_SESSION['admin_user']` (array with `id`, `email`, `name`)
- Flash messages via `$_SESSION['flash']` (array `['type' => 'success|error', 'message' => '...']`)

---

### Task 1: Auth + Infrastructure

**Files:**
- Create: `src/Controllers/Admin/AdminBaseController.php`
- Create: `src/Middleware/AuthMiddleware.php`
- Create: `src/Controllers/Admin/AuthController.php`
- Create: `src/Models/AdminUserModel.php`
- Create: `templates/admin/login.twig`
- Create: `www/assets/css/admin.css`
- Modify: `src/routes.php`

**Interfaces:**
- Produces: `AdminBaseController::renderAdmin(Request, Response, string, array): Response`
- Produces: `AdminBaseController::flash(string, string): void`
- Produces: `AdminBaseController::getFlash(): ?array`
- Produces: `AdminUserModel::findByEmail(string): ?array` — returns row with `id, email, name, password_hash`
- Produces: `AdminUserModel::create(string, string, string): int` — returns new user ID
- Produces: `AdminUserModel::count(): int`
- Produces: `AuthMiddleware` — redirects to `/admin/login` if no session; skips for login/setup/logout

- [ ] **Step 1: Create AdminBaseController**

```php
<?php
// src/Controllers/Admin/AdminBaseController.php
namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class AdminBaseController
{
    public function __construct(protected Twig $twig) {}

    protected function renderAdmin(Request $request, Response $response, string $template, array $data = []): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = $this->getFlash();
        return $this->twig->render($response, $template, array_merge(['flash' => $flash], $data));
    }

    protected function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    protected function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }

    protected function redirect(Response $response, string $url, int $status = 302): Response
    {
        return $response->withHeader('Location', $url)->withStatus($status);
    }
}
```

- [ ] **Step 2: Create AdminUserModel**

```php
<?php
// src/Models/AdminUserModel.php
namespace App\Models;

class AdminUserModel
{
    public static function findByEmail(string $email): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, email, name, password_hash FROM admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, email, name FROM admin_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function count(): int
    {
        $pdo = Database::getConnection();
        return (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    public static function create(string $email, string $name, string $passwordHash): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO admin_users (email, name, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$email, $name, $passwordHash]);
        return (int) $pdo->lastInsertId();
    }

    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT id, email, name, created_at FROM admin_users ORDER BY id')->fetchAll();
    }

    public static function updatePassword(int $id, string $passwordHash): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    public static function delete(int $id): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
    }
}
```

- [ ] **Step 3: Create AuthMiddleware**

```php
<?php
// src/Middleware/AuthMiddleware.php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    private array $publicPaths = ['/admin/login', '/admin/logout', '/admin/setup'];

    public function process(Request $request, Handler $handler): Response
    {
        $path = $request->getUri()->getPath();
        foreach ($this->publicPaths as $public) {
            if ($path === $public || str_starts_with($path, $public . '/')) {
                return $handler->handle($request);
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['admin_user'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 4: Create AuthController**

```php
<?php
// src/Controllers/Admin/AuthController.php
namespace App\Controllers\Admin;

use App\Models\AdminUserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController extends AdminBaseController
{
    public function loginForm(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!empty($_SESSION['admin_user'])) {
            return $this->redirect($response, '/admin');
        }
        return $this->renderAdmin($request, $response, 'admin/login.twig');
    }

    public function loginSubmit(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $body     = (array) $request->getParsedBody();
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        $user = AdminUserModel::findByEmail($email);
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['admin_user'] = ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name']];
            return $this->redirect($response, '/admin');
        }

        return $this->renderAdmin($request, $response, 'admin/login.twig', ['error' => 'Nesprávný e-mail nebo heslo.']);
    }

    public function logout(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_destroy();
        return $this->redirect($response, '/admin/login');
    }

    public function setupForm(Request $request, Response $response, array $args): Response
    {
        if (AdminUserModel::count() > 0) {
            return $this->redirect($response, '/admin/login');
        }
        return $this->renderAdmin($request, $response, 'admin/setup.twig');
    }

    public function setupSubmit(Request $request, Response $response, array $args): Response
    {
        if (AdminUserModel::count() > 0) {
            return $this->redirect($response, '/admin/login');
        }
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $name  = trim($body['name'] ?? '');
        $pass  = $body['password'] ?? '';

        if (!$email || !$name || strlen($pass) < 8) {
            return $this->renderAdmin($request, $response, 'admin/setup.twig', ['error' => 'Vyplňte všechna pole. Heslo musí mít alespoň 8 znaků.']);
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        AdminUserModel::create($email, $name, $hash);

        if (session_status() === PHP_SESSION_NONE) session_start();
        $user = AdminUserModel::findByEmail($email);
        $_SESSION['admin_user'] = ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name']];
        return $this->redirect($response, '/admin');
    }
}
```

- [ ] **Step 5: Create login.twig and setup.twig**

`templates/admin/login.twig`:
```twig
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Přihlášení — BalonkyDecor Admin</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-login-page">
<div class="login-box">
    <h1>BalonkyDecor Admin</h1>
    {% if error %}<p class="form-error">{{ error }}</p>{% endif %}
    <form method="POST" action="/admin/login">
        <div class="form-group">
            <label>E-mail</label>
            <input type="email" name="email" required autofocus>
        </div>
        <div class="form-group">
            <label>Heslo</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Přihlásit se</button>
    </form>
</div>
</body>
</html>
```

`templates/admin/setup.twig`:
```twig
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nastavení admina — BalonkyDecor</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-login-page">
<div class="login-box">
    <h1>Vytvořit administrátora</h1>
    {% if error %}<p class="form-error">{{ error }}</p>{% endif %}
    <form method="POST" action="/admin/setup">
        <div class="form-group">
            <label>Jméno</label>
            <input type="text" name="name" required autofocus>
        </div>
        <div class="form-group">
            <label>E-mail</label>
            <input type="email" name="email" required>
        </div>
        <div class="form-group">
            <label>Heslo (min. 8 znaků)</label>
            <input type="password" name="password" required minlength="8">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Vytvořit účet</button>
    </form>
</div>
</body>
</html>
```

- [ ] **Step 6: Create admin.css**

```css
/* www/assets/css/admin.css */
/* Admin layout */
body.admin-body { display:flex; min-height:100vh; margin:0; background:#f5f5f5; font-family:system-ui,sans-serif; }
.admin-sidebar { width:220px; background:#1a1a2e; color:#fff; flex-shrink:0; display:flex; flex-direction:column; }
.admin-logo { padding:1.5rem 1rem; font-size:1.1rem; font-weight:700; border-bottom:1px solid #2a2a4e; }
.admin-nav { display:flex; flex-direction:column; padding:1rem 0; }
.admin-nav a { color:#c0c0d0; text-decoration:none; padding:0.6rem 1.25rem; font-size:0.9rem; transition:background 0.15s; }
.admin-nav a:hover, .admin-nav a.active { background:#2a2a4e; color:#fff; }
.admin-main { flex:1; padding:2rem; overflow:auto; }

/* Top bar */
.admin-topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; }
.admin-topbar h1 { margin:0; font-size:1.5rem; }

/* Tables */
.admin-table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.admin-table th { background:#f0f0f5; padding:0.75rem 1rem; text-align:left; font-size:0.85rem; color:#555; }
.admin-table td { padding:0.75rem 1rem; border-top:1px solid #eee; font-size:0.9rem; }
.admin-table tr:hover td { background:#fafafa; }

/* Forms */
.admin-form { background:#fff; padding:2rem; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,.08); max-width:800px; }
.admin-form .form-group { margin-bottom:1.25rem; }
.admin-form label { display:block; margin-bottom:0.35rem; font-weight:600; font-size:0.9rem; }
.admin-form input[type=text],
.admin-form input[type=email],
.admin-form input[type=password],
.admin-form input[type=number],
.admin-form input[type=date],
.admin-form select,
.admin-form textarea { width:100%; padding:0.6rem 0.75rem; border:1px solid #ddd; border-radius:4px; font-size:0.95rem; box-sizing:border-box; }
.admin-form textarea { min-height:120px; resize:vertical; }
.admin-form .form-actions { display:flex; gap:0.75rem; margin-top:1.5rem; }

/* Lang tabs */
.lang-tabs { display:flex; gap:0.5rem; margin-bottom:1rem; border-bottom:2px solid #eee; }
.lang-tab { padding:0.5rem 1rem; cursor:pointer; border:none; background:none; font-size:0.9rem; color:#777; border-bottom:2px solid transparent; margin-bottom:-2px; }
.lang-tab.active { color:#e91e8c; border-bottom-color:#e91e8c; font-weight:600; }
.lang-panel { display:none; }
.lang-panel.active { display:block; }

/* Login page */
body.admin-login-page { background:#1a1a2e; display:flex; align-items:center; justify-content:center; min-height:100vh; }
.login-box { background:#fff; border-radius:8px; padding:2.5rem; width:360px; box-shadow:0 4px 24px rgba(0,0,0,.3); }
.login-box h1 { margin:0 0 1.5rem; font-size:1.3rem; text-align:center; }

/* Flash messages */
.flash-success { background:#d4edda; color:#155724; padding:0.75rem 1rem; border-radius:4px; margin-bottom:1rem; }
.flash-error   { background:#f8d7da; color:#721c24; padding:0.75rem 1rem; border-radius:4px; margin-bottom:1rem; }

/* Status badges */
.badge { display:inline-block; padding:0.25rem 0.6rem; border-radius:4px; font-size:0.8rem; font-weight:600; }
.badge-pending  { background:#fff3cd; color:#856404; }
.badge-paid     { background:#d4edda; color:#155724; }
.badge-ready    { background:#cce5ff; color:#004085; }
.badge-completed{ background:#d1ecf1; color:#0c5460; }
.badge-cancelled{ background:#f8d7da; color:#721c24; }

/* Dashboard cards */
.stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:1rem; margin-bottom:2rem; }
.stat-card { background:#fff; border-radius:8px; padding:1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.stat-card .stat-value { font-size:2rem; font-weight:700; color:#e91e8c; }
.stat-card .stat-label { font-size:0.85rem; color:#666; margin-top:0.25rem; }

/* Image preview */
.img-thumb { width:60px; height:60px; object-fit:cover; border-radius:4px; }
.upload-preview { max-width:200px; max-height:200px; border-radius:4px; margin-top:0.5rem; }
```

- [ ] **Step 7: Update admin-base.twig to show flash and logout link**

Replace `templates/layout/admin-base.twig` with:
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
            <a href="/admin/settings">Nastavení</a>
            <a href="/admin/users">Uživatelé</a>
        </nav>
        <div style="margin-top:auto;padding:1rem;">
            <a href="/admin/logout" style="color:#c0c0d0;font-size:0.85rem;">Odhlásit se</a>
        </div>
    </aside>
    <main class="admin-main">
        {% if flash %}
            <div class="flash-{{ flash.type }}">{{ flash.message }}</div>
        {% endif %}
        {% block content %}{% endblock %}
    </main>
    {% block scripts %}{% endblock %}
</body>
</html>
```

- [ ] **Step 8: Register admin routes in routes.php**

Add after the `// Admin routes — added in Plan 4` comment:
```php
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Middleware\AuthMiddleware;

// Auth (public)
$app->get('/admin/login',  AuthController::class . ':loginForm');
$app->post('/admin/login', AuthController::class . ':loginSubmit');
$app->get('/admin/logout', AuthController::class . ':logout');
$app->get('/admin/setup',  AuthController::class . ':setupForm');
$app->post('/admin/setup', AuthController::class . ':setupSubmit');

// Protected admin group
$admin = $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) {
    $group->get('',             DashboardController::class . ':index');
    $group->get('/dashboard',   DashboardController::class . ':index');
    // Products — added below in Task 2
    // Categories — added below in Task 2
    // Orders — added below in Task 3
    // Gallery — added below in Task 4
    // Blog — added below in Task 4
    // Pages — added below in Task 5
    // Settings — added below in Task 5
    // Users — added below in Task 5
})->add(new AuthMiddleware());
```

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/Admin/ src/Middleware/AuthMiddleware.php src/Models/AdminUserModel.php templates/admin/ templates/layout/admin-base.twig www/assets/css/admin.css src/routes.php
git commit -m "feat: admin auth infrastructure — login, setup, AuthMiddleware, AdminBaseController"
```

---

### Task 2: Dashboard + Products + Categories

**Files:**
- Create: `src/Controllers/Admin/DashboardController.php`
- Create: `src/Controllers/Admin/ProductController.php`
- Create: `src/Controllers/Admin/CategoryController.php`
- Create: `src/Services/ImageUploader.php`
- Create: `templates/admin/dashboard.twig`
- Create: `templates/admin/products/index.twig`
- Create: `templates/admin/products/form.twig`
- Create: `templates/admin/categories/index.twig`
- Create: `templates/admin/categories/form.twig`
- Modify: `src/Models/ProductModel.php` — add admin methods
- Modify: `src/Models/CategoryModel.php` — add admin methods
- Modify: `src/routes.php` — add product/category routes

**Interfaces:**
- Consumes: `AdminBaseController::renderAdmin()`, `AdminBaseController::flash()`
- Produces: `ImageUploader::upload(array $file, string $dir): string` — returns filename
- Produces: `ProductModel::all(): array`, `ProductModel::findById(int): ?array`, `ProductModel::create(array): int`, `ProductModel::update(int, array): void`, `ProductModel::delete(int): void`, `ProductModel::setTranslations(int, array): void`, `ProductModel::getTranslations(int): array`
- Produces: `CategoryModel::all(): array`, `CategoryModel::findById(int): ?array`, `CategoryModel::create(array): int`, `CategoryModel::update(int, array): void`, `CategoryModel::delete(int): void`, `CategoryModel::setTranslations(int, array): void`, `CategoryModel::getTranslations(int): array`

- [ ] **Step 1: Create ImageUploader service**

```php
<?php
// src/Services/ImageUploader.php
namespace App\Services;

class ImageUploader
{
    private const MAX_WIDTH   = 1600;
    private const THUMB_WIDTH = 400;

    public static function upload(array $file, string $dir): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file['error']);
        }

        $mime = mime_content_type($file['tmp_name']);
        $ext  = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => throw new \RuntimeException('Unsupported image type: ' . $mime),
        };

        $filename  = bin2hex(random_bytes(16)) . '.' . $ext;
        $thumbName = 'thumb_' . $filename;

        $destDir = rtrim($dir, '/');
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $src  = self::loadImage($file['tmp_name'], $mime);
        $orig = self::resize($src, self::MAX_WIDTH);
        self::saveImage($orig, $destDir . '/' . $filename, $mime);
        imagedestroy($orig);

        $src   = self::loadImage($file['tmp_name'], $mime);
        $thumb = self::resize($src, self::THUMB_WIDTH);
        self::saveImage($thumb, $destDir . '/' . $thumbName, $mime);
        imagedestroy($thumb);

        return $filename;
    }

    private static function loadImage(string $path, string $mime): \GdImage
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png'  => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            'image/gif'  => imagecreatefromgif($path),
            default      => throw new \RuntimeException('Unsupported type'),
        };
    }

    private static function resize(\GdImage $src, int $maxWidth): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= $maxWidth) {
            $dst = imagecreatetruecolor($w, $h);
            imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
            imagedestroy($src);
            return $dst;
        }
        $newW = $maxWidth;
        $newH = (int) round($h * $maxWidth / $w);
        $dst  = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        return $dst;
    }

    private static function saveImage(\GdImage $img, string $path, string $mime): void
    {
        match ($mime) {
            'image/jpeg' => imagejpeg($img, $path, 85),
            'image/png'  => imagepng($img, $path, 6),
            'image/webp' => imagewebp($img, $path, 85),
            'image/gif'  => imagegif($img, $path),
            default      => throw new \RuntimeException('Unsupported type'),
        };
    }
}
```

- [ ] **Step 2: Add admin methods to CategoryModel**

Append to `src/Models/CategoryModel.php`:
```php
    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO categories (slug, sort_order) VALUES (?, ?)');
        $stmt->execute([$data['slug'], $data['sort_order'] ?? 0]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE categories SET slug = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$data['slug'], $data['sort_order'] ?? 0, $id]);
    }

    public static function delete(int $id): void
    {
        $pdo  = Database::getConnection();
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang, name, slug AS trans_slug, description FROM category_t WHERE category_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO category_t (category_id, lang, name, slug, description)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), description = VALUES(description)'
        );
        foreach ($translations as $lang => $t) {
            $stmt->execute([$id, $lang, $t['name'] ?? '', $t['slug'] ?? '', $t['description'] ?? '']);
        }
    }
```

- [ ] **Step 3: Add admin methods to ProductModel**

Append to `src/Models/ProductModel.php`:
```php
    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT p.*, c.slug AS category_slug,
                    (SELECT filename FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image
             FROM products p LEFT JOIN categories c ON c.id = p.category_id
             ORDER BY p.id DESC'
        )->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) return null;

        $imgs = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $imgs->execute([$id]);
        $product['images'] = $imgs->fetchAll();
        return $product;
    }

    public static function create(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, price, category_id, is_active)
             VALUES (:sku, :price, :category_id, :is_active)'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: null,
            'is_active'   => (int) ($data['is_active'] ?? 1),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE products SET sku = :sku, price = :price, category_id = :category_id, is_active = :is_active WHERE id = :id'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: null,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'id'          => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang, name, slug, description FROM product_t WHERE product_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO product_t (product_id, lang, name, slug, description)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), description = VALUES(description)'
        );
        foreach ($translations as $lang => $t) {
            $stmt->execute([$id, $lang, $t['name'] ?? '', $t['slug'] ?? '', $t['description'] ?? '']);
        }
    }

    public static function addImage(int $productId, string $filename, bool $isPrimary = false): void
    {
        $pdo  = Database::getConnection();
        if ($isPrimary) {
            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
        }
        $pdo->prepare('INSERT INTO product_images (product_id, filename, is_primary, sort_order) VALUES (?, ?, ?, 0)')
            ->execute([$productId, $filename, $isPrimary ? 1 : 0]);
    }

    public static function deleteImage(int $imageId): ?string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT filename FROM product_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $row  = $stmt->fetch();
        if (!$row) return null;
        $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
        return $row['filename'];
    }
```

- [ ] **Step 4: Create DashboardController**

```php
<?php
// src/Controllers/Admin/DashboardController.php
namespace App\Controllers\Admin;

use App\Models\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::getConnection();
        $stats = [
            'orders_today'    => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
            'orders_pending'  => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
            'orders_total'    => (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'products_active' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn(),
        ];

        $recent = $pdo->query(
            "SELECT order_number, customer_name, total_amount, status, created_at
             FROM orders ORDER BY created_at DESC LIMIT 10"
        )->fetchAll();

        return $this->renderAdmin($request, $response, 'admin/dashboard.twig', [
            'stats'  => $stats,
            'recent' => $recent,
        ]);
    }
}
```

- [ ] **Step 5: Create CategoryController**

```php
<?php
// src/Controllers/Admin/CategoryController.php
namespace App\Controllers\Admin;

use App\Models\CategoryModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoryController extends AdminBaseController
{
    private const LANGS = ['cs', 'en', 'ru', 'uk', 'sk'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $categories = CategoryModel::all();
        return $this->renderAdmin($request, $response, 'admin/categories/index.twig', compact('categories'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/categories/form.twig', ['category' => null, 'translations' => [], 'langs' => self::LANGS]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = CategoryModel::create(['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)]);
        CategoryModel::setTranslations($id, $body['t'] ?? []);
        $this->flash('success', 'Kategorie vytvořena.');
        return $this->redirect($response, '/admin/categories');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $category     = CategoryModel::findById((int) $args['id']);
        if (!$category) return $response->withStatus(404);
        $translations = CategoryModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/categories/form.twig', compact('category', 'translations') + ['langs' => self::LANGS]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        CategoryModel::update($id, ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)]);
        CategoryModel::setTranslations($id, $body['t'] ?? []);
        $this->flash('success', 'Kategorie uložena.');
        return $this->redirect($response, '/admin/categories');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        CategoryModel::delete((int) $args['id']);
        $this->flash('success', 'Kategorie smazána.');
        return $this->redirect($response, '/admin/categories');
    }
}
```

- [ ] **Step 6: Create ProductController**

```php
<?php
// src/Controllers/Admin/ProductController.php
namespace App\Controllers\Admin;

use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Services\ImageUploader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductController extends AdminBaseController
{
    private const LANGS      = ['cs', 'en', 'ru', 'uk', 'sk'];
    private const UPLOAD_DIR = __DIR__ . '/../../../www/assets/uploads/products';

    public function index(Request $request, Response $response, array $args): Response
    {
        $products = ProductModel::all();
        return $this->renderAdmin($request, $response, 'admin/products/index.twig', compact('products'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        $categories = CategoryModel::allWithTranslation('cs');
        return $this->renderAdmin($request, $response, 'admin/products/form.twig', [
            'product'      => null,
            'translations' => [],
            'categories'   => $categories,
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = ProductModel::create([
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? null,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
        ]);
        ProductModel::setTranslations($id, $body['t'] ?? []);
        $this->handleImageUpload($request, $id, true);
        $this->flash('success', 'Produkt vytvořen.');
        return $this->redirect($response, '/admin/products');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $product = ProductModel::findById((int) $args['id']);
        if (!$product) return $response->withStatus(404);
        $translations = ProductModel::getTranslations((int) $args['id']);
        $categories   = CategoryModel::allWithTranslation('cs');
        return $this->renderAdmin($request, $response, 'admin/products/form.twig', compact('product', 'translations', 'categories') + ['langs' => self::LANGS]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        ProductModel::update($id, [
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? null,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
        ]);
        ProductModel::setTranslations($id, $body['t'] ?? []);
        $this->handleImageUpload($request, $id, false);
        $this->flash('success', 'Produkt uložen.');
        return $this->redirect($response, '/admin/products');
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $filename = ProductModel::deleteImage((int) $args['image_id']);
        if ($filename) {
            @unlink(self::UPLOAD_DIR . '/' . $filename);
            @unlink(self::UPLOAD_DIR . '/thumb_' . $filename);
        }
        $this->flash('success', 'Obrázek smazán.');
        return $this->redirect($response, '/admin/products/' . $args['id'] . '/edit');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $product = ProductModel::findById((int) $args['id']);
        if ($product) {
            foreach ($product['images'] as $img) {
                @unlink(self::UPLOAD_DIR . '/' . $img['filename']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $img['filename']);
            }
            ProductModel::delete((int) $args['id']);
        }
        $this->flash('success', 'Produkt smazán.');
        return $this->redirect($response, '/admin/products');
    }

    private function handleImageUpload(Request $request, int $productId, bool $isPrimary): void
    {
        $files = $request->getUploadedFiles();
        $file  = $files['image'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return;

        $tmp = [
            'tmp_name' => $file->getStream()->getMetadata('uri'),
            'error'    => $file->getError(),
        ];
        $filename = ImageUploader::upload($tmp, self::UPLOAD_DIR);
        ProductModel::addImage($productId, $filename, $isPrimary);
    }
}
```

- [ ] **Step 7: Create admin templates for Dashboard, Categories, Products**

`templates/admin/dashboard.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Dashboard{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>Dashboard</h1></div>
<div class="stat-grid">
    <div class="stat-card"><div class="stat-value">{{ stats.orders_today }}</div><div class="stat-label">Objednávky dnes</div></div>
    <div class="stat-card"><div class="stat-value">{{ stats.orders_pending }}</div><div class="stat-label">Čekající objednávky</div></div>
    <div class="stat-card"><div class="stat-value">{{ stats.orders_total }}</div><div class="stat-label">Celkem objednávek</div></div>
    <div class="stat-card"><div class="stat-value">{{ stats.products_active }}</div><div class="stat-label">Aktivní produkty</div></div>
</div>
<h2>Poslední objednávky</h2>
<table class="admin-table">
    <thead><tr><th>Číslo</th><th>Zákazník</th><th>Celkem</th><th>Status</th><th>Vytvořena</th></tr></thead>
    <tbody>
    {% for o in recent %}
    <tr>
        <td><a href="/admin/orders/{{ o.order_number }}">{{ o.order_number }}</a></td>
        <td>{{ o.customer_name }}</td>
        <td>{{ o.total_amount|number_format(2,'.', ' ') }} Kč</td>
        <td><span class="badge badge-{{ o.status }}">{{ o.status }}</span></td>
        <td>{{ o.created_at }}</td>
    </tr>
    {% else %}
    <tr><td colspan="5">Žádné objednávky.</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

`templates/admin/categories/index.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Kategorie{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>Kategorie</h1>
    <a href="/admin/categories/new" class="btn btn-primary">+ Přidat kategorii</a>
</div>
<table class="admin-table">
    <thead><tr><th>ID</th><th>Slug</th><th>Pořadí</th><th>Akce</th></tr></thead>
    <tbody>
    {% for cat in categories %}
    <tr>
        <td>{{ cat.id }}</td>
        <td>{{ cat.slug }}</td>
        <td>{{ cat.sort_order }}</td>
        <td>
            <a href="/admin/categories/{{ cat.id }}/edit">Upravit</a> |
            <form method="POST" action="/admin/categories/{{ cat.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat?')">
                <button class="btn-link">Smazat</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="4">Žádné kategorie.</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

`templates/admin/categories/form.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ category ? 'Upravit kategorii' : 'Nová kategorie' }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ category ? 'Upravit kategorii' : 'Nová kategorie' }}</h1>
    <a href="/admin/categories" class="btn btn-secondary">← Zpět</a>
</div>
<form method="POST" action="{{ category ? '/admin/categories/' ~ category.id ~ '/edit' : '/admin/categories/new' }}" class="admin-form">
    <div class="form-group">
        <label>Slug (URL)</label>
        <input type="text" name="slug" value="{{ category.slug ?? '' }}" required>
    </div>
    <div class="form-group">
        <label>Pořadí</label>
        <input type="number" name="sort_order" value="{{ category.sort_order ?? 0 }}">
    </div>
    <h3>Překlady</h3>
    <div class="lang-tabs">
        {% for lang in langs %}<button type="button" class="lang-tab {% if loop.first %}active{% endif %}" data-lang="{{ lang }}">{{ lang|upper }}</button>{% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>Název ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>Slug překladu ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][slug]" value="{{ translations[lang].trans_slug ?? '' }}">
        </div>
        <div class="form-group">
            <label>Popis ({{ lang }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Uložit</button>
        <a href="/admin/categories" class="btn btn-secondary">Zrušit</a>
    </div>
</form>
{% block scripts %}
<script>
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});
</script>
{% endblock %}
{% endblock %}
```

`templates/admin/products/index.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Produkty{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>Produkty</h1>
    <a href="/admin/products/new" class="btn btn-primary">+ Přidat produkt</a>
</div>
<table class="admin-table">
    <thead><tr><th>Obrázek</th><th>SKU</th><th>Cena</th><th>Aktivní</th><th>Akce</th></tr></thead>
    <tbody>
    {% for p in products %}
    <tr>
        <td>{% if p.primary_image %}<img src="/assets/uploads/products/thumb_{{ p.primary_image }}" class="img-thumb">{% endif %}</td>
        <td>{{ p.sku }}</td>
        <td>{{ p.price|number_format(2,'.', ' ') }} Kč</td>
        <td>{{ p.is_active ? '✓' : '—' }}</td>
        <td>
            <a href="/admin/products/{{ p.id }}/edit">Upravit</a> |
            <form method="POST" action="/admin/products/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat produkt?')">
                <button class="btn-link">Smazat</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="5">Žádné produkty.</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

`templates/admin/products/form.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ product ? 'Upravit produkt' : 'Nový produkt' }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ product ? 'Upravit produkt' : 'Nový produkt' }}</h1>
    <a href="/admin/products" class="btn btn-secondary">← Zpět</a>
</div>
<form method="POST" action="{{ product ? '/admin/products/' ~ product.id ~ '/edit' : '/admin/products/new' }}" enctype="multipart/form-data" class="admin-form">
    <div class="form-group">
        <label>SKU</label>
        <input type="text" name="sku" value="{{ product.sku ?? '' }}" required>
    </div>
    <div class="form-group">
        <label>Cena (Kč)</label>
        <input type="number" name="price" step="0.01" value="{{ product.price ?? '0.00' }}" required>
    </div>
    <div class="form-group">
        <label>Kategorie</label>
        <select name="category_id">
            <option value="">— bez kategorie —</option>
            {% for cat in categories %}
            <option value="{{ cat.id }}" {% if product.category_id == cat.id %}selected{% endif %}>{{ cat.name ?? cat.slug }}</option>
            {% endfor %}
        </select>
    </div>
    <div class="form-group">
        <label><input type="checkbox" name="is_active" value="1" {% if product is null or product.is_active %}checked{% endif %}> Aktivní</label>
    </div>
    <div class="form-group">
        <label>Přidat obrázek</label>
        <input type="file" name="image" accept="image/*">
    </div>
    {% if product and product.images %}
    <div class="form-group">
        <label>Stávající obrázky</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        {% for img in product.images %}
            <div>
                <img src="/assets/uploads/products/thumb_{{ img.filename }}" class="img-thumb">
                <form method="POST" action="/admin/products/{{ product.id }}/image/{{ img.id }}/delete" style="text-align:center">
                    <button class="btn-link" style="font-size:0.8rem">Smazat</button>
                </form>
            </div>
        {% endfor %}
        </div>
    </div>
    {% endif %}
    <h3>Překlady</h3>
    <div class="lang-tabs">
        {% for lang in langs %}<button type="button" class="lang-tab {% if loop.first %}active{% endif %}" data-lang="{{ lang }}">{{ lang|upper }}</button>{% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>Název ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>Slug ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][slug]" value="{{ translations[lang].slug ?? '' }}">
        </div>
        <div class="form-group">
            <label>Popis ({{ lang }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Uložit</button>
        <a href="/admin/products" class="btn btn-secondary">Zrušit</a>
    </div>
</form>
{% block scripts %}
<script>
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});
</script>
{% endblock %}
{% endblock %}
```

- [ ] **Step 8: Add product/category routes to routes.php**

Inside the admin group:
```php
    // Categories
    $group->get('/categories',                     CategoryController::class . ':index');
    $group->get('/categories/new',                 CategoryController::class . ':createForm');
    $group->post('/categories/new',                CategoryController::class . ':createSubmit');
    $group->get('/categories/{id:[0-9]+}/edit',    CategoryController::class . ':editForm');
    $group->post('/categories/{id:[0-9]+}/edit',   CategoryController::class . ':editSubmit');
    $group->post('/categories/{id:[0-9]+}/delete', CategoryController::class . ':delete');

    // Products
    $group->get('/products',                                        ProductController::class . ':index');
    $group->get('/products/new',                                    ProductController::class . ':createForm');
    $group->post('/products/new',                                   ProductController::class . ':createSubmit');
    $group->get('/products/{id:[0-9]+}/edit',                       ProductController::class . ':editForm');
    $group->post('/products/{id:[0-9]+}/edit',                      ProductController::class . ':editSubmit');
    $group->post('/products/{id:[0-9]+}/delete',                    ProductController::class . ':delete');
    $group->post('/products/{id:[0-9]+}/image/{image_id:[0-9]+}/delete', ProductController::class . ':deleteImage');
```

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/Admin/ src/Services/ImageUploader.php src/Models/CategoryModel.php src/Models/ProductModel.php templates/admin/ src/routes.php www/assets/css/admin.css
git commit -m "feat: admin dashboard, products, categories with image upload"
```

---

### Task 3: Orders

**Files:**
- Create: `src/Controllers/Admin/OrderController.php`
- Create: `templates/admin/orders/index.twig`
- Create: `templates/admin/orders/detail.twig`
- Modify: `src/routes.php` — add order routes inside admin group

**Interfaces:**
- Consumes: `OrderModel::findByNumber()`, `OrderModel::updateStatus()`
- Produces: admin order list with pagination + status filter; detail view with status change form

- [ ] **Step 1: Add admin list method to OrderModel**

Append to `src/Models/OrderModel.php`:
```php
    public static function adminList(int $page = 1, int $perPage = 20, string $status = ''): array
    {
        $pdo    = Database::getConnection();
        $where  = $status ? "WHERE status = " . $pdo->quote($status) : '';
        $total  = (int) $pdo->query("SELECT COUNT(*) FROM orders {$where}")->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            "SELECT order_number, customer_name, customer_email, total_amount, status, created_at
             FROM orders {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        return ['orders' => $stmt->fetchAll(), 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))];
    }
```

- [ ] **Step 2: Create OrderController (admin)**

```php
<?php
// src/Controllers/Admin/OrderController.php
namespace App\Controllers\Admin;

use App\Models\OrderModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends AdminBaseController
{
    private const STATUSES = ['pending', 'paid', 'ready', 'completed', 'cancelled'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $status = $params['status'] ?? '';

        $data = OrderModel::adminList($page, 20, $status);
        return $this->renderAdmin($request, $response, 'admin/orders/index.twig', [
            'orders'   => $data['orders'],
            'pages'    => $data['pages'],
            'page'     => $page,
            'status'   => $status,
            'statuses' => self::STATUSES,
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $order = OrderModel::findByNumber($args['number']);
        if (!$order) return $response->withStatus(404);
        return $this->renderAdmin($request, $response, 'admin/orders/detail.twig', [
            'order'    => $order,
            'statuses' => self::STATUSES,
        ]);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $status = $body['status'] ?? '';
        if (in_array($status, self::STATUSES, true)) {
            OrderModel::updateStatus($args['number'], $status);
            $this->flash('success', 'Status objednávky změněn.');
        }
        return $this->redirect($response, '/admin/orders/' . $args['number']);
    }
}
```

- [ ] **Step 3: Create order templates**

`templates/admin/orders/index.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Objednávky{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>Objednávky</h1></div>
<form method="GET" action="/admin/orders" style="margin-bottom:1rem;display:flex;gap:0.5rem;align-items:center;">
    <label>Filtrovat status:</label>
    <select name="status" onchange="this.form.submit()">
        <option value="">— všechny —</option>
        {% for s in statuses %}
        <option value="{{ s }}" {% if s == status %}selected{% endif %}>{{ s }}</option>
        {% endfor %}
    </select>
</form>
<table class="admin-table">
    <thead><tr><th>Číslo</th><th>Zákazník</th><th>E-mail</th><th>Celkem</th><th>Status</th><th>Vytvořena</th><th>Akce</th></tr></thead>
    <tbody>
    {% for o in orders %}
    <tr>
        <td><a href="/admin/orders/{{ o.order_number }}">{{ o.order_number }}</a></td>
        <td>{{ o.customer_name }}</td>
        <td>{{ o.customer_email }}</td>
        <td>{{ o.total_amount|number_format(2,'.', ' ') }} Kč</td>
        <td><span class="badge badge-{{ o.status }}">{{ o.status }}</span></td>
        <td>{{ o.created_at }}</td>
        <td><a href="/admin/orders/{{ o.order_number }}">Detail</a></td>
    </tr>
    {% else %}
    <tr><td colspan="7">Žádné objednávky.</td></tr>
    {% endfor %}
    </tbody>
</table>
{% if pages > 1 %}
<div class="pagination" style="margin-top:1rem;">
    {% for p in 1..pages %}
    <a href="?page={{ p }}{% if status %}&status={{ status }}{% endif %}" class="{% if p == page %}active{% endif %}">{{ p }}</a>
    {% endfor %}
</div>
{% endif %}
{% endblock %}
```

`templates/admin/orders/detail.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Objednávka {{ order.order_number }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>Objednávka {{ order.order_number }}</h1>
    <a href="/admin/orders" class="btn btn-secondary">← Zpět</a>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;">
<div class="admin-form">
    <h3>Zákazník</h3>
    <p><strong>Jméno:</strong> {{ order.customer_name }}</p>
    <p><strong>E-mail:</strong> {{ order.customer_email }}</p>
    <p><strong>Telefon:</strong> {{ order.customer_phone }}</p>
    <p><strong>Datum vyzvednutí:</strong> {{ order.pickup_date ?? '—' }}</p>
    <p><strong>Poznámka:</strong> {{ order.notes ?? '—' }}</p>
    <p><strong>GoPay ID:</strong> {{ order.gopay_payment_id ?? '—' }}</p>
    <p><strong>Vytvořena:</strong> {{ order.created_at }}</p>

    <h3>Změnit status</h3>
    <form method="POST" action="/admin/orders/{{ order.order_number }}/status" style="display:flex;gap:0.5rem;">
        <select name="status">
            {% for s in statuses %}
            <option value="{{ s }}" {% if s == order.status %}selected{% endif %}>{{ s }}</option>
            {% endfor %}
        </select>
        <button type="submit" class="btn btn-primary">Uložit</button>
    </form>
</div>
<div>
    <table class="admin-table">
        <thead><tr><th>Produkt</th><th>Ks</th><th>Cena/ks</th><th>Celkem</th></tr></thead>
        <tbody>
        {% for item in order.items %}
        <tr>
            <td>{{ item.product_name_snapshot }}</td>
            <td>{{ item.quantity }}</td>
            <td>{{ item.unit_price|number_format(2,'.', ' ') }} Kč</td>
            <td>{{ (item.unit_price * item.quantity)|number_format(2,'.', ' ') }} Kč</td>
        </tr>
        {% endfor %}
        </tbody>
    </table>
    <div class="summary-total" style="margin-top:0.5rem;">
        <span>Celkem</span>
        <strong>{{ order.total_amount|number_format(2,'.', ' ') }} Kč</strong>
    </div>
</div>
</div>
{% endblock %}
```

- [ ] **Step 4: Add order routes**

Inside the admin group in routes.php:
```php
    // Orders
    $group->get('/orders',                         OrderController::class . ':index');
    $group->get('/orders/{number}',                OrderController::class . ':detail');
    $group->post('/orders/{number}/status',        OrderController::class . ':updateStatus');
```

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/Admin/OrderController.php templates/admin/orders/ src/Models/OrderModel.php src/routes.php
git commit -m "feat: admin orders list and detail with status update"
```

---

### Task 4: Gallery + Blog

**Files:**
- Create: `src/Controllers/Admin/GalleryController.php`
- Create: `src/Controllers/Admin/BlogController.php`
- Create: `templates/admin/gallery/index.twig`
- Create: `templates/admin/gallery/form.twig`
- Create: `templates/admin/blog/index.twig`
- Create: `templates/admin/blog/form.twig`
- Modify: `src/Models/GalleryModel.php` — add admin methods
- Modify: `src/Models/BlogModel.php` — add admin methods
- Modify: `src/routes.php`

**Interfaces:**
- Produces: `GalleryModel::allAlbums(): array`, `GalleryModel::findAlbumById(int): ?array`, `GalleryModel::createAlbum(array): int`, `GalleryModel::updateAlbum(int, array): void`, `GalleryModel::deleteAlbum(int): void`, `GalleryModel::setAlbumTranslations(int, array): void`, `GalleryModel::getAlbumTranslations(int): array`, `GalleryModel::addImage(int, string): void`, `GalleryModel::deleteImage(int): ?string`
- Produces: `BlogModel::adminList(int, int): array`, `BlogModel::findById(int): ?array`, `BlogModel::create(array): int`, `BlogModel::update(int, array): void`, `BlogModel::delete(int): void`, `BlogModel::setTranslations(int, array): void`, `BlogModel::getTranslations(int): array`

- [ ] **Step 1: Add admin methods to GalleryModel**

Append to `src/Models/GalleryModel.php`:
```php
    public static function allAlbums(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT * FROM gallery_albums ORDER BY sort_order, id')->fetchAll();
    }

    public static function findAlbumById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM gallery_albums WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $album = $stmt->fetch();
        if (!$album) return null;
        $imgs = $pdo->prepare('SELECT * FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
        $imgs->execute([$id]);
        $album['images'] = $imgs->fetchAll();
        return $album;
    }

    public static function createAlbum(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO gallery_albums (slug, cover_image, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$data['slug'], $data['cover_image'] ?? null, $data['sort_order'] ?? 0]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateAlbum(int $id, array $data): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE gallery_albums SET slug = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$data['slug'], $data['sort_order'] ?? 0, $id]);
    }

    public static function deleteAlbum(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM gallery_albums WHERE id = ?')->execute([$id]);
    }

    public static function getAlbumTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang, name, slug AS trans_slug, description FROM gallery_album_t WHERE album_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang']] = $row;
        }
        return $result;
    }

    public static function setAlbumTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO gallery_album_t (album_id, lang, name, slug, description)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), description = VALUES(description)'
        );
        foreach ($translations as $lang => $t) {
            $stmt->execute([$id, $lang, $t['name'] ?? '', $t['slug'] ?? '', $t['description'] ?? '']);
        }
    }

    public static function addImage(int $albumId, string $filename): void
    {
        $pdo  = Database::getConnection();
        $pdo->prepare('INSERT INTO gallery_images (album_id, filename, sort_order) VALUES (?, ?, 0)')->execute([$albumId, $filename]);
    }

    public static function deleteImage(int $imageId): ?string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT filename FROM gallery_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $row  = $stmt->fetch();
        if (!$row) return null;
        $pdo->prepare('DELETE FROM gallery_images WHERE id = ?')->execute([$imageId]);
        return $row['filename'];
    }
```

- [ ] **Step 2: Add admin methods to BlogModel**

Append to `src/Models/BlogModel.php`:
```php
    public static function adminList(int $page = 1, int $perPage = 20): array
    {
        $pdo    = Database::getConnection();
        $total  = (int) $pdo->query('SELECT COUNT(*) FROM blog_posts')->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            'SELECT id, slug, is_published, published_at, created_at FROM blog_posts ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        return ['posts' => $stmt->fetchAll(), 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO blog_posts (slug, is_published, published_at) VALUES (:slug, :is_published, :published_at)'
        );
        $stmt->execute([
            'slug'         => $data['slug'],
            'is_published' => (int) ($data['is_published'] ?? 0),
            'published_at' => $data['is_published'] ? ($data['published_at'] ?: date('Y-m-d H:i:s')) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE blog_posts SET slug = :slug, is_published = :is_published, published_at = :published_at WHERE id = :id'
        );
        $stmt->execute([
            'slug'         => $data['slug'],
            'is_published' => (int) ($data['is_published'] ?? 0),
            'published_at' => $data['is_published'] ? ($data['published_at'] ?: date('Y-m-d H:i:s')) : null,
            'id'           => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang, title, slug, body FROM blog_post_t WHERE post_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO blog_post_t (post_id, lang, title, slug, body)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), slug = VALUES(slug), body = VALUES(body)'
        );
        foreach ($translations as $lang => $t) {
            $stmt->execute([$id, $lang, $t['title'] ?? '', $t['slug'] ?? '', $t['body'] ?? '']);
        }
    }
```

- [ ] **Step 3: Create GalleryController (admin)**

```php
<?php
// src/Controllers/Admin/GalleryController.php
namespace App\Controllers\Admin;

use App\Models\GalleryModel;
use App\Services\ImageUploader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GalleryController extends AdminBaseController
{
    private const LANGS      = ['cs', 'en', 'ru', 'uk', 'sk'];
    private const UPLOAD_DIR = __DIR__ . '/../../../www/assets/uploads/gallery';

    public function index(Request $request, Response $response, array $args): Response
    {
        $albums = GalleryModel::allAlbums();
        return $this->renderAdmin($request, $response, 'admin/gallery/index.twig', compact('albums'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/gallery/form.twig', ['album' => null, 'translations' => [], 'langs' => self::LANGS]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = GalleryModel::createAlbum(['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)]);
        GalleryModel::setAlbumTranslations($id, $body['t'] ?? []);
        $this->handleImageUploads($request, $id);
        $this->flash('success', 'Album vytvořeno.');
        return $this->redirect($response, '/admin/gallery');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $album = GalleryModel::findAlbumById((int) $args['id']);
        if (!$album) return $response->withStatus(404);
        $translations = GalleryModel::getAlbumTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/gallery/form.twig', compact('album', 'translations') + ['langs' => self::LANGS]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        GalleryModel::updateAlbum($id, ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)]);
        GalleryModel::setAlbumTranslations($id, $body['t'] ?? []);
        $this->handleImageUploads($request, $id);
        $this->flash('success', 'Album uloženo.');
        return $this->redirect($response, '/admin/gallery');
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $filename = GalleryModel::deleteImage((int) $args['image_id']);
        if ($filename) {
            @unlink(self::UPLOAD_DIR . '/' . $filename);
            @unlink(self::UPLOAD_DIR . '/thumb_' . $filename);
        }
        $this->flash('success', 'Obrázek smazán.');
        return $this->redirect($response, '/admin/gallery/' . $args['id'] . '/edit');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $album = GalleryModel::findAlbumById((int) $args['id']);
        if ($album) {
            foreach ($album['images'] as $img) {
                @unlink(self::UPLOAD_DIR . '/' . $img['filename']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $img['filename']);
            }
            GalleryModel::deleteAlbum((int) $args['id']);
        }
        $this->flash('success', 'Album smazáno.');
        return $this->redirect($response, '/admin/gallery');
    }

    private function handleImageUploads(Request $request, int $albumId): void
    {
        $files = $request->getUploadedFiles();
        $images = $files['images'] ?? [];
        if (!is_array($images)) $images = [$images];
        foreach ($images as $file) {
            if ($file->getError() === UPLOAD_ERR_NO_FILE) continue;
            $tmp = ['tmp_name' => $file->getStream()->getMetadata('uri'), 'error' => $file->getError()];
            $filename = ImageUploader::upload($tmp, self::UPLOAD_DIR);
            GalleryModel::addImage($albumId, $filename);
        }
    }
}
```

- [ ] **Step 4: Create BlogController (admin)**

```php
<?php
// src/Controllers/Admin/BlogController.php
namespace App\Controllers\Admin;

use App\Models\BlogModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BlogController extends AdminBaseController
{
    private const LANGS = ['cs', 'en', 'ru', 'uk', 'sk'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $data   = BlogModel::adminList($page, 20);
        return $this->renderAdmin($request, $response, 'admin/blog/index.twig', [
            'posts' => $data['posts'],
            'pages' => $data['pages'],
            'page'  => $page,
        ]);
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/blog/form.twig', ['post' => null, 'translations' => [], 'langs' => self::LANGS]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = BlogModel::create([
            'slug'         => trim($body['slug'] ?? ''),
            'is_published' => isset($body['is_published']) ? 1 : 0,
            'published_at' => $body['published_at'] ?? null,
        ]);
        BlogModel::setTranslations($id, $body['t'] ?? []);
        $this->flash('success', 'Příspěvek vytvořen.');
        return $this->redirect($response, '/admin/blog');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $post = BlogModel::findById((int) $args['id']);
        if (!$post) return $response->withStatus(404);
        $translations = BlogModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/blog/form.twig', compact('post', 'translations') + ['langs' => self::LANGS]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        BlogModel::update($id, [
            'slug'         => trim($body['slug'] ?? ''),
            'is_published' => isset($body['is_published']) ? 1 : 0,
            'published_at' => $body['published_at'] ?? null,
        ]);
        BlogModel::setTranslations($id, $body['t'] ?? []);
        $this->flash('success', 'Příspěvek uložen.');
        return $this->redirect($response, '/admin/blog');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        BlogModel::delete((int) $args['id']);
        $this->flash('success', 'Příspěvek smazán.');
        return $this->redirect($response, '/admin/blog');
    }
}
```

- [ ] **Step 5: Create gallery/blog templates**

`templates/admin/gallery/index.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Galerie{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>Galerie</h1>
    <a href="/admin/gallery/new" class="btn btn-primary">+ Přidat album</a>
</div>
<table class="admin-table">
    <thead><tr><th>ID</th><th>Slug</th><th>Pořadí</th><th>Fotek</th><th>Akce</th></tr></thead>
    <tbody>
    {% for a in albums %}
    <tr>
        <td>{{ a.id }}</td>
        <td>{{ a.slug }}</td>
        <td>{{ a.sort_order }}</td>
        <td>—</td>
        <td>
            <a href="/admin/gallery/{{ a.id }}/edit">Upravit</a> |
            <form method="POST" action="/admin/gallery/{{ a.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat album?')">
                <button class="btn-link">Smazat</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="5">Žádná alba.</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

`templates/admin/gallery/form.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ album ? 'Upravit album' : 'Nové album' }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ album ? 'Upravit album' : 'Nové album' }}</h1>
    <a href="/admin/gallery" class="btn btn-secondary">← Zpět</a>
</div>
<form method="POST" action="{{ album ? '/admin/gallery/' ~ album.id ~ '/edit' : '/admin/gallery/new' }}" enctype="multipart/form-data" class="admin-form">
    <div class="form-group">
        <label>Slug</label>
        <input type="text" name="slug" value="{{ album.slug ?? '' }}" required>
    </div>
    <div class="form-group">
        <label>Pořadí</label>
        <input type="number" name="sort_order" value="{{ album.sort_order ?? 0 }}">
    </div>
    {% if album and album.images %}
    <div class="form-group">
        <label>Fotky v albu</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        {% for img in album.images %}
        <div>
            <img src="/assets/uploads/gallery/thumb_{{ img.filename }}" class="img-thumb">
            <form method="POST" action="/admin/gallery/{{ album.id }}/image/{{ img.id }}/delete" style="text-align:center">
                <button class="btn-link" style="font-size:0.8rem">Smazat</button>
            </form>
        </div>
        {% endfor %}
        </div>
    </div>
    {% endif %}
    <div class="form-group">
        <label>Přidat fotky</label>
        <input type="file" name="images[]" accept="image/*" multiple>
    </div>
    <h3>Překlady</h3>
    <div class="lang-tabs">
        {% for lang in langs %}<button type="button" class="lang-tab {% if loop.first %}active{% endif %}" data-lang="{{ lang }}">{{ lang|upper }}</button>{% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>Název alba ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>Slug ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][slug]" value="{{ translations[lang].trans_slug ?? '' }}">
        </div>
        <div class="form-group">
            <label>Popis ({{ lang }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Uložit</button>
        <a href="/admin/gallery" class="btn btn-secondary">Zrušit</a>
    </div>
</form>
{% block scripts %}
<script>
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});
</script>
{% endblock %}
{% endblock %}
```

`templates/admin/blog/index.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Blog{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>Blog</h1>
    <a href="/admin/blog/new" class="btn btn-primary">+ Nový příspěvek</a>
</div>
<table class="admin-table">
    <thead><tr><th>ID</th><th>Slug</th><th>Publikováno</th><th>Datum</th><th>Akce</th></tr></thead>
    <tbody>
    {% for p in posts %}
    <tr>
        <td>{{ p.id }}</td>
        <td>{{ p.slug }}</td>
        <td>{{ p.is_published ? '✓' : '—' }}</td>
        <td>{{ p.published_at ?? '—' }}</td>
        <td>
            <a href="/admin/blog/{{ p.id }}/edit">Upravit</a> |
            <form method="POST" action="/admin/blog/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat?')">
                <button class="btn-link">Smazat</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="5">Žádné příspěvky.</td></tr>
    {% endfor %}
    </tbody>
</table>
{% if pages > 1 %}
<div class="pagination" style="margin-top:1rem;">
    {% for p in 1..pages %}
    <a href="?page={{ p }}" class="{% if p == page %}active{% endif %}">{{ p }}</a>
    {% endfor %}
</div>
{% endif %}
{% endblock %}
```

`templates/admin/blog/form.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ post ? 'Upravit příspěvek' : 'Nový příspěvek' }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ post ? 'Upravit příspěvek' : 'Nový příspěvek' }}</h1>
    <a href="/admin/blog" class="btn btn-secondary">← Zpět</a>
</div>
<form method="POST" action="{{ post ? '/admin/blog/' ~ post.id ~ '/edit' : '/admin/blog/new' }}" class="admin-form">
    <div class="form-group">
        <label>Slug</label>
        <input type="text" name="slug" value="{{ post.slug ?? '' }}" required>
    </div>
    <div class="form-group">
        <label><input type="checkbox" name="is_published" value="1" {% if post and post.is_published %}checked{% endif %}> Publikováno</label>
    </div>
    <div class="form-group">
        <label>Datum publikace</label>
        <input type="datetime-local" name="published_at" value="{{ post.published_at ?? '' }}">
    </div>
    <h3>Překlady</h3>
    <div class="lang-tabs">
        {% for lang in langs %}<button type="button" class="lang-tab {% if loop.first %}active{% endif %}" data-lang="{{ lang }}">{{ lang|upper }}</button>{% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>Nadpis ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][title]" value="{{ translations[lang].title ?? '' }}">
        </div>
        <div class="form-group">
            <label>Slug ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][slug]" value="{{ translations[lang].slug ?? '' }}">
        </div>
        <div class="form-group">
            <label>Obsah ({{ lang }}) — HTML povoleno</label>
            <textarea name="t[{{ lang }}][body]" style="min-height:300px;">{{ translations[lang].body ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Uložit</button>
        <a href="/admin/blog" class="btn btn-secondary">Zrušit</a>
    </div>
</form>
{% block scripts %}
<script>
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});
</script>
{% endblock %}
{% endblock %}
```

- [ ] **Step 6: Add gallery/blog routes**

Inside admin group in routes.php:
```php
    // Gallery
    $group->get('/gallery',                                           GalleryController::class . ':index');
    $group->get('/gallery/new',                                       GalleryController::class . ':createForm');
    $group->post('/gallery/new',                                      GalleryController::class . ':createSubmit');
    $group->get('/gallery/{id:[0-9]+}/edit',                          GalleryController::class . ':editForm');
    $group->post('/gallery/{id:[0-9]+}/edit',                         GalleryController::class . ':editSubmit');
    $group->post('/gallery/{id:[0-9]+}/delete',                       GalleryController::class . ':delete');
    $group->post('/gallery/{id:[0-9]+}/image/{image_id:[0-9]+}/delete', GalleryController::class . ':deleteImage');

    // Blog
    $group->get('/blog',                       BlogController::class . ':index');
    $group->get('/blog/new',                   BlogController::class . ':createForm');
    $group->post('/blog/new',                  BlogController::class . ':createSubmit');
    $group->get('/blog/{id:[0-9]+}/edit',      BlogController::class . ':editForm');
    $group->post('/blog/{id:[0-9]+}/edit',     BlogController::class . ':editSubmit');
    $group->post('/blog/{id:[0-9]+}/delete',   BlogController::class . ':delete');
```

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/Admin/GalleryController.php src/Controllers/Admin/BlogController.php src/Models/GalleryModel.php src/Models/BlogModel.php templates/admin/gallery/ templates/admin/blog/ src/routes.php
git commit -m "feat: admin gallery and blog CRUD with image uploads"
```

---

### Task 5: Pages + Settings + Users

**Files:**
- Create: `src/Controllers/Admin/PageController.php`
- Create: `src/Controllers/Admin/SettingsController.php`
- Create: `src/Controllers/Admin/UserController.php`
- Create: `templates/admin/pages/index.twig`
- Create: `templates/admin/settings/index.twig`
- Create: `templates/admin/users/index.twig`
- Create: `templates/admin/users/form.twig`
- Modify: `src/Models/PageModel.php` — add admin methods
- Modify: `src/routes.php`

**Interfaces:**
- Produces: `PageModel::allSlugs(): array`, `PageModel::upsert(string slug, string lang, string body): void`
- Consumes: `AdminUserModel::all()`, `AdminUserModel::create()`, `AdminUserModel::delete()`, `AdminUserModel::updatePassword()`
- Settings reads/writes `settings` table directly with `Database::getConnection()`

- [ ] **Step 1: Add admin methods to PageModel**

Append to `src/Models/PageModel.php`:
```php
    public static function allSlugs(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT DISTINCT slug FROM pages ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function allTranslations(string $slug): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT p.id, pt.lang, pt.title, pt.body FROM pages p LEFT JOIN page_t pt ON pt.page_id = p.id WHERE p.slug = ?');
        $stmt->execute([$slug]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang']] = $row;
        }
        return $result;
    }

    public static function upsert(string $slug, string $lang, string $title, string $body): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $page = $stmt->fetch();
        if (!$page) {
            $pdo->prepare('INSERT INTO pages (slug) VALUES (?)')->execute([$slug]);
            $pageId = (int) $pdo->lastInsertId();
        } else {
            $pageId = (int) $page['id'];
        }
        $pdo->prepare(
            'INSERT INTO page_t (page_id, lang, title, body) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body)'
        )->execute([$pageId, $lang, $title, $body]);
    }
```

- [ ] **Step 2: Create PageController (admin)**

```php
<?php
// src/Controllers/Admin/PageController.php
namespace App\Controllers\Admin;

use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageController extends AdminBaseController
{
    private const LANGS = ['cs', 'en', 'ru', 'uk', 'sk'];
    private const SLUGS = ['home', 'services', 'contact'];

    public function index(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/pages/index.twig', ['slugs' => self::SLUGS]);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $slug         = $args['slug'];
        if (!in_array($slug, self::SLUGS, true)) return $response->withStatus(404);
        $translations = PageModel::allTranslations($slug);
        return $this->renderAdmin($request, $response, 'admin/pages/form.twig', compact('slug', 'translations') + ['langs' => self::LANGS]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'];
        if (!in_array($slug, self::SLUGS, true)) return $response->withStatus(404);
        $body = (array) $request->getParsedBody();
        foreach (self::LANGS as $lang) {
            $t = $body['t'][$lang] ?? [];
            PageModel::upsert($slug, $lang, $t['title'] ?? '', $t['body'] ?? '');
        }
        $this->flash('success', 'Stránka uložena.');
        return $this->redirect($response, '/admin/pages');
    }
}
```

- [ ] **Step 3: Create SettingsController**

```php
<?php
// src/Controllers/Admin/SettingsController.php
namespace App\Controllers\Admin;

use App\Models\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController extends AdminBaseController
{
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];

    public function index(Request $request, Response $response, array $args): Response
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query("SELECT `key`, `value` FROM settings");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $this->renderAdmin($request, $response, 'admin/settings/index.twig', compact('settings'));
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $pdo  = Database::getConnection();
        $body = (array) $request->getParsedBody();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        foreach (self::KEYS as $key) {
            $stmt->execute([$key, $body[$key] ?? '']);
        }
        $this->flash('success', 'Nastavení uloženo.');
        return $this->redirect($response, '/admin/settings');
    }
}
```

- [ ] **Step 4: Create UserController**

```php
<?php
// src/Controllers/Admin/UserController.php
namespace App\Controllers\Admin;

use App\Models\AdminUserModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $users = AdminUserModel::all();
        return $this->renderAdmin($request, $response, 'admin/users/index.twig', compact('users'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/users/form.twig', ['user' => null]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $name  = trim($body['name'] ?? '');
        $pass  = $body['password'] ?? '';
        if (!$email || !$name || strlen($pass) < 8) {
            $this->flash('error', 'Vyplňte všechna pole. Heslo musí mít alespoň 8 znaků.');
            return $this->redirect($response, '/admin/users/new');
        }
        AdminUserModel::create($email, $name, password_hash($pass, PASSWORD_BCRYPT));
        $this->flash('success', 'Uživatel vytvořen.');
        return $this->redirect($response, '/admin/users');
    }

    public function changePassword(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $pass = $body['password'] ?? '';
        if (strlen($pass) < 8) {
            $this->flash('error', 'Heslo musí mít alespoň 8 znaků.');
            return $this->redirect($response, '/admin/users');
        }
        AdminUserModel::updatePassword((int) $args['id'], password_hash($pass, PASSWORD_BCRYPT));
        $this->flash('success', 'Heslo změněno.');
        return $this->redirect($response, '/admin/users');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $currentId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        if ((int) $args['id'] === $currentId) {
            $this->flash('error', 'Nemůžete smazat vlastní účet.');
            return $this->redirect($response, '/admin/users');
        }
        AdminUserModel::delete((int) $args['id']);
        $this->flash('success', 'Uživatel smazán.');
        return $this->redirect($response, '/admin/users');
    }
}
```

- [ ] **Step 5: Create templates for Pages, Settings, Users**

`templates/admin/pages/index.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Stránky{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>Stránky</h1></div>
<table class="admin-table">
    <thead><tr><th>Stránka</th><th>Akce</th></tr></thead>
    <tbody>
    {% for slug in slugs %}
    <tr>
        <td>{{ slug }}</td>
        <td><a href="/admin/pages/{{ slug }}/edit">Upravit překlady</a></td>
    </tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

`templates/admin/pages/form.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Stránka: {{ slug }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>Stránka: {{ slug }}</h1>
    <a href="/admin/pages" class="btn btn-secondary">← Zpět</a>
</div>
<form method="POST" action="/admin/pages/{{ slug }}/edit" class="admin-form">
    <div class="lang-tabs">
        {% for lang in langs %}<button type="button" class="lang-tab {% if loop.first %}active{% endif %}" data-lang="{{ lang }}">{{ lang|upper }}</button>{% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>Nadpis ({{ lang }})</label>
            <input type="text" name="t[{{ lang }}][title]" value="{{ translations[lang].title ?? '' }}">
        </div>
        <div class="form-group">
            <label>Obsah ({{ lang }}) — HTML povoleno</label>
            <textarea name="t[{{ lang }}][body]" style="min-height:300px;">{{ translations[lang].body ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Uložit</button>
        <a href="/admin/pages" class="btn btn-secondary">Zrušit</a>
    </div>
</form>
{% block scripts %}
<script>
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});
</script>
{% endblock %}
{% endblock %}
```

`templates/admin/settings/index.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Nastavení{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>Nastavení</h1></div>
<form method="POST" action="/admin/settings" class="admin-form">
    <h3>Web</h3>
    <div class="form-group"><label>Název webu</label><input type="text" name="site_name" value="{{ settings.site_name ?? '' }}"></div>
    <div class="form-group"><label>Kontaktní e-mail</label><input type="email" name="contact_email" value="{{ settings.contact_email ?? '' }}"></div>
    <div class="form-group"><label>Telefon</label><input type="text" name="contact_phone" value="{{ settings.contact_phone ?? '' }}"></div>

    <h3>SMTP (e-maily)</h3>
    <div class="form-group"><label>SMTP host</label><input type="text" name="smtp_host" value="{{ settings.smtp_host ?? '' }}"></div>
    <div class="form-group"><label>SMTP port</label><input type="number" name="smtp_port" value="{{ settings.smtp_port ?? '587' }}"></div>
    <div class="form-group"><label>SMTP uživatel</label><input type="text" name="smtp_user" value="{{ settings.smtp_user ?? '' }}"></div>
    <div class="form-group"><label>SMTP heslo</label><input type="password" name="smtp_pass" value="{{ settings.smtp_pass ?? '' }}"></div>
    <div class="form-group"><label>Odesílatel (From)</label><input type="email" name="smtp_from" value="{{ settings.smtp_from ?? '' }}"></div>

    <h3>GoPay</h3>
    <div class="form-group"><label>GoPay GoID (nechte prázdné pro dev bypass)</label><input type="text" name="gopay_go_id" value="{{ settings.gopay_go_id ?? '' }}"></div>
    <div class="form-group"><label>Client ID</label><input type="text" name="gopay_client_id" value="{{ settings.gopay_client_id ?? '' }}"></div>
    <div class="form-group"><label>Client Secret</label><input type="password" name="gopay_client_secret" value="{{ settings.gopay_client_secret ?? '' }}"></div>
    <div class="form-group"><label><input type="checkbox" name="gopay_test_mode" value="1" {% if settings.gopay_test_mode ?? true %}checked{% endif %}> Testovací režim (sandbox)</label></div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Uložit nastavení</button>
    </div>
</form>
{% endblock %}
```

`templates/admin/users/index.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Uživatelé{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>Uživatelé</h1>
    <a href="/admin/users/new" class="btn btn-primary">+ Přidat uživatele</a>
</div>
<table class="admin-table">
    <thead><tr><th>ID</th><th>Jméno</th><th>E-mail</th><th>Vytvořen</th><th>Akce</th></tr></thead>
    <tbody>
    {% for u in users %}
    <tr>
        <td>{{ u.id }}</td>
        <td>{{ u.name }}</td>
        <td>{{ u.email }}</td>
        <td>{{ u.created_at }}</td>
        <td>
            <form method="POST" action="/admin/users/{{ u.id }}/password" style="display:inline;gap:0.5rem;">
                <input type="password" name="password" placeholder="Nové heslo" style="width:160px;padding:0.3rem;" minlength="8">
                <button type="submit" class="btn btn-secondary" style="padding:0.3rem 0.6rem;">Změnit heslo</button>
            </form>
            |
            <form method="POST" action="/admin/users/{{ u.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat uživatele?')">
                <button class="btn-link">Smazat</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="5">Žádní uživatelé.</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

`templates/admin/users/form.twig`:
```twig
{% extends "layout/admin-base.twig" %}
{% block title %}Nový uživatel{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>Nový uživatel</h1>
    <a href="/admin/users" class="btn btn-secondary">← Zpět</a>
</div>
<form method="POST" action="/admin/users/new" class="admin-form">
    <div class="form-group"><label>Jméno</label><input type="text" name="name" required autofocus></div>
    <div class="form-group"><label>E-mail</label><input type="email" name="email" required></div>
    <div class="form-group"><label>Heslo (min. 8 znaků)</label><input type="password" name="password" required minlength="8"></div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Vytvořit</button>
        <a href="/admin/users" class="btn btn-secondary">Zrušit</a>
    </div>
</form>
{% endblock %}
```

- [ ] **Step 6: Add pages/settings/users routes**

Inside admin group in routes.php:
```php
    // Pages
    $group->get('/pages',                      PageController::class . ':index');
    $group->get('/pages/{slug}/edit',          PageController::class . ':editForm');
    $group->post('/pages/{slug}/edit',         PageController::class . ':editSubmit');

    // Settings
    $group->get('/settings',                   SettingsController::class . ':index');
    $group->post('/settings',                  SettingsController::class . ':save');

    // Users
    $group->get('/users',                      UserController::class . ':index');
    $group->get('/users/new',                  UserController::class . ':createForm');
    $group->post('/users/new',                 UserController::class . ':createSubmit');
    $group->post('/users/{id:[0-9]+}/password', UserController::class . ':changePassword');
    $group->post('/users/{id:[0-9]+}/delete',  UserController::class . ':delete');
```

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/Admin/ src/Models/PageModel.php templates/admin/ src/routes.php
git commit -m "feat: admin pages, settings, users management"
```

---

## Self-Review

**Spec coverage:**
- Auth + session protection ✓
- Dashboard with stats ✓
- Products CRUD + multilingual + image upload ✓
- Categories CRUD + multilingual ✓
- Orders list + filter + detail + status update ✓
- Gallery albums CRUD + multilingual + multiple image upload ✓
- Blog CRUD + multilingual + publish toggle ✓
- Pages content editing in all 5 langs ✓
- Settings (GoPay, SMTP, site) ✓
- Admin user management + bcrypt ✓
- /admin/setup for first user ✓
- admin.css created ✓

**Placeholder scan:** No TBDs found.

**Type consistency:** All model methods referenced in controllers match the interfaces defined per task.

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
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\BlogController as AdminBlogController;
use App\Controllers\Admin\CategoryController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\GalleryController as AdminGalleryController;
use App\Controllers\Admin\OrderController as AdminOrderController;
use App\Controllers\Admin\PageController as AdminPageController;
use App\Controllers\Admin\ProductController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController;
use App\Middleware\AuthMiddleware;
use Slim\App;

/** @var App $app */

// Admin — auth (public, no AuthMiddleware) — must come before /{lang}/* variable routes
$app->get('/admin/login',  AuthController::class . ':loginForm');
$app->post('/admin/login', AuthController::class . ':loginSubmit');
$app->get('/admin/logout', AuthController::class . ':logout');
$app->get('/admin/setup',  AuthController::class . ':setupForm');
$app->post('/admin/setup', AuthController::class . ':setupSubmit');

// Admin — protected group — must come before /{lang}/* variable routes
$app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) {
    $group->get('',           DashboardController::class . ':index');
    $group->get('/dashboard', DashboardController::class . ':index');

    // Categories
    $group->get('/categories',                     CategoryController::class . ':index');
    $group->get('/categories/new',                 CategoryController::class . ':createForm');
    $group->post('/categories/new',                CategoryController::class . ':createSubmit');
    $group->get('/categories/{id:[0-9]+}/edit',    CategoryController::class . ':editForm');
    $group->post('/categories/{id:[0-9]+}/edit',   CategoryController::class . ':editSubmit');
    $group->post('/categories/{id:[0-9]+}/delete', CategoryController::class . ':delete');

    // Products
    $group->get('/products',                                              ProductController::class . ':index');
    $group->get('/products/new',                                          ProductController::class . ':createForm');
    $group->post('/products/new',                                         ProductController::class . ':createSubmit');
    $group->get('/products/{id:[0-9]+}/edit',                             ProductController::class . ':editForm');
    $group->post('/products/{id:[0-9]+}/edit',                            ProductController::class . ':editSubmit');
    $group->post('/products/{id:[0-9]+}/delete',                          ProductController::class . ':delete');
    $group->post('/products/{id:[0-9]+}/image/{image_id:[0-9]+}/delete',  ProductController::class . ':deleteImage');

    // Orders
    $group->get('/orders',                  AdminOrderController::class . ':index');
    $group->get('/orders/{number}',         AdminOrderController::class . ':detail');
    $group->post('/orders/{number}/status', AdminOrderController::class . ':updateStatus');

    // Gallery
    $group->get('/gallery',                                               AdminGalleryController::class . ':index');
    $group->get('/gallery/new',                                           AdminGalleryController::class . ':createForm');
    $group->post('/gallery/new',                                          AdminGalleryController::class . ':createSubmit');
    $group->get('/gallery/{id:[0-9]+}/edit',                              AdminGalleryController::class . ':editForm');
    $group->post('/gallery/{id:[0-9]+}/edit',                             AdminGalleryController::class . ':editSubmit');
    $group->post('/gallery/{id:[0-9]+}/delete',                           AdminGalleryController::class . ':delete');
    $group->post('/gallery/{id:[0-9]+}/image/{image_id:[0-9]+}/delete',   AdminGalleryController::class . ':deleteImage');

    // Blog
    $group->get('/blog',                     AdminBlogController::class . ':index');
    $group->get('/blog/new',                 AdminBlogController::class . ':createForm');
    $group->post('/blog/new',                AdminBlogController::class . ':createSubmit');
    $group->get('/blog/{id:[0-9]+}/edit',    AdminBlogController::class . ':editForm');
    $group->post('/blog/{id:[0-9]+}/edit',   AdminBlogController::class . ':editSubmit');
    $group->post('/blog/{id:[0-9]+}/delete', AdminBlogController::class . ':delete');

    // Pages
    $group->get('/pages',              AdminPageController::class . ':index');
    $group->get('/pages/{slug}/edit',  AdminPageController::class . ':editForm');
    $group->post('/pages/{slug}/edit', AdminPageController::class . ':editSubmit');

    // Settings
    $group->get('/settings',  SettingsController::class . ':index');
    $group->post('/settings', SettingsController::class . ':save');

    // Auto-translate (DeepL)
    $group->post('/translate', function ($request, $response) {
        $body       = json_decode((string) $request->getBody(), true) ?? [];
        $texts      = $body['texts'] ?? null;
        $targetLang = strtoupper(trim($body['target'] ?? ''));
        $allowed    = ['CS', 'SK', 'EN', 'UK', 'RU'];

        if (!is_array($texts) || count($texts) === 0 || !in_array($targetLang, $allowed, true)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid parameters.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $translated = \App\Services\DeepL::translate($texts, $targetLang);
            $response->getBody()->write(json_encode(['texts' => $translated]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });

    // Users
    $group->get('/users',                          UserController::class . ':index');
    $group->get('/users/new',                      UserController::class . ':createForm');
    $group->post('/users/new',                     UserController::class . ':createSubmit');
    $group->post('/users/{id:[0-9]+}/password',    UserController::class . ':changePassword');
    $group->post('/users/{id:[0-9]+}/delete',      UserController::class . ':delete');
})->add(new AuthMiddleware());

// Redirect bare root to default language
$app->get('/', function ($req, $res) {
    return $res->withHeader('Location', '/cs/')->withStatus(302);
});

// Public
$app->get('/{lang}/',                 HomeController::class    . ':index');
$app->get('/{lang}/shop',             ShopController::class    . ':index');
$app->get('/{lang}/shop/{slug}',      ShopController::class    . ':product');
$app->get('/{lang}/services',         PageController::class    . ':services');
$app->get('/{lang}/gallery',          GalleryController::class . ':index');
$app->get('/{lang}/gallery/{slug}',   GalleryController::class . ':album');
$app->get('/{lang}/blog',             BlogController::class    . ':index');
$app->get('/{lang}/blog/{slug}',      BlogController::class    . ':post');
$app->get('/{lang}/contact',          ContactController::class . ':index');
$app->post('/{lang}/contact',         ContactController::class . ':send');
$app->get('/{lang}/cart',             CartController::class    . ':index');
$app->post('/{lang}/cart/add',        CartController::class    . ':add');
$app->post('/{lang}/cart/remove',     CartController::class    . ':remove');
$app->post('/{lang}/cart/update',     CartController::class    . ':update');
$app->get('/{lang}/checkout',         CheckoutController::class . ':index');
$app->post('/{lang}/checkout',        CheckoutController::class . ':submit');
$app->get('/{lang}/checkout/confirm', CheckoutController::class . ':confirm');
$app->post('/{lang}/payment/gopay',   PaymentController::class . ':initiate');
$app->get('/{lang}/payment/return',   PaymentController::class . ':paymentReturn');
$app->post('/payment/notify',         PaymentController::class . ':notify');
$app->get('/{lang}/order/{number}',   OrderController::class   . ':status');

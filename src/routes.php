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

// Admin routes — added in Plan 4

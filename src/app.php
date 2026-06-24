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

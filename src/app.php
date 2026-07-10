<?php
use App\Middleware\LangMiddleware;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$prodConfig = __DIR__ . '/../config/settings.prod.php';
if (file_exists($prodConfig)) {
    $settings = array_replace_recursive($settings, require $prodConfig);
}

$builder = new ContainerBuilder();
$builder->addDefinitions([
    'settings' => $settings,
    Twig::class => function () {
        $twig = Twig::create(
            __DIR__ . '/../templates',
            ['cache' => false]   // production: __DIR__ . '/../tmp/twig_cache'
        );
        $twig->getEnvironment()->addFunction(new \Twig\TwigFunction('asset_v', function (string $path) {
            $full = __DIR__ . '/../www/' . ltrim($path, '/');
            return file_exists($full) ? filemtime($full) : time();
        }));
        $twig->getEnvironment()->addFunction(new \Twig\TwigFunction('site_version', function () {
            return \App\Services\Version::current();
        }));
        return $twig;
    },
]);
$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app->add(new LangMiddleware(
    $settings['languages'],
    $settings['default_lang'],
    __DIR__ . '/../lang'
));
$app->addRoutingMiddleware();
$app->addErrorMiddleware($settings['displayErrorDetails'], true, true);

require __DIR__ . '/routes.php';

return $app;

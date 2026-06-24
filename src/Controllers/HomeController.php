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

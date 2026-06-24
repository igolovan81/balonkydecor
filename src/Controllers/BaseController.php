<?php
namespace App\Controllers;

use App\Services\I18n;
use App\Twig\I18nExtension;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class BaseController
{
    public function __construct(protected Twig $twig) {}

    protected function render(
        Request  $request,
        Response $response,
        string   $template,
        array    $data = []
    ): Response {
        /** @var I18n $i18n */
        $i18n = $request->getAttribute('i18n');
        $lang = $request->getAttribute('lang');

        $env = $this->twig->getEnvironment();
        if (!$env->hasExtension(I18nExtension::class)) {
            $env->addExtension(new I18nExtension($i18n));
        }

        $uri  = $request->getUri()->getPath();
        $path = preg_replace('#^/' . preg_quote($lang, '#') . '#', '', $uri) ?: '/';

        return $this->twig->render($response, $template, array_merge([
            'lang'         => $lang,
            'current_path' => $path,
        ], $data));
    }
}

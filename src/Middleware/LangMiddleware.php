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

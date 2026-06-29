<?php
namespace App\Middleware;

use App\Services\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AdminLangMiddleware implements MiddlewareInterface
{
    private const SUPPORTED = ['cs', 'sk', 'en', 'uk', 'ru'];

    public function __construct(private string $langDir) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $_SESSION['admin_lang'] ?? 'cs';
        if (!in_array($lang, self::SUPPORTED, true)) {
            $lang = 'cs';
        }
        return $handler->handle(
            $request
                ->withAttribute('admin_i18n', new I18n($lang, $this->langDir))
                ->withAttribute('admin_lang', $lang)
        );
    }
}

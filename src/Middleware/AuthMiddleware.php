<?php
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

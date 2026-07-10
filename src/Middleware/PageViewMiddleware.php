<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PageViewMiddleware implements MiddlewareInterface
{
    private \Closure $recorder;

    public function __construct(private array $supportedLangs, \Closure $recorder)
    {
        $this->recorder = $recorder;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($request->getMethod() === 'GET') {
            $path    = $request->getUri()->getPath();
            $segment = explode('/', ltrim($path, '/'))[0];

            if (in_array($segment, $this->supportedLangs, true)) {
                $server    = $request->getServerParams();
                $ip        = $server['REMOTE_ADDR'] ?? '';
                $referrer  = $request->getHeaderLine('Referer') ?: null;
                $userAgent = $request->getHeaderLine('User-Agent') ?: null;

                ($this->recorder)($path, $segment, $referrer, $ip, $userAgent);
            }
        }

        return $response;
    }
}

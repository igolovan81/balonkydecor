<?php
namespace Tests\Unit\Middleware;

use App\Middleware\PageViewMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PageViewMiddlewareTest extends TestCase
{
    private const LANGS = ['cs', 'sk', 'en', 'uk', 'ru'];

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                return (new ResponseFactory())->createResponse();
            }
        };
    }

    public function test_records_get_request_under_supported_lang_path(): void
    {
        $calls = [];
        $mw    = new PageViewMiddleware(self::LANGS, function (...$args) use (&$calls) {
            $calls[] = $args;
        });

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/cs/shop', ['REMOTE_ADDR' => '1.2.3.4'])
            ->withHeader('Referer', 'https://google.com')
            ->withHeader('User-Agent', 'TestAgent/1.0');

        $mw->process($req, $this->passthroughHandler());

        $this->assertCount(1, $calls);
        [$path, $lang, $referrer, $ip, $userAgent] = $calls[0];
        $this->assertSame('/cs/shop', $path);
        $this->assertSame('cs', $lang);
        $this->assertSame('https://google.com', $referrer);
        $this->assertSame('1.2.3.4', $ip);
        $this->assertSame('TestAgent/1.0', $userAgent);
    }

    public function test_skips_admin_paths(): void
    {
        $calls = [];
        $mw    = new PageViewMiddleware(self::LANGS, function (...$args) use (&$calls) {
            $calls[] = $args;
        });

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/services');
        $mw->process($req, $this->passthroughHandler());

        $this->assertCount(0, $calls);
    }

    public function test_skips_non_get_requests(): void
    {
        $calls = [];
        $mw    = new PageViewMiddleware(self::LANGS, function (...$args) use (&$calls) {
            $calls[] = $args;
        });

        $req = (new ServerRequestFactory())->createServerRequest('POST', '/cs/cart/add');
        $mw->process($req, $this->passthroughHandler());

        $this->assertCount(0, $calls);
    }

    public function test_skips_unsupported_lang_segment(): void
    {
        $calls = [];
        $mw    = new PageViewMiddleware(self::LANGS, function (...$args) use (&$calls) {
            $calls[] = $args;
        });

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/robots.txt');
        $mw->process($req, $this->passthroughHandler());

        $this->assertCount(0, $calls);
    }
}

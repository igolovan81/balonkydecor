<?php
namespace Tests\Unit\Middleware;

use App\Middleware\AdminLangMiddleware;
use App\Services\I18n;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class AdminLangMiddlewareTest extends TestCase
{
    private string $langDir;
    private AdminLangMiddleware $middleware;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $this->langDir   = __DIR__ . '/../../../lang/admin';
        $this->middleware = new AdminLangMiddleware($this->langDir);
    }

    private function makeHandler(): object
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $captured = null;
            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->captured = $request;
                return new Response();
            }
        };
    }

    public function test_defaults_to_cs_when_session_key_absent(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin');
        $handler = $this->makeHandler();

        $this->middleware->process($request, $handler);

        $this->assertSame('cs', $handler->captured->getAttribute('admin_lang'));
        $this->assertInstanceOf(I18n::class, $handler->captured->getAttribute('admin_i18n'));
    }

    public function test_uses_lang_from_session(): void
    {
        $_SESSION['admin_lang'] = 'en';
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin');
        $handler = $this->makeHandler();

        $this->middleware->process($request, $handler);

        $this->assertSame('en', $handler->captured->getAttribute('admin_lang'));
    }

    public function test_falls_back_to_cs_for_unsupported_lang(): void
    {
        $_SESSION['admin_lang'] = 'de';
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin');
        $handler = $this->makeHandler();

        $this->middleware->process($request, $handler);

        $this->assertSame('cs', $handler->captured->getAttribute('admin_lang'));
    }
}

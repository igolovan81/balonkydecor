<?php
namespace Tests\Unit\Middleware;

use App\Middleware\LangMiddleware;
use App\Services\I18n;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LangMiddlewareTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/balonky_mw_' . uniqid();
        mkdir($this->langDir);
        foreach (['cs','ru','en','uk','sk'] as $code) {
            file_put_contents($this->langDir . "/{$code}.json", json_encode(['k' => $code]));
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->langDir . '/*.json'));
        rmdir($this->langDir);
    }

    private function captureHandler(string &$lang, string &$i18nLang): RequestHandlerInterface
    {
        return new class($lang, $i18nLang) implements RequestHandlerInterface {
            public function __construct(private string &$lang, private string &$i18nLang) {}
            public function handle(ServerRequestInterface $req): ResponseInterface {
                $this->lang     = $req->getAttribute('lang', '');
                $i18n = $req->getAttribute('i18n');
                $this->i18nLang = $i18n instanceof I18n ? $i18n->getLang() : '';
                return (new ResponseFactory())->createResponse();
            }
        };
    }

    public function test_extracts_lang_from_url(): void
    {
        $lang = ''; $i18nLang = '';
        $mw  = new LangMiddleware(['cs','ru','en','uk','sk'], 'cs', $this->langDir);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/ru/shop');
        $mw->process($req, $this->captureHandler($lang, $i18nLang));
        $this->assertSame('ru', $lang);
        $this->assertSame('ru', $i18nLang);
    }

    public function test_defaults_when_no_lang_prefix(): void
    {
        $lang = ''; $i18nLang = '';
        $mw  = new LangMiddleware(['cs','ru','en','uk','sk'], 'cs', $this->langDir);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/login');
        $mw->process($req, $this->captureHandler($lang, $i18nLang));
        $this->assertSame('cs', $lang);
    }

    public function test_unsupported_segment_falls_back_to_default(): void
    {
        $lang = ''; $i18nLang = '';
        $mw  = new LangMiddleware(['cs','ru','en','uk','sk'], 'cs', $this->langDir);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/fr/whatever');
        $mw->process($req, $this->captureHandler($lang, $i18nLang));
        $this->assertSame('cs', $lang);
    }
}

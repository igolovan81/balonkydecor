<?php
namespace Tests\Unit\Services;

use App\Services\I18n;
use PHPUnit\Framework\TestCase;

class I18nTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/balonky_i18n_' . uniqid();
        mkdir($this->langDir);
        file_put_contents($this->langDir . '/en.json', json_encode([
            'nav.home' => 'Home',
            'greeting' => 'Hello, {name}!',
        ]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->langDir . '/*.json'));
        rmdir($this->langDir);
    }

    public function test_translates_known_key(): void
    {
        $i18n = new I18n('en', $this->langDir);
        $this->assertSame('Home', $i18n->t('nav.home'));
    }

    public function test_returns_key_when_missing(): void
    {
        $i18n = new I18n('en', $this->langDir);
        $this->assertSame('missing.key', $i18n->t('missing.key'));
    }

    public function test_interpolates_params(): void
    {
        $i18n = new I18n('en', $this->langDir);
        $this->assertSame('Hello, Igor!', $i18n->t('greeting', ['name' => 'Igor']));
    }

    public function test_get_lang(): void
    {
        $i18n = new I18n('en', $this->langDir);
        $this->assertSame('en', $i18n->getLang());
    }
}

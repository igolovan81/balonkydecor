<?php
namespace Tests\Unit\Services;

use App\Services\Translator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TranslatorTest extends TestCase
{
    private function fakeTransport(string $responseBody): callable
    {
        return function (string $url) use ($responseBody): string {
            return $responseBody;
        };
    }

    public function test_translate_returns_translated_texts(): void
    {
        $transport = function (string $url): string {
            if (str_contains($url, rawurlencode('Balónky'))) {
                return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balloons']]);
            }
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Description']]);
        };

        $result = Translator::translate(['Balónky', 'Popis'], 'EN', $transport);

        $this->assertSame(['Balloons', 'Description'], $result);
    }

    public function test_translate_throws_on_malformed_response(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected response/i');

        Translator::translate(['Balónky'], 'EN', $this->fakeTransport('not-json'));
    }

    public function test_translate_throws_on_invalid_target_lang(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid target language/i');

        Translator::translate(['Balónky'], 'DE', $this->fakeTransport('{}'));
    }

    public function test_transport_receives_correct_langpair(): void
    {
        $capturedUrl = null;
        $transport = function (string $url) use (&$capturedUrl): string {
            $capturedUrl = $url;
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balóny']]);
        };

        Translator::translate(['Balónky'], 'SK', $transport);

        $this->assertStringContainsString('langpair=cs|sk', $capturedUrl);
        $this->assertStringContainsString(rawurlencode('Balónky'), $capturedUrl);
    }
}

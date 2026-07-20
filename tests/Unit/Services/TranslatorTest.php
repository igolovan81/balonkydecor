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

        $result = Translator::translate(['Balónky', 'Popis'], 'CS', 'EN', $transport);

        $this->assertSame(['Balloons', 'Description'], $result);
    }

    public function test_translate_throws_on_malformed_response(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected response/i');

        Translator::translate(['Balónky'], 'CS', 'EN', $this->fakeTransport('not-json'));
    }

    public function test_translate_throws_on_invalid_target_lang(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid target language/i');

        Translator::translate(['Balónky'], 'CS', 'DE', $this->fakeTransport('{}'));
    }

    public function test_translate_throws_on_invalid_source_lang(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid source language/i');

        Translator::translate(['Balónky'], 'DE', 'EN', $this->fakeTransport('{}'));
    }

    public function test_translate_throws_when_source_equals_target(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/source and target language are the same/i');

        Translator::translate(['Balónky'], 'EN', 'en', $this->fakeTransport('{}'));
    }

    public function test_transport_receives_correct_langpair(): void
    {
        $capturedUrl = null;
        $transport = function (string $url) use (&$capturedUrl): string {
            $capturedUrl = $url;
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balóny']]);
        };

        Translator::translate(['Balónky'], 'CS', 'SK', $transport);

        $this->assertStringContainsString('langpair=cs|sk', $capturedUrl);
        $this->assertStringContainsString(rawurlencode('Balónky'), $capturedUrl);
    }

    public function test_transport_receives_non_czech_source_langpair(): void
    {
        $capturedUrl = null;
        $transport = function (string $url) use (&$capturedUrl): string {
            $capturedUrl = $url;
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balóny']]);
        };

        Translator::translate(['Balloons'], 'EN', 'SK', $transport);

        $this->assertStringContainsString('langpair=en|sk', $capturedUrl);
    }

    public function test_autofill_fills_empty_target_fields_from_source(): void
    {
        $transport = function (string $url): string {
            if (str_contains($url, rawurlencode('Balónky'))) {
                return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balloons']]);
            }
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Description here']]);
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => '', 'description' => ''],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame('Balloons', $result['en']['name']);
        $this->assertSame('Description here', $result['en']['description']);
    }

    public function test_autofill_skips_language_with_nothing_to_fill(): void
    {
        $calls = 0;
        $transport = function (string $url) use (&$calls): string {
            $calls++;
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'x']]);
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => 'Balloons', 'description' => 'Description'],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame(0, $calls);
        $this->assertSame('Balloons', $result['en']['name']);
        $this->assertSame('Description', $result['en']['description']);
    }

    public function test_autofill_leaves_language_blank_when_translation_fails(): void
    {
        $transport = function (string $url): string {
            return 'not-json';
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => '', 'description' => ''],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame('', $result['en']['name']);
        $this->assertSame('', $result['en']['description']);
    }

    public function test_autofill_does_not_overwrite_existing_partial_value(): void
    {
        $transport = function (string $url): string {
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Description here']]);
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => 'Custom Name', 'description' => ''],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame('Custom Name', $result['en']['name']);
        $this->assertSame('Description here', $result['en']['description']);
    }

    public function test_autofill_isolates_field_failures_from_siblings(): void
    {
        $transport = function (string $url): string {
            if (str_contains($url, rawurlencode('Popis'))) {
                return 'not-json';
            }
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balloons']]);
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => '', 'description' => ''],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame('Balloons', $result['en']['name']);
        $this->assertSame('', $result['en']['description']);
    }
}

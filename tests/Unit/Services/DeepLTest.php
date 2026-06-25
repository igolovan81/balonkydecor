<?php
namespace Tests\Unit\Services;

use App\Services\DeepL;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DeepLTest extends TestCase
{
    private function fakeTransport(string $responseBody): callable
    {
        return function (string $url, array $headers, string $body) use ($responseBody): string {
            return $responseBody;
        };
    }

    public function test_translate_returns_translated_texts(): void
    {
        $response = json_encode([
            'translations' => [
                ['detected_source_language' => 'CS', 'text' => 'Balloons'],
                ['detected_source_language' => 'CS', 'text' => 'Description'],
            ],
        ]);

        $result = DeepL::translate(['Balónky', 'Popis'], 'EN', $this->fakeTransport($response));

        $this->assertSame(['Balloons', 'Description'], $result);
    }

    public function test_translate_throws_on_malformed_response(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected response/i');

        DeepL::translate(['Balónky'], 'EN', $this->fakeTransport('not-json'));
    }

    public function test_translate_throws_when_api_key_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/api key not configured/i');

        // Pass null transport — service should throw before making any HTTP call
        DeepL::translateWithKey('', ['Balónky'], 'EN');
    }

    public function test_transport_receives_correct_target_lang(): void
    {
        $capturedBody = null;
        $transport = function (string $url, array $headers, string $body) use (&$capturedBody): string {
            $capturedBody = $body;
            return json_encode(['translations' => [['text' => 'Balóny']]]);
        };

        DeepL::translate(['Balónky'], 'SK', $transport);

        $decoded = json_decode($capturedBody, true);
        $this->assertSame('SK', $decoded['target_lang']);
        $this->assertSame(['Balónky'], $decoded['text']);
    }
}

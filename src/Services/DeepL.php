<?php
namespace App\Services;

use App\Models\Database;
use RuntimeException;

class DeepL
{
    private const ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    private const VALID_TARGETS = ['CS', 'SK', 'EN', 'UK', 'RU'];

    public static function translate(array $texts, string $targetLang, ?callable $transport = null): array
    {
        if ($transport !== null) {
            // Injectable transport signals test/mock mode — skip DB lookup.
            // Key validation is tested directly via translateWithKey().
            $key = 'injected';
        } else {
            $pdo = Database::getConnection();
            $key = (string) $pdo->query("SELECT `value` FROM settings WHERE `key` = 'deepl_api_key'")->fetchColumn();
        }

        return self::translateWithKey($key, $texts, $targetLang, $transport);
    }

    public static function translateWithKey(string $key, array $texts, string $targetLang, ?callable $transport = null): array
    {
        if ($key === '') {
            throw new RuntimeException('DeepL API key not configured.');
        }

        if (!in_array(strtoupper($targetLang), self::VALID_TARGETS, true)) {
            throw new RuntimeException('Invalid DeepL target language: ' . $targetLang);
        }

        $body    = json_encode(['text' => $texts, 'target_lang' => strtoupper($targetLang)]);
        $headers = [
            'Authorization: DeepL-Auth-Key ' . $key,
            'Content-Type: application/json',
        ];

        $transport   = $transport ?? self::curlTransport();
        $rawResponse = $transport(self::ENDPOINT, $headers, $body);

        $decoded = json_decode($rawResponse, true);
        if (!isset($decoded['translations']) || !is_array($decoded['translations'])) {
            throw new RuntimeException('DeepL unexpected response: ' . substr($rawResponse, 0, 200));
        }

        return array_map(fn($t) => $t['text'], $decoded['translations']);
    }

    private static function curlTransport(): callable
    {
        return function (string $url, array $headers, string $body): string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $response = curl_exec($ch);
            $error    = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('DeepL request failed: ' . $error);
            }

            if ($httpCode !== 200) {
                throw new RuntimeException('DeepL API error ' . $httpCode . ': ' . substr($response, 0, 200));
            }

            return $response;
        };
    }
}

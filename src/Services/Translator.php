<?php
namespace App\Services;

use RuntimeException;

class Translator
{
    private const ENDPOINT    = 'https://api.mymemory.translated.net/get';
    private const VALID_LANGS = ['CS', 'SK', 'EN', 'UK', 'RU'];
    private const LANG_MAP    = ['CS' => 'cs', 'SK' => 'sk', 'EN' => 'en', 'UK' => 'uk', 'RU' => 'ru'];

    public static function translate(array $texts, string $sourceLang, string $targetLang, ?callable $transport = null): array
    {
        $sourceLang = strtoupper($sourceLang);
        $targetLang = strtoupper($targetLang);

        if (!in_array($sourceLang, self::VALID_LANGS, true)) {
            throw new RuntimeException('Invalid source language: ' . $sourceLang);
        }

        if (!in_array($targetLang, self::VALID_LANGS, true)) {
            throw new RuntimeException('Invalid target language: ' . $targetLang);
        }

        if ($sourceLang === $targetLang) {
            throw new RuntimeException('Source and target language are the same: ' . $targetLang);
        }

        $sourceCode = self::LANG_MAP[$sourceLang];
        $targetCode = self::LANG_MAP[$targetLang];
        $transport  = $transport ?? self::curlTransport();
        $results    = [];

        foreach ($texts as $text) {
            $url  = self::ENDPOINT . '?q=' . rawurlencode((string) $text) . '&langpair=' . $sourceCode . '|' . $targetCode;
            $raw  = $transport($url);
            $data = json_decode($raw, true);

            if (!isset($data['responseData']['translatedText'])) {
                throw new RuntimeException('MyMemory unexpected response: ' . substr($raw, 0, 200));
            }

            if (isset($data['responseStatus']) && $data['responseStatus'] !== 200) {
                throw new RuntimeException('MyMemory error ' . $data['responseStatus'] . ': ' . $data['responseData']['translatedText']);
            }

            $results[] = $data['responseData']['translatedText'];
        }

        return $results;
    }

    private static function curlTransport(): callable
    {
        return function (string $url): string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['User-Agent: BalonkyDecor/1.0'],
            ]);
            $response = curl_exec($ch);
            $error    = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('MyMemory request failed: ' . $error);
            }

            if ($httpCode !== 200) {
                throw new RuntimeException('MyMemory HTTP error ' . $httpCode);
            }

            return $response;
        };
    }
}

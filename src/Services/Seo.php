<?php
namespace App\Services;

class Seo
{
    public const BASE_URL     = 'https://balonkydecor.cz';
    public const LANGUAGES    = ['cs', 'sk', 'en', 'uk', 'ru'];
    public const DEFAULT_LANG = 'cs';

    public static function canonicalUrl(string $lang, string $path): string
    {
        return self::BASE_URL . '/' . $lang . $path;
    }

    public static function alternateUrls(string $path): array
    {
        $urls = [];
        foreach (self::LANGUAGES as $lang) {
            $urls[] = ['lang' => $lang, 'url' => self::canonicalUrl($lang, $path)];
        }
        $urls[] = ['lang' => 'x-default', 'url' => self::canonicalUrl(self::DEFAULT_LANG, $path)];
        return $urls;
    }

    public static function organizationJsonLd(string $siteName, string $phone, string $email): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $siteName,
            'url'      => self::BASE_URL,
        ];
        if ($phone !== '') {
            $data['telephone'] = $phone;
        }
        if ($email !== '') {
            $data['email'] = $email;
        }
        // Deliberately no JSON_UNESCAPED_SLASHES: this string is emitted with |raw inside a
        // <script> tag (see Task 2), so "/" must stay escaped as "\/" to prevent a "</script>"
        // value (e.g. a malicious contact_phone/contact_email setting) from breaking out of it.
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

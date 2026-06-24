<?php
namespace App\Services;

class I18n
{
    private array $strings = [];

    public function __construct(private string $lang, string $langDir)
    {
        $file = $langDir . '/' . $lang . '.json';
        if (file_exists($file)) {
            $this->strings = json_decode(file_get_contents($file), true) ?? [];
        }
    }

    public function t(string $key, array $params = []): string
    {
        $str = $this->strings[$key] ?? $key;
        foreach ($params as $k => $v) {
            $str = str_replace('{' . $k . '}', (string) $v, $str);
        }
        return $str;
    }

    public function getLang(): string
    {
        return $this->lang;
    }
}

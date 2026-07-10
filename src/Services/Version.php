<?php
namespace App\Services;

class Version
{
    public static function current(?string $rootDir = null): string
    {
        $rootDir = $rootDir ?? __DIR__ . '/../..';
        $file    = rtrim($rootDir, '/') . '/VERSION';

        if (is_file($file)) {
            $contents = trim((string) file_get_contents($file));
            if ($contents !== '') {
                return $contents;
            }
        }

        return self::gitFallback($rootDir);
    }

    private static function gitFallback(string $rootDir): string
    {
        $hash = @shell_exec('git -C ' . escapeshellarg($rootDir) . ' rev-parse --short HEAD 2>/dev/null');
        $hash = $hash !== null ? trim($hash) : '';

        if ($hash === '') {
            return 'dev';
        }

        return date('Y-m-d') . ' (' . $hash . ')';
    }
}

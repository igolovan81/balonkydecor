<?php
namespace App\Services;

use Psr\Log\AbstractLogger;
use Stringable;

class AppLogger extends AbstractLogger
{
    private static ?AppLogger $instance = null;

    public function __construct(private string $logFile = __DIR__ . '/../../tmp/app.log')
    {
    }

    public static function instance(): AppLogger
    {
        return self::$instance ??= new self();
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $line = date('[Y-m-d H:i:s]') . ' ' . strtoupper((string) $level) . ' ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        file_put_contents($this->logFile, $line . "\n", FILE_APPEND);
    }
}

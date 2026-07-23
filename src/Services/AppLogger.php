<?php
namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\AbstractLogger;
use Stringable;

class AppLogger extends AbstractLogger
{
    private static ?AppLogger $instance = null;

    public function __construct(private string $logDir = __DIR__ . '/../../tmp')
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
        file_put_contents($this->currentLogFile(), $line . "\n", FILE_APPEND);

        // No cron on WEDOS shared hosting — prune opportunistically, same
        // 1-in-100-requests idiom PageViewMiddleware uses for page_views.
        if (random_int(1, 100) === 1) {
            $settings   = require __DIR__ . '/../../config/settings.php';
            $prodConfig = __DIR__ . '/../../config/settings.prod.php';
            if (file_exists($prodConfig)) {
                $settings = array_replace_recursive($settings, require $prodConfig);
            }
            $this->prune($settings['log_retention'] ?? '3m');
        }
    }

    private function currentLogFile(): string
    {
        return $this->logDir . '/app-' . date('Y-m-d') . '.log';
    }

    /**
     * Deletes whole app-YYYY-MM-DD.log files older than $retention (e.g. "5d", "3w", "3m").
     */
    public function prune(string $retention): void
    {
        $cutoff = (new DateTimeImmutable('today'))->modify('-' . self::retentionToDays($retention) . ' days');

        foreach (glob($this->logDir . '/app-*.log') ?: [] as $file) {
            if (!preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                continue;
            }
            $fileDate = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
            if ($fileDate !== false && $fileDate < $cutoff) {
                unlink($file);
            }
        }
    }

    public static function retentionToDays(string $retention): int
    {
        if (!preg_match('/^(\d+)([dwm])$/', trim($retention), $m)) {
            throw new InvalidArgumentException(
                "Invalid log_retention format: \"{$retention}\" — expected e.g. \"5d\", \"3w\", \"3m\""
            );
        }
        $amount = (int) $m[1];
        return match ($m[2]) {
            'd' => $amount,
            'w' => $amount * 7,
            'm' => $amount * 30,
        };
    }
}

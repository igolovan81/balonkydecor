<?php
namespace App\Services;

class SlowQueryLogger
{
    private const SEVERITIES = [
        [6.0, 'CRITICAL'],
        [3.0, 'MAJOR'],
        [1.0, 'MEDIUM'],
        [0.5, 'MINOR'],
    ];

    public function __construct(private AppLogger $logger)
    {
    }

    public function log(string $queryString, float $elapsedSeconds): void
    {
        $severity = self::severityFor($elapsedSeconds);
        if ($severity === null) {
            return;
        }

        $this->logger->warning(
            sprintf('Slow query [%s] %.3fs: %s', $severity, $elapsedSeconds, $queryString),
            ['severity' => $severity, 'seconds' => round($elapsedSeconds, 3)]
        );
    }

    public static function severityFor(float $elapsedSeconds): ?string
    {
        foreach (self::SEVERITIES as [$min, $label]) {
            if ($elapsedSeconds >= $min) {
                return $label;
            }
        }
        return null;
    }
}

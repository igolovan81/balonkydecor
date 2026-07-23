<?php
namespace Tests\Unit\Services;

use App\Services\AppLogger;
use App\Services\SlowQueryLogger;
use PHPUnit\Framework\TestCase;

class SlowQueryLoggerTest extends TestCase
{
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/slow-query-logger-test-' . uniqid();
        mkdir($dir);
        return $dir;
    }

    private function logFile(string $dir): string
    {
        return $dir . '/app-' . date('Y-m-d') . '.log';
    }

    public function test_query_under_half_second_is_not_logged(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 0.49);

        $this->assertFileDoesNotExist($this->logFile($dir));
    }

    public function test_query_at_half_second_is_logged_as_minor(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 0.5);

        $this->assertStringContainsString('[MINOR]', file_get_contents($this->logFile($dir)));
    }

    public function test_query_just_under_one_second_is_still_minor(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 0.99);

        $this->assertStringContainsString('[MINOR]', file_get_contents($this->logFile($dir)));
    }

    public function test_query_at_one_second_is_medium(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 1.0);

        $this->assertStringContainsString('[MEDIUM]', file_get_contents($this->logFile($dir)));
    }

    public function test_query_just_under_three_seconds_is_still_medium(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 2.99);

        $this->assertStringContainsString('[MEDIUM]', file_get_contents($this->logFile($dir)));
    }

    public function test_query_at_three_seconds_is_major(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 3.0);

        $this->assertStringContainsString('[MAJOR]', file_get_contents($this->logFile($dir)));
    }

    public function test_query_just_under_six_seconds_is_still_major(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 5.99);

        $this->assertStringContainsString('[MAJOR]', file_get_contents($this->logFile($dir)));
    }

    public function test_query_at_six_seconds_is_critical(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 6.0);

        $this->assertStringContainsString('[CRITICAL]', file_get_contents($this->logFile($dir)));
    }

    public function test_query_well_above_six_seconds_is_critical(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT 1', 12.3);

        $this->assertStringContainsString('[CRITICAL]', file_get_contents($this->logFile($dir)));
    }

    public function test_message_includes_the_sql_and_elapsed_seconds(): void
    {
        $dir = $this->tempDir();
        (new SlowQueryLogger(new AppLogger($dir)))->log('SELECT * FROM products', 1.234);

        $contents = file_get_contents($this->logFile($dir));
        $this->assertStringContainsString('SELECT * FROM products', $contents);
        $this->assertStringContainsString('1.234s', $contents);
    }
}

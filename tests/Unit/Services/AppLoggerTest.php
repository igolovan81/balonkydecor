<?php
namespace Tests\Unit\Services;

use App\Services\AppLogger;
use PHPUnit\Framework\TestCase;

class AppLoggerTest extends TestCase
{
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/app-logger-test-' . uniqid();
        mkdir($dir);
        return $dir;
    }

    public function test_log_writes_a_timestamped_line_to_todays_dated_file(): void
    {
        $dir    = $this->tempDir();
        $logger = new AppLogger($dir);

        $logger->error('Something went wrong');

        $file     = $dir . '/app-' . date('Y-m-d') . '.log';
        $contents = file_get_contents($file);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] ERROR Something went wrong\n$/',
            $contents
        );
    }

    public function test_log_appends_context_as_json(): void
    {
        $dir    = $this->tempDir();
        $logger = new AppLogger($dir);

        $logger->error('Payment failed', ['order' => 'ORD123']);

        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        $this->assertStringContainsString('Payment failed {"order":"ORD123"}', file_get_contents($file));
    }

    public function test_log_appends_multiple_entries_to_the_same_days_file(): void
    {
        $dir    = $this->tempDir();
        $logger = new AppLogger($dir);

        $logger->info('First');
        $logger->info('Second');

        $file  = $dir . '/app-' . date('Y-m-d') . '.log';
        $lines = explode("\n", trim(file_get_contents($file)));
        $this->assertCount(2, $lines);
    }

    public function test_instance_returns_the_same_object_every_call(): void
    {
        $this->assertSame(AppLogger::instance(), AppLogger::instance());
    }

    public function test_retention_to_days_parses_days_weeks_months(): void
    {
        $this->assertSame(5, AppLogger::retentionToDays('5d'));
        $this->assertSame(21, AppLogger::retentionToDays('3w'));
        $this->assertSame(90, AppLogger::retentionToDays('3m'));
        $this->assertSame(30, AppLogger::retentionToDays('1m'));
    }

    public function test_retention_to_days_rejects_invalid_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppLogger::retentionToDays('bogus');
    }

    public function test_prune_deletes_whole_files_older_than_retention(): void
    {
        $dir = $this->tempDir();
        $old = (new \DateTimeImmutable('-10 days'))->format('Y-m-d');
        touch($dir . "/app-{$old}.log");
        touch($dir . '/app-' . date('Y-m-d') . '.log');

        (new AppLogger($dir))->prune('5d');

        $this->assertFileDoesNotExist($dir . "/app-{$old}.log");
        $this->assertFileExists($dir . '/app-' . date('Y-m-d') . '.log');
    }

    public function test_prune_keeps_files_within_retention(): void
    {
        $dir      = $this->tempDir();
        $recent   = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $recentFile = $dir . "/app-{$recent}.log";
        touch($recentFile);

        (new AppLogger($dir))->prune('3m');

        $this->assertFileExists($recentFile);
    }

    public function test_prune_ignores_non_log_files(): void
    {
        $dir   = $this->tempDir();
        $other = $dir . '/notes.txt';
        touch($other);

        (new AppLogger($dir))->prune('1d');

        $this->assertFileExists($other);
    }

    public function test_prune_does_nothing_when_directory_has_no_log_files(): void
    {
        $dir = $this->tempDir();

        (new AppLogger($dir))->prune('3m');

        $this->assertDirectoryExists($dir);
    }
}

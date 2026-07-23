<?php
namespace Tests\Unit\Services;

use App\Services\AppLogger;
use PHPUnit\Framework\TestCase;

class AppLoggerTest extends TestCase
{
    public function test_log_writes_a_timestamped_line_to_the_log_file(): void
    {
        $file   = sys_get_temp_dir() . '/app-logger-test-' . uniqid() . '.log';
        $logger = new AppLogger($file);

        $logger->error('Something went wrong');

        $contents = file_get_contents($file);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] ERROR Something went wrong\n$/',
            $contents
        );

        unlink($file);
    }

    public function test_log_appends_context_as_json(): void
    {
        $file   = sys_get_temp_dir() . '/app-logger-test-' . uniqid() . '.log';
        $logger = new AppLogger($file);

        $logger->error('Payment failed', ['order' => 'ORD123']);

        $contents = file_get_contents($file);
        $this->assertStringContainsString('Payment failed {"order":"ORD123"}', $contents);

        unlink($file);
    }

    public function test_log_appends_multiple_entries(): void
    {
        $file   = sys_get_temp_dir() . '/app-logger-test-' . uniqid() . '.log';
        $logger = new AppLogger($file);

        $logger->info('First');
        $logger->info('Second');

        $lines = explode("\n", trim(file_get_contents($file)));
        $this->assertCount(2, $lines);

        unlink($file);
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

    public function test_prune_removes_lines_older_than_retention(): void
    {
        $file   = sys_get_temp_dir() . '/app-logger-prune-test-' . uniqid() . '.log';
        $old    = (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s');
        $recent = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        file_put_contents($file, "[{$old}] ERROR old entry\n[{$recent}] ERROR recent entry\n");

        $logger = new AppLogger($file);
        $logger->prune('5d');

        $contents = file_get_contents($file);
        $this->assertStringNotContainsString('old entry', $contents);
        $this->assertStringContainsString('recent entry', $contents);

        unlink($file);
    }

    public function test_prune_keeps_all_lines_when_none_are_old_enough(): void
    {
        $file   = sys_get_temp_dir() . '/app-logger-prune-test-' . uniqid() . '.log';
        $recent = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        file_put_contents($file, "[{$recent}] INFO still fresh\n");

        $logger = new AppLogger($file);
        $logger->prune('3m');

        $this->assertStringContainsString('still fresh', file_get_contents($file));

        unlink($file);
    }

    public function test_prune_does_nothing_when_file_does_not_exist(): void
    {
        $file   = sys_get_temp_dir() . '/app-logger-prune-test-' . uniqid() . '.log';
        $logger = new AppLogger($file);

        $logger->prune('3m');

        $this->assertFileDoesNotExist($file);
    }
}

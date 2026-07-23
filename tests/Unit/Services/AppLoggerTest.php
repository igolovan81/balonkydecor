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
}

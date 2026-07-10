<?php
namespace Tests\Unit\Services;

use App\Services\Version;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function test_current_returns_version_file_contents_when_present(): void
    {
        $dir = sys_get_temp_dir() . '/version-test-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/VERSION', "2026-07-10 (abc1234)\n");

        $this->assertSame('2026-07-10 (abc1234)', Version::current($dir));
    }

    public function test_current_returns_dev_when_no_file_and_no_git(): void
    {
        $dir = sys_get_temp_dir() . '/version-test-' . uniqid();
        mkdir($dir);

        $this->assertSame('dev', Version::current($dir));
    }

    public function test_current_falls_back_to_git_hash_when_file_missing(): void
    {
        $dir = sys_get_temp_dir() . '/version-test-' . uniqid();
        mkdir($dir);
        exec('git init -q ' . escapeshellarg($dir));
        exec('git -C ' . escapeshellarg($dir) . ' -c user.email=test@example.com -c user.name=Test commit --allow-empty -q -m init');

        $result = Version::current($dir);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \([0-9a-f]{7,}\)$/', $result);
    }
}

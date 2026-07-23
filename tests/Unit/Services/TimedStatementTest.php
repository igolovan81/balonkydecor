<?php
namespace Tests\Unit\Services;

use App\Services\AppLogger;
use App\Services\SlowQueryLogger;
use App\Services\TimedStatement;
use PDO;
use PHPUnit\Framework\TestCase;

class TimedStatementTest extends TestCase
{
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/timed-statement-test-' . uniqid();
        mkdir($dir);
        return $dir;
    }

    private function connection(string $logDir): PDO
    {
        $db  = (require __DIR__ . '/../../../config/settings.php')['db'];
        $pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}",
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->setAttribute(
            PDO::ATTR_STATEMENT_CLASS,
            [TimedStatement::class, [new SlowQueryLogger(new AppLogger($logDir))]]
        );
        return $pdo;
    }

    private function logFile(string $dir): string
    {
        return $dir . '/app-' . date('Y-m-d') . '.log';
    }

    public function test_query_slower_than_threshold_is_logged_with_its_severity(): void
    {
        $dir = $this->tempDir();

        $this->connection($dir)->prepare('SELECT SLEEP(0.6)')->execute();

        $this->assertStringContainsString('[MINOR]', file_get_contents($this->logFile($dir)));
    }

    public function test_fast_query_is_not_logged(): void
    {
        $dir = $this->tempDir();

        $this->connection($dir)->prepare('SELECT 1')->execute();

        $this->assertFileDoesNotExist($this->logFile($dir));
    }

    public function test_logged_message_contains_the_query_string(): void
    {
        $dir = $this->tempDir();

        $this->connection($dir)->prepare('SELECT SLEEP(0.6)')->execute();

        $this->assertStringContainsString('SELECT SLEEP(0.6)', file_get_contents($this->logFile($dir)));
    }
}

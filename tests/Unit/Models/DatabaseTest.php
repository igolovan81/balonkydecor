<?php
namespace Tests\Unit\Models;

use App\Models\Database;
use App\Services\TimedStatement;
use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function test_connection_returns_pdo(): void
    {
        $pdo = Database::getConnection();
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function test_connection_is_singleton(): void
    {
        $this->assertSame(Database::getConnection(), Database::getConnection());
    }

    public function test_connection_uses_timed_statement_for_slow_query_logging(): void
    {
        $statementClass = Database::getConnection()->getAttribute(PDO::ATTR_STATEMENT_CLASS)[0];
        $this->assertSame(TimedStatement::class, $statementClass);
    }
}

<?php
namespace Tests\Unit\Models;

use App\Models\Database;
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
}

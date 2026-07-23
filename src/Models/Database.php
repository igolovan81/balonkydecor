<?php
namespace App\Models;

use App\Services\AppLogger;
use App\Services\SlowQueryLogger;
use App\Services\TimedStatement;
use PDO;

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $settings   = require __DIR__ . '/../../config/settings.php';
            $prodConfig = __DIR__ . '/../../config/settings.prod.php';
            if (file_exists($prodConfig)) {
                $settings = array_replace_recursive($settings, require $prodConfig);
            }
            $db  = $settings['db'];
            $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
            self::$connection = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$connection->setAttribute(
                PDO::ATTR_STATEMENT_CLASS,
                [TimedStatement::class, [new SlowQueryLogger(AppLogger::instance())]]
            );
        }
        return self::$connection;
    }
}

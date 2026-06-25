<?php
namespace App\Services;

use PDO;
use RuntimeException;

class Migrator
{
    private PDO    $pdo;
    private string $migrationsDir;

    public function __construct(PDO $pdo, string $migrationsDir)
    {
        $this->pdo           = $pdo;
        $this->migrationsDir = $migrationsDir;
    }

    public function run(): array
    {
        $this->ensureTable();
        $applied = $this->appliedVersions();
        $results = [];

        foreach ($this->pendingFiles($applied) as $version => $path) {
            $this->executeFile($path);
            $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $results[] = $version;
        }

        return $results;
    }

    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->appliedVersions();
        $status  = [];

        foreach ($this->allFiles() as $version => $path) {
            $status[] = [
                'version' => $version,
                'applied' => in_array($version, $applied, true),
            ];
        }

        return $status;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version    VARCHAR(255) NOT NULL PRIMARY KEY,
            applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function appliedVersions(): array
    {
        return $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function allFiles(): array
    {
        $files = glob($this->migrationsDir . '/V*.sql') ?: [];
        sort($files);
        $result = [];
        foreach ($files as $file) {
            $result[basename($file, '.sql')] = $file;
        }
        return $result;
    }

    private function pendingFiles(array $applied): array
    {
        return array_filter(
            $this->allFiles(),
            fn($version) => !in_array($version, $applied, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function executeFile(string $path): void
    {
        $sql        = file_get_contents($path);
        $statements = $this->splitStatements($sql);

        foreach ($statements as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    private function splitStatements(string $sql): array
    {
        // Split on semicolons that end a statement (not inside string literals).
        // Handles single-quoted strings; sufficient for controlled migration files.
        $statements = [];
        $current    = '';
        $inString   = false;
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];

            if ($c === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inString = !$inString;
            }

            if ($c === ';' && !$inString) {
                $stmt = trim($current);
                if ($stmt !== '' && !preg_match('/^--/', $stmt)) {
                    $statements[] = $stmt;
                }
                $current = '';
            } else {
                $current .= $c;
            }
        }

        $stmt = trim($current);
        if ($stmt !== '' && !preg_match('/^--/', $stmt)) {
            $statements[] = $stmt;
        }

        return $statements;
    }
}

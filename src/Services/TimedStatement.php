<?php
namespace App\Services;

use PDOStatement;

class TimedStatement extends PDOStatement
{
    protected function __construct(private SlowQueryLogger $slowQueryLogger)
    {
    }

    public function execute(?array $params = null): bool
    {
        $start  = microtime(true);
        $result = parent::execute($params);

        $this->slowQueryLogger->log($this->queryString, microtime(true) - $start);

        return $result;
    }
}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Logging\SQLLogger;
use LogicException;
use function gettype;
use function is_string;

class CachingSqlLogger implements SQLLogger
{

    /**
     * @var string[]
     */
    private array $queries = [];

    /**
     * @param mixed $sql
     * @param mixed[]|null $params
     * @param mixed[]|null $types
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null): void
    {
        if (!is_string($sql)) {
            throw new LogicException('Unexpected sql given: ' . gettype($sql));
        }

        $this->queries[] = $sql;
    }

    public function stopQuery(): void
    {
    }

    /**
     * @return string[]
     */
    public function getQueriesPerformed(): array
    {
        return $this->queries;
    }

    public function clean(): void
    {
        $this->queries = [];
    }

}

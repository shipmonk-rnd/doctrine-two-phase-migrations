<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;

class MigrationDefaultExecutor implements MigrationExecutor
{

    private Connection $connection;

    public function __construct(
        Connection $connection,
    )
    {
        $this->connection = $connection;
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<non-negative-int|string, string|Type|ParameterType|ArrayParameterType> $types
     */
    public function executeQuery(string $statement, array $params = [], array $types = []): Result
    {
        return $this->connection->executeQuery($statement, $params, $types);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

}

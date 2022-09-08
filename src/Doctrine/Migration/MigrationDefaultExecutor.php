<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ForwardCompatibility\DriverResultStatement;
use Doctrine\DBAL\ForwardCompatibility\DriverStatement;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;

class MigrationDefaultExecutor implements MigrationExecutor
{

    private Connection $connection;

    public function __construct(
        Connection $connection
    )
    {
        $this->connection = $connection;
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, int|string|Type|null> $types
     * @return DriverStatement<mixed>|DriverResultStatement<mixed>
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

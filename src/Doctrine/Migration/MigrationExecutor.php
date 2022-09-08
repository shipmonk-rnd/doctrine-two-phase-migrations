<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ForwardCompatibility\DriverResultStatement;
use Doctrine\DBAL\ForwardCompatibility\DriverStatement;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;

interface MigrationExecutor
{

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, int|string|Type|null> $types
     * @return DriverStatement<mixed>|DriverResultStatement<mixed>
     */
    public function executeQuery(string $statement, array $params = [], array $types = []): Result;

    public function getConnection(): Connection;

}

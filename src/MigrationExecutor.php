<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;

interface MigrationExecutor
{

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int|string, int|string|Type|null> $types
     */
    public function executeQuery(string $statement, array $params = [], array $types = []): Result;

    public function getConnection(): Connection;

}

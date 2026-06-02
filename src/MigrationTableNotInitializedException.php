<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use RuntimeException;

/**
 * Thrown when the migration table is missing or its schema is outdated (e.g. after upgrading the library
 * without re-running migration:init). The remedy is always to run the migration:init command.
 *
 * @api
 */
class MigrationTableNotInitializedException extends RuntimeException
{

    public function __construct(
        public readonly string $tableName,
        string $message,
    )
    {
        parent::__construct($message);
    }

}

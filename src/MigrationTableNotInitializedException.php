<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use RuntimeException;

/**
 * Thrown when the migration table is missing. The remedy is to run the migration:init command.
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

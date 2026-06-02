<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use RuntimeException;

/**
 * Thrown when the migration lock cannot be acquired within the configured timeout,
 * typically because another migration run is already in progress.
 *
 * @api
 */
class MigrationLockException extends RuntimeException
{

    public function __construct(
        public readonly int $timeoutSeconds,
        string $message,
    )
    {
        parent::__construct($message);
    }

}

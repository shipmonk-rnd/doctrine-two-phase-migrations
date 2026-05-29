<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use RuntimeException;

/**
 * Thrown when there are migrations that were started but never finished (e.g. the process was killed mid-execution).
 * Such migrations may be partially applied, therefore all subsequent runs must fail until the state is resolved manually.
 *
 * @api
 */
class IncompleteMigrationException extends RuntimeException
{

    /**
     * @param list<array{version: string, phase: string, startedAt: string}> $incompleteMigrations
     */
    public function __construct(
        public readonly array $incompleteMigrations,
        string $message,
    )
    {
        parent::__construct($message);
    }

}

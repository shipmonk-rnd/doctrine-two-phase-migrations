<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Event;

use ShipMonk\Doctrine\Migration\Migration;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use Throwable;

class MigrationExecutionFailedEvent
{

    public function __construct(
        public readonly Migration $migration,
        public readonly string $version,
        public readonly MigrationPhase $phase,
        public readonly Throwable $exception,
    )
    {
    }

}

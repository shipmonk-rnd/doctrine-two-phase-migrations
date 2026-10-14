<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

readonly class Statement
{

    /**
     * A null phase marks an undecided statement. The generator emits it into the `%statements%` placeholder.
     */
    public function __construct(
        public string $sql,
        public ?MigrationPhase $phase,
    )
    {
    }

}

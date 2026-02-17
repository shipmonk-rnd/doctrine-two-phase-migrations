<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

readonly class Statement
{

    public function __construct(
        public string $sql,
        public MigrationPhase $phase,
    )
    {
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

class Statement
{

    public readonly string $sql;

    public readonly ?MigrationPhase $phase;

    public function __construct(string $sql, ?MigrationPhase $phase = null)
    {
        $this->sql = $sql;
        $this->phase = $phase;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

class Statement
{

    public readonly string $sql;

    /**
     * @var MigrationPhase::*|null
     */
    public readonly ?string $phase;

    /**
     * @param MigrationPhase::*|null $phase
     */
    public function __construct(string $sql, ?string $phase = null)
    {
        $this->sql = $sql;
        $this->phase = $phase;
    }

}

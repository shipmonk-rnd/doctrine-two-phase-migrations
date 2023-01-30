<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

interface MigrationVersionProvider
{

    public function getNextVersion(): string;

}

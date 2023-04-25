<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

interface MigrationPhase
{

    public const BEFORE = 'before';
    public const AFTER = 'after';

}

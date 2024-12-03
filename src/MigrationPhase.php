<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

enum MigrationPhase: string
{

    case BEFORE = 'before';
    case AFTER = 'after';

}

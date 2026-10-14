<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

/**
 * Outcome of {@see MigrationService::initializeMigrationTable()} and {@see MigrationService::ensureMigrationTableUpToDate()}.
 *
 * @api
 */
enum MigrationTableState
{

    case Created;
    case Upgraded;
    case AlreadyUpToDate;

}

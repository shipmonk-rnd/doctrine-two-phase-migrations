<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

/**
 * Outcome of {@see MigrationService::initializeMigrationTable()}.
 *
 * @api
 */
enum MigrationTableState
{

    case Created;
    case Upgraded;
    case AlreadyUpToDate;

}

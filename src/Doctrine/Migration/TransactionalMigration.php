<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

/**
 * Marking interface causing each phase to be executed in transaction
 * - beware that many databases like MySQL does not support DDL operations within transaction
 */
interface TransactionalMigration extends Migration
{

}

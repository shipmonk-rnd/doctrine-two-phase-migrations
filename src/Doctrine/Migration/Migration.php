<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

interface Migration
{

    public function before(MigrationExecutor $executor): void;

    public function after(MigrationExecutor $executor): void;

    public function isTransactional(): bool;

}

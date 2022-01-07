<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\Connection;

interface Migration
{

    public function before(Connection $connection): void;

    public function after(Connection $connection): void;

}

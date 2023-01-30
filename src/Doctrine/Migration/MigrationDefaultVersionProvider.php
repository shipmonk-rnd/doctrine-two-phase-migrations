<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use function date;

class MigrationDefaultVersionProvider implements MigrationVersionProvider
{

    public function getNextVersion(): string
    {
        return date('YmdHis');
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class MigrationRunTest extends TestCase
{

    public function testGetDuration(): void
    {
        $migrationRun = new MigrationRun(
            'version',
            MigrationPhase::AFTER,
            new DateTimeImmutable('2021-01-01 00:00:00.000000'),
            new DateTimeImmutable('2021-01-01 00:00:01.000001'),
        );

        self::assertSame(1.000_001, $migrationRun->getDuration());
    }

}

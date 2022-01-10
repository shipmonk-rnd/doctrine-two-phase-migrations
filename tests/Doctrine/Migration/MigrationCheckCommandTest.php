<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationCheckCommandTest extends TestCase
{

    use WithEntityManagerTest;

    public function testCheck(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturnOnConsecutiveCalls(['fakeversion'], []);

        $migrationService->expects(self::exactly(2))
            ->method('getPreparedVersions')
            ->willReturn(['fakeversion']);

        $output = new BufferedOutput();
        $command = new MigrationCheckCommand($this->getEntityManager(), $migrationService);
        $command->run(new ArrayInput([]), $output);

        self::assertSame(
        <<<OUTPUT
        Phase before fully executed, no awaiting migrations
        Phase after not fully executed, awaiting migrations:
         > fakeversion
        Database is not synced with entities, missing updates:
         > CREATE TABLE entity (id VARCHAR(255) NOT NULL, PRIMARY KEY(id))\n
        OUTPUT, $output->fetch());
    }



}

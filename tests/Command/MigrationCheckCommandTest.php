<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\WithEntityManagerTest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationCheckCommandTest extends TestCase
{

    use WithEntityManagerTest;

    public function testCheck(): void
    {
        $diffSql = 'SELECT 1';

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturnOnConsecutiveCalls(['fakeversion'], []);

        $migrationService->expects(self::exactly(2))
            ->method('getPreparedVersions')
            ->willReturn(['fakeversion']);

        $migrationService->expects(self::once())
            ->method('generateDiffSqls')
            ->willReturn([$diffSql]);

        $output = new BufferedOutput();
        $command = new MigrationCheckCommand($migrationService);
        $command->run(new ArrayInput([]), $output);

        self::assertSame(
            <<<"OUTPUT"
            Phase before fully executed, no awaiting migrations
            Phase after not fully executed, awaiting migrations:
             > fakeversion
            Database is not synced with entities, missing updates:
             > $diffSql
            OUTPUT . "\n",
            $output->fetch(),
        );
    }

}

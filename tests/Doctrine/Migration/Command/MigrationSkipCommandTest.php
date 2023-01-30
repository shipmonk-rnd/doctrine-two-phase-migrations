<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationSkipCommandTest extends TestCase
{

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
        $command = new MigrationSkipCommand($migrationService);
        $command->run(new ArrayInput([]), $output);

        self::assertSame("Migration fakeversion phase after skipped.\n", $output->fetch());
    }

}

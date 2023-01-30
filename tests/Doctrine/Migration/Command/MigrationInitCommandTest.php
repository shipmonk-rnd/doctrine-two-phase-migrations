<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationInitCommandTest extends TestCase
{

    public function testInit(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::once())
            ->method('initializeMigrationTable')
            ->willReturn(true);

        $output = new BufferedOutput();
        $command = new MigrationInitCommand($migrationService);
        $command->run(new ArrayInput([]), $output);

        self::assertSame("Creating migration table... done.\n", $output->fetch());
    }

}

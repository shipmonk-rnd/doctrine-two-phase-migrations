<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

class MigrationRunCommandTest extends TestCase
{

    public function testNoMigration(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturnOnConsecutiveCalls(['fakeversion'], []);

        $migrationService->expects(self::exactly(2))
            ->method('getPreparedVersions')
            ->willReturn(['fakeversion']);

        $migrationService->expects(self::once())
            ->method('executeMigration')
            ->with('fakeversion', MigrationPhase::AFTER);

        $command = new MigrationRunCommand($migrationService);

        self::assertSame("No migration executed.\n", $this->runPhase($command, MigrationPhase::AFTER));
        self::assertSame("Executing migration fakeversion phase after... done, 0.00 s elapsed.\n", $this->runPhase($command, MigrationPhase::AFTER));
    }

    private function runPhase(MigrationRunCommand $command, string $phase): string
    {
        $input = new ArrayInput([MigrationRunCommand::ARGUMENT_PHASE => $phase], $command->getDefinition());
        $output = new BufferedOutput();
        $command->run($input, $output);

        return $output->fetch();
    }

}

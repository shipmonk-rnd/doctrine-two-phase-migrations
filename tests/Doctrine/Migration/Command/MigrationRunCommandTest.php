<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationRun;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationRunCommandTest extends TestCase
{

    public function testBasicRun(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturnOnConsecutiveCalls(['fakeversion' => 'fakeversion'], []);

        $migrationService->expects(self::exactly(2))
            ->method('getPreparedVersions')
            ->willReturn(['fakeversion' => 'fakeversion']);

        $migrationService->expects(self::once())
            ->method('executeMigration')
            ->with('fakeversion', MigrationPhase::AFTER)
            ->willReturn(new MigrationRun('fakeversion', MigrationPhase::AFTER, new DateTimeImmutable('today 00:00:00'), new DateTimeImmutable('today 00:00:01')));

        $command = new MigrationRunCommand($migrationService);

        self::assertSame("No migration executed (phase before).\n", $this->runPhase($command, MigrationPhase::BEFORE));
        self::assertSame("Executing migration fakeversion phase after... done, 1.000 s elapsed.\n", $this->runPhase($command, MigrationPhase::AFTER));
    }

    public function testRunBoth(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturn([]);

        $migrationService->expects(self::once())
            ->method('getPreparedVersions')
            ->willReturn(['version1' => 'version1', 'version2' => 'version2']);

        $migrationService->expects(self::exactly(4))
            ->method('executeMigration')
            ->withConsecutive(
                ['version1', MigrationPhase::BEFORE],
                ['version1', MigrationPhase::AFTER],
                ['version2', MigrationPhase::BEFORE],
                ['version2', MigrationPhase::AFTER],
            );

        $command = new MigrationRunCommand($migrationService);

        $output = <<<'OUTPUT'
            Executing migration version1 phase before... done, 0.000 s elapsed.
            Executing migration version1 phase after... done, 0.000 s elapsed.
            Executing migration version2 phase before... done, 0.000 s elapsed.
            Executing migration version2 phase after... done, 0.000 s elapsed.
            OUTPUT . "\n";

        self::assertSame($output, $this->runPhase($command, MigrationRunCommand::PHASE_BOTH));
    }

    private function runPhase(MigrationRunCommand $command, string $phase): string
    {
        $input = new ArrayInput([MigrationRunCommand::ARGUMENT_PHASE => $phase], $command->getDefinition());
        $output = new BufferedOutput();
        $command->run($input, $output);

        return $output->fetch();
    }

}

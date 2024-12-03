<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationRun;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use const PHP_EOL;

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

        self::assertSame('No migration executed (phase before).' . PHP_EOL, $this->runPhase($command, MigrationPhase::BEFORE->value));
        self::assertSame('Executing migration fakeversion phase after... done, 1.000 s elapsed.' . PHP_EOL, $this->runPhase($command, MigrationPhase::AFTER->value));
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

        $executeCallsMatcher = self::exactly(4);
        $migrationService->expects($executeCallsMatcher)
            ->method('executeMigration')
            ->willReturnCallback(function (string $version, MigrationPhase $phase) use ($executeCallsMatcher): MigrationRun {
                match ($executeCallsMatcher->numberOfInvocations()) {
                    1 => self::assertEquals(['version1', MigrationPhase::BEFORE], [$version, $phase]),
                    2 => self::assertEquals(['version1', MigrationPhase::AFTER], [$version, $phase]),
                    3 => self::assertEquals(['version2', MigrationPhase::BEFORE], [$version, $phase]),
                    4 => self::assertEquals(['version2', MigrationPhase::AFTER], [$version, $phase]),
                    default => self::fail('Unexpected call'),
                };

                return $this->createMock(MigrationRun::class);
            });

        $command = new MigrationRunCommand($migrationService);

        $output = 'Executing migration version1 phase before... done, 0.000 s elapsed.' . PHP_EOL
            . 'Executing migration version1 phase after... done, 0.000 s elapsed.' . PHP_EOL
            . 'Executing migration version2 phase before... done, 0.000 s elapsed.' . PHP_EOL
            . 'Executing migration version2 phase after... done, 0.000 s elapsed.' . PHP_EOL;

        self::assertSame($output, $this->runPhase($command, MigrationRunCommand::PHASE_BOTH));
    }

    public function testFailureNoArgs(): void
    {
        self::expectExceptionMessage('Not enough arguments (missing: "phase").');

        $tester = new CommandTester(new MigrationRunCommand($this->createMock(MigrationService::class)));
        $tester->execute([]);
    }

    private function runPhase(MigrationRunCommand $command, string $phase): string
    {
        $input = new ArrayInput([MigrationRunCommand::ARGUMENT_PHASE => $phase], $command->getDefinition());
        $output = new BufferedOutput();
        $command->run($input, $output);

        return $output->fetch();
    }

}

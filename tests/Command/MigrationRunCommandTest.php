<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ShipMonk\Doctrine\Migration\IncompleteMigrationException;
use ShipMonk\Doctrine\Migration\MigrationLockException;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationRun;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\MigrationTableNotInitializedException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

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

        $logger = new TestLogger();
        $command = new MigrationRunCommand($migrationService, $logger);

        $exitCode = $this->runPhase($command, MigrationPhase::BEFORE->value);
        self::assertSame(0, $exitCode);
        self::assertTrue($logger->hasMessage('No migrations to execute (phase {migrationPhaseArgument})'));

        $exitCode = $this->runPhase($command, MigrationPhase::AFTER->value);
        self::assertSame(0, $exitCode);
        self::assertTrue($logger->hasMessage('Migration {migrationVersion} phase {migrationPhase} executed successfully, {migrationDurationSeconds} s elapsed'));
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

        $logger = new TestLogger();
        $command = new MigrationRunCommand($migrationService, $logger);
        $exitCode = $this->runPhase($command, MigrationRunCommand::PHASE_BOTH);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Starting migration execution (phase {migrationPhaseArgument})'));
        self::assertTrue($logger->hasMessage('{migrationPendingCount} pending migrations found'));
        self::assertTrue($logger->hasMessage('Executing migration {migrationVersion} phase {migrationPhase}'));
        self::assertTrue($logger->hasMessage('Migration {migrationVersion} phase {migrationPhase} executed successfully, {migrationDurationSeconds} s elapsed'));
        self::assertTrue($logger->hasMessage('Migration execution completed (phase {migrationPhaseArgument})'));
    }

    public function testLockNotAcquiredReturnsExitCode(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->method('acquireLock')
            ->willThrowException(new MigrationLockException(300, 'cannot acquire lock'));
        $migrationService->expects(self::never())->method('executeMigration');
        $migrationService->expects(self::never())->method('releaseLock'); // nothing acquired, nothing to release

        $logger = new TestLogger();
        $command = new MigrationRunCommand($migrationService, $logger);
        $exitCode = $this->runPhase($command, MigrationPhase::BEFORE->value);

        self::assertSame(MigrationRunCommand::EXIT_LOCK_NOT_ACQUIRED, $exitCode);
        self::assertTrue($logger->hasMessage('Migration execution aborted, could not acquire migration lock within {migrationLockTimeoutSeconds} s (another migration run is probably in progress)'));
    }

    public function testTableNotInitializedReturnsExitCode(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->method('assertMigrationTableUpToDate')
            ->willThrowException(new MigrationTableNotInitializedException('migration', 'run migration:init'));
        $migrationService->expects(self::once())->method('releaseLock');
        $migrationService->expects(self::never())->method('executeMigration');

        $logger = new TestLogger();
        $command = new MigrationRunCommand($migrationService, $logger);
        $exitCode = $this->runPhase($command, MigrationPhase::BEFORE->value);

        self::assertSame(MigrationRunCommand::EXIT_TABLE_NOT_INITIALIZED, $exitCode);
        self::assertTrue($logger->hasMessage('Migration execution aborted, migration table {migrationTableName} is not initialized, run the migration:init command first'));
    }

    public function testIncompleteMigrationReturnsExitCode(): void
    {
        $incomplete = [['version' => 'v1', 'phase' => 'before', 'startedAt' => '2023-01-01 00:00:00.000000']];

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->method('assertNoIncompleteMigrations')
            ->willThrowException(new IncompleteMigrationException($incomplete, 'incomplete migration detected'));
        $migrationService->expects(self::once())->method('releaseLock'); // lock acquired, must be released
        $migrationService->expects(self::never())->method('executeMigration');

        $logger = new TestLogger();
        $command = new MigrationRunCommand($migrationService, $logger);
        $exitCode = $this->runPhase($command, MigrationPhase::BEFORE->value);

        self::assertSame(MigrationRunCommand::EXIT_INCOMPLETE_MIGRATION, $exitCode);
        self::assertTrue($logger->hasMessage('Migration execution aborted, found {migrationIncompleteCount} unfinished migration(s) from a previously interrupted run, manual resolution is required'));
    }

    public function testReleaseLockFailureDoesNotMaskResult(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->method('getExecutedVersions')->willReturn([]);
        $migrationService->method('getPreparedVersions')->willReturn([]);
        $migrationService->method('releaseLock')
            ->willThrowException(new RuntimeException('connection lost'));

        $logger = new TestLogger();
        $command = new MigrationRunCommand($migrationService, $logger);
        $exitCode = $this->runPhase($command, MigrationPhase::BEFORE->value);

        // the run succeeded, so a failing lock release must not turn it into a failure
        self::assertSame(MigrationRunCommand::EXIT_OK, $exitCode);
        self::assertTrue($logger->hasMessage('Failed to release the migration lock, it will be released when the database connection is closed ({migrationError})'));
    }

    public function testFailureNoArgs(): void
    {
        self::expectExceptionMessage('Not enough arguments (missing: "phase").');

        $tester = new CommandTester(new MigrationRunCommand($this->createMock(MigrationService::class)));
        $tester->execute([]);
    }

    private function runPhase(
        MigrationRunCommand $command,
        string $phase,
    ): int
    {
        $input = new ArrayInput([MigrationRunCommand::ARGUMENT_PHASE => $phase], $command->getDefinition());
        $output = new BufferedOutput();
        return $command->run($input, $output);
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationCheckCommandTest extends TestCase
{

    public function testCheck(): void
    {
        $diffSql = 'SELECT 1';

        $config = $this->createMock(MigrationConfig::class);
        $config->method('getMigrationsDirectory')->willReturn('/tmp/migrations');

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

        $migrationService->method('getConfig')->willReturn($config);

        $logger = new TestLogger();

        $output = new BufferedOutput();
        $command = new MigrationCheckCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(MigrationCheckCommand::EXIT_ENTITIES_NOT_SYNCED | MigrationCheckCommand::EXIT_AWAITING_MIGRATION, $exitCode);

        self::assertTrue($logger->hasMessage('Starting migration check'));
        self::assertTrue($logger->hasMessage('Phase {migrationPhase} fully executed, no awaiting migrations'));
        self::assertTrue($logger->hasMessage('Phase {migrationPhase} not fully executed, awaiting migrations: {migrationAwaitingList}'));
        self::assertTrue($logger->hasMessage('Database is not synced with entities, {migrationMissingUpdatesCount} missing updates'));
        self::assertTrue($logger->hasMessage('Migration check completed'));
    }

    public function testCheckReportsIncompleteMigrations(): void
    {
        $config = $this->createMock(MigrationConfig::class);
        $config->method('getMigrationsDirectory')->willReturn('/tmp/migrations');

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->method('getIncompleteMigrations')->willReturn([
            ['version' => 'v1', 'phase' => 'before', 'startedAt' => '2023-01-01 00:00:00.000000'],
        ]);
        $migrationService->method('getExecutedVersions')->willReturn(['fakeversion']);
        $migrationService->method('getPreparedVersions')->willReturn(['fakeversion']);
        $migrationService->method('generateDiffSqls')->willReturn([]);
        $migrationService->method('getConfig')->willReturn($config);

        $logger = new TestLogger();

        $output = new BufferedOutput();
        $command = new MigrationCheckCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(MigrationCheckCommand::EXIT_INCOMPLETE_MIGRATION, $exitCode);
        self::assertTrue($logger->hasMessage('Found {migrationIncompleteCount} unfinished migration(s) from a previously interrupted run, manual resolution is required: {migrationIncompleteList}'));
    }

}

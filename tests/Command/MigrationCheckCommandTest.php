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

        self::assertSame(5, $exitCode); // EXIT_ENTITIES_NOT_SYNCED | EXIT_AWAITING_MIGRATION

        self::assertTrue($logger->hasMessage('Starting migration check'));
        self::assertTrue($logger->hasMessage('Phase {phase} fully executed, no awaiting migrations'));
        self::assertTrue($logger->hasMessage('Phase {phase} not fully executed, awaiting migrations: {awaitingMigrationsList}'));
        self::assertTrue($logger->hasMessage('Database is not synced with entities, {missingUpdatesCount} missing updates'));
        self::assertTrue($logger->hasMessage('Migration check completed'));
    }

}

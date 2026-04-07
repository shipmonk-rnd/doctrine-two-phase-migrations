<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\MigrationServiceRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationSkipCommandTest extends TestCase
{

    public function testSkip(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturnOnConsecutiveCalls(['fakeversion'], []);

        $migrationService->expects(self::exactly(2))
            ->method('getPreparedVersions')
            ->willReturn(['fakeversion']);

        $migrationService->expects(self::once())
            ->method('markMigrationExecuted');

        $logger = new TestLogger();

        $output = new BufferedOutput();
        $command = new MigrationSkipCommand(new MigrationServiceRegistry(['default' => $migrationService]), $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Starting migration skip'));
        self::assertTrue($logger->hasMessage('Found {count} migrations to skip in phase {phase}'));
        self::assertTrue($logger->hasMessage('Migration {version} phase {phase} skipped'));
        self::assertTrue($logger->hasMessage('Migration skip completed, {skippedCount} skipped'));

        $context = $logger->getContextFor('Migration {version} phase {phase} skipped');
        self::assertIsArray($context);
        self::assertArrayHasKey('version', $context);
        self::assertArrayHasKey('phase', $context);
        self::assertSame('fakeversion', $context['version']);
        self::assertSame('after', $context['phase']);
    }

    public function testNoMigrationsToSkip(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturn(['fakeversion']);

        $migrationService->expects(self::exactly(2))
            ->method('getPreparedVersions')
            ->willReturn(['fakeversion']);

        $migrationService->expects(self::never())
            ->method('markMigrationExecuted');

        $logger = new TestLogger();

        $output = new BufferedOutput();
        $command = new MigrationSkipCommand(new MigrationServiceRegistry(['default' => $migrationService]), $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Starting migration skip'));
        self::assertTrue($logger->hasMessage('No migrations to skip'));
    }

}

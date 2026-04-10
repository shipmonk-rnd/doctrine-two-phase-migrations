<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationService;
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
        $command = new MigrationSkipCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Starting migration skip'));
        self::assertTrue($logger->hasMessage('Found {migrationSkipCount} migrations to skip in phase {migrationPhase}'));
        self::assertTrue($logger->hasMessage('Migration {migrationVersion} phase {migrationPhase} skipped'));
        self::assertTrue($logger->hasMessage('Migration skip completed, {migrationSkippedCount} skipped'));

        $context = $logger->getContextFor('Migration {migrationVersion} phase {migrationPhase} skipped');
        self::assertIsArray($context);
        self::assertArrayHasKey('migrationVersion', $context);
        self::assertArrayHasKey('migrationPhase', $context);
        self::assertSame('fakeversion', $context['migrationVersion']);
        self::assertSame('after', $context['migrationPhase']);
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
        $command = new MigrationSkipCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Starting migration skip'));
        self::assertTrue($logger->hasMessage('No migrations to skip'));
    }

}

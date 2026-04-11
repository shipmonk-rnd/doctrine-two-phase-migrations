<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\MigrationServiceRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationInitCommandTest extends TestCase
{

    public function testInit(): void
    {
        $config = $this->createMock(MigrationConfig::class);
        $config->method('getMigrationTableName')->willReturn('migration');

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::once())
            ->method('initializeMigrationTable')
            ->willReturn(true);
        $migrationService->method('getConfig')->willReturn($config);

        $logger = new TestLogger();

        $output = new BufferedOutput();
        $command = new MigrationInitCommand(new MigrationServiceRegistry(['default' => $migrationService]), $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Initializing migration table {tableName}'));
        self::assertTrue($logger->hasMessage('Migration table {tableName} created successfully'));
    }

    public function testInitAlreadyExists(): void
    {
        $config = $this->createMock(MigrationConfig::class);
        $config->method('getMigrationTableName')->willReturn('migration');

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::once())
            ->method('initializeMigrationTable')
            ->willReturn(false);
        $migrationService->method('getConfig')->willReturn($config);

        $logger = new TestLogger();

        $output = new BufferedOutput();
        $command = new MigrationInitCommand(new MigrationServiceRegistry(['default' => $migrationService]), $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Initializing migration table {tableName}'));
        self::assertTrue($logger->hasMessage('Migration table {tableName} already exists'));
    }

    public function testInitWithNamespace(): void
    {
        $config = $this->createMock(MigrationConfig::class);
        $config->method('getMigrationTableName')->willReturn('analytics_migration');

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::once())
            ->method('initializeMigrationTable')
            ->willReturn(true);
        $migrationService->method('getConfig')->willReturn($config);

        $otherService = $this->createMock(MigrationService::class);
        $otherService->expects(self::never())->method('initializeMigrationTable');

        $registry = new MigrationServiceRegistry([
            'default' => $otherService,
            'analytics' => $migrationService,
        ]);

        $logger = new TestLogger();
        $command = new MigrationInitCommand($registry, $logger);
        $exitCode = $command->run(new ArrayInput(['--namespace' => 'analytics'], $command->getDefinition()), new BufferedOutput());

        self::assertSame(0, $exitCode);
        self::assertTrue($logger->hasMessage('Migration table {tableName} created successfully'));
    }

}

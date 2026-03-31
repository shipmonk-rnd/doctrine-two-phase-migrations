<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
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
        $command = new MigrationInitCommand($migrationService, $logger);
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
        $command = new MigrationInitCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Initializing migration table {tableName}'));
        self::assertTrue($logger->hasMessage('Migration table {tableName} already exists'));
    }

}

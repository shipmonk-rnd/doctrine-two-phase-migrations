<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationFile;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationGenerateCommandTest extends TestCase
{

    public function testGenerate(): void
    {
        $diffSql = 'SELECT 1';

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::once())
            ->method('generateDiffSqls')
            ->willReturn([$diffSql]);

        $migrationService->expects(self::once())
            ->method('generateMigrationFile')
            ->with([$diffSql])
            ->willReturn(new MigrationFile('fakepath', 'fakeversion', 'fakecontent'));

        $logger = new TestLogger();

        $output = new BufferedOutput();
        $command = new MigrationGenerateCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('Starting migration generation'));
        self::assertTrue($logger->hasMessage('{sqlCount} schema changes detected'));
        self::assertTrue($logger->hasMessage('Migration version {version} generated successfully'));

        $context = $logger->getContextFor('Migration version {version} generated successfully');
        self::assertIsArray($context);
        self::assertArrayHasKey('version', $context);
        self::assertArrayHasKey('filePath', $context);
        self::assertSame('fakeversion', $context['version']);
        self::assertSame('fakepath', $context['filePath']);
    }

    public function testGenerateEmpty(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::once())
            ->method('generateDiffSqls')
            ->willReturn([]);

        $migrationService->expects(self::once())
            ->method('generateMigrationFile')
            ->with([])
            ->willReturn(new MigrationFile('fakepath', 'fakeversion', ''));

        $logger = new TestLogger();

        $output = new BufferedOutput();
        $command = new MigrationGenerateCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        self::assertTrue($logger->hasMessage('No schema changes found, creating empty migration class'));
        self::assertTrue($logger->hasMessage('Migration version {version} generated successfully'));
    }

}

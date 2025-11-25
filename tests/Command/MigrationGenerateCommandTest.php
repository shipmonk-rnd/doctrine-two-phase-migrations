<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationFile;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use function array_column;

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
            ->willReturn(new MigrationFile('fakepath', 'fakeversion'));

        $logger = $this->createMock(LoggerInterface::class);

        /** @var list<array{level: string, message: string, context: array<string, mixed>}> $logCalls */
        $logCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });

        $output = new BufferedOutput();
        $command = new MigrationGenerateCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        // Verify logging calls
        $logMessages = array_column($logCalls, 'message');
        self::assertContains('Starting migration generation', $logMessages);
        self::assertContains('Schema changes detected', $logMessages);
        self::assertContains('Migration generated successfully', $logMessages);

        // Verify context includes version and file path
        foreach ($logCalls as $logCall) {
            if ($logCall['message'] === 'Migration generated successfully') {
                self::assertArrayHasKey('version', $logCall['context']);
                self::assertArrayHasKey('filePath', $logCall['context']);
                self::assertSame('fakeversion', $logCall['context']['version']);
                self::assertSame('fakepath', $logCall['context']['filePath']);
                break;
            }
        }
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
            ->willReturn(new MigrationFile('fakepath', 'fakeversion'));

        $logger = $this->createMock(LoggerInterface::class);

        $logCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });
        $logger->method('notice')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
        });

        $output = new BufferedOutput();
        $command = new MigrationGenerateCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        // Verify logging calls
        $logMessages = array_column($logCalls, 'message');
        self::assertContains('No schema changes found, creating empty migration class', $logMessages);
        self::assertContains('Migration generated successfully', $logMessages);
    }

}

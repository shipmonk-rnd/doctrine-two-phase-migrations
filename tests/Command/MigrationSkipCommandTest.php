<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use function array_column;

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

        $logger = $this->createMock(LoggerInterface::class);

        /** @var list<array{level: string, message: string, context: array<string, mixed>}> $logCalls */
        $logCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });

        $output = new BufferedOutput();
        $command = new MigrationSkipCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        // Verify logging calls
        $logMessages = array_column($logCalls, 'message');
        self::assertContains('Starting migration skip', $logMessages);
        self::assertContains('Found migrations to skip', $logMessages);
        self::assertContains('Migration skipped', $logMessages);
        self::assertContains('Migration skip completed', $logMessages);

        // Verify context of the skip
        foreach ($logCalls as $logCall) {
            if ($logCall['message'] === 'Migration skipped') {
                self::assertArrayHasKey('version', $logCall['context']);
                self::assertArrayHasKey('phase', $logCall['context']);
                self::assertSame('fakeversion', $logCall['context']['version']);
                self::assertSame('after', $logCall['context']['phase']);
                break;
            }
        }
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

        $logger = $this->createMock(LoggerInterface::class);

        $logCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });
        $logger->method('notice')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
        });

        $output = new BufferedOutput();
        $command = new MigrationSkipCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        // Verify logging calls
        $logMessages = array_column($logCalls, 'message');
        self::assertContains('Starting migration skip', $logMessages);
        self::assertContains('No migrations to skip', $logMessages);
    }

}

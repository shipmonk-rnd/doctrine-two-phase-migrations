<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use function array_column;

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

        $logger = $this->createMock(LoggerInterface::class);

        $logCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });
        $logger->method('warning')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        });
        $logger->method('error')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        });

        $output = new BufferedOutput();
        $command = new MigrationCheckCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(5, $exitCode); // EXIT_ENTITIES_NOT_SYNCED | EXIT_AWAITING_MIGRATION

        // Verify logging calls
        $logMessages = array_column($logCalls, 'message');
        self::assertContains('Starting migration check', $logMessages);
        self::assertContains('Phase fully executed, no awaiting migrations', $logMessages);
        self::assertContains('Phase not fully executed, migrations awaiting', $logMessages);
        self::assertContains('Database is not synced with entities', $logMessages);
        self::assertContains('Migration check completed', $logMessages);
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationConfig;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use function array_column;

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

        $logger = $this->createMock(LoggerInterface::class);

        $logCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });

        $output = new BufferedOutput();
        $command = new MigrationInitCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        // Verify logging calls
        $logMessages = array_column($logCalls, 'message');
        self::assertContains('Initializing migration table', $logMessages);
        self::assertContains('Migration table created successfully', $logMessages);
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

        $logger = $this->createMock(LoggerInterface::class);

        $logCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });
        $logger->method('notice')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
        });

        $output = new BufferedOutput();
        $command = new MigrationInitCommand($migrationService, $logger);
        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(0, $exitCode);

        // Verify logging calls
        $logMessages = array_column($logCalls, 'message');
        self::assertContains('Initializing migration table', $logMessages);
        self::assertContains('Migration table already exists', $logMessages);
    }

}

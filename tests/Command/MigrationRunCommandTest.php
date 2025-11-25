<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationRun;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use function array_column;

class MigrationRunCommandTest extends TestCase
{

    public function testBasicRun(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturnOnConsecutiveCalls(['fakeversion' => 'fakeversion'], []);

        $migrationService->expects(self::exactly(2))
            ->method('getPreparedVersions')
            ->willReturn(['fakeversion' => 'fakeversion']);

        $migrationService->expects(self::once())
            ->method('executeMigration')
            ->with('fakeversion', MigrationPhase::AFTER)
            ->willReturn(new MigrationRun('fakeversion', MigrationPhase::AFTER, new DateTimeImmutable('today 00:00:00'), new DateTimeImmutable('today 00:00:01')));

        $logger = $this->createMock(LoggerInterface::class);

        $command = new MigrationRunCommand($migrationService, $logger);

        // Test BEFORE phase - no migration executed
        $beforeLogCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$beforeLogCalls): void {
            $beforeLogCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });
        $logger->method('notice')->willReturnCallback(static function (string $message, array $context = []) use (&$beforeLogCalls): void {
            $beforeLogCalls[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
        });

        $exitCode = $this->runPhase($command, MigrationPhase::BEFORE->value);
        self::assertSame(0, $exitCode);

        // Test AFTER phase - migration executed
        $exitCode = $this->runPhase($command, MigrationPhase::AFTER->value);
        self::assertSame(0, $exitCode);
    }

    public function testRunBoth(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturn([]);

        $migrationService->expects(self::once())
            ->method('getPreparedVersions')
            ->willReturn(['version1' => 'version1', 'version2' => 'version2']);

        $executeCallsMatcher = self::exactly(4);
        $migrationService->expects($executeCallsMatcher)
            ->method('executeMigration')
            ->willReturnCallback(function (string $version, MigrationPhase $phase) use ($executeCallsMatcher): MigrationRun {
                match ($executeCallsMatcher->numberOfInvocations()) {
                    1 => self::assertEquals(['version1', MigrationPhase::BEFORE], [$version, $phase]),
                    2 => self::assertEquals(['version1', MigrationPhase::AFTER], [$version, $phase]),
                    3 => self::assertEquals(['version2', MigrationPhase::BEFORE], [$version, $phase]),
                    4 => self::assertEquals(['version2', MigrationPhase::AFTER], [$version, $phase]),
                    default => self::fail('Unexpected call'),
                };

                return $this->createMock(MigrationRun::class);
            });

        $logger = $this->createMock(LoggerInterface::class);

        $logCalls = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logCalls): void {
            $logCalls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });

        $command = new MigrationRunCommand($migrationService, $logger);
        $exitCode = $this->runPhase($command, MigrationRunCommand::PHASE_BOTH);

        self::assertSame(0, $exitCode);

        // Verify logging calls
        $logMessages = array_column($logCalls, 'message');
        self::assertContains('Starting migration execution', $logMessages);
        self::assertContains('Pending migrations found', $logMessages);
        self::assertContains('Executing migration', $logMessages);
        self::assertContains('Migration executed successfully', $logMessages);
        self::assertContains('Migration execution completed', $logMessages);
    }

    public function testFailureNoArgs(): void
    {
        self::expectExceptionMessage('Not enough arguments (missing: "phase").');

        $logger = $this->createMock(LoggerInterface::class);
        $tester = new CommandTester(new MigrationRunCommand($this->createMock(MigrationService::class), $logger));
        $tester->execute([]);
    }

    private function runPhase(
        MigrationRunCommand $command,
        string $phase,
    ): int
    {
        $input = new ArrayInput([MigrationRunCommand::ARGUMENT_PHASE => $phase], $command->getDefinition());
        $output = new BufferedOutput();
        return $command->run($input, $output);
    }

}

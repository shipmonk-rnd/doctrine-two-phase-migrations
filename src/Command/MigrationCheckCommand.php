<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function array_values;
use function count;
use function implode;

#[AsCommand(self::NAME, description: 'Check if entities are in sync with database and if migrations were executed')]
class MigrationCheckCommand extends Command
{

    public const NAME = 'migration:check';

    private const EXIT_ENTITIES_NOT_SYNCED = 4;
    private const EXIT_UNKNOWN_MIGRATION = 2;
    private const EXIT_AWAITING_MIGRATION = 1;
    private const EXIT_OK = 0;

    public function __construct(
        private readonly MigrationService $migrationService,
        private readonly ?LoggerInterface $logger = null,
    )
    {
        parent::__construct();
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $logger = $this->createLogger($output);

        $logger->info('Starting migration check');

        $exitCode = self::EXIT_OK;
        $exitCode |= $this->checkMigrationsExecuted($logger);
        $exitCode |= $this->checkEntitiesSyncedWithDatabase($logger);

        $logger->info('Migration check completed', [
            'exitCode' => $exitCode,
            'success' => $exitCode === self::EXIT_OK,
        ]);

        return $exitCode;
    }

    private function checkEntitiesSyncedWithDatabase(LoggerInterface $logger): int
    {
        $updates = $this->migrationService->generateDiffSqls();
        $updateCount = count($updates);

        if ($updateCount !== 0) {
            $logger->warning('Database is not synced with entities, {missingUpdatesCount} missing updates', [
                'missingUpdatesCount' => $updateCount,
                'missingUpdates' => $updates,
            ]);
            return self::EXIT_ENTITIES_NOT_SYNCED;
        }

        $logger->info('Database is synced with entities, no migration needed');
        return self::EXIT_OK;
    }

    private function checkMigrationsExecuted(LoggerInterface $logger): int
    {
        $exitCode = self::EXIT_OK;
        $migrationsDir = $this->migrationService->getConfig()->getMigrationsDirectory();

        foreach (MigrationPhase::cases() as $phase) {
            $executed = $this->migrationService->getExecutedVersions($phase);
            $prepared = $this->migrationService->getPreparedVersions();

            $toBeExecuted = array_values(array_diff($prepared, $executed));
            $executedNotPresent = array_values(array_diff($executed, $prepared));

            if (count($executedNotPresent) > 0) {
                $exitCode |= self::EXIT_UNKNOWN_MIGRATION;
                $logger->error('Phase {phase} has executed migrations not present in {migrationsDirectory}: {unknownMigrationsList}', [
                    'phase' => $phase->value,
                    'migrationsDirectory' => $migrationsDir,
                    'unknownMigrations' => $executedNotPresent,
                    'unknownMigrationsList' => implode(', ', $executedNotPresent),
                ]);
            }

            if (count($toBeExecuted) > 0) {
                $exitCode |= self::EXIT_AWAITING_MIGRATION;
                $logger->warning('Phase {phase} not fully executed, awaiting migrations: {awaitingMigrationsList}', [
                    'phase' => $phase->value,
                    'awaitingMigrations' => $toBeExecuted,
                    'awaitingMigrationsList' => implode(', ', $toBeExecuted),
                ]);
            }

            if (count($executedNotPresent) === 0 && count($toBeExecuted) === 0) {
                $logger->info('Phase {phase} fully executed, no awaiting migrations', [
                    'phase' => $phase->value,
                ]);
            }
        }

        return $exitCode;
    }

    private function createLogger(OutputInterface $output): LoggerInterface
    {
        return $this->logger ?? new ConsoleLogger($output, [
            LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL,
            LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL,
        ]);
    }

}

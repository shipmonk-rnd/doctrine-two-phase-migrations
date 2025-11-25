<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function array_values;
use function count;

#[AsCommand('migration:check', description: 'Check if entities are in sync with database and if migrations were executed')]
class MigrationCheckCommand extends Command
{

    private const EXIT_ENTITIES_NOT_SYNCED = 4;
    private const EXIT_UNKNOWN_MIGRATION = 2;
    private const EXIT_AWAITING_MIGRATION = 1;
    private const EXIT_OK = 0;

    public function __construct(
        private readonly MigrationService $migrationService,
        private readonly LoggerInterface $logger,
    )
    {
        parent::__construct();
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $this->logger->info('Starting migration check');

        $exitCode = self::EXIT_OK;
        $exitCode |= $this->checkMigrationsExecuted();
        $exitCode |= $this->checkEntitiesSyncedWithDatabase();

        $this->logger->info('Migration check completed', [
            'exitCode' => $exitCode,
            'success' => $exitCode === self::EXIT_OK,
        ]);

        return $exitCode;
    }

    private function checkEntitiesSyncedWithDatabase(): int
    {
        $updates = $this->migrationService->generateDiffSqls();
        $updateCount = count($updates);

        if ($updateCount !== 0) {
            $this->logger->warning('Database is not synced with entities', [
                'missingUpdatesCount' => $updateCount,
                'missingUpdates' => $updates,
            ]);
            return self::EXIT_ENTITIES_NOT_SYNCED;
        }

        $this->logger->info('Database is synced with entities, no migration needed');
        return self::EXIT_OK;
    }

    private function checkMigrationsExecuted(): int
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
                $this->logger->error('Executed migrations not present in migrations directory', [
                    'phase' => $phase->value,
                    'migrationsDirectory' => $migrationsDir,
                    'unknownMigrations' => $executedNotPresent,
                    'unknownMigrationsCount' => count($executedNotPresent),
                ]);
            }

            if (count($toBeExecuted) > 0) {
                $exitCode |= self::EXIT_AWAITING_MIGRATION;
                $this->logger->warning('Phase not fully executed, migrations awaiting', [
                    'phase' => $phase->value,
                    'awaitingMigrations' => $toBeExecuted,
                    'awaitingMigrationsCount' => count($toBeExecuted),
                ]);
            }

            if (count($executedNotPresent) === 0 && count($toBeExecuted) === 0) {
                $this->logger->info('Phase fully executed, no awaiting migrations', [
                    'phase' => $phase->value,
                ]);
            }
        }

        return $exitCode;
    }

}

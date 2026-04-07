<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\MigrationServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function array_diff;
use function array_values;
use function count;
use function implode;

#[AsCommand(self::NAME, description: 'Check if entities are in sync with database and if migrations were executed')]
class MigrationCheckCommand extends Command
{

    use ConsoleLoggerFallbackTrait;
    use MigrationServiceRegistryAwareTrait;

    public const NAME = 'migration:check';

    public const EXIT_ENTITIES_NOT_SYNCED = 4;
    public const EXIT_UNKNOWN_MIGRATION = 2;
    public const EXIT_AWAITING_MIGRATION = 1;
    public const EXIT_OK = 0;

    public function __construct(
        private readonly MigrationServiceRegistry $migrationServiceRegistry,
        private readonly ?LoggerInterface $logger = null,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addNamespaceOption();
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $logger = $this->createLogger($output);
        $migrationService = $this->getMigrationService($input);

        $logger->info('Starting migration check');

        $exitCode = self::EXIT_OK;
        $exitCode |= $this->checkMigrationsExecuted($logger, $migrationService);
        $exitCode |= $this->checkEntitiesSyncedWithDatabase($logger, $migrationService);

        $logger->info('Migration check completed', [
            'exitCode' => $exitCode,
            'success' => $exitCode === self::EXIT_OK,
        ]);

        return $exitCode;
    }

    private function checkEntitiesSyncedWithDatabase(
        LoggerInterface $logger,
        MigrationService $migrationService,
    ): int
    {
        $updates = $migrationService->generateDiffSqls();
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

    private function checkMigrationsExecuted(
        LoggerInterface $logger,
        MigrationService $migrationService,
    ): int
    {
        $exitCode = self::EXIT_OK;
        $migrationsDir = $migrationService->getConfig()->getMigrationsDirectory();

        foreach (MigrationPhase::cases() as $phase) {
            $executed = $migrationService->getExecutedVersions($phase);
            $prepared = $migrationService->getPreparedVersions();

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

}

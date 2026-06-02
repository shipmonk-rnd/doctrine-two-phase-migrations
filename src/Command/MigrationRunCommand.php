<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use LogicException;
use Psr\Log\LoggerInterface;
use ShipMonk\Doctrine\Migration\IncompleteMigrationException;
use ShipMonk\Doctrine\Migration\MigrationLockException;
use ShipMonk\Doctrine\Migration\MigrationPhase;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\MigrationTableNotInitializedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use function array_map;
use function count;
use function in_array;
use function is_string;
use function round;

#[AsCommand(self::NAME, description: 'Run all not executed migrations with specified phase')]
class MigrationRunCommand extends Command
{

    use ConsoleLoggerFallbackTrait;

    public const NAME = 'migration:run';

    public const ARGUMENT_PHASE = 'phase';
    public const PHASE_BOTH = 'both';

    public const EXIT_OK = 0;
    public const EXIT_INCOMPLETE_MIGRATION = 1;
    public const EXIT_LOCK_NOT_ACQUIRED = 2;
    public const EXIT_TABLE_NOT_INITIALIZED = 3;

    public function __construct(
        private readonly MigrationService $migrationService,
        private readonly ?LoggerInterface $logger = null,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            self::ARGUMENT_PHASE,
            InputArgument::REQUIRED,
            MigrationPhase::BEFORE->value . '|' . MigrationPhase::AFTER->value . '|' . self::PHASE_BOTH,
        );
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $phaseArgument = $input->getArgument(self::ARGUMENT_PHASE);

        if (!is_string($phaseArgument)) {
            throw new LogicException('Can never happen for required non-array argument');
        }

        $logger = $this->createLogger($output);

        $phases = $this->getPhasesToRun($phaseArgument);

        $logger->info('Starting migration execution (phase {migrationPhaseArgument})', [
            'migrationPhaseArgument' => $phaseArgument,
            'migrationPhases' => array_map(static fn (MigrationPhase $phase): string => $phase->value, $phases),
        ]);

        // serialize the whole run across processes so parallel invocations are safe
        try {
            $this->migrationService->acquireLock();
        } catch (MigrationLockException $e) {
            $logger->error('Migration execution aborted, could not acquire migration lock within {migrationLockTimeoutSeconds} s (another migration run is probably in progress)', [
                'migrationPhaseArgument' => $phaseArgument,
                'migrationLockTimeoutSeconds' => $e->timeoutSeconds,
            ]);

            return self::EXIT_LOCK_NOT_ACQUIRED;
        }

        try {
            $this->migrationService->assertMigrationTableUpToDate();
            $this->migrationService->assertNoIncompleteMigrations();

            $migratedSomething = $this->executeMigrations($logger, $phases);

            if (!$migratedSomething) {
                $logger->notice('No migrations to execute (phase {migrationPhaseArgument})', [
                    'migrationPhaseArgument' => $phaseArgument,
                ]);
            } else {
                $logger->info('Migration execution completed (phase {migrationPhaseArgument})', [
                    'migrationPhaseArgument' => $phaseArgument,
                ]);
            }

            return self::EXIT_OK;
        } catch (MigrationTableNotInitializedException $e) {
            $logger->error('Migration execution aborted, migration table {migrationTableName} is not initialized, run the migration:init command first', [
                'migrationPhaseArgument' => $phaseArgument,
                'migrationTableName' => $e->tableName,
                'migrationError' => $e->getMessage(),
            ]);

            return self::EXIT_TABLE_NOT_INITIALIZED;
        } catch (IncompleteMigrationException $e) {
            $logger->error('Migration execution aborted, found {migrationIncompleteCount} unfinished migration(s) from a previously interrupted run, manual resolution is required', [
                'migrationPhaseArgument' => $phaseArgument,
                'migrationIncompleteCount' => count($e->incompleteMigrations),
                'migrationIncomplete' => $e->incompleteMigrations,
            ]);

            return self::EXIT_INCOMPLETE_MIGRATION;
        } finally {
            // a failed release must not mask the run's outcome; the session lock auto-releases on connection close
            try {
                $this->migrationService->releaseLock();
            } catch (Throwable $e) {
                $logger->warning('Failed to release the migration lock, it will be released when the database connection is closed ({migrationError})', [
                    'migrationError' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param list<MigrationPhase> $phases
     */
    private function executeMigrations(
        LoggerInterface $logger,
        array $phases,
    ): bool
    {
        $executed = [];

        if (in_array(MigrationPhase::BEFORE, $phases, true)) {
            $executed[MigrationPhase::BEFORE->value] = $this->migrationService->getExecutedVersions(MigrationPhase::BEFORE);
        }

        if (in_array(MigrationPhase::AFTER, $phases, true)) {
            $executed[MigrationPhase::AFTER->value] = $this->migrationService->getExecutedVersions(MigrationPhase::AFTER);
        }

        $preparedVersions = $this->migrationService->getPreparedVersions();
        $migratedSomething = false;

        $pendingMigrations = [];

        foreach ($preparedVersions as $version) {
            foreach ($phases as $phase) {
                if (!isset($executed[$phase->value][$version])) {
                    $pendingMigrations[] = ['migrationVersion' => $version, 'migrationPhase' => $phase->value];
                }
            }
        }

        if (count($pendingMigrations) > 0) {
            $logger->info('{migrationPendingCount} pending migrations found', [
                'migrationPendingCount' => count($pendingMigrations),
                'migrationPending' => $pendingMigrations,
            ]);
        }

        foreach ($preparedVersions as $version) {
            foreach ($phases as $phase) {
                if (isset($executed[$phase->value][$version])) {
                    continue;
                }

                $this->executeMigration($logger, $version, $phase);
                $migratedSomething = true;
            }
        }

        return $migratedSomething;
    }

    private function executeMigration(
        LoggerInterface $logger,
        string $version,
        MigrationPhase $phase,
    ): void
    {
        $logger->info('Executing migration {migrationVersion} phase {migrationPhase}', [
            'migrationVersion' => $version,
            'migrationPhase' => $phase->value,
        ]);

        $run = $this->migrationService->executeMigration($version, $phase);

        $logger->info('Migration {migrationVersion} phase {migrationPhase} executed successfully, {migrationDurationSeconds} s elapsed', [
            'migrationVersion' => $version,
            'migrationPhase' => $phase->value,
            'migrationDurationSeconds' => round($run->getDuration(), 3),
            'migrationStartedAt' => $run->getStartedAt()->format('Y-m-d H:i:s.u'),
            'migrationFinishedAt' => $run->getFinishedAt()->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * @return list<MigrationPhase>
     */
    private function getPhasesToRun(string $phaseArgument): array
    {
        if ($phaseArgument === MigrationPhase::BEFORE->value) {
            return [MigrationPhase::BEFORE];
        }

        if ($phaseArgument === MigrationPhase::AFTER->value) {
            return [MigrationPhase::AFTER];
        }

        if ($phaseArgument === self::PHASE_BOTH) {
            return [MigrationPhase::BEFORE, MigrationPhase::AFTER];
        }

        throw new LogicException('Unexpected phase argument');
    }

}
